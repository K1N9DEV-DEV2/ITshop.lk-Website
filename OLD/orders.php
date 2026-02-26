<?php
// myorders.php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

include 'db.php';

$user_id = $_SESSION['user_id'];

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$search_query = $_GET['search'] ?? '';

// Build query
$query = "SELECT o.* FROM orders o WHERE o.user_id = ?";
$params = [$user_id];

if ($status_filter !== 'all') {
    $query .= " AND o.status = ?";
    $params[] = $status_filter;
}

if ($search_query) {
    $query .= " AND (o.id LIKE ? OR o.tracking_number LIKE ?)";
    $search_param = "%$search_query%";
    $params[] = $search_param;
    $params[] = $search_param;
}

$query .= " ORDER BY o.created_at DESC";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get order statistics for user
    $stats_query = "SELECT 
        COUNT(*) as total_orders,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
        SUM(CASE WHEN status = 'shipped' THEN 1 ELSE 0 END) as shipped,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
        SUM(CASE WHEN status != 'cancelled' THEN total_amount ELSE 0 END) as total_spent
        FROM orders WHERE user_id = ?";
    $stmt = $pdo->prepare($stats_query);
    $stmt->execute([$user_id]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get user info
    $stmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
} catch(PDOException $e) {
    error_log("Orders fetch error: " . $e->getMessage());
    $orders = [];
    $stats = ['total_orders' => 0, 'pending' => 0, 'processing' => 0, 'shipped' => 0, 'completed' => 0, 'cancelled' => 0, 'total_spent' => 0];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Orders - STC Electronics</title>
    
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
        
        /* Page Header */
        .page-header {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 2rem;
        }
        
        .page-header h1 {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 0.5rem;
        }
        
        .page-header p {
            color: var(--text-light);
            margin: 0;
        }
        
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            text-align: center;
            transition: transform 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
        }
        
        .stat-card-icon {
            width: 50px;
            height: 50px;
            margin: 0 auto 1rem;
            background: rgba(18, 184, 0, 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: var(--primary-color);
        }
        
        .stat-card h3 {
            font-size: 1.75rem;
            font-weight: 700;
            margin: 0 0 0.25rem 0;
            color: var(--text-dark);
        }
        
        .stat-card p {
            margin: 0;
            color: var(--text-light);
            font-size: 0.9rem;
            font-weight: 500;
        }
        
        /* Filter Section */
        .filter-section {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 2rem;
        }
        
        .filter-tabs {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-bottom: 1rem;
        }
        
        .filter-tab {
            padding: 0.625rem 1.25rem;
            border: 2px solid var(--border-color);
            background: white;
            border-radius: 8px;
            color: var(--text-dark);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }
        
        .filter-tab:hover {
            border-color: var(--primary-color);
            color: var(--primary-color);
        }
        
        .filter-tab.active {
            background: var(--primary-color);
            border-color: var(--primary-color);
            color: white;
        }
        
        .search-bar {
            display: flex;
            gap: 1rem;
        }
        
        .search-bar input {
            flex: 1;
            padding: 0.75rem 1rem;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            font-size: 0.9rem;
            transition: border-color 0.3s ease;
        }
        
        .search-bar input:focus {
            outline: none;
            border-color: var(--primary-color);
        }
        
        .btn-search {
            background: var(--primary-color);
            color: white;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .btn-search:hover {
            background: #0e7b02ff;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(18, 184, 0, 0.3);
        }
        
        .btn-reset {
            background: var(--secondary-color);
            color: white;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-weight: 500;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s ease;
        }
        
        .btn-reset:hover {
            background: #0070cc;
            color: white;
        }
        
        /* Orders Grid */
        .orders-grid {
            display: grid;
            gap: 1.5rem;
        }
        
        .order-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            overflow: hidden;
            transition: all 0.3s ease;
        }
        
        .order-card:hover {
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        
        .order-header {
            background: var(--bg-light);
            padding: 1.25rem 1.5rem;
            border-bottom: 2px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .order-id {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--text-dark);
        }
        
        .order-date {
            color: var(--text-light);
            font-size: 0.9rem;
        }
        
        .order-body {
            padding: 1.5rem;
        }
        
        .order-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .info-item label {
            display: block;
            font-size: 0.85rem;
            color: var(--text-light);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.25rem;
        }
        
        .info-item .value {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-dark);
        }
        
        /* Status Badges */
        .badge-custom {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.8rem;
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
        
        /* Order Actions */
        .order-actions {
            display: flex;
            gap: 0.75rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border-color);
        }
        
        .btn-action {
            flex: 1;
            padding: 0.75rem;
            border-radius: 8px;
            border: none;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
            text-decoration: none;
            display: block;
        }
        
        .btn-view {
            background: var(--secondary-color);
            color: white;
        }
        
        .btn-view:hover {
            background: #0070cc;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 140, 255, 0.3);
        }
        
        .btn-track {
            background: var(--primary-color);
            color: white;
        }
        
        .btn-track:hover {
            background: #0e7b02ff;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(18, 184, 0, 0.3);
        }
        
        .btn-track:disabled {
            background: var(--border-color);
            color: var(--text-light);
            cursor: not-allowed;
            transform: none;
        }
        
        /* Empty State */
        .empty-state {
            background: white;
            padding: 4rem 2rem;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            text-align: center;
        }
        
        .empty-state i {
            font-size: 4rem;
            color: var(--border-color);
            margin-bottom: 1.5rem;
        }
        
        .empty-state h3 {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.5rem;
        }
        
        .empty-state p {
            color: var(--text-light);
            margin-bottom: 2rem;
        }
        
        .btn-shop {
            background: var(--primary-color);
            color: white;
            padding: 0.875rem 2rem;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            display: inline-block;
            transition: all 0.3s ease;
        }
        
        .btn-shop:hover {
            background: #0e7b02ff;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(18, 184, 0, 0.3);
        }
        
        /* Responsive */
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
            
            .page-header h1 {
                font-size: 1.5rem;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-tabs {
                flex-direction: column;
            }
            
            .filter-tab {
                text-align: center;
            }
            
            .search-bar {
                flex-direction: column;
            }
            
            .order-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .order-info {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            
            .order-actions {
                flex-direction: column;
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
                    <img src="assets/revised-04.png" alt="STC Electronics">
                </a>
            </div>
            
            <nav class="user-menu">
                <a href="index.php"><i class="fas fa-home"></i> Home</a>
                <a href="profile.php"><i class="fas fa-user"></i> Profile</a>
                <a href="myorders.php"><i class="fas fa-shopping-bag"></i> Orders</a>
                <a href="logout.php" class="btn-logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </nav>
        </div>
    </header>
    
    <!-- Main Content -->
    <main class="container-custom">
        <!-- Page Header -->
        <div class="page-header">
            <h1>My Orders</h1>
            <p>Welcome back, <?php echo htmlspecialchars($user['full_name']); ?>! Track and manage your orders here.</p>
        </div>
        
        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-card-icon">
                    <i class="fas fa-shopping-bag"></i>
                </div>
                <h3><?php echo number_format($stats['total_orders']); ?></h3>
                <p>Total Orders</p>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <h3><?php echo number_format($stats['pending'] + $stats['processing']); ?></h3>
                <p>Active Orders</p>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <h3><?php echo number_format($stats['completed']); ?></h3>
                <p>Completed</p>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon">
                    <i class="fas fa-coins"></i>
                </div>
                <h3>LKR <?php echo number_format($stats['total_spent'], 0); ?></h3>
                <p>Total Spent</p>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="filter-section">
            <div class="filter-tabs">
                <a href="?status=all" class="filter-tab <?php echo $status_filter === 'all' ? 'active' : ''; ?>">
                    All Orders (<?php echo $stats['total_orders']; ?>)
                </a>
                <a href="?status=pending" class="filter-tab <?php echo $status_filter === 'pending' ? 'active' : ''; ?>">
                    Pending (<?php echo $stats['pending']; ?>)
                </a>
                <a href="?status=processing" class="filter-tab <?php echo $status_filter === 'processing' ? 'active' : ''; ?>">
                    Processing (<?php echo $stats['processing']; ?>)
                </a>
                <a href="?status=shipped" class="filter-tab <?php echo $status_filter === 'shipped' ? 'active' : ''; ?>">
                    Shipped (<?php echo $stats['shipped']; ?>)
                </a>
                <a href="?status=completed" class="filter-tab <?php echo $status_filter === 'completed' ? 'active' : ''; ?>">
                    Completed (<?php echo $stats['completed']; ?>)
                </a>
                <a href="?status=cancelled" class="filter-tab <?php echo $status_filter === 'cancelled' ? 'active' : ''; ?>">
                    Cancelled (<?php echo $stats['cancelled']; ?>)
                </a>
            </div>
            
            <form method="GET" class="search-bar">
                <input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>">
                <input type="text" name="search" placeholder="Search by Order ID or Tracking Number" 
                       value="<?php echo htmlspecialchars($search_query); ?>">
                <button type="submit" class="btn-search">
                    <i class="fas fa-search"></i> Search
                </button>
                <a href="myorders.php" class="btn-reset">
                    <i class="fas fa-redo"></i> Reset
                </a>
            </form>
        </div>
        
        <!-- Orders -->
        <div class="orders-grid">
            <?php if (empty($orders)): ?>
            <div class="empty-state">
                <i class="fas fa-shopping-bag"></i>
                <h3>No Orders Found</h3>
                <p>You haven't placed any orders yet. Start shopping now!</p>
                <a href="index.php" class="btn-shop">
                    <i class="fas fa-shopping-cart"></i> Start Shopping
                </a>
            </div>
            <?php else: ?>
            <?php foreach ($orders as $order): ?>
            <div class="order-card">
                <div class="order-header">
                    <div>
                        <div class="order-id">Order #<?php echo str_pad($order['id'], 5, '0', STR_PAD_LEFT); ?></div>
                        <div class="order-date">
                            <i class="far fa-calendar"></i>
                            Placed on <?php echo date('F d, Y', strtotime($order['created_at'])); ?>
                        </div>
                    </div>
                    <div>
                        <?php
                        $badge_class = 'badge-info';
                        if ($order['status'] === 'completed') $badge_class = 'badge-success';
                        elseif ($order['status'] === 'cancelled') $badge_class = 'badge-danger';
                        elseif ($order['status'] === 'processing') $badge_class = 'badge-warning';
                        elseif ($order['status'] === 'shipped') $badge_class = 'badge-info';
                        elseif ($order['status'] === 'pending') $badge_class = 'badge-secondary';
                        ?>
                        <span class="badge-custom <?php echo $badge_class; ?>">
                            <?php echo ucfirst($order['status']); ?>
                        </span>
                    </div>
                </div>
                
                <div class="order-body">
                    <div class="order-info">
                        <div class="info-item">
                            <label>Total Amount</label>
                            <div class="value">LKR <?php echo number_format($order['total_amount'], 2); ?></div>
                        </div>
                        <div class="info-item">
                            <label>Payment Method</label>
                            <div class="value"><?php echo ucfirst($order['payment_method']); ?></div>
                        </div>
                        <div class="info-item">
                            <label>Delivery Address</label>
                            <div class="value"><?php echo htmlspecialchars(substr($order['shipping_address'], 0, 30)); ?>...</div>
                        </div>
                        <?php if (!empty($order['tracking_number'])): ?>
                        <div class="info-item">
                            <label>Tracking Number</label>
                            <div class="value"><?php echo htmlspecialchars($order['tracking_number']); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="order-actions">
                        <a href="order-details.php?id=<?php echo $order['id']; ?>" class="btn-action btn-view">
                            <i class="fas fa-eye"></i> View Details
                        </a>
                        <?php if ($order['status'] === 'shipped' || $order['status'] === 'completed'): ?>
                        <a href="track-order.php?id=<?php echo $order['id']; ?>" class="btn-action btn-track">
                            <i class="fas fa-shipping-fast"></i> Track Order
                        </a>
                        <?php else: ?>
                        <button class="btn-action btn-track" disabled>
                            <i class="fas fa-shipping-fast"></i> Not Yet Shipped
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>
    
    <!-- Bootstrap JS -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
</body>
</html>