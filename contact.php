<?php
require_once __DIR__ . '/includes/db_connect.php';
$currentPage = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Contact Us - Govind Madhav Public School</title>
    <?php include 'includes/meta.php'; ?>
</head>
<body>

<?php include 'includes/header.php'; ?>

    <section class="twa-hero-card">
        <h1>Contact Us</h1>
        <p>We’re here to help. Reach out to us anytime or leave your valuable feedback below.</p>
    </section>

    <?php
    $methods = [];
    $res = $conn->query("SELECT method, value FROM contact_methods ORDER BY display_order");
    while ($row = $res->fetch_assoc()) {
        $methods[$row['method']] = $row['value'];
    }
    ?>
    
    <section class="contact-methods">
        <h2>Get in Touch</h2>
        <div class="contact-options">
            <div class="contact-item">
                <h3>Phone</h3>
                <p>Call us at: <a href="tel:<?=preg_replace('/\s+/','',($methods['phone'] ?? ''))?>">
                    <?=htmlspecialchars($methods['phone'] ?? 'Not Available')?>
                </a></p>
            </div>
            <div class="contact-item">
                <h3>Email</h3>
                <p>Email us at: <a href="mailto:<?=htmlspecialchars($methods['email'] ?? '')?>">
                    <?=htmlspecialchars($methods['email'] ?? 'Not Available')?>
                </a></p>
            </div>
            <div class="contact-item">
                <h3>WhatsApp</h3>
                <p>Message us on WhatsApp: 
                    <a href="https://wa.me/<?=preg_replace('/\D+/','',($methods['whatsapp'] ?? ''))?>" target="_blank">
                    <?=htmlspecialchars($methods['whatsapp'] ?? 'Not Available')?>
                    </a>
                </p>
            </div>
        </div>
    </section>

    <section class="address-section">
        <h2>Our Address</h2>
        <p>Govind Madhav Public School<br>Pratapgarh-Sultanpur highway, opposite reliance petrol pump, GONDEY, PRATAPGARH, UTTAR PRADESH, INDIA<br>Pincode: 230403</p>
        <div class="map">
            <iframe 
                src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3587.653912185866!2d82.0298688742613!3d25.94685650190868!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x399a9b7f54719223%3A0x8f886f21369529b5!2sGovind%20Madhav%20Public%20School!5e0!3m2!1sen!2sin!4v1695048655385!5m2!1sen!2sin" 
                width="600" height="450" style="border:0;" allowfullscreen="" loading="lazy">
            </iframe>
        </div>
        <a href="https://maps.app.goo.gl/uP9DBd5qg7a2zPjZ7" target="_blank">View on Google Maps</a>
    </section>

    <section class="feedback-section">
        <h2>Feedback Form</h2>
        <form id="feedbackForm" class="form">
            <label for="name">Name:</label>
            <input type="text" id="name" name="name" placeholder="Enter your name" required>

            <label for="contact">Contact Number:</label>
            <input type="tel" id="contact" name="contact" placeholder="Enter your contact number" required>

            <label for="feedback">Feedback:</label>
            <textarea id="feedback" name="feedback" placeholder="Share your thoughts or concerns" rows="4" required></textarea>

            <button type="submit" id="submitButton">Submit Feedback</button>
        </form>
         <div id="thankYouMessage" style="display: none; margin-top: 20px; color: green; font-weight: bold; text-align: center;">
            Thank you for your feedback! We have received your submission.
        </div>
    </section>
    
    <?php include 'footer.php'; ?>
    
    <a href="admissions.php#admissions-form" id="ctaButton">Apply Now</a>

    <script>
        const feedbackForm = document.getElementById('feedbackForm');
        const thankYouMsg = document.getElementById('thankYouMessage');
        const submitBtn = document.getElementById('submitButton');
        
        const feedbackScriptURL = 'https://script.google.com/macros/s/AKfycby6y9BdNr6Dt3kMjlogbvBaTCZTrLWVroVuJrDy0G-GuvFCNmC0mckvYc3hMzraDcKl/exec';

        feedbackForm.addEventListener('submit', e => {
            e.preventDefault();
            submitBtn.disabled = true;
            submitBtn.textContent = 'Submitting...';
            
            fetch(feedbackScriptURL, { method: 'POST', body: new FormData(feedbackForm)})
                .then(response => {
                    thankYouMsg.style.display = 'block';
                    feedbackForm.reset();
                })
                .catch(error => {
                    console.error('Error!', error.message);
                    alert('An error occurred. Please try again.');
                })
                .finally(() => {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Submit Feedback';
                });
        });
    </script>
</body>
</html>