<?php
error_reporting(0); // Prevents HTML warnings from breaking the JSON response
date_default_timezone_set('Asia/Kolkata');
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once 'config.php';
$input = json_decode(file_get_contents("php://input"), true);
$action = $input['action'] ?? $_GET['action'] ?? '';

try {
    // 1. TEACHER: Get their own monthly record
    if ($action === 'get_my_record') {
        $teacher_id = (int)$input['teacher_id'];
        $month = (int)$input['month'];
        $year = (int)$input['year'];
        $date_prefix = sprintf("%04d-%02d", $year, $month);
        
        $sql = "SELECT date, punch_in, punch_out, status FROM teacher_attendance WHERE teacher_id = $teacher_id AND date LIKE '$date_prefix%' ORDER BY date DESC";
        $res = $conn->query($sql);
        if (!$res) throw new Exception($conn->error);
        
        $data = [];
        while($row = $res->fetch_assoc()) {
            $data[] = $row;
        }
        echo json_encode(['status' => 'success', 'data' => $data]);
        exit;
    }

    // 2. ADMIN: Get Live Tracker & Calculate Shift Rules (FIXED UNION ERROR)
    if ($action === 'get_admin_live') {
        $date = $conn->real_escape_string($input['date'] ?? date('Y-m-d'));
        
        // Query 1: Teachers
        $sql_t = "
            SELECT 'teacher' as type, t.id, t.name, t.profile_pic, a.punch_in, a.punch_out, a.status, COALESCE(a.is_override, 0) as is_override,
                   COALESCE(us.shift_start, '08:00:00') as shift_start, COALESCE(us.shift_end, '14:00:00') as shift_end, COALESCE(us.shift_hours, 6.00) as shift_hours
            FROM teachers t 
            LEFT JOIN teacher_attendance a ON t.id = a.teacher_id AND a.date = '$date'
            LEFT JOIN user_salaries us ON us.user_id = t.id AND us.user_type = 'teacher'";
            
        // Query 2: Staff
        $sql_s = "
            SELECT 'staff' as type, s.id, s.name, s.profile_pic, a.punch_in, a.punch_out, a.status, COALESCE(a.is_override, 0) as is_override,
                   COALESCE(us.shift_start, '08:00:00') as shift_start, COALESCE(us.shift_end, '14:00:00') as shift_end, COALESCE(us.shift_hours, 6.00) as shift_hours
            FROM staff_directory s 
            LEFT JOIN staff_attendance a ON s.id = a.staff_id AND a.date = '$date'
            LEFT JOIN user_salaries us ON us.user_id = s.id AND us.user_type = 'staff'";
                
        $res_t = $conn->query($sql_t);
        if (!$res_t) throw new Exception("SQL Error (Teachers): " . $conn->error);
        
        $res_s = $conn->query($sql_s);
        if (!$res_s) throw new Exception("SQL Error (Staff): " . $conn->error);

        // Merge the arrays in PHP to bypass MySQL Collation issues
        $raw_data = [];
        while($row = $res_t->fetch_assoc()) $raw_data[] = $row;
        while($row = $res_s->fetch_assoc()) $raw_data[] = $row;

        // Sort by punch_in DESC, then name ASC
        usort($raw_data, function($a, $b) {
            $timeA = $a['punch_in'] ? strtotime($a['punch_in']) : 0;
            $timeB = $b['punch_in'] ? strtotime($b['punch_in']) : 0;
            if ($timeA === $timeB) return strcmp($a['name'], $b['name']);
            return $timeB - $timeA;
        });

        $data = [];
        $today = date('Y-m-d');
        
        foreach($raw_data as $row) {
            $row['hours_worked'] = 0;
            $row['calculated_status'] = $row['status'] ? $row['status'] : 'absent';
            $row['is_override'] = (int)$row['is_override'];

            if ($row['punch_in']) {
                $in_time = strtotime($date . ' ' . $row['punch_in']);
                $out_time = $row['punch_out'] ? strtotime($date . ' ' . $row['punch_out']) : null;
                $shift_start = strtotime($date . ' ' . $row['shift_start']);
                
                // Calculate Hours Worked
                if ($out_time) {
                    $row['hours_worked'] = round(($out_time - $in_time) / 3600, 2);
                } else if ($date === $today) {
                    $row['hours_worked'] = round((time() - $in_time) / 3600, 2); 
                }

                // Apply Strict Auto-Rules (Only if Admin hasn't overridden)
                if ($row['is_override'] === 0) {
                    $late_mins = ($in_time - $shift_start) / 60;
                    $calc_status = 'present';
                    
                    if ($late_mins > 30) $calc_status = 'halfday';
                    if ($out_time && $row['hours_worked'] < (float)$row['shift_hours']) $calc_status = 'absent';
                    
                    $row['calculated_status'] = $calc_status;
                }
            }
            $data[] = $row;
        }
        echo json_encode(['status' => 'success', 'data' => $data]);
        exit;
    }

    // 3. ADMIN: Manual Override Save
    if ($action === 'admin_edit_attendance') {
        $uid = $input['uid']; 
        $date = $conn->real_escape_string($input['date']);
        $punch_in = !empty($input['punch_in']) ? "'".$conn->real_escape_string($input['punch_in'])."'" : "NULL";
        $punch_out = !empty($input['punch_out']) ? "'".$conn->real_escape_string($input['punch_out'])."'" : "NULL";
        $status = $conn->real_escape_string($input['status']);

        $is_teacher = strpos($uid, 'teacher_') === 0;
        $id = (int)str_replace($is_teacher ? 'teacher_' : 'staff_', '', $uid);
        $table = $is_teacher ? 'teacher_attendance' : 'staff_attendance';
        $col = $is_teacher ? 'teacher_id' : 'staff_id';

        $sql = "INSERT INTO $table ($col, date, punch_in, punch_out, status, is_override) 
                VALUES ($id, '$date', $punch_in, $punch_out, '$status', 1) 
                ON DUPLICATE KEY UPDATE punch_in=$punch_in, punch_out=$punch_out, status='$status', is_override=1";
                
        if (!$conn->query($sql)) throw new Exception($conn->error);
        echo json_encode(['status' => 'success']);
        exit;
    }

    // 4. ADMIN: Get Monthly Mini-Calendar for User
    if ($action === 'get_user_month') {
        $uid = $input['uid'];
        $month = (int)$input['month'];
        $year = (int)$input['year'];
        
        $is_teacher = strpos($uid, 'teacher_') === 0;
        $id = (int)str_replace($is_teacher ? 'teacher_' : 'staff_', '', $uid);
        $table = $is_teacher ? 'teacher_attendance' : 'staff_attendance';
        $col = $is_teacher ? 'teacher_id' : 'staff_id';

        $date_prefix = sprintf("%04d-%02d", $year, $month);
        $res = $conn->query("SELECT date, punch_in, punch_out, status, is_override FROM $table WHERE $col = $id AND date LIKE '$date_prefix%' ORDER BY date ASC");
        if (!$res) throw new Exception($conn->error);
        
        $data = [];
        while($row = $res->fetch_assoc()) $data[] = $row;
        echo json_encode(['status' => 'success', 'data' => $data]);
        exit;
    }

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}
?>