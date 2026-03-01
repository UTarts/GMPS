<?php
// api/accounts_api.php
error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/session_manager.php';

$user = null;
$input = json_decode(file_get_contents("php://input"), true);

// Auth Check
if (isset($_SESSION['adminUser']) && ($_SESSION['adminUser']['level'] == 1 || $_SESSION['adminUser']['level'] == 5)) {
    $user = $_SESSION['adminUser'];
} else {
    if ($_SERVER['REQUEST_METHOD'] !== 'OPTIONS') { echo json_encode(['status'=>'error']); exit; }
}

$active_session = isset($_GET['session']) ? $conn->real_escape_string($_GET['session']) : get_current_session($conn);
if (isset($input['session'])) $active_session = $conn->real_escape_string($input['session']);

$action = $_GET['action'] ?? $input['action'] ?? '';

// --- 1. FEE STRUCTURE MANAGER ---
if ($action === 'get_fee_master') {
    $sql = "SELECT c.id as class_id, c.name as class_name, 
            COALESCE(f.tuition_fee, 0) as tuition_fee,
            COALESCE(f.annual_fee, 0) as annual_fee,
            COALESCE(f.exam_fee, 0) as exam_fee
            FROM classes c 
            LEFT JOIN acc_fee_master f ON c.id = f.class_id AND f.session = '$active_session'
            ORDER BY c.sort_order";
    $res = $conn->query($sql);
    $data = [];
    while($row = $res->fetch_assoc()) $data[] = $row;
    echo json_encode(['status'=>'success', 'data'=>$data]);
    exit;
}

if ($action === 'save_fee_master') {
    $cid = (int)$input['class_id'];
    $tf = (float)$input['tuition_fee'];
    $af = (float)$input['annual_fee'];
    $ef = (float)$input['exam_fee'];
    
    // Upsert (Insert or Update)
    $sql = "INSERT INTO acc_fee_master (class_id, session, tuition_fee, annual_fee, exam_fee) 
            VALUES ($cid, '$active_session', $tf, $af, $ef)
            ON DUPLICATE KEY UPDATE tuition_fee=$tf, annual_fee=$af, exam_fee=$ef";
    $conn->query($sql);
    echo json_encode(['status'=>'success']);
    exit;
}

// --- 2. STUDENT MANAGER (Transport & History) ---
if ($action === 'get_student_details') {
    $sid = (int)$_GET['student_id'];
    
    // A. Basic Info
    $stu = $conn->query("SELECT s.*, c.name as class_name FROM students s JOIN classes c ON s.class_id=c.id WHERE s.id=$sid")->fetch_assoc();
    
    // B. Transport Fee Setting
    $trans_res = $conn->query("SELECT transport_fee FROM acc_student_settings WHERE student_id=$sid AND session='$active_session'");
    $transport_fee = ($trans_res->num_rows > 0) ? (float)$trans_res->fetch_assoc()['transport_fee'] : 0;
    
    // C. Ledger History (Calculation of Due)
    $ledger_sql = "SELECT * FROM acc_ledger WHERE student_id=$sid AND session='$active_session' ORDER BY created_at DESC";
    $l_res = $conn->query($ledger_sql);
    $history = [];
    $total_debit = 0;
    $total_credit = 0;
    
    while($row = $l_res->fetch_assoc()) {
        $history[] = $row;
        if($row['type'] == 'debit') $total_debit += $row['amount'];
        if($row['type'] == 'credit') $total_credit += $row['amount'];
    }
    
    $current_balance = $total_debit - $total_credit;

    echo json_encode([
        'status'=>'success', 
        'student'=>$stu, 
        'transport_fee'=>$transport_fee,
        'balance' => $current_balance,
        'history' => $history
    ]);
    exit;
}

if ($action === 'update_transport') {
    $sid = (int)$input['student_id'];
    $amt = (float)$input['amount'];
    
    $sql = "INSERT INTO acc_student_settings (student_id, session, transport_fee) 
            VALUES ($sid, '$active_session', $amt)
            ON DUPLICATE KEY UPDATE transport_fee=$amt";
    $conn->query($sql);
    echo json_encode(['status'=>'success']);
    exit;
}

// --- 3. PAYMENT COLLECTION CALCULATOR ---
if ($action === 'get_dues_breakdown') {
    // This logic calculates what "Months" are unpaid based on Ledger.
    // NOTE: In a pure ledger, we don't track "Months" strictly, but we can simulate it 
    // by checking "Monthly Fee Demands" vs "Total Paid".
    
    $sid = (int)$_GET['student_id'];
    
    // 1. Get Monthly Fee Rate & Transport Rate
    $stu = $conn->query("SELECT class_id FROM students WHERE id=$sid")->fetch_assoc();
    $fee_res = $conn->query("SELECT tuition_fee FROM acc_fee_master WHERE class_id={$stu['class_id']} AND session='$active_session'");
    $monthly_fee = ($fee_res->num_rows > 0) ? (float)$fee_res->fetch_assoc()['tuition_fee'] : 0;
    
    $trans_res = $conn->query("SELECT transport_fee FROM acc_student_settings WHERE student_id=$sid AND session='$active_session'");
    $trans_fee = ($trans_res->num_rows > 0) ? (float)$trans_res->fetch_assoc()['transport_fee'] : 0;

    echo json_encode([
        'status'=>'success',
        'monthly_fee' => $monthly_fee,
        'transport_fee' => $trans_fee
    ]);
    exit;
}

if ($action === 'collect_payment_complex') {
    $sid = (int)$input['student_id'];
    $paid_amount = (float)$input['paid_amount']; // 4000
    $total_due = (float)$input['total_due']; // 4500 (Visual only)
    $remarks = $conn->real_escape_string($input['remarks']);
    $months = $input['months']; // Array of strings e.g. ["April", "May"]
    $is_discount = isset($input['is_discount']) ? true : false;
    
    $admin_id = $user['id'];

    if ($is_discount) {
        // DISCOUNT LOGIC
        $stmt = $conn->prepare("INSERT INTO acc_ledger (student_id, session, type, amount, category, description) VALUES (?, ?, 'credit', ?, 'discount', ?)");
        $desc = "Discount: $remarks";
        $stmt->bind_param("isds", $sid, $active_session, $paid_amount, $desc);
        $stmt->execute();
    } else {
        // PAYMENT LOGIC
        // 1. Create Transaction Receipt
        $stmt = $conn->prepare("INSERT INTO acc_transactions (student_id, amount, mode, status, verified_by, verified_at, remarks) VALUES (?, ?, 'cash', 'approved', ?, NOW(), ?)");
        $stmt->bind_param("idss", $sid, $paid_amount, $admin_id, $remarks);
        $stmt->execute();
        $tid = $stmt->insert_id;

        // 2. Add to Ledger (Credit)
        // Even if bill was 4500 and they paid 4000, we just credit 4000. 
        // The balance (500) remains automatically in the ledger math.
        $desc = "Cash Payment (Months: " . implode(", ", $months) . ")";
        
        $stmt2 = $conn->prepare("INSERT INTO acc_ledger (student_id, session, type, amount, category, description, transaction_ref_id) VALUES (?, ?, 'credit', ?, 'payment_cash', ?, ?)");
        $stmt2->bind_param("isdsi", $sid, $active_session, $paid_amount, $desc, $tid);
        $stmt2->execute();
    }

    echo json_encode(['status'=>'success']);
    exit;
}

// (Keep previous actions: get_stats, search_student, etc.)
// ... [Include the previous search_student code here] ...
if ($action === 'search_student') {
    $q = $conn->real_escape_string($_GET['query']);
    $sql = "SELECT s.id, s.name, s.father_name, c.name as class_name, s.profile_pic 
            FROM students s 
            JOIN classes c ON s.class_id = c.id 
            WHERE s.name LIKE '%$q%' OR s.login_id LIKE '%$q%' LIMIT 5";
    $res = $conn->query($sql);
    $data = [];
    while($row = $res->fetch_assoc()) $data[] = $row;
    echo json_encode(['status' => 'success', 'data' => $data]);
    exit;
}
?>