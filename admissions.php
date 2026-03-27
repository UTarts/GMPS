<?php
  require_once __DIR__ . '/includes/db_connect.php';

  // 1) Guidelines
  $guidelines = $conn->query(
    "SELECT label, description
     FROM admissions_guidelines
     ORDER BY display_order"
  );
  // 2) Process
  $process = $conn->query(
    "SELECT description
     FROM admissions_process
     ORDER BY step_order"
  );
  // 3) Dates
  $dates = $conn->query(
    "SELECT label, date_value
     FROM admissions_dates
     ORDER BY display_order"
  );
  // 4) Fees 
  $fees_res = $conn->query("SELECT * FROM fee_structure ORDER BY display_order");
  $fee_data = [];
  while($row = $fees_res->fetch_assoc()) {
      $fee_data[] = $row;
  }
  // Extract universal fees from the first row to display as cards
  $general_fees = $fee_data[0] ?? null;
  // 5) FAQ
  $faqs = $conn->query(
    "SELECT question, answer
     FROM admissions_faq
     ORDER BY display_order"
  );

  $currentPage = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Govind Madhav Public School - Admissions Open</title>
    <?php include 'includes/meta.php'; ?>
</head>
<body>
    
<?php include 'includes/header.php'; ?>

    <!-- Main Content -->
    <main class="admissions">
        <!-- Hero Section -->
        <section class="twa-hero-card">
            <h1>Admissions</h1>
            <p>Join us for a bright future.</p>
        </section>

        <!-- Admissions Guidelines -->
        <section class="admissions-guidelines">
            <h2>Admissions Guidelines</h2>
            <p>Ensure you meet the following criteria before applying:</p>
            <ul>
                <?php while($row = $guidelines->fetch_assoc()): ?>
                <li>
                    <strong><?= htmlspecialchars($row['label']) ?></strong>
                    <?= htmlspecialchars($row['description']) ?>
                </li>
                <?php endwhile; ?>
            </ul>
        </section>

        <!-- Admissions Process -->
        <section class="admissions-process">
            <h2>Admissions Process</h2>
            <ol>
                <?php while($row = $process->fetch_assoc()): ?>
                <li><?= htmlspecialchars($row['description']) ?></li>
                <?php endwhile; ?>
            </ol>
        </section>

        <!-- Important Dates -->
        <section class="important-dates">
            <h2>Important Dates</h2>
            <p>Keep track of the key admission dates:</p>
            <ul>
                <?php while($row = $dates->fetch_assoc()): ?>
                <li>
                    <strong><?= htmlspecialchars($row['label']) ?></strong>
                    <?= date('F j, Y', strtotime($row['date_value'])) ?>
                </li>
                <?php endwhile; ?>
            </ul>
        </section>


        <!-- Application Form -->
        <section id="admissions-form" class="admissions-form">
            <h2>Application Form</h2>
            <form id="applicationForm" class="form">
                <label for="student-name">Student's Name:</label>
                <!-- FIX: name attribute changed to "studentname" to match Sheet header -->
                <input type="text" id="student-name" name="studentname" placeholder="Enter student's name" required>

                <label for="dob">Date of Birth:</label>
                <input type="date" id="dob" name="dob" required>

                <label for="parent-name">Parent's Name:</label>
                <!-- FIX: name attribute changed to "parentname" to match Sheet header -->
                <input type="text" id="parent-name" name="parentname" placeholder="Enter parent's name" required>

                <label for="contact">Contact Number:</label>
                <input type="tel" id="contact" name="contact" placeholder="Enter contact number" required>

                <label for="email">Email Address:</label>
                <input type="email" id="email" name="email" placeholder="Enter email address">

                <label for="class">Class Applying For:</label>
                <select id="class" name="class" required>
                    <option value="k1">K1</option>
                    <option value="k2">K2</option>
                    <option value="1">Class 1</option>
                    <option value="2">Class 2</option>
                    <option value="3">Class 3</option>
                    <option value="4">Class 4</option>
                    <option value="5">Class 5</option>
                    <option value="6">Class 6</option>
                    <option value="7">Class 7</option>
                    <option value="8">Class 8</option>
                    <option value="9">Class 9</option>
                    <option value="11">Class 11</option>
                </select>

                <label for="message">Additional Notes:</label>
                <textarea id="message" name="message" rows="4" placeholder="Enter any additional information"></textarea>

                <button type="submit" id="submitButton">Submit Application</button>
            </form>

            <div id="thankYouMessage" style="display: none; margin-top: 20px; color: green;">
                Application form successfully submitted. Thank you for submitting the form. We will get back to you via phone or email as soon as possible.
            </div>
        </section>

        <!-- JavaScript to send data to Google Sheets -->
        <script>
            const form = document.getElementById('applicationForm');
            const thankYouMessage = document.getElementById('thankYouMessage');
            const submitButton = document.getElementById('submitButton');
            
            const scriptURL = 'https://script.google.com/macros/s/AKfycbzlwY2GcK2JGswDw2aqLkudqXgts6Nc9B7x0XXwI6abRQmrMw6U6OliQ6xwufOsMVnk/exec';

            form.addEventListener('submit', e => {
                e.preventDefault();
                submitButton.disabled = true;
                submitButton.textContent = 'Submitting...';
                
                fetch(scriptURL, { method: 'POST', body: new FormData(form)})
                    .then(response => response.json()) // Get the response from the script
                    .then(data => {
                        if(data.result === 'success') {
                            thankYouMessage.style.display = 'block';
                            form.reset();
                            window.scrollTo(0, form.offsetTop);
                        } else {
                            // If the script returns an error, show it
                            throw new Error(data.error || 'Unknown error from script');
                        }
                    })
                    .catch(error => {
                        console.error('Error!', error.message);
                        alert('An error occurred while submitting the form. Please try again.');
                    })
                    .finally(() => {
                        submitButton.disabled = false;
                        submitButton.textContent = 'Submit Application';
                    });
            });
        </script>


        
<section class="fee-structure" style="background: transparent; box-shadow: none; padding: 0;">
            <h2 style="text-align: center; color: var(--primary-color); margin-bottom: 25px;">Fee Structure</h2>
            
            <?php if($general_fees): ?>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 30px;">
                
                <div style="background: #fff; padding: 20px; border-radius: 12px; border-top: 4px solid var(--primary-color); box-shadow: 0 4px 10px rgba(0,0,0,0.05); text-align: center;">
                    <h4 style="margin: 0 0 5px 0; color: #555;">Admission Fee</h4>
                    <p style="margin: 0; font-size: 1.5rem; font-weight: bold; color: #222;">₹<?= number_format($general_fees['admission_fee']) ?></p>
                    <span style="font-size: 0.8rem; font-weight: 600; color: var(--primary-color);">For New Admissions Only</span>
                </div>
                
                <div style="background: #fff; padding: 20px; border-radius: 12px; border-top: 4px solid #e76910; box-shadow: 0 4px 10px rgba(0,0,0,0.05); text-align: center;">
                    <h4 style="margin: 0 0 5px 0; color: #555;">Session Fee</h4>
                    <p style="margin: 0; font-size: 1.5rem; font-weight: bold; color: #222;">₹<?= number_format($general_fees['session_fee']) ?></p>
                    <span style="font-size: 0.8rem; font-weight: 600; color: #e76910;">All Students (Yearly)</span>
                </div>
                
                <div style="background: #fff; padding: 20px; border-radius: 12px; border-top: 4px solid #28a745; box-shadow: 0 4px 10px rgba(0,0,0,0.05); text-align: center;">
                    <h4 style="margin: 0 0 5px 0; color: #555;">Exam Fee</h4>
                    <p style="margin: 0; font-size: 1.5rem; font-weight: bold; color: #222;">₹<?= number_format($general_fees['exam_fee']) ?></p>
                    <span style="font-size: 0.8rem; font-weight: 600; color: #28a745;">All Students (Yearly)</span>
                </div>

                <div style="background: #fff; padding: 20px; border-radius: 12px; border-top: 4px solid #17a2b8; box-shadow: 0 4px 10px rgba(0,0,0,0.05); text-align: center;">
                    <h4 style="margin: 0 0 5px 0; color: #555;">Online Services</h4>
                    <p style="margin: 0; font-size: 1.5rem; font-weight: bold; color: #222;">₹<?= number_format($general_fees['online_services']) ?></p>
                    <span style="font-size: 0.8rem; font-weight: 600; color: #17a2b8;">All Students (Yearly)</span>
                </div>
                
                <div style="background: #fff; padding: 20px; border-radius: 12px; border-top: 4px solid #6a11cb; box-shadow: 0 4px 10px rgba(0,0,0,0.05); text-align: center;">
                    <h4 style="margin: 0 0 5px 0; color: #555;">Student Kit</h4>
                    <p style="margin: 0; font-weight: bold; color: #222; font-size: 1.1rem;">
                        New Students: ₹<?= number_format($general_fees['kit_new']) ?> <br> 
                        Old Students: ₹<?= number_format($general_fees['kit_old']) ?>
                    </p>
                </div>

            </div>
            <?php endif; ?>

            <h3 style="text-align: center; margin-bottom: 15px; color: #333;">Monthly Tuition Fee</h3>
            
            <div style="background: #fff; border-radius: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); overflow: hidden; max-width: 600px; width: 100%; margin: 0 auto; display: block;">
                <table style="width: 100%; min-width: 100%; border-collapse: collapse; margin: 0 auto;">
                    <thead>
                        <tr style="background: var(--primary-color);">
                            <th style="width: 50%; padding: 15px; text-align: center; color: #fff; border-bottom: 2px solid #eee; text-transform: uppercase; letter-spacing: 1px;">Class</th>
                            <th style="width: 50%; padding: 15px; text-align: center; color: #fff; border-bottom: 2px solid #eee; text-transform: uppercase; letter-spacing: 1px;">Monthly Fee</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($fee_data as $f): ?>
                        <tr style="border-bottom: 1px solid #f0f0f0;">
                            <td style="width: 50%; padding: 15px; text-align: center; font-weight: 600; color: #333; font-size: 1.05rem;">
                                <?= htmlspecialchars($f['class_name']) ?>
                            </td>
                            <td style="width: 50%; padding: 15px; text-align: center; color: var(--primary-color); font-weight: bold; font-size: 1.1rem;">
                                ₹<?= number_format($f['monthly_fee']) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!--  faq section code   -->
        <section class="admissions-faq">
            <h2>Frequently Asked Questions</h2>
            <?php while($q = $faqs->fetch_assoc()): ?>
                <div class="faq-item">
                    <h3 class="faq-question"><?= htmlspecialchars($q['question']) ?></h3>
                    <p class="faq-answer"><?= $q['answer'] /* contains safe HTML */ ?></p>
                </div>
            <?php endwhile; ?>
        </section>
        
        <script>
            // Toggle FAQ answers
            document.querySelectorAll(".faq-question").forEach(question => {
                question.addEventListener("click", () => {
                    const answer = question.nextElementSibling;
                    const isVisible = answer.style.display === "block";
                    
                    document.querySelectorAll(".faq-answer").forEach(ans => {
                        ans.style.display = "none";
                    });
                    
                    answer.style.display = isVisible ? "none" : "block";
                });
            });
        </script>

        <!-- Contact Section -->
        <section class="admissions-contact">
            <h2>Contact Us</h2>
            <p>If you have any queries, feel free to reach out:</p>
            <a href="contact.php"> CLICK HERE</a>
        </section>
    </main>

    <?php include 'footer.php'; ?>
</body>
</html>
