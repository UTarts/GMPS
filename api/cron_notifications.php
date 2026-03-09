<?php
// This script should be triggered daily via Hostinger Cron Jobs at 6:00 PM
error_reporting(E_ALL);
require_once 'config.php';
require_once 'NotificationService.php';

$notifier = new NotificationService($conn);

// Check if tomorrow is a holiday or exam
$tomorrow = date('Y-m-d', strtotime('+1 day'));

$sql = "SELECT title, type FROM academic_calendar 
        WHERE '$tomorrow' BETWEEN date_start AND COALESCE(date_end, date_start)";
$res = $conn->query($sql);

if ($res && $res->num_rows > 0) {
    while ($event = $res->fetch_assoc()) {
        $title = $event['title'];
        
        if ($event['type'] === 'holiday') {
            // Get all active user IDs (students and teachers)
            $users = [];
            $s_res = $conn->query("SELECT id FROM students WHERE status='active'");
            while($s = $s_res->fetch_assoc()) $users[] = $s['id']; // We will broadcast to everyone via topic later, but loop works for now.
            
            // Using your existing, working broadcast method
            $notifier->broadcastToAll(
                "🎉 Tomorrow is a Holiday!", 
                "Reminder: School is closed tomorrow for $title."
            );
            echo "Holiday notification sent for: $title\n";
        } elseif ($event['type'] === 'exam') {
            $notifier->broadcastToAll(
                "📝 Exam Reminder", 
                "Reminder: $title begins tomorrow. Best of luck!"
            );
            echo "Exam notification sent for: $title\n";
        }
    }
} else {
    echo "No events scheduled for tomorrow.\n";
}
?>