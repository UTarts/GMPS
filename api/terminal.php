<?php
error_reporting(0);
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
// 1. GET ALL TEACHERS (For Registration & Matching)
if ($action === 'get_teachers') {
    $res = $conn->query("SELECT id, name, face_descriptor FROM teachers");
    $teachers = [];
    while($row = $res->fetch_assoc()) {
        $teachers[] = $row;
    }
    echo json_encode(['status' => 'success', 'data' => $teachers]);
    exit;
}

// 2. REGISTER FACE DESCRIPTOR
if ($action === 'register_face') {
    $teacher_id = (int)$input['teacher_id'];
    $descriptor = $conn->real_escape_string($input['descriptor']); // JSON string of 128 floats
    
    $stmt = $conn->prepare("UPDATE teachers SET face_descriptor = ? WHERE id = ?");
    $stmt->bind_param("si", $descriptor, $teacher_id);
    
    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'Face registered successfully!']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to register face.']);
    }
    exit;
}

// 3. PUNCH IN / OUT
if ($action === 'punch') {
    $teacher_id = (int)$input['teacher_id'];
    $type = $input['type']; // 'in' or 'out'
    $date = date('Y-m-d');
    $time = date('H:i:s');

    // Check existing record for today
    $check = $conn->query("SELECT punch_in, punch_out FROM teacher_attendance WHERE teacher_id = $teacher_id AND date = '$date'");
    $existing = $check->fetch_assoc();

    if ($type === 'in') {
        if ($existing && $existing['punch_in']) {
            echo json_encode(['status' => 'error', 'message' => 'Already Punched IN today at ' . date('h:i A', strtotime($existing['punch_in']))]);
            exit;
        }
        $stmt = $conn->prepare("INSERT INTO teacher_attendance (teacher_id, date, punch_in, status) VALUES (?, ?, ?, 'present')");
        $stmt->bind_param("iss", $teacher_id, $date, $time);
    } else {
        if (!$existing || !$existing['punch_in']) {
            echo json_encode(['status' => 'error', 'message' => 'Cannot Punch OUT. You must Punch IN first!']);
            exit;
        }
        if ($existing['punch_out']) {
            echo json_encode(['status' => 'error', 'message' => 'Already Punched OUT today at ' . date('h:i A', strtotime($existing['punch_out']))]);
            exit;
        }
        $stmt = $conn->prepare("UPDATE teacher_attendance SET punch_out = ? WHERE teacher_id = ? AND date = ?");
        $stmt->bind_param("sis", $time, $teacher_id, $date);
    }

    if ($stmt->execute()) {
        $tRes = $conn->query("SELECT name FROM teachers WHERE id = $teacher_id");
        $tName = $tRes->fetch_assoc()['name'];
        echo json_encode(['status' => 'success', 'name' => $tName, 'time' => date('h:i A'), 'type' => $type]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Database error.']);
    }
    exit;
}
// 4. GET TODAY'S STATS
if ($action === 'get_today_stats') {
    $date = date('Y-m-d');
    
    // Get total teachers (Fail-safe: simplified query to avoid missing column errors)
    $total_res = $conn->query("SELECT COUNT(*) as total FROM teachers");
    $total = $total_res ? $total_res->fetch_assoc()['total'] : 0;
    
    // Get teachers present today (Fail-safe)
    $present_res = $conn->query("SELECT COUNT(DISTINCT teacher_id) as present FROM teacher_attendance WHERE date = '$date'");
    $present = $present_res ? $present_res->fetch_assoc()['present'] : 0;
    
    echo json_encode(['status' => 'success', 'data' => ['total' => $total, 'present' => $present]]);
    exit;
}
?>