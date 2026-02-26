<?php
// Start session for user management
session_start();

// Database connection
include 'db.php';

// Redirect to login if user is not logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=cart.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$user_currency = 'LKR';
$cart_items = [];
$cart_total = 0;
$cart_count = 0;
$subtotal = 0;
$shipping_cost = 500; // Fixed shipping cost

// Handle cart actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $product_id = (int)($_POST['product_id'] ?? 0);
    $quantity = (int)($_POST['quantity'] ?? 1);
    
    try {
        switch ($action) {
            case 'update_quantity':
                if ($quantity > 0) {
                    $stmt = $pdo->prepare("UPDATE cart SET quantity = ? WHERE user_id = ? AND product_id = ?");
                    $stmt->execute([$quantity, $user_id, $product_id]);
                } else {
                    $stmt = $pdo->prepare("DELETE FROM cart WHERE user_id = ? AND product_id = ?");
                    $stmt->execute([$user_id, $product_id]);
                }
                break;
                
            case 'remove_item':
                $stmt = $pdo->prepare("DELETE FROM cart WHERE user_id = ? AND product_id = ?");
                $stmt->execute([$user_id, $product_id]);
                break;
                
            case 'clear_cart':
                $stmt = $pdo->prepare("DELETE FROM cart WHERE user_id = ?");
                $stmt->execute([$user_id]);
                break;
        }
        
        // Redirect to prevent form resubmission
        header('Location: cart.php');
        exit();
        
    } catch(PDOException $e) {
        $error_message = "Error updating cart: " . $e->getMessage();
    }
}

// Fetch cart items with product details
try {
    $stmt = $pdo->prepare("
        SELECT 
            c.product_id,
            c.quantity,
            c.price as cart_price,
            p.name,
            p.brand,
            p.image,
            p.price as current_price,
            p.original_price,
            p.stock_count,
            p.in_stock,
            p.category
        FROM cart c
        JOIN products p ON c.product_id = p.id
        WHERE c.user_id = ?
        ORDER BY c.created_at DESC
    ");
    $stmt->execute([$user_id]);
    $cart_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate totals
    foreach ($cart_items as &$item) {
        $item_total = $item['current_price'] * $item['quantity'];
        $item['item_total'] = $item_total;
        $subtotal += $item_total;
        $cart_count += $item['quantity'];
        
        // Check if price has changed since adding to cart
        $item['price_changed'] = ($item['cart_price'] != $item['current_price']);
        
        // Check stock availability
        $item['sufficient_stock'] = ($item['in_stock'] && $item['stock_count'] >= $item['quantity']);
    }
    
    $tax_amount = $subtotal * $tax_rate;
    $cart_total = $subtotal + $tax_amount + ($subtotal > 0 ? $shipping_cost : 0);
    
} catch(PDOException $e) {
    $error_message = "Error fetching cart items: " . $e->getMessage();
    $cart_items = [];
}

// Get recommended products
$recommended_products = [];
try {
    if (!empty($cart_items)) {
        // Get products from same categories as cart items
        $categories = array_unique(array_column($cart_items, 'category'));
        $placeholders = str_repeat('?,', count($categories) - 1) . '?';
        
        $stmt = $pdo->prepare("
            SELECT id, name, brand, price, original_price, image, rating, category
            FROM products 
            WHERE category IN ($placeholders) AND in_stock = 1 
            AND id NOT IN (SELECT product_id FROM cart WHERE user_id = ?)
            ORDER BY rating DESC, reviews DESC 
            LIMIT 4
        ");
        $stmt->execute(array_merge($categories, [$user_id]));
        $recommended_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch(PDOException $e) {
    // Fallback to popular products if query fails
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shopping Cart - STC Electronics Store</title>
    <meta name="description" content="Review your selected items and proceed to checkout">
    
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
            --warning-color: #d69e2e;
            --danger-color: #e53e3e;
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
            background: #0a6400ff;
            color: white;
        }
        
        /* Page Header */
        .page-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, #008cffff 100%);
            color: white;
            padding: 8rem 0 4rem;
            margin-top: 80px;
        }
        
        .page-header h1 {
            font-size: 3rem;
            font-weight: 700;
            margin-bottom: 1rem;
        }
        
        .breadcrumb {
            background: transparent;
            padding: 0;
        }
        
        .breadcrumb-item a {
            color: rgba(255,255,255,0.8);
            text-decoration: none;
        }
        
        .breadcrumb-item.active {
            color: white;
        }
        
        /* Cart Styles */
        .cart-section {
            padding: 2rem 0;
        }
        
        .cart-item {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .cart-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.12);
        }
        
        .cart-item-image {
            width: 120px;
            height: 120px;
            background: var(--bg-light);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
        }
        
        .cart-item-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 10px;
        }
        
        .cart-item-image .placeholder-icon {
            font-size: 3rem;
            color: var(--text-light);
        }
        
        .cart-item-details {
            flex: 1;
            margin-left: 1.5rem;
        }
        
        .item-title {
            font-weight: 600;
            font-size: 1.2rem;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
        }
        
        .item-brand {
            color: var(--text-light);
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }
        
        .item-price {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 1rem;
        }
        
        .price-changed-notice {
            background: var(--warning-color);
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 5px;
            font-size: 0.75rem;
            margin-left: 0.5rem;
        }
        
        .quantity-controls {
            display: flex;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .quantity-btn {
            background: var(--bg-light);
            border: none;
            width: 35px;
            height: 35px;
            border-radius: 5px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }
        
        .quantity-btn:hover {
            background: var(--primary-color);
            color: white;
        }
        
        .quantity-input {
            width: 60px;
            text-align: center;
            border: 1px solid #ddd;
            margin: 0 0.5rem;
            height: 35px;
            border-radius: 5px;
        }
        
        .item-total {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 1rem;
        }
        
        .stock-warning {
            background: rgba(220, 53, 69, 0.1);
            color: var(--danger-color);
            padding: 0.5rem;
            border-radius: 5px;
            margin-bottom: 1rem;
            font-size: 0.9rem;
        }
        
        .btn-remove {
            background: transparent;
            color: var(--danger-color);
            border: 1px solid var(--danger-color);
            padding: 0.5rem 1rem;
            border-radius: 5px;
            transition: all 0.3s ease;
        }
        
        .btn-remove:hover {
            background: var(--danger-color);
            color: white;
        }
        
        /* Cart Summary */
        .cart-summary {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            position: sticky;
            top: 100px;
        }
        
        .summary-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            color: var(--text-dark);
        }
        
        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 1rem;
            padding: 0.5rem 0;
        }
        
        .summary-row.total {
            border-top: 2px solid var(--bg-light);
            padding-top: 1rem;
            margin-top: 1rem;
            font-weight: 700;
            font-size: 1.2rem;
        }
        
        .btn-checkout {
            background: var(--primary-color);
            color: white;
            border: none;
            width: 100%;
            padding: 1rem;
            border-radius: 8px;
            font-weight: 600;
            font-size: 1.1rem;
            margin-top: 1.5rem;
            transition: background 0.3s ease;
        }
        
        .btn-checkout:hover {
            background: #015603ff;
            color: white;
        }
        
        .btn-continue-shopping {
            color: var(--secondary-color);
            border: 1px solid var(--secondary-color);
            width: 100%;
            padding: 0.75rem;
            border-radius: 8px;
            font-weight: 500;
            margin-top: 1rem;
            transition: all 0.3s ease;
        }
        
        .btn-continue-shopping:hover {
            background: var(--secondary-color);
            color: white;
        }
        
        /* Empty Cart */
        .empty-cart {
            text-align: center;
            padding: 4rem 2rem;
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        }
        
        .empty-cart-icon {
            font-size: 4rem;
            color: var(--text-light);
            margin-bottom: 2rem;
        }
        
        /* Recommended Products */
        .recommended-section {
            margin-top: 3rem;
        }
        
        .recommended-item {
            background: white;
            border-radius: 10px;
            padding: 1rem;
            text-align: center;
            transition: transform 0.3s ease;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .recommended-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        
        .recommended-image {
            height: 120px;
            background: var(--bg-light);
            border-radius: 8px;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        
        .recommended-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 8px;
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .page-header h1 {
                font-size: 2rem;
            }
            
            .cart-item {
                flex-direction: column;
                text-align: center;
            }
            
            .cart-item-image {
                margin: 0 auto 1rem;
            }
            
            .cart-item-details {
                margin-left: 0;
            }
            
            .quantity-controls {
                justify-content: center;
            }
            
            .cart-summary {
                position: relative;
                top: auto;
                margin-top: 2rem;
            }
        }
        
        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .fade-in {
            animation: fadeIn 0.6s ease-out;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        .pulse {
            animation: pulse 0.3s ease-out;
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
                        <a class="nav-link" href="index.php">Home</a>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="productsDropdown" role="button" data-bs-toggle="dropdown">
                            Products
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="products.php?category=products">All Products</a></li>
                            <li><a class="dropdown-item" href="products.php?category=desktops">Casing</a></li>
                            <li><a class="dropdown-item" href="products.php?category=accessories">Cooling & Lighting</a></li>
                            <li><a class="dropdown-item" href="products.php?category=desktops">Desktop Computer</a></li>
                            <li><a class="dropdown-item" href="products.php?category=graphics">Graphic Cards</a></li>
                            <li><a class="dropdown-item" href="products.php?category=accessories">Keyboards & Mouse</a></li>
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
                        <a class="nav-link" href="services.php">Rapidventure</a>
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
                        <a href="login.php" class="btn btn-login">
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
                    <li class="breadcrumb-item active">Shopping Cart</li>
                </ol>
            </nav>
            <h1>Shopping Cart</h1>
            <p class="lead">Review your items and proceed to checkout</p>
        </div>
    </section>

    <!-- Cart Section -->
    <section class="cart-section">
        <div class="container">
            <?php if (isset($error_message)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <?php if (empty($cart_items)): ?>
            <!-- Empty Cart -->
            <div class="empty-cart fade-in">
                <i class="fas fa-shopping-cart empty-cart-icon"></i>
                <h3>Your cart is empty</h3>
                <p class="text-muted mb-4">Looks like you haven't added anything to your cart yet</p>
                <a href="products.php" class="btn btn-primary btn-lg">
                    <i class="fas fa-shopping-bag me-2"></i>Start Shopping
                </a>
            </div>
            <?php else: ?>
            
            <div class="row">
                <!-- Cart Items -->
                <div class="col-lg-8">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h4>Cart Items (<?php echo $cart_count; ?>)</h4>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="action" value="clear_cart">
                            <button type="submit" class="btn btn-outline-danger btn-sm" 
                                    onclick="return confirm('Are you sure you want to clear your cart?')">
                                <i class="fas fa-trash me-1"></i>Clear Cart
                            </button>
                        </form>
                    </div>
                    
                    <?php foreach ($cart_items as $item): ?>
                    <div class="cart-item d-flex fade-in">
                        <!-- Product Image -->
                        <div class="cart-item-image">
                            <img src="<?php echo htmlspecialchars($item['image']); ?>" 
                                 alt="<?php echo htmlspecialchars($item['name']); ?>" 
                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <div style="display:none; width: 100%; height: 100%; align-items: center; justify-content: center;">
                                <i class="placeholder-icon fas fa-laptop"></i>
                            </div>
                        </div>
                        
                        <!-- Product Details -->
                        <div class="cart-item-details">
                            <h5 class="item-title"><?php echo htmlspecialchars($item['name']); ?></h5>
                            <div class="item-brand"><?php echo htmlspecialchars($item['brand']); ?></div>
                            
                            <div class="item-price">
                                <?php echo $user_currency . ' ' . number_format($item['current_price']); ?>
                                <?php if ($item['price_changed']): ?>
                                <span class="price-changed-notice">Price Updated</span>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Stock Warning -->
                            <?php if (!$item['sufficient_stock']): ?>
                            <div class="stock-warning">
                                <i class="fas fa-exclamation-triangle me-1"></i>
                                <?php if (!$item['in_stock']): ?>
                                    This item is currently out of stock
                                <?php else: ?>
                                    Only <?php echo $item['stock_count']; ?> available (you have <?php echo $item['quantity']; ?> in cart)
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Quantity Controls -->
                            <div class="quantity-controls">
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="update_quantity">
                                    <input type="hidden" name="product_id" value="<?php echo $item['product_id']; ?>">
                                    <button type="submit" name="quantity" value="<?php echo max(1, $item['quantity'] - 1); ?>" 
                                            class="quantity-btn" <?php echo $item['quantity'] <= 1 ? 'disabled' : ''; ?>>
                                        <i class="fas fa-minus"></i>
                                    </button>
                                </form>
                                
                                <input type="number" class="quantity-input" value="<?php echo $item['quantity']; ?>" 
                                       min="1" max="<?php echo $item['stock_count']; ?>"
                                       onchange="updateQuantity(<?php echo $item['product_id']; ?>, this.value)">
                                
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="update_quantity">
                                    <input type="hidden" name="product_id" value="<?php echo $item['product_id']; ?>">
                                    <button type="submit" name="quantity" value="<?php echo $item['quantity'] + 1; ?>" 
                                            class="quantity-btn"
                                            <?php echo ($item['quantity'] >= $item['stock_count']) ? 'disabled' : ''; ?>>
                                        <i class="fas fa-plus"></i>
                                    </button>
                                </form>
                            </div>
                            
                            <div class="item-total">
                                Total: <?php echo $user_currency . ' ' . number_format($item['item_total']); ?>
                            </div>
                            
                            <!-- Remove Button -->
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="remove_item">
                                <input type="hidden" name="product_id" value="<?php echo $item['product_id']; ?>">
                                <button type="submit" class="btn btn-remove"
                                        onclick="return confirm('Remove this item from cart?')">
                                    <i class="fas fa-trash me-1"></i>Remove
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Cart Summary -->
                <div class="col-lg-4">
                    <div class="cart-summary">
                        <h4 class="summary-title">Order Summary</h4>
                        
                        <div class="summary-row">
                            <span>Subtotal (<?php echo $cart_count; ?> items)</span>
                            <span><?php echo $user_currency . ' ' . number_format($subtotal); ?></span>
                        </div>
                        
                        <div class="summary-row">
                            <span>Shipping</span>
                            <span><?php echo $user_currency . ' ' . number_format($shipping_cost); ?></span>
                        </div>
                        
                        <div class="summary-row total">
                            <span>Total</span>
                            <span><?php echo $user_currency . ' ' . number_format($cart_total); ?></span>
                        </div>
                        
                        <!-- Checkout Buttons -->
                        <button class="btn btn-checkout" onclick="proceedToCheckout()">
                            <i class="fas fa-credit-card me-2"></i>Proceed to Checkout
                        </button>
                        
                        <a href="products.php" class="btn btn-continue-shopping">
                            <i class="fas fa-arrow-left me-2"></i>Continue Shopping
                        </a>

                        <a href="quotation.php" class="btn btn-continue-shopping">
                            Get Quotation <i class="fas fa-arrow-right me-2"></i>
                        </a>
                        
                        <!-- Payment Methods -->
                        <div class="mt-3">
                            <small class="text-muted">We accept:</small><br>
                            <i class="fab fa-cc-visa fa-2x me-2 text-primary"></i>
                            <i class="fab fa-cc-mastercard fa-2x me-2 text-warning"></i>
                            <i class="fas fa-mobile-alt fa-2x me-2 text-success"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Recommended Products -->
            <?php if (!empty($recommended_products)): ?>
            <div class="recommended-section">
                <h4 class="mb-4">You might also like</h4>
                <div class="row">
                    <?php foreach ($recommended_products as $product): ?>
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="recommended-item">
                            <div class="recommended-image">
                                <img src="<?php echo htmlspecialchars($product['image']); ?>" 
                                     alt="<?php echo htmlspecialchars($product['name']); ?>"
                                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                <div style="display:none; width: 100%; height: 100%; align-items: center; justify-content: center;">
                                    <i class="fas fa-laptop fa-2x text-muted"></i>
                                </div>
                            </div>
                            <h6><?php echo htmlspecialchars($product['name']); ?></h6>
                            <div class="text-muted small"><?php echo htmlspecialchars($product['brand']); ?></div>
                            <div class="text-primary fw-bold">
                                <?php echo $user_currency . ' ' . number_format($product['price']); ?>
                            </div>
                            <button class="btn btn-outline-primary btn-sm mt-2" 
                                    onclick="addToCart(<?php echo $product['id']; ?>)">
                                Add to Cart
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <?php endif; ?>
        </div>
    </section>

    <!-- WhatsApp Button -->
    <a href="https://wa.me/your-whatsapp-number" class="whatsapp-btn" target="_blank" aria-label="Contact us on WhatsApp" style="position: fixed; bottom: 20px; right: 20px; background: #25d366; color: white; border-radius: 50%; width: 60px; height: 60px; display: flex; align-items: center; justify-content: center; font-size: 30px; text-decoration: none; box-shadow: 0 4px 12px rgba(37, 211, 102, 0.4); z-index: 1000; transition: all 0.3s ease;">
        <i class="fab fa-whatsapp"></i>
    </a>

    <!-- Footer -->
    <footer class="bg-dark text-light py-5">
        <div class="container">
            <div class="row">
                <div class="col-lg-4 mb-4">
                    <h5>IT Shop.LK</h5>
                    <p>Your trusted partner for electronics and computer equipment in Sri Lanka.</p>
                    <div class="social-links">
                        <a href="#" class="text-light me-3"><i class="fab fa-facebook"></i></a>
                        <a href="#" class="text-light me-3"><i class="fab fa-instagram"></i></a>
                        <a href="#" class="text-light me-3"><i class="fab fa-twitter"></i></a>
                    </div>
                </div>
                
                <div class="col-lg-2 mb-4">
                    <h6>Quick Links</h6>
                    <ul class="list-unstyled">
                        <li><a href="index.php" class="text-light">Home</a></li>
                        <li><a href="products.php" class="text-light">Products</a></li>
                        <li><a href="" class="text-light">Rapidventure</a></li>
                        <li><a href="contact.php" class="text-light">Contact</a></li>
                    </ul>
                </div>
                
                <div class="col-lg-3 mb-4">
                    <h6>Categories</h6>
                    <ul class="list-unstyled">
                        <li><a href="products.php?category=laptops" class="text-light">Laptops</a></li>
                        <li><a href="products.php?category=desktops" class="text-light">Desktop PCs</a></li>
                        <li><a href="products.php?category=components" class="text-light">Components</a></li>
                        <li><a href="products.php?category=accessories" class="text-light">Accessories</a></li>
                    </ul>
                </div>
                
                <div class="col-lg-3 mb-4">
                    <h6>Contact Info</h6>
                    <p><i class="fas fa-map-marker-alt"></i> admin@itshop.lk</p>
                    <p><i class="fas fa-phone"></i> +94 077 900 5652</p>
                    <p><i class="fas fa-envelope"></i> info@itshop.lk</p>
                </div>
            </div>
            
            <hr class="my-4">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <p>&copy; <?php echo date('Y'); ?> IT Shop.LK All rights reserved.</p>
                </div>
                <div class="col-md-6 text-md-end">
                    <a href="privacy.php" class="text-light me-3">Privacy Policy</a>
                    <a href="terms.php" class="text-light">Terms of Service</a>
                </div>
            </div>
        </div>
    </footer>

    <!-- Bootstrap JS -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    
    <!-- Custom JavaScript -->
    <script>
// Update quantity function
function updateQuantity(productId, quantity) {
    const qty = parseInt(quantity);
    if (isNaN(qty) || qty < 1) {
        alert('Please enter a valid quantity');
        return;
    }
    
    // Create and submit form
    const form = document.createElement('form');
    form.method = 'POST';
    form.style.display = 'none';
    
    const actionInput = document.createElement('input');
    actionInput.type = 'hidden';
    actionInput.name = 'action';
    actionInput.value = 'update_quantity';
    
    const productInput = document.createElement('input');
    productInput.type = 'hidden';
    productInput.name = 'product_id';
    productInput.value = productId;
    
    const quantityInput = document.createElement('input');
    quantityInput.type = 'hidden';
    quantityInput.name = 'quantity';
    quantityInput.value = qty;
    
    form.appendChild(actionInput);
    form.appendChild(productInput);
    form.appendChild(quantityInput);
    document.body.appendChild(form);
    form.submit();
}

// Add to cart function for recommended products
function addToCart(productId, quantity = 1) {
    const button = event ? event.target : document.querySelector(`[onclick*="${productId}"]`);
    if (!button) return;
    
    const originalText = button.innerHTML;
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Adding...';
    button.disabled = true;
    
    // AJAX call to add product to cart
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
            button.classList.remove('btn-outline-primary');
            
            // Show success message
            showToast('Product added to cart!', 'success');
            
            // Reload page after short delay to update cart
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        } else {
            if (data.redirect) {
                alert('Please login to add items to cart');
                window.location.href = data.redirect;
                return;
            }
            
            showToast(data.message || 'Error adding product to cart', 'error');
            button.innerHTML = originalText;
            button.disabled = false;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Network error. Please try again.', 'error');
        button.innerHTML = originalText;
        button.disabled = false;
    });
}

// Proceed to checkout
function proceedToCheckout() {
    // Check if there are any out-of-stock items
    const stockWarnings = document.querySelectorAll('.stock-warning');
    if (stockWarnings.length > 0) {
        if (!confirm('Some items in your cart have stock issues. Would you like to proceed anyway?')) {
            return;
        }
    }
    
    // Show loading state
    const checkoutBtn = document.querySelector('.btn-checkout');
    const originalText = checkoutBtn.innerHTML;
    checkoutBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Processing...';
    checkoutBtn.disabled = true;
    
    // Redirect to checkout page
    setTimeout(() => {
        window.location.href = 'checkout.php';
    }, 1000);
}

// Toast notification function
function showToast(message, type = 'info', duration = 4000) {
    // Remove existing toasts
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
    
    // Add CSS animation if not already added
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

// Smooth animations on page load
document.addEventListener('DOMContentLoaded', function() {
    // Add fade-in animation to cart items
    const cartItems = document.querySelectorAll('.cart-item');
    cartItems.forEach((item, index) => {
        setTimeout(() => {
            item.style.opacity = '1';
            item.style.transform = 'translateY(0)';
        }, index * 100);
    });
    
    // Initialize tooltips if needed
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Auto-save quantity changes
    const quantityInputs = document.querySelectorAll('.quantity-input');
    quantityInputs.forEach(input => {
        let timeoutId;
        input.addEventListener('input', function() {
            clearTimeout(timeoutId);
            timeoutId = setTimeout(() => {
                const productId = this.getAttribute('data-product-id') || 
                    this.closest('.cart-item').querySelector('input[name="product_id"]').value;
                updateQuantity(productId, this.value);
            }, 1000); // Auto-save after 1 second of inactivity
        });
    });
    
    // Add keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Press 'C' to continue shopping
        if (e.key === 'c' && !e.ctrlKey && !e.metaKey && document.activeElement.tagName !== 'INPUT') {
            e.preventDefault();
            window.location.href = 'products.php';
        }
        
        // Press Enter on checkout button when focused
        if (e.key === 'Enter' && document.activeElement.classList.contains('btn-checkout')) {
            e.preventDefault();
            proceedToCheckout();
        }
    });
});

// Auto-update cart summary when quantities change
function updateCartSummary() {
    // This would typically be done server-side, but for demo purposes
    // you could implement client-side calculation here
    console.log('Cart summary updated');
}

// Local storage for cart persistence (optional)
function saveCartToStorage() {
    // Implementation for saving cart state to localStorage
    // Useful for guest users or as backup
}

function loadCartFromStorage() {
    // Implementation for loading cart state from localStorage
}

// Print receipt function
function printReceipt() {
    window.print();
}

// Share cart function
function shareCart() {
    if (navigator.share) {
        navigator.share({
            title: 'IT Shop.LK Cart',
            text: 'Check out my shopping cart at IT Shop.LK!',
            url: window.location.href
        });
    } else {
        // Fallback - copy to clipboard
        navigator.clipboard.writeText(window.location.href)
            .then(() => showToast('Cart link copied to clipboard!', 'success'));
    }
}
    </script>
</body>
</html>