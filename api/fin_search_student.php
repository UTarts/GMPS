<?php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
require_once 'config.php';

$q = isset($_GET['q']) ? $conn->real_escape_string($_GET['q']) : '';

if (strlen($q) < 2) {
    echo json_encode(['status' => 'success', 'data' => []]);
    exit;
}

// Search by student name, father's name, or exact roll number
$sql = "SELECT s.id, s.name, s.father_name, s.roll_no, s.profile_pic, c.name as class_name 
        FROM students s 
        LEFT JOIN classes c ON s.class_id = c.id 
        WHERE (s.name LIKE '%$q%' OR s.father_name LIKE '%$q%' OR s.roll_no = '$q') 
        AND s.status = 'active' 
        LIMIT 8";

$res = $conn->query($sql);
$data = [];

if ($res) {
    while($row = $res->fetch_assoc()) {
        $data[] = $row;
    }
}

echo json_encode(['status' => 'success', 'data' => $data]);
?>