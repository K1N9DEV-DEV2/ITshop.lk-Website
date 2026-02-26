<?php
// admin/settings.php
session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header('Location: admin_login.php');
    exit();
}

include '../db.php';

$success_message = '';
$error_message = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Update site settings
        if (isset($_POST['update_site_settings'])) {
            $site_name = trim($_POST['site_name']);
            $site_email = trim($_POST['site_email']);
            $site_phone = trim($_POST['site_phone']);
            $site_address = trim($_POST['site_address']);
            
            // Update or insert settings
            $settings = [
                'site_name' => $site_name,
                'site_email' => $site_email,
                'site_phone' => $site_phone,
                'site_address' => $site_address
            ];
            
            foreach ($settings as $key => $value) {
                $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) 
                                      VALUES (?, ?) 
                                      ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute([$key, $value, $value]);
            }
            
            $success_message = "Site settings updated successfully!";
        }
        
        // Update admin password
        if (isset($_POST['update_password'])) {
            $current_password = $_POST['current_password'];
            $new_password = $_POST['new_password'];
            $confirm_password = $_POST['confirm_password'];
            
            // Verify current password
            $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ? AND role = 'admin'");
            $stmt->execute([$_SESSION['admin_id']]);
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!password_verify($current_password, $admin['password'])) {
                $error_message = "Current password is incorrect!";
            } elseif ($new_password !== $confirm_password) {
                $error_message = "New passwords do not match!";
            } elseif (strlen($new_password) < 6) {
                $error_message = "Password must be at least 6 characters long!";
            } else {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->execute([$hashed_password, $_SESSION['admin_id']]);
                $success_message = "Password updated successfully!";
            }
        }
        
        // Update email settings
        if (isset($_POST['update_email_settings'])) {
            $email_settings = [
                'smtp_host' => trim($_POST['smtp_host']),
                'smtp_port' => trim($_POST['smtp_port']),
                'smtp_username' => trim($_POST['smtp_username']),
                'smtp_password' => trim($_POST['smtp_password']),
                'order_notification_email' => trim($_POST['order_notification_email'])
            ];
            
            foreach ($email_settings as $key => $value) {
                $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) 
                                      VALUES (?, ?) 
                                      ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute([$key, $value, $value]);
            }
            
            $success_message = "Email settings updated successfully!";
        }
        
    } catch(PDOException $e) {
        error_log("Settings update error: " . $e->getMessage());
        $error_message = "An error occurred. Please try again.";
    }
}

// Fetch current settings
try {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
    $settings_data = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings_data[$row['setting_key']] = $row['setting_value'];
    }
    
    // Get admin info
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'admin'");
    $stmt->execute([$_SESSION['admin_id']]);
    $admin_info = $stmt->fetch(PDO::FETCH_ASSOC);
    
} catch(PDOException $e) {
    error_log("Settings fetch error: " . $e->getMessage());
    $settings_data = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - STC Electronics Admin</title>
    
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
        
        .admin-profile {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .admin-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1.1rem;
        }
        
        .admin-info h6 {
            margin: 0;
            font-size: 0.95rem;
            font-weight: 600;
            color: var(--text-dark);
        }
        
        .admin-info p {
            margin: 0;
            font-size: 0.8rem;
            color: var(--text-light);
        }
        
        /* Settings Cards */
        .settings-section {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 2rem;
        }
        
        .section-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--border-color);
        }
        
        .section-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            background: linear-gradient(135deg, rgba(18, 184, 0, 0.1), rgba(18, 184, 0, 0.2));
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary-color);
            font-size: 1.5rem;
        }
        
        .section-title {
            margin: 0;
            font-size: 1.4rem;
            font-weight: 600;
            color: var(--text-dark);
        }
        
        .section-subtitle {
            margin: 0;
            font-size: 0.9rem;
            color: var(--text-light);
        }
        
        /* Form Styles */
        .form-label {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }
        
        .form-control,
        .form-select {
            border: 2px solid var(--border-color);
            border-radius: 8px;
            padding: 0.75rem 1rem;
            font-size: 0.95rem;
            transition: all 0.3s ease;
        }
        
        .form-control:focus,
        .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(18, 184, 0, 0.1);
        }
        
        /* Buttons */
        .btn-custom {
            padding: 0.75rem 2rem;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.95rem;
            border: none;
            transition: all 0.3s ease;
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
            background: var(--text-dark);
        }
        
        /* Alerts */
        .alert-custom {
            border-radius: 10px;
            padding: 1rem 1.5rem;
            margin-bottom: 2rem;
            border: none;
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .alert-success-custom {
            background: rgba(56, 161, 105, 0.1);
            color: var(--success-color);
        }
        
        .alert-error-custom {
            background: rgba(220, 53, 69, 0.1);
            color: var(--danger-color);
        }
        
        /* Info Box */
        .info-box {
            background: rgba(0, 140, 255, 0.05);
            border-left: 4px solid var(--secondary-color);
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }
        
        .info-box i {
            color: var(--secondary-color);
            margin-right: 0.75rem;
        }
        
        .info-box p {
            margin: 0;
            color: var(--text-dark);
            font-size: 0.9rem;
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
            
            .settings-section {
                padding: 1.5rem;
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
                <a href="reports.php" class="sidebar-menu-link">
                    <i class="fas fa-chart-bar"></i>
                    <span class="sidebar-menu-text">Reports</span>
                </a>
            </li>
            <li class="sidebar-menu-item">
                <a href="settings.php" class="sidebar-menu-link active">
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
            <h1 class="page-title">Settings</h1>
            <div class="admin-profile">
                <div class="admin-avatar">A</div>
                <div class="admin-info">
                    <h6><?php echo htmlspecialchars($admin_info['full_name'] ?? 'Admin User'); ?></h6>
                    <p>Administrator</p>
                </div>
            </div>
        </div>
        
        <!-- Success/Error Messages -->
        <?php if ($success_message): ?>
        <div class="alert-custom alert-success-custom">
            <i class="fas fa-check-circle fa-lg"></i>
            <span><?php echo htmlspecialchars($success_message); ?></span>
        </div>
        <?php endif; ?>
        
        <?php if ($error_message): ?>
        <div class="alert-custom alert-error-custom">
            <i class="fas fa-exclamation-circle fa-lg"></i>
            <span><?php echo htmlspecialchars($error_message); ?></span>
        </div>
        <?php endif; ?>
        
        <!-- Site Settings -->
        <div class="settings-section">
            <div class="section-header">
                <div class="section-icon">
                    <i class="fas fa-globe"></i>
                </div>
                <div>
                    <h2 class="section-title">Site Settings</h2>
                    <p class="section-subtitle">Manage your website's general information</p>
                </div>
            </div>
            
            <form method="POST">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Site Name</label>
                        <input type="text" name="site_name" class="form-control" 
                               value="<?php echo htmlspecialchars($settings_data['site_name'] ?? 'STC Electronics'); ?>" required>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Contact Email</label>
                        <input type="email" name="site_email" class="form-control" 
                               value="<?php echo htmlspecialchars($settings_data['site_email'] ?? ''); ?>" required>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Contact Phone</label>
                        <input type="text" name="site_phone" class="form-control" 
                               value="<?php echo htmlspecialchars($settings_data['site_phone'] ?? ''); ?>" required>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Business Address</label>
                        <input type="text" name="site_address" class="form-control" 
                               value="<?php echo htmlspecialchars($settings_data['site_address'] ?? ''); ?>" required>
                    </div>
                </div>
                
                <button type="submit" name="update_site_settings" class="btn-custom btn-primary-custom">
                    <i class="fas fa-save me-2"></i>Save Changes
                </button>
            </form>
        </div>
        
        <!-- Security Settings -->
        <div class="settings-section">
            <div class="section-header">
                <div class="section-icon">
                    <i class="fas fa-shield-alt"></i>
                </div>
                <div>
                    <h2 class="section-title">Security Settings</h2>
                    <p class="section-subtitle">Update your password and security preferences</p>
                </div>
            </div>
            
            <div class="info-box">
                <i class="fas fa-info-circle"></i>
                <p>Use a strong password with at least 6 characters, including letters and numbers.</p>
            </div>
            
            <form method="POST">
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Current Password</label>
                        <input type="password" name="current_password" class="form-control" required>
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <label class="form-label">New Password</label>
                        <input type="password" name="new_password" class="form-control" minlength="6" required>
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Confirm New Password</label>
                        <input type="password" name="confirm_password" class="form-control" minlength="6" required>
                    </div>
                </div>
                
                <button type="submit" name="update_password" class="btn-custom btn-primary-custom">
                    <i class="fas fa-key me-2"></i>Update Password
                </button>
            </form>
        </div>
        
        <!-- Email Settings -->
        <div class="settings-section">
            <div class="section-header">
                <div class="section-icon">
                    <i class="fas fa-envelope"></i>
                </div>
                <div>
                    <h2 class="section-title">Email Settings</h2>
                    <p class="section-subtitle">Configure email notifications and SMTP settings</p>
                </div>
            </div>
            
            <form method="POST">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">SMTP Host</label>
                        <input type="text" name="smtp_host" class="form-control" 
                               value="<?php echo htmlspecialchars($settings_data['smtp_host'] ?? ''); ?>" 
                               placeholder="smtp.gmail.com">
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label class="form-label">SMTP Port</label>
                        <input type="number" name="smtp_port" class="form-control" 
                               value="<?php echo htmlspecialchars($settings_data['smtp_port'] ?? '587'); ?>" 
                               placeholder="587">
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label class="form-label">SMTP Username</label>
                        <input type="text" name="smtp_username" class="form-control" 
                               value="<?php echo htmlspecialchars($settings_data['smtp_username'] ?? ''); ?>" 
                               placeholder="your-email@gmail.com">
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label class="form-label">SMTP Password</label>
                        <input type="password" name="smtp_password" class="form-control" 
                               value="<?php echo htmlspecialchars($settings_data['smtp_password'] ?? ''); ?>" 
                               placeholder="••••••••">
                    </div>
                    
                    <div class="col-12 mb-3">
                        <label class="form-label">Order Notification Email</label>
                        <input type="email" name="order_notification_email" class="form-control" 
                               value="<?php echo htmlspecialchars($settings_data['order_notification_email'] ?? ''); ?>" 
                               placeholder="orders@stcelectronics.com">
                        <small class="text-muted">Email address to receive order notifications</small>
                    </div>
                </div>
                
                <button type="submit" name="update_email_settings" class="btn-custom btn-primary-custom">
                    <i class="fas fa-save me-2"></i>Save Email Settings
                </button>
            </form>
        </div>
        
        <!-- System Information -->
        <div class="settings-section">
            <div class="section-header">
                <div class="section-icon">
                    <i class="fas fa-info-circle"></i>
                </div>
                <div>
                    <h2 class="section-title">System Information</h2>
                    <p class="section-subtitle">View system details and version information</p>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6 mb-3">
                    <div class="p-3 border rounded">
                        <h6 class="text-muted mb-2">PHP Version</h6>
                        <p class="mb-0 fw-bold"><?php echo phpversion(); ?></p>
                    </div>
                </div>
                
                <div class="col-md-6 mb-3">
                    <div class="p-3 border rounded">
                        <h6 class="text-muted mb-2">Database Type</h6>
                        <p class="mb-0 fw-bold">MySQL</p>
                    </div>
                </div>
                
                <div class="col-md-6 mb-3">
                    <div class="p-3 border rounded">
                        <h6 class="text-muted mb-2">Application Version</h6>
                        <p class="mb-0 fw-bold">1.0.0</p>
                    </div>
                </div>
                
                <div class="col-md-6 mb-3">
                    <div class="p-3 border rounded">
                        <h6 class="text-muted mb-2">Server Software</h6>
                        <p class="mb-0 fw-bold"><?php echo $_SERVER['SERVER_SOFTWARE'] ?? 'N/A'; ?></p>
                    </div>
                </div>
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