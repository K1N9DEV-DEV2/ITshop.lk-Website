<?php
// admin/orders.php
session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit();
}

include '../db.php';

// Handle order status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $order_id = $_POST['order_id'];
    $new_status = $_POST['status'];
    
    try {
        $stmt = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?");
        $stmt->execute([$new_status, $order_id]);
        $success_message = "Order status updated successfully!";
    } catch(PDOException $e) {
        $error_message = "Error updating order status: " . $e->getMessage();
    }
}

// Handle order deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_order'])) {
    $order_id = $_POST['order_id'];
    
    try {
        // Delete order items first
        $stmt = $pdo->prepare("DELETE FROM order_items WHERE order_id = ?");
        $stmt->execute([$order_id]);
        
        // Delete order
        $stmt = $pdo->prepare("DELETE FROM orders WHERE id = ?");
        $stmt->execute([$order_id]);
        
        $success_message = "Order deleted successfully!";
    } catch(PDOException $e) {
        $error_message = "Error deleting order: " . $e->getMessage();
    }
}

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$search_query = $_GET['search'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Build query
$query = "SELECT o.*, u.full_name, u.email, u.phone 
          FROM orders o 
          LEFT JOIN users u ON o.user_id = u.id 
          WHERE 1=1";

$params = [];

if ($status_filter !== 'all') {
    $query .= " AND o.status = ?";
    $params[] = $status_filter;
}

if ($search_query) {
    $query .= " AND (o.id LIKE ? OR u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
    $search_param = "%$search_query%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

if ($date_from) {
    $query .= " AND DATE(o.created_at) >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $query .= " AND DATE(o.created_at) <= ?";
    $params[] = $date_to;
}

$query .= " ORDER BY o.created_at DESC";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get order statistics
    $stats_query = "SELECT 
        COUNT(*) as total_orders,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
        SUM(CASE WHEN status = 'shipped' THEN 1 ELSE 0 END) as shipped,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
        SUM(CASE WHEN status != 'cancelled' THEN total_amount ELSE 0 END) as total_revenue
        FROM orders";
    $stats = $pdo->query($stats_query)->fetch(PDO::FETCH_ASSOC);
    
} catch(PDOException $e) {
    error_log("Orders fetch error: " . $e->getMessage());
    $orders = [];
    $stats = ['total_orders' => 0, 'pending' => 0, 'processing' => 0, 'shipped' => 0, 'completed' => 0, 'cancelled' => 0, 'total_revenue' => 0];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orders Management - STC Electronics</title>
    
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
        
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .stat-card-small {
            background: white;
            padding: 1.25rem;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            text-align: center;
            transition: transform 0.3s ease;
        }
        
        .stat-card-small:hover {
            transform: translateY(-3px);
        }
        
        .stat-card-small h4 {
            font-size: 1.5rem;
            font-weight: 700;
            margin: 0 0 0.25rem 0;
            color: var(--text-dark);
        }
        
        .stat-card-small p {
            margin: 0;
            color: var(--text-light);
            font-size: 0.85rem;
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
            padding: 0.5rem 1rem;
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
            flex-wrap: wrap;
        }
        
        .search-bar input,
        .search-bar select {
            flex: 1;
            min-width: 200px;
            padding: 0.625rem 1rem;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            font-size: 0.9rem;
            transition: border-color 0.3s ease;
        }
        
        .search-bar input:focus,
        .search-bar select:focus {
            outline: none;
            border-color: var(--primary-color);
        }
        
        /* Content Card */
        .content-card {
            background: white;
            border-radius: 12px;
            padding: 1.75rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
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
            vertical-align: middle;
        }
        
        .table-custom tbody tr:last-child td {
            border-bottom: none;
        }
        
        .table-custom tbody tr:hover {
            background: rgba(18, 184, 0, 0.02);
        }
        
        /* Status Badges */
        .badge-custom {
            padding: 0.4rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
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
        
        /* Buttons */
        .btn-custom {
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-weight: 500;
            font-size: 0.85rem;
            border: none;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .btn-sm {
            padding: 0.375rem 0.75rem;
            font-size: 0.8rem;
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
        
        .btn-info-custom {
            background: var(--secondary-color);
            color: white;
        }
        
        .btn-info-custom:hover {
            background: #0070cc;
        }
        
        .btn-danger-custom {
            background: var(--danger-color);
            color: white;
        }
        
        .btn-danger-custom:hover {
            background: #bb2d3b;
        }
        
        /* Alert Messages */
        .alert-custom {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            font-weight: 500;
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
        
        /* Modal Styles */
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
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .top-bar {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }
            
            .filter-tabs {
                flex-direction: column;
            }
            
            .search-bar {
                flex-direction: column;
            }
            
            .search-bar input,
            .search-bar select {
                min-width: 100%;
            }
            
            .table-custom {
                font-size: 0.8rem;
            }
            
            .table-custom thead {
                display: none;
            }
            
            .table-custom tbody tr {
                display: block;
                margin-bottom: 1rem;
                border: 1px solid var(--border-color);
                border-radius: 8px;
            }
            
            .table-custom tbody td {
                display: block;
                text-align: right;
                padding: 0.75rem;
                border-bottom: 1px solid var(--border-color);
            }
            
            .table-custom tbody td:last-child {
                border-bottom: none;
            }
            
            .table-custom tbody td::before {
                content: attr(data-label);
                float: left;
                font-weight: 600;
                text-transform: uppercase;
                font-size: 0.75rem;
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
            <h1 class="page-title">Orders Management</h1>
        </div>
        
        <!-- Alert Messages -->
        <?php if (isset($success_message)): ?>
        <div class="alert-custom alert-success">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo $success_message; ?>
        </div>
        <?php endif; ?>
        
        <?php if (isset($error_message)): ?>
        <div class="alert-custom alert-danger">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo $error_message; ?>
        </div>
        <?php endif; ?>
        
        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card-small">
                <h4><?php echo number_format($stats['total_orders']); ?></h4>
                <p>Total Orders</p>
            </div>
            <div class="stat-card-small">
                <h4><?php echo number_format($stats['pending']); ?></h4>
                <p>Pending</p>
            </div>
            <div class="stat-card-small">
                <h4><?php echo number_format($stats['processing']); ?></h4>
                <p>Processing</p>
            </div>
            <div class="stat-card-small">
                <h4><?php echo number_format($stats['shipped']); ?></h4>
                <p>Shipped</p>
            </div>
            <div class="stat-card-small">
                <h4><?php echo number_format($stats['completed']); ?></h4>
                <p>Completed</p>
            </div>
            <div class="stat-card-small">
                <h4>LKR <?php echo number_format($stats['total_revenue'], 0); ?></h4>
                <p>Total Revenue</p>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="filter-section">
            <div class="filter-tabs">
                <a href="?status=all" class="filter-tab <?php echo $status_filter === 'all' ? 'active' : ''; ?>">
                    All Orders
                </a>
                <a href="?status=pending" class="filter-tab <?php echo $status_filter === 'pending' ? 'active' : ''; ?>">
                    Pending
                </a>
                <a href="?status=processing" class="filter-tab <?php echo $status_filter === 'processing' ? 'active' : ''; ?>">
                    Processing
                </a>
                <a href="?status=shipped" class="filter-tab <?php echo $status_filter === 'shipped' ? 'active' : ''; ?>">
                    Shipped
                </a>
                <a href="?status=completed" class="filter-tab <?php echo $status_filter === 'completed' ? 'active' : ''; ?>">
                    Completed
                </a>
                <a href="?status=cancelled" class="filter-tab <?php echo $status_filter === 'cancelled' ? 'active' : ''; ?>">
                    Cancelled
                </a>
            </div>
            
            <form method="GET" class="search-bar">
                <input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>">
                <input type="text" name="search" placeholder="Search by Order ID, Customer Name, Email, or Phone" 
                       value="<?php echo htmlspecialchars($search_query); ?>">
                <input type="date" name="date_from" placeholder="From Date" 
                       value="<?php echo htmlspecialchars($date_from); ?>">
                <input type="date" name="date_to" placeholder="To Date" 
                       value="<?php echo htmlspecialchars($date_to); ?>">
                <button type="submit" class="btn-custom btn-primary-custom">
                    <i class="fas fa-search"></i> Search
                </button>
                <a href="orders.php" class="btn-custom btn-info-custom">
                    <i class="fas fa-redo"></i> Reset
                </a>
            </form>
        </div>
        
        <!-- Orders Table -->
        <div class="content-card">
            <div class="table-responsive">
                <table class="table-custom">
                    <thead>
                        <tr>
                            <th>Order ID</th>
                            <th>Customer</th>
                            <th>Contact</th>
                            <th>Date</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($orders)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-5">
                                <i class="fas fa-inbox fa-3x text-muted mb-3" style="display: block;"></i>
                                <p class="text-muted">No orders found</p>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($orders as $order): ?>
                        <tr>
                            <td data-label="Order ID">
                                <strong>#<?php echo str_pad($order['id'], 5, '0', STR_PAD_LEFT); ?></strong>
                            </td>
                            <td data-label="Customer">
                                <?php echo htmlspecialchars($order['full_name'] ?? 'Guest'); ?>
                            </td>
                            <td data-label="Contact">
                                <small>
                                    <?php echo htmlspecialchars($order['email'] ?? 'N/A'); ?><br>
                                    <?php echo htmlspecialchars($order['phone'] ?? 'N/A'); ?>
                                </small>
                            </td>
                            <td data-label="Date">
                                <?php echo date('M d, Y', strtotime($order['created_at'])); ?><br>
                                <small class="text-muted"><?php echo date('h:i A', strtotime($order['created_at'])); ?></small>
                            </td>
                            <td data-label="Amount">
                                <strong>LKR <?php echo number_format($order['total_amount'], 2); ?></strong>
                            </td>
                            <td data-label="Status">
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
                            </td>
                            <td data-label="Actions">
                                <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                                    <a href="order-details.php?id=<?php echo $order['id']; ?>" 
                                       class="btn-custom btn-info-custom btn-sm">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                    <button type="button" class="btn-custom btn-primary-custom btn-sm"
                                            onclick="openStatusModal(<?php echo $order['id']; ?>, '<?php echo $order['status']; ?>')">
                                        <i class="fas fa-edit"></i> Update
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
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
                        <input type="hidden" name="order_id" id="modal_order_id">
                        <input type="hidden" name="update_status" value="1">
                        
                        <div class="mb-3">
                            <label class="form-label">Order ID</label>
                            <input type="text" class="form-control" id="modal_order_display" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">New Status</label>
                            <select name="status" class="form-select" id="modal_status" required>
                                <option value="pending">Pending</option>
                                <option value="processing">Processing</option>
                                <option value="shipped">Shipped</option>
                                <option value="completed">Completed</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                        
                        <div class="alert alert-info">
                            <small>
                                <i class="fas fa-info-circle"></i>
                                Changing the order status will update the order immediately.
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
                        <input type="hidden" name="order_id" id="delete_order_id">
                        <input type="hidden" name="delete_order" value="1">
                        
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle"></i>
                            <strong>Warning!</strong> This action cannot be undone.
                        </div>
                        
                        <p>Are you sure you want to delete order <strong id="delete_order_display"></strong>?</p>
                        <p class="text-muted">All order items and related data will be permanently removed.</p>
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
        
        // Status Modal
        function openStatusModal(orderId, currentStatus) {
            document.getElementById('modal_order_id').value = orderId;
            document.getElementById('modal_order_display').value = '#' + String(orderId).padStart(5, '0');
            document.getElementById('modal_status').value = currentStatus;
            
            const modal = new bootstrap.Modal(document.getElementById('statusModal'));
            modal.show();
        }
        
        // Delete Modal
        function openDeleteModal(orderId) {
            document.getElementById('delete_order_id').value = orderId;
            document.getElementById('delete_order_display').textContent = '#' + String(orderId).padStart(5, '0');
            
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
    </script>
</body>
</html>