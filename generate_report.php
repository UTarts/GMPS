<?php
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/fpdf.php';

// 1. Security & Data Fetching
if (!isset($_SESSION['student_id'])) { die("Access Denied"); }

$sid = (int)$_SESSION['student_id'];
$exam_id = isset($_GET['exam_id']) ? (int)$_GET['exam_id'] : 0;

if ($exam_id === 0) die("Invalid Exam");

// Fetch Student Data
$student_q = $conn->query("
    SELECT s.*, c.name as class_name, c.id as class_id, t.name as teacher_name 
    FROM students s 
    JOIN classes c ON s.class_id = c.id 
    LEFT JOIN teachers t ON t.assigned_class_id = c.id 
    WHERE s.id = $sid
");
$student = $student_q->fetch_assoc();

// Check Publish Status
$pub_check = $conn->query("SELECT is_published FROM exam_publish_status WHERE exam_id = $exam_id AND class_id = {$student['class_id']}");
$pub = $pub_check->fetch_assoc();
if (!$pub || $pub['is_published'] != 1) { die("Report card not yet published."); }

// Fetch Exam & Marks
$exam_q = $conn->query("SELECT name FROM exams WHERE id = $exam_id");
$exam = $exam_q->fetch_assoc();

$marks_q = $conn->query("
    SELECT s.name as subject, m.marks_obtained, e.max_marks 
    FROM marks m 
    JOIN subjects s ON m.subject_code = s.code 
    JOIN exams e ON m.exam_id = e.id
    WHERE m.student_id = $sid AND m.exam_id = $exam_id
");
$marks = [];
$total_obt = 0; $total_max = 0;
while($row = $marks_q->fetch_assoc()) {
    $marks[] = $row;
    $total_obt += $row['marks_obtained'];
    $total_max += $row['max_marks'];
}

// Determine Style
$cls = $student['class_name'];
$style = 'Standard';
if (stripos($cls, 'Nursery') !== false || stripos($cls, 'K1') !== false || stripos($cls, 'K2') !== false) {
    $style = 'Playful';
} elseif (preg_match('/^(6|7|8|9|10|11|12)/', $cls)) {
    $style = 'Modern';
}

// Helper to format names
function formatName($name, $prefix) {
    $name = trim($name);
    // Avoid double prefixes like "Mr. Mr. John"
    if (stripos($name, $prefix) === 0) return $name;
    return $prefix . ' ' . $name;
}

// ==============================================================================
// PDF GENERATOR CLASS
// ==============================================================================
class ReportPDF extends FPDF {
    
    // Helper: Rounded Rect (No Shadow)
    function DrawCard($x, $y, $w, $h, $r, $fillColor) {
        $this->SetFillColor($fillColor[0], $fillColor[1], $fillColor[2]);
        $this->RoundedRect($x, $y, $w, $h, $r, 'F');
        $this->SetTextColor(0); 
    }

    function UniversalHeader($exam_name) {
        // 1. Full Logo Image (Centered, Smaller)
        $this->Image('GMPSimages/GMPS.header.logo.png', 40, 10, 130); // Reduced width to 130
        
        $this->SetY(45); // Move down past logo
        
        // 2. Exam Name & Session
        $this->SetFont('Arial', 'B', 14); // Slightly smaller
        $this->SetTextColor(50, 50, 50);
        $session = date('Y') . '-' . (date('Y')+1);
        $this->Cell(0, 8, strtoupper($exam_name) . " ($session)", 0, 1, 'C');
        
        // 3. "REPORT CARD" Label
        $this->SetFont('Arial', 'B', 11);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 6, "REPORT CARD", 0, 1, 'C');
        $this->Ln(5);
    }

    // Profile Image with Circle Mask Hack
    function ProfileImage($path, $x, $y, $size, $shape='square') {
        if ($path && file_exists($path)) {
            // Draw Image
            $this->Image($path, $x, $y, $size, $size);
            
            // If circle, overlay a white "donut" to mask corners
            if ($shape == 'circle') {
               // FPDF doesn't support true masking easily. 
               // We will just draw a thick border to make it look neat.
               $this->SetLineWidth(0.5);
               $this->SetDrawColor(255,255,255); 
               // Instead of complex masking, let's use a white border to clean edges if possible,
               // otherwise just a clean bounding box.
               $this->Rect($x, $y, $size, $size); 
            }
        } else {
            $this->SetFillColor(230,230,230);
            $this->Rect($x, $y, $size, $size, 'F');
            $this->SetXY($x, $y+($size/2)-3);
            $this->SetFont('Arial','',8);
            $this->Cell($size,6,'No Photo',0,0,'C');
        }
    }

    // --- STYLE 1: PLAYFUL (Nursery - KG) ---
    function BodyPlayful($st, $mk, $t_obt, $t_max) {
        // Fun Border
        $this->SetLineWidth(3);
        $this->SetDrawColor(255, 105, 180); // Hot Pink
        $this->Rect(5, 5, 200, 287);
        
        // Student Card (Yellow)
        $this->DrawCard(15, 65, 180, 55, 5, [255, 255, 224]); // Taller for extra fields
        
        // Photo
        $this->ProfileImage($st['profile_pic'], 20, 72, 40, 'square'); // Keeping square for playful is often better, or circle if preferred
        
        // Details
        $this->SetXY(65, 70);
        $this->SetFont('Arial', 'B', 11);
        
        $details = [
            ['Name:', $st['name'], [0, 128, 0]], // Green
            ['Class:', $st['class_name'], [255, 69, 0]], // Orange
            ["Father's Name:", formatName($st['father_name'], 'Mr.'), [75, 0, 130]], // Indigo
            ["Mother's Name:", formatName($st['mother_name'], 'Ms.'), [199, 21, 133]], // MediumVioletRed
            ['Classteacher:', ($st['teacher_name']??"N/A"), [0, 128, 0]] // Green
        ];

        foreach($details as $d) {
            $this->SetX(65);
            $this->SetTextColor($d[2][0], $d[2][1], $d[2][2]);
            $this->Cell(30, 8, $d[0], 0, 0); 
            $this->SetTextColor(0); 
            $this->Cell(60, 8, $d[1], 0, 1);
        }

        // Marks Table
        $this->SetY(130);
        $this->SetFont('Arial', 'B', 12);
        $this->SetFillColor(135, 206, 250); // Sky Blue
        $this->SetTextColor(255);
        $this->RoundedRect(15, 130, 180, 10, 2, 'F');
        $this->Cell(90, 10, '  Subject', 0, 0);
        $this->Cell(45, 10, 'Max Marks', 0, 0, 'C');
        $this->Cell(45, 10, 'Obtained', 0, 1, 'C');
        
        $this->SetTextColor(0);
        $this->SetFont('Arial', '', 12);
        $fill = false;
        foreach ($mk as $m) {
            $this->SetFillColor(255, 240, 245);
            $this->SetX(15);
            $this->Cell(90, 10, '  ' . $m['subject'], 'B', 0, 'L', $fill);
            $this->Cell(45, 10, $m['max_marks'], 'B', 0, 'C', $fill);
            $this->Cell(45, 10, $m['marks_obtained'], 'B', 1, 'C', $fill);
            $fill = !$fill;
        }

        // Footer
        $this->Ln(10);
        $pct = ($t_max > 0) ? ($t_obt/$t_max)*100 : 0;
        
        // Use Standard Font for Stars to avoid issues
        $stars = ($pct > 90) ? '*****' : (($pct > 70) ? '****' : '***');
        
        $this->SetFont('Arial', 'B', 16);
        $this->SetTextColor(255, 20, 147);
        $this->Cell(0, 10, "GOOD EFFORT! $stars", 0, 1, 'C');
        $this->SetTextColor(0);
        $this->SetFont('Arial', '', 12);
        $this->Cell(0, 10, "Total Score: $t_obt / $t_max (" . round($pct,1) . "%)", 0, 1, 'C');
    }

    // --- STYLE 2: STANDARD (Classes 1-5) ---
    function BodyStandard($st, $mk, $t_obt, $t_max) {
        // Page Border
        $this->SetLineWidth(0.5);
        $this->SetDrawColor(100, 100, 100);
        $this->Rect(5, 5, 200, 287);

        // Student Card
        $this->DrawCard(10, 65, 190, 55, 3, [253, 245, 230]);

        // Photo (Try Circular Cutout visually)
        // We draw a white circle over corners if needed, but simple square is safer for PDF
        $this->ProfileImage($st['profile_pic'], 15, 72, 35, 'square'); 
        
        // Data Grid
        $this->SetXY(55, 70);
        $this->SetFont('Arial', '', 10);
        
        // Row 1
        $this->Cell(30, 7, 'Name:', 0, 0); $this->SetFont('Arial','B',10); $this->Cell(60, 7, strtoupper($st['name']), 0, 0);
        $this->SetFont('Arial', '', 10); $this->Cell(25, 7, 'Class:', 0, 0); $this->SetFont('Arial','B',10); $this->Cell(20, 7, $st['class_name'], 0, 1);

        // Row 2
        $this->SetX(55);
        $this->SetFont('Arial', '', 10); $this->Cell(30, 7, "Father's Name:", 0, 0); $this->SetFont('Arial','B',10); $this->Cell(60, 7, formatName($st['father_name'], 'Mr.'), 0, 0);
        $this->SetFont('Arial', '', 10); $this->Cell(25, 7, 'Roll No:', 0, 0); $this->SetFont('Arial','B',10); $this->Cell(20, 7, $st['login_id'], 0, 1);

        // Row 3
        $this->SetX(55);
        $this->SetFont('Arial', '', 10); $this->Cell(30, 7, "Mother's Name:", 0, 0); $this->SetFont('Arial','B',10); $this->Cell(60, 7, formatName($st['mother_name'], 'Ms.'), 0, 1);
        
        // Row 4
        $this->SetX(55);
        $this->SetFont('Arial', '', 10); $this->Cell(30, 7, 'Classteacher:', 0, 0); $this->SetFont('Arial','B',10); $this->Cell(60, 7, $st['teacher_name']??'N/A', 0, 1);

        // Table
        $this->SetY(130);
        $this->SetFont('Arial', 'B', 10);
        $this->SetFillColor(135, 206, 235); 
        $this->SetTextColor(255);
        $this->Cell(90, 10, ' SUBJECT', 0, 0, 'L', true);
        $this->Cell(50, 10, 'TOTAL MARKS', 0, 0, 'C', true);
        $this->Cell(50, 10, 'OBT. MARKS', 0, 1, 'C', true);
        
        $this->SetTextColor(0);
        $this->SetFont('Arial', '', 10);
        foreach ($mk as $m) {
            $this->SetFillColor(245, 245, 245);
            $this->Cell(90, 9, '  '.$m['subject'], 1, 0, 'L', true);
            $this->Cell(50, 9, $m['max_marks'], 1, 0, 'C');
            $this->Cell(50, 9, $m['marks_obtained'], 1, 1, 'C');
        }

        // Total
        $this->Ln(5);
        $this->SetFillColor(220, 20, 60);
        $this->SetTextColor(255);
        $this->RoundedRect(120, $this->GetY(), 80, 12, 2, 'F');
        $this->SetX(120);
        $pct = ($t_max > 0) ? round(($t_obt/$t_max)*100, 1) : 0;
        $this->Cell(80, 12, "Total: $t_obt / $t_max  |  $pct %", 0, 1, 'C');
    }

    // --- STYLE 3: MODERN (Classes 6-12) ---
    function BodyModern($st, $mk, $t_obt, $t_max) {
        // Line Under Header
        $this->SetDrawColor(0, 33, 71);
        $this->SetLineWidth(0.5);
        $this->Line(10, 60, 200, 60);
        
        // Student Profile (Adjusted Y to avoid overlap)
        $this->SetY(70); 
        
        // Photo (Right Aligned)
        $this->ProfileImage($st['profile_pic'], 165, 65, 30);
        
        $this->SetFont('Arial', 'B', 10); $this->SetTextColor(100); 
        $this->Text(10, 65, "STUDENT DETAILS");
        
        $this->SetTextColor(0);
        $this->SetY(70);
        
        // Left Column
        $this->SetFont('Arial', '', 10);
        $this->Cell(35, 6, "Name:", 0, 0); $this->SetFont('Arial', 'B', 10); $this->Cell(60, 6, strtoupper($st['name']), 0, 1);
        
        $this->SetFont('Arial', '', 10);
        $this->Cell(35, 6, "Class:", 0, 0); $this->SetFont('Arial', 'B', 10); $this->Cell(60, 6, $st['class_name'], 0, 1);
        
        $this->SetFont('Arial', '', 10);
        $this->Cell(35, 6, "Roll No:", 0, 0); $this->SetFont('Arial', 'B', 10); $this->Cell(60, 6, $st['login_id'], 0, 1);

        // Right Column (Manual XY for clean layout)
        $this->SetXY(90, 70);
        $this->SetFont('Arial', '', 10); $this->Cell(35, 6, "Father's Name:", 0, 0); $this->SetFont('Arial', 'B', 10); $this->Cell(60, 6, formatName($st['father_name'], 'Mr.'), 0, 1);
        
        $this->SetXY(90, 76);
        $this->SetFont('Arial', '', 10); $this->Cell(35, 6, "Mother's Name:", 0, 0); $this->SetFont('Arial', 'B', 10); $this->Cell(60, 6, formatName($st['mother_name'], 'Ms.'), 0, 1);

        $this->SetXY(90, 82);
        $this->SetFont('Arial', '', 10); $this->Cell(35, 6, "Classteacher:", 0, 0); $this->SetFont('Arial', 'B', 10); $this->Cell(60, 6, $st['teacher_name']??'N/A', 0, 1);

        // Marks Table
        $this->SetY(110);
        $this->SetFillColor(30, 40, 50);
        $this->SetTextColor(255);
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(110, 10, '  SUBJECT', 0, 0, 'L', true);
        $this->Cell(40, 10, 'MAX MARKS', 0, 0, 'C', true);
        $this->Cell(40, 10, 'OBTAINED', 0, 1, 'C', true);

        $this->SetTextColor(0);
        $this->SetFont('Arial', '', 10);
        $fill = false;
        foreach ($mk as $m) {
            $this->SetFillColor(245, 245, 245);
            $this->Cell(110, 10, '  '.$m['subject'], 'B', 0, 'L', $fill);
            $this->Cell(40, 10, $m['max_marks'], 'B', 0, 'C', $fill);
            $this->Cell(40, 10, $m['marks_obtained'], 'B', 1, 'C', $fill);
            $fill = !$fill;
        }

        // Footer Stats
        $this->Ln(10);
        $pct = ($t_max > 0) ? round(($t_obt/$t_max)*100, 2) : 0;
        $grade = 'E';
        if($pct >= 90) $grade = 'A+';
        elseif($pct >= 80) $grade = 'A';
        elseif($pct >= 70) $grade = 'B';
        elseif($pct >= 60) $grade = 'C';
        elseif($pct >= 50) $grade = 'D';

        $this->SetFont('Arial', 'B', 12);
        $this->Cell(110, 10, "Final Grade: $grade", 1, 0, 'L');
        $this->Cell(80, 10, "Total: $t_obt / $t_max ($pct%)", 1, 1, 'C');
    }

    // Rounded Rect Helper (Required for styles)
    function RoundedRect($x, $y, $w, $h, $r, $style = '') {
        $k = $this->k;
        $hp = $this->h;
        if($style=='F') $op='f'; elseif($style=='FD' || $style=='DF') $op='B'; else $op='S';
        $MyArc = 4/3 * (sqrt(2) - 1);
        $this->_out(sprintf('%.2F %.2F m',($x+$r)*$k,($hp-$y)*$k ));
        $xc = $x+$w-$r; $yc = $y+$r;
        $this->_out(sprintf('%.2F %.2F l', $xc*$k,($hp-$y)*$k ));
        $this->_Arc($xc + $r*$MyArc, $yc - $r, $xc + $r, $yc - $r*$MyArc, $xc + $r, $yc);
        $xc = $x+$w-$r; $yc = $y+$h-$r;
        $this->_out(sprintf('%.2F %.2F l',($x+$w)*$k,($hp-$yc)*$k));
        $this->_Arc($xc + $r, $yc + $r*$MyArc, $xc + $r*$MyArc, $yc + $r, $xc, $yc + $r);
        $xc = $x+$r; $yc = $y+$h-$r;
        $this->_out(sprintf('%.2F %.2F l',$xc*$k,($hp-($y+$h))*$k));
        $this->_Arc($xc - $r*$MyArc, $yc + $r, $xc - $r, $yc + $r*$MyArc, $xc - $r, $yc);
        $xc = $x+$r; $yc = $y+$r;
        $this->_out(sprintf('%.2F %.2F l',($x)*$k,($hp-$yc)*$k ));
        $this->_Arc($xc - $r, $yc - $r*$MyArc, $xc - $r*$MyArc, $yc - $r, $xc, $yc - $r);
        $this->_out($op);
    }
    function _Arc($x1, $y1, $x2, $y2, $x3, $y3){
        $h = $this->h;
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c ', $x1*$this->k, ($h-$y1)*$this->k, $x2*$this->k, ($h-$y2)*$this->k, $x3*$this->k, ($h-$y3)*$this->k));
    }
}

// 4. Generate PDF
$pdf = new ReportPDF();
$pdf->AddPage();
$pdf->UniversalHeader($exam['name']); 

if ($style === 'Playful') {
    $pdf->BodyPlayful($student, $marks, $total_obt, $total_max);
} elseif ($style === 'Modern') {
    $pdf->BodyModern($student, $marks, $total_obt, $total_max);
} else {
    $pdf->BodyStandard($student, $marks, $total_obt, $total_max);
}

$clean_exam = preg_replace('/[^a-zA-Z0-9]/', '', $exam['name']);
$filename = $student['login_id'] . "_" . $clean_exam . "_GMPS_ReportCard.pdf";
$pdf->Output('D', $filename);
?>