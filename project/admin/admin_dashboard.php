<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// Auth guard
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: admin_login.php');
    exit;
}

// Logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header('Location: admin_login.php');
    exit;
}

$active      = $_GET['page'] ?? 'dashboard';
$admin_email = $_SESSION['admin_email'] ?? 'admin@itshop.lk';

// ── Role-based access ─────────────────────────────────────────────────────────
$admin_role    = $_SESSION['admin_role'] ?? 'admin';
$is_superadmin = $admin_role === 'superadmin';

if ($active === 'settings' && !$is_superadmin) {
    header('Location: ?page=dashboard');
    exit;
}

// ── DB connection ─────────────────────────────────────────────────────────────
$pdo      = null;
$db_error = '';
try {
    require_once '../db.php';
} catch (Throwable $e) {
    $db_error = $e->getMessage();
}

// ── Safe query helper ─────────────────────────────────────────────────────────
function dbq(string $sql, array $p = [], bool $all = true): array|false {
    global $pdo;
    if (!$pdo) return false;
    try {
        $s = $pdo->prepare($sql);
        $s->execute($p);
        return $all ? $s->fetchAll(PDO::FETCH_ASSOC) : $s->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { return false; }
}

// ── Slug helper ───────────────────────────────────────────────────────────────
function slugify(string $text): string {
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '_', $text);
    return trim($text, '_');
}

// ── Ensure categories table has the right columns ─────────────────────────────
// Adds icon column if missing (safe to run every load; MySQL ignores if exists)
if ($pdo) {
    try {
        $pdo->exec("ALTER TABLE categories ADD COLUMN IF NOT EXISTS slug VARCHAR(100) NOT NULL DEFAULT ''");
        $pdo->exec("ALTER TABLE categories ADD COLUMN IF NOT EXISTS icon VARCHAR(50) NOT NULL DEFAULT 'fa-tag'");
        // Back-fill slugs for any rows that don't have one
        $pdo->exec("UPDATE categories SET slug = LOWER(REPLACE(REPLACE(name,' ','_'),'&','')) WHERE slug = '' OR slug IS NULL");
    } catch (PDOException $e) { /* silently skip if unsupported */ }
}

// ── Load categories from DB (primary source of truth) ────────────────────────
$db_categories = dbq("SELECT * FROM categories ORDER BY name ASC") ?: [];

// Build a lookup map: slug → category row  (fallback uses name as slug)
$cat_map = [];
foreach ($db_categories as $cat) {
    $slug = $cat['slug'] ?: slugify($cat['name']);
    $cat_map[$slug] = [
        'id'    => $cat['id'],
        'label' => $cat['name'],
        'icon'  => $cat['icon'] ?? 'fa-tag',
        'desc'  => $cat['description'] ?? '',
        'slug'  => $slug,
    ];
}

// ── Live DB queries ───────────────────────────────────────────────────────────
$db_products = dbq(
    "SELECT p.id, p.name, p.category, p.price, p.original_price,
            p.image, p.brand, p.stock_count,
            CASE WHEN p.stock_count > 0 THEN 1 ELSE 0 END as in_stock,
            GROUP_CONCAT(ps.spec_name SEPARATOR ' · ') as specs
     FROM products p
     LEFT JOIN product_specs ps ON p.id = ps.product_id
     GROUP BY p.id
     ORDER BY p.name ASC"
) ?: [];

$agg = dbq(
    "SELECT COUNT(*) as total_products,
            COALESCE(SUM(stock_count),0) as total_stock,
            SUM(CASE WHEN stock_count = 0 THEN 1 ELSE 0 END) as out_of_stock,
            SUM(CASE WHEN stock_count > 0 AND stock_count <= 5 THEN 1 ELSE 0 END) as low_stock
     FROM products",
    [], false
) ?: ['total_products'=>0,'total_stock'=>0,'out_of_stock'=>0,'low_stock'=>0];

// Category counts joined from DB categories (left join so we see categories with 0 products too)
$cat_counts = dbq(
    "SELECT c.id, c.name, c.slug, c.icon,
            COUNT(p.id) as cnt,
            COALESCE(SUM(p.stock_count),0) as stock
     FROM categories c
     LEFT JOIN products p ON p.category = c.slug
     GROUP BY c.id
     ORDER BY cnt DESC, c.name ASC"
) ?: [];

// Also detect any products whose category slug has no matching categories row
$orphan_cats = dbq(
    "SELECT p.category, COUNT(*) as cnt, COALESCE(SUM(p.stock_count),0) as stock
     FROM products p
     LEFT JOIN categories c ON c.slug = p.category
     WHERE c.id IS NULL AND p.category != ''
     GROUP BY p.category"
) ?: [];

$db_customers = dbq("SELECT id, name, email, created_at FROM users ORDER BY created_at DESC LIMIT 50") ?: [];

$db_orders = dbq(
    "SELECT o.id, o.status, o.total, o.created_at,
            u.name as customer_name, u.email as customer_email
     FROM orders o
     LEFT JOIN users u ON o.user_id = u.id
     ORDER BY o.created_at DESC LIMIT 100"
) ?: [];

$order_stats = dbq(
    "SELECT COALESCE(SUM(total),0) as revenue,
            COUNT(*) as total_orders,
            SUM(CASE WHEN LOWER(status)='pending'    THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN DATE(created_at)=CURDATE() THEN 1 ELSE 0 END) as today
     FROM orders",
    [], false
) ?: ['revenue'=>0,'total_orders'=>0,'pending'=>0,'today'=>0];

$pending_count = (int)($order_stats['pending'] ?? 0);

// ── POST handlers ─────────────────────────────────────────────────────────────
$flash = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    $act = $_POST['_action'] ?? '';

    // ── ADD CATEGORY ──────────────────────────────────────────────────────────
    if ($act === 'add_category') {
        $cat_name = trim($_POST['cat_name'] ?? '');
        $cat_desc = trim($_POST['cat_desc'] ?? '');
        $cat_icon = trim($_POST['cat_icon'] ?? 'fa-tag');
        $cat_slug = trim($_POST['cat_slug'] ?? '');
        if (!$cat_slug) $cat_slug = slugify($cat_name);

        if ($cat_name) {
            try {
                $pdo->prepare(
                    "INSERT INTO categories (name, description, slug, icon) VALUES (?, ?, ?, ?)"
                )->execute([$cat_name, $cat_desc, $cat_slug, $cat_icon]);
                $flash = ['type' => 'success', 'msg' => "Category \"$cat_name\" added."];
            } catch (PDOException $e) {
                $flash = ['type' => 'error', 'msg' => 'Could not add category: ' . $e->getMessage()];
            }
        } else {
            $flash = ['type' => 'error', 'msg' => 'Category name is required.'];
        }
        $_SESSION['flash'] = $flash;
        header('Location: ?page=categories');
        exit;
    }

    // ── EDIT CATEGORY ─────────────────────────────────────────────────────────
    if ($act === 'edit_category') {
        $cid      = intval($_POST['cat_id'] ?? 0);
        $cat_name = trim($_POST['cat_name'] ?? '');
        $cat_desc = trim($_POST['cat_desc'] ?? '');
        $cat_icon = trim($_POST['cat_icon'] ?? 'fa-tag');
        $cat_slug = trim($_POST['cat_slug'] ?? '');
        $old_slug = trim($_POST['old_slug'] ?? '');

        if ($cid && $cat_name) {
            try {
                $pdo->prepare(
                    "UPDATE categories SET name=?, description=?, slug=?, icon=?, updated_at=NOW() WHERE id=?"
                )->execute([$cat_name, $cat_desc, $cat_slug, $cat_icon, $cid]);

                // Migrate products if slug changed
                if ($old_slug && $old_slug !== $cat_slug) {
                    $pdo->prepare("UPDATE products SET category=? WHERE category=?")->execute([$cat_slug, $old_slug]);
                }
                $flash = ['type' => 'success', 'msg' => "Category updated."];
            } catch (PDOException $e) {
                $flash = ['type' => 'error', 'msg' => 'Could not update: ' . $e->getMessage()];
            }
        }
        $_SESSION['flash'] = $flash;
        header('Location: ?page=categories');
        exit;
    }

    // ── DELETE CATEGORY ───────────────────────────────────────────────────────
    if ($act === 'delete_category') {
        $cid      = intval($_POST['cat_id'] ?? 0);
        $cat_slug = trim($_POST['cat_slug'] ?? '');
        $reassign = trim($_POST['reassign_slug'] ?? '');

        if ($cid) {
            try {
                if ($reassign) {
                    // Move products to another category
                    $pdo->prepare("UPDATE products SET category=? WHERE category=?")->execute([$reassign, $cat_slug]);
                } else {
                    // Null-out the category on orphaned products
                    $pdo->prepare("UPDATE products SET category='' WHERE category=?")->execute([$cat_slug]);
                }
                $pdo->prepare("DELETE FROM categories WHERE id=?")->execute([$cid]);
                $flash = ['type' => 'success', 'msg' => 'Category deleted.'];
            } catch (PDOException $e) {
                $flash = ['type' => 'error', 'msg' => 'Could not delete: ' . $e->getMessage()];
            }
        }
        $_SESSION['flash'] = $flash;
        header('Location: ?page=categories');
        exit;
    }

    // ── IMPORT ORPHAN CATEGORIES ──────────────────────────────────────────────
    // Creates a categories row for any product category slug that has no match
    if ($act === 'import_orphans') {
        $imported = 0;
        $orphans  = dbq(
            "SELECT DISTINCT p.category FROM products p
             LEFT JOIN categories c ON c.slug = p.category
             WHERE c.id IS NULL AND p.category != ''"
        ) ?: [];
        foreach ($orphans as $row) {
            $sl  = $row['category'];
            $lbl = ucwords(str_replace(['_', '-'], ' ', $sl));
            try {
                $pdo->prepare(
                    "INSERT IGNORE INTO categories (name, slug, icon) VALUES (?, ?, 'fa-tag')"
                )->execute([$lbl, $sl]);
                $imported++;
            } catch (PDOException $e) {}
        }
        $flash = ['type' => 'success', 'msg' => "$imported orphan category/categories imported."];
        $_SESSION['flash'] = $flash;
        header('Location: ?page=categories');
        exit;
    }

    // ── ADD PRODUCT ───────────────────────────────────────────────────────────
    if ($act === 'add_product') {
        $name     = trim($_POST['name'] ?? '');
        $brand    = trim($_POST['brand'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $price    = floatval($_POST['price'] ?? 0);
        $orig     = floatval($_POST['original_price'] ?? 0);
        $stock    = intval($_POST['stock_count'] ?? 0);
        $image    = trim($_POST['image_url'] ?? '');

        if (!empty($_FILES['image_file']['name']) && $_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
            $ext     = strtolower(pathinfo($_FILES['image_file']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','webp','gif'];
            if (in_array($ext, $allowed)) {
                $dir = 'uploads/products/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $fname = uniqid('prod_') . '.' . $ext;
                if (move_uploaded_file($_FILES['image_file']['tmp_name'], $dir . $fname))
                    $image = $dir . $fname;
            }
        }

        if ($name && $category && $price > 0) {
            try {
                $pdo->prepare(
                    "INSERT INTO products (name, brand, category, price, original_price, stock_count, image)
                     VALUES (?, ?, ?, ?, ?, ?, ?)"
                )->execute([$name, $brand, $category, $price, $orig ?: $price, $stock, $image]);

                $new_id = $pdo->lastInsertId();

                $specs_raw = trim($_POST['specs'] ?? '');
                if ($specs_raw && $new_id) {
                    $sp = $pdo->prepare("INSERT INTO product_specs (product_id, spec_name) VALUES (?, ?)");
                    foreach (array_filter(array_map('trim', explode("\n", $specs_raw))) as $sl)
                        $sp->execute([$new_id, $sl]);
                }
                $flash = ['type' => 'success', 'msg' => "Product \"$name\" added successfully!"];
            } catch (PDOException $e) {
                $flash = ['type' => 'error', 'msg' => 'DB error: ' . $e->getMessage()];
            }
        } else {
            $flash = ['type' => 'error', 'msg' => 'Name, category and price are required.'];
        }
        $_SESSION['flash'] = $flash;
        header('Location: ?page=products');
        exit;
    }

    // ── DELETE PRODUCT ────────────────────────────────────────────────────────
    if ($act === 'delete_product') {
        $del_id = intval($_POST['product_id'] ?? 0);
        if ($del_id) {
            try {
                $pdo->prepare("DELETE FROM product_specs WHERE product_id = ?")->execute([$del_id]);
                $pdo->prepare("DELETE FROM products WHERE id = ?")->execute([$del_id]);
                $flash = ['type' => 'success', 'msg' => 'Product deleted successfully.'];
            } catch (PDOException $e) {
                $flash = ['type' => 'error', 'msg' => 'Could not delete: ' . $e->getMessage()];
            }
        }
        $_SESSION['flash'] = $flash;
        header('Location: ?page=products');
        exit;
    }

    // ── UPDATE STOCK ──────────────────────────────────────────────────────────
    if ($act === 'update_stock') {
        $pid   = intval($_POST['product_id'] ?? 0);
        $stock = max(0, intval($_POST['stock_count'] ?? 0));
        if ($pid) {
            try { $pdo->prepare("UPDATE products SET stock_count = ? WHERE id = ?")->execute([$stock, $pid]); }
            catch (PDOException $e) {}
        }
        header('Location: ?page=products');
        exit;
    }

    // ── UPDATE ORDER STATUS ───────────────────────────────────────────────────
    if ($act === 'update_order_status') {
        $oid    = intval($_POST['order_id'] ?? 0);
        $status = trim($_POST['status'] ?? '');
        $ok     = ['pending','processing','shipped','completed','cancelled'];
        if ($oid && in_array($status, $ok)) {
            try { $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?")->execute([$status, $oid]); }
            catch (PDOException $e) {}
        }
        header('Location: ?page=orders');
        exit;
    }

    // ── CLEAR ALL ORDERS — superadmin only ───────────────────────────────────
    if ($act === 'clear_orders' && $is_superadmin) {
        try {
            $pdo->exec("DELETE FROM orders");
            $flash = ['type' => 'success', 'msg' => 'All orders have been cleared.'];
        } catch (PDOException $e) {
            $flash = ['type' => 'error', 'msg' => 'Could not clear orders: ' . $e->getMessage()];
        }
        $_SESSION['flash'] = $flash;
        header('Location: ?page=settings');
        exit;
    }
}

// Flash from redirect
if (isset($_SESSION['flash'])) { $flash = $_SESSION['flash']; unset($_SESSION['flash']); }

// ── Status badge colours ──────────────────────────────────────────────────────
$status_colors = [
    'completed'  => ['bg' => '#dcfce7', 'color' => '#15803d'],
    'processing' => ['bg' => '#dbeafe', 'color' => '#1d4ed8'],
    'shipped'    => ['bg' => '#e0e7ff', 'color' => '#4338ca'],
    'pending'    => ['bg' => '#fef9c3', 'color' => '#854d0e'],
    'cancelled'  => ['bg' => '#fee2e2', 'color' => '#dc2626'],
];

// ── Nav items ─────────────────────────────────────────────────────────────────
$nav_items = [
    ['page' => 'dashboard',  'icon' => 'fa-gauge-high',   'label' => 'Dashboard',  'roles' => ['admin','superadmin']],
    ['page' => 'products',   'icon' => 'fa-box',          'label' => 'Products',   'roles' => ['admin','superadmin']],
    ['page' => 'orders',     'icon' => 'fa-bag-shopping', 'label' => 'Orders',     'roles' => ['admin','superadmin']],
    ['page' => 'customers',  'icon' => 'fa-users',        'label' => 'Customers',  'roles' => ['admin','superadmin']],
    ['page' => 'categories', 'icon' => 'fa-layer-group',  'label' => 'Categories', 'roles' => ['admin','superadmin']],
    ['page' => 'reports',    'icon' => 'fa-chart-line',   'label' => 'Reports',    'roles' => ['admin','superadmin']],
    ['page' => 'settings',   'icon' => 'fa-gear',         'label' => 'Settings',   'roles' => ['superadmin']],
];

// Common icon options for the picker
$icon_options = [
    'fa-tag','fa-box','fa-laptop','fa-desktop','fa-server','fa-microchip','fa-memory',
    'fa-hard-drive','fa-tv','fa-display','fa-keyboard','fa-mouse','fa-headphones',
    'fa-fan','fa-bolt','fa-mobile-screen','fa-camera','fa-gamepad','fa-print',
    'fa-network-wired','fa-wifi','fa-battery-full','fa-plug','fa-toolbox',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin — IT Shop.LK</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Red+Hat+Display:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --ink: #0a0a0f;
            --ink-soft: #3d3d50;
            --ink-muted: #8888a0;
            --surface: #f0f0f5;
            --card: #ffffff;
            --sidebar-bg: #0d0d14;
            --sidebar-w: 242px;
            --accent: #0cb100;
            --accent-dim: rgba(12,177,0,0.1);
            --accent-glow: rgba(12,177,0,0.32);
            --r-xl: 20px; --r-lg: 14px; --r-md: 10px; --r-sm: 7px;
            --shadow: 0 2px 16px rgba(10,10,15,0.08), 0 0 0 1px rgba(10,10,15,0.05);
            --topbar-h: 64px;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Red Hat Display', sans-serif; background: var(--surface); color: var(--ink); -webkit-font-smoothing: antialiased; display: flex; min-height: 100vh; }

        /* ── SIDEBAR ─────────────────────────────────────────── */
        .sidebar { width: var(--sidebar-w); min-height: 100vh; background: var(--sidebar-bg); display: flex; flex-direction: column; position: fixed; top: 0; left: 0; bottom: 0; z-index: 200; }
        .sidebar::before { content:''; position:absolute; inset:0; background-image: linear-gradient(rgba(255,255,255,.02) 1px,transparent 1px), linear-gradient(90deg,rgba(255,255,255,.02) 1px,transparent 1px); background-size:32px 32px; pointer-events:none; }
        .sb-logo { padding:1.4rem 1.25rem 1.1rem; display:flex; align-items:center; gap:10px; border-bottom:1px solid rgba(255,255,255,.06); position:relative; }
        .sb-logo-icon { width:36px; height:36px; background:var(--accent); border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1rem; color:#fff; box-shadow:0 2px 14px var(--accent-glow); flex-shrink:0; }
        .sb-logo-text { font-size:.93rem; font-weight:800; color:#fff; letter-spacing:-.02em; line-height:1.1; }
        .sb-logo-text span { display:block; font-size:.64rem; font-weight:500; color:#44445a; letter-spacing:.05em; }
        .sb-nav { flex:1; padding:.8rem .7rem; display:flex; flex-direction:column; gap:2px; overflow-y:auto; position:relative; }
        .sb-section { font-size:.62rem; font-weight:700; letter-spacing:.12em; text-transform:uppercase; color:#2a2a3a; padding:.65rem .75rem .3rem; margin-top:.3rem; }
        .nav-link { display:flex; align-items:center; gap:10px; padding:9px 12px; border-radius:var(--r-sm); text-decoration:none; color:#70708a; font-size:.85rem; font-weight:600; transition:all .17s; position:relative; }
        .nav-link i { width:16px; text-align:center; font-size:.87rem; }
        .nav-link:hover { background:rgba(255,255,255,.05); color:#c0c0da; text-decoration:none; }
        .nav-link.active { background:var(--accent-dim); color:#fff; border:1px solid rgba(12,177,0,.18); }
        .nav-link.active i { color:var(--accent); }
        .nav-link.active::before { content:''; position:absolute; left:0; top:22%; bottom:22%; width:3px; background:var(--accent); border-radius:0 3px 3px 0; }
        .nav-badge { margin-left:auto; background:#ef4444; color:#fff; font-size:.61rem; font-weight:700; padding:1px 6px; border-radius:100px; }
        .sb-foot { padding:.85rem .7rem; border-top:1px solid rgba(255,255,255,.06); position:relative; }
        .sb-profile { display:flex; align-items:center; gap:9px; padding:8px 10px; border-radius:var(--r-sm); background:rgba(255,255,255,.04); }
        .sb-avatar { width:30px; height:30px; background:linear-gradient(135deg,var(--accent),#7dfc9b); border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:.74rem; font-weight:800; color:#fff; flex-shrink:0; }
        .sb-info { flex:1; min-width:0; }
        .sb-name { font-size:.75rem; font-weight:700; color:#ddddf0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .sb-role { font-size:.62rem; color:#33334a; }
        .sb-logout { color:#2a2a3a; font-size:.82rem; text-decoration:none; transition:color .18s; flex-shrink:0; }
        .sb-logout:hover { color:#ef4444; }

        /* ── MAIN ────────────────────────────────────────────── */
        .main { margin-left:var(--sidebar-w); flex:1; display:flex; flex-direction:column; min-height:100vh; }

        /* ── TOPBAR ──────────────────────────────────────────── */
        .topbar { height:var(--topbar-h); background:var(--card); border-bottom:1px solid rgba(10,10,15,.07); display:flex; align-items:center; padding:0 1.75rem; gap:1rem; position:sticky; top:0; z-index:150; }
        .tb-title { font-size:1rem; font-weight:800; color:var(--ink); letter-spacing:-.02em; }
        .tb-bc    { font-size:.73rem; color:var(--ink-muted); margin-top:1px; }
        .tb-search { margin-left:auto; position:relative; }
        .tb-search input { background:var(--surface); border:1.5px solid rgba(10,10,15,.08); border-radius:var(--r-md); padding:7px 12px 7px 33px; font-family:'Red Hat Display',sans-serif; font-size:.82rem; color:var(--ink); width:210px; outline:none; transition:border-color .2s, box-shadow .2s; }
        .tb-search input:focus { border-color:var(--accent); box-shadow:0 0 0 3px rgba(12,177,0,.1); }
        .tb-search i { position:absolute; left:11px; top:50%; transform:translateY(-50%); color:var(--ink-muted); font-size:.75rem; }
        .tb-actions { display:flex; align-items:center; gap:7px; }
        .tb-btn { width:34px; height:34px; border-radius:var(--r-sm); background:var(--surface); border:1px solid rgba(10,10,15,.07); display:flex; align-items:center; justify-content:center; color:var(--ink-muted); cursor:pointer; text-decoration:none; transition:all .17s; position:relative; font-size:.83rem; }
        .tb-btn:hover { background:var(--accent-dim); color:var(--accent); border-color:rgba(12,177,0,.2); }
        .notif-dot { position:absolute; top:6px; right:6px; width:6px; height:6px; background:#ef4444; border-radius:50%; border:1.5px solid var(--card); }

        /* ── CONTENT ─────────────────────────────────────────── */
        .content { flex:1; padding:1.75rem 2rem; }

        /* ── PAGE HEADER ─────────────────────────────────────── */
        .pg-hdr { display:flex; align-items:center; justify-content:space-between; margin-bottom:1.6rem; flex-wrap:wrap; gap:1rem; }
        .pg-hdr-l h2 { font-size:1.4rem; font-weight:800; color:var(--ink); letter-spacing:-.025em; }
        .pg-hdr-l p  { font-size:.8rem; color:var(--ink-muted); margin-top:2px; }
        .pg-hdr-r { display:flex; gap:8px; flex-wrap:wrap; }

        /* ── BUTTONS ─────────────────────────────────────────── */
        .btn-p { display:inline-flex; align-items:center; gap:6px; background:var(--accent); color:#fff; font-family:'Red Hat Display',sans-serif; font-weight:700; font-size:.82rem; padding:9px 17px; border-radius:var(--r-md); border:none; cursor:pointer; text-decoration:none; transition:all .2s; box-shadow:0 2px 12px var(--accent-glow); }
        .btn-p:hover { background:#098600; transform:translateY(-1px); box-shadow:0 6px 20px var(--accent-glow); color:#fff; text-decoration:none; }
        .btn-o { display:inline-flex; align-items:center; gap:6px; background:var(--card); color:var(--ink-soft); font-family:'Red Hat Display',sans-serif; font-weight:600; font-size:.82rem; padding:9px 17px; border-radius:var(--r-md); border:1.5px solid rgba(10,10,15,.1); cursor:pointer; text-decoration:none; transition:all .2s; }
        .btn-o:hover { border-color:var(--accent); color:var(--accent); text-decoration:none; }
        .btn-sm { padding:6px 13px !important; font-size:.77rem !important; }
        .btn-red { background:#ef4444 !important; box-shadow:0 2px 10px rgba(239,68,68,.3) !important; }
        .btn-red:hover { background:#dc2626 !important; }
        .btn-warn { background:#f59e0b !important; box-shadow:0 2px 10px rgba(245,158,11,.3) !important; }
        .btn-warn:hover { background:#d97706 !important; }

        /* ── STATS ───────────────────────────────────────────── */
        .stats-g { display:grid; grid-template-columns:repeat(auto-fill,minmax(205px,1fr)); gap:13px; margin-bottom:1.6rem; }
        .stat-c { background:var(--card); border-radius:var(--r-lg); padding:1.1rem 1.35rem; box-shadow:var(--shadow); display:flex; align-items:flex-start; gap:12px; animation:fadeUp .4s ease both; }
        .stat-ico { width:41px; height:41px; border-radius:var(--r-md); display:flex; align-items:center; justify-content:center; font-size:1rem; flex-shrink:0; }
        .stat-b { flex:1; min-width:0; }
        .stat-lbl { font-size:.68rem; font-weight:700; color:var(--ink-muted); text-transform:uppercase; letter-spacing:.06em; }
        .stat-val { font-size:1.38rem; font-weight:800; color:var(--ink); letter-spacing:-.025em; margin:2px 0; }
        .stat-d { font-size:.7rem; font-weight:700; display:inline-flex; align-items:center; gap:3px; }
        .up { color:#16a34a; } .dn { color:#dc2626; }

        /* ── TABLE CARD ──────────────────────────────────────── */
        .tc { background:var(--card); border-radius:var(--r-xl); box-shadow:var(--shadow); overflow:hidden; animation:fadeUp .45s .07s ease both; }
        .tc-hdr { padding:1.1rem 1.45rem; border-bottom:1px solid rgba(10,10,15,.06); display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap; }
        .tc-hdr h3 { font-size:.92rem; font-weight:700; color:var(--ink); }
        .tc-hdr p  { font-size:.76rem; color:var(--ink-muted); margin-top:2px; }
        .tbl-wrap  { overflow-x:auto; }
        table { width:100%; border-collapse:collapse; }
        thead th { background:var(--surface); font-size:.67rem; font-weight:700; letter-spacing:.08em; text-transform:uppercase; color:var(--ink-muted); padding:9px 14px; text-align:left; white-space:nowrap; }
        tbody tr { border-bottom:1px solid rgba(10,10,15,.044); transition:background .12s; }
        tbody tr:last-child { border-bottom:none; }
        tbody tr:hover { background:#fafafa; }
        td { padding:11px 14px; font-size:.845rem; color:var(--ink-soft); vertical-align:middle; }
        td strong { color:var(--ink); font-weight:700; }
        td.muted  { color:var(--ink-muted); font-size:.77rem; }

        /* ── BADGES ──────────────────────────────────────────── */
        .badge { display:inline-flex; align-items:center; gap:4px; font-size:.68rem; font-weight:700; padding:3px 9px; border-radius:100px; }
        .badge::before { content:''; width:5px; height:5px; border-radius:50%; background:currentColor; }
        .sb { font-size:.7rem; font-weight:700; padding:2px 8px; border-radius:100px; }
        .sa { background:#dcfce7; color:#15803d; }
        .sl { background:#fef9c3; color:#854d0e; }
        .so { background:#fee2e2; color:#dc2626; }
        .si { background:#f3f4f6; color:#6b7280; }
        .sw { background:#fef3c7; color:#92400e; } /* warning/orphan */

        /* ── ACTION BTNS ─────────────────────────────────────── */
        .ab { width:28px; height:28px; border-radius:7px; border:1px solid rgba(10,10,15,.08); background:var(--surface); color:var(--ink-muted); display:inline-flex; align-items:center; justify-content:center; cursor:pointer; font-size:.74rem; transition:all .14s; text-decoration:none; }
        .ab:hover     { border-color:var(--accent); color:var(--accent); background:var(--accent-dim); }
        .ab.del:hover { border-color:#ef4444; color:#ef4444; background:#fee2e2; }
        .ab.warn:hover{ border-color:#f59e0b; color:#f59e0b; background:#fef9c3; }
        .ab.edit:hover{ border-color:#3b82f6; color:#3b82f6; background:#dbeafe; }

        /* ── SEARCH IN TC ────────────────────────────────────── */
        .tbl-s { background:var(--surface); border:1.5px solid rgba(10,10,15,.08); border-radius:var(--r-md); padding:7px 11px; font-family:'Red Hat Display',sans-serif; font-size:.82rem; color:var(--ink); outline:none; transition:border-color .2s; }
        .tbl-s:focus { border-color:var(--accent); }

        /* ── GRID HELPERS ────────────────────────────────────── */
        .g2 { display:grid; grid-template-columns:1fr 1fr; gap:13px; margin-bottom:13px; }

        /* ── CHART CARD ──────────────────────────────────────── */
        .cc { background:var(--card); border-radius:var(--r-xl); box-shadow:var(--shadow); padding:1.35rem; animation:fadeUp .45s .1s ease both; }
        .cc h3 { font-size:.92rem; font-weight:700; color:var(--ink); }
        .cc p  { font-size:.76rem; color:var(--ink-muted); margin:3px 0 1rem; }
        .bar-chart { display:flex; align-items:flex-end; gap:6px; height:96px; }
        .bar-col { flex:1; display:flex; flex-direction:column; align-items:center; gap:4px; }
        .bar { width:100%; border-radius:4px 4px 0 0; background:var(--accent-dim); transition:background .2s; cursor:default; }
        .bar.hi { background:var(--accent); }
        .bar-lbl { font-size:.58rem; color:var(--ink-muted); font-weight:600; }
        .top-item { display:flex; align-items:center; gap:10px; padding:8px 0; border-bottom:1px solid rgba(10,10,15,.05); }
        .top-item:last-child { border-bottom:none; }
        .top-rank { font-size:.7rem; font-weight:800; color:var(--ink-muted); width:16px; text-align:center; }
        .top-info { flex:1; min-width:0; }
        .top-info strong { font-size:.82rem; color:var(--ink); display:block; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .top-info span   { font-size:.7rem; color:var(--ink-muted); }
        .top-val { font-size:.82rem; font-weight:700; color:var(--ink); }

        /* ── PROGRESS BARS ───────────────────────────────────── */
        .pw { margin-bottom:9px; }
        .pw-l { display:flex; justify-content:space-between; font-size:.7rem; color:var(--ink-muted); margin-bottom:3px; }
        .pw-bg { background:var(--surface); border-radius:100px; height:6px; overflow:hidden; }
        .pw-f  { height:100%; border-radius:100px; background:var(--accent); }

        /* ── SETTINGS ────────────────────────────────────────── */
        .sc { background:var(--card); border-radius:var(--r-xl); box-shadow:var(--shadow); overflow:hidden; margin-bottom:13px; animation:fadeUp .4s ease both; }
        .sc-hdr { padding:.9rem 1.4rem; border-bottom:1px solid rgba(10,10,15,.06); font-size:.87rem; font-weight:700; color:var(--ink); display:flex; align-items:center; gap:7px; }
        .sc-hdr i { color:var(--accent); }
        .sc-row { display:flex; align-items:center; justify-content:space-between; padding:.9rem 1.4rem; border-bottom:1px solid rgba(10,10,15,.044); gap:1rem; flex-wrap:wrap; }
        .sc-row:last-child { border-bottom:none; }
        .sc-i strong { font-size:.85rem; font-weight:700; color:var(--ink); display:block; }
        .sc-i span   { font-size:.76rem; color:var(--ink-muted); }
        .f-input { background:var(--surface); border:1.5px solid rgba(10,10,15,.1); border-radius:var(--r-md); padding:8px 11px; font-family:'Red Hat Display',sans-serif; font-size:.85rem; color:var(--ink); outline:none; transition:border-color .2s, box-shadow .2s; width:265px; }
        .f-input:focus { border-color:var(--accent); box-shadow:0 0 0 3px rgba(12,177,0,.1); }
        .toggle { position:relative; display:inline-block; width:40px; height:22px; flex-shrink:0; }
        .toggle input { opacity:0; width:0; height:0; }
        .tgl-sl { position:absolute; cursor:pointer; inset:0; background:#d1d5db; border-radius:100px; transition:.2s; }
        .tgl-sl::before { content:''; position:absolute; height:16px; width:16px; left:3px; bottom:3px; background:#fff; border-radius:50%; transition:.2s; box-shadow:0 1px 4px rgba(0,0,0,.2); }
        .toggle input:checked + .tgl-sl { background:var(--accent); }
        .toggle input:checked + .tgl-sl::before { transform:translateX(18px); }

        /* ── CAT GRID ────────────────────────────────────────── */
        .cg { display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:12px; }
        .cg-c { background:var(--card); border-radius:var(--r-lg); padding:1rem 1.1rem; box-shadow:var(--shadow); display:flex; align-items:center; gap:12px; animation:fadeUp .4s ease both; transition:box-shadow .18s; }
        .cg-c:hover { box-shadow:0 4px 24px rgba(10,10,15,.13); }
        .cg-ico { width:42px; height:42px; border-radius:var(--r-md); background:var(--accent-dim); display:flex; align-items:center; justify-content:center; color:var(--accent); font-size:1.05rem; flex-shrink:0; }
        .cg-b { flex:1; min-width:0; }
        .cg-b strong { font-size:.86rem; font-weight:700; color:var(--ink); display:block; }
        .cg-b span   { font-size:.73rem; color:var(--ink-muted); }
        .cg-b .slug-tag { font-size:.62rem; background:var(--surface); color:var(--ink-muted); border-radius:4px; padding:1px 5px; margin-top:2px; display:inline-block; font-family:monospace; }
        .cg-actions { display:flex; gap:4px; flex-shrink:0; }

        /* ── ORPHAN ALERT ────────────────────────────────────── */
        .orphan-alert { background:#fef9c3; border:1.5px solid #fde047; border-radius:var(--r-md); padding:.8rem 1rem; margin-bottom:1.1rem; font-size:.83rem; color:#854d0e; display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap; }

        /* ── FILTER TABS ─────────────────────────────────────── */
        .ftabs { display:flex; gap:5px; flex-wrap:wrap; margin-bottom:1rem; }
        .ftab { padding:5px 13px; border-radius:100px; font-size:.77rem; font-weight:700; border:1.5px solid rgba(10,10,15,.1); background:var(--card); color:var(--ink-muted); cursor:pointer; text-decoration:none; transition:all .14s; }
        .ftab:hover  { border-color:var(--accent); color:var(--accent); text-decoration:none; }
        .ftab.active { background:var(--accent); border-color:var(--accent); color:#fff; }

        /* ── STOCK EDIT ──────────────────────────────────────── */
        .se-form { display:inline-flex; align-items:center; gap:4px; }
        .se-num { width:54px; padding:4px 6px; background:var(--surface); border:1px solid rgba(10,10,15,.1); border-radius:6px; font-family:'Red Hat Display',sans-serif; font-size:.81rem; text-align:center; outline:none; color:var(--ink); }
        .se-num:focus { border-color:var(--accent); }

        /* ── MODAL ───────────────────────────────────────────── */
        .m-overlay { display:none; position:fixed; inset:0; z-index:1000; background:rgba(10,10,15,.55); backdrop-filter:blur(4px); align-items:center; justify-content:center; padding:1rem; }
        .m-overlay.open { display:flex; }
        .modal { background:var(--card); border-radius:var(--r-xl); box-shadow:0 24px 80px rgba(10,10,15,.22); width:100%; max-width:580px; max-height:92vh; overflow-y:auto; animation:modalIn .22s ease; }
        @keyframes modalIn { from{opacity:0;transform:scale(.96) translateY(8px)} to{opacity:1;transform:scale(1) translateY(0)} }
        .m-hdr { padding:1.35rem 1.5rem 1rem; display:flex; align-items:center; justify-content:space-between; border-bottom:1px solid rgba(10,10,15,.07); }
        .m-hdr h3 { font-size:1rem; font-weight:800; color:var(--ink); }
        .m-close { background:none; border:none; cursor:pointer; color:var(--ink-muted); font-size:1rem; padding:4px; transition:color .14s; }
        .m-close:hover { color:var(--ink); }
        .m-body { padding:1.2rem 1.5rem; }
        .m-foot { padding:.85rem 1.5rem; border-top:1px solid rgba(10,10,15,.07); display:flex; justify-content:flex-end; gap:8px; }
        .fg { margin-bottom:.95rem; }
        .fg label { display:block; font-size:.76rem; font-weight:700; color:var(--ink-soft); margin-bottom:5px; letter-spacing:.02em; }
        .fg label span { color:#ef4444; }
        .fc { width:100%; background:var(--surface); border:1.5px solid rgba(10,10,15,.1); border-radius:var(--r-md); padding:9px 11px; font-family:'Red Hat Display',sans-serif; font-size:.875rem; color:var(--ink); outline:none; transition:border-color .2s, box-shadow .2s; }
        .fc:focus { border-color:var(--accent); box-shadow:0 0 0 3px rgba(12,177,0,.1); background:#fff; }
        textarea.fc { resize:vertical; min-height:78px; }
        .f-row { display:grid; grid-template-columns:1fr 1fr; gap:11px; }
        .f-hint { font-size:.7rem; color:var(--ink-muted); margin-top:3px; }
        .img-prev { width:100%; height:130px; background:var(--surface); border-radius:var(--r-md); border:1.5px dashed rgba(10,10,15,.12); display:flex; align-items:center; justify-content:center; margin-bottom:8px; overflow:hidden; position:relative; }
        .img-prev img { max-width:100%; max-height:100%; object-fit:contain; display:none; }
        .img-prev .iph { color:var(--ink-muted); font-size:1.7rem; }
        /* Icon picker */
        .icon-grid { display:flex; flex-wrap:wrap; gap:6px; margin-top:5px; }
        .icon-opt { width:34px; height:34px; border-radius:var(--r-sm); background:var(--surface); border:1.5px solid rgba(10,10,15,.1); display:flex; align-items:center; justify-content:center; cursor:pointer; transition:all .14s; font-size:.9rem; color:var(--ink-muted); }
        .icon-opt:hover   { border-color:var(--accent); color:var(--accent); background:var(--accent-dim); }
        .icon-opt.selected{ border-color:var(--accent); color:var(--accent); background:var(--accent-dim); }
        .icon-preview { width:38px; height:38px; border-radius:var(--r-md); background:var(--accent-dim); display:flex; align-items:center; justify-content:center; color:var(--accent); font-size:1.1rem; flex-shrink:0; }

        /* ── FLASH ───────────────────────────────────────────── */
        .flash { display:flex; align-items:center; gap:9px; padding:.85rem 1.1rem; border-radius:var(--r-md); font-size:.86rem; font-weight:600; margin-bottom:1.2rem; animation:fadeUp .3s ease; }
        .flash.success { background:#dcfce7; color:#15803d; border:1px solid #bbf7d0; }
        .flash.error   { background:#fee2e2; color:#dc2626; border:1px solid #fca5a5; }
        .db-warn { background:#fef9c3; border:1px solid #fde047; color:#854d0e; border-radius:var(--r-md); padding:.7rem 1rem; font-size:.81rem; font-weight:600; margin-bottom:1.1rem; display:flex; align-items:center; gap:7px; }

        /* ── ROLE CHIP ───────────────────────────────────────── */
        .role-chip { display:inline-flex; align-items:center; gap:5px; font-size:.62rem; font-weight:700; padding:2px 8px; border-radius:100px; }
        .role-chip.superadmin { background:rgba(12,177,0,.15); color:#0cb100; }
        .role-chip.admin      { background:rgba(59,130,246,.12); color:#1d4ed8; }

        /* ── ANIMATION ───────────────────────────────────────── */
        @keyframes fadeUp { from{opacity:0;transform:translateY(11px)} to{opacity:1;transform:translateY(0)} }

        /* ── SCROLLBAR ───────────────────────────────────────── */
        ::-webkit-scrollbar{width:5px;height:5px} ::-webkit-scrollbar-track{background:transparent} ::-webkit-scrollbar-thumb{background:rgba(10,10,15,.11);border-radius:10px}

        /* ── RESPONSIVE ──────────────────────────────────────── */
        @media(max-width:960px){
            :root{--sidebar-w:58px}
            .sb-logo-text,.nav-link span,.sb-info,.sb-section,.nav-badge{display:none}
            .nav-link{justify-content:center;padding:10px} .sb-logo{justify-content:center;padding:1rem}
            .sb-profile{justify-content:center} .sb-logout{display:none}
        }
        @media(max-width:640px){ .g2,.f-row{grid-template-columns:1fr} .stats-g{grid-template-columns:1fr 1fr} .content{padding:1rem} }
    </style>
</head>
<body>

<!-- SIDEBAR -->
<aside class="sidebar">
    <div class="sb-logo">
        <div class="sb-logo-icon"><i class="fas fa-shield-halved"></i></div>
        <div class="sb-logo-text">IT Shop.LK <span>Admin Panel</span></div>
    </div>
    <nav class="sb-nav">
        <div class="sb-section">Main</div>
        <?php foreach ($nav_items as $item): ?>
        <?php if (!in_array($admin_role, $item['roles'])) continue; ?>
        <a href="?page=<?= $item['page'] ?>" class="nav-link <?= $active===$item['page']?'active':'' ?>">
            <i class="fas <?= $item['icon'] ?>"></i>
            <span><?= $item['label'] ?></span>
            <?php if ($item['page']==='orders' && $pending_count>0): ?><span class="nav-badge"><?= $pending_count ?></span><?php endif; ?>
            <?php if ($item['page']==='categories' && count($orphan_cats)>0): ?><span class="nav-badge"><?= count($orphan_cats) ?></span><?php endif; ?>
        </a>
        <?php endforeach; ?>
        <div class="sb-section">Account</div>
        <a href="?action=logout" class="nav-link" onclick="return confirm('Log out of admin panel?')">
            <i class="fas fa-right-from-bracket"></i><span>Logout</span>
        </a>
    </nav>
    <div class="sb-foot">
        <div class="sb-profile">
            <div class="sb-avatar"><?= strtoupper(substr($admin_email,0,1)) ?></div>
            <div class="sb-info">
                <div class="sb-name"><?= htmlspecialchars($admin_email) ?></div>
                <div class="sb-role"><?= $is_superadmin ? 'Super Admin' : 'Admin' ?></div>
            </div>
            <a href="?action=logout" class="sb-logout" onclick="return confirm('Log out?')" title="Logout"><i class="fas fa-right-from-bracket"></i></a>
        </div>
    </div>
</aside>

<!-- MAIN -->
<div class="main">
    <header class="topbar">
        <div>
            <?php $ptitles=['dashboard'=>'Dashboard','products'=>'Products','orders'=>'Orders','customers'=>'Customers','categories'=>'Categories','reports'=>'Reports','settings'=>'Settings']; ?>
            <div class="tb-title"><?= $ptitles[$active]??'Dashboard' ?></div>
            <div class="tb-bc">IT Shop.LK / <?= ucfirst($active) ?>
                <span class="role-chip <?= $admin_role ?>" style="margin-left:6px">
                    <i class="fas fa-<?= $is_superadmin?'shield-halved':'user' ?>"></i>
                    <?= $is_superadmin?'Super Admin':'Admin' ?>
                </span>
            </div>
        </div>
        <div class="tb-search"><i class="fas fa-search"></i><input type="text" placeholder="Search tables…" id="gSearch"></div>
        <div class="tb-actions">
            <span class="tb-btn" title="Alerts">
                <i class="fas fa-bell"></i>
                <?php if ($pending_count>0||($agg['out_of_stock']>0)||count($orphan_cats)>0): ?><span class="notif-dot"></span><?php endif; ?>
            </span>
            <a href="../index.php" class="tb-btn" title="View Store" target="_blank"><i class="fas fa-arrow-up-right-from-square"></i></a>
        </div>
    </header>

    <main class="content">

        <?php if ($db_error): ?>
        <div class="db-warn"><i class="fas fa-triangle-exclamation"></i> DB not connected — live data unavailable. (<?= htmlspecialchars(substr($db_error,0,130)) ?>)</div>
        <?php endif; ?>

        <?php if ($flash): ?>
        <div class="flash <?= $flash['type'] ?>">
            <i class="fas fa-<?= $flash['type']==='success'?'circle-check':'circle-exclamation' ?>"></i>
            <?= htmlspecialchars($flash['msg']) ?>
        </div>
        <?php endif; ?>


        <?php /* ─────────── DASHBOARD ─────────── */ ?>
        <?php if ($active==='dashboard'): ?>

        <div class="pg-hdr">
            <div class="pg-hdr-l">
                <h2>Good <?= date('H')<12?'Morning':(date('H')<17?'Afternoon':'Evening') ?> 👋</h2>
                <p><?= date('l, d F Y') ?> — store at a glance</p>
            </div>
            <div class="pg-hdr-r">
                <button class="btn-p" onclick="openModal('addProductModal')"><i class="fas fa-plus"></i> Add Product</button>
            </div>
        </div>

        <div class="stats-g">
            <?php
            $scards=[
                ['Total Products',  $agg['total_products'],           ($agg['low_stock']??0).' low stock',false,'fa-box','#0cb100'],
                ['Orders (All)',    $order_stats['total_orders'],      ($order_stats['today']??0).' today',  true,'fa-bag-shopping','#3b82f6'],
                ['Pending Orders',  $pending_count,                    'awaiting action',                   ($pending_count===0),'fa-clock','#f59e0b'],
                ['Out of Stock',    $agg['out_of_stock']??0,          'need restocking',                   false,'fa-triangle-exclamation','#ef4444'],
            ];
            foreach ($scards as $i=>$s): ?>
            <div class="stat-c" style="animation-delay:<?= $i*55 ?>ms">
                <div class="stat-ico" style="background:<?= $s[5] ?>18;color:<?= $s[5] ?>"><i class="fas <?= $s[4] ?>"></i></div>
                <div class="stat-b">
                    <div class="stat-lbl"><?= $s[0] ?></div>
                    <div class="stat-val"><?= $s[1] ?></div>
                    <span class="stat-d <?= $s[3]?'up':'dn' ?>"><i class="fas fa-arrow-<?= $s[3]?'up':'down' ?>"></i> <?= $s[2] ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="g2">
            <div class="cc">
                <h3>Products per Category</h3>
                <p>Live count from database</p>
                <?php if ($cat_counts):
                    $bars = array_filter($cat_counts, fn($c)=>$c['cnt']>0);
                    if ($bars):
                        $mc=max(array_column(array_values($bars),'cnt')); ?>
                <div class="bar-chart">
                    <?php foreach (array_slice(array_values($bars),0,9) as $cc):
                        $h=$mc>0?round($cc['cnt']/$mc*90):0; ?>
                    <div class="bar-col">
                        <div class="bar" style="height:<?= $h ?>px" title="<?= htmlspecialchars($cc['name']) ?>: <?= $cc['cnt'] ?>"></div>
                        <div class="bar-lbl"><?= strtoupper(substr($cc['slug']??$cc['name'],0,3)) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; else: ?><p style="color:var(--ink-muted);font-size:.82rem;margin-top:.5rem">No categories yet.</p><?php endif; ?>
            </div>

            <div class="cc">
                <h3>Highest Stock Items</h3>
                <p>Top 5 by quantity on hand</p>
                <?php
                $ts=array_filter($db_products,fn($p)=>$p['stock_count']>0);
                usort($ts,fn($a,$b)=>$b['stock_count']-$a['stock_count']);
                foreach (array_slice($ts,0,5) as $i=>$p): ?>
                <div class="top-item">
                    <div class="top-rank">#<?= $i+1 ?></div>
                    <div class="top-info">
                        <strong><?= htmlspecialchars($p['name']) ?></strong>
                        <span><?= htmlspecialchars($cat_map[$p['category']]['label'] ?? ucfirst($p['category'])) ?></span>
                    </div>
                    <div class="top-val"><?= $p['stock_count'] ?></div>
                </div>
                <?php endforeach; ?>
                <?php if (!$ts): ?><p style="color:var(--ink-muted);font-size:.82rem;padding:.5rem 0">No stocked products.</p><?php endif; ?>
            </div>
        </div>

        <div class="tc">
            <div class="tc-hdr">
                <div><h3>Recent Orders</h3><p>Latest transactions</p></div>
                <a href="?page=orders" class="btn-o btn-sm"><i class="fas fa-arrow-right"></i> View All</a>
            </div>
            <div class="tbl-wrap">
            <?php if ($db_orders): ?>
            <table>
                <thead><tr><th>ID</th><th>Customer</th><th>Total</th><th>Status</th><th>Date</th></tr></thead>
                <tbody>
                    <?php foreach (array_slice($db_orders,0,6) as $o):
                        $st=strtolower($o['status']??'pending');
                        $sc=$status_colors[$st]??['bg'=>'#f3f4f6','color'=>'#6b7280']; ?>
                    <tr>
                        <td><strong>#<?= $o['id'] ?></strong></td>
                        <td><?= htmlspecialchars($o['customer_name']??'Guest') ?></td>
                        <td><strong>LKR <?= number_format($o['total']??0) ?></strong></td>
                        <td><span class="badge" style="background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>"><?= ucfirst($st) ?></span></td>
                        <td class="muted"><?= date('d M Y',strtotime($o['created_at']??'now')) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div style="padding:2.5rem;text-align:center;color:var(--ink-muted);font-size:.85rem">No orders yet.</div>
            <?php endif; ?>
            </div>
        </div>


        <?php /* ─────────── PRODUCTS ─────────── */ ?>
        <?php elseif ($active==='products'): ?>

        <div class="pg-hdr">
            <div class="pg-hdr-l">
                <h2>Products</h2>
                <p><?= count($db_products) ?> products · <?= $agg['out_of_stock']??0 ?> out of stock · <?= $agg['low_stock']??0 ?> low stock</p>
            </div>
            <div class="pg-hdr-r">
                <button class="btn-o btn-sm" onclick="exportTable('prodTable')"><i class="fas fa-download"></i> Export CSV</button>
                <button class="btn-p" onclick="openModal('addProductModal')"><i class="fas fa-plus"></i> Add Product</button>
            </div>
        </div>

        <?php if (empty($cat_map)): ?>
        <div class="db-warn"><i class="fas fa-layer-group"></i> No categories found in database. <a href="?page=categories" style="color:inherit;font-weight:800;margin-left:4px">Create categories first →</a></div>
        <?php endif; ?>

        <div class="ftabs" id="catTabs">
            <a href="#" class="ftab active" data-cat="all">All (<?= count($db_products) ?>)</a>
            <?php foreach ($cat_counts as $cc): if ($cc['cnt'] < 1) continue; ?>
            <a href="#" class="ftab" data-cat="<?= htmlspecialchars($cc['slug']) ?>">
                <?= htmlspecialchars($cc['name']) ?> (<?= $cc['cnt'] ?>)
            </a>
            <?php endforeach; ?>
            <?php foreach ($orphan_cats as $oc): ?>
            <a href="#" class="ftab" data-cat="<?= htmlspecialchars($oc['category']) ?>" style="border-color:#f59e0b;color:#92400e">
                ⚠ <?= htmlspecialchars($oc['category']) ?> (<?= $oc['cnt'] ?>)
            </a>
            <?php endforeach; ?>
        </div>

        <div class="tc">
            <div class="tc-hdr">
                <div><h3>Product Inventory</h3><p>Edit stock inline · press Enter or ✓ to save</p></div>
                <input type="text" class="tbl-s" placeholder="Search products…" id="prodSearch" style="width:185px">
            </div>
            <div class="tbl-wrap">
            <table id="prodTable">
                <thead>
                    <tr><th>ID</th><th>Image</th><th>Product / Specs</th><th>Brand</th><th>Category</th><th>Price (LKR)</th><th>Orig. Price</th><th>Stock</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody>
                <?php if ($db_products): foreach ($db_products as $p):
                    $stk=(int)$p['stock_count'];
                    $scls=$stk===0?'so':($stk<=5?'sl':'sa');
                    $slbl=$stk===0?'Out of Stock':($stk<=5?'Low Stock':'Active');
                    $cat_label=$cat_map[$p['category']]['label'] ?? ucfirst($p['category']);
                    $is_orphan = $p['category'] && !isset($cat_map[$p['category']]);
                ?>
                <tr data-cat="<?= htmlspecialchars($p['category']) ?>">
                    <td class="muted"><?= $p['id'] ?></td>
                    <td>
                        <?php if ($p['image']): ?>
                        <img src="../<?= htmlspecialchars($p['image']) ?>" style="width:38px;height:38px;object-fit:contain;border-radius:6px;background:var(--surface)" onerror="this.style.display='none'">
                        <?php else: ?>
                        <div style="width:38px;height:38px;background:var(--surface);border-radius:6px;display:flex;align-items:center;justify-content:center;color:var(--ink-muted);font-size:.77rem"><i class="fas fa-image"></i></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <strong><?= htmlspecialchars($p['name']) ?></strong>
                        <?php if ($p['specs']): ?>
                        <div style="font-size:.68rem;color:var(--ink-muted);margin-top:2px;max-width:210px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($p['specs']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($p['brand']??'—') ?></td>
                    <td>
                        <span class="sb <?= $is_orphan?'sw':'si' ?>" style="font-size:.67rem" title="<?= $is_orphan?'Category not in DB — orphan':'Mapped category' ?>">
                            <?php if ($is_orphan): ?><i class="fas fa-triangle-exclamation" style="font-size:.6rem"></i> <?php endif; ?>
                            <?= htmlspecialchars($cat_label) ?>
                        </span>
                    </td>
                    <td><strong><?= number_format($p['price'],2) ?></strong></td>
                    <td class="muted"><?= $p['original_price']?number_format($p['original_price'],2):'—' ?></td>
                    <td>
                        <form method="POST" class="se-form">
                            <input type="hidden" name="_action" value="update_stock">
                            <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                            <input type="number" name="stock_count" value="<?= $stk ?>" min="0" class="se-num" title="Edit and press Enter">
                            <button type="submit" class="ab warn" title="Save stock"><i class="fas fa-check"></i></button>
                        </form>
                    </td>
                    <td><span class="sb <?= $scls ?>"><?= $slbl ?></span></td>
                    <td>
                        <div style="display:flex;gap:4px">
                            <a href="../product-details.php?id=<?= $p['id'] ?>" class="ab" target="_blank" title="View on site"><i class="fas fa-eye"></i></a>
                            <form method="POST" style="display:inline" onsubmit="return confirm('Delete product #<?= $p['id'] ?> — <?= addslashes(htmlspecialchars($p['name'])) ?>?\nThis also removes its specs.')">
                                <input type="hidden" name="_action" value="delete_product">
                                <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                                <button type="submit" class="ab del" title="Delete"><i class="fas fa-trash"></i></button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="10" style="text-align:center;padding:3rem;color:var(--ink-muted)">No products found in database.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>


        <?php /* ─────────── ORDERS ─────────── */ ?>
        <?php elseif ($active==='orders'): ?>

        <div class="pg-hdr">
            <div class="pg-hdr-l">
                <h2>Orders</h2>
                <p><?= count($db_orders) ?> orders loaded<?= $pending_count?" · <span style='color:#f59e0b'>$pending_count pending</span>":'' ?></p>
            </div>
            <div class="pg-hdr-r">
                <button class="btn-o btn-sm" onclick="exportTable('orderTable')"><i class="fas fa-download"></i> Export CSV</button>
            </div>
        </div>

        <div class="ftabs" id="orderTabs">
            <?php foreach (['all','pending','processing','shipped','completed','cancelled'] as $tab): ?>
            <a href="#" class="ftab <?= $tab==='all'?'active':'' ?>" data-filter="<?= $tab ?>"><?= ucfirst($tab) ?></a>
            <?php endforeach; ?>
        </div>

        <div class="tc">
            <div class="tc-hdr">
                <div><h3>Order List</h3><p>Update order status inline</p></div>
                <input type="text" class="tbl-s" placeholder="Search…" id="orderSearch" style="width:185px">
            </div>
            <div class="tbl-wrap">
            <?php if ($db_orders): ?>
            <table id="orderTable">
                <thead><tr><th>ID</th><th>Customer</th><th>Email</th><th>Total (LKR)</th><th>Status</th><th>Update Status</th><th>Date</th></tr></thead>
                <tbody>
                    <?php foreach ($db_orders as $o):
                        $st=strtolower($o['status']??'pending');
                        $sc=$status_colors[$st]??['bg'=>'#f3f4f6','color'=>'#6b7280']; ?>
                    <tr data-status="<?= $st ?>">
                        <td><strong>#<?= $o['id'] ?></strong></td>
                        <td><?= htmlspecialchars($o['customer_name']??'Guest') ?></td>
                        <td class="muted"><?= htmlspecialchars($o['customer_email']??'—') ?></td>
                        <td><strong><?= number_format($o['total']??0) ?></strong></td>
                        <td><span class="badge" style="background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>"><?= ucfirst($st) ?></span></td>
                        <td>
                            <form method="POST" style="display:inline-flex;align-items:center;gap:4px">
                                <input type="hidden" name="_action" value="update_order_status">
                                <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                                <select name="status" class="tbl-s" style="width:125px;padding:5px 7px;font-size:.77rem">
                                    <?php foreach (['pending','processing','shipped','completed','cancelled'] as $sv): ?>
                                    <option value="<?= $sv ?>" <?= $sv===$st?'selected':'' ?>><?= ucfirst($sv) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="ab" title="Save status"><i class="fas fa-check"></i></button>
                            </form>
                        </td>
                        <td class="muted"><?= date('d M Y',strtotime($o['created_at']??'now')) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div style="padding:2.5rem;text-align:center;color:var(--ink-muted)">No orders yet.</div>
            <?php endif; ?>
            </div>
        </div>


        <?php /* ─────────── CUSTOMERS ─────────── */ ?>
        <?php elseif ($active==='customers'): ?>

        <div class="pg-hdr">
            <div class="pg-hdr-l"><h2>Customers</h2><p><?= count($db_customers) ?> registered users</p></div>
            <button class="btn-o btn-sm" onclick="exportTable('custTable')"><i class="fas fa-download"></i> Export CSV</button>
        </div>

        <div class="tc">
            <div class="tc-hdr">
                <div><h3>Customer Directory</h3><p>All registered users from database</p></div>
                <input type="text" class="tbl-s" placeholder="Search…" id="custSearch" style="width:185px">
            </div>
            <div class="tbl-wrap">
            <?php if ($db_customers): ?>
            <table id="custTable">
                <thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Joined</th></tr></thead>
                <tbody>
                    <?php foreach ($db_customers as $c): ?>
                    <tr>
                        <td class="muted"><?= $c['id'] ?></td>
                        <td>
                            <div style="display:flex;align-items:center;gap:9px">
                                <div style="width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,var(--accent),#7dfc9b);display:flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:800;color:#fff;flex-shrink:0">
                                    <?= strtoupper(substr($c['name']??'U',0,1)) ?>
                                </div>
                                <strong><?= htmlspecialchars($c['name']??'Unknown') ?></strong>
                            </div>
                        </td>
                        <td><?= htmlspecialchars($c['email']??'—') ?></td>
                        <td class="muted"><?= isset($c['created_at'])?date('d M Y',strtotime($c['created_at'])):'—' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div style="padding:2.5rem;text-align:center;color:var(--ink-muted)">No customers yet.</div>
            <?php endif; ?>
            </div>
        </div>


        <?php /* ─────────── CATEGORIES ─────────── */ ?>
        <?php elseif ($active==='categories'): ?>

        <div class="pg-hdr">
            <div class="pg-hdr-l">
                <h2>Categories</h2>
                <p><?= count($db_categories) ?> categories in DB · <?= count($orphan_cats) ?> orphaned product slugs</p>
            </div>
            <div class="pg-hdr-r">
                <?php if (count($orphan_cats)>0): ?>
                <form method="POST" style="display:inline" onsubmit="return confirm('Auto-import <?= count($orphan_cats) ?> orphan category slug(s) into the categories table?')">
                    <input type="hidden" name="_action" value="import_orphans">
                    <button type="submit" class="btn-o btn-sm btn-warn"><i class="fas fa-file-import"></i> Import <?= count($orphan_cats) ?> Orphan<?= count($orphan_cats)>1?'s':'' ?></button>
                </form>
                <?php endif; ?>
                <button class="btn-p" onclick="openModal('addCatModal')"><i class="fas fa-plus"></i> Add Category</button>
            </div>
        </div>

        <?php if (count($orphan_cats)>0): ?>
        <div class="orphan-alert">
            <span><i class="fas fa-triangle-exclamation"></i> <strong><?= count($orphan_cats) ?> product category slug(s)</strong> exist in products but have no matching categories row:
            <?php foreach ($orphan_cats as $oc): ?>
                <code style="background:rgba(0,0,0,.08);padding:1px 5px;border-radius:4px;margin-left:4px"><?= htmlspecialchars($oc['category']) ?></code>
            <?php endforeach; ?>
            </span>
            <span style="font-size:.77rem;color:#92400e">These products show ⚠ in the Products page. Click "Import Orphans" to fix.</span>
        </div>
        <?php endif; ?>

        <?php if (count($db_categories)===0): ?>
        <div class="db-warn"><i class="fas fa-layer-group"></i> No categories yet. Add your first category to get started. Categories are used as dropdown options when adding products.</div>
        <?php endif; ?>

        <!-- Category Cards -->
        <div class="cg">
            <?php foreach ($db_categories as $i=>$cat):
                $sl = $cat['slug'] ?: slugify($cat['name']);
                // Find cnt for this category
                $cat_cnt = 0; $cat_stock = 0;
                foreach ($cat_counts as $cc) { if ($cc['id']==$cat['id']) { $cat_cnt=$cc['cnt']; $cat_stock=$cc['stock']; break; } }
            ?>
            <div class="cg-c" style="animation-delay:<?= $i*35 ?>ms">
                <div class="cg-ico"><i class="fas <?= htmlspecialchars($cat['icon']??'fa-tag') ?>"></i></div>
                <div class="cg-b">
                    <strong><?= htmlspecialchars($cat['name']) ?></strong>
                    <span><?= $cat_cnt ?> product<?= $cat_cnt!=1?'s':'' ?> · <?= (int)$cat_stock ?> units</span>
                    <div class="slug-tag"><?= htmlspecialchars($sl) ?></div>
                </div>
                <div class="cg-actions">
                    <button class="ab edit" title="Edit" onclick="openEditCat(<?= htmlspecialchars(json_encode([
                        'id'=>$cat['id'],'name'=>$cat['name'],'slug'=>$sl,
                        'desc'=>$cat['description']??'','icon'=>$cat['icon']??'fa-tag'
                    ])) ?>)"><i class="fas fa-pen"></i></button>
                    <button class="ab del" title="Delete" onclick="openDeleteCat(<?= htmlspecialchars(json_encode([
                        'id'=>$cat['id'],'name'=>$cat['name'],'slug'=>$sl,'cnt'=>$cat_cnt
                    ])) ?>)"><i class="fas fa-trash"></i></button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Category Table -->
        <div class="tc" style="margin-top:1.35rem">
            <div class="tc-hdr">
                <div><h3>All Categories</h3><p>Stored in <code style="background:var(--surface);padding:1px 5px;border-radius:4px">categories</code> table · slug maps to <code style="background:var(--surface);padding:1px 5px;border-radius:4px">products.category</code></p></div>
                <input type="text" class="tbl-s" placeholder="Search…" id="catSearch" style="width:170px">
            </div>
            <div class="tbl-wrap">
            <table id="catTable">
                <thead><tr><th>ID</th><th>Icon</th><th>Name</th><th>Slug (FK)</th><th>Description</th><th>Products</th><th>Stock</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($db_categories as $cat):
                        $sl = $cat['slug'] ?: slugify($cat['name']);
                        $cat_cnt = 0; $cat_stock = 0;
                        foreach ($cat_counts as $cc) { if ($cc['id']==$cat['id']) { $cat_cnt=$cc['cnt']; $cat_stock=$cc['stock']; break; } }
                    ?>
                    <tr>
                        <td class="muted"><?= $cat['id'] ?></td>
                        <td><div style="width:30px;height:30px;border-radius:6px;background:var(--accent-dim);display:flex;align-items:center;justify-content:center;color:var(--accent)"><i class="fas <?= htmlspecialchars($cat['icon']??'fa-tag') ?>"></i></div></td>
                        <td><strong><?= htmlspecialchars($cat['name']) ?></strong></td>
                        <td><code style="background:var(--surface);padding:2px 7px;border-radius:5px;font-size:.74rem"><?= htmlspecialchars($sl) ?></code></td>
                        <td class="muted" style="max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($cat['description']??'—') ?></td>
                        <td><?= $cat_cnt ?></td>
                        <td><?= (int)$cat_stock ?></td>
                        <td>
                            <div style="display:flex;gap:4px">
                                <button class="ab edit" title="Edit" onclick="openEditCat(<?= htmlspecialchars(json_encode([
                                    'id'=>$cat['id'],'name'=>$cat['name'],'slug'=>$sl,
                                    'desc'=>$cat['description']??'','icon'=>$cat['icon']??'fa-tag'
                                ])) ?>)"><i class="fas fa-pen"></i></button>
                                <button class="ab del" title="Delete" onclick="openDeleteCat(<?= htmlspecialchars(json_encode([
                                    'id'=>$cat['id'],'name'=>$cat['name'],'slug'=>$sl,'cnt'=>$cat_cnt
                                ])) ?>)"><i class="fas fa-trash"></i></button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$db_categories): ?>
                    <tr><td colspan="8" style="text-align:center;padding:2rem;color:var(--ink-muted)">No categories yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>


        <?php /* ─────────── REPORTS ─────────── */ ?>
        <?php elseif ($active==='reports'): ?>

        <div class="pg-hdr">
            <div class="pg-hdr-l"><h2>Reports</h2><p>Live analytics from database</p></div>
            <button class="btn-o btn-sm"><i class="fas fa-download"></i> Export</button>
        </div>

        <div class="stats-g" style="margin-bottom:1.25rem">
            <?php
            $rs=[
                ['Total Revenue',   'LKR '.number_format($order_stats['revenue']??0), 'all orders', true, '#0cb100'],
                ['Total Orders',    $order_stats['total_orders']??count($db_orders),  'all time',   true, '#3b82f6'],
                ['Pending Orders',  $pending_count,                                   'to fulfill', ($pending_count===0), '#f59e0b'],
                ['Catalogue Size',  $agg['total_products'],                           'products',   true, '#8b5cf6'],
            ];
            foreach ($rs as $i=>$r): ?>
            <div class="stat-c" style="animation-delay:<?= $i*55 ?>ms">
                <div class="stat-ico" style="background:<?= $r[4] ?>18;color:<?= $r[4] ?>"><i class="fas fa-chart-simple"></i></div>
                <div class="stat-b">
                    <div class="stat-lbl"><?= $r[0] ?></div>
                    <div class="stat-val"><?= $r[1] ?></div>
                    <span class="stat-d <?= $r[3]?'up':'dn' ?>"><i class="fas fa-arrow-<?= $r[3]?'up':'down' ?>"></i> <?= $r[2] ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="g2">
            <div class="cc">
                <h3>Products by Category</h3>
                <p>Distribution of catalogue items</p>
                <?php if ($cat_counts):
                    $has_products = array_filter($cat_counts, fn($c)=>$c['cnt']>0);
                    if ($has_products):
                        $mc2=max(array_column(array_values($has_products),'cnt'));
                        foreach ($has_products as $cc):
                            $pct=$mc2>0?round($cc['cnt']/$mc2*100):0; ?>
                <div class="pw">
                    <div class="pw-l"><span><?= htmlspecialchars($cc['name']) ?></span><span><?= $cc['cnt'] ?> products</span></div>
                    <div class="pw-bg"><div class="pw-f" style="width:<?= $pct ?>%"></div></div>
                </div>
                <?php endforeach; else: ?><p style="color:var(--ink-muted);font-size:.82rem">No products assigned to categories.</p><?php endif;
                endif; ?>
            </div>

            <div class="cc">
                <h3>Order Status Breakdown</h3>
                <p>All orders by current status</p>
                <?php
                $osm=[];
                foreach ($db_orders as $o) { $s=strtolower($o['status']??'pending'); $osm[$s]=($osm[$s]??0)+1; }
                $ost=array_sum($osm);
                $oscol=['completed'=>'#0cb100','shipped'=>'#3b82f6','processing'=>'#8b5cf6','pending'=>'#f59e0b','cancelled'=>'#ef4444'];
                foreach ($osm as $s=>$cnt):
                    $pct=$ost>0?round($cnt/$ost*100):0;
                    $col=$oscol[$s]??'#6b7280';
                ?>
                <div class="top-item">
                    <div style="width:9px;height:9px;border-radius:3px;background:<?= $col ?>;flex-shrink:0"></div>
                    <div class="top-info"><strong><?= ucfirst($s) ?></strong><span><?= $cnt ?> orders</span></div>
                    <div style="display:flex;align-items:center;gap:7px">
                        <div style="width:75px;background:var(--surface);border-radius:100px;height:5px;overflow:hidden"><div style="width:<?= $pct ?>%;height:100%;background:<?= $col ?>;border-radius:100px"></div></div>
                        <strong style="font-size:.8rem;width:26px;text-align:right"><?= $pct ?>%</strong>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if (!$osm): ?><p style="color:var(--ink-muted);font-size:.82rem">No order data.</p><?php endif; ?>
            </div>
        </div>

        <div class="cc">
            <h3>Inventory Health</h3>
            <p>Stock status across all products</p>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(155px,1fr));gap:11px;margin-top:.4rem">
                <?php
                $ih=[
                    ['Healthy Stock',  count(array_filter($db_products,fn($p)=>$p['stock_count']>5)),  '#0cb100'],
                    ['Low Stock (≤5)', count(array_filter($db_products,fn($p)=>$p['stock_count']>0&&$p['stock_count']<=5)), '#f59e0b'],
                    ['Out of Stock',   (int)($agg['out_of_stock']??0), '#ef4444'],
                ];
                foreach ($ih as $h): ?>
                <div style="background:var(--surface);border-radius:var(--r-md);padding:.9rem;text-align:center">
                    <div style="font-size:1.55rem;font-weight:800;color:<?= $h[2] ?>"><?= $h[1] ?></div>
                    <div style="font-size:.72rem;color:var(--ink-muted);font-weight:600;margin-top:2px"><?= $h[0] ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>


        <?php /* ─────────── SETTINGS (superadmin only) ─────────── */ ?>
        <?php elseif ($active==='settings'): ?>

        <?php if (!$is_superadmin): ?>
        <div class="db-warn"><i class="fas fa-lock"></i> Access denied. Settings are restricted to Super Admins only.</div>
        <?php else: ?>

        <div class="pg-hdr">
            <div class="pg-hdr-l"><h2>Settings</h2><p>Store configuration and preferences</p></div>
            <button class="btn-p" onclick="alert('Settings saved!')"><i class="fas fa-floppy-disk"></i> Save Changes</button>
        </div>

        <div class="sc">
            <div class="sc-hdr"><i class="fas fa-store"></i> Store Information</div>
            <?php $store_fields=[['Store Name','Public display name','text','IT Shop.LK'],['Contact Email','Order & support email','email','info@itshop.lk'],['WhatsApp Number','Floating chat button','tel','+94 77 000 0000'],['Store Address','Physical location','text','Colombo 04, Sri Lanka'],['Currency Symbol','Shown before prices','text','LKR']];
            foreach ($store_fields as $sf): ?>
            <div class="sc-row">
                <div class="sc-i"><strong><?= $sf[0] ?></strong><span><?= $sf[1] ?></span></div>
                <input type="<?= $sf[2] ?>" class="f-input" value="<?= $sf[3] ?>">
            </div>
            <?php endforeach; ?>
        </div>

        <div class="sc">
            <div class="sc-hdr"><i class="fas fa-sliders"></i> Features & Visibility</div>
            <?php $toggles=[
                ['Maintenance Mode',          'Take the store offline for visitors',        false],
                ['Show Out-of-Stock Products','Display items with stock_count = 0',          true],
                ['WhatsApp Floating Button',  'Show chat button on public pages',            true],
                ['Customer Reviews',          'Allow buyers to leave product reviews',       false],
                ['Price Range Filter',        'Show min/max filter on products.php',         true],
                ['Low Stock Badge (≤5)',      'Show "Only X left" badge on product cards',   true],
                ['Discount % Badge',          'Show discount badge when orig > price',       true],
            ];
            foreach ($toggles as $t): ?>
            <div class="sc-row">
                <div class="sc-i"><strong><?= $t[0] ?></strong><span><?= $t[1] ?></span></div>
                <label class="toggle"><input type="checkbox" <?= $t[2]?'checked':'' ?>><span class="tgl-sl"></span></label>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="sc">
            <div class="sc-hdr"><i class="fas fa-box"></i> Products Page Defaults</div>
            <div class="sc-row">
                <div class="sc-i"><strong>Items per Page</strong><span>$items_per_page in products.php</span></div>
                <input type="number" class="f-input" value="15" min="5" max="60">
            </div>
            <div class="sc-row">
                <div class="sc-i"><strong>Default Sort</strong><span>Default $sort value</span></div>
                <select class="f-input"><option value="name_asc" selected>Name A–Z</option><option value="price_low">Price: Low → High</option><option value="price_high">Price: High → Low</option><option value="rating">Top Rated</option></select>
            </div>
            <div class="sc-row">
                <div class="sc-i"><strong>Low Stock Threshold</strong><span>stock_count ≤ this → badge</span></div>
                <input type="number" class="f-input" value="5" min="1" max="50">
            </div>
        </div>

        <div class="sc">
            <div class="sc-hdr"><i class="fas fa-users-gear"></i> Admin Accounts</div>
            <div class="sc-row">
                <div class="sc-i">
                    <strong>Logged-in Account</strong>
                    <span><?= htmlspecialchars($admin_email) ?></span>
                </div>
                <span class="role-chip superadmin"><i class="fas fa-shield-halved"></i> Super Admin</span>
            </div>
            <div class="sc-row">
                <div class="sc-i"><strong>New Password</strong><span>Leave blank to keep current</span></div>
                <input type="password" class="f-input" placeholder="••••••••••">
            </div>
            <div class="sc-row">
                <div class="sc-i"><strong>Confirm Password</strong><span></span></div>
                <input type="password" class="f-input" placeholder="••••••••••">
            </div>
        </div>

        <div class="sc">
            <div class="sc-hdr" style="color:#dc2626"><i class="fas fa-triangle-exclamation" style="color:#ef4444"></i> Danger Zone</div>
            <div class="sc-row">
                <div class="sc-i"><strong style="color:#dc2626">Clear All Orders</strong><span>Permanently deletes every order record</span></div>
                <form method="POST" onsubmit="return confirm('Delete ALL orders permanently? This cannot be undone.')">
                    <input type="hidden" name="_action" value="clear_orders">
                    <button type="submit" class="btn-p btn-red btn-sm"><i class="fas fa-trash"></i> Clear Orders</button>
                </form>
            </div>
        </div>

        <?php endif; ?>

        <?php endif; /* end page switch */ ?>

    </main>
</div>


<!-- ══════════════════════════════════════════════════════════════ -->
<!-- ADD PRODUCT MODAL                                             -->
<!-- ══════════════════════════════════════════════════════════════ -->
<div class="m-overlay" id="addProductModal">
    <div class="modal">
        <div class="m-hdr">
            <h3><i class="fas fa-plus" style="color:var(--accent);margin-right:6px"></i>Add New Product</h3>
            <button class="m-close" onclick="closeModal('addProductModal')"><i class="fas fa-xmark"></i></button>
        </div>
        <form method="POST" enctype="multipart/form-data" action="?page=products">
            <input type="hidden" name="_action" value="add_product">
            <div class="m-body">

                <div class="fg">
                    <label>Product Image</label>
                    <div class="img-prev" id="imgPrev">
                        <div class="iph"><i class="fas fa-image"></i></div>
                        <img id="imgPrevImg" src="">
                    </div>
                    <div class="f-row">
                        <div>
                            <input type="file" name="image_file" accept="image/*" class="fc" style="padding:5px" onchange="prvFile(this)">
                            <div class="f-hint">Upload JPG/PNG/WebP</div>
                        </div>
                        <div>
                            <input type="text" name="image_url" class="fc" placeholder="…or paste image/path URL" oninput="prvUrl(this.value)">
                            <div class="f-hint">e.g. uploads/products/img.jpg</div>
                        </div>
                    </div>
                </div>

                <div class="f-row">
                    <div class="fg">
                        <label>Product Name <span>*</span></label>
                        <input type="text" name="name" class="fc" placeholder="e.g. ASUS ROG Zephyrus G14" required>
                    </div>
                    <div class="fg">
                        <label>Brand</label>
                        <input type="text" name="brand" class="fc" placeholder="e.g. ASUS, MSI, Samsung">
                    </div>
                </div>

                <div class="fg">
                    <label>Category <span>*</span></label>
                    <?php if (empty($cat_map)): ?>
                    <div style="background:#fef9c3;border:1px solid #fde047;border-radius:var(--r-md);padding:9px 11px;font-size:.82rem;color:#854d0e">
                        <i class="fas fa-triangle-exclamation"></i> No categories found. <a href="?page=categories" style="color:inherit;font-weight:800">Create categories first →</a>
                    </div>
                    <input type="hidden" name="category" value="">
                    <?php else: ?>
                    <select name="category" class="fc" required>
                        <option value="">— Select category —</option>
                        <?php foreach ($cat_map as $slug=>$info): ?>
                        <option value="<?= htmlspecialchars($slug) ?>"><?= htmlspecialchars($info['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                </div>

                <div class="f-row">
                    <div class="fg">
                        <label>Price (LKR) <span>*</span></label>
                        <input type="number" name="price" class="fc" placeholder="e.g. 125000.00" min="0" step="0.01" required>
                    </div>
                    <div class="fg">
                        <label>Original / MRP (LKR)</label>
                        <input type="number" name="original_price" class="fc" placeholder="Leave blank = no discount" min="0" step="0.01">
                        <div class="f-hint">Sets the discount % badge on product cards</div>
                    </div>
                </div>

                <div class="fg">
                    <label>Stock Count <span>*</span></label>
                    <input type="number" name="stock_count" class="fc" value="0" min="0" required>
                    <div class="f-hint">0 = Out of Stock · 1–5 = Low Stock badge · 6+ = Active</div>
                </div>

                <div class="fg">
                    <label>Specs <small style="font-weight:500;color:var(--ink-muted)">(one per line)</small></label>
                    <textarea name="specs" class="fc" placeholder="Intel Core i9-14900HX&#10;32GB DDR5 5600MHz&#10;1TB NVMe Gen4 SSD"></textarea>
                    <div class="f-hint">Each line inserts one row in product_specs.</div>
                </div>

            </div>
            <div class="m-foot">
                <button type="button" class="btn-o" onclick="closeModal('addProductModal')">Cancel</button>
                <button type="submit" class="btn-p"><i class="fas fa-floppy-disk"></i> Save Product</button>
            </div>
        </form>
    </div>
</div>


<!-- ══════════════════════════════════════════════════════════════ -->
<!-- ADD CATEGORY MODAL                                            -->
<!-- ══════════════════════════════════════════════════════════════ -->
<div class="m-overlay" id="addCatModal">
    <div class="modal">
        <div class="m-hdr">
            <h3><i class="fas fa-layer-group" style="color:var(--accent);margin-right:6px"></i>Add Category</h3>
            <button class="m-close" onclick="closeModal('addCatModal')"><i class="fas fa-xmark"></i></button>
        </div>
        <form method="POST" action="?page=categories">
            <input type="hidden" name="_action" value="add_category">
            <div class="m-body">
                <div class="f-row">
                    <div class="fg">
                        <label>Category Name <span>*</span></label>
                        <input type="text" name="cat_name" id="addCatName" class="fc" placeholder="e.g. Graphics Cards" required oninput="autoSlug(this,'addCatSlug')">
                    </div>
                    <div class="fg">
                        <label>Slug <span style="color:var(--ink-muted);font-weight:500">(auto-generated)</span></label>
                        <input type="text" name="cat_slug" id="addCatSlug" class="fc" placeholder="e.g. graphics_cards">
                        <div class="f-hint">Used as <code>products.category</code> foreign key</div>
                    </div>
                </div>
                <div class="fg">
                    <label>Description</label>
                    <textarea name="cat_desc" class="fc" placeholder="Optional description…"></textarea>
                </div>
                <div class="fg">
                    <label>Icon <small style="font-weight:500;color:var(--ink-muted)">(FontAwesome class)</small></label>
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
                        <div class="icon-preview" id="addIconPreview"><i class="fas fa-tag" id="addIconPreviewI"></i></div>
                        <input type="text" name="cat_icon" id="addCatIcon" class="fc" value="fa-tag" placeholder="fa-tag" oninput="updateIconPreview('add')">
                    </div>
                    <div class="icon-grid" id="addIconGrid">
                        <?php foreach ($icon_options as $ico): ?>
                        <div class="icon-opt <?= $ico==='fa-tag'?'selected':'' ?>" title="<?= $ico ?>" onclick="selectIcon('add','<?= $ico ?>')"><i class="fas <?= $ico ?>"></i></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="m-foot">
                <button type="button" class="btn-o" onclick="closeModal('addCatModal')">Cancel</button>
                <button type="submit" class="btn-p"><i class="fas fa-floppy-disk"></i> Save Category</button>
            </div>
        </form>
    </div>
</div>


<!-- ══════════════════════════════════════════════════════════════ -->
<!-- EDIT CATEGORY MODAL                                           -->
<!-- ══════════════════════════════════════════════════════════════ -->
<div class="m-overlay" id="editCatModal">
    <div class="modal">
        <div class="m-hdr">
            <h3><i class="fas fa-pen" style="color:var(--accent);margin-right:6px"></i>Edit Category</h3>
            <button class="m-close" onclick="closeModal('editCatModal')"><i class="fas fa-xmark"></i></button>
        </div>
        <form method="POST" action="?page=categories">
            <input type="hidden" name="_action" value="edit_category">
            <input type="hidden" name="cat_id"   id="editCatId">
            <input type="hidden" name="old_slug"  id="editOldSlug">
            <div class="m-body">
                <div class="f-row">
                    <div class="fg">
                        <label>Category Name <span>*</span></label>
                        <input type="text" name="cat_name" id="editCatName" class="fc" required>
                    </div>
                    <div class="fg">
                        <label>Slug</label>
                        <input type="text" name="cat_slug" id="editCatSlug" class="fc">
                        <div class="f-hint">⚠ Changing slug renames in products too</div>
                    </div>
                </div>
                <div class="fg">
                    <label>Description</label>
                    <textarea name="cat_desc" id="editCatDesc" class="fc"></textarea>
                </div>
                <div class="fg">
                    <label>Icon</label>
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
                        <div class="icon-preview" id="editIconPreview"><i class="fas fa-tag" id="editIconPreviewI"></i></div>
                        <input type="text" name="cat_icon" id="editCatIcon" class="fc" placeholder="fa-tag" oninput="updateIconPreview('edit')">
                    </div>
                    <div class="icon-grid" id="editIconGrid">
                        <?php foreach ($icon_options as $ico): ?>
                        <div class="icon-opt" title="<?= $ico ?>" onclick="selectIcon('edit','<?= $ico ?>')"><i class="fas <?= $ico ?>"></i></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="m-foot">
                <button type="button" class="btn-o" onclick="closeModal('editCatModal')">Cancel</button>
                <button type="submit" class="btn-p"><i class="fas fa-floppy-disk"></i> Update Category</button>
            </div>
        </form>
    </div>
</div>


<!-- ══════════════════════════════════════════════════════════════ -->
<!-- DELETE CATEGORY MODAL                                         -->
<!-- ══════════════════════════════════════════════════════════════ -->
<div class="m-overlay" id="deleteCatModal">
    <div class="modal" style="max-width:440px">
        <div class="m-hdr">
            <h3><i class="fas fa-trash" style="color:#ef4444;margin-right:6px"></i>Delete Category</h3>
            <button class="m-close" onclick="closeModal('deleteCatModal')"><i class="fas fa-xmark"></i></button>
        </div>
        <form method="POST" action="?page=categories">
            <input type="hidden" name="_action" value="delete_category">
            <input type="hidden" name="cat_id"   id="delCatId">
            <input type="hidden" name="cat_slug"  id="delCatSlug">
            <div class="m-body">
                <p id="delCatMsg" style="font-size:.87rem;color:var(--ink-soft);margin-bottom:1rem"></p>
                <div class="fg" id="delReassignWrap" style="display:none">
                    <label>Reassign products to:</label>
                    <select name="reassign_slug" class="fc">
                        <option value="">— Remove category (leave blank) —</option>
                        <?php foreach ($db_categories as $cat): ?>
                        <option value="<?= htmlspecialchars($cat['slug']??slugify($cat['name'])) ?>"><?= htmlspecialchars($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="f-hint">Choosing a category moves all products; leaving blank clears their category field.</div>
                </div>
            </div>
            <div class="m-foot">
                <button type="button" class="btn-o" onclick="closeModal('deleteCatModal')">Cancel</button>
                <button type="submit" class="btn-p btn-red"><i class="fas fa-trash"></i> Delete</button>
            </div>
        </form>
    </div>
</div>


<script>
/* ── Modal helpers ───────────────────────────────────────────── */
function openModal(id)  { document.getElementById(id).classList.add('open'); document.body.style.overflow='hidden'; }
function closeModal(id) { document.getElementById(id).classList.remove('open'); document.body.style.overflow=''; }
document.querySelectorAll('.m-overlay').forEach(el => {
    el.addEventListener('click', e => { if (e.target===el) closeModal(el.id); });
});

/* ── Image preview ───────────────────────────────────────────── */
function prvFile(inp) {
    if (!inp.files.length) return;
    const r=new FileReader();
    r.onload=e=>showPrv(e.target.result);
    r.readAsDataURL(inp.files[0]);
}
function prvUrl(url) { if (url.trim()) showPrv(url); }
function showPrv(src) {
    const img=document.getElementById('imgPrevImg');
    const ph=document.querySelector('#imgPrev .iph');
    img.src=src; img.style.display='block';
    if (ph) ph.style.display='none';
}

/* ── Slug auto-generation ────────────────────────────────────── */
function autoSlug(nameInput, slugFieldId) {
    const slugEl = document.getElementById(slugFieldId);
    if (!slugEl) return;
    slugEl.value = nameInput.value.toLowerCase().trim()
        .replace(/[^a-z0-9]+/g,'_').replace(/^_|_$/g,'');
}

/* ── Icon picker ─────────────────────────────────────────────── */
function updateIconPreview(prefix) {
    const val = document.getElementById(prefix+'CatIcon').value.trim() || 'fa-tag';
    document.getElementById(prefix+'IconPreviewI').className = 'fas '+val;
    document.querySelectorAll('#'+prefix+'IconGrid .icon-opt').forEach(el => {
        el.classList.toggle('selected', el.title===val);
    });
}
function selectIcon(prefix, ico) {
    document.getElementById(prefix+'CatIcon').value = ico;
    updateIconPreview(prefix);
}

/* ── Open Edit Category modal ────────────────────────────────── */
function openEditCat(data) {
    document.getElementById('editCatId').value   = data.id;
    document.getElementById('editCatName').value = data.name;
    document.getElementById('editCatSlug').value = data.slug;
    document.getElementById('editOldSlug').value = data.slug;
    document.getElementById('editCatDesc').value = data.desc;
    document.getElementById('editCatIcon').value = data.icon;
    updateIconPreview('edit');
    openModal('editCatModal');
}

/* ── Open Delete Category modal ──────────────────────────────── */
function openDeleteCat(data) {
    document.getElementById('delCatId').value   = data.id;
    document.getElementById('delCatSlug').value = data.slug;
    const wrap = document.getElementById('delReassignWrap');
    const msg  = document.getElementById('delCatMsg');
    if (data.cnt > 0) {
        msg.innerHTML = `<strong style="color:#dc2626">Warning:</strong> This category has <strong>${data.cnt} product(s)</strong>. Choose how to handle them below.`;
        wrap.style.display = 'block';
        // Remove the current category from the reassign options
        document.querySelectorAll('#delReassignWrap select option').forEach(opt => {
            opt.hidden = (opt.value === data.slug);
        });
    } else {
        msg.textContent = `Delete category "${data.name}"? It has no products, so this is safe.`;
        wrap.style.display = 'none';
    }
    openModal('deleteCatModal');
}

/* ── Category filter (Products page) ────────────────────────── */
document.querySelectorAll('#catTabs .ftab').forEach(tab=>{
    tab.addEventListener('click',e=>{
        e.preventDefault();
        document.querySelectorAll('#catTabs .ftab').forEach(t=>t.classList.remove('active'));
        tab.classList.add('active');
        const cat=tab.dataset.cat;
        document.querySelectorAll('#prodTable tbody tr').forEach(row=>{
            row.style.display=(cat==='all'||row.dataset.cat===cat)?'':'none';
        });
    });
});

/* ── Order status filter ─────────────────────────────────────── */
document.querySelectorAll('#orderTabs .ftab').forEach(tab=>{
    tab.addEventListener('click',e=>{
        e.preventDefault();
        document.querySelectorAll('#orderTabs .ftab').forEach(t=>t.classList.remove('active'));
        tab.classList.add('active');
        const f=tab.dataset.filter;
        document.querySelectorAll('#orderTable tbody tr').forEach(row=>{
            row.style.display=(f==='all'||row.dataset.status===f)?'':'none';
        });
    });
});

/* ── Live table search ───────────────────────────────────────── */
function liveSearch(inputId, tableId) {
    const el=document.getElementById(inputId);
    if (!el) return;
    el.addEventListener('input',()=>{
        const q=el.value.toLowerCase();
        document.querySelectorAll(`#${tableId} tbody tr`).forEach(row=>{
            row.style.display=row.textContent.toLowerCase().includes(q)?'':'none';
        });
    });
}
liveSearch('prodSearch','prodTable');
liveSearch('orderSearch','orderTable');
liveSearch('custSearch','custTable');
liveSearch('catSearch','catTable');

/* Global search */
document.getElementById('gSearch')?.addEventListener('input',function(){
    const q=this.value.toLowerCase();
    ['prodTable','orderTable','custTable','catTable'].forEach(tid=>{
        document.querySelectorAll(`#${tid} tbody tr`).forEach(row=>{
            row.style.display=row.textContent.toLowerCase().includes(q)?'':'none';
        });
    });
});

/* ── CSV export ──────────────────────────────────────────────── */
function exportTable(id) {
    const tbl=document.getElementById(id);
    if (!tbl) { alert('No table to export on this page.'); return; }
    let csv='';
    tbl.querySelectorAll('tr').forEach(row=>{
        const cols=[...row.querySelectorAll('th,td')].map(c=>'"'+c.innerText.replace(/"/g,'""').replace(/\n/g,' ')+'"');
        csv+=cols.join(',')+'\n';
    });
    const a=document.createElement('a');
    a.href='data:text/csv;charset=utf-8,'+encodeURIComponent(csv);
    a.download='itshop-<?= $active ?>-<?= date('Y-m-d') ?>.csv';
    a.click();
}

/* ── Stock input: Enter to submit ────────────────────────────── */
document.querySelectorAll('.se-num').forEach(inp=>{
    inp.addEventListener('keydown',e=>{ if(e.key==='Enter'){e.preventDefault();inp.closest('form').submit();} });
});
</script>
</body>
</html>