<?php
// admin/customers.php
session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header('Location: admin_login.php');
    exit();
}

include '../db.php';

// Handle customer actions
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        $customer_id = $_POST['customer_id'] ?? null;
        
        try {
            if ($action === 'delete' && $customer_id) {
                // Delete customer - remove role filter if role column doesn't exist
                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$customer_id]);
                $message = 'Customer deleted successfully!';
                $message_type = 'success';
            }
            // Removed toggle_status since status column doesn't exist
        } catch(PDOException $e) {
            $message = 'Error: ' . $e->getMessage();
            $message_type = 'danger';
        }
    }
}

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 15;
$offset = ($page - 1) * $per_page;

// Search functionality
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$where_clause = "WHERE 1=1"; // Changed from role filter
$params = [];

if ($search) {
    $where_clause .= " AND (CONCAT(first_name, ' ', last_name) LIKE ? OR email LIKE ? OR phone LIKE ?)";
    $search_param = "%{$search}%";
    $params = [$search_param, $search_param, $search_param];
}

try {
    // Get total customers
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM users $where_clause");
    $stmt->execute($params);
    $total_customers = $stmt->fetch()['count'];
    $total_pages = ceil($total_customers / $per_page);
    
    // Get customers with order statistics
    $stmt = $pdo->prepare("
        SELECT u.*, 
               CONCAT(u.first_name, ' ', u.last_name) as full_name,
               COUNT(DISTINCT o.id) as total_orders,
               COALESCE(SUM(o.total_amount), 0) as total_spent,
               MAX(o.created_at) as last_order_date
        FROM users u
        LEFT JOIN orders o ON u.id = o.user_id
        $where_clause
        GROUP BY u.id
        ORDER BY u.created_at DESC
        LIMIT ? OFFSET ?
    ");
    
    $execute_params = array_merge($params, [$per_page, $offset]);
    $stmt->execute($execute_params);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get statistics
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
    $total_count = $stmt->fetch()['count'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
    $new_customers = $stmt->fetch()['count'];
    
    $stmt = $pdo->query("
        SELECT COUNT(DISTINCT user_id) as count 
        FROM orders 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $active_customers = $stmt->fetch()['count'];
    
} catch(PDOException $e) {
    error_log("Customers page error: " . $e->getMessage());
    $customers = [];
    $total_customers = 0;
    $total_pages = 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customers - Admin Panel - STC Electronics</title>
    
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
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            padding: 1.75rem;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        
        .stat-info h3 {
            font-size: 2rem;
            font-weight: 700;
            margin: 0 0 0.25rem 0;
            color: var(--text-dark);
        }
        
        .stat-info p {
            margin: 0;
            color: var(--text-light);
            font-size: 0.9rem;
            font-weight: 500;
        }
        
        .stat-icon {
            width: 70px;
            height: 70px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
        }
        
        .stat-icon.primary {
            background: linear-gradient(135deg, rgba(18, 184, 0, 0.1), rgba(18, 184, 0, 0.2));
            color: var(--primary-color);
        }
        
        .stat-icon.info {
            background: linear-gradient(135deg, rgba(0, 140, 255, 0.1), rgba(0, 140, 255, 0.2));
            color: var(--secondary-color);
        }
        
        .stat-icon.success {
            background: linear-gradient(135deg, rgba(56, 161, 105, 0.1), rgba(56, 161, 105, 0.2));
            color: var(--success-color);
        }
        
        /* Content Card */
        .content-card {
            background: white;
            border-radius: 12px;
            padding: 1.75rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        /* Search Bar */
        .search-bar {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }
        
        .search-input {
            flex: 1;
            min-width: 250px;
            padding: 0.75rem 1rem;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 0.95rem;
            transition: border-color 0.3s ease;
        }
        
        .search-input:focus {
            outline: none;
            border-color: var(--primary-color);
        }
        
        /* Buttons */
        .btn-custom {
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 500;
            font-size: 0.9rem;
            border: none;
            transition: all 0.3s ease;
            cursor: pointer;
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
        
        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.85rem;
        }
        
        .btn-danger-custom {
            background: var(--danger-color);
            color: white;
        }
        
        .btn-danger-custom:hover {
            background: #bb2d3b;
        }
        
        .btn-info-custom {
            background: var(--secondary-color);
            color: white;
        }
        
        .btn-info-custom:hover {
            background: #0077d4;
        }
        
        /* Table */
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
            white-space: nowrap;
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
        
        /* Customer Avatar */
        .customer-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1rem;
            margin-right: 1rem;
        }
        
        .customer-info {
            display: flex;
            align-items: center;
        }
        
        .customer-details h6 {
            margin: 0;
            font-weight: 600;
            color: var(--text-dark);
        }
        
        .customer-details p {
            margin: 0;
            font-size: 0.85rem;
            color: var(--text-light);
        }
        
        /* Badge */
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
        
        .badge-info {
            background: rgba(0, 140, 255, 0.1);
            color: var(--secondary-color);
        }
        
        /* Pagination */
        .pagination-custom {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 2rem;
            flex-wrap: wrap;
        }
        
        .page-link-custom {
            padding: 0.5rem 1rem;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: var(--text-dark);
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .page-link-custom:hover,
        .page-link-custom.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        
        /* Alert */
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
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .table-custom {
                font-size: 0.85rem;
            }
            
            .table-custom thead th,
            .table-custom tbody td {
                padding: 0.75rem 0.5rem;
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
                <a href="orders.php" class="sidebar-menu-link">
                    <i class="fas fa-shopping-cart"></i>
                    <span class="sidebar-menu-text">Orders</span>
                </a>
            </li>
            <li class="sidebar-menu-item">
                <a href="customers.php" class="sidebar-menu-link active">
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
            <h1 class="page-title">Customers</h1>
        </div>
        
        <?php if ($message): ?>
        <div class="alert-custom alert-<?php echo $message_type; ?>">
            <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
        <?php endif; ?>
        
        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-info">
                    <h3><?php echo number_format($total_count ?? 0); ?></h3>
                    <p>Total Customers</p>
                </div>
                <div class="stat-icon primary">
                    <i class="fas fa-users"></i>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-info">
                    <h3><?php echo number_format($new_customers ?? 0); ?></h3>
                    <p>New This Month</p>
                </div>
                <div class="stat-icon success">
                    <i class="fas fa-user-plus"></i>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-info">
                    <h3><?php echo number_format($active_customers ?? 0); ?></h3>
                    <p>Active Customers</p>
                </div>
                <div class="stat-icon info">
                    <i class="fas fa-user-check"></i>
                </div>
            </div>
        </div>
        
        <!-- Customers Table -->
        <div class="content-card">
            <!-- Search Bar -->
            <form method="GET" class="search-bar">
                <input type="text" 
                       name="search" 
                       class="search-input" 
                       placeholder="Search customers by name, email, or phone..." 
                       value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit" class="btn-custom btn-primary-custom">
                    <i class="fas fa-search"></i> Search
                </button>
                <?php if ($search): ?>
                <a href="customers.php" class="btn-custom btn-info-custom">
                    <i class="fas fa-times"></i> Clear
                </a>
                <?php endif; ?>
            </form>
            
            <!-- Table -->
            <div class="table-responsive">
                <table class="table-custom">
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Contact</th>
                            <th>Total Orders</th>
                            <th>Total Spent</th>
                            <th>Last Order</th>
                            <th>Joined</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($customers)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-4">
                                <i class="fas fa-users fa-3x text-muted mb-3"></i>
                                <p class="text-muted">
                                    <?php echo $search ? 'No customers found matching your search.' : 'No customers yet.'; ?>
                                </p>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($customers as $customer): ?>
                        <tr>
                            <td>
                                <div class="customer-info">
                                    <div class="customer-avatar">
                                        <?php echo strtoupper(substr($customer['full_name'] ?? 'U', 0, 1)); ?>
                                    </div>
                                    <div class="customer-details">
                                        <h6><?php echo htmlspecialchars($customer['full_name'] ?? 'N/A'); ?></h6>
                                        <p><?php echo htmlspecialchars($customer['email']); ?></p>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <?php if (!empty($customer['phone'])): ?>
                                <i class="fas fa-phone me-1"></i>
                                <?php echo htmlspecialchars($customer['phone']); ?>
                                <?php else: ?>
                                <span class="text-muted">N/A</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge-custom badge-info">
                                    <?php echo number_format($customer['total_orders']); ?> orders
                                </span>
                            </td>
                            <td>
                                <strong>LKR <?php echo number_format($customer['total_spent'], 2); ?></strong>
                            </td>
                            <td>
                                <?php if ($customer['last_order_date']): ?>
                                <?php echo date('M d, Y', strtotime($customer['last_order_date'])); ?>
                                <?php else: ?>
                                <span class="text-muted">No orders</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo date('M d, Y', strtotime($customer['created_at'])); ?></td>
                            <td>
                                <div class="d-flex gap-2">
                                    <a href="customer-details.php?id=<?php echo $customer['id']; ?>" 
                                       class="btn-custom btn-info-custom btn-sm"
                                       title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <form method="POST" style="display: inline;" 
                                          onsubmit="return confirm('Are you sure you want to delete this customer?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="customer_id" value="<?php echo $customer['id']; ?>">
                                        <button type="submit" 
                                                class="btn-custom btn-danger-custom btn-sm"
                                                title="Delete Customer">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="pagination-custom">
                <?php if ($page > 1): ?>
                <a href="?page=<?php echo $page - 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>" 
                   class="page-link-custom">
                    <i class="fas fa-chevron-left"></i> Previous
                </a>
                <?php endif; ?>
                
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <?php if ($i == 1 || $i == $total_pages || ($i >= $page - 2 && $i <= $page + 2)): ?>
                    <a href="?page=<?php echo $i; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>" 
                       class="page-link-custom <?php echo $i == $page ? 'active' : ''; ?>">
                        <?php echo $i; ?>
                    </a>
                    <?php elseif ($i == $page - 3 || $i == $page + 3): ?>
                    <span class="page-link-custom">...</span>
                    <?php endif; ?>
                <?php endfor; ?>
                
                <?php if ($page < $total_pages): ?>
                <a href="?page=<?php echo $page + 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>" 
                   class="page-link-custom">
                    Next <i class="fas fa-chevron-right"></i>
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
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
        
        // Auto-hide alerts after 5 seconds
        const alerts = document.querySelectorAll('.alert-custom');
        alerts.forEach(alert => {
            setTimeout(() => {
                alert.style.opacity = '0';
                alert.style.transition = 'opacity 0.5s ease';
                setTimeout(() => alert.remove(), 500);
            }, 5000);
        });
    </script>
</body>
</html>