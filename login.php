<?php
require_once __DIR__ . '/includes/db_connect.php';
$currentPage = 'login.php'; 

// GATEKEEPER: If already logged in, redirect to the appropriate dashboard
if (isset($_SESSION['userType'])) {
    $dashboard = htmlspecialchars($_SESSION['userType']) . '.php';
    header("Location: $dashboard");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Portal - Govind Madhav Public School</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" rel="stylesheet" />
    
    <link rel="stylesheet" href="styles.css">
    <style>
        body {
            background-color: var(--background-color, #F9F9FB);
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }
        .portal-container {
            flex: 1;
            padding: 40px 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 20px;
        }
        .login-choice-card {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            width: 100%;
            max-width: 350px;
            padding: 30px 20px;
            background-color: var(--surface-color, #FFFFFF);
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            gap: 15px;
            border: 1px solid var(--border-color, #EEE);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .login-choice-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 16px rgba(0,0,0,0.08);
        }
        .login-choice-card .icon {
            font-size: 48px;
            color: var(--primary-color, #4A55A2);
        }
        .login-choice-card .text {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--text-primary, #222);
        }
    </style>
</head>
<body>

<?php include 'includes/header.php'; ?>

    <main class="portal-container">
        
        <a href="student.php" class="login-choice-card">
            <span class="icon material-symbols-outlined">school</span>
            <span class="text">Student Login</span>
        </a>

        <a href="teacher.php" class="login-choice-card">
            <span class="icon material-symbols-outlined">person_book</span>
            <span class="text">Teacher Login</span>
        </a>

        <a href="admin.php" class="login-choice-card">
            <span class="icon material-symbols-outlined">admin_panel_settings</span>
            <span class="text">Admin Login</span>
        </a>
        
    </main>

</body>
</html>