<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header('Content-Type: application/json');

require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// --- 1. FETCH ALL POSTS (For Admin Dashboard) ---
if ($action === 'fetch_all_posts') {
    $data = [
        'notices' => [],
        'updates' => [],
        'events' => [],
        'whats_today' => null
    ];

    // Notices
    $res = $conn->query("SELECT * FROM events_announcements ORDER BY id DESC");
    while($r = $res->fetch_assoc()) $data['notices'][] = $r;

    // Updates
    $res = $conn->query("SELECT * FROM events_daily_updates ORDER BY id DESC");
    while($r = $res->fetch_assoc()) $data['updates'][] = $r;

    // Events
    $res = $conn->query("SELECT * FROM events_upcoming ORDER BY id DESC");
    while($r = $res->fetch_assoc()) $data['events'][] = $r;

    // What's Today
    $res = $conn->query("SELECT * FROM whats_today LIMIT 1");
    if($r = $res->fetch_assoc()) $data['whats_today'] = $r;

    echo json_encode(['status' => 'success', 'data' => $data]);
    exit;
}

// --- 2. SAVE POSTS (Create/Edit) ---
if ($method === 'POST' && in_array($action, ['save_notice', 'save_update', 'save_event'])) {
    
    // File Upload Helper
    $uploadFile = function($fileKey) {
        if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) return null;
        $name = time() . '_' . basename($_FILES[$fileKey]['name']);
        $target = __DIR__ . '/../GMPSimages/' . $name;
        if(move_uploaded_file($_FILES[$fileKey]['tmp_name'], $target)) {
            return 'GMPSimages/' . $name;
        }
        return null;
    };

    if ($action === 'save_notice') {
        $title = $conn->real_escape_string($_POST['title']);
        $content = $conn->real_escape_string($_POST['content']);
        $img = $uploadFile('image');
        
        $sql = "INSERT INTO events_announcements (title, content, image_url, display_order) VALUES ('$title', '$content', '$img', 0)";
        if($img === null) $sql = "INSERT INTO events_announcements (title, content, display_order) VALUES ('$title', '$content', 0)";
        
        $conn->query($sql);
    }

    if ($action === 'save_update') {
        $text = $conn->real_escape_string($_POST['text']);
        $img = $uploadFile('image');
        $sql = "INSERT INTO events_daily_updates (update_text, image_url, display_order) VALUES ('$text', '$img', 0)";
        $conn->query($sql);
    }

    if ($action === 'save_event') {
        $title = $conn->real_escape_string($_POST['title']);
        $desc = $conn->real_escape_string($_POST['description']);
        $date = $conn->real_escape_string($_POST['date']);
        $img = $uploadFile('image');
        $sql = "INSERT INTO events_upcoming (title, event_date, description, image_url, display_order) VALUES ('$title', '$date', '$desc', '$img', 0)";
        $conn->query($sql);
    }

    echo json_encode(['status' => 'success']);
    exit;
}

// --- 3. DELETE POST ---
if ($action === 'delete_post') {
    $id = (int)$_POST['id'];
    $type = $_POST['type']; // 'notice', 'update', 'event'
    
    $table = '';
    if($type === 'notice') $table = 'events_announcements';
    if($type === 'update') $table = 'events_daily_updates';
    if($type === 'event') $table = 'events_upcoming';
    
    if($table) $conn->query("DELETE FROM $table WHERE id=$id");
    echo json_encode(['status' => 'success']);
    exit;
}

// --- 4. "WHAT'S TODAY" LOGIC (Upload & Cleanup) ---

if ($action === 'save_whats_today') {
    // 1. Delete Old One First (Only 1 allowed per day)
    $conn->query("TRUNCATE TABLE whats_today");
    
    // 2. Upload New
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $name = 'today_' . time() . '.jpg'; // Unique name
        $target = __DIR__ . '/../GMPSimages/' . $name;
        move_uploaded_file($_FILES['image']['tmp_name'], $target);
        $dbPath = 'GMPSimages/' . $name;
        
        $conn->query("INSERT INTO whats_today (image_url) VALUES ('$dbPath')");
    }
    echo json_encode(['status' => 'success']);
    exit;
}

if ($action === 'get_whats_today_public') {
    // Lazy Cleanup: Check if image is old
    $res = $conn->query("SELECT * FROM whats_today LIMIT 1");
    if ($row = $res->fetch_assoc()) {
        $created = strtotime($row['created_at']);
        $today_start = strtotime('today midnight');
        
        // If image is from BEFORE today (yesterday or older)
        if ($created < $today_start) {
            // Delete file from server (Optional, good for space)
            $filePath = __DIR__ . '/../' . $row['image_url'];
            if(file_exists($filePath)) unlink($filePath);
            
            // Delete from DB
            $conn->query("TRUNCATE TABLE whats_today");
            echo json_encode(['status' => 'empty']); // Return nothing
        } else {
            echo json_encode(['status' => 'success', 'data' => $row]);
        }
    } else {
        echo json_encode(['status' => 'empty']);
    }
    exit;
}
// --- 5. GALLERY UPLOAD HANDLER (NEW) ---

if ($action === 'save_gallery_item') {
    $caption = $conn->real_escape_string($_POST['caption']);
    $category = $conn->real_escape_string($_POST['category']);
    
    // A. Handle Multiple Images
    if (isset($_FILES['images'])) {
        $count = count($_FILES['images']['name']);
        
        // Loop through each file
        for ($i = 0; $i < $count; $i++) {
            if ($_FILES['images']['error'][$i] === UPLOAD_ERR_OK) {
                $name = time() . '_' . $i . '_' . basename($_FILES['images']['name'][$i]);
                $target = __DIR__ . '/../GMPSimages/' . $name;
                
                if (move_uploaded_file($_FILES['images']['tmp_name'][$i], $target)) {
                    $dbPath = 'GMPSimages/' . $name;
                    // Insert each image as a separate row
                    $conn->query("INSERT INTO gallery_items (image_url, caption, category, created_at) VALUES ('$dbPath', '$caption', '$category', NOW())");
                }
            }
        }
    } 
    // B. Handle Video
    elseif (isset($_POST['video_url'])) {
        $url = $conn->real_escape_string($_POST['video_url']);
        $conn->query("INSERT INTO gallery_videos (video_url, caption, category, created_at) VALUES ('$url', '$caption', '$category', NOW())");
    }

    echo json_encode(['status' => 'success']);
    exit;
}
// --- 6. FETCH GALLERY HISTORY (New) ---
if ($action === 'fetch_gallery_history') {
    $history = [];
    
    // Fetch Photos (Limit 50)
    $res = $conn->query("SELECT id, image_url as url, caption, 'photo' as type, created_at FROM gallery_items ORDER BY id DESC LIMIT 50");
    while($r = $res->fetch_assoc()) $history[] = $r;

    // Fetch Videos (Limit 20)
    $res = $conn->query("SELECT id, video_url as url, caption, 'video' as type, created_at FROM gallery_videos ORDER BY id DESC LIMIT 20");
    while($r = $res->fetch_assoc()) $history[] = $r;

    // Sort combined list by date desc
    usort($history, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });

    echo json_encode(['status' => 'success', 'data' => array_slice($history, 0, 50)]);
    exit;
}
// --- 7. DELETE GALLERY ITEM (New) ---
if ($action === 'delete_gallery_item') {
    $id = (int)$_POST['id'];
    $type = $_POST['type']; // 'photo' or 'video'
    
    if ($type === 'photo') {
        // Optional: Delete file from server to save space
        $res = $conn->query("SELECT image_url FROM gallery_items WHERE id=$id");
        if ($r = $res->fetch_assoc()) {
            $path = __DIR__ . '/../' . $r['image_url'];
            if (file_exists($path)) unlink($path);
        }
        $conn->query("DELETE FROM gallery_items WHERE id=$id");
    } elseif ($type === 'video') {
        $conn->query("DELETE FROM gallery_videos WHERE id=$id");
    }
    
    echo json_encode(['status' => 'success']);
    exit;
}
?>