<?php
require_once __DIR__ . '/includes/db_connect.php';
// Helper to convert Instagram links into embedded highlights
function renderHighlight($text) {
    $escaped = htmlspecialchars($text);
    // Convert Instagram URLs to iframes seamlessly
    $replaced = preg_replace(
        '/(https?:\/\/(?:www\.)?instagram\.com\/(?:p|reel)\/[a-zA-Z0-9_-]+)\/?/i',
        '<iframe src="$1/embed" width="100%" height="480" frameborder="0" scrolling="no" allowtransparency="true" style="border-radius:12px; margin-top:10px; border:1px solid #e5e7eb; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);"></iframe>',
        $escaped
    );
    return nl2br($replaced);
}
// --- DATA FETCHING ---
// Reverted sorting to 'display_order' as requested

// 1. Announcements
$anns = $conn->query("SELECT title, content, image_url FROM events_announcements ORDER BY display_order");

// 2. Daily Updates
$ups = $conn->query("SELECT update_text, image_url FROM events_daily_updates ORDER BY display_order");

// 3. Upcoming Events
$evs = $conn->query("SELECT title, event_date, description, image_url FROM events_upcoming ORDER BY display_order");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Govind Madhav Public School - Events and Announcements</title>
    <?php include 'includes/meta.php'; ?>
</head>
<body>

<?php include 'includes/header.php'; ?>

    <section class="twa-hero-card">
        <h1>Events & Updates</h1>
        <p>Stay informed about the latest happenings at our school.</p>
    </section>

    <div class="events-page-container">

        <?php if ($anns->num_rows > 0): ?>
        <section class="modern-events-section">
            <h2 class="section-title">📢 Important Announcements</h2>
            <div class="modern-grid">
                <?php while($a = $anns->fetch_assoc()): ?>
                <div class="modern-card announcement-style">
                    <?php if(!empty($a['image_url'])): ?>
                        <div class="card-image-container skeleton-loading">
                            <img 
                                src="<?= htmlspecialchars($a['image_url']) ?>" 
                                alt="Announcement Image"
                                loading="lazy"
                            >
                        </div>
                    <?php endif; ?>
                    <div class="card-content">
                        <h3><?= htmlspecialchars($a['title']) ?></h3>
                        <p><?= nl2br(htmlspecialchars($a['content'])) ?></p>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($ups->num_rows > 0): ?>
        <section class="modern-events-section">
            <h2 class="section-title">📝 Daily Updates</h2>
            <div class="modern-grid">
                <?php while($u = $ups->fetch_assoc()): ?>
                <div class="modern-card update-style">
                    <?php if(!empty($u['image_url'])): ?>
                        <div class="card-image-container small-height skeleton-loading">
                            <img 
                                src="<?= htmlspecialchars($u['image_url']) ?>" 
                                alt="Update Image"
                                loading="lazy"
                            >
                        </div>
                    <?php endif; ?>
                    <div class="card-content">
                        <?= renderHighlight($u['update_text']) ?>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($evs->num_rows > 0): ?>
        <section class="modern-events-section">
            <h2 class="section-title">📅 Upcoming Events</h2>
            <div class="modern-grid">
                <?php while($e = $evs->fetch_assoc()): ?>
                <div class="modern-card event-style">
                    <div class="event-date-badge">
                        <span class="month"><?= date('M', strtotime($e['event_date'])) ?></span>
                        <span class="day"><?= date('d', strtotime($e['event_date'])) ?></span>
                    </div>
                    <?php if(!empty($e['image_url'])): ?>
                        <div class="card-image-container skeleton-loading">
                            <img 
                                src="<?= htmlspecialchars($e['image_url']) ?>" 
                                alt="Event Image"
                                loading="lazy"
                            >
                        </div>
                    <?php endif; ?>
                    <div class="card-content">
                        <h3><?= htmlspecialchars($e['title']) ?></h3>
                        <p><?= nl2br(htmlspecialchars($e['description'])) ?></p>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
        </section>
        <?php endif; ?>

    </div>

    <?php include 'footer.php'; ?>
    
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            // Reveal Images smoothly once loaded
            const lazyImages = document.querySelectorAll('img[loading="lazy"]');
            
            const revealItem = (element) => {
                element.classList.add('loaded'); 
                const container = element.closest('.skeleton-loading');
                if (container) container.classList.remove('skeleton-loading');
            };

            lazyImages.forEach(el => {
                if (el.complete) {
                    revealItem(el);
                } else {
                    el.onload = () => revealItem(el);
                }
            });
        });
    </script>
</body>
</html>