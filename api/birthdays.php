<?php
error_reporting(0);
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
require_once 'config.php';

$response = ['status' => 'success', 'todays_birthdays' => [], 'is_my_birthday' => false];
$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

// Updated SQL to JOIN the classes table to get the actual class name
$sql = "SELECT s.id, s.name, s.profile_pic, c.name as class_name 
        FROM students s 
        LEFT JOIN classes c ON s.class_id = c.id 
        WHERE s.status='active' AND DATE_FORMAT(s.dob, '%m-%d') = DATE_FORMAT(CURDATE(), '%m-%d')";

if ($res = $conn->query($sql)) {
    while($row = $res->fetch_assoc()) {
        $response['todays_birthdays'][] = $row;
        if ($user_id === (int)$row['id']) {
            $response['is_my_birthday'] = true;
        }
    }
}
echo json_encode($response);
?>