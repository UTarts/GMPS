<?php
@session_start();
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
    die("Database connection failed: " . $conn->connect_error);
}

// =======================================================
// PERSISTENT LOGIN LOGIC (REMEMBER ME)
// =======================================================
function setRememberMe($conn, $user_id, $user_type) {
    // 1. Generate a random token
    $token = bin2hex(random_bytes(32));
    $token_hash = hash('sha256', $token);
    $expiry = date('Y-m-d H:i:s', time() + (86400 * 30)); // 30 Days

    // 2. Store in DB
    $stmt = $conn->prepare("INSERT INTO login_tokens (token_hash, user_type, user_id, expiry) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssis", $token_hash, $user_type, $user_id, $expiry);
    $stmt->execute();

    // 3. Set Cookie (HTTP Only)
    setcookie('remember_token', $token, time() + (86400 * 30), "/", "", false, true);
}

// Check for cookie if session is empty
if (empty($_SESSION['userType']) && isset($_COOKIE['remember_token'])) {
    $token = $_COOKIE['remember_token'];
    $token_hash = hash('sha256', $token);
    
    // Find token in DB
    $stmt = $conn->prepare("SELECT user_type, user_id FROM login_tokens WHERE token_hash = ? AND expiry > NOW()");
    $stmt->bind_param("s", $token_hash);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($row = $res->fetch_assoc()) {
        $type = $row['user_type'];
        $uid = $row['user_id'];

        // Log them in based on type
        if ($type === 'student') {
            $u = $conn->query("SELECT * FROM students WHERE id = $uid")->fetch_assoc();
            if ($u) { $_SESSION['student_id'] = $uid; $_SESSION['userType'] = 'student'; }
        } 
        elseif ($type === 'teacher') {
            $u = $conn->query("SELECT t.*, c.name as class_name FROM teachers t LEFT JOIN classes c ON t.assigned_class_id = c.id WHERE t.id = $uid")->fetch_assoc();
            if ($u) {
                $_SESSION['teacher'] = $u;
                $_SESSION['userType'] = 'teacher';
                if($u['assigned_class_id']) {
                    $_SESSION['teacher']['assigned_class_id'] = $u['assigned_class_id'];
                    $_SESSION['teacher']['assigned_class_name'] = $u['class_name'];
                }
                // Load subjects
                $subQ = $conn->query("SELECT s.name FROM teacher_subjects ts JOIN subjects s ON ts.subject_code=s.code WHERE ts.teacher_id=$uid");
                $names=[]; while($s=$subQ->fetch_assoc()) $names[]=$s['name'];
                $_SESSION['teacher']['subjects'] = implode(', ', $names);
            }
        } 
        elseif ($type === 'admin') {
            $u = $conn->query("SELECT * FROM admins WHERE id = $uid")->fetch_assoc();
            if ($u) {
                $_SESSION['userType'] = 'admin';
                $_SESSION['adminUser'] = ['id'=>$u['id'], 'loginId'=>$u['login_id'], 'name'=>$u['name'], 'contact'=>$u['contact'], 'level'=>$u['level'], 'profilePic'=>$u['profile_pic']];
            }
        }
    }
}
?>