<?php
error_reporting(0);
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");

require_once 'config.php';

$input = json_decode(file_get_contents("php://input"), true);
$user_id = $input['user_id'] ?? 0;
$role = $input['role'] ?? 'guest';

if ($role !== 'student' || $user_id == 0) {
    echo json_encode(["status" => "error", "message" => "Access Denied"]);
    exit;
}

$response = [];

// 1. FETCH PROFILE (Added teacher_contact)
$stu_sql = "
    SELECT s.*, c.name AS class_name, c.id AS class_id, 
           t.name AS teacher_name, t.contact AS teacher_contact
    FROM students s
    JOIN classes c ON c.id = s.class_id
    LEFT JOIN teachers t ON t.assigned_class_id = c.id
    WHERE s.id = $user_id
";
$stu_res = $conn->query($stu_sql);
$student = $stu_res->fetch_assoc();

// Format Data
$student['father_name'] = 'Mr. ' . $student['father_name'];
$student['mother_name'] = 'Ms. ' . $student['mother_name'];
$student['teacher_name'] = $student['teacher_name'] ?? 'Not Assigned';
$student['teacher_contact'] = $student['teacher_contact'] ?? '';
$response['profile'] = $student;

// 2. FETCH ATTENDANCE (FROM NEW TABLE: daily_attendance)
// We fetch ALL records so the App can handle the calendar UI locally
$att_sql = "SELECT date, status FROM daily_attendance WHERE student_id = $user_id";
$att_res = $conn->query($att_sql);

$calendar_data = [];
$total_present = 0; 
$total_working_days = 0;

// Logic for 'Last Month' Calculation
$current_month = date('m');
$last_month = date('m', strtotime("first day of previous month"));
$last_month_present = 0;
$last_month_total = 0;

while ($row = $att_res->fetch_assoc()) {
    $date = $row['date'];
    $status = $row['status'];
    $month = date('m', strtotime($date));
    
    // Build Map: '2023-10-25' => 'present'
    $calendar_data[$date] = $status;

    // Overall Stats (Excluding Holidays from total)
    if ($status === 'present' || $status === 'late') {
        $total_present++;
        $total_working_days++;
    } elseif ($status === 'absent') {
        $total_working_days++;
    }
    // 'holiday' is ignored for denominator

    // Last Month Stats
    if ($month == $last_month) {
        if ($status === 'present' || $status === 'late') {
            $last_month_present++;
            $last_month_total++;
        } elseif ($status === 'absent') {
            $last_month_total++;
        }
    }
}

$response['attendance_map'] = $calendar_data; // Send full calendar map
$response['stats'] = [
    'overall_percent' => $total_working_days > 0 ? round(($total_present / $total_working_days) * 100, 1) : 0,
    'last_month_percent' => $last_month_total > 0 ? round(($last_month_present / $last_month_total) * 100, 1) : 0,
    'total_present' => $total_present,
    'total_working' => $total_working_days
];

// 3. FETCH EXAM RESULTS (Unchanged)
$exams = [];
$ex_sql = "SELECT e.id, e.name, e.max_marks FROM exams e JOIN marks m ON m.exam_id=e.id WHERE m.student_id=$user_id GROUP BY e.id";
$ex_res = $conn->query($ex_sql);

while ($ex = $ex_res->fetch_assoc()) {
    $eid = $ex['id'];
    $pub_q = $conn->query("SELECT is_published FROM exam_publish_status WHERE exam_id=$eid AND class_id={$student['class_id']}");
    $is_published = ($pub_q && $pub_q->fetch_assoc()['is_published'] == 1);
    
    $marks_sql = "
        SELECT sub.name as subject, m.marks_obtained 
        FROM marks m 
        JOIN subjects sub ON sub.code = m.subject_code 
        WHERE m.student_id = $user_id AND m.exam_id = $eid
    ";
    $m_res = $conn->query($marks_sql);
    $subjects = [];
    $total_obt = 0;
    
    while ($mk = $m_res->fetch_assoc()) {
        $subjects[] = $mk;
        $total_obt += $mk['marks_obtained'];
    }
    
    $exams[] = [
        'id' => $eid,
        'name' => $ex['name'],
        'max_marks_per_sub' => $ex['max_marks'],
        'is_published' => $is_published,
        'subjects' => $subjects,
        'total_obtained' => $total_obt
    ];
}
$response['exams'] = $exams;

// 4. FETCH TIMETABLE (Unchanged)
$timetable = [];
$tt_sql = "
    SELECT tt.day_of_week, tt.period_no, s.name AS subject_name 
    FROM timetables tt
    JOIN subjects s ON tt.subject_code = s.code
    WHERE tt.class_id = {$student['class_id']}
    ORDER BY tt.period_no
";
$tt_res = $conn->query($tt_sql);

while ($r = $tt_res->fetch_assoc()) {
    $timetable[$r['day_of_week']][] = [
        'period' => $r['period_no'],
        'subject' => $r['subject_name']
    ];
}
$response['timetable'] = $timetable;

echo json_encode(["status" => "success", "data" => $response]);
?>