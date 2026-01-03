<?php
// api/config.php

// 1. Allow Access from your Next.js App (CORS)
header("Access-Control-Allow-Origin: *"); // For dev, we allow all. In production, we will lock this to your domain.
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

// 2. Handle "Preflight" requests (Browser checking permission)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
$servername = "localhost";
$dbUsername = "root";    
$dbPassword = "";       
$dbName     = "gmps_db";
// $servername = "localhost"; 
// $dbUsername = "u355175815_gmps"; 
// $dbPassword = "Ut@860302"; 
// $dbName     = "u355175815_gmps_db"; 

$conn = new mysqli($servername, $dbUsername, $dbPassword, $dbName);

if ($conn->connect_error) {
    echo json_encode(["status" => "error", "message" => "Database connection failed"]);
    exit;
}

session_start();
?>