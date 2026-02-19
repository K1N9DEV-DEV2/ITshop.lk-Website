<?php
// Start session for user management
session_start();

// Database connection
include 'db.php';
include 'header.php';

// Get product ID from URL
$product_id = isset($_GET['id']) ? intval($_GET['id']) : null;

if (!$product_id) {
    header('Location: products.php');
    exit();
}

// Get cart count for logged-in user
$cart_count = 0;
$user_currency = 'LKR';
$cart_total = 0;

if (isset($_SESSION['user_id']) && isset($pdo)) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count, SUM(price * quantity) as total FROM cart WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $cart_data = $stmt->fetch(PDO::FETCH_ASSOC);
        $cart_count = $cart_data['count'] ?? 0;
        $cart_total = $cart_data['total'] ?? 0;
    } catch(PDOException $e) {
        error_log("Cart error: " . $e->getMessage());
    }
}

// Fetch product details from database
try {
    $stmt = $pdo->prepare("
        SELECT 
            p.*,
            GROUP_CONCAT(CONCAT(ps.spec_name, ':', ps.spec_value) SEPARATOR '|') as specifications
        FROM products p
        LEFT JOIN product_specs ps ON p.id = ps.product_id
        WHERE p.id = ?
        GROUP BY p.id
    ");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$product) {
        header('Location: products.php');
        exit();
    }
    
    // Parse specifications
    $specs = [];
    if (!empty($product['specifications'])) {
        foreach (explode('|', $product['specifications']) as $spec) {
            if (strpos($spec, ':') !== false) {
                list($name, $value) = explode(':', $spec, 2);
                $specs[$name] = $value;
            }
        }
    }
    
    // Set default values for missing fields
    $product['rating']          = $product['rating']          ?? 0;
    $product['reviews']         = $product['reviews']         ?? 0;
    $product['brand']           = $product['brand']           ?? 'Unknown';
    $product['warranty']        = $product['warranty']        ?? '1 Year Warranty';
    $product['warranty_total']  = $product['warranty_total']  ?? 'Standard Warranty Included';
    $product['stock_count']     = $product['stock_count']     ?? 0;
    $product['in_stock']        = $product['in_stock']        ?? ($product['stock_count'] > 0);
    $product['original_price']  = $product['original_price']  ?? $product['price'];
    $product['short_description'] = $product['short_description'] ?? '';
    $product['description']     = $product['description']     ?? $product['short_description'];

} catch(PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    header('Location: products.php');
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($product['name']); ?> - STC Electronics Store</title>
    <meta name="description" content="<?php echo htmlspecialchars($product['short_description']); ?>">

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
            --success-color: #38a169;
            --warning-color: #d69e2e;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', sans-serif;
            line-height: 1.6;
            color: var(--text-dark);
            background: var(--bg-light);
        }

        /* Page Header */
        .page-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, #008cffff 100%);
            color: white;
            padding: 8rem 0 2rem;
            margin-top: 80px;
        }

        .breadcrumb { background: transparent; padding: 0; margin-bottom: 1rem; }
        .breadcrumb-item a { color: rgba(255,255,255,0.8); text-decoration: none; }
        .breadcrumb-item.active { color: white; }
        .breadcrumb-item + .breadcrumb-item::before { color: rgba(255,255,255,0.8); }

        /* Product Detail */
        .product-detail-section { padding: 3rem 0; }

        .product-images { position: sticky; top: 100px; }

        .main-image {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 1rem;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            height: 400px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .main-image img { max-width: 100%; max-height: 100%; object-fit: contain; }

        .thumbnail-images { display: flex; gap: 1rem; flex-wrap: wrap; }

        .thumbnail {
            width: 80px; height: 80px;
            border-radius: 10px;
            border: 2px solid transparent;
            cursor: pointer;
            transition: all 0.3s ease;
            background: white;
            padding: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .thumbnail:hover, .thumbnail.active { border-color: var(--primary-color); }
        .thumbnail img { width: 100%; height: 100%; object-fit: contain; }

        .product-info {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        }

        .product-brand {
            color: var(--text-light);
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
            text-transform: uppercase;
            font-weight: 600;
        }

        .product-title { font-size: 2rem; font-weight: 700; margin-bottom: 1rem; color: var(--text-dark); }

        .product-rating {
            display: flex;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid #e2e8f0;
        }

        .stars { color: #ffd700; margin-right: 0.5rem; font-size: 1.2rem; }
        .rating-text { color: var(--text-light); margin-right: 1rem; }
        .review-count { color: var(--secondary-color); cursor: pointer; }

        .price-section {
            background: var(--bg-light);
            padding: 1.5rem;
            border-radius: 10px;
            margin-bottom: 2rem;
        }

        .current-price { font-size: 2.5rem; font-weight: 700; color: var(--primary-color); }
        .original-price { font-size: 1.2rem; color: var(--text-light); text-decoration: line-through; margin-left: 1rem; }

        .discount-badge {
            background: var(--success-color);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 5px;
            font-weight: 600;
            display: inline-block;
            margin-top: 0.5rem;
        }

        .stock-info {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 1rem;
            background: #d4edda;
            border-radius: 8px;
            margin-bottom: 2rem;
        }
        .stock-info.low-stock { background: #fff3cd; }
        .stock-info.out-of-stock { background: #f8d7da; }
        .stock-info i { font-size: 1.2rem; }

        .warranty-info {
            background: #fff3cd;
            border-left: 4px solid var(--warning-color);
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 2rem;
        }
        .warranty-info h6 { color: #856404; font-weight: 600; margin-bottom: 0.5rem; }
        .warranty-info p { color: #856404; margin-bottom: 0; }

        .quantity-selector { display: flex; align-items: center; gap: 1rem; margin-bottom: 1.5rem; }
        .quantity-selector label { font-weight: 600; }

        .quantity-controls {
            display: flex;
            align-items: center;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            overflow: hidden;
        }
        .quantity-controls button {
            background: white;
            border: none;
            padding: 0.5rem 1rem;
            cursor: pointer;
            transition: background 0.3s ease;
        }
        .quantity-controls button:hover { background: var(--bg-light); }
        .quantity-controls input {
            border: none;
            width: 60px;
            text-align: center;
            padding: 0.5rem;
            font-weight: 600;
        }

        .action-buttons { display: flex; gap: 1rem; margin-bottom: 2rem; }

        .btn-add-cart {
            flex: 1;
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 1rem;
            border-radius: 8px;
            font-weight: 600;
            font-size: 1.1rem;
            transition: background 0.3s ease;
            cursor: pointer;
        }
        .btn-add-cart:hover { background: #0e7b02ff; }

        .btn-buy-now {
            flex: 1;
            background: var(--secondary-color);
            color: white;
            border: none;
            padding: 1rem;
            border-radius: 8px;
            font-weight: 600;
            font-size: 1.1rem;
            transition: background 0.3s ease;
            cursor: pointer;
        }
        .btn-buy-now:hover { background: #0066cc; }

        .btn-wishlist {
            background: white;
            border: 2px solid var(--text-light);
            color: var(--text-light);
            padding: 1rem;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .btn-wishlist:hover { border-color: #e74c3c; color: #e74c3c; }

        /* Tabs */
        .product-tabs { margin-top: 3rem; }
        .nav-tabs { border-bottom: 2px solid #e2e8f0; }
        .nav-tabs .nav-link {
            color: var(--text-light);
            font-weight: 600;
            padding: 1rem 1.5rem;
            border: none;
            border-bottom: 3px solid transparent;
        }
        .nav-tabs .nav-link.active {
            color: var(--primary-color);
            border-bottom-color: var(--primary-color);
            background: transparent;
        }

        .tab-content {
            background: white;
            padding: 2rem;
            border-radius: 0 0 15px 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        }

        .spec-table { width: 100%; }
        .spec-table tr { border-bottom: 1px solid #e2e8f0; }
        .spec-table td { padding: 1rem; }
        .spec-table td:first-child { font-weight: 600; color: var(--text-dark); width: 200px; }
        .spec-table td:last-child { color: var(--text-light); }

        .whatsapp-btn:hover { background: #128c7e !important; transform: scale(1.1); }

        /* Responsive */
        @media (max-width: 768px) {
            .page-header { padding: 6rem 0 2rem; }
            .product-title { font-size: 1.5rem; }
            .current-price { font-size: 2rem; }
            .action-buttons { flex-direction: column; }
            .product-images { position: static; }
        }
    </style>
</head>
<body>

    <!-- Page Header / Breadcrumb -->
    <section class="page-header">
        <div class="container">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                    <li class="breadcrumb-item"><a href="products.php">Products</a></li>
                    <li class="breadcrumb-item">
                        <a href="products.php?category=<?php echo urlencode($product['category']); ?>">
                            <?php echo htmlspecialchars(ucfirst($product['category'])); ?>
                        </a>
                    </li>
                    <li class="breadcrumb-item active"><?php echo htmlspecialchars($product['name']); ?></li>
                </ol>
            </nav>
        </div>
    </section>

    <!-- Product Detail Section -->
    <section class="product-detail-section">
        <div class="container">
            <div class="row">

                <!-- Product Images -->
                <div class="col-lg-5">
                    <div class="product-images">
                        <div class="main-image">
                            <img id="mainProductImage"
                                 src="<?php echo htmlspecialchars($product['image']); ?>"
                                 alt="<?php echo htmlspecialchars($product['name']); ?>"
                                 onerror="this.src='data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'300\' height=\'300\'%3E%3Crect width=\'300\' height=\'300\' fill=\'%23f0f0f0\'/%3E%3Ctext x=\'50%25\' y=\'50%25\' dominant-baseline=\'middle\' text-anchor=\'middle\' font-family=\'monospace\' font-size=\'26px\' fill=\'%23999\'%3EProduct Image%3C/text%3E%3C/svg%3E';">
                        </div>
                        <div class="thumbnail-images">
                            <div class="thumbnail active" onclick="changeImage(this, '<?php echo htmlspecialchars($product['image']); ?>')">
                                <img src="<?php echo htmlspecialchars($product['image']); ?>" alt="View 1">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Product Info -->
                <div class="col-lg-7">
                    <div class="product-info">
                        <div class="product-brand"><?php echo htmlspecialchars($product['brand']); ?></div>
                        <h1 class="product-title"><?php echo htmlspecialchars($product['name']); ?></h1>

                        <?php if (!empty($product['short_description'])): ?>
                        <p class="text-muted"><?php echo htmlspecialchars($product['short_description']); ?></p>
                        <?php endif; ?>

                        <!-- Rating -->
                        <div class="product-rating">
                            <div class="stars">
                                <?php
                                $rating = floatval($product['rating']);
                                for ($i = 1; $i <= 5; $i++) {
                                    if ($i <= floor($rating)) {
                                        echo '<i class="fas fa-star"></i>';
                                    } elseif ($i <= ceil($rating) && $rating > 0) {
                                        echo '<i class="fas fa-star-half-alt"></i>';
                                    } else {
                                        echo '<i class="far fa-star"></i>';
                                    }
                                }
                                ?>
                            </div>
                            <span class="rating-text"><?php echo number_format($rating, 1); ?></span>
                            <span class="review-count">(<?php echo intval($product['reviews']); ?> reviews)</span>
                        </div>

                        <!-- Price -->
                        <div class="price-section">
                            <div>
                                <span class="current-price">LKR <?php echo number_format($product['price']); ?></span>
                                <?php if ($product['original_price'] > $product['price']): ?>
                                <span class="original-price">LKR <?php echo number_format($product['original_price']); ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if ($product['original_price'] > $product['price']):
                                $discount = round((($product['original_price'] - $product['price']) / $product['original_price']) * 100);
                            ?>
                            <span class="discount-badge">Save <?php echo $discount; ?>%</span>
                            <?php endif; ?>
                        </div>

                        <!-- Stock -->
                        <?php if ($product['in_stock'] && $product['stock_count'] > 0): ?>
                            <?php if ($product['stock_count'] <= 5): ?>
                            <div class="stock-info low-stock">
                                <i class="fas fa-exclamation-triangle"></i>
                                <span><strong>Low Stock:</strong> Only <?php echo $product['stock_count']; ?> units left!</span>
                            </div>
                            <?php else: ?>
                            <div class="stock-info">
                                <i class="fas fa-check-circle"></i>
                                <span><strong>In Stock:</strong> <?php echo $product['stock_count']; ?> units available</span>
                            </div>
                            <?php endif; ?>
                        <?php else: ?>
                        <div class="stock-info out-of-stock">
                            <i class="fas fa-times-circle"></i>
                            <span><strong>Out of Stock</strong></span>
                        </div>
                        <?php endif; ?>

                        <!-- Warranty -->
                        <div class="warranty-info">
                            <h6><i class="fas fa-shield-alt"></i> Warranty Information</h6>
                            <p><?php echo htmlspecialchars($product['warranty']); ?></p>
                            <p><strong><?php echo htmlspecialchars($product['warranty_total']); ?></strong></p>
                        </div>

                        <!-- Quantity & Buttons -->
                        <?php if ($product['in_stock'] && $product['stock_count'] > 0): ?>
                        <div class="quantity-selector">
                            <label>Quantity:</label>
                            <div class="quantity-controls">
                                <button type="button" onclick="decrementQuantity()"><i class="fas fa-minus"></i></button>
                                <input type="number" id="quantity" value="1" min="1" max="<?php echo $product['stock_count']; ?>" readonly>
                                <button type="button" onclick="incrementQuantity(<?php echo $product['stock_count']; ?>)"><i class="fas fa-plus"></i></button>
                            </div>
                        </div>

                        <div class="action-buttons">
                            <button class="btn-add-cart" onclick="addToCart(<?php echo $product['id']; ?>)">
                                <i class="fas fa-shopping-cart"></i> Add to Cart
                            </button>
                            <button class="btn-buy-now" onclick="buyNow(<?php echo $product['id']; ?>)">
                                <i class="fas fa-bolt"></i> Buy Now
                            </button>
                            <button class="btn-wishlist" onclick="addToWishlist(<?php echo $product['id']; ?>)">
                                <i class="far fa-heart"></i>
                            </button>
                        </div>

                        <?php else: ?>
                        <div class="action-buttons">
                            <button class="btn-add-cart" disabled style="background:#ccc;cursor:not-allowed;">
                                <i class="fas fa-times"></i> Out of Stock
                            </button>
                            <button class="btn-wishlist" onclick="addToWishlist(<?php echo $product['id']; ?>)">
                                <i class="far fa-heart"></i> Add to Wishlist
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Product Tabs -->
            <div class="product-tabs">
                <ul class="nav nav-tabs" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link active" data-bs-toggle="tab" href="#specifications">
                            <i class="fas fa-list"></i> Specifications
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-bs-toggle="tab" href="#description">
                            <i class="fas fa-info-circle"></i> Description
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-bs-toggle="tab" href="#reviews">
                            <i class="fas fa-star"></i> Reviews (<?php echo $product['reviews']; ?>)
                        </a>
                    </li>
                </ul>

                <div class="tab-content">
                    <!-- Specifications Tab -->
                    <div id="specifications" class="tab-pane fade show active">
                        <h4 class="mb-4">Product Specifications</h4>
                        <?php if (!empty($specs)): ?>
                        <table class="spec-table">
                            <?php foreach ($specs as $spec_name => $spec_value): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($spec_name); ?></td>
                                <td><?php echo htmlspecialchars($spec_value); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </table>
                        <?php else: ?>
                        <p class="text-muted">No specifications available for this product.</p>
                        <?php endif; ?>
                    </div>

                    <!-- Description Tab -->
                    <div id="description" class="tab-pane fade">
                        <h4 class="mb-4">Product Description</h4>
                        <?php if (!empty($product['description'])): ?>
                        <p><?php echo nl2br(htmlspecialchars($product['description'])); ?></p>
                        <?php else: ?>
                        <p class="text-muted">No description available for this product.</p>
                        <?php endif; ?>
                    </div>

                    <!-- Reviews Tab -->
                    <div id="reviews" class="tab-pane fade">
                        <h4 class="mb-4">Customer Reviews</h4>
                        <div class="text-center py-5">
                            <i class="fas fa-star fa-3x text-warning mb-3"></i>
                            <h5>Be the first to review this product!</h5>
                            <p class="text-muted">Share your experience with other customers</p>
                            <button class="btn btn-primary mt-3" data-bs-toggle="modal" data-bs-target="#reviewModal">
                                <i class="fas fa-edit"></i> Write a Review
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- WhatsApp Float Button -->
    <a href="https://wa.me/94779005652"
       class="whatsapp-btn"
       target="_blank"
       aria-label="Contact us on WhatsApp"
       style="position:fixed;bottom:20px;right:20px;background:#25d366;color:white;border-radius:50%;width:60px;height:60px;display:flex;align-items:center;justify-content:center;font-size:30px;text-decoration:none;box-shadow:0 4px 12px rgba(37,211,102,0.4);z-index:1000;transition:all 0.3s ease;">
        <i class="fab fa-whatsapp"></i>
    </a>

    <!-- Review Modal -->
    <div class="modal fade" id="reviewModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Write a Review</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="reviewForm">
                        <div class="mb-3">
                            <label class="form-label">Your Rating</label>
                            <div class="rating-input">
                                <i class="far fa-star" data-rating="1"></i>
                                <i class="far fa-star" data-rating="2"></i>
                                <i class="far fa-star" data-rating="3"></i>
                                <i class="far fa-star" data-rating="4"></i>
                                <i class="far fa-star" data-rating="5"></i>
                            </div>
                            <input type="hidden" id="rating-value" value="0">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Review Title</label>
                            <input type="text" class="form-control" id="review-title" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Your Review</label>
                            <textarea class="form-control" id="review-text" rows="4" required></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="submitReview()">Submit Review</button>
                </div>
            </div>
        </div>
    </div>

    <?php include 'footer.php'; ?>

    <!-- Bootstrap JS -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>

    <script>
    // ── Quantity Controls ─────────────────────────────────────────
    function incrementQuantity(max) {
        const input = document.getElementById('quantity');
        if (input && parseInt(input.value) < max) input.value = parseInt(input.value) + 1;
    }

    function decrementQuantity() {
        const input = document.getElementById('quantity');
        if (input && parseInt(input.value) > 1) input.value = parseInt(input.value) - 1;
    }

    // ── Image Switcher ────────────────────────────────────────────
    function changeImage(thumbnail, imageSrc) {
        const mainImage = document.getElementById('mainProductImage');
        if (mainImage) mainImage.src = imageSrc;
        document.querySelectorAll('.thumbnail').forEach(t => t.classList.remove('active'));
        if (thumbnail) thumbnail.classList.add('active');
    }

    // ── Add to Cart ───────────────────────────────────────────────
    function addToCart(productId) {
        const quantity  = parseInt(document.getElementById('quantity')?.value ?? 1);
        const button    = event.target.closest('button');
        if (!button) return;

        const originalText = button.innerHTML;
        button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Adding...';
        button.disabled = true;

        fetch('add-to-cart.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ product_id: productId, quantity })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                button.innerHTML = '<i class="fas fa-check"></i> Added to Cart!';
                showToast('Product added to cart successfully!', 'success');
                if (data.cart_count !== undefined) updateCartDisplay(data.cart_count, data.cart_total);
                setTimeout(() => { button.innerHTML = originalText; button.disabled = false; }, 2000);
            } else {
                if (data.redirect) {
                    showToast('Please login to add items to cart', 'error');
                    setTimeout(() => window.location.href = data.redirect, 1500);
                    return;
                }
                showToast(data.message || 'Error adding product to cart', 'error');
                button.innerHTML = originalText;
                button.disabled = false;
            }
        })
        .catch(() => {
            showToast('Network error. Please try again.', 'error');
            button.innerHTML = originalText;
            button.disabled = false;
        });
    }

    // ── Buy Now ───────────────────────────────────────────────────
    function buyNow(productId) {
        const quantity = parseInt(document.getElementById('quantity')?.value ?? 1);

        fetch('add-to-cart.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ product_id: productId, quantity })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                window.location.href = 'checkout.php';
            } else {
                if (data.redirect) {
                    showToast('Please login to continue', 'error');
                    setTimeout(() => window.location.href = data.redirect, 1500);
                } else {
                    showToast(data.message || 'Error processing request', 'error');
                }
            }
        })
        .catch(() => showToast('Network error. Please try again.', 'error'));
    }

    // ── Wishlist ──────────────────────────────────────────────────
    function addToWishlist(productId) {
        const button = event.target.closest('button');
        if (!button) return;
        const icon = button.querySelector('i');

        fetch('add-to-wishlist.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ product_id: productId })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                icon?.classList.replace('far', 'fas');
                button.style.borderColor = '#e74c3c';
                button.style.color       = '#e74c3c';
                showToast('Added to wishlist!', 'success');
            } else {
                if (data.redirect) {
                    showToast('Please login to add items to wishlist', 'error');
                    setTimeout(() => window.location.href = data.redirect, 1500);
                } else {
                    showToast(data.message || 'Error adding to wishlist', 'error');
                }
            }
        })
        .catch(() => showToast('Network error. Please try again.', 'error'));
    }

    // ── Update Cart Badge / Total ─────────────────────────────────
    function updateCartDisplay(cartCount, cartTotal) {
        const badge   = document.querySelector('.cart-count');
        const cartIcon = document.querySelector('.cart-icon');

        if (cartCount > 0) {
            if (badge) {
                badge.textContent = cartCount;
            } else if (cartIcon) {
                const b = document.createElement('span');
                b.className   = 'cart-count';
                b.textContent = cartCount;
                cartIcon.appendChild(b);
            }
        }

        document.querySelectorAll('.me-3').forEach(el => {
            if (el.textContent.includes('LKR'))
                el.textContent = 'LKR ' + new Intl.NumberFormat().format(cartTotal);
        });
    }

    // ── Toast Notification ────────────────────────────────────────
    function showToast(message, type = 'info', duration = 4000) {
        document.querySelectorAll('.custom-toast').forEach(t => t.remove());

        if (!document.querySelector('#toast-animations')) {
            const s = document.createElement('style');
            s.id = 'toast-animations';
            s.textContent = `
                @keyframes slideInRight  { from { transform:translateX(100%);opacity:0 } to { transform:translateX(0);opacity:1 } }
                @keyframes slideOutRight { from { transform:translateX(0);opacity:1 } to { transform:translateX(100%);opacity:0 } }
            `;
            document.head.appendChild(s);
        }

        const iconMap  = { success: 'check-circle', error: 'exclamation-circle', info: 'info-circle' };
        const classMap = { success: 'success',       error: 'danger',             info: 'info'         };

        const toast = document.createElement('div');
        toast.className  = `alert alert-${classMap[type] ?? 'info'} alert-dismissible custom-toast`;
        toast.style.cssText = 'position:fixed;top:100px;right:20px;z-index:9999;min-width:300px;max-width:400px;box-shadow:0 4px 12px rgba(0,0,0,0.15);border-radius:8px;animation:slideInRight 0.3s ease-out';
        toast.innerHTML  = `<i class="fas fa-${iconMap[type] ?? 'info-circle'} me-2"></i>${message}<button type="button" class="btn-close" aria-label="Close"></button>`;

        document.body.appendChild(toast);

        const timer = setTimeout(() => {
            toast.style.animation = 'slideOutRight 0.3s ease-in';
            setTimeout(() => toast.remove(), 300);
        }, duration);

        toast.querySelector('.btn-close').addEventListener('click', () => {
            clearTimeout(timer);
            toast.style.animation = 'slideOutRight 0.3s ease-in';
            setTimeout(() => toast.remove(), 300);
        });
    }

    // ── Review Modal Stars ────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', function () {
        const ratingStars = document.querySelectorAll('.rating-input i');
        let selectedRating = 0;

        function updateRatingDisplay(rating) {
            ratingStars.forEach(star => {
                const on = star.getAttribute('data-rating') <= rating;
                star.classList.toggle('fas', on);
                star.classList.toggle('far', !on);
                star.style.color = on ? '#ffd700' : '#ccc';
            });
        }

        ratingStars.forEach(star => {
            star.addEventListener('click', function () {
                selectedRating = this.getAttribute('data-rating');
                document.getElementById('rating-value').value = selectedRating;
                updateRatingDisplay(selectedRating);
            });
            star.addEventListener('mouseenter', function () {
                updateRatingDisplay(this.getAttribute('data-rating'));
            });
        });

        document.querySelector('.rating-input')?.addEventListener('mouseleave', () => {
            updateRatingDisplay(selectedRating);
        });

        // Image zoom on hover
        const mainImage = document.getElementById('mainProductImage');
        if (mainImage) {
            mainImage.addEventListener('mouseenter', function () {
                this.style.transform  = 'scale(1.1)';
                this.style.transition = 'transform 0.3s ease';
            });
            mainImage.addEventListener('mouseleave', function () {
                this.style.transform = 'scale(1)';
            });
        }
    });

    // ── Submit Review ─────────────────────────────────────────────
    function submitReview() {
        const rating = document.getElementById('rating-value').value;
        const title  = document.getElementById('review-title').value;
        const text   = document.getElementById('review-text').value;

        if (rating == 0)       { showToast('Please select a rating', 'error');     return; }
        if (!title || !text)   { showToast('Please fill in all fields', 'error');   return; }

        showToast('Thank you for your review!', 'success');

        bootstrap.Modal.getInstance(document.getElementById('reviewModal'))?.hide();
        document.getElementById('reviewForm').reset();
        document.getElementById('rating-value').value = 0;
    }
    </script>
</body>
</html>