
<?php
// header.php - Reusable header/navbar for IT Shop.LK

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($pdo)) include __DIR__ . '../db.php';

$cart_count    = 0;
$user_currency = 'LKR';
$cart_total    = 0;

if (isset($_SESSION['user_id']) && isset($pdo)) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count, SUM(price * quantity) as total FROM cart WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $cart_data  = $stmt->fetch();
        $cart_count = $cart_data['count'] ?? 0;
        $cart_total = $cart_data['total'] ?? 0;
    } catch (PDOException $e) {}
}

$page_title = $page_title ?? 'IT Shop.LK - Best Computer Store';
$cur_page   = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link rel="icon" type="image/x-icon" href="assets/logo.png">
    <meta name="description" content="Shop laptops, PCs, RAM, VGA cards, keyboards, mice and audio devices from IT Shop.LK">

    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Red+Hat+Display:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

    <style>
        /* ═══════════════ TOKENS ═══════════════ */
        :root {
            --accent:         #0cb100;
            --accent-dark:    #087600;
            --accent-soft:    #f6f7ffb4;
            --accent-border:  rgba(13, 255, 0, 0.2);
            --ink:            #0d0d14;
            --ink-2:          #3c523c;
            --ink-3:          #2a3b29;
            --surface:        #ffffff;
            --white:          #ffffff;
            --r-sm:   8px;
            --r-md:   12px;
            --r-lg:   18px;
            --r-full: 999px;
            --shadow-drop: 0 8px 32px rgba(13,13,20,.12);

            --navbar-h: 66px;
            --ticker-h: 40px;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Red Hat Display', sans-serif;
            color: var(--ink);
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
            padding-top: var(--navbar-h);
        }
        body.ticker-visible {
            padding-top: calc(var(--navbar-h) + var(--ticker-h));
        }

        /* ═══════════════ NAVBAR ═══════════════ */
        .navbar {
            background: rgba(255,255,255,.88);
            backdrop-filter: blur(20px) saturate(180%);
            -webkit-backdrop-filter: blur(20px) saturate(180%);
            border-bottom: 1px solid rgba(0,0,0,.06);
            padding: .6rem 0;
            top: 0;
            transition: background .3s, box-shadow .3s;
            height: var(--navbar-h);
        }
        .navbar.is-scrolled {
            background: rgba(255,255,255,.97);
            box-shadow: 0 1px 0 rgba(0,0,0,.06), 0 4px 24px rgba(0,0,0,.06);
        }

        .navbar-brand img { height: 44px; }

        /* ── nav links ── */
        .navbar-nav .nav-link {
            font-size: .875rem;
            font-weight: 600;
            color: var(--ink-2) !important;
            padding: .45rem .9rem !important;
            border-radius: var(--r-sm);
            letter-spacing: .01em;
            transition: color .15s, background .15s;
        }
        .navbar-nav .nav-link:hover,
        .navbar-nav .nav-link.active {
            color: var(--accent) !important;
            background: var(--accent-soft);
        }

        /* ── dropdown ── */
        .dropdown-menu {
            border: 1px solid rgba(0,0,0,.07);
            border-radius: var(--r-lg);
            box-shadow: var(--shadow-drop);
            padding: .5rem;
            min-width: 210px;
            animation: menuIn .17s ease both;
        }
        @keyframes menuIn {
            from { opacity:0; transform:translateY(-7px) scale(.98); }
            to   { opacity:1; transform:translateY(0) scale(1); }
        }
        .dropdown-item {
            font-family: 'Red Hat Display', sans-serif;
            font-size: .855rem;
            font-weight: 500;
            color: var(--ink-2);
            border-radius: var(--r-sm);
            padding: .46rem .85rem;
            transition: background .13s, color .13s;
        }
        .dropdown-item:hover, .dropdown-item:focus {
            background: var(--accent-soft);
            color: var(--accent);
        }
        .dropdown-divider { margin: .3rem 0; border-color: rgba(0,0,0,.06); }

        /* ── icon button ── */
        .icon-btn {
            position: relative;
            width: 40px; height: 40px;
            border-radius: var(--r-md);
            background: transparent;
            border: none;
            color: var(--ink-2);
            font-size: 1rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            transition: background .15s, color .15s;
            cursor: pointer;
            flex-shrink: 0;
        }
        .icon-btn:hover { background: var(--accent-soft); color: var(--accent); }
        .icon-btn .bdot {
            position: absolute;
            top: 4px; right: 4px;
            min-width: 17px; height: 17px;
            border-radius: var(--r-full);
            background: var(--accent);
            color: #fff;
            font-size: .6rem;
            font-weight: 700;
            display: flex; align-items: center; justify-content: center;
            padding: 0 3px;
            border: 2px solid var(--white);
        }

        /* ── search overlay ── */
        .search-overlay {
            position: fixed;
            inset: 0;
            background: rgba(13,13,20,.55);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            z-index: 2000;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding-top: 120px;
            opacity: 0;
            pointer-events: none;
            transition: opacity .22s ease;
        }
        .search-overlay.open {
            opacity: 1;
            pointer-events: all;
        }
        .search-box {
            background: var(--white);
            border-radius: var(--r-lg);
            box-shadow: 0 24px 80px rgba(13,13,20,.22);
            padding: 1.2rem;
            width: min(620px, 90vw);
            transform: translateY(-16px) scale(.97);
            transition: transform .22s ease, opacity .22s ease;
            opacity: 0;
        }
        .search-overlay.open .search-box {
            transform: translateY(0) scale(1);
            opacity: 1;
        }
        .search-input-wrap {
            display: flex;
            align-items: center;
            gap: 12px;
            border: 1.5px solid rgba(0,0,0,.1);
            border-radius: var(--r-md);
            padding: 10px 16px;
            transition: border-color .15s;
        }
        .search-input-wrap:focus-within {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(12,177,0,.1);
        }
        .search-input-wrap i { color: var(--ink-2); font-size: .95rem; flex-shrink: 0; }
        .search-input-wrap input {
            border: none;
            outline: none;
            width: 100%;
            font-family: 'Red Hat Display', sans-serif;
            font-size: .95rem;
            font-weight: 500;
            color: var(--ink);
            background: transparent;
        }
        .search-input-wrap input::placeholder { color: #aaa; font-weight: 400; }
        .search-kbd {
            flex-shrink: 0;
            font-size: .7rem;
            font-weight: 600;
            color: #999;
            background: #f4f4f4;
            border: 1px solid #e0e0e0;
            border-radius: 5px;
            padding: 2px 7px;
            font-family: monospace;
        }
        .search-hints {
            margin-top: .85rem;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .search-hints span {
            font-size: .75rem;
            font-weight: 600;
            color: #aaa;
        }
        .search-hint-tag {
            font-size: .75rem;
            font-weight: 600;
            color: var(--ink-2);
            background: var(--accent-soft);
            border: 1px solid var(--accent-border);
            border-radius: var(--r-full);
            padding: 3px 12px;
            cursor: pointer;
            text-decoration: none;
            transition: background .13s, color .13s;
        }
        .search-hint-tag:hover { background: var(--accent); color: #fff; }

        /* ── cart total ── */
        .cart-total {
            font-size: .78rem;
            font-weight: 600;
            color: var(--ink-3);
            background: var(--surface);
            border: 1px solid rgba(0, 0, 0, 0.14);
            border-radius: var(--r-full);
            padding: 4px 12px;
            white-space: nowrap;
        }

        /* ── separator ── */
        .v-sep {
            width: 1px; height: 22px;
            background: rgba(0,0,0,.1);
            flex-shrink: 0;
        }

        /* ── CTA (login / sign up) ── */
        .btn-cta {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            background: var(--accent);
            color: #fff !important;
            font-family: 'Red Hat Display', sans-serif;
            font-size: .855rem;
            font-weight: 700;
            padding: 9px 20px;
            border-radius: var(--r-md);
            border: none;
            cursor: pointer;
            text-decoration: none;
            box-shadow: 0 3px 12px rgba(70, 229, 78, 0.32);
            transition: background .2s, transform .2s, box-shadow .2s;
        }
        .btn-cta:hover {
            background: var(--accent-dark);
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(70, 229, 91, 0.42);
            color: #fff !important;
            text-decoration: none;
        }

        /* ── account button ── */
        .btn-account {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--surface);
            color: var(--ink-2) !important;
            font-family: 'Red Hat Display', sans-serif;
            font-size: .855rem;
            font-weight: 600;
            padding: 8px 16px;
            border-radius: var(--r-md);
            border: 1px solid rgba(0,0,0,.09);
            cursor: pointer;
            transition: background .15s, border-color .15s, color .15s;
        }
        .btn-account:hover {
            background: var(--accent-soft);
            border-color: var(--accent-border);
            color: var(--accent) !important;
        }
        .btn-account .av {
            width: 24px; height: 24px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--accent), #34d399);
            display: inline-flex; align-items: center; justify-content: center;
            font-size: .6rem; font-weight: 800; color: #fff;
            flex-shrink: 0;
        }

        /* ── toggler ── */
        .navbar-toggler { border: 1px solid rgba(0,0,0,.1); border-radius: var(--r-sm); padding: 6px 10px; }
        .navbar-toggler:focus { box-shadow: none; }

        /* ═══════════════ TICKER — NOW BELOW NAVBAR ═══════════════ */
        .ticker-bar {
            background: #3b5bdb;
            color: #fff;
            font-family: 'Red Hat Display', sans-serif;
            font-size: .82rem;
            font-weight: 600;
            height: var(--ticker-h);
            display: flex;
            align-items: center;
            overflow: hidden;
            position: fixed;
            top: var(--navbar-h);
            left: 0;
            right: 0;
            z-index: 1029;
            transition: top .3s;
        }

        .ticker-bar .ticker-label {
            flex-shrink: 0;
            padding: 0 16px;
            height: 100%;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: .78rem;
            font-weight: 700;
            letter-spacing: .05em;
            white-space: nowrap;
            opacity: .9;
        }

        .ticker-track {
            display: flex;
            overflow: hidden;
            flex: 1;
            mask-image: linear-gradient(to right, transparent 0%, #000 3%, #000 97%, transparent 100%);
            -webkit-mask-image: linear-gradient(to right, transparent 0%, #000 3%, #000 97%, transparent 100%);
        }
        .ticker-inner {
            display: flex;
            white-space: nowrap;
            animation: tickerScroll 35s linear infinite;
        }
        .ticker-inner:hover { animation-play-state: paused; }
        .ticker-item {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 0 32px;
        }
        .ticker-item .sep {
            width: 4px; height: 4px;
            border-radius: 50%;
            background: rgba(255,255,255,.4);
            display: inline-block;
        }
        .ticker-item a {
            color: #fff;
            text-decoration: underline;
            text-underline-offset: 2px;
        }
        .ticker-item a:hover { color: rgba(255,255,255,.8); }
        @keyframes tickerScroll {
            0%   { transform: translateX(0); }
            100% { transform: translateX(-50%); }
        }
        .ticker-close {
            flex-shrink: 0;
            background: none;
            border: none;
            color: rgba(255,255,255,.7);
            cursor: pointer;
            padding: 0 14px;
            font-size: .7rem;
            height: 100%;
            transition: color .15s;
        }
        .ticker-close:hover { color: #fff; }

        /* ── mobile ── */
        @media (max-width: 991px) {
            .navbar-collapse {
                background: var(--white);
                border-radius: var(--r-lg);
                border: 1px solid rgba(0,0,0,.07);
                box-shadow: var(--shadow-drop);
                padding: 1rem;
                margin-top: .6rem;
            }
            .right-group { flex-wrap: wrap; gap: 8px !important; margin-top: .75rem; }
        }
    </style>
</head>
<body class="ticker-visible">

<!-- ═══════════════ NAVBAR ═══════════════ -->
<nav class="navbar navbar-expand-lg fixed-top" id="mainNav">
    <div class="container">

        <a class="navbar-brand" href="index.php">
            <img src="assets/revised-04.png" alt="IT Shop.LK">
        </a>

        <button class="navbar-toggler" type="button"
                data-bs-toggle="collapse" data-bs-target="#navbarNav"
                aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarNav">

            <!-- Centre links -->
            <ul class="navbar-nav mx-auto gap-1">
                <li class="nav-item">
                    <a class="nav-link <?= ($cur_page==='index.php'?'active':'') ?>" href="index.php">Home</a>
                </li>

                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?= ($cur_page==='products.php'?'active':'') ?>"
                       href="#" id="prodDrop" role="button"
                       data-bs-toggle="dropdown" aria-expanded="false">Products</a>
                    <ul class="dropdown-menu" aria-labelledby="prodDrop">
                        <li>
                            <a class="dropdown-item fw-semibold" href="products.php?category=all">
                                <i class="fas fa-border-all me-2" style="font-size:.75rem;color:var(--accent)"></i>All Products
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <?php
                        $navCats = [
                            ['Processors',   'products.php?category=processors'],
                            ['Motherboard',  'products.php?category=motherboards'],
                            ['Coolers',      'products.php?category=coolers'],
                            ['Memory (RAM)', 'products.php?category=memory'],
                            ['SSD',          'products.php?category=storage'],
                            ['Storage',      'products.php?category=storage'],
                            ['Graphic Cards','products.php?category=graphics'],
                            ['Power Supply', 'products.php?category=power'],
                            ['Pc Cases',     'products.php?category=cases'],
                            ['Laptops',      'products.php?category=laptops'],
                            ['Desktops',     'products.php?category=desktops'],
                        ];
                        foreach ($navCats as [$label, $url]): ?>
                        <li><a class="dropdown-item" href="<?= $url ?>"><?= $label ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </li>

                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle"
                       href="#" role="button"
                       data-bs-toggle="dropdown" aria-expanded="false">Accessories</a>
                    <ul class="dropdown-menu">
                        <?php
                        $navCats = [
                            ['UPS',        'products.php?category=ups'],
                            ['Monitors',   'products.php?category=monitors'],
                            ['Headsets',   'products.php?category=headsets'],
                            ['Keyboards',  'products.php?category=keyboards'],
                            ['Mouse',      'products.php?category=mouse'],
                            ['Speakers',   'products.php?category=speakers'],
                            ['Cables',     'products.php?category=cables'],
                            ['Adapters',   'products.php?category=adapters'],
                            ['Software',   'products.php?category=software'],
                            ['Printers',   'products.php?category=printers'],
                            ['Virus Guard','products.php?category=virus-guard'],
                        ];
                        foreach ($navCats as [$label, $url]): ?>
                        <li><a class="dropdown-item" href="<?= $url ?>"><?= $label ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </li>

                <li class="nav-item">
                    <a class="nav-link <?= ($cur_page==='contact.php'?'active':'') ?>" href="contact.php">Contact</a>
                </li>
            </ul>

            <!-- Right group -->
            <div class="right-group d-flex align-items-center gap-2">

                <!-- Search icon -->
                <button class="icon-btn" id="searchToggle" aria-label="Search products">
                    <i class="fas fa-search"></i>
                </button>

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

                <?php if (isset($_SESSION['user_id'])): ?>
                    <div class="dropdown">
                        <button class="btn-account dropdown-toggle" type="button"
                            data-bs-toggle="dropdown" aria-expanded="false">
                           <span class="av"><?= htmlspecialchars(strtoupper(mb_substr($_SESSION['user_name'] ?? 'U', 0, 1))) ?></span>
                           <?= htmlspecialchars($_SESSION['user_name'] ?? '') ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
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

<!-- ═══════════════ SEARCH OVERLAY ═══════════════ -->
<div class="search-overlay" id="searchOverlay" role="dialog" aria-label="Product search">
    <div class="search-box">
        <form action="products.php" method="GET">
            <div class="search-input-wrap">
                <i class="fas fa-search"></i>
                <input type="text" name="q" id="searchInput"
                       placeholder="Search laptops, RAM, GPUs, keyboards…"
                       autocomplete="off" autofocus>
                <span class="search-kbd">ESC</span>
            </div>
        </form>
        <div class="search-hints">
            <span>Popular:</span>
            <a href="products.php?category=graphics" class="search-hint-tag">RTX 5080</a>
            <a href="products.php?category=processors" class="search-hint-tag">Core Ultra</a>
            <a href="products.php?category=memory" class="search-hint-tag">DDR5 RAM</a>
            <a href="products.php?category=laptops" class="search-hint-tag">Gaming Laptops</a>
            <a href="products.php?category=monitors" class="search-hint-tag">4K Monitors</a>
        </div>
    </div>
</div>

<!-- ═══════════════ TICKER BAR — BELOW NAVBAR ═══════════════ -->
<div class="ticker-bar" id="tickerBar">
    <div class="ticker-label">
        <i class="fas fa-gift" style="font-size:.8rem"></i> Limited Offers
    </div>
    <div class="ticker-track">
        <div class="ticker-inner" id="tickerInner">
            <!-- First copy -->
            <span class="ticker-item">🔥 Free delivery on orders over LKR 50,000 <span class="sep"></span></span>
            <span class="ticker-item">💻 New Arrivals: Intel Core Ultra 200 Series now in stock <span class="sep"></span></span>
            <span class="ticker-item">🖥️ Up to 20% off on Gaming Monitors this week <span class="sep"></span></span>
            <span class="ticker-item">⚡ RTX 5080 pre-orders open — <a href="products.php?category=graphics">Reserve yours</a> <span class="sep"></span></span>
            <span class="ticker-item">🛡️ 1-Year Local Warranty on all laptops <span class="sep"></span></span>
            <span class="ticker-item">🎧 Buy any headset &amp; get a free mouse pad <span class="sep"></span></span>
            <span class="ticker-item">📦 Same-day dispatch for orders placed before 2 PM <span class="sep"></span></span>
            <!-- Duplicate for seamless loop -->
            <span class="ticker-item">🔥 Free delivery on orders over LKR 50,000 <span class="sep"></span></span>
            <span class="ticker-item">💻 New Arrivals: Intel Core Ultra 200 Series now in stock <span class="sep"></span></span>
            <span class="ticker-item">🖥️ Up to 20% off on Gaming Monitors this week <span class="sep"></span></span>
            <span class="ticker-item">⚡ RTX 5080 pre-orders open — <a href="products.php?category=graphics">Reserve yours</a> <span class="sep"></span></span>
            <span class="ticker-item">🛡️ 1-Year Local Warranty on all laptops <span class="sep"></span></span>
            <span class="ticker-item">🎧 Buy any headset &amp; get a free mouse pad <span class="sep"></span></span>
            <span class="ticker-item">📦 Same-day dispatch for orders placed before 2 PM <span class="sep"></span></span>
        </div>
    </div>
    <button class="ticker-close" id="tickerClose" aria-label="Close ticker">
        <i class="fas fa-times"></i>
    </button>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
<script>
    (() => {
        // ── Navbar scroll shadow ──
        const nav = document.getElementById('mainNav');
        window.addEventListener('scroll', () => {
            nav.classList.toggle('is-scrolled', window.scrollY > 10);
        }, { passive: true });

        // ── Ticker close ──
        const tickerBar   = document.getElementById('tickerBar');
        const tickerClose = document.getElementById('tickerClose');
        const body        = document.body;

        tickerClose.addEventListener('click', () => {
            tickerBar.style.display = 'none';
            body.classList.remove('ticker-visible');
            sessionStorage.setItem('tickerClosed', '1');
        });

        // Restore closed state across page loads (same session)
        if (sessionStorage.getItem('tickerClosed') === '1') {
            tickerBar.style.display = 'none';
            body.classList.remove('ticker-visible');
        }

        // ── Search overlay ──
        const overlay     = document.getElementById('searchOverlay');
        const searchInput = document.getElementById('searchInput');
        const searchToggle = document.getElementById('searchToggle');

        function openSearch() {
            overlay.classList.add('open');
            setTimeout(() => searchInput.focus(), 80);
        }
        function closeSearch() {
            overlay.classList.remove('open');
        }

        searchToggle.addEventListener('click', openSearch);

        overlay.addEventListener('click', e => {
            if (!e.target.closest('.search-box')) closeSearch();
        });

        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') closeSearch();
            // Cmd/Ctrl + K to open search
            if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
                e.preventDefault();
                openSearch();
            }
        });
    })();
</script>


