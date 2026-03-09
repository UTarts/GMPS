<?php
error_reporting(0);
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");

require_once 'config.php';

$action = $_POST['action'] ?? '';

// Helper for CBSE Grading
function getGrade($marks, $max) {
    if ($marks === null || $marks === '') return '-';
    $pct = ($marks / $max) * 100;
    if ($pct >= 91) return 'A1';
    if ($pct >= 81) return 'A2';
    if ($pct >= 71) return 'B1';
    if ($pct >= 61) return 'B2';
    if ($pct >= 51) return 'C1';
    if ($pct >= 41) return 'C2';
    if ($pct >= 33) return 'D';
    return 'E';
}

// ==========================================
// THIS IS THE BLOCK THAT FILLS THE DROPDOWNS
// ==========================================
if ($action === 'fetch_admin_filters') {
    $exams = [];
    $classes = [];
    $students = [];
    
    $e_res = $conn->query("SELECT id, name FROM exams ORDER BY id");
    while($e = $e_res->fetch_assoc()) $exams[] = $e;
    
    $c_res = $conn->query("SELECT id, name FROM classes ORDER BY sort_order");
    while($c = $c_res->fetch_assoc()) $classes[] = $c;
    
    $s_res = $conn->query("SELECT id, name, class_id FROM students WHERE status='active' ORDER BY name ASC");
    while($s = $s_res->fetch_assoc()) $students[] = $s;

    echo json_encode(['status' => 'success', 'exams' => $exams, 'classes' => $classes, 'students' => $students]);
    exit;
}

if ($action === 'generate_class_reports') {
    $cid = (int)$_POST['class_id'];
    $eid = (int)$_POST['exam_id'];
    $sid_param = isset($_POST['student_id']) && $_POST['student_id'] !== '' ? (int)$_POST['student_id'] : 0;

    // 1. Get Exam Details & Determine if it's a UT
    $ex_res = $conn->query("SELECT name FROM exams WHERE id = $eid");
    $exam_name = $ex_res->fetch_assoc()['name'];
    $is_ut = (stripos($exam_name, 'ut') !== false || stripos($exam_name, 'periodic') !== false);

    // 2. Get Class Details
    $c_res = $conn->query("SELECT name FROM classes WHERE id = $cid");
    $class_name = $c_res->fetch_assoc()['name'];

    $report_cards = [];

    // 3. Loop active students (Filter by specific student if requested by admin)
    $stu_query = "SELECT id, name, roll_no, father_name, mother_name, DATE_FORMAT(dob, '%d/%m/%Y') as dob, profile_pic 
                  FROM students WHERE class_id = $cid AND status='active'";
    if ($sid_param > 0) {
        $stu_query .= " AND id = $sid_param";
    }
    $stu_query .= " ORDER BY roll_no ASC";
    
    $stu_res = $conn->query($stu_query);
    
    while ($stu = $stu_res->fetch_assoc()) {
        $sid = $stu['id'];
        
        // A. Fetch Scholastic Marks
        $marks = [];
        $grand_total = 0;
        $max_grand_total = 0;
        
        $m_sql = "SELECT s.name as subject_name, m.pt_marks, m.notebook_marks, m.enrichment_marks, m.exam_marks, m.is_absent 
                  FROM marks m 
                  JOIN subjects s ON m.subject_code = s.code 
                  WHERE m.student_id = $sid AND m.exam_id = $eid 
                  ORDER BY s.name ASC";
        $m_res = $conn->query($m_sql);
        
        while ($m = $m_res->fetch_assoc()) {
            $total = 0;
            if ($m['is_absent'] == 0) {
                if ($is_ut) {
                    $total = (float)$m['exam_marks'];
                } else {
                    $total = (float)$m['pt_marks'] + (float)$m['notebook_marks'] + (float)$m['enrichment_marks'] + (float)$m['exam_marks'];
                }
                $grand_total += $total;
            }
            
            $max_sub_marks = $is_ut ? 20 : 100;
            $max_grand_total += $max_sub_marks;

            $marks[] = [
                'subject' => $m['subject_name'],
                'pt' => $m['pt_marks'],
                'nb' => $m['notebook_marks'],
                'se' => $m['enrichment_marks'],
                'exam' => $m['exam_marks'],
                'total' => $m['is_absent'] ? 'AB' : $total,
                'grade' => $m['is_absent'] ? 'AB' : getGrade($total, $max_sub_marks)
            ];
        }

        // B. Fetch Co-Scholastic Grades
        $co_scholastic = [];
        $c_sql = "SELECT skill_name, grade FROM co_scholastic_grades WHERE student_id = $sid AND exam_id = $eid";
        $c_res = $conn->query($c_sql);
        while ($c = $c_res->fetch_assoc()) {
            $co_scholastic[$c['skill_name']] = $c['grade'];
        }

        // C. Fetch Metadata (Attendance & Remarks)
        $meta_sql = "SELECT attendance_total, attendance_present, remarks FROM student_term_data WHERE student_id = $sid AND exam_id = $eid";
        $meta_res = $conn->query($meta_sql);
        $meta = $meta_res->fetch_assoc() ?: ['attendance_total' => '', 'attendance_present' => '', 'remarks' => ''];

        // D. Calculate Percentage
        $percentage = $max_grand_total > 0 ? round(($grand_total / $max_grand_total) * 100, 2) : 0;

        $report_cards[] = [
            'student' => $stu,
            'marks' => $marks,
            'co_scholastic' => $co_scholastic,
            'metadata' => $meta,
            'aggregates' => [
                'grand_total' => $grand_total,
                'max_total' => $max_grand_total,
                'percentage' => $percentage
            ]
        ];
    }

    echo json_encode([
        'status' => 'success',
        'exam_name' => $exam_name,
        'class_name' => $class_name,
        'is_ut' => $is_ut,
        'cards' => $report_cards
    ]);
    exit;
}
?>