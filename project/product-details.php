<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ── Validate ID before anything else ─────────────────────────────────────────
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: products.php'); exit; }

// ── Bootstrap: sets $pdo via header.php (same pattern as products.php) ───────
$page_title = 'Product Details – IT Shop.LK';
include 'header.php';

// ── Fetch product ─────────────────────────────────────────────────────────────
try {
    $stmt = $pdo->prepare("
        SELECT p.*, GROUP_CONCAT(ps.spec_name, ':', ps.spec_value ORDER BY ps.id SEPARATOR '||') AS specs
        FROM products p
        LEFT JOIN product_specs ps ON p.id = ps.product_id
        WHERE p.id = ?
        GROUP BY p.id
    ");
    $stmt->execute([$id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $product = null;
}

if (!$product) { header('Location: products.php'); exit; }

// Parse specs into key=>value pairs
$specs = [];
if ($product['specs']) {
    foreach (explode('||', $product['specs']) as $pair) {
        [$k, $v] = array_pad(explode(':', $pair, 2), 2, '');
        if ($k) $specs[trim($k)] = trim($v);
    }
}

$product['in_stock']       = ($product['stock_count'] > 0);
$product['original_price'] = $product['original_price'] ?: $product['price'];
$discount = ($product['original_price'] > $product['price'])
    ? round((($product['original_price'] - $product['price']) / $product['original_price']) * 100)
    : 0;

// ── Related products ──────────────────────────────────────────────────────────
try {
    $rs = $pdo->prepare("
        SELECT id, name, brand, price, original_price, image, stock_count
        FROM products
        WHERE category = ? AND id != ?
        ORDER BY RAND()
        LIMIT 5
    ");
    $rs->execute([$product['category'], $id]);
    $related = $rs->fetchAll(PDO::FETCH_ASSOC);
    foreach ($related as &$r) {
        $r['in_stock'] = ($r['stock_count'] > 0);
        $r['original_price'] = $r['original_price'] ?: $r['price'];
    }
    unset($r);
} catch (PDOException $e) { $related = []; }
?>

<style>
    /* ══════════════════════ PAGE TOKENS ══════════════════════ */
    .detail-page { padding-top: 72px; }

    /* ══════════════ HERO BAND ══════════════ */
    .det-hero {
        background: var(--ink);
        padding: 2.5rem 0 2rem;
        position: relative;
        overflow: hidden;
    }
    .det-hero::before {
        content:'';
        position:absolute; inset:0;
        background-image:
            linear-gradient(rgba(255,255,255,.028) 1px, transparent 1px),
            linear-gradient(90deg, rgba(255,255,255,.028) 1px, transparent 1px);
        background-size: 56px 56px;
        pointer-events: none;
    }
    .det-hero::after {
        content:'';
        position:absolute;
        width:500px; height:500px;
        background: radial-gradient(circle, rgba(79,70,229,.28) 0%, transparent 70%);
        top:-150px; right:-100px;
        pointer-events:none;
    }
    .det-hero .inner { position:relative; z-index:2; }

    .det-breadcrumb {
        display:flex; align-items:center; gap:.4rem;
        font-size:.8rem; font-weight:600;
    }
    .det-breadcrumb a    { color:rgba(255,255,255,.5); text-decoration:none; transition:color .15s; }
    .det-breadcrumb a:hover { color:rgba(255,255,255,.9); }
    .det-breadcrumb span { color:rgba(255,255,255,.28); }
    .det-breadcrumb em   { color:rgba(255,255,255,.85); font-style:normal;
                           white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:320px; }

    /* ══════════════ MAIN CONTENT ══════════════ */
    .det-body { padding: 2.75rem 0 4rem; }

    /* ── Image Panel ── */
    .img-panel {
        background: var(--white);
        border-radius: var(--r-lg);
        box-shadow: 0 2px 12px rgba(10,10,15,.07), 0 0 0 1px rgba(10,10,15,.05);
        padding: 1.5rem;
        position: sticky;
        top: 90px;
    }
    .main-img-wrap {
        background: var(--surface);
        border-radius: var(--r-md);
        display: flex; align-items: center; justify-content: center;
        height: 320px;
        overflow: hidden;
        margin-bottom: 1rem;
        position: relative;
    }
    .main-img-wrap img {
        max-width: 100%; max-height: 100%;
        object-fit: contain;
        transition: transform .4s ease;
    }
    .main-img-wrap:hover img { transform: scale(1.05); }
    .main-img-wrap .oos-ribbon {
        position:absolute; top:16px; right:16px;
        background:rgba(220,38,38,.1); color:#dc2626;
        border:1px solid rgba(220,38,38,.2);
        border-radius:var(--r-full);
        font-size:.72rem; font-weight:700;
        padding:4px 12px;
    }
    .disc-ribbon {
        position:absolute; top:16px; left:16px;
        background:rgba(16,185,129,.12); color:#059669;
        border:1px solid rgba(16,185,129,.2);
        border-radius:var(--r-full);
        font-size:.72rem; font-weight:700;
        padding:4px 12px;
    }

    /* thumbs row */
    .img-thumbs { display:flex; gap:8px; flex-wrap:wrap; }
    .img-thumb {
        width:64px; height:64px;
        border-radius:var(--r-sm);
        border:2px solid transparent;
        background:var(--surface);
        display:flex; align-items:center; justify-content:center;
        overflow:hidden; cursor:pointer;
        transition:border-color .15s;
    }
    .img-thumb.active, .img-thumb:hover { border-color:var(--accent); }
    .img-thumb img { max-width:100%; max-height:100%; object-fit:contain; }

    /* ── Info Panel ── */
    .info-panel { display:flex; flex-direction:column; gap:.1rem; }

    .det-brand {
        font-size:.76rem; font-weight:700; color:var(--accent);
        letter-spacing:.07em; text-transform:uppercase;
    }
    .det-name {
        font-family:'Red Hat Display',sans-serif;
        font-size: clamp(1.35rem, 3vw, 1.8rem);
        font-weight:900; color:var(--ink);
        letter-spacing:-.02em; line-height:1.25;
        margin:.35rem 0 .6rem;
    }

    /* Rating row */
    .det-rating { display:flex; align-items:center; gap:.6rem; margin-bottom:.9rem; }
    .det-stars  { color:#f59e0b; font-size:.82rem; display:flex; gap:2px; }
    .det-rtxt   { font-size:.8rem; color:var(--ink-3); font-weight:500; }

    /* Price row */
    .det-price-row {
        display:flex; align-items:baseline; gap:.7rem; flex-wrap:wrap;
        padding:1rem 0; border-top:1px solid rgba(0,0,0,.06); border-bottom:1px solid rgba(0,0,0,.06);
        margin-bottom:1.1rem;
    }
    .det-curr {
        font-family:'Red Hat Display',sans-serif;
        font-size:2rem; font-weight:900; color:var(--ink);
    }
    .det-orig { font-size:.95rem; color:var(--ink-3); text-decoration:line-through; }
    .det-save {
        font-size:.8rem; font-weight:700; color:#059669;
        background:rgba(16,185,129,.1); border:1px solid rgba(16,185,129,.2);
        border-radius:var(--r-full); padding:3px 10px;
    }

    /* Stock status */
    .det-stock {
        display:inline-flex; align-items:center; gap:6px;
        padding:.38rem .9rem; border-radius:var(--r-full);
        font-size:.82rem; font-weight:700;
        margin-bottom:1.1rem;
        width:fit-content;
    }
    .st-in  { background:rgba(16,185,129,.1);  color:#059669; border:1px solid rgba(16,185,129,.2); }
    .st-out { background:rgba(220,38,38,.08);   color:#dc2626; border:1px solid rgba(220,38,38,.15); }
    .st-low { background:rgba(234,88,12,.1);    color:#ea580c; border:1px solid rgba(234,88,12,.2); animation:pulse 2s infinite; }
    @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.65} }

    /* Quantity selector */
    .qty-row { display:flex; align-items:center; gap:.75rem; margin-bottom:1.25rem; }
    .qty-label { font-size:.82rem; font-weight:700; color:var(--ink-2); }
    .qty-ctrl {
        display:inline-flex; align-items:center; gap:0;
        border:1px solid rgba(0,0,0,.1); border-radius:var(--r-md);
        overflow:hidden; width:fit-content;
    }
    .qty-btn {
        width:36px; height:36px;
        background:var(--surface); border:none; cursor:pointer;
        font-size:.88rem; color:var(--ink-2);
        display:flex; align-items:center; justify-content:center;
        transition:background .15s, color .15s;
    }
    .qty-btn:hover:not(:disabled) { background:var(--accent-soft); color:var(--accent); }
    .qty-btn:disabled { cursor:not-allowed; opacity:.4; }
    .qty-input {
        width:48px; height:36px;
        border:none; border-left:1px solid rgba(0,0,0,.08); border-right:1px solid rgba(0,0,0,.08);
        text-align:center;
        font-family:'Red Hat Display',sans-serif; font-size:.9rem; font-weight:700;
        color:var(--ink);
        background:var(--white);
    }
    .qty-input:focus { outline:none; }

    /* CTA buttons */
    .det-actions { display:flex; gap:.75rem; margin-bottom:1.25rem; flex-wrap:wrap; }
    .btn-det-cart {
        display:inline-flex; align-items:center; justify-content:center; gap:8px;
        padding:.72rem 2rem;
        background:var(--accent); color:#fff;
        border:none; border-radius:var(--r-md); cursor:pointer;
        font-family:'Red Hat Display',sans-serif; font-size:.92rem; font-weight:700;
        box-shadow:0 4px 14px rgba(79,70,229,.25);
        transition:background .15s, transform .15s, box-shadow .15s;
        width:auto;
    }
    .btn-det-cart:hover:not(:disabled) {
        background:var(--accent-dark);
        transform:translateY(-2px);
        box-shadow:0 6px 20px rgba(79,70,229,.35);
    }
    .btn-det-cart:disabled { background:#ccc; color:#fff; box-shadow:none; cursor:not-allowed; border:1px solid rgba(0,0,0,.08); }

    /* Meta chips */
    .det-meta { display:flex; flex-wrap:wrap; gap:.5rem; margin-top:.9rem; }
    .det-chip {
        display:inline-flex; align-items:center; gap:5px;
        padding:.35rem .8rem; border-radius:var(--r-full);
        font-size:.72rem; font-weight:600; color:var(--ink-2);
        background:var(--surface); border:1px solid rgba(0,0,0,.07);
    }
    .det-chip i { color:var(--accent); font-size:.68rem; }

    /* ══════════════ TABS ══════════════ */
    .det-tabs { padding:2.5rem 0 4rem; border-top:1px solid rgba(0,0,0,.06); }
    .tab-nav {
        display:flex; gap:0;
        border-bottom:2px solid rgba(0,0,0,.07);
        margin-bottom:2rem;
        flex-wrap:wrap;
    }
    .tab-btn {
        padding:.65rem 1.35rem;
        background:none; border:none; border-bottom:3px solid transparent;
        margin-bottom:-2px;
        font-family:'Red Hat Display',sans-serif; font-size:.88rem; font-weight:700;
        color:var(--ink-3); cursor:pointer;
        transition:color .15s, border-color .15s;
    }
    .tab-btn:hover  { color:var(--ink); }
    .tab-btn.active { color:var(--accent); border-bottom-color:var(--accent); }
    .tab-panel { display:none; }
    .tab-panel.active { display:block; }

    /* Specs table */
    .specs-table { width:100%; border-collapse:collapse; }
    .specs-table tr { border-bottom:1px solid rgba(0,0,0,.05); }
    .specs-table tr:last-child { border-bottom:none; }
    .specs-table td {
        padding:.7rem .9rem; font-size:.875rem;
    }
    .specs-table td:first-child {
        width:38%; font-weight:700; color:var(--ink-2);
        background:var(--surface);
    }
    .specs-table td:last-child { color:var(--ink); }
    .specs-table tr:nth-child(even) td:last-child { background:rgba(0,0,0,.012); }

    /* Description */
    .det-desc { font-size:.92rem; color:var(--ink-2); line-height:1.8; }
    .det-desc p { margin-bottom:1rem; }

    /* ══════════════ RELATED PRODUCTS ══════════════ */
    .related-section { padding:0 0 5rem; }
    .section-heading {
        font-family:'Red Hat Display',sans-serif;
        font-size:1.4rem; font-weight:900; color:var(--ink);
        letter-spacing:-.02em; margin-bottom:1.5rem;
    }
    .section-heading span { color:var(--accent); }

    .col-5th {
        flex: 0 0 20%; max-width: 20%;
        padding-left: 10px; padding-right: 10px;
    }
    @media (max-width:1199px) { .col-5th { flex:0 0 25%; max-width:25%; } }
    @media (max-width:991px)  { .col-5th { flex:0 0 33.333%; max-width:33.333%; } }
    @media (max-width:767px)  { .col-5th { flex:0 0 50%; max-width:50%; } }
    @media (max-width:480px)  { .col-5th { flex:0 0 100%; max-width:100%; } }

    .product-card {
        background:var(--white); border-radius:var(--r-lg); padding:1.1rem;
        display:flex; flex-direction:column;
        box-shadow:0 2px 12px rgba(10,10,15,.07), 0 0 0 1px rgba(10,10,15,.05);
        height:100%; transition:transform .28s ease, box-shadow .28s ease;
        position:relative; overflow:hidden;
    }
    .product-card::before {
        content:''; position:absolute; top:0; left:0; right:0; height:3px;
        background:linear-gradient(90deg, var(--accent), #34d399);
        transform:scaleX(0); transform-origin:left; transition:transform .3s ease;
        border-radius:var(--r-lg) var(--r-lg) 0 0;
    }
    .product-card:hover { transform:translateY(-5px); box-shadow:0 16px 40px rgba(10,10,15,.13), 0 0 0 1px rgba(79,70,229,.1); }
    .product-card:hover::before { transform:scaleX(1); }

    .pc-img {
        position:relative; height:140px; background:var(--surface);
        border-radius:var(--r-md); margin-bottom:.9rem;
        display:flex; align-items:center; justify-content:center; overflow:hidden;
    }
    .pc-img img { max-width:100%; max-height:100%; object-fit:contain; }
    .pc-img .ph { font-size:2.4rem; color:var(--ink-3); }
    .pc-badge { position:absolute; padding:3px 8px; border-radius:var(--r-full); font-size:.62rem; font-weight:700; letter-spacing:.02em; line-height:1; z-index:10; }
    .pc-badge.top-r { top:8px; right:8px; }
    .pc-badge.disc-b { background:rgba(16,185,129,.12); color:#059669; border:1px solid rgba(16,185,129,.2); }
    .pc-badge.oos-b  { background:rgba(100,100,120,.18); color:#6b6b88; border:1px solid rgba(100,100,120,.2); }

    .pc-info { flex:1; display:flex; flex-direction:column; }
    .pc-brand { font-size:.68rem; font-weight:700; color:var(--ink-3); letter-spacing:.05em; text-transform:uppercase; margin-bottom:.25rem; }
    .pc-name {
        font-size:.855rem; font-weight:700; color:var(--ink); margin-bottom:.6rem; line-height:1.4;
        display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; min-height:2.4rem;
    }
    .pc-price { margin-top:auto; margin-bottom:.85rem; display:flex; align-items:baseline; gap:.4rem; flex-wrap:wrap; }
    .pc-curr  { font-size:1.05rem; font-weight:800; color:var(--ink); }
    .pc-orig  { font-size:.78rem; color:var(--ink-3); text-decoration:line-through; }

    .pc-actions { display:flex; flex-direction:column; gap:6px; }
    .btn-view {
        display:flex; align-items:center; justify-content:center; gap:6px;
        padding:.5rem; border-radius:var(--r-md);
        font-family:'Red Hat Display',sans-serif; font-size:.78rem; font-weight:600;
        background:var(--accent-soft); color:var(--accent); border:1px solid var(--accent-border);
        text-decoration:none; transition:background .15s, color .15s;
    }
    .btn-view:hover { background:var(--accent); color:#fff; text-decoration:none; }
    .btn-cart {
        display:flex; align-items:center; justify-content:center; gap:6px;
        padding:.5rem; border-radius:var(--r-md);
        font-family:'Red Hat Display',sans-serif; font-size:.78rem; font-weight:700;
        background:var(--accent); color:#fff; border:none; cursor:pointer;
        box-shadow:0 2px 10px rgba(79,70,229,.28);
        transition:background .15s, transform .15s, box-shadow .15s;
    }
    .btn-cart:hover:not(:disabled) { background:var(--accent-dark); transform:translateY(-1px); box-shadow:0 4px 16px rgba(79,70,229,.38); }
    .btn-cart:disabled { background:var(--surface); color:var(--ink-3); box-shadow:none; cursor:not-allowed; border:1px solid rgba(0,0,0,.08); }

    /* ══════════════ TOAST ══════════════ */
    .it-toast {
        position:fixed; top:90px; right:20px; z-index:9999;
        display:flex; align-items:flex-start; gap:10px;
        min-width:280px; max-width:360px;
        background:var(--white); border:1px solid rgba(0,0,0,.08);
        border-radius:var(--r-lg); box-shadow:0 8px 32px rgba(13,13,20,.14);
        padding:.9rem 1rem; animation:toastIn .25s ease;
        font-family:'Red Hat Display',sans-serif;
    }
    @keyframes toastIn { from{opacity:0;transform:translateX(20px)} to{opacity:1;transform:translateX(0)} }
    .it-toast .t-icon { width:32px; height:32px; border-radius:50%; display:flex; align-items:center; justify-content:center; flex-shrink:0; font-size:.85rem; }
    .it-toast .t-icon.s { background:rgba(16,185,129,.12); color:#059669; }
    .it-toast .t-icon.e { background:rgba(220,38,38,.1);   color:#dc2626; }
    .it-toast .t-icon.i { background:var(--accent-soft);   color:var(--accent); }
    .it-toast .t-body  { flex:1; font-size:.855rem; font-weight:500; color:var(--ink-2); line-height:1.4; padding-top:5px; }
    .it-toast .t-close { background:none; border:none; cursor:pointer; color:var(--ink-3); font-size:.8rem; padding:4px; line-height:1; flex-shrink:0; margin-top:2px; transition:color .15s; }
    .it-toast .t-close:hover { color:var(--ink); }

    /* ══════════════ ANIMATE IN ══════════════ */
    .product-card { opacity:0; transform:translateY(20px); }
    .product-card.visible { opacity:1; transform:translateY(0); transition:opacity .5s ease, transform .5s ease, box-shadow .28s ease, transform .28s ease; }

    @media (max-width:991px) {
        .img-panel { position:static; margin-bottom:2rem; }
        .main-img-wrap { height:260px; }
    }
</style>

<div class="detail-page">

    <!-- ── Hero Band ── -->
    <div class="det-hero">
        <div class="container inner">
            <div class="det-breadcrumb">
                <a href="index.php">Home</a>
                <span>/</span>
                <a href="products.php">Products</a>
                <span>/</span>
                <a href="products.php?category=<?php echo urlencode($product['category']); ?>">
                    <?php echo htmlspecialchars(ucwords(str_replace(['_','-'],' ',$product['category']))); ?>
                </a>
                <span>/</span>
                <em><?php echo htmlspecialchars($product['name']); ?></em>
            </div>
        </div>
    </div>

    <!-- ── Main Body ── -->
    <div class="det-body">
        <div class="container">
            <div class="row g-4">

                <!-- Left: Image Panel -->
                <div class="col-lg-5 col-md-6">
                    <div class="img-panel">
                        <div class="main-img-wrap" id="mainImgWrap">
                            <?php
                        // Admin stores image as "uploads/products/filename.ext" relative to /admin/
                        // From public root that becomes "admin/uploads/products/filename.ext"
                        $img = 'admin/' . ltrim($product['image'], '/');
                        ?>
                            <img src="<?php echo htmlspecialchars($img); ?>"
                                 alt="<?php echo htmlspecialchars($product['name']); ?>"
                                 id="mainImg"
                                 onerror="this.style.display='none';document.getElementById('imgFallback').style.display='flex'">
                            <div id="imgFallback" style="display:none;font-size:4rem;color:var(--ink-3)"><i class="fas fa-laptop"></i></div>

                            <?php if ($discount > 0): ?>
                                <span class="disc-ribbon"><?= $discount ?>% OFF</span>
                            <?php endif; ?>
                            <?php if (!$product['in_stock']): ?>
                                <span class="oos-ribbon">Out of Stock</span>
                            <?php endif; ?>
                        </div>
                        <!-- Thumb row -->
                        <div class="img-thumbs">
                            <div class="img-thumb active" onclick="switchImg('<?php echo htmlspecialchars($img); ?>', this)">
                                <img src="<?php echo htmlspecialchars($img); ?>" alt="thumb">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right: Info Panel -->
                <div class="col-lg-7 col-md-6">
                    <div class="info-panel">

                        <div class="det-brand"><?php echo htmlspecialchars($product['brand']); ?></div>
                        <h1 class="det-name"><?php echo htmlspecialchars($product['name']); ?></h1>

                        <!-- Rating -->
                        <?php $r = (float)($product['rating'] ?? 0); if ($r > 0): ?>
                        <div class="det-rating">
                            <div class="det-stars">
                                <?php
                                for ($i = 1; $i <= 5; $i++) {
                                    if ($i <= floor($r))    echo '<i class="fas fa-star"></i>';
                                    elseif ($i <= ceil($r)) echo '<i class="fas fa-star-half-alt"></i>';
                                    else                    echo '<i class="far fa-star"></i>';
                                }
                                ?>
                            </div>
                            <span class="det-rtxt"><?= number_format($r,1) ?>/5
                                <?php if ($product['reviews'] ?? 0): ?>
                                    (<?= number_format($product['reviews']) ?> reviews)
                                <?php endif; ?>
                            </span>
                        </div>
                        <?php endif; ?>

                        <!-- Price -->
                        <div class="det-price-row">
                            <span class="det-curr">LKR <?php echo number_format($product['price'], 2); ?></span>
                            <?php if ($discount > 0): ?>
                                <span class="det-orig">LKR <?php echo number_format($product['original_price'], 2); ?></span>
                                <span class="det-save">Save LKR <?php echo number_format($product['original_price'] - $product['price'], 2); ?></span>
                            <?php endif; ?>
                        </div>

                        <!-- Stock status -->
                        <?php if (!$product['in_stock']): ?>
                            <span class="det-stock st-out"><i class="fas fa-circle-xmark"></i> Out of Stock</span>
                        <?php elseif ($product['stock_count'] <= 5): ?>
                            <span class="det-stock st-low"><i class="fas fa-fire"></i> Only <?= $product['stock_count'] ?> left!</span>
                        <?php else: ?>
                            <span class="det-stock st-in"><i class="fas fa-circle-check"></i> In Stock</span>
                        <?php endif; ?>

                        <!-- Quantity + CTA -->
                        <?php if ($product['in_stock']): ?>
                        <div class="qty-row">
                            <span class="qty-label">Qty:</span>
                            <div class="qty-ctrl">
                                <button class="qty-btn" id="qtyMinus" onclick="changeQty(-1)" title="Decrease"><i class="fas fa-minus"></i></button>
                                <input  class="qty-input" id="qtyInput" type="number" value="1" min="1" max="<?= $product['stock_count'] ?>" readonly>
                                <button class="qty-btn" id="qtyPlus"  onclick="changeQty(1)"  title="Increase"><i class="fas fa-plus"></i></button>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="det-actions">
                            <?php if ($product['in_stock']): ?>
                            <button class="btn-det-cart" id="mainCartBtn"
                                    onclick="addToCartDetail(<?= $product['id'] ?>)">
                                <i class="fas fa-cart-plus"></i> Add to Cart
                            </button>
                            <?php else: ?>
                            <button class="btn-det-cart" disabled>
                                <i class="fas fa-ban"></i> Unavailable
                            </button>
                            <?php endif; ?>
                        </div>

                        <!-- Meta chips -->
                        <div class="det-meta">
                            <?php if ($product['category']): ?>
                            <span class="det-chip">
                                <i class="fas fa-tag"></i>
                                <?php echo htmlspecialchars(ucwords(str_replace(['_','-'],' ',$product['category']))); ?>
                            </span>
                            <?php endif; ?>
                            <span class="det-chip"><i class="fas fa-shield-halved"></i> Warranty included</span>
                            <span class="det-chip"><i class="fas fa-truck-fast"></i> Fast delivery</span>
                            <span class="det-chip"><i class="fas fa-rotate-left"></i> Easy returns</span>
                        </div>

                    </div>
                </div><!-- /col -->

            </div><!-- /row -->
        </div><!-- /container -->
    </div><!-- /det-body -->

    <!-- ── Tabs ── -->
    <div class="det-tabs">
        <div class="container">
            <div class="tab-nav">
                <button class="tab-btn active" onclick="switchTab('specs', this)">Specifications</button>
                <?php if (!empty($product['description'])): ?>
                <button class="tab-btn" onclick="switchTab('desc', this)">Description</button>
                <?php endif; ?>
                <!--<button class="tab-btn" onclick="switchTab('ship', this)">Shipping & Returns</button>-->
            </div>

            <!-- Specifications tab -->
            <div class="tab-panel active" id="tab-specs">
                <?php if (!empty($specs)): ?>
                <div class="table-responsive">
                    <table class="specs-table">
                        <tbody>
                        <?php foreach ($specs as $key => $val): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($key); ?></td>
                                <td><?php echo htmlspecialchars($val); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!isset($specs['Brand']) && $product['brand']): ?>
                            <tr><td>Brand</td><td><?php echo htmlspecialchars($product['brand']); ?></td></tr>
                        <?php endif; ?>
                        <?php if (!isset($specs['Category']) && $product['category']): ?>
                            <tr><td>Category</td><td><?php echo htmlspecialchars(ucwords(str_replace(['_','-'],' ',$product['category']))); ?></td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <p style="color:var(--ink-3);font-size:.9rem;">No specifications available for this product.</p>
                <?php endif; ?>
            </div>

            <!-- Description tab -->
            <?php if (!empty($product['description'])): ?>
            <div class="tab-panel" id="tab-desc">
                <div class="det-desc">
                    <?php echo nl2br(htmlspecialchars($product['description'])); ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Shipping tab -->
            <div class="tab-panel" id="tab-ship">
                <div class="det-desc">
                    <p><strong>Standard Delivery</strong> – 2–5 working days island-wide. Colombo and suburbs typically within 1–2 days.</p>
                    <p><strong>Express Delivery</strong> – Available for select areas. Choose at checkout.</p>
                    <p><strong>Returns Policy</strong> – Products may be returned within 7 days of delivery in original, unopened condition. Contact our support team to initiate a return. Defective items are covered under the manufacturer warranty.</p>
                    <p><strong>Warranty</strong> – All products carry the manufacturer's standard warranty. Duration varies by brand and product category. Please retain your invoice as proof of purchase.</p>
                </div>
            </div>

        </div><!-- /tab-nav -->
        </div><!-- /container -->
    </div><!-- /det-tabs -->

    <!-- ── Related Products ── -->
    <?php if (!empty($related)): ?>
    <section class="related-section">
        <div class="container">
            <h2 class="section-heading">Related <span>Products</span></h2>
            <div class="row g-3" id="relatedGrid" style="margin-left:-10px;margin-right:-10px;">
                <?php foreach ($related as $rel):
                    $rImg  = 'admin/' . ltrim($rel['image'], '/');
                    $rDisc = ($rel['original_price'] > $rel['price'])
                        ? round((($rel['original_price'] - $rel['price']) / $rel['original_price']) * 100) : 0;
                ?>
                <div class="col-5th">
                    <div class="product-card <?= !$rel['in_stock']?'oos':'' ?>">
                        <div class="pc-img">
                            <img src="<?php echo htmlspecialchars($rImg); ?>"
                                 alt="<?php echo htmlspecialchars($rel['name']); ?>"
                                 onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                            <div class="ph" style="display:none"><i class="fas fa-laptop"></i></div>
                            <?php if ($rDisc > 0): ?><span class="pc-badge disc-b top-r"><?= $rDisc ?>% OFF</span><?php endif; ?>
                            <?php if (!$rel['in_stock']): ?><span class="pc-badge oos-b top-r">Out of Stock</span><?php endif; ?>
                        </div>
                        <div class="pc-info">
                            <div class="pc-brand"><?php echo htmlspecialchars($rel['brand']); ?></div>
                            <div class="pc-name"><?php echo htmlspecialchars($rel['name']); ?></div>
                            <div class="pc-price">
                                <span class="pc-curr">LKR <?php echo number_format($rel['price'], 2); ?></span>
                                <?php if ($rDisc > 0): ?><span class="pc-orig">LKR <?php echo number_format($rel['original_price'], 2); ?></span><?php endif; ?>
                            </div>
                            <div class="pc-actions">
                                <a href="product-details.php?id=<?= $rel['id'] ?>" class="btn-view">
                                    <i class="fas fa-eye" style="font-size:.72rem"></i> View Details
                                </a>
                                <?php if ($rel['in_stock']): ?>
                                <button class="btn-cart" onclick="addToCart(<?= $rel['id'] ?>)"
                                        data-product-id="<?= $rel['id'] ?>" data-in-stock="true">
                                    <i class="fas fa-cart-plus" style="font-size:.72rem"></i> Add to Cart
                                </button>
                                <?php else: ?>
                                <button class="btn-cart" disabled>
                                    <i class="fas fa-ban" style="font-size:.72rem"></i> Unavailable
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

</div><!-- /detail-page -->

<?php
$extra_scripts = <<<'JS'
<script>
/* ── Image switcher ── */
function switchImg(src, thumb) {
    document.getElementById('mainImg').src = src;
    document.querySelectorAll('.img-thumb').forEach(t => t.classList.remove('active'));
    if (thumb) thumb.classList.add('active');
}

/* ── Tab switcher ── */
function switchTab(name, btn) {
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    const panel = document.getElementById('tab-' + name);
    if (panel) panel.classList.add('active');
    if (btn)   btn.classList.add('active');
}

/* ── Quantity control ── */
function changeQty(delta) {
    const input = document.getElementById('qtyInput');
    if (!input) return;
    const max = parseInt(input.max) || 999;
    let val = parseInt(input.value) + delta;
    val = Math.max(1, Math.min(val, max));
    input.value = val;
    document.getElementById('qtyMinus').disabled = (val <= 1);
    document.getElementById('qtyPlus').disabled  = (val >= max);
}

/* ── Add to cart (detail page, respects qty) ── */
function addToCartDetail(productId) {
    const btn = document.getElementById('mainCartBtn');
    const qty = parseInt(document.getElementById('qtyInput')?.value) || 1;
    if (!btn || btn.disabled) return;

    const orig   = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Adding…';
    btn.disabled  = true;

    fetch('add-to-cart.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ product_id: productId, quantity: qty })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            btn.innerHTML = '<i class="fas fa-check"></i> Added!';
            btn.style.background = '#038703';
            updateCartUI(data.cart_count, data.cart_total);
            showToast(data.message || 'Added to cart!', 's');
            setTimeout(() => { btn.innerHTML = orig; btn.style.background = ''; btn.disabled = false; }, 2200);
        } else {
            if (data.redirect) {
                showToast('Please log in to add items to your cart', 'e');
                setTimeout(() => window.location.href = data.redirect, 1600);
                return;
            }
            showToast(data.message || 'Could not add to cart', 'e');
            btn.innerHTML = orig; btn.disabled = false;
        }
    })
    .catch(() => {
        showToast('Network error – please try again', 'e');
        btn.innerHTML = orig; btn.disabled = false;
    });
}

/* ── Add to cart (related cards) ── */
function addToCart(productId) {
    const btn = document.querySelector(`[data-product-id="${productId}"]`);
    if (!btn || btn.disabled) return;

    const orig   = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin" style="font-size:.72rem"></i> Adding…';
    btn.disabled  = true;

    fetch('add-to-cart.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ product_id: productId, quantity: 1 })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            btn.innerHTML = '<i class="fas fa-check" style="font-size:.72rem"></i> Added!';
            btn.style.background = '#059669';
            updateCartUI(data.cart_count, data.cart_total);
            showToast(data.message || 'Added to cart!', 's');
            setTimeout(() => { btn.innerHTML = orig; btn.style.background = ''; btn.disabled = false; }, 2200);
        } else {
            if (data.redirect) {
                showToast('Please log in to add items to your cart', 'e');
                setTimeout(() => window.location.href = data.redirect, 1600);
                return;
            }
            showToast(data.message || 'Could not add to cart', 'e');
            btn.innerHTML = orig; btn.disabled = false;
        }
    })
    .catch(() => {
        showToast('Network error – please try again', 'e');
        btn.innerHTML = orig; btn.disabled = false;
    });
}

/* ── Cart UI updater ── */
function updateCartUI(count, total) {
    let badge = document.querySelector('.bdot');
    const icon  = document.querySelector('.icon-btn');
    if (count > 0) {
        if (badge) badge.textContent = count;
        else if (icon) { const b = document.createElement('span'); b.className = 'bdot'; b.textContent = count; icon.appendChild(b); }
    } else if (badge) badge.remove();
    document.querySelectorAll('.cart-total').forEach(el => {
        if (total !== undefined) el.textContent = 'LKR ' + new Intl.NumberFormat().format(total);
    });
}

/* ── Toast ── */
function showToast(msg, type = 'i') {
    document.querySelectorAll('.it-toast').forEach(t => t.remove());
    const icons = { s:'circle-check', e:'circle-exclamation', i:'circle-info' };
    const el = document.createElement('div');
    el.className = 'it-toast';
    el.innerHTML = `
        <div class="t-icon ${type}"><i class="fas fa-${icons[type]||'circle-info'}"></i></div>
        <div class="t-body">${msg}</div>
        <button class="t-close" aria-label="Close"><i class="fas fa-xmark"></i></button>`;
    document.body.appendChild(el);
    const tid = setTimeout(() => el.remove(), 4000);
    el.querySelector('.t-close').addEventListener('click', () => { clearTimeout(tid); el.remove(); });
}

/* ── Scroll reveal for related cards ── */
document.addEventListener('DOMContentLoaded', () => {
    const io = new IntersectionObserver(entries => {
        entries.forEach((e, i) => {
            if (e.isIntersecting) {
                setTimeout(() => e.target.classList.add('visible'), i * 60);
                io.unobserve(e.target);
            }
        });
    }, { threshold: 0.05, rootMargin:'0px 0px -40px 0px' });
    document.querySelectorAll('#relatedGrid .product-card').forEach(c => io.observe(c));

    /* init qty button states */
    changeQty(0);

    /* update browser tab title with real product name */
    const detName = document.querySelector(".det-name");
    if (detName) document.title = detName.textContent.trim() + " – IT Shop.LK";
});
</script>
JS;

include 'footer.php';
?>