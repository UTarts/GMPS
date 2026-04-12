<?php
require_once 'config.php';
require_once 'NotificationService.php';

$notifier = new NotificationService($conn);
$count = 0;

$sql = "SELECT id, name FROM students WHERE status='active' AND DATE_FORMAT(dob, '%m-%d') = DATE_FORMAT(CURDATE(), '%m-%d')";
$res = $conn->query($sql);

while($row = $res->fetch_assoc()) {
    $title = "🎉 Happy Birthday " . $row['name'] . "! 🎂";
    $body = "Wishing you a fantastic day from all of us at GMPS! Open the app for your special card.";
    // Send directly to this specific student
    $notifier->sendToUserIds([$row['id']], $title, $body, ['url' => '/']);
    $count++;
}

echo "Sent $count birthday notifications.";
?>