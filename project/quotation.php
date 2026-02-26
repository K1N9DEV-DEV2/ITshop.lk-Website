<?php
// Start session for user management
session_start();

// Database connection
include 'db.php';

// Redirect to login if user is not logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=quotation.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$user_currency = 'LKR';
$cart_items = [];
$subtotal = 0;
$shipping_cost = 0;

// Fetch user details
$user_details = [];
try {
    $stmt = $pdo->prepare("SELECT name, email, phone FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user_details = $stmt->fetch(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $user_details = ['name' => 'Valued Customer', 'email' => '', 'phone' => ''];
}

// Fetch cart items
try {
    $stmt = $pdo->prepare("
        SELECT 
            c.product_id,
            c.quantity,
            p.name,
            p.brand,
            p.price as unit_price,
            p.category
        FROM cart c
        JOIN products p ON c.product_id = p.id
        WHERE c.user_id = ?
        ORDER BY c.created_at DESC
    ");
    $stmt->execute([$user_id]);
    $cart_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($cart_items as &$item) {
        $item_total = $item['unit_price'] * $item['quantity'];
        $item['line_total'] = $item_total;
        $subtotal += $item_total;
    }
    
} catch(PDOException $e) {
    $error_message = "Error fetching cart items: " . $e->getMessage();
    $cart_items = [];
}

$total = $subtotal + ($subtotal > 0 ? $shipping_cost : 0);
$quotation_number = 'QT-' . date('Y') . '-' . str_pad($user_id, 5, '0', STR_PAD_LEFT) . '-' . time();
$quotation_date = date('F d, Y');
$valid_until = date('F d, Y', strtotime('+30 days'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quotation — IT Shop.LK</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Red+Hat+Display:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">

    <style>
        :root {
            --ink: #209e00;
            --ink-soft: #3d3d50;
            --ink-muted: #8888a0;
            --surface: #f6f6f8;
            --card: #ffffff;
            --accent: #0cb100;
            --accent-dark: #098600;
            --accent-light: #eef2ff;
            --accent-glow: rgba(12, 177, 0, 0.25);
            --border: #e8e8f0;
            --radius-xl: 24px;
            --radius-lg: 16px;
            --radius-md: 12px;
            --shadow-card: 0 2px 12px rgba(10,10,15,0.07), 0 0 0 1px rgba(10,10,15,0.05);
            --shadow-hover: 0 16px 40px rgba(10,10,15,0.13);
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Red Hat Display', sans-serif;
            background: var(--surface);
            color: var(--ink);
            -webkit-font-smoothing: antialiased;
        }

        /* ── TOOLBAR ── */
        .toolbar {
            position: sticky;
            top: 0;
            z-index: 100;
            background: rgba(10, 10, 15, 0.92);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-bottom: 1px solid rgba(255,255,255,0.08);
            padding: 0 2rem;
            height: 64px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }

        .toolbar-brand {
            font-family: 'Red Hat Display', sans-serif;
            font-size: 1.15rem;
            font-weight: 800;
            color: #fff;
            letter-spacing: -0.02em;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .toolbar-brand em {
            font-style: normal;
            color: var(--accent);
        }

        .toolbar-actions {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.55rem 1.3rem;
            border-radius: var(--radius-md);
            font-family: 'Red Hat Display', sans-serif;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            border: none;
            text-decoration: none;
            white-space: nowrap;
        }

        .btn-primary {
            background: var(--accent);
            color: #fff;
            box-shadow: 0 4px 16px var(--accent-glow);
        }
        .btn-primary:hover {
            background: var(--accent-dark);
            transform: translateY(-2px);
            box-shadow: 0 8px 24px var(--accent-glow);
        }

        .btn-ghost {
            background: rgba(255,255,255,0.08);
            color: rgba(255,255,255,0.7);
            border: 1px solid rgba(255,255,255,0.12);
        }
        .btn-ghost:hover {
            background: rgba(255,255,255,0.14);
            color: #fff;
        }

        /* ── PAGE ── */
        .page {
            max-width: 900px;
            margin: 0 auto;
            padding: 2.5rem 1.5rem 5rem;
        }

        /* ── QUOTATION CARD ── */
        .quotation-card {
            background: var(--card);
            border-radius: var(--radius-xl);
            border: 1px solid var(--border);
            overflow: hidden;
            box-shadow: var(--shadow-card);
        }

        /* ── DARK HERO HEADER ── */
        .q-header {
            background: var(--ink);
            padding: 2.75rem 2.75rem 2.25rem;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 2rem;
            position: relative;
            overflow: hidden;
        }

        /* background blobs like homepage hero */
        .q-header::before,
        .q-header::after {
            content: '';
            position: absolute;
            border-radius: 50%;
            filter: blur(70px);
            pointer-events: none;
        }
        .q-header::before {
            width: 420px; height: 420px;
            background: radial-gradient(circle, rgba(12, 177, 0, 0.28) 0%, transparent 70%);
            top: -160px; right: -100px;
        }
        .q-header::after {
            width: 280px; height: 280px;
            background: radial-gradient(circle, rgba(79, 135, 188, 0.18) 0%, transparent 70%);
            bottom: -100px; left: -60px;
        }

        /* grid overlay */
        .q-header-grid {
            position: absolute;
            inset: 0;
            background-image:
                linear-gradient(rgba(255,255,255,0.025) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,0.025) 1px, transparent 1px);
            background-size: 48px 48px;
            pointer-events: none;
        }

        .q-logo {
            position: relative;
            z-index: 2;
        }

        .q-logo img {
            height: 42px;
            filter: brightness(0) invert(1);
            opacity: 0.9;
        }

        .q-logo-text {
            font-family: 'Red Hat Display', sans-serif;
            font-size: 1.6rem;
            font-weight: 800;
            color: #fff;
            letter-spacing: -0.03em;
        }

        .q-logo-text em {
            font-style: normal;
            color: var(--accent);
        }

        .q-company-meta {
            margin-top: 0.85rem;
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
        }

        .q-company-meta span {
            font-size: 0.8rem;
            color: rgb(255, 255, 255);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .q-company-meta i {
            width: 13px;
            color: #fff;
            opacity: 0.7;
        }

        .q-meta-right {
            text-align: right;
            position: relative;
            z-index: 2;
        }

        .q-pill {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(12, 177, 0, 0.35);
            color: #d5f9ff;
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            padding: 5px 14px;
            border-radius: 100px;
            margin-bottom: 1rem;
        }
        .q-pill-dot { width: 6px; height: 6px; background: #11ff00; border-radius: 50%; }

        .q-title {
            font-family: 'Red Hat Display', sans-serif;
            font-size: 3rem;
            font-weight: 800;
            color: #fff;
            line-height: 1;
            letter-spacing: -0.04em;
            margin-bottom: 1.25rem;
        }

        .q-title em {
            font-style: italic;
            font-weight: 300;
            background: linear-gradient(135deg, #1eff00 0%, #00a2ff 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .q-number-badge {
            display: inline-block;
            background: rgba(255,255,255,0.07);
            border: 1px solid rgba(255,255,255,0.14);
            border-radius: 8px;
            padding: 0.5rem 1rem;
            font-family: 'Red Hat Display',sans-serif;
            font-size: 0.75rem;
            color: rgba(255, 255, 255, 0.93);
            margin-bottom: 0.75rem;
            letter-spacing: 0.05em;
        }

        .q-dates {
            display: flex;
            flex-direction: column;
            gap: 0.3rem;
        }

        .q-date-row {
            font-size: 0.8rem;
            color: rgba(255, 255, 255, 0.96);
        }
        .q-date-row strong {
            color: rgba(255, 255, 255, 0.9);
            font-weight: 600;
        }

        /* ── VALIDITY STRIP ── */
        .validity-strip {
            background: var(--accent);
            padding: 0.7rem 2.75rem;
            display: flex;
            align-items: center;
            gap: 0.6rem;
            font-size: 0.82rem;
            font-weight: 700;
            color: #fff;
            letter-spacing: 0.01em;
        }
        .validity-strip i { font-size: 0.8rem; }

        /* ── BODY ── */
        .q-body { padding: 2.75rem; }

        /* ── SECTION HEADING ── */
        .section-heading {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: var(--ink-muted);
            margin-bottom: 1.1rem;
        }
        .section-heading i { color: var(--accent); font-size: 0.7rem; }
        .section-heading::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--border);
        }

        /* ── CUSTOMER BLOCK ── */
        .customer-block {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1px;
            background: var(--border);
            border-radius: var(--radius-md);
            overflow: hidden;
            margin-bottom: 2.75rem;
            box-shadow: var(--shadow-card);
        }

        .customer-field {
            background: var(--card);
            padding: 1.1rem 1.35rem;
            transition: background 0.2s;
        }
        .customer-field:hover { background: #fafbff; }

        .customer-field:first-child { border-radius: var(--radius-md) 0 0 0; }
        .customer-field:nth-child(2) { border-radius: 0 var(--radius-md) 0 0; }
        .customer-field:last-child:nth-child(odd) { grid-column: span 2; border-radius: 0 0 var(--radius-md) var(--radius-md); }
        .customer-field:nth-last-child(2) { border-radius: 0 0 0 var(--radius-md); }
        .customer-field:last-child:nth-child(even) { border-radius: 0 0 var(--radius-md) 0; }

        .cf-label {
            font-size: 0.68rem;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: var(--ink-muted);
            margin-bottom: 0.35rem;
        }

        .cf-value {
            font-size: 0.92rem;
            color: #000000ca;
            font-weight: 600;
        }

        /* ── TABLE ── */
        .table-wrap {
            overflow-x: auto;
            border-radius: var(--radius-md);
            border: 1px solid var(--border);
            margin-bottom: 2rem;
            box-shadow: var(--shadow-card);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
        }

        thead tr {
            background: var(--ink);
        }

        th {
            padding: 1rem 1.1rem;
            font-size: 0.68rem;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: rgba(255, 255, 255, 0.97);
            text-align: left;
            white-space: nowrap;
        }

        th:last-child, td:last-child { text-align: right; }
        th:nth-child(3), th:nth-child(4), td:nth-child(3), td:nth-child(4) { text-align: center; }

        td {
            padding: 1.05rem 1.1rem;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
            color: var(--ink);
        }

        tbody tr:last-child td { border-bottom: none; }
        tbody tr { transition: background 0.15s; }
        tbody tr:hover { background: #f7f8ff; }

        .item-num {
            font-family: 'Red Hat Display', sans-serif;
            font-size: 0.75rem;
            color: #ccc;
        }

        .item-name { font-weight: 700; line-height: 1.35; font-size: 0.9rem; color: #000; }

        .item-meta {
            margin-top: 0.3rem;
            display: flex;
            gap: 0.4rem;
            flex-wrap: wrap;
        }

        .item-tag {
            display: inline-block;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 0.12rem 0.5rem;
            font-size: 0.68rem;
            font-weight: 600;
            color: #000000c2;
            letter-spacing: 0.04em;
        }

        .price-cell {
            font-family: 'Red Hat Display', sans-serif;
            font-size: 0.82rem;
            color: #000;
        }

        .total-cell {
            font-family: 'Red Hat Display', sans-serif;
            font-size: 0.9rem;
            font-weight: 700;
            color: var(--ink);
        }

        .qty-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--ink);
            color: #fff;
            border-radius: 20px;
            min-width: 30px;
            height: 26px;
            font-size: 0.8rem;
            font-weight: 700;
            padding: 0 0.6rem;
        }

        /* ── TOTALS ── */
        .totals-wrap {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 2.75rem;
        }

        .totals-box {
            width: 340px;
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            overflow: hidden;
            box-shadow: var(--shadow-card);
        }

        .total-line {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.9rem 1.35rem;
            font-size: 0.9rem;
            border-bottom: 1px solid var(--border);
        }
        .total-line:last-child { border-bottom: none; }

        .tl-label { color: var(--ink-muted); font-weight: 500; font-family: 'Red Hat Display', sans-serif; }
        .tl-value { font-family: 'Red Hat Display', sans-serif; font-weight: 600; color: var(--ink); }

        .total-line.grand { background: var(--ink); }
        .total-line.grand .tl-label { color: rgb(255, 255, 255); font-weight: 600; }
        .total-line.grand .tl-value { color: #fff; font-size: 1.1rem; }

        /* ── TERMS ── */
        .terms-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 2.75rem;
        }

        .terms-card {
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            padding: 1.35rem;
            background: var(--card);
            box-shadow: var(--shadow-card);
            transition: box-shadow 0.25s ease, transform 0.25s ease;
            position: relative;
            overflow: hidden;
        }
        .terms-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--accent), #7dfc9b);
            transform: scaleX(0);
            transform-origin: left;
            transition: transform 0.35s ease;
            border-radius: var(--radius-md) var(--radius-md) 0 0;
        }
        .terms-card:hover::before { transform: scaleX(1); }
        .terms-card:hover {
            box-shadow: var(--shadow-hover);
            transform: translateY(-2px);
        }

        .terms-card-title {
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: var(--ink-soft);
            margin-bottom: 0.85rem;
            display: flex;
            align-items: center;
            gap: 0.45rem;
        }
        .terms-card-title i { color: var(--accent); }

        .terms-list {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .terms-list li {
            font-size: 0.84rem;
            color: var(--ink-muted);
            display: flex;
            align-items: flex-start;
            gap: 0.55rem;
            line-height: 1.45;
        }

        .terms-list li::before {
            content: '–';
            color: var(--accent);
            flex-shrink: 0;
            font-weight: 700;
            margin-top: 0.05em;
        }

        /* ── FOOTER ── */
        .q-footer {
            border-top: 1px solid var(--border);
            padding: 1.6rem 2.75rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            background: var(--surface);
        }

        .q-footer-text {
            font-size: 0.78rem;
            color: var(--ink-muted);
            line-height: 1.6;
        }
        .q-footer-text strong { color: var(--ink); font-weight: 700; }

        .q-footer-stamp {
            flex-shrink: 0;
            width: 52px;
            height: 52px;
            border-radius: 50%;
            border: 2px solid var(--accent);
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(12,177,0,0.06);
        }
        .q-footer-stamp i { color: var(--accent); font-size: 1.05rem; }

        /* ── EMPTY STATE ── */
        .empty-state {
            padding: 6rem 2rem;
            text-align: center;
        }
        .empty-icon {
            width: 80px;
            height: 80px;
            background: var(--ink);
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1.5rem;
            box-shadow: 0 8px 24px var(--accent-glow);
        }
        .empty-icon i { font-size: 1.75rem; color: var(--accent); }
        .empty-state h3 { font-size: 1.5rem; font-weight: 800; margin-bottom: 0.5rem; letter-spacing: -0.02em; }
        .empty-state p { color: var(--ink-muted); font-size: 0.95rem; margin-bottom: 2rem; }

        /* ── PRINT ── */
        @media print {
            .toolbar { display: none !important; }
            body { background: white; }
            .page { padding: 0; max-width: 100%; }
            .quotation-card { box-shadow: none; border: none; border-radius: 0; }
            @page { margin: 1.2cm; size: A4; }
        }

        /* ── RESPONSIVE ── */
        @media (max-width: 640px) {
            .toolbar { padding: 0 1rem; }
            .page { padding: 1rem 0.75rem 3rem; }
            .q-header { flex-direction: column; padding: 2rem 1.5rem 1.75rem; gap: 1.5rem; }
            .q-meta-right { text-align: left; }
            .q-title { font-size: 2.2rem; }
            .validity-strip { padding: 0.65rem 1.5rem; }
            .q-body { padding: 1.5rem; }
            .customer-block { grid-template-columns: 1fr; }
            .customer-field:first-child { border-radius: var(--radius-md) var(--radius-md) 0 0; }
            .customer-field:nth-child(2) { border-radius: 0; }
            .customer-field:last-child { border-radius: 0 0 var(--radius-md) var(--radius-md); grid-column: span 1; }
            .terms-grid { grid-template-columns: 1fr; }
            .totals-wrap { justify-content: stretch; }
            .totals-box { width: 100%; }
            .q-footer { flex-direction: column; text-align: center; padding: 1.35rem 1.5rem; }
            .btn span { display: none; }
        }
    </style>
</head>
<body>

    <!-- ── TOOLBAR ── -->
    <div class="toolbar">
        <div class="toolbar-brand">IT<em>&nbsp;Shop.LK</em></div>
        <div class="toolbar-actions">
            <button onclick="downloadPDF()" class="btn btn-primary" id="downloadBtn">
                <i class="fas fa-download"></i>
                <span>Download PDF</span>
            </button>
            <a href="cart.php" class="btn btn-ghost">
                <i class="fas fa-arrow-left"></i>
                <span>Back to Cart</span>
            </a>
        </div>
    </div>

    <div class="page">
        <div class="quotation-card">

            <?php if (empty($cart_items)): ?>
            <div class="empty-state">
                <div class="empty-icon"><i class="fas fa-file-invoice"></i></div>
                <h3>Your cart is empty</h3>
                <p>Add items to your cart first to generate a quotation.</p>
                <a href="products.php" class="btn btn-primary" style="display:inline-flex;margin:0 auto;">
                    <i class="fas fa-shopping-bag"></i> Browse Products
                </a>
            </div>

            <?php else: ?>

            <!-- ── HEADER ── -->
            <div class="q-header">
                <div class="q-header-grid"></div>

                <div class="q-logo">
                    <img src="assets/revised-04.png" alt="IT Shop.LK"
                         onerror="this.style.display='none';this.nextElementSibling.style.display='block'">
                    <div class="q-logo-text" style="display:none">IT<em> Shop.LK</em></div>
                    <div class="q-company-meta">
                        <span><i class="fas fa-phone"></i>+94 077 900 5652</span>
                        <span><i class="fas fa-envelope"></i>info@itshop.lk</span>
                        <span><i class="fas fa-globe"></i>www.itshop.lk</span>
                    </div>
                </div>

                <div class="q-meta-right">
                    <div class="q-pill"><span class="q-pill-dot"></span>Official Document</div>
                    <div class="q-title">Quotation</div>
                    <div class="q-number-badge"><?php echo $quotation_number; ?></div>
                    <div class="q-dates">
                        <div class="q-date-row"><strong>Issued:</strong> <?php echo $quotation_date; ?></div>
                        <div class="q-date-row"><strong>Valid until:</strong> <?php echo $valid_until; ?></div>
                    </div>
                </div>
            </div>

            <!-- ── VALIDITY STRIP ── -->
            <div class="validity-strip">
                <i class="fas fa-circle-check"></i>
                This quotation is valid for 30 days from the date of issue. — Contact us to confirm your order.
            </div>

            <!-- ── BODY ── -->
            <div class="q-body">

                <!-- Customer -->
                <div class="section-heading"><i class="fas fa-user"></i> Bill To</div>
                <div class="customer-block">
                    <div class="customer-field">
                        <div class="cf-label">Full Name</div>
                        <div class="cf-value"><?php echo htmlspecialchars($user_details['name']); ?></div>
                    </div>
                    <div class="customer-field">
                        <div class="cf-label">Email Address</div>
                        <div class="cf-value"><?php echo htmlspecialchars($user_details['email']); ?></div>
                    </div>
                    <?php if (!empty($user_details['phone'])): ?>
                    <div class="customer-field">
                        <div class="cf-label">Phone</div>
                        <div class="cf-value"><?php echo htmlspecialchars($user_details['phone']); ?></div>
                    </div>
                    <?php endif; ?>
                    <div class="customer-field">
                        <div class="cf-label">Customer ID</div>
                        <div class="cf-value" style="font-family:'Red Hat Display',sans-serif;font-size:.82rem">#<?php echo str_pad($user_id, 6, '0', STR_PAD_LEFT); ?></div>
                    </div>
                </div>

                <!-- Items -->
                <div class="section-heading"><i class="fas fa-list"></i> Line Items</div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Product</th>
                                <th>Unit Price</th>
                                <th>Qty</th>
                                <th>Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cart_items as $index => $item): ?>
                            <tr>
                                <td><span class="item-num"><?php echo str_pad($index + 1, 2, '0', STR_PAD_LEFT); ?></span></td>
                                <td>
                                    <div class="item-name"><?php echo htmlspecialchars($item['name']); ?></div>
                                    <div class="item-meta">
                                        <span class="item-tag"><?php echo htmlspecialchars($item['brand']); ?></span>
                                        <span class="item-tag"><?php echo htmlspecialchars($item['category']); ?></span>
                                    </div>
                                </td>
                                <td class="price-cell"><?php echo $user_currency . ' ' . number_format($item['unit_price'], 2); ?></td>
                                <td style="text-align:center"><span class="qty-pill"><?php echo $item['quantity']; ?></span></td>
                                <td class="total-cell"><?php echo $user_currency . ' ' . number_format($item['line_total'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Totals -->
                <div class="totals-wrap">
                    <div class="totals-box">
                        <div class="total-line">
                            <span class="tl-label">Subtotal</span>
                            <span class="tl-value"><?php echo $user_currency . ' ' . number_format($subtotal, 2); ?></span>
                        </div>
                        <div class="total-line">
                            <span class="tl-label">Shipping</span>
                            <span class="tl-value"><?php echo $user_currency . ' ' . number_format($shipping_cost, 2); ?></span>
                        </div>
                        <div class="total-line grand">
                            <span class="tl-label">Grand Total</span>
                            <span class="tl-value"><?php echo $user_currency . ' ' . number_format($total, 2); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Terms -->
                <div class="section-heading"><i class="fas fa-file-lines"></i> Terms & Notes</div>
                <div class="terms-grid">
                    <div class="terms-card">
                        <div class="terms-card-title"><i class="fas fa-shield-halved"></i> Terms & Conditions</div>
                        <ul class="terms-list">
                            <li>Prices are valid for 30 days from the quotation date.</li>
                            <li>All prices are inclusive of applicable taxes.</li>
                            <li>Payment must be made before delivery.</li>
                            <li>Warranty terms vary by product and manufacturer.</li>
                        </ul>
                    </div>
                    <div class="terms-card">
                        <div class="terms-card-title"><i class="fas fa-circle-info"></i> Important Notes</div>
                        <ul class="terms-list">
                            <li>Stock availability is subject to change.</li>
                            <li>Prices may vary depending on the final order date.</li>
                            <li>Delivery timelines will be confirmed on order.</li>
                            <li>Contact us for bulk order discounts.</li>
                        </ul>
                    </div>
                </div>

            </div><!-- /.q-body -->

            <!-- ── FOOTER ── -->
            <div class="q-footer">
                <div class="q-footer-text">
                    <strong>IT Shop.LK</strong> — Your Trusted Technology Partner<br>
                    This is a computer-generated document and is valid without a physical signature.
                </div>
                <div class="q-footer-stamp">
                    <i class="fas fa-check"></i>
                </div>
            </div>

            <?php endif; ?>

        </div><!-- /.quotation-card -->
    </div><!-- /.page -->

    <!-- Scripts -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>

    <script>
    async function downloadPDF() {
        const btn = document.getElementById('downloadBtn');
        const orig = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>Generating…</span>';
        btn.disabled = true;

        try {
            const el = document.querySelector('.quotation-card');
            const canvas = await html2canvas(el, { scale: 2, useCORS: true, logging: false });
            const imgData = canvas.toDataURL('image/png');
            const pdf = new jspdf.jsPDF('p', 'mm', 'a4');
            const imgW = 210;
            const imgH = (canvas.height * imgW) / canvas.width;
            const pageH = 297;
            let heightLeft = imgH;
            let pos = 0;

            pdf.addImage(imgData, 'PNG', 0, pos, imgW, imgH);
            heightLeft -= pageH;
            while (heightLeft > 0) {
                pos = heightLeft - imgH;
                pdf.addPage();
                pdf.addImage(imgData, 'PNG', 0, pos, imgW, imgH);
                heightLeft -= pageH;
            }

            pdf.save('quotation-<?php echo $quotation_number; ?>.pdf');
            toast('PDF downloaded successfully!', 'success');
        } catch(e) {
            toast('Error generating PDF. Please try again.', 'error');
        }

        btn.innerHTML = orig;
        btn.disabled = false;
    }

    function toast(msg, type) {
        const el = document.createElement('div');
        const isOk = type === 'success';
        el.style.cssText = `
            position:fixed;top:78px;right:20px;z-index:9999;
            background:${isOk ? '#0cb100' : '#dc2626'};
            color:#fff;border-radius:12px;padding:.8rem 1.3rem;
            font-family:'Red Hat Display',sans-serif;font-size:.875rem;font-weight:700;
            display:flex;align-items:center;gap:.5rem;
            box-shadow:0 8px 28px rgba(0,0,0,.18);
            animation:slideIn .25s ease;
        `;
        el.innerHTML = `<i class="fas fa-${isOk ? 'check-circle' : 'exclamation-circle'}"></i>${msg}`;
        const style = document.createElement('style');
        style.textContent = `@keyframes slideIn{from{opacity:0;transform:translateY(-10px)}to{opacity:1;transform:translateY(0)}}`;
        document.head.appendChild(style);
        document.body.appendChild(el);
        setTimeout(() => el.remove(), 4000);
    }
    </script>
</body>
</html>