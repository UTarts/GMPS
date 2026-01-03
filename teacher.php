<?php
require_once __DIR__ . '/includes/db_connect.php';
$currentPage = basename($_SERVER['PHP_SELF']);

// ===================================================================
// 1. INITIALIZE ROLE VARIABLES
// ===================================================================
$teacher_class_id = null;
$teacher_class_name = null;
$is_classteacher = false;

// ===================================================================
// 2. LOGIN LOGIC (Updated to fetch Assigned Class)
// ===================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action']) && $_POST['action'] === 'login') {
  $uid  = mysqli_real_escape_string($conn, $_POST['userid']);
  $pass = $_POST['password'];

  // Join with classes table to get class name if assigned
  $sql = "SELECT t.*, c.name AS class_name 
          FROM teachers t
          LEFT JOIN classes c ON t.assigned_class_id = c.id
          WHERE t.login_id='$uid'";
  $res = mysqli_query($conn, $sql);

  if (mysqli_num_rows($res) === 1) {
      $row = mysqli_fetch_assoc($res);
      if (password_verify($pass, $row['password_hash'])) {
        $_SESSION['teacher']  = $row;
        $_SESSION['userType'] = 'teacher';
    
        // Store Class Info in Session
        if (!empty($row['assigned_class_id'])) {
            $_SESSION['teacher']['assigned_class_id'] = $row['assigned_class_id'];
            $_SESSION['teacher']['assigned_class_name'] = $row['class_name'];
        } else {
            // Ensure these are unset if switching from a classteacher to subject teacher
            unset($_SESSION['teacher']['assigned_class_id']);
            unset($_SESSION['teacher']['assigned_class_name']);
        }
    
        // Load Subjects
        $tid = (int)$row['id'];
        $subQ = mysqli_query($conn, "SELECT s.name FROM teacher_subjects ts JOIN subjects s ON ts.subject_code = s.code WHERE ts.teacher_id = $tid");
        $names = [];
        while ($srow = mysqli_fetch_assoc($subQ)) { $names[] = $srow['name']; }
        $_SESSION['teacher']['subjects'] = implode(', ', $names);
    
        // REMEMBER ME
        if (isset($_POST['remember'])) {
          setRememberMe($conn, $row['id'], 'teacher');
      }
      
      // REDIRECT LOGIC (Standard Website)
      header("Location: " . basename($_SERVER['PHP_SELF']));
      exit;
    }    
  }
  $loginError = "Invalid ID or password";
}

// ROBUST LOGOUT LOGIC
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
  // 1. Clear Session
  session_unset();
  session_destroy();

  // 2. Clear Cookie (Force expiry in past)
  if (isset($_COOKIE['remember_token'])) {
      setcookie('remember_token', '', time() - 3600, "/", "", false, true);
      unset($_COOKIE['remember_token']); // Clear from current execution
      
      // Clear DB Token
      $token_hash = hash('sha256', $_COOKIE['remember_token']);
      $stmt = $conn->prepare("DELETE FROM login_tokens WHERE token_hash = ?");
      $stmt->bind_param("s", $token_hash);
      $stmt->execute();
  }

  // 3. Redirect (Standard Website)
  header("Location: login.php");
  exit;
}

// ===================================================================
// 3. SETUP SESSION VARIABLES FOR CURRENT PAGE LOAD
// ===================================================================
if (!empty($_SESSION['teacher'])) {
    if (!empty($_SESSION['teacher']['assigned_class_id'])) {
        $teacher_class_id = (int)$_SESSION['teacher']['assigned_class_id'];
        $teacher_class_name = htmlspecialchars($_SESSION['teacher']['assigned_class_name']);
        $is_classteacher = true;
    }
}

// =================================================================
// 5. SAVE POST LOGIC
// =================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_post') {
    
  // 1. Define the Helper Function FIRST (Outside the loop)
  if (!function_exists('saveItems')) {
      function saveItems($conn, $post_id, $type, $data, $files_key) {
          if (!isset($data['heading'])) return;
          foreach ($data['heading'] as $k => $heading) {
              if (empty($heading)) continue;
              $content = $data['content'][$k] ?? '';
              
              // Insert Item
              $stmt = $conn->prepare("INSERT INTO post_items (post_id, item_type, heading, content) VALUES (?, ?, ?, ?)");
              $stmt->bind_param("isss", $post_id, $type, $heading, $content);
              $stmt->execute();
              $item_id = $stmt->insert_id;

              // Handle File Uploads for this item
              if (isset($_FILES[$files_key]['name'][$k])) {
                  // Handle single or multiple files
                  $file_names = $_FILES[$files_key]['name'][$k];
                  if (is_array($file_names)) {
                      $count = count($file_names);
                      for ($i = 0; $i < $count; $i++) {
                          processUpload($conn, $item_id, 
                              $_FILES[$files_key]['name'][$k][$i], 
                              $_FILES[$files_key]['tmp_name'][$k][$i], 
                              $_FILES[$files_key]['error'][$k][$i]
                          );
                      }
                  } else {
                      // Handle single file case just in case
                      processUpload($conn, $item_id, 
                          $_FILES[$files_key]['name'][$k], 
                          $_FILES[$files_key]['tmp_name'][$k], 
                          $_FILES[$files_key]['error'][$k]
                      );
                  }
              }

              // Handle Defaulters
              if ($type === 'defaulter' && isset($data['students'][$k])) {
                  foreach ($data['students'][$k] as $stu_id) {
                      $stu_id = (int)$stu_id;
                      $conn->query("INSERT INTO post_defaulters (item_id, student_id) VALUES ($item_id, $stu_id)");
                  }
              }
          }
      }
  }

  if (!function_exists('processUpload')) {
      function processUpload($conn, $item_id, $name, $tmp_name, $error) {
          if ($error === UPLOAD_ERR_OK) {
              $base_name = basename($name);
              // Save to GMPSimages folder as requested
              $target_dir = __DIR__ . '/GMPSimages/';
              $unique_name = time() . '_' . rand(100,999) . '_' . $base_name;
              $target_file = $target_dir . $unique_name;
              
              // Store relative path in DB
              $db_path = 'GMPSimages/' . $unique_name;

              if (move_uploaded_file($tmp_name, $target_file)) {
                  $conn->query("INSERT INTO post_attachments (item_id, file_path, original_name) VALUES ($item_id, '$db_path', '$base_name')");
              }
          }
      }
  }

  // 2. Determine Class(es)
  $target_class_ids = [];
  if ($is_classteacher) {
      $target_class_ids[] = $teacher_class_id;
  } elseif (!empty($_POST['target_class'])) {
      if ($_POST['target_class'] === 'all') {
          $c_res = $conn->query("SELECT id FROM classes");
          while($c = $c_res->fetch_assoc()) $target_class_ids[] = $c['id'];
      } else {
          $target_class_ids[] = (int)$_POST['target_class'];
      }
  }

  $post_date = $_POST['post_date'];
  $teacher_id = $_SESSION['teacher']['id'];

  // 3. Loop through each class and create a post
  foreach ($target_class_ids as $cid) {
      // Create Main Post Entry
      $stmt = $conn->prepare("INSERT INTO daily_posts (teacher_id, class_id, post_date) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE created_at=NOW()");
      $stmt->bind_param("iis", $teacher_id, $cid, $post_date);
      $stmt->execute();
      $post_id = $stmt->insert_id;
      
      if ($post_id == 0) {
          $res = $conn->query("SELECT post_id FROM daily_posts WHERE teacher_id=$teacher_id AND class_id=$cid AND post_date='$post_date'");
          $post_id = $res->fetch_assoc()['post_id'];
      }

      // Call the helper function defined above
      saveItems($conn, $post_id, 'classwork', $_POST['cw'] ?? [], 'cw_files');
      saveItems($conn, $post_id, 'homework', $_POST['hw'] ?? [], 'hw_files');
      
      // Defaulters only work if specific class selected
      if (count($target_class_ids) == 1) {
          saveItems($conn, $post_id, 'defaulter', $_POST['def'] ?? [], 'def_files');
      }
  }
  
  header("Location: teacher.php?saved_post=1#postCreator");
  exit;
}

// 6. DELETE LOGIC (Updated)

// A) Delete Entire Post (Single Class)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_post') {
  $post_id = (int)$_POST['post_id'];
  // Verify ownership
  $chk = $conn->query("SELECT post_id FROM daily_posts WHERE post_id=$post_id AND teacher_id={$_SESSION['teacher']['id']}");
  if ($chk->num_rows > 0) {
      // Attachments are deleted via cascade or manual cleanup if needed
      $conn->query("DELETE FROM daily_posts WHERE post_id=$post_id");
  }
  header("Location: teacher.php#recentPosts"); exit;
}

// B) Delete Single Item (e.g. just Math HW)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_item') {
  $item_id = (int)$_POST['item_id'];
  // Verify ownership via join
  $chk = $conn->query("SELECT pi.item_id FROM post_items pi JOIN daily_posts dp ON pi.post_id=dp.post_id WHERE pi.item_id=$item_id AND dp.teacher_id={$_SESSION['teacher']['id']}");
  if ($chk->num_rows > 0) {
      $conn->query("DELETE FROM post_items WHERE item_id=$item_id");
  }
  header("Location: teacher.php#recentPosts"); exit;
}

// C) Delete "General Update" Batch (All Classes)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_batch') {
  $batch_time = $conn->real_escape_string($_POST['batch_time']);
  $tid = $_SESSION['teacher']['id'];
  // Delete all posts by this teacher created at this exact second
  $conn->query("DELETE FROM daily_posts WHERE teacher_id=$tid AND created_at='$batch_time'");
  header("Location: teacher.php#recentPosts"); exit;
}

// ===================================================================
// 4. SAVE HANDLERS (Restricted to Classteachers)
// ===================================================================

// Save Attendance
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && $_POST['action']==='saveAttendance' && $is_classteacher){
  $month_name = mysqli_real_escape_string($conn,$_POST['attendanceMonth']);
  $month_num = date('m', strtotime($month_name));
  $year = date('Y');

  if(isset($_POST['present'])){
      foreach($_POST['present'] as $sid => $present){
        $present = (int)$present;
        $absent  = (int)$_POST['absent'][$sid];
        // Verify student belongs to this class before saving
        $check = mysqli_query($conn, "SELECT id FROM students WHERE id=$sid AND class_id=$teacher_class_id");
        if(mysqli_num_rows($check) > 0) {
            mysqli_query($conn,
              "INSERT INTO attendance (student_id, year, month, days_present, days_absent)
               VALUES ('$sid','$year','$month_num',$present,$absent)
               ON DUPLICATE KEY UPDATE days_present=$present, days_absent=$absent"
            );
        }
      }
  }
  header("Location: teacher.php?section=attendance&attendanceMonth=$month_name#attendanceManagement");
  exit;
}

// Save Marks
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'saveMarks' && $is_classteacher) {
  $exam_id = (int)$_POST['examType'];
  $subj_code = mysqli_real_escape_string($conn, $_POST['subject']);

  if (isset($_POST['obtained'])) {
      foreach ($_POST['obtained'] as $student_id => $obtained_marks) {
          $sid = (int)$student_id;
          $marks = is_numeric($obtained_marks) ? (int)$obtained_marks : null;

          // Verify student belongs to this class
          $check = mysqli_query($conn, "SELECT id FROM students WHERE id=$sid AND class_id=$teacher_class_id");
          if(mysqli_num_rows($check) > 0 && $marks !== null) {
              mysqli_query($conn,
                  "INSERT INTO marks (student_id, exam_id, subject_code, marks_obtained)
                   VALUES ($sid, $exam_id, '$subj_code', $marks)
                   ON DUPLICATE KEY UPDATE marks_obtained = $marks"
              );
          }
      }
  }
  header("Location: teacher.php?section=marks&examType=$exam_id&subject=".urlencode($subj_code)."#marksEntry");
  exit;
}

// Save Timetable
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'saveTimetable' && $is_classteacher) {
  foreach ($_POST['period'] as $day => $periods) {
      $escaped_day = mysqli_real_escape_string($conn, $day);
      foreach ($periods as $pnum => $subj_code) {
          $escaped_pnum = (int)$pnum;
          $escaped_subj_code = mysqli_real_escape_string($conn, $subj_code);

          if (empty($escaped_subj_code)) {
              mysqli_query($conn, "DELETE FROM timetables WHERE class_id = $teacher_class_id AND day_of_week = '$escaped_day' AND period_no = $escaped_pnum");
          } else {
              mysqli_query($conn, "INSERT INTO timetables (class_id, day_of_week, period_no, subject_code) VALUES ($teacher_class_id, '$escaped_day', $escaped_pnum, '$escaped_subj_code') ON DUPLICATE KEY UPDATE subject_code = '$escaped_subj_code'");
          }
      }
  }
  header("Location: teacher.php?section=timetable&saved=1#timetableEditing");
  exit;
}

// 7. PUBLISH REPORT CARD LOGIC
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'publish_result' && $is_classteacher) {
  $exam_id = (int)$_POST['exam_id'];
  $status = (int)$_POST['publish_status']; // 1 = Publish, 0 = Unpublish
  
  if ($status === 1) {
      // Publish
      $stmt = $conn->prepare("INSERT INTO exam_publish_status (exam_id, class_id, is_published, published_at) VALUES (?, ?, 1, NOW()) ON DUPLICATE KEY UPDATE is_published = 1, published_at = NOW()");
  } else {
      // Unpublish
      $stmt = $conn->prepare("UPDATE exam_publish_status SET is_published = 0 WHERE exam_id = ? AND class_id = ?");
  }
  
  if ($status === 1) {
      $stmt->bind_param("ii", $exam_id, $teacher_class_id);
  } else {
      $stmt->bind_param("ii", $exam_id, $teacher_class_id);
  }
  
  $stmt->execute();
  header("Location: teacher.php#reportCards");
  exit;
}


// Save Profile Edits (Single Student)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_profile_edits']) && !empty($_GET['viewStudent']) && $is_classteacher) {
  $sid = (int)$_GET['viewStudent'];
  $check = mysqli_query($conn, "SELECT id FROM students WHERE id=$sid AND class_id=$teacher_class_id");
  
  if(mysqli_num_rows($check) > 0) {
      // Attendance
      if (isset($_POST['attendance'])) {
          foreach ($_POST['attendance'] as $month => $vals) {
              $present = is_numeric($vals['present']) ? (int)$vals['present'] : 0;
              $absent  = is_numeric($vals['absent']) ? (int)$vals['absent'] : 0;
              $month_num = (int)$month; $year = date('Y');
              if ($present == 0 && $absent == 0) {
                   $conn->query("DELETE FROM attendance WHERE student_id=$sid AND year=$year AND month=$month_num");
              } else {
                   $conn->query("INSERT INTO attendance (student_id, year, month, days_present, days_absent) VALUES ($sid, $year, $month_num, $present, $absent) ON DUPLICATE KEY UPDATE days_present=$present, days_absent=$absent");
              }
          }
      }
      // Marks
      if (isset($_POST['marks'])) {
          foreach ($_POST['marks'] as $exam_id => $subjects) {
              foreach ($subjects as $subject_code => $obtained) {
                  if ($subject_code === 'new_subject_code' || $subject_code === 'new_subject_marks') continue;
                  $exam_id_int = (int)$exam_id;
                  $obtained_marks = is_numeric($obtained) ? (int)$obtained : null;
                  if ($obtained_marks !== null) {
                      $stmt = $conn->prepare("INSERT INTO marks (student_id, exam_id, subject_code, marks_obtained) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE marks_obtained = ?");
                      $stmt->bind_param("iisii", $sid, $exam_id_int, $subject_code, $obtained_marks, $obtained_marks);
                      $stmt->execute();
                  } else {
                      $stmt = $conn->prepare("DELETE FROM marks WHERE student_id=? AND exam_id=? AND subject_code=?");
                      $stmt->bind_param("iis", $sid, $exam_id_int, $subject_code);
                      $stmt->execute();
                  }
              }
              // Add new marks
              if (isset($subjects['new_subject_code'])) {
                  foreach ($subjects['new_subject_code'] as $key => $new_code) {
                      $new_mark = $subjects['new_subject_marks'][$key];
                      if (!empty($new_code) && is_numeric($new_mark)) {
                          $exam_id_int = (int)$exam_id; $obtained_marks = (int)$new_mark;
                          $stmt = $conn->prepare("INSERT INTO marks (student_id, exam_id, subject_code, marks_obtained) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE marks_obtained = ?");
                          $stmt->bind_param("iisii", $sid, $exam_id_int, $new_code, $obtained_marks, $obtained_marks);
                          $stmt->execute();
                      }
                  }
              }
          }
      }
  }
  header("Location: teacher.php?viewStudent=$sid&saved=1#selectedStudentProfile");
  exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Teacher Dashboard - Govind Madhav Public School</title>
    <?php include 'includes/meta.php'; ?>
  <style> .whatsapp-sticky-button { display: none !important; } </style>
</head>
<body>

<?php include 'includes/header.php'; ?>  

  <?php if(empty($_SESSION['teacher'])): ?>
  <section class="login-container">
    <div class="login-box">
      <h2>Teacher Login</h2>
      <form method="post"><input type="hidden" name="action" value="login">
        <div class="input-group"><label>User ID</label><input type="text" name="userid" required></div>
        <div class="input-group"><label>Password</label><input type="password" name="password" required></div>
        <div style="margin:10px 0;"><label><input type="checkbox" name="remember"> Keep me logged in</label></div>
        <button type="submit">Login</button>
      </form>
    </div>
  </section>
  <?php else: ?>

  <div class="dashboard" id="profileSection">
    <nav class="sidebar profile-sidebar" id="teacherSidebar"> <button class="close-sidebar-btn" onclick="toggleSidebar()">×</button>

      <ul>
        <li><a href="#profile">Profile</a></li>
        <li><a href="#postCreator">📝 Create Updates</a></li>
        <?php if ($is_classteacher): ?>
            <li><a href="#studentProfileManagement">My Students</a></li>
            <li><a href="#attendanceManagement">Attendance</a></li>
            <li><a href="#marksEntry">Marks</a></li>
            <li><a href="#timetableEditing">Timetable</a></li>
            <li><a href="#reportCards">🖨️ Report Cards</a></li>
        <?php endif; ?>
        <li>
            <a href="?action=logout" class="button" style="color:#ff6b6b;">
                Logout 🚪
            </a>
        </li>
      </ul>
    </nav>
    <button class="dashboard-float-btn" onclick="toggleSidebar()">
        <span class="material-symbols-outlined">menu_open</span>
    </button>

    <div class="content">
    <section id="profile">
        <h2>Teacher Profile</h2>
        <div class="profile-card">
          <img class="profile-pic" src="<?= htmlspecialchars($_SESSION['teacher']['profile_pic']) ?>" loading="lazy">
          <div class="profile-details">
            <h2><?= htmlspecialchars($_SESSION['teacher']['name']) ?></h2>
            <?php if ($is_classteacher): ?>
                <p style="color:#28a745; font-weight:bold;">Classteacher of: <?= $teacher_class_name ?></p>
            <?php else: ?>
                <p style="color:#007bff; font-weight:bold;">Subject Teacher</p>
            <?php endif; ?>
            <p><strong>User ID:</strong> <?= htmlspecialchars($_SESSION['teacher']['login_id']) ?></p>
            <p><strong>Subjects:</strong> <?= htmlspecialchars($_SESSION['teacher']['subjects']) ?></p>
            <p><strong>Contact:</strong> <?= htmlspecialchars($_SESSION['teacher']['contact']) ?></p>
          </div>
        </div>
      </section>

      <section id="postCreator">
        
        <?php if ($is_classteacher): ?>
        <div style="margin-bottom: 40px;">
            <h2>Daily Class Updates (<?= $teacher_class_name ?>)</h2>
            <?php if(isset($_GET['saved_post'])) echo "<p style='color:green; font-weight:bold;'>Updates Posted Successfully!</p>"; ?>
            
            <div class="post-creator-section" style="border-top: 4px solid var(--primary-color);">
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="create_post">
                    <input type="hidden" name="post_type" value="daily_update"> <div style="margin-bottom:20px;">
                        <label><strong>Date:</strong></label>
                        <input type="date" name="post_date" value="<?= date('Y-m-d') ?>" required style="width:100%; padding:8px;">
                    </div>

                    <div class="post-form-group">
                        <h4>Classwork <button type="button" class="add-row-btn" onclick="addRow('cw_container', 'cw', 'cw_files')">+</button></h4>
                        <div id="cw_container"></div>
                    </div>

                    <div class="post-form-group">
                        <h4>Homework <button type="button" class="add-row-btn" onclick="addRow('hw_container', 'hw', 'hw_files')">+</button></h4>
                        <div id="hw_container"></div>
                    </div>

                    <div class="post-form-group" id="defaultersGroup">
                        <h4>Defaulters List <button type="button" class="add-row-btn" onclick="addDefaulterRow()">+</button></h4>
                        <div id="def_container"></div>
                    </div>

                    <button type="submit" style="width:100%; padding:15px; background:var(--primary-color); color:white; border:none; border-radius:8px; font-size:1.1rem;">POST DAILY UPDATES</button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <div>
            <h2>General Subject Update</h2>
            <div class="post-creator-section" style="border-top: 4px solid var(--accent-color);">
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="create_post">
                    <input type="hidden" name="post_type" value="general_update"> <div style="display:flex; gap:20px; margin-bottom:20px;">
                        <div style="flex:1;">
                            <label><strong>Date:</strong></label>
                            <input type="date" name="post_date" value="<?= date('Y-m-d') ?>" required style="width:100%; padding:8px;">
                        </div>
                        
                        <div style="flex:1;">
                            <label><strong>Select Class:</strong></label>
                            <select name="target_class" required style="width:100%; padding:8px;">
                                <option value="" disabled selected>Select Class</option>
                                <?php
                                // Re-use class query if needed, or fetch again
                                $cResGen = $conn->query("SELECT id, name FROM classes ORDER BY id");
                                while($c=$cResGen->fetch_assoc()) echo "<option value='{$c['id']}'>{$c['name']}</option>";
                                ?>
                                <option value="all">All Classes</option>
                            </select>
                        </div>
                    </div>

                    <div class="post-form-group">
                        <h4>Update Details <button type="button" class="add-row-btn" onclick="addRow('gen_container', 'cw', 'cw_files')">+</button></h4>
                        <div id="gen_container">
                            <div class="repeater-row">
                                <input type="text" name="cw[heading][]" placeholder="Subject / Title" required>
                                <textarea name="cw[content][]" placeholder="Message / Update..." rows="3"></textarea>
                                <input type="file" name="cw_files[0][]" multiple>
                            </div>
                        </div>
                    </div>

                    <button type="submit" style="width:100%; padding:15px; background:var(--accent-color); color:white; border:none; border-radius:8px; font-size:1.1rem;">POST GENERAL UPDATE</button>
                </form>
            </div>
        </div>

      </section>

      <section id="recentPosts">
        <h2>Recent Posts</h2>
        <?php
        $tid = $_SESSION['teacher']['id'];
        
        // Using mysqli_query for maximum compatibility
        $query = "SELECT dp.post_id, dp.class_id, dp.post_date, dp.created_at, c.name as class_name 
                FROM daily_posts dp 
                LEFT JOIN classes c ON dp.class_id = c.id 
                WHERE dp.teacher_id = $tid 
                ORDER BY dp.created_at DESC 
                LIMIT 50";
        
        $raw_posts = mysqli_query($conn, $query);

        if ($raw_posts && mysqli_num_rows($raw_posts) > 0) {
            $grouped_posts = [];
            while($row = mysqli_fetch_assoc($raw_posts)) {
                $key = $row['created_at'];
                if (!isset($grouped_posts[$key])) {
                    $grouped_posts[$key] = [
                        'date' => $row['post_date'],
                        'created_at' => $row['created_at'],
                        'classes' => [],
                        'post_ids' => []
                    ];
                }
                $grouped_posts[$key]['classes'][] = ($row['class_id'] == 0) ? 'All Classes' : $row['class_name'];
                $grouped_posts[$key]['post_ids'][] = $row['post_id'];
            }

            foreach ($grouped_posts as $timestamp => $batch):
                $representative_pid = $batch['post_ids'][0];
                $items_q = mysqli_query($conn, "SELECT item_id, item_type, heading FROM post_items WHERE post_id = $representative_pid");
                
                $is_batch = (count($batch['post_ids']) > 1);
                $target_display = $is_batch ? "Multiple Classes (" . count($batch['classes']) . ")" : $batch['classes'][0];
        ?>
            <div style="background:#fff; padding:15px; margin-bottom:15px; border-radius:8px; border:1px solid #ddd;">
                <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #eee; padding-bottom:10px; margin-bottom:10px;">
                    <div style="font-size:0.9rem; color:#666;">
                        <strong><?= date('d M Y', strtotime($batch['date'])) ?></strong> • 
                        <span style="color:var(--primary-color);"><?= htmlspecialchars($target_display) ?></span>
                    </div>
                    <form method="post" onsubmit="return confirm('Delete this entire post?');" style="margin:0;">
                        <?php if($is_batch): ?>
                            <input type="hidden" name="action" value="delete_batch">
                            <input type="hidden" name="batch_time" value="<?= $batch['created_at'] ?>">
                        <?php else: ?>
                            <input type="hidden" name="action" value="delete_post">
                            <input type="hidden" name="post_id" value="<?= $batch['post_ids'][0] ?>">
                        <?php endif; ?>
                        <button type="submit" style="color:red; background:none; border:none; cursor:pointer; font-size:1.2rem;">&times;</button>
                    </form>
                </div>

                <div>
                    <?php while($it = mysqli_fetch_assoc($items_q)): ?>
                        <div style="display:flex; justify-content:space-between; align-items:center; padding:5px 0; border-bottom:1px dashed #f0f0f0;">
                            <div style="font-size:0.95rem;">
                                <span style="font-weight:bold; color:#555; text-transform:uppercase; font-size:0.8rem;"><?= htmlspecialchars($it['item_type']) ?>:</span> 
                                <?= htmlspecialchars($it['heading']) ?>
                            </div>
                            <?php if(!$is_batch): ?>
                            <form method="post" onsubmit="return confirm('Remove this item only?');" style="margin:0;">
                                <input type="hidden" name="action" value="delete_item">
                                <input type="hidden" name="item_id" value="<?= $it['item_id'] ?>">
                                <button type="submit" style="background:none; border:none; color:#ccc; cursor:pointer; font-size:1rem;">&times;</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    <?php endwhile; ?>
                </div>
            </div>
        <?php 
            endforeach; 
        } else {
            echo '<p style="text-align:center; padding:20px; color:#999;">No recent posts found.</p>';
        }
        ?>
    </section>

      <?php if ($is_classteacher): ?>

          <section id="studentProfileManagement">
            <h2>Students of <?= $teacher_class_name ?></h2>
            <?php 
              $stu = mysqli_query($conn, "SELECT id, login_id, name, roll_no FROM students WHERE class_id=$teacher_class_id AND status = 'active' ORDER BY roll_no ASC");
            ?>
            <div class="table-container">
              <table>
              <thead><tr><th>Roll No</th><th>Name</th><th>Student ID</th><th>Action</th></tr></thead>
                <tbody>
                  <?php while($s = mysqli_fetch_assoc($stu)): ?>
                    <tr>
                        <td><?= htmlspecialchars($s['roll_no'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($s['name']) ?></td>
                        <td><?= htmlspecialchars($s['login_id']) ?></td>
                      <td>
                        <a href="teacher.php?viewStudent=<?= urlencode($s['id']) ?>#selectedStudentProfile">
                          <button>View</button>
                        </a>
                      </td>
                    </tr>
                  <?php endwhile; ?>
                </tbody>
              </table>
            </div>
          </section>

          <section id="selectedStudentProfile">
            <h2>Selected Student Profile</h2>
            <div id="studentProfileDisplay">
            <?php
              if(!empty($_GET['viewStudent'])){
                $sid = (int)$_GET['viewStudent'];
                // Ensure student is in this class
                $r_res = mysqli_query($conn, "SELECT s.*, c.name AS class_name FROM students s JOIN classes c ON s.class_id = c.id WHERE s.id = '$sid' AND s.class_id = $teacher_class_id");
                if($r_res && $r = $r_res->fetch_assoc()){
                  echo "<div class=\"profile-card\">
                          <img class=\"profile-pic\" src=\"" . htmlspecialchars($r['profile_pic']) . "\" loading=\"lazy\">
                          <div class=\"profile-details\">
                            <p><strong>Name:</strong> {$r['name']}</p>
                            <p><strong>Roll No:</strong> {$r['roll_no']} | <strong>ID:</strong> {$r['login_id']}</p>
                            <p><strong>DOB:</strong> {$r['dob']}</p>
                            <p><strong>Parents:</strong> {$r['father_name']} / {$r['mother_name']}</p>
                            <p><strong>Contact:</strong> {$r['contact']}</p>
                          </div>
                        </div>";
                  
                  // Edit Form
                  echo "<div class='table-container'><form method='post' action='teacher.php?viewStudent=$sid&saved=1'>"; 
                  echo "<input type='hidden' name='save_profile_edits' value='1'>";
                  
                  // Attendance Table
                  echo "<h3>Attendance</h3><table><thead><tr><th>Month</th><th>Present</th><th>Absent</th><th>%</th></tr></thead><tbody>";
                  $academic_months = [4=>'April', 5=>'May', 6=>'June', 7=>'July', 8=>'August', 9=>'September', 10=>'October', 11=>'November', 12=>'December', 1=>'January', 2=>'February', 3=>'March'];
                  $att_res = mysqli_query($conn, "SELECT month, days_present, days_absent FROM attendance WHERE student_id='$sid' AND year=YEAR(CURDATE())");
                  $attendance_map = [];
                  while ($row = mysqli_fetch_assoc($att_res)) $attendance_map[(int)$row['month']] = $row;
                  
                  foreach ($academic_months as $month_num => $month_name) {
                      $d = $attendance_map[$month_num] ?? ['days_present'=>'', 'days_absent'=>''];
                      $p = is_numeric($d['days_present']) ? $d['days_present'] : '';
                      $a = is_numeric($d['days_absent']) ? $d['days_absent'] : '';
                      $pct = ($p!=='' && $a!=='') ? round(($p/($p+$a))*100,1).'%' : '--';
                      echo "<tr><td>$month_name</td>
                            <td><input type='number' name='attendance[$month_num][present]' value='$p' min='0' style='width:60px'></td>
                            <td><input type='number' name='attendance[$month_num][absent]' value='$a' min='0' style='width:60px'></td>
                            <td>$pct</td></tr>";
                  }
                  echo "</tbody></table>";

                  // Marks Table
                  echo "<h3>Exam Results</h3>";
                  $subs = $conn->query("SELECT code, name FROM subjects ORDER BY name");
                  $all_subjects = []; while($s=$subs->fetch_assoc()){ $all_subjects[]=$s; }
                  $exams = $conn->query("SELECT id, name, max_marks FROM exams ORDER BY id");
                  
                  while ($ex = $exams->fetch_assoc()):
                      $eid = (int)$ex['id'];
                      echo "<h4>".htmlspecialchars($ex['name'])." (Max: {$ex['max_marks']})</h4>";
                      echo "<table><thead><tr><th>Subject</th><th>Marks</th></tr></thead><tbody>";
                      $marks = $conn->query("SELECT s.name, s.code, m.marks_obtained FROM marks m JOIN subjects s ON m.subject_code=s.code WHERE m.student_id=$sid AND m.exam_id=$eid");
                      while ($mk = $marks->fetch_assoc()){
                          echo "<tr><td>".htmlspecialchars($mk['name'])."</td>
                                <td><input type='number' name='marks[$eid][{$mk['code']}]' value='{$mk['marks_obtained']}' min='0' max='{$ex['max_marks']}' style='width:80px'></td></tr>";
                      }
                      // Add new subject row
                      echo "<tr><td><select name='marks[$eid][new_subject_code][]'><option value='' disabled selected>Add Subject</option>";
                      foreach($all_subjects as $sb) echo "<option value='{$sb['code']}'>{$sb['name']}</option>";
                      echo "</select></td><td><input type='number' name='marks[$eid][new_subject_marks][]' placeholder='Marks'></td></tr>";
                      echo "</tbody></table>";
                  endwhile;

                  echo "<button type='submit' style='margin-top:20px;'>Save All Changes</button></form></div>";
                } else {
                  echo "<p>Student not found in your class.</p>";
                }
              } else {
                echo "<p>Select a student from the list above.</p>";
              }
            ?>
            </div>
          </section>

          <section id="attendanceManagement">
            <h2>Attendance: <?= $teacher_class_name ?></h2>
            <form method="get" action="teacher.php#attendanceManagement">
              <input type="hidden" name="section" value="attendance">
              <label>Month:</label>
              <select name="attendanceMonth" required>
                <?php
                $months = [ 'April','May','June','July','August','September','October','November','December','January','February','March' ];
                $selM = $_GET['attendanceMonth'] ?? date('F');
                foreach($months as $m) echo "<option value='$m' ".($selM==$m?'selected':'').">$m</option>";
                ?>
              </select>
              <button type="submit">Load</button>
            </form>

            <?php if(isset($_GET['section']) && $_GET['section']==='attendance'): 
              $m_name = mysqli_real_escape_string($conn,$_GET['attendanceMonth']);
              $m_num = date('m', strtotime($m_name));
              $y = date('Y');
              $attQ = mysqli_query($conn, "SELECT s.id, s.name, IFNULL(a.days_present,0) as p, IFNULL(a.days_absent,0) as a FROM students s LEFT JOIN attendance a ON a.student_id=s.id AND a.month='$m_num' AND a.year='$y' WHERE s.class_id=$teacher_class_id");
            ?>
            <form method="post">
              <input type="hidden" name="action" value="saveAttendance">
              <input type="hidden" name="attendanceMonth" value="<?= htmlspecialchars($m_name) ?>">
              <div class="table-container">
                <table>
                  <thead><tr><th>Name</th><th>Present</th><th>Absent</th><th>%</th></tr></thead>
                  <tbody>
                    <?php while($r=mysqli_fetch_assoc($attQ)): $pct=($r['p']+$r['a'])?round($r['p']/($r['p']+$r['a'])*100).'%':'0%'; ?>
                    <tr>
                      <td><?= htmlspecialchars($r['name']) ?></td>
                      <td><input type="number" name="present[<?=$r['id']?>]" value="<?=$r['p']?>" style="width:60px"></td>
                      <td><input type="number" name="absent[<?=$r['id']?>]" value="<?=$r['a']?>" style="width:60px"></td>
                      <td><?=$pct?></td>
                    </tr>
                    <?php endwhile; ?>
                  </tbody>
                </table>
              </div>
              <button type="submit">Save Bulk Attendance</button>
            </form>
            <?php endif; ?>
          </section>

          <section id="marksEntry">
            <h2>Marks Entry: <?= $teacher_class_name ?></h2>
            <form method="get" action="teacher.php#marksEntry">
              <input type="hidden" name="section" value="marks">
              <label>Exam:</label>
              <select name="examType" required>
                  <?php 
                  $selE = $_GET['examType'] ?? null;
                  $exs = $conn->query("SELECT id, name FROM exams ORDER BY id");
                  while($e=$exs->fetch_assoc()) echo "<option value='{$e['id']}' ".($selE==$e['id']?'selected':'').">{$e['name']}</option>"; 
                  ?>
              </select>
              <label>Subject:</label>
              <select name="subject" required>
                  <?php 
                  $selS = $_GET['subject'] ?? null;
                  $sbs = $conn->query("SELECT code, name FROM subjects ORDER BY name");
                  while($s=$sbs->fetch_assoc()) echo "<option value='{$s['code']}' ".($selS==$s['code']?'selected':'').">{$s['name']}</option>"; 
                  ?>
              </select>
              <button type="submit">Load</button>
            </form>

            <?php if(isset($_GET['section']) && $_GET['section']==='marks' && isset($_GET['examType'])):
              $eid = (int)$_GET['examType']; $sc = mysqli_real_escape_string($conn, $_GET['subject']);
              $ed = $conn->query("SELECT max_marks FROM exams WHERE id=$eid")->fetch_assoc();
              $max = $ed['max_marks'];
              $mq = $conn->query("SELECT s.id, s.name, IFNULL(m.marks_obtained,'') as obt FROM students s LEFT JOIN marks m ON m.student_id=s.id AND m.exam_id=$eid AND m.subject_code='$sc' WHERE s.class_id=$teacher_class_id");
            ?>
            <form method="post">
              <input type="hidden" name="action" value="saveMarks">
              <input type="hidden" name="examType" value="<?=$eid?>">
              <input type="hidden" name="subject" value="<?=htmlspecialchars($sc)?>">
              <div class="table-container">
                <table>
                  <thead><tr><th>Name</th><th>Marks (Max: <?=$max?>)</th></tr></thead>
                  <tbody>
                    <?php while($r=$mq->fetch_assoc()): ?>
                    <tr>
                      <td><?= htmlspecialchars($r['name']) ?></td>
                      <td><input type="number" name="obtained[<?=$r['id']?>]" value="<?=$r['obt']?>" min="0" max="<?=$max?>"></td>
                    </tr>
                    <?php endwhile; ?>
                  </tbody>
                </table>
              </div>
              <button type="submit">Save Marks</button>
            </form>
            <?php endif; ?>
          </section>

          <section id="timetableEditing">
            <h2>Timetable: <?= $teacher_class_name ?></h2>
            <?php
              $days = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
              $sbs = $conn->query("SELECT code, name FROM subjects ORDER BY name");
              $sl=[]; while($s=$sbs->fetch_assoc()) $sl[]=$s;
              
              $tq = $conn->query("SELECT day_of_week, period_no, subject_code FROM timetables WHERE class_id=$teacher_class_id");
              $tt=[]; while($row=$tq->fetch_assoc()) $tt[$row['day_of_week']][$row['period_no']] = $row['subject_code'];
            ?>
            <form method="post">
              <input type="hidden" name="action" value="saveTimetable">
              <div class="table-container">
                <table>
                  <thead><tr><th>Day</th><?php for($p=1;$p<=8;$p++) echo "<th>P$p</th>"; ?></tr></thead>
                  <tbody>
                    <?php foreach($days as $d): ?>
                    <tr>
                      <td><?=$d?></td>
                      <?php for($p=1;$p<=8;$p++): $cur=$tt[$d][$p]??''; ?>
                      <td>
                        <select name="period[<?=$d?>][<?=$p?>]">
                          <option value="">--</option>
                          <?php foreach($sl as $s) echo "<option value='{$s['code']}' ".($s['code']==$cur?'selected':'').">{$s['name']}</option>"; ?>
                        </select>
                      </td>
                      <?php endfor; ?>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <button type="submit">Save Timetable</button>
            </form>
          </section>

          <section id="reportCards">
            <h2>Generate Report Cards</h2>
            <div class="post-creator-section">
                <p>Select an exam to publish results. Once published, students can download their report cards instantly.</p>
                
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Exam Name</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $exams_res = $conn->query("SELECT id, name FROM exams ORDER BY id");
                            while($ex = $exams_res->fetch_assoc()):
                                $eid = $ex['id'];
                                // Check current status
                                $pub_check = $conn->query("SELECT is_published, published_at FROM exam_publish_status WHERE exam_id = $eid AND class_id = $teacher_class_id");
                                $pub_data = $pub_check->fetch_assoc();
                                $is_pub = ($pub_data && $pub_data['is_published'] == 1);
                            ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($ex['name']) ?></strong>
                                    <?php if($is_pub): ?>
                                        <div style="font-size:0.8rem; color:green;">Published on: <?= date('d M Y', strtotime($pub_data['published_at'])) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if($is_pub): ?>
                                        <span style="background:#d4edda; color:#155724; padding:5px 10px; border-radius:15px; font-size:0.85rem; font-weight:600;">Live</span>
                                    <?php else: ?>
                                        <span style="background:#f8d7da; color:#721c24; padding:5px 10px; border-radius:15px; font-size:0.85rem; font-weight:600;">Hidden</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="post" style="margin:0;">
                                        <input type="hidden" name="action" value="publish_result">
                                        <input type="hidden" name="exam_id" value="<?= $eid ?>">
                                        <?php if($is_pub): ?>
                                            <input type="hidden" name="publish_status" value="0">
                                            <button type="submit" style="background:#dc3545; color:white; border:none; padding:8px 15px; border-radius:6px; cursor:pointer;">Unpublish</button>
                                        <?php else: ?>
                                            <input type="hidden" name="publish_status" value="1">
                                            <button type="submit" style="background:#28a745; color:white; border:none; padding:8px 15px; border-radius:6px; cursor:pointer;">Publish Result</button>
                                        <?php endif; ?>
                                    </form>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
          </section>

      
      <?php endif; ?>

    </div>
  </div>
  <?php endif; ?>
  
  <script>
    window.onload = function() {
        // Initialize Classteacher forms if they exist
        if (document.getElementById('cw_container')) {
            addRow('cw_container', 'cw', 'cw_files');
            addRow('hw_container', 'hw', 'hw_files');
            <?php if ($is_classteacher): ?>
                loadStudents(<?= $teacher_class_id ?>);
                addDefaulterRow();
            <?php endif; ?>
        }
    };

    let studentList = []; 

    function loadStudents(classId) {
        if (!document.getElementById('def_container')) return;

        if (classId === 'all') {
            document.getElementById('defaultersGroup').style.display = 'none';
            return;
        }
        document.getElementById('defaultersGroup').style.display = 'block';
        
        fetch('fetch_students.php?class_id=' + classId)
            .then(response => response.json())
            .then(data => {
                studentList = data;
                document.getElementById('def_container').innerHTML = '';
                addDefaulterRow();
            });
    }

    function addRow(containerId, prefix, filePrefix) {
        const container = document.getElementById(containerId);
        const index = container.children.length;
        
        const div = document.createElement('div');
        div.className = 'repeater-row';
        div.innerHTML = `
            <input type="text" name="${prefix}[heading][]" placeholder="Subject / Heading (e.g. Math Ch-1)">
            <textarea name="${prefix}[content][]" placeholder="Details..." rows="2"></textarea>
            <input type="file" name="${filePrefix}[${index}][]" multiple>
            <button type="button" class="remove-row-btn" onclick="this.parentElement.remove()">X</button>
        `;
        container.appendChild(div);
    }

    function addDefaulterRow() {
        if (!document.getElementById('def_container')) return;
        const container = document.getElementById('def_container');
        const index = container.children.length;
        
        let checkboxes = '';
        studentList.forEach(s => {
            checkboxes += `
                <label class="checkbox-item">
                    <input type="checkbox" name="def[students][${index}][]" value="${s.id}"> ${s.name}
                </label>
            `;
        });

        const div = document.createElement('div');
        div.className = 'repeater-row';
        div.innerHTML = `
            <input type="text" name="def[heading][]" placeholder="Subject / Reason (e.g. Math Copy)">
            <div class="student-checklist">${checkboxes}</div>
            <button type="button" class="remove-row-btn" onclick="this.parentElement.remove()">X</button>
        `;
        container.appendChild(div);
    }
    
    // Sidebar Toggle Function
    function toggleSidebar() {
        // Target the SPECIFIC ID for the teacher sidebar
        const sidebar = document.getElementById('teacherSidebar');
        sidebar.classList.toggle('show');
    }

    // Attach to Hamburger
    const hamburgerBtn = document.getElementById("hamburgerBtn"); // Mobile floating btn
    const headerMenuBtn = document.getElementById("headerHamburgerBtn"); // App header btn
    
    if(hamburgerBtn) hamburgerBtn.addEventListener("click", toggleSidebar);
    if(headerMenuBtn) headerMenuBtn.addEventListener("click", toggleSidebar);
    // --- FIX FOR INVISIBLE IMAGES ---
    document.addEventListener("DOMContentLoaded", function() {
        const lazyImages = document.querySelectorAll('img[loading="lazy"]');
        lazyImages.forEach(img => {
            if (img.complete) {
                img.classList.add('loaded');
            } else {
                img.onload = () => img.classList.add('loaded');
            }
        });
    });
  </script>

<?php include 'footer.php'; ?>
</body>
</html>