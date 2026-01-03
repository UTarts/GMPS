<?php
// This code assumes db_connect.php is already included on the parent page.
$phone = '';
$email = '';
$whatsapp_number = '';

// Fetch contact details from the database
$contact_res = $conn->query("SELECT method, value FROM contact_methods");
if ($contact_res) {
    while ($row = $contact_res->fetch_assoc()) {
        if ($row['method'] === 'phone') $phone = $row['value'];
        if ($row['method'] === 'email') $email = $row['value'];
        if ($row['method'] === 'whatsapp') {
            // Remove non-numeric characters for the link
            $whatsapp_number = preg_replace('/[^0-9]/', '', $row['value']);
        }
    }
}
?>
    
<footer>
    <p>&copy; <?= date('Y') ?> Govind Madhav Public School</p>
    <p>Contact: <a style="color: orangered;" href="tel:<?= htmlspecialchars($phone) ?>">
    <?= htmlspecialchars($phone) ?>
</a> | Email: <a style="color: orangered;" href="mailto:<?= htmlspecialchars($email) ?>"><?= htmlspecialchars($email) ?></a></p>
    
    <div class="social-links">
        <a href="https://www.facebook.com/share/15vCcE1Qsv/" target="_blank">
            <img src="https://img.icons8.com/ios/50/ffffff/facebook.png" alt="Facebook" />
        </a>
        <a href="https://youtube.com/@govindmadhav2018?si=ON-LgXezQr-xDgsH" target="_blank">
            <img src="https://img.icons8.com/ios/50/ffffff/youtube.png" alt="YouTube" />
        </a>
    </div>
    <div style="font-family:Arial,sans-serif;padding-top:15px;padding-bottom:70px;margin-top:20px;border-top:1px solid #eaeaea;"><a href="https://www.utarts.in" target="_blank" rel="noopener noreferrer" style="display:block;text-align:center;text-decoration:none;color:#888;font-size:12px;"><img src="https://utarts.in/images/poweredbyutarts.webp" alt="Powered by UT Arts" style="display:block;margin-left:auto;margin-right:auto;height:50px;width:auto;border:0;margin-bottom:0;"><br>visit www.utarts.in</a></div>
</footer>

<!-- NEW: Sticky WhatsApp Button -->
<?php if (!empty($whatsapp_number)): ?>
<a href="https://wa.me/<?= $whatsapp_number ?>" class="whatsapp-sticky-button" target="_blank" title="Contact us on WhatsApp">
    <img src="https://upload.wikimedia.org/wikipedia/commons/6/6b/WhatsApp.svg" alt="WhatsApp">
</a>
<?php endif; ?>


<!-- Back-to-Top Button -->
<button id="backToTop" title="Go to top">↑</button>
<script>
    const backToTopButton = document.getElementById("backToTop");
    window.addEventListener("scroll", () => {
        if (window.scrollY > 300) {
            backToTopButton.classList.add("show");
        } else {
            backToTopButton.classList.remove("show");
        }
    });
    backToTopButton.addEventListener("click", () => {
        window.scrollTo({ top: 0, behavior: "smooth" });
    });
</script>
