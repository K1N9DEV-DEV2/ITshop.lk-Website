<?php
// header.php - Reusable header/navbar for IT Shop.LK

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database connection
if (!isset($pdo)) {
    include __DIR__ . '/db.php';
}

// Get cart count and total for logged-in user
$cart_count = 0;
$user_currency = 'LKR';
$cart_total = 0;

if (isset($_SESSION['user_id']) && isset($pdo)) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count, SUM(price * quantity) as total FROM cart WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $cart_data = $stmt->fetch();
        $cart_count = $cart_data['count'] ?? 0;
        $cart_total = $cart_data['total'] ?? 0;
    } catch (PDOException $e) {
        // Handle gracefully
    }
}

// Allow pages to set a custom title; fallback to default
$page_title = $page_title ?? 'IT Shop.LK - Best Computer Store';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link rel="icon" type="image/x-icon" href="assets/logo.png">
    <meta name="description" content="Shop laptops, PCs, RAM, VGA cards, keyboards, mice and audio devices from IT Shop.LK">

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
        }

        /* Navbar */
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

        .navbar-nav .nav-link:hover,
        .navbar-nav .nav-link.active {
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
            background: #0b6c00ff;
            color: white;
        }

        @media (max-width: 768px) {
            .navbar-collapse {
                background: white;
                padding: 1rem;
                border-radius: 10px;
                margin-top: 1rem;
                box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            }
        }
    </style>
</head>
<body>

<!-- Navigation -->
<nav class="navbar navbar-expand-lg fixed-top">
    <div class="container">
        <a class="navbar-brand" href="index.php">
            <img src="assets/revised-04.png" alt="IT Shop.LK Logo">
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav mx-auto">
                <li class="nav-item">
                    <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) === 'index.php') ? 'active' : ''; ?>" href="index.php">Home</a>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?php echo (basename($_SERVER['PHP_SELF']) === 'products.php') ? 'active' : ''; ?>"
                       href="#" id="productsDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        Products
                    </a>
                    <ul class="dropdown-menu" aria-labelledby="productsDropdown">
                        <li><a class="dropdown-item" href="products.php?category=all">All Products</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="products.php?category=desktops">Casing</a></li>
                        <li><a class="dropdown-item" href="products.php?category=peripherals">Cooling &amp; Lighting</a></li>
                        <li><a class="dropdown-item" href="products.php?category=desktops">Desktop Computer</a></li>
                        <li><a class="dropdown-item" href="products.php?category=graphics">Graphic Cards</a></li>
                        <li><a class="dropdown-item" href="products.php?category=peripherals">Keyboards &amp; Mouse</a></li>
                        <li><a class="dropdown-item" href="products.php?category=laptops">Laptops</a></li>
                        <li><a class="dropdown-item" href="products.php?category=memory">Memory (RAM)</a></li>
                        <li><a class="dropdown-item" href="products.php?category=monitors">Monitors</a></li>
                        <li><a class="dropdown-item" href="products.php?category=motherboards">Motherboards</a></li>
                        <li><a class="dropdown-item" href="products.php?category=power">Power Supply</a></li>
                        <li><a class="dropdown-item" href="products.php?category=processors">Processors</a></li>
                        <li><a class="dropdown-item" href="products.php?category=audio">Speakers &amp; Headset</a></li>
                        <li><a class="dropdown-item" href="products.php?category=storage">Storage</a></li>
                    </ul>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="rapidventure.php">Rapidventure</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) === 'contact.php') ? 'active' : ''; ?>" href="contact.php">Contact</a>
                </li>
            </ul>

            <div class="d-flex align-items-center">
                <!-- Cart Icon -->
                <a href="cart.php" class="cart-icon" aria-label="View cart">
                    <i class="fas fa-shopping-cart"></i>
                    <?php if ($cart_count > 0): ?>
                        <span class="cart-count"><?php echo $cart_count; ?></span>
                    <?php endif; ?>
                </a>

                <!-- Cart Total -->
                <span class="me-3"><?php echo $user_currency . ' ' . number_format($cart_total); ?></span>

                <!-- User Account -->
                <?php if (isset($_SESSION['user_id'])): ?>
                    <div class="dropdown">
                        <button class="btn btn-login dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-user"></i> Account
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="profile.php"><i class="fas fa-id-card me-2"></i>My Profile</a></li>
                            <li><a class="dropdown-item" href="orders.php"><i class="fas fa-box me-2"></i>My Orders</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                        </ul>
                    </div>
                <?php else: ?>
                    <a href="/login.php" class="btn btn-login">
                        <i class="fas fa-sign-in-alt"></i> Login / Sign Up
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>
<!-- /Navigation -->