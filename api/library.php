<?php
error_reporting(0);
// Set charset explicitly to prevent encoding crashes
header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");

require_once 'config.php';

$action = $_POST['action'] ?? '';

// Helper to format file sizes
function formatSizeUnits($bytes) {
    if ($bytes >= 1048576) { return number_format($bytes / 1048576, 2) . ' MB'; }
    elseif ($bytes >= 1024) { return number_format($bytes / 1024, 2) . ' KB'; }
    elseif ($bytes > 1) { return $bytes . ' bytes'; }
    elseif ($bytes == 1) { return $bytes . ' byte'; }
    return '0 bytes';
}

// Helper to strictly guarantee a string is valid UTF-8 for JSON encoding
function safe_string($str) {
    return mb_convert_encoding((string)$str, 'UTF-8', 'UTF-8');
}

// 1. FETCH INITIAL DATA
if ($action === 'fetch_init') {
    $role = strtolower(trim($_POST['role'] ?? ''));
    $user_id = (int)($_POST['user_id'] ?? 0);
    $class_id = (int)($_POST['class_id'] ?? 0); 
    
    $response = ['status' => 'success', 'classes' => [], 'subjects' => [], 'resources' => [], 'debug_log' => []];
    
    // FETCH SUBJECTS SAFELY (Captures multiple possible column names & enforces UTF-8)
    $s_res = $conn->query("SELECT * FROM subjects ORDER BY name ASC");
    if ($s_res) { 
        while($s = $s_res->fetch_assoc()) {
            $code = $s['code'] ?? $s['subject_code'] ?? $s['id'] ?? '';
            $name = $s['name'] ?? $s['subject_name'] ?? 'Unnamed Subject';
            $response['subjects'][] = [
                'code' => safe_string($code),
                'name' => safe_string($name)
            ]; 
        }
    } else {
        $response['debug_log'][] = "Subjects query failed: " . $conn->error;
    }

    // FETCH CLASSES SAFELY (Matches assignments.php logic perfectly)
    $c_res = $conn->query("SELECT id, name FROM classes ORDER BY sort_order ASC, id ASC");
    if ($c_res) { 
        while($c = $c_res->fetch_assoc()) {
            $response['classes'][] = [
                'id' => $c['id'],
                'name' => safe_string($c['name'] ?? 'Unnamed Class')
            ]; 
        }
    } else {
        $response['debug_log'][] = "Classes query failed: " . $conn->error;
    }

    // FETCH RESOURCES SAFELY
    if ($role === 'teacher' || $role === 'admin') {
        $r_sql = "
            SELECT r.*, t.name as teacher_name, t.profile_pic as teacher_pic, s.name as subject_name 
            FROM library_resources r 
            LEFT JOIN teachers t ON r.teacher_id = t.id 
            LEFT JOIN subjects s ON r.subject_code = s.code
            ORDER BY r.created_at DESC
        ";
    } else {
        $r_sql = "
            SELECT r.*, t.name as teacher_name, t.profile_pic as teacher_pic, s.name as subject_name 
            FROM library_resources r 
            LEFT JOIN teachers t ON r.teacher_id = t.id 
            LEFT JOIN subjects s ON r.subject_code = s.code
            WHERE FIND_IN_SET('$class_id', r.class_ids) > 0 
            ORDER BY r.created_at DESC
        ";
    }
    
    $r_res = $conn->query($r_sql);
    if ($r_res) {
        while($r = $r_res->fetch_assoc()) {
            $r['formatted_date'] = date('d M Y', strtotime($r['created_at']));
            $r['title'] = safe_string($r['title']);
            $r['description'] = safe_string($r['description']);
            $r['teacher_name'] = safe_string($r['teacher_name']);
            $r['subject_name'] = safe_string($r['subject_name']);
            $response['resources'][] = $r;
        }
    } else {
        $response['debug_log'][] = "Resources query failed: " . $conn->error;
    }
    
    // Safely output JSON. If JSON fails entirely, it returns a readable error instead of a blank screen.
    $jsonOutput = json_encode($response, JSON_INVALID_UTF8_SUBSTITUTE);
    if ($jsonOutput === false) {
        echo json_encode(['status' => 'error', 'message' => 'JSON Encode Error: ' . json_last_error_msg()]);
        exit;
    }
    
    echo $jsonOutput;
    exit;
}

// 2. UPLOAD RESOURCE
if ($action === 'upload_resource') {
    require_once 'NotificationService.php'; 
    
    $teacher_id = (int)$_POST['teacher_id'];
    $teacher_name = $conn->real_escape_string($_POST['teacher_name']);
    $title = $conn->real_escape_string($_POST['title']);
    $desc = $conn->real_escape_string($_POST['description']);
    $subject_code = $conn->real_escape_string($_POST['subject_code']);
    $class_ids = $conn->real_escape_string($_POST['class_ids']); 
    
    if (empty($_FILES['file']['tmp_name'])) {
        echo json_encode(['status'=>'error', 'message'=>'File is required']);
        exit;
    }

    $upload_dir = '../uploads/library/';
    if(!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
    
    $file_name = $_FILES['file']['name'];
    $file_size_raw = $_FILES['file']['size'];
    $file_size = formatSizeUnits($file_size_raw);
    $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
    
    $new_name = 'lib_' . time() . '_' . rand(100,999) . '.' . $ext;
    
    if (move_uploaded_file($_FILES['file']['tmp_name'], $upload_dir . $new_name)) {
        $file_url = "uploads/library/$new_name";
        
        $sql = "INSERT INTO library_resources (teacher_id, title, description, subject_code, file_url, file_type, file_size, class_ids) 
                VALUES ($teacher_id, '$title', '$desc', '$subject_code', '$file_url', '$ext', '$file_size', '$class_ids')";
                
        if($conn->query($sql)) {
            try {
                $notif = new NotificationService($conn);
                $classes_array = explode(',', $class_ids);
                $push_title = "📚 Digital Library Update";
                $push_body = "$teacher_name uploaded a new resource: $title.";
                foreach($classes_array as $cid) {
                    $notif->sendToClass($cid, $push_title, $push_body, ['route' => '/library']); 
                }
            } catch(Exception $e) { }

            echo json_encode(['status'=>'success']);
        } else {
            echo json_encode(['status'=>'error', 'message'=>'DB Error']);
        }
    } else {
        echo json_encode(['status'=>'error', 'message'=>'Failed to move uploaded file']);
    }
    exit;
}

// 3. DELETE RESOURCE
if ($action === 'delete_resource') {
    $id = (int)$_POST['id'];
    $teacher_id = (int)$_POST['teacher_id']; 
    
    $res = $conn->query("SELECT file_url FROM library_resources WHERE id = $id AND teacher_id = $teacher_id");
    if($res && $res->num_rows > 0) {
        $file = $res->fetch_assoc()['file_url'];
        if(file_exists("../" . $file)) unlink("../" . $file); 
        $conn->query("DELETE FROM library_resources WHERE id = $id");
        echo json_encode(['status'=>'success']);
    } else {
        echo json_encode(['status'=>'error', 'message'=>'Not authorized']);
    }
    exit;
}
?>