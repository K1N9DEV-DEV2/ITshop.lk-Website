<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$page_title = 'IT Shop.LK - the Best Computer Store';

// ── DB connection ─────────────────────────────────────────────────────────────
$pdo = null;
try {
    require_once 'db.php';
} catch (Throwable $e) { /* silently skip */ }

// ── Load hero slides from DB ──────────────────────────────────────────────────
$hero_slides = [];
if ($pdo) {
    try {
        $stmt = $pdo->query("SELECT * FROM hero_slides WHERE is_active = 1 ORDER BY sort_order ASC, id ASC");
        $hero_slides = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { /* silently skip */ }
}

// ── Fallback slides if DB empty ───────────────────────────────────────────────
if (empty($hero_slides)) {
    $hero_slides = [
        ['id'=>0,'title'=>'Premium Laptops','subtitle'=>'High-performance machines for every need','image_url'=>'','link_url'=>'products.php','btn_text'=>'Shop Now','btn_ghost_text'=>'View All','is_active'=>1,'sort_order'=>0],
        ['id'=>0,'title'=>'Desktop Workstations','subtitle'=>'Build the ultimate powerhouse setup','image_url'=>'','link_url'=>'products.php','btn_text'=>'Shop Now','btn_ghost_text'=>'View All','is_active'=>1,'sort_order'=>1],
    ];
}

// ── Load categories from DB ───────────────────────────────────────────────────
$db_categories = [];
if ($pdo) {
    try {
        $stmt = $pdo->query("SELECT c.*, COUNT(p.id) as product_count FROM categories c LEFT JOIN products p ON p.category = c.slug GROUP BY c.id ORDER BY c.name ASC");
        $db_categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { /* silently skip */ }
}

include 'header.php';
include 'popup.php';
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

    /* ── HERO SLIDER ──────────────────────────────────────── */
    .hero {
        min-height: 100vh;
        padding-top: 90px;
        position: relative;
        overflow: hidden;
    }

    .hero-slides {
        position: absolute;
        inset: 0;
        z-index: 0;
    }

    .hero-slide {
        position: absolute;
        inset: 0;
        background-size: cover;
        background-position: center;
        opacity: 0;
        transform: scale(1.04);
        transition: opacity 1s ease, transform 6s ease;
    }

    .hero-slide.active {
        opacity: 1;
        transform: scale(1);
    }

    .hero-slide::after {
        content: '';
        position: absolute;
        inset: 0;
        background: linear-gradient(
            135deg,
            rgba(5, 5, 10, 0.78) 0%,
            rgba(5, 5, 10, 0.45) 60%,
            rgba(5, 5, 10, 0.25) 100%
        );
    }

    .hero-grid {
        position: absolute;
        inset: 0;
        z-index: 1;
        background-image:
            linear-gradient(rgba(255,255,255,0.025) 1px, transparent 1px),
            linear-gradient(90deg, rgba(255,255,255,0.025) 1px, transparent 1px);
        background-size: 60px 60px;
        pointer-events: none;
    }

    .hero-glow {
        position: absolute;
        z-index: 1;
        width: 600px; height: 600px;
        background: radial-gradient(circle, rgba(70, 229, 91, 0.22) 0%, transparent 70%);
        top: -120px; right: -100px;
        border-radius: 50%;
        filter: blur(90px);
        pointer-events: none;
        animation: glowPulse 5s ease-in-out infinite alternate;
    }

    @keyframes glowPulse {
        from { opacity: 0.6; }
        to   { opacity: 1; }
    }

    .hero-inner {
        position: relative;
        z-index: 3;
        min-height: calc(100vh - 90px);
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        max-width: 780px;
        margin: 0 auto;
        padding: 4rem 2rem;
        text-align: center;
        transition: opacity 0.5s ease;
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
        backdrop-filter: blur(6px);
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

    /* ── SLIDER CONTROLS ──────────────────────────────────── */
    .slider-dots {
        position: absolute;
        bottom: 2rem;
        left: 50%;
        transform: translateX(-50%);
        z-index: 4;
        display: flex;
        gap: 10px;
        align-items: center;
    }

    .slider-dot {
        width: 8px;
        height: 8px;
        border-radius: 100px;
        background: rgba(255,255,255,0.35);
        cursor: pointer;
        border: none;
        padding: 0;
        transition: all 0.35s ease;
    }
    .slider-dot.active {
        width: 28px;
        background: var(--accent);
        box-shadow: 0 0 10px rgba(13,255,0,0.5);
    }
    .slider-dot:hover:not(.active) { background: rgba(255,255,255,0.65); }

    .slider-arrow {
        position: absolute;
        top: 50%;
        transform: translateY(-50%);
        z-index: 4;
        width: 46px; height: 46px;
        background: rgba(255,255,255,0.1);
        border: 1px solid rgba(255,255,255,0.18);
        backdrop-filter: blur(8px);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        color: #fff;
        font-size: 1rem;
        transition: all 0.25s ease;
    }
    .slider-arrow:hover {
        background: var(--accent);
        border-color: var(--accent);
        box-shadow: 0 4px 20px rgba(13,255,0,0.4);
    }
    .slider-arrow-prev { left: 1.5rem; }
    .slider-arrow-next { right: 1.5rem; }

    .slider-progress {
        position: absolute;
        bottom: 0; left: 0;
        height: 3px;
        background: var(--accent);
        z-index: 4;
        width: 0%;
        box-shadow: 0 0 8px rgba(13,255,0,0.6);
        transition: width linear;
    }

    /* ── BUTTONS ──────────────────────────────────────────── */
    .btn-primary-custom {
        display: inline-flex; align-items: center; gap: 8px;
        background: var(--accent); color: #fff;
        font-family: 'Red Hat Display', sans-serif; font-weight: 600; font-size: 0.95rem;
        padding: 14px 28px; border-radius: var(--radius-md); border: none; cursor: pointer;
        text-decoration: none; transition: all 0.25s ease;
        box-shadow: 0 4px 20px rgba(13, 255, 0, 0.4);
    }
    .btn-primary-custom:hover { background: #098600; transform: translateY(-2px); box-shadow: 0 8px 28px rgba(13, 255, 0, 0.4); color: #fff; text-decoration: none; }

    .btn-ghost {
        display: inline-flex; align-items: center; gap: 8px;
        background: rgba(255,255,255,0.07); color: #c7c7e0;
        font-family: 'Red Hat Display', sans-serif; font-weight: 600; font-size: 0.95rem;
        padding: 14px 28px; border-radius: var(--radius-md);
        border: 1px solid rgba(255,255,255,0.1); cursor: pointer;
        text-decoration: none; transition: all 0.25s ease; backdrop-filter: blur(6px);
    }
    .btn-ghost:hover { background: rgba(255,255,255,0.12); color: #fff; text-decoration: none; }

    /* ── CATEGORIES ───────────────────────────────────────── */
    .categories-section { padding: 6rem 0; background: var(--surface); }

    .section-header { text-align: center; margin-bottom: 3.5rem; }
    .section-eyebrow { font-size: 0.75rem; font-weight: 700; letter-spacing: 0.12em; text-transform: uppercase; color: var(--accent); margin-bottom: 0.75rem; }
    .section-heading { font-family: 'Red Hat Display', sans-serif; font-size: clamp(2rem, 4vw, 2.8rem); font-weight: 800; color: var(--ink); letter-spacing: -0.02em; line-height: 1.1; }
    .section-sub { margin-top: 0.75rem; color: var(--ink-muted); font-size: 1rem; }

    .cats-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 20px; max-width: 1200px; margin: 0 auto; padding: 0 2rem; }

    .cat-card {
        background: var(--card); border-radius: var(--radius-xl);
        padding: 2rem 2rem 1.75rem; box-shadow: var(--shadow-card);
        display: flex; flex-direction: column; gap: 0;
        text-decoration: none; color: var(--ink);
        transition: box-shadow 0.3s ease, transform 0.3s ease;
        opacity: 0; transform: translateY(24px); position: relative; overflow: hidden;
    }
    .cat-card.visible { opacity: 1; transform: translateY(0); transition: opacity 0.55s ease, transform 0.55s ease, box-shadow 0.3s ease; }
    .cat-card:hover { box-shadow: var(--shadow-hover); transform: translateY(-4px); text-decoration: none; color: var(--ink); }
    .cat-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; background: linear-gradient(90deg, var(--accent), #7dfc9b); transform: scaleX(0); transform-origin: left; transition: transform 0.35s ease; border-radius: var(--radius-xl) var(--radius-xl) 0 0; }
    .cat-card:hover::before { transform: scaleX(1); }

    .cat-icon-wrap { width: 52px; height: 52px; border-radius: var(--radius-md); background: var(--accent-light); display: flex; align-items: center; justify-content: center; margin-bottom: 1.25rem; transition: background 0.3s ease; }
    .cat-card:hover .cat-icon-wrap { background: var(--accent); }
    .cat-icon-wrap i { font-size: 1.4rem; color: var(--accent); transition: color 0.3s ease; }
    .cat-card:hover .cat-icon-wrap i { color: #fff; }
    .cat-title { font-weight: 700; font-size: 1.05rem; margin-bottom: 0.5rem; color: var(--ink); }
    .cat-desc  { font-size: 0.88rem; color: var(--ink-muted); line-height: 1.65; flex: 1; }
    .cat-link  { display: inline-flex; align-items: center; gap: 6px; margin-top: 1.25rem; font-size: 0.85rem; font-weight: 600; color: var(--accent); transition: gap 0.2s ease; }
    .cat-card:hover .cat-link { gap: 10px; }
    .cat-count { font-size: 0.72rem; font-weight: 700; color: var(--ink-muted); margin-top: 4px; }

    /* ── WHATSAPP ─────────────────────────────────────────── */
    .wa-btn { position: fixed; bottom: 24px; right: 24px; width: 56px; height: 56px; background: #25d366; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; z-index: 9999; text-decoration: none; box-shadow: 0 6px 20px rgba(37,211,102,0.45); transition: transform 0.25s ease, box-shadow 0.25s ease; }
    .wa-btn:hover { transform: scale(1.1); box-shadow: 0 10px 30px rgba(37,211,102,0.55); color: white; text-decoration: none; }

    /* ── CART FAB (mobile only) ──────────────────────────── */
.cart-fab {
    position: fixed;
    bottom: 89px; right: 24px;
    width: 56px; height: 56px;
    background: var(--accent);
    color: #fff;
    border-radius: 50%;
    display: none;
    align-items: center; justify-content: center;
    font-size: 1.3rem;
    z-index: 9999;
    text-decoration: none;
    box-shadow: 0 6px 20px rgba(12,177,0,0.45);
    transition: transform 0.25s ease, box-shadow 0.25s ease;
}
.cart-fab:hover {
    transform: scale(1.1);
    box-shadow: 0 10px 30px rgba(12,177,0,0.55);
    color: #fff;
    text-decoration: none;
}
.cart-fab .cart-fab-badge {
    position: absolute;
    top: 10px; right: 2px;
    min-width: 18px; height: 18px;
    background: #fff;
    color: var(--accent);
    font-size: .6rem; font-weight: 800;
    border-radius: 999px;
    display: flex; align-items: center; justify-content: center;
    padding: 0 3px;
    border: 2px solid var(--accent);
}

    /* ── ANIMATIONS ───────────────────────────────────────── */
    @keyframes fadeDown { from { opacity: 0; transform: translateY(-16px); } to { opacity: 1; transform: translateY(0); } }

    /* ── RESPONSIVE ───────────────────────────────────────── */
    @media (max-width: 600px) {
        .cats-grid { grid-template-columns: 1fr; }
        .slider-arrow { display: none; }
        .cart-fab { display: flex; }
    }
</style>


<!-- HERO SLIDER -->
<section class="hero">

    <!-- Background slides -->
    <div class="hero-slides">
        <?php foreach ($hero_slides as $i => $slide):
            $bg = '';
            if (!empty($slide['image_url'])) {
                // local upload path
                $bg = 'style="background-image:url(\'' . htmlspecialchars($slide['image_url']) . '\')"';
            }
        ?>
        <div class="hero-slide <?= $i === 0 ? 'active' : '' ?>" <?= $bg ?>
             data-title="<?= htmlspecialchars($slide['title'] ?? '') ?>"
             data-subtitle="<?= htmlspecialchars($slide['subtitle'] ?? '') ?>"
             data-link="<?= htmlspecialchars($slide['link_url'] ?? 'products.php') ?>"
             data-btn="<?= htmlspecialchars($slide['btn_text'] ?? 'Shop Now') ?>"
             data-ghost="<?= htmlspecialchars($slide['btn_ghost_text'] ?? 'View All') ?>">
        </div>
        <?php endforeach; ?>
    </div>

    <div class="hero-grid"></div>
    <div class="hero-glow"></div>

    <!-- Arrow buttons -->
    <?php if (count($hero_slides) > 1): ?>
    <button class="slider-arrow slider-arrow-prev" aria-label="Previous slide">
        <i class="fas fa-chevron-left"></i>
    </button>
    <button class="slider-arrow slider-arrow-next" aria-label="Next slide">
        <i class="fas fa-chevron-right"></i>
    </button>
    <?php endif; ?>

    <!-- Hero content (updates dynamically) -->
    <div class="hero-inner" id="hero-content">
        <!--<div class="hero-pill"><span></span> SRI LANKA BEST COMPUTER STORE</div>
        <h1 class="hero-title" id="hero-title">
            <?= htmlspecialchars($hero_slides[0]['title'] ?? 'Premium Tech') ?>
        </h1>
        <p class="hero-sub" id="hero-sub">
            <?= htmlspecialchars($hero_slides[0]['subtitle'] ?? 'Top-quality computers, components & accessories') ?>
        </p>
        <div class="hero-actions">
            <a href="<?= htmlspecialchars($hero_slides[0]['link_url'] ?? 'products.php') ?>"
               class="btn-primary-custom" id="hero-btn">
                <i class="fas fa-shopping-bag"></i>
                <?= htmlspecialchars($hero_slides[0]['btn_text'] ?? 'Shop Now') ?>
            </a>
            <a href="products.php" class="btn-ghost" id="hero-ghost">
                <?= htmlspecialchars($hero_slides[0]['btn_ghost_text'] ?? 'View All') ?>
                <i class="fas fa-arrow-right" style="font-size:0.8rem"></i>
            </a>-->
        </div>
    </div>

    <!-- Dot navigation -->
    <?php if (count($hero_slides) > 1): ?>
    <div class="slider-dots" id="slider-dots">
        <?php foreach ($hero_slides as $i => $slide): ?>
        <button class="slider-dot <?= $i === 0 ? 'active' : '' ?>" aria-label="Slide <?= $i + 1 ?>"></button>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="slider-progress" id="slider-progress"></div>
</section>


<!-- CATEGORIES -->
<section class="categories-section">
    <div class="section-header">
        <div class="section-eyebrow">Browse By Category</div>
        <h2 class="section-heading">Everything You Need</h2>
        <p class="section-sub">Explore our full range of electronics &amp; computer equipment</p>
    </div>

    <div class="cats-grid">
        <?php if (!empty($db_categories)):
            foreach ($db_categories as $i => $cat):
                $icon = !empty($cat['icon']) ? $cat['icon'] : 'fa-tag';
                $slug = !empty($cat['slug']) ? $cat['slug'] : strtolower(str_replace(' ', '_', $cat['name']));
                $cnt  = (int)($cat['product_count'] ?? 0);
        ?>
        <a href="products.php?category=<?= urlencode($slug) ?>"
           class="cat-card"
           style="transition-delay: <?= $i * 70 ?>ms">
            <div class="cat-icon-wrap">
                <i class="fas <?= htmlspecialchars($icon) ?>"></i>
            </div>
            <div class="cat-title"><?= htmlspecialchars($cat['name']) ?></div>
            <?php if (!empty($cat['description'])): ?>
            <div class="cat-desc"><?= htmlspecialchars($cat['description']) ?></div>
            <?php endif; ?>
            <?php if ($cnt > 0): ?>
            <div class="cat-count"><?= $cnt ?> product<?= $cnt !== 1 ? 's' : '' ?></div>
            <?php endif; ?>
            <div class="cat-link">View Products <i class="fas fa-arrow-right" style="font-size:0.75rem"></i></div>
        </a>
        <?php endforeach;
        else:
            // Fallback static categories if DB is empty
            $fallback = [
                ['icon' => 'fa-laptop',     'title' => 'Laptops & Notebooks',  'desc' => 'High-performance laptops for gaming, business, and everyday use.',  'url' => 'products.php?category=laptops'],
                ['icon' => 'fa-desktop',    'title' => 'Desktop PCs',           'desc' => 'Custom-built desktops and workstations for maximum performance.',    'url' => 'products.php?category=desktops'],
                ['icon' => 'fa-memory',     'title' => 'RAM & Storage',         'desc' => 'High-speed memory modules and storage solutions.',                   'url' => 'products.php?category=memory'],
                ['icon' => 'fa-tv',         'title' => 'Graphics Cards',        'desc' => 'Latest VGA cards for gaming and professional graphics work.',        'url' => 'products.php?category=graphics'],
                ['icon' => 'fa-keyboard',   'title' => 'Keyboards & Mice',      'desc' => 'Premium input devices for gaming and productivity.',                 'url' => 'products.php?category=peripherals'],
                ['icon' => 'fa-headphones', 'title' => 'Audio Devices',         'desc' => 'High-quality headphones, speakers, and audio equipment.',           'url' => 'products.php?category=audio'],
            ];
            foreach ($fallback as $i => $cat): ?>
        <a href="<?= $cat['url'] ?>" class="cat-card" style="transition-delay: <?= $i * 70 ?>ms">
            <div class="cat-icon-wrap">
                <i class="fas <?= $cat['icon'] ?>"></i>
            </div>
            <div class="cat-title"><?= $cat['title'] ?></div>
            <div class="cat-desc"><?= $cat['desc'] ?></div>
            <div class="cat-link">View Products <i class="fas fa-arrow-right" style="font-size:0.75rem"></i></div>
        </a>
        <?php endforeach;
        endif; ?>
    </div>
</section>


<!-- WhatsApp FAB -->
<a href="https://wa.me/94xxxxxxxxx" class="wa-btn" target="_blank" aria-label="Chat on WhatsApp">
    <i class="fab fa-whatsapp"></i>
</a>

<a href="cart.php" class="cart-fab" aria-label="View cart">
    <i class="fas fa-shopping-cart"></i>
    <?php if ($cart_count > 0): ?>
        <span class="cart-fab-badge"><?= $cart_count ?></span>
    <?php endif; ?>
</a>

<?php
$extra_scripts = <<<'JS'
<script>
(function () {
    const slides   = document.querySelectorAll('.hero-slide');
    const dots     = document.querySelectorAll('#slider-dots .slider-dot');
    const progress = document.getElementById('slider-progress');
    const heroTitle  = document.getElementById('hero-title');
    const heroSub    = document.getElementById('hero-sub');
    const heroBtn    = document.getElementById('hero-btn');
    const heroGhost  = document.getElementById('hero-ghost');
    const heroContent = document.getElementById('hero-content');

    const INTERVAL = 5000;
    let current = 0;
    let timer;

    function updateContent(slide) {
        const title = slide.dataset.title;
        const sub   = slide.dataset.subtitle;
        const link  = slide.dataset.link  || 'products.php';
        const btn   = slide.dataset.btn   || 'Shop Now';
        const ghost = slide.dataset.ghost || 'View All';

        heroContent.style.opacity = '0';
        setTimeout(() => {
            if (heroTitle && title) heroTitle.textContent = title;
            if (heroSub   && sub)   heroSub.textContent   = sub;
            if (heroBtn) {
                heroBtn.href = link;
                heroBtn.lastChild.textContent = ' ' + btn;
            }
            if (heroGhost) heroGhost.firstChild.textContent = ghost;
            heroContent.style.opacity = '1';
        }, 300);
    }

    function goTo(n) {
        slides[current].classList.remove('active');
        if (dots[current]) dots[current].classList.remove('active');

        current = (n + slides.length) % slides.length;

        slides[current].classList.add('active');
        if (dots[current]) dots[current].classList.add('active');
        updateContent(slides[current]);

        resetProgress();
    }

    function resetProgress() {
        if (!progress) return;
        progress.style.transition = 'none';
        progress.style.width = '0%';
        requestAnimationFrame(() => requestAnimationFrame(() => {
            progress.style.transition = `width ${INTERVAL}ms linear`;
            progress.style.width = '100%';
        }));
    }

    function startAuto() {
        clearInterval(timer);
        timer = setInterval(() => goTo(current + 1), INTERVAL);
        resetProgress();
    }

    // Arrow buttons
    const prevBtn = document.querySelector('.slider-arrow-prev');
    const nextBtn = document.querySelector('.slider-arrow-next');
    if (prevBtn) prevBtn.addEventListener('click', () => { goTo(current - 1); startAuto(); });
    if (nextBtn) nextBtn.addEventListener('click', () => { goTo(current + 1); startAuto(); });

    // Dot buttons
    dots.forEach((dot, i) => dot.addEventListener('click', () => { goTo(i); startAuto(); }));

    // Keyboard
    document.addEventListener('keydown', e => {
        if (e.key === 'ArrowLeft')  { goTo(current - 1); startAuto(); }
        if (e.key === 'ArrowRight') { goTo(current + 1); startAuto(); }
    });

    // Touch / swipe
    let touchX = 0;
    const hero = document.querySelector('.hero');
    hero.addEventListener('touchstart', e => { touchX = e.changedTouches[0].clientX; }, { passive: true });
    hero.addEventListener('touchend',   e => {
        const dx = e.changedTouches[0].clientX - touchX;
        if (Math.abs(dx) > 50) { goTo(dx < 0 ? current + 1 : current - 1); startAuto(); }
    }, { passive: true });

    // Pause on hover
    hero.addEventListener('mouseenter', () => clearInterval(timer));
    hero.addEventListener('mouseleave', () => startAuto());

    // Only auto-advance if more than one slide
    if (slides.length > 1) startAuto();
    else resetProgress();

    // Category card reveal
    const cards = document.querySelectorAll('.cat-card');
    const io = new IntersectionObserver(entries => {
        entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('visible'); io.unobserve(e.target); } });
    }, { threshold: 0.1, rootMargin: '0px 0px -40px 0px' });
    cards.forEach(c => io.observe(c));
})();
</script>
JS;

include 'footer.php';
?>