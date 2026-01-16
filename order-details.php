<?php
// order-details.php
session_start();

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

include 'db.php';

$user_id = $_SESSION['user_id'];
$order_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$order_id) {
    $_SESSION['error'] = "Invalid order ID";
    header('Location: orders.php');
    exit();
}

try {
    // Get order details
    $stmt = $pdo->prepare("SELECT o.*, u.full_name, u.email, u.phone 
                           FROM orders o 
                           JOIN users u ON o.user_id = u.id 
                           WHERE o.id = ? AND o.user_id = ?");
    $stmt->execute([$order_id, $user_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        $_SESSION['error'] = "Order not found or you don't have permission to view it";
        header('Location: orders.php');
        exit();
    }
    
    // Get order items
    $stmt = $pdo->prepare("SELECT oi.*, p.name, p.image_url 
                           FROM order_items oi 
                           JOIN products p ON oi.product_id = p.id 
                           WHERE oi.order_id = ?");
    $stmt->execute([$order_id]);
    $order_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($order_items)) {
        throw new Exception("No items found for this order");
    }
    
} catch(PDOException $e) {
    error_log("Order details error: " . $e->getMessage());
    $_SESSION['error'] = "Database error: " . $e->getMessage();
    header('Location: orders.php');
    exit();
} catch(Exception $e) {
    error_log("Order details error: " . $e->getMessage());
    $_SESSION['error'] = $e->getMessage();
    header('Location: orders.php');
    exit();
}

// Calculate order timeline
$timeline = [
    ['status' => 'pending', 'label' => 'Order Placed', 'icon' => 'fa-check-circle', 'date' => $order['created_at']],
    ['status' => 'processing', 'label' => 'Processing', 'icon' => 'fa-cog', 'date' => null],
    ['status' => 'shipped', 'label' => 'Shipped', 'icon' => 'fa-shipping-fast', 'date' => null],
    ['status' => 'completed', 'label' => 'Delivered', 'icon' => 'fa-box-open', 'date' => null]
];

$current_status_index = 0;
$statuses = ['pending', 'processing', 'shipped', 'completed'];
$current_status_index = array_search($order['status'], $statuses);
if ($current_status_index === false) {
    $current_status_index = 0;
}

// Calculate totals
$subtotal = 0;
foreach ($order_items as $item) {
    $subtotal += $item['price'] * $item['quantity'];
}
$shipping = 500; // Default shipping cost

// Helper function to safely output HTML
function e($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

// Helper function to format currency
function formatCurrency($amount) {
    return number_format(floatval($amount), 2);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order #<?php echo str_pad($order['id'], 5, '0', STR_PAD_LEFT); ?> - STC Electronics</title>
    
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
        }
        
        /* Header */
        .site-header {
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            padding: 1rem 0;
            margin-bottom: 2rem;
        }
        
        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 2rem;
        }
        
        .logo img {
            height: 40px;
        }
        
        .user-menu {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }
        
        .user-menu a {
            color: var(--text-dark);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s ease;
        }
        
        .user-menu a:hover {
            color: var(--primary-color);
        }
        
        .user-menu .btn-logout {
            background: var(--danger-color);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .user-menu .btn-logout:hover {
            background: #bb2d3b;
            transform: translateY(-2px);
        }
        
        /* Main Container */
        .container-custom {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 2rem 3rem;
        }
        
        /* Back Button */
        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--text-dark);
            text-decoration: none;
            font-weight: 500;
            margin-bottom: 1.5rem;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .back-button:hover {
            background: white;
            color: var(--primary-color);
        }
        
        /* Page Header */
        .page-header {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .page-header h1 {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-dark);
            margin: 0;
        }
        
        .order-date-header {
            color: var(--text-light);
            font-size: 0.95rem;
        }
        
        /* Status Badges */
        .badge-custom {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: inline-block;
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
        
        .badge-info {
            background: rgba(0, 140, 255, 0.1);
            color: var(--secondary-color);
        }
        
        .badge-secondary {
            background: rgba(108, 117, 125, 0.1);
            color: #6c757d;
        }
        
        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
        }
        
        /* Order Timeline */
        .timeline-card {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 2rem;
        }
        
        .timeline-card h2 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            color: var(--text-dark);
        }
        
        .timeline {
            position: relative;
            padding-left: 2rem;
        }
        
        .timeline::before {
            content: '';
            position: absolute;
            left: 1rem;
            top: 0;
            bottom: 0;
            width: 2px;
            background: var(--border-color);
        }
        
        .timeline-item {
            position: relative;
            padding-bottom: 2rem;
        }
        
        .timeline-item:last-child {
            padding-bottom: 0;
        }
        
        .timeline-icon {
            position: absolute;
            left: -1.5rem;
            width: 2.5rem;
            height: 2.5rem;
            background: white;
            border: 3px solid var(--border-color);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            color: var(--text-light);
            transition: all 0.3s ease;
        }
        
        .timeline-item.active .timeline-icon,
        .timeline-item.completed .timeline-icon {
            background: var(--primary-color);
            border-color: var(--primary-color);
            color: white;
        }
        
        .timeline-content h3 {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
            color: var(--text-dark);
        }
        
        .timeline-content p {
            margin: 0;
            color: var(--text-light);
            font-size: 0.9rem;
        }
        
        .timeline-item.completed .timeline-content h3 {
            color: var(--primary-color);
        }
        
        /* Order Items Card */
        .card-section {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 2rem;
        }
        
        .card-section h2 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            color: var(--text-dark);
        }
        
        /* Product Item */
        .product-item {
            display: flex;
            gap: 1.5rem;
            padding: 1.5rem;
            background: var(--bg-light);
            border-radius: 8px;
            margin-bottom: 1rem;
            transition: all 0.3s ease;
        }
        
        .product-item:hover {
            background: #edf2f7;
        }
        
        .product-item:last-child {
            margin-bottom: 0;
        }
        
        .product-image {
            width: 100px;
            height: 100px;
            border-radius: 8px;
            object-fit: cover;
            background: white;
            border: 1px solid var(--border-color);
        }
        
        .product-info {
            flex: 1;
        }
        
        .product-name {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.5rem;
        }
        
        .product-details {
            display: flex;
            gap: 2rem;
            color: var(--text-light);
            font-size: 0.9rem;
        }
        
        .product-price {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--primary-color);
            text-align: right;
        }
        
        /* Order Summary */
        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--border-color);
        }
        
        .summary-row:last-child {
            border-bottom: none;
            padding-top: 1rem;
            margin-top: 0.5rem;
            border-top: 2px solid var(--border-color);
        }
        
        .summary-label {
            color: var(--text-light);
            font-weight: 500;
        }
        
        .summary-value {
            font-weight: 600;
            color: var(--text-dark);
        }
        
        .summary-row:last-child .summary-label,
        .summary-row:last-child .summary-value {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text-dark);
        }
        
        /* Info Card */
        .info-section {
            margin-bottom: 1.5rem;
        }
        
        .info-section:last-child {
            margin-bottom: 0;
        }
        
        .info-section h3 {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .info-section h3 i {
            color: var(--primary-color);
        }
        
        .info-section p {
            margin: 0.5rem 0;
            color: var(--text-light);
            line-height: 1.6;
        }
        
        .info-section strong {
            color: var(--text-dark);
            font-weight: 600;
        }
        
        /* Action Buttons */
        .action-buttons {
            display: grid;
            gap: 1rem;
            margin-top: 1.5rem;
        }
        
        .btn-action {
            padding: 0.875rem;
            border-radius: 8px;
            border: none;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        
        .btn-primary {
            background: var(--primary-color);
            color: white;
        }
        
        .btn-primary:hover {
            background: #0e7b02ff;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(18, 184, 0, 0.3);
        }
        
        .btn-secondary {
            background: var(--secondary-color);
            color: white;
        }
        
        .btn-secondary:hover {
            background: #0070cc;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 140, 255, 0.3);
        }
        
        .btn-outline {
            background: white;
            color: var(--text-dark);
            border: 2px solid var(--border-color);
        }
        
        .btn-outline:hover {
            border-color: var(--primary-color);
            color: var(--primary-color);
        }
        
        .btn-danger {
            background: var(--danger-color);
            color: white;
        }
        
        .btn-danger:hover {
            background: #bb2d3b;
            color: white;
            transform: translateY(-2px);
        }
        
        /* Print Styles */
        @media print {
            .site-header,
            .back-button,
            .action-buttons {
                display: none;
            }
        }
        
        /* Responsive */
        @media (max-width: 992px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .container-custom {
                padding: 0 1rem 2rem;
            }
            
            .header-content {
                padding: 0 1rem;
                flex-direction: column;
                gap: 1rem;
            }
            
            .user-menu {
                width: 100%;
                justify-content: space-between;
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .page-header h1 {
                font-size: 1.5rem;
            }
            
            .product-item {
                flex-direction: column;
            }
            
            .product-image {
                width: 100%;
                height: 200px;
            }
            
            .product-details {
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .product-price {
                text-align: left;
                margin-top: 0.5rem;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="site-header">
        <div class="header-content">
            <div class="logo">
                <a href="index.php">
                    <img src="assets/revised-04.png" alt="STC Electronics" onerror="this.style.display='none'">
                </a>
            </div>
            
            <nav class="user-menu">
                <a href="index.php"><i class="fas fa-home"></i> Home</a>
                <a href="profile.php"><i class="fas fa-user"></i> Profile</a>
                <a href="orders.php"><i class="fas fa-shopping-bag"></i> Orders</a>
                <a href="logout.php" class="btn-logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </nav>
        </div>
    </header>
    
    <!-- Main Content -->
    <main class="container-custom">
        <a href="orders.php" class="back-button">
            <i class="fas fa-arrow-left"></i> Back to Orders
        </a>
        
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1>Order #<?php echo str_pad($order['id'], 5, '0', STR_PAD_LEFT); ?></h1>
                <p class="order-date-header">
                    <i class="far fa-calendar"></i>
                    Placed on <?php echo date('F d, Y \a\t g:i A', strtotime($order['created_at'])); ?>
                </p>
            </div>
            <div>
                <?php
                $badge_class = 'badge-info';
                switch($order['status']) {
                    case 'completed':
                        $badge_class = 'badge-success';
                        break;
                    case 'cancelled':
                        $badge_class = 'badge-danger';
                        break;
                    case 'processing':
                        $badge_class = 'badge-warning';
                        break;
                    case 'shipped':
                        $badge_class = 'badge-info';
                        break;
                    case 'pending':
                        $badge_class = 'badge-secondary';
                        break;
                }
                ?>
                <span class="badge-custom <?php echo $badge_class; ?>">
                    <?php echo ucfirst($order['status']); ?>
                </span>
            </div>
        </div>
        
        <div class="content-grid">
            <!-- Left Column -->
            <div>
                <!-- Order Timeline -->
                <?php if ($order['status'] !== 'cancelled'): ?>
                <div class="timeline-card">
                    <h2><i class="fas fa-route"></i> Order Timeline</h2>
                    <div class="timeline">
                        <?php foreach ($timeline as $index => $item): ?>
                        <div class="timeline-item <?php echo $index <= $current_status_index ? 'completed' : ''; ?> <?php echo $index === $current_status_index ? 'active' : ''; ?>">
                            <div class="timeline-icon">
                                <i class="fas <?php echo $item['icon']; ?>"></i>
                            </div>
                            <div class="timeline-content">
                                <h3><?php echo e($item['label']); ?></h3>
                                <p>
                                    <?php if ($index <= $current_status_index): ?>
                                        <?php echo $index === 0 ? date('F d, Y \a\t g:i A', strtotime($order['created_at'])) : 'In Progress'; ?>
                                    <?php else: ?>
                                        Pending
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Order Items -->
                <div class="card-section">
                    <h2><i class="fas fa-box"></i> Order Items</h2>
                    <?php foreach ($order_items as $item): ?>
                    <div class="product-item">
                        <img src="<?php echo e($item['image_url'] ?: 'assets/placeholder.jpg'); ?>" 
                             alt="<?php echo e($item['name']); ?>" 
                             class="product-image"
                             onerror="this.src='assets/placeholder.jpg'">
                        <div class="product-info">
                            <div class="product-name"><?php echo e($item['name']); ?></div>
                            <div class="product-details">
                                <span><strong>Price:</strong> LKR <?php echo formatCurrency($item['price']); ?></span>
                                <span><strong>Quantity:</strong> <?php echo intval($item['quantity']); ?></span>
                            </div>
                        </div>
                        <div class="product-price">
                            LKR <?php echo formatCurrency($item['price'] * $item['quantity']); ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Order Summary -->
                <div class="card-section">
                    <h2><i class="fas fa-receipt"></i> Order Summary</h2>
                    <div class="summary-row">
                        <span class="summary-label">Subtotal</span>
                        <span class="summary-value">LKR <?php echo formatCurrency($subtotal); ?></span>
                    </div>
                    <div class="summary-row">
                        <span class="summary-label">Shipping Fee</span>
                        <span class="summary-value">LKR <?php echo formatCurrency($shipping); ?></span>
                    </div>
                    <div class="summary-row">
                        <span class="summary-label">Total</span>
                        <span class="summary-value">LKR <?php echo formatCurrency($order['total_amount']); ?></span>
                    </div>
                </div>
            </div>
            
            <!-- Right Column -->
            <div>
                <!-- Customer Information -->
                <div class="card-section">
                    <h2><i class="fas fa-user"></i> Customer Information</h2>
                    <div class="info-section">
                        <h3><i class="fas fa-user-circle"></i> Contact Details</h3>
                        <p><strong>Name:</strong> <?php echo e($order['full_name']); ?></p>
                        <p><strong>Email:</strong> <?php echo e($order['email']); ?></p>
                        <p><strong>Phone:</strong> <?php echo e($order['phone']); ?></p>
                    </div>
                    
                    <div class="info-section">
                        <h3><i class="fas fa-map-marker-alt"></i> Shipping Address</h3>
                        <p><?php echo nl2br(e($order['shipping_address'])); ?></p>
                    </div>
                    
                    <div class="info-section">
                        <h3><i class="fas fa-credit-card"></i> Payment Information</h3>
                        <p><strong>Method:</strong> <?php echo ucfirst(e($order['payment_method'])); ?></p>
                        <p><strong>Status:</strong> 
                            <span style="color: var(--success-color); font-weight: 600;">
                                <?php echo $order['payment_method'] === 'cod' ? 'Cash on Delivery' : 'Paid'; ?>
                            </span>
                        </p>
                    </div>
                    
                    <?php if (!empty($order['tracking_number'])): ?>
                    <div class="info-section">
                        <h3><i class="fas fa-truck"></i> Tracking Information</h3>
                        <p><strong>Tracking Number:</strong><br><?php echo e($order['tracking_number']); ?></p>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Action Buttons -->
                <div class="card-section">
                    <h2><i class="fas fa-tools"></i> Actions</h2>
                    <div class="action-buttons">
                        <?php if ($order['status'] === 'shipped' || $order['status'] === 'completed'): ?>
                        <a href="track-order.php?id=<?php echo intval($order['id']); ?>" class="btn-action btn-primary">
                            <i class="fas fa-shipping-fast"></i> Track Order
                        </a>
                        <?php endif; ?>
                        
                        <button onclick="window.print()" class="btn-action btn-secondary">
                            <i class="fas fa-print"></i> Print Invoice
                        </button>
                        
                        <a href="mailto:support@stcelectronics.com?subject=Order%20%23<?php echo intval($order['id']); ?>%20Support" 
                           class="btn-action btn-outline">
                            <i class="fas fa-question-circle"></i> Need Help?
                        </a>
                        
                        <?php if ($order['status'] === 'pending'): ?>
                        <button onclick="cancelOrder(<?php echo intval($order['id']); ?>)" class="btn-action btn-danger">
                            <i class="fas fa-times-circle"></i> Cancel Order
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>
    
    <!-- Bootstrap JS -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    
    <script>
        function cancelOrder(orderId) {
            if (confirm('Are you sure you want to cancel this order?')) {
                window.location.href = 'cancel-order.php?id=' + orderId;
            }
        }
    </script>
</body>
</html>