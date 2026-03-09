<?php
error_reporting(0);
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");

require_once 'config.php';

$input = json_decode(file_get_contents("php://input"), true);
$user_id = $input['user_id'] ?? 0;
$role = $input['role'] ?? 'guest';
// Detect requested session from the App (or fallback to current)
$requested_session = $input['session'] ?? $_GET['session'] ?? null;

if ($role !== 'student' || $user_id == 0) {
    echo json_encode(["status" => "error", "message" => "Access Denied"]);
    exit;
}

// Get global current session safely
$sess_query = $conn->query("SELECT setting_value FROM settings WHERE setting_key='current_session'");
$sess_row = $sess_query ? $sess_query->fetch_assoc() : null;
$current_global_session = $sess_row ? $sess_row['setting_value'] : '2026-2027';

// Determine the target session to fetch
$target_session = $requested_session ? $conn->real_escape_string($requested_session) : $current_global_session;

$response = [
    'active_session' => $target_session,
    'is_historical' => ($target_session !== $current_global_session)
];

// --- 1. SAFE CLASS DETERMINATION LOGIC ---
$target_class_id = 0;

if ($target_session === $current_global_session) {
    // Current year: Fetch directly from students table
    $class_lookup = $conn->query("SELECT class_id FROM students WHERE id = $user_id");
    if ($class_lookup) {
        $target_class_id = $class_lookup->fetch_assoc()['class_id'] ?? 0;
    }
} else {
    // Past year: Look up in student_session_history
    // SAFE FALLBACK: If history table doesn't exist or is empty, we don't crash.
    $hist_query = "SELECT class_id FROM student_session_history WHERE student_id = $user_id AND session = '$target_session' LIMIT 1";
    $hist_res = $conn->query($hist_query);
    if ($hist_res && $hist_res->num_rows > 0) {
        $target_class_id = $hist_res->fetch_assoc()['class_id'];
    } else {
        // Fallback to their current class if no history exists (prevents N/A crash during testing)
        $fallback_lookup = $conn->query("SELECT class_id FROM students WHERE id = $user_id");
        $target_class_id = $fallback_lookup ? ($fallback_lookup->fetch_assoc()['class_id'] ?? 0) : 0;
    }
}

// --- 2. FETCH PROFILE ---
$student = [];
if ($target_class_id > 0) {
    $stu_sql = "
        SELECT s.*, DATE_FORMAT(s.dob, '%d/%m/%Y') as formatted_dob, c.name AS class_name, c.id AS class_id, 
               t.name AS teacher_name, t.contact AS teacher_contact, t.profile_pic AS teacher_pic
        FROM students s
        JOIN classes c ON c.id = $target_class_id
        LEFT JOIN teachers t ON t.assigned_class_id = c.id
        WHERE s.id = $user_id
    ";
    $stu_res = $conn->query($stu_sql);
    if ($stu_res && $stu_res->num_rows > 0) {
        $student = $stu_res->fetch_assoc();
        // Format Data Safely
        $student['father_name'] = !empty($student['father_name']) ? $student['father_name'] : '';
        $student['mother_name'] = !empty($student['mother_name']) ? $student['mother_name'] : '';
        $student['teacher_name'] = !empty($student['teacher_name']) ? $student['teacher_name'] : 'Not Assigned';
        $student['teacher_contact'] = !empty($student['teacher_contact']) ? $student['teacher_contact'] : '';
        $student['dob'] = $student['formatted_dob']; // Overwrite default dob with our formatted one
    }
}
$response['profile'] = $student;

// --- 3. FETCH ATTENDANCE (Overall stats calculation for profile page) ---
$calendar_data = [];
$total_present = 0;
$total_school_days = 0;

$att_sql = "SELECT date, status FROM daily_attendance WHERE student_id = $user_id";
$att_res = $conn->query($att_sql);
if ($att_res) {
    while ($row = $att_res->fetch_assoc()) {
        $calendar_data[$row['date']] = $row['status'];
        if ($row['status'] !== 'holiday') {
            $total_school_days++;
        }
        if (in_array($row['status'], ['present', 'late'])) {
            $total_present++;
        }
    }
}
$response['attendance_map'] = $calendar_data; 
$response['stats'] = [
    'overall_percent' => $total_school_days > 0 ? round(($total_present / $total_school_days) * 100) : 0
];


// --- 4. NEW ADVANCED EXAM RESULTS FETCHER ---
$exams = [];
$ex_sql = "SELECT e.id, e.name FROM exams e JOIN marks m ON m.exam_id=e.id WHERE m.student_id=$user_id GROUP BY e.id ORDER BY e.id";
$ex_res = $conn->query($ex_sql);

if ($ex_res) {
    while ($ex = $ex_res->fetch_assoc()) {
        $eid = $ex['id'];
        
        // Determine if Exam is UT
        $is_ut = (stripos($ex['name'], 'ut') !== false || stripos($ex['name'], 'periodic') !== false);
        
        // Check if Teacher published this specific exam for this specific class
        $pub_q = $conn->query("SELECT is_published FROM exam_publish_status WHERE exam_id=$eid AND class_id=$target_class_id");
        $is_published = ($pub_q && $pub_q->num_rows > 0 && $pub_q->fetch_assoc()['is_published'] == 1);
        
        // Fetch the detailed split marks
        $marks_sql = "
            SELECT sub.name as subject, m.pt_marks, m.notebook_marks, m.enrichment_marks, m.exam_marks, m.is_absent 
            FROM marks m 
            JOIN subjects sub ON sub.code = m.subject_code 
            WHERE m.student_id = $user_id AND m.exam_id = $eid AND m.session = '$target_session'
            ORDER BY sub.name ASC
        ";
        $m_res = $conn->query($marks_sql);
        $subjects = [];
        
        while ($mk = $m_res->fetch_assoc()) {
            // Calculate total for this specific subject based on UT vs Term rules
            $total = 0;
            if ($mk['is_absent'] == 0) {
                if ($is_ut) {
                    $total = (float)$mk['exam_marks'];
                } else {
                    $total = (float)$mk['pt_marks'] + (float)$mk['notebook_marks'] + (float)$mk['enrichment_marks'] + (float)$mk['exam_marks'];
                }
            }
            
            $subjects[] = [
                'subject' => $mk['subject'],
                'pt' => $mk['pt_marks'],
                'nb' => $mk['notebook_marks'],
                'se' => $mk['enrichment_marks'],
                'exam' => $mk['exam_marks'],
                'is_absent' => $mk['is_absent'],
                'total' => $total
            ];
        }
        
        // ONLY send this exam to the app if the class teacher has clicked "Publish"
        // This prevents parents from seeing incomplete grading!
        if ($is_published) {
            $exams[] = [
                'id' => $eid,
                'name' => $ex['name'],
                'is_ut' => $is_ut,
                'subjects' => $subjects
            ];
        }
    }
}
$response['exams'] = $exams;

// --- 5. FETCH TIMETABLE ---
$timetable = [];
if ($target_class_id > 0) {
    $tt_sql = "
        SELECT tt.day_of_week, tt.period_no, s.name AS subject_name 
        FROM timetables tt
        JOIN subjects s ON tt.subject_code = s.code
        WHERE tt.class_id = $target_class_id
        ORDER BY tt.period_no
    ";
    $tt_res = $conn->query($tt_sql);
    if ($tt_res) {
        while ($r = $tt_res->fetch_assoc()) {
            $timetable[$r['day_of_week']][] = [
                'period' => $r['period_no'],
                'subject' => $r['subject_name']
            ];
        }
    }
}
$response['timetable'] = $timetable;

echo json_encode(["status" => "success", "data" => $response]);
?>