<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Set page title before header
$page_title = 'IT Shop.LK - Best Computer Store';

// Include header (handles session, db, navbar)
include 'header.php';
?>

<style>
    /* Hero Section */
    .hero-section {
        background: url('assets/275819.png') no-repeat center center / cover;
        min-height: 80vh;
        display: flex;
        align-items: center;
        color: white;
        position: relative;
        padding-top: 80px; /* offset fixed navbar */
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
        font-style: italic;
        margin-bottom: 1rem;
    }

    .hero-subtitle {
        font-size: 2rem;
        font-weight: 600;
        color: #ffffff;
        margin-bottom: 2rem;
        line-height: 1.3;
    }

    @media (max-width: 768px) {
        .hero-title    { font-size: 2.5rem; }
        .hero-subtitle { font-size: 1.5rem; }
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
        height: 100%;
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
</style>

<!-- ===== Hero Section ===== -->
<section class="hero-section">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-lg-6">
                <div class="hero-badge">Best Products From</div>
                <h1 class="hero-title">IT Shop.LK</h1>
                <p class="hero-subtitle">
                    Laptop, PC, RAM, VGA,<br>
                    Keyboard, Mouse and<br>
                    Best Audio devices...
                </p>
                <div class="mt-4">
                    <a href="products.php" class="btn btn-light btn-lg me-3">
                        <i class="fas fa-shopping-bag"></i> Shop Now
                    </a>
                    <a href="contact.php" class="btn btn-outline-light btn-lg">
                        <i class="fas fa-phone"></i> Contact Us
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>
<!-- /Hero Section -->

<!-- ===== Product Categories ===== -->
<section class="products-section">
    <div class="container">
        <div class="section-title">
            <h2>Our Product Categories</h2>
            <p class="lead">Explore our wide range of electronics and computer equipment</p>
        </div>

        <div class="row g-4">
            <?php
            $categories = [
                ['icon' => 'fa-laptop',     'title' => 'Laptops & Notebooks',  'desc' => 'High-performance laptops for gaming, business, and everyday use.',        'url' => 'products.php?category=laptops'],
                ['icon' => 'fa-desktop',    'title' => 'Desktop PCs',           'desc' => 'Custom built desktops and workstations for maximum performance.',          'url' => 'products.php?category=desktops'],
                ['icon' => 'fa-memory',     'title' => 'RAM & Storage',         'desc' => 'High-speed memory modules and storage solutions.',                          'url' => 'products.php?category=memory'],
                ['icon' => 'fa-tv',         'title' => 'Graphics Cards',        'desc' => 'Latest VGA cards for gaming and professional graphics work.',               'url' => 'products.php?category=graphics'],
                ['icon' => 'fa-keyboard',   'title' => 'Keyboards & Mice',      'desc' => 'Premium input devices for gaming and productivity.',                        'url' => 'products.php?category=peripherals'],
                ['icon' => 'fa-headphones', 'title' => 'Audio Devices',         'desc' => 'High-quality headphones, speakers, and audio equipment.',                  'url' => 'products.php?category=audio'],
            ];

            foreach ($categories as $cat): ?>
            <div class="col-lg-4 col-md-6">
                <div class="product-card">
                    <div class="product-icon">
                        <i class="fas <?php echo $cat['icon']; ?>"></i>
                    </div>
                    <h4><?php echo $cat['title']; ?></h4>
                    <p class="text-muted"><?php echo $cat['desc']; ?></p>
                    <a href="<?php echo $cat['url']; ?>" class="btn btn-outline-primary mt-2">View Products</a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<!-- /Product Categories -->

<?php
// Page-specific JS for scroll-in animation
$extra_scripts = <<<'JS'
<script>
    const observer = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
            }
        });
    }, { threshold: 0.1, rootMargin: '0px 0px -50px 0px' });

    document.querySelectorAll('.product-card').forEach(card => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(30px)';
        card.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
        observer.observe(card);
    });
</script>
JS;

include 'footer.php';
?>