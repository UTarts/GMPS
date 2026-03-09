<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header('Content-Type: application/json');

require_once 'config.php';
require_once 'session_manager.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$current_session = get_current_session($conn);

// --- HELPER: File Upload ---
function uploadProof($fileKey) {
    if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) return null;
    $name = time() . '_leave_' . basename($_FILES[$fileKey]['name']);
    $target = __DIR__ . '/../GMPSimages/' . $name;
    if(move_uploaded_file($_FILES[$fileKey]['tmp_name'], $target)) {
        return 'GMPSimages/' . $name;
    }
    return null;
}

// 1. SUBMIT LEAVE APPLICATION
if ($action === 'apply_leave') {
    $user_id = (int)$_POST['user_id'];
    $role = $conn->real_escape_string($_POST['role']);
    $start_date = $conn->real_escape_string($_POST['start_date']);
    $end_date = $conn->real_escape_string($_POST['end_date']);
    $reason = $conn->real_escape_string($_POST['reason']);
    
    $proof_image = uploadProof('proof_image');
    
    $stmt = $conn->prepare("INSERT INTO leave_applications (user_id, role, session, start_date, end_date, reason, proof_image) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("issssss", $user_id, $role, $current_session, $start_date, $end_date, $reason, $proof_image);
    
    if($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'Leave application submitted successfully.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to submit application.']);
    }
    exit;
}

// 2. GET USER LEAVE HISTORY
if ($action === 'get_my_leaves') {
    $user_id = (int)$_GET['user_id'];
    $role = $conn->real_escape_string($_GET['role']);
    $session = $conn->real_escape_string($_GET['session'] ?? $current_session);
    
    $sql = "SELECT * FROM leave_applications WHERE user_id = $user_id AND role = '$role' AND session = '$session' ORDER BY applied_on DESC";
    $res = $conn->query($sql);
    
    $data = [];
    while($row = $res->fetch_assoc()) {
        $data[] = $row;
    }
    
    echo json_encode(['status' => 'success', 'data' => $data]);
    exit;
}

// 3. ADMIN: GET ALL PENDING LEAVES
if ($action === 'get_pending_leaves') {
    // Fetch students and teachers who applied
    $sql = "
        SELECT l.*, 
        CASE 
            WHEN l.role = 'student' THEN (SELECT name FROM students WHERE id = l.user_id)
            WHEN l.role = 'teacher' THEN (SELECT name FROM teachers WHERE id = l.user_id)
        END as applicant_name,
        CASE 
            WHEN l.role = 'student' THEN (SELECT c.name FROM students s JOIN classes c ON s.class_id = c.id WHERE s.id = l.user_id)
            WHEN l.role = 'teacher' THEN 'Staff'
        END as class_name
        FROM leave_applications l 
        WHERE l.status = 'pending' AND l.session = '$current_session' 
        ORDER BY l.applied_on ASC
    ";
    
    $res = $conn->query($sql);
    $data = [];
    while($row = $res->fetch_assoc()) $data[] = $row;
    
    echo json_encode(['status' => 'success', 'data' => $data]);
    exit;
}

// 4. ADMIN: UPDATE LEAVE STATUS
if ($action === 'update_status') {
    $leave_id = (int)$_POST['leave_id'];
    $status = $conn->real_escape_string($_POST['status']); // 'approved' or 'rejected'
    $admin_id = (int)$_POST['admin_id'];
    
    $stmt = $conn->prepare("UPDATE leave_applications SET status = ?, action_by = ?, action_on = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->bind_param("sii", $status, $admin_id, $leave_id);
    
    if($stmt->execute()) {
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error']);
    }
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
?>