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
$ticker_speed    = 35;
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

// Hardcoded fallback if no DB messages exist yet
if (empty($ticker_messages)) {
    $ticker_messages = [
        ['emoji' => '🔥', 'message' => 'Free delivery on orders over LKR 50,000',              'link_url' => '',                           'link_text' => ''],
        ['emoji' => '💻', 'message' => 'New Arrivals: Intel Core Ultra 200 Series now in stock','link_url' => '',                           'link_text' => ''],
        ['emoji' => '🖥️', 'message' => 'Up to 20% off on Gaming Monitors this week',            'link_url' => '',                           'link_text' => ''],
        ['emoji' => '⚡', 'message' => 'RTX 5080 pre-orders open',                               'link_url' => 'products.php?category=graphics','link_text' => 'Reserve yours'],
        ['emoji' => '🛡️', 'message' => '1-Year Local Warranty on all laptops',                   'link_url' => '',                           'link_text' => ''],
        ['emoji' => '🎧', 'message' => 'Buy any headset &amp; get a free mouse pad',             'link_url' => '',                           'link_text' => ''],
        ['emoji' => '📦', 'message' => 'Same-day dispatch for orders placed before 2 PM',        'link_url' => '',                           'link_text' => ''],
    ];
}

$page_title = $page_title ?? 'IT Shop.LK - Best Computer Store';
$cur_page   = basename($_SERVER['PHP_SELF']);
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

            /* legacy compat */
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
            /* Default: room for navbar + ticker */
            padding-top: calc(var(--navbar-h) + var(--ticker-h));
        }
        /* When ticker is hidden by user click or disabled in admin */
        body.ticker-off {
            padding-top: var(--navbar-h) !important;
        }

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

        /* dropdown */
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
            to   { opacity:1; transform:translateY(0)   scale(1);   }
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
        .dropdown-item:hover,
        .dropdown-item:focus { background: var(--accent-soft); color: var(--accent); }
        .dropdown-divider    { margin: .3rem 0; border-color: rgba(0,0,0,.06); }

        /* icon button */
        .icon-btn {
            position: relative;
            width: 40px; height: 40px;
            border-radius: var(--r-md);
            background: transparent;
            border: none;
            color: var(--ink-2);
            font-size: 1rem;
            display: inline-flex; align-items: center; justify-content: center;
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
            font-size: .6rem; font-weight: 700;
            display: flex; align-items: center; justify-content: center;
            padding: 0 3px;
            border: 2px solid var(--white);
        }

        /* cart total */
        .cart-total {
            font-size: .78rem; font-weight: 600;
            color: var(--ink-3);
            background: var(--surface);
            border: 1px solid rgba(0,0,0,.14);
            border-radius: var(--r-full);
            padding: 4px 12px;
            white-space: nowrap;
        }

        /* separator */
        .v-sep { width:1px; height:22px; background:rgba(0,0,0,.1); flex-shrink:0; }

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

        /* toggler */
        .navbar-toggler { border: 1px solid rgba(0,0,0,.1); border-radius: var(--r-sm); padding: 6px 10px; }
        .navbar-toggler:focus { box-shadow: none; }

        /* ═══════════════════════════════════════════════
           TICKER BAR
        ═══════════════════════════════════════════════ */
        .ticker-bar {
            background: <?= htmlspecialchars($ticker_color) ?>;
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
            left: 0; right: 0;
            z-index: 1029;
            transition: transform .3s ease, opacity .3s ease;
        }
        .ticker-bar.hidden {
            transform: translateY(-100%);
            opacity: 0;
            pointer-events: none;
        }
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
        .ticker-item {
            display: inline-flex; align-items: center; gap: 8px; padding: 0 28px;
        }
        .ticker-item .sep {
            width: 4px; height: 4px; border-radius: 50%;
            background: rgba(255,255,255,.35); display: inline-block;
        }
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

        /* mobile */
        @media (max-width: 991px) {
            .navbar-collapse {
                background: var(--white);
                border-radius: var(--r-lg);
                border: 1px solid rgba(0,0,0,.07);
                box-shadow: var(--shadow-drop);
                padding: 1rem; margin-top: .6rem;
            }
            .right-group { flex-wrap: wrap; gap: 8px !important; margin-top: .75rem; }
        }
    </style>
</head>
<?php
// Determine if ticker is effectively visible
// (globally enabled AND user hasn't dismissed it this session)
$ticker_show = $ticker_enabled; // PHP-side; JS will check sessionStorage
?>
<body class="<?= $ticker_show ? '' : 'ticker-off' ?>">

<!-- ═══════════════════════════════════════════════
     NAVBAR
═══════════════════════════════════════════════ -->
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
                    <a class="nav-link <?= $cur_page==='index.php' ? 'active' : '' ?>" href="index.php">Home</a>
                </li>

                <!-- Products dropdown -->
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

                <!-- Accessories dropdown -->
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

            <!-- Right group -->
            <div class="right-group d-flex align-items-center gap-2">

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
     TICKER BAR  (DB-driven — managed in admin panel)
═══════════════════════════════════════════════ -->
<div class="ticker-bar" id="tickerBar">

    <div class="ticker-label">
        <i class="fas fa-gift" style="font-size:.78rem"></i>
        Limited Offers
    </div>

    <div class="ticker-track">
        <div class="ticker-inner" id="tickerInner">
            <?php
            // Render two identical sets so the scroll loops seamlessly
            foreach ([1, 2] as $pass):
                foreach ($ticker_messages as $tm):
            ?>
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
<!-- Ticker disabled in admin panel -->
<style>body { padding-top: var(--navbar-h) !important; }</style>
<?php endif; ?>


<script>
(() => {
    // ── Bootstrap dropdown init (handles double-load edge cases) ──────────────
    function initDropdowns() {
        document.querySelectorAll('[data-bs-toggle="dropdown"]').forEach(el => {
            try {
                if (el.getAttribute('href') === '#') {
                    el.addEventListener('click', e => e.preventDefault());
                }
                const existing = bootstrap.Dropdown.getInstance(el);
                if (existing) existing.dispose();
                new bootstrap.Dropdown(el);
            } catch (e) {}
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initDropdowns);
    } else {
        initDropdowns();
    }
    setTimeout(initDropdowns, 300);

    // ── Navbar scroll shadow ──────────────────────────────────────────────────
    const nav = document.getElementById('mainNav');
    if (nav) {
        window.addEventListener('scroll', () => {
            nav.classList.toggle('is-scrolled', window.scrollY > 10);
        }, { passive: true });
    }

    // ── Ticker close (dismiss for this session) ───────────────────────────────
    const tickerBar   = document.getElementById('tickerBar');
    const tickerClose = document.getElementById('tickerClose');

    function hideTicker() {
        if (!tickerBar) return;
        tickerBar.classList.add('hidden');
        document.body.classList.add('ticker-off');
        sessionStorage.setItem('tickerClosed', '1');
        // Wait for CSS transition then remove from layout
        setTimeout(() => { tickerBar.style.display = 'none'; }, 320);
    }

    if (tickerClose) tickerClose.addEventListener('click', hideTicker);

    // Restore dismissed state within the same browser session
    if (tickerBar && sessionStorage.getItem('tickerClosed') === '1') {
        // instant hide (no animation on restore)
        tickerBar.style.display = 'none';
        document.body.classList.add('ticker-off');
    }
})();
</script>