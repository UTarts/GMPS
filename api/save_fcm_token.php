<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
require_once 'config.php';

$user_id = (int)$_POST['user_id'];
$role = $_POST['role'];
$token = $_POST['token'];
$device = $_POST['device_info'] ?? 'Unknown';

if ($user_id && $token) {
    $stmt = $conn->prepare("INSERT INTO fcm_tokens (user_id, role, token, device_info) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE updated_at = NOW()");
    $stmt->bind_param("isss", $user_id, $role, $token, $device);
    $stmt->execute();
}
echo json_encode(['status' => 'success']);
?>