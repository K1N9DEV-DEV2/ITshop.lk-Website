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
$brand_filter   = $_GET['brand']     ?? '';
$rating_filter  = $_GET['rating']    ?? '';
$in_stock_only  = isset($_GET['in_stock']) && $_GET['in_stock'] === '1';
$page           = max(1, (int)($_GET['page'] ?? 1));
$items_per_page = 15;
$offset         = ($page - 1) * $items_per_page;

// ── Base WHERE builder ────────────────────────────────────────────────────────
function buildWhere($category, $search, $price_min, $price_max, $brand_filter, $rating_filter, $in_stock_only, &$params) {
    $sql = " WHERE 1=1";
    if ($category && $category !== 'all') { $sql .= " AND p.category = ?"; $params[] = $category; }
    if ($search) {
        $sql .= " AND (p.name LIKE ? OR p.brand LIKE ? OR p.category LIKE ?)";
        $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
    }
    if ($price_min !== '') { $sql .= " AND p.price >= ?"; $params[] = floatval($price_min); }
    if ($price_max !== '') { $sql .= " AND p.price <= ?"; $params[] = floatval($price_max); }
    if ($brand_filter)     { $sql .= " AND p.brand = ?"; $params[] = $brand_filter; }
    if ($rating_filter)    { $sql .= " AND p.rating >= ?"; $params[] = floatval($rating_filter); }
    if ($in_stock_only)    { $sql .= " AND p.stock_count > 0"; }
    return $sql;
}

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

// ── Brands ────────────────────────────────────────────────────────────────────
try {
    $stmt = $pdo->query("SELECT DISTINCT brand FROM products WHERE brand IS NOT NULL AND brand != '' ORDER BY brand");
    $db_brands = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) { $db_brands = []; }

// ── Price range ───────────────────────────────────────────────────────────────
try {
    $stmt = $pdo->query("SELECT MIN(price) as mn, MAX(price) as mx FROM products");
    $pr = $stmt->fetch(PDO::FETCH_ASSOC);
    $global_min = (int)floor($pr['mn'] ?? 0);
    $global_max = (int)ceil($pr['mx'] ?? 999999);
} catch (PDOException $e) { $global_min = 0; $global_max = 999999; }

// ── Count ─────────────────────────────────────────────────────────────────────
$count_params = [];
$count_where  = buildWhere($category, $search, $price_min, $price_max, $brand_filter, $rating_filter, $in_stock_only, $count_params);
$count_sql    = "SELECT COUNT(DISTINCT p.id) as total FROM products p" . $count_where;

try {
    $cs = $pdo->prepare($count_sql); $cs->execute($count_params);
    $total_products = $cs->fetch()['total'];
    $total_pages    = ceil($total_products / $items_per_page);
} catch (PDOException $e) { $total_products = 0; $total_pages = 1; }

// ── Main query ────────────────────────────────────────────────────────────────
$params = [];
$where  = buildWhere($category, $search, $price_min, $price_max, $brand_filter, $rating_filter, $in_stock_only, $params);

$sql = "SELECT p.id, p.name, p.category, p.price, p.original_price,
               p.image, p.brand, p.rating, p.reviews, p.stock_count,
               GROUP_CONCAT(ps.spec_name SEPARATOR '|') as specs
        FROM products p
        LEFT JOIN product_specs ps ON p.id = ps.product_id"
     . $where
     . " GROUP BY p.id";

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
        $raw = trim($product['image'] ?? '');
        if ($raw === '') {
            $product['image_src'] = '';
        } elseif (preg_match('#^https?://#i', $raw)) {
            $product['image_src'] = $raw;
        } else {
            $clean = preg_replace('#^(\.\./)+#', '', $raw);
            $clean = ltrim($clean, '/');
            if (!str_starts_with($clean, 'admin/')) $clean = 'admin/' . $clean;
            $product['image_src'] = $clean;
        }
    }
    unset($product);
} catch (PDOException $e) { $filtered_products = []; }

// ── Category counts for sidebar ───────────────────────────────────────────────
try {
    $stmt = $pdo->query("SELECT category, COUNT(*) as cnt FROM products GROUP BY category");
    $cat_counts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) { $cat_counts = []; }

// ── Active filter count ───────────────────────────────────────────────────────
$active_filters = 0;
if ($category && $category !== 'all') $active_filters++;
if ($search)           $active_filters++;
if ($price_min !== '') $active_filters++;
if ($price_max !== '') $active_filters++;
if ($brand_filter)     $active_filters++;
if ($rating_filter)    $active_filters++;
if ($in_stock_only)    $active_filters++;

function buildQueryString($p) { $q = $_GET; $q['page'] = $p; return http_build_query($q); }

// ── Category icon map ─────────────────────────────────────────────────────────
$cat_icons = [
    'all'          => 'fa-layer-group',
    'laptops'      => 'fa-laptop',
    'desktops'     => 'fa-desktop',
    'processors'   => 'fa-microchip',
    'memory'       => 'fa-memory',
    'storage'      => 'fa-hdd',
    'monitors'     => 'fa-display',
    'graphics'     => 'fa-gamepad',
    'motherboards' => 'fa-circuit-board',
    'power'        => 'fa-plug',
    'casings'      => 'fa-server',
    'cooling'      => 'fa-fan',
    'peripherals'  => 'fa-keyboard',
    'audio'        => 'fa-headphones',
];
?>

<style>
/* ══════════════════════ PAGE TOKENS ══════════════════════ */
.products-page { padding-top: 0px; }

/* ══════════════ PAGE HEADER ══════════════ */
.pg-hero {
    background: var(--ink);
    padding: 3rem 0 2.25rem;
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
    font-size: clamp(1.75rem, 3.5vw, 2.5rem);
    font-weight: 900;
    color: #fff;
    letter-spacing: -.025em;
    margin-bottom: .4rem;
}
.pg-sub { font-size:.9rem; color:rgba(255,255,255,.45); }

/* ══════════════ LAYOUT ══════════════ */
.products-layout {
    display: flex;
    gap: 1.5rem;
    padding: 1.5rem 0 5rem;
    align-items: flex-start;
}

/* ══════════════ SIDEBAR ══════════════ */
.filter-sidebar {
    width: 260px;
    flex-shrink: 0;
    position: sticky;
    top: 90px;
    max-height: calc(100vh - 110px);
    overflow-y: auto;
    scrollbar-width: thin;
    scrollbar-color: rgba(0,0,0,.12) transparent;
}
.filter-sidebar::-webkit-scrollbar { width: 4px; }
.filter-sidebar::-webkit-scrollbar-thumb { background: rgba(0,0,0,.12); border-radius: 4px; }

.sidebar-panel {
    background: var(--white);
    border-radius: var(--r-lg);
    box-shadow: 0 2px 12px rgba(10,10,15,.07), 0 0 0 1px rgba(10,10,15,.05);
    overflow: hidden;
}

.sidebar-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 1rem 1.1rem .85rem;
    border-bottom: 1px solid rgba(0,0,0,.06);
}
.sidebar-header h3 {
    font-family:'Red Hat Display',sans-serif;
    font-size:.9rem; font-weight:800;
    color:var(--ink); margin:0;
    display:flex; align-items:center; gap:7px;
}
.sidebar-header h3 i { color:var(--accent); font-size:.82rem; }
.filter-count-badge {
    background:var(--accent); color:#fff;
    border-radius:var(--r-full); font-size:.65rem;
    font-weight:700; padding:2px 7px; line-height:1.4;
    display:none;
}
.filter-count-badge.show { display:inline-block; }

.btn-clear-all {
    font-size:.75rem; font-weight:700;
    color:var(--accent); background:none; border:none; cursor:pointer;
    padding:0; text-decoration:none;
    transition:color .15s;
}
.btn-clear-all:hover { color:#dc2626; }

.filter-section {
    padding: .9rem 1.1rem;
    border-bottom: 1px solid rgba(0,0,0,.05);
}
.filter-section:last-child { border-bottom: none; }

.filter-section-title {
    font-family:'Red Hat Display',sans-serif;
    font-size:.72rem; font-weight:800;
    color:var(--ink-3); letter-spacing:.06em;
    text-transform:uppercase;
    margin-bottom:.7rem;
    display:flex; align-items:center; justify-content:space-between;
    cursor:pointer; user-select:none;
}
.filter-section-title .toggle-icon { transition:transform .2s; font-size:.65rem; }
.filter-section-title.collapsed .toggle-icon { transform:rotate(-90deg); }
.filter-section-body.hidden { display:none; }

.brand-list { display:flex; flex-direction:column; gap:4px; max-height:180px; overflow-y:auto; scrollbar-width:thin; }
.brand-check {
    display:flex; align-items:center; gap:8px;
    padding:.32rem .4rem; border-radius:var(--r-sm);
    cursor:pointer; transition:background .12s;
}
.brand-check:hover { background:var(--surface); }
.brand-check input[type="radio"] { accent-color:var(--accent); cursor:pointer; flex-shrink:0; }
.brand-check span { font-size:.8rem; font-weight:500; color:var(--ink-2); line-height:1.3; }

.price-inputs { display:flex; gap:6px; align-items:center; }
.price-inp {
    flex:1; min-width:0;
    font-family:'Red Hat Display',sans-serif;
    font-size:.8rem; font-weight:600;
    border:1px solid rgba(0,0,0,.1); border-radius:var(--r-sm);
    padding:.42rem .6rem; color:var(--ink); background:var(--surface);
    transition:border-color .15s, box-shadow .15s;
}
.price-inp:focus { border-color:var(--accent-border); box-shadow:0 0 0 2px rgba(79,70,229,.1); outline:none; background:var(--white); }
.price-sep { color:var(--ink-3); font-size:.78rem; font-weight:700; flex-shrink:0; }

.stock-toggle { display:flex; align-items:center; justify-content:space-between; padding:.1rem 0; }
.stock-toggle label { display:flex; align-items:center; gap:8px; cursor:pointer; font-size:.82rem; font-weight:600; color:var(--ink-2); }
.toggle-sw { position:relative; width:36px; height:20px; flex-shrink:0; }
.toggle-sw input { opacity:0; width:0; height:0; }
.toggle-track { position:absolute; inset:0; background:rgba(0,0,0,.12); border-radius:20px; transition:background .2s; cursor:pointer; }
.toggle-track::after { content:''; position:absolute; top:2px; left:2px; width:16px; height:16px; background:#fff; border-radius:50%; transition:transform .2s; box-shadow:0 1px 4px rgba(0,0,0,.2); }
.toggle-sw input:checked + .toggle-track { background:var(--accent); }
.toggle-sw input:checked + .toggle-track::after { transform:translateX(16px); }

.btn-apply-filters {
    display:flex; align-items:center; justify-content:center; gap:7px;
    width:100%; padding:.65rem;
    background:var(--accent); color:#fff;
    border:none; border-radius:var(--r-md);
    font-family:'Red Hat Display',sans-serif; font-size:.84rem; font-weight:700;
    cursor:pointer;
    box-shadow:0 2px 10px rgba(79,70,229,.28);
    transition:background .15s, transform .15s, box-shadow .15s;
}
.btn-apply-filters:hover { background:var(--accent-dark); transform:translateY(-1px); box-shadow:0 4px 16px rgba(79,70,229,.38); }

.btn-mobile-filter {
    display:none;
    align-items:center; justify-content:center; gap:6px;
    height:36px; padding:0 .85rem;
    background:var(--surface); color:var(--ink);
    border:1px solid rgba(0,0,0,.1); border-radius:var(--r-md);
    font-family:'Red Hat Display',sans-serif; font-size:.82rem; font-weight:700;
    cursor:pointer; white-space:nowrap; transition:background .15s;
    position:relative; flex-shrink:0;
}
.btn-mobile-filter:hover { background:var(--accent-soft); color:var(--accent); border-color:var(--accent-border); }
.btn-mobile-filter .filter-badge {
    position:absolute; top:-6px; right:-6px;
    width:18px; height:18px;
    background:var(--accent); color:#fff;
    border-radius:50%; font-size:.65rem; font-weight:700;
    display:flex; align-items:center; justify-content:center;
}

/* ══════════════ PRODUCT AREA ══════════════ */
.products-main { flex:1; min-width:0; }

.results-bar {
    display:flex; align-items:center; justify-content:space-between;
    flex-wrap:wrap; gap:.75rem; margin-bottom:1rem;
}
.results-count { font-size:.875rem; font-weight:600; color:var(--ink-2); }
.results-count em { color:var(--accent); font-style:normal; }

.sort-wrap { display:flex; align-items:center; gap:.5rem; }
.sort-wrap label { font-size:.78rem; font-weight:700; color:var(--ink-3); white-space:nowrap; }
.sort-select {
    font-family:'Red Hat Display',sans-serif; font-size:.82rem; font-weight:600;
    border:1px solid rgba(0,0,0,.1); border-radius:var(--r-sm);
    padding:.38rem .65rem; color:var(--ink); background:var(--surface); cursor:pointer;
    transition:border-color .15s;
}
.sort-select:focus { border-color:var(--accent-border); outline:none; }

.view-toggle { display:flex; gap:5px; }
.vt-btn {
    width:34px; height:34px; border-radius:var(--r-sm);
    border:1px solid rgba(0,0,0,.09); background:var(--surface); color:var(--ink-3);
    display:inline-flex; align-items:center; justify-content:center;
    font-size:.78rem; cursor:pointer;
    transition:background .15s, color .15s, border-color .15s;
}
.vt-btn.active, .vt-btn:hover { background:var(--accent-soft); color:var(--accent); border-color:var(--accent-border); }

.active-tags { display:flex; flex-wrap:wrap; gap:5px; margin-bottom:.85rem; }
.active-tag {
    display:inline-flex; align-items:center; gap:5px;
    background:var(--accent-soft); color:var(--accent);
    border:1px solid var(--accent-border); border-radius:var(--r-full);
    font-size:.72rem; font-weight:700; padding:3px 10px 3px 8px;
}
.active-tag .rm {
    background:none; border:none; cursor:pointer; color:var(--accent);
    font-size:.65rem; padding:0;
    display:flex; align-items:center; justify-content:center;
    width:14px; height:14px; border-radius:50%; transition:background .12s;
}
.active-tag .rm:hover { background:var(--accent); color:#fff; }

/* ══════════════ DESKTOP GRID: 4 cols ══════════════ */
#productsGrid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
}
@media (max-width:1200px) { #productsGrid { grid-template-columns: repeat(3, 1fr); } }
@media (max-width:900px)  { #productsGrid { grid-template-columns: repeat(2, 1fr); gap: 10px; } }
@media (max-width:400px)  { #productsGrid { gap: 8px; } }

/* ══════════════ PRODUCT CARD ══════════════ */
.product-card {
    background:var(--white);
    border-radius:var(--r-lg);
    padding:1rem;
    display:flex; flex-direction:column;
    box-shadow: 0 2px 12px rgba(10,10,15,.07), 0 0 0 1px rgba(10,10,15,.05);
    height:100%;
    transition:transform .28s ease, box-shadow .28s ease;
    position:relative; overflow:hidden;
}
.product-card::before {
    content:''; position:absolute; top:0; left:0; right:0; height:3px;
    background:linear-gradient(90deg, var(--accent), #34d399);
    transform:scaleX(0); transform-origin:left;
    transition:transform .3s ease;
    border-radius:var(--r-lg) var(--r-lg) 0 0;
}
.product-card:hover { transform:translateY(-5px); box-shadow:0 16px 40px rgba(10,10,15,.13), 0 0 0 1px rgba(79,70,229,.1); }
.product-card:hover::before { transform:scaleX(1); }
.product-card.oos { opacity:.85; }

/* ── Mobile card: no padding on shell, no hover lift ── */
@media (max-width:900px) {
    .product-card { padding:0; }
    .product-card:hover { transform:none; box-shadow:0 2px 12px rgba(10,10,15,.07), 0 0 0 1px rgba(10,10,15,.05); }
    .product-card:active { transform:scale(.97); transition:transform .12s; }
}

/* ── Image ── */
.pc-img {
    position:relative; height:150px;
    background:var(--surface);
    border-radius:var(--r-md);
    margin-bottom:.85rem;
    display:flex; align-items:center; justify-content:center;
    overflow:hidden;
}
@media (max-width:900px) {
    .pc-img {
        height:120px;
        border-radius:var(--r-lg) var(--r-lg) 0 0;
        margin-bottom:0;
    }
}
.pc-img img {
    max-width:100%; max-height:100%; object-fit:contain;
    transition: transform .3s ease;
}
.product-card:hover .pc-img img { transform:scale(1.05); }
.pc-img .ph { font-size:2.5rem; color:var(--ink-3); }
.pc-img.oos-overlay::after {
    content:''; position:absolute; inset:0;
    background:rgba(255,255,255,.45); pointer-events:none;
}

/* Badges */
.pc-badge {
    position:absolute; padding:3px 8px;
    border-radius:var(--r-full);
    font-size:.62rem; font-weight:700;
    letter-spacing:.02em; line-height:1; z-index:10;
}
.pc-badge.top-r { top:8px; right:8px; }
.pc-badge.top-l { top:8px; left:8px; }
.pc-badge.oos-b  { background:rgba(100,100,120,.18); color:#6b6b88; border:1px solid rgba(100,100,120,.2); }
.pc-badge.low-b  { background:rgba(234,88,12,.1); color:#ea580c; border:1px solid rgba(234,88,12,.2); animation:pulse 2s infinite; }
.pc-badge.disc-b { background:rgba(16,185,129,.12); color:#059669; border:1px solid rgba(16,185,129,.2); }
@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.65} }
@media (max-width:900px) { .pc-badge { font-size:.56rem; padding:2px 6px; } }

/* Wishlist – mobile only */
.wish-btn {
    position:absolute; bottom:7px; right:7px;
    width:26px; height:26px;
    background:rgba(255,255,255,.88); border:none; border-radius:50%;
    display:none; align-items:center; justify-content:center;
    font-size:.65rem; color:var(--ink-3);
    cursor:pointer; z-index:10;
    box-shadow:0 1px 4px rgba(0,0,0,.15);
    transition:color .15s, background .15s;
    backdrop-filter:blur(4px);
}
.wish-btn:hover, .wish-btn.active { color:#ef4444; background:#fff3f3; }
@media (max-width:900px) { .wish-btn { display:flex; } }

/* Card body */
.pc-info { flex:1; display:flex; flex-direction:column; }
@media (max-width:900px) { .pc-info { padding:8px 9px 10px; } }

.pc-brand { font-size:.65rem; font-weight:700; color:var(--ink-3); letter-spacing:.05em; text-transform:uppercase; margin-bottom:.22rem; }
.pc-name {
    font-size:.82rem; font-weight:700; color:var(--ink);
    margin-bottom:.45rem; line-height:1.4;
    display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;
    min-height:2.3rem;
}
@media (max-width:900px) {
    .pc-brand { font-size:.58rem; }
    .pc-name  { font-size:.75rem; margin-bottom:.3rem; min-height:2.1em; }
}

/* Stars – mobile only */
.pc-stars { display:none; align-items:center; gap:3px; margin-bottom:5px; }
.stars { display:flex; gap:1px; }
.stars i { font-size:.52rem; color:#fbbf24; }
@media (max-width:900px) { .pc-stars { display:flex; } }

.pc-stock { display:inline-flex; align-items:center; gap:4px; padding:3px 8px; border-radius:var(--r-full); font-size:.65rem; font-weight:600; margin-bottom:.65rem; }
.st-high { background:rgba(16,185,129,.1);  color:#059669; }
.st-out  { background:rgba(220,38,38,.08);  color:#dc2626; }
.st-low  { background:rgba(234,88,12,.1);   color:#ea580c; }
@media (max-width:900px) { .pc-stock { font-size:.58rem; padding:2px 7px; margin-bottom:.45rem; } }

.pc-price { margin-top:auto; margin-bottom:.8rem; display:flex; align-items:baseline; gap:.4rem; flex-wrap:wrap; }
.pc-curr  { font-size:1rem; font-weight:800; color:var(--ink); }
.pc-orig  { font-size:.75rem; color:var(--ink-3); text-decoration:line-through; }
@media (max-width:900px) {
    .pc-price { margin-bottom:.6rem; }
    .pc-curr  { font-size:.88rem; }
    .pc-orig  { font-size:.65rem; }
}

/* Actions */
.pc-actions { display:flex; flex-direction:column; gap:5px; }
@media (max-width:900px) { .pc-actions { flex-direction:row; gap:5px; } }

.btn-view {
    display:flex; align-items:center; justify-content:center; gap:5px;
    padding:.45rem; border-radius:var(--r-sm);
    font-family:'Red Hat Display',sans-serif; font-size:.75rem; font-weight:600;
    background:var(--accent-soft); color:var(--accent);
    border:1px solid var(--accent-border); text-decoration:none;
    transition:background .15s, color .15s;
}
.btn-view:hover { background:var(--accent); color:#fff; text-decoration:none; }
@media (max-width:900px) {
    .btn-view { flex:1; font-size:.67rem; padding:.38rem .3rem; gap:3px; }
}

.btn-cart {
    display:flex; align-items:center; justify-content:center; gap:5px;
    padding:.45rem; border-radius:var(--r-sm);
    font-family:'Red Hat Display',sans-serif; font-size:.75rem; font-weight:700;
    background:var(--accent); color:#fff; border:none; cursor:pointer;
    box-shadow:0 2px 10px rgba(79,70,229,.28);
    transition:background .15s, transform .15s, box-shadow .15s;
}
.btn-cart:hover:not(:disabled) { background:var(--accent-dark); transform:translateY(-1px); box-shadow:0 4px 16px rgba(79,70,229,.38); }
.btn-cart:disabled { background:#7e7e7e; box-shadow:none; cursor:not-allowed; border:1px solid rgba(0,0,0,.08); }
/* Mobile: icon-square */
@media (max-width:900px) {
    .btn-cart { width:32px; height:32px; flex-shrink:0; padding:0; }
    .btn-cart-text { display:none; }
}

/* ══════════════ LIST VIEW ══════════════ */
#productsGrid.list-view { grid-template-columns: 1fr; }
#productsGrid.list-view .product-card { flex-direction:row; gap:1.1rem; padding:1rem; }
#productsGrid.list-view .pc-img { width:140px; height:120px; flex-shrink:0; margin-bottom:0; border-radius:var(--r-md); }
#productsGrid.list-view .pc-info { flex:1; padding:0; }
#productsGrid.list-view .pc-actions { flex-direction:row; }
#productsGrid.list-view .btn-view,
#productsGrid.list-view .btn-cart { flex:1; width:auto; height:auto; padding:.45rem; }
#productsGrid.list-view .btn-cart-text { display:inline; }

/* ══════════════ MOBILE CATEGORY STRIP ══════════════ */
.mobile-cat-strip {
    display:none;
    background:var(--white);
    padding:10px 14px;
    border-bottom:1px solid rgba(0,0,0,.05);
    overflow-x:auto; scrollbar-width:none;
    gap:8px; -webkit-overflow-scrolling:touch;
}
.mobile-cat-strip::-webkit-scrollbar { display:none; }
@media (max-width:900px) { .mobile-cat-strip { display:flex; } }

.cat-chip { flex-shrink:0; display:flex; flex-direction:column; align-items:center; gap:4px; cursor:pointer; text-decoration:none; }
.cat-icon-wrap {
    width:46px; height:46px; border-radius:var(--r-md);
    display:flex; align-items:center; justify-content:center;
    font-size:.88rem; background:var(--surface); color:var(--ink-3);
    border:1.5px solid transparent; transition:all .15s;
}
.cat-chip.active .cat-icon-wrap, .cat-chip:hover .cat-icon-wrap {
    background:var(--accent-soft); color:var(--accent); border-color:var(--accent-border);
}
.cat-chip span { font-size:.56rem; font-weight:700; color:var(--ink-3); white-space:nowrap; }
.cat-chip.active span { color:var(--accent); }

/* ══════════════ MOBILE FILTER PILLS ══════════════ */
.mobile-filter-pills {
    display:none;
    background:var(--white);
    padding:7px 14px;
    border-bottom:1px solid rgba(0,0,0,.05);
    overflow-x:auto; scrollbar-width:none; gap:6px; -webkit-overflow-scrolling:touch;
}
.mobile-filter-pills::-webkit-scrollbar { display:none; }
@media (max-width:900px) { .mobile-filter-pills { display:flex; } }

.m-fpill {
    flex-shrink:0; height:28px; padding:0 10px; border-radius:var(--r-full);
    font-size:.67rem; font-weight:700;
    border:1px solid rgba(0,0,0,.1); background:var(--surface); color:var(--ink-2);
    cursor:pointer; display:flex; align-items:center; gap:5px; white-space:nowrap;
    text-decoration:none; transition:all .15s;
}
.m-fpill.active, .m-fpill:hover { background:var(--accent-soft); color:var(--accent); border-color:var(--accent-border); }
.m-fpill i { font-size:.6rem; }

/* ══════════════ EMPTY STATE ══════════════ */
.empty-state { text-align:center; padding:5rem 2rem; }
.empty-icon { width:80px; height:80px; background:var(--accent-soft); border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 1.5rem; font-size:1.8rem; color:var(--accent); }
.empty-state h3 { font-weight:800; color:var(--ink); margin-bottom:.5rem; }
.empty-state p  { color:var(--ink-3); margin-bottom:1.5rem; }

/* ══════════════ PAGINATION ══════════════ */
.pg-nav { display:flex; justify-content:center; gap:5px; margin-top:2.5rem; flex-wrap:wrap; }
.pg-btn { min-width:36px; height:36px; padding:0 9px; border-radius:var(--r-sm); display:inline-flex; align-items:center; justify-content:center; font-family:'Red Hat Display',sans-serif; font-size:.82rem; font-weight:600; border:1px solid rgba(0,0,0,.09); background:var(--white); color:var(--ink-2); text-decoration:none; transition:background .15s, color .15s, border-color .15s; }
.pg-btn:hover           { background:var(--accent-soft); color:var(--accent); border-color:var(--accent-border); text-decoration:none; }
.pg-btn.active          { background:var(--accent); color:#fff; border-color:var(--accent); }
.pg-btn.disabled        { opacity:.4; pointer-events:none; }
.pg-btn.pg-dots         { pointer-events:none; border:none; background:transparent; color:var(--ink-3); }

/* ══════════════ TOAST ══════════════ */
.it-toast { position:fixed; top:90px; right:20px; z-index:9999; display:flex; align-items:flex-start; gap:10px; min-width:280px; max-width:360px; background:var(--white); border:1px solid rgba(0,0,0,.08); border-radius:var(--r-lg); box-shadow:0 8px 32px rgba(13,13,20,.14); padding:.9rem 1rem; animation:toastIn .25s ease; font-family:'Red Hat Display',sans-serif; }
@keyframes toastIn { from{opacity:0;transform:translateX(20px)} to{opacity:1;transform:translateX(0)} }
@media (max-width:900px) { .it-toast { left:12px; right:12px; min-width:0; max-width:none; top:70px; animation:toastInMobile .25s ease; } }
@keyframes toastInMobile { from{opacity:0;transform:translateY(-10px)} to{opacity:1;transform:translateY(0)} }
.it-toast .t-icon { width:32px; height:32px; border-radius:50%; display:flex; align-items:center; justify-content:center; flex-shrink:0; font-size:.85rem; }
.it-toast .t-icon.s { background:rgba(16,185,129,.12); color:#059669; }
.it-toast .t-icon.e { background:rgba(220,38,38,.1);   color:#dc2626; }
.it-toast .t-icon.i { background:var(--accent-soft);   color:var(--accent); }
.it-toast .t-body  { flex:1; font-size:.855rem; font-weight:500; color:var(--ink-2); line-height:1.4; padding-top:5px; }
.it-toast .t-close { background:none; border:none; cursor:pointer; color:var(--ink-3); font-size:.8rem; padding:4px; line-height:1; flex-shrink:0; margin-top:2px; transition:color .15s; }
.it-toast .t-close:hover { color:var(--ink); }

/* ══════════════ MOBILE DRAWER ══════════════ */
.sidebar-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:900; backdrop-filter:blur(2px); }
.sidebar-overlay.open { display:block; }

/* ── CART FAB ── */
.cart-fab {
    position:fixed; bottom:89px; right:24px;
    width:56px; height:56px;
    background:var(--accent); color:#fff;
    border-radius:50%;
    display:none; align-items:center; justify-content:center;
    font-size:1.3rem; z-index:9999; text-decoration:none;
    box-shadow:0 6px 20px rgba(79,70,229,.45);
    transition:transform .25s ease, box-shadow .25s ease;
}
.cart-fab:hover { transform:scale(1.1); box-shadow:0 10px 30px rgba(79,70,229,.55); color:#fff; text-decoration:none; }
.cart-fab .cart-fab-badge {
    position:absolute; top:8px; right:1px;
    min-width:18px; height:18px;
    background:#fff; color:var(--accent);
    font-size:.6rem; font-weight:800; border-radius:999px;
    display:flex; align-items:center; justify-content:center;
    padding:0 3px; border:2px solid var(--accent);
}

/* ── BOTTOM NAV ── */
.bottom-nav {
    position:fixed; bottom:0; left:0; right:0;
    height:62px; background:var(--white);
    border-top:1px solid rgba(0,0,0,.08);
    display:none; z-index:200;
    box-shadow:0 -4px 20px rgba(0,0,0,.07);
}
.bnav-item {
    flex:1; display:flex; flex-direction:column; align-items:center; justify-content:center;
    gap:3px; cursor:pointer; color:var(--ink-3);
    font-size:.58rem; font-weight:700;
    transition:color .15s; text-decoration:none; position:relative;
}
.bnav-item i { font-size:.95rem; }
.bnav-item.active { color:var(--accent); }
.bnav-item.active::before {
    content:''; position:absolute; top:0; left:50%; transform:translateX(-50%);
    width:28px; height:2px; background:var(--accent); border-radius:0 0 4px 4px;
}
.bnav-cart-dot {
    position:absolute; top:9px; right:calc(50% - 14px);
    width:7px; height:7px; background:#ef4444; border-radius:50%;
    border:1.5px solid var(--white); display:none;
}
.bnav-cart-dot.show { display:block; }

@media (max-width:900px) {
    .btn-mobile-filter { display:inline-flex; }
    .products-layout { padding-top:1rem; padding-bottom:80px; }
    .filter-sidebar {
        position:fixed; top:0; left:-290px; bottom:0;
        width:280px; z-index:910; background:var(--white);
        max-height:100vh;
        border-radius:0 var(--r-lg) var(--r-lg) 0;
        box-shadow:4px 0 30px rgba(0,0,0,.2);
        transition:left .28s cubic-bezier(.4,0,.2,1);
    }
    .filter-sidebar.open { left:0; }
    .filter-sidebar .sidebar-panel { border-radius:0; box-shadow:none; height:100%; }
    .cart-fab  { display:flex; }
    .bottom-nav { display:flex; }
    .pg-hero { padding:1.5rem 0 1.25rem; }
    .pg-title { font-size:1.4rem; }
}

/* ══════════════ ANIMATE IN ══════════════ */
.product-card { opacity:0; transform:translateY(18px); }
.product-card.visible { opacity:1; transform:translateY(0); transition:opacity .45s ease, transform .45s ease, box-shadow .28s ease; }
</style>

<div class="products-page">

<!-- ── Hero ── -->
<div class="pg-hero">
    <div class="container inner">
        <div class="pg-breadcrumb">
            <a href="index.php">Home</a><span>/</span><em>Products</em>
            <?php if ($category && $category !== 'all'): ?>
            <span>/</span><em><?php echo htmlspecialchars($categories[$category] ?? ucfirst($category)); ?></em>
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

<!-- ── Mobile: Category strip ── 
<div class="mobile-cat-strip">
    <?php
    $all_strip_cats = array_merge(['all' => 'All'], $categories);
    foreach ($all_strip_cats as $key => $label):
        if ($key === 'all' && isset($all_strip_cats['all']) && $label === 'All Categories') $label = 'All';
        $icon = $cat_icons[$key] ?? 'fa-tag';
        $is_active = ($key === 'all' && (!$category || $category === 'all')) || $key === $category;
        $url = ($key === 'all') ? 'products.php' : '?' . http_build_query(array_merge($_GET, ['category' => $key, 'page' => 1]));
    ?>
    <a href="<?= $url ?>" class="cat-chip <?= $is_active ? 'active' : '' ?>">
        <div class="cat-icon-wrap"><i class="fas <?= $icon ?>"></i></div>
        <span><?= htmlspecialchars($key === 'all' ? 'All' : ucfirst($key)) ?></span>
    </a>
    <?php endforeach; ?>
</div>-->

<!-- ── Mobile: Quick filter pills ── 
<div class="mobile-filter-pills">
    <a href="?<?= http_build_query(array_merge($_GET, ['in_stock' => $in_stock_only ? '' : '1', 'page' => 1])) ?>"
       class="m-fpill <?= $in_stock_only ? 'active' : '' ?>">
        <i class="fas fa-circle-check"></i> In Stock
        <?php if ($in_stock_only): ?><i class="fas fa-xmark"></i><?php endif; ?>
    </a>
    <?php
    $mob_presets = ['Under 50K' => ['','50000'], '50K–100K' => ['50000','100000'], 'Over 100K' => ['100000','']];
    foreach ($mob_presets as $lbl => [$mn, $mx]):
        $pa = ($price_min == $mn && $price_max == $mx);
    ?>
    <a href="?<?= http_build_query(array_merge($_GET, ['price_min'=>$mn,'price_max'=>$mx,'page'=>1])) ?>"
       class="m-fpill <?= $pa ? 'active' : '' ?>">
        <?= $lbl ?><?php if ($pa): ?> <i class="fas fa-xmark"></i><?php endif; ?>
    </a>
    <?php endforeach; ?>
    <a href="?<?= http_build_query(array_merge($_GET, ['sort'=>'rating','page'=>1])) ?>"
       class="m-fpill <?= $sort==='rating' ? 'active' : '' ?>">
        <i class="fas fa-star"></i> Top Rated
    </a>
    <?php if ($active_filters > 0): ?>
    <a href="products.php" class="m-fpill active">
        <i class="fas fa-rotate-left"></i> Clear All
    </a>
    <?php endif; ?>
</div>-->

<!-- ── Mobile overlay ── -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- ── Main Layout ── -->
<div class="container">
<div class="products-layout">

    <!-- ════ SIDEBAR ════ -->
    <aside class="filter-sidebar" id="filterSidebar">
        <div class="sidebar-panel">

            <div class="sidebar-header">
                <h3><i class="fas fa-sliders"></i> Filters
                    <span class="filter-count-badge <?= $active_filters > 0 ? 'show' : '' ?>"><?= $active_filters ?></span>
                </h3>
                <?php if ($active_filters > 0): ?>
                <a href="products.php" class="btn-clear-all"><i class="fas fa-rotate-left"></i> Clear all</a>
                <?php endif; ?>
            </div>

            <form method="GET" action="products.php" id="sidebarForm">
                <?php if ($search): ?><input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>"><?php endif; ?>
                <?php if ($sort !== 'name_asc'): ?><input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>"><?php endif; ?>

                <div class="filter-section">
                    <div class="filter-section-title" data-target="sec-price">
                        Price Range <i class="fas fa-chevron-down toggle-icon"></i>
                    </div>
                    <div class="filter-section-body" id="sec-price">
                        <div class="price-inputs">
                            <input type="number" name="price_min" class="price-inp"
                                   placeholder="Min" min="<?= $global_min ?>" max="<?= $global_max ?>"
                                   value="<?= htmlspecialchars($price_min) ?>">
                            <span class="price-sep">—</span>
                            <input type="number" name="price_max" class="price-inp"
                                   placeholder="Max" min="<?= $global_min ?>" max="<?= $global_max ?>"
                                   value="<?= htmlspecialchars($price_max) ?>">
                        </div>
                        <div style="display:flex;flex-wrap:wrap;gap:4px;margin-top:.6rem;">
                            <?php
                            $presets = ['Under 50K'=>['',50000],'50K–100K'=>[50000,100000],'100K–200K'=>[100000,200000],'Over 200K'=>[200000,'']];
                            foreach ($presets as $label => [$mn, $mx]):
                                $is_active = ($price_min == $mn && $price_max == $mx);
                            ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['price_min'=>$mn,'price_max'=>$mx,'page'=>1])) ?>"
                               style="font-size:.68rem;font-weight:700;padding:3px 8px;border-radius:var(--r-full);text-decoration:none;border:1px solid;
                               <?= $is_active ? 'background:var(--accent);color:#fff;border-color:var(--accent);' : 'background:var(--surface);color:var(--ink-2);border-color:rgba(0,0,0,.1);' ?>
                               transition:all .12s;"><?= $label ?></a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="filter-section">
                    <div class="stock-toggle">
                        <label>
                            <span class="toggle-sw">
                                <input type="checkbox" name="in_stock" value="1"
                                       <?= $in_stock_only ? 'checked' : '' ?>
                                       onchange="document.getElementById('sidebarForm').submit()">
                                <span class="toggle-track"></span>
                            </span>
                            In Stock Only
                        </label>
                    </div>
                </div>

                <?php if (!empty($db_brands)): ?>
                <div class="filter-section">
                    <div class="filter-section-title" data-target="sec-brands">
                        Brand <i class="fas fa-chevron-down toggle-icon"></i>
                    </div>
                    <div class="filter-section-body" id="sec-brands">
                        <div class="brand-list">
                            <?php foreach ($db_brands as $b): ?>
                            <label class="brand-check">
                                <input type="radio" name="brand" value="<?= htmlspecialchars($b) ?>"
                                       <?= $brand_filter === $b ? 'checked' : '' ?>
                                       onchange="document.getElementById('sidebarForm').submit()">
                                <span><?= htmlspecialchars($b) ?></span>
                            </label>
                            <?php endforeach; ?>
                            <?php if ($brand_filter): ?>
                            <label class="brand-check" style="padding-top:6px;border-top:1px solid rgba(0,0,0,.06);margin-top:4px;">
                                <input type="radio" name="brand" value=""
                                       onchange="document.getElementById('sidebarForm').submit()">
                                <span style="color:var(--accent);">Clear brand</span>
                            </label>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="filter-section">
                    <button type="submit" class="btn-apply-filters">
                        <i class="fas fa-check"></i> Apply Filters
                    </button>
                </div>

                <?php if ($category && $category !== 'all'): ?>
                <input type="hidden" name="category" value="<?= htmlspecialchars($category) ?>">
                <?php endif; ?>
            </form>

        </div>
    </aside>

    <!-- ════ PRODUCTS MAIN ════ -->
    <div class="products-main">

        <!-- Active filter tags -->
        <?php
        $tag_list = [];
        if ($search)                        $tag_list[] = ['label'=>'Search: '.$search,                    'remove'=>array_merge($_GET,['search'=>'','page'=>1])];
        if ($category && $category!=='all') $tag_list[] = ['label'=>$categories[$category]??$category,    'remove'=>array_merge($_GET,['category'=>'all','page'=>1])];
        if ($brand_filter)                  $tag_list[] = ['label'=>'Brand: '.$brand_filter,              'remove'=>array_merge($_GET,['brand'=>'','page'=>1])];
        if ($rating_filter)                 $tag_list[] = ['label'=>'Rating ≥ '.$rating_filter.'★',       'remove'=>array_merge($_GET,['rating'=>'','page'=>1])];
        if ($price_min !== '')              $tag_list[] = ['label'=>'Min LKR '.number_format($price_min), 'remove'=>array_merge($_GET,['price_min'=>'','page'=>1])];
        if ($price_max !== '')              $tag_list[] = ['label'=>'Max LKR '.number_format($price_max), 'remove'=>array_merge($_GET,['price_max'=>'','page'=>1])];
        if ($in_stock_only)                 $tag_list[] = ['label'=>'In Stock Only',                      'remove'=>array_merge($_GET,['in_stock'=>'','page'=>1])];
        ?>
        <?php if (!empty($tag_list)): ?>
        <div class="active-tags">
            <?php foreach ($tag_list as $tag): ?>
            <span class="active-tag">
                <?= htmlspecialchars($tag['label']) ?>
                <a href="?<?= http_build_query($tag['remove']) ?>" class="rm" title="Remove"><i class="fas fa-xmark"></i></a>
            </span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Results / sort bar -->
        <div class="results-bar">
            <div class="d-flex align-items-center gap-2">
                <button type="button" class="btn-mobile-filter" id="mobileFilterBtn">
                    <i class="fas fa-sliders"></i> Filters
                    <?php if ($active_filters > 0): ?>
                    <span class="filter-badge"><?= $active_filters ?></span>
                    <?php endif; ?>
                </button>
                <div class="results-count">
                    Showing <em><?= count($filtered_products) ?></em> of <em><?= $total_products ?></em> products
                </div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <?php if ($active_filters > 0): ?>
                <a href="products.php" style="display:inline-flex;align-items:center;justify-content:center;height:34px;padding:0 .75rem;background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;border-radius:var(--r-md);font-size:.78rem;font-weight:700;text-decoration:none;gap:5px;white-space:nowrap;" onmouseover="this.style.background='#fecaca'" onmouseout="this.style.background='#fee2e2'">
                    <i class="fas fa-xmark"></i> Clear
                </a>
                <?php endif; ?>
                <div class="sort-wrap">
                    <label>Sort:</label>
                    <select class="sort-select" onchange="window.location='?<?= htmlspecialchars(http_build_query(array_merge($_GET,['sort'=>'__SORT__','page'=>1]))) ?>'.replace('__SORT__',this.value)">
                        <option value="name_asc"   <?= $sort==='name_asc'  ?'selected':''?>>Name A–Z</option>
                        <option value="price_low"  <?= $sort==='price_low' ?'selected':''?>>Price ↑</option>
                        <option value="price_high" <?= $sort==='price_high'?'selected':''?>>Price ↓</option>
                        <option value="rating"     <?= $sort==='rating'    ?'selected':''?>>Top Rated</option>
                    </select>
                </div>
                <div class="view-toggle">
                    <button class="vt-btn active" id="gridBtn" onclick="setView('grid')" title="Grid"><i class="fas fa-th"></i></button>
                    <button class="vt-btn"        id="listBtn" onclick="setView('list')" title="List"><i class="fas fa-list"></i></button>
                </div>
            </div>
        </div>

        <?php if (empty($filtered_products)): ?>
        <div class="empty-state">
            <div class="empty-icon"><i class="fas fa-magnifying-glass"></i></div>
            <h3>No products found</h3>
            <p>Try adjusting your filters or browse a different category</p>
            <a href="products.php" class="btn-cta" style="display:inline-flex;gap:8px;">
                <i class="fas fa-arrow-left" style="font-size:.8rem"></i> View All Products
            </a>
        </div>

        <?php else: ?>
        <div id="productsGrid">
            <?php foreach ($filtered_products as $product):
                $discount = ($product['original_price'] > $product['price'])
                    ? round((($product['original_price'] - $product['price']) / $product['original_price']) * 100) : 0;
                $img_src  = htmlspecialchars($product['image_src']);
                $has_img  = $img_src !== '';
                $rating   = floatval($product['rating'] ?? 0);
                $full_s   = floor($rating);
                $half_s   = ($rating - $full_s) >= 0.5;
                $empty_s  = 5 - $full_s - ($half_s ? 1 : 0);
            ?>
            <div class="product-card <?= !$product['in_stock']?'oos':'' ?>" data-id="<?= $product['id'] ?>">

                <div class="pc-img <?= !$product['in_stock']?'oos-overlay':'' ?>">
                    <?php if ($has_img): ?>
                    <img src="<?= $img_src ?>" alt="<?= htmlspecialchars($product['name']) ?>" loading="lazy"
                         onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                    <?php endif; ?>
                    <div class="ph" <?= $has_img?'style="display:none"':'' ?>><i class="fas fa-laptop"></i></div>

                    <?php if (!$product['in_stock']): ?>
                        <span class="pc-badge oos-b top-r">Out of Stock</span>
                    <?php elseif ($product['stock_count'] <= 5): ?>
                        <span class="pc-badge low-b top-r">Only <?= (int)$product['stock_count'] ?> left</span>
                    <?php endif; ?>
                    <?php if ($discount > 0): ?>
                        <span class="pc-badge disc-b top-l"><?= $discount ?>% OFF</span>
                    <?php endif; ?>

                    <!--<button class="wish-btn" onclick="toggleWish(this)" aria-label="Wishlist">
                        <i class="far fa-heart"></i>
                    </button>-->
                </div>

                <div class="pc-info">
                    <div class="pc-brand"><?= htmlspecialchars($product['brand'] ?? '') ?></div>
                    <div class="pc-name"><?= htmlspecialchars($product['name']) ?></div>

                    <?php if ($rating > 0): ?>
                    <!--<div class="pc-stars">
                        <div class="stars">
                            <?php for ($s=0;$s<$full_s;$s++): ?><i class="fas fa-star"></i><?php endfor; ?>
                            <?php if ($half_s): ?><i class="fas fa-star-half-alt"></i><?php endif; ?>
                            <?php for ($s=0;$s<$empty_s;$s++): ?><i class="far fa-star"></i><?php endfor; ?>
                        </div>
                        <?php if ($product['reviews']): ?>
                        <span style="font-size:.6rem;font-weight:600;color:var(--ink-3);">(<?= (int)$product['reviews'] ?>)</span>
                        <?php endif; ?>
                    </div>-->
                    <?php endif; ?>

                    <div>
                        <?php if (!$product['in_stock']): ?>
                            <span class="pc-stock st-out"><i class="fas fa-circle-xmark"></i> Out of Stock</span>
                        <?php elseif ($product['stock_count'] <= 5): ?>
                            <span class="pc-stock st-low"><i class="fas fa-circle-exclamation"></i> Low Stock</span>
                        <?php else: ?>
                            <span class="pc-stock st-high"><i class="fas fa-circle-check"></i> In Stock</span>
                        <?php endif; ?>
                    </div>

                    <div class="pc-price">
                        <span class="pc-curr">LKR <?= number_format($product['price'], 2) ?></span>
                        <?php if ($discount > 0): ?>
                        <span class="pc-orig">LKR <?= number_format($product['original_price'], 2) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="pc-actions">
                        <a href="product-details.php?id=<?= (int)$product['id'] ?>" class="btn-view">
                            <i class="fas fa-eye" style="font-size:.68rem"></i>
                            <span class="btn-view-text"> View Details</span>
                        </a>
                        <?php if ($product['in_stock']): ?>
                        <button class="btn-cart"
                                onclick="addToCart(<?= (int)$product['id'] ?>)"
                                data-product-id="<?= (int)$product['id'] ?>"
                                data-in-stock="true">
                            <i class="fas fa-cart-plus" style="font-size:.68rem"></i>
                            <span class="btn-cart-text"> Add to Cart</span>
                        </button>
                        <?php else: ?>
                        <button class="btn-cart" disabled>
                            <i class="fas fa-ban" style="font-size:.68rem"></i>
                            <span class="btn-cart-text"> Unavailable</span>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
            <?php endforeach; ?>
        </div>

        <?php if ($total_pages > 1):
            $range = 2; $start = max(1,$page-$range); $end = min($total_pages,$page+$range);
        ?>
        <nav class="pg-nav" aria-label="Pagination">
            <a class="pg-btn <?= $page<=1?'disabled':'' ?>" href="?<?= buildQueryString($page-1) ?>"><i class="fas fa-chevron-left"></i></a>
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
            <a class="pg-btn <?= $page>=$total_pages?'disabled':'' ?>" href="?<?= buildQueryString($page+1) ?>"><i class="fas fa-chevron-right"></i></a>
        </nav>
        <?php endif; ?>
        <?php endif; ?>

    </div><!-- /products-main -->
</div><!-- /products-layout -->
</div><!-- /container -->
</div><!-- /products-page -->

<!-- Cart FAB (mobile) 
<a href="cart.php" class="cart-fab" id="cartFab" aria-label="View cart">
    <i class="fas fa-shopping-cart"></i>
    <span class="cart-fab-badge" id="cartFabBadge" <?= (($cart_count??0)>0)?'':'style="display:none"' ?>>
        <?= $cart_count ?? 0 ?>
    </span>
</a>-->

<!-- Bottom nav (mobile) -->
<nav class="bottom-nav">
    <a href="index.php" class="bnav-item"><i class="fas fa-house"></i><span>Home</span></a>
    <a href="products.php" class="bnav-item active"><i class="fas fa-box"></i><span>Products</span></a>
    <a href="cart.php" class="bnav-item">
        <i class="fas fa-shopping-cart"></i><span>Cart</span>
        <div class="bnav-cart-dot <?= (($cart_count??0)>0)?'show':'' ?>" id="bnavDot"></div>
    </a>
    <!--<a href="wishlist.php" class="bnav-item"><i class="fas fa-heart"></i><span>Wishlist</span></a>-->
    <a href="account.php" class="bnav-item"><i class="fas fa-user"></i><span>Account</span></a>
</nav>

<?php
$extra_scripts = <<<'JS'
<script>
/* ── View toggle ── */
let curView = 'grid';
function setView(v) {
    if (v === curView) return;
    curView = v;
    document.getElementById('productsGrid').classList.toggle('list-view', v === 'list');
    document.getElementById('gridBtn').classList.toggle('active', v === 'grid');
    document.getElementById('listBtn').classList.toggle('active', v === 'list');
}

/* ── Mobile sidebar ── */
const sidebar = document.getElementById('filterSidebar');
const overlay = document.getElementById('sidebarOverlay');
const mBtn    = document.getElementById('mobileFilterBtn');
if (mBtn)    mBtn.addEventListener('click', () => { sidebar.classList.add('open'); overlay.classList.add('open'); document.body.style.overflow='hidden'; });
if (overlay) overlay.addEventListener('click', closeSidebar);
function closeSidebar() { sidebar.classList.remove('open'); overlay.classList.remove('open'); document.body.style.overflow=''; }

/* ── Sidebar section collapse ── */
document.querySelectorAll('.filter-section-title').forEach(title => {
    title.addEventListener('click', () => {
        const body = document.getElementById(title.dataset.target);
        if (!body) return;
        body.classList.toggle('hidden');
        title.classList.toggle('collapsed', body.classList.contains('hidden'));
    });
});

/* ── Add to cart ── */
function addToCart(productId) {
    const btn = document.querySelector(`[data-product-id="${productId}"]`);
    if (!btn || btn.disabled) return;
    const orig = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin" style="font-size:.72rem"></i>';
    btn.disabled = true;
    fetch('add-to-cart.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ product_id: productId, quantity: 1 })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            btn.innerHTML = '<i class="fas fa-check" style="font-size:.72rem"></i>';
            btn.style.background = '#059669';
            updateCartUI(data.cart_count, data.cart_total);
            showToast(data.message || 'Added to cart!', 's');
            setTimeout(() => { btn.innerHTML = orig; btn.style.background = ''; btn.disabled = false; }, 2200);
        } else {
            if (data.redirect) { showToast('Please log in to add items to your cart','e'); setTimeout(() => window.location.href = data.redirect, 1600); return; }
            showToast(data.message || 'Could not add to cart','e');
            btn.innerHTML = orig; btn.disabled = false;
        }
    })
    .catch(() => { showToast('Network error – please try again','e'); btn.innerHTML = orig; btn.disabled = false; });
}

function updateCartUI(count, total) {
    // Header badge
    let badge = document.querySelector('.bdot');
    const icon = document.querySelector('.icon-btn');
    if (count > 0) {
        if (badge) badge.textContent = count;
        else if (icon) { const b = document.createElement('span'); b.className='bdot'; b.textContent=count; icon.appendChild(b); }
    } else if (badge) badge.remove();
    // FAB
    const fab = document.getElementById('cartFabBadge');
    if (fab) { fab.textContent = count; fab.style.display = count > 0 ? 'flex' : 'none'; }
    // Bottom nav dot
    const dot = document.getElementById('bnavDot');
    if (dot) dot.classList.toggle('show', count > 0);
    // Cart total text
    document.querySelectorAll('.cart-total').forEach(el => { if (total !== undefined) el.textContent = 'LKR ' + new Intl.NumberFormat().format(total); });
}

/* ── Wishlist toggle ── */
function toggleWish(btn) {
    btn.classList.toggle('active');
    const icon = btn.querySelector('i');
    if (btn.classList.contains('active')) {
        icon.className = 'fas fa-heart';
        showToast('Added to wishlist ❤️', 'i');
    } else {
        icon.className = 'far fa-heart';
    }
}

function showToast(msg, type = 'i') {
    document.querySelectorAll('.it-toast').forEach(t => t.remove());
    const icons = { s:'circle-check', e:'circle-exclamation', i:'circle-info' };
    const el = document.createElement('div');
    el.className = 'it-toast';
    el.innerHTML = `<div class="t-icon ${type}"><i class="fas fa-${icons[type]||'circle-info'}"></i></div><div class="t-body">${msg}</div><button class="t-close" aria-label="Close"><i class="fas fa-xmark"></i></button>`;
    document.body.appendChild(el);
    const tid = setTimeout(() => el.remove(), 4000);
    el.querySelector('.t-close').addEventListener('click', () => { clearTimeout(tid); el.remove(); });
}

/* ── Scroll reveal ── */
document.addEventListener('DOMContentLoaded', () => {
    const io = new IntersectionObserver(entries => {
        entries.forEach((e, i) => {
            if (e.isIntersecting) { setTimeout(() => e.target.classList.add('visible'), i * 40); io.unobserve(e.target); }
        });
    }, { threshold: 0.05, rootMargin: '0px 0px -40px 0px' });
    document.querySelectorAll('.product-card').forEach(c => io.observe(c));
});
</script>
JS;

include 'footer.php';
?>