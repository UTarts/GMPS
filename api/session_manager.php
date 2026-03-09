<?php
require_once __DIR__ . '/../includes/db_connect.php';


function get_current_session($conn) {
    $res = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'current_session'");
    if ($res && $res->num_rows > 0) {
        return $res->fetch_assoc()['setting_value'];
    }
    return '2025-2026'; 
}


function increment_session($conn) {
    $current = get_current_session($conn);
    $parts = explode('-', $current);
    
    // Ensure format is Year-Year
    if (count($parts) === 2 && is_numeric($parts[0]) && is_numeric($parts[1])) {
        $start = (int)$parts[0] + 1;
        $end = (int)$parts[1] + 1;
        $new_session = "$start-$end";
        
        $stmt = $conn->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'current_session'");
        $stmt->bind_param("s", $new_session);
        return $stmt->execute();
    }
    return false;
}

?>