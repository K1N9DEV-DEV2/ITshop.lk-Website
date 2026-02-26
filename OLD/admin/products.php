 <?php
// admin/products.php
session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit();
}

include '../db.php';

$message = '';
$error = '';
$action = isset($_GET['action']) ? $_GET['action'] : 'list';
$product_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Handle delete product
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    try {
        $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
        $stmt->execute([$_POST['product_id']]);
        $message = "Product deleted successfully!";
        $action = 'list';
    } catch(PDOException $e) {
        $error = "Error deleting product: " . $e->getMessage();
    }
}

// Handle add/edit product
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['add', 'edit'])) {
    try {
        $name = trim($_POST['name']);
        $description = trim($_POST['description']);
        $price = floatval($_POST['price']);
        $stock_count = intval($_POST['stock_count']);
        $category_id = intval($_POST['category_id']);
        $image = $_POST['image'] ?? '';
        
        // Validate inputs
        if (empty($name) || $price < 0 || $stock_count < 0 || $category_id <= 0) {
            throw new Exception("Invalid input data");
        }
        
        if ($_POST['action'] === 'add') {
            $stmt = $pdo->prepare("INSERT INTO products (name, description, price, stock_count, category_id, image, created_at) 
                                   VALUES (?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$name, $description, $price, $stock_count, $category_id, $image]);
            $message = "Product added successfully!";
        } else {
            $stmt = $pdo->prepare("UPDATE products SET name = ?, description = ?, price = ?, stock_count = ?, category_id = ?, image = ? 
                                   WHERE id = ?");
            $stmt->execute([$name, $description, $price, $stock_count, $category_id, $image, $_POST['product_id']]);
            $message = "Product updated successfully!";
        }
        $action = 'list';
    } catch(Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Get product for edit
$product = null;
if ($action === 'edit' && $product_id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
        $stmt->execute([$product_id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$product) {
            $error = "Product not found";
            $action = 'list';
        }
    } catch(PDOException $e) {
        $error = "Error fetching product: " . $e->getMessage();
        $action = 'list';
    }
}

// Get all categories
$categories = [];
try {
    $stmt = $pdo->query("SELECT id, name FROM categories ORDER BY name");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    error_log("Error fetching categories: " . $e->getMessage());
    // Continue without categories - they may not exist yet
}

// Get products list with filters
$products = [];
if ($action === 'list') {
    try {
        $search = isset($_GET['search']) ? trim($_GET['search']) : '';
        $category_filter = isset($_GET['category']) ? intval($_GET['category']) : 0;
        $stock_filter = isset($_GET['stock']) ? $_GET['stock'] : '';
        $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
        $limit = 10;
        $offset = ($page - 1) * $limit;
        
        $query = "SELECT p.* FROM products p WHERE 1=1";
        $params = [];
        
        if (!empty($search)) {
            $query .= " AND (p.name LIKE ? OR p.description LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        
        if ($category_filter > 0) {
            $query .= " AND p.category_id = ?";
            $params[] = $category_filter;
        }
        
        if ($stock_filter === 'low') {
            $query .= " AND p.stock_count <= 5 AND p.stock_count > 0";
        } elseif ($stock_filter === 'out') {
            $query .= " AND p.stock_count = 0";
        }
        
// Count total products
$count_query = "SELECT COUNT(*) as count FROM products p WHERE 1=1";
$count_params = [];

if (!empty($search)) {
    $count_query .= " AND (p.name LIKE ? OR p.description LIKE ?)";
    $count_params[] = "%$search%";
    $count_params[] = "%$search%";
}

if ($category_filter > 0) {
    $count_query .= " AND p.category_id = ?";
    $count_params[] = $category_filter;
}

if ($stock_filter === 'low') {
    $count_query .= " AND p.stock_count <= 5 AND p.stock_count > 0";
} elseif ($stock_filter === 'out') {
    $count_query .= " AND p.stock_count = 0";
}

$stmt = $pdo->prepare($count_query);
$stmt->execute($count_params);
$total_products = $stmt->fetch()['count'];
$total_pages = ceil($total_products / $limit);

// Get products
$query .= " ORDER BY p.created_at DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        $error = "Error fetching products: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products - Admin Dashboard</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --primary-color: #12b800ff;
            --secondary-color: #008cffff;
            --sidebar-bg: #1a1d29;
            --sidebar-hover: #252837;
            --text-dark: #1a202c;
            --text-light: #718096;
            --bg-light: #f7fafc;
            --border-color: #e2e8f0;
            --success-color: #38a169;
            --warning-color: #fd7e14;
            --danger-color: #dc3545;
            --info-color: #0dcaf0;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-light);
            color: var(--text-dark);
            overflow-x: hidden;
        }
        
        /* Sidebar */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            height: 100vh;
            width: 260px;
            background: var(--sidebar-bg);
            padding: 2rem 0;
            z-index: 1000;
            transition: all 0.3s ease;
            overflow-y: auto;
        }
        
        .sidebar::-webkit-scrollbar {
            width: 4px;
        }
        
        .sidebar::-webkit-scrollbar-thumb {
            background: rgba(255,255,255,0.2);
            border-radius: 4px;
        }
        
        .sidebar-brand {
            padding: 0 1.5rem 2rem;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            margin-bottom: 2rem;
        }
        
        .sidebar-brand img {
            height: 40px;
            margin-bottom: 0.5rem;
        }
        
        .sidebar-brand h4 {
            color: white;
            font-size: 1.2rem;
            font-weight: 600;
            margin: 0;
        }
        
        .sidebar-menu {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .sidebar-menu-item {
            margin-bottom: 0.25rem;
        }
        
        .sidebar-menu-link {
            display: flex;
            align-items: center;
            padding: 0.875rem 1.5rem;
            color: rgba(255,255,255,0.7);
            text-decoration: none;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .sidebar-menu-link:hover,
        .sidebar-menu-link.active {
            background: var(--sidebar-hover);
            color: white;
        }
        
        .sidebar-menu-link.active::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            height: 100%;
            width: 4px;
            background: var(--primary-color);
        }
        
        .sidebar-menu-link i {
            width: 24px;
            font-size: 1.1rem;
            margin-right: 0.75rem;
        }
        
        .sidebar-menu-text {
            font-size: 0.95rem;
            font-weight: 500;
        }
        
        /* Main Content */
        .main-content {
            margin-left: 260px;
            padding: 2rem;
            min-height: 100vh;
            transition: all 0.3s ease;
        }
        
        /* Top Bar */
        .top-bar {
            background: white;
            padding: 1rem 2rem;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .page-title {
            margin: 0;
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--text-dark);
        }
        
        /* Content Card */
        .content-card {
            background: white;
            border-radius: 12px;
            padding: 1.75rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 2rem;
        }
        
        .card-header-custom {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
        }
        
        .card-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin: 0;
            color: var(--text-dark);
        }
        
        /* Filter Bar */
        .filter-bar {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .form-control-custom {
            padding: 0.625rem 1rem;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 0.9rem;
            font-family: 'Inter', sans-serif;
        }
        
        .form-control-custom:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(18, 184, 0, 0.1);
        }
        
        /* Buttons */
        .btn-custom {
            padding: 0.625rem 1.5rem;
            border-radius: 8px;
            font-weight: 500;
            font-size: 0.9rem;
            border: none;
            transition: all 0.3s ease;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .btn-primary-custom {
            background: var(--primary-color);
            color: white;
        }
        
        .btn-primary-custom:hover {
            background: #0e7b02ff;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(18, 184, 0, 0.3);
        }
        
        .btn-secondary-custom {
            background: var(--secondary-color);
            color: white;
        }
        
        .btn-secondary-custom:hover {
            background: #0073d9;
        }
        
        .btn-danger-custom {
            background: var(--danger-color);
            color: white;
        }
        
        .btn-danger-custom:hover {
            background: #bb2d3b;
        }
        
        .btn-sm {
            padding: 0.4rem 0.75rem;
            font-size: 0.8rem;
        }
        
        /* Table Styles */
        .table-custom {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }
        
        .table-custom thead th {
            background: var(--bg-light);
            color: var(--text-dark);
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 1rem;
            border: none;
        }
        
        .table-custom tbody td {
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-dark);
            font-size: 0.9rem;
        }
        
        .table-custom tbody tr:hover {
            background: rgba(18, 184, 0, 0.02);
        }
        
        /* Product Image */
        .product-img {
            width: 50px;
            height: 50px;
            object-fit: contain;
            border-radius: 8px;
            background: var(--bg-light);
            padding: 5px;
        }
        
        /* Badges */
        .badge-custom {
            padding: 0.4rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .badge-success {
            background: rgba(56, 161, 105, 0.1);
            color: var(--success-color);
        }
        
        .badge-warning {
            background: rgba(253, 126, 20, 0.1);
            color: var(--warning-color);
        }
        
        .badge-danger {
            background: rgba(220, 53, 69, 0.1);
            color: var(--danger-color);
        }
        
        /* Form Styles */
        .form-group-custom {
            margin-bottom: 1.5rem;
        }
        
        .form-group-custom label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.9rem;
        }
        
        .form-group-custom input,
        .form-group-custom textarea,
        .form-group-custom select {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 0.9rem;
            font-family: 'Inter', sans-serif;
        }
        
        .form-group-custom textarea {
            resize: vertical;
            min-height: 120px;
        }
        
        .form-group-custom input:focus,
        .form-group-custom textarea:focus,
        .form-group-custom select:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(18, 184, 0, 0.1);
        }
        
        /* Messages */
        .alert-custom {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .alert-success {
            background: rgba(56, 161, 105, 0.1);
            color: var(--success-color);
            border: 1px solid rgba(56, 161, 105, 0.2);
        }
        
        .alert-danger {
            background: rgba(220, 53, 69, 0.1);
            color: var(--danger-color);
            border: 1px solid rgba(220, 53, 69, 0.2);
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.active {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
                padding: 1rem;
            }
            
            .top-bar {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }
            
            .filter-bar {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-brand">
            <img src="../assets/revised-04.png" alt="STC Logo">
            <h4>Admin Panel</h4>
        </div>
        
        <ul class="sidebar-menu">
            <li class="sidebar-menu-item">
                <a href="admin_dashbaord.php" class="sidebar-menu-link">
                    <i class="fas fa-home"></i>
                    <span class="sidebar-menu-text">Dashboard</span>
                </a>
            </li>
            <li class="sidebar-menu-item">
                <a href="products.php" class="sidebar-menu-link active">
                    <i class="fas fa-box"></i>
                    <span class="sidebar-menu-text">Products</span>
                </a>
            </li>
            <li class="sidebar-menu-item">
                <a href="orders.php" class="sidebar-menu-link">
                    <i class="fas fa-shopping-cart"></i>
                    <span class="sidebar-menu-text">Orders</span>
                </a>
            </li>
            <li class="sidebar-menu-item">
                <a href="customers.php" class="sidebar-menu-link">
                    <i class="fas fa-users"></i>
                    <span class="sidebar-menu-text">Customers</span>
                </a>
            </li>
            <li class="sidebar-menu-item">
                <a href="categories.php" class="sidebar-menu-link">
                    <i class="fas fa-tags"></i>
                    <span class="sidebar-menu-text">Categories</span>
                </a>
            </li>
            <li class="sidebar-menu-item">
                <a href="reports.php" class="sidebar-menu-link">
                    <i class="fas fa-chart-bar"></i>
                    <span class="sidebar-menu-text">Reports</span>
                </a>
            </li>
            <li class="sidebar-menu-item">
                <a href="settings.php" class="sidebar-menu-link">
                    <i class="fas fa-cog"></i>
                    <span class="sidebar-menu-text">Settings</span>
                </a>
            </li>
            <li class="sidebar-menu-item">
                <a href="logout.php" class="sidebar-menu-link">
                    <i class="fas fa-sign-out-alt"></i>
                    <span class="sidebar-menu-text">Logout</span>
                </a>
            </li>
        </ul>
    </aside>
    
    <!-- Main Content -->
    <main class="main-content">
        <!-- Top Bar -->
        <div class="top-bar">
            <h1 class="page-title">Products</h1>
            <?php if ($action === 'list'): ?>
            <a href="?action=add" class="btn-custom btn-primary-custom">
                <i class="fas fa-plus"></i> Add Product
            </a>
            <?php endif; ?>
        </div>
        
        <!-- Messages -->
        <?php if (!empty($message)): ?>
        <div class="alert-custom alert-success">
            <i class="fas fa-check-circle"></i>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($error)): ?>
        <div class="alert-custom alert-danger">
            <i class="fas fa-exclamation-circle"></i>
            <span><?php echo htmlspecialchars($error); ?></span>
        </div>
        <?php endif; ?>
        
        <!-- Product List View -->
        <?php if ($action === 'list'): ?>
        <div class="content-card">
            <!-- Filter Bar -->
            <form method="GET" class="filter-bar">
                <input type="text" name="search" placeholder="Search products..." 
                       value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>" 
                       class="form-control-custom">
                
                <select name="category" class="form-control-custom">
                    <option value="0">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo $cat['id']; ?>" 
                            <?php echo (($_GET['category'] ?? 0) == $cat['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($cat['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                
                <select name="stock" class="form-control-custom">
                    <option value="">All Stock Status</option>
                    <option value="low" <?php echo (($_GET['stock'] ?? '') === 'low') ? 'selected' : ''; ?>>Low Stock</option>
                    <option value="out" <?php echo (($_GET['stock'] ?? '') === 'out') ? 'selected' : ''; ?>>Out of Stock</option>
                </select>
                
                <button type="submit" class="btn-custom btn-secondary-custom">
                    <i class="fas fa-search"></i> Filter
                </button>
            </form>
            
            <!-- Products Table -->
            <div class="table-responsive">
                <table class="table-custom">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Category</th>
                            <th>Price</th>
                            <th>Stock</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($products)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-4">
                                <i class="fas fa-box-open fa-3x text-muted mb-3"></i>
                                <p class="text-muted">No products found</p>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($products as $prod): ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center">
                                    <img src="../<?php echo htmlspecialchars($prod['image']); ?>" 
                                         alt="<?php echo htmlspecialchars($prod['name']); ?>" 
                                         class="product-img me-3">
                                    <strong><?php echo htmlspecialchars($prod['name']); ?></strong>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($prod['category_name'] ?? 'Uncategorized'); ?></td>
                            <td><strong>LKR <?php echo number_format($prod['price'], 2); ?></strong></td>
                            <td><?php echo $prod['stock_count']; ?> units</td>
                            <td>
                                <?php
                                $badge_class = 'badge-success';
                                $status_text = 'In Stock';
                                if ($prod['stock_count'] == 0) {
                                    $badge_class = 'badge-danger';
                                    $status_text = 'Out of Stock';
                                } elseif ($prod['stock_count'] <= 5) {
                                    $badge_class = 'badge-warning';
                                    $status_text = 'Low Stock';
                                }
                                ?>
                                <span class="badge-custom <?php echo $badge_class; ?>">
                                    <?php echo $status_text; ?>
                                </span>
                            </td>
                            <td>
                                <a href="?action=edit&id=<?php echo $prod['id']; ?>" 
                                   class="btn-custom btn-secondary-custom btn-sm">
                                    <i class="fas fa-edit"></i> Edit
                                </a>
                                <form method="POST" style="display:inline;" 
                                      onsubmit="return confirm('Are you sure you want to delete this product?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="product_id" value="<?php echo $prod['id']; ?>">
                                    <button type="submit" class="btn-custom btn-danger-custom btn-sm">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <?php if (isset($total_pages) && $total_pages > 1): ?>
            <div class="d-flex justify-content-center gap-2 mt-4">
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <a href="?page=<?php echo $i; ?>" 
                       class="btn-custom <?php echo ($page == $i) ? 'btn-primary-custom' : 'btn-secondary-custom'; ?>">
                        <?php echo $i; ?>
                    </a>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Add/Edit Product Form -->
        <?php elseif ($action === 'add' || $action === 'edit'): ?>
        <div class="content-card">
            <div class="card-header-custom">
                <h2 class="card-title">
                    <?php echo ($action === 'add') ? 'Add New Product' : 'Edit Product'; ?>
                </h2>
                <a href="products.php" class="btn-custom btn-secondary-custom">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
            </div>
            
            <form method="POST" class="row">
                <input type="hidden" name="action" value="<?php echo $action; ?>">
                <?php if ($action === 'edit'): ?>
                <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
                <?php endif; ?>
                
                <div class="col-md-6">
                    <div class="form-group-custom">
                        <label>Product Name *</label>
                        <input type="text" name="name" required
                               value="<?php echo htmlspecialchars($product['name'] ?? ''); ?>">
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="form-group-custom">
                        <label>Category *</label>
                        <select name="category_id" required>
                            <option value="">Select a category</option>
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>" 
                                    <?php echo (($product['category_id'] ?? 0) == $cat['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="col-12">
                    <div class="form-group-custom">
                        <label>Description</label>
                        <textarea name="description"><?php echo htmlspecialchars($product['description'] ?? ''); ?></textarea>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="form-group-custom">
                        <label>Product Image</label>
                        <input type="text" name="image" placeholder="e.g., products/image.jpg"
                               value="<?php echo htmlspecialchars($product['image'] ?? ''); ?>">
                        <small class="text-muted d-block mt-2">
                            <?php if ($action === 'edit' && !empty($product['image'])): ?>
                            <img src="../<?php echo htmlspecialchars($product['image']); ?>" 
                                 alt="Product" style="max-width: 100px; margin-top: 10px; border-radius: 8px;">
                            <?php endif; ?>
                        </small>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="form-group-custom">
                        <label>Price (LKR) *</label>
                        <input type="number" name="price" step="0.01" min="0" required
                        value="<?php echo htmlspecialchars($product['price'] ?? ''); ?>">
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="form-group-custom">
                        <label>Stock Count *</label>
                        <input type="number" name="stock_count" min="0" required
                        value="<?php echo htmlspecialchars($product['stock_count'] ?? ''); ?>">
                    </div>
                </div>
                
                <div class="col-12">
                    <div style="display: flex; gap: 1rem;">
                        <button type="submit" class="btn-custom btn-primary-custom">
                            <i class="fas fa-save"></i> 
                            <?php echo ($action === 'add') ? 'Add Product' : 'Update Product'; ?>
                        </button>
                        <a href="products.php" class="btn-custom btn-secondary-custom">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>
                </div>

                
            </form>
        </div>
        <?php endif; ?>
    </main>
    
    <!-- Bootstrap JS -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Mobile menu toggle
        const menuToggle = document.createElement('button');
        menuToggle.className = 'btn btn-primary-custom d-lg-none';
        menuToggle.style.cssText = 'position: fixed; top: 1rem; left: 1rem; z-index: 1001;';
        menuToggle.innerHTML = '<i class="fas fa-bars"></i>';
        document.body.appendChild(menuToggle);
        
        menuToggle.addEventListener('click', () => {
            document.querySelector('.sidebar').classList.toggle('active');
        });
    </script>
</body>
</html>