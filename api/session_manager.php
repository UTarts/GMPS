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

// --- ONE-TIME SECURITY TOOL ---
if (isset($_GET['setup_auth']) && $_GET['setup_auth'] == 1) {
    $password = 'GMPS2018_EndOfSession';
    $hash = password_hash($password, PASSWORD_DEFAULT);
    
    // Update Database with Secure Hash
    $stmt = $conn->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'master_password_hash'");
    $stmt->bind_param("s", $hash);
    
    if ($stmt->execute()) {
        echo "<div style='font-family:sans-serif; padding:20px; background:#d4edda; color:#155724; border:1px solid #c3e6cb; border-radius:5px;'>";
        echo "<strong>Success!</strong> Master Password set to: <code>$password</code><br>";
        echo "Session Table is ready.";
        echo "</div>";
    } else {
        echo "Error updating password: " . $conn->error;
    }
    exit;
}
?>