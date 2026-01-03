<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header('Content-Type: application/json');

require_once 'config.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// --- HELPER: File Upload ---
function uploadFile($fileKey) {
    if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) return null;
    $name = time() . '_' . basename($_FILES[$fileKey]['name']);
    $target = __DIR__ . '/../GMPSimages/' . $name;
    if(move_uploaded_file($_FILES[$fileKey]['tmp_name'], $target)) {
        return 'GMPSimages/' . $name;
    }
    return null;
}

// 1. DASHBOARD STATS
if ($action === 'get_stats') {
    $stats = [];
    $stats['students'] = $conn->query("SELECT COUNT(*) FROM students WHERE status='active'")->fetch_row()[0];
    $stats['teachers'] = $conn->query("SELECT COUNT(*) FROM teachers")->fetch_row()[0];
    echo json_encode(['status' => 'success', 'data' => $stats]);
    exit;
}

// 2. SUGGESTIONS
if ($action === 'get_suggestions') {
    $res = $conn->query("SELECT f.id, f.message, f.created_at, s.name, s.profile_pic, c.name as class_name FROM student_feedback f JOIN students s ON f.student_id = s.id JOIN classes c ON s.class_id = c.id ORDER BY f.created_at DESC");
    $data = [];
    while($r = $res->fetch_assoc()) $data[] = $r;
    echo json_encode(['status' => 'success', 'data' => $data]);
    exit;
}
if ($action === 'delete_suggestion') {
    $id = (int)$_POST['id'];
    $conn->query("DELETE FROM student_feedback WHERE id=$id");
    echo json_encode(['status' => 'success']);
    exit;
}

// 3. STUDENTS MANAGEMENT
if ($action === 'get_classes') {
    $res = $conn->query("SELECT id, name FROM classes ORDER BY sort_order");
    $data = [];
    while($r = $res->fetch_assoc()) $data[] = $r;
    echo json_encode(['status' => 'success', 'data' => $data]);
    exit;
}

if ($action === 'get_students') {
    $cid = (int)$_GET['class_id'];
    $res = $conn->query("SELECT id, name, roll_no, profile_pic FROM students WHERE class_id=$cid AND status='active' ORDER BY roll_no ASC");
    $data = [];
    while($r = $res->fetch_assoc()) $data[] = $r;
    echo json_encode(['status' => 'success', 'data' => $data]);
    exit;
}

if ($action === 'get_student_details') {
    $sid = (int)$_GET['id'];
    
    // A. Profile
    $profile = $conn->query("SELECT s.*, c.name as class_name FROM students s JOIN classes c ON s.class_id = c.id WHERE s.id=$sid")->fetch_assoc();
    
    // B. Attendance (Formatted for Month 1-12)
    $att_res = $conn->query("SELECT month, days_present, days_absent FROM attendance WHERE student_id=$sid AND year=YEAR(CURDATE())");
    $attendance = [];
    while($r = $att_res->fetch_assoc()) $attendance[(int)$r['month']] = $r;
    
    // C. Marks (Grouped by Exam)
    $exams = [];
    $ex_res = $conn->query("SELECT id, name, max_marks FROM exams ORDER BY id");
    while($ex = $ex_res->fetch_assoc()) {
        $eid = $ex['id'];
        // Fetch marks for this exam
        $m_res = $conn->query("SELECT m.marks_obtained, s.name as subject, s.code as subject_code FROM marks m JOIN subjects s ON m.subject_code=s.code WHERE m.student_id=$sid AND m.exam_id=$eid ORDER BY s.name");
        $ex_marks = [];
        while($m = $m_res->fetch_assoc()) $ex_marks[] = $m;
        
        $ex['results'] = $ex_marks;
        $exams[] = $ex;
    }

    echo json_encode(['status' => 'success', 'data' => ['profile'=>$profile, 'attendance'=>$attendance, 'exams'=>$exams]]);
    exit;
}

if ($action === 'save_student') {
    $id = (int)$_POST['id'];
    $name = $conn->real_escape_string($_POST['name']);
    $roll = !empty($_POST['roll_no']) ? (int)$_POST['roll_no'] : "NULL";
    $dob = !empty($_POST['dob']) ? "'".$conn->real_escape_string($_POST['dob'])."'" : "NULL";
    $father = $conn->real_escape_string($_POST['father_name']);
    $mother = $conn->real_escape_string($_POST['mother_name']);
    $contact = $conn->real_escape_string($_POST['contact']);
    $address = $conn->real_escape_string($_POST['address']);
    $login = $conn->real_escape_string($_POST['login_id']);
    
    $conn->query("UPDATE students SET name='$name', roll_no=$roll, dob=$dob, father_name='$father', mother_name='$mother', contact='$contact', address='$address', login_id='$login' WHERE id=$id");

    if (!empty($_POST['password'])) {
        $hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $conn->query("UPDATE students SET password_hash='$hash' WHERE id=$id");
    }

    $img = uploadFile('image');
    if ($img) $conn->query("UPDATE students SET profile_pic='$img' WHERE id=$id");

    echo json_encode(['status' => 'success']);
    exit;
}

if ($action === 'add_student') {
    $name = $conn->real_escape_string($_POST['name']);
    $login = $conn->real_escape_string($_POST['login_id']);
    $pass = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $class_id = (int)$_POST['class_id'];
    $roll = !empty($_POST['roll_no']) ? (int)$_POST['roll_no'] : null;
    $dob = !empty($_POST['dob']) ? $_POST['dob'] : null;
    $father = $conn->real_escape_string($_POST['father_name']);
    $mother = $conn->real_escape_string($_POST['mother_name']);
    $addr = $conn->real_escape_string($_POST['address']);
    $cont = $conn->real_escape_string($_POST['contact']);
    $year = !empty($_POST['admission_year']) ? (int)$_POST['admission_year'] : date('Y');
    
    $img = uploadFile('image') ?? 'GMPSimages/default_student.png';
    
    $stmt = $conn->prepare("INSERT INTO students (name, login_id, password_hash, class_id, roll_no, dob, father_name, mother_name, address, contact, admission_year, profile_pic, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')");
    $stmt->bind_param("sssiisssssss", $name, $login, $pass, $class_id, $roll, $dob, $father, $mother, $addr, $cont, $year, $img);
    
    if($stmt->execute()) echo json_encode(['status' => 'success']);
    else echo json_encode(['status' => 'error', 'message' => $conn->error]);
    exit;
}

if ($action === 'delete_student') {
    $id = (int)$_POST['id'];
    $conn->query("DELETE FROM marks WHERE student_id=$id");
    $conn->query("DELETE FROM attendance WHERE student_id=$id");
    $conn->query("DELETE FROM student_feedback WHERE student_id=$id");
    $conn->query("DELETE FROM students WHERE id=$id");
    echo json_encode(['status' => 'success']);
    exit;
}

// 4. TEACHERS MANAGEMENT
if ($action === 'get_teachers') {
    $res = $conn->query("SELECT t.id, t.name, t.profile_pic, t.assigned_class_id, c.name as assigned_class, (SELECT s.name FROM teacher_subjects ts JOIN subjects s ON ts.subject_code = s.code WHERE ts.teacher_id = t.id LIMIT 1) as subject FROM teachers t LEFT JOIN classes c ON t.assigned_class_id=c.id");
    $data = [];
    while($r = $res->fetch_assoc()) $data[] = $r;
    
    $subRes = $conn->query("SELECT code, name FROM subjects ORDER BY name");
    $subjects = [];
    while($s = $subRes->fetch_assoc()) $subjects[] = $s;

    echo json_encode(['status' => 'success', 'data' => $data, 'subjects' => $subjects]);
    exit;
}

if ($action === 'get_teacher_details') {
    $id = (int)$_GET['id'];
    $teacher = $conn->query("SELECT * FROM teachers WHERE id=$id")->fetch_assoc();
    $sub = $conn->query("SELECT subject_code FROM teacher_subjects WHERE teacher_id=$id LIMIT 1")->fetch_assoc();
    $teacher['subject_code'] = $sub ? $sub['subject_code'] : '';
    echo json_encode(['status' => 'success', 'data' => $teacher]);
    exit;
}

if ($action === 'save_teacher') {
    $id = (int)$_POST['id'];
    $name = $conn->real_escape_string($_POST['name']);
    $contact = $conn->real_escape_string($_POST['contact']);
    $login = $conn->real_escape_string($_POST['login_id']);
    $class_id = !empty($_POST['assigned_class_id']) ? (int)$_POST['assigned_class_id'] : "NULL";
    
    $conn->query("UPDATE teachers SET name='$name', contact='$contact', login_id='$login', assigned_class_id=$class_id WHERE id=$id");
    
    $conn->query("DELETE FROM teacher_subjects WHERE teacher_id=$id");
    if (!empty($_POST['subject_code'])) {
        $scode = $conn->real_escape_string($_POST['subject_code']);
        $conn->query("INSERT INTO teacher_subjects (teacher_id, subject_code) VALUES ($id, '$scode')");
    }

    if (!empty($_POST['password'])) {
        $hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $conn->query("UPDATE teachers SET password_hash='$hash' WHERE id=$id");
    }
    
    $img = uploadFile('image');
    if ($img) $conn->query("UPDATE teachers SET profile_pic='$img' WHERE id=$id");
    
    echo json_encode(['status' => 'success']);
    exit;
}

if ($action === 'add_teacher') {
    $name = $conn->real_escape_string($_POST['name']);
    $login = $conn->real_escape_string($_POST['login_id']);
    $pass = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $contact = $conn->real_escape_string($_POST['contact']);
    $class_id = !empty($_POST['assigned_class_id']) ? (int)$_POST['assigned_class_id'] : null;
    $img = uploadFile('image') ?? 'GMPSimages/default_teacher.png';
    
    $stmt = $conn->prepare("INSERT INTO teachers (name, login_id, password_hash, contact, profile_pic, assigned_class_id) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssssi", $name, $login, $pass, $contact, $img, $class_id);
    
    if($stmt->execute()) {
        $tid = $stmt->insert_id;
        if (!empty($_POST['subject_code'])) {
            $scode = $conn->real_escape_string($_POST['subject_code']);
            $conn->query("INSERT INTO teacher_subjects (teacher_id, subject_code) VALUES ($tid, '$scode')");
        }
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error']);
    }
    exit;
}

if ($action === 'delete_teacher') {
    $id = (int)$_POST['id'];
    $conn->query("DELETE FROM daily_posts WHERE teacher_id=$id");
    $conn->query("DELETE FROM teachers WHERE id=$id");
    echo json_encode(['status' => 'success']);
    exit;
}

// 5. ADMINS MANAGEMENT
if ($action === 'get_admins') {
    $res = $conn->query("SELECT * FROM admins ORDER BY level ASC");
    $data = [];
    while($r = $res->fetch_assoc()) $data[] = $r;
    echo json_encode(['status' => 'success', 'data' => $data]);
    exit;
}

if ($action === 'save_admin') {
    $id = (int)$_POST['id'];
    $name = $conn->real_escape_string($_POST['name']);
    $contact = $conn->real_escape_string($_POST['contact']);
    $login = $conn->real_escape_string($_POST['login_id']);
    $level = (int)$_POST['level'];
    
    $conn->query("UPDATE admins SET name='$name', contact='$contact', login_id='$login', level=$level WHERE id=$id");
    
    if (!empty($_POST['password'])) {
        $hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $conn->query("UPDATE admins SET password_hash='$hash' WHERE id=$id");
    }
    
    $img = uploadFile('image');
    if ($img) $conn->query("UPDATE admins SET profile_pic='$img' WHERE id=$id");
    
    echo json_encode(['status' => 'success']);
    exit;
}

if ($action === 'add_admin') {
    $name = $conn->real_escape_string($_POST['name']);
    $login = $conn->real_escape_string($_POST['login_id']);
    $pass = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $contact = $conn->real_escape_string($_POST['contact']);
    $level = (int)$_POST['level'];
    $img = uploadFile('image') ?? 'GMPSimages/default-admin.jpg';
    
    $stmt = $conn->prepare("INSERT INTO admins (name, login_id, password_hash, contact, level, profile_pic) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssis", $name, $login, $pass, $contact, $level, $img);
    $stmt->execute();
    echo json_encode(['status' => 'success']);
    exit;
}

if ($action === 'delete_admin') {
    $id = (int)$_POST['id'];
    $conn->query("DELETE FROM admins WHERE id=$id");
    echo json_encode(['status' => 'success']);
    exit;
}
?>