<?php
error_reporting(0);
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");

require_once 'config.php';

$action = $_POST['action'] ?? '';
if (!$action) {
    $input = json_decode(file_get_contents("php://input"), true);
    $action = $input['action'] ?? '';
}

$tid = isset($_POST['teacher_id']) ? (int)$_POST['teacher_id'] : 0;

// --- 1. DASHBOARD INIT ---
if ($action === 'fetch_dashboard') {
    $t_res = $conn->query("SELECT t.id, t.name, t.profile_pic, t.contact, t.login_id, t.assigned_class_id, c.name as class_name 
                           FROM teachers t LEFT JOIN classes c ON t.assigned_class_id = c.id WHERE t.id = $tid");
    $teacher = $t_res->fetch_assoc();
    
    $students = [];
    $student_count = 0;
    if ($teacher['assigned_class_id']) {
        $cid = $teacher['assigned_class_id'];
        $s_res = $conn->query("SELECT id, name, roll_no, profile_pic, login_id FROM students WHERE class_id = $cid AND status = 'active' ORDER BY roll_no ASC");
        while($r = $s_res->fetch_assoc()) $students[] = $r;
        $student_count = count($students);
    }
    
    // Fetch Classes List for General Update Dropdown
    $classes_list = [];
    $c_res = $conn->query("SELECT id, name FROM classes ORDER BY sort_order");
    while($c = $c_res->fetch_assoc()) $classes_list[] = $c;

    echo json_encode([
        'status'=>'success', 
        'profile'=>$teacher, 
        'students'=>$students, 
        'student_count'=>$student_count,
        'all_classes'=>$classes_list
    ]);
    exit;
}

// --- 2. CREATE POST (The Complex Part) ---
if ($action === 'create_post') {
    $post_date = $_POST['post_date'];
    $type = $_POST['post_type']; // 'daily' or 'general'
    
    // 1. Determine Targets
    $class_ids = [];
    if ($type === 'general') {
        // Frontend sends JSON string of IDs: "[85, 86]" or "all"
        $targets = json_decode($_POST['target_classes'], true);
        if ($targets === 'all') {
            $c_res = $conn->query("SELECT id FROM classes");
            while($r=$c_res->fetch_assoc()) $class_ids[] = $r['id'];
        } else if (is_array($targets)) {
            $class_ids = $targets;
        }
    } else {
        $t_res = $conn->query("SELECT assigned_class_id FROM teachers WHERE id = $tid");
        $row = $t_res->fetch_assoc();
        if($row['assigned_class_id']) $class_ids[] = $row['assigned_class_id'];
    }

    if (empty($class_ids)) {
        echo json_encode(['status'=>'error', 'message'=>'No target class selected']);
        exit;
    }

    // 2. Loop Targets and Create Posts
    foreach ($class_ids as $cid) {
        $stmt = $conn->prepare("INSERT INTO daily_posts (teacher_id, class_id, post_date) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE created_at=NOW()");
        $stmt->bind_param("iis", $tid, $cid, $post_date);
        $stmt->execute();
        $post_id = $stmt->insert_id ?: $conn->query("SELECT post_id FROM daily_posts WHERE teacher_id=$tid AND class_id=$cid AND post_date='$post_date'")->fetch_assoc()['post_id'];

        // Helper to Save Item & Files
        // $file_key is the HTML name like 'cw_files'. 
        // $row_index is which row of the repeater we are on.
        $saveItem = function($p_id, $i_type, $heading, $content, $file_key, $row_index, $defaulters_json) use ($conn) {
            if(empty($heading)) return;
            
            // Insert Item
            $stmt_i = $conn->prepare("INSERT INTO post_items (post_id, item_type, heading, content) VALUES (?, ?, ?, ?)");
            $stmt_i->bind_param("isss", $p_id, $i_type, $heading, $content);
            $stmt_i->execute();
            $item_id = $stmt_i->insert_id;

            // HANDLE FILES (Crucial Update)
            // $_FILES['cw_files']['name'][$row_index][0...n]
            if (isset($_FILES[$file_key]['name'][$row_index])) {
                $file_array = $_FILES[$file_key];
                $count = count($file_array['name'][$row_index]);
                
                for($i = 0; $i < $count; $i++) {
                    $tmp_name = $file_array['tmp_name'][$row_index][$i];
                    $name = $file_array['name'][$row_index][$i];
                    $error = $file_array['error'][$row_index][$i];
                    
                    if ($error === UPLOAD_ERR_OK && is_uploaded_file($tmp_name)) {
                        $ext = pathinfo($name, PATHINFO_EXTENSION);
                        $new_name = time() . '_' . rand(1000,9999) . '.' . $ext;
                        $target = __DIR__ . '/../GMPSimages/' . $new_name;
                        
                        if(move_uploaded_file($tmp_name, $target)) {
                            $db_path = 'GMPSimages/' . $new_name;
                            $conn->query("INSERT INTO post_attachments (item_id, file_path, original_name) VALUES ($item_id, '$db_path', '$name')");
                        }
                    }
                }
            }

            // Defaulters
            if ($i_type === 'defaulter' && !empty($defaulters_json)) {
                $ids = json_decode($defaulters_json, true);
                if(is_array($ids)) {
                    foreach($ids as $sid) $conn->query("INSERT INTO post_defaulters (item_id, student_id) VALUES ($item_id, ".(int)$sid.")");
                }
            }
        };

        // 3. Process Input Arrays
        if(isset($_POST['cw_heading'])) {
            foreach($_POST['cw_heading'] as $k => $h) {
                $saveItem($post_id, 'classwork', $h, $_POST['cw_content'][$k]??'', 'cw_files', $k, null);
            }
        }
        if(isset($_POST['hw_heading'])) {
            foreach($_POST['hw_heading'] as $k => $h) {
                $saveItem($post_id, 'homework', $h, $_POST['hw_content'][$k]??'', 'hw_files', $k, null);
            }
        }
        if(isset($_POST['def_heading'])) {
            foreach($_POST['def_heading'] as $k => $h) {
                $saveItem($post_id, 'defaulter', $h, '', 'none', $k, $_POST['def_students'][$k]??'[]');
            }
        }
        if(isset($_POST['gen_heading'])) {
            foreach($_POST['gen_heading'] as $k => $h) {
                $saveItem($post_id, 'update', $h, $_POST['gen_content'][$k]??'', 'gen_files', $k, null);
            }
        }
    }
    echo json_encode(['status'=>'success']);
    exit;
}

// --- 3. FETCH RECENT POSTS (With Items) ---
if ($action === 'fetch_recent_posts') {
    // 1. Get Posts
    $raw = $conn->query("SELECT dp.post_id, dp.class_id, dp.post_date, dp.created_at, c.name as class_name 
                         FROM daily_posts dp LEFT JOIN classes c ON dp.class_id = c.id 
                         WHERE dp.teacher_id = $tid ORDER BY dp.created_at DESC LIMIT 50");
    
    $posts = [];
    while($r = $raw->fetch_assoc()) {
        $key = $r['created_at']; // Group by timestamp to merge multi-class posts
        if(!isset($posts[$key])) {
            $posts[$key] = [
                'date' => $r['post_date'],
                'created_at' => $r['created_at'],
                'classes' => [],
                'post_ids' => [],
                'items' => [] 
            ];
        }
        $posts[$key]['classes'][] = $r['class_id'] == 0 ? "All Classes" : $r['class_name'];
        $posts[$key]['post_ids'][] = $r['post_id'];
    }

    // 2. Fetch Items for the FIRST post_id in the batch (since identical)
    foreach($posts as $k => &$p) {
        $pid = $p['post_ids'][0];
        $i_res = $conn->query("SELECT item_id, item_type, heading, content FROM post_items WHERE post_id = $pid");
        while($it = $i_res->fetch_assoc()) {
            // Fetch attachments
            $att_res = $conn->query("SELECT file_path FROM post_attachments WHERE item_id = " . $it['item_id']);
            $it['files'] = [];
            while($f = $att_res->fetch_assoc()) $it['files'][] = $f['file_path'];
            
            $p['items'][] = $it;
        }
    }
    
    echo json_encode(['status'=>'success', 'posts'=>array_values($posts)]);
    exit;
}

// --- 4. DELETE ACTIONS ---
if ($action === 'delete_batch') {
    $time = $conn->real_escape_string($_POST['batch_time']);
    $conn->query("DELETE FROM daily_posts WHERE teacher_id = $tid AND created_at = '$time'");
    echo json_encode(['status'=>'success']);
    exit;
}

if ($action === 'delete_item') {
    $iid = (int)$_POST['item_id'];
    // Security check: join to ensure teacher owns this item
    $check = $conn->query("SELECT pi.item_id FROM post_items pi JOIN daily_posts dp ON pi.post_id = dp.post_id WHERE pi.item_id = $iid AND dp.teacher_id = $tid");
    if($check->num_rows > 0) {
        $conn->query("DELETE FROM post_items WHERE item_id = $iid");
        echo json_encode(['status'=>'success']);
    } else {
        echo json_encode(['status'=>'error', 'message'=>'Permission denied']);
    }
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

// --- 4. MARKS MANAGEMENT ---

if ($action === 'fetch_marks_sheet') {
    $cid = (int)$_POST['class_id'];
    $eid = (int)$_POST['exam_id'];
    $sub = $conn->real_escape_string($_POST['subject_code']);
    
    $sql = "SELECT s.id, s.name, s.roll_no, m.marks_obtained 
            FROM students s 
            LEFT JOIN marks m ON s.id = m.student_id AND m.exam_id = $eid AND m.subject_code = '$sub'
            WHERE s.class_id = $cid AND s.status = 'active' ORDER BY s.roll_no ASC";
            
    $res = $conn->query($sql);
    $data = [];
    while($row = $res->fetch_assoc()) $data[] = $row;
    
    echo json_encode(['status'=>'success', 'data'=>$data]);
    exit;
}

if ($action === 'save_marks_bulk') {
    $eid = (int)$_POST['exam_id'];
    $sub = $_POST['subject_code'];
    $marks = json_decode($_POST['marks_data'], true); // { sid: marks, sid: marks }
    
    $stmt = $conn->prepare("INSERT INTO marks (student_id, exam_id, subject_code, marks_obtained) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE marks_obtained = ?");
    
    foreach($marks as $sid => $val) {
        $val = (int)$val;
        $stmt->bind_param("iisii", $sid, $eid, $sub, $val, $val);
        $stmt->execute();
    }
    echo json_encode(['status'=>'success']);
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
// Fallback
echo json_encode(['status'=>'error', 'message'=>'Invalid Action']);
?>