<?php
// Start session for user management
session_start();

// Database connection (adjust credentials as needed)
include 'db.php';

// Get cart count for logged-in user
$cart_count = 0;
$user_currency = 'LKR';
$cart_total = 0;

if (isset($_SESSION['user_id']) && $pdo) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count, SUM(price * quantity) as total FROM cart WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $cart_data = $stmt->fetch();
        $cart_count = $cart_data['count'] ?? 0;
        $cart_total = $cart_data['total'] ?? 0;
    } catch(PDOException $e) {
        error_log("Cart fetch error: " . $e->getMessage());
    }
}

// Get filters from URL
$category = $_GET['category'] ?? '';
$search = $_GET['search'] ?? '';
$sort = $_GET['sort'] ?? 'name_asc';
$price_min = $_GET['price_min'] ?? '';
$price_max = $_GET['price_max'] ?? '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$items_per_page = 12;
$offset = ($page - 1) * $items_per_page;

// Build SQL query with filters
$sql = "SELECT 
    p.id,
    p.name,
    p.category,
    p.price,
    p.original_price,
    p.image,
    p.brand,
    p.rating,
    p.reviews,
    p.stock_count,
    CASE WHEN p.stock_count > 0 THEN 1 ELSE 0 END as in_stock,
    GROUP_CONCAT(ps.spec_name SEPARATOR '|') as specs
FROM products p
LEFT JOIN product_specs ps ON p.id = ps.product_id
WHERE 1=1";

$params = [];

// Category filter
if ($category && $category !== 'all') {
    $sql .= " AND p.category = ?";
    $params[] = $category;
}

// Search filter
if ($search) {
    $sql .= " AND (p.name LIKE ? OR p.brand LIKE ? OR p.category LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

// Price filter
if ($price_min) {
    $sql .= " AND p.price >= ?";
    $params[] = floatval($price_min);
}

if ($price_max) {
    $sql .= " AND p.price <= ?";
    $params[] = floatval($price_max);
}

$sql .= " GROUP BY p.id";

// Get total count for pagination
$count_sql = "SELECT COUNT(DISTINCT p.id) as total FROM products p WHERE 1=1";
$count_params = [];

if ($category && $category !== 'all') {
    $count_sql .= " AND p.category = ?";
    $count_params[] = $category;
}

if ($search) {
    $count_sql .= " AND (p.name LIKE ? OR p.brand LIKE ? OR p.category LIKE ?)";
    $count_params[] = "%$search%";
    $count_params[] = "%$search%";
    $count_params[] = "%$search%";
}

if ($price_min) {
    $count_sql .= " AND p.price >= ?";
    $count_params[] = floatval($price_min);
}

if ($price_max) {
    $count_sql .= " AND p.price <= ?";
    $count_params[] = floatval($price_max);
}

try {
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($count_params);
    $total_products = $count_stmt->fetch()['total'];
    $total_pages = ceil($total_products / $items_per_page);
} catch(PDOException $e) {
    $total_products = 0;
    $total_pages = 1;
    error_log("Count query failed: " . $e->getMessage());
}

// Sort products
switch ($sort) {
    case 'price_low':
        $sql .= " ORDER BY p.price ASC";
        break;
    case 'price_high':
        $sql .= " ORDER BY p.price DESC";
        break;
    case 'rating':
        $sql .= " ORDER BY p.rating DESC";
        break;
    case 'name_asc':
    default:
        $sql .= " ORDER BY p.name ASC";
        break;
}

// Add pagination
$sql .= " LIMIT ? OFFSET ?";
$params[] = $items_per_page;
$params[] = $offset;

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $filtered_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Process specs for each product
    foreach ($filtered_products as &$product) {
        $product['specs'] = $product['specs'] ? explode('|', $product['specs']) : [];
        $product['in_stock'] = ($product['stock_count'] > 0);
        
        // Ensure original_price has a value
        if (!$product['original_price']) {
            $product['original_price'] = $product['price'];
        }
    }
    
} catch(PDOException $e) {
    $filtered_products = [];
    error_log("Database query failed: " . $e->getMessage());
}

// Get categories from database
try {
    $stmt = $pdo->query("SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category != '' ORDER BY category");
    $db_categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $categories = ['all' => 'All Categories'];
    foreach ($db_categories as $cat) {
        $categories[$cat] = ucwords(str_replace(['_', '-'], ' ', $cat));
    }
} catch(PDOException $e) {
    $categories = [
        'all' => 'All Products',
        'casings' => 'Casing',
        'cooling' => 'Cooling & Lighting',
        'desktops' => 'Desktop',
        'graphics' => 'Graphics Cards',
        'peripherals' => 'Keyboards & Mouse',
        'laptops' => 'Laptops',
        'memory' => 'Memory (RAM)',
        'monitors' => 'Monitors',
        'motherboards' => 'Motherboards',
        'processors' => 'Processors',
        'storage' => 'Storage',
        'power' => 'Power Supply',
        'audio' => 'Speakers & Headset'
    ];
}

// Get brands from database
try {
    $stmt = $pdo->query("SELECT DISTINCT brand FROM products WHERE brand IS NOT NULL AND brand != '' ORDER BY brand");
    $brands = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch(PDOException $e) {
    $brands = [];
}

// Function to build query string for pagination
function buildQueryString($page) {
    $params = $_GET;
    $params['page'] = $page;
    return http_build_query($params);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products - STC Electronics Store</title>
    <meta name="description" content="Browse our wide selection of laptops, PCs, components and accessories">
    
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
            --text-dark: #1a202c;
            --text-light: #4a5568;
            --bg-light: #f7fafc;
            --success-color: #38a169;
            --warning-color: #fd7e14;
            --danger-color: #dc3545;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            line-height: 1.6;
            color: var(--text-dark);
            background: var(--bg-light);
        }
        
        /* Header Styles */
        .navbar {
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 1rem 0;
        }
        
        .navbar-brand img {
            height: 50px;
        }
        
        .navbar-nav .nav-link {
            font-weight: 500;
            margin: 0 1rem;
            color: var(--text-dark) !important;
            transition: color 0.3s ease;
        }
        
        .navbar-nav .nav-link:hover {
            color: var(--primary-color) !important;
        }
        
        .cart-icon {
            position: relative;
            color: var(--text-dark);
            font-size: 1.2rem;
            margin-right: 1rem;
            text-decoration: none;
        }
        
        .cart-count {
            position: absolute;
            top: -8px;
            right: -8px;
            background: var(--primary-color);
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
        }
        
        .btn-login {
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 0.5rem 1.5rem;
            border-radius: 5px;
            font-weight: 500;
            transition: background 0.3s ease;
        }
        
        .btn-login:hover {
            background: rgba(4, 97, 0, 1);
            color: white;
        }
        
        /* Page Header */
        .page-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, #008cffff 100%);
            color: white;
            padding: 8rem 0 4rem;
            margin-top: 76px;
        }
        
        .page-header h1 {
            font-size: 3rem;
            font-weight: 700;
            margin-bottom: 1rem;
        }
        
        .breadcrumb {
            background: transparent;
            padding: 0;
            margin-bottom: 0;
        }
        
        .breadcrumb-item a {
            color: rgba(255,255,255,0.8);
            text-decoration: none;
        }
        
        .breadcrumb-item.active {
            color: white;
        }
        
        .breadcrumb-item + .breadcrumb-item::before {
            color: rgba(255,255,255,0.6);
        }
        
        /* Filters Section */
        .filters-section {
            background: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .search-box {
            position: relative;
        }
        
        .search-box input {
            padding-left: 2.5rem;
        }
        
        .search-box .fa-search {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-light);
            pointer-events: none;
            z-index: 10;
        }
        
        /* Product Grid */
        .products-section {
            padding: 2rem 0 4rem;
        }
        
        .product-card {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            border: none;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            height: 100%;
            display: flex;
            flex-direction: column;
        }
        
        .product-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 30px rgba(0,0,0,0.15);
        }
        
        /* Out of stock product styling */
        .product-card.out-of-stock-item {
            opacity: 0.9;
        }
        
        .product-card.out-of-stock-item .product-image::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.4);
            border-radius: 10px;
            pointer-events: none;
        }
        
        .product-image {
            position: relative;
            height: 220px;
            background: var(--bg-light);
            border-radius: 10px;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        
        .product-image img {
            max-width: 100%;
            max-height: 100%;
            width: auto;
            height: auto;
            object-fit: contain;
        }
        
        .product-image .placeholder-icon {
            font-size: 4rem;
            color: var(--text-light);
        }
        
        .product-badge {
            position: absolute;
            color: white;
            padding: 0.35rem 0.75rem;
            border-radius: 5px;
            font-size: 0.75rem;
            font-weight: 600;
            z-index: 10;
        }
        
        .product-badge.out-of-stock-badge {
            background: #6c757d !important;
        }
        
        .product-badge.low-stock-badge {
            background: var(--warning-color) !important;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
        
        .product-info {
            flex: 1;
            display: flex;
            flex-direction: column;
        }
        
        .product-title {
            font-weight: 600;
            font-size: 1.05rem;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            min-height: 3rem;
        }
        
        .product-brand {
            color: var(--text-light);
            font-size: 0.875rem;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }
        
        .product-rating {
            display: flex;
            align-items: center;
            margin-bottom: 0.75rem;
        }
        
        .stars {
            color: #ffd700;
            margin-right: 0.5rem;
            font-size: 0.875rem;
        }
        
        .rating-text {
            font-size: 0.875rem;
            color: var(--text-light);
        }
        
        .product-specs {
            margin-bottom: 0.75rem;
            min-height: 2.5rem;
        }
        
        .spec-tag {
            display: inline-block;
            background: var(--bg-light);
            color: var(--text-dark);
            padding: 0.25rem 0.5rem;
            border-radius: 5px;
            font-size: 0.7rem;
            margin: 0.125rem;
        }
        
        .product-price {
            margin-bottom: 1rem;
            margin-top: auto;
        }
        
        .current-price {
            font-size: 1.35rem;
            font-weight: 700;
            color: var(--primary-color);
        }
        
        .original-price {
            font-size: 0.9rem;
            color: var(--text-light);
            text-decoration: line-through;
            margin-left: 0.5rem;
        }
        
        /* Stock Count Styling */
        .product-stock {
            margin: 0.75rem 0;
        }

        .stock-info {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 0.8rem;
            font-weight: 500;
            padding: 4px 10px;
            border-radius: 15px;
        }

        .stock-info i {
            font-size: 0.85rem;
        }

        .high-stock {
            color: #28a745;
            background-color: rgba(40, 167, 69, 0.1);
        }

        .medium-stock {
            color: #007bff;
            background-color: rgba(0, 123, 255, 0.1);
        }

        .low-stock {
            color: #fd7e14;
            background-color: rgba(253, 126, 20, 0.1);
        }

        .out-of-stock {
            color: #dc3545;
            background-color: rgba(220, 53, 69, 0.1);
        }
        
        .btn-add-cart {
            background: var(--primary-color);
            color: white;
            border: none;
            width: 100%;
            padding: 0.75rem;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .btn-add-cart:hover:not(:disabled) {
            background: #0e7b02ff;
            color: white;
            transform: translateY(-2px);
        }
        
        .btn-add-cart:disabled,
        .btn-add-cart.btn-out-of-stock {
            background: #6c757d !important;
            cursor: not-allowed !important;
            opacity: 0.65 !important;
        }
        
        .btn-view-details {
            color: var(--secondary-color);
            border: 1px solid var(--secondary-color);
            background: white;
            width: 100%;
            padding: 0.75rem;
            border-radius: 8px;
            font-weight: 500;
            margin-bottom: 0.5rem;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }
        
        .btn-view-details:hover {
            background: var(--secondary-color);
            color: white;
        }
        
        /* Results Header */
        .results-header {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        /* Pagination Styles */
        .pagination {
            margin-top: 2rem;
        }
        
        .pagination .page-link {
            color: var(--primary-color);
            border-color: #dee2e6;
            padding: 0.5rem 0.75rem;
            margin: 0 0.25rem;
            border-radius: 5px;
        }
        
        .pagination .page-item.active .page-link {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .pagination .page-link:hover {
            background-color: var(--bg-light);
            color: var(--primary-color);
        }
        
        .pagination .page-item.disabled .page-link {
            color: #6c757d;
        }
        
        /* No products message */
        .no-products {
            text-align: center;
            padding: 4rem 2rem;
        }
        
        .no-products i {
            font-size: 4rem;
            color: var(--text-light);
            margin-bottom: 1.5rem;
        }
        
        .no-products h3 {
            color: var(--text-dark);
            margin-bottom: 1rem;
        }
        
        .no-products p {
            color: var(--text-light);
            margin-bottom: 2rem;
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .page-header {
                padding: 6rem 0 3rem;
            }
            
            .page-header h1 {
                font-size: 2rem;
            }
            
            .product-card {
                margin-bottom: 1.5rem;
            }
            
            .navbar-collapse {
                background: white;
                padding: 1rem;
                border-radius: 10px;
                margin-top: 1rem;
                box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            }
            
            .filters-section .col-lg-1 {
                margin-top: 0.5rem;
            }
        }
        
        /* Loading Animation */
        @keyframes shimmer {
            0% { background-position: -200px 0; }
            100% { background-position: calc(200px + 100%) 0; }
        }
        
        .loading {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200px 100%;
            animation: shimmer 1.5s infinite;
        }
        
        /* WhatsApp Button */
        .whatsapp-btn {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #25d366;
            color: white;
            border-radius: 50%;
            width: 60px;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 30px;
            text-decoration: none;
            box-shadow: 0 4px 12px rgba(37, 211, 102, 0.4);
            z-index: 1000;
            transition: all 0.3s ease;
        }
        
        .whatsapp-btn:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 20px rgba(37, 211, 102, 0.6);
            color: white;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg fixed-top">
        <div class="container">
            <a class="navbar-brand" href="index.php">
                <img src="assets/revised-04.png" alt="STC Logo">
            </a>
            
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav mx-auto">
                    <li class="nav-item">
                        <a class="nav-link active" href="index.php">Home</a>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="productsDropdown" role="button" data-bs-toggle="dropdown">
                            Products
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="products.php?category=all">All Products</a></li>
                            <li><a class="dropdown-item" href="products.php?category=desktops">Casing</a></li>
                            <li><a class="dropdown-item" href="products.php?category=peripherals">Cooling & Lighting</a></li>
                            <li><a class="dropdown-item" href="products.php?category=desktops">Desktop Computer</a></li>
                            <li><a class="dropdown-item" href="products.php?category=graphics">Graphic Cards</a></li>
                            <li><a class="dropdown-item" href="products.php?category=peripherals">Keyboards & Mouse</a></li>
                            <li><a class="dropdown-item" href="products.php?category=laptops">Laptops</a></li>
                            <li><a class="dropdown-item" href="products.php?category=memory">Memory (RAM)</a></li>
                            <li><a class="dropdown-item" href="products.php?category=monitors">Monitors</a></li>
                            <li><a class="dropdown-item" href="products.php?category=motherboards">Motherboards</a></li>
                            <li><a class="dropdown-item" href="products.php?category=power">Power Supply</a></li>
                            <li><a class="dropdown-item" href="products.php?category=processors">Processors</a></li>
                            <li><a class="dropdown-item" href="products.php?category=audio">Speakers & Headset</a></li>
                            <li><a class="dropdown-item" href="products.php?category=storage">Storage</a></li>
                        </ul>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="">Rapidventure</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="contact.php">Contact</a>
                    </li>
                </ul>
                
                <div class="d-flex align-items-center">
                    <a href="cart.php" class="cart-icon">
                        <i class="fas fa-shopping-cart"></i>
                        <?php if ($cart_count > 0): ?>
                        <span class="cart-count"><?php echo $cart_count; ?></span>
                        <?php endif; ?>
                    </a>
                    
                    <span class="me-3"><?php echo $user_currency . ' ' . number_format($cart_total); ?></span>
                    
                    <?php if (isset($_SESSION['user_id'])): ?>
                        <div class="dropdown">
                            <button class="btn btn-login dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                <i class="fas fa-user"></i> Account
                            </button>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="profile.php">My Profile</a></li>
                                <li><a class="dropdown-item" href="orders.php">My Orders</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="logout.php">Logout</a></li>
                            </ul>
                        </div>
                    <?php else: ?>
                        <a href="/login.php" class="btn btn-login">
                            <i class="fas fa-sign-in-alt"></i> Login / Sign Up
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </nav>

    <!-- Page Header -->
    <section class="page-header">
        <div class="container">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                    <li class="breadcrumb-item active">Products</li>
                    <?php if ($category && $category !== 'all'): ?>
                    <li class="breadcrumb-item active"><?php echo htmlspecialchars($categories[$category] ?? ucfirst($category)); ?></li>
                    <?php endif; ?>
                </ol>
            </nav>
            <h1>
                <?php 
                if ($category && $category !== 'all') {
                    echo htmlspecialchars($categories[$category] ?? ucfirst($category));
                } elseif ($search) {
                    echo "Search Results";
                } else {
                    echo "All Products";
                }
                ?>
            </h1>
            <p class="lead">Discover our premium selection of electronics and computer equipment</p>
        </div>
    </section>

    <!-- Filters Section -->
    <section class="filters-section">
        <div class="container">
            <form method="GET" action="products.php" id="filterForm">
                <div class="row g-3">
                    <!-- Search -->
                    <div class="col-lg-3 col-md-6">
                        <div class="search-box">
                            <i class="fas fa-search"></i>
                            <input type="text" name="search" class="form-control" placeholder="Search products..." 
                                   value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                    </div>
                    
                    <!-- Category Filter -->
                    <div class="col-lg-2 col-md-6">
                        <select name="category" class="form-select" onchange="this.form.submit()">
                            <?php foreach ($categories as $cat_key => $cat_name): ?>
                            <option value="<?php echo htmlspecialchars($cat_key); ?>" <?php echo $category === $cat_key ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat_name); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- Price Range -->
                    <div class="col-lg-2 col-md-6">
                        <input type="number" name="price_min" class="form-control" placeholder="Min Price" 
                               value="<?php echo htmlspecialchars($price_min); ?>">
                    </div>
                    
                    <div class="col-lg-2 col-md-6">
                        <input type="number" name="price_max" class="form-control" placeholder="Max Price" 
                               value="<?php echo htmlspecialchars($price_max); ?>">
                    </div>
                    
                    <!-- Sort -->
                    <div class="col-lg-2 col-md-6">
                        <select name="sort" class="form-select" onchange="this.form.submit()">
                            <option value="name_asc" <?php echo $sort === 'name_asc' ? 'selected' : ''; ?>>Name A-Z</option>
                            <option value="price_low" <?php echo $sort === 'price_low' ? 'selected' : ''; ?>>Price Low-High</option>
                            <option value="price_high" <?php echo $sort === 'price_high' ? 'selected' : ''; ?>>Price High-Low</option>
                            <option value="rating" <?php echo $sort === 'rating' ? 'selected' : ''; ?>>Highest Rated</option>
                        </select>
                    </div>
                    
                    <!-- Clear Filters -->
                    <div class="col-lg-1 col-md-6">
                        <a href="products.php" class="btn btn-outline-secondary w-100" title="Clear Filters">
                            <i class="fas fa-times"></i>
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </section>

    <!-- Results Header -->
    <div class="container">
        <div class="results-header">
            <div class="d-flex justify-content-between align-items-center flex-wrap">
                <div>
                    <h5 class="mb-0">Showing <?php echo count($filtered_products); ?> of <?php echo $total_products; ?> products</h5>
                    <?php if ($search): ?>
                    <small class="text-muted">Search results for "<?php echo htmlspecialchars($search); ?>"</small>
                    <?php endif; ?>
                </div>
                <div class="d-flex align-items-center">
                    <button class="btn btn-outline-secondary me-2" onclick="toggleView('grid')" id="gridViewBtn">
                        <i class="fas fa-th"></i>
                    </button>
                    <button class="btn btn-outline-secondary" onclick="toggleView('list')" id="listViewBtn">
                        <i class="fas fa-list"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Products Section -->
    <section class="products-section">
        <div class="container">
            <?php if (empty($filtered_products)): ?>
            <div class="no-products">
                <i class="fas fa-search"></i>
                <h3>No products found</h3>
                <p class="text-muted">Try adjusting your search criteria or browse our categories</p>
                <a href="products.php" class="btn btn-primary btn-lg">
                    <i class="fas fa-arrow-left me-2"></i>View All Products
                </a>
            </div>
            <?php else: ?>
            <div class="row" id="productsGrid">
                <?php foreach ($filtered_products as $product): ?>
                <div class="col-lg-4 col-md-6 product-item">
                    <div class="product-card <?php echo !$product['in_stock'] ? 'out-of-stock-item' : ''; ?>">
                        <div class="product-image">
                            <?php 
                            $image_path = $product['image'];
                            if (strpos($image_path, '/uploads/') === 0) {
                                $image_path = ltrim($image_path, '/');
                            }
                            ?>
                            <img src="<?php echo htmlspecialchars($image_path); ?>" 
                                 alt="<?php echo htmlspecialchars($product['name']); ?>" 
                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <div style="display:none; width: 100%; height: 100%; align-items: center; justify-content: center;">
                                <i class="placeholder-icon fas fa-laptop"></i>
                            </div>
                            
                            <?php if (!$product['in_stock']): ?>
                                <span class="product-badge out-of-stock-badge" style="top: 10px; right: 10px;">
                                    Out of Stock
                                </span>
                            <?php elseif ($product['stock_count'] <= 5): ?>
                                <span class="product-badge low-stock-badge" style="top: 10px; right: 10px;">
                                    Only <?php echo $product['stock_count']; ?> left
                                </span>
                            <?php endif; ?>
                            
                            <?php 
                            if ($product['original_price'] > $product['price']): 
                                $discount = round((($product['original_price'] - $product['price']) / $product['original_price']) * 100);
                            ?>
                                <span class="product-badge" style="top: 10px; left: 10px; background: var(--success-color);">
                                    <?php echo $discount; ?>% OFF
                                </span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="product-info">
                            <div class="product-brand"><?php echo htmlspecialchars($product['brand']); ?></div>
                            <h5 class="product-title"><?php echo htmlspecialchars($product['name']); ?></h5>
                            
                            <div class="product-rating">
                                <div class="stars">
                                    <?php
                                    $rating = $product['rating'];
                                    for ($i = 1; $i <= 5; $i++) {
                                        if ($i <= floor($rating)) {
                                            echo '<i class="fas fa-star"></i>';
                                        } elseif ($i <= ceil($rating)) {
                                            echo '<i class="fas fa-star-half-alt"></i>';
                                        } else {
                                            echo '<i class="far fa-star"></i>';
                                        }
                                    }
                                    ?>
                                </div>
                                <span class="rating-text"><?php echo number_format($rating, 1); ?> (<?php echo $product['reviews']; ?>)</span>
                            </div>
                            
                            <?php if (!empty($product['specs']) && count($product['specs']) > 0): ?>
                            <div class="product-specs">
                                <?php 
                                $displayed_specs = array_slice($product['specs'], 0, 3);
                                foreach ($displayed_specs as $spec): 
                                ?>
                                <span class="spec-tag"><?php echo htmlspecialchars($spec); ?></span>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            
                            <div class="product-stock">
                                <?php if (!$product['in_stock']): ?>
                                    <span class="stock-info out-of-stock">
                                        <i class="fas fa-times-circle"></i> 
                                        Out of Stock
                                    </span>
                                <?php elseif ($product['stock_count'] <= 5): ?>
                                    <span class="stock-info low-stock">
                                        <i class="fas fa-exclamation-triangle"></i> 
                                        Only <?php echo $product['stock_count']; ?> left
                                    </span>
                                <?php elseif ($product['stock_count'] <= 20): ?>
                                    <span class="stock-info medium-stock">
                                        <i class="fas fa-box"></i> 
                                        <?php echo $product['stock_count']; ?> available
                                    </span>
                                <?php else: ?>
                                    <span class="stock-info high-stock">
                                        <i class="fas fa-check-circle"></i> 
                                        In Stock
                                    </span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="product-price">
                                <span class="current-price">LKR <?php echo number_format($product['price'], 2); ?></span>
                                <?php if ($product['original_price'] > $product['price']): ?>
                                <span class="original-price">LKR <?php echo number_format($product['original_price'], 2); ?></span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="product-actions">
                                <a href="product-details.php?id=<?php echo $product['id']; ?>" class="btn btn-view-details">
                                    <i class="fas fa-eye"></i> View Details
                                </a>
                                
                                <?php if ($product['in_stock'] && $product['stock_count'] > 0): ?>
                                <button class="btn btn-add-cart" onclick="addToCart(<?php echo $product['id']; ?>)" 
                                        data-product-id="<?php echo $product['id']; ?>" 
                                        data-in-stock="true">
                                    <i class="fas fa-shopping-cart"></i> Add to Cart
                                </button>
                                <?php else: ?>
                                <button class="btn btn-add-cart btn-out-of-stock" disabled>
                                    <i class="fas fa-ban"></i> Out of Stock
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="row mt-4">
                <div class="col-12">
                    <nav aria-label="Products pagination">
                        <ul class="pagination justify-content-center">
                            <!-- Previous Button -->
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?<?php echo buildQueryString($page - 1); ?>" <?php echo $page <= 1 ? 'tabindex="-1"' : ''; ?>>
                                    <i class="fas fa-chevron-left"></i> Previous
                                </a>
                            </li>
                            
                            <?php
                            $range = 2;
                            $start = max(1, $page - $range);
                            $end = min($total_pages, $page + $range);
                            
                            if ($start > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?<?php echo buildQueryString(1); ?>">1</a>
                                </li>
                                <?php if ($start > 2): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                                <?php endif; ?>
                            <?php endif; ?>
                            
                            <?php for ($i = $start; $i <= $end; $i++): ?>
                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?<?php echo buildQueryString($i); ?>"><?php echo $i; ?></a>
                            </li>
                            <?php endfor; ?>
                            
                            <?php if ($end < $total_pages): ?>
                                <?php if ($end < $total_pages - 1): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                                <?php endif; ?>
                                <li class="page-item">
                                    <a class="page-link" href="?<?php echo buildQueryString($total_pages); ?>"><?php echo $total_pages; ?></a>
                                </li>
                            <?php endif; ?>
                            
                            <!-- Next Button -->
                            <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?<?php echo buildQueryString($page + 1); ?>">
                                    Next <i class="fas fa-chevron-right"></i>
                                </a>
                            </li>
                        </ul>
                    </nav>
                </div>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </section>

    <!-- WhatsApp Button -->
    <a href="https://wa.me/your-whatsapp-number" class="whatsapp-btn" target="_blank" aria-label="Contact us on WhatsApp">
        <i class="fab fa-whatsapp"></i>
    </a>

    <!-- Footer -->
    <footer class="bg-dark text-light py-5">
        <div class="container">
            <div class="row">
                <div class="col-lg-4 mb-4">
                    <h5>IT Shop.LK</h5>
                    <p>Your trusted partner for electronics and computer equipment in Sri Lanka.</p>
                    <div class="social-links mt-3">
                        <a href="#" class="text-light me-3"><i class="fab fa-facebook fa-lg"></i></a>
                        <a href="#" class="text-light me-3"><i class="fab fa-instagram fa-lg"></i></a>
                        <a href="#" class="text-light me-3"><i class="fab fa-twitter fa-lg"></i></a>
                    </div>
                </div>
                
                <div class="col-lg-2 mb-4">
                    <h6>Quick Links</h6>
                    <ul class="list-unstyled">
                        <li class="mb-2"><a href="index.php" class="text-light text-decoration-none">Home</a></li>
                        <li class="mb-2"><a href="products.php" class="text-light text-decoration-none">Products</a></li>
                        <li class="mb-2"><a href="" class="text-light text-decoration-none">Rapidventure</a></li>
                        <li class="mb-2"><a href="contact.php" class="text-light text-decoration-none">Contact</a></li>
                    </ul>
                </div>
                
                <div class="col-lg-3 mb-4">
                    <h6>Categories</h6>
                    <ul class="list-unstyled">
                        <li class="mb-2"><a href="products.php?category=laptops" class="text-light text-decoration-none">Laptops</a></li>
                        <li class="mb-2"><a href="products.php?category=desktops" class="text-light text-decoration-none">Desktop PCs</a></li>
                        <li class="mb-2"><a href="products.php?category=graphics" class="text-light text-decoration-none">Graphics Cards</a></li>
                        <li class="mb-2"><a href="products.php?category=peripherals" class="text-light text-decoration-none">Accessories</a></li>
                    </ul>
                </div>
                
                <div class="col-lg-3 mb-4">
                    <h6>Contact Info</h6>
                    <p class="mb-2"><i class="fas fa-envelope me-2"></i>admin@itshop.lk</p>
                    <p class="mb-2"><i class="fas fa-phone me-2"></i>+94 077 900 5652</p>
                    <p class="mb-2"><i class="fas fa-map-marker-alt me-2"></i>Colombo, Sri Lanka</p>
                </div>
            </div>
            
            <hr class="my-4 bg-light">
            <div class="row align-items-center">
                <div class="col-md-6 text-center text-md-start">
                    <p class="mb-0">&copy; <?php echo date('Y'); ?> IT Shop.LK. All rights reserved.</p>
                </div>
                <div class="col-md-6 text-center text-md-end">
                    <a href="privacy.php" class="text-light me-3 text-decoration-none">Privacy Policy</a>
                    <a href="terms.php" class="text-light text-decoration-none">Terms of Service</a>
                </div>
            </div>
        </div>
    </footer>

    <!-- Bootstrap JS -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    
    <!-- Custom JavaScript -->
    <script>
// View toggle functionality
let currentView = 'grid';

function toggleView(view) {
    const grid = document.getElementById('productsGrid');
    const gridBtn = document.getElementById('gridViewBtn');
    const listBtn = document.getElementById('listViewBtn');
    
    if (view === 'list' && currentView !== 'list') {
        grid.querySelectorAll('.product-item').forEach(item => {
            item.className = 'col-12 product-item mb-3';
            const card = item.querySelector('.product-card');
            card.style.display = 'flex';
            card.style.flexDirection = 'row';
            card.style.alignItems = 'center';
            
            const image = card.querySelector('.product-image');
            image.style.width = '200px';
            image.style.height = '180px';
            image.style.marginRight = '2rem';
            image.style.marginBottom = '0';
            image.style.flexShrink = '0';
            
            const info = card.querySelector('.product-info');
            info.style.flex = '1';
        });
        
        listBtn.classList.add('btn-primary');
        listBtn.classList.remove('btn-outline-secondary');
        gridBtn.classList.add('btn-outline-secondary');
        gridBtn.classList.remove('btn-primary');
        currentView = 'list';
        
    } else if (view === 'grid' && currentView !== 'grid') {
        grid.querySelectorAll('.product-item').forEach(item => {
            item.className = 'col-lg-4 col-md-6 product-item';
            const card = item.querySelector('.product-card');
            card.style.display = 'flex';
            card.style.flexDirection = 'column';
            
            const image = card.querySelector('.product-image');
            image.style.width = 'auto';
            image.style.height = '220px';
            image.style.marginRight = '0';
            image.style.marginBottom = '1rem';
            image.style.flexShrink = 'unset';
            
            const info = card.querySelector('.product-info');
            info.style.flex = '1';
        });
        
        gridBtn.classList.add('btn-primary');
        gridBtn.classList.remove('btn-outline-secondary');
        listBtn.classList.add('btn-outline-secondary');
        listBtn.classList.remove('btn-primary');
        currentView = 'grid';
    }
}

// Add to cart functionality
function addToCart(productId, quantity = 1) {
    const button = event ? event.target.closest('button') : document.querySelector(`[data-product-id="${productId}"]`);
    if (!button || button.disabled) {
        showToast('This product is currently out of stock', 'error');
        return;
    }
    
    const inStock = button.getAttribute('data-in-stock');
    if (inStock !== 'true') {
        showToast('This product is currently out of stock', 'error');
        return;
    }
    
    const originalText = button.innerHTML;
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Adding...';
    button.disabled = true;
    
    fetch('add-to-cart.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            product_id: productId,
            quantity: quantity
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            button.innerHTML = '<i class="fas fa-check"></i> Added!';
            button.classList.add('btn-success');
            button.classList.remove('btn-add-cart');
            
            updateCartDisplay(data.cart_count, data.cart_total);
            showToast(data.message || 'Product added to cart successfully!', 'success');
            
            setTimeout(() => {
                button.innerHTML = originalText;
                button.classList.remove('btn-success');
                button.classList.add('btn-add-cart');
                button.disabled = false;
            }, 2000);
        } else {
            if (data.redirect) {
                showToast('Please login to add items to cart', 'error');
                setTimeout(() => {
                    window.location.href = data.redirect;
                }, 1500);
                return;
            }
            
            if (data.message && data.message.toLowerCase().includes('stock')) {
                showToast(data.message, 'error');
                button.innerHTML = '<i class="fas fa-ban"></i> Out of Stock';
                button.disabled = true;
            } else {
                showToast(data.message || 'Error adding product to cart', 'error');
                button.innerHTML = originalText;
                button.disabled = false;
            }
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Network error. Please try again.', 'error');
        button.innerHTML = originalText;
        button.disabled = false;
    });
}

// Update cart display
function updateCartDisplay(cartCount, cartTotal) {
    const cartCountElement = document.querySelector('.cart-count');
    const cartIcon = document.querySelector('.cart-icon');
    
    if (cartCount > 0) {
        if (cartCountElement) {
            cartCountElement.textContent = cartCount;
        } else if (cartIcon) {
            const badge = document.createElement('span');
            badge.className = 'cart-count';
            badge.textContent = cartCount;
            cartIcon.appendChild(badge);
        }
    } else if (cartCountElement) {
        cartCountElement.remove();
    }
    
    const cartTotalElements = document.querySelectorAll('.me-3');
    cartTotalElements.forEach(element => {
        if (element.textContent.includes('LKR') && cartTotal !== undefined) {
            element.textContent = 'LKR ' + new Intl.NumberFormat().format(cartTotal);
        }
    });
}

// Toast notification
function showToast(message, type = 'info', duration = 4000) {
    const existingToasts = document.querySelectorAll('.custom-toast');
    existingToasts.forEach(toast => toast.remove());
    
    const toast = document.createElement('div');
    toast.className = `alert alert-${type === 'success' ? 'success' : type === 'error' ? 'danger' : 'info'} alert-dismissible custom-toast`;
    toast.style.cssText = `
        position: fixed;
        top: 100px;
        right: 20px;
        z-index: 9999;
        min-width: 300px;
        max-width: 400px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        border-radius: 8px;
        animation: slideInRight 0.3s ease-out;
    `;
    
    toast.innerHTML = `
        <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'} me-2"></i>
        ${message}
        <button type="button" class="btn-close" aria-label="Close"></button>
    `;
    
    if (!document.querySelector('#toast-animations')) {
        const style = document.createElement('style');
        style.id = 'toast-animations';
        style.textContent = `
            @keyframes slideInRight {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
            @keyframes slideOutRight {
                from { transform: translateX(0); opacity: 1; }
                to { transform: translateX(100%); opacity: 0; }
            }
        `;
        document.head.appendChild(style);
    }
    
    document.body.appendChild(toast);
    
    const autoRemove = setTimeout(() => {
        toast.style.animation = 'slideOutRight 0.3s ease-in';
        setTimeout(() => toast.remove(), 300);
    }, duration);
    
    toast.querySelector('.btn-close').addEventListener('click', () => {
        clearTimeout(autoRemove);
        toast.style.animation = 'slideOutRight 0.3s ease-in';
        setTimeout(() => toast.remove(), 300);
    });
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    const gridBtn = document.getElementById('gridViewBtn');
    const listBtn = document.getElementById('listViewBtn');
    
    if (gridBtn && listBtn) {
        gridBtn.classList.add('btn-primary');
        gridBtn.classList.remove('btn-outline-secondary');
    }
    
    // Smooth scroll animation for product cards
    const observerOptions = {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px'
    };

    const observer = new IntersectionObserver(function(entries) {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
            }
        });
    }, observerOptions);

    document.querySelectorAll('.product-card').forEach(card => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(30px)';
        card.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
        observer.observe(card);
    });

    // Price validation
    const priceMinInput = document.querySelector('input[name="price_min"]');
    const priceMaxInput = document.querySelector('input[name="price_max"]');

    if (priceMinInput && priceMaxInput) {
        priceMinInput.addEventListener('change', function() {
            if (priceMaxInput.value && parseInt(this.value) > parseInt(priceMaxInput.value)) {
                showToast('Minimum price cannot be greater than maximum price', 'error');
                this.value = '';
            }
        });

        priceMaxInput.addEventListener('change', function() {
            if (priceMinInput.value && parseInt(this.value) < parseInt(priceMinInput.value)) {
                showToast('Maximum price cannot be less than minimum price', 'error');
                this.value = '';
            }
        });
    }

    // Navbar scroll effect
    const navbar = document.querySelector('.navbar');
    if (navbar) {
        window.addEventListener('scroll', function() {
            if (window.scrollY > 50) {
                navbar.style.background = 'rgba(255, 255, 255, 0.98)';
                navbar.style.backdropFilter = 'blur(10px)';
            } else {
                navbar.style.background = 'white';
            }
        });
    }
});
    </script>
</body>
</html>