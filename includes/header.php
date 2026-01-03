<?php
// Determine TWA status
$is_in_app = isset($_GET['source']) && $_GET['source'] === 'twa';
$twa_param = $is_in_app ? '?source=twa' : '';
$home_link = "index.php" . $twa_param;
$current_page = basename($_SERVER['PHP_SELF']);

function isActive($page) { 
    global $currentPage; 
    if (strpos($currentPage, $page) !== false) return 'active';
    return ''; 
}
?>

<!-- Inject TWA class into body if needed -->
<?php if($is_in_app): ?>
<script>document.body.classList.add('twa-view');</script>
<?php endif; ?>

<?php if (!$is_in_app): ?>
    <!-- BROWSER HEADER -->
    <header class="new-site-header">
        <div class="header-logo-row">
            <a href="<?= $home_link ?>">
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

<?php else: ?>

    <!-- TWA HEADER -->
    <?php if ($current_page == 'index.php'): ?>
        <div class="header-logo-row" style="justify-content:center; border-bottom:none; padding-top:15px;">
            <img src="GMPSimages/GMPS.header.logo.png" alt="GMPS" class="header-brand-img" style="height:50px;">
        </div>
    <?php elseif ($current_page == 'login.php'): ?>
        <!-- No header for login -->
    <?php else: ?>
        <?php 
        // HIDE HEADER IF NOT LOGGED IN ON PROFILE PAGES
        $is_profile_page = in_array($current_page, ['student.php','teacher.php','admin.php']);
        $user_logged_in = !empty($_SESSION['userType']);
        
        if (!$is_profile_page || $user_logged_in): 
        ?>
        <div class="app-header">
            <a href="<?= $home_link ?>" class="back-icon material-symbols-outlined">arrow_back</a>
            <span class="title">
                <?php 
                    if($current_page=='student.php') echo "Student Profile";
                    elseif($current_page=='teacher.php') echo "Teacher Dashboard";
                    elseif($current_page=='admin.php') echo "Admin Dashboard";
                    else echo ucfirst(str_replace('.php','',$current_page));
                ?>
            </span>
            
        </div>
    <?php endif; ?>

    <?php endif; ?>
<?php endif; ?>