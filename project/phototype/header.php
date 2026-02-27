<?php
// header.php — IT Shop.LK  (ticker driven by admin panel DB settings)

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($pdo)) include __DIR__ . '/db.php';

$cart_count    = 0;
$user_currency = 'LKR';
$cart_total    = 0;

if (isset($_SESSION['user_id']) && isset($pdo)) {
    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) as count, SUM(price * quantity) as total FROM cart WHERE user_id = ?"
        );
        $stmt->execute([$_SESSION['user_id']]);
        $row        = $stmt->fetch(PDO::FETCH_ASSOC);
        $cart_count = (int)($row['count'] ?? 0);
        $cart_total = (float)($row['total'] ?? 0);
    } catch (PDOException $e) {}
}

// ── Ticker: load settings & active messages from DB ───────────────────────────
$ticker_enabled  = true;
$ticker_speed    = 2;
$ticker_color    = '#3b5bdb';
$ticker_messages = [];

if (isset($pdo)) {
    try {
        $ts = $pdo->query(
            "SELECT setting_key, setting_val FROM ticker_settings"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        $ticker_enabled = ($ts['ticker_enabled'] ?? '1') === '1';
        $ticker_speed   = max(10, min(120, (int)($ts['ticker_speed'] ?? 35)));
        $ticker_color   = (preg_match('/^#[0-9a-f]{6}$/i', $ts['ticker_color'] ?? ''))
                          ? $ts['ticker_color'] : '#3b5bdb';
    } catch (PDOException $e) {}

    try {
        $stmt = $pdo->query(
            "SELECT * FROM ticker_items WHERE is_active = 1 ORDER BY sort_order ASC, id ASC"
        );
        $ticker_messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}
}

if (empty($ticker_messages)) {
    $ticker_messages = [
        ['emoji' => '🔥', 'message' => 'Free delivery on orders over LKR 50,000',               'link_url' => '',                            'link_text' => ''],
        ['emoji' => '💻', 'message' => 'New Arrivals: Intel Core Ultra 200 Series now in stock', 'link_url' => '',                            'link_text' => ''],
        ['emoji' => '🖥️', 'message' => 'Up to 20% off on Gaming Monitors this week',             'link_url' => '',                            'link_text' => ''],
        ['emoji' => '⚡', 'message' => 'RTX 5080 pre-orders open',                                'link_url' => 'products.php?category=graphics','link_text' => 'Reserve yours'],
        ['emoji' => '🛡️', 'message' => '1-Year Local Warranty on all laptops',                    'link_url' => '',                            'link_text' => ''],
        ['emoji' => '🎧', 'message' => 'Buy any headset &amp; get a free mouse pad',              'link_url' => '',                            'link_text' => ''],
        ['emoji' => '📦', 'message' => 'Same-day dispatch for orders placed before 2 PM',         'link_url' => '',                            'link_text' => ''],
    ];
}

$page_title = $page_title ?? 'IT Shop.LK - Best Computer Store';
$cur_page   = basename($_SERVER['PHP_SELF']);
$cur_search = $_GET['search'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?></title>
    <link rel="icon" type="image/x-icon" href="assets/logo.png">
    <meta name="description" content="Shop laptops, PCs, RAM, VGA cards, keyboards, mice and audio devices from IT Shop.LK">

    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Red+Hat+Display:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

    <style>
        /* ═══════════════════════════════════════════════
           TOKENS
        ═══════════════════════════════════════════════ */
        :root {
            --navbar-h:  62px;
            --ticker-h:  34px;

            --accent:        #0cb100;
            --accent-dark:   #087600;
            --accent-soft:   rgba(12,177,0,.07);
            --accent-border: rgba(12,177,0,.22);
            --ink:           #0d0d14;
            --ink-2:         #3c523c;
            --ink-3:         #2a3b29;
            --surface:       #ffffff;
            --white:         #ffffff;
            --r-sm:   8px;
            --r-md:   12px;
            --r-lg:   18px;
            --r-full: 999px;
            --shadow-drop: 0 8px 32px rgba(13,13,20,.12);

            --primary-color:   #0a00cc;
            --secondary-color: #10b981;
            --text-dark:       #0d0d14;
            --text-light:      #3f523c;
            --bg-light:        #f5f5f8;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Red Hat Display', sans-serif;
            color: var(--ink);
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
            padding-top: calc(var(--navbar-h) + var(--ticker-h));
        }
        body.ticker-off { padding-top: var(--navbar-h) !important; }

        /* ═══════════════════════════════════════════════
           NAVBAR
        ═══════════════════════════════════════════════ */
        .navbar {
            background: rgba(255,255,255,.88);
            backdrop-filter: blur(20px) saturate(180%);
            -webkit-backdrop-filter: blur(20px) saturate(180%);
            border-bottom: 1px solid rgba(0,0,0,.06);
            padding: .6rem 0;
            height: var(--navbar-h);
            transition: background .3s, box-shadow .3s;
        }
        .navbar.is-scrolled {
            background: rgba(255,255,255,.97);
            box-shadow: 0 1px 0 rgba(0,0,0,.06), 0 4px 24px rgba(0,0,0,.06);
        }
        .navbar-brand img { height: 44px; }

        /* nav links */
        .navbar-nav .nav-link {
            font-size: .875rem; font-weight: 600;
            color: var(--ink-2) !important;
            padding: .45rem .9rem !important;
            border-radius: var(--r-sm); letter-spacing: .01em;
            transition: color .15s, background .15s;
        }
        .navbar-nav .nav-link:hover,
        .navbar-nav .nav-link.active { color: var(--accent) !important; background: var(--accent-soft); }

        /* dropdown */
        .dropdown-menu {
            border: 1px solid rgba(0,0,0,.07); border-radius: var(--r-lg);
            box-shadow: var(--shadow-drop); padding: .5rem; min-width: 210px;
            animation: menuIn .17s ease both;
        }
        @keyframes menuIn {
            from { opacity:0; transform:translateY(-7px) scale(.98); }
            to   { opacity:1; transform:translateY(0)   scale(1);   }
        }
        .dropdown-item {
            font-family: 'Red Hat Display', sans-serif;
            font-size: .855rem; font-weight: 500; color: var(--ink-2);
            border-radius: var(--r-sm); padding: .46rem .85rem;
            transition: background .13s, color .13s;
        }
        .dropdown-item:hover,
        .dropdown-item:focus { background: var(--accent-soft); color: var(--accent); }
        .dropdown-divider { margin: .3rem 0; border-color: rgba(0,0,0,.06); }

        /* icon button */
        .icon-btn {
            position: relative; width: 40px; height: 40px;
            border-radius: var(--r-md); background: transparent; border: none;
            color: var(--ink-2); font-size: 1rem;
            display: inline-flex; align-items: center; justify-content: center;
            text-decoration: none; transition: background .15s, color .15s;
            cursor: pointer; flex-shrink: 0;
        }
        .icon-btn:hover { background: var(--accent-soft); color: var(--accent); }
        .icon-btn .bdot {
            position: absolute; top: 4px; right: 4px;
            min-width: 17px; height: 17px; border-radius: var(--r-full);
            background: var(--accent); color: #fff;
            font-size: .6rem; font-weight: 700;
            display: flex; align-items: center; justify-content: center;
            padding: 0 3px; border: 2px solid var(--white);
        }

        /* cart total */
        .cart-total {
            font-size: .78rem; font-weight: 600; color: var(--ink-3);
            background: var(--surface); border: 1px solid rgba(0,0,0,.14);
            border-radius: var(--r-full); padding: 4px 12px; white-space: nowrap;
        }

        /* separator */
        .v-sep { width:1px; height:22px; background:rgba(0,0,0,.1); flex-shrink:0; }

        /* ── Navbar Search ── */
        .nav-search-form { display: flex; align-items: center; }
        .nav-search-wrap {
            position: relative; display: flex; align-items: center;
        }
        .nav-search-wrap .nav-search-icon {
            position: absolute; left: .7rem;
            color: var(--ink-3); font-size: .75rem;
            pointer-events: none; z-index: 1;
        }
        .nav-search-input {
            font-family: 'Red Hat Display', sans-serif;
            font-size: .82rem; font-weight: 500;
            border: 1px solid rgba(0,0,0,.1);
            border-radius: var(--r-full);
            padding: .42rem 2.4rem .42rem 2.1rem;
            color: var(--ink); background: var(--surface);
            width: 190px;
            transition: border-color .2s, box-shadow .2s, width .3s ease, background .2s;
            outline: none;
        }
        .nav-search-input:focus {
            border-color: var(--accent-border);
            box-shadow: 0 0 0 3px rgba(12,177,0,.1);
            background: var(--white);
            width: 250px;
        }
        .nav-search-input::placeholder { color: var(--ink-3); }
        .nav-search-btn {
            position: absolute; right: 4px;
            width: 28px; height: 28px;
            background: var(--accent); border: none; border-radius: var(--r-full);
            color: #fff; font-size: .68rem;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; transition: background .15s, transform .15s; flex-shrink: 0;
        }
        .nav-search-btn:hover { background: var(--accent-dark); transform: scale(1.05); }

        /* CTA button */
        .btn-cta {
            display: inline-flex; align-items: center; gap: 7px;
            background: var(--accent); color: #fff !important;
            font-family: 'Red Hat Display', sans-serif;
            font-size: .855rem; font-weight: 700;
            padding: 9px 20px; border-radius: var(--r-md); border: none;
            cursor: pointer; text-decoration: none;
            box-shadow: 0 3px 12px rgba(12,177,0,.3);
            transition: background .2s, transform .2s, box-shadow .2s;
        }
        .btn-cta:hover {
            background: var(--accent-dark); transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(12,177,0,.4); color: #fff !important;
        }

        /* account button */
        .btn-account {
            display: inline-flex; align-items: center; gap: 8px;
            background: var(--surface); color: var(--ink-2) !important;
            font-family: 'Red Hat Display', sans-serif;
            font-size: .855rem; font-weight: 600;
            padding: 8px 16px; border-radius: var(--r-md);
            border: 1px solid rgba(0,0,0,.09); cursor: pointer;
            transition: background .15s, border-color .15s, color .15s;
        }
        .btn-account:hover { background: var(--accent-soft); border-color: var(--accent-border); color: var(--accent) !important; }
        .btn-account .av {
            width: 24px; height: 24px; border-radius: 50%;
            background: linear-gradient(135deg, var(--accent), #34d399);
            display: inline-flex; align-items: center; justify-content: center;
            font-size: .6rem; font-weight: 800; color: #fff; flex-shrink: 0;
        }

        /* ═══════════════════════════════════════════════
           MODERN HAMBURGER TOGGLER
        ═══════════════════════════════════════════════ */
        .navbar-toggler {
            border: none !important;
            background: none !important;
            padding: 0 !important;
            outline: none !important;
            box-shadow: none !important;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: var(--r-md);
            transition: background .15s;
            flex-shrink: 0;
        }
        .navbar-toggler:focus { box-shadow: none !important; outline: none !important; }
        .navbar-toggler:hover { background: var(--accent-soft); }

        /* Custom hamburger icon */
        .hamburger-icon {
            width: 22px;
            height: 16px;
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            cursor: pointer;
        }
        .hamburger-icon span {
            display: block;
            height: 2px;
            border-radius: 2px;
            background: var(--ink-2);
            transition: all .3s cubic-bezier(.4,0,.2,1);
            transform-origin: center;
        }
        .hamburger-icon span:nth-child(1) { width: 100%; }
        .hamburger-icon span:nth-child(2) { width: 75%; }
        .hamburger-icon span:nth-child(3) { width: 55%; }

        /* Animated state when open */
        .navbar-toggler[aria-expanded="true"] .hamburger-icon span:nth-child(1) {
            transform: translateY(7px) rotate(45deg);
            width: 100%;
            background: var(--accent);
        }
        .navbar-toggler[aria-expanded="true"] .hamburger-icon span:nth-child(2) {
            opacity: 0;
            transform: scaleX(0);
        }
        .navbar-toggler[aria-expanded="true"] .hamburger-icon span:nth-child(3) {
            transform: translateY(-7px) rotate(-45deg);
            width: 100%;
            background: var(--accent);
        }

        /* ── MOBILE TOP ROW (brand + search + toggler) ── */
        .mobile-top-row {
            display: none;
            width: 100%;
            align-items: center;
            gap: 10px;
        }

        /* Mobile inline search bar (left of toggler) */
        .mobile-search-wrap {
            flex: 1;
            position: relative;
            display: flex;
            align-items: center;
        }
        .mobile-search-icon {
            position: absolute; left: .65rem;
            color: var(--ink-3); font-size: .72rem;
            pointer-events: none; z-index: 1;
        }
        .mobile-search-input {
            font-family: 'Red Hat Display', sans-serif;
            font-size: .82rem; font-weight: 500;
            border: 1px solid rgba(0,0,0,.11);
            border-radius: var(--r-full);
            padding: .42rem 2.2rem .42rem 2rem;
            color: var(--ink);
            background: #f6f8f5;
            width: 100%;
            outline: none;
            transition: border-color .2s, box-shadow .2s, background .2s;
        }
        .mobile-search-input:focus {
            border-color: var(--accent-border);
            box-shadow: 0 0 0 3px rgba(12,177,0,.1);
            background: var(--white);
        }
        .mobile-search-input::placeholder { color: var(--ink-3); }
        .mobile-search-btn {
            position: absolute; right: 4px;
            width: 26px; height: 26px;
            background: var(--accent); border: none; border-radius: var(--r-full);
            color: #fff; font-size: .65rem;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; transition: background .15s;
        }
        .mobile-search-btn:hover { background: var(--accent-dark); }

        /* ═══════════════════════════════════════════════
           TICKER BAR
        ═══════════════════════════════════════════════ */
        .ticker-bar {
            background: <?= htmlspecialchars($ticker_color) ?>;
            color: #fff; font-family: 'Red Hat Display', sans-serif;
            font-size: .82rem; font-weight: 600;
            height: var(--ticker-h);
            display: flex; align-items: center; overflow: hidden;
            position: fixed; top: var(--navbar-h); left: 0; right: 0;
            z-index: 1029;
            transition: transform .3s ease, opacity .3s ease;
        }
        .ticker-bar.hidden { transform: translateY(-100%); opacity: 0; pointer-events: none; }
        .ticker-bar .ticker-label {
            flex-shrink: 0; padding: 0 16px; height: 100%;
            display: flex; align-items: center; gap: 8px;
            font-size: .76rem; font-weight: 700; letter-spacing: .05em;
            white-space: nowrap; opacity: .9;
            border-right: 1px solid rgba(255,255,255,.15);
        }
        .ticker-track {
            display: flex; overflow: hidden; flex: 1;
            mask-image: linear-gradient(to right, transparent 0%, #000 3%, #000 97%, transparent 100%);
            -webkit-mask-image: linear-gradient(to right, transparent 0%, #000 3%, #000 97%, transparent 100%);
        }
        .ticker-inner {
            display: flex; white-space: nowrap;
            animation: tickerScroll <?= $ticker_speed ?>s linear infinite;
        }
        .ticker-inner:hover { animation-play-state: paused; }
        .ticker-item { display: inline-flex; align-items: center; gap: 8px; padding: 0 28px; }
        .ticker-item .sep { width: 4px; height: 4px; border-radius: 50%; background: rgba(255,255,255,.35); display: inline-block; }
        .ticker-item a { color: #fff; text-decoration: underline; text-underline-offset: 2px; }
        .ticker-item a:hover { color: rgba(255,255,255,.75); }
        @keyframes tickerScroll {
            0%   { transform: translateX(0); }
            100% { transform: translateX(-50%); }
        }
        .ticker-close {
            flex-shrink: 0; background: none; border: none;
            color: rgba(255,255,255,.65); cursor: pointer;
            padding: 0 14px; font-size: .72rem; height: 100%;
            transition: color .15s;
        }
        .ticker-close:hover { color: #fff; }

        /* ── MOBILE CART BAR ── */
        .cart-mobile-bar {
            display: none;
            position: fixed; bottom: 0; left: 0; right: 0; z-index: 1040;
            padding: 10px 16px max(14px, env(safe-area-inset-bottom));
            background: linear-gradient(to top, rgba(255,255,255,0.98) 70%, transparent);
            backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
        }
        .cart-mobile-btn {
            display: flex; align-items: center; justify-content: center; gap: 10px;
            width: 100%; background: var(--accent); color: #fff;
            font-family: 'Red Hat Display', sans-serif; font-weight: 700; font-size: .95rem;
            padding: 14px 20px; border-radius: var(--r-md); text-decoration: none;
            box-shadow: 0 4px 18px rgba(12,177,0,0.35);
            transition: background .2s, transform .15s, box-shadow .2s;
            letter-spacing: .01em; position: relative;
        }
        .cart-mobile-btn:hover, .cart-mobile-btn:focus { color: #fff; text-decoration: none; }
        .cart-mobile-btn:active { transform: scale(0.97); background: var(--accent-dark); box-shadow: 0 2px 10px rgba(12,177,0,0.25); }
        .cart-mobile-btn i { font-size: 1.1rem; }
        .cart-mobile-btn .cart-mb-badge {
            background: #fff; color: var(--accent); font-size: .72rem; font-weight: 800;
            min-width: 20px; height: 20px; border-radius: var(--r-full);
            display: inline-flex; align-items: center; justify-content: center;
            padding: 0 5px; margin-left: 2px;
        }
        .cart-mobile-btn .cart-mb-total {
            margin-left: auto; font-size: .82rem; font-weight: 600; opacity: .88;
            background: rgba(255,255,255,0.18); padding: 3px 10px; border-radius: var(--r-full);
        }

        /* ═══════════════════════════════════════════════
           RESPONSIVE — MOBILE
        ═══════════════════════════════════════════════ */
        @media (max-width: 991px) {
            /* Show mobile top row, hide default toggler */
            .mobile-top-row { display: flex; }
            .default-toggler { display: none !important; }

            /* Hide desktop search from right-group on mobile (we use mobile-search-wrap instead) */
            .right-group .nav-search-form { display: none; }

            .cart-mobile-bar { display: block; }
            body { padding-bottom: 76px !important; }

            /* Collapse panel styles */
            .navbar-collapse {
                background: var(--white);
                border-radius: var(--r-lg);
                border: 1px solid rgba(0,0,0,.07);
                box-shadow: var(--shadow-drop);
                padding: 1rem;
                margin-top: .6rem;
            }
            .right-group {
                flex-wrap: wrap;
                gap: 8px !important;
                margin-top: .75rem;
                /* hide cart icon/total in collapse — they're in the bottom bar */
            }

            /* Navbar container on mobile: flex column so mobile-top-row and collapse stack */
            .navbar > .container {
                flex-direction: column;
                align-items: stretch;
                gap: 0;
            }
            /* The collapse toggle target stays as Bootstrap handles it */
        }

        @media (min-width: 992px) {
            /* Hide mobile row on desktop, show default layout */
            .mobile-top-row { display: none !important; }
            .default-toggler { display: flex !important; }
        }
    </style>
</head>
<?php $ticker_show = $ticker_enabled; ?>
<body class="<?= $ticker_show ? '' : 'ticker-off' ?>">

<!-- ═══════════════════════════════════════════════
     NAVBAR
═══════════════════════════════════════════════ -->
<nav class="navbar navbar-expand-lg fixed-top" id="mainNav">
    <div class="container">

        <!-- ══════════════════════════════
             MOBILE TOP ROW
             (Brand | Search Bar | Hamburger)
        ══════════════════════════════ -->
        <div class="mobile-top-row">
            <!-- Brand -->
            <a class="navbar-brand me-0" href="index.php" style="flex-shrink:0">
                <img src="assets/revised-04.png" alt="IT Shop.LK">
            </a>

            <!-- Mobile Search -->
            <form class="mobile-search-wrap" method="GET" action="products.php" role="search">
                <i class="fas fa-search mobile-search-icon"></i>
                <input
                    type="text"
                    name="search"
                    class="mobile-search-input"
                    placeholder="Search products…"
                    value="<?= htmlspecialchars($cur_search) ?>"
                    aria-label="Search products">
                <button type="submit" class="mobile-search-btn" aria-label="Submit search">
                    <i class="fas fa-arrow-right"></i>
                </button>
            </form>

            <!-- Modern Hamburger -->
            <button class="navbar-toggler" type="button"
                    data-bs-toggle="collapse" data-bs-target="#navbarNav"
                    aria-controls="navbarNav" aria-expanded="false"
                    aria-label="Toggle navigation">
                <div class="hamburger-icon">
                    <span></span>
                    <span></span>
                    <span></span>
                </div>
            </button>
        </div>

        <!-- ══════════════════════════════
             DESKTOP BRAND (hidden on mobile)
        ══════════════════════════════ -->
        <a class="navbar-brand d-none d-lg-flex" href="index.php">
            <img src="assets/revised-04.png" alt="IT Shop.LK">
        </a>

        <!-- ══════════════════════════════
             COLLAPSIBLE NAV
        ══════════════════════════════ -->
        <div class="collapse navbar-collapse" id="navbarNav">

            <!-- Nav links -->
            <ul class="navbar-nav mx-auto gap-1">
                <li class="nav-item">
                    <a class="nav-link <?= $cur_page==='index.php' ? 'active' : '' ?>" href="index.php">Home</a>
                </li>

                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?= $cur_page==='products.php' ? 'active' : '' ?>"
                       href="#" id="prodDrop" role="button"
                       data-bs-toggle="dropdown" aria-expanded="false">Products</a>
                    <ul class="dropdown-menu" aria-labelledby="prodDrop">
                        <li>
                            <a class="dropdown-item fw-semibold" href="products.php?category=all">
                                <i class="fas fa-border-all me-2" style="font-size:.75rem;color:var(--accent)"></i>All Products
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <?php foreach ([
                            ['Processors',   'products.php?category=processors'],
                            ['Motherboard',  'products.php?category=motherboards'],
                            ['Coolers',      'products.php?category=coolers'],
                            ['Memory (RAM)', 'products.php?category=memory'],
                            ['SSD',          'products.php?category=storage'],
                            ['Storage',      'products.php?category=storage'],
                            ['Graphic Cards','products.php?category=graphics'],
                            ['Power Supply', 'products.php?category=power'],
                            ['PC Cases',     'products.php?category=cases'],
                            ['Laptops',      'products.php?category=laptops'],
                            ['Desktops',     'products.php?category=desktops'],
                        ] as [$label, $url]): ?>
                            <li><a class="dropdown-item" href="<?= $url ?>"><?= $label ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </li>

                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle"
                       href="#" id="accDrop" role="button"
                       data-bs-toggle="dropdown" aria-expanded="false">Accessories</a>
                    <ul class="dropdown-menu" aria-labelledby="accDrop">
                        <?php foreach ([
                            ['UPS',         'products.php?category=ups'],
                            ['Monitors',    'products.php?category=monitors'],
                            ['Headsets',    'products.php?category=headsets'],
                            ['Keyboards',   'products.php?category=keyboards'],
                            ['Mouse',       'products.php?category=mouse'],
                            ['Speakers',    'products.php?category=speakers'],
                            ['Cables',      'products.php?category=cables'],
                            ['Adapters',    'products.php?category=adapters'],
                            ['Software',    'products.php?category=software'],
                            ['Printers',    'products.php?category=printers'],
                            ['Virus Guard', 'products.php?category=virus-guard'],
                        ] as [$label, $url]): ?>
                            <li><a class="dropdown-item" href="<?= $url ?>"><?= $label ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </li>

                <li class="nav-item">
                    <a class="nav-link <?= $cur_page==='contact.php' ? 'active' : '' ?>" href="contact.php">Contact</a>
                </li>
            </ul>

            <!-- Right group: Search → Cart → Account -->
            <div class="right-group d-flex align-items-center gap-2">

                <!-- ── Search bar (desktop only — hidden on mobile via CSS) ── -->
                <form class="nav-search-form" method="GET" action="products.php" role="search">
                    <div class="nav-search-wrap">
                        <i class="fas fa-search nav-search-icon"></i>
                        <input
                            type="text"
                            name="search"
                            class="nav-search-input"
                            placeholder="Search products…"
                            value="<?= htmlspecialchars($cur_search) ?>"
                            aria-label="Search products">
                        <button type="submit" class="nav-search-btn" aria-label="Submit search">
                            <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                </form>

                <!-- ── Cart icon ── -->
                <a href="cart.php" class="icon-btn" aria-label="View cart">
                    <i class="fas fa-shopping-cart"></i>
                    <?php if ($cart_count > 0): ?>
                        <span class="bdot"><?= $cart_count ?></span>
                    <?php endif; ?>
                </a>

                <?php if ($cart_total > 0): ?>
                    <span class="cart-total"><?= $user_currency . ' ' . number_format($cart_total) ?></span>
                <?php endif; ?>

                <div class="v-sep d-none d-lg-block"></div>

                <!-- ── Account ── -->
                <?php if (isset($_SESSION['user_id'])): ?>
                    <div class="dropdown">
                        <button class="btn-account dropdown-toggle" type="button"
                                id="userDrop" data-bs-toggle="dropdown" aria-expanded="false">
                            <span class="av"><?= strtoupper(mb_substr($_SESSION['user_name'] ?? 'U', 0, 1)) ?></span>
                            <?= htmlspecialchars($_SESSION['user_name'] ?? '') ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDrop">
                            <li><a class="dropdown-item" href="profile.php">
                                <i class="fas fa-id-card me-2" style="font-size:.78rem;color:var(--ink-3)"></i>My Profile</a></li>
                            <li><a class="dropdown-item" href="orders.php">
                                <i class="fas fa-box me-2" style="font-size:.78rem;color:var(--ink-3)"></i>My Orders</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="logout.php">
                                <i class="fas fa-sign-out-alt me-2" style="font-size:.78rem"></i>Logout</a></li>
                        </ul>
                    </div>
                <?php else: ?>
                    <a href="login.php" class="btn-cta">
                        <i class="fas fa-sign-in-alt" style="font-size:.78rem"></i> Login / Sign Up
                    </a>
                <?php endif; ?>

            </div>
        </div>
    </div>
</nav>

<?php if ($ticker_show): ?>
<!-- ═══════════════════════════════════════════════
     TICKER BAR
═══════════════════════════════════════════════ -->
<div class="ticker-bar" id="tickerBar">

    <div class="ticker-label">
        <i class="fas fa-gift" style="font-size:.78rem"></i>
        Limited Offers
    </div>

    <div class="ticker-track">
        <div class="ticker-inner" id="tickerInner">
            <?php foreach ([1, 2] as $pass): foreach ($ticker_messages as $tm): ?>
            <span class="ticker-item">
                <?= htmlspecialchars($tm['emoji'] ?? '') ?>
                <?= htmlspecialchars($tm['message']) ?>
                <?php if (!empty($tm['link_url']) && !empty($tm['link_text'])): ?>
                    — <a href="<?= htmlspecialchars($tm['link_url']) ?>"><?= htmlspecialchars($tm['link_text']) ?></a>
                <?php endif; ?>
                <span class="sep"></span>
            </span>
            <?php endforeach; endforeach; ?>
        </div>
    </div>

    <button class="ticker-close" id="tickerClose" aria-label="Close ticker" title="Dismiss">
        <i class="fas fa-times"></i>
    </button>
</div>
<?php else: ?>
<style>body { padding-top: var(--navbar-h) !important; }</style>
<?php endif; ?>



<script>
(() => {
    // Bootstrap dropdown init
    function initDropdowns() {
        document.querySelectorAll('[data-bs-toggle="dropdown"]').forEach(el => {
            try {
                if (el.getAttribute('href') === '#') el.addEventListener('click', e => e.preventDefault());
                const existing = bootstrap.Dropdown.getInstance(el);
                if (existing) existing.dispose();
                new bootstrap.Dropdown(el);
            } catch (e) {}
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initDropdowns);
    else initDropdowns();
    setTimeout(initDropdowns, 300);

    // Navbar scroll shadow
    const nav = document.getElementById('mainNav');
    if (nav) window.addEventListener('scroll', () => nav.classList.toggle('is-scrolled', window.scrollY > 10), { passive: true });

    // Sync hamburger aria-expanded for CSS animation
    const toggler = document.querySelector('.navbar-toggler[data-bs-target="#navbarNav"]');
    if (toggler) {
        const collapse = document.getElementById('navbarNav');
        if (collapse) {
            collapse.addEventListener('show.bs.collapse',  () => toggler.setAttribute('aria-expanded', 'true'));
            collapse.addEventListener('hide.bs.collapse',  () => toggler.setAttribute('aria-expanded', 'false'));
        }
    }

    // Ticker close
    const tickerBar   = document.getElementById('tickerBar');
    const tickerClose = document.getElementById('tickerClose');

    function hideTicker() {
        if (!tickerBar) return;
        tickerBar.classList.add('hidden');
        document.body.classList.add('ticker-off');
        sessionStorage.setItem('tickerClosed', '1');
        setTimeout(() => { tickerBar.style.display = 'none'; }, 320);
    }

    if (tickerClose) tickerClose.addEventListener('click', hideTicker);

    if (tickerBar && sessionStorage.getItem('tickerClosed') === '1') {
        tickerBar.style.display = 'none';
        document.body.classList.add('ticker-off');
    }
})();
</script>