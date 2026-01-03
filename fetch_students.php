<?php
require_once __DIR__ . '/includes/db_connect.php';

if (isset($_GET['class_id'])) {
    $cid = (int)$_GET['class_id'];
    $res = $conn->query("SELECT id, name FROM students WHERE class_id = $cid AND status = 'active' ORDER BY name");
    
    $students = [];
    while ($row = $res->fetch_assoc()) {
        $students[] = $row;
    }
    
    header('Content-Type: application/json');
    echo json_encode($students);
}
?>