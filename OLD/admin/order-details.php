<?php
// admin/order-details.php
session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit();
}

include '../db.php';

// Get order ID
$order_id = intval($_GET['id'] ?? 0);

if (!$order_id) {
    header('Location: orders.php');
    exit();
}

// Initialize error/success variables
$error_message = null;
$success_message = null;
$order = null;
$order_items = [];

// Handle order status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $new_status = $_POST['status'] ?? '';
    
    if ($new_status) {
        try {
            $stmt = $pdo->prepare("UPDATE orders SET status = ?, updated_at = NOW() WHERE id = ?");
            if ($stmt->execute([$new_status, $order_id])) {
                $success_message = "Order status updated successfully!";
            } else {
                $error_message = "Failed to update order status";
            }
        } catch(PDOException $e) {
            $error_message = "Database error: " . htmlspecialchars($e->getMessage());
            error_log("Order status update error: " . $e->getMessage());
        }
    }
}

// Handle order deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_order'])) {
    try {
        $pdo->beginTransaction();
        
        // Delete order items first
        $stmt = $pdo->prepare("DELETE FROM order_items WHERE order_id = ?");
        $stmt->execute([$order_id]);
        
        // Delete order
        $stmt = $pdo->prepare("DELETE FROM orders WHERE id = ?");
        $stmt->execute([$order_id]);
        
        $pdo->commit();
        header('Location: orders.php?deleted=1');
        exit();
    } catch(PDOException $e) {
        $pdo->rollBack();
        $error_message = "Error deleting order: " . htmlspecialchars($e->getMessage());
        error_log("Order deletion error: " . $e->getMessage());
    }
}

// Fetch order details with customer information
try {
    $stmt = $pdo->prepare("
        SELECT o.*, COALESCE(u.full_name, 'Guest') as full_name, 
               u.email, u.phone, u.address 
        FROM orders o 
        LEFT JOIN users u ON o.user_id = u.id 
        WHERE o.id = ?
    ");
    
    if ($stmt->execute([$order_id])) {
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $error_message = "Failed to fetch order";
    }
    
} catch(PDOException $e) {
    $error_message = "Database error: " . htmlspecialchars($e->getMessage());
    error_log("Order fetch error: " . $e->getMessage());
}

// Redirect if order not found
if (!$order || !is_array($order)) {
    header('Location: orders.php');
    exit();
}

// Fetch order items with product details
try {
    $stmt = $pdo->prepare("
        SELECT oi.id, oi.order_id, oi.product_id, oi.quantity, oi.price,
               p.name as product_name, p.image, p.sku 
        FROM order_items oi 
        LEFT JOIN products p ON oi.product_id = p.id 
        WHERE oi.order_id = ?
        ORDER BY oi.id
    ");
    
    if ($stmt->execute([$order_id])) {
        $order_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($order_items)) {
            $order_items = [];
        }
    } else {
        $order_items = [];
    }
    
} catch(PDOException $e) {
    error_log("Order items fetch error: " . $e->getMessage());
    $order_items = [];
}

// Safe function to get array value
function safe_get($array, $key, $default = 'N/A') {
    if (!is_array($array)) {
        return $default;
    }
    return isset($array[$key]) && $array[$key] !== null ? $array[$key] : $default;
}

// Safe function to format currency
function format_currency($amount) {
    return 'LKR ' . number_format((float)$amount, 2);
}

// Safe function to format date
function format_date($date_string) {
    if (empty($date_string)) {
        return 'N/A';
    }
    try {
        return date('F d, Y', strtotime($date_string));
    } catch (Exception $e) {
        return 'N/A';
    }
}

// Safe function to format time
function format_time($date_string) {
    if (empty($date_string)) {
        return 'N/A';
    }
    try {
        return date('h:i A', strtotime($date_string));
    } catch (Exception $e) {
        return 'N/A';
    }
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
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .page-title-section {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .back-btn {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--bg-light);
            border-radius: 8px;
            color: var(--text-dark);
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .back-btn:hover {
            background: var(--primary-color);
            color: white;
        }
        
        .page-title {
            margin: 0;
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--text-dark);
        }
        
        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        
        /* Content Card */
        .content-card {
            background: white;
            border-radius: 12px;
            padding: 1.75rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 1.5rem;
        }
        
        .card-header {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--border-color);
        }
        
        .order-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .info-item {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }
        
        .info-label {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-light);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .info-value {
            font-size: 1rem;
            color: var(--text-dark);
            font-weight: 500;
        }
        
        .badge-custom {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: inline-block;
            width: fit-content;
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
        
        .order-items-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }
        
        .order-items-table thead th {
            background: var(--bg-light);
            color: var(--text-dark);
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 1rem;
            text-align: left;
            border-bottom: 2px solid var(--border-color);
        }
        
        .order-items-table tbody td {
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-dark);
            font-size: 0.95rem;
            vertical-align: middle;
        }
        
        .order-items-table tbody tr:last-child td {
            border-bottom: none;
        }
        
        .product-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .product-image {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }
        
        .product-details h6 {
            margin: 0 0 0.25rem 0;
            font-size: 0.95rem;
            font-weight: 600;
            color: var(--text-dark);
        }
        
        .order-summary {
            max-width: 400px;
            margin-left: auto;
            margin-top: 2rem;
        }
        
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
        
        .summary-row.total {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text-dark);
        }
        
        .summary-label {
            color: var(--text-light);
            font-weight: 500;
        }
        
        .summary-value {
            color: var(--text-dark);
            font-weight: 600;
        }
        
        .btn-custom {
            padding: 0.625rem 1.25rem;
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
        }
        
        .btn-info-custom {
            background: var(--secondary-color);
            color: white;
        }
        
        .btn-info-custom:hover {
            background: #0070cc;
            transform: translateY(-2px);
        }
        
        .btn-danger-custom {
            background: var(--danger-color);
            color: white;
        }
        
        .btn-danger-custom:hover {
            background: #bb2d3b;
            transform: translateY(-2px);
        }
        
        .btn-warning-custom {
            background: var(--warning-color);
            color: white;
        }
        
        .btn-warning-custom:hover {
            background: #dc6502;
        }
        
        .alert-custom {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .alert-success {
            background: rgba(56, 161, 105, 0.1);
            color: var(--success-color);
            border: 1px solid rgba(56, 161, 105, 0.3);
        }
        
        .alert-danger {
            background: rgba(220, 53, 69, 0.1);
            color: var(--danger-color);
            border: 1px solid rgba(220, 53, 69, 0.3);
        }
        
        .modal-content {
            border-radius: 12px;
            border: none;
        }
        
        .modal-header {
            border-bottom: 1px solid var(--border-color);
            padding: 1.5rem;
        }
        
        .modal-title {
            font-weight: 600;
            color: var(--text-dark);
        }
        
        .modal-body {
            padding: 1.5rem;
        }
        
        .modal-footer {
            border-top: 1px solid var(--border-color);
            padding: 1rem 1.5rem;
        }
        
        .form-label {
            font-weight: 500;
            color: var(--text-dark);
            margin-bottom: 0.5rem;
        }
        
        .form-select {
            padding: 0.625rem 1rem;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            font-size: 0.9rem;
        }
        
        .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: none;
        }
        
        .address-box {
            background: var(--bg-light);
            padding: 1.25rem;
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }
        
        .address-box p {
            margin: 0.25rem 0;
            color: var(--text-dark);
            line-height: 1.6;
        }
        
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
            
            .order-info-grid {
                grid-template-columns: 1fr;
            }
            
            .order-items-table thead {
                display: none;
            }
            
            .order-items-table tbody tr {
                display: block;
                margin-bottom: 1rem;
                border: 1px solid var(--border-color);
                border-radius: 8px;
                padding: 1rem;
            }
            
            .order-items-table tbody td {
                display: block;
                text-align: left;
                padding: 0.5rem 0;
                border: none;
            }
            
            .order-items-table tbody td::before {
                content: attr(data-label);
                font-weight: 600;
                text-transform: uppercase;
                font-size: 0.75rem;
                color: var(--text-light);
                display: block;
                margin-bottom: 0.25rem;
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
                <a href="products.php" class="sidebar-menu-link">
                    <i class="fas fa-box"></i>
                    <span class="sidebar-menu-text">Products</span>
                </a>
            </li>
            <li class="sidebar-menu-item">
                <a href="orders.php" class="sidebar-menu-link active">
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
            <div class="page-title-section">
                <a href="orders.php" class="back-btn">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <h1 class="page-title">Order #<?php echo str_pad(safe_get($order, 'id', '0'), 5, '0', STR_PAD_LEFT); ?></h1>
            </div>
            <div class="action-buttons">
                <button type="button" class="btn-custom btn-primary-custom" onclick="openStatusModal()">
                    <i class="fas fa-edit"></i> Update Status
                </button>
                <button type="button" class="btn-custom btn-warning-custom" onclick="window.print()">
                    <i class="fas fa-print"></i> Print
                </button>
                <button type="button" class="btn-custom btn-danger-custom" onclick="openDeleteModal()">
                    <i class="fas fa-trash"></i> Delete
                </button>
            </div>
        </div>
        
        <!-- Alert Messages -->
        <?php if ($success_message): ?>
        <div class="alert-custom alert-success">
            <i class="fas fa-check-circle"></i>
            <span><?php echo htmlspecialchars($success_message); ?></span>
        </div>
        <?php endif; ?>
        
        <?php if ($error_message): ?>
        <div class="alert-custom alert-danger">
            <i class="fas fa-exclamation-circle"></i>
            <span><?php echo htmlspecialchars($error_message); ?></span>
        </div>
        <?php endif; ?>
        
        <!-- Order Information -->
        <div class="content-card">
            <h3 class="card-header">Order Information</h3>
            
            <div class="order-info-grid">
                <div class="info-item">
                    <span class="info-label">Order ID</span>
                    <span class="info-value">#<?php echo str_pad(safe_get($order, 'id', '0'), 5, '0', STR_PAD_LEFT); ?></span>
                </div>
                
                <div class="info-item">
                    <span class="info-label">Order Date</span>
                    <span class="info-value">
                        <?php echo format_date(safe_get($order, 'created_at')); ?><br>
                        <small class="text-muted"><?php echo format_time(safe_get($order, 'created_at')); ?></small>
                    </span>
                </div>
                
                <div class="info-item">
                    <span class="info-label">Status</span>
                    <?php
                    $status = strtolower(safe_get($order, 'status', 'unknown'));
                    $badge_class = 'badge-info';
                    
                    switch($status) {
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
                        <?php echo ucfirst($status); ?>
                    </span>
                </div>
                
                <div class="info-item">
                    <span class="info-label">Payment Method</span>
                    <span class="info-value"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', safe_get($order, 'payment_method')))); ?></span>
                </div>
            </div>
        </div>
        
        <!-- Customer Information -->
        <div class="content-card">
            <h3 class="card-header">Customer Information</h3>
            
            <div class="order-info-grid">
                <div class="info-item">
                    <span class="info-label">Full Name</span>
                    <span class="info-value"><?php echo htmlspecialchars(safe_get($order, 'full_name')); ?></span>
                </div>
                
                <div class="info-item">
                    <span class="info-label">Email</span>
                    <span class="info-value"><?php echo htmlspecialchars(safe_get($order, 'email')); ?></span>
                </div>
                
                <div class="info-item">
                    <span class="info-label">Phone</span>
                    <span class="info-value"><?php echo htmlspecialchars(safe_get($order, 'phone')); ?></span>
                </div>
            </div>
            
            <?php if (safe_get($order, 'shipping_address') !== 'N/A'): ?>
            <div style="margin-top: 1.5rem;">
                <span class="info-label" style="display: block; margin-bottom: 0.5rem;">Shipping Address</span>
                <div class="address-box">
                    <p><?php echo nl2br(htmlspecialchars(safe_get($order, 'shipping_address'))); ?></p>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Order Items -->
        <div class="content-card">
            <h3 class="card-header">Order Items</h3>
            
            <?php if (is_array($order_items) && count($order_items) > 0): ?>
            <div class="table-responsive">
                <table class="order-items-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>SKU</th>
                            <th>Price</th>
                            <th>Quantity</th>
                            <th>Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($order_items as $item): 
                            if (!is_array($item)) continue;
                            $product_name = safe_get($item, 'product_name');
                            $image = safe_get($item, 'image');
                            $sku = safe_get($item, 'sku');
                            $price = (float)safe_get($item, 'price', 0);
                            $quantity = (int)safe_get($item, 'quantity', 0);
                        ?>
                        <tr>
                            <td data-label="Product">
                                <div class="product-info">
                                    <?php if ($image !== 'N/A'): ?>
                                        <img src="../uploads/products/<?php echo htmlspecialchars($image); ?>" 
                                             alt="<?php echo htmlspecialchars($product_name); ?>"
                                             class="product-image"
                                             onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22%3E%3Crect fill=%22%23f0f0f0%22 width=%22100%25%22 height=%22100%25%22/%3E%3C/svg%3E'">
                                    <?php else: ?>
                                        <div class="product-image" style="background: var(--bg-light); display: flex; align-items: center; justify-content: center;">
                                            <i class="fas fa-image" style="color: var(--text-light);"></i>
                                        </div>
                                    <?php endif; ?>
                                    <div class="product-details">
                                        <h6><?php echo htmlspecialchars($product_name); ?></h6>
                                    </div>
                                </div>
                            </td>
                            <td data-label="SKU">
                                <small><?php echo htmlspecialchars($sku); ?></small>
                            </td>
                            <td data-label="Price">
                                <?php echo format_currency($price); ?>
                            </td>
                            <td data-label="Quantity">
                                <?php echo $quantity; ?>
                            </td>
                            <td data-label="Subtotal">
                                <strong><?php echo format_currency($price * $quantity); ?></strong>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Order Summary -->
            <div class="order-summary">
                <div class="summary-row">
                    <span class="summary-label">Subtotal:</span>
                    <span class="summary-value"><?php echo format_currency(safe_get($order, 'total_amount', 0)); ?></span>
                </div>
                <div class="summary-row">
                    <span class="summary-label">Shipping:</span>
                    <span class="summary-value"><?php echo format_currency(0); ?></span>
                </div>
                <div class="summary-row">
                    <span class="summary-label">Tax:</span>
                    <span class="summary-value"><?php echo format_currency(0); ?></span>
                </div>
                <div class="summary-row total">
                    <span class="summary-label">Total:</span>
                    <span class="summary-value"><?php echo format_currency(safe_get($order, 'total_amount', 0)); ?></span>
                </div>
            </div>
            <?php else: ?>
            <p class="text-muted">No items found for this order.</p>
            <?php endif; ?>
        </div>
        
        <!-- Order Notes -->
        <?php if (safe_get($order, 'notes') !== 'N/A'): ?>
        <div class="content-card">
            <h3 class="card-header">Order Notes</h3>
            <div class="address-box">
                <p><?php echo nl2br(htmlspecialchars(safe_get($order, 'notes'))); ?></p>
            </div>
        </div>
        <?php endif; ?>
    </main>
    
    <!-- Status Update Modal -->
    <div class="modal fade" id="statusModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Update Order Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="update_status" value="1">
                        
                        <div class="mb-3">
                            <label class="form-label">Current Status</label>
                            <input type="text" class="form-control" value="<?php echo ucfirst(safe_get($order, 'status', 'unknown')); ?>" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">New Status</label>
                            <select name="status" class="form-select" required>
                                <option value="pending" <?php echo safe_get($order, 'status') === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="processing" <?php echo safe_get($order, 'status') === 'processing' ? 'selected' : ''; ?>>Processing</option>
                                <option value="shipped" <?php echo safe_get($order, 'status') === 'shipped' ? 'selected' : ''; ?>>Shipped</option>
                                <option value="completed" <?php echo safe_get($order, 'status') === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="cancelled" <?php echo safe_get($order, 'status') === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                        
                        <div class="alert alert-info" style="margin-bottom: 0;">
                            <small>
                                <i class="fas fa-info-circle"></i>
                                Changing the order status will update the order immediately and may trigger notifications to the customer.
                            </small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn-custom btn-danger-custom" data-bs-dismiss="modal">
                            Cancel
                        </button>
                        <button type="submit" class="btn-custom btn-primary-custom">
                            <i class="fas fa-save"></i> Update Status
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Delete Order Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Delete Order</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="delete_order" value="1">
                        
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle"></i>
                            <strong>Warning!</strong> This action cannot be undone.
                        </div>
                        
                        <p>Are you sure you want to delete order <strong>#<?php echo str_pad(safe_get($order, 'id', '0'), 5, '0', STR_PAD_LEFT); ?></strong>?</p>
                        <p class="text-muted" style="margin-bottom: 0;">All order items and related data will be permanently removed from the system.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn-custom btn-info-custom" data-bs-dismiss="modal">
                            Cancel
                        </button>
                        <button type="submit" class="btn-custom btn-danger-custom">
                            <i class="fas fa-trash"></i> Delete Order
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
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
        
        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', (e) => {
            const sidebar = document.querySelector('.sidebar');
            if (window.innerWidth <= 768 && 
                sidebar.classList.contains('active') && 
                !sidebar.contains(e.target) && 
                !menuToggle.contains(e.target)) {
                sidebar.classList.remove('active');
            }
        });
        
        // Status Modal
        function openStatusModal() {
            const modal = new bootstrap.Modal(document.getElementById('statusModal'));
            modal.show();
        }
        
        // Delete Modal
        function openDeleteModal() {
            const modal = new bootstrap.Modal(document.getElementById('deleteModal'));
            modal.show();
        }
        
        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert-custom');
            alerts.forEach(alert => {
                alert.style.transition = 'opacity 0.5s ease';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);
        
        // Print functionality
        window.onbeforeprint = function() {
            document.body.style.background = 'white';
        };
        
        window.onafterprint = function() {
            document.body.style.background = '';
        };
    </script>
</body>
</html>