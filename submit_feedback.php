<?php
error_reporting(E_ALL);
ini_set('display_errors', 0); // Hide HTML errors to prevent JSON breakage
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header('Content-Type: application/json');

// Handle Preflight Options Request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once 'config.php';

// Get JSON Input
$input = json_decode(file_get_contents("php://input"), true);

// Validate Input
if (!isset($input['user_id']) || !isset($input['message']) || empty(trim($input['message']))) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid input']);
    exit;
}

$student_id = (int)$input['user_id'];
$message = $conn->real_escape_string(trim($input['message']));
$role = $input['role'] ?? 'student';

// Only allow students to submit feedback (Optional security check)
if ($role !== 'student') {
    echo json_encode(['status' => 'error', 'message' => 'Only students can submit feedback']);
    exit;
}

// Insert into Database
$sql = "INSERT INTO student_feedback (student_id, message) VALUES ($student_id, '$message')";

if ($conn->query($sql)) {
    echo json_encode(['status' => 'success', 'message' => 'Feedback submitted successfully']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
}

$conn->close();
?>