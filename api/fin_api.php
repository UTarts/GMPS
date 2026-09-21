<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once 'config.php';
$db = $conn;

// ── Auth helper ────────────────────────────────────────────────────────────
function get_auth(mysqli $db): ?array {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!$h && function_exists('apache_request_headers')) {
        $hdrs = apache_request_headers();
        $h = $hdrs['Authorization'] ?? $hdrs['authorization'] ?? '';
    }
    $token = '';
    if (str_starts_with($h, 'Bearer ')) $token = substr($h, 7);
    elseif (!empty($_GET['token']))  $token = $_GET['token'];
    elseif (!empty($_POST['token'])) $token = $_POST['token'];
    if (!$token) return null;
    $hash = hash('sha256', $token);
    $stmt = $db->prepare("SELECT user_type, user_id FROM login_tokens WHERE token_hash=? AND expiry>NOW() LIMIT 1");
    $stmt->bind_param('s', $hash);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function json_out(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Guard: any admin level (1=superadmin, 2=admin, 3=accountant) ──────────
function require_fin_access(mysqli $db, int $max_level = 3): array {
    $auth = get_auth($db);
    
    // THE WHITELIST BYPASS FOR PARENTS/STUDENTS
    $allowed_student_actions = ['get_simple_ledger', 'get_sibling_groups'];
    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    if ($auth && $auth['user_type'] === 'student' && in_array($action, $allowed_student_actions)) {
        return []; // Bypass granted for safe read-only actions
    }

    if (!$auth || $auth['user_type'] !== 'admin')
        json_out(['success'=>false,'message'=>'Unauthorized'], 401);
        
    $stmt = $db->prepare("SELECT id, name, level, profile_pic, contact FROM admins WHERE id=? LIMIT 1");
    $stmt->bind_param('i', $auth['user_id']);
    $stmt->execute();
    $admin = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$admin || (int)$admin['level'] > $max_level)
        json_out(['success'=>false,'message'=>'Insufficient permissions'], 403);
        
    return $admin;
}

// ── Guard: original (level must be <= min_level) — kept for old endpoints ─
function require_admin(mysqli $db, int $min_level = 2): array {
    $auth = get_auth($db);
    if (!$auth || $auth['user_type'] !== 'admin')
        json_out(['success'=>false,'message'=>'Unauthorized'], 401);
    $stmt = $db->prepare("SELECT id, name, level FROM admins WHERE id=? LIMIT 1");
    $stmt->bind_param('i', $auth['user_id']);
    $stmt->execute();
    $admin = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$admin || (int)$admin['level'] > $min_level)
        json_out(['success'=>false,'message'=>'Insufficient permissions'], 403);
    return $admin;
}

// ── Guard: superadmin only (level 1) ──────────────────────────────────────
function require_superadmin(mysqli $db): array {
    $auth = get_auth($db);
    if (!$auth || $auth['user_type'] !== 'admin')
        json_out(['success'=>false,'message'=>'Unauthorized'], 401);
    $stmt = $db->prepare("SELECT id, name, level FROM admins WHERE id=? LIMIT 1");
    $stmt->bind_param('i', $auth['user_id']);
    $stmt->execute();
    $admin = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$admin || (int)$admin['level'] !== 1)
        json_out(['success'=>false,'message'=>'Super Admin only.'], 403);
    return $admin;
}

// ── Guard: student ─────────────────────────────────────────────────────────
function require_student(mysqli $db): array {
    $auth = get_auth($db);
    if (!$auth || $auth['user_type'] !== 'student')
        json_out(['success'=>false,'message'=>'Unauthorized'], 401);
    $stmt = $db->prepare("SELECT id, name, class_id FROM students WHERE id=? AND status='active' LIMIT 1");
    $stmt->bind_param('i', $auth['user_id']);
    $stmt->execute();
    $s = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$s) json_out(['success'=>false,'message'=>'Student not found'], 404);
    return $s;
}

function get_session(mysqli $db): string {
    $r = $db->query("SELECT setting_value FROM settings WHERE setting_key='current_session' LIMIT 1");
    return $r ? ($r->fetch_assoc()['setting_value'] ?? '2026-2027') : '2026-2027';
}

function next_receipt(mysqli $db): string {
    $db->query("UPDATE settings SET setting_value=setting_value+1 WHERE setting_key='fin_receipt_counter'");
    $r = $db->query("SELECT setting_value FROM settings WHERE setting_key='fin_receipt_counter' LIMIT 1");
    $n = (int)($r->fetch_assoc()['setting_value'] ?? 1);
    $session = get_session($db);
    $yr = explode('-', $session)[0] ?? date('Y');
    return 'GMPS-' . $yr . '-' . str_pad($n, 4, '0', STR_PAD_LEFT);
}

// ── Router ─────────────────────────────────────────────────────────────────
$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? $_POST['action'] ?? ($body['action'] ?? '');

switch ($action) {

    // ════════════════════════════════════════════════════════════════
    // ACCOUNTANT / ADMIN ENDPOINTS
    // ════════════════════════════════════════════════════════════════

    case 'get_dashboard_stats': {
        $admin   = require_fin_access($db);
        $session = get_session($db);

        $modes = "'cash','upi','cheque','bank_transfer'";

        $r = $db->query("SELECT COALESCE(SUM(amount_paid),0) AS total FROM fin_transactions WHERE session='$session' AND status='completed' AND payment_mode IN ($modes)");
        $total_collected = (float)$r->fetch_assoc()['total'];

        $r = $db->query("SELECT COALESCE(SUM(amount_paid),0) AS total FROM fin_transactions WHERE session='$session' AND status='completed' AND payment_mode = 'discount'");
        $total_discount = (float)$r->fetch_assoc()['total'];

        $r = $db->query("SELECT COALESCE(SUM(total_due - total_paid),0) AS due FROM fin_invoices WHERE session='$session' AND status != 'paid'");
        $total_due = (float)$r->fetch_assoc()['due'];

        $r = $db->query("SELECT COUNT(*) AS c FROM fin_submissions WHERE session='$session' AND status='pending'");
        $pending = (int)$r->fetch_assoc()['c'];

        $r = $db->query("SELECT COUNT(DISTINCT student_id) AS c FROM fin_invoices WHERE session='$session' AND status='unpaid'");
        $defaulters = (int)$r->fetch_assoc()['c'];

        $chart = [];
        for ($i = 11; $i >= 0; $i--) {
            $ts  = strtotime("-$i months");
            $m   = date('n', $ts);
            $y   = date('Y', $ts);
            $lbl = date('M Y', $ts);
            $r   = $db->query("SELECT COALESCE(SUM(amount_paid),0) AS amt FROM fin_transactions WHERE MONTH(payment_date)=$m AND YEAR(payment_date)=$y AND status='completed' AND payment_mode IN ($modes)");
            $chart[] = ['label' => $lbl, 'amount' => (float)$r->fetch_assoc()['amt']];
        }

        $r = $db->query("
            SELECT c.id AS class_id, c.name AS class_name,
                   COUNT(DISTINCT s.id) AS student_count,
                   (SELECT COALESCE(SUM(fi.total_due),0) FROM fin_invoices fi JOIN students st ON st.id=fi.student_id WHERE st.class_id=c.id AND fi.session='$session') AS total_due,
                   (SELECT COALESCE(SUM(ft.amount_paid),0) FROM fin_transactions ft JOIN students st ON st.id=ft.student_id WHERE st.class_id=c.id AND ft.session='$session' AND ft.status='completed' AND ft.payment_mode IN ($modes)) AS total_paid
            FROM classes c
            JOIN students s ON s.class_id = c.id AND s.status='active'
            GROUP BY c.id ORDER BY c.sort_order
        ");
        $class_data = $r->fetch_all(MYSQLI_ASSOC);

        $today    = date('Y-m-d');
        $r        = $db->query("SELECT COALESCE(SUM(amount_paid),0) AS amt, COUNT(*) AS txns FROM fin_transactions WHERE payment_date='$today' AND status='completed' AND payment_mode IN ($modes)");
        $today_row = $r->fetch_assoc();

        json_out([
            'success' => true,
            'stats'   => [
                'total_collected'    => $total_collected,
                'total_discount'     => $total_discount,
                'total_outstanding'  => $total_due,
                'pending_submissions'=> $pending,
                'defaulters'         => $defaulters,
                'today_collection'   => (float)$today_row['amt'],
                'today_transactions' => (int)$today_row['txns'],
            ],
            'chart'      => $chart,
            'class_data' => $class_data,
        ]);
    }

    case 'get_fee_matrix': {
        require_fin_access($db);
        $session = get_session($db);
        $classes = $db->query("SELECT id, name FROM classes ORDER BY sort_order")->fetch_all(MYSQLI_ASSOC);
        $heads = $db->query("SELECT * FROM fin_fee_heads WHERE is_active=1 ORDER BY id ASC")->fetch_all(MYSQLI_ASSOC);
        $rows = $db->query("SELECT class_id, fee_head_id, amount FROM fin_class_fees WHERE session='$session'")->fetch_all(MYSQLI_ASSOC);
        json_out(['success' => true, 'classes' => $classes, 'fee_heads' => $heads, 'matrix_rows' => $rows]);
    }

    case 'save_fee_amounts': {
        require_fin_access($db);
        $session = get_session($db);
        
        $raw  = $_POST['rows'] ?? ($body['rows'] ?? '[]');
        $rows = is_string($raw) ? json_decode($raw, true) : $raw;
        
        if (empty($rows)) json_out(['success' => false, 'message' => 'No data provided.']);
        
        foreach ($rows as $r) {
            $cid    = (int)$r['class_id'];
            $hid    = (int)$r['fee_head_id'];
            $amount = (float)$r['amount'];
            
            // Saving to native fin_class_fees with ON DUPLICATE KEY UPDATE
            $db->query("INSERT INTO fin_class_fees (class_id, fee_head_id, session, amount) 
                        VALUES ($cid, $hid, '$session', $amount)
                        ON DUPLICATE KEY UPDATE amount=$amount");
        }
        json_out(['success' => true]);
    }

    case 'add_fee_head': {
        require_fin_access($db);
        $name = $db->real_escape_string(trim($_POST['name'] ?? $body['name'] ?? ''));
        $type = in_array($_POST['type'] ?? $body['type'] ?? '', ['monthly','yearly','one_time']) ? ($_POST['type'] ?? $body['type']) : 'monthly';
        
        // FIX: Capture new fields
        $is_extra = (int)($_POST['is_extra'] ?? 0);
        $preset_amount = (float)($_POST['preset_amount'] ?? 0);
        
        if (!$name) json_out(['success' => false, 'message' => 'Name required.']);
        
        // FIX: Save to database
        $db->query("INSERT INTO fin_fee_heads (name, type, is_active, is_extra, preset_amount) VALUES ('$name','$type', 1, $is_extra, $preset_amount)");
        json_out(['success' => true]);
    }

    case 'update_fee_head': {
        require_fin_access($db);
        $id   = (int)($_POST['id'] ?? $body['id'] ?? 0);
        $name = $db->real_escape_string(trim($_POST['name'] ?? $body['name'] ?? ''));
        $type = in_array($_POST['type'] ?? $body['type'] ?? '', ['monthly','yearly','one_time']) ? ($_POST['type'] ?? $body['type']) : 'monthly';
        
        // FIX: Capture new fields
        $is_extra = (int)($_POST['is_extra'] ?? 0);
        $preset_amount = (float)($_POST['preset_amount'] ?? 0);
        
        if (!$id || !$name) json_out(['success' => false, 'message' => 'Invalid data.']);
        
        // FIX: Update database
        $db->query("UPDATE fin_fee_heads SET name='$name', type='$type', is_extra=$is_extra, preset_amount=$preset_amount WHERE id=$id");
        json_out(['success' => true]);
    }
    case 'delete_fee_head': {
        require_fin_access($db);
        $id = (int)($_POST['id'] ?? $body['id'] ?? 0);
        if (!$id) json_out(['success' => false, 'message' => 'Invalid ID.']);
        $db->query("DELETE FROM fin_fee_heads WHERE id=$id");
        $db->query("DELETE FROM fin_fee_matrix WHERE fee_head_id=$id");
        json_out(['success' => true]);
    }

    case 'get_fee_heads': {
        require_fin_access($db);
        $r = $db->query("SELECT * FROM fin_fee_heads WHERE is_active=1 ORDER BY id");
        json_out(['success'=>true,'fee_heads'=>$r->fetch_all(MYSQLI_ASSOC)]);
    }

    case 'save_fee_head': {
        $admin = require_admin($db, 1);
        $id    = (int)($body['id'] ?? 0);
        $name  = $db->real_escape_string(trim($body['name'] ?? ''));
        $type  = in_array($body['type'] ?? '', ['monthly','yearly','one_time']) ? $body['type'] : 'monthly';
        if (!$name) json_out(['success'=>false,'message'=>'Name required']);
        if ($id) {
            $db->query("UPDATE fin_fee_heads SET name='$name', type='$type' WHERE id=$id");
        } else {
            $db->query("INSERT INTO fin_fee_heads (name, type, is_active) VALUES ('$name','$type',1)");
            $id = $db->insert_id;
        }
        json_out(['success'=>true,'id'=>$id]);
    }

    case 'get_class_fee_matrix': {
        require_fin_access($db);
        $session = $db->real_escape_string($body['session'] ?? get_session($db));
        $heads   = $db->query("SELECT id, name, type FROM fin_fee_heads WHERE is_active=1 ORDER BY id")->fetch_all(MYSQLI_ASSOC);
        $classes = $db->query("SELECT id, name FROM classes ORDER BY sort_order")->fetch_all(MYSQLI_ASSOC);
        $fees_r  = $db->query("SELECT class_id, fee_head_id, amount FROM fin_class_fees WHERE session='$session'");
        $fee_map = [];
        while ($row = $fees_r->fetch_assoc())
            $fee_map[$row['class_id']][$row['fee_head_id']] = (float)$row['amount'];
        json_out(['success'=>true,'fee_heads'=>$heads,'classes'=>$classes,'fee_map'=>$fee_map,'session'=>$session]);
    }

    case 'save_class_fee': {
        $admin       = require_fin_access($db, 2);
        $class_id    = (int)($body['class_id'] ?? 0);
        $fee_head_id = (int)($body['fee_head_id'] ?? 0);
        $session     = $db->real_escape_string($body['session'] ?? get_session($db));
        $amount      = (float)($body['amount'] ?? 0);
        if (!$class_id || !$fee_head_id) json_out(['success'=>false,'message'=>'class_id and fee_head_id required']);
        $db->query("INSERT INTO fin_class_fees (class_id, fee_head_id, session, amount)
                    VALUES ($class_id, $fee_head_id, '$session', $amount)
                    ON DUPLICATE KEY UPDATE amount=$amount");
        json_out(['success'=>true]);
    }

    case 'search_student': {
        require_fin_access($db);
        $q = $db->real_escape_string(trim($body['q'] ?? $_GET['q'] ?? ''));
        if (strlen($q) < 2) json_out(['success'=>false,'message'=>'Query too short']);
        $r = $db->query("
            SELECT s.id, s.name, s.login_id, s.father_name, s.contact, c.name AS class_name
            FROM students s
            JOIN classes c ON c.id = s.class_id
            WHERE s.status='active' AND (s.name LIKE '%$q%' OR s.login_id LIKE '%$q%' OR s.father_name LIKE '%$q%')
            ORDER BY s.name LIMIT 20
        ");
        json_out(['success'=>true,'students'=>$r->fetch_all(MYSQLI_ASSOC)]);
    }

    case 'get_student_dues': {
        require_fin_access($db);
        $sid     = (int)($body['student_id'] ?? $_GET['student_id'] ?? 0);
        $session = $db->real_escape_string($body['session'] ?? get_session($db));
        if (!$sid) json_out(['success'=>false,'message'=>'student_id required']);

        $s = $db->query("SELECT s.id,s.name,s.father_name,s.contact,s.admission_year,c.name AS class_name FROM students s JOIN classes c ON c.id=s.class_id WHERE s.id=$sid LIMIT 1")->fetch_assoc();
        if (!$s) json_out(['success'=>false,'message'=>'Student not found']);

        $invoices = $db->query("
            SELECT fi.*,
                   GROUP_CONCAT(fh.name ORDER BY fh.id SEPARATOR ', ') AS fee_heads
            FROM fin_invoices fi
            LEFT JOIN fin_invoice_heads fih ON fih.invoice_id = fi.id
            LEFT JOIN fin_fee_heads fh ON fh.id = fih.fee_head_id
            WHERE fi.student_id=$sid AND fi.session='$session'
            GROUP BY fi.id
            ORDER BY fi.invoice_year, fi.invoice_month
        ")->fetch_all(MYSQLI_ASSOC);

        $transactions = $db->query("
            SELECT ft.*, a.name AS collected_by_name
            FROM fin_transactions ft
            LEFT JOIN admins a ON a.id = ft.collected_by
            WHERE ft.student_id=$sid AND ft.session='$session'
            ORDER BY ft.payment_date DESC
        ")->fetch_all(MYSQLI_ASSOC);

        $total_due  = array_sum(array_column($invoices, 'total_due'));
        $total_paid = array_sum(array_column($invoices, 'total_paid'));

        $arrears_r = $db->query("
            SELECT session,
                SUM(total_due) AS total_due,
                SUM(total_paid) AS total_paid,
                SUM(total_due - total_paid) AS amount_pending
            FROM fin_invoices
            WHERE student_id=$sid AND session != '$session' AND status != 'paid'
            GROUP BY session ORDER BY session ASC
        ");
        $arrears       = $arrears_r ? $arrears_r->fetch_all(MYSQLI_ASSOC) : [];
        $total_arrears = (float)array_sum(array_column($arrears, 'amount_pending'));

        json_out(['success'=>true,'student'=>$s,'invoices'=>$invoices,'transactions'=>$transactions,'arrears'=>$arrears,'summary'=>[
            'total_due'   => $total_due,
            'total_paid'  => $total_paid,
            'balance'     => $total_due - $total_paid,
            'arrears'     => $total_arrears,
            'grand_total' => ($total_due - $total_paid) + $total_arrears
        ]]);
    }

    case 'generate_invoices': {
        $admin     = require_admin($db, 2);
        $session   = $db->real_escape_string($body['session'] ?? get_session($db));
        $inv_month = (int)($body['month'] ?? date('n'));
        $inv_year  = (int)($body['year']  ?? date('Y'));
        if ($inv_month < 1 || $inv_month > 12) json_out(['success'=>false,'message'=>'Invalid month']);

        $students  = $db->query("SELECT id, class_id FROM students WHERE status='active'")->fetch_all(MYSQLI_ASSOC);
        $heads     = $db->query("SELECT id, type FROM fin_fee_heads WHERE is_active=1")->fetch_all(MYSQLI_ASSOC);
        $fee_rows  = $db->query("SELECT class_id, fee_head_id, amount FROM fin_class_fees WHERE session='$session'")->fetch_all(MYSQLI_ASSOC);
        $fee_map   = [];
        foreach ($fee_rows as $fr) $fee_map[$fr['class_id']][$fr['fee_head_id']] = (float)$fr['amount'];
        $overrides_r = $db->query("SELECT student_id, fee_head_id, custom_amount FROM fin_student_settings WHERE session='$session'");
        $ov_map = [];
        while ($o = $overrides_r->fetch_assoc()) $ov_map[$o['student_id']][$o['fee_head_id']] = (float)$o['custom_amount'];

        $created = 0; $skipped = 0;
        foreach ($students as $st) {
            $sid = $st['id']; $cid = $st['class_id'];
            $exists = $db->query("SELECT id FROM fin_invoices WHERE student_id=$sid AND invoice_month=$inv_month AND invoice_year=$inv_year AND session='$session' LIMIT 1");
            if ($exists->num_rows > 0) { $skipped++; continue; }
            $total = 0; $line_items = [];
            foreach ($heads as $h) {
                $hid  = $h['id']; $type = $h['type'];
                if ($type === 'yearly'   && $inv_month !== 4) continue;
                if ($type === 'one_time') continue;
                $amt = $ov_map[$sid][$hid] ?? $fee_map[$cid][$hid] ?? 0;
                if ($amt <= 0) continue;
                $total += $amt;
                $line_items[] = ['fee_head_id' => $hid, 'amount' => $amt];
            }
            if ($total <= 0) { $skipped++; continue; }
            $db->query("INSERT INTO fin_invoices (student_id, session, invoice_month, invoice_year, total_due, total_paid, status) VALUES ($sid, '$session', $inv_month, $inv_year, $total, 0, 'unpaid')");
            $inv_id = $db->insert_id;
            foreach ($line_items as $li)
                $db->query("INSERT INTO fin_invoice_heads (invoice_id, fee_head_id, amount) VALUES ($inv_id, {$li['fee_head_id']}, {$li['amount']})");
            $created++;
        }
        json_out(['success'=>true,'created'=>$created,'skipped'=>$skipped,'month'=>$inv_month,'year'=>$inv_year]);
    }

    case 'add_onetime_fee': {
        $admin       = require_fin_access($db);
        $sid         = (int)($body['student_id'] ?? 0);
        $fee_head_id = (int)($body['fee_head_id'] ?? 0);
        $session     = $db->real_escape_string($body['session'] ?? get_session($db));
        $amount      = (float)($body['amount'] ?? 0);
        if (!$sid || !$fee_head_id || $amount <= 0) json_out(['success'=>false,'message'=>'student_id, fee_head_id, amount required']);
        $now_m = (int)date('n'); $now_y = (int)date('Y');
        $exists = $db->query("SELECT id FROM fin_invoices WHERE student_id=$sid AND invoice_month=$now_m AND invoice_year=$now_y AND session='$session' LIMIT 1")->fetch_assoc();
        if ($exists) {
            $inv_id = $exists['id'];
            $db->query("UPDATE fin_invoices SET total_due=total_due+$amount WHERE id=$inv_id");
            $db->query("INSERT IGNORE INTO fin_invoice_heads (invoice_id, fee_head_id, amount) VALUES ($inv_id, $fee_head_id, $amount)");
        } else {
            $db->query("INSERT INTO fin_invoices (student_id, session, invoice_month, invoice_year, total_due, total_paid, status) VALUES ($sid,'$session',$now_m,$now_y,$amount,0,'unpaid')");
            $inv_id = $db->insert_id;
            $db->query("INSERT INTO fin_invoice_heads (invoice_id, fee_head_id, amount) VALUES ($inv_id,$fee_head_id,$amount)");
        }
        json_out(['success'=>true,'invoice_id'=>$inv_id]);
    }

    case 'save_student_override': {
        $admin       = require_fin_access($db);
        $sid         = (int)($body['student_id'] ?? 0);
        $fee_head_id = (int)($body['fee_head_id'] ?? 0);
        $session     = $db->real_escape_string($body['session'] ?? get_session($db));
        $amount      = (float)($body['custom_amount'] ?? 0);
        $remarks     = $db->real_escape_string($body['remarks'] ?? '');
        if (!$sid || !$fee_head_id) json_out(['success'=>false,'message'=>'student_id and fee_head_id required']);
        $db->query("INSERT INTO fin_student_settings (student_id, fee_head_id, session, custom_amount, remarks)
                    VALUES ($sid, $fee_head_id, '$session', $amount, '$remarks')
                    ON DUPLICATE KEY UPDATE custom_amount=$amount, remarks='$remarks'");
        json_out(['success'=>true]);
    }

    case 'collect_cash': {
        $admin       = require_fin_access($db);
        $sid         = (int)($body['student_id'] ?? 0);
        $invoice_ids = $body['invoice_ids'] ?? [];
        $amount      = (float)($body['amount_paid'] ?? 0);
        $mode        = in_array($body['payment_mode'] ?? '', ['cash','upi','cheque','bank_transfer']) ? $body['payment_mode'] : 'cash';
        $ref         = $db->real_escape_string($body['reference_no'] ?? '');
        $date        = $db->real_escape_string($body['payment_date'] ?? date('Y-m-d'));
        $remarks     = $db->real_escape_string($body['remarks'] ?? '');
        $session     = $db->real_escape_string(get_session($db));
        if (!$sid || $amount <= 0 || empty($invoice_ids)) json_out(['success'=>false,'message'=>'student_id, amount_paid, invoice_ids required']);

        $receipt_no = next_receipt($db);
        $db->query("INSERT INTO fin_transactions (receipt_no, student_id, session, amount_paid, payment_mode, reference_no, collected_by, payment_date, remarks, status)
                    VALUES ('$receipt_no', $sid, '$session', $amount, '$mode', '$ref', {$admin['id']}, '$date', '$remarks', 'completed')");
        $txn_id    = $db->insert_id;
        $remaining = $amount;
        foreach ($invoice_ids as $inv_id) {
            $inv_id = (int)$inv_id;
            $inv    = $db->query("SELECT total_due, total_paid FROM fin_invoices WHERE id=$inv_id AND student_id=$sid LIMIT 1")->fetch_assoc();
            if (!$inv) continue;
            $balance = $inv['total_due'] - $inv['total_paid'];
            $apply   = min($remaining, $balance);
            if ($apply <= 0) continue;
            $db->query("INSERT INTO fin_txn_invoices (transaction_id, invoice_id, amount_applied) VALUES ($txn_id, $inv_id, $apply)");
            $new_paid = $inv['total_paid'] + $apply;
            $status   = $new_paid >= $inv['total_due'] ? 'paid' : 'partial';
            $db->query("UPDATE fin_invoices SET total_paid=$new_paid, status='$status' WHERE id=$inv_id");
            $remaining -= $apply;
            if ($remaining <= 0) break;
        }
        json_out(['success'=>true,'receipt_no'=>$receipt_no,'transaction_id'=>$txn_id]);
    }

    case 'get_pending_submissions': {
        require_fin_access($db);
        $session = $db->real_escape_string(get_session($db));
        $r = $db->query("
            SELECT fs.*, s.name AS student_name, s.login_id, s.contact, s.father_name, c.name AS class_name
            FROM fin_submissions fs
            JOIN students s ON s.id = fs.student_id
            JOIN classes c ON c.id = s.class_id
            WHERE fs.session='$session' AND fs.status='pending'
            ORDER BY fs.created_at DESC
        ");
        json_out(['success'=>true,'submissions'=>$r->fetch_all(MYSQLI_ASSOC)]);
    }

    case 'get_all_submissions': {
        require_fin_access($db);
        $session = $db->real_escape_string(get_session($db));
        $status  = $db->real_escape_string($body['status'] ?? $_GET['status'] ?? 'pending');
        $where   = $status !== 'all' ? "AND fs.status='$status'" : '';
        $r = $db->query("
            SELECT fs.*, s.name AS student_name, s.login_id, c.name AS class_name, a.name AS reviewed_by_name
            FROM fin_submissions fs
            JOIN students s ON s.id = fs.student_id
            JOIN classes c ON c.id = s.class_id
            LEFT JOIN admins a ON a.id = fs.reviewed_by
            WHERE fs.session='$session' $where
            ORDER BY fs.created_at DESC LIMIT 200
        ");
        json_out(['success'=>true,'submissions'=>$r->fetch_all(MYSQLI_ASSOC)]);
    }

    case 'verify_submission': {
        $admin  = require_fin_access($db);
        $sub_id = (int)($body['submission_id'] ?? 0);
        $remarks = $db->real_escape_string($body['remarks'] ?? '');
        if (!$sub_id) json_out(['success'=>false,'message'=>'submission_id required']);
        $sub = $db->query("SELECT * FROM fin_submissions WHERE id=$sub_id AND status='pending' LIMIT 1")->fetch_assoc();
        if (!$sub) json_out(['success'=>false,'message'=>'Submission not found or already reviewed']);

        $session     = $db->real_escape_string($sub['session']);
        $sid         = (int)$sub['student_id'];
        $amount      = (float)$sub['amount_submitted'];
        $mode        = $db->real_escape_string($sub['payment_mode']);
        $ref         = $db->real_escape_string($sub['transaction_ref']);
        $date        = $db->real_escape_string($sub['payment_date']);
        $invoice_ids = json_decode($sub['invoice_ids'], true) ?? [];

        $receipt_no = next_receipt($db);
        $now = date('Y-m-d H:i:s');
        $db->query("INSERT INTO fin_transactions (receipt_no, student_id, session, amount_paid, payment_mode, reference_no, collected_by, payment_date, remarks, status, submission_id, verified_by, verified_at)
                    VALUES ('$receipt_no', $sid, '$session', $amount, '$mode', '$ref', {$admin['id']}, '$date', '$remarks', 'completed', $sub_id, {$admin['id']}, '$now')");
        $txn_id    = $db->insert_id;
        $remaining = $amount;
        foreach ($invoice_ids as $inv_id) {
            $inv_id = (int)$inv_id;
            $inv    = $db->query("SELECT total_due, total_paid FROM fin_invoices WHERE id=$inv_id AND student_id=$sid LIMIT 1")->fetch_assoc();
            if (!$inv) continue;
            $balance = $inv['total_due'] - $inv['total_paid'];
            $apply   = min($remaining, $balance);
            if ($apply <= 0) continue;
            $db->query("INSERT INTO fin_txn_invoices (transaction_id, invoice_id, amount_applied) VALUES ($txn_id, $inv_id, $apply)");
            $new_paid = $inv['total_paid'] + $apply;
            $status   = $new_paid >= $inv['total_due'] ? 'paid' : 'partial';
            $db->query("UPDATE fin_invoices SET total_paid=$new_paid, status='$status' WHERE id=$inv_id");
            $remaining -= $apply;
            if ($remaining <= 0) break;
        }
        $db->query("UPDATE fin_submissions SET status='verified', reviewed_by={$admin['id']}, reviewed_at='$now', transaction_id=$txn_id WHERE id=$sub_id");
        json_out(['success'=>true,'receipt_no'=>$receipt_no,'transaction_id'=>$txn_id]);
    }

    case 'reject_submission': {
        $admin  = require_fin_access($db);
        $sub_id = (int)($body['submission_id'] ?? 0);
        $reason = $db->real_escape_string($body['reason'] ?? 'Payment details could not be verified');
        if (!$sub_id) json_out(['success'=>false,'message'=>'submission_id required']);
        $sub = $db->query("SELECT id FROM fin_submissions WHERE id=$sub_id AND status='pending' LIMIT 1")->fetch_assoc();
        if (!$sub) json_out(['success'=>false,'message'=>'Submission not found or already reviewed']);
        $now = date('Y-m-d H:i:s');
        $db->query("UPDATE fin_submissions SET status='rejected', reviewed_by={$admin['id']}, reviewed_at='$now', rejection_reason='$reason' WHERE id=$sub_id");
        json_out(['success'=>true]);
    }

    case 'get_defaulters': {
        require_fin_access($db);
        $session = $db->real_escape_string(get_session($db));
        $month   = (int)($body['month'] ?? $_GET['month'] ?? date('n'));
        $year    = (int)($body['year']  ?? $_GET['year']  ?? date('Y'));
        $r = $db->query("
            SELECT s.id, s.name, s.father_name, s.contact, s.login_id, c.name AS class_name,
                   fi.total_due, fi.total_paid, (fi.total_due - fi.total_paid) AS balance, fi.status
            FROM fin_invoices fi
            JOIN students s ON s.id = fi.student_id
            JOIN classes c ON c.id = s.class_id
            WHERE fi.session='$session' AND fi.invoice_month=$month AND fi.invoice_year=$year
              AND fi.status IN ('unpaid','partial')
            ORDER BY c.sort_order, s.name
        ");
        json_out(['success'=>true,'defaulters'=>$r->fetch_all(MYSQLI_ASSOC),'month'=>$month,'year'=>$year]);
    }

    case 'get_reports': {
        require_fin_access($db);
        $type    = $body['type'] ?? $_GET['type'] ?? 'daily';
        $session = $db->real_escape_string(get_session($db));
        if ($type === 'daily') {
            $date = $db->real_escape_string($body['date'] ?? $_GET['date'] ?? date('Y-m-d'));
            $r    = $db->query("
                SELECT ft.*, s.name AS student_name, c.name AS class_name, a.name AS collected_by_name
                FROM fin_transactions ft
                JOIN students s ON s.id = ft.student_id
                JOIN classes c ON c.id = s.class_id
                LEFT JOIN admins a ON a.id = ft.collected_by
                WHERE ft.payment_date='$date' AND ft.status='completed'
                ORDER BY ft.id DESC
            ");
            $txns  = $r->fetch_all(MYSQLI_ASSOC);
            $total = array_sum(array_column($txns, 'amount_paid'));
            json_out(['success'=>true,'transactions'=>$txns,'total'=>$total,'date'=>$date]);
        } else {
            $r = $db->query("
                SELECT payment_mode, COUNT(*) AS count, SUM(amount_paid) AS total
                FROM fin_transactions WHERE session='$session' AND status='completed'
                GROUP BY payment_mode
            ");
            json_out(['success'=>true,'summary'=>$r->fetch_all(MYSQLI_ASSOC),'session'=>$session]);
        }
    }

    case 'set_session': {
        require_superadmin($db);
        $session = $db->real_escape_string(trim($body['session'] ?? ''));
        if (!preg_match('/^\d{4}-\d{4}$/', $session)) json_out(['success'=>false,'message'=>'Invalid session format. Use YYYY-YYYY']);
        $db->query("UPDATE settings SET setting_value='$session' WHERE setting_key='current_session'");
        json_out(['success'=>true,'session'=>$session]);
    }

    // ════════════════════════════════════════════════════════════════
    // STUDENT / PARENT ENDPOINTS
    // ════════════════════════════════════════════════════════════════

    case 'get_my_dues': {
        $student = require_student($db);
        $session = $db->real_escape_string(get_session($db));
        $sid     = $student['id'];

        $invoices = $db->query("
            SELECT fi.*,
                   MONTHNAME(CONCAT(fi.invoice_year,'-',fi.invoice_month,'-01')) AS month_name,
                   GROUP_CONCAT(fh.name ORDER BY fh.id SEPARATOR ', ') AS fee_heads
            FROM fin_invoices fi
            LEFT JOIN fin_invoice_heads fih ON fih.invoice_id = fi.id
            LEFT JOIN fin_fee_heads fh ON fh.id = fih.fee_head_id
            WHERE fi.student_id=$sid AND fi.session='$session'
            GROUP BY fi.id
            ORDER BY fi.invoice_year DESC, fi.invoice_month DESC
        ")->fetch_all(MYSQLI_ASSOC);

        $pending_sub = $db->query("SELECT id, amount_submitted, payment_mode, transaction_ref, payment_date, status, rejection_reason, created_at FROM fin_submissions WHERE student_id=$sid AND session='$session' AND status='pending' LIMIT 5")->fetch_all(MYSQLI_ASSOC);

        $total_due  = array_sum(array_column($invoices, 'total_due'));
        $total_paid = array_sum(array_column($invoices, 'total_paid'));

        $arrears_r = $db->query("
            SELECT session, SUM(total_due) AS total_due, SUM(total_paid) AS total_paid,
                   SUM(total_due - total_paid) AS amount_pending
            FROM fin_invoices
            WHERE student_id=$sid AND session != '$session' AND status != 'paid'
            GROUP BY session ORDER BY session ASC
        ");
        $arrears       = $arrears_r ? $arrears_r->fetch_all(MYSQLI_ASSOC) : [];
        $total_arrears = (float)array_sum(array_column($arrears, 'amount_pending'));

        json_out(['success'=>true,'invoices'=>$invoices,'pending_submissions'=>$pending_sub,'arrears'=>$arrears,'summary'=>[
            'total_due'   => $total_due,
            'total_paid'  => $total_paid,
            'balance'     => $total_due - $total_paid,
            'arrears'     => $total_arrears,
            'grand_total' => ($total_due - $total_paid) + $total_arrears
        ]]);
    }

    case 'get_my_receipts': {
        $student = require_student($db);
        $session = $db->real_escape_string(get_session($db));
        $sid     = $student['id'];
        $r = $db->query("
            SELECT ft.id, ft.receipt_no, ft.amount_paid, ft.payment_mode, ft.reference_no,
                   ft.payment_date, ft.remarks, ft.status, ft.created_at, a.name AS collected_by_name
            FROM fin_transactions ft
            LEFT JOIN admins a ON a.id = ft.collected_by
            WHERE ft.student_id=$sid AND ft.session='$session' AND ft.status='completed'
            ORDER BY ft.payment_date DESC
        ");
        json_out(['success'=>true,'receipts'=>$r->fetch_all(MYSQLI_ASSOC)]);
    }

    case 'submit_online_payment': {
        $student     = require_student($db);
        $session     = $db->real_escape_string(get_session($db));
        $sid         = $student['id'];
        $invoice_ids = $body['invoice_ids'] ?? [];
        $amount      = (float)($body['amount_submitted'] ?? 0);
        $mode        = in_array($body['payment_mode'] ?? '', ['upi','bank_transfer','cheque']) ? $body['payment_mode'] : 'upi';
        $ref         = $db->real_escape_string(trim($body['transaction_ref'] ?? ''));
        $date        = $db->real_escape_string($body['payment_date'] ?? date('Y-m-d'));
        $remarks     = $db->real_escape_string($body['remarks'] ?? '');

        if (empty($invoice_ids) || $amount <= 0 || !$ref)
            json_out(['success'=>false,'message'=>'invoice_ids, amount_submitted, and transaction_ref are required']);

        $dup = $db->query("SELECT id FROM fin_submissions WHERE student_id=$sid AND transaction_ref='$ref' AND status='pending' LIMIT 1");
        if ($dup->num_rows > 0) json_out(['success'=>false,'message'=>'A submission with this transaction reference is already pending']);

        $ids_json = $db->real_escape_string(json_encode(array_map('intval', $invoice_ids)));
        $db->query("INSERT INTO fin_submissions (student_id, session, invoice_ids, amount_submitted, payment_mode, transaction_ref, payment_date, remarks, status)
                    VALUES ($sid, '$session', '$ids_json', $amount, '$mode', '$ref', '$date', '$remarks', 'pending')");
        $sub_id = $db->insert_id;

        $notify_url = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname(dirname($_SERVER['PHP_SELF'])) . '/api/notify.php';
        @file_get_contents($notify_url . '?action=fee_submission&student_id=' . $sid . '&amount=' . $amount);

        json_out(['success'=>true,'submission_id'=>$sub_id,'message'=>'Payment submitted successfully. The accountant will verify within 1-2 business days.']);
    }

    
    case 'get_fee_heads_all': {
        require_fin_access($db);
        // FIX: Added is_extra and preset_amount to the SELECT query
        $r = $db->query("SELECT id, name, type, is_active, is_extra, preset_amount FROM fin_fee_heads ORDER BY is_active DESC, id ASC, name");
        json_out(['success' => true, 'fee_heads' => $r->fetch_all(MYSQLI_ASSOC)]);
    }

    
    // NOTE: add_fee_head / update_fee_head / delete_fee_head already exist above in your file.
    // The ones below are ONLY needed if yours are missing. Check before adding to avoid duplicates.
    // Your existing add_fee_head / update_fee_head / delete_fee_head use $db correctly — keep them.
    
    case 'toggle_fee_head': {
        require_fin_access($db);
        $id        = (int)($_POST['id'] ?? $body['id'] ?? 0);
        $is_active = (int)($_POST['is_active'] ?? $body['is_active'] ?? 0) ? 1 : 0;
        if (!$id) json_out(['success' => false, 'message' => 'ID required.']);
        $db->query("UPDATE fin_fee_heads SET is_active=$is_active WHERE id=$id");
        json_out(['success' => true]);
    }
    
    case 'get_student_ledger': {
        require_fin_access($db);
        $student_id = (int)($_GET['student_id'] ?? 0);
        if (!$student_id) json_out(['success' => false, 'message' => 'student_id required.']);
    
        // Session
        $session_key = trim($_GET['session'] ?? '');
        if (!$session_key) $session_key = get_session($db);
        $esc_session = $db->real_escape_string($session_key);
    
        // Student info
        $st = $db->query("SELECT s.id, s.name, s.login_id, s.father_name, s.contact, c.name AS class_name
                          FROM students s LEFT JOIN classes c ON c.id=s.class_id WHERE s.id=$student_id LIMIT 1");
        $student = $st ? $st->fetch_assoc() : null;
        if (!$student) json_out(['success' => false, 'message' => 'Student not found.']);
    
        // Invoices
        $inv_r = $db->query("SELECT id, invoice_month, invoice_year, total_due,
                              COALESCE(discount,0) AS discount, total_paid, status
                              FROM fin_invoices
                              WHERE student_id=$student_id AND session='$esc_session'
                              ORDER BY invoice_year, invoice_month");
        $invoices = $inv_r ? $inv_r->fetch_all(MYSQLI_ASSOC) : [];
    
        // Attach line items to each invoice
        foreach ($invoices as &$inv) {
            $iid = (int)$inv['id'];
            $h = $db->query("SELECT ih.fee_head_id, fh.name AS fee_head_name, ih.amount
                             FROM fin_invoice_heads ih
                             JOIN fin_fee_heads fh ON fh.id=ih.fee_head_id
                             WHERE ih.invoice_id=$iid");
            $inv['heads'] = $h ? $h->fetch_all(MYSQLI_ASSOC) : [];
        }
        unset($inv);
    
        // Transactions
        $txn_r = $db->query("SELECT id, receipt_no, amount_paid, payment_mode, reference_no,
                              payment_date, remarks, status
                              FROM fin_transactions
                              WHERE student_id=$student_id AND session='$esc_session'
                              ORDER BY payment_date DESC, id DESC");
        $transactions = $txn_r ? $txn_r->fetch_all(MYSQLI_ASSOC) : [];
    
        // Student overrides / settings
        $ss_r = $db->query("SELECT id, fee_head_id,
                             COALESCE(custom_amount,0)   AS custom_amount,
                             COALESCE(discount_amount,0) AS discount_amount,
                             COALESCE(discount_reason,'') AS discount_reason,
                             COALESCE(remarks,'')         AS remarks
                             FROM fin_student_settings
                             WHERE student_id=$student_id AND session='$esc_session'");
        $student_settings = $ss_r ? $ss_r->fetch_all(MYSQLI_ASSOC) : [];
    
        // All active fee heads (for override modal dropdown)
        $fh_r = $db->query("SELECT id, name, type FROM fin_fee_heads WHERE is_active=1 ORDER BY id");
        $fee_heads = $fh_r ? $fh_r->fetch_all(MYSQLI_ASSOC) : [];
    
        // Summary
        $total_due      = array_sum(array_column($invoices, 'total_due'));
        $total_discount = array_sum(array_column($invoices, 'discount'));
        $total_paid     = array_sum(array_column($invoices, 'total_paid'));
    
        json_out([
            'success'          => true,
            'student'          => $student,
            'session'          => $session_key,
            'invoices'         => $invoices,
            'transactions'     => $transactions,
            'student_settings' => $student_settings,
            'fee_heads'        => $fee_heads,
            'summary'          => compact('total_due', 'total_discount', 'total_paid'),
        ]);
    }
    
    case 'save_student_setting': {
        require_fin_access($db);
        $student_id      = (int)($_POST['student_id'] ?? 0);
        $fee_head_id     = (int)($_POST['fee_head_id'] ?? 0);
        $custom_amount   = (float)($_POST['custom_amount'] ?? 0);
        $discount_amount = (float)($_POST['discount_amount'] ?? 0);
        $discount_reason = $db->real_escape_string(trim($_POST['discount_reason'] ?? ''));
        $remarks         = $db->real_escape_string(trim($_POST['remarks'] ?? ''));
        $session_key     = $db->real_escape_string(trim($_POST['session'] ?? '') ?: get_session($db));
    
        if (!$student_id || !$fee_head_id)
            json_out(['success' => false, 'message' => 'student_id and fee_head_id required.']);
    
        // Prevent duplicate
        $chk = $db->query("SELECT id FROM fin_student_settings WHERE student_id=$student_id AND fee_head_id=$fee_head_id AND session='$session_key' LIMIT 1");
        if ($chk && $chk->num_rows > 0)
            json_out(['success' => false, 'message' => 'Override for this fee head already exists. Edit it instead.']);
    
        $db->query("INSERT INTO fin_student_settings
                    (student_id, fee_head_id, session, custom_amount, discount_amount, discount_reason, remarks)
                    VALUES ($student_id, $fee_head_id, '$session_key', $custom_amount, $discount_amount, '$discount_reason', '$remarks')");
        json_out(['success' => true]);
    }
    
    case 'update_student_setting': {
        require_fin_access($db);
        $id              = (int)($_POST['id'] ?? 0);
        $custom_amount   = (float)($_POST['custom_amount'] ?? 0);
        $discount_amount = (float)($_POST['discount_amount'] ?? 0);
        $discount_reason = $db->real_escape_string(trim($_POST['discount_reason'] ?? ''));
        $remarks         = $db->real_escape_string(trim($_POST['remarks'] ?? ''));
        if (!$id) json_out(['success' => false, 'message' => 'ID required.']);
        $db->query("UPDATE fin_student_settings
                    SET custom_amount=$custom_amount, discount_amount=$discount_amount,
                        discount_reason='$discount_reason', remarks='$remarks'
                    WHERE id=$id");
        json_out(['success' => true]);
    }
    
    case 'delete_student_setting': {
        require_fin_access($db);
        $id = (int)($_POST['id'] ?? $body['id'] ?? 0);
        if (!$id) json_out(['success' => false, 'message' => 'ID required.']);
        $db->query("DELETE FROM fin_student_settings WHERE id=$id");
        json_out(['success' => true]);
    }
    
    // =========================================================================
    // --- SMART LEDGER SYSTEM (PHASE 1 UPDATES) ---
    // =========================================================================

    case 'get_global_timeline': {
        require_fin_access($db);
        
        $r = $db->query("
            SELECT ft.*, s.name AS student_name, c.name AS class_name 
            FROM fin_transactions ft 
            JOIN students s ON s.id = ft.student_id 
            JOIN classes c ON c.id = s.class_id 
            WHERE NOT (ft.payment_mode = 'discount' AND ft.remarks LIKE 'Staff Waiver%')
            ORDER BY ft.created_at DESC 
            LIMIT 7000
        ");
        json_out(['success' => true, 'timeline' => $r->fetch_all(MYSQLI_ASSOC)]);
    }

    case 'get_sibling_groups': {
        require_fin_access($db);
        $all_st = $db->query("SELECT s.id, s.name, s.contact, s.primary_payer_id, s.father_name, c.name as class_name, c.sort_order 
                              FROM students s JOIN classes c ON c.id=s.class_id 
                              WHERE s.status='active' ORDER BY c.sort_order DESC")->fetch_all(MYSQLI_ASSOC);

        $student_map = [];
        foreach($all_st as $s) { $student_map[$s['id']] = $s; }

        $strict_groups = [];
        $suggested_groups = [];
        $strict_student_ids = [];

        // 1. Build Strict Groups (Official Families explicitly linked via Primary Payer)
        $primary_payers = [];
        foreach($all_st as $s) {
            if (!empty($s['primary_payer_id'])) {
                $pid = $s['primary_payer_id'];
                if (!isset($primary_payers[$pid])) $primary_payers[$pid] = [];
                $primary_payers[$pid][] = $s;
            }
        }

        foreach($primary_payers as $pid => $dependents) {
            if (isset($student_map[$pid])) {
                $primary_student = $student_map[$pid];
                $group_students = [$primary_student];
                $strict_student_ids[] = $primary_student['id'];
                
                foreach($dependents as $dep) {
                    $group_students[] = $dep;
                    $strict_student_ids[] = $dep['id'];
                }
                
                $strict_groups[] = [
                    'contact' => $primary_student['contact'] ?: 'Custom Linked Family',
                    'students' => $group_students,
                    'is_suggested' => false
                ];
            }
        }

        // 2. Build Suggested Groups (Sharing a phone number, but NOT officially linked)
        $by_contact = [];
        foreach($all_st as $s) {
            $c = trim($s['contact']);
            if ($c && strlen($c) > 5) {
                if (!isset($by_contact[$c])) $by_contact[$c] = [];
                $by_contact[$c][] = $s;
            }
        }

        foreach($by_contact as $c => $students) {
            $unlinked = [];
            foreach($students as $s) {
                // Only suggest students who are not already safely tucked into a strict family
                if (!in_array($s['id'], $strict_student_ids)) {
                    $unlinked[] = $s;
                }
            }
            if (count($unlinked) > 1) {
                $suggested_groups[] = [
                    'contact' => $c,
                    'students' => $unlinked,
                    'is_suggested' => true
                ];
            }
        }

        // Returning them separated natively fixes the false-aggregation bug in the ledgers!
        json_out(['success' => true, 'groups' => $strict_groups, 'suggested_groups' => $suggested_groups]);
    }

    case 'save_sibling_payer': {
        require_fin_access($db);
        $student_id = (int)($body['student_id'] ?? 0);
        $payer_id = (int)($body['payer_id'] ?? 0); 
        if (!$student_id) json_out(['success' => false]);
        $val = $payer_id > 0 ? $payer_id : "NULL";
        $db->query("UPDATE students SET primary_payer_id=$val WHERE id=$student_id");
        json_out(['success' => true]);
    }

    case 'save_sibling_payers_bulk': {
        require_fin_access($db);
        $updates = json_decode($_POST['updates'] ?? $body['updates'] ?? '[]', true);
        foreach($updates as $u) {
            $sid = (int)$u['student_id'];
            $pid = (int)$u['payer_id'];
            $val = $pid > 0 ? $pid : "NULL";
            $db->query("UPDATE students SET primary_payer_id=$val WHERE id=$sid");
        }
        json_out(['success' => true]);
    }

    case 'get_simple_ledger': {
        require_fin_access($db);
        $student_id = (int)($_GET['student_id'] ?? 0);
        $session = $db->real_escape_string(get_session($db));
        
        $st = $db->query("SELECT s.*, c.name AS class_name FROM students s JOIN classes c ON c.id=s.class_id WHERE s.id=$student_id LIMIT 1")->fetch_assoc();
        if (!$st) json_out(['success' => false, 'message' => 'Student not found']);
        
        $payer_info = null;
        $ignore_sibling = (int)($_GET['ignore_sibling'] ?? 0);
        if (!$ignore_sibling && !empty($st['primary_payer_id']) && $st['primary_payer_id'] != $student_id) {
            $payer = $db->query("SELECT s.name, c.name AS class_name FROM students s JOIN classes c ON c.id=s.class_id WHERE s.id={$st['primary_payer_id']}")->fetch_assoc();
            if ($payer) {
                $payer_info = ['id' => $st['primary_payer_id'], 'name' => $payer['name'], 'class_name' => $payer['class_name']];
            }
        }
        
        $is_new = (int)$st['is_new_admission'] === 1;
        $uses_transport = (int)$st['uses_transport'] === 1;
        $is_staff = $st['fee_waiver_type'] === 'staff';

        $fee_heads_all = $db->query("SELECT * FROM fin_fee_heads WHERE is_active=1 ORDER BY id")->fetch_all(MYSQLI_ASSOC);
        $matrix = $db->query("SELECT fh.id as fee_head_id, COALESCE(fcf.amount, fh.preset_amount) as amount, fh.name, fh.type 
                              FROM fin_fee_heads fh 
                              LEFT JOIN fin_class_fees fcf ON fcf.fee_head_id = fh.id AND fcf.class_id={$st['class_id']} AND fcf.session='$session'
                              WHERE fh.is_active=1 AND fh.is_extra=0")->fetch_all(MYSQLI_ASSOC);
                              
        $overrides = $db->query("SELECT * FROM fin_student_settings WHERE student_id=$student_id AND session='$session'")->fetch_all(MYSQLI_ASSOC);
        $ov_map = [];
        $total_discount = 0;
        foreach($overrides as $ov) {
            $ov_map[$ov['fee_head_id']] = $ov;
            $total_discount += (float)$ov['discount_amount'];
        }
        
        $breakdown = [];
        $total_projected_due = 0;
        
        foreach($matrix as $m) {
            $name_lower = strtolower($m['name']);
            if (strpos($name_lower, 'admission') !== false && !$is_new) continue;
            if (strpos($name_lower, 'kit') !== false) {
                if ($is_new && strpos($name_lower, 'old') !== false) continue;
                if (!$is_new && strpos($name_lower, 'new') !== false) continue;
            }
            if (strpos($name_lower, 'transport') !== false && !$uses_transport) continue;

            $hid = $m['fee_head_id'];
            $base_amt = (float)$m['amount'];
            $custom_amt = (isset($ov_map[$hid]) && (float)$ov_map[$hid]['custom_amount'] > 0) ? (float)$ov_map[$hid]['custom_amount'] : $base_amt;
            
            if ($custom_amt <= 0) continue;
            
            $multiplier = 1;
            $label = $m['name'];
            
            if ($m['type'] === 'monthly') {
                if (strpos($name_lower, 'transport') !== false) { 
                    $multiplier = 11;
                    // --- SMART SPLIT LOGIC FOR TRANSPORT FEE ---
                    if ($custom_amt == 750) {
                        $line_total = (600 * 2) + (750 * 9);
                        $label .= " (2m @ ₹600 + 9m @ ₹750)";
                    } else {
                        // If they have a custom override (like ₹400), charge normally
                        $line_total = $custom_amt * 11;
                        $label .= " (11 Months)"; 
                    }
                } else { 
                    $multiplier = 12; 
                    $line_total = $custom_amt * 12;
                    $label .= " (12 Months)"; 
                }
            } else {
                $line_total = $custom_amt * $multiplier;
            }
            
            $total_projected_due += $line_total;
            $breakdown[] = ['fee_head_id' => $hid, 'name' => $label, 'type' => $m['type'], 'applied_amount' => $custom_amt, 'multiplier' => $multiplier, 'total' => $line_total];
        }
        
        $extras = $db->query("SELECT fih.id, fh.name, fih.amount, fi.created_at
                              FROM fin_invoice_heads fih
                              JOIN fin_invoices fi ON fi.id=fih.invoice_id
                              JOIN fin_fee_heads fh ON fh.id=fih.fee_head_id
                              WHERE fi.student_id=$student_id AND fi.session='$session' AND fh.is_extra=1")->fetch_all(MYSQLI_ASSOC);
                              
        foreach($extras as $ext) {
            $amt = (float)$ext['amount'];
            $total_projected_due += $amt;
            $breakdown[] = ['name' => $ext['name'], 'type' => 'extra', 'total' => $amt, 'date' => $ext['created_at']];
        }

        // --- BULLETPROOF STAFF WAIVER AUTO-SYNC ---
        if ($is_staff) {
            $w_r = $db->query("SELECT SUM(amount_paid) as total_w FROM fin_transactions WHERE student_id=$student_id AND session='$session' AND payment_mode='discount' AND remarks LIKE 'Staff Waiver%'")->fetch_assoc();
            $existing_waiver = (float)($w_r['total_w'] ?? 0);
            
            if ($total_projected_due > $existing_waiver) {
                $diff = $total_projected_due - $existing_waiver;
                $receipt_no = 'STF-' . time() . rand(10,99);
                $date = date('Y-m-d');
                $created_at = date('Y-m-d H:i:s');
                
                // Safely grab an admin ID so it doesn't crash on foreign key constraint
                $adm_r = $db->query("SELECT id FROM admins LIMIT 1")->fetch_assoc();
                $safe_admin_id = $adm_r ? $adm_r['id'] : 1;
                
                $db->query("INSERT INTO fin_transactions (receipt_no, student_id, session, amount_paid, payment_mode, collected_by, payment_date, remarks, status, created_at)
                            VALUES ('$receipt_no', $student_id, '$session', $diff, 'discount', $safe_admin_id, '$date', 'Staff Waiver (Auto Sync)', 'completed', '$created_at')");
                
                $fh_r = $db->query("SELECT id FROM fin_fee_heads LIMIT 1")->fetch_assoc();
                if ($fh_r) {
                    $safe_fid = $fh_r['id'];
                    $db->query("INSERT INTO fin_student_settings (student_id, fee_head_id, session, custom_amount, discount_amount, discount_reason)
                                VALUES ($student_id, $safe_fid, '$session', 0, $diff, 'Staff Waiver')
                                ON DUPLICATE KEY UPDATE discount_amount = discount_amount + $diff");
                }
                $total_discount += $diff;
            }
        }
        // ---------------------------------------

        $txns = $db->query("SELECT ft.*, a.name AS collected_by_name, 
                                   (SELECT COUNT(id) FROM fin_transaction_edits WHERE transaction_id = ft.id) AS edit_count
                            FROM fin_transactions ft 
                            LEFT JOIN admins a ON a.id=ft.collected_by 
                            WHERE ft.student_id=$student_id AND ft.session='$session' AND ft.status='completed'
                            ORDER BY ft.created_at DESC")->fetch_all(MYSQLI_ASSOC);
                            
        $total_paid = 0;
        foreach($txns as $t) {
            if (in_array($t['payment_mode'], ['cash','upi','cheque','bank_transfer'])) {
                $total_paid += (float)$t['amount_paid'];
            }
        }
        
        $balance = $total_projected_due - $total_paid - $total_discount;
        json_out(['success' => true, 'student' => $st, 'breakdown' => $breakdown, 'total_due' => $total_projected_due, 'total_discount' => $total_discount, 'total_paid' => $total_paid, 'balance' => $balance, 'transactions' => $txns, 'fee_heads' => $fee_heads_all, 'session' => $session, 'payer_info' => $payer_info]);
    }

    case 'collect_simple_fee': {
        $admin = require_fin_access($db);
        $sid = (int)($body['student_id'] ?? 0);
        $amount = (float)($body['amount'] ?? 0);
        $mode = $db->real_escape_string($body['payment_mode'] ?? 'cash');
        $ref = $db->real_escape_string($body['reference_no'] ?? '');
        $remarks = $db->real_escape_string($body['remarks'] ?? '');
        $session = $db->real_escape_string(get_session($db));
        
        if (!$sid || $amount <= 0) json_out(['success'=>false, 'message'=>'Invalid amount']);
        
        $receipt_no = next_receipt($db);
        $date = date('Y-m-d');
        $created_at = date('Y-m-d H:i:s');
        
        $db->query("INSERT INTO fin_transactions (receipt_no, student_id, session, amount_paid, payment_mode, reference_no, collected_by, payment_date, remarks, status, created_at)
                    VALUES ('$receipt_no', $sid, '$session', $amount, '$mode', '$ref', {$admin['id']}, '$date', '$remarks', 'completed', '$created_at')");
                    
        json_out(['success'=>true, 'receipt_no'=>$receipt_no]);
    }

    case 'add_simple_extra_fee': {
        $admin = require_fin_access($db);
        $sid = (int)($body['student_id'] ?? 0);
        $raw_fee_head = $body['fee_head_id'] ?? 0;
        $amount = (float)($body['amount'] ?? 0);
        $user_remarks = $db->real_escape_string(trim($body['remarks'] ?? ''));
        $manual_title = $db->real_escape_string(trim($body['manual_title'] ?? ''));
        $session = $db->real_escape_string(get_session($db));
        
        if (!$sid || $amount <= 0) json_out(['success'=>false, 'message'=>'Invalid data']);
        
        $fee_head_id = 0;
        $head_name = 'Extra Item';

        // Check if user selected Manual Entry
        if ($raw_fee_head === 'manual') {
            if (!$manual_title) json_out(['success'=>false, 'message'=>'Item name required for manual entry']);
            // Create a hidden, one-time fee head to keep the invoice system structurally sound
            $db->query("INSERT INTO fin_fee_heads (name, type, is_active, is_extra, preset_amount) VALUES ('$manual_title', 'one_time', 0, 1, $amount)");
            $fee_head_id = $db->insert_id;
            $head_name = $manual_title;
        } else {
            $fee_head_id = (int)$raw_fee_head;
            if (!$fee_head_id) json_out(['success'=>false, 'message'=>'Invalid item selected']);
            $fh = $db->query("SELECT name FROM fin_fee_heads WHERE id=$fee_head_id")->fetch_assoc();
            $head_name = $fh ? $fh['name'] : 'Extra Item';
        }
        
        $year = (int)date('Y');
        $exists = $db->query("SELECT id FROM fin_invoices WHERE student_id=$sid AND invoice_month=0 AND invoice_year=$year AND session='$session' LIMIT 1")->fetch_assoc();
        
        if ($exists) {
            $inv_id = $exists['id'];
            $db->query("UPDATE fin_invoices SET total_due=total_due+$amount WHERE id=$inv_id");
            $db->query("INSERT INTO fin_invoice_heads (invoice_id, fee_head_id, amount) VALUES ($inv_id, $fee_head_id, $amount)");
        } else {
            $db->query("INSERT INTO fin_invoices (student_id, session, invoice_month, invoice_year, total_due, total_paid, status) 
                        VALUES ($sid, '$session', 0, $year, $amount, 0, 'unpaid')");
            $inv_id = $db->insert_id;
            $db->query("INSERT INTO fin_invoice_heads (invoice_id, fee_head_id, amount) VALUES ($inv_id, $fee_head_id, $amount)");
        }
        
        $receipt_no = 'EXT-' . time() . rand(10, 99); 
        $date = date('Y-m-d');
        $created_at = date('Y-m-d H:i:s');
        
        // Construct the remark carefully for the history log
        $final_remark = "Added Extra: " . $head_name;
        if ($user_remarks) $final_remark .= " | " . $user_remarks;
        $final_remark = $db->real_escape_string($final_remark);
        
        $db->query("INSERT INTO fin_transactions (receipt_no, student_id, session, amount_paid, payment_mode, collected_by, payment_date, remarks, status, created_at)
                    VALUES ('$receipt_no', $sid, '$session', $amount, 'extra_fee', {$admin['id']}, '$date', '$final_remark', 'completed', '$created_at')");
        
        json_out(['success'=>true]);
    }

    case 'save_simple_discount': {
        $admin = require_fin_access($db);
        $sid = (int)($body['student_id'] ?? 0);
        $amount = (float)($body['discount_amount'] ?? 0);
        $reason = $db->real_escape_string($body['discount_reason'] ?? '');
        $session = $db->real_escape_string(get_session($db));
        
        $fh = $db->query("SELECT id FROM fin_fee_heads LIMIT 1")->fetch_assoc();
        
        $fh = $db->query("SELECT id FROM fin_fee_heads LIMIT 1")->fetch_assoc();
        $fid = $fh['id'] ?? 1;
        
        $db->query("INSERT INTO fin_student_settings (student_id, fee_head_id, session, custom_amount, discount_amount, discount_reason)
                    VALUES ($sid, $fid, '$session', 0, $amount, '$reason')
                    ON DUPLICATE KEY UPDATE discount_amount = discount_amount + $amount, discount_reason = CONCAT(discount_reason, ' | ', '$reason')");
                    
        $receipt_no = 'DSC-' . time() . rand(10, 99); 
        $date = date('Y-m-d');
        $created_at = date('Y-m-d H:i:s');
        
        $db->query("INSERT INTO fin_transactions (receipt_no, student_id, session, amount_paid, payment_mode, collected_by, payment_date, remarks, status, created_at)
                    VALUES ('$receipt_no', $sid, '$session', $amount, 'discount', {$admin['id']}, '$date', '$reason', 'completed', '$created_at')");
        
        json_out(['success'=>true]);
    }

    case 'edit_transaction': {
        $admin = require_fin_access($db);
        $txn_id = (int)($body['txn_id'] ?? 0);
        $new_amt = (float)($body['amount'] ?? 0);
        $new_rem = $db->real_escape_string($body['remarks'] ?? '');
        $reason = $db->real_escape_string($body['reason'] ?? '');
        
        $txn = $db->query("SELECT * FROM fin_transactions WHERE id=$txn_id")->fetch_assoc();
        if (!$txn) json_out(['success'=>false, 'message'=>'Txn not found']);
        
        $old_amt = (float)$txn['amount_paid'];
        
        if ($old_amt != $new_amt) {
            if (!$reason) json_out(['success'=>false, 'message'=>'Reason required for amount change']);
            $date = date('Y-m-d H:i:s');
            $db->query("INSERT INTO fin_transaction_edits (transaction_id, old_amount, new_amount, reason, edited_at, edited_by) VALUES ($txn_id, $old_amt, $new_amt, '$reason', '$date', {$admin['id']})");
        }
        
        $db->query("UPDATE fin_transactions SET amount_paid=$new_amt, remarks='$new_rem' WHERE id=$txn_id");
        
        if ($txn['payment_mode'] == 'discount' && $old_amt != $new_amt) {
            $diff = $new_amt - $old_amt;
            $db->query("UPDATE fin_student_settings SET discount_amount = discount_amount + $diff WHERE student_id={$txn['student_id']}");
        }

        // --- SELF-HEALING EXTRA FEE SYNC ---
        // Automatically rescues orphaned invoices and forces them to match the transaction
        if ($txn['payment_mode'] == 'extra_fee') {
            $sid = $txn['student_id'];
            $sess = $txn['session'];
            
            $extracted_name = str_replace('Added Extra: ', '', $txn['remarks']);
            $extracted_name = $db->real_escape_string($extracted_name);
            
            // Look up the audit log to find the original amount before the remarks were ruined
            $orig_res = $db->query("SELECT old_amount FROM fin_transaction_edits WHERE transaction_id=$txn_id ORDER BY edited_at ASC LIMIT 1");
            $orig_amt = $orig_res && $orig_res->num_rows > 0 ? (float)$orig_res->fetch_assoc()['old_amount'] : $old_amt;
            
            // Hunt down the specific invoice head using multiple fallbacks
            $head_res = $db->query("
                SELECT fih.id, fih.amount, fi.id as inv_id
                FROM fin_invoice_heads fih 
                JOIN fin_invoices fi ON fih.invoice_id = fi.id 
                JOIN fin_fee_heads fh ON fh.id = fih.fee_head_id
                WHERE fi.student_id=$sid AND fi.session='$sess' AND fi.invoice_month=0 
                AND (fh.name='$extracted_name' OR fih.amount=$old_amt OR fih.amount=$new_amt OR fih.amount=$orig_amt)
                ORDER BY fih.id DESC LIMIT 1
            ");
            
            if ($head_res && $head_res->num_rows > 0) {
                $row = $head_res->fetch_assoc();
                $head_id = $row['id'];
                $inv_id = $row['inv_id'];
                $current_head_amount = (float)$row['amount'];
                
                // If the invoice is stuck on the old number, self-heal it instantly
                if ($current_head_amount != $new_amt) {
                    $actual_diff = $new_amt - $current_head_amount; 
                    $db->query("UPDATE fin_invoice_heads SET amount = $new_amt WHERE id=$head_id");
                    $db->query("UPDATE fin_invoices SET total_due = total_due + $actual_diff WHERE id=$inv_id");
                }
            }
        }
        
        json_out(['success'=>true]);
    }

    case 'get_transaction_edits': {
        require_fin_access($db);
        $txn_id = (int)($_GET['txn_id'] ?? 0);
        $r = $db->query("SELECT e.*, a.name as editor_name FROM fin_transaction_edits e JOIN admins a ON a.id=e.edited_by WHERE e.transaction_id=$txn_id ORDER BY e.edited_at DESC");
        json_out(['success'=>true, 'edits'=>$r->fetch_all(MYSQLI_ASSOC)]);
    }

    case 'get_students_mapping': {
        require_fin_access($db);
        $class_id = (int)($_GET['class_id'] ?? 0);
        if (!$class_id) json_out(['success' => false, 'message' => 'class_id required']);
        
        $r = $db->query("SELECT id, name, father_name, login_id, is_new_admission, uses_transport, fee_waiver_type 
                         FROM students WHERE class_id=$class_id AND status='active' ORDER BY name");
        json_out(['success' => true, 'students' => $r->fetch_all(MYSQLI_ASSOC)]);
    }

    case 'save_students_mapping': {
        require_fin_access($db);
        $updates = json_decode($_POST['updates'] ?? $body['updates'] ?? '[]', true);
        if (empty($updates)) json_out(['success' => false, 'message' => 'No data']);
        
        foreach($updates as $u) {
            $id = (int)$u['id'];
            $is_new = (int)$u['is_new_admission'];
            $transport = (int)$u['uses_transport'];
            $waiver = $db->real_escape_string($u['fee_waiver_type']);
            $db->query("UPDATE students SET is_new_admission=$is_new, uses_transport=$transport, fee_waiver_type='$waiver' WHERE id=$id");
        }
        json_out(['success' => true]);
    }

    // --- QUARTERLY RECOVERY & DEFAULTERS ---
    // --- QUARTERLY RECOVERY & DEFAULTERS ---
    case 'get_recovery_data': {
        require_fin_access($db);
        $session = $db->real_escape_string(get_session($db));

        // 1. Fetch all required data
        $students = $db->query("SELECT id, name, class_id, contact, father_name, primary_payer_id, is_new_admission, uses_transport, fee_waiver_type FROM students WHERE status = 'active'")->fetch_all(MYSQLI_ASSOC);
        
        $classes = [];
        $c_r = $db->query("SELECT id, name, sort_order FROM classes");
        while($c = $c_r->fetch_assoc()) $classes[$c['id']] = $c;

        $heads = $db->query("SELECT id, name, type, preset_amount FROM fin_fee_heads WHERE is_active=1 AND is_extra=0")->fetch_all(MYSQLI_ASSOC);
        
        $matrix_raw = $db->query("SELECT class_id, fee_head_id, amount FROM fin_class_fees WHERE session='$session'")->fetch_all(MYSQLI_ASSOC);
        $matrix = [];
        foreach($matrix_raw as $m) $matrix[$m['class_id']][$m['fee_head_id']] = (float)$m['amount'];

        $overrides_raw = $db->query("SELECT student_id, fee_head_id, custom_amount FROM fin_student_settings WHERE session='$session'")->fetch_all(MYSQLI_ASSOC);
        $overrides = [];
        foreach($overrides_raw as $o) $overrides[$o['student_id']][$o['fee_head_id']] = (float)$o['custom_amount'];

        $txns_raw = $db->query("SELECT student_id, SUM(CASE WHEN payment_mode IN ('cash','upi','cheque','bank_transfer') THEN amount_paid ELSE 0 END) as total_paid, SUM(CASE WHEN payment_mode = 'discount' THEN amount_paid ELSE 0 END) as total_discount FROM fin_transactions WHERE session='$session' AND status='completed' GROUP BY student_id")->fetch_all(MYSQLI_ASSOC);
        $txns = [];
        foreach($txns_raw as $t) $txns[$t['student_id']] = ['paid' => (float)$t['total_paid'], 'discount' => (float)$t['total_discount']];

        $extras_raw = $db->query("SELECT fi.student_id, SUM(fih.amount) as extra_amount FROM fin_invoice_heads fih JOIN fin_invoices fi ON fi.id = fih.invoice_id JOIN fin_fee_heads fh ON fh.id = fih.fee_head_id WHERE fi.session='$session' AND fh.is_extra=1 GROUP BY fi.student_id")->fetch_all(MYSQLI_ASSOC);
        $extras = [];
        foreach($extras_raw as $e) $extras[$e['student_id']] = (float)$e['extra_amount'];

        // 2. Calculate Exact Yearly Due for Everyone
        $result = [];
        foreach($students as $s) {
            $sid = $s['id'];
            $cid = $s['class_id'];
            $is_new = (int)$s['is_new_admission'] === 1;
            $uses_transport = (int)$s['uses_transport'] === 1;
            $is_staff = $s['fee_waiver_type'] === 'staff';

            $projected_due = 0;
            foreach($heads as $h) {
                $n_l = strtolower($h['name']);
                if (strpos($n_l, 'admission') !== false && !$is_new) continue;
                if (strpos($n_l, 'kit') !== false) {
                    if ($is_new && strpos($n_l, 'old') !== false) continue;
                    if (!$is_new && strpos($n_l, 'new') !== false) continue;
                }
                if (strpos($n_l, 'transport') !== false && !$uses_transport) continue;

                $hid = $h['id'];
                $base = $matrix[$cid][$hid] ?? $h['preset_amount'];
                $cust = (isset($overrides[$sid][$hid]) && $overrides[$sid][$hid] > 0) ? $overrides[$sid][$hid] : $base;
                if ($cust <= 0) continue;

                if ($h['type'] === 'monthly') {
                    if (strpos($n_l, 'transport') !== false) {
                        // --- SMART SPLIT LOGIC FOR TRANSPORT FEE ---
                        if ($cust == 750) {
                            $projected_due += ((600 * 2) + (750 * 9));
                        } else {
                            $projected_due += ($cust * 11);
                        }
                    } else {
                        $projected_due += ($cust * 12);
                    }
                } else {
                    $projected_due += $cust;
                }
            }
            $projected_due += ($extras[$sid] ?? 0);

            $paid = $txns[$sid]['paid'] ?? 0;
            $discount = $txns[$sid]['discount'] ?? 0;

            if ($is_staff) $discount = $projected_due; // Auto offset for targets to ignore staff

            $result[] = [
                'id' => $s['id'],
                'name' => $s['name'],
                'class_name' => $classes[$cid]['name'] ?? 'Unknown',
                'sort_order' => $classes[$cid]['sort_order'] ?? 0,
                'contact' => $s['contact'],
                'father_name' => $s['father_name'],
                'primary_payer_id' => $s['primary_payer_id'],
                'total_due' => $projected_due,
                'total_paid' => $paid,
                'total_discount' => $discount,
                'is_staff' => $is_staff
            ];
        }

        // 3. Aggregate by Family (Primary Payer logic)
        $fams = [];
        foreach($result as $r) {
            $pid = $r['primary_payer_id'] ? $r['primary_payer_id'] : $r['id'];
            if (!isset($fams[$pid])) $fams[$pid] = ['due'=>0, 'paid'=>0, 'disc'=>0, 'deps'=>[]];
            $fams[$pid]['due'] += $r['total_due'];
            $fams[$pid]['paid'] += $r['total_paid'];
            $fams[$pid]['disc'] += $r['total_discount'];
            if ($r['primary_payer_id']) $fams[$pid]['deps'][] = $r['name'];
        }

        // 4. Return only the actionable Primary accounts
        $final = [];
        foreach($result as $r) {
            if (!$r['primary_payer_id']) {
                $pid = $r['id'];
                $r['family_due'] = $fams[$pid]['due'];
                $r['family_paid'] = $fams[$pid]['paid'];
                $r['family_discount'] = $fams[$pid]['disc'];
                $r['dependents'] = $fams[$pid]['deps'];
                $final[] = $r;
            }
        }
        
        usort($final, function($a, $b) { return $b['sort_order'] <=> $a['sort_order']; });

        json_out(['success' => true, 'students' => $final]);
    }

    // --- DISCOUNT TRACKING ---
    case 'get_discount_tracking': {
        require_fin_access($db);
        $session = $db->real_escape_string(get_session($db));
        
        $r = $db->query("
            SELECT ft.id, ft.amount_paid, ft.remarks, ft.created_at, s.name AS student_name, s.father_name, s.fee_waiver_type, c.name AS class_name, s.id as student_id
            FROM fin_transactions ft
            JOIN students s ON s.id = ft.student_id
            JOIN classes c ON c.id = s.class_id
            WHERE ft.session='$session' AND ft.payment_mode='discount' AND ft.status='completed'
            ORDER BY ft.created_at DESC
        ");
        json_out(['success' => true, 'discounts' => $r->fetch_all(MYSQLI_ASSOC)]);
    }

    // =========================================================================
    // --- GENERAL EXPENSE ENDPOINTS ---
    // =========================================================================
    case 'add_expense': {
        try {
            $admin = require_fin_access($db);
            $title = $db->real_escape_string(trim($body['title'] ?? ''));
            $amount = (float)($body['amount'] ?? 0);
            $remarks = $db->real_escape_string(trim($body['remarks'] ?? ''));
            $date = $db->real_escape_string($body['expense_date'] ?? date('Y-m-d'));
            
            // STRICT SESSION LOCK
            $session = $db->real_escape_string($body['session'] ?? get_session($db));

            if (!$title || $amount <= 0) json_out(['success'=>false, 'message'=>'Valid title and amount are required.']);

            $db->query("CREATE TABLE IF NOT EXISTS fin_general_expenses (
                id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255) NOT NULL, amount DECIMAL(10,2) NOT NULL,
                remarks TEXT, expense_date DATE NOT NULL, session VARCHAR(20) NOT NULL, created_by INT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");

            $insert = $db->query("INSERT INTO fin_general_expenses (title, amount, remarks, expense_date, session, created_by) 
                        VALUES ('$title', $amount, '$remarks', '$date', '$session', {$admin['id']})");
            
            if (!$insert) throw new Exception('Insert Error: ' . $db->error);
            json_out(['success'=>true, 'message'=>'Expense logged successfully']);
        } catch (Throwable $e) { json_out(['success'=>false, 'message'=>'PHP Error: ' . $e->getMessage()]); }
    }

    case 'get_expenses': {
        try {
            $admin = require_fin_access($db);
            // STRICT SESSION LOCK
            $session_key = trim($_GET['session'] ?? '');
            if (!$session_key) $session_key = get_session($db);
            $session = $db->real_escape_string($session_key);
            
            $db->query("CREATE TABLE IF NOT EXISTS fin_general_expenses (
                id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255) NOT NULL, amount DECIMAL(10,2) NOT NULL,
                remarks TEXT, expense_date DATE NOT NULL, session VARCHAR(20) NOT NULL, created_by INT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");

            $res = $db->query("SELECT e.*, a.name as created_by_name FROM fin_general_expenses e LEFT JOIN admins a ON a.id = e.created_by WHERE e.session='$session' ORDER BY e.expense_date DESC, e.id DESC");
            if (!$res) throw new Exception('Query Error: ' . $db->error);
            $list = $res->fetch_all(MYSQLI_ASSOC);
            
            $total = 0; $this_month = 0; $today = 0;
            $current_m = date('Y-m'); $current_d = date('Y-m-d');
            
            foreach($list as $e) {
                $amt = (float)$e['amount'];
                $total += $amt;
                if (substr($e['expense_date'], 0, 7) === $current_m) $this_month += $amt;
                if ($e['expense_date'] === $current_d) $today += $amt;
            }

            json_out(['success'=>true, 'expenses'=>$list, 'stats'=>['total'=>$total, 'this_month'=>$this_month, 'today'=>$today]]);
        } catch (Throwable $e) { json_out(['success'=>false, 'message'=>'PHP Error: ' . $e->getMessage()]); }
    }

    // =========================================================================
    // --- TRANSPORT & FUEL ADVANCE SYSTEM ---
    // =========================================================================
    case 'get_transport_data': {
        try {
            require_fin_access($db);
            // STRICT SESSION LOCK
            $session_key = trim($_GET['session'] ?? '');
            if (!$session_key) $session_key = get_session($db);
            $session = $db->real_escape_string($session_key);

            $db->query("CREATE TABLE IF NOT EXISTS fin_vehicles (
                id INT AUTO_INCREMENT PRIMARY KEY, vehicle_no VARCHAR(50) NOT NULL, driver_name VARCHAR(100) NOT NULL, is_active INT DEFAULT 1
            )");
            $db->query("CREATE TABLE IF NOT EXISTS fin_fuel_advances (
                id INT AUTO_INCREMENT PRIMARY KEY, vehicle_id INT NOT NULL, amount DECIMAL(10,2) NOT NULL, date_given DATE NOT NULL, 
                status VARCHAR(20) DEFAULT 'pending', remarks TEXT, session VARCHAR(20) NOT NULL, created_by INT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
            $db->query("CREATE TABLE IF NOT EXISTS fin_fuel_logs (
                id INT AUTO_INCREMENT PRIMARY KEY, vehicle_id INT NOT NULL, advance_id INT NULL, amount DECIMAL(10,2) NOT NULL, 
                rate DECIMAL(10,2) NOT NULL, litres DECIMAL(10,2) NOT NULL, fuel_date DATE NOT NULL, remarks TEXT, 
                session VARCHAR(20) NOT NULL, created_by INT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
            $db->query("CREATE TABLE IF NOT EXISTS fin_vehicle_documents (
                id INT AUTO_INCREMENT PRIMARY KEY, vehicle_id INT NOT NULL, doc_name VARCHAR(255) NOT NULL, file_url VARCHAR(500) NOT NULL,
                issue_date DATE NULL, expiry_date DATE NOT NULL, session VARCHAR(20) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");

            $vehicles = $db->query("SELECT * FROM fin_vehicles WHERE is_active=1 ORDER BY id DESC")->fetch_all(MYSQLI_ASSOC);
            $advances = $db->query("SELECT a.*, v.vehicle_no, v.driver_name FROM fin_fuel_advances a JOIN fin_vehicles v ON v.id = a.vehicle_id WHERE a.session='$session' AND a.status='pending' ORDER BY a.date_given DESC")->fetch_all(MYSQLI_ASSOC);
            $logs = $db->query("SELECT l.*, v.vehicle_no, v.driver_name FROM fin_fuel_logs l JOIN fin_vehicles v ON v.id = l.vehicle_id WHERE l.session='$session' ORDER BY l.fuel_date DESC, l.id DESC")->fetch_all(MYSQLI_ASSOC);
            $docs = $db->query("SELECT d.*, v.vehicle_no FROM fin_vehicle_documents d JOIN fin_vehicles v ON v.id = d.vehicle_id")->fetch_all(MYSQLI_ASSOC);

            $last_rate_res = $db->query("SELECT rate FROM fin_fuel_logs ORDER BY id DESC LIMIT 1");
            $last_rate = $last_rate_res && $last_rate_res->num_rows > 0 ? (float)$last_rate_res->fetch_assoc()['rate'] : 89.50;

            $staff_list = [];
            try {
                $staff_q = $db->query("SELECT id, name FROM staff_directory ORDER BY name ASC");
                if($staff_q) $staff_list = $staff_q->fetch_all(MYSQLI_ASSOC);
            } catch(Throwable $e) {}

            json_out(['success'=>true, 'vehicles'=>$vehicles, 'pending_advances'=>$advances, 'fuel_logs'=>$logs, 'documents'=>$docs, 'last_rate'=>$last_rate, 'staff_list'=>$staff_list]);
        } catch (Throwable $e) { json_out(['success'=>false, 'message'=>'PHP Error: ' . $e->getMessage()]); }
    }

    case 'upload_vehicle_document': {
        try {
            require_fin_access($db);
            $vid = (int)($_POST['vehicle_id'] ?? 0);
            $doc_name = $db->real_escape_string(trim($_POST['doc_name'] ?? ''));
            $issue_date = $db->real_escape_string($_POST['issue_date'] ?? '');
            $expiry_date = $db->real_escape_string($_POST['expiry_date'] ?? '');
            $session = $db->real_escape_string($_POST['session'] ?? get_session($db));

            if (!$vid || !$doc_name || !$expiry_date) {
                json_out(['success'=>false, 'message'=>'Vehicle, Document Name, and Expiry Date are required.']);
            }

            $file_url = '';
            if (isset($_FILES['document']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg','jpeg','png','pdf'];
                if(!in_array($ext, $allowed)) json_out(['success'=>false, 'message'=>'Invalid file format. Only JPG, PNG, and PDF allowed.']);
                
                $filename = 'veh_doc_' . $vid . '_' . time() . '_' . rand(100,999) . '.' . $ext;
                $dest_dir = '../GMPSimages/documents/';
                if (!is_dir($dest_dir)) mkdir($dest_dir, 0777, true);
                
                if (move_uploaded_file($_FILES['document']['tmp_name'], $dest_dir . $filename)) {
                    $file_url = 'GMPSimages/documents/' . $filename;
                }
            }

            if (!$file_url) json_out(['success'=>false, 'message'=>'Please select a valid file to upload.']);

            $issue_sql = $issue_date ? "'$issue_date'" : "NULL";
            $db->query("INSERT INTO fin_vehicle_documents (vehicle_id, doc_name, file_url, issue_date, expiry_date, session) 
                        VALUES ($vid, '$doc_name', '$file_url', $issue_sql, '$expiry_date', '$session')");
            
            json_out(['success'=>true, 'message'=>'Document uploaded successfully.']);
        } catch (Throwable $e) { json_out(['success'=>false, 'message'=>'PHP Error: ' . $e->getMessage()]); }
    }

    case 'delete_vehicle_document': {
        try {
            require_fin_access($db);
            $id = (int)($body['id'] ?? 0);
            $db->query("DELETE FROM fin_vehicle_documents WHERE id=$id");
            json_out(['success'=>true]);
        } catch (Throwable $e) { json_out(['success'=>false, 'message'=>'PHP Error: ' . $e->getMessage()]); }
    }

    case 'save_vehicle': {
        try {
            require_fin_access($db);
            $vid = (int)($body['id'] ?? 0);
            $v_no = $db->real_escape_string(trim($body['vehicle_no'] ?? ''));
            $driver = $db->real_escape_string(trim($body['driver_name'] ?? ''));
            if (!$v_no || !$driver) json_out(['success'=>false, 'message'=>'Vehicle number and Driver name are required.']);
            if ($vid > 0) { $db->query("UPDATE fin_vehicles SET vehicle_no='$v_no', driver_name='$driver' WHERE id=$vid"); } 
            else { $db->query("INSERT INTO fin_vehicles (vehicle_no, driver_name) VALUES ('$v_no', '$driver')"); }
            json_out(['success'=>true]);
        } catch (Throwable $e) { json_out(['success'=>false, 'message'=>'PHP Error: ' . $e->getMessage()]); }
    }

    case 'delete_vehicle': {
        try {
            require_fin_access($db);
            $vid = (int)($body['id'] ?? 0);
            $db->query("UPDATE fin_vehicles SET is_active=0 WHERE id=$vid");
            json_out(['success'=>true]);
        } catch (Throwable $e) { json_out(['success'=>false, 'message'=>'PHP Error: ' . $e->getMessage()]); }
    }

    case 'delete_fuel_advance': {
        try {
            require_fin_access($db);
            $id = (int)($body['id'] ?? 0);
            $db->query("DELETE FROM fin_fuel_advances WHERE id=$id");
            json_out(['success'=>true]);
        } catch (Throwable $e) { json_out(['success'=>false, 'message'=>'PHP Error: ' . $e->getMessage()]); }
    }

    case 'delete_fuel_log': {
        try {
            require_fin_access($db);
            $id = (int)($body['id'] ?? 0);
            $db->query("DELETE FROM fin_fuel_logs WHERE id=$id");
            json_out(['success'=>true]);
        } catch (Throwable $e) { json_out(['success'=>false, 'message'=>'PHP Error: ' . $e->getMessage()]); }
    }

    case 'give_fuel_advance': {
        try {
            $admin = require_fin_access($db);
            $vid = (int)($body['vehicle_id'] ?? 0);
            $amt = (float)($body['amount'] ?? 0);
            $date = $db->real_escape_string($body['date_given'] ?? date('Y-m-d'));
            $rem = $db->real_escape_string(trim($body['remarks'] ?? ''));
            $session = $db->real_escape_string($body['session'] ?? get_session($db));

            if (!$vid || $amt <= 0) json_out(['success'=>false, 'message'=>'Valid vehicle and amount required.']);

            $db->query("INSERT INTO fin_fuel_advances (vehicle_id, amount, date_given, remarks, session, created_by) 
                        VALUES ($vid, $amt, '$date', '$rem', '$session', {$admin['id']})");
            json_out(['success'=>true, 'message'=>'Advance cash logged. Awaiting bill.']);
        } catch (Throwable $e) { json_out(['success'=>false, 'message'=>'PHP Error: ' . $e->getMessage()]); }
    }

    case 'log_fuel_bill': {
        try {
            $admin = require_fin_access($db);
            $vid = (int)($body['vehicle_id'] ?? 0);
            $adv_id = (int)($body['advance_id'] ?? 0);
            $amt = (float)($body['amount'] ?? 0);
            $rate = (float)($body['rate'] ?? 0);
            $litres = (float)($body['litres'] ?? 0);
            $date = $db->real_escape_string($body['fuel_date'] ?? date('Y-m-d'));
            $rem = $db->real_escape_string(trim($body['remarks'] ?? ''));
            $session = $db->real_escape_string($body['session'] ?? get_session($db));

            if (!$vid || $amt <= 0 || $rate <= 0) json_out(['success'=>false, 'message'=>'Invalid billing parameters.']);

            $db->query("INSERT INTO fin_fuel_logs (vehicle_id, advance_id, amount, rate, litres, fuel_date, remarks, session, created_by) 
                        VALUES ($vid, " . ($adv_id > 0 ? $adv_id : "NULL") . ", $amt, $rate, $litres, '$date', '$rem', '$session', {$admin['id']})");

            if ($adv_id > 0) {
                $db->query("UPDATE fin_fuel_advances SET status='cleared' WHERE id=$adv_id");
            }

            $v_res = $db->query("SELECT vehicle_no FROM fin_vehicles WHERE id=$vid");
            $v_no = $v_res ? $v_res->fetch_assoc()['vehicle_no'] : 'Unknown Vehicle';
            
            $master_title = "Diesel/Fuel - " . $v_no;
            $master_rem = "Fuel Log: $litres L @ ₹$rate. " . $rem;
            
            $db->query("CREATE TABLE IF NOT EXISTS fin_general_expenses (
                id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255) NOT NULL, amount DECIMAL(10,2) NOT NULL,
                remarks TEXT, expense_date DATE NOT NULL, session VARCHAR(20) NOT NULL, created_by INT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
            $db->query("INSERT INTO fin_general_expenses (title, amount, remarks, expense_date, session, created_by) 
                        VALUES ('$master_title', $amt, '$master_rem', '$date', '$session', {$admin['id']})");

            json_out(['success'=>true, 'message'=>'Fuel bill verified and advance cleared!']);
        } catch (Throwable $e) { json_out(['success'=>false, 'message'=>'PHP Error: ' . $e->getMessage()]); }
    }
    
    // =========================================================================
    // --- SOFTWARE SERVICES EXPENSE SYSTEM ---
    // =========================================================================

    case 'get_software_services_data': {
        try {
            require_fin_access($db);
            $session = $db->real_escape_string($body['session'] ?? $_GET['session'] ?? get_session($db));

            // Auto-create table safely
            $db->query("CREATE TABLE IF NOT EXISTS fin_software_payments (
                id INT AUTO_INCREMENT PRIMARY KEY, amount DECIMAL(10,2) NOT NULL, 
                payment_date DATE NOT NULL, remarks TEXT, reference_no VARCHAR(100), 
                session VARCHAR(20) NOT NULL, created_by INT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");

            // Get total active students
            $st_res = $db->query("SELECT COUNT(id) as total_students FROM students WHERE status='active'");
            $total_students = $st_res && $st_res->num_rows > 0 ? (int)$st_res->fetch_assoc()['total_students'] : 0;

            $cost_per_student = 200;
            $total_billed = $total_students * $cost_per_student;

            // Fetch history for this session
            $pay_res = $db->query("SELECT p.*, a.name as created_by_name FROM fin_software_payments p LEFT JOIN admins a ON a.id = p.created_by WHERE p.session='$session' ORDER BY p.payment_date DESC, p.id DESC");
            $payments = $pay_res ? $pay_res->fetch_all(MYSQLI_ASSOC) : [];

            $total_paid = 0;
            foreach($payments as $p) {
                $total_paid += (float)$p['amount'];
            }

            $total_due = $total_billed - $total_paid;

            json_out([
                'success' => true, 
                'stats' => [
                    'total_students' => $total_students,
                    'cost_per_student' => $cost_per_student,
                    'total_billed' => $total_billed,
                    'total_paid' => $total_paid,
                    'total_due' => $total_due
                ],
                'payments' => $payments
            ]);

        } catch (Throwable $e) { json_out(['success'=>false, 'message'=>'PHP Error: ' . $e->getMessage()]); }
    }

    case 'add_software_payment': {
        try {
            $admin = require_fin_access($db);
            $amount = (float)($body['amount'] ?? 0);
            $date = $db->real_escape_string($body['payment_date'] ?? date('Y-m-d'));
            $remarks = $db->real_escape_string(trim($body['remarks'] ?? ''));
            $ref = $db->real_escape_string(trim($body['reference_no'] ?? ''));
            $session = $db->real_escape_string($body['session'] ?? get_session($db));

            if ($amount <= 0) json_out(['success'=>false, 'message'=>'Valid amount is required.']);

            // Log in the dedicated software table
            $insert = $db->query("INSERT INTO fin_software_payments (amount, payment_date, remarks, reference_no, session, created_by) 
                        VALUES ($amount, '$date', '$remarks', '$ref', '$session', {$admin['id']})");
            
            if (!$insert) throw new Exception('Insert Error: ' . $db->error);

            // Inject into General Expenses for Master Tracking
            $master_title = "Software Services Subscription";
            $master_rem = "Ref: $ref. $remarks";
            
            $db->query("CREATE TABLE IF NOT EXISTS fin_general_expenses (
                id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255) NOT NULL, amount DECIMAL(10,2) NOT NULL,
                remarks TEXT, expense_date DATE NOT NULL, session VARCHAR(20) NOT NULL, created_by INT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
            $db->query("INSERT INTO fin_general_expenses (title, amount, remarks, expense_date, session, created_by) 
                        VALUES ('$master_title', $amount, '$master_rem', '$date', '$session', {$admin['id']})");

            json_out(['success'=>true, 'message'=>'Payment logged successfully']);
        } catch (Throwable $e) { json_out(['success'=>false, 'message'=>'PHP Error: ' . $e->getMessage()]); }
    }

    case 'delete_software_payment': {
        try {
            require_fin_access($db);
            $id = (int)($body['id'] ?? 0);
            $db->query("DELETE FROM fin_software_payments WHERE id=$id");
            json_out(['success'=>true]);
        } catch (Throwable $e) { json_out(['success'=>false, 'message'=>'PHP Error: ' . $e->getMessage()]); }
    }
    
    // =========================================================================
    default:
        json_out(['success'=>false,'message'=>'Unknown action: ' . htmlspecialchars($action)], 404);
}