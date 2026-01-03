<?php
error_reporting(0);
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");

require_once 'config.php'; 

$response = [];

// 1. DAILY UPDATES (LATEST FIRST)
$updates = [];
// Added 'created_at' to query
$u_sql = "SELECT id, update_text, image_url, created_at FROM events_daily_updates ORDER BY created_at DESC LIMIT 20";
if ($res = $conn->query($u_sql)) {
    while($row = $res->fetch_assoc()) $updates[] = $row;
}
$response['updates'] = $updates;

// 2. ANNOUNCEMENTS (LATEST FIRST)
$announcements = [];
// Added 'created_at' to query
$a_sql = "SELECT id, title, content, image_url, created_at FROM events_announcements ORDER BY created_at DESC LIMIT 20";
if ($res = $conn->query($a_sql)) {
    while($row = $res->fetch_assoc()) $announcements[] = $row;
}
$response['announcements'] = $announcements;

// 3. EVENTS (NEXT UPCOMING FIRST)
$events = [];
$e_sql = "SELECT id, title, description, event_date, image_url FROM events_upcoming WHERE event_date >= CURDATE() ORDER BY event_date ASC LIMIT 10";
if ($res = $conn->query($e_sql)) {
    while($row = $res->fetch_assoc()) $events[] = $row;
}
$response['events'] = $events;

echo json_encode(["status" => "success", "data" => $response]);
?>