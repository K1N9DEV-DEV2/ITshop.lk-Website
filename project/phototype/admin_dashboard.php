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

    // ── Ensure ticker table exists ────────────────────────────────────────────
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS ticker_items (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            message    TEXT NOT NULL,
            link_url   VARCHAR(500) NOT NULL DEFAULT '',
            link_text  VARCHAR(120) NOT NULL DEFAULT '',
            emoji      VARCHAR(10)  NOT NULL DEFAULT '',
            is_active  TINYINT(1)   NOT NULL DEFAULT 1,
            sort_order INT          NOT NULL DEFAULT 0,
            created_at DATETIME     NOT NULL DEFAULT NOW(),
            updated_at DATETIME     NOT NULL DEFAULT NOW() ON UPDATE NOW()
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Ensure ticker_settings table for global on/off
        $pdo->exec("CREATE TABLE IF NOT EXISTS ticker_settings (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(80) NOT NULL UNIQUE,
            setting_val TEXT        NOT NULL DEFAULT ''
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Default: ticker enabled
        $pdo->exec("INSERT IGNORE INTO ticker_settings (setting_key, setting_val) VALUES ('ticker_enabled','1')");
        $pdo->exec("INSERT IGNORE INTO ticker_settings (setting_key, setting_val) VALUES ('ticker_speed','35')");
        $pdo->exec("INSERT IGNORE INTO ticker_settings (setting_key, setting_val) VALUES ('ticker_color','#3b5bdb')");
    } catch (PDOException $e) { /* silently skip */ }

    // ── Ensure advertisements table exists ────────────────────────────────────
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS advertisements (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            title       VARCHAR(255)  NOT NULL,
            subtitle    VARCHAR(255)  NOT NULL DEFAULT '',
            image       VARCHAR(500)  NOT NULL DEFAULT '',
            link_url    VARCHAR(500)  NOT NULL DEFAULT '',
            position    VARCHAR(50)   NOT NULL DEFAULT 'hero',
            badge_text  VARCHAR(60)   NOT NULL DEFAULT '',
            btn_text    VARCHAR(60)   NOT NULL DEFAULT 'Shop Now',
            btn_color   VARCHAR(20)   NOT NULL DEFAULT '#0cb100',
            is_active   TINYINT(1)    NOT NULL DEFAULT 1,
            sort_order  INT           NOT NULL DEFAULT 0,
            created_at  DATETIME      NOT NULL DEFAULT NOW(),
            updated_at  DATETIME      NOT NULL DEFAULT NOW() ON UPDATE NOW()
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
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

// ── Load advertisements ───────────────────────────────────────────────────────
$db_ads = dbq(
    "SELECT * FROM advertisements ORDER BY position ASC, sort_order ASC, id DESC"
) ?: [];

// ── Load ticker data ─────────────────────────────────────────────────────────
$db_ticker_items = dbq("SELECT * FROM ticker_items ORDER BY sort_order ASC, id ASC") ?: [];
$ticker_settings = [];
$ts_rows = dbq("SELECT setting_key, setting_val FROM ticker_settings") ?: [];
foreach ($ts_rows as $r) $ticker_settings[$r['setting_key']] = $r['setting_val'];
$ticker_enabled = ($ticker_settings['ticker_enabled'] ?? '1') === '1';
$ticker_speed   = (int)($ticker_settings['ticker_speed'] ?? 35);
$ticker_color   = $ticker_settings['ticker_color'] ?? '#3b5bdb';
$ticker_active_count = count(array_filter($db_ticker_items, fn($t) => $t['is_active']));

$ad_positions = [
    'hero'    => ['label' => 'Hero / Main Banner',  'icon' => 'fa-panorama',        'color' => '#0cb100', 'desc' => 'Full-width hero slider at top of homepage'],
    'banner'  => ['label' => 'Section Banner',       'icon' => 'fa-rectangle-wide',  'color' => '#3b82f6', 'desc' => 'Mid-page promotional banner strip'],
    'sidebar' => ['label' => 'Sidebar Promo',        'icon' => 'fa-sidebar',         'color' => '#8b5cf6', 'desc' => 'Right-side sidebar widget'],
    'popup'   => ['label' => 'Popup / Overlay',      'icon' => 'fa-window-maximize', 'color' => '#f59e0b', 'desc' => 'Modal overlay shown on page load'],
    'footer'  => ['label' => 'Footer Banner',        'icon' => 'fa-rectangle',       'color' => '#6b7280', 'desc' => 'Banner in footer area above copyright'],
];

$ads_by_position = [];
foreach ($db_ads as $ad) {
    $ads_by_position[$ad['position']][] = $ad;
}
$active_ads   = count(array_filter($db_ads, fn($a) => $a['is_active']));
$inactive_ads = count($db_ads) - $active_ads;

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
                $pdo->prepare("INSERT INTO categories (name, description, slug, icon) VALUES (?, ?, ?, ?)")
                    ->execute([$cat_name, $cat_desc, $cat_slug, $cat_icon]);
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
                $pdo->prepare("UPDATE categories SET name=?, description=?, slug=?, icon=?, updated_at=NOW() WHERE id=?")
                    ->execute([$cat_name, $cat_desc, $cat_slug, $cat_icon, $cid]);
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
        $orphans  = dbq("SELECT DISTINCT p.category FROM products p LEFT JOIN categories c ON c.slug = p.category WHERE c.id IS NULL AND p.category != ''") ?: [];
        foreach ($orphans as $row) {
            $sl  = $row['category'];
            $lbl = ucwords(str_replace(['_', '-'], ' ', $sl));
            try {
                $pdo->prepare("INSERT IGNORE INTO categories (name, slug, icon) VALUES (?, ?, 'fa-tag')")->execute([$lbl, $sl]);
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
            $ext = strtolower(pathinfo($_FILES['image_file']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','webp','gif'])) {
                $dir = 'uploads/products/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $fname = uniqid('prod_') . '.' . $ext;
                if (move_uploaded_file($_FILES['image_file']['tmp_name'], $dir . $fname)) $image = $dir . $fname;
            }
        }
        if ($name && $category && $price > 0) {
            try {
                $pdo->prepare("INSERT INTO products (name, brand, category, price, original_price, stock_count, image) VALUES (?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$name, $brand, $category, $price, $orig ?: $price, $stock, $image]);
                $new_id = $pdo->lastInsertId();
                $specs_raw = trim($_POST['specs'] ?? '');
                if ($specs_raw && $new_id) {
                    $sp = $pdo->prepare("INSERT INTO product_specs (product_id, spec_name) VALUES (?, ?)");
                    foreach (array_filter(array_map('trim', explode("\n", $specs_raw))) as $sl) $sp->execute([$new_id, $sl]);
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
            $ext = strtolower(pathinfo($_FILES['image_file']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','webp','gif'])) {
                $dir = 'uploads/products/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $fname = uniqid('prod_') . '.' . $ext;
                if (move_uploaded_file($_FILES['image_file']['tmp_name'], $dir . $fname)) $image = $dir . $fname;
            }
        }
        if (!$image) $image = trim($_POST['existing_image'] ?? '');
        if ($pid && $name && $category && $price > 0) {
            try {
                $pdo->prepare("UPDATE products SET name=?, brand=?, category=?, price=?, original_price=?, stock_count=?, image=? WHERE id=?")
                    ->execute([$name, $brand, $category, $price, $orig ?: $price, $stock, $image, $pid]);
                $specs_raw = trim($_POST['specs'] ?? '');
                $pdo->prepare("DELETE FROM product_specs WHERE product_id=?")->execute([$pid]);
                if ($specs_raw) {
                    $sp = $pdo->prepare("INSERT INTO product_specs (product_id, spec_name) VALUES (?, ?)");
                    foreach (array_filter(array_map('trim', explode("\n", $specs_raw))) as $sl) $sp->execute([$pid, $sl]);
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

    // ── TICKER SETTINGS ───────────────────────────────────────────────────────
    if ($act === 'save_ticker_settings') {
        $t_enabled = isset($_POST['ticker_enabled']) ? '1' : '0';
        $t_speed   = max(10, min(120, intval($_POST['ticker_speed'] ?? 35)));
        $t_color   = preg_match('/^#[0-9a-f]{6}$/i', $_POST['ticker_color'] ?? '') ? $_POST['ticker_color'] : '#3b5bdb';
        try {
            $pdo->prepare("INSERT INTO ticker_settings (setting_key, setting_val) VALUES ('ticker_enabled',?) ON DUPLICATE KEY UPDATE setting_val=?")->execute([$t_enabled, $t_enabled]);
            $pdo->prepare("INSERT INTO ticker_settings (setting_key, setting_val) VALUES ('ticker_speed',?) ON DUPLICATE KEY UPDATE setting_val=?")->execute([$t_speed, $t_speed]);
            $pdo->prepare("INSERT INTO ticker_settings (setting_key, setting_val) VALUES ('ticker_color',?) ON DUPLICATE KEY UPDATE setting_val=?")->execute([$t_color, $t_color]);
            $flash = ['type' => 'success', 'msg' => 'Ticker settings saved!'];
        } catch (PDOException $e) {
            $flash = ['type' => 'error', 'msg' => 'Could not save: ' . $e->getMessage()];
        }
        $_SESSION['flash'] = $flash;
        header('Location: ?page=ticker');
        exit;
    }

    // ── TOGGLE TICKER GLOBAL ──────────────────────────────────────────────────
    if ($act === 'toggle_ticker_global') {
        $new_val   = $ticker_enabled ? '0' : '1';
        $back_page = trim($_POST['_back_page'] ?? 'ticker');
        $allowed   = ['dashboard','ticker'];
        if (!in_array($back_page, $allowed)) $back_page = 'ticker';
        try {
            $pdo->prepare("INSERT INTO ticker_settings (setting_key, setting_val) VALUES ('ticker_enabled',?) ON DUPLICATE KEY UPDATE setting_val=?")->execute([$new_val, $new_val]);
            $flash = ['type' => 'success', 'msg' => 'Ticker ' . ($new_val === '1' ? 'enabled ✓' : 'disabled') . '.'];
        } catch (PDOException $e) {}
        $_SESSION['flash'] = $flash;
        header('Location: ?page=' . $back_page);
        exit;
    }

    // ── ADD TICKER ITEM ───────────────────────────────────────────────────────
    if ($act === 'add_ticker') {
        $t_msg   = trim($_POST['ticker_message'] ?? '');
        $t_link  = trim($_POST['ticker_link_url'] ?? '');
        $t_ltxt  = trim($_POST['ticker_link_text'] ?? '');
        $t_emoji = trim($_POST['ticker_emoji'] ?? '');
        $t_order = intval($_POST['ticker_order'] ?? 0);
        $t_active= isset($_POST['ticker_active']) ? 1 : 0;
        if ($t_msg) {
            try {
                $pdo->prepare("INSERT INTO ticker_items (message, link_url, link_text, emoji, is_active, sort_order) VALUES (?,?,?,?,?,?)")
                    ->execute([$t_msg, $t_link, $t_ltxt, $t_emoji, $t_active, $t_order]);
                $flash = ['type' => 'success', 'msg' => 'Ticker item added!'];
            } catch (PDOException $e) {
                $flash = ['type' => 'error', 'msg' => 'DB error: ' . $e->getMessage()];
            }
        } else { $flash = ['type' => 'error', 'msg' => 'Message is required.']; }
        $_SESSION['flash'] = $flash;
        header('Location: ?page=ticker');
        exit;
    }

    // ── EDIT TICKER ITEM ──────────────────────────────────────────────────────
    if ($act === 'edit_ticker') {
        $tid     = intval($_POST['ticker_id'] ?? 0);
        $t_msg   = trim($_POST['ticker_message'] ?? '');
        $t_link  = trim($_POST['ticker_link_url'] ?? '');
        $t_ltxt  = trim($_POST['ticker_link_text'] ?? '');
        $t_emoji = trim($_POST['ticker_emoji'] ?? '');
        $t_order = intval($_POST['ticker_order'] ?? 0);
        $t_active= isset($_POST['ticker_active']) ? 1 : 0;
        if ($tid && $t_msg) {
            try {
                $pdo->prepare("UPDATE ticker_items SET message=?, link_url=?, link_text=?, emoji=?, is_active=?, sort_order=?, updated_at=NOW() WHERE id=?")
                    ->execute([$t_msg, $t_link, $t_ltxt, $t_emoji, $t_active, $t_order, $tid]);
                $flash = ['type' => 'success', 'msg' => 'Ticker item updated!'];
            } catch (PDOException $e) {
                $flash = ['type' => 'error', 'msg' => 'DB error: ' . $e->getMessage()];
            }
        }
        $_SESSION['flash'] = $flash;
        header('Location: ?page=ticker');
        exit;
    }

    // ── DELETE TICKER ITEM ────────────────────────────────────────────────────
    if ($act === 'delete_ticker') {
        $tid = intval($_POST['ticker_id'] ?? 0);
        if ($tid) {
            try {
                $pdo->prepare("DELETE FROM ticker_items WHERE id=?")->execute([$tid]);
                $flash = ['type' => 'success', 'msg' => 'Ticker item deleted.'];
            } catch (PDOException $e) {
                $flash = ['type' => 'error', 'msg' => 'Could not delete: ' . $e->getMessage()];
            }
        }
        $_SESSION['flash'] = $flash;
        header('Location: ?page=ticker');
        exit;
    }

    // ── TOGGLE TICKER ITEM ────────────────────────────────────────────────────
    if ($act === 'toggle_ticker_item') {
        $tid = intval($_POST['ticker_id'] ?? 0);
        if ($tid) {
            try { $pdo->prepare("UPDATE ticker_items SET is_active = 1 - is_active WHERE id=?")->execute([$tid]); }
            catch (PDOException $e) {}
        }
        header('Location: ?page=ticker');
        exit;
    }

    // ── ADD ADVERTISEMENT ─────────────────────────────────────────────────────
    if ($act === 'add_ad') {
        $ad_title     = trim($_POST['ad_title'] ?? '');
        $ad_subtitle  = trim($_POST['ad_subtitle'] ?? '');
        $ad_link      = trim($_POST['ad_link'] ?? '');
        $ad_position  = trim($_POST['ad_position'] ?? 'hero');
        $ad_badge     = trim($_POST['ad_badge'] ?? '');
        $ad_btn_text  = trim($_POST['ad_btn_text'] ?? 'Shop Now');
        $ad_btn_color = trim($_POST['ad_btn_color'] ?? '#0cb100');
        $ad_active    = isset($_POST['ad_active']) ? 1 : 0;
        $ad_order     = intval($_POST['ad_order'] ?? 0);
        $ad_image     = trim($_POST['ad_image_url'] ?? '');
        if (!empty($_FILES['ad_image_file']['name']) && $_FILES['ad_image_file']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['ad_image_file']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','webp','gif'])) {
                $dir = 'uploads/ads/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $fname = uniqid('ad_') . '.' . $ext;
                if (move_uploaded_file($_FILES['ad_image_file']['tmp_name'], $dir . $fname)) $ad_image = $dir . $fname;
            }
        }
        if ($ad_title) {
            try {
                $pdo->prepare("INSERT INTO advertisements (title,subtitle,image,link_url,position,badge_text,btn_text,btn_color,is_active,sort_order) VALUES (?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$ad_title,$ad_subtitle,$ad_image,$ad_link,$ad_position,$ad_badge,$ad_btn_text,$ad_btn_color,$ad_active,$ad_order]);
                $flash = ['type' => 'success', 'msg' => "Ad \"$ad_title\" created!"];
            } catch (PDOException $e) {
                $flash = ['type' => 'error', 'msg' => 'DB error: ' . $e->getMessage()];
            }
        } else {
            $flash = ['type' => 'error', 'msg' => 'Ad title is required.'];
        }
        $_SESSION['flash'] = $flash;
        header('Location: ?page=advertisements');
        exit;
    }

    // ── EDIT ADVERTISEMENT ────────────────────────────────────────────────────
    if ($act === 'edit_ad') {
        $aid          = intval($_POST['ad_id'] ?? 0);
        $ad_title     = trim($_POST['ad_title'] ?? '');
        $ad_subtitle  = trim($_POST['ad_subtitle'] ?? '');
        $ad_link      = trim($_POST['ad_link'] ?? '');
        $ad_position  = trim($_POST['ad_position'] ?? 'hero');
        $ad_badge     = trim($_POST['ad_badge'] ?? '');
        $ad_btn_text  = trim($_POST['ad_btn_text'] ?? 'Shop Now');
        $ad_btn_color = trim($_POST['ad_btn_color'] ?? '#0cb100');
        $ad_active    = isset($_POST['ad_active']) ? 1 : 0;
        $ad_order     = intval($_POST['ad_order'] ?? 0);
        $ad_image     = trim($_POST['ad_image_url'] ?? '');
        if (!empty($_FILES['ad_image_file']['name']) && $_FILES['ad_image_file']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['ad_image_file']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','webp','gif'])) {
                $dir = 'uploads/ads/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $fname = uniqid('ad_') . '.' . $ext;
                if (move_uploaded_file($_FILES['ad_image_file']['tmp_name'], $dir . $fname)) $ad_image = $dir . $fname;
            }
        }
        if (!$ad_image) $ad_image = trim($_POST['existing_ad_image'] ?? '');
        if ($aid && $ad_title) {
            try {
                $pdo->prepare("UPDATE advertisements SET title=?,subtitle=?,image=?,link_url=?,position=?,badge_text=?,btn_text=?,btn_color=?,is_active=?,sort_order=? WHERE id=?")
                    ->execute([$ad_title,$ad_subtitle,$ad_image,$ad_link,$ad_position,$ad_badge,$ad_btn_text,$ad_btn_color,$ad_active,$ad_order,$aid]);
                $flash = ['type' => 'success', 'msg' => "Ad \"$ad_title\" updated!"];
            } catch (PDOException $e) {
                $flash = ['type' => 'error', 'msg' => 'DB error: ' . $e->getMessage()];
            }
        }
        $_SESSION['flash'] = $flash;
        header('Location: ?page=advertisements');
        exit;
    }

    // ── DELETE ADVERTISEMENT ──────────────────────────────────────────────────
    if ($act === 'delete_ad') {
        $aid = intval($_POST['ad_id'] ?? 0);
        if ($aid) {
            try {
                $pdo->prepare("DELETE FROM advertisements WHERE id=?")->execute([$aid]);
                $flash = ['type' => 'success', 'msg' => 'Ad deleted.'];
            } catch (PDOException $e) {
                $flash = ['type' => 'error', 'msg' => 'Could not delete: ' . $e->getMessage()];
            }
        }
        $_SESSION['flash'] = $flash;
        header('Location: ?page=advertisements');
        exit;
    }

    // ── TOGGLE AD ACTIVE ──────────────────────────────────────────────────────
    if ($act === 'toggle_ad') {
        $aid = intval($_POST['ad_id'] ?? 0);
        if ($aid) {
            try { $pdo->prepare("UPDATE advertisements SET is_active = 1 - is_active WHERE id=?")->execute([$aid]); }
            catch (PDOException $e) {}
        }
        header('Location: ?page=advertisements');
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
    ['page' => 'dashboard',      'icon' => 'fa-gauge-high',      'label' => 'Dashboard',    'roles' => ['admin','superadmin']],
    ['page' => 'products',       'icon' => 'fa-box',             'label' => 'Products',     'roles' => ['admin','superadmin']],
    ['page' => 'orders',         'icon' => 'fa-bag-shopping',    'label' => 'Orders',       'roles' => ['admin','superadmin']],
    ['page' => 'customers',      'icon' => 'fa-users',           'label' => 'Customers',    'roles' => ['admin','superadmin']],
    ['page' => 'categories',     'icon' => 'fa-layer-group',     'label' => 'Categories',   'roles' => ['admin','superadmin']],
    ['page' => 'ticker',         'icon' => 'fa-bullhorn',        'label' => 'Ticker Bar',   'roles' => ['admin','superadmin']],
    ['page' => 'advertisements', 'icon' => 'fa-rectangle-ad',    'label' => 'Ads & Banners','roles' => ['admin','superadmin']],
    ['page' => 'reports',        'icon' => 'fa-chart-line',      'label' => 'Reports',      'roles' => ['admin','superadmin']],
    ['page' => 'settings',       'icon' => 'fa-gear',            'label' => 'Settings',     'roles' => ['superadmin']],
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

        /* ── AD PAGE STYLES ──────────────────────────────────── */
        .ad-stats { display:grid; grid-template-columns:repeat(auto-fill,minmax(175px,1fr)); gap:12px; margin-bottom:1.5rem; }
        .ad-stat  { background:var(--card); border-radius:var(--r-lg); padding:1rem 1.2rem; box-shadow:var(--shadow); display:flex; align-items:center; gap:11px; }
        .ad-stat-ico { width:38px; height:38px; border-radius:var(--r-md); display:flex; align-items:center; justify-content:center; font-size:.95rem; flex-shrink:0; }
        .ad-stat-v { font-size:1.3rem; font-weight:800; color:var(--ink); letter-spacing:-.02em; }
        .ad-stat-l { font-size:.67rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--ink-muted); }
        .pos-section { margin-bottom:1.5rem; }
        .pos-hdr { display:flex; align-items:center; gap:9px; margin-bottom:.8rem; padding:.65rem 1rem; background:var(--card); border-radius:var(--r-md); box-shadow:var(--shadow); }
        .pos-hdr-ico { width:30px; height:30px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:.8rem; }
        .pos-hdr strong { font-size:.87rem; font-weight:700; color:var(--ink); }
        .pos-hdr span   { font-size:.74rem; color:var(--ink-muted); }
        .pos-count { margin-left:auto; font-size:.72rem; font-weight:700; padding:2px 9px; border-radius:100px; }
        .ad-cards { display:grid; grid-template-columns:repeat(auto-fill,minmax(300px,1fr)); gap:11px; }
        .ad-card  { background:var(--card); border-radius:var(--r-lg); box-shadow:var(--shadow); overflow:hidden; transition:box-shadow .18s, transform .18s; animation:fadeUp .35s ease both; }
        .ad-card:hover { box-shadow:0 6px 28px rgba(10,10,15,.13); transform:translateY(-1px); }
        .ad-card.inactive { opacity:.62; }
        .ad-img-wrap { height:140px; background:var(--surface); position:relative; overflow:hidden; }
        .ad-img-wrap img { width:100%; height:100%; object-fit:cover; }
        .ad-img-ph { width:100%; height:100%; display:flex; align-items:center; justify-content:center; color:var(--ink-muted); font-size:2rem; }
        .ad-status-pill { position:absolute; top:8px; left:8px; font-size:.62rem; font-weight:700; padding:3px 9px; border-radius:100px; }
        .ad-pos-pill    { position:absolute; top:8px; right:8px; font-size:.62rem; font-weight:700; padding:3px 9px; border-radius:100px; background:rgba(10,10,15,.55); color:#fff; backdrop-filter:blur(4px); }
        .ad-sort-pill   { position:absolute; bottom:8px; right:8px; font-size:.62rem; font-weight:700; padding:2px 7px; border-radius:100px; background:rgba(10,10,15,.45); color:#fff; }
        .ad-body { padding:.9rem 1rem; }
        .ad-body strong { font-size:.9rem; font-weight:800; color:var(--ink); display:block; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .ad-body .sub   { font-size:.76rem; color:var(--ink-muted); margin:2px 0 8px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .ad-meta { display:flex; gap:6px; flex-wrap:wrap; align-items:center; }
        .ad-meta-tag { font-size:.62rem; font-weight:700; padding:2px 7px; border-radius:100px; background:var(--surface); color:var(--ink-muted); display:inline-flex; align-items:center; gap:3px; }
        .ad-meta-tag i { font-size:.58rem; }
        .ad-foot { padding:.7rem 1rem; border-top:1px solid rgba(10,10,15,.06); display:flex; align-items:center; justify-content:space-between; gap:7px; }
        .ad-actions { display:flex; gap:4px; }
        /* Preview modal */
        .preview-device { background:#1a1a2e; border-radius:var(--r-lg); overflow:hidden; position:relative; }
        .preview-chrome { height:22px; background:#0d0d18; display:flex; align-items:center; padding:0 12px; }
        .preview-chrome::after { content:'IT Shop.LK'; font-size:.62rem; font-weight:700; color:#44445a; }
        .preview-hero { background:linear-gradient(135deg,#111 0%,#1a1a2e 100%); min-height:155px; display:flex; align-items:center; justify-content:space-between; padding:1.2rem 1.4rem; gap:1rem; position:relative; overflow:hidden; }
        .preview-hero-bg { position:absolute; inset:0; object-fit:cover; opacity:.35; width:100%; height:100%; }
        .preview-hero-content { position:relative; z-index:1; }
        .preview-hero-badge { font-size:.58rem; font-weight:800; padding:2px 8px; border-radius:100px; margin-bottom:6px; display:inline-block; }
        .preview-hero-title { font-size:.88rem; font-weight:800; color:#fff; line-height:1.2; margin-bottom:4px; }
        .preview-hero-sub   { font-size:.67rem; color:rgba(255,255,255,.55); margin-bottom:10px; }
        .preview-hero-btn   { font-size:.63rem; font-weight:700; padding:5px 12px; border-radius:6px; display:inline-block; color:#fff; }

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
            <?php if ($item['page']==='advertisements'): ?>
                <?php $inactive_badge = count(array_filter($db_ads, fn($a) => !$a['is_active'])); ?>
                <?php if ($inactive_badge > 0): ?><span class="nav-badge"><?= $inactive_badge ?></span><?php endif; ?>
            <?php endif; ?>
            <?php if ($item['page']==='ticker' && !$ticker_enabled): ?><span class="nav-badge">OFF</span><?php endif; ?>
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
            <?php $ptitles=['dashboard'=>'Dashboard','products'=>'Products','orders'=>'Orders','customers'=>'Customers','categories'=>'Categories','ticker'=>'Ticker Bar','advertisements'=>'Ads & Banners','reports'=>'Reports','settings'=>'Settings']; ?>
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
                ['Active Ads',      $active_ads,                       $inactive_ads.' inactive',            true,'fa-rectangle-ad','#8b5cf6'],
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

        <!-- ══ TICKER BAR WIDGET ══ -->
        <style>
            @keyframes tickerDash { from{transform:translateX(0)} to{transform:translateX(-50%)} }
            .db-ticker-wrap { background:var(--card); border-radius:var(--r-xl); box-shadow:var(--shadow); overflow:hidden; margin-bottom:13px; animation:fadeUp .4s .05s ease both; }
            .db-ticker-hdr  { padding:.9rem 1.25rem; border-bottom:1px solid rgba(10,10,15,.06); display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; }
            .db-ticker-body { padding:1rem 1.25rem; }
            .db-ticker-preview { border-radius:var(--r-md); height:38px; display:flex; align-items:center; overflow:hidden; margin-bottom:1rem; position:relative; }
            .db-ticker-preview-label { padding:0 14px; font-size:.72rem; font-weight:700; color:rgba(255,255,255,.85); white-space:nowrap; flex-shrink:0; display:flex; align-items:center; gap:6px; border-right:1px solid rgba(255,255,255,.15); height:100%; }
            .db-ticker-preview-scroll { flex:1; overflow:hidden; position:relative; mask-image:linear-gradient(to right,transparent 0%,#000 4%,#000 96%,transparent 100%); -webkit-mask-image:linear-gradient(to right,transparent 0%,#000 4%,#000 96%,transparent 100%); }
            .db-ticker-preview-inner  { display:flex; gap:28px; white-space:nowrap; }
            .db-ticker-preview-inner.running { animation:tickerDash <?= $ticker_speed ?>s linear infinite; }
            .db-ticker-preview-inner:hover   { animation-play-state:paused; }
            .db-msg-list { display:flex; flex-direction:column; gap:6px; margin-top:.4rem; max-height:220px; overflow-y:auto; }
            .db-msg-row  { display:flex; align-items:center; gap:9px; padding:7px 10px; background:var(--surface); border-radius:var(--r-md); border:1px solid rgba(10,10,15,.05); transition:border-color .15s; }
            .db-msg-row:hover { border-color:rgba(12,177,0,.2); }
            .db-msg-row.inactive { opacity:.5; }
            .db-msg-emoji { font-size:1rem; flex-shrink:0; width:22px; text-align:center; }
            .db-msg-text  { flex:1; font-size:.82rem; font-weight:600; color:var(--ink); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; min-width:0; }
            .db-msg-link  { font-size:.68rem; color:var(--accent); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:110px; }
            .db-msg-acts  { display:flex; gap:3px; flex-shrink:0; }
        </style>

        <div class="db-ticker-wrap">
            <div class="db-ticker-hdr">
                <div style="display:flex;align-items:center;gap:10px">
                    <div style="width:34px;height:34px;border-radius:var(--r-md);background:<?= $ticker_color ?>22;display:flex;align-items:center;justify-content:center;color:<?= $ticker_color ?>"><i class="fas fa-bullhorn"></i></div>
                    <div>
                        <div style="font-size:.92rem;font-weight:800;color:var(--ink);display:flex;align-items:center;gap:8px">
                            Ticker Bar
                            <!-- Global toggle pill — clicking submits the form -->
                            <form method="POST" style="display:inline;margin:0" id="dashTickerToggleForm">
                                <input type="hidden" name="_action"    value="toggle_ticker_global">
                                <input type="hidden" name="_back_page" value="dashboard">
                                <button type="submit"
                                    title="<?= $ticker_enabled ? 'Click to disable ticker' : 'Click to enable ticker' ?>"
                                    style="all:unset;cursor:pointer;display:inline-flex;align-items:center;gap:5px;font-size:.68rem;font-weight:700;padding:3px 10px;border-radius:100px;background:<?= $ticker_enabled ? '#dcfce7' : '#fee2e2' ?>;color:<?= $ticker_enabled ? '#15803d' : '#dc2626' ?>;transition:all .2s;border:1.5px solid <?= $ticker_enabled ? '#bbf7d0' : '#fca5a5' ?>">
                                    <span style="width:7px;height:7px;border-radius:50%;background:currentColor;display:inline-block"></span>
                                    <?= $ticker_enabled ? 'Live' : 'Off' ?>
                                    <i class="fas fa-<?= $ticker_enabled ? 'pause' : 'play' ?>" style="font-size:.55rem"></i>
                                </button>
                            </form>
                        </div>
                        <div style="font-size:.73rem;color:var(--ink-muted);margin-top:1px">
                            <?= $ticker_active_count ?> of <?= count($db_ticker_items) ?> messages active
                        </div>
                    </div>
                </div>
                <div style="display:flex;gap:7px;align-items:center">
                    <button class="btn-p btn-sm" onclick="openModal('addTickerModal')" style="gap:5px">
                        <i class="fas fa-plus"></i> Add Message
                    </button>
                    <a href="?page=ticker" class="btn-o btn-sm"><i class="fas fa-sliders"></i> All Settings</a>
                </div>
            </div>

            <div class="db-ticker-body">

                <!-- Live preview strip -->
                <div class="db-ticker-preview" id="dashTickerBar"
                     style="background:<?= htmlspecialchars($ticker_color) ?>;<?= !$ticker_enabled ? 'opacity:.38;filter:grayscale(.8)' : '' ?>">
                    <div class="db-ticker-preview-label">
                        <i class="fas fa-gift" style="font-size:.75rem"></i>
                        Limited Offers
                    </div>
                    <div class="db-ticker-preview-scroll">
                        <?php $active_t = array_values(array_filter($db_ticker_items, fn($t) => $t['is_active'])); ?>
                        <?php if ($active_t): ?>
                        <div class="db-ticker-preview-inner running">
                            <?php foreach (array_merge($active_t, $active_t) as $ti): ?>
                            <span style="font-size:.79rem;font-weight:600;color:#fff;flex-shrink:0;padding:0 14px">
                                <?= htmlspecialchars($ti['emoji']) ?>
                                <?= htmlspecialchars($ti['message']) ?>
                                <?php if ($ti['link_url'] && $ti['link_text']): ?>
                                — <span style="text-decoration:underline;color:rgba(255,255,255,.8)"><?= htmlspecialchars($ti['link_text']) ?></span>
                                <?php endif; ?>
                                <span style="opacity:.3;margin-left:10px">◆</span>
                            </span>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <span style="font-size:.78rem;color:rgba(255,255,255,.45);padding-left:16px;font-style:italic">
                            No active messages — toggle some on below
                        </span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Message rows with inline toggle + edit -->
                <?php if ($db_ticker_items): ?>
                <div class="db-msg-list">
                    <?php foreach ($db_ticker_items as $ti):
                        $t_edit = ['id'=>$ti['id'],'message'=>$ti['message'],'link_url'=>$ti['link_url'],
                                   'link_text'=>$ti['link_text'],'emoji'=>$ti['emoji'],
                                   'is_active'=>$ti['is_active'],'sort_order'=>$ti['sort_order']];
                    ?>
                    <div class="db-msg-row <?= $ti['is_active'] ? '' : 'inactive' ?>" id="dbmsg-<?= $ti['id'] ?>">
                        <div class="db-msg-emoji"><?= htmlspecialchars($ti['emoji']) ?: '📢' ?></div>
                        <div class="db-msg-text" title="<?= htmlspecialchars($ti['message']) ?>">
                            <?= htmlspecialchars($ti['message']) ?>
                        </div>
                        <?php if ($ti['link_url']): ?>
                        <div class="db-msg-link" title="<?= htmlspecialchars($ti['link_url']) ?>">
                            <i class="fas fa-link" style="font-size:.6rem;margin-right:3px"></i><?= htmlspecialchars($ti['link_text'] ?: $ti['link_url']) ?>
                        </div>
                        <?php endif; ?>
                        <div class="db-msg-acts">
                            <!-- Toggle on/off -->
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="_action"   value="toggle_ticker_item">
                                <input type="hidden" name="ticker_id" value="<?= $ti['id'] ?>">
                                <button type="submit"
                                        class="ab <?= $ti['is_active'] ? 'warn' : '' ?>"
                                        title="<?= $ti['is_active'] ? 'Deactivate' : 'Activate' ?>"
                                        style="<?= $ti['is_active'] ? '' : 'border-color:#16a34a;color:#16a34a;background:#dcfce7' ?>">
                                    <i class="fas fa-<?= $ti['is_active'] ? 'eye-slash' : 'eye' ?>"></i>
                                </button>
                            </form>
                            <!-- Edit -->
                            <button class="ab edit" title="Edit message"
                                    onclick="openEditTicker(<?= htmlspecialchars(json_encode($t_edit)) ?>)">
                                <i class="fas fa-pen"></i>
                            </button>
                            <!-- Delete -->
                            <form method="POST" style="display:inline"
                                  onsubmit="return confirm('Delete this ticker message?')">
                                <input type="hidden" name="_action"   value="delete_ticker">
                                <input type="hidden" name="ticker_id" value="<?= $ti['id'] ?>">
                                <button type="submit" class="ab del" title="Delete">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div style="text-align:center;padding:1.4rem;color:var(--ink-muted);font-size:.82rem">
                    <i class="fas fa-bullhorn" style="font-size:1.5rem;margin-bottom:.4rem;display:block;opacity:.18"></i>
                    No ticker messages yet. Click <strong>Add Message</strong> to create your first one.
                </div>
                <?php endif; ?>

            </div>
        </div>

        <!-- Active Ads Quick View on Dashboard -->
        <?php if ($db_ads): ?>
        <div class="cc" style="margin-bottom:13px">
            <h3>Active Ads Overview</h3>
            <p><?= $active_ads ?> running · <a href="?page=advertisements" style="color:var(--accent)">Manage all →</a></p>
            <div style="display:flex;gap:9px;flex-wrap:wrap;margin-top:.4rem">
                <?php foreach (array_filter($db_ads, fn($a)=>$a['is_active']) as $ad):
                    $pi = $ad_positions[$ad['position']] ?? ['color'=>'#6b7280','label'=>ucfirst($ad['position'])]; ?>
                <div style="display:flex;align-items:center;gap:8px;background:var(--surface);border-radius:var(--r-md);padding:7px 11px;flex:1;min-width:200px">
                    <?php if ($ad['image']): ?>
                    <img src="../<?= htmlspecialchars($ad['image']) ?>" style="width:38px;height:28px;object-fit:cover;border-radius:5px" onerror="this.style.display='none'">
                    <?php else: ?>
                    <div style="width:38px;height:28px;background:<?= $pi['color'] ?>22;border-radius:5px;display:flex;align-items:center;justify-content:center;color:<?= $pi['color'] ?>;font-size:.75rem"><i class="fas fa-image"></i></div>
                    <?php endif; ?>
                    <div style="min-width:0">
                        <div style="font-size:.78rem;font-weight:700;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($ad['title']) ?></div>
                        <div style="font-size:.65rem;color:<?= $pi['color'] ?>;font-weight:700"><?= $pi['label'] ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

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
                        <span class="sb <?= $is_orphan?'sw':'si' ?>" style="font-size:.67rem">
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
                            <input type="number" name="stock_count" value="<?= $stk ?>" min="0" class="se-num">
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
                            <form method="POST" style="display:inline" onsubmit="return confirm('Delete product #<?= $p['id'] ?>?\nThis also removes its specs.')">
                                <input type="hidden" name="_action" value="delete_product">
                                <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                                <button type="submit" class="ab del" title="Delete"><i class="fas fa-trash"></i></button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="10" style="text-align:center;padding:3rem;color:var(--ink-muted)">No products found.</td></tr>
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
                <form method="POST" style="display:inline" onsubmit="return confirm('Auto-import <?= count($orphan_cats) ?> orphan category slug(s)?')">
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
        </div>
        <?php endif; ?>

        <?php if (count($db_categories)===0): ?>
        <div class="db-warn"><i class="fas fa-layer-group"></i> No categories yet. Add your first category to get started.</div>
        <?php endif; ?>

        <div class="cg">
            <?php foreach ($db_categories as $i=>$cat):
                $sl = $cat['slug'] ?: slugify($cat['name']);
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

        <div class="tc" style="margin-top:1.35rem">
            <div class="tc-hdr">
                <div><h3>All Categories</h3><p>Slug maps to <code style="background:var(--surface);padding:1px 5px;border-radius:4px">products.category</code></p></div>
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
                                <button class="ab edit" onclick="openEditCat(<?= htmlspecialchars(json_encode([
                                    'id'=>$cat['id'],'name'=>$cat['name'],'slug'=>$sl,
                                    'desc'=>$cat['description']??'','icon'=>$cat['icon']??'fa-tag'
                                ])) ?>)"><i class="fas fa-pen"></i></button>
                                <button class="ab del" onclick="openDeleteCat(<?= htmlspecialchars(json_encode([
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


        <?php /* ─────────── TICKER BAR ─────────── */ ?>
        <?php elseif ($active==='ticker'): ?>

        <style>
        .ticker-card { background:var(--card); border-radius:var(--r-lg); box-shadow:var(--shadow); overflow:hidden; animation:fadeUp .35s ease both; transition:box-shadow .18s, transform .18s; }
        .ticker-card:hover { box-shadow:0 5px 24px rgba(10,10,15,.12); }
        .ticker-card.inactive { opacity:.58; }
        .ticker-card-body { padding:.85rem 1rem; display:flex; align-items:center; gap:11px; }
        .ticker-emoji { width:38px; height:38px; border-radius:var(--r-md); background:var(--accent-dim); display:flex; align-items:center; justify-content:center; font-size:1.2rem; flex-shrink:0; }
        .ticker-msg { flex:1; min-width:0; }
        .ticker-msg strong { font-size:.86rem; font-weight:700; color:var(--ink); display:block; }
        .ticker-msg span   { font-size:.72rem; color:var(--ink-muted); }
        .ticker-cards-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(320px,1fr)); gap:11px; margin-bottom:1.4rem; }
        .preview-ticker-bar { border-radius:var(--r-md); height:36px; display:flex; align-items:center; overflow:hidden; margin-bottom:1rem; }
        .preview-ticker-label { padding:0 14px; font-size:.72rem; font-weight:700; color:rgba(255,255,255,.8); white-space:nowrap; flex-shrink:0; }
        .preview-ticker-scroll { flex:1; overflow:hidden; position:relative; }
        .preview-ticker-inner { display:flex; gap:28px; white-space:nowrap; animation:tickerPrv 25s linear infinite; }
        .preview-ticker-inner:hover { animation-play-state:paused; }
        @keyframes tickerPrv { from{transform:translateX(0)} to{transform:translateX(-50%)} }
        .preview-ticker-item { font-size:.79rem; font-weight:600; color:#fff; flex-shrink:0; }
        .color-swatch { width:32px; height:32px; border-radius:var(--r-sm); border:2px solid rgba(10,10,15,.1); cursor:pointer; flex-shrink:0; }
        .quick-emoji { font-size:1.2rem; cursor:pointer; padding:3px 6px; border-radius:6px; transition:background .12s; display:inline-block; }
        .quick-emoji:hover { background:var(--surface); }
        </style>

        <div class="pg-hdr">
            <div class="pg-hdr-l">
                <h2>Ticker Bar</h2>
                <p><?= count($db_ticker_items) ?> messages · <?= $ticker_active_count ?> active · shown below the navbar on all pages</p>
            </div>
            <div class="pg-hdr-r">
                <!-- Global on/off -->
                <form method="POST" style="display:inline">
                    <input type="hidden" name="_action" value="toggle_ticker_global">
                    <input type="hidden" name="_back_page" value="ticker">
                    <button type="submit" class="btn-o btn-sm" style="<?= $ticker_enabled ? 'border-color:#ef4444;color:#ef4444' : 'border-color:#16a34a;color:#16a34a' ?>">
                        <i class="fas fa-<?= $ticker_enabled ? 'pause' : 'play' ?>"></i>
                        <?= $ticker_enabled ? 'Disable Ticker' : 'Enable Ticker' ?>
                    </button>
                </form>
                <button class="btn-p" onclick="openModal('addTickerModal')"><i class="fas fa-plus"></i> Add Message</button>
            </div>
        </div>

        <!-- Stats row -->
        <div class="ad-stats" style="margin-bottom:1.3rem">
            <?php $tss = [
                ['Total Messages', count($db_ticker_items),   'fa-list',         '#0cb100'],
                ['Active',         $ticker_active_count,      'fa-circle-check', '#3b82f6'],
                ['Inactive',       count($db_ticker_items) - $ticker_active_count, 'fa-circle-pause','#f59e0b'],
                ['Global Status',  $ticker_enabled ? 'ON' : 'OFF', 'fa-bullhorn', $ticker_enabled ? '#0cb100' : '#ef4444'],
            ];
            foreach ($tss as $s): ?>
            <div class="ad-stat">
                <div class="ad-stat-ico" style="background:<?= $s[3] ?>18;color:<?= $s[3] ?>"><i class="fas <?= $s[2] ?>"></i></div>
                <div>
                    <div class="ad-stat-l"><?= $s[0] ?></div>
                    <div class="ad-stat-v" style="color:<?= $s[3] ?>"><?= $s[1] ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Live preview -->
        <div class="cc" style="margin-bottom:1.3rem">
            <h3>Live Preview</h3>
            <p>Hover to pause scroll · this mirrors exactly what visitors see</p>
            <div class="preview-ticker-bar" id="livePreviewBar" style="background:<?= htmlspecialchars($ticker_color) ?>;<?= !$ticker_enabled ? 'opacity:.4;filter:grayscale(1)' : '' ?>">
                <div class="preview-ticker-label"><i class="fas fa-gift" style="margin-right:5px"></i> Limited Offers</div>
                <div class="preview-ticker-scroll">
                    <?php $active_items = array_values(array_filter($db_ticker_items, fn($t) => $t['is_active'])); ?>
                    <?php if ($active_items): ?>
                    <div class="preview-ticker-inner" style="animation-duration:<?= $ticker_speed ?>s">
                        <?php foreach (array_merge($active_items, $active_items) as $ti): ?>
                        <span class="preview-ticker-item">
                            <?= htmlspecialchars($ti['emoji']) ?>
                            <?= htmlspecialchars($ti['message']) ?>
                            <?php if ($ti['link_url'] && $ti['link_text']): ?>
                            — <span style="text-decoration:underline;color:rgba(255,255,255,.85)"><?= htmlspecialchars($ti['link_text']) ?></span>
                            <?php endif; ?>
                            <span style="opacity:.35;margin-left:10px">•</span>
                        </span>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <span style="font-size:.79rem;color:rgba(255,255,255,.45);padding-left:12px;font-style:italic">No active messages — enable some items below</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php if (!$ticker_enabled): ?>
            <div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:var(--r-md);padding:.6rem 1rem;font-size:.82rem;color:#dc2626;font-weight:600;margin-top:.5rem">
                <i class="fas fa-triangle-exclamation"></i> Ticker is currently <strong>disabled globally</strong> — visitors cannot see it. Click "Enable Ticker" to show it.
            </div>
            <?php endif; ?>
        </div>

        <!-- Settings card -->
        <div class="sc" style="margin-bottom:1.3rem">
            <div class="sc-hdr"><i class="fas fa-sliders"></i> Ticker Settings</div>
            <form method="POST" action="?page=ticker">
                <input type="hidden" name="_action" value="save_ticker_settings">
                <div class="sc-row">
                    <div class="sc-i">
                        <strong>Global On/Off</strong>
                        <span>Show or hide the ticker bar site-wide</span>
                    </div>
                    <label class="toggle">
                        <input type="checkbox" name="ticker_enabled" <?= $ticker_enabled ? 'checked' : '' ?>>
                        <span class="tgl-sl"></span>
                    </label>
                </div>
                <div class="sc-row">
                    <div class="sc-i">
                        <strong>Scroll Speed</strong>
                        <span>Animation duration in seconds — lower = faster</span>
                    </div>
                    <div style="display:flex;align-items:center;gap:9px">
                        <input type="range" name="ticker_speed" id="tickerSpeedRange"
                               min="10" max="120" value="<?= $ticker_speed ?>"
                               style="width:130px;accent-color:var(--accent)"
                               oninput="document.getElementById('tickerSpeedVal').textContent=this.value+'s'">
                        <span id="tickerSpeedVal" style="font-size:.82rem;font-weight:700;color:var(--ink);min-width:32px"><?= $ticker_speed ?>s</span>
                    </div>
                </div>
                <div class="sc-row">
                    <div class="sc-i">
                        <strong>Bar Colour</strong>
                        <span>Background colour of the ticker strip</span>
                    </div>
                    <div style="display:flex;align-items:center;gap:9px">
                        <input type="color" name="ticker_color" id="tickerColorPicker"
                               value="<?= htmlspecialchars($ticker_color) ?>"
                               style="width:44px;height:34px;padding:2px;border-radius:6px;border:1.5px solid rgba(10,10,15,.1);cursor:pointer"
                               oninput="updateTickerPreviewColor(this.value);document.getElementById('tickerColorText').value=this.value">
                        <input type="text" id="tickerColorText" class="f-input" value="<?= htmlspecialchars($ticker_color) ?>"
                               style="width:105px"
                               oninput="if(/^#[0-9a-f]{6}$/i.test(this.value)){document.getElementById('tickerColorPicker').value=this.value;updateTickerPreviewColor(this.value)}">
                        <!-- Quick colour swatches -->
                        <div style="display:flex;gap:5px;flex-wrap:wrap">
                            <?php foreach (['#3b5bdb','#0cb100','#e63946','#f59e0b','#8b5cf6','#0891b2','#0d0d14','#be185d'] as $sw): ?>
                            <div class="color-swatch" style="background:<?= $sw ?>" title="<?= $sw ?>"
                                 onclick="document.getElementById('tickerColorPicker').value='<?= $sw ?>';document.getElementById('tickerColorText').value='<?= $sw ?>';updateTickerPreviewColor('<?= $sw ?>')"></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="sc-row" style="border-bottom:none;padding-top:.7rem">
                    <div></div>
                    <button type="submit" class="btn-p btn-sm"><i class="fas fa-floppy-disk"></i> Save Settings</button>
                </div>
            </form>
        </div>

        <!-- Ticker messages grid -->
        <div class="pg-hdr" style="margin-bottom:.8rem">
            <div class="pg-hdr-l"><h3 style="font-size:1rem;font-weight:800">Ticker Messages</h3><p style="font-size:.79rem;color:var(--ink-muted)">Drag to reorder (sort by changing the order number) · toggle to show/hide individual messages</p></div>
            <button class="btn-p btn-sm" onclick="openModal('addTickerModal')"><i class="fas fa-plus"></i> Add Message</button>
        </div>

        <?php if (empty($db_ticker_items)): ?>
        <div style="background:var(--card);border-radius:var(--r-lg);border:2px dashed rgba(10,10,15,.1);padding:2.5rem;text-align:center;color:var(--ink-muted)">
            <i class="fas fa-bullhorn" style="font-size:2rem;margin-bottom:.6rem;display:block;opacity:.2"></i>
            No ticker messages yet. Click <strong>Add Message</strong> to create your first one.
        </div>
        <?php else: ?>
        <div class="ticker-cards-grid">
            <?php foreach ($db_ticker_items as $i => $ti):
                $t_edit = ['id'=>$ti['id'],'message'=>$ti['message'],'link_url'=>$ti['link_url'],
                           'link_text'=>$ti['link_text'],'emoji'=>$ti['emoji'],
                           'is_active'=>$ti['is_active'],'sort_order'=>$ti['sort_order']];
            ?>
            <div class="ticker-card <?= $ti['is_active'] ? '' : 'inactive' ?>" style="animation-delay:<?= $i*35 ?>ms">
                <div class="ticker-card-body">
                    <div class="ticker-emoji"><?= htmlspecialchars($ti['emoji']) ?: '<i class="fas fa-bullhorn" style="color:var(--accent);font-size:.9rem"></i>' ?></div>
                    <div class="ticker-msg">
                        <strong><?= htmlspecialchars($ti['message']) ?></strong>
                        <?php if ($ti['link_url']): ?>
                        <span><i class="fas fa-link" style="font-size:.6rem;margin-right:3px"></i>
                            <a href="<?= htmlspecialchars($ti['link_url']) ?>" target="_blank" style="color:var(--accent)">
                                <?= htmlspecialchars($ti['link_text'] ?: $ti['link_url']) ?>
                            </a>
                        </span>
                        <?php endif; ?>
                    </div>
                    <div style="display:flex;flex-direction:column;align-items:flex-end;gap:5px;flex-shrink:0">
                        <span class="sb <?= $ti['is_active'] ? 'sa' : 'so' ?>" style="font-size:.63rem"><?= $ti['is_active'] ? 'Active' : 'Inactive' ?></span>
                        <span style="font-size:.65rem;color:var(--ink-muted);font-weight:700">Order #<?= $ti['sort_order'] ?></span>
                    </div>
                </div>
                <div style="padding:.55rem 1rem;border-top:1px solid rgba(10,10,15,.06);display:flex;align-items:center;justify-content:space-between">
                    <span style="font-size:.68rem;color:var(--ink-muted)">
                        <i class="fas fa-calendar" style="margin-right:3px"></i><?= date('d M Y', strtotime($ti['created_at'])) ?>
                    </span>
                    <div style="display:flex;gap:4px">
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="_action" value="toggle_ticker_item">
                            <input type="hidden" name="ticker_id" value="<?= $ti['id'] ?>">
                            <button type="submit" class="ab <?= $ti['is_active'] ? 'warn' : '' ?>" title="<?= $ti['is_active'] ? 'Deactivate' : 'Activate' ?>">
                                <i class="fas fa-<?= $ti['is_active'] ? 'eye-slash' : 'eye' ?>"></i>
                            </button>
                        </form>
                        <button class="ab edit" title="Edit" onclick="openEditTicker(<?= htmlspecialchars(json_encode($t_edit)) ?>)">
                            <i class="fas fa-pen"></i>
                        </button>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Delete this ticker message?')">
                            <input type="hidden" name="_action" value="delete_ticker">
                            <input type="hidden" name="ticker_id" value="<?= $ti['id'] ?>">
                            <button type="submit" class="ab del" title="Delete"><i class="fas fa-trash"></i></button>
                        </form>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- All messages table -->
        <div class="tc">
            <div class="tc-hdr">
                <div><h3>All Messages</h3><p>Full list with inline controls</p></div>
                <input type="text" class="tbl-s" placeholder="Search…" id="tickerSearch" style="width:185px">
            </div>
            <div class="tbl-wrap">
            <table id="tickerTable">
                <thead><tr><th>ID</th><th>Emoji</th><th>Message</th><th>Link</th><th>Order</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php if ($db_ticker_items): foreach ($db_ticker_items as $ti):
                        $t_edit = ['id'=>$ti['id'],'message'=>$ti['message'],'link_url'=>$ti['link_url'],
                                   'link_text'=>$ti['link_text'],'emoji'=>$ti['emoji'],
                                   'is_active'=>$ti['is_active'],'sort_order'=>$ti['sort_order']];
                    ?>
                    <tr>
                        <td class="muted"><?= $ti['id'] ?></td>
                        <td style="font-size:1.2rem"><?= htmlspecialchars($ti['emoji']) ?: '—' ?></td>
                        <td><strong><?= htmlspecialchars($ti['message']) ?></strong></td>
                        <td class="muted">
                            <?php if ($ti['link_url']): ?>
                            <a href="<?= htmlspecialchars($ti['link_url']) ?>" target="_blank" style="color:var(--accent);font-size:.77rem">
                                <?= htmlspecialchars($ti['link_text'] ?: substr($ti['link_url'],0,25)) ?>
                            </a>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td class="muted"><?= $ti['sort_order'] ?></td>
                        <td>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="_action" value="toggle_ticker_item">
                                <input type="hidden" name="ticker_id" value="<?= $ti['id'] ?>">
                                <button type="submit" class="sb <?= $ti['is_active'] ? 'sa' : 'so' ?>"
                                        style="border:none;cursor:pointer;font-family:inherit;font-size:.68rem;font-weight:700">
                                    <?= $ti['is_active'] ? '● Active' : '● Inactive' ?>
                                </button>
                            </form>
                        </td>
                        <td>
                            <div style="display:flex;gap:4px">
                                <button class="ab edit" onclick="openEditTicker(<?= htmlspecialchars(json_encode($t_edit)) ?>)"><i class="fas fa-pen"></i></button>
                                <form method="POST" style="display:inline" onsubmit="return confirm('Delete?')">
                                    <input type="hidden" name="_action" value="delete_ticker">
                                    <input type="hidden" name="ticker_id" value="<?= $ti['id'] ?>">
                                    <button type="submit" class="ab del"><i class="fas fa-trash"></i></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--ink-muted)">No ticker items yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>


        <?php /* ─────────── ADVERTISEMENTS ─────────── */ ?>
        <?php elseif ($active==='advertisements'): ?>

        <div class="pg-hdr">
            <div class="pg-hdr-l">
                <h2>Ads & Banners</h2>
                <p><?= count($db_ads) ?> total · <?= $active_ads ?> active · <?= $inactive_ads ?> inactive · displayed on homepage</p>
            </div>
            <div class="pg-hdr-r">
                <button class="btn-o btn-sm" onclick="exportTable('adTable')"><i class="fas fa-download"></i> Export CSV</button>
                <button class="btn-p" onclick="openModal('addAdModal')"><i class="fas fa-plus"></i> New Ad</button>
            </div>
        </div>

        <!-- Ad Stats -->
        <div class="ad-stats">
            <?php
            $adstats = [
                ['Total Ads',    count($db_ads),    'fa-rectangle-ad',  '#0cb100'],
                ['Active',       $active_ads,       'fa-circle-check',  '#3b82f6'],
                ['Inactive',     $inactive_ads,     'fa-circle-pause',  '#f59e0b'],
                ['Hero Banners', count($ads_by_position['hero'] ?? []), 'fa-panorama', '#8b5cf6'],
            ];
            foreach ($adstats as $s): ?>
            <div class="ad-stat">
                <div class="ad-stat-ico" style="background:<?= $s[3] ?>18;color:<?= $s[3] ?>"><i class="fas <?= $s[2] ?>"></i></div>
                <div>
                    <div class="ad-stat-l"><?= $s[0] ?></div>
                    <div class="ad-stat-v"><?= $s[1] ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Ads grouped by position -->
        <?php foreach ($ad_positions as $pos_key => $pos):
            $pos_ads = $ads_by_position[$pos_key] ?? []; ?>
        <div class="pos-section">
            <div class="pos-hdr">
                <div class="pos-hdr-ico" style="background:<?= $pos['color'] ?>18;color:<?= $pos['color'] ?>">
                    <i class="fas <?= $pos['icon'] ?>"></i>
                </div>
                <div>
                    <strong><?= $pos['label'] ?></strong>
                    <span style="display:block;margin-top:1px"><?= $pos['desc'] ?></span>
                </div>
                <span class="pos-count" style="background:<?= $pos['color'] ?>18;color:<?= $pos['color'] ?>"><?= count($pos_ads) ?> ad<?= count($pos_ads)!=1?'s':'' ?></span>
                <button class="btn-p btn-sm" style="background:<?= $pos['color'] ?>;box-shadow:0 2px 10px <?= $pos['color'] ?>44;margin-left:6px"
                        onclick="openAddAdForPosition('<?= $pos_key ?>')">
                    <i class="fas fa-plus"></i> Add
                </button>
            </div>

            <?php if (empty($pos_ads)): ?>
            <div style="background:var(--card);border-radius:var(--r-md);border:2px dashed rgba(10,10,15,.1);padding:1.5rem;text-align:center;color:var(--ink-muted);font-size:.82rem">
                <i class="fas <?= $pos['icon'] ?>" style="font-size:1.5rem;margin-bottom:.4rem;display:block;opacity:.2"></i>
                No ads for this position yet. Click <strong>Add</strong> to create one.
            </div>
            <?php else: ?>
            <div class="ad-cards">
                <?php foreach ($pos_ads as $i => $ad):
                    $ad_is_active = (bool)$ad['is_active'];
                    $pi = $ad_positions[$ad['position']] ?? ['color' => '#6b7280', 'label' => ucfirst($ad['position'])];
                    $edit_data = [
                        'id' => $ad['id'], 'title' => $ad['title'], 'subtitle' => $ad['subtitle'],
                        'image' => $ad['image'], 'link_url' => $ad['link_url'], 'position' => $ad['position'],
                        'badge_text' => $ad['badge_text'], 'btn_text' => $ad['btn_text'],
                        'btn_color' => $ad['btn_color'], 'is_active' => $ad['is_active'], 'sort_order' => $ad['sort_order'],
                    ];
                ?>
                <div class="ad-card <?= $ad_is_active ? '' : 'inactive' ?>" style="animation-delay:<?= $i*40 ?>ms">
                    <div class="ad-img-wrap">
                        <?php if ($ad['image']): ?>
                        <img src="../<?= htmlspecialchars($ad['image']) ?>" alt="" onerror="this.parentNode.innerHTML='<div class=ad-img-ph><i class=fas\ fa-image></i></div>'">
                        <?php else: ?>
                        <div class="ad-img-ph"><i class="fas fa-image"></i></div>
                        <?php endif; ?>
                        <span class="ad-status-pill" style="background:<?= $ad_is_active ? '#dcfce7' : '#fee2e2' ?>;color:<?= $ad_is_active ? '#15803d' : '#dc2626' ?>">
                            <i class="fas fa-circle" style="font-size:.45rem;vertical-align:middle"></i>
                            <?= $ad_is_active ? 'Active' : 'Inactive' ?>
                        </span>
                        <span class="ad-pos-pill"><?= htmlspecialchars($pi['label']) ?></span>
                        <?php if ($ad['sort_order']): ?><span class="ad-sort-pill">Order #<?= $ad['sort_order'] ?></span><?php endif; ?>
                    </div>
                    <div class="ad-body">
                        <strong title="<?= htmlspecialchars($ad['title']) ?>"><?= htmlspecialchars($ad['title']) ?></strong>
                        <div class="sub"><?= htmlspecialchars($ad['subtitle'] ?: '—') ?></div>
                        <div class="ad-meta">
                            <?php if ($ad['badge_text']): ?>
                            <span class="ad-meta-tag"><i class="fas fa-tag"></i><?= htmlspecialchars($ad['badge_text']) ?></span>
                            <?php endif; ?>
                            <?php if ($ad['btn_text']): ?>
                            <span class="ad-meta-tag" style="background:<?= htmlspecialchars($ad['btn_color']) ?>22;color:<?= htmlspecialchars($ad['btn_color']) ?>">
                                <i class="fas fa-arrow-pointer"></i><?= htmlspecialchars($ad['btn_text']) ?>
                            </span>
                            <?php endif; ?>
                            <?php if ($ad['link_url']): ?>
                            <span class="ad-meta-tag"><i class="fas fa-link"></i><?= htmlspecialchars(substr($ad['link_url'],0,20)) ?><?= strlen($ad['link_url'])>20?'…':'' ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="ad-foot">
                        <span style="font-size:.7rem;color:var(--ink-muted)">
                            <i class="fas fa-calendar" style="margin-right:3px"></i>
                            <?= date('d M Y', strtotime($ad['created_at'])) ?>
                        </span>
                        <div class="ad-actions">
                            <button class="ab" title="Preview" onclick="openAdPreview(<?= htmlspecialchars(json_encode($edit_data)) ?>)"><i class="fas fa-eye"></i></button>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="_action" value="toggle_ad">
                                <input type="hidden" name="ad_id" value="<?= $ad['id'] ?>">
                                <button type="submit" class="ab <?= $ad_is_active ? 'warn' : '' ?>" title="<?= $ad_is_active ? 'Deactivate' : 'Activate' ?>">
                                    <i class="fas fa-<?= $ad_is_active ? 'pause' : 'play' ?>"></i>
                                </button>
                            </form>
                            <button class="ab edit" title="Edit" onclick="openEditAd(<?= htmlspecialchars(json_encode($edit_data)) ?>)">
                                <i class="fas fa-pen"></i>
                            </button>
                            <form method="POST" style="display:inline" onsubmit="return confirm('Delete ad #<?= $ad['id'] ?>?')">
                                <input type="hidden" name="_action" value="delete_ad">
                                <input type="hidden" name="ad_id" value="<?= $ad['id'] ?>">
                                <button type="submit" class="ab del" title="Delete"><i class="fas fa-trash"></i></button>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <!-- All Ads Table -->
        <div class="tc" style="margin-top:.5rem">
            <div class="tc-hdr">
                <div><h3>All Advertisements</h3><p>Complete list — toggle, edit, or delete inline</p></div>
                <input type="text" class="tbl-s" placeholder="Search ads…" id="adSearch" style="width:185px">
            </div>
            <div class="tbl-wrap">
            <?php if ($db_ads): ?>
            <table id="adTable">
                <thead><tr><th>ID</th><th>Image</th><th>Title / Subtitle</th><th>Position</th><th>Button</th><th>Link</th><th>Order</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($db_ads as $ad):
                        $pi = $ad_positions[$ad['position']] ?? ['label' => ucfirst($ad['position']), 'color' => '#6b7280'];
                        $edit_data = [
                            'id' => $ad['id'], 'title' => $ad['title'], 'subtitle' => $ad['subtitle'],
                            'image' => $ad['image'], 'link_url' => $ad['link_url'], 'position' => $ad['position'],
                            'badge_text' => $ad['badge_text'], 'btn_text' => $ad['btn_text'],
                            'btn_color' => $ad['btn_color'], 'is_active' => $ad['is_active'], 'sort_order' => $ad['sort_order'],
                        ];
                    ?>
                    <tr>
                        <td class="muted"><?= $ad['id'] ?></td>
                        <td>
                            <?php if ($ad['image']): ?>
                            <img src="../<?= htmlspecialchars($ad['image']) ?>" style="width:44px;height:32px;object-fit:cover;border-radius:5px;background:var(--surface)" onerror="this.style.display='none'">
                            <?php else: ?>
                            <div style="width:44px;height:32px;background:var(--surface);border-radius:5px;display:flex;align-items:center;justify-content:center;color:var(--ink-muted);font-size:.7rem"><i class="fas fa-image"></i></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong><?= htmlspecialchars($ad['title']) ?></strong>
                            <?php if ($ad['subtitle']): ?>
                            <div style="font-size:.72rem;color:var(--ink-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:175px"><?= htmlspecialchars($ad['subtitle']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><span class="sb" style="background:<?= $pi['color'] ?>18;color:<?= $pi['color'] ?>;font-size:.67rem"><?= htmlspecialchars($pi['label']) ?></span></td>
                        <td>
                            <?php if ($ad['btn_text']): ?>
                            <span style="display:inline-flex;align-items:center;gap:4px;font-size:.71rem;font-weight:700;padding:2px 8px;border-radius:100px;background:<?= htmlspecialchars($ad['btn_color']) ?>22;color:<?= htmlspecialchars($ad['btn_color']) ?>"><?= htmlspecialchars($ad['btn_text']) ?></span>
                            <?php else: ?><span class="muted">—</span><?php endif; ?>
                        </td>
                        <td class="muted" style="max-width:130px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                            <?php if ($ad['link_url']): ?>
                            <a href="<?= htmlspecialchars($ad['link_url']) ?>" target="_blank" style="color:var(--accent);font-size:.74rem"><?= htmlspecialchars(substr($ad['link_url'],0,22)) ?><?= strlen($ad['link_url'])>22?'…':'' ?></a>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td class="muted"><?= $ad['sort_order'] ?></td>
                        <td>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="_action" value="toggle_ad">
                                <input type="hidden" name="ad_id" value="<?= $ad['id'] ?>">
                                <button type="submit" class="sb <?= $ad['is_active'] ? 'sa' : 'so' ?>" style="border:none;cursor:pointer;font-family:inherit;font-size:.68rem;font-weight:700" title="Click to toggle">
                                    <?= $ad['is_active'] ? '● Active' : '● Inactive' ?>
                                </button>
                            </form>
                        </td>
                        <td class="muted"><?= date('d M Y', strtotime($ad['created_at'])) ?></td>
                        <td>
                            <div style="display:flex;gap:4px">
                                <button class="ab" title="Preview" onclick="openAdPreview(<?= htmlspecialchars(json_encode($edit_data)) ?>)"><i class="fas fa-eye"></i></button>
                                <button class="ab edit" title="Edit" onclick="openEditAd(<?= htmlspecialchars(json_encode($edit_data)) ?>)"><i class="fas fa-pen"></i></button>
                                <form method="POST" style="display:inline" onsubmit="return confirm('Delete this ad?')">
                                    <input type="hidden" name="_action" value="delete_ad">
                                    <input type="hidden" name="ad_id" value="<?= $ad['id'] ?>">
                                    <button type="submit" class="ab del" title="Delete"><i class="fas fa-trash"></i></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div style="padding:3rem;text-align:center;color:var(--ink-muted)">
                <i class="fas fa-rectangle-ad" style="font-size:2rem;margin-bottom:.6rem;display:block;opacity:.3"></i>
                No ads yet. Click <strong>New Ad</strong> to create your first banner.
            </div>
            <?php endif; ?>
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
                ['Maintenance Mode',          'Take the store offline for visitors',       false],
                ['Show Out-of-Stock Products','Display items with stock_count = 0',         true],
                ['WhatsApp Floating Button',  'Show chat button on public pages',           true],
                ['Customer Reviews',          'Allow buyers to leave product reviews',      false],
                ['Price Range Filter',        'Show min/max filter on products.php',        true],
                ['Low Stock Badge (≤5)',      'Show "Only X left" badge on product cards',  true],
                ['Discount % Badge',          'Show discount badge when orig > price',      true],
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
                <select class="f-input"><option selected>Name A–Z</option><option>Price: Low → High</option><option>Price: High → Low</option><option>Top Rated</option></select>
            </div>
            <div class="sc-row">
                <div class="sc-i"><strong>Low Stock Threshold</strong><span>stock_count ≤ this → badge</span></div>
                <input type="number" class="f-input" value="5" min="1" max="50">
            </div>
        </div>

        <div class="sc">
            <div class="sc-hdr"><i class="fas fa-users-gear"></i> Admin Accounts</div>
            <div class="sc-row">
                <div class="sc-i"><strong>Logged-in Account</strong><span><?= htmlspecialchars($admin_email) ?></span></div>
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
                    <div class="f-hint">0 = Out of Stock · 1–5 = Low Stock · 6+ = Active</div>
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
<!-- EDIT PRODUCT MODAL                                            -->
<!-- ══════════════════════════════════════════════════════════════ -->
<div class="m-overlay" id="editProductModal">
    <div class="modal">
        <div class="m-hdr">
            <h3><i class="fas fa-pen" style="color:var(--accent);margin-right:6px"></i>Edit Product</h3>
            <button class="m-close" onclick="closeModal('editProductModal')"><i class="fas fa-xmark"></i></button>
        </div>
        <form method="POST" enctype="multipart/form-data" action="?page=products">
            <input type="hidden" name="_action"        value="edit_product">
            <input type="hidden" name="product_id"     id="editProdId">
            <input type="hidden" name="existing_image" id="editProdExistingImage">
            <div class="m-body">
                <div class="fg">
                    <label>Product Image</label>
                    <div class="img-prev" id="editImgPrev">
                        <div class="iph" id="editImgPh"><i class="fas fa-image"></i></div>
                        <img id="editImgPrevImg" src="" style="display:none">
                    </div>
                    <div class="f-row">
                        <div>
                            <input type="file" name="image_file" accept="image/*" class="fc" style="padding:5px" onchange="prvFileEdit(this)">
                            <div class="f-hint">Upload to replace current image</div>
                        </div>
                        <div>
                            <input type="text" name="image_url" id="editProdImageUrl" class="fc" placeholder="…or paste image/path URL" oninput="prvUrlEdit(this.value)">
                            <div class="f-hint">Leave blank to keep existing</div>
                        </div>
                    </div>
                </div>
                <div class="f-row">
                    <div class="fg">
                        <label>Product Name <span>*</span></label>
                        <input type="text" name="name" id="editProdName" class="fc" required>
                    </div>
                    <div class="fg">
                        <label>Brand</label>
                        <input type="text" name="brand" id="editProdBrand" class="fc">
                    </div>
                </div>
                <div class="fg">
                    <label>Category <span>*</span></label>
                    <select name="category" id="editProdCategory" class="fc" required>
                        <option value="">— Select category —</option>
                        <?php foreach ($cat_map as $slug => $info): ?>
                        <option value="<?= htmlspecialchars($slug) ?>"><?= htmlspecialchars($info['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="f-row">
                    <div class="fg">
                        <label>Price (LKR) <span>*</span></label>
                        <input type="number" name="price" id="editProdPrice" class="fc" min="0" step="0.01" required>
                    </div>
                    <div class="fg">
                        <label>Original / MRP (LKR)</label>
                        <input type="number" name="original_price" id="editProdOrigPrice" class="fc" min="0" step="0.01">
                    </div>
                </div>
                <div class="fg">
                    <label>Stock Count</label>
                    <input type="number" name="stock_count" id="editProdStock" class="fc" value="0" min="0">
                </div>
                <div class="fg">
                    <label>Specs <small style="font-weight:500;color:var(--ink-muted)">(one per line)</small></label>
                    <textarea name="specs" id="editProdSpecs" class="fc" rows="5"></textarea>
                    <div class="f-hint">Replaces all existing specs on save.</div>
                </div>
            </div>
            <div class="m-foot">
                <button type="button" class="btn-o" onclick="closeModal('editProductModal')">Cancel</button>
                <button type="submit" class="btn-p"><i class="fas fa-floppy-disk"></i> Update Product</button>
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
                        <div class="f-hint">Used as <code>products.category</code> FK</div>
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
                </div>
            </div>
            <div class="m-foot">
                <button type="button" class="btn-o" onclick="closeModal('deleteCatModal')">Cancel</button>
                <button type="submit" class="btn-p btn-red"><i class="fas fa-trash"></i> Delete</button>
            </div>
        </form>
    </div>
</div>


<!-- ══════════════════════════════════════════════════════════════ -->
<!-- ADD TICKER MODAL                                              -->
<!-- ══════════════════════════════════════════════════════════════ -->
<div class="m-overlay" id="addTickerModal">
    <div class="modal" style="max-width:540px">
        <div class="m-hdr">
            <h3><i class="fas fa-bullhorn" style="color:var(--accent);margin-right:6px"></i>Add Ticker Message</h3>
            <button class="m-close" onclick="closeModal('addTickerModal')"><i class="fas fa-xmark"></i></button>
        </div>
        <form method="POST" action="?page=ticker">
            <input type="hidden" name="_action" value="add_ticker">
            <div class="m-body">

                <div class="fg">
                    <label>Emoji <small style="font-weight:500;color:var(--ink-muted)">(optional)</small></label>
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                        <input type="text" name="ticker_emoji" id="addTickerEmoji" class="fc" style="width:80px;font-size:1.2rem;text-align:center" placeholder="🔥" maxlength="4">
                        <div>
                            <?php foreach (['🔥','💻','🖥️','⚡','🛡️','🎧','📦','🎁','💡','🚀','🛒','✅','🆕','🎮','📢'] as $em): ?>
                            <span class="quick-emoji" onclick="document.getElementById('addTickerEmoji').value='<?= $em ?>'"><?= $em ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="f-hint">Click an emoji to use it, or type/paste your own</div>
                </div>

                <div class="fg">
                    <label>Message <span>*</span></label>
                    <textarea name="ticker_message" class="fc" rows="2"
                              placeholder="e.g. Free delivery on orders over LKR 50,000" required></textarea>
                    <div class="f-hint">Keep it concise — under 80 characters works best</div>
                </div>

                <div class="f-row">
                    <div class="fg">
                        <label>Link URL</label>
                        <input type="text" name="ticker_link_url" class="fc" placeholder="products.php?category=laptops">
                        <div class="f-hint">Leave blank for no link</div>
                    </div>
                    <div class="fg">
                        <label>Link Text</label>
                        <input type="text" name="ticker_link_text" class="fc" placeholder="Shop now">
                        <div class="f-hint">Text shown as hyperlink</div>
                    </div>
                </div>

                <div class="f-row">
                    <div class="fg">
                        <label>Sort Order</label>
                        <input type="number" name="ticker_order" class="fc" value="0" min="0">
                        <div class="f-hint">Lower = shown first</div>
                    </div>
                    <div class="fg">
                        <label style="visibility:hidden">Active</label>
                        <div style="display:flex;align-items:center;gap:10px;padding:.7rem 1rem;background:var(--surface);border-radius:var(--r-md)">
                            <label class="toggle" style="margin:0"><input type="checkbox" name="ticker_active" checked><span class="tgl-sl"></span></label>
                            <strong style="font-size:.84rem;color:var(--ink)">Active</strong>
                        </div>
                    </div>
                </div>

            </div>
            <div class="m-foot">
                <button type="button" class="btn-o" onclick="closeModal('addTickerModal')">Cancel</button>
                <button type="submit" class="btn-p"><i class="fas fa-floppy-disk"></i> Add Message</button>
            </div>
        </form>
    </div>
</div>


<!-- ══════════════════════════════════════════════════════════════ -->
<!-- EDIT TICKER MODAL                                             -->
<!-- ══════════════════════════════════════════════════════════════ -->
<div class="m-overlay" id="editTickerModal">
    <div class="modal" style="max-width:540px">
        <div class="m-hdr">
            <h3><i class="fas fa-pen" style="color:var(--accent);margin-right:6px"></i>Edit Ticker Message</h3>
            <button class="m-close" onclick="closeModal('editTickerModal')"><i class="fas fa-xmark"></i></button>
        </div>
        <form method="POST" action="?page=ticker">
            <input type="hidden" name="_action" value="edit_ticker">
            <input type="hidden" name="ticker_id" id="editTickerId">
            <div class="m-body">

                <div class="fg">
                    <label>Emoji</label>
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                        <input type="text" name="ticker_emoji" id="editTickerEmoji" class="fc" style="width:80px;font-size:1.2rem;text-align:center" maxlength="4">
                        <div>
                            <?php foreach (['🔥','💻','🖥️','⚡','🛡️','🎧','📦','🎁','💡','🚀','🛒','✅','🆕','🎮','📢'] as $em): ?>
                            <span class="quick-emoji" onclick="document.getElementById('editTickerEmoji').value='<?= $em ?>'"><?= $em ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="fg">
                    <label>Message <span>*</span></label>
                    <textarea name="ticker_message" id="editTickerMessage" class="fc" rows="2" required></textarea>
                </div>

                <div class="f-row">
                    <div class="fg">
                        <label>Link URL</label>
                        <input type="text" name="ticker_link_url" id="editTickerLinkUrl" class="fc">
                    </div>
                    <div class="fg">
                        <label>Link Text</label>
                        <input type="text" name="ticker_link_text" id="editTickerLinkText" class="fc">
                    </div>
                </div>

                <div class="f-row">
                    <div class="fg">
                        <label>Sort Order</label>
                        <input type="number" name="ticker_order" id="editTickerOrder" class="fc" min="0">
                    </div>
                    <div class="fg">
                        <label style="visibility:hidden">Active</label>
                        <div style="display:flex;align-items:center;gap:10px;padding:.7rem 1rem;background:var(--surface);border-radius:var(--r-md)">
                            <label class="toggle" style="margin:0"><input type="checkbox" name="ticker_active" id="editTickerActive"><span class="tgl-sl"></span></label>
                            <strong style="font-size:.84rem;color:var(--ink)">Active</strong>
                        </div>
                    </div>
                </div>

            </div>
            <div class="m-foot">
                <button type="button" class="btn-o" onclick="closeModal('editTickerModal')">Cancel</button>
                <button type="submit" class="btn-p"><i class="fas fa-floppy-disk"></i> Update Message</button>
            </div>
        </form>
    </div>
</div>


<!-- ══════════════════════════════════════════════════════════════ -->
<!-- ADD AD MODAL                                                  -->
<!-- ══════════════════════════════════════════════════════════════ -->
<div class="m-overlay" id="addAdModal">
    <div class="modal" style="max-width:640px">
        <div class="m-hdr">
            <h3><i class="fas fa-rectangle-ad" style="color:var(--accent);margin-right:6px"></i>New Advertisement</h3>
            <button class="m-close" onclick="closeModal('addAdModal')"><i class="fas fa-xmark"></i></button>
        </div>
        <form method="POST" enctype="multipart/form-data" action="?page=advertisements">
            <input type="hidden" name="_action" value="add_ad">
            <div class="m-body">
                <div class="fg">
                    <label>Banner Image</label>
                    <div class="img-prev" id="addAdImgPrev" style="height:110px">
                        <div class="iph"><i class="fas fa-image"></i></div>
                        <img id="addAdImgPrevImg" src="">
                    </div>
                    <div class="f-row">
                        <div>
                            <input type="file" name="ad_image_file" accept="image/*" class="fc" style="padding:5px"
                                   onchange="adPrvFile(this,'addAdImgPrevImg','addAdImgPrev')">
                            <div class="f-hint">Upload JPG/PNG/WebP (recommended: 1200×500px)</div>
                        </div>
                        <div>
                            <input type="text" name="ad_image_url" class="fc" placeholder="…or paste image path"
                                   oninput="adPrvUrl(this.value,'addAdImgPrevImg','addAdImgPrev')">
                            <div class="f-hint">e.g. uploads/ads/banner.jpg</div>
                        </div>
                    </div>
                </div>
                <div class="f-row">
                    <div class="fg">
                        <label>Title <span>*</span></label>
                        <input type="text" name="ad_title" class="fc" placeholder="e.g. Summer Sale — Up to 40% Off" required>
                    </div>
                    <div class="fg">
                        <label>Subtitle / Tagline</label>
                        <input type="text" name="ad_subtitle" class="fc" placeholder="e.g. On laptops, monitors & more">
                    </div>
                </div>
                <div class="f-row">
                    <div class="fg">
                        <label>Position <span>*</span></label>
                        <select name="ad_position" class="fc" id="addAdPosition">
                            <?php foreach ($ad_positions as $pk => $pv): ?>
                            <option value="<?= $pk ?>"><?= $pv['label'] ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="f-hint" id="addAdPositionDesc">Full-width hero slider at top of homepage</div>
                    </div>
                    <div class="fg">
                        <label>Badge Text</label>
                        <input type="text" name="ad_badge" class="fc" placeholder="e.g. 🔥 Limited Time, NEW, SALE">
                        <div class="f-hint">Small badge shown above the title</div>
                    </div>
                </div>
                <div class="f-row">
                    <div class="fg">
                        <label>Button Text</label>
                        <input type="text" name="ad_btn_text" class="fc" value="Shop Now">
                    </div>
                    <div class="fg">
                        <label>Button Color</label>
                        <div style="display:flex;gap:7px;align-items:center">
                            <input type="color" name="ad_btn_color" id="addAdBtnColorPicker" class="fc" value="#0cb100" style="width:44px;height:36px;padding:3px;cursor:pointer" oninput="syncColorPicker('addAdBtnColorPicker','addAdBtnColorText')">
                            <input type="text" id="addAdBtnColorText" class="fc" value="#0cb100" oninput="syncColorText('addAdBtnColorText','addAdBtnColorPicker')" style="flex:1">
                        </div>
                    </div>
                </div>
                <div class="f-row">
                    <div class="fg">
                        <label>Link URL</label>
                        <input type="text" name="ad_link" class="fc" placeholder="e.g. products.php?category=laptops">
                        <div class="f-hint">Leave blank for non-clickable banners</div>
                    </div>
                    <div class="fg">
                        <label>Sort Order</label>
                        <input type="number" name="ad_order" class="fc" value="0" min="0">
                        <div class="f-hint">Lower = shown first in sliders</div>
                    </div>
                </div>
                <div class="fg">
                    <div style="display:flex;align-items:center;gap:10px;padding:.7rem 1rem;background:var(--surface);border-radius:var(--r-md)">
                        <label class="toggle" style="margin:0"><input type="checkbox" name="ad_active" checked><span class="tgl-sl"></span></label>
                        <div>
                            <strong style="font-size:.84rem;color:var(--ink)">Active</strong>
                            <span style="display:block;font-size:.72rem;color:var(--ink-muted)">Toggle off to save as draft without publishing</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="m-foot">
                <button type="button" class="btn-o" onclick="closeModal('addAdModal')">Cancel</button>
                <button type="submit" class="btn-p"><i class="fas fa-floppy-disk"></i> Create Ad</button>
            </div>
        </form>
    </div>
</div>


<!-- ══════════════════════════════════════════════════════════════ -->
<!-- EDIT AD MODAL                                                 -->
<!-- ══════════════════════════════════════════════════════════════ -->
<div class="m-overlay" id="editAdModal">
    <div class="modal" style="max-width:640px">
        <div class="m-hdr">
            <h3><i class="fas fa-pen" style="color:var(--accent);margin-right:6px"></i>Edit Advertisement</h3>
            <button class="m-close" onclick="closeModal('editAdModal')"><i class="fas fa-xmark"></i></button>
        </div>
        <form method="POST" enctype="multipart/form-data" action="?page=advertisements">
            <input type="hidden" name="_action" value="edit_ad">
            <input type="hidden" name="ad_id" id="editAdId">
            <input type="hidden" name="existing_ad_image" id="editAdExistingImage">
            <div class="m-body">
                <div class="fg">
                    <label>Banner Image</label>
                    <div class="img-prev" id="editAdImgPrev" style="height:110px">
                        <div class="iph" id="editAdImgPh"><i class="fas fa-image"></i></div>
                        <img id="editAdImgPrevImg" src="" style="display:none">
                    </div>
                    <div class="f-row">
                        <div>
                            <input type="file" name="ad_image_file" accept="image/*" class="fc" style="padding:5px"
                                   onchange="adPrvFile(this,'editAdImgPrevImg','editAdImgPrev')">
                            <div class="f-hint">Upload to replace current image</div>
                        </div>
                        <div>
                            <input type="text" name="ad_image_url" id="editAdImageUrl" class="fc" placeholder="…or paste image path"
                                   oninput="adPrvUrl(this.value,'editAdImgPrevImg','editAdImgPrev')">
                            <div class="f-hint">Leave blank to keep existing</div>
                        </div>
                    </div>
                </div>
                <div class="f-row">
                    <div class="fg">
                        <label>Title <span>*</span></label>
                        <input type="text" name="ad_title" id="editAdTitle" class="fc" required>
                    </div>
                    <div class="fg">
                        <label>Subtitle / Tagline</label>
                        <input type="text" name="ad_subtitle" id="editAdSubtitle" class="fc">
                    </div>
                </div>
                <div class="f-row">
                    <div class="fg">
                        <label>Position</label>
                        <select name="ad_position" id="editAdPosition" class="fc">
                            <?php foreach ($ad_positions as $pk => $pv): ?>
                            <option value="<?= $pk ?>"><?= $pv['label'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="fg">
                        <label>Badge Text</label>
                        <input type="text" name="ad_badge" id="editAdBadge" class="fc" placeholder="e.g. 🔥 Limited Time">
                    </div>
                </div>
                <div class="f-row">
                    <div class="fg">
                        <label>Button Text</label>
                        <input type="text" name="ad_btn_text" id="editAdBtnText" class="fc">
                    </div>
                    <div class="fg">
                        <label>Button Color</label>
                        <div style="display:flex;gap:7px;align-items:center">
                            <input type="color" name="ad_btn_color" id="editAdBtnColorPicker" class="fc" style="width:44px;height:36px;padding:3px;cursor:pointer" oninput="syncColorPicker('editAdBtnColorPicker','editAdBtnColorText')">
                            <input type="text" id="editAdBtnColorText" class="fc" placeholder="#0cb100" oninput="syncColorText('editAdBtnColorText','editAdBtnColorPicker')" style="flex:1">
                        </div>
                    </div>
                </div>
                <div class="f-row">
                    <div class="fg">
                        <label>Link URL</label>
                        <input type="text" name="ad_link" id="editAdLink" class="fc">
                    </div>
                    <div class="fg">
                        <label>Sort Order</label>
                        <input type="number" name="ad_order" id="editAdOrder" class="fc" min="0">
                    </div>
                </div>
                <div class="fg">
                    <div style="display:flex;align-items:center;gap:10px;padding:.7rem 1rem;background:var(--surface);border-radius:var(--r-md)">
                        <label class="toggle" style="margin:0"><input type="checkbox" name="ad_active" id="editAdActive"><span class="tgl-sl"></span></label>
                        <div>
                            <strong style="font-size:.84rem;color:var(--ink)">Active</strong>
                            <span style="display:block;font-size:.72rem;color:var(--ink-muted)">Toggle off to hide without deleting</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="m-foot">
                <button type="button" class="btn-o" onclick="closeModal('editAdModal')">Cancel</button>
                <button type="submit" class="btn-p"><i class="fas fa-floppy-disk"></i> Update Ad</button>
            </div>
        </form>
    </div>
</div>


<!-- ══════════════════════════════════════════════════════════════ -->
<!-- AD PREVIEW MODAL                                              -->
<!-- ══════════════════════════════════════════════════════════════ -->
<div class="m-overlay" id="adPreviewModal">
    <div class="modal" style="max-width:560px">
        <div class="m-hdr">
            <h3><i class="fas fa-eye" style="color:var(--accent);margin-right:6px"></i>Ad Preview</h3>
            <button class="m-close" onclick="closeModal('adPreviewModal')"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="m-body">
            <p style="font-size:.78rem;color:var(--ink-muted);margin-bottom:.9rem">Approximate preview of how this ad will appear on the homepage.</p>
            <div class="preview-device">
                <div class="preview-chrome"></div>
                <div id="adPreviewContent" style="padding:.3rem"></div>
            </div>
            <div style="margin-top:1rem;background:var(--surface);border-radius:var(--r-md);padding:.8rem 1rem" id="adPreviewMeta"></div>
        </div>
        <div class="m-foot">
            <button type="button" class="btn-o" onclick="closeModal('adPreviewModal')">Close</button>
        </div>
    </div>
</div>


<script>
/* ── Modal helpers ───────────────────────────────────────────── */
function openModal(id)  { document.getElementById(id).classList.add('open'); document.body.style.overflow='hidden'; }
function closeModal(id) { document.getElementById(id).classList.remove('open'); document.body.style.overflow=''; }
document.querySelectorAll('.m-overlay').forEach(el => {
    el.addEventListener('click', e => { if (e.target===el) closeModal(el.id); });
});

/* ── Add Product image preview ───────────────────────────────── */
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

/* ── Edit Product image preview ──────────────────────────────── */
function prvFileEdit(inp) {
    if (!inp.files.length) return;
    const r = new FileReader();
    r.onload = e => showPrvEdit(e.target.result);
    r.readAsDataURL(inp.files[0]);
}
function prvUrlEdit(url) { if (url.trim()) showPrvEdit(url); }
function showPrvEdit(src) {
    const img = document.getElementById('editImgPrevImg');
    const ph  = document.getElementById('editImgPh');
    img.src = src; img.style.display = 'block';
    if (ph) ph.style.display = 'none';
}

/* ── Open Edit Product modal ─────────────────────────────────── */
function openEditProduct(data) {
    document.getElementById('editProdId').value            = data.id;
    document.getElementById('editProdName').value          = data.name;
    document.getElementById('editProdBrand').value         = data.brand || '';
    document.getElementById('editProdPrice').value         = data.price;
    document.getElementById('editProdOrigPrice').value     = data.original_price || '';
    document.getElementById('editProdStock').value         = data.stock_count;
    document.getElementById('editProdSpecs').value         = data.specs || '';
    document.getElementById('editProdImageUrl').value      = '';
    document.getElementById('editProdExistingImage').value = data.image || '';
    const sel = document.getElementById('editProdCategory');
    Array.from(sel.options).forEach(o => { o.selected = (o.value === data.category); });
    const img = document.getElementById('editImgPrevImg');
    const ph  = document.getElementById('editImgPh');
    if (data.image) {
        img.src = '../' + data.image; img.style.display = 'block';
        if (ph) ph.style.display = 'none';
        img.onerror = () => { img.style.display='none'; if(ph) ph.style.display='flex'; };
    } else {
        img.style.display = 'none'; if (ph) ph.style.display = 'flex';
    }
    const fileInput = document.querySelector('#editProductModal input[type=file]');
    if (fileInput) fileInput.value = '';
    openModal('editProductModal');
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
        document.querySelectorAll('#delReassignWrap select option').forEach(opt => {
            opt.hidden = (opt.value === data.slug);
        });
    } else {
        msg.textContent = `Delete category "${data.name}"? It has no products, so this is safe.`;
        wrap.style.display = 'none';
    }
    openModal('deleteCatModal');
}

/* ── Ad image preview helpers ────────────────────────────────── */
function adPrvFile(inp, imgId, wrpId) {
    if (!inp.files.length) return;
    const r = new FileReader();
    r.onload = e => adShowPrv(e.target.result, imgId, wrpId);
    r.readAsDataURL(inp.files[0]);
}
function adPrvUrl(url, imgId, wrpId) { if (url.trim()) adShowPrv(url, imgId, wrpId); }
function adShowPrv(src, imgId, wrpId) {
    const img = document.getElementById(imgId);
    const ph  = document.querySelector('#' + wrpId + ' .iph') || document.getElementById(imgId.replace('PrevImg','ImgPh'));
    img.src = src; img.style.display = 'block';
    if (ph) ph.style.display = 'none';
}

/* ── Color sync ──────────────────────────────────────────────── */
function syncColorPicker(pickerId, textId) {
    document.getElementById(textId).value = document.getElementById(pickerId).value;
}
function syncColorText(textId, pickerId) {
    const val = document.getElementById(textId).value;
    if (/^#[0-9a-f]{6}$/i.test(val)) document.getElementById(pickerId).value = val;
}

/* ── Add-ad modal: pre-fill position ─────────────────────────── */
function openAddAdForPosition(pos) {
    const sel = document.getElementById('addAdPosition');
    if (sel) Array.from(sel.options).forEach(o => o.selected = (o.value === pos));
    const descs = <?= json_encode(array_combine(array_keys($ad_positions), array_column(array_values($ad_positions),'desc'))) ?>;
    const descEl = document.getElementById('addAdPositionDesc');
    if (descEl && descs[pos]) descEl.textContent = descs[pos];
    openModal('addAdModal');
}
document.getElementById('addAdPosition')?.addEventListener('change', function() {
    const descs = <?= json_encode(array_combine(array_keys($ad_positions), array_column(array_values($ad_positions),'desc'))) ?>;
    const descEl = document.getElementById('addAdPositionDesc');
    if (descEl) descEl.textContent = descs[this.value] || '';
});

/* ── Open edit-ad modal ──────────────────────────────────────── */
function openEditAd(data) {
    document.getElementById('editAdId').value              = data.id;
    document.getElementById('editAdTitle').value           = data.title;
    document.getElementById('editAdSubtitle').value        = data.subtitle || '';
    document.getElementById('editAdBadge').value           = data.badge_text || '';
    document.getElementById('editAdBtnText').value         = data.btn_text || '';
    document.getElementById('editAdBtnColorPicker').value  = data.btn_color || '#0cb100';
    document.getElementById('editAdBtnColorText').value    = data.btn_color || '#0cb100';
    document.getElementById('editAdLink').value            = data.link_url || '';
    document.getElementById('editAdOrder').value           = data.sort_order || 0;
    document.getElementById('editAdActive').checked        = !!parseInt(data.is_active);
    document.getElementById('editAdExistingImage').value   = data.image || '';
    document.getElementById('editAdImageUrl').value        = '';
    const sel = document.getElementById('editAdPosition');
    Array.from(sel.options).forEach(o => o.selected = (o.value === data.position));
    const img = document.getElementById('editAdImgPrevImg');
    const ph  = document.getElementById('editAdImgPh');
    if (data.image) {
        img.src = '../' + data.image; img.style.display = 'block';
        if (ph) ph.style.display = 'none';
        img.onerror = () => { img.style.display = 'none'; if (ph) ph.style.display = 'flex'; };
    } else {
        img.style.display = 'none'; if (ph) ph.style.display = 'flex';
    }
    const fileInput = document.querySelector('#editAdModal input[type=file]');
    if (fileInput) fileInput.value = '';
    openModal('editAdModal');
}

/* ── Ad preview renderer ─────────────────────────────────────── */
function openAdPreview(data) {
    const container = document.getElementById('adPreviewContent');
    const meta      = document.getElementById('adPreviewMeta');
    const imgSrc    = data.image ? '../' + data.image : '';
    const btnC      = data.btn_color || '#0cb100';
    const pos       = data.position || 'hero';
    let html = '';
    if (pos === 'hero') {
        html = `<div style="background:linear-gradient(135deg,#111,#1a1a2e);min-height:155px;display:flex;align-items:center;justify-content:space-between;padding:1.2rem 1.4rem;gap:1rem;position:relative;overflow:hidden">
            ${imgSrc ? `<img style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;opacity:.35" src="${imgSrc}" onerror="this.remove()">` : ''}
            <div style="position:relative;z-index:1">
                ${data.badge_text ? `<div style="font-size:.58rem;font-weight:800;padding:2px 8px;border-radius:100px;margin-bottom:6px;display:inline-block;background:${btnC};color:#fff">${data.badge_text}</div>` : ''}
                <div style="font-size:.9rem;font-weight:800;color:#fff;line-height:1.2;margin-bottom:4px">${data.title||'Ad Title'}</div>
                <div style="font-size:.67rem;color:rgba(255,255,255,.55);margin-bottom:10px">${data.subtitle||''}</div>
                ${data.btn_text ? `<span style="font-size:.63rem;font-weight:700;padding:5px 12px;border-radius:6px;background:${btnC};color:#fff">${data.btn_text}</span>` : ''}
            </div>
            ${imgSrc ? `<img src="${imgSrc}" style="width:90px;height:90px;object-fit:contain;border-radius:10px;position:relative;z-index:1;flex-shrink:0" onerror="this.remove()">` : ''}
        </div>`;
    } else if (pos === 'banner') {
        html = `<div style="background:#f8f8ff;padding:1rem 1.4rem;display:flex;align-items:center;gap:1rem">
            ${imgSrc ? `<img src="${imgSrc}" style="width:70px;height:55px;object-fit:cover;border-radius:8px" onerror="this.remove()">` : '<div style="width:70px;height:55px;background:#e0e0e8;border-radius:8px;flex-shrink:0"></div>'}
            <div>
                ${data.badge_text ? `<div style="font-size:.58rem;font-weight:800;background:${btnC};color:#fff;padding:2px 8px;border-radius:100px;margin-bottom:4px;display:inline-block">${data.badge_text}</div>` : ''}
                <div style="font-size:.82rem;font-weight:800;color:#111">${data.title||'Banner Title'}</div>
                <div style="font-size:.67rem;color:#888;margin:2px 0 8px">${data.subtitle||''}</div>
                ${data.btn_text ? `<span style="font-size:.63rem;font-weight:700;padding:4px 10px;border-radius:6px;background:${btnC};color:#fff">${data.btn_text}</span>` : ''}
            </div>
        </div>`;
    } else if (pos === 'sidebar') {
        html = `<div style="background:#f8f8ff;padding:1rem;max-width:190px;margin:0 auto">
            ${imgSrc ? `<img src="${imgSrc}" style="width:100%;height:90px;object-fit:cover;border-radius:8px;margin-bottom:8px" onerror="this.remove()">` : '<div style="width:100%;height:90px;background:#e0e0e8;border-radius:8px;margin-bottom:8px"></div>'}
            ${data.badge_text ? `<span style="font-size:.55rem;background:${btnC};color:#fff;padding:2px 6px;border-radius:100px">${data.badge_text}</span>` : ''}
            <div style="font-size:.75rem;font-weight:800;color:#111;margin:4px 0 2px">${data.title||''}</div>
            <div style="font-size:.63rem;color:#888;margin-bottom:7px">${data.subtitle||''}</div>
            ${data.btn_text ? `<div style="font-size:.6rem;font-weight:700;padding:4px 10px;border-radius:6px;background:${btnC};color:#fff;text-align:center">${data.btn_text}</div>` : ''}
        </div>`;
    } else if (pos === 'popup') {
        html = `<div style="display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.4);padding:1.2rem;border-radius:8px">
            <div style="background:#fff;border-radius:10px;overflow:hidden;width:210px">
                ${imgSrc ? `<img src="${imgSrc}" style="width:100%;height:85px;object-fit:cover" onerror="this.remove()">` : '<div style="width:100%;height:85px;background:#e8e8f0"></div>'}
                <div style="padding:.7rem .9rem">
                    ${data.badge_text ? `<span style="font-size:.55rem;background:${btnC};color:#fff;padding:2px 6px;border-radius:100px">${data.badge_text}</span>` : ''}
                    <div style="font-size:.78rem;font-weight:800;color:#111;margin:4px 0 2px">${data.title||''}</div>
                    <div style="font-size:.62rem;color:#888;margin-bottom:8px">${data.subtitle||''}</div>
                    ${data.btn_text ? `<div style="font-size:.6rem;font-weight:700;padding:4px 10px;border-radius:6px;background:${btnC};color:#fff;text-align:center;margin-bottom:4px">${data.btn_text}</div>` : ''}
                    <div style="font-size:.55rem;color:#aaa;text-align:center;cursor:pointer">✕ Close popup</div>
                </div>
            </div>
        </div>`;
    } else {
        html = `<div style="background:#f8f8ff;padding:.8rem 1rem;text-align:center;display:flex;align-items:center;justify-content:center;gap:.7rem;flex-wrap:wrap">
            ${data.badge_text ? `<span style="font-size:.58rem;background:${btnC};color:#fff;padding:2px 8px;border-radius:100px">${data.badge_text}</span>` : ''}
            <span style="font-size:.78rem;font-weight:700;color:#111">${data.title}</span>
            ${data.subtitle ? `<span style="font-size:.67rem;color:#888">${data.subtitle}</span>` : ''}
            ${data.btn_text ? `<span style="font-size:.63rem;font-weight:700;padding:3px 10px;border-radius:6px;background:${btnC};color:#fff">${data.btn_text}</span>` : ''}
        </div>`;
    }
    container.innerHTML = html;
    meta.innerHTML = `<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:.74rem">
        <div><span style="color:var(--ink-muted);font-weight:600">Position:</span> <strong>${data.position}</strong></div>
        <div><span style="color:var(--ink-muted);font-weight:600">Status:</span> <strong style="color:${data.is_active?'#15803d':'#dc2626'}">${data.is_active?'● Active':'● Inactive'}</strong></div>
        <div><span style="color:var(--ink-muted);font-weight:600">Link:</span> ${data.link_url?`<a href="${data.link_url}" target="_blank" style="color:var(--accent)">${data.link_url.substring(0,35)}${data.link_url.length>35?'…':''}</a>`:'<em>none</em>'}</div>
        <div><span style="color:var(--ink-muted);font-weight:600">Sort order:</span> <strong>${data.sort_order}</strong></div>
    </div>`;
    openModal('adPreviewModal');
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
liveSearch('adSearch','adTable');

/* ── Ticker live preview color update ────────────────────────── */
function updateTickerPreviewColor(color) {
    const bar = document.getElementById('livePreviewBar');
    if (bar) bar.style.background = color;
    const dash = document.querySelector('.ticker-dash-bar');
    if (dash) dash.style.background = color;
}

/* ── Open edit ticker modal ──────────────────────────────────── */
function openEditTicker(data) {
    document.getElementById('editTickerId').value       = data.id;
    document.getElementById('editTickerMessage').value  = data.message;
    document.getElementById('editTickerEmoji').value    = data.emoji || '';
    document.getElementById('editTickerLinkUrl').value  = data.link_url || '';
    document.getElementById('editTickerLinkText').value = data.link_text || '';
    document.getElementById('editTickerOrder').value    = data.sort_order || 0;
    document.getElementById('editTickerActive').checked = !!parseInt(data.is_active);
    openModal('editTickerModal');
}

liveSearch('tickerSearch','tickerTable');

/* Global search */
document.getElementById('gSearch')?.addEventListener('input',function(){
    const q=this.value.toLowerCase();
    ['prodTable','orderTable','custTable','catTable','adTable','tickerTable'].forEach(tid=>{
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