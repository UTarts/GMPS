<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include your database connection
require_once 'includes/db_connect.php';

$message = '';
$SECURITY_PIN = "62126636";

// Hardcoded target class IDs based on your database structure
$target_classes = [
    ['label' => 'Upload NURSERY File ➔ Promotes to K1', 'id' => 64],
    ['label' => 'Upload K1 File ➔ Promotes to K2', 'id' => 67],
    ['label' => 'Upload K2 File ➔ Promotes to 1st', 'id' => 70],
    ['label' => 'Upload 1st File ➔ Promotes to 2nd', 'id' => 73],
    ['label' => 'Upload 2nd File ➔ Promotes to 3rd', 'id' => 76],
    ['label' => 'Upload 3rd File ➔ Promotes to 4th', 'id' => 79],
    ['label' => 'Upload 4th File ➔ Promotes to 5th', 'id' => 82],
    ['label' => 'Upload 5th File ➔ Promotes to 6th', 'id' => 85],
    ['label' => 'Upload 6th File ➔ Promotes to 7th', 'id' => 88],
    ['label' => 'Upload 7th File ➔ Promotes to 8th', 'id' => 91],
    ['label' => 'Upload 8th File ➔ Promotes to 9th', 'id' => 94],
    ['label' => 'Upload 9th File ➔ Promotes to 10th', 'id' => 97],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pin = $_POST['pin'] ?? '';
    
    if ($pin !== $SECURITY_PIN) {
        $message = "<div style='color: red; padding: 15px; background: #fee2e2; border-radius: 8px; border: 1px solid #ef4444; font-weight: bold;'>Incorrect Security PIN.</div>";
    } else {
        // 1. Figure out the absolute next gmps ID sequence globally
        $res = $conn->query("SELECT login_id FROM students WHERE login_id LIKE 'gmps%' ORDER BY LENGTH(login_id) DESC, login_id DESC LIMIT 1");
        $last_id_num = 0;
        if ($res && $res->num_rows > 0) {
            $row = $res->fetch_assoc();
            $last_id_num = (int)str_replace('gmps', '', $row['login_id']);
        }

        $total_imported = 0;
        $results_msg = [];
        $admission_year = date('Y');

        // 2. Loop through all 12 file inputs
        foreach ($target_classes as $index => $class_info) {
            $file_key = 'csv_file_' . $class_info['id'];

            if (isset($_FILES[$file_key]) && $_FILES[$file_key]['error'] === UPLOAD_ERR_OK) {
                $class_id = $class_info['id'];
                $file_tmp = $_FILES[$file_key]['tmp_name'];
                $handle = fopen($file_tmp, "r");
                
                if ($handle !== FALSE) {
                    // Skip the Header Row
                    fgetcsv($handle, 1000, ",");
                    
                    // Get next Roll Number for this specific class
                    $roll_res = $conn->query("SELECT MAX(roll_no) as max_roll FROM students WHERE class_id = $class_id");
                    $next_roll = ($roll_res->fetch_assoc()['max_roll'] ?? 0) + 1;
                    
                    $class_count = 0;
                    $stmt = $conn->prepare("INSERT INTO students (name, dob, aadhar_no, father_name, mother_name, contact, address, class_id, roll_no, login_id, password_hash, admission_year, status, profile_pic) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', 'GMPSimages/default_student.png')");
                    
                    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                        // If row is empty or name is missing, skip
                        if (empty(trim($data[1] ?? ''))) continue;
                        
                        $name = trim($data[1]);
                        $dob_raw = trim($data[3] ?? '');
                        $student_aadhar = trim($data[4] ?? '');
                        $father_name = trim($data[5] ?? '');
                        $contact = trim($data[7] ?? ''); // Father's mobile
                        $mother_name = trim($data[8] ?? '');
                        $address = trim($data[11] ?? '');
                        
                        // Format DOB safely to YYYY-MM-DD
                        $dob = null;
                        if (!empty($dob_raw)) {
                            $time = strtotime(str_replace('/', '-', $dob_raw));
                            if ($time) $dob = date('Y-m-d', $time);
                        }
                        
                        // Global ID Incrementer - Continues seamlessly across files
                        $last_id_num++;
                        $login_id = 'gmps' . str_pad($last_id_num, 5, '0', STR_PAD_LEFT);
                        $password_hash = password_hash($login_id, PASSWORD_DEFAULT);
                        
                        // Execute insertion
                        $stmt->bind_param("sssssssiissi", 
                            $name, $dob, $student_aadhar, $father_name, $mother_name, 
                            $contact, $address, $class_id, $next_roll, $login_id, 
                            $password_hash, $admission_year
                        );
                        
                        if ($stmt->execute()) {
                            $class_count++;
                            $total_imported++;
                            $next_roll++;
                        }
                    }
                    fclose($handle);
                    $results_msg[] = "✔ " . explode('➔', $class_info['label'])[1] . ": <b>$class_count students imported</b>";
                }
            }
        }

        // Final Report
        if ($total_imported > 0) {
            $message = "<div style='color: #065f46; padding: 15px; background: #dcfce7; border-radius: 8px; border: 2px solid #10b981; margin-bottom: 20px;'>
                <h3 style='margin:0 0 10px 0;'>✅ Successfully Processed $total_imported Students!</h3>
                <div style='font-size: 14px; line-height: 1.6;'>" . implode("<br>", $results_msg) . "</div>
            </div>";
        } else {
            $message = "<div style='color: #9a3412; padding: 15px; background: #fef08a; border-radius: 8px; border: 2px solid #facc15; font-weight: bold;'>No files were uploaded or the files were empty.</div>";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GMPS Mass Data Importer</title>
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; background: #f3f4f6; color: #1f2937; padding: 40px; }
        .container { max-width: 900px; margin: 0 auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1); }
        h1 { margin-top: 0; font-size: 28px; color: #111827; display: flex; align-items: center; gap: 10px; }
        .instructions { background: #f8fafc; padding: 20px; border-radius: 8px; font-size: 14px; color: #475569; margin-bottom: 30px; border: 1px solid #e2e8f0; line-height: 1.6; }
        
        .pin-box { background: #1e293b; padding: 20px; border-radius: 10px; margin-bottom: 30px; }
        .pin-box label { color: #fff; font-size: 16px; margin-bottom: 10px; display: block; font-weight: bold; }
        .pin-box input { width: 100%; max-width: 300px; padding: 12px; font-size: 20px; letter-spacing: 5px; border-radius: 6px; border: none; outline: none; text-align: center; }

        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .file-card { background: #ffffff; border: 2px dashed #cbd5e1; padding: 20px; border-radius: 10px; transition: border-color 0.3s; }
        .file-card:hover { border-color: #6366f1; background: #f8fafc; }
        .file-card label { display: block; font-weight: bold; margin-bottom: 10px; font-size: 15px; color: #334155; }
        .file-card input[type="file"] { width: 100%; font-size: 14px; }
        
        button.submit-btn { background: #4f46e5; color: white; border: none; padding: 18px 20px; border-radius: 10px; width: 100%; font-size: 20px; font-weight: black; cursor: pointer; box-shadow: 0 4px 14px rgba(79, 70, 229, 0.4); transition: transform 0.1s; }
        button.submit-btn:active { transform: scale(0.98); }
    </style>
</head>
<body>
    <div class="container">
        <h1>🚀 Mass Student Importer (12 Classes)</h1>
        
        <?= $message ?>

        <div class="instructions">
            <b>CRITICAL INSTRUCTIONS:</b><br>
            1. All 12 files must be saved as <b>.csv (Comma delimited)</b>.<br>
            2. You can upload all 12 files at once, or just a few at a time. The system will process them from top to bottom.<br>
            3. The <b>gmpsXXXXX</b> IDs will be generated sequentially across all files uploaded.<br>
            4. Make sure the headers match your format exactly: <i>Adm No | Student Name | Class | DOB | Aadhar No. | Father's Name | ... etc</i>
        </div>

        <form method="post" enctype="multipart/form-data">
            
            <div class="pin-box">
                <label>Authorize with Security PIN</label>
                <input type="password" name="pin" required placeholder="••••••••" maxlength="8">
            </div>

            <div class="grid">
                <?php foreach($target_classes as $class_info): ?>
                    <div class="file-card">
                        <label><?= $class_info['label'] ?></label>
                        <input type="file" name="csv_file_<?= $class_info['id'] ?>" accept=".csv">
                    </div>
                <?php endforeach; ?>
            </div>

            <button type="submit" class="submit-btn">EXECUTE MASS IMPORT</button>
        </form>
    </div>
</body>
</html>