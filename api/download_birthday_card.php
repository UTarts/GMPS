<?php
require_once 'config.php';
require_once '../includes/fpdf.php';

// --- HELPER FUNCTION: Makes the image circular ---
function createCircularProfilePic($source_file, $destination_file) {
    if (!file_exists($source_file)) return false;
    
    // Load the original image
    $img_str = file_get_contents($source_file);
    if (!$img_str) return false;
    $img = imagecreatefromstring($img_str);
    
    $width = imagesx($img);
    $height = imagesy($img);
    $min_size = min($width, $height);
    
    // Crop it to a perfect square first
    $square = imagecrop($img, ['x' => ($width - $min_size) / 2, 'y' => ($height - $min_size) / 2, 'width' => $min_size, 'height' => $min_size]);
    
    // Create a transparent canvas
    $size = 300; // Resolution of the circle
    $circle = imagecreatetruecolor($size, $size);
    imagealphablending($circle, false);
    imagesavealpha($circle, true);
    $transparent = imagecolorallocatealpha($circle, 255, 255, 255, 127);
    imagefill($circle, 0, 0, $transparent);
    
    // Resize the square image to fit our canvas
    $resized = imagecreatetruecolor($size, $size);
    imagecopyresampled($resized, $square, 0, 0, 0, 0, $size, $size, $min_size, $min_size);
    
    // Math to crop out the corners and make it a circle
    for ($x = 0; $x < $size; $x++) {
        for ($y = 0; $y < $size; $y++) {
            $c_x = $x - $size / 2;
            $c_y = $y - $size / 2;
            if ($c_x * $c_x + $c_y * $c_y < ($size / 2) * ($size / 2)) {
                $color = imagecolorat($resized, $x, $y);
                imagesetpixel($circle, $x, $y, $color);
            }
        }
    }
    
    // Save as a temporary PNG file with transparency
    imagepng($circle, $destination_file);
    imagedestroy($img); imagedestroy($square); imagedestroy($circle); imagedestroy($resized);
    return true;
}
// --- END HELPER ---

$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
if (!$user_id) die("Invalid User");

$stmt = $conn->prepare("SELECT name, profile_pic FROM students WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();

if ($student = $res->fetch_assoc()) {
    $pdf = new FPDF('L', 'mm', 'A4'); // Landscape A4
    $pdf->AddPage();
    $pdf->SetAutoPageBreak(false);

    $bgPath = __DIR__ . '/../GMPSimages/birthday.jpg';
    $picPath = __DIR__ . '/../' . $student['profile_pic'];
    
    // Temporary file path for the circular image
    $tempCirclePath = __DIR__ . '/../GMPSimages/temp_circle_' . $user_id . '.png';

    // 1. PLACE BACKGROUND
    if (file_exists($bgPath)) {
        $pdf->Image($bgPath, 0, 0, 297, 210);
        $pdf->SetTextColor(0, 0, 0); 
    } else {
        $pdf->SetTextColor(0, 0, 0); 
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->SetXY(0, 20);
        $pdf->Cell(297, 10, '[Please upload birthday_bg.jpg to GMPSimages folder]', 0, 1, 'C');
    }

    // 2. PLACE PROFILE PICTURE (CIRCULAR)
    if (file_exists($picPath) && $student['profile_pic'] !== 'GMPSimages/profile-placeholder.jpg') {
        if (createCircularProfilePic($picPath, $tempCirclePath)) {
            
            // 👉 HOW TO ADJUST THE PICTURE POSITION:
            // $pdf->Image(file, X-position, Y-position, Width, Height)
            // X: Increase to move RIGHT, decrease to move LEFT
            // Y: Increase to move DOWN, decrease to move UP
            // Width/Height: Change '50' to make the circle bigger or smaller
            
            $pdf->Image($tempCirclePath, 157, 53, 50, 50); 
            
            // Delete the temporary circular file to save server space
            unlink($tempCirclePath); 
        }
    }

    // 3. PLACE STUDENT NAME
    $pdf->SetFont('Times', 'I', 36); 
    
    // 👉 HOW TO ADJUST THE NAME POSITION:
    // $pdf->SetXY(X-position, Y-position)
    // Because it is a centered cell ('C' below), leave X as 0. 
    // Just change the Y-position (currently 120).
    // Increase 120 to move the text DOWN, decrease it to move it UP.
    
    $pdf->SetXY(35, 106); 
    
    // 👉 HOW TO ADJUST NAME COLOR:
    // $pdf->SetTextColor(R, G, B);
    // Currently it is set to pure white (255, 255, 255). 
    // If you want black, use (0, 0, 0).
    // $pdf->SetTextColor(255, 255, 255); 
    
    $pdf->Cell(297, 20, $student['name'], 0, 1, 'C');

    $pdf->Output('D', 'Birthday_Card_' . str_replace(' ', '_', $student['name']) . '.pdf');
}
?>