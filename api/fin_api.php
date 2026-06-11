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
    $r = $db->query("SELECT setting_value FROM settings WHERE setting_key='fin_current_session' LIMIT 1");
    return $r ? ($r->fetch_assoc()['setting_value'] ?? '2025-2026') : '2025-2026';
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
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

switch ($action) {

    // ════════════════════════════════════════════════════════════════
    // ACCOUNTANT / ADMIN ENDPOINTS
    // ════════════════════════════════════════════════════════════════

    case 'get_dashboard_stats': {
        $admin   = require_fin_access($db);
        $session = get_session($db);

        $r = $db->query("SELECT COALESCE(SUM(amount_paid),0) AS total FROM fin_transactions WHERE session='$session' AND status='completed'");
        $total_collected = (float)$r->fetch_assoc()['total'];

        $r = $db->query("SELECT COALESCE(SUM(total_due - total_paid),0) AS due FROM fin_invoices WHERE session='$session' AND status != 'paid'");
        $total_due = (float)$r->fetch_assoc()['due'];

        $r = $db->query("SELECT COUNT(*) AS c FROM fin_submissions WHERE session='$session' AND status='pending'");
        $pending = (int)$r->fetch_assoc()['c'];

        $last_month = date('n', strtotime('-1 month'));
        $last_year  = date('Y', strtotime('-1 month'));
        $r = $db->query("SELECT COUNT(DISTINCT student_id) AS c FROM fin_invoices WHERE session='$session' AND invoice_month=$last_month AND invoice_year=$last_year AND status='unpaid'");
        $defaulters = (int)$r->fetch_assoc()['c'];

        $chart = [];
        for ($i = 11; $i >= 0; $i--) {
            $ts  = strtotime("-$i months");
            $m   = date('n', $ts);
            $y   = date('Y', $ts);
            $lbl = date('M Y', $ts);
            $r   = $db->query("SELECT COALESCE(SUM(amount_paid),0) AS amt FROM fin_transactions WHERE MONTH(payment_date)=$m AND YEAR(payment_date)=$y AND status='completed'");
            $chart[] = ['label' => $lbl, 'amount' => (float)$r->fetch_assoc()['amt']];
        }

        $r = $db->query("
            SELECT c.name AS class_name,
                   COUNT(DISTINCT s.id) AS student_count,
                   COALESCE(SUM(fi.total_due),0) AS total_due,
                   COALESCE(SUM(fi.total_paid),0) AS total_paid
            FROM classes c
            JOIN students s ON s.class_id = c.id AND s.status='active'
            LEFT JOIN fin_invoices fi ON fi.student_id = s.id AND fi.session='$session'
            GROUP BY c.id ORDER BY c.sort_order
        ");
        $class_data = $r->fetch_all(MYSQLI_ASSOC);

        $today    = date('Y-m-d');
        $r        = $db->query("SELECT COALESCE(SUM(amount_paid),0) AS amt, COUNT(*) AS txns FROM fin_transactions WHERE payment_date='$today' AND status='completed'");
        $today_row = $r->fetch_assoc();

        json_out([
            'success' => true,
            'stats'   => [
                'total_collected'    => $total_collected,
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
        $classes = [];
        $cr = $db->query("SELECT id, name FROM classes ORDER BY name ASC");
        while ($row = $cr->fetch_assoc()) $classes[] = $row;

        $heads = [];
        $hr = $db->query("SELECT id, name, frequency, is_optional FROM fin_fee_heads WHERE is_active=1 ORDER BY sort_order ASC, id ASC");
        while ($row = $hr->fetch_assoc()) $heads[] = $row;

        $rows = [];
        $mr = $db->query("SELECT class_id, fee_head_id, amount FROM fin_fee_matrix");
        while ($row = $mr->fetch_assoc()) $rows[] = $row;

        json_out(['success' => true, 'classes' => $classes, 'fee_heads' => $heads, 'matrix_rows' => $rows]);
    }

    case 'save_fee_amounts': {
        require_fin_access($db);
        $raw  = $_POST['rows'] ?? ($body['rows'] ?? '[]');
        $rows = is_string($raw) ? json_decode($raw, true) : $raw;
        if (empty($rows)) json_out(['success' => false, 'message' => 'No data provided.']);
        foreach ($rows as $r) {
            $cid    = (int)$r['class_id'];
            $hid    = (int)$r['fee_head_id'];
            $amount = (float)$r['amount'];
            $check  = $db->query("SELECT id FROM fin_fee_matrix WHERE class_id=$cid AND fee_head_id=$hid LIMIT 1")->fetch_assoc();
            if ($check) {
                $db->query("UPDATE fin_fee_matrix SET amount=$amount WHERE class_id=$cid AND fee_head_id=$hid");
            } else {
                $db->query("INSERT INTO fin_fee_matrix (class_id, fee_head_id, amount) VALUES ($cid, $hid, $amount)");
            }
        }
        json_out(['success' => true]);
    }

    case 'add_fee_head': {
        require_fin_access($db);
        $name      = $db->real_escape_string(trim($_POST['name'] ?? $body['name'] ?? ''));
        $frequency = $db->real_escape_string($_POST['frequency'] ?? $body['frequency'] ?? 'yearly');
        $optional  = (int)($_POST['is_optional'] ?? $body['is_optional'] ?? 0);
        if (!$name) json_out(['success' => false, 'message' => 'Name required.']);
        $db->query("INSERT INTO fin_fee_heads (name, frequency, is_optional, is_active) VALUES ('$name','$frequency',$optional,1)");
        json_out(['success' => true]);
    }

    case 'update_fee_head': {
        require_fin_access($db);
        $id        = (int)($_POST['id'] ?? $body['id'] ?? 0);
        $name      = $db->real_escape_string(trim($_POST['name'] ?? $body['name'] ?? ''));
        $frequency = $db->real_escape_string($_POST['frequency'] ?? $body['frequency'] ?? 'yearly');
        $optional  = (int)($_POST['is_optional'] ?? $body['is_optional'] ?? 0);
        if (!$id || !$name) json_out(['success' => false, 'message' => 'Invalid data.']);
        $db->query("UPDATE fin_fee_heads SET name='$name', frequency='$frequency', is_optional=$optional WHERE id=$id");
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
        $db->query("UPDATE settings SET setting_value='$session' WHERE setting_key='fin_current_session'");
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

    default:
        json_out(['success'=>false,'message'=>'Unknown action: ' . htmlspecialchars($action)], 404);
}