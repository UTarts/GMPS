<?php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
require_once 'config.php';

$student_id = (int)$_GET['student_id'];
$session = getCurrentSession($conn); // Automated Session Lock
$start_year = (int)explode('-', $session)[0];

// 1. Get Student Data
$stu = $conn->query("SELECT class_id, admission_year FROM students WHERE id = $student_id")->fetch_assoc();
$class_id = $stu['class_id'];
$is_new_student = ((int)$stu['admission_year'] == $start_year);

// 2. Fetch Fees for this Class
$fees = [];
$res = $conn->query("SELECT fh.name, cf.amount FROM fin_class_fees cf JOIN fin_fee_heads fh ON cf.fee_head_id = fh.id WHERE cf.class_id = $class_id AND cf.session = '$session'");
while($r = $res->fetch_assoc()) $fees[$r['name']] = (float)$r['amount'];

// 3. Generate April to March Ledger
for ($i = 0; $i < 12; $i++) {
    $m = (($i + 3) % 12) + 1; 
    $y = ($m >= 4) ? $start_year : $start_year + 1;
    
    $monthly_total = $fees['Monthly Tuition'] ?? 0;
    
    // Add annual/one-time fees to April (Month 4)
    if ($m == 4) {
        $monthly_total += 3000; // Session Fee
        $monthly_total += 3500; // Exam Fee
        $monthly_total += 500;  // Online Services
        $monthly_total += $is_new_student ? 1000 : 500; // Kit Fee
        if ($is_new_student) $monthly_total += 3000;    // Admission Fee
    }

    $check = $conn->query("SELECT id FROM fin_invoices WHERE student_id = $student_id AND invoice_month = $m AND session = '$session'");
    if ($check->num_rows == 0) {
        $stmt = $conn->prepare("INSERT INTO fin_invoices (student_id, session, invoice_month, invoice_year, total_due) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("isiii", $student_id, $session, $m, $y, $monthly_total);
        $stmt->execute();
    }
}
echo json_encode(['status' => 'success', 'session_active' => $session]);
?>