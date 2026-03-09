<?php
error_reporting(0);
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");

require_once 'config.php';

$action = $_POST['action'] ?? '';

// 1. Fetch Dropdown Data for Teachers (Filtered by Class)
if ($action === 'get_form_data') {
    $cid = isset($_POST['class_id']) ? (int)$_POST['class_id'] : 0;
    $exams = [];
    $subjects = [];
    
    // Get Exams
    $e_res = $conn->query("SELECT id, name FROM exams ORDER BY id");
    if ($e_res) {
        while($e = $e_res->fetch_assoc()) $exams[] = $e;
    }
    
    // Get Mapped Subjects for this specific class
    if ($cid > 0) {
        $s_sql = "SELECT s.code, s.name FROM subjects s JOIN class_subjects cs ON s.code = cs.subject_code WHERE cs.class_id = $cid ORDER BY s.name";
        $s_res = $conn->query($s_sql);
        
        if ($s_res && $s_res->num_rows > 0) {
            while($s = $s_res->fetch_assoc()) $subjects[] = $s;
        } else {
            // SAFE FALLBACK: If no subjects are mapped yet, load all subjects so the app doesn't break
            $fall_res = $conn->query("SELECT code, name FROM subjects ORDER BY name");
            if ($fall_res) {
                while($s = $fall_res->fetch_assoc()) $subjects[] = $s;
            }
        }
    }
    
    echo json_encode(['status'=>'success', 'exams'=>$exams, 'subjects'=>$subjects]);
    exit;
}

// 2. Fetch Organized Syllabus for Students & Teachers
if ($action === 'get_syllabus') {
    $cid = (int)$_POST['class_id'];
    
    $sql = "SELECT s.id, s.exam_id, e.name as exam_name, s.subject_code, sub.name as subject_name, s.syllabus_text, DATE_FORMAT(s.last_updated, '%d %b %Y, %h:%i %p') as formatted_date 
            FROM class_syllabus s 
            JOIN exams e ON s.exam_id = e.id 
            JOIN subjects sub ON s.subject_code = sub.code 
            WHERE s.class_id = $cid 
            ORDER BY e.id ASC, sub.name ASC";
            
    $res = $conn->query($sql);
    $data = [];
    
    // Group exactly by Exam Name for the React Accordion
    while($row = $res->fetch_assoc()) {
        $exam_name = $row['exam_name'];
        if (!isset($data[$exam_name])) {
            $data[$exam_name] = [];
        }
        $data[$exam_name][] = $row;
    }
    
    echo json_encode(['status'=>'success', 'data'=>$data]);
    exit;
}

// 3. Save/Update Syllabus (And trigger notification concept)
if ($action === 'save_syllabus') {
    $cid = (int)$_POST['class_id'];
    $eid = (int)$_POST['exam_id'];
    $sub = $conn->real_escape_string($_POST['subject_code']);
    $text = $conn->real_escape_string($_POST['syllabus_text']);
    
    $sql = "INSERT INTO class_syllabus (class_id, exam_id, subject_code, syllabus_text) 
            VALUES ($cid, $eid, '$sub', '$text') 
            ON DUPLICATE KEY UPDATE syllabus_text = '$text', last_updated = CURRENT_TIMESTAMP";
            
    if($conn->query($sql)) {
        
        // --- NOTIFICATION ENGINE HOOK ---
        // You can link your Firebase Push Notification logic here later!
        // Example: sendPushToClass($cid, "Syllabus Updated", "New syllabus posted for $sub");
        
        echo json_encode(['status'=>'success']);
    } else {
        echo json_encode(['status'=>'error', 'message'=>$conn->error]);
    }
    exit;
}
?>