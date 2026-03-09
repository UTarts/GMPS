<?php
error_reporting(0);
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");

require_once 'config.php';

$action = $_POST['action'] ?? '';

// 1. Fetch data for initial load
if ($action === 'fetch_init') {
    $role = $_POST['role'] ?? '';
    $user_id = (int)($_POST['user_id'] ?? 0);
    $class_id = (int)($_POST['class_id'] ?? 0); // Sent if student
    
    $response = ['status' => 'success', 'classes' => [], 'assignments' => []];
    
    // If teacher, fetch classes for the checkboxes
    if ($role === 'teacher') {
        $c_res = $conn->query("SELECT id, name FROM classes ORDER BY sort_order");
        while($c = $c_res->fetch_assoc()) $response['classes'][] = $c;
        
        // Fetch only assignments this teacher created
        $a_sql = "SELECT * FROM special_assignments WHERE teacher_id = $user_id ORDER BY created_at DESC";
    } else {
        // If student, fetch assignments where their class_id is in the comma-separated list
        $a_sql = "
            SELECT a.*, t.name as teacher_name, t.profile_pic as teacher_pic 
            FROM special_assignments a 
            JOIN teachers t ON a.teacher_id = t.id 
            WHERE FIND_IN_SET('$class_id', a.class_ids) > 0 
            ORDER BY a.created_at DESC
        ";
    }
    
    $a_res = $conn->query($a_sql);
    if($a_res) {
        while($a = $a_res->fetch_assoc()) {
            $a['formatted_date'] = date('d M Y', strtotime($a['created_at']));
            $a['due_date_formatted'] = $a['due_date'] ? date('d M Y', strtotime($a['due_date'])) : 'No Due Date';
            $response['assignments'][] = $a;
        }
    }
    
    echo json_encode($response);
    exit;
}

// 2. Upload new Assignment
if ($action === 'upload_assignment') {
    $teacher_id = (int)$_POST['teacher_id'];
    $title = $conn->real_escape_string($_POST['title']);
    $desc = $conn->real_escape_string($_POST['description']);
    $due_date = !empty($_POST['due_date']) ? "'".$conn->real_escape_string($_POST['due_date'])."'" : "NULL";
    $class_ids = $conn->real_escape_string($_POST['class_ids']); // e.g., "1,3,4"
    
    $attachment_url = "NULL";
    
    // Handle File Upload
    if (!empty($_FILES['file']['tmp_name'])) {
        $upload_dir = '../uploads/assignments/';
        if(!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
        
        $ext = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
        $new_name = 'assignment_' . time() . '_' . rand(100,999) . '.' . $ext;
        
        if (move_uploaded_file($_FILES['file']['tmp_name'], $upload_dir . $new_name)) {
            $attachment_url = "'uploads/assignments/$new_name'";
        }
    }
    
    $sql = "INSERT INTO special_assignments (teacher_id, title, description, due_date, attachment_url, class_ids) 
            VALUES ($teacher_id, '$title', '$desc', $due_date, $attachment_url, '$class_ids')";
            
    if($conn->query($sql)) {
        echo json_encode(['status'=>'success']);
    } else {
        echo json_encode(['status'=>'error', 'message'=>$conn->error]);
    }
    exit;
}

// 3. Delete Assignment
if ($action === 'delete_assignment') {
    $id = (int)$_POST['id'];
    $teacher_id = (int)$_POST['teacher_id']; // Security check
    
    $conn->query("DELETE FROM special_assignments WHERE id = $id AND teacher_id = $teacher_id");
    echo json_encode(['status'=>'success']);
    exit;
}
?>