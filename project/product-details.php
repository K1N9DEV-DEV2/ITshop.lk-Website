<?php
/**
 * product-detail.php
 * Full product page with integrated comment & rating system.
 */
session_start();
include 'db.php';

$product_id = isset($_GET['id']) ? intval($_GET['id']) : null;
if (!$product_id) { header('Location: products.php'); exit(); }

/* ── cart totals ──────────────────────────────────────────────────── */
$cart_count = 0;
$cart_total = 0;
if (isset($_SESSION['user_id']) && isset($pdo)) {
    try {
        $s = $pdo->prepare("SELECT COUNT(*) as c, SUM(price*quantity) as t FROM cart WHERE user_id=?");
        $s->execute([$_SESSION['user_id']]);
        $d = $s->fetch(PDO::FETCH_ASSOC);
        $cart_count = $d['c'] ?? 0;
        $cart_total = $d['t'] ?? 0;
    } catch(PDOException $e) { error_log($e->getMessage()); }
}

/* ── product ──────────────────────────────────────────────────────── */
try {
    $s = $pdo->prepare("
        SELECT p.*, GROUP_CONCAT(CONCAT(ps.spec_name,':',ps.spec_value) SEPARATOR '|') AS specifications
        FROM products p LEFT JOIN product_specs ps ON p.id=ps.product_id
        WHERE p.id=? GROUP BY p.id
    ");
    $s->execute([$product_id]);
    $product = $s->fetch(PDO::FETCH_ASSOC);
    if (!$product) { header('Location: products.php'); exit(); }

    $specs = [];
    if (!empty($product['specifications']))
        foreach (explode('|', $product['specifications']) as $sp)
            if (strpos($sp,':')!==false) { [$k,$v]=explode(':',$sp,2); $specs[$k]=$v; }

    $product['rating']            = $product['rating']            ?? 0;
    $product['reviews']           = $product['reviews']           ?? 0;
    $product['brand']             = $product['brand']             ?? 'Unknown';
    $product['warranty']          = $product['warranty']          ?? '1 Year Warranty';
    $product['warranty_total']    = $product['warranty_total']    ?? 'Standard Warranty Included';
    $product['stock_count']       = $product['stock_count']       ?? 0;
    $product['in_stock']          = $product['in_stock']          ?? ($product['stock_count'] > 0);
    $product['original_price']    = $product['original_price']    ?? $product['price'];
    $product['short_description'] = $product['short_description'] ?? '';
    $product['description']       = $product['description']       ?? $product['short_description'];
} catch(PDOException $e) { error_log($e->getMessage()); header('Location: products.php'); exit(); }

/* ── review stats ─────────────────────────────────────────────────── */
try {
    $s = $pdo->prepare("
        SELECT COUNT(*) AS total, ROUND(AVG(rating),1) AS avg,
               SUM(rating=5) AS r5, SUM(rating=4) AS r4, SUM(rating=3) AS r3,
               SUM(rating=2) AS r2, SUM(rating=1) AS r1
        FROM product_reviews WHERE product_id=?
    ");
    $s->execute([$product_id]);
    $rev_stats = $s->fetch(PDO::FETCH_ASSOC);
    $rev_total = intval($rev_stats['total']);
    $rev_avg   = floatval($rev_stats['avg'] ?? 0);
} catch(PDOException $e) {
    $rev_stats = ['total'=>0,'avg'=>0,'r5'=>0,'r4'=>0,'r3'=>0,'r2'=>0,'r1'=>0];
    $rev_total = 0; $rev_avg = 0;
}

/* ── logged-in user info for review form ──────────────────────────── */
$user_name  = '';
$user_email = '';
if (isset($_SESSION['user_id']) && isset($pdo)) {
    try {
        $s = $pdo->prepare("SELECT name, email FROM users WHERE id=? LIMIT 1");
        $s->execute([$_SESSION['user_id']]);
        $u = $s->fetch(PDO::FETCH_ASSOC);
        $user_name  = $u['name']  ?? '';
        $user_email = $u['email'] ?? '';
    } catch(PDOException $e) {}
}

$already_reviewed = isset($_SESSION['reviewed_'.$product_id]);

include 'header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($product['name']); ?> – STC Electronics</title>
<meta name="description" content="<?php echo htmlspecialchars($product['short_description']); ?>">

<link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800;900&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500&display=swap" rel="stylesheet">

<style>
/* ══ TOKENS ══════════════════════════════════════════════════ */
:root{
    --g:#15c247; --g-dk:#0ea83c; --g-pale:#e8fded;
    --b:#0ea5e9; --b-pale:#e0f4ff;
    --surf:#fff; --base:#f3f6fa;
    --text:#111827; --muted:#6b7280; --border:#e5e7eb;
    --r-sm:10px; --r-md:18px; --r-lg:26px; --r-xl:36px; --r-pill:999px;
    --sh-sm:0 2px 8px rgba(0,0,0,.05);
    --sh-md:0 6px 28px rgba(0,0,0,.08);
    --sh-lg:0 16px 56px rgba(0,0,0,.12);
    --font-h:'Nunito',sans-serif;
    --font-b:'DM Sans',sans-serif;
}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
body{font-family:var(--font-b);background:var(--base);color:var(--text)}

/* ── Breadcrumb ── */
.breadbar{background:var(--surf);border-bottom:1px solid var(--border);padding:13px 0;margin-top:80px}
.breadbar .breadcrumb{margin:0;padding:0;background:transparent}
.breadbar .breadcrumb-item a{color:var(--muted);text-decoration:none;font-size:.83rem;transition:color .2s}
.breadbar .breadcrumb-item a:hover{color:var(--g)}
.breadbar .breadcrumb-item.active{color:var(--text);font-size:.83rem;font-weight:600}
.breadbar .breadcrumb-item+.breadcrumb-item::before{color:var(--border)}

/* ── Layout ── */
.pd-wrap{padding:2.25rem 0 5rem}

/* ── Image panel ── */
.img-panel{position:sticky;top:100px}
.img-card{
    background:var(--surf);border-radius:var(--r-xl);padding:2.5rem;
    height:420px;display:flex;align-items:center;justify-content:center;
    box-shadow:var(--sh-md);overflow:hidden;position:relative;
}
.img-card::before{
    content:'';position:absolute;inset:0;border-radius:inherit;pointer-events:none;
    background:radial-gradient(ellipse at 25% 20%,rgba(21,194,71,.07),transparent 65%),
               radial-gradient(ellipse at 80% 80%,rgba(14,165,233,.06),transparent 60%);
}
.img-card img{max-width:100%;max-height:100%;object-fit:contain;position:relative;z-index:1;transition:transform .4s cubic-bezier(.34,1.56,.64,1)}
.img-card:hover img{transform:scale(1.08)}
.thumbs{display:flex;gap:.7rem;flex-wrap:wrap;margin-top:1.1rem}
.thumb{
    width:70px;height:70px;border-radius:var(--r-md);border:2px solid var(--border);
    background:var(--surf);padding:.45rem;cursor:pointer;
    display:flex;align-items:center;justify-content:center;
    box-shadow:var(--sh-sm);transition:all .25s;
}
.thumb img{width:100%;height:100%;object-fit:contain}
.thumb:hover,.thumb.active{border-color:var(--g);box-shadow:0 0 0 4px rgba(21,194,71,.15)}

/* ── Info card ── */
.info-card{background:var(--surf);border-radius:var(--r-xl);padding:2.25rem;box-shadow:var(--sh-md)}
.brand-pill{
    display:inline-flex;align-items:center;gap:.4rem;
    background:var(--g-pale);color:var(--g-dk);
    font-family:var(--font-h);font-weight:700;font-size:.75rem;
    letter-spacing:.07em;text-transform:uppercase;
    padding:.3rem .9rem;border-radius:var(--r-pill);margin-bottom:.9rem;
}
.prod-name{font-family:var(--font-h);font-size:1.7rem;font-weight:900;line-height:1.25;margin-bottom:.65rem}
.short-desc{color:var(--muted);font-size:.93rem;line-height:1.65;margin-bottom:1.1rem}

.rating-row{
    display:flex;align-items:center;gap:.7rem;
    padding:.9rem 0;border-top:1px solid var(--border);border-bottom:1px solid var(--border);margin-bottom:1.4rem;
}
.stars i{color:#f59e0b;font-size:.95rem}
.r-num{font-weight:700;font-size:.9rem}
.r-link{color:var(--b);font-size:.83rem;cursor:pointer;text-decoration:none}
.r-link:hover{text-decoration:underline}

.price-block{
    background:linear-gradient(135deg,#f0fff5,#e0f4ff);
    border:1px solid rgba(21,194,71,.15);border-radius:var(--r-lg);
    padding:1.2rem 1.5rem;display:flex;align-items:center;flex-wrap:wrap;gap:.9rem;margin-bottom:1.4rem;
}
.p-now{font-family:var(--font-h);font-size:2.3rem;font-weight:900;color:var(--g-dk);line-height:1}
.p-was{font-size:.95rem;color:var(--muted);text-decoration:line-through;margin-top:.2rem}
.save-badge{background:var(--g);color:#fff;font-family:var(--font-h);font-weight:800;font-size:.78rem;padding:.3rem .85rem;border-radius:var(--r-pill);margin-left:auto}

.chip{display:inline-flex;align-items:center;gap:.5rem;padding:.55rem 1.1rem;border-radius:var(--r-pill);font-weight:600;font-size:.85rem;margin-bottom:1.2rem}
.chip.ok {background:#e8fded;color:#0ea83c}
.chip.low{background:#fff7e6;color:#b45309}
.chip.out{background:#fff1f2;color:#e11d48}
.chip .dot{width:7px;height:7px;border-radius:50%}
.chip.ok  .dot{background:#15c247;animation:blink 1.5s infinite}
.chip.low .dot{background:#f59e0b;animation:blink 1.5s infinite}
.chip.out .dot{background:#f43f5e}
@keyframes blink{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.45;transform:scale(.7)}}

.warranty-box{
    display:flex;align-items:flex-start;gap:.9rem;
    background:#fffbeb;border:1px solid #fde68a;border-radius:var(--r-lg);
    padding:1rem 1.2rem;margin-bottom:1.4rem;
}
.w-icon{width:38px;height:38px;flex-shrink:0;background:#fef3c7;border-radius:var(--r-sm);display:flex;align-items:center;justify-content:center;color:#d97706;font-size:1rem}
.w-text h6{font-family:var(--font-h);font-weight:700;color:#92400e;margin-bottom:.2rem;font-size:.9rem}
.w-text p{font-size:.82rem;color:#a16207;margin:0}

.qty-row{display:flex;align-items:center;gap:.9rem;margin-bottom:1.4rem}
.qty-label{font-weight:600;font-size:.88rem}
.qty-ctrl{display:flex;align-items:center;background:var(--base);border:1.5px solid var(--border);border-radius:var(--r-pill);overflow:hidden}
.qty-ctrl button{background:none;border:none;width:38px;height:38px;cursor:pointer;color:var(--muted);display:flex;align-items:center;justify-content:center;transition:all .2s;font-size:.85rem}
.qty-ctrl button:hover{background:var(--border);color:var(--text)}
.qty-ctrl input{border:none;background:none;width:46px;text-align:center;font-weight:700;font-size:.95rem;color:var(--text)}

.act-row{display:flex;gap:.7rem;flex-wrap:wrap}
.btn-cart,.btn-buy,.btn-wish{
    display:inline-flex;align-items:center;justify-content:center;gap:.45rem;
    border:none;cursor:pointer;font-family:var(--font-h);font-weight:700;font-size:.95rem;
    border-radius:var(--r-pill);padding:.8rem 1.6rem;
    transition:all .3s cubic-bezier(.34,1.56,.64,1);
}
.btn-cart{flex:1;background:var(--g);color:#fff;box-shadow:0 4px 18px rgba(21,194,71,.32)}
.btn-cart:hover{background:var(--g-dk);transform:translateY(-2px);box-shadow:0 8px 26px rgba(21,194,71,.42)}
.btn-cart:active{transform:scale(.97)}
.btn-buy{flex:1;background:var(--b);color:#fff;box-shadow:0 4px 18px rgba(14,165,233,.28)}
.btn-buy:hover{background:#0284c7;transform:translateY(-2px);box-shadow:0 8px 26px rgba(14,165,233,.38)}
.btn-buy:active{transform:scale(.97)}
.btn-wish{background:var(--surf);color:var(--muted);border:1.5px solid var(--border);padding:.8rem 1rem}
.btn-wish:hover{border-color:#f43f5e;color:#f43f5e;background:#fff1f2;transform:scale(1.07)}
.btn-cart[disabled]{background:#d1d5db;box-shadow:none;cursor:not-allowed}

/* ── Tabs ── */
.tabs-wrap{margin-top:2.25rem}
.tab-pills{display:flex;gap:.5rem;flex-wrap:wrap;border:none;margin-bottom:0}
.tab-pills .nav-link{
    font-family:var(--font-h);font-weight:700;font-size:.88rem;
    color:var(--muted);background:var(--surf);
    border:1.5px solid var(--border) !important;border-radius:var(--r-pill) !important;
    padding:.55rem 1.3rem;transition:all .25s;
}
.tab-pills .nav-link.active,.tab-pills .nav-link:hover{
    background:var(--g);color:#fff !important;border-color:var(--g) !important;
    box-shadow:0 4px 14px rgba(21,194,71,.28);
}
.tab-body{background:var(--surf);border-radius:var(--r-xl);padding:2rem;margin-top:1rem;box-shadow:var(--sh-md)}
.tab-body h4{font-family:var(--font-h);font-weight:800;font-size:1.1rem;margin-bottom:1.4rem;color:var(--text)}

.spec-grid{display:grid;gap:.4rem}
.spec-row{display:grid;grid-template-columns:190px 1fr;border-radius:var(--r-sm);overflow:hidden}
.spec-row:nth-child(odd) .spec-k{background:rgba(21,194,71,.07)}
.spec-row:nth-child(odd) .spec-v{background:#fafcfa}
.spec-k{padding:.72rem 1rem;font-weight:600;font-size:.84rem;color:var(--text);background:rgba(21,194,71,.04)}
.spec-v{padding:.72rem 1rem;font-size:.84rem;color:var(--muted);background:#fff}

/* ══════════════════════════════════════════
   REVIEW SYSTEM
══════════════════════════════════════════ */

/* Overview block */
.rev-overview{
    display:grid;grid-template-columns:140px 1fr;gap:2rem;align-items:center;
    background:linear-gradient(135deg,#f0fff5,#e8f4ff);
    border:1px solid rgba(21,194,71,.12);border-radius:var(--r-xl);
    padding:1.75rem;margin-bottom:1.75rem;
}
.score-big .num{font-family:var(--font-h);font-size:3.8rem;font-weight:900;color:var(--g-dk);line-height:1}
.score-big .big-stars{display:flex;gap:3px;margin:.4rem 0}
.score-big .big-stars i{color:#f59e0b;font-size:1.1rem}
.score-big .sub{font-size:.78rem;color:var(--muted);font-weight:500}

.bars{display:flex;flex-direction:column;gap:.55rem}
.bar-row{display:flex;align-items:center;gap:.65rem}
.bar-lbl{font-size:.78rem;font-weight:700;color:var(--text);white-space:nowrap;min-width:28px}
.bar-track{flex:1;height:8px;background:var(--border);border-radius:var(--r-pill);overflow:hidden}
.bar-fill{height:100%;border-radius:var(--r-pill);background:linear-gradient(90deg,var(--g),#7ee8a2);transition:width .9s cubic-bezier(.34,1.56,.64,1)}
.bar-cnt{font-size:.78rem;color:var(--muted);min-width:20px;text-align:right}

/* Toolbar */
.rev-toolbar{display:flex;align-items:center;flex-wrap:wrap;gap:.65rem;margin-bottom:1.4rem}
.sort-label{font-size:.82rem;font-weight:600;color:var(--muted);white-space:nowrap}
.sort-btns{display:flex;gap:.4rem;flex-wrap:wrap}
.sort-btn{
    background:var(--base);border:1.5px solid var(--border);border-radius:var(--r-pill);
    padding:.32rem .85rem;font-family:var(--font-h);font-size:.78rem;font-weight:700;
    color:var(--muted);cursor:pointer;transition:all .2s;
}
.sort-btn:hover,.sort-btn.active{background:var(--g);border-color:var(--g);color:#fff}
.btn-write-rev{
    margin-left:auto;background:linear-gradient(135deg,var(--g),var(--b));
    color:#fff;border:none;border-radius:var(--r-pill);padding:.52rem 1.35rem;
    font-family:var(--font-h);font-weight:700;font-size:.85rem;cursor:pointer;
    transition:transform .2s,box-shadow .2s;white-space:nowrap;
}
.btn-write-rev:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(21,194,71,.3)}
.btn-write-rev:disabled{opacity:.55;cursor:not-allowed;transform:none}

/* Review list */
.rev-list{display:flex;flex-direction:column;gap:.9rem}
.rev-card{
    background:var(--surf);border:1.5px solid var(--border);border-radius:var(--r-lg);
    padding:1.3rem 1.5rem;transition:box-shadow .25s,transform .25s;
    animation:revIn .4s ease both;
}
.rev-card:hover{box-shadow:var(--sh-md);transform:translateY(-2px)}
@keyframes revIn{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}

.rev-head{display:flex;align-items:flex-start;gap:.9rem;margin-bottom:.8rem}
.avatar{
    width:42px;height:42px;flex-shrink:0;border-radius:50%;
    background:linear-gradient(135deg,var(--g-pale),var(--b-pale));
    display:flex;align-items:center;justify-content:center;
    font-family:var(--font-h);font-weight:800;font-size:.85rem;color:var(--g-dk);
    border:2px solid rgba(21,194,71,.2);
}
.rev-meta{flex:1}
.rev-name-row{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;margin-bottom:.2rem}
.rev-name{font-family:var(--font-h);font-weight:700;font-size:.93rem;color:var(--text)}
.v-badge{
    display:inline-flex;align-items:center;gap:.22rem;
    background:var(--g-pale);color:var(--g-dk);
    font-size:.68rem;font-weight:700;padding:.12rem .5rem;border-radius:var(--r-pill);
}
.rev-date{font-size:.76rem;color:var(--muted)}
.rev-stars-row{display:flex;gap:2px;margin-top:.2rem}
.rev-stars-row i{color:#f59e0b;font-size:.82rem}
.rev-stars-row i.e{color:#e5e7eb}

.rev-rating-badge{
    flex-shrink:0;
    width:34px;height:34px;border-radius:10px;
    display:flex;align-items:center;justify-content:center;
    font-family:var(--font-h);font-weight:900;font-size:.9rem;color:#fff;
}
.rb-5,.rb-4{background:var(--g)}
.rb-3{background:#f59e0b}
.rb-2,.rb-1{background:#f43f5e}

.rev-title{font-family:var(--font-h);font-weight:700;font-size:.98rem;margin-bottom:.35rem;color:var(--text)}
.rev-body{font-size:.875rem;color:var(--muted);line-height:1.7;margin-bottom:.85rem}

.rev-foot{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem}
.helpful-row{display:flex;align-items:center;gap:.5rem}
.helpful-lbl{font-size:.76rem;color:var(--muted)}
.btn-helpful{
    display:inline-flex;align-items:center;gap:.32rem;
    background:var(--base);border:1.5px solid var(--border);border-radius:var(--r-pill);
    padding:.26rem .72rem;font-size:.76rem;font-weight:600;color:var(--muted);cursor:pointer;transition:all .2s;
}
.btn-helpful:hover{border-color:var(--g);color:var(--g);background:var(--g-pale)}
.btn-helpful.voted{border-color:var(--g);color:var(--g);background:var(--g-pale);cursor:default}

/* Pagination */
.rev-pagination{display:flex;justify-content:center;gap:.45rem;flex-wrap:wrap;margin-top:1.5rem}
.pg-btn{
    width:36px;height:36px;border-radius:50%;border:1.5px solid var(--border);
    background:var(--surf);color:var(--muted);font-family:var(--font-h);font-weight:700;font-size:.82rem;
    cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .2s;
}
.pg-btn:hover,.pg-btn.active{background:var(--g);border-color:var(--g);color:#fff;box-shadow:0 4px 12px rgba(21,194,71,.3)}
.pg-btn:disabled{opacity:.4;cursor:not-allowed}

/* Empty state */
.rev-empty-state{text-align:center;padding:2.5rem 1rem}
.empty-icon-wrap{
    width:72px;height:72px;border-radius:50%;
    background:linear-gradient(135deg,#fef3c7,#fde68a);
    display:flex;align-items:center;justify-content:center;
    font-size:1.8rem;color:#f59e0b;margin:0 auto 1rem;
}
.rev-empty-state h5{font-family:var(--font-h);font-weight:800;margin-bottom:.4rem}
.rev-empty-state p{color:var(--muted);font-size:.88rem;margin-bottom:1.2rem}

/* Loading spinner */
.rev-loading{text-align:center;padding:2rem;color:var(--muted)}
.rev-loading i{font-size:1.8rem;animation:spin 1s linear infinite;color:var(--g)}
@keyframes spin{to{transform:rotate(360deg)}}

/* ── Write Review Form ── */
.write-form-wrap{
    background:linear-gradient(135deg,#f0fff5,#e8f4ff);
    border:1.5px solid rgba(21,194,71,.18);border-radius:var(--r-xl);
    padding:1.75rem;margin-top:1.75rem;
}
.write-form-wrap h5{font-family:var(--font-h);font-weight:800;margin-bottom:1.3rem;color:var(--text)}

.form-row-2{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
.form-group{margin-bottom:1rem}
.form-group label{display:block;font-weight:600;font-size:.84rem;color:var(--text);margin-bottom:.4rem}
.form-inp,.form-ta{
    width:100%;border:1.5px solid var(--border);border-radius:var(--r-md);
    padding:.65rem 1rem;font-family:var(--font-b);font-size:.9rem;
    background:var(--surf);color:var(--text);transition:border-color .2s,box-shadow .2s;outline:none;
}
.form-inp:focus,.form-ta:focus{border-color:var(--g);box-shadow:0 0 0 3px rgba(21,194,71,.13)}
.form-ta{resize:vertical;min-height:110px}

/* Inline star picker */
.star-picker{display:flex;gap:.4rem;margin-top:.35rem}
.star-picker i{font-size:2rem;color:#d1d5db;cursor:pointer;transition:color .15s,transform .15s}
.star-picker i:hover,.star-picker i.on{color:#f59e0b;transform:scale(1.2)}
.star-hint{font-size:.78rem;color:var(--muted);margin-top:.3rem;min-height:1.2em}

.submit-row{display:flex;align-items:center;gap:1rem;flex-wrap:wrap;margin-top:.5rem}
.btn-submit-rev{
    background:linear-gradient(135deg,var(--g),var(--b));color:#fff;
    border:none;border-radius:var(--r-pill);padding:.75rem 2rem;
    font-family:var(--font-h);font-weight:700;font-size:.95rem;cursor:pointer;
    transition:transform .2s,box-shadow .2s;
}
.btn-submit-rev:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(21,194,71,.35)}
.btn-submit-rev:active{transform:scale(.97)}
.btn-submit-rev:disabled{opacity:.55;cursor:not-allowed;transform:none;box-shadow:none}
.form-msg{font-size:.83rem;font-weight:600}
.form-msg.ok{color:var(--g-dk)}
.form-msg.err{color:#e11d48}

/* Already-reviewed notice */
.already-notice{
    display:flex;align-items:center;gap:.75rem;
    background:var(--g-pale);border:1.5px solid rgba(21,194,71,.25);
    border-radius:var(--r-lg);padding:1rem 1.3rem;
    font-size:.88rem;color:var(--g-dk);font-weight:600;
    margin-top:1.75rem;
}
.already-notice i{font-size:1.2rem}

/* ── WhatsApp FAB ── */
.wa-fab{
    position:fixed;bottom:24px;right:24px;width:58px;height:58px;
    background:#25d366;border-radius:50%;display:flex;align-items:center;justify-content:center;
    font-size:1.7rem;color:#fff;text-decoration:none;
    box-shadow:0 6px 22px rgba(37,211,102,.42);z-index:1000;
    transition:transform .3s cubic-bezier(.34,1.56,.64,1),box-shadow .3s;
}
.wa-fab:hover{transform:scale(1.12);box-shadow:0 10px 30px rgba(37,211,102,.5);color:#fff}

/* ── Toast ── */
.toast-shelf{position:fixed;top:100px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:.5rem;pointer-events:none}
.toast-item{
    background:#fff;border-radius:var(--r-lg);padding:.8rem 1.2rem;
    display:flex;align-items:center;gap:.7rem;
    box-shadow:0 8px 28px rgba(0,0,0,.13);font-size:.88rem;font-weight:500;
    pointer-events:all;animation:tIn .35s cubic-bezier(.34,1.56,.64,1);
    min-width:250px;max-width:340px;border-left:4px solid var(--g);
}
.toast-item.err{border-left-color:#f43f5e}
.t-icon{font-size:1.05rem;flex-shrink:0}
.toast-item.ok  .t-icon{color:var(--g)}
.toast-item.err .t-icon{color:#f43f5e}
@keyframes tIn {from{transform:translateX(110%);opacity:0}to{transform:translateX(0);opacity:1}}
@keyframes tOut{from{transform:translateX(0);opacity:1}to{transform:translateX(110%);opacity:0}}

/* Responsive */
@media(max-width:768px){
    .prod-name{font-size:1.4rem}.p-now{font-size:1.85rem}
    .act-row{flex-direction:column}.img-panel{position:static}
    .spec-row{grid-template-columns:1fr}
    .rev-overview{grid-template-columns:1fr}
    .score-big{display:flex;align-items:center;gap:1rem;flex-wrap:wrap}
    .form-row-2{grid-template-columns:1fr}
}
</style>
</head>
<body>

<!-- Breadcrumb -->
<div class="breadbar">
    <div class="container">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="index.php"><i class="fas fa-home me-1"></i>Home</a></li>
                <li class="breadcrumb-item"><a href="products.php">Products</a></li>
                <li class="breadcrumb-item"><a href="products.php?category=<?php echo urlencode($product['category']); ?>"><?php echo htmlspecialchars(ucfirst($product['category'])); ?></a></li>
                <li class="breadcrumb-item active"><?php echo htmlspecialchars($product['name']); ?></li>
            </ol>
        </nav>
    </div>
</div>

<!-- Main -->
<div class="pd-wrap">
<div class="container">
<div class="row g-4">

    <!-- Images -->
    <div class="col-lg-5">
        <div class="img-panel">
            <div class="img-card">
                <img id="mainImg"
                     src="<?php echo htmlspecialchars($product['image']); ?>"
                     alt="<?php echo htmlspecialchars($product['name']); ?>"
                     onerror="this.src='data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'300\' height=\'300\'%3E%3Crect width=\'300\' height=\'300\' fill=\'%23f3f6fa\'/%3E%3Ctext x=\'50%25\' y=\'50%25\' dominant-baseline=\'middle\' text-anchor=\'middle\' font-size=\'16px\' fill=\'%23aaa\'%3ENo Image%3C/text%3E%3C/svg%3E';">
            </div>
            <div class="thumbs">
                <div class="thumb active" onclick="changeImg(this,'<?php echo htmlspecialchars($product['image']); ?>')">
                    <img src="<?php echo htmlspecialchars($product['image']); ?>" alt="">
                </div>
            </div>
        </div>
    </div>

    <!-- Info -->
    <div class="col-lg-7">
    <div class="info-card">

        <div class="brand-pill"><i class="fas fa-tag"></i> <?php echo htmlspecialchars($product['brand']); ?></div>
        <h1 class="prod-name"><?php echo htmlspecialchars($product['name']); ?></h1>
        <?php if(!empty($product['short_description'])): ?>
        <p class="short-desc"><?php echo htmlspecialchars($product['short_description']); ?></p>
        <?php endif; ?>

        <!-- Rating row (links to reviews tab) -->
        <div class="rating-row">
            <div class="stars d-flex gap-1">
                <?php
                $r = floatval($product['rating']);
                for($i=1;$i<=5;$i++){
                    if($i<=floor($r))          echo '<i class="fas fa-star"></i>';
                    elseif($i<=ceil($r)&&$r>0) echo '<i class="fas fa-star-half-alt"></i>';
                    else                       echo '<i class="far fa-star"></i>';
                }
                ?>
            </div>
            <span class="r-num"><?php echo number_format($r,1); ?></span>
            <a class="r-link" onclick="switchToReviews()" href="#t-rev">
                <?php echo intval($product['reviews']); ?> reviews
            </a>
        </div>

        <!-- Price -->
        <div class="price-block">
            <div>
                <div class="p-now">LKR <?php echo number_format($product['price']); ?></div>
                <?php if($product['original_price']>$product['price']): ?>
                <div class="p-was">LKR <?php echo number_format($product['original_price']); ?></div>
                <?php endif; ?>
            </div>
            <?php if($product['original_price']>$product['price']):
                $disc=round((($product['original_price']-$product['price'])/$product['original_price'])*100); ?>
            <span class="save-badge">–<?php echo $disc; ?>% OFF</span>
            <?php endif; ?>
        </div>

        <!-- Stock -->
        <?php if($product['in_stock']&&$product['stock_count']>0): ?>
            <?php if($product['stock_count']<=5): ?>
            <div class="chip low"><span class="dot"></span>Only <?php echo $product['stock_count']; ?> left in stock</div>
            <?php else: ?>
            <div class="chip ok"><span class="dot"></span>In Stock — <?php echo $product['stock_count']; ?> available</div>
            <?php endif; ?>
        <?php else: ?>
        <div class="chip out"><span class="dot"></span>Out of Stock</div>
        <?php endif; ?>

        <!-- Warranty -->
        <div class="warranty-box">
            <div class="w-icon"><i class="fas fa-shield-alt"></i></div>
            <div class="w-text">
                <h6><?php echo htmlspecialchars($product['warranty']); ?></h6>
                <p><?php echo htmlspecialchars($product['warranty_total']); ?></p>
            </div>
        </div>

        <!-- Qty + Buttons -->
        <?php if($product['in_stock']&&$product['stock_count']>0): ?>
        <div class="qty-row">
            <span class="qty-label">Quantity</span>
            <div class="qty-ctrl">
                <button type="button" onclick="decQty()"><i class="fas fa-minus"></i></button>
                <input type="number" id="quantity" value="1" min="1" max="<?php echo $product['stock_count']; ?>" readonly>
                <button type="button" onclick="incQty(<?php echo $product['stock_count']; ?>)"><i class="fas fa-plus"></i></button>
            </div>
        </div>
        <div class="act-row">
            <button class="btn-cart" onclick="addToCart(<?php echo $product['id']; ?>)"><i class="fas fa-shopping-cart"></i> Add to Cart</button>
            <button class="btn-buy"  onclick="buyNow(<?php echo $product['id']; ?>)"><i class="fas fa-bolt"></i> Buy Now</button>
            <button class="btn-wish" onclick="addWish(<?php echo $product['id']; ?>)" title="Wishlist"><i class="far fa-heart"></i></button>
        </div>
        <?php else: ?>
        <div class="act-row">
            <button class="btn-cart" disabled><i class="fas fa-times"></i> Out of Stock</button>
            <button class="btn-wish" onclick="addWish(<?php echo $product['id']; ?>)"><i class="far fa-heart"></i></button>
        </div>
        <?php endif; ?>

    </div>
    </div><!-- /col -->

</div><!-- /row -->

<!-- ══ TABS ══════════════════════════════════════════════════════ -->
<div class="tabs-wrap">
    <ul class="nav tab-pills" id="prodTabs" role="tablist">
        <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#t-specs"><i class="fas fa-list-ul me-1"></i> Specifications</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#t-desc"><i class="fas fa-align-left me-1"></i> Description</a></li>
        <li class="nav-item">
            <a class="nav-link" id="rev-tab-link" data-bs-toggle="tab" href="#t-rev">
                <i class="fas fa-star me-1"></i> Reviews
                <span class="ms-1" id="rev-tab-count">(<?php echo $rev_total; ?>)</span>
            </a>
        </li>
    </ul>

    <div class="tab-content tab-body">

        <!-- Specifications -->
        <div id="t-specs" class="tab-pane fade show active">
            <h4>Product Specifications</h4>
            <?php if(!empty($specs)): ?>
            <div class="spec-grid">
                <?php foreach($specs as $k=>$v): ?>
                <div class="spec-row">
                    <div class="spec-k"><?php echo htmlspecialchars($k); ?></div>
                    <div class="spec-v"><?php echo htmlspecialchars($v); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?><p class="text-muted">No specifications available.</p><?php endif; ?>
        </div>

        <!-- Description -->
        <div id="t-desc" class="tab-pane fade">
            <h4>Product Description</h4>
            <?php if(!empty($product['description'])): ?>
            <p style="color:var(--muted);line-height:1.8"><?php echo nl2br(htmlspecialchars($product['description'])); ?></p>
            <?php else: ?><p class="text-muted">No description available.</p><?php endif; ?>
        </div>

        <!-- ══ REVIEWS TAB ══════════════════════════════════════ -->
        <div id="t-rev" class="tab-pane fade">
            <h4>Customer Reviews</h4>

            <!-- Rating Overview -->
            <div class="rev-overview">
                <div class="score-big" id="scoreBig">
                    <div class="num" id="avgNum"><?php echo $rev_avg > 0 ? number_format($rev_avg,1) : '–'; ?></div>
                    <div class="big-stars" id="bigStars">
                        <?php
                        for($i=1;$i<=5;$i++){
                            if($i<=floor($rev_avg))           echo '<i class="fas fa-star"></i>';
                            elseif($i<=ceil($rev_avg)&&$rev_avg>0) echo '<i class="fas fa-star-half-alt"></i>';
                            else                              echo '<i class="far fa-star"></i>';
                        }
                        ?>
                    </div>
                    <div class="sub" id="totalSub">
                        <?php echo $rev_total; ?> review<?php echo $rev_total!=1?'s':''; ?>
                    </div>
                </div>

                <div class="bars" id="ratingBars">
                    <?php
                    foreach([5,4,3,2,1] as $star):
                        $cnt = intval($rev_stats['r'.$star] ?? 0);
                        $pct = $rev_total > 0 ? round($cnt/$rev_total*100) : 0;
                    ?>
                    <div class="bar-row">
                        <span class="bar-lbl"><?php echo $star; ?> <i class="fas fa-star" style="color:#f59e0b;font-size:.7rem"></i></span>
                        <div class="bar-track"><div class="bar-fill" id="bar<?php echo $star; ?>" style="width:<?php echo $pct; ?>%"></div></div>
                        <span class="bar-cnt" id="barcnt<?php echo $star; ?>"><?php echo $cnt; ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Sort toolbar -->
            <div class="rev-toolbar">
                <span class="sort-label">Sort by:</span>
                <div class="sort-btns">
                    <button class="sort-btn active" data-sort="recent">Most Recent</button>
                    <button class="sort-btn" data-sort="highest">Highest</button>
                    <button class="sort-btn" data-sort="lowest">Lowest</button>
                    <button class="sort-btn" data-sort="helpful">Most Helpful</button>
                </div>
                <button class="btn-write-rev" id="showFormBtn"
                    <?php echo $already_reviewed ? 'disabled title="You already reviewed this product"' : ''; ?>
                    onclick="toggleForm()">
                    <i class="fas fa-pen me-1"></i>
                    <?php echo $already_reviewed ? 'Already Reviewed' : 'Write a Review'; ?>
                </button>
            </div>

            <!-- Review list -->
            <div class="rev-list" id="revList">
                <div class="rev-loading"><i class="fas fa-circle-notch"></i></div>
            </div>

            <!-- Pagination -->
            <div class="rev-pagination" id="revPager"></div>

            <!-- Write Review Form -->
            <?php if(!$already_reviewed): ?>
            <div class="write-form-wrap" id="writeForm" style="display:none">
                <h5><i class="fas fa-pen-to-square me-2"></i>Write a Review</h5>

                <div class="form-group" style="text-align:center">
                    <label>Your Rating <span style="color:#e11d48">*</span></label>
                    <div class="star-picker" id="starPicker">
                        <i class="far fa-star" data-r="1"></i>
                        <i class="far fa-star" data-r="2"></i>
                        <i class="far fa-star" data-r="3"></i>
                        <i class="far fa-star" data-r="4"></i>
                        <i class="far fa-star" data-r="5"></i>
                    </div>
                    <div class="star-hint" id="starHint">Click to rate</div>
                    <input type="hidden" id="fRating" value="0">
                </div>

                <div class="form-row-2">
                    <div class="form-group">
                        <label for="fName">Your Name <span style="color:#e11d48">*</span></label>
                        <input class="form-inp" type="text" id="fName" placeholder="John Silva" value="<?php echo htmlspecialchars($user_name); ?>">
                    </div>
                    <div class="form-group">
                        <label for="fEmail">Email <span style="color:#e11d48">*</span></label>
                        <input class="form-inp" type="email" id="fEmail" placeholder="you@email.com" value="<?php echo htmlspecialchars($user_email); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label for="fTitle">Review Title <span style="color:#e11d48">*</span></label>
                    <input class="form-inp" type="text" id="fTitle" placeholder="Summarise your experience…">
                </div>

                <div class="form-group">
                    <label for="fBody">Your Review <span style="color:#e11d48">*</span></label>
                    <textarea class="form-ta" id="fBody" placeholder="What did you love or dislike about this product?"></textarea>
                </div>

                <div class="submit-row">
                    <button class="btn-submit-rev" id="submitRevBtn" onclick="submitReview()">
                        <i class="fas fa-paper-plane me-1"></i> Submit Review
                    </button>
                    <span class="form-msg" id="formMsg"></span>
                </div>
            </div>
            <?php else: ?>
            <div class="already-notice">
                <i class="fas fa-circle-check"></i>
                You have already submitted a review for this product. Thank you!
            </div>
            <?php endif; ?>

        </div><!-- /t-rev -->

    </div><!-- /tab-body -->
</div><!-- /tabs-wrap -->

</div><!-- /container -->
</div><!-- /pd-wrap -->

<!-- WhatsApp FAB -->
<a href="https://wa.me/94779005652" class="wa-fab" target="_blank" aria-label="WhatsApp">
    <i class="fab fa-whatsapp"></i>
</a>

<!-- Toast shelf -->
<div class="toast-shelf" id="shelf"></div>

<?php include 'footer.php'; ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
<script>
/* ══════════════════════════════════════════════════════
   CONFIG
══════════════════════════════════════════════════════ */
const PRODUCT_ID = <?php echo intval($product_id); ?>;
let currentSort = 'recent';
let currentPage = 1;
const PER_PAGE  = 5;
const starLabels = ['','Terrible','Poor','Average','Good','Excellent'];

/* ══════════════════════════════════════════════════════
   QTY
══════════════════════════════════════════════════════ */
function incQty(max){ const e=document.getElementById('quantity'); if(e&&+e.value<max) e.value=+e.value+1; }
function decQty()    { const e=document.getElementById('quantity'); if(e&&+e.value>1)  e.value=+e.value-1; }

/* ══════════════════════════════════════════════════════
   IMAGE
══════════════════════════════════════════════════════ */
function changeImg(t,s){
    document.getElementById('mainImg').src=s;
    document.querySelectorAll('.thumb').forEach(x=>x.classList.remove('active'));
    t.classList.add('active');
}

/* ══════════════════════════════════════════════════════
   TOAST
══════════════════════════════════════════════════════ */
function toast(msg, type='ok'){
    const shelf=document.getElementById('shelf');
    const el=document.createElement('div');
    el.className=`toast-item ${type}`;
    el.innerHTML=`<i class="fas ${type==='ok'?'fa-circle-check':'fa-circle-exclamation'} t-icon"></i><span>${msg}</span>`;
    shelf.appendChild(el);
    setTimeout(()=>{ el.style.animation='tOut .3s ease-in forwards'; setTimeout(()=>el.remove(),300); },3500);
}

/* ══════════════════════════════════════════════════════
   CART / WISHLIST
══════════════════════════════════════════════════════ */
function addToCart(id){
    const qty=+(document.getElementById('quantity')?.value??1);
    const btn=event.target.closest('button');
    const orig=btn.innerHTML;
    btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Adding…';btn.disabled=true;
    fetch('add-to-cart.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({product_id:id,quantity:qty})})
    .then(r=>r.json()).then(d=>{
        if(d.success){ btn.innerHTML='<i class="fas fa-check"></i> Added!'; toast('Added to cart!'); updateCart(d.cart_count,d.cart_total); setTimeout(()=>{btn.innerHTML=orig;btn.disabled=false},2000); }
        else{ if(d.redirect){toast('Please login first','err');setTimeout(()=>location.href=d.redirect,1500);return;} toast(d.message||'Error','err'); btn.innerHTML=orig;btn.disabled=false; }
    }).catch(()=>{ toast('Network error','err'); btn.innerHTML=orig;btn.disabled=false; });
}

function buyNow(id){
    const qty=+(document.getElementById('quantity')?.value??1);
    fetch('add-to-cart.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({product_id:id,quantity:qty})})
    .then(r=>r.json()).then(d=>{ if(d.success) location.href='checkout.php'; else{ if(d.redirect){toast('Please login','err');setTimeout(()=>location.href=d.redirect,1500);}else toast(d.message||'Error','err'); } })
    .catch(()=>toast('Network error','err'));
}

function addWish(id){
    const btn=event.target.closest('button');
    fetch('add-to-wishlist.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({product_id:id})})
    .then(r=>r.json()).then(d=>{ if(d.success){btn.querySelector('i')?.classList.replace('far','fas');btn.style.color='#f43f5e';btn.style.borderColor='#f43f5e';toast('Added to wishlist!');}else{if(d.redirect){toast('Please login first','err');setTimeout(()=>location.href=d.redirect,1500);}else toast(d.message||'Error','err');} })
    .catch(()=>toast('Network error','err'));
}

function updateCart(cnt,total){
    const b=document.querySelector('.cart-count'),ic=document.querySelector('.cart-icon');
    if(cnt>0){ if(b) b.textContent=cnt; else if(ic){const nb=document.createElement('span');nb.className='cart-count';nb.textContent=cnt;ic.appendChild(nb);} }
    document.querySelectorAll('.me-3').forEach(el=>{ if(el.textContent.includes('LKR')) el.textContent='LKR '+new Intl.NumberFormat().format(total); });
}

/* ══════════════════════════════════════════════════════
   TAB switch helper
══════════════════════════════════════════════════════ */
function switchToReviews(){
    const link=document.getElementById('rev-tab-link');
    if(link) bootstrap.Tab.getOrCreateInstance(link).show();
}

/* ══════════════════════════════════════════════════════
   REVIEW SYSTEM
══════════════════════════════════════════════════════ */

/* ── Load & render reviews ── */
function loadReviews(page=1, sort='recent'){
    currentPage=page; currentSort=sort;
    const list=document.getElementById('revList');
    list.innerHTML='<div class="rev-loading"><i class="fas fa-circle-notch"></i></div>';

    fetch(`get-reviews.php?product_id=${PRODUCT_ID}&page=${page}&per_page=${PER_PAGE}&sort=${sort}`)
    .then(r=>r.json()).then(d=>{
        if(!d.success){ list.innerHTML='<p class="text-muted">Could not load reviews.</p>'; return; }

        // update overview numbers
        updateOverview(d.avg_rating, d.total, d.breakdown);

        list.innerHTML='';
        if(!d.reviews.length){
            list.innerHTML=`
                <div class="rev-empty-state">
                    <div class="empty-icon-wrap"><i class="fas fa-star"></i></div>
                    <h5>No reviews yet</h5>
                    <p>Be the first to share your experience with this product.</p>
                </div>`;
            document.getElementById('revPager').innerHTML='';
            return;
        }

        d.reviews.forEach((rv, idx) => {
            const card = buildCard(rv, idx);
            list.appendChild(card);
        });

        buildPager(d.page, d.pages);
    })
    .catch(()=>{ list.innerHTML='<p class="text-muted">Failed to load reviews.</p>'; });
}

/* ── Build one review card ── */
function buildCard(rv, idx){
    const card = document.createElement('div');
    card.className = 'rev-card';
    card.style.animationDelay = (idx*0.07)+'s';

    // stars html
    let starsHtml='';
    for(let i=1;i<=5;i++) starsHtml+=`<i class="fas fa-star${i>rv.rating?' e':''}"></i>`;

    // rating badge colour class
    const badgeCls = rv.rating>=4?'rb-4 rb-5':rv.rating===3?'rb-3':'rb-2 rb-1';

    const votedKey = 'hv_'+rv.id;
    const alreadyVoted = localStorage.getItem(votedKey);

    card.innerHTML=`
        <div class="rev-head">
            <div class="avatar">${rv.initials}</div>
            <div class="rev-meta">
                <div class="rev-name-row">
                    <span class="rev-name">${rv.name}</span>
                    ${rv.verified?'<span class="v-badge"><i class="fas fa-circle-check"></i> Verified Purchase</span>':''}
                    <span class="rev-date">${rv.created_at}</span>
                </div>
                <div class="rev-stars-row">${starsHtml}</div>
            </div>
            <div class="rev-rating-badge rb-${rv.rating}">${rv.rating}</div>
        </div>
        <div class="rev-title">${rv.title}</div>
        <div class="rev-body">${rv.body}</div>
        <div class="rev-foot">
            <div class="helpful-row">
                <span class="helpful-lbl">Helpful?</span>
                <button class="btn-helpful${alreadyVoted?' voted':''}" id="hbtn_${rv.id}" onclick="markHelpful(${rv.id})" ${alreadyVoted?'disabled':''}>
                    <i class="fas fa-thumbs-up"></i>
                    <span id="hcnt_${rv.id}">${rv.helpful}</span>
                </button>
            </div>
        </div>`;
    return card;
}

/* ── Pagination ── */
function buildPager(page, pages){
    const pager=document.getElementById('revPager');
    if(pages<=1){ pager.innerHTML=''; return; }
    let html='';
    html+=`<button class="pg-btn" onclick="loadReviews(${page-1},'${currentSort}')" ${page===1?'disabled':''}><i class="fas fa-chevron-left"></i></button>`;
    for(let p=1;p<=pages;p++)
        html+=`<button class="pg-btn${p===page?' active':''}" onclick="loadReviews(${p},'${currentSort}')">${p}</button>`;
    html+=`<button class="pg-btn" onclick="loadReviews(${page+1},'${currentSort}')" ${page===pages?'disabled':''}><i class="fas fa-chevron-right"></i></button>`;
    pager.innerHTML=html;
}

/* ── Update overview block ── */
function updateOverview(avg, total, breakdown){
    // avg number
    document.getElementById('avgNum').textContent = avg>0 ? avg.toFixed(1) : '–';
    document.getElementById('totalSub').textContent = total+' review'+(total!==1?'s':'');

    // big stars
    let sh='';
    for(let i=1;i<=5;i++){
        if(i<=Math.floor(avg))        sh+='<i class="fas fa-star"></i>';
        else if(i<=Math.ceil(avg)&&avg>0) sh+='<i class="fas fa-star-half-alt"></i>';
        else                           sh+='<i class="far fa-star"></i>';
    }
    document.getElementById('bigStars').innerHTML=sh;

    // bar fills
    [5,4,3,2,1].forEach(s=>{
        const cnt=breakdown[s]||0;
        const pct=total>0?Math.round(cnt/total*100):0;
        const bf=document.getElementById('bar'+s);
        const bc=document.getElementById('barcnt'+s);
        if(bf) bf.style.width=pct+'%';
        if(bc) bc.textContent=cnt;
    });

    // tab count
    document.getElementById('rev-tab-count').textContent='('+total+')';
}

/* ── Sort buttons ── */
document.querySelectorAll('.sort-btn').forEach(btn=>{
    btn.addEventListener('click',()=>{
        document.querySelectorAll('.sort-btn').forEach(b=>b.classList.remove('active'));
        btn.classList.add('active');
        loadReviews(1, btn.dataset.sort);
    });
});

/* ── Mark helpful ── */
function markHelpful(id){
    fetch('review-helpful.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({review_id:id})})
    .then(r=>r.json()).then(d=>{
        if(d.success){
            document.getElementById('hcnt_'+id).textContent=d.helpful;
            const btn=document.getElementById('hbtn_'+id);
            btn.classList.add('voted'); btn.disabled=true;
            localStorage.setItem('hv_'+id,'1');
            toast('Thanks for your feedback!');
        } else toast(d.message||'Already voted','err');
    }).catch(()=>toast('Network error','err'));
}

/* ── Toggle form ── */
function toggleForm(){
    const f=document.getElementById('writeForm');
    if(!f) return;
    const showing=f.style.display!=='none';
    f.style.display=showing?'none':'block';
    if(!showing) f.scrollIntoView({behavior:'smooth',block:'nearest'});
}

/* ── Star picker ── */
let selectedRating=0;
const starHints=['','Terrible 😣','Poor 😕','Average 😐','Good 😊','Excellent 🤩'];

document.querySelectorAll('#starPicker i').forEach(s=>{
    s.addEventListener('click',()=>{
        selectedRating=+s.dataset.r;
        document.getElementById('fRating').value=selectedRating;
        paintStars(selectedRating);
        document.getElementById('starHint').textContent=starHints[selectedRating];
    });
    s.addEventListener('mouseenter',()=>paintStars(+s.dataset.r));
    s.addEventListener('mouseleave',()=>paintStars(selectedRating));
});

function paintStars(n){
    document.querySelectorAll('#starPicker i').forEach(s=>{
        const on=+s.dataset.r<=n;
        s.classList.toggle('fas',on); s.classList.toggle('far',!on); s.classList.toggle('on',on);
    });
}

/* ── Submit review ── */
function submitReview(){
    const rating = +document.getElementById('fRating').value;
    const name   = document.getElementById('fName').value.trim();
    const email  = document.getElementById('fEmail').value.trim();
    const title  = document.getElementById('fTitle').value.trim();
    const body   = document.getElementById('fBody').value.trim();
    const msg    = document.getElementById('formMsg');
    const btn    = document.getElementById('submitRevBtn');

    // Validate
    if(!rating)         { showMsg('Please select a star rating.','err'); return; }
    if(!name)           { showMsg('Please enter your name.','err'); return; }
    if(!isEmail(email)) { showMsg('Please enter a valid email.','err'); return; }
    if(title.length<3)  { showMsg('Title is too short.','err'); return; }
    if(body.length<10)  { showMsg('Review is too short (min 10 chars).','err'); return; }

    btn.disabled=true;
    btn.innerHTML='<i class="fas fa-spinner fa-spin me-1"></i> Submitting…';

    fetch('submit-review.php',{
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({product_id:PRODUCT_ID, rating, name, email, title, body})
    })
    .then(r=>r.json()).then(d=>{
        if(d.success){
            showMsg('✓ '+d.message,'ok');
            toast('Review submitted! Thank you 🎉');

            // update overview live
            updateOverview(d.avg_rating, d.total, calcBreakdown());
            document.getElementById('rev-tab-count').textContent='('+d.total+')';

            // hide form, reload list
            setTimeout(()=>{
                document.getElementById('writeForm').style.display='none';
                document.getElementById('showFormBtn').disabled=true;
                document.getElementById('showFormBtn').textContent='Already Reviewed';
                loadReviews(1,'recent');
            },1200);
        } else {
            showMsg(d.message||'Something went wrong.','err');
            btn.disabled=false;
            btn.innerHTML='<i class="fas fa-paper-plane me-1"></i> Submit Review';
        }
    })
    .catch(()=>{ showMsg('Network error. Try again.','err'); btn.disabled=false; btn.innerHTML='<i class="fas fa-paper-plane me-1"></i> Submit Review'; });
}

function showMsg(txt, type){
    const m=document.getElementById('formMsg');
    m.textContent=txt; m.className='form-msg '+type;
    setTimeout(()=>{ m.textContent=''; m.className='form-msg'; },5000);
}

function isEmail(v){ return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v); }

// rough breakdown re-calc (server sends correct on reload)
function calcBreakdown(){ return {5:0,4:0,3:0,2:0,1:0}; }

/* ── Load reviews when tab shown ── */
document.getElementById('rev-tab-link').addEventListener('shown.bs.tab',()=>{
    loadReviews(1,'recent');
});

/* ── If page loaded directly with #reviews hash ── */
document.addEventListener('DOMContentLoaded',()=>{
    if(location.hash==='#t-rev') switchToReviews();
});
</script>
</body>
</html>