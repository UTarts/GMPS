<?php
error_reporting(0);
date_default_timezone_set('Asia/Kolkata');
// 1. Bulletproof CORS Headers
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// 2. Handle browser preflight requests instantly
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once 'config.php';

$input = json_decode(file_get_contents("php://input"), true);
$action = $input['action'] ?? $_GET['action'] ?? '';

// 1. TEACHER: Get their own monthly record
if ($action === 'get_my_record') {
    $teacher_id = (int)$input['teacher_id'];
    $month = (int)$input['month'];
    $year = (int)$input['year'];
    
    // Format for SQL (e.g. '2026-03%')
    $date_prefix = sprintf("%04d-%02d", $year, $month);
    
    $sql = "SELECT date, punch_in, punch_out, status FROM teacher_attendance WHERE teacher_id = $teacher_id AND date LIKE '$date_prefix%' ORDER BY date DESC";
    $res = $conn->query($sql);
    
    $data = [];
    if($res) {
        while($row = $res->fetch_assoc()) $data[] = $row;
    }
    
    echo json_encode(['status' => 'success', 'data' => $data]);
    exit;
}

// 2. ADMIN: Get today's live tracker for all staff
if ($action === 'get_admin_live') {
    $date = $input['date'] ?? date('Y-m-d');
    
    $sql = "SELECT t.id, t.name, t.profile_pic, a.punch_in, a.punch_out, a.status 
            FROM teachers t 
            LEFT JOIN teacher_attendance a ON t.id = a.teacher_id AND a.date = '$date'
            ORDER BY a.punch_in DESC, t.name ASC";
            
    $res = $conn->query($sql);
    $data = [];
    if($res) {
        while($row = $res->fetch_assoc()) $data[] = $row;
    }
    
    echo json_encode(['status' => 'success', 'data' => $data]);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
?>