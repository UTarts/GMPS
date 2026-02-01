<?php
// 1. Allow the app to talk to this file (CORS)
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header('Content-Type: application/json');

// 2. Handle the "Pre-flight" check (Browser security standard)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once 'config.php';

// 3. Receive the JSON data from the App
$input = json_decode(file_get_contents("php://input"), true);

// 4. Validate the input (Make sure message isn't empty)
if (!isset($input['user_id']) || !isset($input['message']) || empty(trim($input['message']))) {
    echo json_encode(['status' => 'error', 'message' => 'Message cannot be empty']);
    exit;
}

$student_id = (int)$input['user_id'];
$message = $conn->real_escape_string(trim($input['message']));

// 5. INSERT the data into the database
$sql = "INSERT INTO student_feedback (student_id, message) VALUES ($student_id, '$message')";

if ($conn->query($sql)) {
    echo json_encode(['status' => 'success', 'message' => 'Feedback sent!']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Database error']);
}

$conn->close();
?>