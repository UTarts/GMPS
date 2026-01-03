<?php
error_reporting(0);
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");

require_once 'config.php';

$response = [];

// 1. FETCH IMAGES (Newest First)
$images = [];
$res_img = $conn->query("SELECT id, image_url, caption, category, created_at FROM gallery_items ORDER BY created_at DESC");
if ($res_img) {
    while($row = $res_img->fetch_assoc()) $images[] = $row;
}
$response['images'] = $images;

// 2. FETCH VIDEOS (Newest First)
$videos = [];
$res_vid = $conn->query("SELECT id, video_url, caption, category, created_at FROM gallery_videos ORDER BY created_at DESC");
if ($res_vid) {
    while($row = $res_vid->fetch_assoc()) $videos[] = $row;
}
$response['videos'] = $videos;

echo json_encode(["status" => "success", "data" => $response]);
?>