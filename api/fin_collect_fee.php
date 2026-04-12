<?php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
require_once 'config.php';
require_once 'NotificationService.php';

$data = json_decode(file_get_contents("php://input"), true);
$student_id = (int)$data['student_id'];
$total_received = (float)$data['amount'];
$collected_by = (int)$data['collected_by'];
$session = getCurrentSession($conn); // Automated Session Lock

if ($total_received <= 0) exit(json_encode(['status' => 'error', 'message' => 'Invalid amount']));

$conn->begin_transaction();
try {
    $receipt_no = 'RCPT-' . date('YmdHis') . '-' . rand(10, 99);
    $stmt = $conn->prepare("INSERT INTO fin_transactions (receipt_no, student_id, amount_paid, payment_mode, collected_by, payment_date) VALUES (?, ?, ?, 'cash', ?, CURDATE())");
    $stmt->bind_param("sidi", $receipt_no, $student_id, $total_received, $collected_by);
    $stmt->execute();

    $remaining = $total_received;
    // Process April -> March chronologically
    $inv_res = $conn->query("SELECT id, total_due, total_paid FROM fin_invoices WHERE student_id = $student_id AND session = '$session' AND status != 'paid' ORDER BY invoice_year ASC, invoice_month ASC");

    while ($inv = $inv_res->fetch_assoc()) {
        if ($remaining <= 0) break;
        $due = $inv['total_due'] - $inv['total_paid'];
        $pay = min($remaining, $due);
        $new_paid = $inv['total_paid'] + $pay;
        $status = ($new_paid >= $inv['total_due']) ? 'paid' : 'partial';
        $conn->query("UPDATE fin_invoices SET total_paid = $new_paid, status = '$status' WHERE id = " . $inv['id']);
        $remaining -= $pay;
    }

    $conn->commit();
    echo json_encode(['status' => 'success', 'receipt' => $receipt_no]);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>