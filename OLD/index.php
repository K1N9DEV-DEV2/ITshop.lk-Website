<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL); 

// Start session for user management
session_start();

// Database connection (adjust credentials as needed)
include 'db.php';


// Get cart count for logged-in user
$cart_count = 0;
$user_currency = 'LKR';
$cart_total = 0;

if (isset($_SESSION['user_id']) && $pdo) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count, SUM(price * quantity) as total FROM cart WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $cart_data = $stmt->fetch();
        $cart_count = $cart_data['count'] ?? 0;
        $cart_total = $cart_data['total'] ?? 0;
    } catch(PDOException $e) {
        // Handle error gracefully
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IT Shop.LK - Best Computer Store</title>
    <link rel="icon" type="image/x-icon" href="assets/logo.png">
    <meta name="description" content="Shop laptops, PCs, RAM, VGA cards, keyboards, mice and audio devices from Seoul Trading Company">
    
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
            background: #0b6c00ff;
            color: white;
        }
        
        /* Hero Section */
        .hero-section {
            background: url('assets/275819.png') no-repeat center center/cover;
            min-height: 80vh;
            display: flex;
            align-items: center;
            color: white;
            position: relative;
            overflow: hidden;
        }
        
        .hero-content {
            z-index: 2;
        }
        
        .hero-badge {
            background: var(--primary-color);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 5px;
            display: inline-block;
            font-weight: 600;
            margin-bottom: 1rem;
        }
        
        .hero-title {
            font-size: 3.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
            font-style: italic;
        }
        
        .hero-subtitle {
            font-size: 2rem;
            font-weight: 600;
            color: #ffffffff;
            margin-bottom: 2rem;
            line-height: 1.3;
        }
        
        .hero-image {
            position: relative;
            z-index: 2;
        }
        
        .laptop-showcase {
            max-width: 100%;
            height: auto;
            filter: drop-shadow(0 20px 40px rgba(0,0,0,0.3));
            animation: float 3s ease-in-out infinite;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-20px); }
        }
        
        /* WhatsApp Button */
        .whatsapp-btn {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #25d366;
            color: white;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            z-index: 1000;
            box-shadow: 0 4px 12px rgba(37, 211, 102, 0.4);
            transition: transform 0.3s ease;
            text-decoration: none;
        }
        
        .whatsapp-btn:hover {
            transform: scale(1.1);
            color: white;
        }
        
        /* Products Section */
        .products-section {
            padding: 5rem 0;
            background: var(--bg-light);
        }
        
        .section-title {
            text-align: center;
            margin-bottom: 3rem;
        }
        
        .section-title h2 {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
        }
        
        .product-card {
            background: white;
            border-radius: 10px;
            padding: 2rem;
            text-align: center;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            border: none;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        }
        
        .product-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 30px rgba(0,0,0,0.15);
        }
        
        .product-icon {
            font-size: 3rem;
            color: var(--secondary-color);
            margin-bottom: 1rem;
        }
        
        .product-card h4 {
            font-weight: 600;
            margin-bottom: 1rem;
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .hero-title {
                font-size: 2.5rem;
            }
            
            .hero-subtitle {
                font-size: 1.5rem;
            }
            
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
                <img src="assets/revised-04.png" alt="STC Logo">
            </a>
            
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav mx-auto">
                    <li class="nav-item">
                        <a class="nav-link active" href="index.php">Home</a>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="productsDropdown" role="button" data-bs-toggle="dropdown">
                            Products
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="products.php?category=all">All Products</a></li>
                            <li><a class="dropdown-item" href="products.php?category=desktops">Casing</a></li>
                            <li><a class="dropdown-item" href="products.php?category=peripherals">Cooling & Lighting</a></li>
                            <li><a class="dropdown-item" href="products.php?category=desktops">Desktop Computer</a></li>
                            <li><a class="dropdown-item" href="products.php?category=graphics">Graphic Cards</a></li>
                            <li><a class="dropdown-item" href="products.php?category=peripherals">Keyboards & Mouse</a></li>
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
                        <a class="nav-link" href="">Rapidventure</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="contact.php">Contact</a>
                    </li>
                </ul>
                
                <div class="d-flex align-items-center">
                    <a href="cart.php" class="cart-icon">
                        <i class="fas fa-shopping-cart"></i>
                        <?php if ($cart_count > 0): ?>
                        <span class="cart-count"><?php echo $cart_count; ?></span>
                        <?php endif; ?>
                    </a>
                    
                    <span class="me-3"><?php echo $user_currency . ' ' . number_format($cart_total); ?></span>
                    
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
                        <a href="/login.php" class="btn btn-login">
                            <i class="fas fa-sign-in-alt"></i> Login / Sign Up
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero-section">
        </div>
        
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-6 hero-content">
                    <div class="hero-badge">Best Products From</div>
                    <h1 class="hero-title">IT Shop.LK</h1>
                    <p class="hero-subtitle">Laptop, PC, RAM, VGA,<br>Keyboard, Mouse and<br>Best Audio devices...</p>
                    <div class="mt-4">
                        <a href="products.php" class="btn btn-light btn-lg me-3">
                            <i class="fas fa-shopping-bag"></i> Shop Now
                        </a>
                        <a href="contact.php" class="btn btn-outline-light btn-lg">
                            <i class="fas fa-phone"></i> Contact Us
                        </a>
                    </div>
                </div>
                <!--<div class="col-lg-6 hero-image text-center">
                    <div class="laptop-showcase">
                        <img src="laptop-showcase.jpg" alt="Gaming Laptops" class="img-fluid" style="max-width: 500px;" 
                             onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                         Fallback content if image doesn't load 
                        <div style="display:none; background: rgba(255,255,255,0.2); padding: 3rem; border-radius: 15px; backdrop-filter: blur(10px);">
                            <i class="fas fa-laptop" style="font-size: 5rem; margin-bottom: 1rem; display: block;"></i>
                            <h3>Premium Gaming Laptops</h3>
                            <p>High-performance machines for gaming and productivity</p>
                        </div>
                    </div>
                </div>-->
            </div>
        </div>
    </section>

    <!-- Products Section -->
    <section class="products-section">
        <div class="container">
            <div class="section-title">
                <h2>Our Product Categories</h2>
                <p class="lead">Explore our wide range of electronics and computer equipment</p>
            </div>
            
            <div class="row">
                <div class="col-lg-4 col-md-6 mb-4">
                    <div class="product-card">
                        <div class="product-icon">
                            <i class="fas fa-laptop"></i>
                        </div>
                        <h4>Laptops & Notebooks</h4>
                        <p>High-performance laptops for gaming, business, and everyday use</p>
                        <a href="products.php?category=laptops" class="btn btn-outline-primary">View Products</a>
                    </div>
                </div>
                
                <div class="col-lg-4 col-md-6 mb-4">
                    <div class="product-card">
                        <div class="product-icon">
                            <i class="fas fa-desktop"></i>
                        </div>
                        <h4>Desktop PCs</h4>
                        <p>Custom built desktops and workstations for maximum performance</p>
                        <a href="products.php?category=desktops" class="btn btn-outline-primary">View Products</a>
                    </div>
                </div>
                
                <div class="col-lg-4 col-md-6 mb-4">
                    <div class="product-card">
                        <div class="product-icon">
                            <i class="fas fa-memory"></i>
                        </div>
                        <h4>RAM & Storage</h4>
                        <p>High-speed memory modules and storage solutions</p>
                        <a href="products.php?category=memory" class="btn btn-outline-primary">View Products</a>
                    </div>
                </div>
                
                <div class="col-lg-4 col-md-6 mb-4">
                    <div class="product-card">
                        <div class="product-icon">
                            <i class="fas fa-tv"></i>
                        </div>
                        <h4>Graphics Cards</h4>
                        <p>Latest VGA cards for gaming and professional graphics work</p>
                        <a href="products.php?category=graphics" class="btn btn-outline-primary">View Products</a>
                    </div>
                </div>
                
                <div class="col-lg-4 col-md-6 mb-4">
                    <div class="product-card">
                        <div class="product-icon">
                            <i class="fas fa-keyboard"></i>
                        </div>
                        <h4>Keyboards & Mice</h4>
                        <p>Premium input devices for gaming and productivity</p>
                        <a href="products.php?category=peripherals" class="btn btn-outline-primary">View Products</a>
                    </div>
                </div>
                
                <div class="col-lg-4 col-md-6 mb-4">
                    <div class="product-card">
                        <div class="product-icon">
                            <i class="fas fa-headphones"></i>
                        </div>
                        <h4>Audio Devices</h4>
                        <p>High-quality headphones, speakers, and audio equipment</p>
                        <a href="products.php?category=audio" class="btn btn-outline-primary">View Products</a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- WhatsApp Button -->
    <a href="https://wa.me/your-whatsapp-number" class="whatsapp-btn" target="_blank" aria-label="Contact us on WhatsApp">
        <i class="fab fa-whatsapp"></i>
    </a>

    <!-- Footer -->
    <footer class="bg-dark text-light py-5">
        <div class="container">
            <div class="row">
                <div class="col-lg-4 mb-4">
                    <h5>IT Shop.LK</h5>
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
                        <li><a href="" class="text-light">Rapidventure</a></li>
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
                    <p><i class="fas fa-phone"></i> +94 77 900 5652</p>
                    <p><i class="fas fa-envelope"></i> info@itshop.lk</p>
                </div>
            </div>
            
            <hr class="my-4">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <p>&copy; <?php echo date('Y'); ?> IT Shop.LK All rights reserved.</p>
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
        // Smooth scrolling for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                document.querySelector(this.getAttribute('href')).scrollIntoView({
                    behavior: 'smooth'
                });
            });
        });

        // Add scroll effect to navbar
        window.addEventListener('scroll', function() {
            const navbar = document.querySelector('.navbar');
            if (window.scrollY > 100) {
                navbar.style.background = 'rgba(255, 255, 255, 0.95)';
                navbar.style.backdropFilter = 'blur(10px)';
            } else {
                navbar.style.background = 'white';
                navbar.style.backdropFilter = 'none';
            }
        });

        // Loading animation for product cards
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver(function(entries) {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, observerOptions);

        // Observe all product cards
        document.querySelectorAll('.product-card').forEach(card => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(30px)';
            card.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
            observer.observe(card);
        });
    </script>
</body>
</html>