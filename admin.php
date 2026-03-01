<?php
// 1. PERFORMANCE: GZIP Compression for faster loading in rural areas
if (substr_count($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip')) ob_start("ob_gzhandler"); else ob_start();

require_once 'includes/db_connect.php';
// If Accountant tries to access Main Admin Panel, kick them to Accounts
if (isset($_SESSION['adminUser']) && $_SESSION['adminUser']['level'] == 5) {
    header("Location: accounts.php");
    exit;
}
$currentPage = basename($_SERVER['PHP_SELF']);


// --- HELPER: Alert Session Setter ---
function setAlert($type, $msg) {
    $_SESSION['swal_type'] = $type; // success, error, warning, info
    $_SESSION['swal_msg'] = $msg;
}

// handle login POST
$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action']) && isset($_POST['adminId'], $_POST['adminPassword'])) {
    require_once 'includes/db_connect.php';
    $stmt = $conn->prepare("SELECT id, login_id, name, contact, level, password_hash, profile_pic FROM admins WHERE login_id = ?");
    $stmt->bind_param("s", $_POST['adminId']);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
      if (password_verify($_POST['adminPassword'], $row['password_hash'])) {
        $_SESSION['userType']  = 'admin';
        $_SESSION['adminUser'] = [
          'id'        => $row['id'],
          'loginId'   => $row['login_id'],
          'name'      => $row['name'],
          'contact'   => $row['contact'],
          'level'     => $row['level'],
          'profilePic'=> $row['profile_pic']
        ];
        // REMEMBER ME
        if (isset($_POST['remember'])) {
            setRememberMe($conn, $row['id'], 'admin'); 
          }
        
          // REDIRECT LOGIC
          if ($row['level'] == 5) {
              // Accountants go to Accounts Dashboard
              header("Location: accounts.php");
          } else {
              // Everyone else stays on Admin Dashboard
              header("Location: " . basename($_SERVER['PHP_SELF']));
          }
          exit;
      }
    }
    $loginError = "Invalid credentials. Please try again.";
}

// LOGOUT LOGIC
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
  // 1. Clear Session
  session_unset();
  session_destroy();

  // 2. Clear Cookie
  if (isset($_COOKIE['remember_token'])) {
      setcookie('remember_token', '', time() - 3600, "/", "", false, true);
      $token_hash = hash('sha256', $_COOKIE['remember_token']);
      $stmt = $conn->prepare("DELETE FROM login_tokens WHERE token_hash = ?");
      $stmt->bind_param("s", $token_hash);
      $stmt->execute();
  }

  // 4. Standard Redirect to Login
  header("Location: " . basename($_SERVER['PHP_SELF'])); 
  exit;
}

function uploadProfilePic($fieldName) {
  if(!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) { return null; }
  $tmp = $_FILES[$fieldName]['tmp_name'];
  $name = time() . '_' . basename($_FILES[$fieldName]['name']); // Timestamp to prevent caching issues
  $target = __DIR__ . '/GMPSimages/' . $name;
  move_uploaded_file($tmp, $target);
  return 'GMPSimages/' . $name;
}

// --- MAIN FORM PROCESSING BLOCK ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
  $action = $_POST['action'];

  // --- ADD TEACHER ---
  if ($action === 'addTeacher') {
      $pic   = uploadProfilePic('profile_pic');
      $name  = $_POST['newTeacherName'];
      $lid   = $_POST['newTeacherId'];
      $pwd   = password_hash($_POST['newTeacherPassword'], PASSWORD_DEFAULT);
      $cont  = $_POST['newTeacherContact'];
      
      // Determine Role
      $role = $_POST['teacherRole']; // 'classteacher' or 'subjectteacher'
      $assigned_class = ($role === 'classteacher' && !empty($_POST['assignedClass'])) ? $_POST['assignedClass'] : null;
      
      // Insert Teacher
      $stmt = $conn->prepare("INSERT INTO teachers (name, login_id, password_hash, contact, profile_pic, assigned_class_id) VALUES (?, ?, ?, ?, ?, ?)");
      $stmt->bind_param("sssssi", $name, $lid, $pwd, $cont, $pic, $assigned_class);
      
      if ($stmt->execute()) {
          $new_teacher_id = $stmt->insert_id;
          
          // If Subject Teacher (or even Classteacher), assign the selected subject
          if (!empty($_POST['assignedSubject'])) {
              $subj_code = $_POST['assignedSubject'];
              $stmt_sub = $conn->prepare("INSERT INTO teacher_subjects (teacher_id, subject_code) VALUES (?, ?)");
              $stmt_sub->bind_param("is", $new_teacher_id, $subj_code);
              $stmt_sub->execute();
          }
          setAlert('success', 'Teacher added successfully!');
      } else {
          setAlert('error', 'Error adding teacher.');
      }
      header("Location: admin.php#manageTeachers");
      exit;
  }

  // --- UPDATE TEACHER ---
  if ($action === 'updateTeacher' && isset($_POST['teacherIdToUpdate'])) {
      $id_to_update = (int)$_POST['teacherIdToUpdate'];
      $name    = $_POST['editTeacherName'];
      $loginId = $_POST['editTeacherId'];
      $contact = $_POST['editTeacherContact'];
      
      // Handle Role Update
      $role = $_POST['editTeacherRole'];
      $assigned_class = ($role === 'classteacher' && !empty($_POST['editAssignedClass'])) ? $_POST['editAssignedClass'] : null;

      $pic_path = $_POST['existingProfilePic'];
      if (isset($_FILES['editProfilePic']) && $_FILES['editProfilePic']['error'] === UPLOAD_ERR_OK) {
          $pic_path = uploadProfilePic('editProfilePic');
      }
      
      // Update basic info and assigned class
      $stmt = $conn->prepare("UPDATE teachers SET name = ?, login_id = ?, contact = ?, profile_pic = ?, assigned_class_id = ? WHERE id = ?");
      $stmt->bind_param("ssssii", $name, $loginId, $contact, $pic_path, $assigned_class, $id_to_update);
      $stmt->execute();
      
      // Update Password if provided
      if (!empty($_POST['editTeacherPassword'])) {
          $pwd_hash = password_hash($_POST['editTeacherPassword'], PASSWORD_DEFAULT);
          $stmt = $conn->prepare("UPDATE teachers SET password_hash = ? WHERE id = ?");
          $stmt->bind_param("si", $pwd_hash, $id_to_update);
          $stmt->execute();
      }

      // Update Subject (Delete old, insert new)
      if (!empty($_POST['editAssignedSubject'])) {
          $conn->query("DELETE FROM teacher_subjects WHERE teacher_id = $id_to_update");
          $subj_code = $_POST['editAssignedSubject'];
          $stmt_sub = $conn->prepare("INSERT INTO teacher_subjects (teacher_id, subject_code) VALUES (?, ?)");
          $stmt_sub->bind_param("is", $id_to_update, $subj_code);
          $stmt_sub->execute();
      }

      setAlert('success', 'Teacher updated successfully!');
      header("Location: admin.php?viewTeacher=$id_to_update#manageTeachers");
      exit;
  }

  if ($action === 'deleteTeacher' && isset($_POST['teacherIdToDelete'])) {
      $id_to_delete = (int)$_POST['teacherIdToDelete'];
      $stmt = $conn->prepare("DELETE FROM teachers WHERE id = ?");
      $stmt->bind_param("i", $id_to_delete);
      if($stmt->execute()) {
         setAlert('success', 'Teacher deleted successfully.');
      } else {
         setAlert('error', 'Could not delete teacher.');
      }
      header("Location: admin.php#manageTeachers");
      exit;
  }

  if ($action === 'addStudent') {
    $pic = uploadProfilePic('profile_pic');
    if ($pic === null) {
        $pic = 'GMPSimages/default_student.png'; 
    }

    $sid   = $_POST['newStudentId'];
    $pwd   = password_hash($_POST['newStudentPassword'], PASSWORD_DEFAULT);
    $name  = $_POST['newStudentName'];
    $dob   = !empty($_POST['newDOB']) ? $_POST['newDOB'] : NULL; 
    $father= $_POST['newFatherName'];
    $mother= $_POST['newMotherName'];
    $addr  = $_POST['newAddress'];
    $cont  = $_POST['newContact'];
    $cls   = $_POST['newStudentClass'];
    $roll  = !empty($_POST['newRollNo']) ? $_POST['newRollNo'] : NULL; 
    $year  = $_POST['newAdmissionYear'];
    
    $stmt = $conn->prepare("INSERT INTO students (name, dob, login_id, password_hash, father_name, mother_name, address, contact, class_id, roll_no, admission_year, profile_pic, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')");
    $stmt->bind_param("ssssssssiiis", $name, $dob, $sid, $pwd, $father, $mother, $addr, $cont, $cls, $roll, $year, $pic);
    
    if($stmt->execute()){
        setAlert('success', 'Student added successfully!');
    } else {
        setAlert('error', 'Error adding student.');
    }
  }

    if ($action === 'addAdmin') {
        $pic   = uploadProfilePic('profile_pic');
        $aid   = $_POST['newAdminId'];
        $pwd   = password_hash($_POST['newAdminPassword'], PASSWORD_DEFAULT);
        $name  = $_POST['newAdminName'];
        $lvl   = $_POST['newAdminLevel'];
        $cont  = $_POST['newAdminContact'];
        $stmt = $conn->prepare("INSERT INTO admins (name, login_id, password_hash, contact, level, profile_pic) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssis", $name, $aid, $pwd, $cont, $lvl, $pic);
        if($stmt->execute()){
            setAlert('success', 'Admin added successfully!');
        }
    }

    if ($action === 'deleteAdmin' && isset($_POST['adminIdToDelete'])) {
        if ($_POST['adminIdToDelete'] != $_SESSION['adminUser']['id']) {
            $id_to_delete = (int)$_POST['adminIdToDelete'];
            $stmt = $conn->prepare("DELETE FROM admins WHERE id = ?");
            $stmt->bind_param("i", $id_to_delete);
            $stmt->execute();
            setAlert('success', 'Admin deleted successfully.');
            header("Location: admin.php#manageAdminsSection");
            exit;
        }
    }

    if ($action === 'updateAdmin' && isset($_POST['adminIdToUpdate'])) {
      $id_to_update = (int)$_POST['adminIdToUpdate'];
      $name    = $_POST['editAdminName'];
      $loginId = $_POST['editAdminId'];
      $contact = $_POST['editAdminContact'];
      $level   = (int)$_POST['editAdminLevel']; 
      
      $pic_path = $_POST['existingProfilePic'];
      if (isset($_FILES['editProfilePic']) && $_FILES['editProfilePic']['error'] === UPLOAD_ERR_OK) {
          $new_pic = uploadProfilePic('editProfilePic');
          if ($new_pic) $pic_path = $new_pic;
      }

      $stmt = $conn->prepare("UPDATE admins SET name = ?, login_id = ?, contact = ?, level = ?, profile_pic = ? WHERE id = ?");
      if ($stmt) {
          $stmt->bind_param("sssisi", $name, $loginId, $contact, $level, $pic_path, $id_to_update);
          if ($stmt->execute()) {
              setAlert('success', 'Admin updated successfully!');
          } else {
              setAlert('error', 'Error updating details: ' . $stmt->error);
          }
          $stmt->close();
      }

      if (!empty($_POST['editAdminPassword'])) {
          $pwd_hash = password_hash($_POST['editAdminPassword'], PASSWORD_DEFAULT);
          $stmt_pw = $conn->prepare("UPDATE admins SET password_hash = ? WHERE id = ?");
          if ($stmt_pw) {
              $stmt_pw->bind_param("si", $pwd_hash, $id_to_update);
              $stmt_pw->execute();
              $stmt_pw->close();
          }
      }
      
      header("Location: admin.php?viewAdmin=$id_to_update&scrollTo=manageAdminsSection");
      exit;
  }
  
    if ($action === 'deleteStudent' && isset($_POST['studentIdToDelete'])) {
        $id_to_delete = (int)$_POST['studentIdToDelete'];
        $conn->query("DELETE FROM marks WHERE student_id = $id_to_delete");
        $conn->query("DELETE FROM attendance WHERE student_id = $id_to_delete");
        $conn->query("DELETE FROM students WHERE id = $id_to_delete");
        setAlert('success', 'Student deleted successfully.');
        header("Location: admin.php#manageStudents");
        exit;
    }

    // ---- Handle 'updateStudent' action ----
    if ($action === 'updateStudent' && isset($_POST['studentIdToUpdate'])) {
      $sid = (int)$_POST['studentIdToUpdate'];
      
      $name    = $_POST['editStudentName'];
      $father  = $_POST['editFatherName'];
      $mother  = $_POST['editMotherName'];
      $addr    = $_POST['editAddress'];
      $contact = $_POST['editContact'];
      $class   = (int)$_POST['editStudentClass'];
      $year    = $_POST['editAdmissionYear'];

      $pic_path = $_POST['existingProfilePic'];
      if (isset($_FILES['editProfilePic']) && $_FILES['editProfilePic']['error'] === UPLOAD_ERR_OK) {
          $pic_path = uploadProfilePic('editProfilePic');
      }

      $dob   = !empty($_POST['editDOB']) ? $_POST['editDOB'] : NULL;
      $roll  = !empty($_POST['editRollNo']) ? $_POST['editRollNo'] : NULL;

      $stmt = $conn->prepare(
        "UPDATE students SET name=?, dob=?, father_name=?, mother_name=?, address=?, contact=?, class_id=?, roll_no=?, admission_year=?, profile_pic=? WHERE id=?"
      );
      $stmt->bind_param("ssssssiiiss", $name, $dob, $father, $mother, $addr, $contact, $class, $roll, $year, $pic_path, $sid);
      $stmt->execute();

      if (!empty($_POST['editStudentPassword'])) {
          $pwd_hash = password_hash($_POST['editStudentPassword'], PASSWORD_DEFAULT);
          $stmt = $conn->prepare("UPDATE students SET password_hash = ? WHERE id = ?");
          $stmt->bind_param("si", $pwd_hash, $sid);
          $stmt->execute();
      }

      // --- Update Attendance ---
      if (isset($_POST['attendance'])) {
          foreach ($_POST['attendance'] as $month_num => $vals) {
              $present = is_numeric($vals['present']) ? (int)$vals['present'] : 0;
              $absent  = is_numeric($vals['absent']) ? (int)$vals['absent'] : 0;
              $month   = (int)$month_num;
              $att_year= date('Y');

              if ($present == 0 && $absent == 0) {
                  $conn->query("DELETE FROM attendance WHERE student_id=$sid AND year=$att_year AND month=$month");
              } else {
                  $conn->query("INSERT INTO attendance (student_id, year, month, days_present, days_absent) VALUES ($sid, $att_year, $month, $present, $absent) ON DUPLICATE KEY UPDATE days_present=$present, days_absent=$absent");
              }
          }
      }

      // --- Update Marks ---
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

              if (isset($subjects['new_subject_code'])) {
                  foreach ($subjects['new_subject_code'] as $key => $new_code) {
                      $new_mark = $subjects['new_subject_marks'][$key];
                      if (!empty($new_code) && is_numeric($new_mark)) {
                          $exam_id_int = (int)$exam_id;
                          $obtained_marks = (int)$new_mark;
                          $stmt = $conn->prepare("INSERT INTO marks (student_id, exam_id, subject_code, marks_obtained) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE marks_obtained = ?");
                          $stmt->bind_param("iisii", $sid, $exam_id_int, $new_code, $obtained_marks, $obtained_marks);
                          $stmt->execute();
                      }
                  }
              }
          }
      }
      
      setAlert('success', 'Student data updated successfully!');
      $class_id_for_redirect = $_GET['profileClass'] ?? $class;
      header("Location: admin.php?viewStudent=$sid&profileClass=$class_id_for_redirect#manageStudents");
      exit;
    }

    if ($action === 'addNewClass' && isset($_POST['newClassName'], $_POST['sortOrderAfter'])) {
        $name = $_POST['newClassName'];
        $sort_after = (int)$_POST['sortOrderAfter'];
        $next_class_res = $conn->query("SELECT MIN(sort_order) as next_order FROM classes WHERE sort_order > $sort_after");
        $next_class = $next_class_res->fetch_assoc();
        $next_order = $next_class['next_order'] ? (int)$next_class['next_order'] : $sort_after + 1000;
        $new_sort_order = floor(($sort_after + $next_order) / 2);
        $stmt = $conn->prepare("INSERT INTO classes (name, sort_order) VALUES (?, ?)");
        $stmt->bind_param("si", $name, $new_sort_order);
        if($stmt->execute()) setAlert('success', 'Class added!');
        header("Location: admin.php#manageClasses");
        exit;
    }

    if ($action === 'deleteClass' && isset($_POST['classIdToDelete'])) {
        $id_to_delete = (int)$_POST['classIdToDelete'];
        $stmt = $conn->prepare("DELETE FROM classes WHERE id = ?");
        $stmt->bind_param("i", $id_to_delete);
        $stmt->execute();
        setAlert('success', 'Class deleted.');
        header("Location: admin.php#manageClasses");
        exit;
    }

    if ($action === 'updateClass' && isset($_POST['classIdToUpdate'], $_POST['editClassName'])) {
      $id_to_update = (int)$_POST['classIdToUpdate'];
      $new_name = trim($_POST['editClassName']);
      
      if (!empty($new_name)) {
          $stmt = $conn->prepare("UPDATE classes SET name = ? WHERE id = ?");
          $stmt->bind_param("si", $new_name, $id_to_update);
          $stmt->execute();
          setAlert('success', 'Class name updated.');
      }
      
      header("Location: admin.php#manageClasses");
      exit;
    }

    if ($action === 'savePromotions') {
      $conn->query("TRUNCATE TABLE class_promotions");
      if (isset($_POST['promotions'])) {
          $stmt = $conn->prepare("INSERT INTO class_promotions (current_class_id, next_class_id) VALUES (?, ?)");
          
          foreach ($_POST['promotions'] as $current_id => $next_id) {
              $current_class_id = (int)$current_id;
              
              if (empty($next_id)) {
                  $next_class_id = null;
              } else {
                  $next_class_id = (int)$next_id;
              }
              
              $stmt->bind_param("ii", $current_class_id, $next_class_id);
              $stmt->execute();
          }
      }
      setAlert('success', 'Promotion map saved.');
      header("Location: admin.php#manageClasses");
      exit;
  }

  // ---- subjectManager actions ----
  if ($action === 'addSubject') {
    $code = strtoupper($_POST['newSubjectCode']); 
    $name = $_POST['newSubjectName'];
    if (!empty($code) && !empty($name)) {
        $stmt = $conn->prepare("INSERT INTO subjects (code, name) VALUES (?, ?)");
        $stmt->bind_param("ss", $code, $name);
        $stmt->execute();
        setAlert('success', 'Subject added.');
    }
    header("Location: admin.php#manageSubjects");
    exit;
  }

  if ($action === 'updateSubject') {
    $code = $_POST['subjectCodeToUpdate'];
    $name = $_POST['editSubjectName'];
    if (!empty($code) && !empty($name)) {
        $stmt = $conn->prepare("UPDATE subjects SET name = ? WHERE code = ?");
        $stmt->bind_param("ss", $name, $code);
        $stmt->execute();
        setAlert('success', 'Subject updated.');
    }
    header("Location: admin.php#manageSubjects");
    exit;
  }

  if ($action === 'deleteSubject') {
    $code = $_POST['subjectCodeToDelete'];
    if (!empty($code)) {
        $stmt = $conn->prepare("DELETE FROM subjects WHERE code = ?");
        $stmt->bind_param("s", $code);
        $stmt->execute();
        setAlert('success', 'Subject deleted.');
    }
    header("Location: admin.php#manageSubjects");
    exit;
  }
}

// ---- Handle 'endOfSession' action ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action']) && $_POST['action'] === 'endOfSession') {
    if (!isset($_SESSION['adminUser']['level']) || $_SESSION['adminUser']['level'] != 1) {
        die("ACCESS DENIED: You do not have permission to perform this action.");
    }
  
    $master_password = $_POST['masterPassword'];
    $archive_name = preg_replace('/[^a-zA-Z0-9-_\.]/', '', $_POST['archiveName']);
  
    // 1. Verify Master Password (Securely)
    $hash_res = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'master_password_hash'");
    $hash_row = $hash_res->fetch_assoc();
    $stored_hash = $hash_row ? trim($hash_row['setting_value']) : '';
    if (!$stored_hash || !password_verify($master_password, $stored_hash)) {
        setAlert('error', 'Master password incorrect!');
        header("Location: admin.php?error=master_password#endOfSession");
        exit;
    }
  
   // --- STEP 1: ARCHIVE DATA AS A PDF ---
   require_once 'includes/fpdf.php';
   require_once 'api/session_manager.php';
   $current_sess = get_current_session($conn);
  
   $pdf = new FPDF('P', 'mm', 'A4');
   $pdf->SetFont('Arial', 'B', 16);
   $pdf->AddPage();
   $pdf->Cell(0, 10, 'Student Data Archive: Session ' . $current_sess, 0, 1, 'C');
   $pdf->SetFont('Arial', '', 10);
   $pdf->Cell(0, 10, 'Generated on: ' . date('Y-m-d H:i:s'), 0, 1, 'C');
   $pdf->Ln(10);
  
   $students_res = $conn->query("SELECT s.*, c.name as class_name FROM students s JOIN classes c ON s.class_id = c.id ORDER BY c.sort_order");
   
   if($students_res && $students_res->num_rows > 0) {
       while($student = $students_res->fetch_assoc()){
           $sid = $student['id'];
           
           $pdf->SetFont('Arial', 'B', 14);
           $pdf->Cell(0, 10, 'Student: ' . $student['name'] . ' (ID: ' . $student['login_id'] . ')', 1, 1, 'C');
           $pdf->SetFont('Arial', '', 12);
           
           $image_path = $student['profile_pic'];
           if (empty($image_path) || !file_exists(__DIR__ . '/' . $image_path)) {
               $image_path = 'GMPSimages/default_student.png'; 
           }
           $pdf->Image($image_path, 160, $pdf->GetY() + 12, 30, 30);
           
           $pdf->Cell(140, 8, 'Class: ' . $student['class_name'], 'L', 1);
           $pdf->Cell(140, 8, "Father's Name: " . $student['father_name'], 'L', 1);
           $pdf->Cell(140, 8, "Mother's Name: " . $student['mother_name'], 'L', 1);
           $pdf->Cell(140, 8, 'Contact: ' . $student['contact'], 'L', 1);
           $pdf->Cell(140, 8, 'Address: ' . $student['address'], 'LB', 1);
           $pdf->Ln(5);
  
           // Attendance Table
           $pdf->SetFont('Arial', 'B', 12);
           $pdf->Cell(0, 8, 'Attendance Record', 0, 1);
           $pdf->SetFont('Arial', 'B', 10);
           $pdf->Cell(47.5, 7, 'Month', 1, 0, 'C');
           $pdf->Cell(47.5, 7, 'Days Present', 1, 0, 'C');
           $pdf->Cell(47.5, 7, 'Days Absent', 1, 0, 'C');
           $pdf->Cell(47.5, 7, 'Percentage', 1, 1, 'C');
           $pdf->SetFont('Arial', '', 10);
           $att_res = $conn->query("SELECT month, days_present, days_absent FROM attendance WHERE student_id=$sid ORDER BY year, month");
           if ($att_res && $att_res->num_rows > 0) {
               while($att = $att_res->fetch_assoc()){
                   $month_name = date('F', mktime(0, 0, 0, $att['month'], 10));
                   $total = $att['days_present'] + $att['days_absent'];
                   $percent = $total > 0 ? round(($att['days_present'] / $total) * 100) . '%' : 'N/A';
                   $pdf->Cell(47.5, 7, $month_name, 1, 0);
                   $pdf->Cell(47.5, 7, $att['days_present'], 1, 0, 'C');
                   $pdf->Cell(47.5, 7, $att['days_absent'], 1, 0, 'C');
                   $pdf->Cell(47.5, 7, $percent, 1, 1, 'C');
               }
           } else {
               $pdf->Cell(0, 7, 'No attendance records found.', 1, 1, 'C');
           }
           $pdf->Ln(5);
  
           // Marks Tables
           $pdf->SetFont('Arial', 'B', 12);
           $pdf->Cell(0, 8, 'Exam Results', 0, 1);
           $exams_res = $conn->query("SELECT DISTINCT e.id, e.name as exam_name, e.max_marks FROM exams e JOIN marks m ON e.id = m.exam_id WHERE m.student_id=$sid ORDER BY e.id");
           if($exams_res && $exams_res->num_rows > 0) {
                while($exam = $exams_res->fetch_assoc()) {
                   $pdf->SetFont('Arial', 'B', 10);
                   $pdf->Cell(0, 7, $exam['exam_name'], 1, 1, 'L');
                   $pdf->Cell(130, 7, 'Subject', 1, 0, 'C');
                   $pdf->Cell(60, 7, 'Marks', 1, 1, 'C');
                   $pdf->SetFont('Arial', '', 10);
                   $marks_res = $conn->query("SELECT s.name as subject_name, m.marks_obtained FROM marks m JOIN subjects s ON m.subject_code = s.code WHERE m.student_id=$sid AND m.exam_id={$exam['id']} ORDER BY s.name");
                   while($mark = $marks_res->fetch_assoc()){
                      $pdf->Cell(130, 7, $mark['subject_name'], 1, 0);
                      $pdf->Cell(60, 7, $mark['marks_obtained'] . ' / ' . $exam['max_marks'], 1, 1, 'C');
                   }
                }
           } else {
               $pdf->SetFont('Arial', '', 10);
               $pdf->Cell(0, 7, 'No marks found for this student.', 1, 1, 'C');
           }
           $pdf->AddPage();
       }
   } else {
       $pdf->Cell(0, 10, 'No students found in the database at the time of archival.', 0, 1);
   }
  
   if (!is_dir(__DIR__ . '/archives')) { mkdir(__DIR__ . '/archives'); }
   $pdf->Output('F', __DIR__ . '/archives/' . $archive_name . '.pdf');
  
    // --- STEP 2: IDENTIFY GRADUATING & FORK CLASSES ---
    $graduating_class_id = null;
    $fork_class_ids = [];
    $promo_map_res = $conn->query("SELECT p.current_class_id, c.sort_order FROM class_promotions p JOIN classes c ON p.current_class_id = c.id WHERE p.next_class_id IS NULL ORDER BY c.sort_order DESC");
    if ($promo_map_res && $promo_map_res->num_rows > 0) {
        $graduating_class = $promo_map_res->fetch_assoc();
        $graduating_class_id = $graduating_class['current_class_id'];
        while($row = $promo_map_res->fetch_assoc()) {
            $fork_class_ids[] = (int)$row['current_class_id'];
        }
    }
    
    if (!empty($fork_class_ids)) {
        $fork_ids_str = implode(',', $fork_class_ids);
        $conn->query("UPDATE students SET status = 'awaiting_stream' WHERE class_id IN ($fork_ids_str)");
    }
    if ($graduating_class_id) {
        $conn->query("UPDATE students SET status = 'graduated' WHERE class_id = $graduating_class_id");
    }
  
    // --- STEP 3: PROMOTE ACTIVE STUDENTS (Reverse Order) ---
    $promo_map_query = "SELECT p.current_class_id, p.next_class_id FROM class_promotions p JOIN classes c ON p.current_class_id = c.id ORDER BY c.sort_order DESC";
    $promo_map_res = $conn->query($promo_map_query);
    if ($promo_map_res) {
        while ($map = $promo_map_res->fetch_assoc()) {
            if (!is_null($map['next_class_id'])) {
                $current_id = (int)$map['current_class_id'];
                $next_id = (int)$map['next_class_id'];
                $conn->query("UPDATE students SET class_id = $next_id WHERE class_id = $current_id AND status = 'active'");
            }
        }
    }
  
    // --- STEP 4: CLEANUP & RESET (THE DEEP CLEAN) ---
    // Disable FK checks to allow truncation of parent tables
    $conn->query("SET FOREIGN_KEY_CHECKS = 0");
  
    // A. Students Logic (Remove Graduated)
    $conn->query("DELETE FROM marks WHERE student_id IN (SELECT id FROM students WHERE status = 'graduated')");
    $conn->query("DELETE FROM attendance WHERE student_id IN (SELECT id FROM students WHERE status = 'graduated')");
    $conn->query("DELETE FROM students WHERE status = 'graduated'");
  
    // B. Academic Data Reset
    $conn->query("TRUNCATE TABLE attendance"); // Old monthly table
    $conn->query("TRUNCATE TABLE daily_attendance"); // New daily app table
    $conn->query("TRUNCATE TABLE marks");
  
    // C. Communications & Content Reset
    $conn->query("TRUNCATE TABLE daily_posts"); // Homework/Classwork (Cascades to post_items)
    $conn->query("TRUNCATE TABLE events_announcements"); // Notice Board
    $conn->query("TRUNCATE TABLE events_daily_updates"); // "What's Happening"
    $conn->query("TRUNCATE TABLE events_upcoming"); // Calendar Events
    $conn->query("TRUNCATE TABLE exam_publish_status"); // Reset Exam Visibility
  
    // Re-enable FK checks
    $conn->query("SET FOREIGN_KEY_CHECKS = 1");
  
    // --- STEP 5: AUTO-INCREMENT ACADEMIC SESSION ---
    // This updates '2025-2026' to '2026-2027' in the database
    require_once 'api/session_manager.php';
    increment_session($conn);
  
    setAlert('success', 'Session ended successfully! Archive created, students promoted, and all academic/activity data reset for the new year.');
    header("Location: admin.php?success=session_ended#endOfSession");
    exit;
  }

// ---- Handle 'assignStreams' action ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action']) && $_POST['action'] === 'assignStreams') {
  if (isset($_POST['assignments'])) {
      $stmt = $conn->prepare("UPDATE students SET class_id = ?, status = 'active' WHERE id = ?");
      foreach ($_POST['assignments'] as $student_id => $new_class_id) {
          $sid = (int)$student_id;
          $cid = (int)$new_class_id;
          $stmt->bind_param("ii", $cid, $sid);
          $stmt->execute();
      }
      setAlert('success', 'Streams assigned successfully!');
  }
  header("Location: admin.php#manualAssignment");
  exit;
}

// Delete Feedback
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'deleteFeedback' && isset($_POST['feedbackId'])) {
  $fid = (int)$_POST['feedbackId'];
  $conn->query("DELETE FROM student_feedback WHERE id = $fid");
  setAlert('success', 'Feedback deleted.');
  header("Location: admin.php#manageFeedback");
  exit;
}

// --------- Handle “saveHome” form submission ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'saveHome') {

  function handleUpload(array $fileArr, string $oldUrl): string {
      if (!empty($fileArr['tmp_name']) && is_uploaded_file($fileArr['tmp_name'])) {
          $name = time().'_'.basename($fileArr['name']);
          $dest = __DIR__ . '/GMPSimages/' . $name;
          move_uploaded_file($fileArr['tmp_name'], $dest);
          return str_replace('\\', '/', 'GMPSimages/' . $name);
      }
      return str_replace('\\', '/', $oldUrl);
  }

  // 1) Slideshow
  $conn->query("TRUNCATE TABLE home_slideshow");
  foreach ($_POST['slide_order'] as $i => $_ignore) {
      $fileArr = [
          'tmp_name' => $_FILES['slide_file']['tmp_name'][$i] ?? '',
          'name'     => $_FILES['slide_file']['name'][$i]     ?? '',
          'error'    => $_FILES['slide_file']['error'][$i]    ?? UPLOAD_ERR_NO_FILE,
      ];
      $url = handleUpload($fileArr, $_POST['slide_img'][$i]);
      if (trim($url) === '') continue;

      $alt = $conn->real_escape_string($_POST['slide_alt'][$i] ?? '');
      $ord = (int) $_POST['slide_order'][$i];
      $conn->query("INSERT INTO home_slideshow (img_url, alt_text, display_order) VALUES ('{$conn->real_escape_string($url)}', '$alt', $ord)");
  }

  // 2) Administration Thoughts
  $conn->query("TRUNCATE TABLE home_administration_thoughts");
  foreach ($_POST['adm_name'] as $i => $nm) {
      $nm = trim($nm);
      if ($nm === '') continue;

      $fileArr = [
          'tmp_name' => $_FILES['adm_file']['tmp_name'][$i] ?? '',
          'name'     => $_FILES['adm_file']['name'][$i]     ?? '',
          'error'    => $_FILES['adm_file']['error'][$i]    ?? UPLOAD_ERR_NO_FILE,
      ];
      $img = handleUpload($fileArr, $_POST['adm_img'][$i]);
      if ($img === '') continue;

      $pos   = $conn->real_escape_string($_POST['adm_pos'][$i]   ?? '');
      $quote = $conn->real_escape_string($_POST['adm_quote'][$i] ?? '');
      $conn->query("INSERT INTO home_administration_thoughts (name, position, image_url, quote) VALUES ('$nm','$pos','$img','$quote')");
  }

  // 3) Gallery
  $conn->query("TRUNCATE TABLE home_gallery");
  foreach ($_POST['gal_alt'] as $i => $_ignore) {
      $fileArr = [
          'tmp_name' => $_FILES['gal_file']['tmp_name'][$i] ?? '',
          'name'     => $_FILES['gal_file']['name'][$i]     ?? '',
          'error'    => $_FILES['gal_file']['error'][$i]    ?? UPLOAD_ERR_NO_FILE,
      ];
      $url = handleUpload($fileArr, $_POST['gal_img'][$i]);
      if (trim($url) === '') continue;

      $alt = $conn->real_escape_string($_POST['gal_alt'][$i] ?? '');
      $conn->query("INSERT INTO home_gallery (image_url, alt_text) VALUES ('{$conn->real_escape_string($url)}','$alt')");
  }

  // 4) Video + Quote (single row)
  $h = $conn->real_escape_string($_POST['vq_heading'] ?? '');
  $p = $conn->real_escape_string($_POST['vq_para']    ?? '');
  $u = $conn->real_escape_string($_POST['vq_url']     ?? '');
  $conn->query("TRUNCATE TABLE home_video_quote");
  $conn->query("INSERT INTO home_video_quote (heading, paragraph, video_url) VALUES ('$h','$p','$u')");

  // 5) Stats
  $conn->query("TRUNCATE TABLE home_statistics");
  foreach ($_POST['stat_label'] as $i => $lbl) {
      $val = trim($_POST['stat_val'][$i] ?? '');
      if ($val === '') continue;
      $lbl = $conn->real_escape_string($lbl);
      $conn->query("INSERT INTO home_statistics (label, value) VALUES ('$lbl','{$conn->real_escape_string($val)}')");
  }

  setAlert('success', 'Home page content updated!');
  header("Location: admin.php#manageContent");
  exit;
}

// --------- Handle “saveAcademics” form submission ----------
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='saveAcademics') {

  function handleUploadAcademics(array $fileArr, string $oldUrl): string {
      if (!empty($fileArr['tmp_name']) && is_uploaded_file($fileArr['tmp_name'])) {
          $name = time().'_'.basename($fileArr['name']);
          $dest = __DIR__ . '/GMPSimages/' . $name;
          move_uploaded_file($fileArr['tmp_name'], $dest);
          return str_replace('\\','/','GMPSimages/' . $name);
      }
      return str_replace('\\','/',$oldUrl);
  }

  // 1) Curriculum bullets
  $conn->query("TRUNCATE TABLE academics_curriculum");
  foreach ($_POST['curr_bullet'] as $b) {
      $b = trim($b);
      if ($b==='') continue;
      $b = $conn->real_escape_string($b);
      $conn->query("INSERT INTO academics_curriculum (bullet) VALUES ('$b')");
  }

  // 2) Toppers
  $conn->query("TRUNCATE TABLE academics_toppers");
  foreach ($_POST['top_class'] as $i => $cls) {
      $cls = trim($cls);
      $name = trim($_POST['top_name'][$i]);
      if ($cls==='' || $name==='') continue;
      $fileArr = [
        'tmp_name'=> $_FILES['top_file']['tmp_name'][$i] ?? '',
        'name'    => $_FILES['top_file']['name'][$i]     ?? '',
        'error'   => $_FILES['top_file']['error'][$i]    ?? UPLOAD_ERR_NO_FILE,
      ];
      $img = handleUploadAcademics($fileArr, $_POST['top_old_img'][$i]);
      if ($img==='') continue;  
      $cls  = $conn->real_escape_string($cls);
      $name = $conn->real_escape_string($name);
      $img  = $conn->real_escape_string($img);
      $conn->query("INSERT INTO academics_toppers (class_desc, student_name, img_url) VALUES ('$cls','$name','$img')");
  }

  // 3) Facilities
  $conn->query("TRUNCATE TABLE academics_facilities");
  foreach ($_POST['fac_desc'] as $i => $desc) {
      $desc = trim($desc);
      if ($desc==='') continue;
      $fileArr = [
        'tmp_name'=> $_FILES['fac_file']['tmp_name'][$i] ?? '',
        'name'    => $_FILES['fac_file']['name'][$i]     ?? '',
        'error'   => $_FILES['fac_file']['error'][$i]    ?? UPLOAD_ERR_NO_FILE,
      ];
      $img = handleUploadAcademics($fileArr, $_POST['fac_old_img'][$i]);
      if ($img==='') continue;
      $rev = isset($_POST['fac_rev'][$i]) ? 1 : 0;
      $desc = $conn->real_escape_string($desc);
      $img  = $conn->real_escape_string($img);
      $conn->query("INSERT INTO academics_facilities (img_url, description, is_reverse) VALUES ('$img','$desc',$rev)");
  }

  setAlert('success', 'Academics page updated!');
  header("Location: admin.php#manageContent");
  exit;
}


// --------- Handle “saveAdmissions” form submission ----------
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='saveAdmissions') {

  // 1) Guidelines
  $conn->query("TRUNCATE TABLE admissions_guidelines");
  foreach ($_POST['guid_label'] as $id => $label) {
    if ($id==='new') continue;
    $desc = $conn->real_escape_string($_POST['guid_desc'][$id]);
    $lbl  = $conn->real_escape_string($label);
    $conn->query("INSERT INTO admissions_guidelines (label,description,display_order) VALUES ('$lbl','$desc',$id)");
  }
  foreach ($_POST['guid_label']['new'] as $i=>$lbl) {
    $lbl = trim($lbl);
    $desc = trim($_POST['guid_desc']['new'][$i]);
    if ($lbl==='') continue;
    $lbl  = $conn->real_escape_string($lbl);
    $desc = $conn->real_escape_string($desc);
    $conn->query("INSERT INTO admissions_guidelines (label,description,display_order) VALUES ('$lbl','$desc',999)");
  }

  // 2) Process
  $conn->query("TRUNCATE TABLE admissions_process");
  foreach ($_POST['proc_desc'] as $id => $d) {
    if ($id==='new') continue;
    $d = $conn->real_escape_string($d);
    $conn->query("INSERT INTO admissions_process (step_order,description) VALUES ($id,'$d')");
  }
  foreach ($_POST['proc_desc']['new'] as $d) {
    $d = trim($d);
    if ($d==='') continue;
    $d = $conn->real_escape_string($d);
    $conn->query("INSERT INTO admissions_process (step_order,description) VALUES (999,'$d')");
  }

  // 3) Dates
  $conn->query("TRUNCATE TABLE admissions_dates");
  foreach ($_POST['date_label'] as $id => $lbl) {
    if ($id==='new') continue;
    $date = $_POST['date_val'][$id];
    $lbl  = $conn->real_escape_string($lbl);
    $conn->query("INSERT INTO admissions_dates (label,date_value,display_order) VALUES ('$lbl','$date',$id)");
  }
  foreach ($_POST['date_label']['new'] as $i=>$lbl) {
    $lbl = trim($lbl);
    $date = $_POST['date_val']['new'][$i];
    if ($lbl===''|| $date==='') continue;
    $lbl = $conn->real_escape_string($lbl);
    $conn->query("INSERT INTO admissions_dates (label,date_value,display_order) VALUES ('$lbl','$date',999)");
  }

  // 4) Fees
  $conn->query("TRUNCATE TABLE fee_structure");
  foreach ($_POST['fee_class'] as $id => $cls) {
    if ($id==='new') continue;
    $t = (float)$_POST['fee_tuit'][$id];
    $r = (float)$_POST['fee_reg'][$id];
    $m = (float)$_POST['fee_misc'][$id];
    $cls = $conn->real_escape_string($cls);
    $conn->query("INSERT INTO fee_structure (class_name,tuition_fee,registration_fee,misc_fee,display_order) VALUES ('$cls',$t,$r,$m,$id)");
  }
  foreach ($_POST['fee_class']['new'] as $i=>$cls) {
    $cls = trim($cls);
    if ($cls==='') continue;
    $t=(float)$_POST['fee_tuit']['new'][$i];
    $r=(float)$_POST['fee_reg']['new'][$i];
    $m=(float)$_POST['fee_misc']['new'][$i];
    $cls = $conn->real_escape_string($cls);
    $conn->query("INSERT INTO fee_structure (class_name,tuition_fee,registration_fee,misc_fee,display_order) VALUES ('$cls',$t,$r,$m,999)");
  }

  // 5) FAQ
  $conn->query("TRUNCATE TABLE admissions_faq");
  foreach ($_POST['faq_q'] as $id => $q) {
    if ($id==='new') continue;
    $a = $conn->real_escape_string($_POST['faq_a'][$id]);
    $q = $conn->real_escape_string($q);
    $conn->query("INSERT INTO admissions_faq (question,answer,display_order) VALUES ('$q','$a',$id)");
  }
  foreach ($_POST['faq_q']['new'] as $i=>$q) {
    $q = trim($q);
    $a = trim($_POST['faq_a']['new'][$i]);
    if ($q==='') continue;
    $q = $conn->real_escape_string($q);
    $a = $conn->real_escape_string($a);
    $conn->query("INSERT INTO admissions_faq (question,answer,display_order) VALUES ('$q','$a',999)");
  }

  setAlert('success', 'Admissions page updated!');
  header("Location: admin.php#manageContent");
  exit;
}

// ---- Handle “saveEvents” form submission ----
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='saveEvents') {
  
  if (!function_exists('handleEventUpload')) {
      function handleEventUpload($fileIndex, $fileKey, $oldUrl) {
          if (!empty($_FILES[$fileKey]['tmp_name'][$fileIndex]) && is_uploaded_file($_FILES[$fileKey]['tmp_name'][$fileIndex])) {
              $name = time() . '_' . basename($_FILES[$fileKey]['name'][$fileIndex]);
              $dest = __DIR__ . '/GMPSimages/' . $name;
              move_uploaded_file($_FILES[$fileKey]['tmp_name'][$fileIndex], $dest);
              return 'GMPSimages/' . $name;
          }
          return $oldUrl;
      }
  }

  // 1) Announcements
  $conn->query("TRUNCATE TABLE events_announcements");
  if (isset($_POST['ann_title'])) {
      foreach ($_POST['ann_title'] as $i => $title) {
          $t = trim($title);
          if ($t === '') continue;
          $img = handleEventUpload($i, 'ann_file', $_POST['ann_old_img'][$i] ?? '');
          $c = $conn->real_escape_string(trim($_POST['ann_content'][$i]));
          $t = $conn->real_escape_string($t);
          $img = $conn->real_escape_string($img);
          $conn->query("INSERT INTO events_announcements (title, content, image_url, display_order) VALUES ('$t','$c', '$img', $i)");
      }
  }

  // 2) Daily Updates
  $conn->query("TRUNCATE TABLE events_daily_updates");
  if (isset($_POST['upd_text'])) {
      foreach ($_POST['upd_text'] as $i => $upd) {
          $u = trim($upd);
          if ($u === '') continue;
          $img = handleEventUpload($i, 'upd_file', $_POST['upd_old_img'][$i] ?? '');
          $u = $conn->real_escape_string($u);
          $img = $conn->real_escape_string($img);
          $conn->query("INSERT INTO events_daily_updates (update_text, image_url, display_order) VALUES ('$u', '$img', $i)");
      }
  }

  // 3) Upcoming Events
  $conn->query("TRUNCATE TABLE events_upcoming");
  if (isset($_POST['ev_title'])) {
      foreach ($_POST['ev_title'] as $i => $title) {
          $t = trim($title);
          if ($t === '') continue;
          $img = handleEventUpload($i, 'ev_file', $_POST['ev_old_img'][$i] ?? '');
          $d = $_POST['ev_date'][$i];
          $desc = $conn->real_escape_string(trim($_POST['ev_desc'][$i]));
          $t = $conn->real_escape_string($t);
          $img = $conn->real_escape_string($img);
          $conn->query("INSERT INTO events_upcoming (title, event_date, description, image_url, display_order) VALUES ('$t','$d','$desc', '$img', $i)");
      }
  }
  
  setAlert('success', 'School updates saved!');
  header("Location: admin.php#manageEvents");
  exit;
}

// ---- “Save Contact” handler ----
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '') === 'saveContact') {
  foreach ($_POST['id'] as $i => $id) {
    $val = trim($_POST['value'][$i] ?? '');
    if ($val === '') {
      $conn->query("DELETE FROM contact_methods WHERE id=".(int)$id);
    } else {
      $v = $conn->real_escape_string($val);
      $conn->query("UPDATE contact_methods SET value = '$v' WHERE id = ".(int)$id);
    }
  }

  $newVal = trim($_POST['value_new'] ?? '');
  $newMeth = $_POST['method_new'] ?? '';
  if ($newVal !== '' && in_array($newMeth, ['phone','email','whatsapp'])) {
    $v = $conn->real_escape_string($newVal);
    $m = $conn->real_escape_string($newMeth);
    $ord = (int)$conn->query("SELECT COALESCE(MAX(display_order),0)+1 FROM contact_methods")->fetch_row()[0];
    $conn->query("INSERT INTO contact_methods (method,value,display_order) VALUES ('$m','$v',$ord)");
  }
  setAlert('success', 'Contact info updated!');
  header("Location: admin.php#manageContent");
  exit;
}

// ---- Save Mandatory Disclosures ----
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '') === 'saveDisclosure') {
  $conn->query("TRUNCATE TABLE mandatory_disclosures");
  
  if (isset($_POST['disc_head'])) {
      foreach ($_POST['disc_head'] as $i => $head) {
          $head = trim($head);
          if ($head === '') continue;
          
          $filePath = $_POST['disc_old_file'][$i] ?? '';
          if (!empty($_FILES['disc_file']['tmp_name'][$i]) && is_uploaded_file($_FILES['disc_file']['tmp_name'][$i])) {
              $name = time() . '_' . basename($_FILES['disc_file']['name'][$i]);
              $dest = __DIR__ . '/GMPSimages/' . $name;
              move_uploaded_file($_FILES['disc_file']['tmp_name'][$i], $dest);
              $filePath = 'GMPSimages/' . $name;
          }

          if ($filePath !== '') {
              $h = $conn->real_escape_string($head);
              $p = $conn->real_escape_string($filePath);
              $conn->query("INSERT INTO mandatory_disclosures (heading, file_path, display_order) VALUES ('$h', '$p', $i)");
          }
      }
  }
  setAlert('success', 'Disclosures updated!');
  header("Location: admin.php#manageDisclosure");
  exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Admin dashboard - Govind Madhav Public School</title>
    <?php include 'includes/meta.php'; ?>
  <style>
    .whatsapp-sticky-button { display: none !important; }
   
    .role-specific { display: none; }
  </style>
  <script>
  function toggleTeacherFields(prefix = '') {
      const role = document.getElementById(prefix + 'teacherRole').value;
      document.getElementById(prefix + 'divClassteacher').style.display = (role === 'classteacher') ? 'block' : 'none';
  }
  </script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>

<?php include 'includes/header.php'; ?>

    <?php if (! isset($_SESSION['adminUser'])): ?>
    <section class="login-container">
      <div class="login-box">
        <h2>Admin Login</h2>
        <form method="post">
          <?php if (! empty($loginError)): ?><p style="color:red;"><?= htmlspecialchars($loginError) ?></p><?php endif; ?>
          <div class="input-group"><label>Admin ID</label><input type="text" name="adminId" required></div>
          <div class="input-group"><label>Password</label><input type="password" name="adminPassword" required></div>
          <div style="margin:10px 0;"><label><input type="checkbox" name="remember"> Keep me logged in</label></div>
          <button type="submit">Login</button>
        </form>
      </div>
    </section>
    <?php else: ?>

  <div class="dashboard" id="profileSection">

    <nav class="sidebar profile-sidebar" id="adminSidebar"> <button class="close-sidebar-btn" onclick="toggleSidebar()">×</button>

      <ul>
        <li><a href="#profile">Profile</a></li>
        
        <?php if (isset($_SESSION['adminUser']['level']) && $_SESSION['adminUser']['level'] == 1): ?>
            <li><a href="#manageEvents">School Updates</a></li>
            <li><a href="#manageTeachers">Teachers </a></li>
            <li><a href="#manageStudents">Students </a></li>
            <li><a href="#manageContent">Website Content</a></li>
            <li><a href="#manageAdminsSection">Admins</a></li>
            <li><a href="#manageSubjects">Subjects</a></li>
            <li><a href="#manageClasses">Classes & Promotions</a></li>
        <?php else: ?>
            <li><a href="#manageEvents">School Updates 📢</a></li>
            <li><a href="#manageStudents">Students 🎓</a></li>
            <li><a href="#manageContent">Website Content 🌐</a></li>
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

    <?php
    if (isset($_SESSION['adminUser'])) {
        $total_students_res = $conn->query("SELECT COUNT(id) as count FROM students WHERE status = 'active'");
        $total_students = $total_students_res->fetch_assoc()['count'];

        $total_teachers_res = $conn->query("SELECT COUNT(id) as count FROM teachers");
        $total_teachers = $total_teachers_res->fetch_assoc()['count'];

        $total_classes_res = $conn->query("SELECT COUNT(id) as count FROM classes");
        $total_classes = $total_classes_res->fetch_assoc()['count'];

        $awaiting_assignment_res = $conn->query("SELECT COUNT(id) as count FROM students WHERE status = 'awaiting_stream'");
        $awaiting_assignment_count = $awaiting_assignment_res->fetch_assoc()['count'];
    }
    ?>

    <div class="content">
      <section id="profile">
        <h2>
          <?php 
          if (isset($_SESSION['adminUser']['level']) && $_SESSION['adminUser']['level'] == 1) {
              echo "Super Admin Profile";
          } else {
              echo "Admin Profile";
          }
          ?>
      </h2>
        <div class="profile-card">
            <?php 
            $profile_pic_path = !empty($_SESSION['adminUser']['profilePic']) 
                                ? htmlspecialchars($_SESSION['adminUser']['profilePic']) 
                                : 'GMPSimages/default-admin.jpg';
            ?>
            <img id="adminPic" class="profile-pic" src="<?= $profile_pic_path ?>" alt="Admin Picture"/>
            <div class="profile-details">
                <p><strong>Admin ID:</strong> <?= htmlspecialchars($_SESSION['adminUser']['loginId']) ?></p>
                <p><strong>Name:</strong> <?= htmlspecialchars($_SESSION['adminUser']['name']) ?></p>
                <p><strong>Contact:</strong> <?= htmlspecialchars($_SESSION['adminUser']['contact']) ?></p>
            </div>
        </div>
      </section>

      <section id="masterDashboard">
          <h2>Master Dashboard</h2>
          
          <div class="dashboard-stats">
              <div class="stat-card">
                  <h3>Total Students</h3>
                  <p><?= $total_students ?></p>
              </div>
              <div class="stat-card">
                  <h3>Total Teachers</h3>
                  <p><?= $total_teachers ?></p>
              </div>
              <div class="stat-card">
                  <h3>Total Classes</h3>
                  <p><?= $total_classes ?></p>
              </div>
          </div>

          <?php if ($awaiting_assignment_count > 0): ?>
          <div class="dashboard-alert">
              <h3>Action Required</h3>
              <p>There are <strong><?= $awaiting_assignment_count ?></strong> student(s) awaiting manual stream assignment.</p>
              <a href="#manualAssignment">Go to Assignment Tool</a>
          </div>
          <?php endif; ?>
      </section>

      <?php if (isset($_SESSION['adminUser']['level']) && $_SESSION['adminUser']['level'] == 1): ?>
      <section id="manageFeedback">
        <h2>Student Feedback</h2>
        <div class="post-creator-section">
            <?php
            $feed_res = $conn->query("
                SELECT f.id, f.message, f.created_at, s.name, s.login_id, c.name as class_name 
                FROM student_feedback f 
                JOIN students s ON f.student_id = s.id 
                JOIN classes c ON s.class_id = c.id 
                ORDER BY f.created_at DESC
            ");
            
            if ($feed_res->num_rows > 0):
                while($feed = $feed_res->fetch_assoc()):
            ?>
            <div class="repeater-row" style="border-left: 4px solid #ffc107; background:#fff;">
                <div style="display:flex; justify-content:space-between; align-items:start;">
                    <div>
                        <strong style="color:var(--primary-color);"><?= htmlspecialchars($feed['name']) ?></strong> 
                        <span style="font-size:0.85rem; color:#666;">(<?= htmlspecialchars($feed['class_name']) ?>)</span>
                        <div style="font-size:0.8rem; color:#999; margin-bottom:5px;"><?= date('d M Y, h:i A', strtotime($feed['created_at'])) ?></div>
                    </div>
                    <form method="post" id="delFeedForm_<?= $feed['id'] ?>">
                        <input type="hidden" name="action" value="deleteFeedback">
                        <input type="hidden" name="feedbackId" value="<?= $feed['id'] ?>">
                        <button type="button" class="remove-row-btn" onclick="confirmDelete(event, 'delFeedForm_<?= $feed['id'] ?>')">X</button>
                    </form>
                </div>
                <p style="margin:5px 0 0 0; font-size:0.95rem; line-height:1.4;"><?= nl2br(htmlspecialchars($feed['message'])) ?></p>
            </div>
            <?php endwhile; else: ?>
                <p style="text-align:center; color:#666;">No feedback received yet.</p>
            <?php endif; ?>
        </div>
      </section>
      <?php endif; ?>

      <section id="manageEvents">
        <h2>Manage School Updates</h2>
        
        <div class="post-creator-section">
            <form method="post" action="admin.php#manageEvents" enctype="multipart/form-data">
                <input type="hidden" name="action" value="saveEvents">

                <div class="post-form-group">
                    <h4>📢 Important Announcements 
                        <button type="button" class="add-row-btn" onclick="addEventRow('ann_container', 'ann', true)">+ Add New</button>
                    </h4>
                    <div id="ann_container">
                        <?php
                        $anns = $conn->query("SELECT id,title,content,image_url FROM events_announcements ORDER BY display_order ASC");
                        while($a = $anns->fetch_assoc()):
                        ?>
                        <div class="repeater-row" style="border-left: 4px solid var(--accent-color); position: relative; padding-right: 80px;">
                            <input type="hidden" name="ann_ids[]" value="<?=$a['id']?>">
                            <input type="text" name="ann_title[]" value="<?=htmlspecialchars($a['title'])?>" placeholder="Title" required>
                            <textarea name="ann_content[]" rows="2" placeholder="Content"><?=htmlspecialchars($a['content'])?></textarea>
                            <div style="margin-top:5px;">
                                <input type="file" name="ann_file[]">
                                <input type="hidden" name="ann_old_img[]" value="<?=htmlspecialchars($a['image_url'])?>">
                                <?php if($a['image_url']): ?><a href="<?=$a['image_url']?>" target="_blank" style="color:green;font-size:0.8rem;">View Image</a><?php endif; ?>
                            </div>
                            <div style="position: absolute; top: 10px; right: 10px; display: flex; gap: 5px;">
                                <button type="button" title="Remove" class="remove-row-btn" onclick="this.closest('.repeater-row').remove()" style="position:static; transform:none;">X</button>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    </div>
                </div>

                <div class="post-form-group">
                    <h4>📝 Daily Updates 
                        <button type="button" class="add-row-btn" onclick="addEventRow('upd_container', 'upd', false)">+ Add New</button>
                    </h4>
                    <div id="upd_container">
                        <?php
                        $ups = $conn->query("SELECT id,update_text,image_url FROM events_daily_updates ORDER BY display_order ASC");
                        while($u = $ups->fetch_assoc()):
                        ?>
                        <div class="repeater-row" style="border-left: 4px solid #28a745; position: relative; padding-right: 80px;">
                            <input type="hidden" name="upd_ids[]" value="<?=$u['id']?>">
                            <textarea name="upd_text[]" rows="2" placeholder="Update Text" required><?=htmlspecialchars($u['update_text'])?></textarea>
                            <div style="margin-top:5px;">
                                <input type="file" name="upd_file[]">
                                <input type="hidden" name="upd_old_img[]" value="<?=htmlspecialchars($u['image_url'])?>">
                                <?php if($u['image_url']) echo "<span style='color:green;font-size:0.8rem;'>Has Image</span>"; ?>
                            </div>
                            <div style="position: absolute; top: 10px; right: 10px; display: flex; gap: 5px;">
                                <button type="button" title="Remove" class="remove-row-btn" onclick="this.closest('.repeater-row').remove()" style="position:static; transform:none;">X</button>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    </div>
                </div>

                <div class="post-form-group">
                    <h4>📅 Upcoming Events 
                        <button type="button" class="add-row-btn" onclick="addEventRow('ev_container', 'ev', true, true)">+ Add New</button>
                    </h4>
                    <div id="ev_container">
                        <?php
                        $evs = $conn->query("SELECT id,title,DATE_FORMAT(event_date,'%Y-%m-%d') AS ed,description,image_url FROM events_upcoming ORDER BY display_order ASC");
                        while($e = $evs->fetch_assoc()):
                        ?>
                        <div class="repeater-row" style="border-left: 4px solid #dc3545; position: relative; padding-right: 80px;">
                            <input type="hidden" name="ev_ids[]" value="<?=$e['id']?>">
                            <div style="display:flex; gap:10px;">
                                <input type="text" name="ev_title[]" value="<?=htmlspecialchars($e['title'])?>" placeholder="Title" style="flex:2;" required>
                                <input type="date" name="ev_date[]" value="<?=$e['ed']?>" style="flex:1;" required>
                            </div>
                            <textarea name="ev_desc[]" rows="2" placeholder="Description"><?=htmlspecialchars($e['description'])?></textarea>
                            <div style="margin-top:5px;">
                                <input type="file" name="ev_file[]">
                                <input type="hidden" name="ev_old_img[]" value="<?=htmlspecialchars($e['image_url'])?>">
                                <?php if($e['image_url']) echo "<span style='color:green;font-size:0.8rem;'>Has Image</span>"; ?>
                            </div>
                            <div style="position: absolute; top: 10px; right: 10px; display: flex; gap: 5px;">
                                <button type="button" title="Remove" class="remove-row-btn" onclick="this.closest('.repeater-row').remove()" style="position:static; transform:none;">X</button>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    </div>
                </div>

                <button type="submit" style="width:100%; padding:15px; background:var(--primary-color); color:white; border:none; border-radius:8px; font-size:1.1rem; margin-top:10px;">
                    SAVE ALL UPDATES
                </button>
            </form>
        </div>
      </section>

      <?php if (isset($_SESSION['adminUser']['level']) && $_SESSION['adminUser']['level'] == 1): ?>
      <section id="manageTeachers">
        <h2>Manage Teachers</h2>
        
        <div class="teacher-list table-container" style="border-top: 4px solid var(--accent-color);">
            <table>
                <thead><tr><th>Name</th><th>Role</th><th>Class/Subject</th><th>Action</th></tr></thead>
                <tbody>
                    <?php
                    $sql = "SELECT t.id, t.name, c.name as class_name, GROUP_CONCAT(s.name) as subjects 
                            FROM teachers t 
                            LEFT JOIN classes c ON t.assigned_class_id = c.id 
                            LEFT JOIN teacher_subjects ts ON t.id = ts.teacher_id
                            LEFT JOIN subjects s ON ts.subject_code = s.code
                            GROUP BY t.id";
                    $result = $conn->query($sql);
                    while ($row = $result->fetch_assoc()):
                        $role = $row['class_name'] ? "Classteacher ({$row['class_name']})" : "Subject Teacher";
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($row['name']) ?></td>
                        <td><?= $role ?></td>
                        <td><?= htmlspecialchars($row['subjects'] ?? 'None') ?></td>
                        <td>
                            <a href="?viewTeacher=<?= $row['id'] ?>#manageTeachers">
                                <button>Edit</button>
                            </a>
                            <form method="post" style="display:inline;" id="delTeachForm_<?= $row['id'] ?>">
                                <input type="hidden" name="action" value="deleteTeacher">
                                <input type="hidden" name="teacherIdToDelete" value="<?= $row['id'] ?>">
                                <button type="button" onclick="confirmDelete(event, 'delTeachForm_<?= $row['id'] ?>')">Delete</button>
                            </form>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>

        <?php if (isset($_GET['viewTeacher'])):
            $view_id = (int)$_GET['viewTeacher'];
            $stmt = $conn->prepare("SELECT t.*, ts.subject_code FROM teachers t LEFT JOIN teacher_subjects ts ON t.id = ts.teacher_id WHERE t.id = ?");
            $stmt->bind_param("i", $view_id);
            $stmt->execute();
            $teacher_to_edit = $stmt->get_result()->fetch_assoc();
            
            if ($teacher_to_edit):
                $is_classteacher = !empty($teacher_to_edit['assigned_class_id']);
        ?>
        <div class="profile-editor" style="margin-top: 2rem; border-top: 2px solid #ccc; padding-top: 1.5rem;">
            <h3>Editing Teacher: <?= htmlspecialchars($teacher_to_edit['name']) ?></h3>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="updateTeacher">
                <input type="hidden" name="teacherIdToUpdate" value="<?= $teacher_to_edit['id'] ?>">
                <input type="hidden" name="existingProfilePic" value="<?= htmlspecialchars($teacher_to_edit['profile_pic']) ?>">

                <div class="profile-card">
                    <img class="profile-pic" src="<?= htmlspecialchars($teacher_to_edit['profile_pic'] ?: 'GMPSimages/default-admin.jpg') ?>" >
                    <div class="profile-details">
                        <p><strong>Change Picture:</strong> <input type="file" name="editProfilePic" accept="image/*"></p>
                        <p><strong>Name:</strong> <input type="text" name="editTeacherName" value="<?= htmlspecialchars($teacher_to_edit['name']) ?>" required></p>
                        <p><strong>Teacher ID:</strong> <input type="text" name="editTeacherId" value="<?= htmlspecialchars($teacher_to_edit['login_id']) ?>" required></p>
                        <p><strong>Contact:</strong> <input type="text" name="editTeacherContact" value="<?= htmlspecialchars($teacher_to_edit['contact']) ?>" required></p>
                        
                        <div class="input-group">
                            <label>Role</label>
                            <select id="edit_teacherRole" name="editTeacherRole" onchange="toggleTeacherFields('edit_')" required>
                                <option value="classteacher" <?= $is_classteacher ? 'selected' : '' ?>>Classteacher</option>
                                <option value="subjectteacher" <?= !$is_classteacher ? 'selected' : '' ?>>Subject Teacher</option>
                            </select>
                        </div>

                        <div class="input-group" id="edit_divClassteacher" style="display: <?= $is_classteacher ? 'block' : 'none' ?>;">
                            <label>Assign Class</label>
                            <select name="editAssignedClass">
                                <option value="">Select Class...</option>
                                <?php
                                $cRes = $conn->query("SELECT id, name FROM classes ORDER BY id");
                                while($c = $cRes->fetch_assoc()) {
                                    $sel = ($teacher_to_edit['assigned_class_id'] == $c['id']) ? 'selected' : '';
                                    echo "<option value=\"{$c['id']}\" $sel>{$c['name']}</option>";
                                }
                                ?>
                            </select>
                        </div>

                        <div class="input-group">
                            <label>Main Subject</label>
                            <select name="editAssignedSubject">
                                <option value="">Select Subject...</option>
                                <?php
                                $sRes = $conn->query("SELECT code, name FROM subjects ORDER BY name");
                                while($s = $sRes->fetch_assoc()) {
                                    $sel = ($teacher_to_edit['subject_code'] == $s['code']) ? 'selected' : '';
                                    echo "<option value=\"{$s['code']}\" $sel>{$s['name']}</option>";
                                }
                                ?>
                            </select>
                        </div>

                        <p><strong>New Password:</strong> <input type="password" name="editTeacherPassword" placeholder="Leave blank to keep unchanged"></p>
                    </div>
                </div>
                <div style="text-align: right; margin-top: 1rem;">
                    <button type="submit">Save Changes</button>
                </div>
            </form>
        </div>
        <?php endif; endif; ?>

        <div class="add-teacher">
            <h3>Add New Teacher</h3>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="addTeacher">
                
                <div class="input-group"><label>Name</label><input type="text" name="newTeacherName" required/></div>
                <div class="input-group"><label>Teacher ID</label><input type="text" name="newTeacherId" required/></div>
                <div class="input-group"><label>Password</label><input type="password" name="newTeacherPassword" required/></div>
                <div class="input-group"><label>Contact</label><input type="text" name="newTeacherContact" required/></div>
                
                <div class="input-group">
                    <label>Role</label>
                    <select id="teacherRole" name="teacherRole" onchange="toggleTeacherFields()" required>
                        <option value="" disabled selected>Select Role</option>
                        <option value="classteacher">Classteacher</option>
                        <option value="subjectteacher">Subject Teacher</option>
                    </select>
                </div>

                <div class="input-group role-specific" id="divClassteacher">
                    <label>Assign Class</label>
                    <select name="assignedClass">
                        <option value="">Select Class...</option>
                        <?php
                        $cRes = $conn->query("SELECT id, name FROM classes ORDER BY id");
                        while($c = $cRes->fetch_assoc()) echo "<option value=\"{$c['id']}\">{$c['name']}</option>";
                        ?>
                    </select>
                </div>

                <div class="input-group">
                    <label>Main Subject</label>
                    <select name="assignedSubject">
                        <option value="">Select Subject...</option>
                        <?php
                        $sRes = $conn->query("SELECT code, name FROM subjects ORDER BY name");
                        while($s = $sRes->fetch_assoc()) echo "<option value=\"{$s['code']}\">{$s['name']}</option>";
                        ?>
                    </select>
                </div>

                <div class="input-group"><label>Profile Picture</label><input type="file" name="profile_pic" accept="image/*"></div>
                <button type="submit">Add Teacher</button>
            </form>
        </div>
      </section>
      <?php endif; ?>

      <section id="manageStudents">
        <h2>Manage Students</h2>
        <div class="profile-management-filters">
            <form method="get" action="admin.php#manageStudents">
                <label for="profileClass">Select Class:</label>
                <select id="profileClass" name="profileClass" onchange="this.form.submit()">
                    <option value="" disabled <?= !isset($_GET['profileClass']) ? 'selected' : '' ?>>Select a Class...</option>
                    <?php
                      $cRes = $conn->query("SELECT id, name FROM classes ORDER BY id");
                      while($c = $cRes->fetch_assoc()) {
                        $sel = ($_GET['profileClass'] ?? '') == $c['id'] ? ' selected' : '';
                        echo "<option value=\"{$c['id']}\"{$sel}>".htmlspecialchars($c['name'])."</option>";
                      }
                    ?>
                </select>
                <button type="submit">Show</button>
            </form>
        </div>
        
        <?php if (!empty($_GET['profileClass'])):
            $chosenClass = (int)$_GET['profileClass'];
            $result = $conn->query("SELECT id, login_id, name, contact FROM students WHERE class_id = $chosenClass AND status = 'active'");        ?>
        <div class="student-list table-container">
            <table>
                <thead>
                    <tr>
                        <th>Student ID</th>
                        <th>Student Name</th>
                        <th>Contact</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result && $result->num_rows > 0):
                        while ($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['login_id']) ?></td>
                        <td><?= htmlspecialchars($row['name']) ?></td>
                        <td><?= htmlspecialchars($row['contact']) ?></td>
                        <td>
                            <a href="?viewStudent=<?= $row['id'] ?>&profileClass=<?= $chosenClass ?>#manageStudents">
                                <button>Edit</button>
                            </a>
                        </td>
                    </tr>
                    <?php endwhile; else: ?>
                        <tr><td colspan="4">No students found in this class.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if (isset($_GET['viewStudent'])):
            $view_id = (int)$_GET['viewStudent'];
            $stmt = $conn->prepare("SELECT s.*, c.name as class_name FROM students s JOIN classes c ON s.class_id = c.id WHERE s.id = ?");
            $stmt->bind_param("i", $view_id);
            $stmt->execute();
            $student_to_edit = $stmt->get_result()->fetch_assoc();
            
            if ($student_to_edit):
        ?>
        <div class="profile-editor" style="margin-top: 2rem; border-top: 2px solid #ccc; padding-top: 1.5rem;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <h3>Editing Profile: <?= htmlspecialchars($student_to_edit['name']) ?></h3>
                <form method="post" id="delStudForm">
                    <input type="hidden" name="action" value="deleteStudent">
                    <input type="hidden" name="studentIdToDelete" value="<?= $student_to_edit['id'] ?>">
                    <button type="button" onclick="confirmDelete(event, 'delStudForm')" style="background-color: #dc3545;">Delete Student</button>
                </form>
            </div>
            
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="updateStudent">
                <input type="hidden" name="studentIdToUpdate" value="<?= $student_to_edit['id'] ?>">
                <input type="hidden" name="existingProfilePic" value="<?= htmlspecialchars($student_to_edit['profile_pic']) ?>">

                <div class="profile-card">
                    <img class="profile-pic" src="<?= htmlspecialchars($student_to_edit['profile_pic'] ?: 'GMPSimages/default_student.png') ?>" alt="Student Picture"/>
                    <div class="profile-details">
                        <p><strong>Change Picture:</strong> <input type="file" name="editProfilePic" accept="image/*"></p>
                        <p><strong>Name:</strong> <input type="text" name="editStudentName" value="<?= htmlspecialchars($student_to_edit['name']) ?>"></p>
                        <p><strong>DOB:</strong> <input type="date" name="editDOB" value="<?= htmlspecialchars($student_to_edit['dob'] ?? '') ?>"></p>
                        <p><strong>Roll No:</strong> <input type="number" name="editRollNo" value="<?= htmlspecialchars($student_to_edit['roll_no'] ?? '') ?>"></p>
                        <p><strong>Father's Name:</strong> <input type="text" name="editFatherName" value="<?= htmlspecialchars($student_to_edit['father_name']) ?>"></p>
                        <p><strong>Mother's Name:</strong> <input type="text" name="editMotherName" value="<?= htmlspecialchars($student_to_edit['mother_name']) ?>"></p>
                        <p><strong>Address:</strong> <textarea name="editAddress" rows="2"><?= htmlspecialchars($student_to_edit['address']) ?></textarea></p>
                        <p><strong>Contact:</strong> <input type="text" name="editContact" value="<?= htmlspecialchars($student_to_edit['contact']) ?>"></p>
                        <p><strong>Set New Password:</strong> <input type="password" name="editStudentPassword" placeholder="Leave blank to keep unchanged"></p>
                        <p><strong>Class:</strong> 
                            <select name="editStudentClass">
                                <?php 
                                $cRes = $conn->query("SELECT id, name FROM classes ORDER BY id");
                                while($c = $cRes->fetch_assoc()): ?>
                                    <option value="<?= $c['id'] ?>" <?= ($c['id'] == $student_to_edit['class_id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($c['name']) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </p>
                        <p><strong>Admission Year:</strong> <input type="number" name="editAdmissionYear" value="<?= htmlspecialchars($student_to_edit['admission_year']) ?>"></p>
                    </div>
                </div>

                <h3>Attendance Record</h3>
                <div class="table-container">
                    <table>
                        <thead><tr><th>Month</th><th>Present</th><th>Absent</th><th>%</th></tr></thead>
                        <tbody>
                        <?php
                        $academic_months = [4 => 'April', 5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December', 1 => 'January', 2 => 'February', 3 => 'March'];
                        $att_res = $conn->query("SELECT month, days_present, days_absent FROM attendance WHERE student_id=$view_id AND year=YEAR(CURDATE())");
                        $attendance_map = [];
                        if($att_res) { while ($row = $att_res->fetch_assoc()) { $attendance_map[(int)$row['month']] = $row; } }

                        foreach ($academic_months as $month_num => $month_name):
                            $data = $attendance_map[$month_num] ?? ['days_present' => '', 'days_absent' => ''];
                            $present = is_numeric($data['days_present']) ? $data['days_present'] : '';
                            $absent = is_numeric($data['days_absent']) ? $data['days_absent'] : '';
                            $total_days = ($present !== '' && $absent !== '') ? $present + $absent : 0;
                            $percentage = $total_days > 0 ? round(($present / $total_days) * 100, 1) . '%' : '--';
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($month_name) ?></td>
                            <td><input type="number" name="attendance[<?= $month_num ?>][present]" value="<?= htmlspecialchars($present) ?>" min="0"></td>
                            <td><input type="number" name="attendance[<?= $month_num ?>][absent]" value="<?= htmlspecialchars($absent) ?>" min="0"></td>
                            <td><?= htmlspecialchars($percentage) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <h3>Exam Results</h3>
                <div class="table-container">
                  <?php
                  $all_subjects_res = $conn->query("SELECT code, name FROM subjects ORDER BY name");
                  $all_subjects = [];
                  while ($subject = $all_subjects_res->fetch_assoc()) {
                      $all_subjects[] = $subject;
                  }

                  $exams_query = $conn->query("SELECT id, name, max_marks FROM exams ORDER BY id");
                  while ($exam = $exams_query->fetch_assoc()):
                      $exam_id = (int)$exam['id'];
                  ?>
                      <h4><?= htmlspecialchars($exam['name']) ?></h4>
                      <table>
                          <thead><tr><th>Subject</th><th>Marks Obtained</th><th>Total Marks</th></tr></thead>
                          <tbody>
                          <?php
                          $marks_query = $conn->query("
                              SELECT s.name AS subject_name, s.code AS subject_code, m.marks_obtained 
                              FROM marks m
                              INNER JOIN subjects s ON m.subject_code = s.code
                              WHERE m.student_id = $view_id AND m.exam_id = $exam_id
                          ");
                          while ($mark = $marks_query->fetch_assoc()):
                          ?>
                          <tr>
                              <td><?= htmlspecialchars($mark['subject_name']) ?></td>
                              <td><input type="number" name="marks[<?= $exam_id ?>][<?= $mark['subject_code'] ?>]" value="<?= htmlspecialchars($mark['marks_obtained'] ?? '') ?>" min="0" max="<?= $exam['max_marks'] ?>"></td>
                              <td><?= htmlspecialchars($exam['max_marks']) ?></td>
                          </tr>
                          <?php endwhile; ?>

                          <tr>
                              <td>
                                  <select name="marks[<?= $exam_id ?>][new_subject_code][]">
                                      <option value="" selected disabled>Add another subject...</option>
                                      <?php foreach ($all_subjects as $subject): ?>
                                          <option value="<?= htmlspecialchars($subject['code']) ?>">
                                              <?= htmlspecialchars($subject['name']) ?>
                                          </option>
                                      <?php endforeach; ?>
                                  </select>
                              </td>
                              <td><input type="number" name="marks[<?= $exam_id ?>][new_subject_marks][]" placeholder="Enter marks" min="0" max="<?= $exam['max_marks'] ?>"></td>
                              <td><?= htmlspecialchars($exam['max_marks']) ?></td>
                          </tr>
                          </tbody>
                      </table>
                  <?php endwhile; ?>
                </div>
                
                <div style="text-align: right; margin-top: 1rem;">
                    <button type="submit">Save All Changes</button>
                </div>
            </form>
        </div>
        <?php endif; endif; ?>

        <div class="add-student">
            <h3>Add New Student</h3>
            <form id="addStudentForm" method="post" enctype="multipart/form-data">
                <div class="input-group">
                  <label for="newStudentId">Student ID</label>
                  <input type="text" id="newStudentId" name="newStudentId" required/>
                </div>
                <div class="input-group">
                  <label for="newStudentPassword">Password</label>
                  <input type="password" id="newStudentPassword" name="newStudentPassword" required/>
                </div>
                <div class="input-group">
                  <label for="newStudentName">Name</label>
                  <input type="text" id="newStudentName" name="newStudentName" required/>
                </div>
                <div class="input-group">
                  <label for="newDOB">Date of Birth</label>
                  <input type="date" id="newDOB" name="newDOB"/>
                </div>
                <div class="input-group">
                  <label for="newRollNo">Roll Number</label>
                  <input type="number" id="newRollNo" name="newRollNo" placeholder="Class Roll No."/>
                </div>
                <div class="input-group">
                  <label for="newFatherName">Father’s Name</label>
                  <input type="text" id="newFatherName" name="newFatherName"/>
                </div>
                <div class="input-group">
                  <label for="newMotherName">Mother’s Name</label>
                  <input type="text" id="newMotherName" name="newMotherName"/>
                </div>
                <div class="input-group">
                  <label for="newAddress">Address</label>
                  <textarea id="newAddress" name="newAddress" rows="2"></textarea>
                </div>
                <div class="input-group">
                  <label for="newContact">Contact</label>
                  <input type="text" id="newContact" name="newContact"/>
                </div>
                <div class="input-group">
                  <label for="newStudentClass">Class</label>
                  <select id="newStudentClass" name="newStudentClass" required>
                    <option value="" disabled selected>Select a Class</option>
                    <?php
                      $cRes = $conn->query("SELECT id, name FROM classes ORDER BY id");
                      while($c = $cRes->fetch_assoc()) {
                        echo "<option value=\"{$c['id']}\">".htmlspecialchars($c['name'])."</option>";
                      }
                    ?>
                </select>
                </div>
                <div class="input-group">
                  <label for="newAdmissionYear">Admission Year</label>
                  <input type="number" id="newAdmissionYear" name="newAdmissionYear" value="<?= date('Y') ?>"/>
                </div>
                <div class="input-group">
                  <label for="newStudentPic">Profile Picture:</label>
                  <input type="file" id="newStudentPic" name="profile_pic"  accept="image/*">
                </div>
                <input type="hidden" name="action" value="addStudent">
                <button type="submit">Add Student</button>
              </form>
        </div>
      </section>

      <section id="manageContent">
        <h2>Manage Website Content</h2>
        <div class="content-editor"  style="border-top: 4px solid var(--accent-color);">
          <h3>Home Page Content</h3>
          <form method="post" enctype="multipart/form-data" action="admin.php#manageContent">
            <input type="hidden" name="action" value="saveHome">

            <h4>Slideshow</h4>
            <table>
              <tr><th>Image URL</th><th>Alt Text</th><th>Order</th></tr>
              <?php
                $slides = $conn->query("SELECT img_url,alt_text,display_order FROM home_slideshow ORDER BY display_order");
                while($s = $slides->fetch_assoc()):
              ?>
              <tr>
              <td>
                <input type="file" name="slide_file[]" accept="image/*">
                <input type="hidden" name="slide_img[]" value="<?=htmlspecialchars($s['img_url'])?>">
              </td>
                <td><input name="slide_alt[]" value="<?=htmlspecialchars($s['alt_text'])?>"></td>
                <td><input name="slide_order[]" type="number" value="<?=$s['display_order']?>" style="width:60px"></td>
              </tr>
              <?php endwhile; ?>
              <tr>
              <td>
                <input type="file" name="slide_file[]" accept="image/*">
                <input type="hidden" name="slide_img[]" value="">
              </td>
                <td><input name="slide_alt[]" placeholder="new alt"></td>
                <td><input name="slide_order[]" type="number" placeholder="order"></td>
              </tr>
            </table>

            <h4>Admin Thoughts</h4>
            <table>
              <tr><th>Name</th><th>Position</th><th>Image URL</th><th>Quote</th></tr>
              <?php
                $ats = $conn->query("SELECT name,position,image_url,quote FROM home_administration_thoughts");
                while($a = $ats->fetch_assoc()):
              ?>
              <tr>
                <td><input name="adm_name[]" value="<?=htmlspecialchars($a['name'])?>"></td>
                <td><input name="adm_pos[]"  value="<?=htmlspecialchars($a['position'])?>"></td>
                <td>
                  <input type="file" name="adm_file[]" accept="image/*">
                  <input type="hidden" name="adm_img[]" value="<?=htmlspecialchars($a['image_url'])?>">
                </td>
                <td><input name="adm_quote[]" value="<?=htmlspecialchars($a['quote'])?>" style="width:300px"></td>
              </tr>
              <?php endwhile; ?>
              <tr><td colspan="4"><em>Blank row = new entry</em></td></tr>
              <tr>
                <td><input name="adm_name[]"  placeholder="new name"></td>
                <td><input name="adm_pos[]"   placeholder="new pos"></td>
                <td>
                  <input type="file" name="adm_file[]" accept="image/*">
                  <input type="hidden" name="adm_img[]" value="">
                </td>
                <td><input name="adm_quote[]" placeholder="new quote"></td>
              </tr>
            </table>

            <h4>Gallery Images</h4>
            <table>
              <tr><th>Image URL</th><th>Alt Text</th></tr>
              <?php
                $gal = $conn->query("SELECT image_url,alt_text FROM home_gallery");
                while($g = $gal->fetch_assoc()):
              ?>
              <tr>
              <td>
                <input type="file" name="gal_file[]" accept="image/*">
                <input type="hidden" name="gal_img[]" value="<?=htmlspecialchars($g['image_url'])?>">
              </td>
                <td><input name="gal_alt[]" value="<?=htmlspecialchars($g['alt_text'])?>"></td>
              </tr>
              <?php endwhile; ?>
              <tr><td colspan="2"><em>Blank row = new image</em></td></tr>
              <tr>
              <td>
                <input type="file" name="gal_file[]" accept="image/*">
                <input type="hidden" name="gal_img[]" value="">
              </td>
                <td><input name="gal_alt[]" placeholder="new alt"></td>
              </tr>
            </table>

            <h4>Video & Quote</h4>
            <?php
              $vq = $conn->query("SELECT heading,paragraph,video_url FROM home_video_quote LIMIT 1")->fetch_assoc();
            ?>
            <label>Heading:<br>
              <input name="vq_heading" value="<?=htmlspecialchars($vq['heading'])?>" style="width:100%">
            </label><br>
            <label>Paragraph:<br>
              <textarea name="vq_para" rows="2" style="width:100%"><?=htmlspecialchars($vq['paragraph'])?></textarea>
            </label><br>
            <label>Embed URL:<br>
              <input name="vq_url" value="<?=htmlspecialchars($vq['video_url'])?>" style="width:100%">
            </label>

            <h4>Statistics</h4>
            <table>
              <tr><th>Value</th><th>Label</th></tr>
              <?php
                $st = $conn->query("SELECT value,label FROM home_statistics");
                while($r = $st->fetch_assoc()):
              ?>
              <tr>
                <td><input name="stat_val[]" value="<?=htmlspecialchars($r['value'])?>"></td>
                <td><input name="stat_label[]" value="<?=htmlspecialchars($r['label'])?>"></td>
              </tr>
              <?php endwhile; ?>
              <tr><td colspan="2"><em>Blank row = new stat</em></td></tr>
              <tr>
                <td><input name="stat_val[]" placeholder="e.g. 123+"></td>
                <td><input name="stat_label[]" placeholder="e.g. Courses"></td>
              </tr>
            </table>

            <div class="table-actions">
              <button type="submit">Save All Home Content</button>
            </div>
          </form>
        </div>

        <div class="content-editor" id="academicsEditor"  style="border-top: 4px solid var(--accent-color);">
          <h3>Academics Page Content</h3>
          <form method="post" enctype="multipart/form-data" action="admin.php#manageContent">
            <input type="hidden" name="action" value="saveAcademics">

            <h4>Curriculum Overview</h4>
            <table>
              <tr><th>Subject Bullet</th></tr>
              <?php
                $cur = $conn->query("SELECT bullet FROM academics_curriculum");
                while($r = $cur->fetch_assoc()):
              ?>
              <tr>
                <td><input name="curr_bullet[]" value="<?=htmlspecialchars($r['bullet'])?>" style="width:100%"></td>
              </tr>
              <?php endwhile; ?>
              <tr>
                <td><input name="curr_bullet[]" placeholder="New bullet…" style="width:100%"></td>
              </tr>
            </table>

            <h4>Topper Lists</h4>
            <table>
              <tr><th>Class Desc</th><th>Student Name</th><th>Image</th></tr>
              <?php
                $tops = $conn->query("SELECT class_desc,student_name,img_url FROM academics_toppers");
                while($t = $tops->fetch_assoc()):
              ?>
              <tr>
                <td><input name="top_class[]" value="<?=htmlspecialchars($t['class_desc'])?>"></td>
                <td><input name="top_name[]"  value="<?=htmlspecialchars($t['student_name'])?>"></td>
                <td>
                  <input type="file" name="top_file[]" accept="image/*">
                  <input type="hidden" name="top_old_img[]" value="<?=htmlspecialchars($t['img_url'])?>">
                </td>
              </tr>
              <?php endwhile; ?>
              <tr>
                <td><input name="top_class[]" placeholder="e.g. Class 10 Toppers"></td>
                <td><input name="top_name[]"  placeholder="Name & %"></td>
                <td>
                  <input type="file" name="top_file[]" accept="image/*">
                  <input type="hidden" name="top_old_img[]" value="">
                </td>
              </tr>
            </table>

            <h4>Facilities</h4>
            <table>
              <tr><th>Image</th><th>Description</th><th>Reverse?</th></tr>
              <?php
                $fac = $conn->query("SELECT img_url,description,is_reverse FROM academics_facilities");
                $facIndex = 0;  
                while($f = $fac->fetch_assoc()):
              ?>
              <tr>
                <td>
                  <input type="file" name="fac_file[]" accept="image/*">
                  <input type="hidden" name="fac_old_img[]" value="<?=htmlspecialchars($f['img_url'])?>">
                </td>
                <td><input name="fac_desc[]" value="<?=htmlspecialchars($f['description'])?>" style="width:300px"></td>
                <td>
                  <input type="checkbox" name="fac_rev[<?= $facIndex ?>]" <?= $f['is_reverse'] ? 'checked' : '' ?>>
                </td>
              </tr>
              <?php 
                $facIndex++; 
                endwhile;
              ?>
              <tr>
                <td>
                  <input type="file" name="fac_file[]" accept="image/*">
                  <input type="hidden" name="fac_old_img[]" value="">
                </td>
                <td>
                  <input name="fac_desc[]" placeholder="Description" style="width:300px">
                </td>
                <td>
                  <input type="checkbox" name="fac_rev[<?= $facIndex ?>]">
                </td>
              </tr>
            </table>

            <div class="table-actions">
              <button type="submit">Save Academics Content</button>
            </div>
          </form>
        </div>

        <div class="content-editor" id="admissionsEditor" style="border-top: 4px solid var(--accent-color);">
          <h3>Admissions Page Content</h3>
          <form method="post" action="admin.php#manageContent">
            <input type="hidden" name="action" value="saveAdmissions">

            <h4>Admissions Guidelines</h4>
            <table>
              <tr><th><strong>Label</strong></th><th>Description</th></tr>
              <?php
                $g = $conn->query("SELECT id,label,description FROM admissions_guidelines ORDER BY display_order");
                while($row = $g->fetch_assoc()):
              ?>
              <tr>
                <td><input name="guid_label[<?=$row['id']?>]" value="<?=htmlspecialchars($row['label'])?>" style="width:150px"></td>
                <td><input name="guid_desc[<?=$row['id']?>]" value="<?=htmlspecialchars($row['description'])?>" style="width:100%"></td>
              </tr>
              <?php endwhile; ?>
              <tr>
                <td><input name="guid_label[new][]" placeholder="Label" style="width:150px"></td>
                <td><input name="guid_desc[new][]" placeholder="Description" style="width:100%"></td>
              </tr>
            </table>

            <h4>Admissions Process</h4>
            <table>
              <tr><th>Step Description</th></tr>
              <?php
                $p = $conn->query("SELECT id,description FROM admissions_process ORDER BY step_order");
                while($row = $p->fetch_assoc()):
              ?>
              <tr>
                <td><input name="proc_desc[<?=$row['id']?>]" value="<?=htmlspecialchars($row['description'])?>" style="width:100%"></td>
              </tr>
              <?php endwhile; ?>
              <tr>
                <td><input name="proc_desc[new][]" placeholder="New step…" style="width:100%"></td>
              </tr>
            </table>

            <h4>Important Dates</h4>
            <table>
              <tr><th><strong>Label</strong></th><th>Date</th></tr>
              <?php
                $d = $conn->query("SELECT id,label,date_value FROM admissions_dates ORDER BY display_order");
                while($row = $d->fetch_assoc()):
              ?>
              <tr>
                <td><input name="date_label[<?=$row['id']?>]" value="<?=htmlspecialchars($row['label'])?>" style="width:150px"></td>
                <td><input type="date" name="date_val[<?=$row['id']?>]" value="<?=$row['date_value']?>"></td>
              </tr>
              <?php endwhile; ?>
              <tr>
                <td><input name="date_label[new][]" placeholder="Label" style="width:150px"></td>
                <td><input type="date" name="date_val[new][]" value=""></td>
              </tr>
            </table>

            <h4>Fee Structure</h4>
            <table>
              <tr><th>Class</th><th>Tuition</th><th>Registration</th><th>Misc</th></tr>
              <?php
                $f = $conn->query("SELECT id,class_name,tuition_fee,registration_fee,misc_fee FROM fee_structure ORDER BY display_order");
                while($row = $f->fetch_assoc()):
              ?>
              <tr>
                <td><input name="fee_class[<?=$row['id']?>]" value="<?=htmlspecialchars($row['class_name'])?>" style="width:100px"></td>
                <td><input name="fee_tuit[<?=$row['id']?>]" value="<?=$row['tuition_fee']?>" style="width:80px"></td>
                <td><input name="fee_reg[<?=$row['id']?>]" value="<?=$row['registration_fee']?>" style="width:80px"></td>
                <td><input name="fee_misc[<?=$row['id']?>]" value="<?=$row['misc_fee']?>" style="width:80px"></td>
              </tr>
              <?php endwhile; ?>
              <tr>
                <td><input name="fee_class[new][]" placeholder="Class" style="width:100px"></td>
                <td><input name="fee_tuit[new][]" placeholder="0.00" style="width:80px"></td>
                <td><input name="fee_reg[new][]" placeholder="0.00" style="width:80px"></td>
                <td><input name="fee_misc[new][]" placeholder="0.00" style="width:80px"></td>
              </tr>
            </table>

            <h4>Admissions FAQ</h4>
            <table>
              <tr><th>Question</th><th>Answer</th></tr>
              <?php
                $q = $conn->query("SELECT id,question,answer FROM admissions_faq ORDER BY display_order");
                while($row = $q->fetch_assoc()):
              ?>
              <tr>
                <td><input name="faq_q[<?=$row['id']?>]" value="<?=htmlspecialchars($row['question'])?>" style="width:200px"></td>
                <td><input name="faq_a[<?=$row['id']?>]" value="<?=htmlspecialchars($row['answer'])?>" style="width:100%"></td>
              </tr>
              <?php endwhile; ?>
              <tr>
                <td><input name="faq_q[new][]" placeholder="Question…" style="width:200px"></td>
                <td><input name="faq_a[new][]" placeholder="Answer…" style="width:100%"></td>
              </tr>
            </table>

            <div class="table-actions">
              <button type="submit">Save Admissions Content</button>
            </div>
          </form>
        </div>

        <div class="content-editor" id="contactEditor" style="border-top: 4px solid var(--accent-color);">
          <h3>Contact Page Content</h3>
          <form method="post" action="admin.php#manageContent">
            <input type="hidden" name="action" value="saveContact">

            <table>
              <tr><th>Method</th><th>Value</th></tr>
              <?php
                $cm = $conn->query("SELECT id,method,value FROM contact_methods ORDER BY display_order");
                while($c = $cm->fetch_assoc()):
              ?>
              <tr>
                <td><?= ucfirst($c['method']) ?></td>
                <td>
                  <input type="hidden" name="id[]" value="<?=$c['id']?>">
                  <input name="value[]" value="<?=htmlspecialchars($c['value'])?>" style="width:100%">
                </td>
              </tr>
              <?php endwhile; ?>
              <tr>
                <td>
                  <select name="method_new">
                    <option value="phone">Phone</option>
                    <option value="email">Email</option>
                    <option value="whatsapp">WhatsApp</option>
                  </select>
                </td>
                <td><input name="value_new" placeholder="New value…" style="width:100%"></td>
              </tr>
            </table>

            <div class="table-actions">
              <button type="submit">Save Contact Content</button>
            </div>
          </form>
        </div>
      </section>

      <section id="manageDisclosure">
        <h2>Manage Mandatory Disclosure</h2>
        <div class="post-creator-section">
            <form method="post" action="admin.php#manageDisclosure" enctype="multipart/form-data">
                <input type="hidden" name="action" value="saveDisclosure">
                
                <div class="post-form-group">
                    <h4>Documents <button type="button" class="add-row-btn" onclick="addDisclosureRow()">+</button></h4>
                    <div id="disclosure_container">
                        <?php
                        $discs = $conn->query("SELECT heading, file_path FROM mandatory_disclosures ORDER BY display_order");
                        while($d = $discs->fetch_assoc()):
                        ?>
                        <div class="repeater-row">
                            <input type="text" name="disc_head[]" value="<?=htmlspecialchars($d['heading'])?>" placeholder="Document Heading">
                            <div style="margin-top:5px; display:flex; align-items:center; gap:10px;">
                                <input type="file" name="disc_file[]" accept=".pdf,.jpg,.png">
                                <input type="hidden" name="disc_old_file[]" value="<?=htmlspecialchars($d['file_path'])?>">
                                <a href="<?=$d['file_path']?>" target="_blank" style="color:blue; font-size:0.8rem;">View Current</a>
                            </div>
                            <button type="button" class="remove-row-btn" onclick="this.parentElement.remove()">X</button>
                        </div>
                        <?php endwhile; ?>
                    </div>
                </div>
                <button type="submit" style="width:100%; padding:15px; background:var(--primary-color); color:white; border:none; border-radius:8px;">SAVE DOCUMENTS</button>
            </form>
        </div>
      </section>

      <?php if (isset($_SESSION['adminUser']['level']) && $_SESSION['adminUser']['level'] == 1): ?>
      <section id="manageAdminsSection">
        <h2>Manage Admins</h2>
        <div class="table-container">
            <table>
                <thead>
                    <tr><th>Name</th><th>Admin ID</th><th>Level</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php
                    $res = $conn->query("SELECT id, name, login_id, level FROM admins ORDER BY id");
                    while ($admin = $res->fetch_assoc()):
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($admin['name']) ?></td>
                        <td><?= htmlspecialchars($admin['login_id']) ?></td>
                        <td><?= htmlspecialchars($admin['level']) ?></td>
                        <td>
                            <a href="?viewAdmin=<?= $admin['id'] ?>#manageAdminsSection">
                                <button>Edit</button>
                            </a>
                            
                            <?php if ($admin['id'] != $_SESSION['adminUser']['id']): ?>
                              <form method="post" style="display:inline;" id="delAdminForm_<?= $admin['id'] ?>">
                                <input type="hidden" name="action" value="deleteAdmin">
                                <input type="hidden" name="adminIdToDelete" value="<?= $admin['id'] ?>">
                                <button type="button" onclick="confirmDelete(event, 'delAdminForm_<?= $admin['id'] ?>')" style="background-color: #dc3545;">Delete</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>

        <?php if (isset($_GET['viewAdmin'])):
            $view_id = (int)$_GET['viewAdmin'];
            $stmt = $conn->prepare("SELECT * FROM admins WHERE id = ?");
            $stmt->bind_param("i", $view_id);
            $stmt->execute();
            $admin_to_edit = $stmt->get_result()->fetch_assoc();
            
            if ($admin_to_edit):
        ?>
        <div class="profile-editor" style="margin-top: 2rem; border-top: 2px solid #ccc; padding-top: 1.5rem;">
            <h3>Editing Admin: <?= htmlspecialchars($admin_to_edit['name']) ?></h3>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="updateAdmin">
                <input type="hidden" name="adminIdToUpdate" value="<?= $admin_to_edit['id'] ?>">
                <input type="hidden" name="existingProfilePic" value="<?= htmlspecialchars($admin_to_edit['profile_pic']) ?>">

                <div class="profile-card">
                    <img class="profile-pic" src="<?= htmlspecialchars($admin_to_edit['profile_pic'] ?: 'GMPSimages/default-admin.jpg') ?>" />
                    <div class="profile-details">
                        <p><strong>Change Picture:</strong> <input type="file" name="editProfilePic" accept="image/*"></p>
                        <p><strong>Name:</strong> <input type="text" name="editAdminName" value="<?= htmlspecialchars($admin_to_edit['name']) ?>" required></p>
                        <p><strong>Admin ID:</strong> <input type="text" name="editAdminId" value="<?= htmlspecialchars($admin_to_edit['login_id']) ?>" required></p>
                        <p><strong>Contact:</strong> <input type="text" name="editAdminContact" value="<?= htmlspecialchars($admin_to_edit['contact']) ?>" required></p>
                        <p><strong>Level:</strong> 
                            <select name="editAdminLevel" required>
                                <option value="1" <?= $admin_to_edit['level'] == 1 ? 'selected' : '' ?>>Level 1 (Super Admin)</option>
                                <option value="2" <?= $admin_to_edit['level'] == 2 ? 'selected' : '' ?>>Level 2 (Admin)</option>
                                <option value="5" style="background-color: #e6fffa; color: #047857; font-weight:bold;" <?= $admin_to_edit['level'] == 5 ? 'selected' : '' ?>>>Level 5 (Accountant)</option>
                            </select>
                        </p>
                        <p><strong>New Password:</strong> <input type="password" name="editAdminPassword" placeholder="Leave blank to keep unchanged"></p>
                    </div>
                </div>
                <div style="text-align: right; margin-top: 1rem;">
                    <button type="submit">Save Changes</button>
                </div>
            </form>
        </div>
        <?php endif; endif; ?>

        <div class="add-admin">
            <h3>Add New Admin</h3>
            <form id="addAdminForm" method="post" enctype="multipart/form-data">
                <div class="input-group">
                    <label for="newAdminName">Name</label>
                    <input type="text" id="newAdminName" name="newAdminName" required/>
                </div>
                <div class="input-group">
                    <label for="newAdminId">Admin ID</label>
                    <input type="text" id="newAdminId" name="newAdminId" required/>
                </div>
                <div class="input-group">
                    <label for="newAdminPassword">Password</label>
                    <input type="password" id="newAdminPassword" name="newAdminPassword" required/>
                </div>
                <div class="input-group">
                    <label for="newAdminContact">Contact</label>
                    <input type="text" id="newAdminContact" name="newAdminContact" required/>
                </div>
                <div class="input-group">
                    <label for="newAdminLevel">Admin Level</label>
                    <select id="newAdminLevel" name="newAdminLevel" required>
                        <option value="1">Level 1 (Super Admin)</option>
                        <option value="2">Level 2 (General Admin)</option>
                        <option value="5" style="background-color: #e6fffa; color: #047857; font-weight:bold;">Level 5 (Accountant)</option>
                    </select>
                </div>
                <div class="input-group">
                    <label for="newAdminPic">Profile Picture</label>
                    <input type="file" id="newAdminPic" name="profile_pic" accept="image/*">
                </div>
                <input type="hidden" name="action" value="addAdmin">
                <button type="submit">Add Admin</button>
            </form>
        </div>
      </section>
      <?php endif; ?>

      <?php if (isset($_SESSION['adminUser']['level']) && $_SESSION['adminUser']['level'] == 1): ?>
      <section id="manageSubjects">
          <h2>Manage Subjects</h2>

          <div class="content-editor">
              <h3>Subject List</h3>
              <div class="table-container">
                  <table>
                      <thead>
                          <tr>
                              <th>Subject Code (ID)</th>
                              <th>Subject Name</th>
                              <th>Actions</th>
                          </tr>
                      </thead>
                      <tbody>
                          <?php
                          $subjects_res = $conn->query("SELECT code, name FROM subjects ORDER BY name");
                          while ($subject = $subjects_res->fetch_assoc()):
                          ?>
                          <tr>
                              <td><?= htmlspecialchars($subject['code']) ?></td>
                              <td><?= htmlspecialchars($subject['name']) ?></td>
                              <td>
                                  <button onclick="editSubject('<?= htmlspecialchars($subject['code']) ?>', '<?= htmlspecialchars($subject['name']) ?>')">Edit</button>
                                  
                                  <form method="post" style="display:inline;" id="delSubForm_<?= $subject['code'] ?>">
                                      <input type="hidden" name="action" value="deleteSubject">
                                      <input type="hidden" name="subjectCodeToDelete" value="<?= htmlspecialchars($subject['code']) ?>">
                                      <button type="button" onclick="confirmDelete(event, 'delSubForm_<?= $subject['code'] ?>')">Delete</button>
                                  </form>
                              </td>
                          </tr>
                          <?php endwhile; ?>
                      </tbody>
                  </table>
              </div>
          </div>
          
          <div class="content-editor" style="margin-top: 2rem;">
              <form id="subjectForm" method="post">
                  <h3 id="subjectFormTitle">Add New Subject</h3>
                  <input type="hidden" id="subjectAction" name="action" value="addSubject">
                  <input type="hidden" id="subjectCodeToUpdate" name="subjectCodeToUpdate" value="">
                  
                  <div class="input-group">
                      <label for="newSubjectCode">Subject Code (e.g., MAT, SCI, ENG)</label>
                      <input type="text" id="newSubjectCode" name="newSubjectCode" required>
                  </div>
                  <div class="input-group">
                      <label for="newSubjectName">Subject Name (e.g., Mathematics)</label>
                      <input type="text" id="newSubjectName" name="newSubjectName" required>
                  </div>
                  
                  <button type="submit" id="subjectSubmitButton">Add Subject</button>
                  <button type="button" id="cancelEditButton" onclick="resetSubjectForm()" style="display:none;">Cancel Edit</button>
              </form>
        </div>
      </section>
      <?php endif; ?>

      <?php if (isset($_SESSION['adminUser']['level']) && $_SESSION['adminUser']['level'] == 1): ?>
      <section id="manageClasses">
        <h2>Manage Classes & Promotions</h2>

        <div class="content-editor">
          <h3>Class List & Order</h3>
          <p>Edit class names directly and use the (+) buttons to add a new class in the desired position.</p>
          
          <table class="class-manager-table">
              <thead>
                  <tr>
                      <th>Class Name (Editable)</th>
                      <th>Actions</th>
                  </tr>
              </thead>
              <tbody>
                  <?php
                  $classes_res = $conn->query("SELECT id, name, sort_order FROM classes ORDER BY sort_order");
                  $all_classes = [];
                  while ($row = $classes_res->fetch_assoc()) {
                      $all_classes[] = $row;
                  }
                  ?>

                  <tr class="add-row">
                      <td colspan="2">
                          <form method="post" class="add-class-form">
                              <input type="hidden" name="action" value="addNewClass">
                              <input type="hidden" name="sortOrderAfter" value="0">
                              <input type="text" name="newClassName" placeholder="Add a new class at the top" required>
                              <button type="submit">+</button>
                          </form>
                      </td>
                  </tr>

                  <?php foreach ($all_classes as $class): ?>
                      <tr class="class-row">
                          <td>
                              <form method="post" class="edit-class-form">
                                  <input type="hidden" name="action" value="updateClass">
                                  <input type="hidden" name="classIdToUpdate" value="<?= $class['id'] ?>">
                                  <input type="text" name="editClassName" value="<?= htmlspecialchars($class['name']) ?>" required>
                                  <button type="submit">Save</button>
                              </form>
                          </td>
                          <td>
                              <form method="post" id="delClassForm_<?= $class['id'] ?>">
                                  <input type="hidden" name="action" value="deleteClass">
                                  <input type="hidden" name="classIdToDelete" value="<?= $class['id'] ?>">
                                  <button type="button" class="delete-btn" onclick="confirmDelete(event, 'delClassForm_<?= $class['id'] ?>')">Delete</button>
                              </form>
                          </td>
                      </tr>
                      <tr class="add-row">
                          <td colspan="2">
                              <form method="post" class="add-class-form">
                                  <input type="hidden" name="action" value="addNewClass">
                                  <input type="hidden" name="sortOrderAfter" value="<?= $class['sort_order'] ?>">
                                  <input type="text" name="newClassName" placeholder="Add a new class here" required>
                                  <button type="submit">+</button>
                              </form>
                          </td>
                      </tr>
                  <?php endforeach; ?>
              </tbody>
          </table>
        </div>

        <div class="content-editor" style="margin-top: 2rem;">
            <h3>Class Promotion Mapper</h3>
            <p>For each class, select the class that students will be promoted to at the end of the session.</p>
            <form method="post">
                <input type="hidden" name="action" value="savePromotions">
                <table>
                    <thead><tr><th>Current Class</th><th>Promotes To</th></tr></thead>
                    <tbody>
                    <?php
                    $promo_map_res = $conn->query("SELECT current_class_id, next_class_id FROM class_promotions");
                    $promo_map = [];
                    while($row = $promo_map_res->fetch_assoc()){
                        $promo_map[$row['current_class_id']] = $row['next_class_id'];
                    }

                    foreach ($all_classes as $current_class):
                    ?>
                        <tr>
                            <td><?= htmlspecialchars($current_class['name']) ?></td>
                            <td>
                                <select name="promotions[<?= $current_class['id'] ?>]">
                                    <option value="">-- Graduate / End of Path --</option>
                                    <?php foreach ($all_classes as $next_class):
                                        if ($current_class['id'] === $next_class['id']) continue;
                                        $selected_id = $promo_map[$current_class['id']] ?? null;
                                        $selected = ($next_class['id'] == $selected_id) ? 'selected' : '';
                                    ?>
                                    <option value="<?= $next_class['id'] ?>" <?= $selected ?>>
                                        <?= htmlspecialchars($next_class['name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="table-actions">
                    <button type="submit">Save Promotion Map</button>
                </div>
            </form>
        </div>
      </section>
      <?php endif; ?>

      <?php if (isset($_SESSION['adminUser']['level']) && $_SESSION['adminUser']['level'] == 1): ?>
      <section id="endOfSession">
        <h2>End of Session Process</h2>

        <div class="content-editor" style="border: 2px solid #dc3545;">
            <h3><span style="color: #dc3545;">DANGER ZONE</span>: This action is irreversible.</h3>
            <p>This process will promote all students, archive the year's data, and permanently delete all attendance and marks records. Proceed with extreme caution.</p>

            <?php if(isset($_GET['error']) && $_GET['error'] === 'master_password'): ?>
                <p style="color: red; font-weight: bold;">The Master Password was incorrect. No actions have been taken.</p>
            <?php endif; ?>
            <?php if(isset($_GET['success']) && $_GET['success'] === 'session_ended'): ?>
                <p style="color: green; font-weight: bold;">The session has been ended successfully. The data has been archived and reset.</p>
            <?php endif; ?>

            <form method="post" onsubmit="return validateEndOfSessionForm();">
                <input type="hidden" name="action" value="endOfSession">
                
                <div class="input-group">
                    <label for="archiveName">Archive File Name</label>
                    <input type="text" id="archiveName" name="archiveName" value="Session-<?= date('Y')-1 ?>-<?= date('Y') ?>-Archive" required>
                </div>
                <div class="input-group">
                    <label for="masterPassword">Master Password</label>
                    <input type="password" id="masterPassword" name="masterPassword" required>
                </div>

                <hr style="margin: 1rem 0;">
                <h4>Confirmation Checklist</h4>
                <div class="input-group">
                    <label><input type="checkbox" id="confirm1" required> I confirm that the academic session is over and all results are final.</label>
                </div>
                <div class="input-group">
                    <label><input type="checkbox" id="confirm2" required> I understand that all students will be promoted or graduated according to the map.</label>
                </div>
                <div class="input-group">
                    <label><input type="checkbox" id="confirm3" required> I understand this will create a downloadable archive of all student data.</label>
                </div>
                <div class="input-group">
                    <label><input type="checkbox" id="confirm4" required> I confirm I will download the archive before proceeding, as it's the only record.</label>
                </div>
                <div class="input-group">
                    <label><input type="checkbox" id="confirm5" required> I understand that all attendance and marks records for the past year will be PERMANENTLY DELETED.</label>
                </div>

                <button type="submit" id="finalSubmitBtn" style="background-color: #dc3545; width: 100%; padding: 1rem; font-size: 1.2rem;">INITIATE END OF SESSION</button>
            </form>
        </div>
      </section>
      <?php endif; ?>

      <script>
        function validateEndOfSessionForm() {
            const c1 = document.getElementById('confirm1').checked;
            const c2 = document.getElementById('confirm2').checked;
            const c3 = document.getElementById('confirm3').checked;
            const c4 = document.getElementById('confirm4').checked;
            const c5 = document.getElementById('confirm5').checked;
            if (c1 && c2 && c3 && c4 && c5) {
                return confirm('FINAL WARNING:\n\nThis is the last step. Are you absolutely sure you want to end the session? This cannot be undone.');
            } else {
                alert('You must tick all confirmation checkboxes to proceed.');
                return false;
            }
        }
      </script>
      
      <ul>
        <?php
        $archive_dir = __DIR__ . '/archives/';
        if (is_dir($archive_dir)) {
            $files = scandir($archive_dir);
            $has_files = false;
            foreach ($files as $file) {
                if (pathinfo($file, PATHINFO_EXTENSION) === 'pdf') {
                    $has_files = true;
                    $file_path = 'archives/' . htmlspecialchars($file);
                    echo '<li><a href="' . $file_path . '" download>' . htmlspecialchars($file) . '</a></li>';
                }
            }
            if (!$has_files) {
                echo '<li>No PDF archives found.</li>';
            }
        }
        ?>
      </ul>

      <?php 
          $awaiting_res = $conn->query("SELECT s.id, s.name, c.name as current_class FROM students s JOIN classes c ON s.class_id = c.id WHERE s.status = 'awaiting_stream' ORDER BY s.name");
          if ($awaiting_res && $awaiting_res->num_rows > 0):
      ?>
      <section id="manualAssignment">
        <h2>Manual Stream Assignment</h2>
          <div class="content-editor" style="border: 2px solid #ffc107;">
              <h3>Students Awaiting Promotion</h3>
              <p>The following students have completed a "fork" class (e.g., Class 10) and must be manually assigned to their new stream for the next session.</p>
              <form method="post">
                  <input type="hidden" name="action" value="assignStreams">
                  <table>
                      <thead><tr><th>Student Name</th><th>Current Class</th><th>Assign to New Class</th></tr></thead>
                      <tbody>
                      <?php
                      $all_classes_res = $conn->query("SELECT id, name FROM classes ORDER BY sort_order");
                      $all_classes = [];
                      while($row = $all_classes_res->fetch_assoc()){ $all_classes[] = $row; }

                      while($student = $awaiting_res->fetch_assoc()):
                      ?>
                          <tr>
                              <td><?= htmlspecialchars($student['name']) ?></td>
                              <td><?= htmlspecialchars($student['current_class']) ?></td>
                              <td>
                                  <select name="assignments[<?= $student['id'] ?>]" required>
                                      <option value="" disabled selected>Select a stream...</option>
                                      <?php foreach($all_classes as $class): ?>
                                          <option value="<?= $class['id'] ?>"><?= htmlspecialchars($class['name']) ?></option>
                                      <?php endforeach; ?>
                                  </select>
                              </td>
                          </tr>
                      <?php endwhile; ?>
                      </tbody>
                  </table>
                  <div class="table-actions">
                      <button type="submit">Confirm All Stream Assignments</button>
                  </div>
              </form>
          </div>
      </section>
      <?php endif; ?>
    </div></div><?php endif; ?>

  <script>
    // Login/Profile toggle
    document.addEventListener("DOMContentLoaded", () => {
      const loginSection = document.getElementById("loginSection"),
            profileSection = document.getElementById("profileSection"),
            user = JSON.parse(sessionStorage.getItem("adminUser")||"null");

      if (user) {
        document.getElementById("adminID").innerText = user.adminId;
        document.getElementById("adminName").innerText = user.name;
        document.getElementById("adminContact").innerText = user.contact;
        loginSection.style.display = "none";
        profileSection.style.display = "flex";
      }
    });

    function toggleSidebar() {
        const sidebar = document.getElementById('adminSidebar');
        sidebar.classList.toggle('show');
    }
    const hamburgerBtn = document.getElementById("hamburgerBtn"); 
    const headerMenuBtn = document.getElementById("headerHamburgerBtn"); 
    
    if(hamburgerBtn) hamburgerBtn.addEventListener("click", toggleSidebar);
    if(headerMenuBtn) headerMenuBtn.addEventListener("click", toggleSidebar);

    function editSubject(code, name) {
        document.getElementById('subjectFormTitle').innerText = 'Edit Subject';
        document.getElementById('subjectAction').value = 'updateSubject';
        document.getElementById('subjectCodeToUpdate').value = code;
        
        const codeInput = document.getElementById('newSubjectCode');
        codeInput.value = code;
        codeInput.readOnly = true; 
        codeInput.style.backgroundColor = '#e9ecef';

        document.getElementById('newSubjectName').value = name;
        document.getElementById('subjectSubmitButton').innerText = 'Save Changes';
        document.getElementById('cancelEditButton').style.display = 'inline-block';
        document.getElementById('subjectForm').scrollIntoView();
    }

    function resetSubjectForm() {
        document.getElementById('subjectFormTitle').innerText = 'Add New Subject';
        document.getElementById('subjectAction').value = 'addSubject';
        document.getElementById('subjectForm').reset();
        
        const codeInput = document.getElementById('newSubjectCode');
        codeInput.readOnly = false;
        codeInput.style.backgroundColor = '#fff';

        document.getElementById('subjectSubmitButton').innerText = 'Add Subject';
        document.getElementById('cancelEditButton').style.display = 'none';
    }

    function addEventRow(containerId, prefix, hasTitle, hasDate = false) {
        const container = document.getElementById(containerId);
        const div = document.createElement('div');
        div.className = 'repeater-row';
        div.style.cssText = 'border-left: 4px solid #666; position: relative; padding-right: 80px; background-color: #f9f9f9; transition: all 0.3s ease;';
        
        let html = '';
        if (hasTitle && hasDate) {
            html += `
                <div style="display:flex; gap:10px;">
                    <input type="text" name="${prefix}_title[]" placeholder="Title" style="flex:2;" required>
                    <input type="date" name="${prefix}_date[]" style="flex:1;" required>
                </div>
                <textarea name="${prefix}_desc[]" rows="2" placeholder="Description"></textarea>
            `;
        } else if (hasTitle) {
            html += `
                <input type="text" name="${prefix}_title[]" placeholder="Title" required>
                <textarea name="${prefix}_content[]" rows="2" placeholder="Content"></textarea>
            `;
        } else {
            html += `
                <textarea name="${prefix}_text[]" rows="2" placeholder="Update Text" required></textarea>
            `;
        }
        
        html += `
            <div style="margin-top:5px;">
                <input type="file" name="${prefix}_file[]">
                <input type="hidden" name="${prefix}_old_img[]" value="">
            </div>
            <div style="position: absolute; top: 10px; right: 10px; display: flex; gap: 5px;">
                <button type="submit" title="Save This" style="background:none; border:none; font-size:1.2rem; cursor:pointer;">💾</button>
                <button type="button" title="Remove" class="remove-row-btn" onclick="this.closest('.repeater-row').remove()" style="position:static; transform:none;">X</button>
            </div>
        `;
        div.innerHTML = html;
        container.prepend(div);
        div.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function addDisclosureRow() {
        const container = document.getElementById('disclosure_container');
        const div = document.createElement('div');
        div.className = 'repeater-row';
        div.innerHTML = `
            <input type="text" name="disc_head[]" placeholder="Document Heading" required>
            <div style="margin-top:5px;">
                <input type="file" name="disc_file[]" accept=".pdf,.jpg,.png" required>
                <input type="hidden" name="disc_old_file[]" value="">
            </div>
            <button type="button" class="remove-row-btn" onclick="this.parentElement.remove()">X</button>
        `;
        container.appendChild(div);
    }
  </script>

<?php if (isset($_SESSION['swal_msg'])): ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        Swal.fire({
            toast: true,
            position: 'top-end',
            title: '<?= ucfirst($_SESSION['swal_type']) ?>',
            text: '<?= addslashes($_SESSION['swal_msg']) ?>',
            icon: '<?= $_SESSION['swal_type'] ?>',
            showConfirmButton: false,
            timer: 3000,
            timerProgressBar: true
        });
    });
</script>
<?php 
    unset($_SESSION['swal_type']);
    unset($_SESSION['swal_msg']);
?>
<?php endif; ?>

<script>
    const urlParams = new URLSearchParams(window.location.search);
    const targetId = urlParams.get('scrollTo');
    if (targetId) {
        const element = document.getElementById(targetId);
        if (element) {
            setTimeout(() => {
                element.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }, 100);
        }
    }

    function confirmDelete(event, formId) {
        event.preventDefault(); 
        Swal.fire({
            title: 'Are you sure?',
            text: "You won't be able to revert this!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Yes, delete it!'
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById(formId).submit();
            }
        })
    }
</script>

<?php include 'footer.php'; ?>
</body>
</html>