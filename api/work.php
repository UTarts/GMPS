<?php
error_reporting(0);
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");

require_once 'config.php';

$input = json_decode(file_get_contents("php://input"), true);
$user_id = $input['user_id'] ?? 0;
$role = $input['role'] ?? 'guest';

// 1. Determine Student's Class
$student_class_id = 0;
if ($role === 'student') {
    $s_res = $conn->query("SELECT class_id FROM students WHERE id = $user_id");
    if ($row = $s_res->fetch_assoc()) $student_class_id = $row['class_id'];
} elseif ($role === 'teacher') {
    $t_res = $conn->query("SELECT assigned_class_id FROM teachers WHERE id = $user_id");
    if ($row = $t_res->fetch_assoc()) $student_class_id = $row['assigned_class_id'];
}

if ($student_class_id == 0) { echo json_encode(["status" => "error", "message" => "No class assigned"]); exit; }

// 2. Fetch Posts
$posts = [];
$sql = "SELECT dp.post_id, dp.post_date, dp.created_at, 
               t.name as teacher_name, t.profile_pic as teacher_pic, t.id as teacher_id, t.assigned_class_id
        FROM daily_posts dp
        JOIN teachers t ON dp.teacher_id = t.id
        WHERE dp.class_id = $student_class_id
        ORDER BY dp.post_date DESC, dp.created_at DESC
        LIMIT 30";

$res = $conn->query($sql);
if ($res) {
    while($post = $res->fetch_assoc()) {
        $pid = $post['post_id'];
        
        $is_classteacher = ($post['assigned_class_id'] == $student_class_id);
        $post['teacher_role'] = $is_classteacher ? 'Class Teacher' : 'Subject Teacher';
        
        if (!$is_classteacher) {
            $sub_q = $conn->query("SELECT s.name FROM teacher_subjects ts JOIN subjects s ON ts.subject_code = s.code WHERE ts.teacher_id = {$post['teacher_id']} LIMIT 1");
            if ($sub = $sub_q->fetch_assoc()) {
                $post['teacher_role'] = $sub['name'] . ' Teacher';
            }
        }

        // 3. Fetch Items WITH Subject Name Join
        $items = [];
        $i_sql = "SELECT pi.item_id, pi.item_type, pi.heading, pi.content, s.name as subject_name 
                  FROM post_items pi 
                  LEFT JOIN subjects s ON pi.subject_code = s.code 
                  WHERE pi.post_id = $pid ORDER BY pi.item_id";
        $i_res = $conn->query($i_sql);
        
        while($item = $i_res->fetch_assoc()) {
            $iid = $item['item_id'];
            $item['attachments'] = [];
            $item['defaulters'] = []; 
            
            $att_res = $conn->query("SELECT file_path FROM post_attachments WHERE item_id = $iid");
            while($att = $att_res->fetch_assoc()) $item['attachments'][] = $att['file_path'];

            if ($item['item_type'] === 'defaulter') {
                $def_res = $conn->query("SELECT s.name FROM post_defaulters pd JOIN students s ON pd.student_id = s.id WHERE pd.item_id = $iid");
                while($def = $def_res->fetch_assoc()) $item['defaulters'][] = $def['name'];
            }

            if (!$is_classteacher && $item['item_type'] !== 'defaulter') {
                $item['item_type'] = 'update';
            }
            $items[] = $item;
        }

        $post['items'] = $items;
        $posts[] = $post;
    }
}

echo json_encode(["status" => "success", "data" => $posts]);
?>