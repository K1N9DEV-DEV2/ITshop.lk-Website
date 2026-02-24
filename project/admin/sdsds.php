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
if ($pdo) {
    try {
        $pdo->exec("ALTER TABLE categories ADD COLUMN IF NOT EXISTS slug VARCHAR(100) NOT NULL DEFAULT ''");
        $pdo->exec("ALTER TABLE categories ADD COLUMN IF NOT EXISTS icon VARCHAR(50) NOT NULL DEFAULT 'fa-tag'");
        $pdo->exec("UPDATE categories SET slug = LOWER(REPLACE(REPLACE(name,' ','_'),'&','')) WHERE slug = '' OR slug IS NULL");
    } catch (PDOException $e) { /* silently skip */ }
}

// ── Load categories from DB ───────────────────────────────────────────────────
$db_categories = dbq("SELECT * FROM categories ORDER BY name ASC") ?: [];

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
            GROUP_CONCAT(ps.spec_name SEPARATOR '\n') as specs
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

$cat_counts = dbq(
    "SELECT c.id, c.name, c.slug, c.icon,
            COUNT(p.id) as cnt,
            COALESCE(SUM(p.stock_count),0) as stock
     FROM categories c
     LEFT JOIN products p ON p.category = c.slug
     GROUP BY c.id
     ORDER BY cnt DESC, c.name ASC"
) ?: [];

$orphan_cats = dbq(
    "SELECT p.category, COUNT(*) as cnt, COALESCE(SUM(p.stock_count),0) as stock
     FROM products p
     LEFT JOIN categories c ON c.slug = p.category
     WHERE c.id IS NULL AND p.category != ''
     GROUP BY p.category"
) ?: [];

$db_customers = dbq("SELECT id, name, email, created_at FROM users ORDER BY created_at DESC LIMIT 200") ?: [];

// ── FIXED: Full orders query with all fields ──────────────────────────────────
$db_orders = dbq(
    "SELECT o.id, o.order_number, o.status, o.total, o.subtotal, o.shipping_cost,
            o.shipping, o.tax, o.notes, o.created_at,
            COALESCE(o.full_name, u.name) as customer_name,
            COALESCE(o.email, u.email)    as customer_email,
            o.phone, o.address, o.currency
     FROM orders o
     LEFT JOIN users u ON o.user_id = u.id
     ORDER BY o.created_at DESC LIMIT 200"
) ?: [];

// ── FIXED: Fetch all order items grouped by order_id ─────────────────────────
$raw_items = dbq(
    "SELECT oi.order_id,
            oi.id as item_id,
            COALESCE(NULLIF(oi.product_name,''), oi.name, p.name, 'Unknown Product') as product_name,
            COALESCE(oi.brand, p.brand) as brand,
            COALESCE(oi.unit_price, oi.price, 0)  as unit_price,
            COALESCE(oi.total_price, oi.subtotal, oi.price * oi.quantity, 0) as total_price,
            oi.quantity,
            oi.product_id,
            p.image
     FROM order_items oi
     LEFT JOIN products p ON p.id = oi.product_id
     ORDER BY oi.order_id ASC, oi.id ASC"
) ?: [];

// Group items by order_id
$order_items_map = [];
foreach ($raw_items as $item) {
    $order_items_map[$item['order_id']][] = $item;
}

$order_stats = dbq(
    "SELECT COALESCE(SUM(total),0) as revenue,
            COUNT(*) as total_orders,
            SUM(CASE WHEN LOWER(status)='pending'    THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN DATE(created_at)=CURDATE() THEN 1 ELSE 0 END) as today
     FROM orders",
    [], false
) ?: ['revenue'=>0,'total_orders'=>0,'pending'=>0,'today'=>0];

$pending_count = (int)($order_stats['pending'] ?? 0);

// ── Compute per-status counts for order tabs ──────────────────────────────────
$order_status_counts = ['all' => count($db_orders)];
foreach ($db_orders as $o) {
    $s = strtolower(trim($o['status'] ?? 'pending'));
    $order_status_counts[$s] = ($order_status_counts[$s] ?? 0) + 1;
}

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
                    $pdo->prepare("UPDATE products SET category=? WHERE category=?")->execute([$reassign, $cat_slug]);
                } else {
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

    // ── EDIT PRODUCT ──────────────────────────────────────────────────────────
    if ($act === 'edit_product') {
        $pid      = intval($_POST['product_id'] ?? 0);
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
        if (!$image) $image = trim($_POST['existing_image'] ?? '');

        if ($pid && $name && $category && $price > 0) {
            try {
                $pdo->prepare(
                    "UPDATE products SET name=?, brand=?, category=?, price=?, original_price=?, stock_count=?, image=? WHERE id=?"
                )->execute([$name, $brand, $category, $price, $orig ?: $price, $stock, $image, $pid]);

                $specs_raw = trim($_POST['specs'] ?? '');
                $pdo->prepare("DELETE FROM product_specs WHERE product_id=?")->execute([$pid]);
                if ($specs_raw) {
                    $sp = $pdo->prepare("INSERT INTO product_specs (product_id, spec_name) VALUES (?, ?)");
                    foreach (array_filter(array_map('trim', explode("\n", $specs_raw))) as $sl)
                        $sp->execute([$pid, $sl]);
                }
                $flash = ['type' => 'success', 'msg' => "Product \"$name\" updated successfully!"];
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

    // ── CLEAR ALL ORDERS — superadmin only ────────────────────────────────────
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
        .pg-hdr-r { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }

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
        .sw { background:#fef3c7; color:#92400e; }

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
        .ftab-count { display:inline-flex; align-items:center; justify-content:center; background:rgba(0,0,0,.12); color:inherit; font-size:.6rem; font-weight:800; min-width:16px; height:16px; border-radius:100px; padding:0 4px; margin-left:4px; }
        .ftab.active .ftab-count { background:rgba(255,255,255,.25); }

        /* ── STOCK EDIT ──────────────────────────────────────── */
        .se-form { display:inline-flex; align-items:center; gap:4px; }
        .se-num { width:54px; padding:4px 6px; background:var(--surface); border:1px solid rgba(10,10,15,.1); border-radius:6px; font-family:'Red Hat Display',sans-serif; font-size:.81rem; text-align:center; outline:none; color:var(--ink); }
        .se-num:focus { border-color:var(--accent); }

        /* ── CUSTOMER AVATAR ─────────────────────────────────── */
        .cust-avatar { width:34px; height:34px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:.78rem; font-weight:800; color:#fff; flex-shrink:0; }

        /* ── MODAL ───────────────────────────────────────────── */
        .m-overlay { display:none; position:fixed; inset:0; z-index:1000; background:rgba(10,10,15,.55); backdrop-filter:blur(4px); align-items:center; justify-content:center; padding:1rem; }
        .m-overlay.open { display:flex; }
        .modal { background:var(--card); border-radius:var(--r-xl); box-shadow:0 24px 80px rgba(10,10,15,.22); width:100%; max-width:580px; max-height:92vh; overflow-y:auto; animation:modalIn .22s ease; }
        .modal-lg { max-width:720px; }
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

        /* ── EMPTY STATE ─────────────────────────────────────── */
        .empty-state { padding:3.5rem; text-align:center; color:var(--ink-muted); }
        .empty-state i { font-size:2.5rem; margin-bottom:.75rem; display:block; opacity:.35; }
        .empty-state p { font-size:.87rem; }

        /* ── ANIMATION ───────────────────────────────────────── */
        @keyframes fadeUp { from{opacity:0;transform:translateY(11px)} to{opacity:1;transform:translateY(0)} }

        /* ── SCROLLBAR ───────────────────────────────────────── */
        ::-webkit-scrollbar{width:5px;height:5px} ::-webkit-scrollbar-track{background:transparent} ::-webkit-scrollbar-thumb{background:rgba(10,10,15,.11);border-radius:10px}

        /* ── ORDER ITEMS EXPAND ROW ──────────────────────────── */
        .order-expand-btn {
            width:24px; height:24px; border-radius:6px; border:1px solid rgba(10,10,15,.1);
            background:var(--surface); color:var(--ink-muted); display:inline-flex;
            align-items:center; justify-content:center; cursor:pointer; font-size:.65rem;
            transition:all .15s; flex-shrink:0;
        }
        .order-expand-btn:hover { background:var(--accent-dim); color:var(--accent); border-color:var(--accent); }
        .order-expand-btn.open  { background:var(--accent); color:#fff; border-color:var(--accent); transform:rotate(180deg); }

        .order-detail-row { display:none; background:#fafbff; }
        .order-detail-row.open { display:table-row; }
        .order-detail-row td { padding:0 !important; border-bottom:2px solid rgba(12,177,0,.15) !important; }

        .order-items-panel {
            padding:1rem 1.4rem 1.2rem;
            display:grid;
            grid-template-columns:1fr auto;
            gap:1.2rem;
        }
        .order-items-list { flex:1; }
        .order-item-row {
            display:flex; align-items:center; gap:10px;
            padding:7px 0;
            border-bottom:1px solid rgba(10,10,15,.05);
        }
        .order-item-row:last-child { border-bottom:none; }
        .order-item-img {
            width:36px; height:36px; border-radius:7px; object-fit:contain;
            background:var(--surface); flex-shrink:0;
        }
        .order-item-img-ph {
            width:36px; height:36px; border-radius:7px; background:var(--surface);
            display:flex; align-items:center; justify-content:center;
            color:var(--ink-muted); font-size:.75rem; flex-shrink:0;
        }
        .order-item-info { flex:1; min-width:0; }
        .order-item-name { font-size:.82rem; font-weight:700; color:var(--ink); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .order-item-brand { font-size:.7rem; color:var(--ink-muted); }
        .order-item-qty { font-size:.75rem; font-weight:700; color:var(--ink-soft); white-space:nowrap; padding:2px 7px; background:var(--surface); border-radius:5px; }
        .order-item-price { font-size:.83rem; font-weight:700; color:var(--ink); white-space:nowrap; min-width:80px; text-align:right; }

        .order-totals-box {
            background:var(--surface); border-radius:var(--r-md);
            padding:.85rem 1rem; min-width:195px; height:fit-content;
        }
        .order-total-row { display:flex; justify-content:space-between; gap:1rem; font-size:.78rem; padding:3px 0; color:var(--ink-soft); }
        .order-total-row.grand { font-size:.88rem; font-weight:800; color:var(--ink); border-top:1px solid rgba(10,10,15,.09); margin-top:5px; padding-top:8px; }
        .order-meta-row { display:flex; gap:1.3rem; flex-wrap:wrap; margin-bottom:.75rem; padding-bottom:.75rem; border-bottom:1px solid rgba(10,10,15,.06); }
        .order-meta-item { display:flex; flex-direction:column; gap:1px; }
        .order-meta-item span { font-size:.66rem; font-weight:700; color:var(--ink-muted); text-transform:uppercase; letter-spacing:.05em; }
        .order-meta-item strong { font-size:.8rem; color:var(--ink); }

        .no-items-note { color:var(--ink-muted); font-size:.8rem; font-style:italic; padding:.5rem 0; }

        /* ── RESPONSIVE ──────────────────────────────────────── */
        @media(max-width:960px){
            :root{--sidebar-w:58px}
            .sb-logo-text,.nav-link span,.sb-info,.sb-section,.nav-badge{display:none}
            .nav-link{justify-content:center;padding:10px} .sb-logo{justify-content:center;padding:1rem}
            .sb-profile{justify-content:center} .sb-logout{display:none}
            .order-items-panel { grid-template-columns:1fr; }
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
                        $st=strtolower(trim($o['status']??'pending'));
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
            <div class="empty-state"><i class="fas fa-bag-shopping"></i><p>No orders yet.</p></div>
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
            <a href="#" class="ftab active" data-cat="all">All <span class="ftab-count"><?= count($db_products) ?></span></a>
            <?php foreach ($cat_counts as $cc): if ($cc['cnt'] < 1) continue; ?>
            <a href="#" class="ftab" data-cat="<?= htmlspecialchars($cc['slug']) ?>">
                <?= htmlspecialchars($cc['name']) ?> <span class="ftab-count"><?= $cc['cnt'] ?></span>
            </a>
            <?php endforeach; ?>
            <?php foreach ($orphan_cats as $oc): ?>
            <a href="#" class="ftab" data-cat="<?= htmlspecialchars($oc['category']) ?>" style="border-color:#f59e0b;color:#92400e">
                ⚠ <?= htmlspecialchars($oc['category']) ?> <span class="ftab-count"><?= $oc['cnt'] ?></span>
            </a>
            <?php endforeach; ?>
        </div>

        <div class="tc">
            <div class="tc-hdr">
                <div><h3>Product Inventory</h3><p>Edit stock inline · click <i class="fas fa-pen" style="font-size:.7rem"></i> to edit full product</p></div>
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
                    $edit_data = [
                        'id'             => (int)$p['id'],
                        'name'           => $p['name'],
                        'brand'          => $p['brand'] ?? '',
                        'category'       => $p['category'],
                        'price'          => $p['price'],
                        'original_price' => $p['original_price'] ?? '',
                        'stock_count'    => $stk,
                        'image'          => $p['image'] ?? '',
                        'specs'          => $p['specs'] ?? '',
                    ];
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
                        <div style="font-size:.68rem;color:var(--ink-muted);margin-top:2px;max-width:210px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars(str_replace("\n", ' · ', $p['specs'])) ?></div>
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
                            <button type="button" class="ab edit" title="Edit product"
                                onclick="openEditProduct(<?= htmlspecialchars(json_encode($edit_data), ENT_QUOTES) ?>)">
                                <i class="fas fa-pen"></i>
                            </button>
                            <form method="POST" style="display:inline" onsubmit="return confirm('Delete product #<?= $p['id'] ?> — <?= addslashes(htmlspecialchars($p['name'])) ?>?\nThis also removes its specs.')">
                                <input type="hidden" name="_action" value="delete_product">
                                <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                                <button type="submit" class="ab del" title="Delete"><i class="fas fa-trash"></i></button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="10"><div class="empty-state"><i class="fas fa-box"></i><p>No products found in database.</p></div></td></tr>
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
                <p>
                    <?= count($db_orders) ?> orders loaded
                    <?php if ($pending_count > 0): ?>
                    · <span style="color:#f59e0b;font-weight:700"><?= $pending_count ?> pending</span>
                    <?php endif; ?>
                    · Total revenue: <strong>LKR <?= number_format($order_stats['revenue'] ?? 0) ?></strong>
                </p>
            </div>
            <div class="pg-hdr-r">
                <button class="btn-o btn-sm" onclick="exportTable('orderTable')"><i class="fas fa-download"></i> Export CSV</button>
            </div>
        </div>

        <div class="ftabs" id="orderTabs">
            <?php
            $order_tab_labels = ['all'=>'All','pending'=>'Pending','processing'=>'Processing','shipped'=>'Shipped','completed'=>'Completed','cancelled'=>'Cancelled'];
            foreach ($order_tab_labels as $tab => $label):
                $cnt = $order_status_counts[$tab] ?? 0;
            ?>
            <a href="#" class="ftab <?= $tab==='all'?'active':'' ?>" data-filter="<?= $tab ?>">
                <?= $label ?>
                <?php if ($cnt > 0 || $tab === 'all'): ?>
                <span class="ftab-count"><?= $cnt ?></span>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </div>

        <div class="tc">
            <div class="tc-hdr">
                <div>
                    <h3>Order List</h3>
                    <p>Click <i class="fas fa-chevron-down" style="font-size:.65rem"></i> to see order items &amp; details · showing <span id="orderVisibleCount"><?= count($db_orders) ?></span> of <?= count($db_orders) ?> orders</p>
                </div>
                <input type="text" class="tbl-s" placeholder="Search orders…" id="orderSearch" style="width:185px">
            </div>
            <div class="tbl-wrap">
            <?php if ($db_orders): ?>
            <table id="orderTable">
                <thead>
                    <tr>
                        <th style="width:30px"></th>
                        <th>Order</th>
                        <th>Customer</th>
                        <th>Items</th>
                        <th>Total (LKR)</th>
                        <th>Status</th>
                        <th>Update Status</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($db_orders as $o):
                        $st      = strtolower(trim($o['status'] ?? 'pending'));
                        $sc      = $status_colors[$st] ?? ['bg'=>'#f3f4f6','color'=>'#6b7280'];
                        $items   = $order_items_map[$o['id']] ?? [];
                        $item_ct = count($items);
                        $oid     = $o['id'];
                        $cur     = $o['currency'] ?? 'LKR';

                        // Compute shipping display: use shipping_cost if set, else shipping column
                        $shipping_amt = (float)($o['shipping_cost'] > 0 ? $o['shipping_cost'] : $o['shipping']);
                        $subtotal_amt = (float)($o['subtotal'] > 0 ? $o['subtotal'] : ($o['total'] - $shipping_amt - (float)$o['tax']));
                    ?>
                    <!-- Main order row -->
                    <tr data-status="<?= htmlspecialchars($st) ?>" class="order-main-row" data-order-id="<?= $oid ?>">
                        <td style="padding:11px 8px 11px 14px">
                            <button class="order-expand-btn" onclick="toggleOrderItems(<?= $oid ?>)" title="<?= $item_ct ?> item<?= $item_ct!=1?'s':'' ?>">
                                <i class="fas fa-chevron-down"></i>
                            </button>
                        </td>
                        <td>
                            <strong>#<?= htmlspecialchars($o['id']) ?></strong>
                            <?php if ($o['order_number']): ?>
                            <div style="font-size:.66rem;color:var(--ink-muted);margin-top:1px;font-family:monospace"><?= htmlspecialchars($o['order_number']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="display:flex;align-items:center;gap:8px">
                                <?php $initials = strtoupper(substr($o['customer_name'] ?? 'G', 0, 1)); ?>
                                <div class="cust-avatar" style="background:hsl(<?= (ord($initials) * 37) % 360 ?>,60%,55%);width:28px;height:28px;font-size:.68rem">
                                    <?= $initials ?>
                                </div>
                                <div>
                                    <div style="font-size:.83rem;font-weight:700;color:var(--ink)"><?= htmlspecialchars($o['customer_name'] ?? 'Guest') ?></div>
                                    <div style="font-size:.7rem;color:var(--ink-muted)"><?= htmlspecialchars($o['customer_email'] ?? '') ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <?php if ($item_ct > 0): ?>
                            <span style="font-size:.78rem;font-weight:700;color:var(--ink-soft)">
                                <?= $item_ct ?> item<?= $item_ct!=1?'s':'' ?>
                            </span>
                            <?php else: ?>
                            <span style="font-size:.75rem;color:var(--ink-muted)">—</span>
                            <?php endif; ?>
                        </td>
                        <td><strong><?= number_format($o['total'] ?? 0) ?></strong></td>
                        <td>
                            <span class="badge" style="background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>">
                                <?= ucfirst($st) ?>
                            </span>
                        </td>
                        <td>
                            <form method="POST" style="display:inline-flex;align-items:center;gap:4px">
                                <input type="hidden" name="_action" value="update_order_status">
                                <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                                <select name="status" class="tbl-s" style="width:130px;padding:5px 7px;font-size:.77rem">
                                    <?php foreach (['pending','processing','shipped','completed','cancelled'] as $sv): ?>
                                    <option value="<?= $sv ?>" <?= $sv === $st ? 'selected' : '' ?>><?= ucfirst($sv) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="ab" title="Save status"><i class="fas fa-check"></i></button>
                            </form>
                        </td>
                        <td class="muted">
                            <?php $created = $o['created_at'] ?? null;
                            echo $created ? date('d M Y, H:i', strtotime($created)) : '—'; ?>
                        </td>
                    </tr>

                    <!-- ── ORDER DETAIL EXPAND ROW ── -->
                    <tr class="order-detail-row" id="order-detail-<?= $oid ?>">
                        <td colspan="8">
                            <div class="order-items-panel">

                                <!-- Left: meta + items list -->
                                <div>
                                    <!-- Order meta info -->
                                    <div class="order-meta-row">
                                        <?php if ($o['phone']): ?>
                                        <div class="order-meta-item">
                                            <span><i class="fas fa-phone" style="font-size:.55rem"></i> Phone</span>
                                            <strong><?= htmlspecialchars($o['phone']) ?></strong>
                                        </div>
                                        <?php endif; ?>
                                        <?php if ($o['address']): ?>
                                        <div class="order-meta-item">
                                            <span><i class="fas fa-location-dot" style="font-size:.55rem"></i> Address</span>
                                            <strong style="max-width:220px;white-space:normal;font-size:.76rem"><?= nl2br(htmlspecialchars($o['address'])) ?></strong>
                                        </div>
                                        <?php endif; ?>
                                        <?php if ($o['notes']): ?>
                                        <div class="order-meta-item">
                                            <span><i class="fas fa-note-sticky" style="font-size:.55rem"></i> Note</span>
                                            <strong style="max-width:200px;white-space:normal;font-size:.76rem;font-weight:500;color:var(--ink-soft)"><?= htmlspecialchars($o['notes']) ?></strong>
                                        </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Items list -->
                                    <div class="order-items-list">
                                        <?php if ($items): foreach ($items as $item):
                                            $img_src = $item['image'] ? '../' . $item['image'] : null;
                                        ?>
                                        <div class="order-item-row">
                                            <?php if ($img_src): ?>
                                            <img src="<?= htmlspecialchars($img_src) ?>"
                                                 class="order-item-img"
                                                 onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                                            <div class="order-item-img-ph" style="display:none"><i class="fas fa-image"></i></div>
                                            <?php else: ?>
                                            <div class="order-item-img-ph"><i class="fas fa-image"></i></div>
                                            <?php endif; ?>

                                            <div class="order-item-info">
                                                <div class="order-item-name"><?= htmlspecialchars($item['product_name']) ?></div>
                                                <?php if ($item['brand']): ?>
                                                <div class="order-item-brand"><?= htmlspecialchars($item['brand']) ?></div>
                                                <?php endif; ?>
                                            </div>

                                            <div style="display:flex;align-items:center;gap:8px;flex-shrink:0">
                                                <span class="order-item-qty">× <?= (int)$item['quantity'] ?></span>
                                                <div style="text-align:right">
                                                    <div class="order-item-price"><?= $cur ?> <?= number_format($item['total_price'], 2) ?></div>
                                                    <div style="font-size:.68rem;color:var(--ink-muted)"><?= $cur ?> <?= number_format($item['unit_price'], 2) ?> each</div>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; else: ?>
                                        <p class="no-items-note"><i class="fas fa-circle-info"></i> No item records found for this order.</p>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Right: totals box -->
                                <div class="order-totals-box">
                                    <div style="font-size:.72rem;font-weight:800;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:8px">Order Summary</div>
                                    <div class="order-total-row">
                                        <span>Subtotal</span>
                                        <span><?= $cur ?> <?= number_format($subtotal_amt, 2) ?></span>
                                    </div>
                                    <?php if ($shipping_amt > 0): ?>
                                    <div class="order-total-row">
                                        <div class="order-total-row">
                                        <span>Shipping</span>
                                        <span><?= $cur ?> <?= number_format($shipping_amt, 2) ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ((float)$o['tax'] > 0): ?>
                                    <div class="order-total-row">
                                        <span>Tax</span>
                                        <span><?= $cur ?> <?= number_format((float)$o['tax'], 2) ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <div class="order-total-row grand">
                                        <span>Total</span>
                                        <span><?= $cur ?> <?= number_format((float)$o['total'], 2) ?></span>
                                    </div>
                                </div>

                            </div><!-- /order-items-panel -->
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty-state"><i class="fas fa-bag-shopping"></i><p>No orders yet.</p></div>
            <?php endif; ?>
            </div>
        </div>


        <?php /* ─────────── CUSTOMERS ─────────── */ ?>
        <?php elseif ($active==='customers'): ?>

        <div class="pg-hdr">
            <div class="pg-hdr-l">
                <h2>Customers</h2>
                <p><?= count($db_customers) ?> registered customers</p>
            </div>
            <div class="pg-hdr-r">
                <button class="btn-o btn-sm" onclick="exportTable('custTable')"><i class="fas fa-download"></i> Export CSV</button>
            </div>
        </div>

        <div class="tc">
            <div class="tc-hdr">
                <div><h3>Customer List</h3><p>Registered users from the database</p></div>
                <input type="text" class="tbl-s" placeholder="Search customers…" id="custSearch" style="width:185px">
            </div>
            <div class="tbl-wrap">
            <?php if ($db_customers): ?>
            <table id="custTable">
                <thead>
                    <tr><th>#</th><th>Customer</th><th>Email</th><th>Joined</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($db_customers as $c):
                        $init = strtoupper(substr($c['name'] ?? 'U', 0, 1));
                        $hue  = (ord($init) * 37) % 360;
                    ?>
                    <tr>
                        <td class="muted"><?= $c['id'] ?></td>
                        <td>
                            <div style="display:flex;align-items:center;gap:9px">
                                <div class="cust-avatar" style="background:hsl(<?= $hue ?>,60%,55%)"><?= $init ?></div>
                                <strong><?= htmlspecialchars($c['name'] ?? '—') ?></strong>
                            </div>
                        </td>
                        <td><?= htmlspecialchars($c['email'] ?? '—') ?></td>
                        <td class="muted"><?= $c['created_at'] ? date('d M Y', strtotime($c['created_at'])) : '—' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty-state"><i class="fas fa-users"></i><p>No customers yet.</p></div>
            <?php endif; ?>
            </div>
        </div>


        <?php /* ─────────── CATEGORIES ─────────── */ ?>
        <?php elseif ($active==='categories'): ?>

        <div class="pg-hdr">
            <div class="pg-hdr-l">
                <h2>Categories</h2>
                <p><?= count($db_categories) ?> categories in database</p>
            </div>
            <div class="pg-hdr-r">
                <?php if ($orphan_cats): ?>
                <form method="POST" style="display:inline">
                    <input type="hidden" name="_action" value="import_orphans">
                    <button type="submit" class="btn-o btn-sm btn-warn" style="color:#fff">
                        <i class="fas fa-file-import"></i> Import <?= count($orphan_cats) ?> Orphan<?= count($orphan_cats)!=1?'s':'' ?>
                    </button>
                </form>
                <?php endif; ?>
                <button class="btn-p" onclick="openModal('addCatModal')"><i class="fas fa-plus"></i> Add Category</button>
            </div>
        </div>

        <?php if ($orphan_cats): ?>
        <div class="orphan-alert">
            <div><i class="fas fa-triangle-exclamation"></i> <strong><?= count($orphan_cats) ?> orphan category slug<?= count($orphan_cats)!=1?'s':'' ?></strong> found in products but not in the categories table:
                <?php foreach ($orphan_cats as $oc): ?>
                <code style="background:#fde68a;border-radius:3px;padding:1px 5px;margin:0 2px;font-size:.8em"><?= htmlspecialchars($oc['category']) ?></code>
                <?php endforeach; ?>
            </div>
            <form method="POST">
                <input type="hidden" name="_action" value="import_orphans">
                <button class="btn-o btn-sm" type="submit" style="border-color:#f59e0b;color:#92400e">Auto-Import All</button>
            </form>
        </div>
        <?php endif; ?>

        <?php if ($db_categories): ?>
        <div class="cg">
            <?php foreach ($db_categories as $cat):
                $slug = $cat['slug'] ?: slugify($cat['name']);
                $cnt  = 0;
                foreach ($cat_counts as $cc) { if ($cc['id'] == $cat['id']) { $cnt = $cc['cnt']; break; } }
            ?>
            <div class="cg-c">
                <div class="cg-ico"><i class="fas <?= htmlspecialchars($cat['icon'] ?? 'fa-tag') ?>"></i></div>
                <div class="cg-b">
                    <strong><?= htmlspecialchars($cat['name']) ?></strong>
                    <span><?= $cnt ?> product<?= $cnt!=1?'s':'' ?></span>
                    <div class="slug-tag"><?= htmlspecialchars($slug) ?></div>
                </div>
                <div class="cg-actions">
                    <button class="ab edit" title="Edit category"
                        onclick="openEditCat(<?= htmlspecialchars(json_encode([
                            'id'   => $cat['id'],
                            'name' => $cat['name'],
                            'desc' => $cat['description'] ?? '',
                            'icon' => $cat['icon'] ?? 'fa-tag',
                            'slug' => $slug,
                        ]), ENT_QUOTES) ?>)">
                        <i class="fas fa-pen"></i>
                    </button>
                    <form method="POST" style="display:inline"
                          onsubmit="return confirm('Delete category \"<?= addslashes(htmlspecialchars($cat['name'])) ?>\"?\n<?= $cnt ?> product(s) will be unassigned.')">
                        <input type="hidden" name="_action"   value="delete_category">
                        <input type="hidden" name="cat_id"   value="<?= $cat['id'] ?>">
                        <input type="hidden" name="cat_slug" value="<?= htmlspecialchars($slug) ?>">
                        <button type="submit" class="ab del" title="Delete category"><i class="fas fa-trash"></i></button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty-state" style="background:var(--card);border-radius:var(--r-xl);padding:3rem">
            <i class="fas fa-layer-group"></i><p>No categories yet. Add your first one!</p>
        </div>
        <?php endif; ?>


        <?php /* ─────────── REPORTS ─────────── */ ?>
        <?php elseif ($active==='reports'): ?>

        <div class="pg-hdr">
            <div class="pg-hdr-l"><h2>Reports</h2><p>Sales and inventory overview</p></div>
        </div>

        <div class="stats-g" style="margin-bottom:1.4rem">
            <?php
            $rcards = [
                ['Total Revenue',   'LKR '.number_format($order_stats['revenue']??0),   'all time',          'fa-coins',       '#0cb100'],
                ['Total Orders',    $order_stats['total_orders']??0,                     'all statuses',      'fa-bag-shopping','#3b82f6'],
                ['Total Customers', count($db_customers),                               'registered users',  'fa-users',       '#8b5cf6'],
                ['Products Listed', $agg['total_products']??0,                          'in catalogue',      'fa-box',         '#f59e0b'],
            ];
            foreach ($rcards as $i=>$r): ?>
            <div class="stat-c" style="animation-delay:<?= $i*55 ?>ms">
                <div class="stat-ico" style="background:<?= $r[4] ?>18;color:<?= $r[4] ?>"><i class="fas <?= $r[3] ?>"></i></div>
                <div class="stat-b">
                    <div class="stat-lbl"><?= $r[0] ?></div>
                    <div class="stat-val" style="font-size:1.15rem"><?= $r[1] ?></div>
                    <span class="stat-d" style="color:var(--ink-muted)"><?= $r[2] ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="g2">
            <div class="cc">
                <h3>Orders by Status</h3>
                <p>Current distribution</p>
                <?php
                $status_totals = [];
                foreach ($db_orders as $o) {
                    $s = strtolower(trim($o['status'] ?? 'pending'));
                    $status_totals[$s] = ($status_totals[$s] ?? 0) + 1;
                }
                $total_ord = array_sum($status_totals) ?: 1;
                foreach ($status_totals as $s => $cnt):
                    $pct = round($cnt / $total_ord * 100);
                    $sc  = $status_colors[$s] ?? ['bg'=>'#f3f4f6','color'=>'#6b7280'];
                ?>
                <div class="pw">
                    <div class="pw-l"><span><?= ucfirst($s) ?></span><span><?= $cnt ?> (<?= $pct ?>%)</span></div>
                    <div class="pw-bg"><div class="pw-f" style="width:<?= $pct ?>%;background:<?= $sc['color'] ?>"></div></div>
                </div>
                <?php endforeach; ?>
                <?php if (!$status_totals): ?><p style="color:var(--ink-muted);font-size:.82rem">No orders yet.</p><?php endif; ?>
            </div>

            <div class="cc">
                <h3>Category Stock Distribution</h3>
                <p>Units in stock per category</p>
                <?php
                $total_stk = array_sum(array_column($cat_counts, 'stock')) ?: 1;
                foreach ($cat_counts as $cc):
                    if ($cc['stock'] < 1) continue;
                    $pct = round($cc['stock'] / $total_stk * 100);
                ?>
                <div class="pw">
                    <div class="pw-l"><span><?= htmlspecialchars($cc['name']) ?></span><span><?= $cc['stock'] ?> units</span></div>
                    <div class="pw-bg"><div class="pw-f" style="width:<?= $pct ?>%"></div></div>
                </div>
                <?php endforeach; ?>
                <?php if (!$cat_counts): ?><p style="color:var(--ink-muted);font-size:.82rem">No data.</p><?php endif; ?>
            </div>
        </div>

        <div class="tc">
            <div class="tc-hdr"><div><h3>Top Selling Products</h3><p>By total quantity ordered</p></div></div>
            <div class="tbl-wrap">
            <?php
            $product_sales = [];
            foreach ($raw_items as $item) {
                $pid  = $item['product_id'] ?? $item['product_name'];
                $name = $item['product_name'];
                if (!isset($product_sales[$pid])) $product_sales[$pid] = ['name' => $name, 'qty' => 0, 'rev' => 0];
                $product_sales[$pid]['qty'] += (int)$item['quantity'];
                $product_sales[$pid]['rev'] += (float)$item['total_price'];
            }
            usort($product_sales, fn($a,$b) => $b['qty'] - $a['qty']);
            ?>
            <?php if ($product_sales): ?>
            <table>
                <thead><tr><th>#</th><th>Product</th><th>Units Sold</th><th>Revenue (LKR)</th></tr></thead>
                <tbody>
                    <?php foreach (array_slice($product_sales, 0, 20) as $i => $ps): ?>
                    <tr>
                        <td class="muted"><?= $i+1 ?></td>
                        <td><strong><?= htmlspecialchars($ps['name']) ?></strong></td>
                        <td><?= $ps['qty'] ?></td>
                        <td><strong><?= number_format($ps['rev'], 2) ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty-state"><i class="fas fa-chart-line"></i><p>No sales data yet.</p></div>
            <?php endif; ?>
            </div>
        </div>


        <?php /* ─────────── SETTINGS ─────────── */ ?>
        <?php elseif ($active==='settings' && $is_superadmin): ?>

        <div class="pg-hdr">
            <div class="pg-hdr-l"><h2>Settings</h2><p>Super-admin only configuration</p></div>
        </div>

        <div class="sc">
            <div class="sc-hdr"><i class="fas fa-database"></i> Database / Data Management</div>
            <div class="sc-row">
                <div class="sc-i">
                    <strong>Clear All Orders</strong>
                    <span>Permanently delete all orders and order items. This cannot be undone.</span>
                </div>
                <form method="POST" onsubmit="return confirm('⚠️ Delete ALL orders permanently? This cannot be undone!')">
                    <input type="hidden" name="_action" value="clear_orders">
                    <button type="submit" class="btn-p btn-red btn-sm"><i class="fas fa-trash"></i> Clear All Orders</button>
                </form>
            </div>
        </div>

        <div class="sc">
            <div class="sc-hdr"><i class="fas fa-circle-info"></i> System Info</div>
            <div class="sc-row">
                <div class="sc-i"><strong>PHP Version</strong><span>Runtime version</span></div>
                <span style="font-family:monospace;font-size:.85rem"><?= phpversion() ?></span>
            </div>
            <div class="sc-row">
                <div class="sc-i"><strong>Database</strong><span>Connection status</span></div>
                <span class="sb <?= $pdo ? 'sa' : 'so' ?>"><?= $pdo ? 'Connected' : 'Disconnected' ?></span>
            </div>
            <div class="sc-row">
                <div class="sc-i"><strong>Admin Role</strong><span>Current session role</span></div>
                <span class="role-chip superadmin"><i class="fas fa-shield-halved"></i> Super Admin</span>
            </div>
            <div class="sc-row">
                <div class="sc-i"><strong>Total Products</strong><span>In database</span></div>
                <span style="font-weight:700"><?= $agg['total_products'] ?></span>
            </div>
            <div class="sc-row">
                <div class="sc-i"><strong>Total Orders</strong><span>All time</span></div>
                <span style="font-weight:700"><?= $order_stats['total_orders'] ?></span>
            </div>
        </div>

        <?php endif; ?>


    </main>
</div><!-- /main -->


<!-- ══════════════ ADD PRODUCT MODAL ══════════════ -->
<div class="m-overlay" id="addProductModal">
<div class="modal modal-lg">
    <div class="m-hdr">
        <h3><i class="fas fa-plus" style="color:var(--accent);margin-right:6px"></i>Add New Product</h3>
        <button class="m-close" onclick="closeModal('addProductModal')"><i class="fas fa-xmark"></i></button>
    </div>
    <form method="POST" enctype="multipart/form-data">
    <input type="hidden" name="_action" value="add_product">
    <div class="m-body">
        <div class="f-row">
            <div class="fg">
                <label>Product Name <span>*</span></label>
                <input type="text" name="name" class="fc" placeholder="e.g. Samsung 970 EVO Plus" required>
            </div>
            <div class="fg">
                <label>Brand</label>
                <input type="text" name="brand" class="fc" placeholder="e.g. Samsung">
            </div>
        </div>
        <div class="f-row">
            <div class="fg">
                <label>Category <span>*</span></label>
                <select name="category" class="fc" required>
                    <option value="">— Select Category —</option>
                    <?php foreach ($cat_map as $slug => $cat): ?>
                    <option value="<?= htmlspecialchars($slug) ?>"><?= htmlspecialchars($cat['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="fg">
                <label>Stock Count</label>
                <input type="number" name="stock_count" class="fc" value="0" min="0">
            </div>
        </div>
        <div class="f-row">
            <div class="fg">
                <label>Selling Price (LKR) <span>*</span></label>
                <input type="number" name="price" class="fc" step="0.01" min="0.01" placeholder="0.00" required>
            </div>
            <div class="fg">
                <label>Original / Strike Price (LKR)</label>
                <input type="number" name="original_price" class="fc" step="0.01" min="0" placeholder="0.00">
            </div>
        </div>
        <div class="fg">
            <label>Product Image</label>
            <div class="img-prev" id="addImgPrev">
                <img id="addImgThumb" src="" alt="">
                <i class="fas fa-image iph" id="addImgIcon"></i>
            </div>
            <input type="file" name="image_file" class="fc" accept="image/*" onchange="previewImage(this,'addImgThumb','addImgIcon')">
            <div class="f-hint">Or paste a URL below</div>
            <input type="url" name="image_url" class="fc" style="margin-top:5px" placeholder="https://…" oninput="setUrlPreview(this,'addImgThumb','addImgIcon')">
        </div>
        <div class="fg">
            <label>Specs / Features <span style="font-weight:500;color:var(--ink-muted)">(one per line)</span></label>
            <textarea name="specs" class="fc" rows="4" placeholder="500GB NVMe SSD&#10;PCIe 3.0 x4&#10;Read: 3500 MB/s"></textarea>
        </div>
    </div>
    <div class="m-foot">
        <button type="button" class="btn-o" onclick="closeModal('addProductModal')">Cancel</button>
        <button type="submit" class="btn-p"><i class="fas fa-plus"></i> Add Product</button>
    </div>
    </form>
</div>
</div>

<!-- ══════════════ EDIT PRODUCT MODAL ══════════════ -->
<div class="m-overlay" id="editProductModal">
<div class="modal modal-lg">
    <div class="m-hdr">
        <h3><i class="fas fa-pen" style="color:#3b82f6;margin-right:6px"></i>Edit Product</h3>
        <button class="m-close" onclick="closeModal('editProductModal')"><i class="fas fa-xmark"></i></button>
    </div>
    <form method="POST" enctype="multipart/form-data">
    <input type="hidden" name="_action" value="edit_product">
    <input type="hidden" name="product_id" id="ep_id">
    <input type="hidden" name="existing_image" id="ep_existing_image">
    <div class="m-body">
        <div class="f-row">
            <div class="fg">
                <label>Product Name <span>*</span></label>
                <input type="text" name="name" id="ep_name" class="fc" required>
            </div>
            <div class="fg">
                <label>Brand</label>
                <input type="text" name="brand" id="ep_brand" class="fc">
            </div>
        </div>
        <div class="f-row">
            <div class="fg">
                <label>Category <span>*</span></label>
                <select name="category" id="ep_category" class="fc" required>
                    <option value="">— Select Category —</option>
                    <?php foreach ($cat_map as $slug => $cat): ?>
                    <option value="<?= htmlspecialchars($slug) ?>"><?= htmlspecialchars($cat['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="fg">
                <label>Stock Count</label>
                <input type="number" name="stock_count" id="ep_stock" class="fc" min="0">
            </div>
        </div>
        <div class="f-row">
            <div class="fg">
                <label>Selling Price (LKR) <span>*</span></label>
                <input type="number" name="price" id="ep_price" class="fc" step="0.01" required>
            </div>
            <div class="fg">
                <label>Original / Strike Price (LKR)</label>
                <input type="number" name="original_price" id="ep_orig" class="fc" step="0.01">
            </div>
        </div>
        <div class="fg">
            <label>Product Image</label>
            <div class="img-prev">
                <img id="ep_thumb" src="" alt="">
                <i class="fas fa-image iph" id="ep_icon"></i>
            </div>
            <input type="file" name="image_file" class="fc" accept="image/*" onchange="previewImage(this,'ep_thumb','ep_icon')">
            <div class="f-hint">Or paste a URL (leave blank to keep existing)</div>
            <input type="url" name="image_url" id="ep_image_url" class="fc" style="margin-top:5px" placeholder="https://…" oninput="setUrlPreview(this,'ep_thumb','ep_icon')">
        </div>
        <div class="fg">
            <label>Specs / Features <span style="font-weight:500;color:var(--ink-muted)">(one per line)</span></label>
            <textarea name="specs" id="ep_specs" class="fc" rows="4"></textarea>
        </div>
    </div>
    <div class="m-foot">
        <button type="button" class="btn-o" onclick="closeModal('editProductModal')">Cancel</button>
        <button type="submit" class="btn-p"><i class="fas fa-check"></i> Save Changes</button>
    </div>
    </form>
</div>
</div>


<!-- ══════════════ ADD CATEGORY MODAL ══════════════ -->
<div class="m-overlay" id="addCatModal">
<div class="modal">
    <div class="m-hdr">
        <h3><i class="fas fa-plus" style="color:var(--accent);margin-right:6px"></i>Add Category</h3>
        <button class="m-close" onclick="closeModal('addCatModal')"><i class="fas fa-xmark"></i></button>
    </div>
    <form method="POST">
    <input type="hidden" name="_action" value="add_category">
    <div class="m-body">
        <div class="fg">
            <label>Category Name <span>*</span></label>
            <input type="text" name="cat_name" class="fc" placeholder="e.g. Laptops" required
                   oninput="document.getElementById('acSlugPreview').value=slugify(this.value)">
        </div>
        <div class="fg">
            <label>Slug (auto-generated)</label>
            <input type="text" name="cat_slug" id="acSlugPreview" class="fc" placeholder="laptops">
            <div class="f-hint">Used to match products — lowercase, underscores only</div>
        </div>
        <div class="fg">
            <label>Description</label>
            <textarea name="cat_desc" class="fc" rows="2" placeholder="Optional description"></textarea>
        </div>
        <div class="fg">
            <label>Icon</label>
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:7px">
                <div class="icon-preview" id="acIconPreview"><i class="fas fa-tag" id="acIconPreviewI"></i></div>
                <input type="text" name="cat_icon" id="acIconInput" class="fc" value="fa-tag" style="width:160px" readonly>
            </div>
            <div class="icon-grid">
                <?php foreach ($icon_options as $ico): ?>
                <div class="icon-opt <?= $ico==='fa-tag'?'selected':'' ?>"
                     onclick="selectIcon(this,'<?= $ico ?>','acIconInput','acIconPreviewI')"
                     title="<?= $ico ?>">
                    <i class="fas <?= $ico ?>"></i>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="m-foot">
        <button type="button" class="btn-o" onclick="closeModal('addCatModal')">Cancel</button>
        <button type="submit" class="btn-p"><i class="fas fa-plus"></i> Add Category</button>
    </div>
    </form>
</div>
</div>


<!-- ══════════════ EDIT CATEGORY MODAL ══════════════ -->
<div class="m-overlay" id="editCatModal">
<div class="modal">
    <div class="m-hdr">
        <h3><i class="fas fa-pen" style="color:#3b82f6;margin-right:6px"></i>Edit Category</h3>
        <button class="m-close" onclick="closeModal('editCatModal')"><i class="fas fa-xmark"></i></button>
    </div>
    <form method="POST">
    <input type="hidden" name="_action"  value="edit_category">
    <input type="hidden" name="cat_id"   id="ec_id">
    <input type="hidden" name="old_slug" id="ec_old_slug">
    <div class="m-body">
        <div class="fg">
            <label>Category Name <span>*</span></label>
            <input type="text" name="cat_name" id="ec_name" class="fc" required>
        </div>
        <div class="fg">
            <label>Slug</label>
            <input type="text" name="cat_slug" id="ec_slug" class="fc">
            <div class="f-hint">Changing slug will re-assign all matching products</div>
        </div>
        <div class="fg">
            <label>Description</label>
            <textarea name="cat_desc" id="ec_desc" class="fc" rows="2"></textarea>
        </div>
        <div class="fg">
            <label>Icon</label>
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:7px">
                <div class="icon-preview" id="ecIconPreview"><i class="fas fa-tag" id="ecIconPreviewI"></i></div>
                <input type="text" name="cat_icon" id="ecIconInput" class="fc" value="fa-tag" style="width:160px" readonly>
            </div>
            <div class="icon-grid">
                <?php foreach ($icon_options as $ico): ?>
                <div class="icon-opt"
                     onclick="selectIcon(this,'<?= $ico ?>','ecIconInput','ecIconPreviewI')"
                     title="<?= $ico ?>">
                    <i class="fas <?= $ico ?>"></i>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="m-foot">
        <button type="button" class="btn-o" onclick="closeModal('editCatModal')">Cancel</button>
        <button type="submit" class="btn-p"><i class="fas fa-check"></i> Save Changes</button>
    </div>
    </form>
</div>
</div>


<script>
// ── Modal helpers ─────────────────────────────────────────────────────────────
function openModal(id)  { document.getElementById(id).classList.add('open');  document.body.style.overflow='hidden'; }
function closeModal(id) { document.getElementById(id).classList.remove('open'); document.body.style.overflow=''; }
document.querySelectorAll('.m-overlay').forEach(o => {
    o.addEventListener('click', e => { if (e.target === o) closeModal(o.id); });
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') document.querySelectorAll('.m-overlay.open').forEach(o => closeModal(o.id));
});

// ── Slug helper (JS mirror of PHP slugify) ────────────────────────────────────
function slugify(t) {
    return t.toLowerCase().trim().replace(/[^a-z0-9]+/g,'_').replace(/^_+|_+$/g,'');
}

// ── Image preview ─────────────────────────────────────────────────────────────
function previewImage(input, thumbId, iconId) {
    const file = input.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = e => {
        document.getElementById(thumbId).src = e.target.result;
        document.getElementById(thumbId).style.display = 'block';
        document.getElementById(iconId).style.display  = 'none';
    };
    reader.readAsDataURL(file);
}
function setUrlPreview(input, thumbId, iconId) {
    const url = input.value.trim();
    if (url) {
        document.getElementById(thumbId).src = url;
        document.getElementById(thumbId).style.display = 'block';
        document.getElementById(iconId).style.display  = 'none';
    }
}

// ── Edit Product modal ────────────────────────────────────────────────────────
function openEditProduct(d) {
    document.getElementById('ep_id').value            = d.id;
    document.getElementById('ep_name').value          = d.name;
    document.getElementById('ep_brand').value         = d.brand;
    document.getElementById('ep_price').value         = d.price;
    document.getElementById('ep_orig').value          = d.original_price;
    document.getElementById('ep_stock').value         = d.stock_count;
    document.getElementById('ep_specs').value         = d.specs;
    document.getElementById('ep_existing_image').value= d.image;
    document.getElementById('ep_image_url').value     = '';

    const sel = document.getElementById('ep_category');
    for (let i=0;i<sel.options.length;i++) if (sel.options[i].value===d.category) { sel.selectedIndex=i; break; }

    const thumb = document.getElementById('ep_thumb');
    const icon  = document.getElementById('ep_icon');
    if (d.image) {
        thumb.src = d.image.startsWith('http') ? d.image : '../' + d.image;
        thumb.style.display = 'block';
        icon.style.display  = 'none';
    } else {
        thumb.style.display = 'none';
        icon.style.display  = 'block';
    }
    openModal('editProductModal');
}

// ── Edit Category modal ───────────────────────────────────────────────────────
function openEditCat(d) {
    document.getElementById('ec_id').value       = d.id;
    document.getElementById('ec_name').value     = d.name;
    document.getElementById('ec_slug').value     = d.slug;
    document.getElementById('ec_old_slug').value = d.slug;
    document.getElementById('ec_desc').value     = d.desc;
    document.getElementById('ecIconInput').value = d.icon;
    document.getElementById('ecIconPreviewI').className = 'fas ' + d.icon;

    // update selected state in icon grid
    document.querySelectorAll('#editCatModal .icon-opt').forEach(el => {
        el.classList.toggle('selected', el.title === d.icon);
    });
    openModal('editCatModal');
}

// ── Icon selector ─────────────────────────────────────────────────────────────
function selectIcon(el, ico, inputId, previewId) {
    el.closest('.icon-grid').querySelectorAll('.icon-opt').forEach(o => o.classList.remove('selected'));
    el.classList.add('selected');
    document.getElementById(inputId).value = ico;
    document.getElementById(previewId).className = 'fas ' + ico;
}

// ── Product category filter tabs ──────────────────────────────────────────────
document.querySelectorAll('#catTabs .ftab').forEach(tab => {
    tab.addEventListener('click', e => {
        e.preventDefault();
        document.querySelectorAll('#catTabs .ftab').forEach(t => t.classList.remove('active'));
        tab.classList.add('active');
        const cat = tab.dataset.cat;
        document.querySelectorAll('#prodTable tbody tr').forEach(row => {
            row.style.display = (cat === 'all' || row.dataset.cat === cat) ? '' : 'none';
        });
    });
});

// ── Order status filter tabs ──────────────────────────────────────────────────
document.querySelectorAll('#orderTabs .ftab').forEach(tab => {
    tab.addEventListener('click', e => {
        e.preventDefault();
        document.querySelectorAll('#orderTabs .ftab').forEach(t => t.classList.remove('active'));
        tab.classList.add('active');
        const filter = tab.dataset.filter;
        let visible = 0;
        document.querySelectorAll('#orderTable tbody tr.order-main-row').forEach(row => {
            const show = filter === 'all' || row.dataset.status === filter;
            row.style.display = show ? '' : 'none';
            // also hide the associated detail row if not visible
            const detailRow = document.getElementById('order-detail-' + row.dataset.orderId);
            if (detailRow && !show) detailRow.classList.remove('open');
            if (show) visible++;
        });
        const vc = document.getElementById('orderVisibleCount');
        if (vc) vc.textContent = visible;
    });
});

// ── Order items expand ────────────────────────────────────────────────────────
function toggleOrderItems(orderId) {
    const detailRow = document.getElementById('order-detail-' + orderId);
    const btn = document.querySelector('[onclick="toggleOrderItems(' + orderId + ')"]');
    if (!detailRow) return;
    const isOpen = detailRow.classList.toggle('open');
    if (btn) btn.classList.toggle('open', isOpen);
}

// ── Table search ──────────────────────────────────────────────────────────────
function tableSearch(inputId, tableId, rowSelector) {
    const input = document.getElementById(inputId);
    if (!input) return;
    input.addEventListener('input', () => {
        const q = input.value.toLowerCase();
        document.querySelectorAll('#' + tableId + ' ' + (rowSelector||'tbody tr')).forEach(row => {
            row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
        });
    });
}
tableSearch('prodSearch',  'prodTable',  'tbody tr');
tableSearch('orderSearch', 'orderTable', 'tbody tr.order-main-row');
tableSearch('custSearch',  'custTable',  'tbody tr');

// ── Global topbar search ──────────────────────────────────────────────────────
document.getElementById('gSearch')?.addEventListener('input', function() {
    const q = this.value.toLowerCase();
    if (!q) return;
    ['prodTable','orderTable','custTable'].forEach(tid => {
        document.querySelectorAll('#' + tid + ' tbody tr').forEach(r => {
            r.style.display = r.textContent.toLowerCase().includes(q) ? '' : 'none';
        });
    });
});

// ── CSV Export ────────────────────────────────────────────────────────────────
function exportTable(tableId) {
    const tbl = document.getElementById(tableId);
    if (!tbl) return;
    let csv = '';
    tbl.querySelectorAll('tr').forEach(row => {
        const cells = [...row.querySelectorAll('th,td')].map(c => '"' + c.innerText.replace(/"/g,'""').replace(/\n/g,' ') + '"');
        csv += cells.join(',') + '\n';
    });
    const a   = document.createElement('a');
    a.href    = 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv);
    a.download = tableId + '_' + new Date().toISOString().slice(0,10) + '.csv';
    a.click();
}
</script>

</body>
</html>