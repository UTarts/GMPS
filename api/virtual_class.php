<?php
error_reporting(0);
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");

require_once 'config.php';
require_once 'NotificationService.php'; // Hooking into your amazing notification engine!

$action = $_POST['action'] ?? '';

// Helper to extract YouTube ID from any format (youtu.be, youtube.com/watch, live, etc.)
function extractYTId($url) {
    preg_match('%(?:youtube(?:-nocookie)?\.com/(?:[^/]+/.+/|(?:v|e(?:mbed)?)/|.*[?&]v=)|youtu\.be/|youtube\.com/live/)([^"&?/\s]{11})%i', $url, $match);
    return $match[1] ?? '';
}

if ($action === 'fetch_init') {
    $role = $_POST['role'] ?? '';
    $user_id = (int)($_POST['user_id'] ?? 0);
    $class_id = (int)($_POST['class_id'] ?? 0); 
    
    $response = ['status' => 'success', 'classes' => [], 'videos' => []];
    
    if ($role === 'teacher') {
        $c_res = $conn->query("SELECT id, name FROM classes ORDER BY sort_order");
        while($c = $c_res->fetch_assoc()) $response['classes'][] = $c;
        
        $v_sql = "SELECT * FROM virtual_classes WHERE teacher_id = $user_id ORDER BY created_at DESC";
    } else {
        // Students only see videos meant for their class
        $v_sql = "
            SELECT v.*, t.name as teacher_name, t.profile_pic as teacher_pic 
            FROM virtual_classes v 
            JOIN teachers t ON v.teacher_id = t.id 
            WHERE FIND_IN_SET('$class_id', v.class_ids) > 0 
            ORDER BY v.created_at DESC
        ";
    }
    
    $v_res = $conn->query($v_sql);
    if($v_res) {
        while($v = $v_res->fetch_assoc()) {
            $v['formatted_date'] = date('d M Y, h:i A', strtotime($v['created_at']));
            $response['videos'][] = $v;
        }
    }
    
    echo json_encode($response);
    exit;
}

if ($action === 'upload_video') {
    $teacher_id = (int)$_POST['teacher_id'];
    $teacher_name = $conn->real_escape_string($_POST['teacher_name']);
    $title = $conn->real_escape_string($_POST['title']);
    $desc = $conn->real_escape_string($_POST['description']);
    $type = $conn->real_escape_string($_POST['type']); // 'live' or 'recorded'
    $url = $conn->real_escape_string($_POST['youtube_url']);
    $class_ids = $conn->real_escape_string($_POST['class_ids']); 
    
    $yt_id = extractYTId($url);
    if (empty($yt_id)) {
        echo json_encode(['status'=>'error', 'message'=>'Invalid YouTube URL']);
        exit;
    }
    
    $sql = "INSERT INTO virtual_classes (teacher_id, type, title, description, youtube_url, youtube_video_id, class_ids) 
            VALUES ($teacher_id, '$type', '$title', '$desc', '$url', '$yt_id', '$class_ids')";
            
    if($conn->query($sql)) {
        
        // --- TRIGGER YOUR NOTIFICATION SYSTEM ---
        $notif = new NotificationService($conn);
        $classes_array = explode(',', $class_ids);
        
        $push_title = $type === 'live' ? "🔴 Live Class: $title" : "📺 New Video Lecture";
        $push_body = $type === 'live' ? "$teacher_name has started a live session." : "$teacher_name posted: $title. Watch now!";
        
        foreach($classes_array as $cid) {
            // Sends the push notification to FCM using your exact class!
            $notif->sendToClass($cid, $push_title, $push_body, ['route' => '/virtual-class']); 
        }

        echo json_encode(['status'=>'success']);
    } else {
        echo json_encode(['status'=>'error', 'message'=>$conn->error]);
    }
    exit;
}

if ($action === 'delete_video') {
    $id = (int)$_POST['id'];
    $teacher_id = (int)$_POST['teacher_id']; 
    $conn->query("DELETE FROM virtual_classes WHERE id = $id AND teacher_id = $teacher_id");
    echo json_encode(['status'=>'success']);
    exit;
}
?>