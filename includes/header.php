<?php
if (!isset($currentPage)) {
    $currentPage = basename($_SERVER['PHP_SELF']);
}

function isActive($page) { 
    global $currentPage; 
    if (strpos($currentPage, $page) !== false) return 'active';
    return ''; 
}
?>

<header class="new-site-header">
    <div class="header-logo-row">
        <a href="index.php">
            <img src="GMPSimages/GMPS.header.logo.png" alt="Govind Madhav Public School" class="header-brand-img">
        </a>
    </div>
    <div class="header-nav-row">
        <ul class="new-desktop-nav">
            <li><a href="index.php" class="<?= isActive('index.php') ?>">Home</a></li>
            <li><a href="academics.php" class="<?= isActive('academics.php') ?>">Academics</a></li>
            <li><a href="gallery.php" class="<?= isActive('gallery.php') ?>">Gallery</a></li>
            <li><a href="admissions.php" class="<?= isActive('admissions.php') ?>">Admissions</a></li>
            <li><a href="events.php" class="<?= isActive('events.php') ?>">Events</a></li>
            <li><a href="mandatory_disclosure.php" class="<?= isActive('mandatory_disclosure.php') ?>">Mandatory Disclosure</a></li>
            <li><a href="contact.php" class="<?= isActive('contact.php') ?>">Contact</a></li>
            <?php if(!empty($_SESSION['userType'])): ?>
                <li><a href="<?= htmlspecialchars($_SESSION['userType']) ?>.php" style="background:#FFD700; color:#000;">Profile</a></li>
            <?php else: ?>
                <li><a href="login.php">Login</a></li>
            <?php endif; ?>
        </ul>

        <div class="mobile-nav-bar">
            <a href="index.php" class="mobile-home-link">Home</a>
            <div class="mobile-menu-toggle" onclick="toggleBrowserSidebar()">
                <span>Menu</span>
                <span class="material-symbols-outlined" style="font-size: 32px;">menu</span>
            </div>
        </div>
    </div>
    
    <nav class="sidebar" id="mobileBrowserSidebar">
        <button class="close-sidebar-btn" onclick="toggleBrowserSidebar()">×</button>
        <ul>
            <li><a href="index.php">Home</a></li>
            <li><a href="academics.php">Academics</a></li>
            <li><a href="gallery.php">Gallery</a></li>
            <li><a href="admissions.php">Admissions</a></li>
            <li><a href="events.php">Events</a></li>
            <li><a href="mandatory_disclosure.php">Mandatory Disclosure</a></li>
            <li><a href="contact.php">Contact</a></li>
            <?php if(!empty($_SESSION['userType'])): ?>
                <li><a href="<?= htmlspecialchars($_SESSION['userType']) ?>.php">Profile</a></li>
            <?php else: ?>
                <li><a href="login.php">Login</a></li>
            <?php endif; ?>
        </ul>
    </nav>
    <script>
        function toggleBrowserSidebar() {
            document.getElementById('mobileBrowserSidebar').classList.toggle('show');
        }
    </script>
</header>