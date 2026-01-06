<?php
require_once __DIR__ . '/includes/db_connect.php';
$currentPage = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Govind Madhav Public School - Academics</title>
    <?php include 'includes/meta.php'; ?>
</head>
<body>

<?php include 'includes/header.php'; ?>

<main class="academic">

    <!-- Hero Section -->
    <section class="twa-hero-card">
        <h1>Our Academic Programs</h1>
        <p>Govind Madhav Public School offers world-class education, fostering excellence and all-round development in every student.</p>
    </section>

    <!-- Curriculum Overview -->
    <section class="curriculum">
        <h2>Curriculum Overview</h2>
        <p>Our school follows the English-medium CBSE curriculum, offering a strong foundation in core subjects:</p>
        <ul>
            <?php
            $cur = $conn->query("SELECT bullet FROM academics_curriculum");
            while ($r = $cur->fetch_assoc()):
            ?>
            <li><?= htmlspecialchars($r['bullet']) ?></li>
            <?php endwhile; ?>
        </ul>
    </section>

    <!-- Class Levels -->
    <section class="class-levels">
        <h2>Class Levels</h2>
        <p>We cater to students from Nursery to Class 12, divided into the following levels:</p>
        <ul>
            <li><strong>Pre-Primary:</strong> Nursery, K1, K2</li>
            <li><strong>Primary:</strong> Classes 1 to 5</li>
            <li><strong>Middle:</strong> Classes 6 to 8</li>
            <li><strong>High School:</strong> Classes 9 and 10</li>
            <li><strong>Senior Secondary:</strong> Classes 11 and 12</li>
        </ul>
    </section>

    <!-- Teaching Methodology -->
    <section class="teaching-methodology">
        <h2>Our Teaching Methodology</h2>
        <div class="methodology">
            <div class="method">
                <img src="https://img.icons8.com/color/96/teaching.png" alt="Interactive Teaching">
                <p>Interactive and activity-based learning to engage students effectively.</p>
            </div>
            <div class="method">
                <img src="https://img.icons8.com/color/96/computer.png" alt="Technology Integration">
                <p>State-of-the-art technology integration for modern learning experiences.</p>
            </div>
            <div class="method">
                <img src="https://img.icons8.com/color/96/project.png" alt="Project-Based Learning">
                <p>Project-based learning to enhance problem-solving and teamwork skills.</p>
            </div>
        </div>
    </section>

    <!-- Extra-Curricular Activities -->
    <section class="extra-curricular">
        <h2>Extra-Curricular Activities</h2>
        <div class="activities">
            <div class="activity">
                <img src="https://img.icons8.com/color/96/paint-palette.png" alt="Arts">
                <p>Arts</p>
            </div>
            <div class="activity">
                <img src="https://img.icons8.com/color/96/music.png" alt="Music">
                <p>Music</p>
            </div>
            <div class="activity">
                <img src="https://img.icons8.com/color/96/dancing.png" alt="Dance">
                <p>Dance</p>
            </div>
            <div class="activity">
                <img src="https://img.icons8.com/color/96/running.png" alt="Sports">
                <p>Sports</p>
            </div>
        </div>
    </section>

    <!-- Achievements -->
    <section class="achievements">
        <h2>Achievements</h2>
        <div class="topper-section">
            <?php
            // fetch distinct groups
            $groups = $conn->query(
                "SELECT DISTINCT class_desc 
                FROM academics_toppers 
                ORDER BY class_desc DESC"
            );
            while ($g = $groups->fetch_assoc()):
                // for each group, get its toppers
                $cls = $conn->real_escape_string($g['class_desc']);
            ?>
            <h3><?= htmlspecialchars($g['class_desc']) ?></h3>
            <div class="toppers">
            <?php
                $list = $conn->query(
                "SELECT student_name,img_url 
                    FROM academics_toppers 
                    WHERE class_desc = '$cls'"
                );
                while ($t = $list->fetch_assoc()):
            ?>
                <div class="topper">
                <img src="<?= htmlspecialchars($t['img_url']) ?>" alt="">
                <p><?= htmlspecialchars($t['student_name']) ?></p>
                </div>
            <?php endwhile; ?>
            </div>
            <?php endwhile; ?>
        </div>
    </section>

    <!-- Facilities -->
    <section class="facilities">
        <h2>Our Facilities</h2>
        <?php
            $fac = $conn->query("SELECT img_url,description,is_reverse FROM academics_facilities ORDER BY id");
            while ($f = $fac->fetch_assoc()):
                $cls = $f['is_reverse'] ? 'facility reverse' : 'facility';
        ?>
            <div class="<?= $cls ?>">
                <img src="<?= htmlspecialchars($f['img_url']) ?>" alt="">
                <p><?= htmlspecialchars($f['description']) ?></p>
            </div>
        <?php endwhile; ?>
    </section>
</main>

<?php include 'footer.php'; ?>
    <!-- Sticky CTA Button -->
    <a href="admissions.php#admissions-form" id="ctaButton">Apply Now</a>
</body>
</html>
