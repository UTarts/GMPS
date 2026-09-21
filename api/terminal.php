<?php
error_reporting(0);
date_default_timezone_set('Asia/Kolkata');
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

require_once 'config.php';

$input = json_decode(file_get_contents("php://input"), true);
$action = $input['action'] ?? '';

// 0. SECURE PIN VERIFICATION
if ($action === 'verify_pin') {
    $pin = $input['pin'] ?? '';
    // The secure 8-digit PIN
    $secure_pin = "62126636"; 
    
    if ($pin === $secure_pin) {
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error']);
    }
    exit;
}

// 1. GET ALL USERS (Hybrid: Teachers + Staff)
if ($action === 'get_teachers') {
    $users = [];
    
    // Fetch Teachers
    $resT = $conn->query("SELECT id, name, face_descriptor FROM teachers");
    while($row = $resT->fetch_assoc()) {
        $row['user_type'] = 'teacher'; // Tag as teacher
        $row['uid'] = 'teacher_' . $row['id']; // Unique ID for the scanner
        $users[] = $row;
    }

    // Fetch General Staff
    $resS = $conn->query("SELECT id, name, face_descriptor FROM staff_directory WHERE status='active'");
    if ($resS) {
        while($row = $resS->fetch_assoc()) {
            $row['user_type'] = 'staff'; // Tag as staff
            $row['uid'] = 'staff_' . $row['id']; // Unique ID for the scanner
            $users[] = $row;
        }
    }

    echo json_encode(['status' => 'success', 'data' => $users]);
    exit;
}

// 2. REGISTER FACE DESCRIPTOR (Routes to correct table)
if ($action === 'register_face') {
    $uid = $input['uid'] ?? ''; // Format: "teacher_12" or "staff_5"
    $descriptor = $conn->real_escape_string($input['descriptor']); 
    
    if (strpos($uid, 'teacher_') === 0) {
        $id = (int)str_replace('teacher_', '', $uid);
        $stmt = $conn->prepare("UPDATE teachers SET face_descriptor = ? WHERE id = ?");
        $stmt->bind_param("si", $descriptor, $id);
    } else if (strpos($uid, 'staff_') === 0) {
        $id = (int)str_replace('staff_', '', $uid);
        $stmt = $conn->prepare("UPDATE staff_directory SET face_descriptor = ? WHERE id = ?");
        $stmt->bind_param("si", $descriptor, $id);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid user type.']);
        exit;
    }
    
    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'Face registered successfully!']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to register face.']);
    }
    exit;
}

// 3. PUNCH IN / OUT (Hybrid Routing)
if ($action === 'punch') {
    $uid = $input['uid'] ?? ''; 
    $type = $input['type']; // 'in' or 'out'
    $date = date('Y-m-d');
    $time = date('H:i:s');

    $is_teacher = strpos($uid, 'teacher_') === 0;
    $id = (int)str_replace($is_teacher ? 'teacher_' : 'staff_', '', $uid);
    
    $table_att = $is_teacher ? 'teacher_attendance' : 'staff_attendance';
    $col_id = $is_teacher ? 'teacher_id' : 'staff_id';
    $table_users = $is_teacher ? 'teachers' : 'staff_directory';

    // Check existing record for today
    $check = $conn->query("SELECT punch_in, punch_out FROM $table_att WHERE $col_id = $id AND date = '$date'");
    $existing = $check->fetch_assoc();

    if ($type === 'in') {
        if ($existing && $existing['punch_in']) {
            echo json_encode(['status' => 'error', 'message' => 'Already Punched IN today at ' . date('h:i A', strtotime($existing['punch_in']))]);
            exit;
        }
        $stmt = $conn->prepare("INSERT INTO $table_att ($col_id, date, punch_in, status) VALUES (?, ?, ?, 'present')");
        $stmt->bind_param("iss", $id, $date, $time);
    } else {
        if (!$existing || !$existing['punch_in']) {
            echo json_encode(['status' => 'error', 'message' => 'Cannot Punch OUT. You must Punch IN first!']);
            exit;
        }
        if ($existing['punch_out']) {
            echo json_encode(['status' => 'error', 'message' => 'Already Punched OUT today at ' . date('h:i A', strtotime($existing['punch_out']))]);
            exit;
        }
        $stmt = $conn->prepare("UPDATE $table_att SET punch_out = ? WHERE $col_id = ? AND date = ?");
        $stmt->bind_param("sis", $time, $id, $date);
    }

    if ($stmt->execute()) {
        $uRes = $conn->query("SELECT name FROM $table_users WHERE id = $id");
        $uName = $uRes->fetch_assoc()['name'];
        echo json_encode(['status' => 'success', 'name' => $uName, 'time' => date('h:i A'), 'type' => $type]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Database error.']);
    }
    exit;
}

// 4. GET TODAY'S STATS (Combined totals)
if ($action === 'get_today_stats') {
    $date = date('Y-m-d');
    
    // Total Users (Teachers + Active Staff)
    $t_res = $conn->query("SELECT COUNT(*) as c FROM teachers");
    $s_res = $conn->query("SELECT COUNT(*) as c FROM staff_directory WHERE status='active'");
    $total = ($t_res ? $t_res->fetch_assoc()['c'] : 0) + ($s_res ? $s_res->fetch_assoc()['c'] : 0);
    
    // Present Today (Teachers + Staff)
    $tp_res = $conn->query("SELECT COUNT(DISTINCT teacher_id) as c FROM teacher_attendance WHERE date = '$date'");
    $sp_res = $conn->query("SELECT COUNT(DISTINCT staff_id) as c FROM staff_attendance WHERE date = '$date'");
    $present = ($tp_res ? $tp_res->fetch_assoc()['c'] : 0) + ($sp_res ? $sp_res->fetch_assoc()['c'] : 0);
    
    echo json_encode(['status' => 'success', 'data' => ['total' => $total, 'present' => $present]]);
    exit;
}
?>