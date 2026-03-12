<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

$page_title = 'IT Shop.LK - the Best Computer Store';

// ── DB connection ─────────────────────────────────────────────────────────────
$pdo = null;
try {
    require_once 'db.php';
} catch (Throwable $e) { /* silently skip */ }

// ── Category icon helper ──────────────────────────────────────────────────────
function cat_icon(string $category): string {
    $map = [
        'laptop'      => 'fa-laptop',
        'notebook'    => 'fa-laptop',
        'desktop'     => 'fa-desktop',
        'workstation' => 'fa-desktop',
        'graphic'     => 'fa-tv',
        'gpu'         => 'fa-tv',
        'vga'         => 'fa-tv',
        'memory'      => 'fa-memory',
        'ram'         => 'fa-memory',
        'storage'     => 'fa-hard-drive',
        'ssd'         => 'fa-hard-drive',
        'hdd'         => 'fa-hard-drive',
        'nvme'        => 'fa-hard-drive',
        'peripheral'  => 'fa-keyboard',
        'keyboard'    => 'fa-keyboard',
        'mouse'       => 'fa-computer-mouse',
        'audio'       => 'fa-headphones',
        'headphone'   => 'fa-headphones',
        'monitor'     => 'fa-display',
        'network'     => 'fa-network-wired',
        'component'   => 'fa-microchip',
        'cpu'         => 'fa-microchip',
        'processor'   => 'fa-microchip',
        'power'       => 'fa-plug',
        'psu'         => 'fa-plug',
        'cooling'     => 'fa-fan',
        'case'        => 'fa-server',
        'printer'     => 'fa-print',
        'camera'      => 'fa-camera',
        'gaming'      => 'fa-gamepad',
        'accessories' => 'fa-toolbox',
    ];
    $slug = strtolower(trim($category));
    foreach ($map as $key => $icon) {
        if (str_contains($slug, $key)) return $icon;
    }
    return 'fa-box';
}

// ── Load hero slides ──────────────────────────────────────────────────────────
$hero_slides = [];
if ($pdo) {
    try {
        $stmt = $pdo->query("SELECT * FROM hero_slides WHERE is_active = 1 ORDER BY sort_order ASC, id ASC");
        $hero_slides = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}
}
if (empty($hero_slides)) {
    $hero_slides = [
        ['id'=>0,'title'=>'Premium Laptops','subtitle'=>'High-performance machines for every need','image_url'=>'','link_url'=>'products.php','btn_text'=>'Shop Now','btn_ghost_text'=>'View All'],
        ['id'=>0,'title'=>'Desktop Workstations','subtitle'=>'Build the ultimate powerhouse setup','image_url'=>'','link_url'=>'products.php','btn_text'=>'Explore Now','btn_ghost_text'=>'View All'],
    ];
}

// ── Load categories ───────────────────────────────────────────────────────────
$db_categories = [];
if ($pdo) {
    try {
        $stmt = $pdo->query("SELECT c.*, COUNT(p.id) as product_count FROM categories c LEFT JOIN products p ON p.category = c.slug GROUP BY c.id ORDER BY c.name ASC");
        $db_categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}
}

// ── Cart count ────────────────────────────────────────────────────────────────
$cart_count = 0;
if (isset($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    $cart_count = array_sum($_SESSION['cart']);
}

// ── LIMITED-TIME DEALS — primary: category2 flag, fallback: discount ─────────
$deal_products = [];
if ($pdo) {
    try {
        $stmt = $pdo->query(
            "SELECT * FROM products
             WHERE category2 = 'limited_time_deals' AND stock_count > 0
             ORDER BY (original_price - price) DESC, id ASC LIMIT 5"
        );
        $deal_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}
}
if (empty($deal_products) && $pdo) {
    try {
        $stmt = $pdo->query(
            "SELECT * FROM products
             WHERE original_price > price AND original_price > 0 AND stock_count > 0
             ORDER BY (original_price - price) DESC LIMIT 5"
        );
        $deal_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}
}

// ── BEST SELLING — primary: category2 flag, fallback: order_items, then latest ─
$best_products = [];
if ($pdo) {
    try {
        $stmt = $pdo->query(
            "SELECT * FROM products
             WHERE category2 = 'best_selling_products' AND stock_count > 0
             ORDER BY id ASC LIMIT 10"
        );
        $best_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}
}
if (empty($best_products) && $pdo) {
    try {
        $stmt = $pdo->query(
            "SELECT p.*, COALESCE(SUM(oi.quantity),0) AS total_sold
             FROM products p LEFT JOIN order_items oi ON oi.product_id = p.id
             WHERE p.stock_count > 0
             GROUP BY p.id ORDER BY total_sold DESC, p.id ASC LIMIT 10"
        );
        $best_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        try {
            $stmt = $pdo->query("SELECT * FROM products WHERE stock_count > 0 ORDER BY id ASC LIMIT 10");
            $best_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e2) {}
    }
}

include 'header.php';
include 'popup.php';
?>

<style>
    @import url('https://fonts.googleapis.com/css2?family=Red+Hat+Display:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,700&display=swap');

    :root {
        --ink: #0a0a0f; --ink-soft: #3d3d50; --ink-muted: #8888a0;
        --surface: #f6f6f8; --card: #ffffff; --accent: #0cb100;
        --accent-glow: rgba(17,255,0,0.18);
        --radius-xl: 24px; --radius-lg: 16px; --radius-md: 12px;
        --shadow-card: 0 2px 12px rgba(10,10,15,0.07), 0 0 0 1px rgba(10,10,15,0.05);
    }

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Red Hat Display', sans-serif; background: var(--surface); color: var(--ink); -webkit-font-smoothing: antialiased; }

    /* ── HERO ──────────────────────────────────────── */
    .hero { min-height: 100vh; padding-top: 90px; position: relative; overflow: hidden; background: #050510; }
    .hero-slides { position: absolute; inset: 0; z-index: 0; }
    .hero-slide { position: absolute; inset: 0; background-size: cover; background-position: center; opacity: 0; transform: scale(1.04); background-color: #050510; transition: opacity 1s ease, transform 6s ease; }
      /*.hero-slide::before { content:''; position:absolute; inset:0; z-index:1; background:linear-gradient(135deg,rgba(5,5,16,.82) 0%,rgba(5,5,16,.45) 100%); }*/
    .hero-slide.active { opacity: 1; transform: scale(1); }
    .hero-grid { position:absolute; inset:0; z-index:1; pointer-events:none; background-image:linear-gradient(rgba(255,255,255,.025) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.025) 1px,transparent 1px); background-size:60px 60px; }
    .hero-glow { position:absolute; z-index:1; width:600px; height:600px; border-radius:50%; background:radial-gradient(circle,rgba(70,229,91,.22) 0%,transparent 70%); top:-120px; right:-100px; filter:blur(90px); pointer-events:none; animation:glowPulse 5s ease-in-out infinite alternate; }
    @keyframes glowPulse { from{opacity:.6} to{opacity:1} }

    .hero-content-wrap { position:relative; z-index:3; min-height:calc(100vh - 90px); display:flex; align-items:center; justify-content:center; pointer-events:none; }
    .hero-slide-content { text-align:center; max-width:780px; padding:4rem 2rem; opacity:0; transform:translateY(18px); pointer-events:auto; transition:opacity .65s ease .2s,transform .65s ease .2s; position:absolute; left:50%; translate:-50% 0; width:100%; }
    .hero-slide-content.active { opacity:1; transform:translateY(0); }
    .hero-pill { display:inline-flex; align-items:center; gap:8px; background:rgba(187,187,187,.15); border:1px solid rgba(110,229,70,.35); color:#d5f9ff; font-size:.8rem; font-weight:600; letter-spacing:.08em; text-transform:uppercase; padding:6px 16px; border-radius:100px; margin-bottom:2rem; backdrop-filter:blur(6px); }
    .hero-pill span { width:6px; height:6px; background:#11ff00; border-radius:50%; display:inline-block; }
    .hero-title { font-size:clamp(3rem,8vw,6rem); font-weight:800; color:#fff; line-height:1.0; letter-spacing:-.03em; margin-bottom:1.5rem; }
    .hero-title em { font-style:normal; background:linear-gradient(135deg,#1eff00 0%,#00b4d8 100%); -webkit-background-clip:text; -webkit-text-fill-color:transparent; background-clip:text; }
    .hero-sub { font-size:1.15rem; color:#9999b8; line-height:1.7; max-width:520px; margin:0 auto 2.5rem; }
    .hero-actions { display:flex; gap:12px; justify-content:center; flex-wrap:wrap; }

    .slider-dots { position:absolute; bottom:2rem; left:50%; transform:translateX(-50%); z-index:4; display:flex; gap:10px; align-items:center; }
    .slider-dot { width:8px; height:8px; border-radius:100px; background:rgba(255,255,255,.35); cursor:pointer; border:none; padding:0; transition:all .35s ease; }
    .slider-dot.active { width:28px; background:var(--accent); box-shadow:0 0 10px rgba(13,255,0,.5); }
    .slider-arrow { position:absolute; top:50%; transform:translateY(-50%); z-index:4; width:46px; height:46px; background:rgba(255,255,255,.1); border:1px solid rgba(255,255,255,.18); backdrop-filter:blur(8px); border-radius:50%; display:flex; align-items:center; justify-content:center; cursor:pointer; color:#fff; font-size:1rem; transition:all .25s ease; }
    .slider-arrow:hover { background:var(--accent); border-color:var(--accent); box-shadow:0 4px 20px rgba(13,255,0,.4); }
    .slider-arrow-prev { left:1.5rem; } .slider-arrow-next { right:1.5rem; }
    .slider-progress { position:absolute; bottom:0; left:0; height:3px; background:var(--accent); z-index:4; width:0%; box-shadow:0 0 8px rgba(13,255,0,.6); transition:width linear; }

    /* ── BUTTONS ──────────────────────────────────────── */
    .btn-primary-custom { display:inline-flex; align-items:center; gap:8px; background:var(--accent); color:#fff; font-family:'Red Hat Display',sans-serif; font-weight:600; font-size:.95rem; padding:14px 28px; border-radius:var(--radius-md); border:none; cursor:pointer; text-decoration:none; transition:all .25s ease; box-shadow:0 4px 20px rgba(13,255,0,.4); }
    .btn-primary-custom:hover { background:#098600; transform:translateY(-2px); color:#fff; text-decoration:none; }
    .btn-ghost { display:inline-flex; align-items:center; gap:8px; background:rgba(255,255,255,.07); color:#c7c7e0; font-family:'Red Hat Display',sans-serif; font-weight:600; font-size:.95rem; padding:14px 28px; border-radius:var(--radius-md); border:1px solid rgba(255,255,255,.1); cursor:pointer; text-decoration:none; transition:all .25s ease; backdrop-filter:blur(6px); }
    .btn-ghost:hover { background:rgba(255,255,255,.12); color:#fff; text-decoration:none; }
    .btn-view-all { display:inline-flex; align-items:center; gap:8px; background:transparent; color:var(--accent); font-family:'Red Hat Display',sans-serif; font-weight:700; font-size:.95rem; padding:13px 28px; border-radius:var(--radius-md); border:2px solid var(--accent); cursor:pointer; text-decoration:none; transition:all .25s ease; }
    .btn-view-all:hover { background:var(--accent); color:#fff; transform:translateY(-2px); text-decoration:none; }

    /* ── SECTIONS ─────────────────────────────────────── */
    .section-header { text-align:center; margin-bottom:3rem; }
    .section-eyebrow { font-size:.75rem; font-weight:700; letter-spacing:.12em; text-transform:uppercase; color:var(--accent); margin-bottom:.75rem; }
    .section-heading { font-size:clamp(2rem,4vw,2.8rem); font-weight:800; color:var(--ink); letter-spacing:-.02em; line-height:1.1; }
    .section-sub { margin-top:.75rem; color:var(--ink-muted); font-size:1rem; }

    /* ── CATEGORIES ───────────────────────────────────── */
    .categories-section { padding:6rem 0; background:var(--surface); }
    .cats-grid { display:grid; grid-template-columns:repeat(6,1fr); gap:16px; max-width:1300px; margin:0 auto; padding:0 2rem; }
    .cat-card { position:relative; border-radius:20px; overflow:hidden; text-decoration:none; color:#fff; aspect-ratio:3/4; display:flex; flex-direction:column; justify-content:flex-end; padding:1.25rem; background-color:#1a1a2e; background-size:cover; background-position:center; opacity:0; transform:translateY(20px); box-shadow:0 4px 20px rgba(0,0,0,.2); cursor:pointer; transition:opacity .55s ease,transform .55s ease,box-shadow .3s ease; }
    .cat-card.visible { opacity:1; transform:translateY(0); }
    .cat-card:hover { transform:translateY(-6px) scale(1.02); box-shadow:0 20px 50px rgba(0,0,0,.35); text-decoration:none; color:#fff; }
    .cat-card .cat-bg-img { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; z-index:0; transition:transform .5s ease; }
    .cat-card:hover .cat-bg-img { transform:scale(1.08); }
    .cat-card::after { content:''; position:absolute; inset:0; background:linear-gradient(to top,rgba(0,0,0,.88) 0%,rgba(0,0,0,.35) 55%,rgba(0,0,0,.1) 100%); z-index:1; }
    .cat-icon-wrap { position:absolute; top:1.2rem; left:1.2rem; z-index:2; width:44px; height:44px; background:rgba(255,255,255,.1); border:1px solid rgba(255,255,255,.15); backdrop-filter:blur(6px); border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:1.15rem; transition:background .3s ease,transform .3s ease; }
    .cat-card:hover .cat-icon-wrap { background:var(--accent); border-color:var(--accent); transform:scale(1.1); }
    .cat-card-content-inner { position:relative; z-index:2; }
    .cat-title { font-size:.88rem; font-weight:800; margin-bottom:4px; line-height:1.2; }
    .cat-desc  { font-size:.7rem; color:rgba(255,255,255,.55); line-height:1.5; }
    .cat-link  { font-size:.65rem; font-weight:700; color:var(--accent); margin-top:6px; letter-spacing:.05em; text-transform:uppercase; display:flex; align-items:center; gap:4px; }

    /* ── PRODUCT CARDS ────────────────────────────────── */
    .products-row-5 { display:grid; grid-template-columns:repeat(5,1fr); gap:16px; }
    .prod-card { background:var(--card); border-radius:18px; overflow:hidden; box-shadow:var(--shadow-card); display:flex; flex-direction:column; transition:box-shadow .3s ease,transform .3s ease; position:relative; }
    .prod-card:hover { box-shadow:0 16px 40px rgba(10,10,15,.14); transform:translateY(-4px); }

    /* ── RIBBON BADGE (corner triangle — matches screenshot) ── */
    .prod-card-ribbon {
        position: absolute;
        top: 0;
        right: 0;
        width: 86px;
        height: 86px;
        overflow: hidden;
        z-index: 4;
        pointer-events: none;
        border-radius: 0 18px 0 0; /* match card corner */
    }
    .prod-card-ribbon .ribbon-inner {
        position: absolute;
        top: 14px;
        right: -24px;
        width: 96px;
        padding: 6px 0 5px;
        text-align: center;
        transform: rotate(45deg);
        box-shadow: 0 3px 10px rgba(0,0,0,.22);
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 1px;
        line-height: 1;
    }
    .prod-card-ribbon .ribbon-inner span {
        display: block;
        font-family: 'Red Hat Display', sans-serif;
        color: #fff;
        white-space: nowrap;
    }
    .prod-card-ribbon .ribbon-inner .r-top {
        font-size: .6rem;
        font-weight: 700;
        letter-spacing: .06em;
        text-transform: uppercase;
        opacity: .92;
    }
    .prod-card-ribbon .ribbon-inner .r-main {
        font-size: .75rem;
        font-weight: 900;
        letter-spacing: .03em;
        text-transform: uppercase;
    }

    /* Colour variants */
    .ribbon-sale   .ribbon-inner { background: linear-gradient(135deg, #ff3b30, #c0392b); }
    .ribbon-today  .ribbon-inner { background: linear-gradient(135deg, #0cb100, #087a00); }
    .ribbon-seller .ribbon-inner { background: linear-gradient(135deg, #003879, #0080ff); }

    /* Keep low-stock as a small pill — not a ribbon */
    .badge-low { position:absolute; bottom:8px; left:8px; z-index:4; display:inline-block; background:#f59e0b; color:#fff; font-size:.6rem; font-weight:700; padding:3px 8px; border-radius:100px; }

    .prod-card-img-wrap { height:170px; background:var(--surface); display:flex; align-items:center; justify-content:center; text-decoration:none; overflow:hidden; position:relative; }
    .prod-card-img-wrap img { max-width:90%; max-height:90%; object-fit:contain; transition:transform .4s ease; }
    .prod-card:hover .prod-card-img-wrap img { transform:scale(1.06); }
    .prod-card-img-ph { font-size:2.5rem; color:var(--ink-muted); opacity:.3; }
    .prod-card-hover-overlay { position:absolute; inset:0; background:rgba(12,177,0,.88); display:flex; align-items:center; justify-content:center; opacity:0; transition:opacity .25s ease; }
    .prod-card:hover .prod-card-hover-overlay { opacity:1; }
    .prod-card-hover-overlay span { color:#fff; font-size:.82rem; font-weight:700; letter-spacing:.04em; }
    .prod-card-body { padding:1rem; display:flex; flex-direction:column; flex:1; gap:4px; }
    .prod-card-brand { font-size:.65rem; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:var(--accent); }
    .prod-card-name { font-size:.82rem; font-weight:700; color:var(--ink); line-height:1.35; text-decoration:none; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; flex:1; }
    .prod-card-name:hover { color:var(--accent); }
    .prod-card-pricing { display:flex; align-items:baseline; gap:7px; margin-top:4px; flex-wrap:wrap; }
    .prod-price { font-size:.88rem; font-weight:800; color:var(--ink); }
    .prod-orig  { font-size:.72rem; color:var(--ink-muted); text-decoration:line-through; }
    .prod-add-btn { margin-top:8px; width:100%; padding:9px; background:var(--accent); color:#fff; border:none; border-radius:10px; font-family:'Red Hat Display',sans-serif; font-size:.78rem; font-weight:700; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:6px; transition:all .2s ease; box-shadow:0 2px 10px rgba(12,177,0,.3); }
    .prod-add-btn:hover { background:#098600; transform:translateY(-1px); }

    /* ── DEALS ────────────────────────────────────────── */
    .deals-section { padding:5rem 0; background:linear-gradient(135deg,#050510 0%,#0a0a1a 50%,#050510 100%); position:relative; overflow:hidden; }
    .deals-section::before { content:''; position:absolute; top:-100px; left:-100px; width:500px; height:500px; background:radial-gradient(circle,rgba(12,177,0,.12) 0%,transparent 70%); pointer-events:none; }
    .deals-section::after  { content:''; position:absolute; bottom:-100px; right:-100px; width:400px; height:400px; background:radial-gradient(circle,rgba(59,130,246,.1) 0%,transparent 70%); pointer-events:none; }
    .deals-inner { max-width:1300px; margin:0 auto; padding:0 2rem; position:relative; z-index:1; }
    .deals-header { display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:2.5rem; flex-wrap:wrap; gap:1.5rem; }
    .deals-countdown { display:flex; align-items:center; gap:8px; background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.08); backdrop-filter:blur(10px); padding:1rem 1.5rem; border-radius:16px; }
    .cd-block { display:flex; flex-direction:column; align-items:center; min-width:52px; }
    .cd-num { font-size:1.8rem; font-weight:800; color:var(--accent); line-height:1; font-variant-numeric:tabular-nums; }
    .cd-lbl { font-size:.6rem; font-weight:700; text-transform:uppercase; letter-spacing:.1em; color:rgba(255,255,255,.35); margin-top:3px; }
    .cd-sep { font-size:1.5rem; font-weight:800; color:rgba(255,255,255,.2); margin-bottom:12px; }
    .deal-card { background:rgba(255,255,255,.04) !important; border:1px solid rgba(255,255,255,.07) !important; }
    .deal-card .prod-card-name     { color:#e0e0f0 !important; }
    .deal-card .prod-price         { color:#fff !important; }
    .deal-card .prod-orig          { color:rgba(255,255,255,.35) !important; }
    .deal-card .prod-card-img-wrap { background:rgba(255,255,255,.04) !important; }

    /* ── PROMO BANNER ─────────────────────────────────── */
.promo-banner-section {
    width: 100%;
    position: relative;
    overflow: hidden;
    background: #050510;
}
.promo-banner-inner {
    position: relative;
    width: 100%;
    aspect-ratio: 16/7;
    min-height: 420px;
    max-height: 620px;
    display: flex;
    align-items: center;
}
.promo-banner-bg {
    position: absolute;
    inset: 0;
    z-index: 0;
}
.promo-banner-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    object-position: center;
    display: block;
}
.promo-banner-overlay {
    position: absolute;
    inset: 0;
    background: linear-gradient(
        100deg,
        rgba(5,5,16,.92) 0%,
        rgba(5,5,16,.72) 45%,
        rgba(5,5,16,.25) 75%,
        rgba(5,5,16,.1) 100%
    );
    z-index: 1;
}
.promo-banner-content {
    position: relative;
    z-index: 2;
    padding: 4rem clamp(1.5rem, 6vw, 6rem);
    max-width: 640px;
}
.promo-banner-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-size: .75rem;
    font-weight: 700;
    letter-spacing: .12em;
    text-transform: uppercase;
    color: var(--accent);
    margin-bottom: 1.25rem;
}
.promo-dot {
    width: 7px;
    height: 7px;
    background: var(--accent);
    border-radius: 50%;
    display: inline-block;
    box-shadow: 0 0 10px rgba(12,177,0,.8);
    animation: promoDotPulse 1.8s ease-in-out infinite;
}
@keyframes promoDotPulse {
    0%, 100% { transform: scale(1); opacity: 1; }
    50%       { transform: scale(1.5); opacity: .6; }
}
.promo-banner-title {
    font-size: clamp(2rem, 4.5vw, 3.6rem);
    font-weight: 800;
    color: #fff;
    line-height: 1.05;
    letter-spacing: -.03em;
    margin-bottom: 1.25rem;
}
.promo-banner-title em {
    font-style: normal;
    background: linear-gradient(135deg, #1eff00 0%, #00b4d8 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}
.promo-banner-sub {
    font-size: 1rem;
    color: rgba(255,255,255,.55);
    line-height: 1.7;
    margin-bottom: 2rem;
    max-width: 440px;
}
.promo-banner-actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 2.5rem;
}
.promo-banner-stats {
    display: flex;
    align-items: center;
    gap: 1.5rem;
    flex-wrap: wrap;
}
.promo-stat {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.promo-stat-num {
    font-size: 1.4rem;
    font-weight: 800;
    color: #fff;
    line-height: 1;
}
.promo-stat-lbl {
    font-size: .68rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: rgba(255,255,255,.38);
}
.promo-stat-divider {
    width: 1px;
    height: 36px;
    background: rgba(255,255,255,.12);
}

@media (max-width: 768px) {
    .promo-banner-inner { aspect-ratio: unset; min-height: 480px; }
    .promo-banner-overlay {
        background: linear-gradient(180deg, rgba(5,5,16,.55) 0%, rgba(5,5,16,.92) 60%);
    }
    .promo-banner-content { padding: 2rem 1.5rem; max-width: 100%; }
    .promo-banner-title { font-size: clamp(1.8rem, 7vw, 2.4rem); }
}

    /* ── BESTSELLERS ──────────────────────────────────── */
    .bestsellers-section { padding:6rem 0; background:var(--surface); }

    /* ── FABs ─────────────────────────────────────────── */
    .wa-btn { position:fixed; bottom:24px; right:24px; width:56px; height:56px; background:#25d366; color:white; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:1.5rem; z-index:9999; text-decoration:none; box-shadow:0 6px 20px rgba(37,211,102,.45); transition:transform .25s ease; }
    .wa-btn:hover { transform:scale(1.1); color:white; text-decoration:none; }
    .cart-fab { position:fixed; bottom:89px; right:24px; width:56px; height:56px; background:var(--accent); color:#fff; border-radius:50%; display:none; align-items:center; justify-content:center; font-size:1.3rem; z-index:9999; text-decoration:none; box-shadow:0 6px 20px rgba(12,177,0,.45); transition:transform .25s ease; }
    .cart-fab:hover { transform:scale(1.1); color:#fff; text-decoration:none; }
    .cart-fab .cart-fab-badge { position:absolute; top:10px; right:2px; min-width:18px; height:18px; background:#fff; color:var(--accent); font-size:.6rem; font-weight:800; border-radius:999px; display:flex; align-items:center; justify-content:center; padding:0 3px; border:2px solid var(--accent); }

    /* ── RESPONSIVE ───────────────────────────────────── */
    @media(max-width:1200px) { .cats-grid{grid-template-columns:repeat(3,1fr)} .products-row-5{grid-template-columns:repeat(3,1fr)} }
    @media(max-width:768px)  { .cats-grid{grid-template-columns:repeat(2,1fr)} .products-row-5{grid-template-columns:repeat(2,1fr)} .deals-header{flex-direction:column;align-items:flex-start} .cat-card{aspect-ratio:1} }
    @media(max-width:600px)  { .cats-grid{grid-template-columns:repeat(2,1fr);gap:10px} .products-row-5{grid-template-columns:repeat(2,1fr);gap:10px} .slider-arrow{display:none} .cart-fab{display:flex} }
    @media(max-width:400px)  { .products-row-5{grid-template-columns:1fr} }
</style>


<!-- HERO -->
<section class="hero">
    <div class="hero-slides">
        <?php foreach ($hero_slides as $i => $slide):
            $bg = !empty($slide['image_url']) ? 'style="background-image:url(\'' . htmlspecialchars($slide['image_url']) . '\')"' : '';
        ?>
        <div class="hero-slide <?= $i === 0 ? 'active' : '' ?>" <?= $bg ?>></div>
        <?php endforeach; ?>
    </div>
    <div class="hero-grid"></div>
    <div class="hero-glow"></div>
    <!--<div class="hero-content-wrap">
        <?php foreach ($hero_slides as $i => $slide):
            $link      = !empty($slide['link_url'])       ? htmlspecialchars($slide['link_url'])       : 'products.php';
            $btn_text  = !empty($slide['btn_text'])       ? htmlspecialchars($slide['btn_text'])       : 'Shop Now';
            $ghost_txt = !empty($slide['btn_ghost_text']) ? htmlspecialchars($slide['btn_ghost_text']) : 'View All';
        ?>
        <div class="hero-slide-content <?= $i === 0 ? 'active' : '' ?>">
            <div class="hero-pill"><span></span> IT Shop.LK &mdash; Sri Lanka&rsquo;s #1 Tech Store</div>
            <h1 class="hero-title">
                <?= htmlspecialchars($slide['title']) ?>
                <?php if (!empty($slide['subtitle'])): ?><br><em><?= htmlspecialchars($slide['subtitle']) ?></em><?php endif; ?>
            </h1>
            <p class="hero-sub">Explore the latest laptops, desktops, components &amp; accessories &mdash; delivered to your door across Sri Lanka.</p>
            <div class="hero-actions">
                <a href="<?= $link ?>" class="btn-primary-custom"><?= $btn_text ?> <i class="fas fa-arrow-right"></i></a>
                <a href="products.php" class="btn-ghost"><?= $ghost_txt ?> <i class="fas fa-th-large"></i></a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>-->
    <?php if (count($hero_slides) > 1): ?>
    <button class="slider-arrow slider-arrow-prev" aria-label="Previous slide"><i class="fas fa-chevron-left"></i></button>
    <button class="slider-arrow slider-arrow-next" aria-label="Next slide"><i class="fas fa-chevron-right"></i></button>
    <div class="slider-dots" id="slider-dots">
        <?php foreach ($hero_slides as $i => $s): ?>
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
        <?php
        $categories = [
            ['icon'=>'fa-laptop',    'title'=>'Laptops & Notebooks',  'desc'=>'High-performance laptops for gaming, business & everyday use.', 'url'=>'products.php?category=laptops',    'img'=>'uploads/homeassets/laptops.png',    'color'=>'#0d1b2a'],
            ['icon'=>'fa-desktop',   'title'=>'Desktop PCs',          'desc'=>'Custom-built desktops and workstations for maximum performance.','url'=>'products.php?category=desktops',   'img'=>'assets/homeassets/desktops.jpg',   'color'=>'#0a1628'],
            ['icon'=>'fa-memory',    'title'=>'RAM & Storage',         'desc'=>'High-speed memory modules and storage solutions.',               'url'=>'products.php?category=memory',     'img'=>'assets/homeassets/memory.jpg',     'color'=>'#0d1a0d'],
            ['icon'=>'fa-tv',        'title'=>'Graphics Cards',        'desc'=>'Latest VGA cards for gaming and professional work.',             'url'=>'products.php?category=graphics',   'img'=>'assets/homeassets/graphics.jpg',   'color'=>'#1a0d28'],
            ['icon'=>'fa-keyboard',  'title'=>'Keyboards & Mice',      'desc'=>'Premium input devices for gaming and productivity.',             'url'=>'products.php?category=peripherals','img'=>'assets/homeassets/peripherals.jpg','color'=>'#1a1200'],
            ['icon'=>'fa-headphones','title'=>'Audio Devices',         'desc'=>'High-quality headphones, speakers and audio equipment.',         'url'=>'products.php?category=audio',      'img'=>'assets/homeassets/audio.jpg',      'color'=>'#0d1a1a'],
        ];
        foreach ($categories as $i => $cat): ?>
        <a href="<?= $cat['url'] ?>" class="cat-card" style="transition-delay:<?= $i * 70 ?>ms;background-color:<?= $cat['color'] ?>">
            <img class="cat-bg-img" src="<?= htmlspecialchars($cat['img']) ?>" alt="<?= htmlspecialchars($cat['title']) ?>" loading="lazy" onerror="this.style.display='none'">
            <div class="cat-icon-wrap"><i class="fas <?= $cat['icon'] ?>"></i></div>
            <div class="cat-card-content-inner">
                <div class="cat-title"><?= $cat['title'] ?></div>
                <div class="cat-desc"><?= $cat['desc'] ?></div>
                <div class="cat-link">View Products <i class="fas fa-arrow-right" style="font-size:.75rem"></i></div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
</section>


<!-- LIMITED-TIME DEALS 
<section class="deals-section">
    <div class="deals-inner">
        <div class="deals-header">
            <div>
                <div class="section-eyebrow">🔥 Flash Sale</div>
                <h2 class="section-heading" style="color:#fff">Limited-Time Deals</h2>
                <p class="section-sub" style="color:rgba(255,255,255,.45)">Today only — prices drop at midnight</p>
            </div>
            <div class="deals-countdown">
                <div class="cd-block"><span class="cd-num" id="cd-h">00</span><span class="cd-lbl">Hours</span></div>
                <div class="cd-sep">:</div>
                <div class="cd-block"><span class="cd-num" id="cd-m">00</span><span class="cd-lbl">Mins</span></div>
                <div class="cd-sep">:</div>
                <div class="cd-block"><span class="cd-num" id="cd-s">00</span><span class="cd-lbl">Secs</span></div>
            </div>
        </div>

        <?php if (!empty($deal_products)): ?>
        <div class="products-row-5">
            <?php foreach ($deal_products as $p):
                $disc = (!empty($p['original_price']) && $p['original_price'] > $p['price'])
                        ? round((1 - $p['price'] / $p['original_price']) * 100) : 0;
                $stk  = (int)$p['stock_count'];
                $img  = trim($p['image'] ?? '');
            ?>
            <div class="prod-card deal-card">

                 ── RIBBON: Sale discount ── 
                <?php if ($disc > 0): ?>
                <div class="prod-card-ribbon ribbon-sale">
                    <div class="ribbon-inner">
                        <span class="r-top">Limited</span>
                        <span class="r-main">Offers</span>
                    </div>
                </div>
                <?php else: ?>
                 ── RIBBON: Only Today (when no discount %) ── 
                <div class="prod-card-ribbon ribbon-today">
                    <div class="ribbon-inner">
                        <span class="r-top">Only</span>
                        <span class="r-main">Today</span>
                    </div>
                </div>
                <?php endif; ?>

                 Low stock pill (bottom-left, non-ribbon) 
                <?php if ($stk > 0 && $stk <= 5): ?>
                <span class="badge-low">Only <?= $stk ?> left</span>
                <?php endif; ?>

                <a href="product-details.php?id=<?= (int)$p['id'] ?>" class="prod-card-img-wrap">
                    <?php if ($img !== ''): ?>
                    <img src="<?= htmlspecialchars($img) ?>" alt="<?= htmlspecialchars($p['name']) ?>" loading="lazy"
                         onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                    <div class="prod-card-img-ph" style="display:none"><i class="fas <?= cat_icon($p['category'] ?? '') ?>"></i></div>
                    <?php else: ?>
                    <div class="prod-card-img-ph"><i class="fas <?= cat_icon($p['category'] ?? '') ?>"></i></div>
                    <?php endif; ?>
                    <div class="prod-card-hover-overlay"><span><i class="fas fa-eye"></i> Quick View</span></div>
                </a>
                <div class="prod-card-body">
                    <div class="prod-card-brand"><?= htmlspecialchars($p['brand'] ?? '') ?></div>
                    <a href="product-details.php?id=<?= (int)$p['id'] ?>" class="prod-card-name"><?= htmlspecialchars($p['name']) ?></a>
                    <div class="prod-card-pricing">
                        <span class="prod-price">LKR <?= number_format($p['price']) ?></span>
                        <?php if ($disc > 0): ?><span class="prod-orig">LKR <?= number_format($p['original_price']) ?></span><?php endif; ?>
                    </div>
                    <button class="prod-add-btn" onclick="addToCart(<?= (int)$p['id'] ?>, this)">
                        <i class="fas fa-cart-plus"></i> Add to Cart
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div style="text-align:center;padding:3rem;color:rgba(255,255,255,.35);font-size:.9rem">
            <i class="fas fa-tag" style="font-size:2rem;display:block;margin-bottom:.75rem;opacity:.2"></i>
            No deals right now — check back soon!
        </div>
        <?php endif; ?>

        <div style="text-align:center;margin-top:2rem">
             <a href="products.php" class="btn-primary-custom">View All Products <i class="fas fa-arrow-right"></i></a>
        </div>
    </div>
</section>-->

<!-- FULL-WIDTH PROMO BANNER -->
<section class="promo-banner-section">
    <div class="promo-banner-inner">
        <div class="promo-banner-bg">
            <!-- Replace src with your 1920x1080 image path -->
            <img src="assets/homeassets/promo.png" alt="Special Promotion" class="promo-banner-img"
                 onerror="this.style.display='none'">
            <div class="promo-banner-overlay"></div>
        </div>
        <div class="promo-banner-content">
            <!--<div class="promo-banner-eyebrow"><span class="promo-dot"></span> Exclusive Offer</div>-->
            <h2 class="promo-banner-title">Upgrade Your Setup<br><em>Like Never Before</em></h2>
            <p class="promo-banner-sub">Discover premium gear, unbeatable prices, and lightning-fast delivery across Sri Lanka. Your dream workstation starts here.</p>
            <div class="promo-banner-actions">
                <a href="products.php" class="btn-primary-custom">Shop Now <i class="fas fa-arrow-right"></i></a>
                <a href="products.php" class="btn-ghost">View All Products <i class="fas fa-th-large"></i></a>
            </div>
            <!--<div class="promo-banner-stats">
                <div class="promo-stat"><span class="promo-stat-num">10K+</span><span class="promo-stat-lbl">Happy Customers</span></div>
                <div class="promo-stat-divider"></div>
                <div class="promo-stat"><span class="promo-stat-num">5K+</span><span class="promo-stat-lbl">Products</span></div>
                <div class="promo-stat-divider"></div>
                <div class="promo-stat"><span class="promo-stat-num">Fast</span><span class="promo-stat-lbl">Island-Wide Delivery</span></div>
            </div>-->
        </div>
    </div>
</section>


<!-- BEST SELLING PRODUCTS -->
<section class="bestsellers-section">
    <div class="section-header">
        <div class="section-eyebrow">⭐ Top Picks</div>
        <h2 class="section-heading">Best Selling Products</h2>
        <p class="section-sub">Most loved by our customers — trusted, tested, delivered fast</p>
    </div>

    <?php if (!empty($best_products)): ?>
    <div class="products-row-5" style="max-width:1300px;margin:0 auto;padding:0 2rem">
        <?php foreach ($best_products as $idx => $p):
            $disc = (!empty($p['original_price']) && $p['original_price'] > $p['price'])
                    ? round((1 - $p['price'] / $p['original_price']) * 100) : 0;
            $img  = trim($p['image'] ?? '');
        ?>
        <div class="prod-card" style="opacity:0;transform:translateY(20px);transition:opacity .5s ease <?= $idx * 55 ?>ms,transform .5s ease <?= $idx * 55 ?>ms,box-shadow .3s ease">

            <!-- ── RIBBON: Best Seller ── -->
            <div class="prod-card-ribbon ribbon-seller">
                <div class="ribbon-inner">
                    <span class="r-top">Best</span>
                    <span class="r-main">SELLER</span>
                </div>
            </div>

            <a href="product-details.php?id=<?= (int)$p['id'] ?>" class="prod-card-img-wrap">
                <?php if ($img !== ''): ?>
                <img src="<?= htmlspecialchars($img) ?>" alt="<?= htmlspecialchars($p['name']) ?>" loading="lazy"
                     onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                <div class="prod-card-img-ph" style="display:none"><i class="fas <?= cat_icon($p['category'] ?? '') ?>"></i></div>
                <?php else: ?>
                <div class="prod-card-img-ph"><i class="fas <?= cat_icon($p['category'] ?? '') ?>"></i></div>
                <?php endif; ?>
                <div class="prod-card-hover-overlay"><span><i class="fas fa-eye"></i> Quick View</span></div>
            </a>
            <div class="prod-card-body">
                <div class="prod-card-brand"><?= htmlspecialchars($p['brand'] ?? '') ?></div>
                <a href="product-details.php?id=<?= (int)$p['id'] ?>" class="prod-card-name"><?= htmlspecialchars($p['name']) ?></a>
                <div class="prod-card-pricing">
                    <span class="prod-price">LKR <?= number_format($p['price']) ?></span>
                    <?php if ($disc > 0): ?><span class="prod-orig">LKR <?= number_format($p['original_price']) ?></span><?php endif; ?>
                </div>
                <button class="prod-add-btn" onclick="addToCart(<?= (int)$p['id'] ?>, this)">
                    <i class="fas fa-cart-plus"></i> Add to Cart
                </button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div style="text-align:center;padding:3rem;color:var(--ink-muted);font-size:.9rem">
        <i class="fas fa-star" style="font-size:2rem;display:block;margin-bottom:.75rem;opacity:.18"></i>
        No featured products yet.
    </div>
    <?php endif; ?>

    <div style="text-align:center;margin-top:2.5rem">
        <!--<a href="products.php" class="btn-view-all">Browse Full Catalogue <i class="fas fa-arrow-right"></i></a>-->
    </div>
</section>




<!-- Cart FAB (mobile) -->
<a href="cart.php" class="cart-fab" aria-label="View cart">
    <i class="fas fa-shopping-cart"></i>
    <?php if ($cart_count > 0): ?>
    <span class="cart-fab-badge"><?= (int)$cart_count ?></span>
    <?php endif; ?>
</a>

<?php
$extra_scripts = <<<'JS'
<script>
(function () {

    // ── HERO SLIDER ──────────────────────────────────────────
    const slides   = document.querySelectorAll('.hero-slide');
    const contents = document.querySelectorAll('.hero-slide-content');
    const dots     = document.querySelectorAll('#slider-dots .slider-dot');
    const progress = document.getElementById('slider-progress');
    const INTERVAL = 5000;
    let current = 0, timer;

    function goTo(n) {
        slides[current].classList.remove('active');
        contents[current] && contents[current].classList.remove('active');
        dots[current]     && dots[current].classList.remove('active');
        current = (n + slides.length) % slides.length;
        slides[current].classList.add('active');
        contents[current] && contents[current].classList.add('active');
        dots[current]     && dots[current].classList.add('active');
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

    document.querySelector('.slider-arrow-prev')?.addEventListener('click', () => { goTo(current - 1); startAuto(); });
    document.querySelector('.slider-arrow-next')?.addEventListener('click', () => { goTo(current + 1); startAuto(); });
    dots.forEach((d, i) => d.addEventListener('click', () => { goTo(i); startAuto(); }));
    document.addEventListener('keydown', e => {
        if (e.key === 'ArrowLeft')  { goTo(current - 1); startAuto(); }
        if (e.key === 'ArrowRight') { goTo(current + 1); startAuto(); }
    });

    const hero = document.querySelector('.hero');
    let touchX = 0;
    hero?.addEventListener('touchstart', e => { touchX = e.changedTouches[0].clientX; }, { passive: true });
    hero?.addEventListener('touchend',   e => {
        const dx = e.changedTouches[0].clientX - touchX;
        if (Math.abs(dx) > 50) { goTo(dx < 0 ? current + 1 : current - 1); startAuto(); }
    }, { passive: true });
    hero?.addEventListener('mouseenter', () => clearInterval(timer));
    hero?.addEventListener('mouseleave', () => startAuto());

    if (slides.length > 1) startAuto(); else resetProgress();

    // ── CATEGORY CARD REVEAL ─────────────────────────────────
    const catIO = new IntersectionObserver(entries => {
        entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('visible'); catIO.unobserve(e.target); } });
    }, { threshold: 0.1, rootMargin: '0px 0px -40px 0px' });
    document.querySelectorAll('.cat-card').forEach(c => catIO.observe(c));

    // ── PRODUCT CARD SCROLL REVEAL ───────────────────────────
    const prodIO = new IntersectionObserver(entries => {
        entries.forEach(e => {
            if (e.isIntersecting) {
                e.target.style.opacity = '1';
                e.target.style.transform = 'translateY(0)';
                prodIO.unobserve(e.target);
            }
        });
    }, { threshold: 0.08 });
    document.querySelectorAll('.prod-card').forEach(c => prodIO.observe(c));

    // ── COUNTDOWN TIMER ──────────────────────────────────────
    function updateCountdown() {
        const now = new Date(), end = new Date();
        end.setHours(23, 59, 59, 999);
        const diff = Math.max(0, end - now);
        const pad = n => String(n).padStart(2, '0');
        const cdH = document.getElementById('cd-h');
        const cdM = document.getElementById('cd-m');
        const cdS = document.getElementById('cd-s');
        if (cdH) cdH.textContent = pad(Math.floor(diff / 3600000));
        if (cdM) cdM.textContent = pad(Math.floor((diff % 3600000) / 60000));
        if (cdS) cdS.textContent = pad(Math.floor((diff % 60000) / 1000));
    }
    updateCountdown();
    setInterval(updateCountdown, 1000);

    // ── ADD TO CART ──────────────────────────────────────────
    window.addToCart = function(id, btn) {
        const origHTML = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Adding...';
        fetch('cart_add.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'product_id=' + id + '&quantity=1'
        })
        .then(r => r.json())
        .then(() => {
            btn.innerHTML = '<i class="fas fa-check"></i> Added!';
            btn.style.background = '#16a34a';
            setTimeout(() => { btn.innerHTML = origHTML; btn.style.background = ''; btn.disabled = false; }, 1800);
        })
        .catch(() => { window.location.href = 'cart.php?add=' + id; });
    };

})();
</script>
JS;

include 'footer.php';
?>