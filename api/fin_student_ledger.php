<?php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
require_once 'config.php';

$student_id = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
$session = isset($_GET['session']) ? $conn->real_escape_string($_GET['session']) : '2025-2026';

if (!$student_id) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid Student ID']);
    exit;
}

$response = [
    'status' => 'success',
    'student' => null,
    'summary' => ['total_due' => 0, 'total_paid' => 0],
    'invoices' => [],
    'transactions' => []
];

// 1. Get Student Details
$sql_stu = "SELECT s.id, s.name, s.father_name, s.contact, s.profile_pic, s.roll_no, c.name as class_name 
            FROM students s 
            LEFT JOIN classes c ON s.class_id = c.id 
            WHERE s.id = $student_id";
$res_stu = $conn->query($sql_stu);
if ($res_stu && $res_stu->num_rows > 0) {
    $response['student'] = $res_stu->fetch_assoc();
} else {
    echo json_encode(['status' => 'error', 'message' => 'Student not found']);
    exit;
}

// 2. Get Invoices (Pending Dues)
$sql_inv = "SELECT * FROM fin_invoices WHERE student_id = $student_id AND session = '$session' ORDER BY invoice_month ASC";
$res_inv = $conn->query($sql_inv);
if ($res_inv) {
    while($row = $res_inv->fetch_assoc()) {
        $response['invoices'][] = $row;
        if ($row['status'] !== 'paid') {
            $response['summary']['total_due'] += ($row['total_due'] - $row['total_paid']);
        }
    }
}

// 3. Get Transaction History
$sql_txn = "SELECT * FROM fin_transactions WHERE student_id = $student_id ORDER BY payment_date DESC LIMIT 15";
$res_txn = $conn->query($sql_txn);
if ($res_txn) {
    while($row = $res_txn->fetch_assoc()) {
        $response['transactions'][] = $row;
        $response['summary']['total_paid'] += $row['amount_paid'];
    }
}

echo json_encode($response);
?>