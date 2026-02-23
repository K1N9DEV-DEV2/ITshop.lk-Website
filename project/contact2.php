<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$page_title = 'Contact Us - IT Shop.LK';

$success_msg = '';
$error_msg   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name    = trim($_POST['name']    ?? '');
    $email   = trim($_POST['email']   ?? '');
    $phone   = trim($_POST['phone']   ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if ($name && $email && $message) {
        // ── Mail setup ──────────────────────────────────────────
        $to      = 'info@itshop.lk'; // ← change to your email
        $headers = "From: {$name} <{$email}>\r\nReply-To: {$email}\r\nContent-Type: text/plain; charset=UTF-8";
        $body    = "Name: {$name}\nEmail: {$email}\nPhone: {$phone}\nSubject: {$subject}\n\nMessage:\n{$message}";

        if (mail($to, "IT Shop Contact: {$subject}", $body, $headers)) {
            $success_msg = 'Message sent! We\'ll get back to you within 24 hours.';
        } else {
            $error_msg = 'Sorry, something went wrong. Please try WhatsApp or call us directly.';
        }
    } else {
        $error_msg = 'Please fill in Name, Email and Message fields.';
    }
}

include 'header.php';
?>

<style>
    @import url('https://fonts.googleapis.com/css2?family=Red+Hat+Display:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,700&display=swap');

    :root {
        --ink:          #0a0a0f;
        --ink-soft:     #3d3d50;
        --ink-muted:    #8888a0;
        --surface:      #f6f6f8;
        --card:         #ffffff;
        --accent:       #0cb100;
        --accent-dark:  #098600;
        --accent-light: #eef2ff;
        --accent-glow:  rgba(17,255,0,0.18);
        --radius-xl:    24px;
        --radius-lg:    16px;
        --radius-md:    12px;
        --shadow-card:  0 2px 12px rgba(10,10,15,0.07), 0 0 0 1px rgba(10,10,15,0.05);
        --shadow-hover: 0 16px 40px rgba(10,10,15,0.13), 0 0 0 1px rgba(12,177,0,0.15);
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }

    body {
        font-family: 'Red Hat Display', sans-serif;
        background: var(--surface);
        color: var(--ink);
        -webkit-font-smoothing: antialiased;
    }

    /* ── PAGE HERO ──────────────────────────────────────────── */
    .page-hero {
        background: var(--ink);
        padding: 120px 2rem 80px;
        text-align: center;
        position: relative;
        overflow: hidden;
    }
    .page-hero::before {
        content: '';
        position: absolute;
        width: 500px; height: 500px;
        border-radius: 50%;
        background: radial-gradient(circle, rgba(70,229,91,0.28) 0%, transparent 70%);
        top: -150px; right: -80px;
        pointer-events: none;
        filter: blur(80px);
    }
    .page-hero::after {
        content: '';
        position: absolute;
        inset: 0;
        background-image:
            linear-gradient(rgba(255,255,255,0.025) 1px, transparent 1px),
            linear-gradient(90deg, rgba(255,255,255,0.025) 1px, transparent 1px);
        background-size: 60px 60px;
        pointer-events: none;
    }
    .page-hero-inner { position: relative; z-index: 2; }

    .page-eyebrow {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: rgba(255,255,255,0.08);
        border: 1px solid rgba(110,229,70,0.3);
        color: #d5f9ff;
        font-size: 0.78rem;
        font-weight: 700;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        padding: 5px 16px;
        border-radius: 100px;
        margin-bottom: 1.5rem;
        animation: fadeDown 0.6s ease both;
    }
    .page-eyebrow span { width: 6px; height: 6px; background: #11ff00; border-radius: 50%; }

    .page-title {
        font-size: clamp(2.4rem, 6vw, 4rem);
        font-weight: 800;
        color: #fff;
        letter-spacing: -0.03em;
        line-height: 1.05;
        animation: fadeDown 0.6s 0.1s ease both;
    }
    .page-title em {
        font-style: normal;
        background: linear-gradient(135deg, #1eff00 0%, #4f87bc 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }
    .page-sub {
        margin-top: 1rem;
        color: #9999b8;
        font-size: 1.05rem;
        animation: fadeDown 0.6s 0.2s ease both;
    }

    /* ── LAYOUT ─────────────────────────────────────────────── */
    .contact-wrap {
        max-width: 1160px;
        margin: 0 auto;
        padding: 5rem 2rem 6rem;
        display: grid;
        grid-template-columns: 1fr 1.6fr;
        gap: 2.5rem;
        align-items: start;
    }
    @media (max-width: 860px) {
        .contact-wrap { grid-template-columns: 1fr; }
    }

    /* ── INFO PANEL ─────────────────────────────────────────── */
    .info-panel {
        display: flex;
        flex-direction: column;
        gap: 1.25rem;
    }

    .info-card {
        background: var(--card);
        border-radius: var(--radius-xl);
        padding: 1.75rem;
        box-shadow: var(--shadow-card);
        display: flex;
        align-items: flex-start;
        gap: 1.1rem;
        opacity: 0;
        transform: translateX(-20px);
        transition: opacity 0.5s ease, transform 0.5s ease;
    }
    .info-card.visible {
        opacity: 1;
        transform: translateX(0);
    }
    .info-icon {
        width: 48px;
        height: 48px;
        border-radius: var(--radius-md);
        background: var(--accent-light);
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        transition: background 0.3s ease;
    }
    .info-card:hover .info-icon { background: var(--accent); }
    .info-icon i { font-size: 1.2rem; color: var(--accent); transition: color 0.3s ease; }
    .info-card:hover .info-icon i { color: #fff; }

    .info-content {}
    .info-label {
        font-size: 0.75rem;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: var(--ink-muted);
        margin-bottom: 0.35rem;
    }
    .info-value {
        font-size: 0.97rem;
        font-weight: 600;
        color: var(--ink);
        line-height: 1.5;
    }
    .info-value a {
        color: var(--ink);
        text-decoration: none;
    }
    .info-value a:hover { color: var(--accent); }

    /* Map placeholder */
    .map-card {
        background: var(--card);
        border-radius: var(--radius-xl);
        overflow: hidden;
        box-shadow: var(--shadow-card);
        opacity: 0;
        transform: translateX(-20px);
        transition: opacity 0.5s 0.3s ease, transform 0.5s 0.3s ease;
        aspect-ratio: 4/3;
    }
    .map-card.visible { opacity: 1; transform: translateX(0); }
    .map-card iframe { width: 100%; height: 100%; border: 0; display: block; }

    /* ── FORM CARD ──────────────────────────────────────────── */
    .form-card {
        background: var(--card);
        border-radius: var(--radius-xl);
        padding: 2.5rem;
        box-shadow: var(--shadow-card);
        opacity: 0;
        transform: translateY(24px);
        transition: opacity 0.55s 0.1s ease, transform 0.55s 0.1s ease;
    }
    .form-card.visible { opacity: 1; transform: translateY(0); }

    .form-heading {
        font-size: 1.5rem;
        font-weight: 800;
        color: var(--ink);
        letter-spacing: -0.02em;
        margin-bottom: 0.4rem;
    }
    .form-sub {
        font-size: 0.9rem;
        color: var(--ink-muted);
        margin-bottom: 2rem;
    }

    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
    }
    @media (max-width: 560px) { .form-row { grid-template-columns: 1fr; } }

    .form-group {
        display: flex;
        flex-direction: column;
        gap: 0.45rem;
        margin-bottom: 1.1rem;
    }
    .form-label {
        font-size: 0.82rem;
        font-weight: 700;
        color: var(--ink-soft);
        letter-spacing: 0.02em;
    }
    .form-label span { color: var(--accent); }

    .form-control {
        font-family: 'Red Hat Display', sans-serif;
        font-size: 0.95rem;
        font-weight: 500;
        color: var(--ink);
        background: var(--surface);
        border: 1.5px solid rgba(10,10,15,0.1);
        border-radius: var(--radius-md);
        padding: 12px 16px;
        outline: none;
        transition: border-color 0.25s ease, box-shadow 0.25s ease, background 0.25s ease;
        width: 100%;
    }
    .form-control:focus {
        border-color: var(--accent);
        background: #fff;
        box-shadow: 0 0 0 4px var(--accent-glow);
    }
    .form-control::placeholder { color: var(--ink-muted); }

    textarea.form-control {
        resize: vertical;
        min-height: 140px;
    }

    /* subject select */
    select.form-control {
        cursor: pointer;
        appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%238888a0' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 14px center;
        padding-right: 40px;
    }

    .btn-submit {
        width: 100%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        background: var(--accent);
        color: #fff;
        font-family: 'Red Hat Display', sans-serif;
        font-weight: 700;
        font-size: 1rem;
        padding: 15px 28px;
        border-radius: var(--radius-md);
        border: none;
        cursor: pointer;
        transition: all 0.25s ease;
        box-shadow: 0 4px 20px rgba(13,255,0,0.35);
        margin-top: 0.5rem;
    }
    .btn-submit:hover {
        background: var(--accent-dark);
        transform: translateY(-2px);
        box-shadow: 0 8px 28px rgba(13,255,0,0.4);
    }
    .btn-submit:active { transform: translateY(0); }

    /* ── ALERT MESSAGES ─────────────────────────────────────── */
    .alert {
        border-radius: var(--radius-md);
        padding: 14px 18px;
        font-size: 0.92rem;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 1.5rem;
        animation: fadeDown 0.4s ease both;
    }
    .alert-success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
    .alert-error   { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

    /* ── WHATSAPP FAB ───────────────────────────────────────── */
    .wa-btn {
        position: fixed;
        bottom: 24px; right: 24px;
        width: 56px; height: 56px;
        background: #25d366;
        color: white;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
        z-index: 9999;
        text-decoration: none;
        box-shadow: 0 6px 20px rgba(37,211,102,0.45);
        transition: transform 0.25s ease, box-shadow 0.25s ease;
    }
    .wa-btn:hover {
        transform: scale(1.1);
        box-shadow: 0 10px 30px rgba(37,211,102,0.55);
        color: white;
        text-decoration: none;
    }

    /* ── SOCIAL STRIP ───────────────────────────────────────── */
    .social-strip {
        display: flex;
        gap: 10px;
        margin-top: 0.5rem;
    }
    .social-btn {
        width: 40px; height: 40px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
        text-decoration: none;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
        color: #fff;
    }
    .social-btn:hover { transform: translateY(-3px); color: #fff; text-decoration: none; }
    .social-fb   { background: #1877f2; }
    .social-ig   { background: linear-gradient(135deg,#f09433,#e6683c,#dc2743,#cc2366,#bc1888); }
    .social-tw   { background: #1da1f2; }
    .social-wa   { background: #25d366; }

    /* ── DIVIDER ─────────────────────────────────────────────── */
    .form-divider {
        display: flex;
        align-items: center;
        gap: 12px;
        margin: 1.5rem 0;
        color: var(--ink-muted);
        font-size: 0.82rem;
        font-weight: 600;
        letter-spacing: 0.05em;
    }
    .form-divider::before,
    .form-divider::after {
        content: '';
        flex: 1;
        height: 1px;
        background: rgba(10,10,15,0.1);
    }

    /* ── ANIMATION ──────────────────────────────────────────── */
    @keyframes fadeDown {
        from { opacity: 0; transform: translateY(-14px); }
        to   { opacity: 1; transform: translateY(0); }
    }
</style>

<!-- PAGE HERO -->
<section class="page-hero">
    <div class="page-hero-inner">
        <div class="page-eyebrow"><span></span>We're Here to Help</div>
        <h1 class="page-title">Get in <em>Touch</em></h1>
        <p class="page-sub">Questions about products? Need a quote? We reply within 24 hours.</p>
    </div>
</section>

<!-- CONTACT BODY -->
<div class="contact-wrap">

    <!-- LEFT: Info cards + map -->
    <div class="info-panel">

        <div class="info-card">
            <div class="info-icon"><i class="fas fa-phone-alt"></i></div>
            <div class="info-content">
                <div class="info-label">Call / WhatsApp</div>
                <div class="info-value">
                    <a href="tel:+94XXXXXXXXX">+94 XX XXX XXXX</a><br>
                    <a href="tel:+94XXXXXXXXX">+94 XX XXX XXXX</a>
                </div>
            </div>
        </div>

        <div class="info-card">
            <div class="info-icon"><i class="fas fa-envelope"></i></div>
            <div class="info-content">
                <div class="info-label">Email</div>
                <div class="info-value">
                    <a href="mailto:info@itshop.lk">info@itshop.lk</a><br>
                    <a href="mailto:sales@itshop.lk">sales@itshop.lk</a>
                </div>
            </div>
        </div>

        <div class="info-card">
            <div class="info-icon"><i class="fas fa-clock"></i></div>
            <div class="info-content">
                <div class="info-label">Opening Hours</div>
                <div class="info-value">
                    Mon – Fri: 9:00 AM – 6:00 PM<br>
                    Sat: 9:00 AM – 4:00 PM<br>
                    Sun: Closed
                </div>
            </div>
        </div>

        <div class="info-card">
            <div class="info-icon"><i class="fas fa-share-alt"></i></div>
            <div class="info-content">
                <div class="info-label">Follow Us</div>
                <div class="social-strip">
                    <a href="#" class="social-btn social-fb" aria-label="Facebook"><i class="fab fa-facebook-f"></i></a>
                    <a href="#" class="social-btn social-ig" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
                    <a href="#" class="social-btn social-tw" aria-label="Twitter"><i class="fab fa-twitter"></i></a>
                    <a href="https://wa.me/94XXXXXXXXX" class="social-btn social-wa" aria-label="WhatsApp"><i class="fab fa-whatsapp"></i></a>
                </div>
            </div>
        </div>

        <!-- Embedded Google Map — replace src with your actual embed URL -->
         

    </div><!-- /info-panel -->

    <!-- RIGHT: Contact form -->
    <div class="form-card">
        <div class="form-heading">Send Us a Message</div>
        <p class="form-sub">Fill out the form and our team will contact you as soon as possible.</p>

        <?php if ($success_msg): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_msg); ?></div>
        <?php elseif ($error_msg): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_msg); ?></div>
        <?php endif; ?>

        <form method="POST" action="contact.php" novalidate>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="name">Full Name <span>*</span></label>
                    <input type="text" id="name" name="name" class="form-control"
                           placeholder="John Perera"
                           value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="email">Email Address <span>*</span></label>
                    <input type="email" id="email" name="email" class="form-control"
                           placeholder="john@example.com"
                           value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="phone">Phone Number</label>
                    <input type="tel" id="phone" name="phone" class="form-control"
                           placeholder="+94 77 000 0000"
                           value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="subject">Subject</label>
                    <select id="subject" name="subject" class="form-control">
                        <option value="" disabled <?php echo empty($_POST['subject']) ? 'selected' : ''; ?>>Select a topic…</option>
                        <option value="Product Inquiry"    <?php echo (($_POST['subject'] ?? '') === 'Product Inquiry')    ? 'selected' : ''; ?>>Product Inquiry</option>
                        <option value="Price Quote"        <?php echo (($_POST['subject'] ?? '') === 'Price Quote')        ? 'selected' : ''; ?>>Price Quote</option>
                        <option value="Warranty / Repair"  <?php echo (($_POST['subject'] ?? '') === 'Warranty / Repair')  ? 'selected' : ''; ?>>Warranty / Repair</option>
                        <option value="Bulk Order"         <?php echo (($_POST['subject'] ?? '') === 'Bulk Order')         ? 'selected' : ''; ?>>Bulk Order</option>
                        <option value="Other"              <?php echo (($_POST['subject'] ?? '') === 'Other')              ? 'selected' : ''; ?>>Other</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="message">Message <span>*</span></label>
                <textarea id="message" name="message" class="form-control"
                          placeholder="Tell us what you're looking for…" required><?php echo htmlspecialchars($_POST['message'] ?? ''); ?></textarea>
            </div>

            <button type="submit" class="btn-submit">
                <i class="fas fa-paper-plane"></i> Send Message
            </button>

        </form>

    </div><!-- /form-card -->

</div><!-- /contact-wrap -->

<!-- WhatsApp FAB -->
<a href="https://wa.me/94XXXXXXXXX" class="wa-btn" target="_blank" aria-label="Chat on WhatsApp">
    <i class="fab fa-whatsapp"></i>
</a>

<?php
$extra_scripts = <<<'JS'
<script>
    // Intersection Observer — animate in info cards & form card
    const els = document.querySelectorAll('.info-card, .map-card, .form-card');
    const io = new IntersectionObserver(entries => {
        entries.forEach((e, idx) => {
            if (e.isIntersecting) {
                // stagger info cards
                setTimeout(() => e.target.classList.add('visible'), idx * 80);
                io.unobserve(e.target);
            }
        });
    }, { threshold: 0.1 });
    els.forEach(el => io.observe(el));
</script>
JS;

include 'footer.php';
?>