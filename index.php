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
$upcoming_events_res = $conn->query("SELECT title, event_date, description, image_url FROM events_upcoming WHERE event_date >= CURDATE() ORDER BY event_date DESC LIMIT 3");
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
    <title>Govind Madhav Public School | Pratapgarh, UP</title>
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
            <h4 class="hero-title" data-aos="fade-down">Empowering Future Leaders in Pratapgarh.</h4>
            <p class="hero-subtitle" data-aos="fade-up" data-aos-delay="200">Nurturing Potential, Fostering Excellence.</p>
            <a href="admissions.php" class="hero-cta" data-aos="fade-up" data-aos-delay="400">Admissions Open 2026 ➜</a>
        </div>
    </section>

    <section class="master-about-section">
        <div class="container">
            
            <div class="about-header" data-aos="fade-down">
                <h4 class="sanskrit-tagline">वाचः सत्यमशीमहि</h4>
                <h2 class="prestige-heading">About <span class="highlight-gold">Us</span></h2>
                <div class="heading-line"></div>
            </div>

            <div class="history-pill-container" data-aos="zoom-in">
                <img src="GMPSimages/gmps new bld.webp" alt="Govind Madhav Public School Building" class="pill-image">
                <div class="est-badge">Est. 2018</div>
            </div>

            <div class="legacy-grid">
                
                <div class="award-column" data-aos="fade-right">
                    <div class="award-circle-wrapper">
                        <img src="GMPSimages/awardgmps.webp" alt="Excellence Award" class="award-img-floating">
                    </div>
                    <div class="award-label">
                        <span class="star">★</span> Excellence Award <span class="star">★</span>
                        <br><small>Honored by Dainik Jagran</small>
                    </div>
                </div>

                <div class="text-column" data-aos="fade-left">
                    <h3 class="mission-title">Shaping Leaders in <br> <span class="text-primary">Pratapgarh</span></h3>
                    
                    <p class="prestige-text">
                        Welcome to Govind Madhav Public School, the premier center of educational excellence in Gondey, Pratapgarh.
                    </p>
                    
                    <p class="prestige-text">
                        Since 2018, we have redefined English-medium education by blending modern academics with deep-rooted values, dedicated to nurturing every child into a future leader.
                    </p>
                </div>

                <div class="features-column">
                    <div class="mini-feature-item">
                        <img src="https://img.icons8.com/fluency/48/student-center.png" alt="Icon"> <span>Holistic Growth</span>
                    </div>
                    <div class="mini-feature-item">
                        <img src="https://img.icons8.com/fluency/48/trophy.png" alt="Icon"> <span>Top Sports</span>
                    </div>
                    <div class="mini-feature-item">
                        <img src="https://img.icons8.com/fluency/48/microscope.png" alt="Icon"> <span>Smart Labs</span>
                    </div>
                    <div class="mini-feature-item">
                        <img src="https://img.icons8.com/fluency/48/teacher.png" alt="Icon"> <span>Expert Faculty</span>
                    </div>
                </div>

            </div>

        </div>
    </section>
      
    <section class="premium-blog-section">
        <div class="container">
            <div class="section-header-center">
                <h4 class="mini-heading">Academic Insights</h4>
                <h3 class="main-heading">Expert Perspectives</h3>
                <div class="heading-line"></div>
            </div>

            <div class="blog-grid">
                
                <article class="blog-card" onclick="openBlog('blog1')">
                    <div class="blog-image">
                        <img src="GMPSimages/blog1.webp" alt="Best School in Pratapgarh" loading="lazy">
                        <span class="blog-category">Admissions</span>
                    </div>
                    <div class="blog-content">
                        <span class="blog-date">Oct 15, 2025</span>
                        <h3>Top 5 Reasons GMPS is the Best Choice in Pratapgarh</h3>
                        <p>Discover why parents in Gondey and Sultanpur Highway are switching to GMPS for their child's future.</p>
                        <span class="read-more">Read Article ➔</span>
                    </div>
                </article>

                <article class="blog-card" onclick="openBlog('blog2')">
                    <div class="blog-image">
                        <img src="GMPSimages/blog2.webp" alt="Holistic Education" loading="lazy">
                        <span class="blog-category">Student Life</span>
                    </div>
                    <div class="blog-content">
                        <span class="blog-date">Nov 02, 2025</span>
                        <h3>Beyond Academics: How GMPS Builds Character</h3>
                        <p>Education is more than marks. Learn how our sports and arts programs shape future leaders.</p>
                        <span class="read-more">Read Article ➔</span>
                    </div>
                </article>

                <article class="blog-card" onclick="openBlog('blog3')">
                    <div class="blog-image">
                        <img src="GMPSimages/blog3.webp" alt="Technology in School" loading="lazy">
                        <span class="blog-category">Innovation</span>
                    </div>
                    <div class="blog-content">
                        <span class="blog-date">Dec 10, 2025</span>
                        <h3>The Role of Technology in Modern Education</h3>
                        <p>From smart labs to digital learning, see how GMPS is preparing students for the AI era.</p>
                        <span class="read-more">Read Article ➔</span>
                    </div>
                </article>

            </div>
        </div>

        <div id="blog1" class="hidden-blog-content">
            <img src="GMPSimages/blog1.webp" alt="Best School in Pratapgarh" class="modal-hero-img">
            <h3>Top 5 Reasons Govind Madhav Public School is the Best Choice in Pratapgarh</h3>
            <p><strong>Are you looking for the best school in Pratapgarh?</strong> Choosing the right institution is the biggest decision a parent can make. Here is why Govind Madhav Public School (GMPS) stands above the rest.</p>
            <h4>1. Prime Location in Gondey.</h4>
            <p>Located conveniently in Gondey, our campus is safe, accessible, and away from the noise of the main city, providing a perfect learning environment.</p>
            <h4>2. CBSE Affiliated Curriculum</h4>
            <p>We follow a strict CBSE curriculum that prepares students not just for exams, but for competitive success in IIT-JEE and NEET.</p>
            <h4>3. Unmatched Sports Facilities</h4>
            <p>We believe in holistic development. Our sports complex allows students to excel physically while our labs train them mentally.</p>
            <h4>4. Experienced and Dedicated Faculty</h4>
            <p>Our highly qualified teachers focus on individual attention, concept clarity, and continuous mentoring to help every child reach their full potential.</p>

            <h4>5. Strong Focus on Discipline and Values</h4>
            <p>Along with academics, we nurture moral values, discipline, and confidence, shaping students into responsible and well-rounded individuals.</p>
            <div class="blog-quote">"Education is the passport to the future."</div>
            <p>Visit us today to see the difference yourself.</p>
        </div>

        <div id="blog2" class="hidden-blog-content">
         <img src="GMPSimages/blog2.webp" alt="Holistic Education" class="modal-hero-img">
            <h3>Beyond Academics: How GMPS Builds Character</h3>
            <p>At GMPS, we don't just create scholars; we create leaders. In today's world, emotional intelligence is as important as IQ.</p>
            <p>Through our <strong>House System</strong> and annual cultural fests, students learn teamwork, leadership, and public speaking.</p>
            <p>Our teachers act as mentors, guiding students through the challenges of growing up with empathy and discipline.</p>
        </div>

        <div id="blog3" class="hidden-blog-content">
            <img src="GMPSimages/blog3.webp" alt="Technology and Automation at GMPS" class="modal-hero-img">
            
            <h2>The Role of Technology: How GMPS is Future-Ready</h2>
            
            <p>The world is moving towards a digital future, and your child's school must lead the way. At Govind Madhav Public School (GMPS), we are not just using computers; we are <strong>automating our entire education system</strong> to make learning seamless, transparent, and efficient.</p>

            <h4>1. The GMPS Smart App: School in Your Pocket</h4>
            <p>We understand that parents are busy. That is why we have integrated a fully automated mobile app system that connects Students, Teachers, and Guardians instantly.</p>
            <ul>
                <li><strong>For Guardians:</strong> No more guessing. Check your child's <strong>Attendance</strong>, pay <strong>Fees</strong> online, track <strong>Home Work</strong>, and communicate directly with teachers—all from your phone.</li>
                <li><strong>For Students:</strong> Digital assignments, notes, and exam schedules are uploaded directly to the app, ensuring no student ever falls behind.</li>
                <li><strong>For Teachers:</strong> Automated attendance and digital reporting allow our teachers to focus less on paperwork and more on teaching your child.</li>
            </ul>

            <h4>2. AI Workshops & Future Skills</h4>
            <p>Using an app is just the beginning. We prepare students for the future job market by organizing specialized workshops on <strong>Artificial Intelligence (AI)</strong>, Coding, and Robotics.</p>
            
            <p>Combined with our high-speed Computer Labs and Smart Classrooms, we ensure our students are not just consumers of technology, but <strong>creators of it</strong>.</p>
        </div>

    <div id="blogModal" class="blog-modal">
        <div class="blog-modal-content">
            <span class="close-modal" onclick="closeBlog()">×</span>
            <div id="modalBody"></div>
        </div>
    </div>

    <section class="gallery-bento-section">
        <div class="container">
            
            <div class="section-header-left" style="text-align: center;">
                <h4 class="mini-heading">Campus Life</h4>
                <h3 class="main-heading">Life at <span class="highlight">GMPS</span></h3>
                <div class="heading-line"></div>
            </div>

            <div class="bento-grid">
                <?php 
                // Fetch up to 4 images for the grid
                $bento_res = $conn->query("SELECT image_url, alt_text FROM home_gallery ORDER BY id DESC LIMIT 4");
                $count = 0;
                $total_fetched = $bento_res->num_rows;

                while($img = $bento_res->fetch_assoc()): 
                    $count++;
                    $is_last = ($count == $total_fetched); // Check if this is the last image
                ?>
                    <a href="gallery.php" class="bento-item item-<?= $count ?>">
                        <img 
                            src="<?= htmlspecialchars($img['image_url']) ?>" 
                            alt="<?= htmlspecialchars($img['alt_text']) ?>" 
                            loading="lazy"
                        >
                        
                        <?php if ($is_last): ?>
                        <div class="more-overlay">
                            <span class="plus-number">+50</span>
                            <span class="more-text">View Gallery</span>
                        </div>
                        <?php endif; ?>
                    </a>
                <?php endwhile; ?>
            </div>
        </div>
    </section>

    <section id="browser-admin-thoughts" class="admin-section">
        <div class="container">
        <div class="section-header-left" style="text-align: center;">
                <h4 class="mini-heading">Insights from the</h4>
                <h3 class="main-heading">Administration</h3>
                <div class="heading-line"></div>
            </div>
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
  
    <section class="testimonials-modern">
        <div class="container">
            <div class="section-header-left" style="text-align: center;">
                <h4 class="mini-heading">Parent Voices</h4>
                <h3 class="main-heading">What They Say About <span class="highlight">GMPS</span></h3>
                <div class="heading-line"></div>
            </div>

            <div class="testimonial-slider">
                
                <div class="review-card">
                    <div class="review-header">
                        <!-- <img src="https://randomuser.me/api/portraits/men/32.jpg" alt="Parent" class="reviewer-img" loading="lazy"> -->
                        <div class="reviewer-initial">D</div>
                        <div class="reviewer-info">
                            <h4>Dharmendra Shukla</h4>
                            <span class="stars">★★★★★</span>
                        </div>
                    </div>
                    <p class="review-text">"This school has transformed my child's life! The teachers are incredibly supportive, and the focus on both studies and sports is exactly what we needed."</p>
                    <div class="review-footer">Parent of Class 10 Student</div>
                </div>

                <div class="review-card">
                    <div class="review-header">
                        <!-- <img src="https://randomuser.me/api/portraits/women/44.jpg" alt="Parent" class="reviewer-img" loading="lazy"> -->
                        <div class="reviewer-initial">P</div>
                        <div class="reviewer-info">
                            <h4>Priya Sharma</h4>
                            <span class="stars">★★★★★</span>
                        </div>
                    </div>
                    <p class="review-text">"A perfect blend of academics and extracurricular activities. My daughter loves going to school every day, which says it all! She even won a Gold medal in a Judo Championship."</p>
                    <div class="review-footer">Parent of Class 5 Student</div>
                </div>

                <div class="review-card">
                    <div class="review-header">
                        <!-- <img src="https://randomuser.me/api/portraits/men/85.jpg" alt="Parent" class="reviewer-img" loading="lazy"> -->
                        <div class="reviewer-initial">A</div>
                        <div class="reviewer-info">
                            <h4>Anil Singh</h4>
                            <span class="stars">★★★★★</span>
                        </div>
                    </div>
                    <p class="review-text">"Truly the best school in Pratapgarh. The new labs and smart classes have really improved the way children learn here, and dedicated Judo classes are really productive."</p>
                    <div class="review-footer">Parent of Class 8 Student</div>
                </div>

                <div class="review-card">
                    <div class="review-header">
                        <div class="reviewer-initial">S</div>
                        <div class="reviewer-info">
                            <h4>Sunita Verma</h4>
                            <span class="stars">★★★★★</span>
                        </div>
                    </div>
                    <p class="review-text">"Safe campus, disciplined environment, and very cooperative staff. I recommend Govind Madhav Public School to every parent in Pratapgarh."</p>
                    <div class="review-footer">Parent of Class 3 Student</div>
                </div>

                <div class="review-card">
                    <div class="review-header">
                        <!-- <img src="https://randomuser.me/api/portraits/men/22.jpg" alt="Parent" class="reviewer-img" loading="lazy"> -->
                        <div class="reviewer-initial">R</div>
                        <div class="reviewer-info">
                            <h4>Rajesh Gupta</h4>
                            <span class="stars">★★★★★</span>
                        </div>
                    </div>
                    <p class="review-text">"The improvement in my son's English speaking and confidence is remarkable. Thank you GMPS team!"</p>
                    <div class="review-footer">Parent of Class 6 Student</div>
                </div>

            </div>
            
            <p class="swipe-hint">← Swipe to read more →</p>
        </div>
    </section>
    
    <section class="prestige-events-section">
        <div class="container">
            
            <div class="section-header-center">
                <h4 class="mini-heading">Calendar</h4>
                <h3 class="main-heading">Upcoming <span class="highlight">Events</span></h3>
                <div class="heading-line"></div>
            </div>

            <div class="prestige-event-list">
                <?php 
                if ($upcoming_events_res->num_rows > 0):
                    while($e = $upcoming_events_res->fetch_assoc()): 
                ?>
                <div class="event-row">
                    
                    <div class="event-date-minimal">
                        <span class="day"><?= date('d', strtotime($e['event_date'])) ?></span>
                        <span class="month"><?= date('M', strtotime($e['event_date'])) ?></span>
                    </div>

                    <div class="event-info">
                        <h3><?= htmlspecialchars($e['title']) ?></h3>
                        <p><?= htmlspecialchars(substr($e['description'], 0, 120)) ?>...</p>
                        <div class="event-meta">
                            <span class="time-icon">🕒 8:00 AM</span> <span class="location-icon">📍 School Campus</span>
                        </div>
                    </div>

                    <?php if(!empty($e['image_url'])): ?>
                    <div class="event-thumb">
                        <img src="<?= htmlspecialchars($e['image_url']) ?>" alt="Event" loading="lazy">
                    </div>
                    <?php endif; ?>

                </div>
                <?php 
                    endwhile; 
                else:
                ?>
                    <p style="text-align:center; color:#777; padding:20px;">No upcoming events scheduled at the moment.</p>
                <?php endif; ?>
            </div>

            <div class="events-footer">
                <a href="events.php" class="view-all-link">View Full Calendar ➔</a>
            </div>

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
        // BLOG MODAL LOGIC
        function openBlog(blogId) {
            const content = document.getElementById(blogId).innerHTML;
            const modal = document.getElementById('blogModal');
            const modalBody = document.getElementById('modalBody');
            
            modalBody.innerHTML = content;
            modal.classList.add('active');
            document.body.style.overflow = 'hidden'; // Stop background scrolling
        }

        function closeBlog() {
            const modal = document.getElementById('blogModal');
            modal.classList.remove('active');
            document.body.style.overflow = 'auto'; // Restore scrolling
        }

        // Close if clicked outside
        document.getElementById('blogModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeBlog();
            }
        });
    </script>
    <a href="admissions.php#admissions-form" id="ctaButton">Apply Now</a>
    <style>
    footer {
        margin-bottom: -50px !important;
        position: relative;
        z-index: 100;
        }
    </style>
    <?php include 'footer.php'; ?>
</body>
</html>