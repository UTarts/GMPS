<?php
require_once __DIR__ . '/includes/db_connect.php';

// Handle Feedback (Web Version)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_feedback') {
    if (isset($_SESSION['student_id']) && !empty($_POST['message'])) {
        $sid = (int)$_SESSION['student_id'];
        $msg = trim($_POST['message']);
        $stmt = $conn->prepare("INSERT INTO student_feedback (student_id, message) VALUES (?, ?)");
        $stmt->bind_param("is", $sid, $msg);
        $stmt->execute();
        header("Location: index.php?feedback=success");
        exit;
    }
}

// --- DATA FETCHING ---
// Slideshow
$slideshow_res = $conn->query("SELECT img_url, alt_text FROM home_slideshow ORDER BY display_order");

// Upcoming Events
$upcoming_events_res = $conn->query("SELECT title, event_date, description, image_url FROM events_upcoming ORDER BY display_order LIMIT 3");

// Gallery
$home_gallery_res = $conn->query("SELECT image_url, alt_text FROM home_gallery ORDER BY id");

// Admin Thoughts
$admin_thoughts_res = $conn->query("SELECT name, position, image_url, quote FROM home_administration_thoughts ORDER BY id");

// Video Quote
$video_quote_res = $conn->query("SELECT heading, paragraph, video_url FROM home_video_quote LIMIT 1")->fetch_assoc();

// Statistics
$stats_res = $conn->query("SELECT label, value FROM home_statistics ORDER BY id");
?>

<!DOCTYPE html>
<html lang="en" style="scroll-behavior: smooth;">
<head>
    <title>Govind Madhav Public School - Home</title>
    <?php include 'includes/meta.php'; ?>
</head>
<body>
    
    <?php include 'includes/header.php'; ?>

    <section class="hero-new" data-aos="fade-in">
        <div class="hero-slideshow">
            <?php while($row = $slideshow_res->fetch_assoc()): ?>
                <div class="slide" style="background-image: url('<?= htmlspecialchars($row['img_url']) ?>');"></div>
            <?php endwhile; ?>
        </div>
        <div class="hero-overlay"></div>
        <div class="hero-content container">
            <h1 class="hero-title" data-aos="fade-down">Welcome to Govind Madhav Public School</h1>
            <p class="hero-subtitle" data-aos="fade-up" data-aos-delay="200">Nurturing Potential, Fostering Excellence.</p>
            <a href="admissions.php" class="hero-cta" data-aos="fade-up" data-aos-delay="400">Admissions Open</a>
        </div>
    </section>

    <section class="about">
        <div class="aboutus">
          <h2>About Us</h2>
          <h3>वाचः सत्यमशीमहि</h3>
          <p>Govind Madhav Public School is a center of excellence located in Pratapgarh. We strive to provide quality education and foster overall development in our students.</p>
          <p>Address: Pratapgarh-Sultanpur highway, opposite reliance petrol pump, GONDEY, <span style="color: #6a11cb; font-weight: bold;">PRATAPGARH</span> - 230403</p>
        </div>
    </section>
      
    <section class="gallery">
        <h2>Campus Life & Activities</h2>
        <div class="gallery-grid">
            <?php while($img = $home_gallery_res->fetch_assoc()): ?>
            <img 
                src="<?= htmlspecialchars($img['image_url']) ?>" 
                alt="<?= htmlspecialchars($img['alt_text']) ?>" 
                loading="lazy"
                width="300" height="200" 
            >
            <?php endwhile; ?>
        </div>
        <div style="text-align:center; margin-top:30px;">
            <a href="gallery.php" style=" color:#fff; font-size:0.9rem;background-color:rgb(45, 45, 45); border-radius: 10%; padding:10px 30px;">View School Gallery</a>
        </div>
    </section>
    
    <section class="modern-events-section" style=" padding: 20px 20px;">
        <div class="container" style="max-width:1200px; margin:0 auto;">
            <h2 class="section-title" style="text-align:center; border:none; margin-bottom:30px;">Recent Events</h2>
            
            <div class="modern-grid"> 
                <?php while($e = $upcoming_events_res->fetch_assoc()): ?>
                <div class="modern-card event-style">
                    <div class="event-date-badge">
                        <span class="month"><?= date('M', strtotime($e['event_date'])) ?></span>
                        <span class="day"><?= date('d', strtotime($e['event_date'])) ?></span>
                    </div>
                    <?php if(!empty($e['image_url'])): ?>
                        <div class="card-image-container skeleton-loading">
                            <img src="<?= htmlspecialchars($e['image_url']) ?>" alt="Event" loading="lazy">
                        </div>
                    <?php endif; ?>
                    <div class="card-content">
                        <h3><?= htmlspecialchars($e['title']) ?></h3>
                        <p><?= htmlspecialchars(substr($e['description'], 0, 100)) ?>...</p>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
        </div>
        <div style="text-align:center; margin-top:30px;">
            <a href="events.php" style=" color:#fff; font-size:0.9rem;background-color:rgb(45, 45, 45); padding:10px 30px;">View All Events</a>
        </div>
    </section>
    
    <section class="about-section">
        <div class="about-container">
            <h2>Our <span style="color: #e76910;">Mission</span> </h2>
            <p>
                At Govind Madhav Public School, our mission is to nurture every child's potential through quality education, 
                fostering values, and cultivating skills for a bright future.
            </p>
            <div class="about-icons">
                <div class="icon-card"><img src="https://img.icons8.com/color/96/school.png" alt="Education" loading="lazy"><p>World-Class Education</p></div>
                <div class="icon-card"><img src="https://img.icons8.com/color/96/teamwork.png" alt="Teamwork" loading="lazy"><p>Holistic Growth</p></div>
                <div class="icon-card" ><img src="https://img.icons8.com/color/96/leadership.png" alt="Leadership" loading="lazy"><p>Leadership Training</p></div>
                <div class="icon-card"><img src="https://img.icons8.com/color/96/running.png" alt="Sports" loading="lazy"><p>Sports Inspiration</p></div>
            </div>
        </div>
    </section>

    <section class="about-section">
        <div class="about-container">
            <h2>Our <span style="color: #e76910;">History</span></h2>
            <p>
                <span style="color: #6a11cb;">Established in 2018</span>, Govind Madhav Public School has grown from a small initiative to a recognized institution 
                for excellence. Located on the Pratapgarh-Sultanpur Highway, we are committed to shaping the leaders of tomorrow.
            </p>
            <img src="GMPSimages/gmps new bld.webp" alt="School History" class="history-image" data-aos="fade-down" loading="lazy">
        </div>
    </section>

    <section id="browser-admin-thoughts" class="admin-section">
        <div class="container">
            <h2 class="section-title">Insights from the <span style="color: var(--accent-color);">Administration</span></h2>
            <div class="centered-scroll-container" id="adminScrollContainer">
                <?php while($th = $admin_thoughts_res->fetch_assoc()): ?>
                <div class="admin-card">
                    <div class="skeleton-loading" style="width:100px; height:100px; border-radius:50%; margin:0 auto 15px auto; overflow:hidden;">
                        <img src="<?= htmlspecialchars($th['image_url']) ?>" alt="Admin" class="admin-photo" loading="lazy" style="width:100%; height:100%;">
                    </div>
                    <p class="admin-quote">"<?= htmlspecialchars($th['quote']) ?>"</p>
                    <h4 class="admin-name"><?= htmlspecialchars($th['name']) ?></h4>
                    <p class="admin-position"><?= htmlspecialchars($th['position']) ?></p>
                </div>
                <?php endwhile; ?>
            </div>
        </div>
    </section>

    <section id="our-team">
        <div class="container">
            <div class="team-photo skeleton-loading">
                <img src="GMPSimages/our team.webp" alt="Govind Madhav Public School Faculty Team" loading="lazy">
            </div>
        </div>
    </section>
  
    <section class="testimonials">
        <h2>What <span style="color: #e76910;">Parents</span> Say</h2>
        <div class="testimonials-grid">
            <div class="testimonial-card" data-aos="flip-up" data-aos-delay="100"><p>"This school has transformed my child's life! Amazing teachers and facilities."</p><h4>- Dharmendra Shukla</h4></div>
            <div class="testimonial-card" data-aos="flip-up" data-aos-delay="160"><p>"A perfect blend of academics and extracurricular activities."</p><h4>- Priya Sharma</h4></div>
            <div class="testimonial-card" data-aos="flip-up" data-aos-delay="220"><p>"Truly the best school in the region for all-round development."</p><h4>- Anil Singh</h4></div>
        </div>
    </section>
    
    <section class="video-quote">
        <div class="container">
            <div class="quote-text">
            <h2><?= htmlspecialchars($video_quote_res['heading']) ?></h2>
            <p><?= htmlspecialchars($video_quote_res['paragraph']) ?></p>
            </div>
            <div class="quote-video skeleton-loading">
                <iframe
                    width="100%" height="315"
                    src="<?= htmlspecialchars($video_quote_res['video_url']) ?>"
                    frameborder="0"
                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                    loading="lazy" 
                    allowfullscreen
                    onload="this.parentElement.classList.remove('skeleton-loading')">
                </iframe>
            </div>
        </div>
    </section>

    <section class="stats" data-aos="fade-up">
        <div class="container">
            <?php while($s = $stats_res->fetch_assoc()): 
                $target_number = preg_replace('/[^0-9]/', '', $s['value']);
                $original_value = htmlspecialchars($s['value']);
            ?>
            <div class="stat-box">
                <h3 class="stat-number" 
                    data-target="<?= $target_number ?>" 
                    data-original="<?= $original_value ?>"
                >0</h3>
                <p><?= htmlspecialchars($s['label']) ?></p>
            </div>
            <?php endwhile; ?>
        </div>
    </section>

    <?php include 'footer.php'; ?>
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/aos/2.3.4/aos.js"></script>
    <script>
        // 1. Initialize Animation Library
        AOS.init({ once: false, easing: 'ease-out', duration: 600 });

        // 2. High Performance Image Loading (Skeleton Remover)
        document.addEventListener("DOMContentLoaded", function() {
            const lazyImages = document.querySelectorAll('img[loading="lazy"]');
            
            const revealImage = (img) => {
                img.classList.add('loaded');
                // Find nearest skeleton container and remove class
                const container = img.closest('.skeleton-loading');
                if (container) container.classList.remove('skeleton-loading');
            };

            lazyImages.forEach(img => {
                if (img.complete) {
                    revealImage(img);
                } else {
                    img.onload = () => revealImage(img);
                }
            });
        });

        // 3. Stats Counter Animation
        document.addEventListener('DOMContentLoaded', function() {
            const statNumbers = document.querySelectorAll('.stat-number');
            const statsSection = document.querySelector('.stats');

            if (statsSection) {
                const observer = new IntersectionObserver(entries => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            statNumbers.forEach(counter => {
                                const target = +counter.getAttribute('data-target');
                                const originalText = counter.getAttribute('data-original'); 
                                let count = 0;
                                const updateCount = () => {
                                    const increment = Math.ceil(target / 100); 
                                    if (count < target) {
                                        count += increment;
                                        if (count > target) count = target;
                                        counter.innerText = count;
                                        setTimeout(updateCount, 20);
                                    } else {
                                        counter.innerText = originalText;
                                    }
                                };
                                updateCount();
                            });
                            observer.unobserve(statsSection);
                        }
                    });
                }, { threshold: 0.5 });
                observer.observe(statsSection);
            }
        });

        // 4. Center Scroll Admin Cards
        const adminContainer = document.getElementById('adminScrollContainer');
        if (adminContainer) {
            setTimeout(() => {
                const cards = adminContainer.querySelectorAll('.admin-card');
                if (cards.length >= 2) {
                    const cardWidth = cards[1].offsetWidth + 30; 
                    adminContainer.scrollTo({ left: cardWidth, behavior: 'smooth' });
                }
            }, 500);
        }
    </script>
    <a href="admissions.php#admissions-form" id="ctaButton">Apply Now</a>
</body>
</html>