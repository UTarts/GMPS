<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header('Content-Type: application/json');
date_default_timezone_set('Asia/Kolkata');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once 'config.php';

$input = json_decode(file_get_contents("php://input"), true);
$user_id = isset($input['user_id']) ? (int)$input['user_id'] : 0;
$class_id = isset($input['class_id']) ? (int)$input['class_id'] : 0;

if ($user_id === 0) {
    echo json_encode(['status' => 'error', 'message' => 'User ID required']);
    exit;
}

// Security: Fetches only the last 30 days of notifications to keep the app fast
$sql = "SELECT id, title, body, url, DATE_FORMAT(created_at, '%d/%m/%Y') as date, created_at 
        FROM app_notifications 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        AND (
            target_type = 'all' 
            OR (target_type = 'class' AND target_ids = ?) 
            OR (target_type = 'users' AND FIND_IN_SET(?, target_ids))
        )
        ORDER BY created_at DESC LIMIT 50";

$stmt = $conn->prepare($sql);
$stmt->bind_param("is", $class_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

$notifications = [];
while ($row = $result->fetch_assoc()) {
    $notifications[] = $row;
}

echo json_encode(['status' => 'success', 'data' => $notifications]);
?>