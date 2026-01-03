<?php
require_once __DIR__ . '/includes/db_connect.php';

// --- DATA FETCHING ---
$items = $conn->query("
    SELECT image_url AS url, caption, category, 'img' AS type, created_at
    FROM gallery_items
    UNION ALL
    SELECT video_url AS url, caption, category, 'vid' AS type, created_at
    FROM gallery_videos
    ORDER BY created_at DESC
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Govind Madhav Public School - Gallery</title>
    <?php include 'includes/meta.php'; ?>
    <style>
        .gallery-controls {
            max-width: 800px;
            margin: 0 auto 30px auto;
            padding: 0 20px;
            text-align: center;
        }
        .type-tabs {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-bottom: 20px;
        }
        .type-btn {
            background: #fff;
            border: 2px solid #eee;
            padding: 10px 25px;
            border-radius: 30px;
            font-weight: 700;
            font-family: 'Poppins', sans-serif;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            color: #666;
        }
        .type-btn.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .category-tabs {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 10px;
        }
        .cat-btn {
            background: transparent;
            border: none;
            font-size: 0.9rem;
            color: #888;
            font-weight: 600;
            cursor: pointer;
            padding: 5px 10px;
            position: relative;
        }
        .cat-btn.active { color: #333; }
        .cat-btn.active::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 20px;
            height: 2px;
            background: var(--accent-color);
        }
        .no-items {
            text-align: center;
            grid-column: 1 / -1;
            padding: 50px;
            color: #999;
            display: none;
        }
    </style>
</head>
<body>

<?php include 'includes/header.php'; ?>

    <div class="gallery-container" style="min-height: 60vh;">
        
        <section class="gallery-hero">
            <h1>Our Moments Captured</h1>
            <p>Life at Govind Madhav Public School</p>
        </section>

        <div class="gallery-controls">
            <div class="type-tabs">
                <button class="type-btn active" data-filter-type="img">
                    <span class="material-symbols-outlined">photo_library</span> Photos
                </button>
                <button class="type-btn" data-filter-type="vid">
                    <span class="material-symbols-outlined">videocam</span> Videos
                </button>
            </div>

            <div class="category-tabs">
                <button class="cat-btn active" data-filter-cat="all">All</button>
                <button class="cat-btn" data-filter-cat="academic">Academic</button>
                <button class="cat-btn" data-filter-cat="sports">Sports</button>
                <button class="cat-btn" data-filter-cat="cultural">Cultural</button>
                <button class="cat-btn" data-filter-cat="infrastructure">Campus</button>
            </div>
        </div>

        <div class="gallery-grid-layout" id="galleryGrid">
            <?php while($it = $items->fetch_assoc()): ?>
                <div 
                    class="modern-card gallery-card" 
                    data-type="<?= $it['type'] ?>" 
                    data-category="<?= htmlspecialchars($it['category']) ?>"
                >
                    <?php if($it['type'] == 'img'): ?>
                        <div class="card-image-container skeleton-loading" style="height:250px;">
                            <img 
                                src="<?= htmlspecialchars($it['url']) ?>" 
                                alt="<?= htmlspecialchars($it['caption']) ?>"
                                class="expandable-image"
                                loading="lazy"
                            > 
                        </div>
                    <?php else: ?>
                        <div class="gallery-video-container skeleton-loading" style="height:250px; background:#000;">
                            <iframe
                                src="<?= htmlspecialchars($it['url']) ?>"
                                frameborder="0"
                                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                                allowfullscreen
                                loading="lazy"
                            >
                            </iframe>
                        </div>
                    <?php endif; ?>
                    
                    <div class="gallery-caption">
                        <?= htmlspecialchars($it['caption']) ?>
                    </div>
                </div>
            <?php endwhile; ?>
            <div class="no-items" id="noItemsMsg">No items found in this category.</div>
        </div>

    </div>

    <?php include 'footer.php'; ?>

    <script src="lightbox.js"></script>
    
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            // 1. REVEAL IMAGES SCRIPT (Fix for invisible images)
            const lazyImages = document.querySelectorAll('img[loading="lazy"], iframe[loading="lazy"]');
            
            const revealItem = (element) => {
                element.classList.add('loaded'); // Reveals image (opacity: 1)
                const container = element.closest('.skeleton-loading');
                if (container) container.classList.remove('skeleton-loading'); // Removes gray shimmer
            };

            lazyImages.forEach(el => {
                if (el.complete) {
                    revealItem(el);
                } else {
                    el.onload = () => revealItem(el);
                }
            });

            // 2. FILTER LOGIC
            const typeBtns = document.querySelectorAll('.type-btn');
            const catBtns = document.querySelectorAll('.cat-btn');
            const cards = document.querySelectorAll('.gallery-card');
            const noItemsMsg = document.getElementById('noItemsMsg');

            let currentType = 'img'; 
            let currentCat = 'all';

            function filterGallery() {
                let visibleCount = 0;
                cards.forEach(card => {
                    const cardType = card.getAttribute('data-type');
                    const cardCat = card.getAttribute('data-category');
                    
                    const typeMatch = (cardType === currentType);
                    const catMatch = (currentCat === 'all' || cardCat === currentCat);

                    if (typeMatch && catMatch) {
                        card.style.display = 'block';
                        visibleCount++;
                    } else {
                        card.style.display = 'none';
                    }
                });
                noItemsMsg.style.display = visibleCount === 0 ? 'block' : 'none';
            }

            typeBtns.forEach(btn => {
                btn.addEventListener('click', () => {
                    typeBtns.forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                    currentType = btn.getAttribute('data-filter-type');
                    filterGallery();
                });
            });

            catBtns.forEach(btn => {
                btn.addEventListener('click', () => {
                    catBtns.forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                    currentCat = btn.getAttribute('data-filter-cat');
                    filterGallery();
                });
            });

            filterGallery(); // Init
        });
    </script>
</body>
</html>