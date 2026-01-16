<?php
// Start session for user management
session_start();

// Database connection
include 'db.php';

// Redirect to login if user is not logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=checkout.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$user_currency = 'LKR';
$cart_items = [];
$cart_total = 0;
$cart_count = 0;
$subtotal = 0;
$shipping_cost = 500; // Fixed shipping cost

// Handle form submissions
$errors = [];
$success_message = '';
$order_placed = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'place_order') {
        // Validate required fields
        $required_fields = [
            'billing_first_name', 'billing_last_name', 'billing_email',
            'billing_phone', 'billing_address', 'billing_city', 'billing_postal_code',
            'payment_method'
        ];
        
        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                $errors[] = ucwords(str_replace('_', ' ', $field)) . ' is required';
            }
        }
        
        // Validate email
        if (!empty($_POST['billing_email']) && !filter_var($_POST['billing_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address';
        }
        
        // Validate phone
        if (!empty($_POST['billing_phone']) && !preg_match('/^[0-9+\-\s()]{10,15}$/', $_POST['billing_phone'])) {
            $errors[] = 'Please enter a valid phone number';
        }
        
        // If no errors, process the order
        if (empty($errors)) {
            try {
                // Recalculate totals before placing order
                $stmt = $pdo->prepare("
                    SELECT 
                        SUM(c.quantity * p.price) as subtotal,
                        SUM(c.quantity) as total_items
                    FROM cart c
                    JOIN products p ON c.product_id = p.id
                    WHERE c.user_id = ?
                ");
                $stmt->execute([$user_id]);
                $cart_totals = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $subtotal = $cart_totals['subtotal'] ?? 0;
                $cart_count = $cart_totals['total_items'] ?? 0;
                
                if ($subtotal <= 0) {
                    $errors[] = 'Your cart is empty';
                } else {
                    $pdo->beginTransaction();
                    
                    $cart_total = $subtotal + $shipping_cost;
                    
                    // Create order record
                    $order_number = 'ORD-' . date('Y') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO orders (
                            user_id, order_number, status, subtotal, 
                            shipping_cost, total_amount, currency, payment_method, 
                            billing_first_name, billing_last_name, billing_email, 
                            billing_phone, billing_address, billing_city, billing_postal_code,
                            shipping_first_name, shipping_last_name, shipping_address,
                            shipping_city, shipping_postal_code, special_instructions,
                            created_at
                        ) VALUES (?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    
                    $use_different_shipping = !empty($_POST['different_shipping']);
                    
                    $stmt->execute([
                        $user_id, $order_number, $subtotal,
                        $shipping_cost, $cart_total, $user_currency, $_POST['payment_method'],
                        $_POST['billing_first_name'], $_POST['billing_last_name'], 
                        $_POST['billing_email'], $_POST['billing_phone'], 
                        $_POST['billing_address'], $_POST['billing_city'], 
                        $_POST['billing_postal_code'],
                        $use_different_shipping ? $_POST['shipping_first_name'] : $_POST['billing_first_name'],
                        $use_different_shipping ? $_POST['shipping_last_name'] : $_POST['billing_last_name'],
                        $use_different_shipping ? $_POST['shipping_address'] : $_POST['billing_address'],
                        $use_different_shipping ? $_POST['shipping_city'] : $_POST['billing_city'],
                        $use_different_shipping ? $_POST['shipping_postal_code'] : $_POST['billing_postal_code'],
                        $_POST['special_instructions'] ?? ''
                    ]);
                    
                    $order_id = $pdo->lastInsertId();
                    
                    // Add order items from cart
                    $stmt = $pdo->prepare("
                        INSERT INTO order_items (order_id, product_id, quantity, price, total)
                        SELECT ?, c.product_id, c.quantity, p.price, (c.quantity * p.price)
                        FROM cart c
                        JOIN products p ON c.product_id = p.id
                        WHERE c.user_id = ?
                    ");
                    $stmt->execute([$order_id, $user_id]);
                    
                    // Update product stock
                    $stmt = $pdo->prepare("
                        UPDATE products p
                        JOIN cart c ON p.id = c.product_id
                        SET p.stock_count = p.stock_count - c.quantity
                        WHERE c.user_id = ? AND p.stock_count >= c.quantity
                    ");
                    $stmt->execute([$user_id]);
                    
                    // Clear cart
                    $stmt = $pdo->prepare("DELETE FROM cart WHERE user_id = ?");
                    $stmt->execute([$user_id]);
                    
                    $pdo->commit();
                    
                    $success_message = "Order placed successfully! Order number: " . $order_number;
                    $order_placed = true;
                    
                    // Send confirmation email (implement as needed)
                    // sendOrderConfirmationEmail($order_id);
                }
                
            } catch(PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = "Error processing order: " . $e->getMessage();
            }
        }
    }
}

// Fetch cart items if order not yet placed
if (!$order_placed) {
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
                p.stock_count,
                p.in_stock
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
        }
        
        $cart_total = $subtotal + ($subtotal > 0 ? $shipping_cost : 0);
        
        // Redirect to cart if empty
        if (empty($cart_items)) {
            header('Location: cart.php');
            exit();
        }
        
    } catch(PDOException $e) {
        $errors[] = "Error fetching cart items: " . $e->getMessage();
        $cart_items = [];
    }
}

// Get user information for pre-filling forms
$user_info = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user_info = $stmt->fetch(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    // Continue without user info
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout - IT Shop.LK</title>
    <meta name="description" content="Complete your order securely">
    
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
            background: #0ea600;
            color: white;
        }
        
        /* Page Header */
        .page-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
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
        
        /* Progress Steps */
        .checkout-progress {
            background: white;
            padding: 2rem;
            margin: 2rem 0;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        }
        
        .progress-steps {
            display: flex;
            justify-content: space-between;
            position: relative;
            margin-bottom: 1rem;
        }
        
        .progress-steps::before {
            content: '';
            position: absolute;
            top: 20px;
            left: 0;
            right: 0;
            height: 2px;
            background: #e2e8f0;
            z-index: 1;
        }
        
        .progress-steps::after {
            content: '';
            position: absolute;
            top: 20px;
            left: 0;
            width: 66.6%;
            height: 2px;
            background: var(--success-color);
            z-index: 2;
            transition: width 0.3s ease;
        }
        
        .step {
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            position: relative;
            z-index: 3;
        }
        
        .step.completed {
            background: var(--success-color);
            border-color: var(--success-color);
            color: white;
        }
        
        .step.active {
            background: var(--primary-color);
            border-color: var(--primary-color);
            color: white;
        }
        
        .step-label {
            margin-top: 0.5rem;
            font-size: 0.9rem;
            font-weight: 500;
            text-align: center;
        }
        
        /* Form Styles */
        .checkout-section {
            padding: 2rem 0;
        }
        
        .checkout-form {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            margin-bottom: 2rem;
        }
        
        .section-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            color: var(--text-dark);
            display: flex;
            align-items: center;
        }
        
        .section-title i {
            margin-right: 0.5rem;
            color: var(--primary-color);
        }
        
        .form-label {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.5rem;
        }
        
        .form-control, .form-select {
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            padding: 0.75rem 1rem;
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(18, 184, 0, 0.25);
        }
        
        .form-check-input {
            margin-top: 0.25rem;
        }
        
        .form-check-label {
            font-weight: 500;
        }
        
        /* Order Summary */
        .order-summary {
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
        
        .order-item {
            display: flex;
            align-items: center;
            padding: 1rem 0;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .order-item:last-child {
            border-bottom: none;
        }
        
        .item-image {
            width: 60px;
            height: 60px;
            background: var(--bg-light);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            margin-right: 1rem;
        }
        
        .item-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 8px;
        }
        
        .item-details {
            flex: 1;
        }
        
        .item-name {
            font-weight: 600;
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
        }
        
        .item-price {
            color: var(--text-light);
            font-size: 0.85rem;
        }
        
        .item-total {
            font-weight: 600;
            color: var(--primary-color);
        }
        
        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.75rem;
            padding: 0.5rem 0;
        }
        
        .summary-row.total {
            border-top: 2px solid var(--bg-light);
            padding-top: 1rem;
            margin-top: 1rem;
            font-weight: 700;
            font-size: 1.2rem;
        }
        
        /* Payment Methods */
        .payment-option {
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .payment-option:hover {
            border-color: var(--primary-color);
            background: rgba(18, 184, 0, 0.05);
        }
        
        .payment-option.selected {
            border-color: var(--primary-color);
            background: rgba(18, 184, 0, 0.1);
        }
        
        .payment-icon {
            font-size: 2rem;
            margin-right: 1rem;
        }
        
        /* Buttons */
        .btn-place-order {
            background: var(--primary-color);
            color: white;
            border: none;
            width: 100%;
            padding: 1rem;
            border-radius: 8px;
            font-weight: 600;
            font-size: 1.1rem;
            transition: background 0.3s ease;
        }
        
        .btn-place-order:hover {
            background: #0ea600;
            color: white;
        }
        
        .btn-place-order:disabled {
            background: #a0aec0;
            cursor: not-allowed;
        }
        
        /* Security Badges */
        .security-badges {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid #e2e8f0;
        }
        
        .security-badge {
            margin: 0 0.5rem;
            opacity: 0.7;
        }
        
        /* Success Message */
        .order-success {
            background: white;
            border-radius: 15px;
            padding: 3rem;
            text-align: center;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        }
        
        .success-icon {
            font-size: 4rem;
            color: var(--success-color);
            margin-bottom: 2rem;
        }
        
        .order-number {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-dark);
            margin: 1rem 0;
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .page-header h1 {
                font-size: 2rem;
            }
            
            .checkout-form,
            .order-summary {
                padding: 1.5rem;
            }
            
            .progress-steps {
                flex-direction: column;
                align-items: center;
            }
            
            .progress-steps::before,
            .progress-steps::after {
                display: none;
            }
            
            .step {
                margin-bottom: 1rem;
            }
            
            .order-summary {
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
        
        @keyframes checkmark {
            0% { transform: scale(0); }
            50% { transform: scale(1.3); }
            100% { transform: scale(1); }
        }
        
        .checkmark {
            animation: checkmark 0.6s ease-out;
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
                        <a class="nav-link" href="services.php">Services</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="contact.php">Contact</a>
                    </li>
                </ul>
                
                <div class="d-flex align-items-center">
                    <a href="cart.php" class="cart-icon">
                        <i class="fas fa-shopping-cart"></i>
                        <?php if ($cart_count > 0 && !$order_placed): ?>
                        <span class="cart-count"><?php echo $cart_count; ?></span>
                        <?php endif; ?>
                    </a>
                    
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
                    <li class="breadcrumb-item"><a href="cart.php">Cart</a></li>
                    <li class="breadcrumb-item active">Checkout</li>
                </ol>
            </nav>
            <h1><?php echo $order_placed ? 'Order Confirmed' : 'Checkout'; ?></h1>
            <p class="lead"><?php echo $order_placed ? 'Thank you for your order!' : 'Complete your purchase securely'; ?></p>
        </div>
    </section>

    <!-- Progress Steps -->
    <?php if (!$order_placed): ?>
    <section class="container">
        <div class="checkout-progress">
            <div class="progress-steps">
                <div class="step completed">1</div>
                <div class="step completed">2</div>
                <div class="step active">3</div>
            </div>
            <div class="d-flex justify-content-between">
                <div class="step-label">Shopping Cart</div>
                <div class="step-label">Checkout Details</div>
                <div class="step-label">Order Complete</div>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- Main Content -->
    <section class="checkout-section">
        <div class="container">
            <?php if (!empty($errors)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if ($success_message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if ($order_placed): ?>
            <!-- Order Success -->
            <div class="order-success fade-in checkmark">
                <i class="fas fa-check-circle success-icon"></i>
                <h2>Order Placed Successfully!</h2>
                <p class="text-muted mb-4">Your order has been received and is being processed</p>
                <div class="order-number">Order #<?php echo htmlspecialchars($order_number ?? 'N/A'); ?></div>
                <p class="mb-4">A confirmation email has been sent to your registered email address.</p>
                
                <div class="row justify-content-center">
                    <div class="col-md-6">
                        <a href="orders.php" class="btn btn-primary btn-lg mb-2 w-100">
                            <i class="fas fa-list me-2"></i>View My Orders
                        </a>
                        <a href="products.php" class="btn btn-outline-primary btn-lg w-100">
                            <i class="fas fa-shopping-bag me-2"></i>Continue Shopping
                        </a>
                    </div>
                </div>
                
                <div class="mt-4 pt-4 border-top">
                    <h5>What's Next?</h5>
                    <div class="row text-start">
                        <div class="col-md-4">
                            <i class="fas fa-credit-card fa-2x text-primary mb-2"></i>
                            <h6>Payment Processing</h6>
                            <small class="text-muted">Your payment is being verified</small>
                        </div>
                        <div class="col-md-4">
                            <i class="fas fa-box fa-2x text-warning mb-2"></i>
                            <h6>Order Preparation</h6>
                            <small class="text-muted">We'll prepare your items for shipping</small>
                        </div>
                        <div class="col-md-4">
                            <i class="fas fa-truck fa-2x text-success mb-2"></i>
                            <h6>Delivery</h6>
                            <small class="text-muted">Your order will be delivered within 3-5 business days</small>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php else: ?>
            
            <form method="POST" id="checkoutForm" class="needs-validation" novalidate>
                <input type="hidden" name="action" value="place_order">
                
                <div class="row">
                    <!-- Left Column - Forms -->
                    <div class="col-lg-7">
                        <!-- Billing Information -->
                        <div class="checkout-form fade-in">
                            <h4 class="section-title">
                                <i class="fas fa-user"></i>Billing Information
                            </h4>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="billing_first_name" class="form-label">First Name *</label>
                                    <input type="text" class="form-control" id="billing_first_name" name="billing_first_name" 
                                           value="<?php echo htmlspecialchars($user_info['first_name'] ?? ''); ?>" required>
                                    <div class="invalid-feedback">Please enter your first name.</div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="billing_last_name" class="form-label">Last Name *</label>
                                    <input type="text" class="form-control" id="billing_last_name" name="billing_last_name" 
                                           value="<?php echo htmlspecialchars($user_info['last_name'] ?? ''); ?>" required>
                                    <div class="invalid-feedback">Please enter your last name.</div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="billing_email" class="form-label">Email Address *</label>
                                    <input type="email" class="form-control" id="billing_email" name="billing_email" 
                                           value="<?php echo htmlspecialchars($user_info['email'] ?? ''); ?>" required>
                                    <div class="invalid-feedback">Please enter a valid email address.</div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="billing_phone" class="form-label">Phone Number *</label>
                                    <input type="tel" class="form-control" id="billing_phone" name="billing_phone" 
                                           value="<?php echo htmlspecialchars($user_info['phone'] ?? ''); ?>" required>
                                    <div class="invalid-feedback">Please enter your phone number.</div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="billing_address" class="form-label">Address *</label>
                                <input type="text" class="form-control" id="billing_address" name="billing_address" 
                                       placeholder="Street address, P.O. box, company name, c/o" required>
                                <div class="invalid-feedback">Please enter your address.</div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="billing_city" class="form-label">City *</label>
                                    <input type="text" class="form-control" id="billing_city" name="billing_city" required>
                                    <div class="invalid-feedback">Please enter your city.</div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="billing_postal_code" class="form-label">Postal Code *</label>
                                    <input type="text" class="form-control" id="billing_postal_code" name="billing_postal_code" required>
                                    <div class="invalid-feedback">Please enter your postal code.</div>
                                </div>
                            </div>
                        </div>

                        <!-- Shipping Information -->
                        <div class="checkout-form fade-in">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h4 class="section-title mb-0">
                                    <i class="fas fa-truck"></i>Shipping Information
                                </h4>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="same_as_billing" checked onchange="toggleShippingFields()">
                                    <label class="form-check-label" for="same_as_billing">
                                        Same as billing address
                                    </label>
                                </div>
                            </div>
                            
                            <div id="shipping_fields" style="display: none;">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="shipping_first_name" class="form-label">First Name</label>
                                        <input type="text" class="form-control" id="shipping_first_name" name="shipping_first_name">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="shipping_last_name" class="form-label">Last Name</label>
                                        <input type="text" class="form-control" id="shipping_last_name" name="shipping_last_name">
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="shipping_address" class="form-label">Address</label>
                                    <input type="text" class="form-control" id="shipping_address" name="shipping_address" 
                                           placeholder="Street address, P.O. box, company name, c/o">
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="shipping_city" class="form-label">City</label>
                                        <input type="text" class="form-control" id="shipping_city" name="shipping_city">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="shipping_postal_code" class="form-label">Postal Code</label>
                                        <input type="text" class="form-control" id="shipping_postal_code" name="shipping_postal_code">
                                    </div>
                                </div>
                            </div>
                            
                            <input type="hidden" id="different_shipping" name="different_shipping" value="0">
                        </div>

                        <!-- Payment Method -->
                        <div class="checkout-form fade-in">
                            <h4 class="section-title">
                                <i class="fas fa-credit-card"></i>Payment Method
                            </h4>
                            
                            <div class="payment-option" onclick="selectPayment('credit_card')">
                                <div class="d-flex align-items-center">
                                    <input type="radio" class="form-check-input me-3" name="payment_method" value="credit_card" id="credit_card" required>
                                    <i class="fas fa-credit-card payment-icon text-primary"></i>
                                    <div>
                                        <h6 class="mb-1">Credit/Debit Card</h6>
                                        <small class="text-muted">Pay securely with your card</small>
                                    </div>
                                    <div class="ms-auto">
                                        <i class="fab fa-cc-visa fa-2x me-2"></i>
                                        <i class="fab fa-cc-mastercard fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="payment-option" onclick="selectPayment('bank_transfer')">
                                <div class="d-flex align-items-center">
                                    <input type="radio" class="form-check-input me-3" name="payment_method" value="bank_transfer" id="bank_transfer">
                                    <i class="fas fa-university payment-icon text-success"></i>
                                    <div>
                                        <h6 class="mb-1">Bank Transfer</h6>
                                        <small class="text-muted">Direct bank transfer</small>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="payment-option" onclick="selectPayment('cash_on_delivery')">
                                <div class="d-flex align-items-center">
                                    <input type="radio" class="form-check-input me-3" name="payment_method" value="cash_on_delivery" id="cash_on_delivery">
                                    <i class="fas fa-hand-holding-usd payment-icon text-info"></i>
                                    <div>
                                        <h6 class="mb-1">Cash on Delivery</h6>
                                        <small class="text-muted">Pay when you receive your order</small>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Special Instructions -->
                        <div class="checkout-form fade-in">
                            <h4 class="section-title">
                                <i class="fas fa-comment"></i>Special Instructions
                            </h4>
                            
                            <div class="mb-3">
                                <label for="special_instructions" class="form-label">Order Notes (Optional)</label>
                                <textarea class="form-control" id="special_instructions" name="special_instructions" 
                                         rows="3" placeholder="Any special delivery instructions or notes about your order..."></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column - Order Summary -->
                    <div class="col-lg-5">
                        <div class="order-summary">
                            <h4 class="summary-title">Order Summary</h4>
                            
                            <!-- Order Items -->
                            <div class="order-items mb-4">
                                <?php foreach ($cart_items as $item): ?>
                                <div class="order-item">
                                    <div class="item-image">
                                        <img src="<?php echo htmlspecialchars($item['image']); ?>" 
                                             alt="<?php echo htmlspecialchars($item['name']); ?>" 
                                             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                        <div style="display:none; width: 100%; height: 100%; align-items: center; justify-content: center;">
                                            <i class="fas fa-laptop fa-2x text-muted"></i>
                                        </div>
                                    </div>
                                    <div class="item-details">
                                        <div class="item-name"><?php echo htmlspecialchars($item['name']); ?></div>
                                        <div class="item-price">
                                            <?php echo htmlspecialchars($item['brand']); ?> - Qty: <?php echo $item['quantity']; ?>
                                        </div>
                                    </div>
                                    <div class="item-total">
                                        <?php echo $user_currency . ' ' . number_format($item['item_total'], 2); ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <!-- Price Breakdown -->
                            <div class="summary-row">
                                <span>Subtotal (<?php echo $cart_count; ?> items)</span>
                                <span><?php echo $user_currency . ' ' . number_format($subtotal, 2); ?></span>
                            </div>
                            
                            <div class="summary-row">
                                <span>Shipping</span>
                                <span><?php echo $user_currency . ' ' . number_format($shipping_cost, 2); ?></span>
                            </div>
                            
                            <div class="summary-row total">
                                <span>Total</span>
                                <span><?php echo $user_currency . ' ' . number_format($cart_total, 2); ?></span>
                            </div>
                            
                            <!-- Place Order Button -->
                            <button type="submit" class="btn-place-order" id="place_order_btn">
                                <i class="fas fa-lock me-2"></i>Place Order Securely
                            </button>
                            
                            <!-- Security Badges -->
                            <div class="security-badges">
                                <i class="fas fa-shield-alt security-badge fa-2x text-success" title="SSL Secured"></i>
                                <i class="fas fa-lock security-badge fa-2x text-primary" title="256-bit Encryption"></i>
                                <i class="fab fa-cc-visa security-badge fa-2x text-info"></i>
                                <i class="fab fa-cc-mastercard security-badge fa-2x text-warning"></i>
                            </div>
                            
                            <div class="mt-3 text-center">
                                <small class="text-muted">
                                    <i class="fas fa-shield-alt me-1"></i>Your payment information is secure and encrypted
                                </small>
                            </div>
                            
                            <!-- Return Policy -->
                            <div class="mt-4 pt-3 border-top">
                                <h6><i class="fas fa-undo me-2 text-primary"></i>Return Policy</h6>
                                <small class="text-muted">
                                    30-day return policy on all items. Free returns for defective products.
                                </small>
                            </div>
                            
                            <!-- Support -->
                            <div class="mt-3">
                                <h6><i class="fas fa-headset me-2 text-success"></i>Need Help?</h6>
                                <small class="text-muted">
                                    Call us at <strong>+94 077 900 5652</strong> or 
                                    <a href="contact.php" class="text-decoration-none">contact support</a>
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
            
            <?php endif; ?>
        </div>
    </section>

    <!-- Footer -->
    <footer class="bg-dark text-light py-5">
        <div class="container">
            <div class="row">
                <div class="col-lg-4 mb-4">
                    <h5>Seoul Trading PVT (LTD)</h5>
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
                        <li><a href="services.php" class="text-light">Services</a></li>
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
                    <p>&copy; <?php echo date('Y'); ?> Seoul Trading PVT (LTD). All rights reserved.</p>
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
// Form validation
(function() {
    'use strict';
    window.addEventListener('load', function() {
        var forms = document.getElementsByClassName('needs-validation');
        var validation = Array.prototype.filter.call(forms, function(form) {
            form.addEventListener('submit', function(event) {
                if (form.checkValidity() === false) {
                    event.preventDefault();
                    event.stopPropagation();
                    showToast('Please fill in all required fields correctly.', 'error');
                }
                form.classList.add('was-validated');
            }, false);
        });
    }, false);
})();

// Toggle shipping fields
function toggleShippingFields() {
    const sameAsBilling = document.getElementById('same_as_billing').checked;
    const shippingFields = document.getElementById('shipping_fields');
    const differentShipping = document.getElementById('different_shipping');
    
    if (sameAsBilling) {
        shippingFields.style.display = 'none';
        differentShipping.value = '0';
        // Clear shipping fields
        const inputs = shippingFields.querySelectorAll('input');
        inputs.forEach(input => {
            input.value = '';
            input.removeAttribute('required');
        });
    } else {
        shippingFields.style.display = 'block';
        differentShipping.value = '1';
        // Make shipping fields required
        const requiredFields = ['shipping_first_name', 'shipping_last_name', 'shipping_address', 'shipping_city', 'shipping_postal_code'];
        requiredFields.forEach(field => {
            document.getElementById(field).setAttribute('required', 'required');
        });
    }
}

// Select payment method
function selectPayment(method) {
    // Remove selected class from all options
    document.querySelectorAll('.payment-option').forEach(option => {
        option.classList.remove('selected');
    });
    
    // Add selected class to clicked option
    event.currentTarget.classList.add('selected');
    
    // Check the radio button
    document.getElementById(method).checked = true;
}

// Form submission handling
document.getElementById('checkoutForm').addEventListener('submit', function(e) {
    const submitBtn = document.getElementById('place_order_btn');
    
    // Show loading state
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Processing Order...';
    submitBtn.disabled = true;
    
    // Add a small delay to show loading state
    setTimeout(() => {
        // The form will submit normally after this
    }, 500);
});

// Auto-fill billing to shipping when same address is checked
function copyBillingToShipping() {
    if (document.getElementById('same_as_billing').checked) {
        const billingFields = {
            'billing_first_name': 'shipping_first_name',
            'billing_last_name': 'shipping_last_name',
            'billing_address': 'shipping_address',
            'billing_city': 'shipping_city',
            'billing_postal_code': 'shipping_postal_code'
        };
        
        Object.entries(billingFields).forEach(([billing, shipping]) => {
            const billingValue = document.getElementById(billing).value;
            const shippingField = document.getElementById(shipping);
            if (shippingField) {
                shippingField.value = billingValue;
            }
        });
    }
}

// Add event listeners to billing fields
document.addEventListener('DOMContentLoaded', function() {
    const billingFields = ['billing_first_name', 'billing_last_name', 'billing_address', 'billing_city', 'billing_postal_code'];
    
    billingFields.forEach(field => {
        const element = document.getElementById(field);
        if (element) {
            element.addEventListener('blur', copyBillingToShipping);
        }
    });
    
    // Initialize fade-in animations
    const elements = document.querySelectorAll('.fade-in');
    elements.forEach((element, index) => {
        setTimeout(() => {
            element.style.opacity = '1';
            element.style.transform = 'translateY(0)';
        }, index * 200);
    });
    
    // Form field validation feedback
    const inputs = document.querySelectorAll('.form-control, .form-select');
    inputs.forEach(input => {
        input.addEventListener('blur', function() {
            if (this.checkValidity()) {
                this.classList.add('is-valid');
                this.classList.remove('is-invalid');
            } else {
                this.classList.add('is-invalid');
                this.classList.remove('is-valid');
            }
        });
        
        input.addEventListener('input', function() {
            this.classList.remove('is-valid', 'is-invalid');
        });
    });
    
    // Phone number formatting
    const phoneInput = document.getElementById('billing_phone');
    if (phoneInput) {
        phoneInput.addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length > 0) {
                if (value.startsWith('94')) {
                    value = '+' + value;
                } else if (!value.startsWith('+')) {
                    value = '+94' + value;
                }
            }
            e.target.value = value;
        });
    }
    
    // Postal code validation for Sri Lanka
    const postalInputs = document.querySelectorAll('input[name*="postal_code"]');
    postalInputs.forEach(input => {
        input.addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length > 5) {
                value = value.substring(0, 5);
            }
            e.target.value = value;
        });
    });
    
    // Save form data to localStorage (for recovery)
    const formFields = document.querySelectorAll('#checkoutForm input, #checkoutForm textarea, #checkoutForm select');
    formFields.forEach(field => {
        // Load saved data
        const savedValue = localStorage.getItem('checkout_' + field.name);
        if (savedValue && field.type !== 'radio') {
            field.value = savedValue;
        } else if (savedValue && field.type === 'radio' && field.value === savedValue) {
            field.checked = true;
            selectPayment(field.value);
        }
        
        // Save data on change
        field.addEventListener('change', function() {
            if (field.type === 'radio' && field.checked) {
                localStorage.setItem('checkout_' + field.name, field.value);
            } else if (field.type !== 'radio') {
                localStorage.setItem('checkout_' + field.name, field.value);
            }
        });
    });
});

// Toast notification function
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

// Clear saved form data after successful order
<?php if ($order_placed): ?>
// Clear localStorage
Object.keys(localStorage).forEach(key => {
    if (key.startsWith('checkout_')) {
        localStorage.removeItem(key);
    }
});
<?php endif; ?>

// Prevent back button after order is placed
<?php if ($order_placed): ?>
history.pushState(null, null, location.href);
window.onpopstate = function () {
    history.go(1);
};
<?php endif; ?>

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    // Ctrl+Enter to submit form
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
        e.preventDefault();
        const submitBtn = document.getElementById('place_order_btn');
        if (submitBtn && !submitBtn.disabled) {
            document.getElementById('checkoutForm').requestSubmit();
        }
    }
});
    </script>
</body>
</html>