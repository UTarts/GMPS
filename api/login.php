<?php
require_once 'config.php';

$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['userid']) || !isset($data['password']) || !isset($data['role'])) {
    echo json_encode(["status" => "error", "message" => "Missing credentials"]);
    exit;
}

$userid = $conn->real_escape_string($data['userid']);
$password = $data['password'];
$role = $data['role']; 

$response = ["status" => "error", "message" => "Invalid credentials"];

if ($role === 'student') {
    if (!isset($data['class_id'])) {
        echo json_encode(["status" => "error", "message" => "Class selection required"]);
        exit;
    }
    $class_id = (int)$data['class_id'];
    
    // Added class_id to selection to be safe
    $sql = "SELECT id, name, dob, roll_no, login_id, password_hash, profile_pic, class_id FROM students WHERE login_id='$userid' AND class_id=$class_id";
    $result = $conn->query($sql);
    
    if ($result && $row = $result->fetch_assoc()) {
        if (password_verify($password, $row['password_hash'])) {
            // Fetch Class Name for Student Profile
            $c_res = $conn->query("SELECT name FROM classes WHERE id = " . $row['class_id']);
            $c_row = $c_res->fetch_assoc();
            
            $response = [
                "status" => "success",
                "role" => "student",
                "user" => [
                    "id" => $row['id'],
                    "name" => $row['name'],
                    "pic" => $row['profile_pic'],
                    "dob" => $row['dob'],
                    "roll_no" => $row['roll_no'],
                    "class_id" => $row['class_id'],
                    "class_name" => $c_row['name'] ?? ''
                ]
            ];
        }
    }

} elseif ($role === 'teacher') {
    // --- FIX STARTS HERE ---
    // Join classes to get the class name properly
    $sql = "SELECT t.id, t.name, t.login_id, t.password_hash, t.profile_pic, t.assigned_class_id, c.name as assigned_class_name 
            FROM teachers t 
            LEFT JOIN classes c ON t.assigned_class_id = c.id 
            WHERE t.login_id='$userid'";
    $result = $conn->query($sql);
    
    if ($result && $row = $result->fetch_assoc()) {
        if (password_verify($password, $row['password_hash'])) {
            $response = [
                "status" => "success",
                "role" => "teacher",
                "user" => [
                    "id" => $row['id'],
                    "name" => $row['name'],
                    "pic" => $row['profile_pic'],
                    "is_classteacher" => !empty($row['assigned_class_id']),
                    // CRITICAL: Send the ID and Name so the App knows WHICH class
                    "assigned_class_id" => $row['assigned_class_id'],
                    "assigned_class_name" => $row['assigned_class_name']
                ]
            ];
        }
    }
    // --- FIX ENDS HERE ---

} elseif ($role === 'admin') {
    $sql = "SELECT id, name, login_id, password_hash, profile_pic, level FROM admins WHERE login_id='$userid'";
    $result = $conn->query($sql);
    
    if ($result && $row = $result->fetch_assoc()) {
        if (password_verify($password, $row['password_hash'])) {
            $response = [
                "status" => "success",
                "role" => "admin",
                "user" => [
                    "id" => $row['id'],
                    "name" => $row['name'],
                    "pic" => $row['profile_pic'],
                    "level" => $row['level']
                ]
            ];
        }
    }
}

echo json_encode($response);
?>