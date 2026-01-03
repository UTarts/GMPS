<?php
require_once __DIR__ . '/includes/db_connect.php';
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Mandatory Disclosure - Govind Madhav Public School</title>
    <?php include 'includes/meta.php'; ?>
</head>
<body class="<?php echo (isset($_GET['source']) && $_GET['source'] === 'twa') ? 'app-body twa-view' : ''; ?>">

    <?php include 'includes/header.php'; ?>

    <div class="gallery-container" style="max-width: 900px; margin: 40px auto; padding: 20px;">
        
        <section class="gallery-hero" style="background: linear-gradient(135deg, #2c3e50, #4ca1af);">
            <h1>Mandatory Disclosure</h1>
            <p>Public Disclosure Documents</p>
        </section>

        <div class="disclosure-list">
            <?php
            $docs = $conn->query("SELECT heading, file_path FROM mandatory_disclosures ORDER BY display_order");
            if ($docs->num_rows > 0):
                while($doc = $docs->fetch_assoc()):
            ?>
            <div class="disclosure-item">
                <div class="doc-icon">
                    <span class="material-symbols-outlined">description</span>
                </div>
                <div class="doc-info">
                    <h3><?= htmlspecialchars($doc['heading']) ?></h3>
                </div>
                <a href="<?= htmlspecialchars($doc['file_path']) ?>" target="_blank" class="doc-download-btn">
                    <span class="material-symbols-outlined">visibility</span> View
                </a>
            </div>
            <?php endwhile; else: ?>
                <p style="text-align:center; color:#666;">No documents uploaded yet.</p>
            <?php endif; ?>
        </div>

    </div>

    <?php if (!(isset($_GET['source']) && $_GET['source'] === 'twa')): ?>
        <?php include 'footer.php'; ?>
    <?php endif; ?>
    
    <?php if(isset($_GET['source']) && $_GET['source'] === 'twa' && isset($_SESSION['student_id'])) include 'bottom-nav.php'; ?>

</body>
</html>