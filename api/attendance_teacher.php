<?php
error_reporting(0);
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");

require_once 'config.php';
require_once 'NotificationService.php'; // <--- IMPORT SERVICE

$input = json_decode(file_get_contents("php://input"), true);
$action = $input['action'] ?? '';

// Initialize Notifier
$notifier = new NotificationService($conn);

// 1. FETCH STUDENTS FOR ATTENDANCE (Card Stack Data)
if ($action === 'fetch_class') {
    $class_id = (int)$input['class_id'];
    $date = $input['date'] ?? date('Y-m-d');

    // --- SMART CALENDAR CHECK ---
    $holiday_name = null;
    $is_holiday = false;
    $cal_sql = "SELECT title FROM academic_calendar WHERE type = 'holiday' AND ('$date' BETWEEN date_start AND COALESCE(date_end, date_start)) LIMIT 1";
    $cal_res = $conn->query($cal_sql);
    if ($cal_res && $cal_res->num_rows > 0) {
        $is_holiday = true;
        $holiday_name = $cal_res->fetch_assoc()['title'];
    }
    // Get students + their existing status for this date
    // FIXED: Added roll_no and ordered by it
    $sql = "
        SELECT s.id, s.name, s.login_id, s.profile_pic, s.roll_no,
               COALESCE(da.status, 'pending') as status
        FROM students s
        LEFT JOIN daily_attendance da ON s.id = da.student_id AND da.date = '$date'
        WHERE s.class_id = $class_id AND s.status = 'active'
        ORDER BY s.roll_no ASC
    ";
    
    $res = $conn->query($sql);
    $students = [];
    while($row = $res->fetch_assoc()) $students[] = $row;
    
    // Get stats
    $stats_sql = "SELECT status, COUNT(*) as count FROM daily_attendance WHERE class_id = $class_id AND date = '$date' GROUP BY status";
    $stats_res = $conn->query($stats_sql);
    $stats = ['present'=>0, 'absent'=>0, 'total'=>count($students)];
    while($row = $stats_res->fetch_assoc()) $stats[$row['status']] = (int)$row['count'];

    echo json_encode([
        'status' => 'success', 
        'data' => $students, 
        'stats' => $stats,
        'calendar_info' => [
            'is_holiday' => $is_holiday,
            'holiday_name' => $holiday_name
        ]
    ]);
    exit;
}

// 2. SAVE BULK ATTENDANCE (With Notification Injection)
if ($action === 'save_batch') {
    $teacher_id = (int)$input['teacher_id'];
    $class_id = (int)$input['class_id'];
    $date = $input['date'];
    $records = $input['records']; 

    $stmt = $conn->prepare("INSERT INTO daily_attendance (student_id, class_id, date, status, recorded_by) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE status = VALUES(status), recorded_by = VALUES(recorded_by)");
    
    // Arrays to collect IDs for notifications
    $presentIds = [];
    $absentIds = [];

    foreach ($records as $rec) {
        $sid = (int)$rec['student_id'];
        $stat = $rec['status'];
        $stmt->bind_param("iisss", $sid, $class_id, $date, $stat, $teacher_id);
        $stmt->execute();

        // Sort into lists for notification
        if ($stat === 'present') {
            $presentIds[] = $sid;
        } elseif ($stat === 'absent') {
            $absentIds[] = $sid;
        }
    }

    // --- TRIGGER NOTIFICATIONS ---
    $formattedDate = date("d/m/Y", strtotime($date));

    // 1. Notify PRESENT Students
    if (!empty($presentIds)) {
        $notifier->sendToUserIds(
            $presentIds, 
            "✅ Attendance: Present", 
            "You have been marked PRESENT for today ($formattedDate)."
        );
    }

    // 2. Notify ABSENT Students
    if (!empty($absentIds)) {
        $notifier->sendToUserIds(
            $absentIds, 
            "❌ Attendance: Absent", 
            "You have been marked ABSENT for today ($formattedDate)."
        );
    }
    
    echo json_encode(['status'=>'success', 'message'=>'Attendance saved']);
    exit;
}

// 3. FETCH MONTHLY CALENDAR SUMMARY
if ($action === 'fetch_month_summary') {
    $class_id = (int)$input['class_id'];
    $month = (int)$input['month']; 
    $year = (int)$input['year'];

    $sql = "SELECT date, status, COUNT(*) as count 
            FROM daily_attendance 
            WHERE class_id = $class_id 
            AND MONTH(date) = $month 
            AND YEAR(date) = $year 
            GROUP BY date, status";
    
    $res = $conn->query($sql);
    
    $calendar = [];
    while($row = $res->fetch_assoc()) {
        $d = $row['date'];
        if (!isset($calendar[$d])) {
            $calendar[$d] = ['status' => 'taken', 'stats' => []];
        }
        $calendar[$d]['stats'][$row['status']] = (int)$row['count'];
        
        if ($row['status'] === 'holiday') {
            $calendar[$d]['status'] = 'holiday';
        }
    }

    echo json_encode(['status'=>'success', 'data'=>$calendar]);
    exit;
}

// 4. MARK HOLIDAY (Bulk Action)
if ($action === 'mark_holiday') {
    $teacher_id = (int)$input['teacher_id'];
    $class_id = (int)$input['class_id'];
    $date = $input['date'];

    $stu_res = $conn->query("SELECT id FROM students WHERE class_id = $class_id AND status = 'active'");
    
    if ($stu_res->num_rows > 0) {
        $stmt = $conn->prepare("INSERT INTO daily_attendance (student_id, class_id, date, status, recorded_by) VALUES (?, ?, ?, 'holiday', ?) ON DUPLICATE KEY UPDATE status = 'holiday', recorded_by = VALUES(recorded_by)");
        
        $idsForNotification = [];
        
        while($s = $stu_res->fetch_assoc()) {
            $sid = (int)$s['id'];
            $stmt->bind_param("iisi", $sid, $class_id, $date, $teacher_id);
            $stmt->execute();
            $idsForNotification[] = $sid;
        }

        // Notify Everyone about Holiday
        if (!empty($idsForNotification)) {
            $formattedDate = date("d/m/Y", strtotime($date));
            $notifier->sendToUserIds(
                $idsForNotification, 
                "🎉 Holiday Alert", 
                "School is closed on $formattedDate. Enjoy your day!"
            );
        }
    }

    echo json_encode(['status'=>'success', 'message'=>'Holiday marked successfully']);
    exit;
}
?>