<?php
// admin/categories.php
session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header('Location: admin_login.php');
    exit();
}

include '../db.php';

// Handle category operations
$message = '';
$message_type = '';

// Add new category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_category'])) {
    $name = trim($_POST['name']);
    $description = trim($_POST['description']);
    
    if (!empty($name)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO categories (name, description) VALUES (?, ?)");
            $stmt->execute([$name, $description]);
            $message = 'Category added successfully!';
            $message_type = 'success';
        } catch(PDOException $e) {
            $message = 'Error adding category: ' . $e->getMessage();
            $message_type = 'danger';
        }
    } else {
        $message = 'Category name is required!';
        $message_type = 'warning';
    }
}

// Update category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_category'])) {
    $id = $_POST['category_id'];
    $name = trim($_POST['name']);
    $description = trim($_POST['description']);
    
    if (!empty($name)) {
        try {
            $stmt = $pdo->prepare("UPDATE categories SET name = ?, description = ? WHERE id = ?");
            $stmt->execute([$name, $description, $id]);
            $message = 'Category updated successfully!';
            $message_type = 'success';
        } catch(PDOException $e) {
            $message = 'Error updating category: ' . $e->getMessage();
            $message_type = 'danger';
        }
    } else {
        $message = 'Category name is required!';
        $message_type = 'warning';
    }
}

// Delete category
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    
    try {
        // Check if category has products
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM products WHERE category_id = ?");
        $stmt->execute([$id]);
        $product_count = $stmt->fetch()['count'];
        
        if ($product_count > 0) {
            $message = 'Cannot delete category with existing products! Please reassign or delete products first.';
            $message_type = 'danger';
        } else {
            $stmt = $pdo->prepare("DELETE FROM categories WHERE id = ?");
            $stmt->execute([$id]);
            $message = 'Category deleted successfully!';
            $message_type = 'success';
        }
    } catch(PDOException $e) {
        $message = 'Error deleting category: ' . $e->getMessage();
        $message_type = 'danger';
    }
}

// Get all categories with product count
try {
    $stmt = $pdo->query("SELECT c.*, COUNT(p.id) as product_count 
                         FROM categories c 
                         LEFT JOIN products p ON c.id = p.category_id 
                         GROUP BY c.id 
                         ORDER BY c.name ASC");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    error_log("Error fetching categories: " . $e->getMessage());
    $categories = [];
}

// Get category for editing
$edit_category = null;
if (isset($_GET['edit'])) {
    $edit_id = $_GET['edit'];
    try {
        $stmt = $pdo->prepare("SELECT * FROM categories WHERE id = ?");
        $stmt->execute([$edit_id]);
        $edit_category = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        error_log("Error fetching category: " . $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Categories - STC Electronics</title>
    
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
        
        /* Form Styles */
        .form-label {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }
        
        .form-control {
            border: 2px solid var(--border-color);
            border-radius: 8px;
            padding: 0.75rem;
            font-size: 0.95rem;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(18, 184, 0, 0.15);
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
        
        /* Buttons */
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
        
        .btn-secondary-custom {
            background: var(--text-light);
            color: white;
        }
        
        .btn-secondary-custom:hover {
            background: #5a6c7d;
        }
        
        .btn-warning-custom {
            background: var(--warning-color);
            color: white;
        }
        
        .btn-warning-custom:hover {
            background: #e56b0f;
        }
        
        .btn-danger-custom {
            background: var(--danger-color);
            color: white;
        }
        
        .btn-danger-custom:hover {
            background: #c82333;
        }
        
        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.85rem;
        }
        
        /* Alert Messages */
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
            border: 1px solid rgba(56, 161, 105, 0.3);
        }
        
        .alert-danger {
            background: rgba(220, 53, 69, 0.1);
            color: var(--danger-color);
            border: 1px solid rgba(220, 53, 69, 0.3);
        }
        
        .alert-warning {
            background: rgba(253, 126, 20, 0.1);
            color: var(--warning-color);
            border: 1px solid rgba(253, 126, 20, 0.3);
        }
        
        /* Badge */
        .badge-custom {
            padding: 0.4rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }
        
        .badge-primary {
            background: rgba(18, 184, 0, 0.1);
            color: var(--primary-color);
        }
        
        .badge-secondary {
            background: rgba(113, 128, 150, 0.1);
            color: var(--text-light);
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
        }
        
        .empty-state i {
            font-size: 4rem;
            color: var(--text-light);
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        
        .empty-state p {
            color: var(--text-light);
            font-size: 1.1rem;
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
            
            .table-responsive {
                overflow-x: auto;
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
                <a href="dashboard.php" class="sidebar-menu-link">
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
                <a href="categories.php" class="sidebar-menu-link active">
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
            <h1 class="page-title">
                <i class="fas fa-tags me-2"></i>Categories Management
            </h1>
        </div>
        
        <!-- Alert Messages -->
        <?php if ($message): ?>
        <div class="alert-custom alert-<?php echo $message_type; ?>">
            <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : ($message_type === 'danger' ? 'exclamation-circle' : 'exclamation-triangle'); ?>"></i>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
        <?php endif; ?>
        
        <!-- Add/Edit Category Form -->
        <div class="content-card">
            <div class="card-header-custom">
                <h2 class="card-title">
                    <?php echo $edit_category ? '<i class="fas fa-edit me-2"></i>Edit Category' : '<i class="fas fa-plus me-2"></i>Add New Category'; ?>
                </h2>
                <?php if ($edit_category): ?>
                <a href="categories.php" class="btn-custom btn-secondary-custom btn-sm">
                    <i class="fas fa-times me-1"></i> Cancel Edit
                </a>
                <?php endif; ?>
            </div>
            
            <form method="POST" action="">
                <?php if ($edit_category): ?>
                <input type="hidden" name="category_id" value="<?php echo $edit_category['id']; ?>">
                <?php endif; ?>
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="name" class="form-label">Category Name *</label>
                        <input type="text" 
                               class="form-control" 
                               id="name" 
                               name="name" 
                               value="<?php echo $edit_category ? htmlspecialchars($edit_category['name']) : ''; ?>"
                               placeholder="e.g., Laptops, Smartphones, Tablets" 
                               required>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label for="description" class="form-label">Description</label>
                        <input type="text" 
                               class="form-control" 
                               id="description" 
                               name="description" 
                               value="<?php echo $edit_category ? htmlspecialchars($edit_category['description']) : ''; ?>"
                               placeholder="Brief category description">
                    </div>
                </div>
                
                <div class="d-flex gap-2">
                    <button type="submit" 
                            name="<?php echo $edit_category ? 'update_category' : 'add_category'; ?>" 
                            class="btn-custom btn-primary-custom">
                        <i class="fas fa-<?php echo $edit_category ? 'save' : 'plus'; ?> me-2"></i>
                        <?php echo $edit_category ? 'Update Category' : 'Add Category'; ?>
                    </button>
                    
                    <?php if ($edit_category): ?>
                    <a href="categories.php" class="btn-custom btn-secondary-custom">
                        <i class="fas fa-times me-2"></i>Cancel
                    </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
        
        <!-- Categories List -->
        <div class="content-card">
            <div class="card-header-custom">
                <h2 class="card-title">
                    <i class="fas fa-list me-2"></i>All Categories
                </h2>
                <span class="badge-custom badge-primary">
                    <?php echo count($categories); ?> Total
                </span>
            </div>
            
            <div class="table-responsive">
                <?php if (empty($categories)): ?>
                <div class="empty-state">
                    <i class="fas fa-tags"></i>
                    <p>No categories found. Add your first category above!</p>
                </div>
                <?php else: ?>
                <table class="table-custom">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Description</th>
                            <th>Products</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($categories as $category): ?>
                        <tr>
                            <td><strong>#<?php echo $category['id']; ?></strong></td>
                            <td>
                                <strong><?php echo htmlspecialchars($category['name']); ?></strong>
                            </td>
                            <td>
                                <?php echo $category['description'] ? htmlspecialchars($category['description']) : '<span class="text-muted">No description</span>'; ?>
                            </td>
                            <td>
                                <span class="badge-custom <?php echo $category['product_count'] > 0 ? 'badge-primary' : 'badge-secondary'; ?>">
                                    <?php echo $category['product_count']; ?> product<?php echo $category['product_count'] != 1 ? 's' : ''; ?>
                                </span>
                            </td>
                            <td>
                                <?php echo date('M d, Y', strtotime($category['created_at'])); ?>
                            </td>
                            <td>
                                <div class="d-flex gap-2">
                                    <a href="categories.php?edit=<?php echo $category['id']; ?>" 
                                       class="btn-custom btn-warning-custom btn-sm"
                                       title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="categories.php?delete=<?php echo $category['id']; ?>" 
                                       class="btn-custom btn-danger-custom btn-sm"
                                       onclick="return confirm('Are you sure you want to delete this category? This action cannot be undone.');"
                                       title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
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
        
        // Auto-hide alert messages after 5 seconds
        const alerts = document.querySelectorAll('.alert-custom');
        alerts.forEach(alert => {
            setTimeout(() => {
                alert.style.transition = 'opacity 0.5s ease';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            }, 5000);
        });
    </script>
</body>
</html>