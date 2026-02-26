<?php
// admin/reports.php
session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header('Location: admin_login.php');
    exit();
}

include '../db.php';

// Set default date range (last 30 days)
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-30 days'));
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

try {
    // Sales Overview
    $stmt = $pdo->prepare("SELECT 
        COUNT(*) as total_orders,
        SUM(total_amount) as total_revenue,
        AVG(total_amount) as avg_order_value,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_orders,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_orders
        FROM orders 
        WHERE DATE(created_at) BETWEEN ? AND ?");
    $stmt->execute([$start_date, $end_date]);
    $sales_overview = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Daily sales data for chart
    $stmt = $pdo->prepare("SELECT 
        DATE(created_at) as date,
        COUNT(*) as orders,
        SUM(total_amount) as revenue
        FROM orders 
        WHERE DATE(created_at) BETWEEN ? AND ? AND status != 'cancelled'
        GROUP BY DATE(created_at)
        ORDER BY date ASC");
    $stmt->execute([$start_date, $end_date]);
    $daily_sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Top customers
    $stmt = $pdo->prepare("SELECT 
        u.full_name, u.email,
        COUNT(o.id) as order_count,
        SUM(o.total_amount) as total_spent
        FROM orders o
        JOIN users u ON o.user_id = u.id
        WHERE DATE(o.created_at) BETWEEN ? AND ? AND o.status != 'cancelled'
        GROUP BY o.user_id
        ORDER BY total_spent DESC
        LIMIT 10");
    $stmt->execute([$start_date, $end_date]);
    $top_customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Category performance
    $stmt = $pdo->prepare("SELECT 
        c.name as category,
        COUNT(DISTINCT o.id) as orders,
        SUM(oi.quantity) as units_sold,
        SUM(oi.quantity * oi.price) as revenue
        FROM order_items oi
        JOIN products p ON oi.product_id = p.id
        JOIN categories c ON p.category_id = c.id
        JOIN orders o ON oi.order_id = o.id
        WHERE DATE(o.created_at) BETWEEN ? AND ? AND o.status != 'cancelled'
        GROUP BY p.category_id
        ORDER BY revenue DESC");
    $stmt->execute([$start_date, $end_date]);
    $category_performance = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Payment method breakdown
    $stmt = $pdo->prepare("SELECT 
        payment_method,
        COUNT(*) as count,
        SUM(total_amount) as revenue
        FROM orders 
        WHERE DATE(created_at) BETWEEN ? AND ? AND status != 'cancelled'
        GROUP BY payment_method");
    $stmt->execute([$start_date, $end_date]);
    $payment_methods = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Order status breakdown
    $stmt = $pdo->prepare("SELECT 
        status,
        COUNT(*) as count,
        SUM(total_amount) as revenue
        FROM orders 
        WHERE DATE(created_at) BETWEEN ? AND ?
        GROUP BY status");
    $stmt->execute([$start_date, $end_date]);
    $order_status = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch(PDOException $e) {
    error_log("Reports error: " . $e->getMessage());
    $sales_overview = ['total_orders' => 0, 'total_revenue' => 0, 'avg_order_value' => 0, 'completed_orders' => 0, 'cancelled_orders' => 0];
    $daily_sales = $top_customers = $category_performance = $payment_methods = $order_status = [];
}

// Prepare chart data
$chart_dates = [];
$chart_revenue = [];
$chart_orders = [];

foreach ($daily_sales as $day) {
    $chart_dates[] = date('M d', strtotime($day['date']));
    $chart_revenue[] = (float)$day['revenue'];
    $chart_orders[] = (int)$day['orders'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - Admin Dashboard</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Chart.js -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
    
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
        
        .page-title {
            margin: 0;
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--text-dark);
        }
        
        .date-filter {
            display: flex;
            gap: 1rem;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .date-filter input {
            padding: 0.5rem 1rem;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 0.9rem;
        }
        
        .btn-custom {
            padding: 0.5rem 1.25rem;
            border-radius: 8px;
            font-weight: 500;
            font-size: 0.9rem;
            border: none;
            transition: all 0.3s ease;
            cursor: pointer;
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
        
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
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
        
        .stat-icon.warning {
            background: linear-gradient(135deg, rgba(253, 126, 20, 0.1), rgba(253, 126, 20, 0.2));
            color: var(--warning-color);
        }
        
        .stat-icon.success {
            background: linear-gradient(135deg, rgba(56, 161, 105, 0.1), rgba(56, 161, 105, 0.2));
            color: var(--success-color);
        }
        
        /* Content Cards */
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
        
        /* Chart Container */
        .chart-container {
            position: relative;
            height: 350px;
            margin-top: 1rem;
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
        
        .table-custom tbody tr:last-child td {
            border-bottom: none;
        }
        
        .table-custom tbody tr:hover {
            background: rgba(18, 184, 0, 0.02);
        }
        
        /* Progress Bar */
        .progress-custom {
            height: 8px;
            background: var(--bg-light);
            border-radius: 10px;
            overflow: hidden;
            margin-top: 0.5rem;
        }
        
        .progress-bar-custom {
            height: 100%;
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
            border-radius: 10px;
            transition: width 0.3s ease;
        }
        
        .grid-2 {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 2rem;
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
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .grid-2 {
                grid-template-columns: 1fr;
            }
            
            .top-bar {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .date-filter {
                width: 100%;
            }
            
            .date-filter input {
                flex: 1;
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
                <a href="reports.php" class="sidebar-menu-link active">
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
            <h1 class="page-title">Sales Reports</h1>
            <form method="GET" class="date-filter">
                <input type="date" name="start_date" value="<?php echo $start_date; ?>" required>
                <input type="date" name="end_date" value="<?php echo $end_date; ?>" required>
                <button type="submit" class="btn-custom btn-primary-custom">
                    <i class="fas fa-filter"></i> Apply Filter
                </button>
            </form>
        </div>
        
        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-info">
                    <h3><?php echo number_format($sales_overview['total_orders'] ?? 0); ?></h3>
                    <p>Total Orders</p>
                </div>
                <div class="stat-icon info">
                    <i class="fas fa-shopping-cart"></i>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-info">
                    <h3>LKR <?php echo number_format($sales_overview['total_revenue'] ?? 0, 2); ?></h3>
                    <p>Total Revenue</p>
                </div>
                <div class="stat-icon primary">
                    <i class="fas fa-dollar-sign"></i>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-info">
                    <h3>LKR <?php echo number_format($sales_overview['avg_order_value'] ?? 0, 2); ?></h3>
                    <p>Average Order Value</p>
                </div>
                <div class="stat-icon warning">
                    <i class="fas fa-chart-line"></i>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-info">
                    <h3><?php echo number_format($sales_overview['completed_orders'] ?? 0); ?></h3>
                    <p>Completed Orders</p>
                </div>
                <div class="stat-icon success">
                    <i class="fas fa-check-circle"></i>
                </div>
            </div>
        </div>
        
        <!-- Sales Chart -->
        <div class="content-card">
            <div class="card-header-custom">
                <h2 class="card-title">Sales Trend</h2>
            </div>
            <div class="chart-container">
                <canvas id="salesChart"></canvas>
            </div>
        </div>
        
        <!-- Two Column Layout -->
        <div class="grid-2">
            <!-- Category Performance -->
            <div class="content-card">
                <div class="card-header-custom">
                    <h2 class="card-title">Category Performance</h2>
                </div>
                <div class="chart-container" style="height: 300px;">
                    <canvas id="categoryChart"></canvas>
                </div>
            </div>
            
            <!-- Payment Methods -->
            <div class="content-card">
                <div class="card-header-custom">
                    <h2 class="card-title">Payment Methods</h2>
                </div>
                <div class="chart-container" style="height: 300px;">
                    <canvas id="paymentChart"></canvas>
                </div>
            </div>
        </div>
        
        <!-- Top Customers -->
        <div class="content-card">
            <div class="card-header-custom">
                <h2 class="card-title">Top Customers</h2>
            </div>
            <div class="table-responsive">
                <table class="table-custom">
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Email</th>
                            <th>Orders</th>
                            <th>Total Spent</th>
                            <th>Performance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($top_customers)): ?>
                        <tr>
                            <td colspan="5" class="text-center py-4">
                                <i class="fas fa-users-slash fa-3x text-muted mb-3"></i>
                                <p class="text-muted">No customer data available</p>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php 
                        $max_spent = max(array_column($top_customers, 'total_spent'));
                        foreach ($top_customers as $customer): 
                            $percentage = ($customer['total_spent'] / $max_spent) * 100;
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($customer['full_name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($customer['email']); ?></td>
                            <td><?php echo $customer['order_count']; ?> orders</td>
                            <td><strong>LKR <?php echo number_format($customer['total_spent'], 2); ?></strong></td>
                            <td>
                                <div class="progress-custom">
                                    <div class="progress-bar-custom" style="width: <?php echo $percentage; ?>%"></div>
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
    
    <!-- Bootstrap JS -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Sales Trend Chart
        const salesCtx = document.getElementById('salesChart').getContext('2d');
        new Chart(salesCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($chart_dates); ?>,
                datasets: [{
                    label: 'Revenue (LKR)',
                    data: <?php echo json_encode($chart_revenue); ?>,
                    borderColor: '#12b800ff',
                    backgroundColor: 'rgba(18, 184, 0, 0.1)',
                    tension: 0.4,
                    fill: true,
                    yAxisID: 'y'
                }, {
                    label: 'Orders',
                    data: <?php echo json_encode($chart_orders); ?>,
                    borderColor: '#008cffff',
                    backgroundColor: 'rgba(0, 140, 255, 0.1)',
                    tension: 0.4,
                    fill: true,
                    yAxisID: 'y1'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: {
                        position: 'top',
                    }
                },
                scales: {
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        title: {
                            display: true,
                            text: 'Revenue (LKR)'
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'Orders'
                        },
                        grid: {
                            drawOnChartArea: false,
                        }
                    }
                }
            }
        });
        
        // Category Performance Chart
        const categoryCtx = document.getElementById('categoryChart').getContext('2d');
        new Chart(categoryCtx, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode(array_column($category_performance, 'category')); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_column($category_performance, 'revenue')); ?>,
                    backgroundColor: [
                        '#12b800ff',
                        '#008cffff',
                        '#fd7e14',
                        '#dc3545',
                        '#0dcaf0',
                        '#38a169'
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                    }
                }
            }
        });
        
        // Payment Methods Chart
        const paymentCtx = document.getElementById('paymentChart').getContext('2d');
        new Chart(paymentCtx, {
            type: 'pie',
            data: {
                labels: <?php echo json_encode(array_map('ucfirst', array_column($payment_methods, 'payment_method'))); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_column($payment_methods, 'count')); ?>,
                    backgroundColor: [
                        '#12b800ff',
                        '#008cffff',
                        '#fd7e14'
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                    }
                }
            }
        });
        
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