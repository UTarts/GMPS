<?php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
require_once 'config.php';

// Accept a specific date, default to today if none is provided
$target_date = isset($_GET['date']) ? $conn->real_escape_string($_GET['date']) : date('Y-m-d');
$response = ['status' => 'success', 'date' => $target_date, 'classes' => []];

// Smart Query: Fetch attendance for the specific Target Date
$sql = "SELECT c.id as class_id, c.name as class_name, 
               s.id as student_id, s.name as student_name, s.roll_no, s.profile_pic,
               da.status as attendance_status
        FROM classes c
        LEFT JOIN students s ON c.id = s.class_id AND s.status = 'active'
        LEFT JOIN daily_attendance da ON s.id = da.student_id AND da.date = '$target_date'
        ORDER BY c.sort_order, s.roll_no";

$res = $conn->query($sql);
$dashboard = [];

while ($row = $res->fetch_assoc()) {
    $cid = $row['class_id'];
    
    if (!isset($dashboard[$cid])) {
        $dashboard[$cid] = [
            'class_id' => $cid,
            'class_name' => $row['class_name'],
            'stats' => ['total' => 0, 'present' => 0, 'absent' => 0, 'unmarked' => 0],
            'students' => ['present' => [], 'absent' => [], 'unmarked' => []]
        ];
    }

    if ($row['student_id']) {
        $dashboard[$cid]['stats']['total']++;
        $status = $row['attendance_status'] ? $row['attendance_status'] : 'unmarked';

        if ($status === 'present' || $status === 'late') {
            $dashboard[$cid]['stats']['present']++;
            $dashboard[$cid]['students']['present'][] = $row;
        } elseif ($status === 'absent') {
            $dashboard[$cid]['stats']['absent']++;
            $dashboard[$cid]['students']['absent'][] = $row;
        } else {
            $dashboard[$cid]['stats']['unmarked']++;
            $dashboard[$cid]['students']['unmarked'][] = $row;
        }
    }
}

$response['classes'] = array_values($dashboard);
echo json_encode($response);
?>