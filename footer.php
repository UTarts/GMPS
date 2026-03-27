<?php
// Fetch contact details from the database securely
$phone = '';
$email = '';
$whatsapp_number = '';

if (isset($conn)) {
    $contact_res = $conn->query("SELECT method, value FROM contact_methods");
    if ($contact_res) {
        while ($row = $contact_res->fetch_assoc()) {
            if ($row['method'] === 'phone') $phone = $row['value'];
            if ($row['method'] === 'email') $email = $row['value'];
            if ($row['method'] === 'whatsapp') {
                $whatsapp_number = preg_replace('/[^0-9]/', '', $row['value']);
            }
        }
    }
}
?>

<style>
/* =========================================
   PREMIUM SCHOOL FOOTER (Agency-Inspired)
   ========================================= */
.premium-school-footer {
    background: linear-gradient(145deg,rgb(24, 24, 30) 0%, #1e293b 100%);
    color: #ffffff;
    border-top-left-radius: 3rem;
    border-top-right-radius: 3rem;
    padding: 5rem 1.5rem 2rem 1.5rem;
    margin-top: 4rem;
    font-family: 'Poppins', sans-serif;
    position: relative;
    z-index: 10;
    box-shadow: 0 -10px 40px rgba(0,0,0,0.1);
}

/* --- Top CTA Section --- */
.psf-cta {
    max-width: 60rem;
    margin: 0 auto;
    text-align: center;
    border-bottom: 1px solid rgba(255,255,255,0.05);
    padding-bottom: 4rem;
    margin-bottom: 4rem;
}
.psf-title {
    font-size: clamp(2rem, 5vw, 3.5rem);
    font-weight: 900;
    margin-bottom: 1rem;
    color: #ffffff;
    line-height: 1.1;
}
.psf-title span {
    color: var(--accent-color, #f97b22);
}
.psf-subtitle {
    color: #94a3b8;
    max-width: 32rem;
    margin: 0 auto 2.5rem auto;
    font-size: 1rem;
    line-height: 1.6;
}

.psf-buttons {
    display: flex;
    justify-content: center;
    gap: 1rem;
    flex-wrap: wrap;
}
.psf-btn-primary {
    background-color: var(--accent-color, #f97b22);
    color: #ffffff;
    font-weight: 700;
    padding: 1rem 2.5rem;
    border-radius: 1rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    text-decoration: none;
    transition: all 0.3s ease;
    box-shadow: 0 10px 20px rgba(249, 123, 34, 0.2);
}
.psf-btn-primary:hover {
    transform: translateY(-3px);
    box-shadow: 0 15px 25px rgba(249, 123, 34, 0.3);
    background-color: #e76910;
}

/* --- Footer Grid --- */
.psf-grid {
    display: grid;
    grid-template-columns: 1.5fr 1fr 1fr 1fr;
    gap: 3rem;
    max-width: 1200px;
    margin: 0 auto;
    text-align: left;
}

/* Brand Column */
.psf-brand-header {
    display: flex;
    align-items: center;
    gap: 15px;
    margin-bottom: 1.5rem;
}
.psf-school-logo {
    width: 45px;
    height: 45px;
    object-fit: contain;
    background: #ffffff;
    border-radius: 50%;
}
.psf-brand-name {
    font-weight: 800;
    font-size: 1.3rem;
    line-height: 1.2;
    color: #ffffff;
}
.psf-col-brand p {
    color: #94a3b8;
    font-size: 0.9rem;
    line-height: 1.6;
    margin-bottom: 1.5rem;
}

/* Social Icons */
.psf-socials {
    display: flex;
    gap: 12px;
}
.psf-socials a {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    background: rgba(255,255,255,0.05);
    border-radius: 50%;
    transition: all 0.3s ease;
}
.psf-socials a img {
    width: 20px;
    height: 20px;
    opacity: 0.8;
    transition: opacity 0.3s ease;
}
.psf-socials a:hover {
    background: var(--accent-color, #f97b22);
    transform: translateY(-3px);
}
.psf-socials a:hover img {
    opacity: 1;
}

/* Link Columns */
.psf-col h4 {
    color: #ffffff;
    font-weight: 700;
    font-size: 1rem;
    margin-bottom: 1.5rem;
    position: relative;
    padding-bottom: 10px;
}
.psf-col h4::after {
    content: '';
    position: absolute;
    left: 0;
    bottom: 0;
    width: 30px;
    height: 2px;
    background-color: var(--accent-color, #f97b22);
}
.psf-col ul {
    list-style: none;
    padding: 0;
    margin: 0;
}
.psf-col ul li {
    margin-bottom: 1rem;
    color: #94a3b8;
    font-size: 0.9rem;
}
.psf-col ul li a {
    color: #94a3b8;
    text-decoration: none;
    transition: color 0.3s ease, transform 0.3s ease;
    display: inline-block;
}
.psf-col ul li a:hover {
    color: #ffffff;
    transform: translateX(4px);
}
.psf-col ul li strong {
    color: #cbd5e1;
    display: block;
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-bottom: 2px;
}

/* --- Bottom Bar & UT Arts Signature --- */
.psf-bottom {
    max-width: 1200px;
    margin: 4rem auto 0 auto;
    padding-top: 2rem;
    border-top: 1px solid rgba(255,255,255,0.05);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1.5rem;
}
.psf-copyright {
    color: #64748b;
    font-size: 0.8rem;
    font-weight: 500;
}

/* UT Arts Badge */
.utarts-badge {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    font-size: 0.85rem;
    color: #94a3b8;
}
.utarts-badge a {
    font-weight: 700;
    color: #3b82f6; 
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 6px;
    transition: color 0.3s ease;
}
.utarts-badge a:hover {
    color: #60a5fa; 
}
.utarts-logo {
    height: 24px;
    width: 24px;
    border-radius: 50%;
    object-fit: cover;
    border: 1px solid #4b5563;
}

/* --- Mobile Responsiveness --- */
@media (max-width: 768px) {
    .premium-school-footer {
        padding: 4rem 1.5rem 2rem 1.5rem;
        border-top-left-radius: 2rem;
        border-top-right-radius: 2rem;
    }
    .psf-cta {
        padding-bottom: 3rem;
        margin-bottom: 3rem;
    }
    .psf-grid {
        grid-template-columns: 1fr;
        gap: 2.5rem;
    }
    .psf-bottom {
        flex-direction: column;
        text-align: center;
        margin-top: 3rem;
        gap: 1rem;
    }
    .utarts-badge {
        flex-direction: column;
        gap: 6px;
    }
}
</style>

<footer class="premium-school-footer">
    <div class="psf-cta">
        <h2 class="psf-title">Shape Their <span>Future</span></h2>
        <p class="psf-subtitle">Join the parents who trust Govind Madhav Public School for modern education alongwith traditional values, disciplined growth, and future-ready skills.</p>
        <div class="psf-buttons">
            <a href="admissions.php#admissions-form" class="psf-btn-primary">Apply for Admissions</a>
        </div>
    </div>

    <div class="psf-grid">
        <div class="psf-col-brand">
            <div class="psf-brand-header">
                <img src="GMPSimages/logo.png" alt="GMPS Logo" class="psf-school-logo" onerror="this.src='GMPSimages/gmps_logo.png'; this.onerror=null;">
                <span class="psf-brand-name">Govind Madhav<br>Public School</span>
            </div>
            <p>Premier English-medium education committed to nurturing every child's potential into a future leader.</p>
            
            <div class="psf-socials">
                <a href="https://www.facebook.com/share/15vCcE1Qsv/" target="_blank" title="Facebook"><img src="https://img.icons8.com/ios-filled/50/ffffff/facebook-new.png" alt="Facebook"></a>
                <a href="https://www.instagram.com/govindmadhavpublicschool?utm_source=ig_web_button_share_sheet&igsh=ZDNlZDc0MzIxNw==" target="_blank" title="Instagram"><img src="https://img.icons8.com/ios-filled/50/ffffff/instagram-new.png" alt="Instagram"></a>
                <a href="https://youtube.com/@govindmadhav2018?si=ON-LgXezQr-xDgsH" target="_blank" title="YouTube"><img src="https://img.icons8.com/ios-filled/50/ffffff/youtube-play.png" alt="YouTube"></a>
                <a href="#" target="_blank" title="LinkedIn"><img src="https://img.icons8.com/ios-filled/50/ffffff/linkedin.png" alt="LinkedIn"></a>
            </div>
        </div>

        <div class="psf-col">
            <h4>Explore</h4>
            <ul>
                <li><a href="index.php">Home</a></li>
                <li><a href="about.php">About Us</a></li>
                <li><a href="gallery.php">Campus Gallery</a></li>
                <li><a href="events.php">News & Updates</a></li>
            </ul>
        </div>

        <div class="psf-col">
            <h4>Contact</h4>
            <ul>
                <li>
                    <strong>Campus Location</strong>
                    Gondey, Pratapgarh,<br>Sultanpur Highway, UP
                </li>
                <li>
                    <strong>Email Us</strong>
                    <a href="mailto:<?= htmlspecialchars($email) ?>"><?= htmlspecialchars($email) ?: 'info@govindmadhav.com' ?></a>
                </li>
                <li>
                    <strong>Call Us</strong>
                    <a href="tel:<?= htmlspecialchars($phone) ?>"><?= htmlspecialchars($phone) ?: '+91 99846 61166' ?></a>
                </li>
            </ul>
        </div>

        <div class="psf-col">
            <h4>Legal</h4>
            <ul>
                <li><a href="#">Privacy Policy</a></li>
                <li><a href="#">Terms & Conditions</a></li>
                <li style="margin-top: 1.5rem; font-size: 0.75rem; color: #64748b; border-top: 1px solid rgba(255,255,255,0.05); padding-top: 1rem;">
                    Recognized Educational Entity<br>Established 2018
                </li>
            </ul>
        </div>
    </div>

    <div class="psf-bottom">
        <p class="psf-copyright">© <?= date('Y') ?> Govind Madhav Public School. All Rights Reserved.</p>
        
        <span class="utarts-badge">
            Designed & Developed by
            <a href="https://www.utarts.in" target="_blank" rel="noopener noreferrer">
                <img src="https://www.utarts.in/images/UTArt_Logo.webp" alt="UT Arts Logo" class="utarts-logo"/>
                UT Arts
                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
            </a>
        </span>
    </div>
</footer>

<?php if (!empty($whatsapp_number)): ?>
<a href="https://wa.me/<?= $whatsapp_number ?>" class="whatsapp-sticky-button" target="_blank" title="Contact us on WhatsApp">
    <img src="https://upload.wikimedia.org/wikipedia/commons/6/6b/WhatsApp.svg" alt="WhatsApp">
</a>
<?php endif; ?>

<button id="backToTop" title="Go to top" style="position: fixed; bottom: 2rem; right: 1.5rem; background-color: #334155; color: white; border: none; border-radius: 50%; width: 50px; height: 50px; font-size: 1.5rem; cursor: pointer; box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2); opacity: 0; visibility: hidden; transition: opacity 0.3s ease, visibility 0.3s ease; z-index: 1000; display: flex; align-items: center; justify-content: center;">↑</button>

<script>
    const backToTopButton = document.getElementById("backToTop");
    window.addEventListener("scroll", () => {
        if (window.scrollY > 300) {
            backToTopButton.style.opacity = "1";
            backToTopButton.style.visibility = "visible";
        } else {
            backToTopButton.style.opacity = "0";
            backToTopButton.style.visibility = "hidden";
        }
    });
    backToTopButton.addEventListener("click", () => {
        window.scrollTo({ top: 0, behavior: "smooth" });
    });
</script>