<?php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
require_once 'config.php';

$session = $_GET['session'] ?? '2026-2027';

// 1. Today's Collection
$today = $conn->query("SELECT SUM(amount_paid) as total FROM fin_transactions WHERE payment_date = CURDATE()")->fetch_assoc();

// 2. Session Total Collection
$sess_total = $conn->query("SELECT SUM(amount_paid) as total FROM fin_transactions t JOIN fin_invoices i ON t.invoice_id = i.id WHERE i.session = '$session'")->fetch_assoc();

// 3. Total Dues (Pending)
$dues = $conn->query("SELECT SUM(total_due - total_paid) as total FROM fin_invoices WHERE session = '$session'")->fetch_assoc();

echo json_encode([
    'status' => 'success',
    'today' => (float)$today['total'],
    'session_total' => (float)$sess_total['total'],
    'pending_dues' => (float)$dues['total']
]);
?>