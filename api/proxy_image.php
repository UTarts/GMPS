<?php
// api/proxy_image.php
error_reporting(0);
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");

if (isset($_GET['path'])) {
    // Security: Remove any ".." to prevent directory traversal attacks
    $clean_path = str_replace('..', '', $_GET['path']);
    
    // The images are one folder up from 'api' in 'GMPSimages'
    // DB stores 'GMPSimages/file.jpg', so we go up one level
    $local_path = __DIR__ . '/../' . $clean_path;

    if (file_exists($local_path)) {
        // Get the correct mime type (jpg, png, etc.)
        $mime = mime_content_type($local_path);
        header("Content-Type: $mime");
        readfile($local_path);
        exit;
    }
}

// Return a 1x1 transparent pixel if file not found (prevents broken image icons)
header("Content-Type: image/png");
echo base64_decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=");
?>