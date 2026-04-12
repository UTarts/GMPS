<?php
date_default_timezone_set('Asia/Kolkata');
header("Access-Control-Allow-Origin: *"); 
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

// 2. Handle "Preflight" requests (Browser checking permission)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
// $servername = "localhost";
// $dbUsername = "root";    
// $dbPassword = "";       
// $dbName     = "gmps_db";
$servername = "localhost"; 
$dbUsername = "u355175815_gmps"; 
$dbPassword = "Ut@860302"; 
$dbName     = "u355175815_gmps_db"; 

$conn = new mysqli($servername, $dbUsername, $dbPassword, $dbName);

if ($conn->connect_error) {
    echo json_encode(["status" => "error", "message" => "Database connection failed"]);
    exit;
}
$conn->query("SET time_zone = '+05:30'");

session_start();
// Helper to get current session from settings table
function getCurrentSession($conn) {
    $res = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'current_session' LIMIT 1");
    if ($res && $row = $res->fetch_assoc()) {
        return $row['setting_value'];
    }
    return date('Y') . '-' . (date('Y') + 1); // Fallback if table is empty
}
?>