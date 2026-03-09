<?php
error_reporting(0);
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");

require_once 'config.php';
require_once 'NotificationService.php'; // <--- IMPORT SERVICE

$action = $_POST['action'] ?? '';
if (!$action) {
    $input = json_decode(file_get_contents("php://input"), true);
    $action = $input['action'] ?? '';
}

$tid = isset($_POST['teacher_id']) ? (int)$_POST['teacher_id'] : 0;
$notifier = new NotificationService($conn); // <--- INITIALIZE

// ... (fetch_dashboard remains the same) ...
if ($action === 'fetch_dashboard') {
    $t_res = $conn->query("SELECT t.id, t.name, t.profile_pic, t.contact, t.login_id, t.assigned_class_id, c.name as class_name FROM teachers t LEFT JOIN classes c ON t.assigned_class_id = c.id WHERE t.id = $tid");
    $teacher = $t_res->fetch_assoc();
    $students = []; $student_count = 0;
    if ($teacher['assigned_class_id']) {
        $cid = $teacher['assigned_class_id'];
        $s_res = $conn->query("SELECT id, name, roll_no, profile_pic, login_id FROM students WHERE class_id = $cid AND status = 'active' ORDER BY roll_no ASC");
        while($r = $s_res->fetch_assoc()) $students[] = $r;
        $student_count = count($students);
    }
    $classes_list = [];
    $c_res = $conn->query("SELECT id, name FROM classes ORDER BY sort_order");
    while($c = $c_res->fetch_assoc()) $classes_list[] = $c;
    echo json_encode(['status'=>'success', 'profile'=>$teacher, 'students'=>$students, 'student_count'=>$student_count, 'all_classes'=>$classes_list]);
    exit;
}

// ==================================================================
// PHASE 1.5: THE TIMELINE HISTORY API
// ==================================================================

if ($action === 'fetch_work_history') {
    $tid = (int)$_POST['teacher_id'];
    $history = [];

    // 1. Fetch Daily Posts (CW, HW, Defaulters)
    $q1 = $conn->query("
        SELECT dp.post_date as date, pi.item_id as id, pi.item_type as type, pi.heading, pi.content, pi.subject_code, c.name as class_name, dp.created_at
        FROM daily_posts dp
        JOIN post_items pi ON dp.post_id = pi.post_id
        LEFT JOIN classes c ON dp.class_id = c.id
        WHERE dp.teacher_id = $tid
    ");
    while($r = $q1->fetch_assoc()) {
        $date = $r['date'];
        if(!isset($history[$date])) $history[$date] = [];
        
        $files = [];
        $f_res = $conn->query("SELECT file_path FROM post_attachments WHERE item_id = " . $r['id']);
        while($f = $f_res->fetch_assoc()) $files[] = $f['file_path'];
        
        $students = [];
        if($r['type'] === 'defaulter') {
            $s_res = $conn->query("SELECT s.name, s.roll_no FROM post_defaulters pd JOIN students s ON pd.student_id = s.id WHERE pd.item_id = " . $r['id']);
            while($s = $s_res->fetch_assoc()) $students[] = $s['name'] . ' (Roll: ' . $s['roll_no'] . ')';
        }

        $history[$date][] = [
            'id' => 'dp_' . $r['id'],
            'type' => $r['type'],
            'heading' => $r['heading'],
            'content' => $r['content'],
            'subject' => $r['subject_code'],
            'class_name' => $r['class_name'],
            'time' => date('h:i A', strtotime($r['created_at'])),
            'timestamp' => strtotime($r['created_at']),
            'files' => $files,
            'defaulters' => $students
        ];
    }

    // 2. Fetch General Notices
    $q2 = $conn->query("SELECT id, title, content, date, image_url, created_at FROM events_announcements WHERE teacher_id = $tid");
    while($r = $q2->fetch_assoc()) {
        $date = $r['date'];
        if(!isset($history[$date])) $history[$date] = [];
        
        $files = [];
        if(!empty($r['image_url'])) $files[] = $r['image_url'];

        $history[$date][] = [
            'id' => 'gen_' . $r['id'],
            'type' => 'general',
            'heading' => $r['title'],
            'content' => $r['content'],
            'subject' => null,
            'class_name' => 'All Assigned Classes',
            'time' => date('h:i A', strtotime($r['created_at'])),
            'timestamp' => strtotime($r['created_at']),
            'files' => $files,
            'defaulters' => []
        ];
    }

    // Sort items inside each date (Newest first)
    foreach($history as $date => &$items) {
        usort($items, function($a, $b) { return $b['timestamp'] - $a['timestamp']; });
    }
    krsort($history); // Sort dates descending

    echo json_encode(['status' => 'success', 'history' => $history]);
    exit;
}

if ($action === 'delete_history_item') {
    $id_string = $_POST['item_id']; // e.g. "dp_15" or "gen_8"
    $tid = (int)$_POST['teacher_id'];
    list($prefix, $id) = explode('_', $id_string);
    $id = (int)$id;

    if ($prefix === 'dp') {
        $check = $conn->query("SELECT pi.item_id FROM post_items pi JOIN daily_posts dp ON pi.post_id = dp.post_id WHERE pi.item_id = $id AND dp.teacher_id = $tid");
        if($check->num_rows > 0) $conn->query("DELETE FROM post_items WHERE item_id = $id");
    } elseif ($prefix === 'gen') {
        $conn->query("DELETE FROM events_announcements WHERE id = $id AND teacher_id = $tid");
    }

    echo json_encode(['status' => 'success']);
    exit;
}

// --- 3. ATTENDANCE MANAGEMENT (Bulk View/Save) ---

if ($action === 'fetch_attendance_sheet') {
    $cid = (int)$_POST['class_id'];
    $month = (int)$_POST['month'];
    $year = date('Y');
    
    // Fetch students + attendance for that month
    $sql = "SELECT s.id, s.name, s.roll_no,
            (SELECT COUNT(*) FROM daily_attendance WHERE student_id=s.id AND MONTH(date)=$month AND YEAR(date)=$year AND status='present') as p,
            (SELECT COUNT(*) FROM daily_attendance WHERE student_id=s.id AND MONTH(date)=$month AND YEAR(date)=$year AND status='absent') as a
            FROM students s WHERE s.class_id = $cid AND s.status = 'active' ORDER BY s.roll_no ASC";
    
    $res = $conn->query($sql);
    $data = [];
    while($row = $res->fetch_assoc()) $data[] = $row;
    
    echo json_encode(['status'=>'success', 'data'=>$data]);
    exit;
}

// --- 4. MARKS MANAGEMENT (SUPER ENGINE) ---

if ($action === 'fetch_marks_sheet') {
    $cid = (int)$_POST['class_id'];
    $eid = (int)$_POST['exam_id'];
    $sub = $conn->real_escape_string($_POST['subject_code']);
    
    // Fetch students and their highly granular marks
    $sql = "SELECT s.id, s.name, s.roll_no, 
                   m.pt_marks, m.notebook_marks, m.enrichment_marks, m.exam_marks, m.is_absent 
            FROM students s 
            LEFT JOIN marks m ON s.id = m.student_id AND m.exam_id = $eid AND m.subject_code = '$sub'
            WHERE s.class_id = $cid AND s.status = 'active' ORDER BY s.roll_no ASC, s.name ASC";
            
    $res = $conn->query($sql);
    $data = [];
    while($row = $res->fetch_assoc()) $data[] = $row;
    
    echo json_encode(['status'=>'success', 'data'=>$data]);
    exit;
}

if ($action === 'save_single_mark') {
    $sid = (int)$_POST['student_id'];
    $eid = (int)$_POST['exam_id'];
    $sub = $conn->real_escape_string($_POST['subject_code']);
    
    // Parse values safely. If empty string, convert to NULL for database integrity.
    $pt = isset($_POST['pt']) && $_POST['pt'] !== '' ? (float)$_POST['pt'] : 'NULL';
    $nb = isset($_POST['nb']) && $_POST['nb'] !== '' ? (float)$_POST['nb'] : 'NULL';
    $se = isset($_POST['se']) && $_POST['se'] !== '' ? (float)$_POST['se'] : 'NULL';
    $ex = isset($_POST['exam']) && $_POST['exam'] !== '' ? (float)$_POST['exam'] : 'NULL';
    $abs = isset($_POST['is_absent']) ? (int)$_POST['is_absent'] : 0;
    
    // Get the dynamic current session globally from settings
    $sess_res = $conn->query("SELECT setting_value FROM settings WHERE setting_key='current_session'");
    $sess_row = $sess_res->fetch_assoc();
    $session_val = $sess_row ? $sess_row['setting_value'] : '2026-2027';

    // The powerful ON DUPLICATE KEY UPDATE query
    $sql = "INSERT INTO marks (student_id, exam_id, subject_code, session, pt_marks, notebook_marks, enrichment_marks, exam_marks, is_absent) 
            VALUES ($sid, $eid, '$sub', '$session_val', $pt, $nb, $se, $ex, $abs) 
            ON DUPLICATE KEY UPDATE 
            pt_marks = VALUES(pt_marks), 
            notebook_marks = VALUES(notebook_marks), 
            enrichment_marks = VALUES(enrichment_marks), 
            exam_marks = VALUES(exam_marks), 
            is_absent = VALUES(is_absent)";
            
    if ($conn->query($sql)) {
        echo json_encode(['status'=>'success']);
    } else {
        echo json_encode(['status'=>'error', 'message'=>$conn->error]);
    }
    exit;
}

// --- 5. TIMETABLE ---

if ($action === 'fetch_timetable') {
    $cid = (int)$_POST['class_id'];
    $tt = [];
    $res = $conn->query("SELECT day_of_week, period_no, subject_code FROM timetables WHERE class_id = $cid");
    while($r = $res->fetch_assoc()) {
        $tt[$r['day_of_week']][$r['period_no']] = $r['subject_code'];
    }
    
    // Also fetch subjects list
    $subs = [];
    $s_res = $conn->query("SELECT code, name FROM subjects ORDER BY name");
    while($r = $s_res->fetch_assoc()) $subs[] = $r;

    echo json_encode(['status'=>'success', 'timetable'=>$tt, 'subjects'=>$subs]);
    exit;
}

if ($action === 'save_timetable') {
    $cid = (int)$_POST['class_id'];
    $data = json_decode($_POST['timetable_data'], true); // { 'Monday': { 1: 'ENG', 2: 'MTH' } }
    
    $conn->query("DELETE FROM timetables WHERE class_id = $cid");
    $stmt = $conn->prepare("INSERT INTO timetables (class_id, day_of_week, period_no, subject_code) VALUES (?, ?, ?, ?)");
    
    foreach($data as $day => $periods) {
        foreach($periods as $p => $code) {
            if(!empty($code)) {
                $stmt->bind_param("isis", $cid, $day, $p, $code);
                $stmt->execute();
            }
        }
    }
    echo json_encode(['status'=>'success']);
    exit;
}

// --- 6. REPORT CARDS & EXAMS ---

if ($action === 'fetch_exams_status') {
    $cid = (int)$_POST['class_id'];
    $data = [];
    $res = $conn->query("SELECT e.id, e.name, p.is_published 
                         FROM exams e 
                         LEFT JOIN exam_publish_status p ON e.id = p.exam_id AND p.class_id = $cid
                         ORDER BY e.id");
    while($row = $res->fetch_assoc()) $data[] = $row;
    echo json_encode(['status'=>'success', 'data'=>$data]);
    exit;
}

if ($action === 'toggle_publish') {
    $cid = (int)$_POST['class_id'];
    $eid = (int)$_POST['exam_id'];
    $status = (int)$_POST['status']; // 1 or 0
    
    if ($status === 1) {
        $conn->query("INSERT INTO exam_publish_status (exam_id, class_id, is_published, published_at) VALUES ($eid, $cid, 1, NOW()) ON DUPLICATE KEY UPDATE is_published=1");
    } else {
        $conn->query("UPDATE exam_publish_status SET is_published=0 WHERE exam_id=$eid AND class_id=$cid");
    }
    echo json_encode(['status'=>'success']);
    exit;
}

// --- 7. TOPPERS ---

if ($action === 'fetch_toppers') {
    $cid = (int)$_POST['class_id'];
    $toppers = [];
    $res = $conn->query("SELECT rank, student_name, percentage FROM class_toppers WHERE class_id = $cid");
    while($r = $res->fetch_assoc()) $toppers[$r['rank']] = $r;
    
    $show = $conn->query("SELECT show_toppers FROM classes WHERE id = $cid")->fetch_assoc()['show_toppers'];
    
    echo json_encode(['status'=>'success', 'toppers'=>$toppers, 'show_toppers'=>$show]);
    exit;
}

if ($action === 'save_toppers') {
    $cid = (int)$_POST['class_id'];
    $show = (int)$_POST['show_toppers'];
    $ranks = json_decode($_POST['ranks_data'], true); // { 1: {sid: 5, pct: 90} }
    
    $conn->query("UPDATE classes SET show_toppers = $show WHERE id = $cid");
    
    foreach($ranks as $rank => $data) {
        $sid = (int)$data['sid'];
        $pct = $data['pct'];
        if($sid > 0) {
            $s = $conn->query("SELECT name, profile_pic FROM students WHERE id = $sid")->fetch_assoc();
            $stmt = $conn->prepare("INSERT INTO class_toppers (class_id, rank, student_name, percentage, image_url) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE student_name=?, percentage=?, image_url=?");
            $stmt->bind_param("iissssss", $cid, $rank, $s['name'], $pct, $s['profile_pic'], $s['name'], $pct, $s['profile_pic']);
            $stmt->execute();
        }
    }
    echo json_encode(['status'=>'success']);
    exit;
}

// --- 8. SINGLE STUDENT DEEP EDIT ---
if ($action === 'fetch_student_deep') {
    $sid = (int)$_POST['student_id'];
    $student = $conn->query("SELECT * FROM students WHERE id = $sid")->fetch_assoc();
    
    // Attendance by Month
    $att = [];
    $res = $conn->query("SELECT month, days_present, days_absent FROM attendance WHERE student_id=$sid AND year=YEAR(CURDATE())");
    while($r = $res->fetch_assoc()) $att[$r['month']] = $r;
    
    // Marks by Exam -> Subject
    $marks = [];
    $e_res = $conn->query("SELECT id, name, max_marks FROM exams");
    while($ex = $e_res->fetch_assoc()) {
        $eid = $ex['id'];
        $m_res = $conn->query("SELECT s.code, s.name, m.marks_obtained FROM marks m JOIN subjects s ON m.subject_code=s.code WHERE m.student_id=$sid AND m.exam_id=$eid");
        $subs = [];
        while($m = $m_res->fetch_assoc()) $subs[] = $m;
        $ex['subjects'] = $subs;
        $marks[] = $ex;
    }
    
    // Available Subjects
    $all_subs = [];
    $s_res = $conn->query("SELECT code, name FROM subjects");
    while($r = $s_res->fetch_assoc()) $all_subs[] = $r;

    echo json_encode(['status'=>'success', 'student'=>$student, 'attendance'=>$att, 'marks'=>$marks, 'all_subjects'=>$all_subs]);
    exit;
}

if ($action === 'save_student_deep') {
    $sid = (int)$_POST['student_id'];
    
    // Save Attendance (JSON: { 4: {p:20, a:2} })
    $att_data = json_decode($_POST['attendance'], true);
    $year = date('Y');
    foreach($att_data as $m => $d) {
        $p = (int)$d['p']; $a = (int)$d['a'];
        if($p==0 && $a==0) {
            $conn->query("DELETE FROM attendance WHERE student_id=$sid AND month=$m AND year=$year");
        } else {
            $conn->query("INSERT INTO attendance (student_id, year, month, days_present, days_absent) VALUES ($sid, $year, $m, $p, $a) ON DUPLICATE KEY UPDATE days_present=$p, days_absent=$a");
        }
    }
    
    // Save Marks (JSON: { exam_id: { sub_code: mark } })
    $marks_data = json_decode($_POST['marks'], true);
    $stmt = $conn->prepare("INSERT INTO marks (student_id, exam_id, subject_code, marks_obtained) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE marks_obtained=?");
    foreach($marks_data as $eid => $subs) {
        foreach($subs as $code => $val) {
            $val = (int)$val;
            $stmt->bind_param("iisii", $sid, $eid, $code, $val, $val);
            $stmt->execute();
        }
    }
    
    echo json_encode(['status'=>'success']);
    exit;
}

// --- 9. DEEP STUDENT PROFILE (FETCH) ---
if ($action === 'fetch_student_full_detail') {
    $sid = (int)$_POST['student_id'];
    $year = date('Y');

    // 1. Profile
    $s_res = $conn->query("SELECT s.*, c.name as class_name FROM students s JOIN classes c ON s.class_id = c.id WHERE s.id = $sid");
    if($s_res->num_rows == 0) {
        echo json_encode(['status'=>'error', 'message'=>'Student not found']);
        exit;
    }
    $student = $s_res->fetch_assoc();
    
    // 2. Attendance Summary
    $att_summary = [];
    $months = range(1, 12);
    foreach ($months as $m) {
        $stats = $conn->query("SELECT status, COUNT(*) as cnt FROM daily_attendance WHERE student_id=$sid AND MONTH(date)=$m AND YEAR(date)=$year GROUP BY status");
        $month_data = ['present'=>0, 'absent'=>0, 'total'=>0];
        while($r = $stats->fetch_assoc()) {
            $month_data[$r['status']] = (int)$r['cnt'];
            if($r['status'] !== 'holiday') $month_data['total'] += $r['cnt'];
        }
        if($month_data['total'] > 0) $att_summary[$m] = $month_data;
    }
    // Return empty object if no attendance, not null or array
    if(empty($att_summary)) $att_summary = (object)[]; 

    // 3. Exam Marks
    $marks_data = [];
    $exams = $conn->query("SELECT id, name, max_marks FROM exams");
    while($ex = $exams->fetch_assoc()) {
        $eid = $ex['id'];
        $m_res = $conn->query("SELECT s.code, s.name, m.marks_obtained FROM marks m JOIN subjects s ON m.subject_code = s.code WHERE m.student_id = $sid AND m.exam_id = $eid");
        $subs = [];
        while($sub = $m_res->fetch_assoc()) $subs[] = $sub;
        if(!empty($subs)) {
            $ex['subjects'] = $subs;
            $marks_data[] = $ex;
        }
    }
    
    echo json_encode(['status'=>'success', 'student'=>$student, 'attendance'=>$att_summary, 'marks'=>$marks_data]);
    exit;
}

// --- 10. FETCH MONTHLY ATTENDANCE DETAIL (Daily Logs) ---
if ($action === 'fetch_student_month_detail') {
    $sid = (int)$_POST['student_id'];
    $month = (int)$_POST['month'];
    $year = date('Y');
    
    $logs = [];
    $res = $conn->query("SELECT date, status FROM daily_attendance WHERE student_id=$sid AND MONTH(date)=$month AND YEAR(date)=$year ORDER BY date ASC");
    while($row = $res->fetch_assoc()) $logs[] = $row;
    
    echo json_encode(['status'=>'success', 'logs'=>$logs]);
    exit;
}

// --- 11. UPDATE SINGLE DAY ATTENDANCE ---
if ($action === 'update_student_day_attendance') {
    $sid = (int)$_POST['student_id'];
    $date = $_POST['date'];
    $status = $_POST['status']; // 'present', 'absent', 'late', 'holiday'
    $tid = (int)$_POST['teacher_id'];
    $cid = (int)$_POST['class_id'];
    
    $stmt = $conn->prepare("INSERT INTO daily_attendance (student_id, class_id, date, status, recorded_by) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE status=?, recorded_by=?");
    $stmt->bind_param("iissssi", $sid, $cid, $date, $status, $tid, $status, $tid);
    $stmt->execute();
    
    echo json_encode(['status'=>'success']);
    exit;
}

// --- 12. UPDATE STUDENT MARKS (Single Exam) ---
if ($action === 'update_student_marks') {
    $sid = (int)$_POST['student_id'];
    $eid = (int)$_POST['exam_id'];
    $marks = json_decode($_POST['marks'], true); // { "ENG": 20, "MTH": 19 }
    
    $stmt = $conn->prepare("INSERT INTO marks (student_id, exam_id, subject_code, marks_obtained) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE marks_obtained=?");
    foreach($marks as $code => $val) {
        $val = (int)$val;
        $stmt->bind_param("iisii", $sid, $eid, $code, $val, $val);
        $stmt->execute();
    }
    echo json_encode(['status'=>'success']);
    exit;
}

// --- FETCH WORK UPLOAD DATA (Required for Create Post Page) ---
if ($action === 'fetch_work_upload_data') {
    $tid = (int)$_POST['teacher_id'];
    
    $t_res = $conn->query("SELECT assigned_class_id FROM teachers WHERE id = $tid");
    $teacher = $t_res->fetch_assoc();
    $class_id = $teacher['assigned_class_id'];
    
    $subjects = []; $students = []; $classes = [];
    
    if ($class_id) {
        // Fetch ONLY the subjects mapped to this specific class
        $sub_res = $conn->query("SELECT s.code, s.name FROM class_subjects cs JOIN subjects s ON cs.subject_code = s.code WHERE cs.class_id = $class_id ORDER BY s.name ASC");
        while($r = $sub_res->fetch_assoc()) $subjects[] = $r;
        
        $stu_res = $conn->query("SELECT id, name, roll_no, profile_pic FROM students WHERE class_id = $class_id AND status='active' ORDER BY roll_no ASC, name ASC");
        while($r = $stu_res->fetch_assoc()) $students[] = $r;
    }
    
    $cl_res = $conn->query("SELECT id, name FROM classes ORDER BY sort_order ASC");
    while($r = $cl_res->fetch_assoc()) $classes[] = $r;
    
    echo json_encode([
        'status' => 'success',
        'is_class_teacher' => !empty($class_id),
        'subjects' => $subjects,
        'students' => $students,
        'classes' => $classes
    ]);
    exit;
}

// --- SMART BATCH WORK UPLOAD & NOTIFICATIONS ---
if ($action === 'submit_smart_work') {
    $tid = (int)$_POST['teacher_id'];
    $type = $_POST['post_type']; // classwork, homework, defaulter, general
    $date = date('Y-m-d');
    $item_count = (int)$_POST['item_count'];
    
    // Get Teacher Details
    $t_res = $conn->query("SELECT name, assigned_class_id FROM teachers WHERE id = $tid");
    $teacher = $t_res->fetch_assoc();
    $teacher_name = explode(' ', $teacher['name'])[0]; // e.g., "Utkarsh"
    $class_id = $teacher['assigned_class_id'];

    $upload_dir = '../uploads/work/';
    if(!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

    $notified_classes = []; // Keep track of classes to send push notifications to

    if ($type === 'general') {
        // --- GENERAL NOTICES ---
        $target_classes = json_decode($_POST['target_classes'], true);
        if (in_array('all', $target_classes)) {
            $cl_res = $conn->query("SELECT id FROM classes");
            while ($r = $cl_res->fetch_assoc()) $notified_classes[] = $r['id'];
        } else {
            $notified_classes = $target_classes;
        }

        for ($i = 0; $i < $item_count; $i++) {
            $heading = $conn->real_escape_string($_POST["heading_$i"] ?? 'General Update');
            $content = $conn->real_escape_string($_POST["content_$i"] ?? '');
            
            // For general notices, we save to events_announcements
            $conn->query("INSERT INTO events_announcements (title, content, date, display_order, teacher_id) VALUES ('$heading', '$content', '$date', 0, $tid)");
            $item_id = $conn->insert_id;

            // Handle image for notice
            if (!empty($_FILES["files_$i"]['tmp_name'][0])) {
                $tmp = $_FILES["files_$i"]['tmp_name'][0];
                $name = $_FILES["files_$i"]['name'][0];
                $ext = pathinfo($name, PATHINFO_EXTENSION);
                $new_name = 'notice_' . time() . '_' . rand(1000,9999) . '.' . $ext;
                if (move_uploaded_file($tmp, $upload_dir . $new_name)) {
                    $conn->query("UPDATE events_announcements SET image_url = 'uploads/work/$new_name' WHERE id = $item_id");
                }
            }
        }
        $notify_title = "School Notice";
        $notify_body = "A new notice has been posted by $teacher_name.";
        
    } else {
        // --- DAILY POSTS (CW, HW, DEFAULTERS) ---
        $conn->query("INSERT IGNORE INTO daily_posts (teacher_id, class_id, post_date) VALUES ($tid, $class_id, '$date')");
        $res = $conn->query("SELECT post_id FROM daily_posts WHERE teacher_id=$tid AND class_id=$class_id AND post_date='$date'");
        $post_id = $res->fetch_assoc()['post_id'];
        $notified_classes[] = $class_id;

        for ($i = 0; $i < $item_count; $i++) {
            $sub_code = $conn->real_escape_string($_POST["subject_$i"] ?? '');
            $heading = $conn->real_escape_string($_POST["heading_$i"] ?? '');
            $content = $conn->real_escape_string($_POST["content_$i"] ?? '');
            $sub_sql = $sub_code ? "'$sub_code'" : "NULL";

            // If heading is blank but subject is given, auto-generate title!
            // if(empty($heading) && $sub_code) $heading = "$sub_code Update";

            $conn->query("INSERT INTO post_items (post_id, item_type, heading, content, subject_code) VALUES ($post_id, '$type', '$heading', '$content', $sub_sql)");
            $item_id = $conn->insert_id;

            // Handle Defaulter Students
            if ($type === 'defaulter' && !empty($_POST["students_$i"])) {
                $st_list = json_decode($_POST["students_$i"], true);
                foreach($st_list as $sid) {
                    $conn->query("INSERT INTO post_defaulters (item_id, student_id) VALUES ($item_id, ".(int)$sid.")");
                }
            }

            // Handle Attachments
            if (!empty($_FILES["files_$i"]['tmp_name'])) {
                foreach($_FILES["files_$i"]['tmp_name'] as $key => $tmp_name) {
                    if(empty($tmp_name)) continue;
                    $name = $_FILES["files_$i"]['name'][$key];
                    $ext = pathinfo($name, PATHINFO_EXTENSION);
                    $new_name = 'work_' . time() . '_' . rand(100,999) . '.' . $ext;
                    if (move_uploaded_file($tmp_name, $upload_dir . $new_name)) {
                        $db_path = 'uploads/work/' . $new_name;
                        $conn->query("INSERT INTO post_attachments (item_id, file_path, original_name) VALUES ($item_id, '$db_path', '$name')");
                    }
                }
            }
        }

        if ($type === 'homework') {
            $notify_title = "Homework Updated";
            $notify_body = "Today's homework has been posted by $teacher_name.";
        } elseif ($type === 'classwork') {
            $notify_title = "Classwork Updated";
            $notify_body = "Today's classwork has been posted by $teacher_name.";
        } else {
            $notify_title = "Defaulters List";
            $notify_body = "$teacher_name has updated the defaulters list. Please check the portal.";
        }
    }

    // --- AUTOMATED FIREBASE NOTIFICATIONS ---
    if (!empty($notified_classes) && isset($notify_title) && isset($notify_body)) {
        // Remove duplicate class IDs just in case
        $notified_classes = array_unique($notified_classes);
        
        foreach ($notified_classes as $notify_cid) {
            // This triggers your exact existing Firebase Notification Service!
            $notifier->sendToClass($notify_cid, $notify_title, $notify_body);
        }
    }

    echo json_encode(['status' => 'success']);
    exit;
}
// ==================================================================
// 4.5 TERM ASSESSMENT & CO-SCHOLASTIC ENGINE
// ==================================================================

if ($action === 'fetch_term_assessment') {
    $cid = (int)$_POST['class_id'];
    $eid = (int)$_POST['exam_id'];
    
    $sql = "SELECT id, name, roll_no FROM students WHERE class_id = $cid AND status = 'active' ORDER BY roll_no ASC, name ASC";
    $res = $conn->query($sql);
    $data = [];
    
    while($stu = $res->fetch_assoc()) {
        $sid = $stu['id'];
        
        // 1. Get Metadata (Attendance & Remarks)
        $m_res = $conn->query("SELECT attendance_total, attendance_present, remarks FROM student_term_data WHERE student_id = $sid AND exam_id = $eid");
        
        if ($m_res->num_rows > 0) {
            // Use saved data if the teacher has already saved it
            $meta = $m_res->fetch_assoc();
        } else {
            // SMART FIX: Auto-calculate from daily_attendance if no data exists!
            $att_q = $conn->query("SELECT 
                COUNT(CASE WHEN status != 'holiday' THEN 1 END) as total_days,
                COUNT(CASE WHEN status IN ('present', 'late') THEN 1 END) as present_days
                FROM daily_attendance WHERE student_id = $sid");
            $att_data = $att_q->fetch_assoc();
            
            $meta = [
                'attendance_total' => $att_data['total_days'] ?? '',
                'attendance_present' => $att_data['present_days'] ?? '',
                'remarks' => ''
            ];
        }
        
        // 2. Get Co-Scholastic Grades
        $c_res = $conn->query("SELECT skill_name, grade FROM co_scholastic_grades WHERE student_id = $sid AND exam_id = $eid");
        $skills = [];
        while($c = $c_res->fetch_assoc()) {
            $skills[$c['skill_name']] = $c['grade'];
        }
        
        $stu['meta'] = $meta;
        $stu['skills'] = $skills;
        $data[] = $stu;
    }
    echo json_encode(['status'=>'success', 'data'=>$data]);
    exit;
}

if ($action === 'save_term_assessment') {
    $sid = (int)$_POST['student_id'];
    $eid = (int)$_POST['exam_id'];
    
    $sess_res = $conn->query("SELECT setting_value FROM settings WHERE setting_key='current_session'");
    $sess_row = $sess_res ? $sess_res->fetch_assoc() : null;
    $session_val = $sess_row ? $sess_row['setting_value'] : '2026-2027';

    // 1. Save Metadata (Attendance & Remarks)
    $att_t = isset($_POST['att_total']) && $_POST['att_total'] !== '' ? (int)$_POST['att_total'] : 'NULL';
    $att_p = isset($_POST['att_present']) && $_POST['att_present'] !== '' ? (int)$_POST['att_present'] : 'NULL';
    $rem = $conn->real_escape_string($_POST['remarks'] ?? '');

    $meta_sql = "INSERT INTO student_term_data (student_id, exam_id, session, attendance_total, attendance_present, remarks) 
                 VALUES ($sid, $eid, '$session_val', $att_t, $att_p, '$rem') 
                 ON DUPLICATE KEY UPDATE attendance_total=$att_t, attendance_present=$att_p, remarks='$rem'";
    $conn->query($meta_sql);

    // 2. Save Co-Scholastic Skills
    $skills = json_decode($_POST['skills'], true);
    if(is_array($skills)) {
        $stmt = $conn->prepare("INSERT INTO co_scholastic_grades (student_id, exam_id, session, skill_name, grade) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE grade=VALUES(grade)");
        foreach($skills as $skill => $grade) {
            if(!empty($grade)) {
                $stmt->bind_param("iisss", $sid, $eid, $session_val, $skill, $grade);
                $stmt->execute();
            } else {
                // If teacher clears the grade, delete it from DB
                $conn->query("DELETE FROM co_scholastic_grades WHERE student_id=$sid AND exam_id=$eid AND skill_name='".$conn->real_escape_string($skill)."'");
            }
        }
    }
    
    echo json_encode(['status'=>'success']);
    exit;
}

// Fallback
echo json_encode(['status'=>'error', 'message'=>'Invalid Action']);
?>