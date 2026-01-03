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
  $fees = $conn->query(
    "SELECT class_name, tuition_fee, registration_fee, misc_fee
     FROM fee_structure
     ORDER BY display_order"
  );
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
        <section class="admissions-hero">
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


        
        <!-- Fee Structure -->
        <section class="fee-structure">
            <h2>Fee Structure</h2>
            <table>
                <thead>
                    <tr>
                        <th>Class</th>
                        <th>Tuition Fee (Monthly)</th>
                        <th>Registration Fee (yearly)</th>
                        <th>Miscellaneous Fee (yearly)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($f = $fees->fetch_assoc()): ?>
                    <tr>
                        <td><?= htmlspecialchars($f['class_name']) ?></td>
                        <td>₹<?= number_format($f['tuition_fee']) ?></td>
                        <td>₹<?= number_format($f['registration_fee']) ?></td>
                        <td>₹<?= number_format($f['misc_fee']) ?></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
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
