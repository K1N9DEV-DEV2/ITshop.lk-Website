<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$page_title = 'IT Shop.LK - Best Computer Store';
include 'header.php';
?>

<style>
    @import url('https://fonts.googleapis.com/css2?family=Red+Hat+Display:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,700&display=swap');

    :root {
        --ink: #0a0a0f;
        --ink-soft: #3d3d50;
        --ink-muted: #8888a0;
        --surface: #f6f6f8;
        --card: #ffffff;
        --accent: #0cb100;
        --accent-light: #eef2ff;
        --accent-glow: rgba(17, 255, 0, 0.18);
        --green: #10b981;
        --radius-xl: 24px;
        --radius-lg: 16px;
        --radius-md: 12px;
        --shadow-card: 0 2px 12px rgba(10,10,15,0.07), 0 0 0 1px rgba(10,10,15,0.05);
        --shadow-hover: 0 16px 40px rgba(10,10,15,0.13), 0 0 0 1px rgba(79,70,229,0.12);
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }

    body {
        font-family: 'Red Hat Display', sans-serif;
        background: var(--surface);
        color: var(--ink);
        -webkit-font-smoothing: antialiased;
    }

    /* ── HERO ─────────────────────────────────────────────── */
    .hero {
        min-height: 100vh;
        background: var(--ink);
        padding-top: 90px;
        display: grid;
        place-items: center;
        position: relative;
        overflow: hidden;
    }

    /* geometric bg blobs */
    .hero::before,
    .hero::after {
        content: '';
        position: absolute;
        border-radius: 50%;
        filter: blur(90px);
        pointer-events: none;
    }
    .hero::before {
        width: 600px; height: 600px;
        background: radial-gradient(circle, rgba(70, 229, 91, 0.35) 0%, transparent 70%);
        top: -120px; right: -100px;
    }
    .hero::after {
        width: 400px; height: 400px;
        background: radial-gradient(circle, rgba(79, 135, 188, 0.2) 0%, transparent 70%);
        bottom: -60px; left: -60px;
    }

    /* grid lines overlay */
    .hero-grid {
        position: absolute;
        inset: 0;
        background-image:
            linear-gradient(rgba(255,255,255,0.03) 1px, transparent 1px),
            linear-gradient(90deg, rgba(255,255,255,0.03) 1px, transparent 1px);
        background-size: 60px 60px;
        pointer-events: none;
    }

    .hero-inner {
        position: relative;
        z-index: 2;
        max-width: 780px;
        padding: 0 2rem;
        text-align: center;
    }

    .hero-pill {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: rgba(187, 187, 187, 0.15);
        border: 1px solid rgba(110, 229, 70, 0.35);
        color: #d5f9ff;
        font-size: 0.8rem;
        font-weight: 600;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        padding: 6px 16px;
        border-radius: 100px;
        margin-bottom: 2rem;
        animation: fadeDown 0.7s ease both;
    }
    .hero-pill span { width: 6px; height: 6px; background: #11ff00; border-radius: 50%; }

    .hero-title {
        font-family: 'Red Hat Display', sans-serif;
        font-size: clamp(3rem, 8vw, 6rem);
        font-weight: 800;
        color: #fff;
        line-height: 1.0;
        letter-spacing: -0.03em;
        margin-bottom: 1.5rem;
        animation: fadeDown 0.7s 0.1s ease both;
    }
    .hero-title em {
        font-style: normal;
        background: linear-gradient(135deg, #1eff00 0%, #000792 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }

    .hero-sub {
        font-size: 1.15rem;
        color: #9999b8;
        line-height: 1.7;
        max-width: 520px;
        margin: 0 auto 2.5rem;
        animation: fadeDown 0.7s 0.2s ease both;
    }

    .hero-actions {
        display: flex;
        gap: 12px;
        justify-content: center;
        flex-wrap: wrap;
        animation: fadeDown 0.7s 0.3s ease both;
    }

    /* ── BUTTONS ──────────────────────────────────────────── */
    .btn-primary-custom {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: var(--accent);
        color: #fff;
        font-family: 'Red Hat Display', sans-serif;
        font-weight: 600;
        font-size: 0.95rem;
        padding: 14px 28px;
        border-radius: var(--radius-md);
        border: none;
        cursor: pointer;
        text-decoration: none;
        transition: all 0.25s ease;
        box-shadow: 0 4px 20px rgba(13, 255, 0, 0.4);
    }
    .btn-primary-custom:hover {
        background: #098600;
        transform: translateY(-2px);
        box-shadow: 0 8px 28px rgba(13, 255, 0, 0.4);
        color: #fff;
        text-decoration: none;
    }

    .btn-ghost {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: rgba(255,255,255,0.07);
        color: #c7c7e0;
        font-family: 'Red Hat Display', sans-serif;
        font-weight: 600;
        font-size: 0.95rem;
        padding: 14px 28px;
        border-radius: var(--radius-md);
        border: 1px solid rgba(255,255,255,0.1);
        cursor: pointer;
        text-decoration: none;
        transition: all 0.25s ease;
    }
    .btn-ghost:hover {
        background: rgba(255,255,255,0.12);
        color: #fff;
        text-decoration: none;
    }

    /* ── STATS STRIP ──────────────────────────────────────── */
    .stats-strip {
        background: var(--card);
        border-bottom: 1px solid rgba(10,10,15,0.07);
        padding: 1.5rem 0;
    }
    .stats-inner {
        max-width: 1200px;
        margin: 0 auto;
        padding: 0 2rem;
        display: flex;
        justify-content: center;
        gap: 3rem;
        flex-wrap: wrap;
    }
    .stat-item {
        text-align: center;
    }
    .stat-num {
        font-family: 'Red Hat Display', sans-serif;
        font-size: 1.8rem;
        font-weight: 700;
        color: var(--ink);
    }
    .stat-label {
        font-size: 0.8rem;
        color: var(--ink-muted);
        font-weight: 500;
        letter-spacing: 0.04em;
        text-transform: uppercase;
    }

    /* ── CATEGORIES ───────────────────────────────────────── */
    .categories-section {
        padding: 6rem 0;
        background: var(--surface);
    }

    .section-header {
        text-align: center;
        margin-bottom: 3.5rem;
    }
    .section-eyebrow {
        font-size: 0.75rem;
        font-weight: 700;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        color: var(--accent);
        margin-bottom: 0.75rem;
    }
    .section-heading {
        font-family: 'Red Hat Display', sans-serif;
        font-size: clamp(2rem, 4vw, 2.8rem);
        font-weight: 800;
        color: var(--ink);
        letter-spacing: -0.02em;
        line-height: 1.1;
    }
    .section-sub {
        margin-top: 0.75rem;
        color: var(--ink-muted);
        font-size: 1rem;
    }

    .cats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
        gap: 20px;
        max-width: 1200px;
        margin: 0 auto;
        padding: 0 2rem;
    }

    .cat-card {
        background: var(--card);
        border-radius: var(--radius-xl);
        padding: 2rem 2rem 1.75rem;
        box-shadow: var(--shadow-card);
        display: flex;
        flex-direction: column;
        gap: 0;
        text-decoration: none;
        color: var(--ink);
        transition: box-shadow 0.3s ease, transform 0.3s ease;
        opacity: 0;
        transform: translateY(24px);
        position: relative;
        overflow: hidden;
    }
    .cat-card.visible {
        opacity: 1;
        transform: translateY(0);
        transition: opacity 0.55s ease, transform 0.55s ease, box-shadow 0.3s ease;
    }
    .cat-card:hover {
        box-shadow: var(--shadow-hover);
        transform: translateY(-4px);
        text-decoration: none;
        color: var(--ink);
    }

    /* subtle top-border accent on hover */
    .cat-card::before {
        content: '';
        position: absolute;
        top: 0; left: 0; right: 0;
        height: 3px;
        background: linear-gradient(90deg, var(--accent), #7dfc9b);
        transform: scaleX(0);
        transform-origin: left;
        transition: transform 0.35s ease;
        border-radius: var(--radius-xl) var(--radius-xl) 0 0;
    }
    .cat-card:hover::before { transform: scaleX(1); }

    .cat-icon-wrap {
        width: 52px;
        height: 52px;
        border-radius: var(--radius-md);
        background: var(--accent-light);
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 1.25rem;
        transition: background 0.3s ease;
    }
    .cat-card:hover .cat-icon-wrap {
        background: var(--accent);
    }
    .cat-icon-wrap i {
        font-size: 1.4rem;
        color: var(--accent);
        transition: color 0.3s ease;
    }
    .cat-card:hover .cat-icon-wrap i { color: #fff; }

    .cat-title {
        font-weight: 700;
        font-size: 1.05rem;
        margin-bottom: 0.5rem;
        color: var(--ink);
    }
    .cat-desc {
        font-size: 0.88rem;
        color: var(--ink-muted);
        line-height: 1.65;
        flex: 1;
    }
    .cat-link {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        margin-top: 1.25rem;
        font-size: 0.85rem;
        font-weight: 600;
        color: var(--accent);
        transition: gap 0.2s ease;
    }
    .cat-card:hover .cat-link { gap: 10px; }

    /* ── WHATSAPP ─────────────────────────────────────────── */
    .wa-btn {
        position: fixed;
        bottom: 24px;
        right: 24px;
        width: 56px;
        height: 56px;
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

    /* ── ANIMATIONS ───────────────────────────────────────── */
    @keyframes fadeDown {
        from { opacity: 0; transform: translateY(-16px); }
        to   { opacity: 1; transform: translateY(0); }
    }

    /* ── RESPONSIVE ───────────────────────────────────────── */
    @media (max-width: 600px) {
        .cats-grid { grid-template-columns: 1fr; }
        .stats-inner { gap: 2rem; }
    }
</style>

<!-- HERO -->
<section class="hero">
    <div class="hero-grid"></div>
    <div class="hero-inner">
        <div class="hero-pill"><span></span>Sri Lanka Best Computer Store</div>
        <h1 class="hero-title">Power up with <em> IT Shop.LK</em></h1>
        <p class="hero-sub">Laptops, PCs, RAM, GPUs, peripherals &amp; premium audio — everything you need to build, play, or create.</p>
        <div class="hero-actions">
            <a href="products.php" class="btn-primary-custom"><i class="fas fa-shopping-bag"></i> Shop Now</a>
            <a href="contact.php" class="btn-ghost"><i class="fas fa-phone"></i> Contact Us</a>
        </div>
    </div>
</section>

<!-- STATS STRIP 
<div class="stats-strip">
    <div class="stats-inner">
        <div class="stat-item"><div class="stat-num">500+</div><div class="stat-label">Products</div></div>
        <div class="stat-item"><div class="stat-num">10K+</div><div class="stat-label">Customers</div></div>
        <div class="stat-item"><div class="stat-num">5★</div><div class="stat-label">Rated</div></div>
        <div class="stat-item"><div class="stat-num">24hr</div><div class="stat-label">Support</div></div>
    </div>
</div>-->

<!-- CATEGORIES -->
<section class="categories-section">
    <div class="section-header">
        <div class="section-eyebrow">Browse By Category</div>
        <h2 class="section-heading">Everything You Need</h2>
        <p class="section-sub">Explore our full range of electronics &amp; computer equipment</p>
    </div>

    <div class="cats-grid">
        <?php
        $categories = [
            ['icon' => 'fa-laptop',     'title' => 'Laptops & Notebooks',  'desc' => 'High-performance laptops for gaming, business, and everyday use.',       'url' => 'products.php?category=laptops'],
            ['icon' => 'fa-desktop',    'title' => 'Desktop PCs',           'desc' => 'Custom-built desktops and workstations for maximum performance.',         'url' => 'products.php?category=desktops'],
            ['icon' => 'fa-memory',     'title' => 'RAM & Storage',         'desc' => 'High-speed memory modules and storage solutions.',                         'url' => 'products.php?category=memory'],
            ['icon' => 'fa-tv',         'title' => 'Graphics Cards',        'desc' => 'Latest VGA cards for gaming and professional graphics work.',              'url' => 'products.php?category=graphics'],
            ['icon' => 'fa-keyboard',   'title' => 'Keyboards & Mice',      'desc' => 'Premium input devices for gaming and productivity.',                       'url' => 'products.php?category=peripherals'],
            ['icon' => 'fa-headphones', 'title' => 'Audio Devices',         'desc' => 'High-quality headphones, speakers, and audio equipment.',                 'url' => 'products.php?category=audio'],
        ];
        foreach ($categories as $i => $cat): ?>
        <a href="<?php echo $cat['url']; ?>" class="cat-card" style="transition-delay: <?php echo $i * 70; ?>ms">
            <div class="cat-icon-wrap">
                <i class="fas <?php echo $cat['icon']; ?>"></i>
            </div>
            <div class="cat-title"><?php echo $cat['title']; ?></div>
            <div class="cat-desc"><?php echo $cat['desc']; ?></div>
            <div class="cat-link">View Products <i class="fas fa-arrow-right" style="font-size:0.75rem"></i></div>
        </a>
        <?php endforeach; ?>
    </div>
</section>

<!-- WhatsApp FAB -->
<a href="https://wa.me/94xxxxxxxxx" class="wa-btn" target="_blank" aria-label="Chat on WhatsApp">
    <i class="fab fa-whatsapp"></i>
</a>

<?php
$extra_scripts = <<<'JS'
<script>
    const cards = document.querySelectorAll('.cat-card');
    const io = new IntersectionObserver(entries => {
        entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('visible'); io.unobserve(e.target); } });
    }, { threshold: 0.1, rootMargin: '0px 0px -40px 0px' });
    cards.forEach(c => io.observe(c));
</script>
JS;

include 'footer.php';
?>