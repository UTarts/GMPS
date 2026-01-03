<?php
require_once __DIR__.'/includes/db_connect.php';
$currentPage = basename($_SERVER['PHP_SELF']);

// Handle login form submission
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='studentLogin') {
    $login = $conn->real_escape_string($_POST['userid']);
    $pw    = $_POST['password'];
    $class = (int)$_POST['class'];
    $res   = $conn->query("SELECT * FROM students WHERE login_id='$login' AND class_id=$class");
    
    if ($row = $res->fetch_assoc()) {
      if (password_verify($pw, $row['password_hash'])) {
        $_SESSION['student_id'] = $row['id'];
        $_SESSION['userType'] = 'student';
        
        // REMEMBER ME
        if (isset($_POST['remember'])) {
            setRememberMe($conn, $row['id'], 'student');
        }
        
        header("Location: " . basename($_SERVER['PHP_SELF']));
        exit;
      } else {
        $error = "Invalid credentials.";
      }
    } else {
      $error = "Invalid credentials.";
    }
}  

// LOGOUT LOGIC
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_unset();
    session_destroy();
    if (isset($_COOKIE['remember_token'])) {
        setcookie('remember_token', '', time() - 3600, "/", "", false, true);
        unset($_COOKIE['remember_token']);
        $token_hash = hash('sha256', $_COOKIE['remember_token']);
        $stmt = $conn->prepare("DELETE FROM login_tokens WHERE token_hash = ?");
        $stmt->bind_param("s", $token_hash);
        $stmt->execute();
    }
    header("Location: login.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Student Dashboard - Govind Madhav Public School</title>
    <?php include 'includes/meta.php'; ?>
    <style>
        /* Specific tweaks for dashboard readability */
        .profile-card { margin-bottom: 30px; }
        .dashboard { padding-top: 10px; min-height: 40vh; }
    </style>
</head>
<body>

<?php include 'includes/header.php'; ?>

    <script>
        // Sticky Header Logic
        let lastScrollPosition = 0;
        const header = document.querySelector('header');
        window.addEventListener('scroll', () => {
            const currentScrollPosition = window.pageYOffset;
            if (currentScrollPosition > lastScrollPosition) {
                header.style.transform = 'translateY(-100%)';
            } else {
                header.style.transform = 'translateY(0)';
            }
            lastScrollPosition = currentScrollPosition;
        });
    </script>

    <?php if (!isset($_SESSION['student_id'])): ?>
    <section class="login-container" id="loginSection">
        <?php if (!empty($error)): ?>
        <div class="error" style="color:red; margin-bottom: 10px;"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <div class="login-box">
        <h2>Student Login</h2>
        <form method="post" id="loginForm">
            <input type="hidden" name="action" value="studentLogin">
                <div class="input-group">
                    <label for="userid">User ID</label>
                    <input type="text" id="userid" name="userid" required>
                </div>
                <div class="input-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <div class="input-group">
                    <label for="class">Select Class</label>
                    <select id="class" name="class" required>
                    <?php
                        $classes = $conn->query("SELECT id,name FROM classes ORDER BY id");
                        while($c = $classes->fetch_assoc()):
                    ?>
                        <option value="<?=$c['id']?>"><?=htmlspecialchars($c['name'])?></option>
                    <?php endwhile; ?>
                    </select>
                </div>
                <div style="margin:10px 0;"><label><input type="checkbox" name="remember"> Keep me logged in</label></div>
                <button type="submit">Login</button>
            </form>
        </div>
    </section>
    <?php endif; ?>

    <?php if (isset($_SESSION['student_id'])): ?>
    <div class="dashboard" id="profileSection">
        
        <nav class="sidebar profile-sidebar" id="studentSidebar">
            <button class="close-sidebar-btn" onclick="toggleSidebar()">×</button>
            <ul>
                <li><a href="#profile">Profile</a></li>
                <li><a href="#attendance">Attendance</a></li>
                <li><a href="#results">Results</a></li>
                <li><a href="#timetable">Timetable</a></li>
                <li>
                    <a href="?action=logout" class="button" style="color:#ff6b6b;">Logout 🚪</a>
                </li>
            </ul>
        </nav>
        <button class="dashboard-float-btn" onclick="toggleSidebar()">
            <span class="material-symbols-outlined">menu_open</span>
        </button>

        <?php 
            $sid = (int)$_SESSION['student_id'];
            
            // FETCH STUDENT DATA + CLASS TEACHER
            $stu = $conn->query("
                SELECT 
                    s.*, 
                    c.name AS class_name,
                    t.name AS teacher_name
                FROM students s
                JOIN classes c ON c.id = s.class_id
                LEFT JOIN teachers t ON t.assigned_class_id = c.id
                WHERE s.id = $sid
            ")->fetch_assoc();
            
            $stu['father_name'] = 'Mr. ' . $stu['father_name'];
            $stu['mother_name'] = 'Ms. ' . $stu['mother_name'];
            $teacher_name = $stu['teacher_name'] ? htmlspecialchars($stu['teacher_name']) : 'Not Assigned';
        ?>

        <main class="content">
            
            <section id="profile">
                <h2>Student Profile</h2>
                <div class="profile-card" style="border-top: 6px solid var(--primary-color);">
                    <div style="text-align: center; margin-bottom: 20px;">
                        <img class="profile-pic" src="<?=htmlspecialchars($stu['profile_pic'])?>" alt="Student Profile Picture" style="width: 120px; height: 120px; border-radius: 50%; object-fit: cover; border: 4px solid #fff; box-shadow: 0 4px 10px rgba(0,0,0,0.1);">
                    </div>
                    <div class="profile-details">
                        <div class="detail-row">
                            <p><strong>Name:</strong> <?=htmlspecialchars($stu['name'])?></p>
                            <p><strong>Roll No:</strong> <?=htmlspecialchars($stu['roll_no'] ?? '-')?></p>
                        </div>
                        <div class="detail-row">
                            <p><strong>Class:</strong> <?=htmlspecialchars($stu['class_name'])?></p>
                            <p><strong>DOB:</strong> <?=htmlspecialchars($stu['dob'] ?? '-')?></p>
                        </div>
                        <p><strong>Student ID:</strong> <?=$stu['login_id']?></p>
                        <p><strong>Father's Name:</strong> <?=htmlspecialchars($stu['father_name'])?></p>
                        <p><strong>Mother's Name:</strong> <?=htmlspecialchars($stu['mother_name'])?></p>
                        <p><strong>Address:</strong> <?=htmlspecialchars($stu['address'])?></p>
                        <p><strong>Contact:</strong> <?=htmlspecialchars($stu['contact'])?></p>
                        <p><strong>Classteacher:</strong> <span style="color: green; font-weight: bold;"><?= $teacher_name ?></span></p>
                        <p><strong>Admission Year:</strong> <?=$stu['admission_year']?></p>
                    </div>
                </div>
            </section>

            <section id="attendance">
                <h2>Attendance Record</h2>
                <div class="table-container" style="border-top: 4px solid var(--accent-color);"> 
                    <table>
                        <tr>
                            <th>Month</th>
                            <th>Present</th>
                            <th>Absent</th>
                            <th>Percentage</th>
                        </tr>
                        <?php
                        // Academic Year Months Order
                        $academic_months = [
                            4 => 'April', 5 => 'May', 6 => 'June', 7 => 'July',
                            8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November',
                            12 => 'December', 1 => 'January', 2 => 'February', 3 => 'March'
                        ];
                        
                        $att = $conn->query("
                            SELECT month, days_present, days_absent 
                            FROM attendance
                            WHERE student_id=$sid AND year = YEAR(CURDATE())
                        ")->fetch_all(MYSQLI_ASSOC);
                        
                        $attMap = [];
                        foreach($att as $r) $attMap[(int)$r['month']] = $r;

                        foreach($academic_months as $month_num => $month_name):
                            $d = $attMap[$month_num] ?? null;
                            $hasData = $d && ($d['days_present'] + $d['days_absent']) > 0;
                        ?>
                        <tr>
                            <td><?= $month_name ?></td>
                            <td><?= $d ? $d['days_present'] : '--' ?></td>
                            <td><?= $d ? $d['days_absent'] : '--' ?></td>
                            <td style="font-weight: bold; color: <?= ($hasData && ($d['days_present']/($d['days_present']+$d['days_absent']) < 0.75)) ? 'red' : 'green' ?>;">
                                <?php if($hasData): ?>
                                    <?= round(100 * $d['days_present'] / ($d['days_present'] + $d['days_absent']), 1) ?>%
                                <?php else: ?>--<?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </section>

            <section id="results">
                <h2>Exam Results</h2>
                <?php
                $exams = $conn->query("
                    SELECT e.id, e.name 
                    FROM exams e
                    JOIN marks m ON m.exam_id=e.id
                    WHERE m.student_id=$sid
                    GROUP BY e.id
                ");
                
                if ($exams->num_rows > 0):
                    while ($ex = $exams->fetch_assoc()):
                ?>
                    <div style="background: #fff; padding: 20px; border-radius: 10px; margin-bottom: 20px; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; flex-wrap:wrap; gap:10px;">
                            <h3 style="margin:0; color: var(--primary-color);"><?=htmlspecialchars($ex['name'])?></h3>
                            
                            <?php
                            // Check Publish Status
                            $eid = $ex['id'];
                            $pub_q = $conn->query("SELECT is_published FROM exam_publish_status WHERE exam_id=$eid AND class_id={$stu['class_id']}");
                            $is_published = ($pub_q && $pub_q->fetch_assoc()['is_published'] == 1);
                            
                            if ($is_published):
                            ?>
                            <a href="generate_report.php?exam_id=<?= $eid ?>" class="hero-cta" style="font-size:0.8rem; padding:8px 15px; background-color:var(--accent-color); text-decoration:none; color:white; border-radius:4px;">
                                📄 Download Report Card
                            </a>
                            <?php endif; ?>
                        </div>
                        
                        <div class="table-container"> 
                            <table>
                                <tr><th>Subject</th><th>Total Marks</th><th>Obtained</th></tr>
                                <?php
                                $ms = $conn->query("
                                    SELECT 
                                    sub.name,
                                    e.max_marks AS total_marks,
                                    m.marks_obtained
                                    FROM marks m
                                    JOIN subjects sub ON sub.code = m.subject_code
                                    JOIN exams e ON e.id = m.exam_id
                                    WHERE m.student_id = $sid AND m.exam_id = {$ex['id']}
                                ");
                                while ($row = $ms->fetch_assoc()):
                                ?>
                                <tr>
                                    <td><?=htmlspecialchars($row['name'])?></td>
                                    <td><?=$row['total_marks']?></td>
                                    <td><?=$row['marks_obtained']?></td>
                                </tr>
                                <?php endwhile; ?>
                            </table>
                        </div>
                    </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <p style="text-align: center; color: #666;">No exam results available yet.</p>
                <?php endif; ?>
            </section>

            <section id="timetable">
                <h2>Class Timetable</h2>
                <?php
                $days = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
                $tt_res = $conn->query("
                    SELECT 
                        tt.day_of_week, 
                        tt.period_no, 
                        s.name AS subject_name 
                    FROM timetables tt
                    JOIN subjects s ON tt.subject_code = s.code
                    WHERE tt.class_id = {$stu['class_id']}
                ");

                $ttMap = [];
                if ($tt_res) {
                    while($r = $tt_res->fetch_assoc()) {
                        $ttMap[$r['day_of_week']][$r['period_no']] = $r['subject_name'];
                    }
                }
                ?>
                <div class="table-container" style="border-top: 4px solid var(--accent-color);"> 
                    <table>
                        <thead>
                            <tr>
                                <th>Day</th>
                                <?php for($p=1;$p<=8;$p++): ?><th>P<?=$p?></th><?php endfor; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($days as $day): ?>
                                <tr>
                                <td><?=$day?></td>
                                <?php for($p=1;$p<=8;$p++): ?>
                                    <td style="font-size: 0.85rem;">
                                    <?php if(!empty($ttMap[$day][$p])): ?>
                                        <?=htmlspecialchars($ttMap[$day][$p])?>
                                    <?php else: ?>-<?php endif; ?>
                                    </td>
                                <?php endfor; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>
    <?php endif; ?>

    <?php include 'footer.php'; ?>

    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('studentSidebar');
            sidebar.classList.toggle('show');
        }

        const hamburgerBtn = document.getElementById("hamburgerBtn"); 
        const headerMenuBtn = document.getElementById("headerHamburgerBtn");
        
        if(hamburgerBtn) hamburgerBtn.addEventListener("click", toggleSidebar);
        if(headerMenuBtn) headerMenuBtn.addEventListener("click", toggleSidebar);
    </script>

</body>
</html>