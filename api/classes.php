<?php
require_once 'config.php';

// Fetch all classes ordered by their sort order (or ID)
$sql = "SELECT id, name FROM classes ORDER BY sort_order ASC, id ASC";
$result = $conn->query($sql);

$classes = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $classes[] = $row;
    }
}

echo json_encode($classes);
?>