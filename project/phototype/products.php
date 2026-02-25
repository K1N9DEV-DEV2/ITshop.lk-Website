<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$page_title = 'Products - IT Shop.LK';
include 'header.php';

// ── Filters ───────────────────────────────────────────────────────────────────
$category       = $_GET['category']  ?? '';
$search         = $_GET['search']    ?? '';
$sort           = $_GET['sort']      ?? 'name_asc';
$price_min      = $_GET['price_min'] ?? '';
$price_max      = $_GET['price_max'] ?? '';
$page           = max(1, (int)($_GET['page'] ?? 1));
$items_per_page = 15; // updated to 15 to match 5-per-row
$offset         = ($page - 1) * $items_per_page;

// ── SQL ───────────────────────────────────────────────────────────────────────
$sql = "SELECT p.id, p.name, p.category, p.price, p.original_price,
               p.image, p.brand, p.rating, p.reviews, p.stock_count,
               CASE WHEN p.stock_count > 0 THEN 1 ELSE 0 END as in_stock,
               GROUP_CONCAT(ps.spec_name SEPARATOR '|') as specs
        FROM products p
        LEFT JOIN product_specs ps ON p.id = ps.product_id
        WHERE 1=1";
$params = [];

if ($category && $category !== 'all') { $sql .= " AND p.category = ?";                               $params[] = $category; }
if ($search) {
    $sql .= " AND (p.name LIKE ? OR p.brand LIKE ? OR p.category LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}
if ($price_min) { $sql .= " AND p.price >= ?"; $params[] = floatval($price_min); }
if ($price_max) { $sql .= " AND p.price <= ?"; $params[] = floatval($price_max); }
$sql .= " GROUP BY p.id";

$count_sql    = "SELECT COUNT(DISTINCT p.id) as total FROM products p WHERE 1=1";
$count_params = [];
if ($category && $category !== 'all') { $count_sql .= " AND p.category = ?"; $count_params[] = $category; }
if ($search) {
    $count_sql .= " AND (p.name LIKE ? OR p.brand LIKE ? OR p.category LIKE ?)";
    $count_params[] = "%$search%"; $count_params[] = "%$search%"; $count_params[] = "%$search%";
}
if ($price_min) { $count_sql .= " AND p.price >= ?"; $count_params[] = floatval($price_min); }
if ($price_max) { $count_sql .= " AND p.price <= ?"; $count_params[] = floatval($price_max); }

try {
    $cs = $pdo->prepare($count_sql); $cs->execute($count_params);
    $total_products = $cs->fetch()['total'];
    $total_pages    = ceil($total_products / $items_per_page);
} catch (PDOException $e) { $total_products = 0; $total_pages = 1; }

$sql .= match ($sort) {
    'price_low'  => " ORDER BY p.price ASC",
    'price_high' => " ORDER BY p.price DESC",
    'rating'     => " ORDER BY p.rating DESC",
    default      => " ORDER BY p.name ASC",
};
$sql .= " LIMIT ? OFFSET ?";
$params[] = $items_per_page;
$params[] = $offset;

try {
    $stmt = $pdo->prepare($sql); $stmt->execute($params);
    $filtered_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($filtered_products as &$product) {
        $product['specs']          = $product['specs'] ? explode('|', $product['specs']) : [];
        $product['in_stock']       = ($product['stock_count'] > 0);
        $product['original_price'] = $product['original_price'] ?: $product['price'];
    }
    unset($product);
} catch (PDOException $e) { $filtered_products = []; }

// ── Categories ────────────────────────────────────────────────────────────────
try {
    $stmt = $pdo->query("SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category != '' ORDER BY category");
    $db_categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $categories = ['all' => 'All Categories'];
    foreach ($db_categories as $cat) $categories[$cat] = ucwords(str_replace(['_','-'],' ',$cat));
} catch (PDOException $e) {
    $categories = [
        'all'=>'All Categories','casings'=>'Casing','cooling'=>'Cooling & Lighting',
        'desktops'=>'Desktop','graphics'=>'Graphics Cards','peripherals'=>'Keyboards & Mouse',
        'laptops'=>'Laptops','memory'=>'Memory (RAM)','monitors'=>'Monitors',
        'motherboards'=>'Motherboards','processors'=>'Processors',
        'storage'=>'Storage','power'=>'Power Supply','audio'=>'Speakers & Headset',
    ];
}

function buildQueryString($p) { $q = $_GET; $q['page'] = $p; return http_build_query($q); }
?>

<style>
    /* ══════════════════════ PAGE TOKENS ══════════════════════ */
    .products-page { padding-top: 72px; }

    /* ══════════════ 5-COLUMN GRID ══════════════ */
    .col-5th {
        flex: 0 0 20%;
        max-width: 20%;
        padding-left: 10px;
        padding-right: 10px;
    }
    @media (max-width: 1199px) {
        .col-5th { flex: 0 0 25%; max-width: 25%; } /* 4 per row */
    }
    @media (max-width: 991px) {
        .col-5th { flex: 0 0 33.3333%; max-width: 33.3333%; } /* 3 per row */
    }
    @media (max-width: 767px) {
        .col-5th { flex: 0 0 50%; max-width: 50%; } /* 2 per row */
    }
    @media (max-width: 480px) {
        .col-5th { flex: 0 0 100%; max-width: 100%; } /* 1 per row */
    }

    /* ══════════════ PAGE HEADER ══════════════ */
    .pg-hero {
        background: var(--ink);
        padding: 3.5rem 0 2.75rem;
        position: relative;
        overflow: hidden;
    }
    .pg-hero::before {
        content:'';
        position:absolute; inset:0;
        background-image:
            linear-gradient(rgba(255,255,255,.028) 1px, transparent 1px),
            linear-gradient(90deg, rgba(255,255,255,.028) 1px, transparent 1px);
        background-size: 56px 56px;
        pointer-events: none;
    }
    .pg-hero::after {
        content:'';
        position:absolute;
        width:500px; height:500px;
        background: radial-gradient(circle, rgba(79,70,229,.3) 0%, transparent 70%);
        top:-150px; right:-100px;
        pointer-events:none;
    }
    .pg-hero .inner { position:relative; z-index:2; }

    .pg-breadcrumb {
        display:flex; align-items:center; gap:.4rem;
        font-size:.8rem; font-weight:600;
        margin-bottom:1rem;
    }
    .pg-breadcrumb a  { color:rgba(255,255,255,.55); text-decoration:none; transition:color .15s; }
    .pg-breadcrumb a:hover { color:rgba(255,255,255,.9); }
    .pg-breadcrumb span { color:rgba(255,255,255,.3); }
    .pg-breadcrumb em   { color:rgba(255,255,255,.85); font-style:normal; }

    .pg-title {
        font-family:'Red Hat Display', sans-serif;
        font-size: clamp(2rem, 4vw, 2.8rem);
        font-weight: 900;
        color: #fff;
        letter-spacing: -.025em;
        margin-bottom: .5rem;
    }
    .pg-sub { font-size:.95rem; color:rgba(255,255,255,.45); }

    /* ══════════════ FILTER BAR ══════════════ */
    .filter-bar {
        background: var(--white);
        border-bottom: 1px solid rgba(0,0,0,.06);
        padding: 1.1rem 0;
        position: sticky;
        top: 72px;
        z-index: 100;
        box-shadow: 0 2px 16px rgba(0,0,0,.05);
    }
    .filter-bar .form-control,
    .filter-bar .form-select {
        font-family: 'Red Hat Display', sans-serif;
        font-size: .855rem;
        font-weight: 500;
        border: 1px solid rgba(0,0,0,.1);
        border-radius: var(--r-md);
        padding: .52rem .85rem;
        color: var(--ink);
        background: var(--surface);
        transition: border-color .15s, box-shadow .15s;
    }
    .filter-bar .form-control:focus,
    .filter-bar .form-select:focus {
        border-color: var(--accent-border);
        box-shadow: 0 0 0 3px rgba(79,70,229,.1);
        background: var(--white);
        outline: none;
    }
    .filter-bar .form-control::placeholder { color: var(--ink-3); }

    .search-wrap { position:relative; }
    .search-wrap .si {
        position:absolute; left:.75rem; top:50%;
        transform:translateY(-50%);
        color:var(--ink-3); font-size:.8rem;
        pointer-events:none;
    }
    .search-wrap .form-control { padding-left:2.25rem; }

    .btn-filter-go {
        display:inline-flex; align-items:center; justify-content:center;
        width:40px; height:40px;
        background:var(--accent); color:#fff;
        border:none; border-radius:var(--r-md);
        font-size:.85rem; cursor:pointer; flex-shrink:0;
        transition:background .15s, transform .15s;
    }
    .btn-filter-go:hover { background:var(--accent-dark); transform:translateY(-1px); }

    .btn-filter-clear {
        display:inline-flex; align-items:center; justify-content:center;
        width:40px; height:40px;
        background:var(--surface); color:var(--ink-3);
        border:1px solid rgba(0,0,0,.09); border-radius:var(--r-md);
        font-size:.82rem; cursor:pointer; flex-shrink:0;
        text-decoration:none;
        transition:background .15s, color .15s;
    }
    .btn-filter-clear:hover { background:#fee2e2; color:#dc2626; border-color:#fca5a5; }

    /* ══════════════ RESULTS BAR ══════════════ */
    .results-bar {
        display:flex; align-items:center; justify-content:space-between;
        flex-wrap:wrap; gap:.75rem;
        padding:1rem 0 1.25rem;
    }
    .results-count {
        font-size:.875rem; font-weight:600; color:var(--ink-2);
    }
    .results-count em { color:var(--accent); font-style:normal; }

    .view-toggle { display:flex; gap:6px; }
    .vt-btn {
        width:36px; height:36px;
        border-radius:var(--r-sm);
        border:1px solid rgba(0,0,0,.09);
        background:var(--surface); color:var(--ink-3);
        display:inline-flex; align-items:center; justify-content:center;
        font-size:.82rem; cursor:pointer;
        transition:background .15s, color .15s, border-color .15s;
    }
    .vt-btn.active, .vt-btn:hover {
        background:var(--accent-soft); color:var(--accent);
        border-color:var(--accent-border);
    }

    /* ══════════════ PRODUCT GRID ══════════════ */
    .products-section { padding:0 0 5rem; }

    #productsGrid {
        margin-left: -10px;
        margin-right: -10px;
    }

    .product-card {
        background:var(--white);
        border-radius:var(--r-lg);
        padding:1.1rem;
        display:flex; flex-direction:column;
        box-shadow: 0 2px 12px rgba(10,10,15,.07), 0 0 0 1px rgba(10,10,15,.05);
        height:100%;
        transition:transform .28s ease, box-shadow .28s ease;
        position:relative;
        overflow:hidden;
    }
    .product-card::before {
        content:'';
        position:absolute; top:0; left:0; right:0; height:3px;
        background:linear-gradient(90deg, var(--accent), #34d399);
        transform:scaleX(0); transform-origin:left;
        transition:transform .3s ease;
        border-radius:var(--r-lg) var(--r-lg) 0 0;
    }
    .product-card:hover { transform:translateY(-5px); box-shadow:0 16px 40px rgba(10,10,15,.13), 0 0 0 1px rgba(79,70,229,.1); }
    .product-card:hover::before { transform:scaleX(1); }
    .product-card.oos { opacity:.85; }

    /* image area */
    .pc-img {
        position:relative;
        height:160px;
        background:var(--surface);
        border-radius:var(--r-md);
        margin-bottom:.9rem;
        display:flex; align-items:center; justify-content:center;
        overflow:hidden;
    }
    .pc-img img { max-width:100%; max-height:100%; object-fit:contain; }
    .pc-img .ph { font-size:2.8rem; color:var(--ink-3); }
    .pc-img.oos-overlay::after {
        content:''; position:absolute; inset:0;
        background:rgba(255,255,255,.45); border-radius:var(--r-md);
        pointer-events:none;
    }

    /* badges */
    .pc-badge {
        position:absolute;
        padding:3px 8px;
        border-radius:var(--r-full);
        font-size:.62rem; font-weight:700;
        letter-spacing:.02em;
        line-height:1;
        z-index:10;
    }
    .pc-badge.top-r { top:8px; right:8px; }
    .pc-badge.top-l { top:8px; left:8px; }
    .pc-badge.oos-b  { background:rgba(100,100,120,.18); color:#6b6b88; border:1px solid rgba(100,100,120,.2); }
    .pc-badge.low-b  { background:rgba(234,88,12,.1); color:#ea580c; border:1px solid rgba(234,88,12,.2); animation:pulse 2s infinite; }
    .pc-badge.disc-b { background:rgba(16,185,129,.12); color:#059669; border:1px solid rgba(16,185,129,.2); }
    @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.65} }

    /* info block */
    .pc-info { flex:1; display:flex; flex-direction:column; }
    .pc-brand { font-size:.68rem; font-weight:700; color:var(--ink-3); letter-spacing:.05em; text-transform:uppercase; margin-bottom:.25rem; }
    .pc-name {
        font-size:.855rem; font-weight:700; color:var(--ink);
        margin-bottom:.5rem; line-height:1.4;
        display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;
        min-height:2.4rem;
    }

    /* stars */
    .pc-rating { display:flex; align-items:center; gap:.35rem; margin-bottom:.55rem; }
    .pc-stars  { color:#f59e0b; font-size:.7rem; display:flex; gap:1px; }
    .pc-rtxt   { font-size:.7rem; color:var(--ink-3); font-weight:500; }

    /* spec tags */
    .pc-specs { display:flex; flex-wrap:wrap; gap:4px; margin-bottom:.65rem; min-height:24px; }
    .pc-spec {
        font-size:.62rem; font-weight:600;
        background:var(--surface); color:var(--ink-2);
        padding:2px 7px; border-radius:var(--r-full);
        border:1px solid rgba(0,0,0,.06);
    }

    /* stock pill */
    .pc-stock { display:inline-flex; align-items:center; gap:4px; padding:3px 8px; border-radius:var(--r-full); font-size:.68rem; font-weight:600; margin-bottom:.7rem; }
    .st-high   { background:rgba(16,185,129,.1);  color:#059669; }
    .st-medium { background:rgba(79,70,229,.1);   color:var(--accent); }
    .st-low    { background:rgba(234,88,12,.1);   color:#ea580c; }
    .st-out    { background:rgba(220,38,38,.08);  color:#dc2626; }

    /* price */
    .pc-price { margin-top:auto; margin-bottom:.85rem; display:flex; align-items:baseline; gap:.4rem; flex-wrap:wrap; }
    .pc-curr  { font-size:1.05rem; font-weight:800; color:var(--ink); }
    .pc-orig  { font-size:.78rem; color:var(--ink-3); text-decoration:line-through; }

    /* action buttons */
    .pc-actions { display:flex; flex-direction:column; gap:6px; }

    .btn-view {
        display:flex; align-items:center; justify-content:center; gap:6px;
        padding:.5rem; border-radius:var(--r-md);
        font-family:'Red Hat Display',sans-serif; font-size:.78rem; font-weight:600;
        background:var(--accent-soft); color:var(--accent);
        border:1px solid var(--accent-border);
        text-decoration:none;
        transition:background .15s, color .15s;
    }
    .btn-view:hover { background:var(--accent); color:#fff; text-decoration:none; }

    .btn-cart {
        display:flex; align-items:center; justify-content:center; gap:6px;
        padding:.5rem; border-radius:var(--r-md);
        font-family:'Red Hat Display',sans-serif; font-size:.78rem; font-weight:700;
        background:var(--accent); color:#fff;
        border:none; cursor:pointer;
        box-shadow:0 2px 10px rgba(79,70,229,.28);
        transition:background .15s, transform .15s, box-shadow .15s;
    }
    .btn-cart:hover:not(:disabled) {
        background:var(--accent-dark);
        transform:translateY(-1px);
        box-shadow:0 4px 16px rgba(79,70,229,.38);
    }
    .btn-cart:disabled {
        background:var(--surface); color: #fff;
        box-shadow:none; cursor:not-allowed;
        border:1px solid rgba(0,0,0,.08);
    }

    /* ══════════════ EMPTY STATE ══════════════ */
    .empty-state {
        text-align:center; padding:5rem 2rem;
    }
    .empty-icon {
        width:80px; height:80px;
        background:var(--accent-soft);
        border-radius:50%;
        display:flex; align-items:center; justify-content:center;
        margin:0 auto 1.5rem;
        font-size:1.8rem; color:var(--accent);
    }
    .empty-state h3 { font-weight:800; color:var(--ink); margin-bottom:.5rem; }
    .empty-state p  { color:var(--ink-3); margin-bottom:1.5rem; }

    /* ══════════════ PAGINATION ══════════════ */
    .pg-nav { display:flex; justify-content:center; gap:5px; margin-top:3rem; flex-wrap:wrap; }
    .pg-btn {
        min-width:38px; height:38px; padding:0 10px;
        border-radius:var(--r-sm);
        display:inline-flex; align-items:center; justify-content:center;
        font-family:'Red Hat Display',sans-serif; font-size:.84rem; font-weight:600;
        border:1px solid rgba(0,0,0,.09);
        background:var(--white); color:var(--ink-2);
        text-decoration:none;
        transition:background .15s, color .15s, border-color .15s;
    }
    .pg-btn:hover           { background:var(--accent-soft); color:var(--accent); border-color:var(--accent-border); text-decoration:none; }
    .pg-btn.active          { background:var(--accent); color:#fff; border-color:var(--accent); }
    .pg-btn.disabled        { opacity:.4; pointer-events:none; }
    .pg-btn.pg-dots         { pointer-events:none; border:none; background:transparent; color:var(--ink-3); }

    /* ══════════════ TOAST ══════════════ */
    .it-toast {
        position:fixed; top:90px; right:20px; z-index:9999;
        display:flex; align-items:flex-start; gap:10px;
        min-width:280px; max-width:360px;
        background:var(--white);
        border:1px solid rgba(0,0,0,.08);
        border-radius:var(--r-lg);
        box-shadow:0 8px 32px rgba(13,13,20,.14);
        padding:.9rem 1rem;
        animation:toastIn .25s ease;
        font-family:'Red Hat Display',sans-serif;
    }
    @keyframes toastIn { from{opacity:0;transform:translateX(20px)} to{opacity:1;transform:translateX(0)} }
    .it-toast .t-icon {
        width:32px; height:32px; border-radius:50%;
        display:flex; align-items:center; justify-content:center;
        flex-shrink:0; font-size:.85rem;
    }
    .it-toast .t-icon.s { background:rgba(16,185,129,.12); color:#059669; }
    .it-toast .t-icon.e { background:rgba(220,38,38,.1);   color:#dc2626; }
    .it-toast .t-icon.i { background:var(--accent-soft);   color:var(--accent); }
    .it-toast .t-body  { flex:1; font-size:.855rem; font-weight:500; color:var(--ink-2); line-height:1.4; padding-top:5px; }
    .it-toast .t-close {
        background:none; border:none; cursor:pointer;
        color:var(--ink-3); font-size:.8rem; padding:4px; line-height:1;
        flex-shrink:0; margin-top:2px;
        transition:color .15s;
    }
    .it-toast .t-close:hover { color:var(--ink); }

    /* ══════════════ LIST VIEW ADJUSTMENTS ══════════════ */
    #productsGrid.list-view .col-5th { flex:0 0 100%; max-width:100%; }
    #productsGrid.list-view .product-card { flex-direction:row; gap:1.25rem; }
    #productsGrid.list-view .pc-img { width:160px; height:140px; flex-shrink:0; margin-bottom:0; }
    #productsGrid.list-view .pc-info { flex:1; }
    #productsGrid.list-view .pc-actions { flex-direction:row; }
    #productsGrid.list-view .btn-view,
    #productsGrid.list-view .btn-cart { flex:1; }

    /* ══════════════ ANIMATE IN ══════════════ */
    .product-card { opacity:0; transform:translateY(20px); }
    .product-card.visible { opacity:1; transform:translateY(0); transition:opacity .5s ease, transform .5s ease, box-shadow .28s ease, transform .28s ease; }

    @media (max-width:768px) {
        .pg-hero { padding:2.5rem 0 2rem; }
        #productsGrid.list-view .product-card { flex-direction:column; }
        #productsGrid.list-view .pc-img { width:100%; height:180px; }
        .filter-bar { position:static; }
    }
</style>

<div class="products-page">

    <!-- ── Hero Header ── -->
    <div class="pg-hero">
        <div class="container inner">
            <div class="pg-breadcrumb">
                <a href="index.php">Home</a>
                <span>/</span>
                <em>Products</em>
                <?php if ($category && $category !== 'all'): ?>
                <span>/</span>
                <em><?php echo htmlspecialchars($categories[$category] ?? ucfirst($category)); ?></em>
                <?php endif; ?>
            </div>
            <h1 class="pg-title">
                <?php
                if ($category && $category !== 'all')
                    echo htmlspecialchars($categories[$category] ?? ucfirst($category));
                elseif ($search)
                    echo 'Search: "' . htmlspecialchars($search) . '"';
                else
                    echo 'All Products';
                ?>
            </h1>
            <p class="pg-sub">Premium electronics &amp; computer equipment</p>
        </div>
    </div>

    <!-- ── Filter Bar ── -->
    <div class="filter-bar">
        <div class="container">
            <form method="GET" action="products.php" id="filterForm">
                <div class="row g-2 align-items-center">

                    <div class="col-lg-3 col-md-6">
                        <div class="search-wrap">
                            <i class="fas fa-search si"></i>
                            <input type="text" name="search" class="form-control"
                                   placeholder="Search products…"
                                   value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                    </div>

                    <div class="col-lg-2 col-md-6">
                        <select name="category" class="form-select" onchange="this.form.submit()">
                            <?php foreach ($categories as $k => $v): ?>
                            <option value="<?php echo htmlspecialchars($k); ?>" <?= $category===$k?'selected':'' ?>>
                                <?php echo htmlspecialchars($v); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-lg-2 col-md-4">
                        <input type="number" name="price_min" class="form-control"
                               placeholder="Min price"
                               value="<?php echo htmlspecialchars($price_min); ?>">
                    </div>

                    <div class="col-lg-2 col-md-4">
                        <input type="number" name="price_max" class="form-control"
                               placeholder="Max price"
                               value="<?php echo htmlspecialchars($price_max); ?>">
                    </div>

                    <div class="col-lg-2 col-md-4">
                        <select name="sort" class="form-select" onchange="this.form.submit()">
                            <option value="name_asc"   <?= $sort==='name_asc'  ?'selected':'' ?>>Name A–Z</option>
                            <option value="price_low"  <?= $sort==='price_low' ?'selected':'' ?>>Price: Low → High</option>
                            <option value="price_high" <?= $sort==='price_high'?'selected':'' ?>>Price: High → Low</option>
                            <option value="rating"     <?= $sort==='rating'    ?'selected':'' ?>>Top Rated</option>
                        </select>
                    </div>

                    <div class="col-lg-1 col-md-12 d-flex gap-2">
                        <button type="submit" class="btn-filter-go" title="Search"><i class="fas fa-search"></i></button>
                        <a href="products.php" class="btn-filter-clear" title="Clear filters"><i class="fas fa-xmark"></i></a>
                    </div>

                </div>
            </form>
        </div>
    </div>

    <!-- ── Products Area ── -->
    <section class="products-section">
        <div class="container">

            <!-- Results bar -->
            <div class="results-bar">
                <div class="results-count">
                    Showing <em><?php echo count($filtered_products); ?></em> of <em><?php echo $total_products; ?></em> products
                    <?php if ($search): ?>
                    &nbsp;·&nbsp; <span style="color:var(--ink-3)">for "<?php echo htmlspecialchars($search); ?>"</span>
                    <?php endif; ?>
                </div>
                <div class="view-toggle">
                    <button class="vt-btn active" id="gridBtn" onclick="setView('grid')" title="Grid view"><i class="fas fa-th"></i></button>
                    <button class="vt-btn"        id="listBtn" onclick="setView('list')" title="List view"><i class="fas fa-list"></i></button>
                </div>
            </div>

            <?php if (empty($filtered_products)): ?>
            <!-- Empty state -->
            <div class="empty-state">
                <div class="empty-icon"><i class="fas fa-magnifying-glass"></i></div>
                <h3>No products found</h3>
                <p>Try adjusting your search or browse a different category</p>
                <a href="products.php" class="btn-cta" style="display:inline-flex;gap:8px;">
                    <i class="fas fa-arrow-left" style="font-size:.8rem"></i> View All Products
                </a>
            </div>

            <?php else: ?>
            <!-- 5-per-row Grid -->
            <div class="row g-3" id="productsGrid">
                <?php foreach ($filtered_products as $product):
                    $img      = ltrim($product['image'], '/');
                    $discount = ($product['original_price'] > $product['price'])
                        ? round((($product['original_price'] - $product['price']) / $product['original_price']) * 100)
                        : 0;
                ?>
                <div class="col-5th product-col">
                    <div class="product-card <?= !$product['in_stock']?'oos':'' ?>"
                         data-id="<?= $product['id'] ?>">

                        <!-- Image -->
                        <div class="pc-img <?= !$product['in_stock']?'oos-overlay':'' ?>">
                            <img src="<?php echo htmlspecialchars($img); ?>"
                                 alt="<?php echo htmlspecialchars($product['name']); ?>"
                                 onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                            <div class="ph" style="display:none"><i class="fas fa-laptop"></i></div>

                            <?php if (!$product['in_stock']): ?>
                                <span class="pc-badge oos-b top-r">Out of Stock</span>
                            <?php elseif ($product['stock_count'] <= 5): ?>
                                <span class="pc-badge low-b top-r">Only <?= $product['stock_count'] ?> left</span>
                            <?php endif; ?>

                            <?php if ($discount > 0): ?>
                                <span class="pc-badge disc-b top-l"><?= $discount ?>% OFF</span>
                            <?php endif; ?>
                        </div>

                        <!-- Info -->
                        <div class="pc-info">
                            <div class="pc-brand"><?php echo htmlspecialchars($product['brand']); ?></div>
                            <div class="pc-name"><?php echo htmlspecialchars($product['name']); ?></div>

                            <!-- Rating (commented out)
                            <div class="pc-rating">
                                <div class="pc-stars">
                                    <?php
                                    $r = $product['rating'];
                                    for ($i = 1; $i <= 5; $i++) {
                                        if ($i <= floor($r))    echo '<i class="fas fa-star"></i>';
                                        elseif ($i <= ceil($r)) echo '<i class="fas fa-star-half-alt"></i>';
                                        else                    echo '<i class="far fa-star"></i>';
                                    }
                                    ?>
                                </div>
                                <span class="pc-rtxt"><?= number_format($r,1) ?> (<?= $product['reviews'] ?>)</span>
                            </div>
                            -->

                            <!-- Stock -->
                            <div>
                                <?php if (!$product['in_stock']): ?>
                                    <span class="pc-stock st-out"><i class="fas fa-circle-xmark"></i> Out of Stock</span>
                                <?php else: ?>
                                    <span class="pc-stock st-high"><i class="fas fa-circle-check"></i> In Stock</span>
                                <?php endif; ?>
                            </div>

                            <!-- Price -->
                            <div class="pc-price">
                                <span class="pc-curr">LKR <?php echo number_format($product['price'], 2); ?></span>
                                <?php if ($discount > 0): ?>
                                <span class="pc-orig">LKR <?php echo number_format($product['original_price'], 2); ?></span>
                                <?php endif; ?>
                            </div>

                            <!-- Actions -->
                            <div class="pc-actions">
                                <a href="product-details.php?id=<?= $product['id'] ?>" class="btn-view">
                                    <i class="fas fa-eye" style="font-size:.72rem"></i> View Details
                                </a>
                                <?php if ($product['in_stock']): ?>
                                <button class="btn-cart"
                                        onclick="addToCart(<?= $product['id'] ?>)"
                                        data-product-id="<?= $product['id'] ?>"
                                        data-in-stock="true">
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

            <!-- Pagination -->
            <?php if ($total_pages > 1):
                $range = 2; $start = max(1,$page-$range); $end = min($total_pages,$page+$range);
            ?>
            <nav class="pg-nav" aria-label="Pagination">
                <a class="pg-btn <?= $page<=1?'disabled':'' ?>" href="?<?= buildQueryString($page-1) ?>">
                    <i class="fas fa-chevron-left"></i>
                </a>
                <?php if ($start > 1): ?>
                    <a class="pg-btn" href="?<?= buildQueryString(1) ?>">1</a>
                    <?php if ($start > 2): ?><span class="pg-btn pg-dots">…</span><?php endif; ?>
                <?php endif; ?>
                <?php for ($i=$start; $i<=$end; $i++): ?>
                    <a class="pg-btn <?= $i===$page?'active':'' ?>" href="?<?= buildQueryString($i) ?>"><?= $i ?></a>
                <?php endfor; ?>
                <?php if ($end < $total_pages): ?>
                    <?php if ($end < $total_pages-1): ?><span class="pg-btn pg-dots">…</span><?php endif; ?>
                    <a class="pg-btn" href="?<?= buildQueryString($total_pages) ?>"><?= $total_pages ?></a>
                <?php endif; ?>
                <a class="pg-btn <?= $page>=$total_pages?'disabled':'' ?>" href="?<?= buildQueryString($page+1) ?>">
                    <i class="fas fa-chevron-right"></i>
                </a>
            </nav>
            <?php endif; ?>
            <?php endif; ?>

        </div>
    </section>

</div><!-- /products-page -->

<?php
$extra_scripts = <<<'JS'
<script>
/* ── View toggle ── */
let curView = 'grid';
function setView(v) {
    if (v === curView) return;
    curView = v;
    const grid = document.getElementById('productsGrid');
    grid.classList.toggle('list-view', v === 'list');
    document.getElementById('gridBtn').classList.toggle('active', v === 'grid');
    document.getElementById('listBtn').classList.toggle('active', v === 'list');
}

/* ── Add to cart ── */
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
            btn.style.background = '#008716';
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

function updateCartUI(count, total) {
    let badge = document.querySelector('.bdot');
    const icon  = document.querySelector('.icon-btn');
    if (count > 0) {
        if (badge) { badge.textContent = count; }
        else if (icon) { const b = document.createElement('span'); b.className = 'bdot'; b.textContent = count; icon.appendChild(b); }
    } else if (badge) badge.remove();
    document.querySelectorAll('.cart-total').forEach(el => {
        if (total !== undefined) el.textContent = 'LKR ' + new Intl.NumberFormat().format(total);
    });
}

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

/* ── Scroll reveal ── */
document.addEventListener('DOMContentLoaded', () => {
    const io = new IntersectionObserver(entries => {
        entries.forEach((e, i) => {
            if (e.isIntersecting) {
                setTimeout(() => e.target.classList.add('visible'), i * 40);
                io.unobserve(e.target);
            }
        });
    }, { threshold: 0.05, rootMargin: '0px 0px -40px 0px' });

    document.querySelectorAll('.product-card').forEach(c => io.observe(c));

    /* price validation */
    const mn = document.querySelector('[name="price_min"]');
    const mx = document.querySelector('[name="price_max"]');
    if (mn && mx) {
        mn.addEventListener('change', () => { if (mx.value && +mn.value > +mx.value) { showToast('Min price can\'t exceed max', 'e'); mn.value = ''; } });
        mx.addEventListener('change', () => { if (mn.value && +mx.value < +mn.value) { showToast('Max price can\'t be less than min', 'e'); mx.value = ''; } });
    }
});
</script>
JS;

include 'footer.php';
?>