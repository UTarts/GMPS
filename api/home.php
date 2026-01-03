<?php
require_once 'config.php';

$input = json_decode(file_get_contents("php://input"), true);
$user_id = $input['user_id'] ?? 0;
$role = $input['role'] ?? 'guest';

$response = [];

// 1. SLIDESHOW
$slides = [];
$res = $conn->query("SELECT img_url, alt_text FROM home_slideshow ORDER BY display_order");
while($row = $res->fetch_assoc()) $slides[] = $row;
$response['slides'] = $slides;

// 2. ANNOUNCEMENTS (LATEST FIRST)
$announcements = [];
// Added 'created_at'
$res = $conn->query("SELECT title, content, image_url, created_at FROM events_announcements ORDER BY created_at DESC LIMIT 3");
while($row = $res->fetch_assoc()) $announcements[] = $row;
$response['announcements'] = $announcements;

// 3. DAILY UPDATES (LATEST FIRST)
$updates = [];
// Added 'created_at'
$res = $conn->query("SELECT update_text, image_url, created_at FROM events_daily_updates ORDER BY created_at DESC LIMIT 3");
while($row = $res->fetch_assoc()) $updates[] = $row;
$response['updates'] = $updates;

// 4. ADMIN THOUGHTS
$thoughts = [];
$res = $conn->query("SELECT name, position, image_url, quote FROM home_administration_thoughts ORDER BY id");
while($row = $res->fetch_assoc()) $thoughts[] = $row;
$response['thoughts'] = $thoughts;

// 5. GALLERY
$gallery = [];
$res = $conn->query("SELECT image_url, caption FROM gallery_items ORDER BY created_at DESC LIMIT 5");
while($row = $res->fetch_assoc()) $gallery[] = $row;
$response['gallery'] = $gallery;

// 6. CONTACTS
$contacts = [];
$res = $conn->query("SELECT method, value FROM contact_methods ORDER BY display_order");
while($row = $res->fetch_assoc()) $contacts[$row['method']] = $row['value'];
$response['contacts'] = $contacts;

// 7. CLASS TOPPERS & 8. USER DETAILS
$response['show_toppers'] = false;
$response['toppers'] = [];
$target_class_id = 0;

$response['user_details'] = [ 'class_name' => '', 'designation' => $role === 'teacher' ? 'Faculty Member' : 'Guest' ];

if ($role === 'student' && $user_id > 0) {
    $s_res = $conn->query("SELECT s.class_id, c.name as class_name FROM students s JOIN classes c ON s.class_id = c.id WHERE s.id = $user_id");
    if ($s_row = $s_res->fetch_assoc()) {
        $target_class_id = $s_row['class_id'];
        $response['user_details']['class_name'] = "Class " . $s_row['class_name'];
        $response['user_details']['designation'] = "Student";
    }
} elseif ($role === 'teacher' && $user_id > 0) {
    $t_res = $conn->query("SELECT assigned_class_id, c.name as class_name FROM teachers t LEFT JOIN classes c ON t.assigned_class_id = c.id WHERE t.id = $user_id");
    if ($t_row = $t_res->fetch_assoc()) {
        $target_class_id = $t_row['assigned_class_id'];
        if($target_class_id) $response['user_details']['class_name'] = "Class Teacher (" . $t_row['class_name'] . ")";
    }
}

if ($target_class_id > 0) {
    $c_check = $conn->query("SELECT show_toppers FROM classes WHERE id = $target_class_id");
    if ($c_row = $c_check->fetch_assoc()) {
        if ($c_row['show_toppers'] == 1) {
            $response['show_toppers'] = true;
            $top_res = $conn->query("SELECT rank, student_name, percentage, image_url FROM class_toppers WHERE class_id = $target_class_id ORDER BY rank ASC");
            while($t = $top_res->fetch_assoc()) $response['toppers'][] = $t;
        }
    }
}

echo json_encode(["status" => "success", "data" => $response]);
?>