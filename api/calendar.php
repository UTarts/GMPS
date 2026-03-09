<?php
error_reporting(0);
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");

require_once 'config.php';

$session = $_GET['session'] ?? '2026-2027';

// Fetch all events for the requested session, ordered by date
$sql = "SELECT * FROM academic_calendar WHERE session = '$session' ORDER BY date_start ASC";
$res = $conn->query($sql);

$data = [];
if ($res && $res->num_rows > 0) {
    while ($row = $res->fetch_assoc()) {
        $data[] = $row;
    }
}

echo json_encode(['status' => 'success', 'data' => $data]);
?>