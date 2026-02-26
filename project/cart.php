<?php
// Start session for user management
session_start();

// Database connection
include 'db.php';

// Redirect to login if user is not logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=cart.php');
    exit();
}

$user_id       = $_SESSION['user_id'];
$user_currency = 'LKR';
$cart_items    = [];
$cart_total    = 0;
$cart_count    = 0;
$subtotal      = 0;
$shipping_cost = 0;

// Handle cart actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action     = $_POST['action']     ?? '';
    $product_id = (int)($_POST['product_id'] ?? 0);
    $quantity   = (int)($_POST['quantity']   ?? 1);

    try {
        switch ($action) {
            case 'update_quantity':
                if ($quantity > 0) {
                    $stmt = $pdo->prepare("UPDATE cart SET quantity = ? WHERE user_id = ? AND product_id = ?");
                    $stmt->execute([$quantity, $user_id, $product_id]);
                } else {
                    $stmt = $pdo->prepare("DELETE FROM cart WHERE user_id = ? AND product_id = ?");
                    $stmt->execute([$user_id, $product_id]);
                }
                break;

            case 'remove_item':
                $stmt = $pdo->prepare("DELETE FROM cart WHERE user_id = ? AND product_id = ?");
                $stmt->execute([$user_id, $product_id]);
                break;

            case 'clear_cart':
                $stmt = $pdo->prepare("DELETE FROM cart WHERE user_id = ?");
                $stmt->execute([$user_id]);
                break;
        }

        header('Location: cart.php');
        exit();

    } catch (PDOException $e) {
        $error_message = "Error updating cart: " . $e->getMessage();
    }
}

// Fetch cart items with product details
try {
    $stmt = $pdo->prepare("
        SELECT
            c.product_id,
            c.quantity,
            c.price AS cart_price,
            p.name,
            p.brand,
            p.image,
            p.price AS current_price,
            p.original_price,
            p.stock_count,
            p.in_stock,
            p.category
        FROM cart c
        JOIN products p ON c.product_id = p.id
        WHERE c.user_id = ?
        ORDER BY c.created_at DESC
    ");
    $stmt->execute([$user_id]);
    $cart_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($cart_items as &$item) {
        $item_total               = $item['current_price'] * $item['quantity'];
        $item['item_total']       = $item_total;
        $subtotal                += $item_total;
        $cart_count              += $item['quantity'];
        $item['price_changed']    = ($item['cart_price'] != $item['current_price']);
        $item['sufficient_stock'] = ($item['in_stock'] && $item['stock_count'] >= $item['quantity']);
    }
    unset($item);

    if (!isset($tax_rate)) $tax_rate = 0;
    $tax_amount = $subtotal * $tax_rate;
    $cart_total = $subtotal + $tax_amount + ($subtotal > 0 ? $shipping_cost : 0);

} catch (PDOException $e) {
    $error_message = "Error fetching cart items: " . $e->getMessage();
    $cart_items    = [];
}

// Get recommended products
$recommended_products = [];
try {
    if (!empty($cart_items)) {
        $categories   = array_unique(array_column($cart_items, 'category'));
        $placeholders = str_repeat('?,', count($categories) - 1) . '?';

        $stmt = $pdo->prepare("
            SELECT id, name, brand, price, original_price, image, rating, category
            FROM products
            WHERE category IN ($placeholders)
              AND in_stock = 1
              AND id NOT IN (SELECT product_id FROM cart WHERE user_id = ?)
            ORDER BY rating DESC, reviews DESC
            LIMIT 4
        ");
        $stmt->execute(array_merge($categories, [$user_id]));
        $recommended_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    // Silently fall back
}

$page_title       = 'Shopping Cart - STC Electronics Store';
$page_description = 'Review your selected items and proceed to checkout';

include 'header.php';

// Build cart items JSON for JS
$cart_items_json = json_encode(array_map(function($item) use ($user_currency) {
    return [
        'name'       => $item['name'],
        'brand'      => $item['brand'],
        'quantity'   => $item['quantity'],
        'price'      => $item['current_price'],
        'item_total' => $item['item_total'],
        'currency'   => $user_currency,
    ];
}, $cart_items));
?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">

<!-- Page Header -->
<section class="page-header">
    <div class="container">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                <li class="breadcrumb-item active">Shopping Cart</li>
            </ol>
        </nav>
        <div class="header-content">
            <div class="header-icon-wrap">
                <i class="fas fa-shopping-bag"></i>
            </div>
            <div>
                <h1>Your Cart</h1>
                <p class="lead">Review your items and proceed to checkout</p>
            </div>
        </div>
    </div>
</section>

<!-- Cart Section -->
<section class="cart-section">
    <div class="container">

        <?php if (isset($error_message)): ?>
        <div class="alert-pill alert-pill-danger">
            <i class="fas fa-exclamation-circle"></i>
            <?php echo htmlspecialchars($error_message); ?>
            <button type="button" class="pill-close" onclick="this.parentElement.remove()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <?php endif; ?>

        <?php if (empty($cart_items)): ?>
        <!-- Empty Cart -->
        <div class="empty-cart fade-in">
            <div class="empty-bag-visual">
                <div class="bag-circle">
                    <i class="fas fa-shopping-bag"></i>
                </div>
                <div class="empty-dots">
                    <span></span><span></span><span></span>
                </div>
            </div>
            <h3>Your cart is empty</h3>
            <p>Looks like you haven't added anything yet. Let's fix that!</p>
            <a href="products.php" class="btn-primary-pill">
                <i class="fas fa-arrow-right me-2"></i>Start Shopping
            </a>
        </div>

        <?php else: ?>

        <div class="row g-4">
            <!-- Cart Items -->
            <div class="col-lg-8">
                <div class="section-header">
                    <div class="section-badge">
                        <span><?php echo $cart_count; ?></span>
                        <?php echo $cart_count === 1 ? 'item' : 'items'; ?>
                    </div>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="action" value="clear_cart">
                        <button type="submit" class="btn-ghost-danger"
                                onclick="return confirm('Clear your entire cart?')">
                            <i class="fas fa-trash-alt me-1"></i>Clear all
                        </button>
                    </form>
                </div>

                <?php foreach ($cart_items as $item): ?>
                <div class="cart-card fade-in">
                    <div class="cart-card-inner">
                        <!-- Product Image -->
                        <div class="product-thumb">
                            <img src="<?php echo htmlspecialchars($item['image']); ?>"
                                 alt="<?php echo htmlspecialchars($item['name']); ?>"
                                 onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                            <div class="thumb-placeholder" style="display:none;">
                                <i class="fas fa-laptop"></i>
                            </div>
                        </div>

                        <!-- Product Info -->
                        <div class="product-info">
                            <div class="product-meta">
                                <span class="product-brand"><?php echo htmlspecialchars($item['brand']); ?></span>
                                <?php if ($item['price_changed']): ?>
                                    <span class="price-pill">Price Updated</span>
                                <?php endif; ?>
                            </div>
                            <h5 class="product-name"><?php echo htmlspecialchars($item['name']); ?></h5>

                            <?php if (!$item['sufficient_stock']): ?>
                            <div class="stock-alert">
                                <i class="fas fa-exclamation-triangle"></i>
                                <?php if (!$item['in_stock']): ?>
                                    Out of stock
                                <?php else: ?>
                                    Only <?php echo $item['stock_count']; ?> left (<?php echo $item['quantity']; ?> in cart)
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>

                            <div class="product-footer">
                                <!-- Quantity Controls -->
                                <div class="qty-stepper">
                                    <form method="POST" style="display:contents;">
                                        <input type="hidden" name="action" value="update_quantity">
                                        <input type="hidden" name="product_id" value="<?php echo $item['product_id']; ?>">
                                        <button type="submit" name="quantity"
                                                value="<?php echo max(1, $item['quantity'] - 1); ?>"
                                                class="qty-btn"
                                                <?php echo $item['quantity'] <= 1 ? 'disabled' : ''; ?>>
                                            <i class="fas fa-minus"></i>
                                        </button>
                                    </form>

                                    <input type="number" class="qty-value"
                                           value="<?php echo $item['quantity']; ?>"
                                           min="1" max="<?php echo $item['stock_count']; ?>"
                                           data-product-id="<?php echo $item['product_id']; ?>"
                                           onchange="updateQuantity(<?php echo $item['product_id']; ?>, this.value)">

                                    <form method="POST" style="display:contents;">
                                        <input type="hidden" name="action" value="update_quantity">
                                        <input type="hidden" name="product_id" value="<?php echo $item['product_id']; ?>">
                                        <button type="submit" name="quantity"
                                                value="<?php echo $item['quantity'] + 1; ?>"
                                                class="qty-btn"
                                                <?php echo ($item['quantity'] >= $item['stock_count']) ? 'disabled' : ''; ?>>
                                            <i class="fas fa-plus"></i>
                                        </button>
                                    </form>
                                </div>

                                <!-- Price -->
                                <div class="price-block">
                                    <div class="unit-price"><?php echo $user_currency; ?> <?php echo number_format($item['current_price']); ?></div>
                                    <div class="total-price"><?php echo $user_currency; ?> <?php echo number_format($item['item_total']); ?></div>
                                </div>
                            </div>
                        </div>

                        <!-- Remove -->
                        <form method="POST" class="remove-form">
                            <input type="hidden" name="action" value="remove_item">
                            <input type="hidden" name="product_id" value="<?php echo $item['product_id']; ?>">
                            <button type="submit" class="remove-btn"
                                    onclick="return confirm('Remove this item?')"
                                    title="Remove item">
                                <i class="fas fa-times"></i>
                            </button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Cart Summary -->
            <div class="col-lg-4">
                <div class="summary-card">
                    <div class="summary-header">
                        <h4>Order Summary</h4>
                    </div>

                    <div class="summary-body">
                        <div class="summary-line">
                            <span>Subtotal</span>
                            <span><?php echo $user_currency; ?> <?php echo number_format($subtotal); ?></span>
                        </div>
                        <div class="summary-line">
                            <span>Shipping</span>
                            <span class="shipping-tag"><?php echo $user_currency; ?> <?php echo number_format($shipping_cost); ?></span>
                        </div>
                        <?php if ($tax_rate > 0): ?>
                        <div class="summary-line">
                            <span>Tax (<?php echo ($tax_rate * 100); ?>%)</span>
                            <span><?php echo $user_currency; ?> <?php echo number_format($tax_amount); ?></span>
                        </div>
                        <?php endif; ?>

                        <div class="summary-divider"></div>

                        <div class="summary-total">
                            <span>Total</span>
                            <span><?php echo $user_currency; ?> <?php echo number_format($cart_total); ?></span>
                        </div>
                    </div>

                    <div class="summary-actions">
                        <button class="btn-checkout" onclick="openOrderModal()">
                            <i class="fas fa-bag-shopping me-2"></i>Place Order
                        </button>
                        <a href="products.php" class="btn-outline-round">
                            <i class="fas fa-arrow-left me-1"></i> Continue Shopping
                        </a>
                        <a href="quotation.php" class="btn-outline-round">
                            Get Quotation <i class="fas fa-arrow-right ms-1"></i>
                        </a>
                    </div>

                    <div class="payment-methods">
                        <span class="pm-label">Accepted payments</span>
                        <div class="pm-icons">
                            <i class="fab fa-cc-visa"></i>
                            <i class="fab fa-cc-mastercard"></i>
                            <i class="fas fa-mobile-alt"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recommended Products -->
        <?php if (!empty($recommended_products)): ?>
        <div class="recommended-section">
            <div class="section-label">You might also like</div>
            <div class="row g-3">
                <?php foreach ($recommended_products as $product): ?>
                <div class="col-lg-3 col-md-6">
                    <div class="rec-card">
                        <div class="rec-image">
                            <img src="<?php echo htmlspecialchars($product['image']); ?>"
                                 alt="<?php echo htmlspecialchars($product['name']); ?>"
                                 onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                            <div class="rec-placeholder" style="display:none;">
                                <i class="fas fa-laptop fa-2x"></i>
                            </div>
                        </div>
                        <div class="rec-body">
                            <div class="rec-brand"><?php echo htmlspecialchars($product['brand']); ?></div>
                            <h6 class="rec-name"><?php echo htmlspecialchars($product['name']); ?></h6>
                            <div class="rec-footer">
                                <span class="rec-price"><?php echo $user_currency; ?> <?php echo number_format($product['price']); ?></span>
                                <button class="rec-add-btn" onclick="addToCart(<?php echo $product['id']; ?>)">
                                    <i class="fas fa-plus"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php endif; ?>
    </div>
</section>

<!-- ══ Place Order Modal ══════════════════════════════════════════ -->
<div id="orderModal" class="modal-overlay" onclick="closeModalOnOverlay(event)">
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
        <div class="modal-header">
            <div class="modal-icon">
                <i class="fas fa-shopping-bag"></i>
            </div>
            <div>
                <h5 id="modalTitle">Complete Your Order</h5>
                <p>Fill in your details to place the order</p>
            </div>
            <button class="modal-close" onclick="closeOrderModal()" aria-label="Close">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="modal-body">
            <!-- Step 1: Form -->
            <div id="modalFormStep">
                <div class="form-group">
                    <label class="form-label" for="orderFullName">
                        <i class="fas fa-user"></i> Full Name <span class="req">*</span>
                    </label>
                    <input type="text" id="orderFullName" class="form-input"
                           placeholder="Enter your full name" autocomplete="name">
                    <div class="field-error" id="nameError"></div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="orderPhone">
                        <i class="fas fa-phone"></i> Phone Number <span class="req">*</span>
                    </label>
                    <input type="tel" id="orderPhone" class="form-input"
                           placeholder="e.g. 0771234567" autocomplete="tel">
                    <div class="field-error" id="phoneError"></div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="orderEmail">
                        <i class="fas fa-envelope"></i> Email Address <span class="req">*</span>
                    </label>
                    <input type="email" id="orderEmail" class="form-input"
                           placeholder="you@example.com" autocomplete="email">
                    <div class="field-error" id="emailError"></div>
                </div>

                <!-- Order mini summary -->
                <div class="modal-order-summary">
                    <div class="mos-title">Order Summary</div>
                    <div id="mosItems" class="mos-items"></div>
                    <div class="mos-total">
                        <span>Total</span>
                        <span id="mosTotal"></span>
                    </div>
                </div>

                <div class="modal-actions">
                    <button class="modal-btn-cancel" onclick="closeOrderModal()">Cancel</button>
                    <button class="modal-btn-confirm" id="confirmOrderBtn" onclick="confirmOrder()">
                        <i class="fas fa-check me-1"></i> Confirm Order
                    </button>
                </div>
            </div>

            <!-- Step 2: Success -->
            <div id="modalSuccessStep" style="display:none;" class="modal-success">
                <div class="success-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <h5>Order Placed Successfully!</h5>
                <p>Thank you! Your order <strong id="successOrderNumber"></strong> has been received.<br>
                   We'll contact you shortly to confirm the details.</p>
                <div class="redirect-countdown">
                    <div class="countdown-bar"><div id="countdownFill" class="countdown-fill"></div></div>
                    <span id="countdownText">Redirecting to home in <strong id="countdownNum">5</strong>s…</span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Toast container -->
<div id="toast-container"></div>

<!-- Pass PHP cart data to JS -->
<script>
const CART_ITEMS   = <?php echo $cart_items_json; ?>;
const CART_TOTAL   = <?php echo $cart_total; ?>;
const CART_SUBTOTAL = <?php echo $subtotal; ?>;
const SHIPPING_COST = <?php echo $shipping_cost; ?>;
const CURRENCY     = '<?php echo $user_currency; ?>';
</script>

<!-- ══ Styles ══════════════════════════════════════════════════════ -->
<style>
    :root {
        --primary: #0cb100;
        --primary-dark: #087600;
        --primary-light: #f6f7ffb4;
        --accent: #0cb100;
        --danger: #EF4444;
        --danger-light: #fff1f1;
        --text-primary: #0f172a;
        --text-secondary: #64748b;
        --text-muted: #94a3b8;
        --bg: #f1f5f9;
        --surface: #ffffff;
        --border: #e2e8f0;
        --radius-sm: 10px;
        --radius-md: 16px;
        --radius-lg: 24px;
        --radius-xl: 32px;
        --shadow-sm: 0 1px 4px rgba(0,0,0,0.04), 0 4px 12px rgba(0,0,0,0.06);
        --shadow-md: 0 4px 16px rgba(0,0,0,0.08), 0 8px 32px rgba(0,0,0,0.06);
        --font: 'Red Hat Display', sans-serif;
        --font-mono: 'Red Hat Display', sans-serif;
    }

    * { box-sizing: border-box; }

    body {
        font-family: var(--font);
        background: var(--bg);
        color: var(--text-primary);
    }

    /* ── Page Header ── */
    .page-header {
        background-image:
            linear-gradient(rgba(255,255,255,.028) 1px, transparent 1px),
            linear-gradient(90deg, rgba(255,255,255,.028) 1px, transparent 1px);
        padding: 7rem 0 3.5rem;
        margin-top: 80px;
        position: relative;
        overflow: hidden;
        background: #000;
    }
    .page-header::before {
        content: '';
        position: absolute;
        inset: 0;
        background: radial-gradient(ellipse at 80% 50%, rgba(59, 246, 62, 0.25) 0%, transparent 60%),
                    radial-gradient(ellipse at 20% 80%, rgba(22,196,127,0.2) 0%, transparent 50%);
    }
    .page-header .container { position: relative; }

    .breadcrumb { background: transparent; padding: 0; margin-bottom: 1.5rem; }
    .breadcrumb-item a {
        color: rgba(255,255,255,0.7);
        text-decoration: none;
        font-size: 0.875rem;
        transition: color 0.2s;
    }
    .breadcrumb-item a:hover { color: #fff; }
    .breadcrumb-item.active { color: rgba(255,255,255,0.9); font-size: 0.875rem; }
    .breadcrumb-item + .breadcrumb-item::before { color: rgba(255,255,255,0.4); }

    .header-content {
        display: flex;
        align-items: center;
        gap: 1.25rem;
    }
    .header-icon-wrap {
        width: 60px; height: 60px;
        background: rgba(255,255,255,0.15);
        backdrop-filter: blur(10px);
        border-radius: 18px;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.5rem;
        color: white;
        flex-shrink: 0;
        border: 1px solid rgba(255,255,255,0.2);
    }
    .page-header h1 {
        font-size: 2.5rem;
        font-weight: 700;
        color: white;
        margin: 0 0 0.25rem;
        letter-spacing: -0.03em;
    }
    .page-header .lead {
        color: rgba(255,255,255,0.75);
        font-size: 1rem;
        margin: 0;
    }

    /* ── Cart Section ── */
    .cart-section { padding: 2.5rem 0 4rem; }

    /* ── Alert pill ── */
    .alert-pill {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        background: var(--danger-light);
        color: var(--danger);
        border: 1px solid rgba(239,68,68,0.2);
        border-radius: 100px;
        padding: 0.75rem 1.25rem;
        margin-bottom: 1.5rem;
        font-size: 0.9rem;
        font-weight: 500;
    }
    .pill-close {
        margin-left: auto;
        background: none;
        border: none;
        color: var(--danger);
        cursor: pointer;
        padding: 0;
        opacity: 0.6;
    }
    .pill-close:hover { opacity: 1; }

    /* ── Section header ── */
    .section-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 1.25rem;
    }
    .section-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.95rem;
        font-weight: 600;
        color: var(--text-secondary);
    }
    .section-badge span {
        background: var(--primary);
        color: white;
        font-size: 0.8rem;
        font-weight: 700;
        width: 26px; height: 26px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    .btn-ghost-danger {
        background: none;
        border: none;
        color: var(--text-muted);
        font-size: 0.85rem;
        font-weight: 500;
        cursor: pointer;
        padding: 0.4rem 0.75rem;
        border-radius: 8px;
        transition: all 0.2s;
        font-family: var(--font);
    }
    .btn-ghost-danger:hover {
        background: var(--danger-light);
        color: var(--danger);
    }

    /* ── Cart Card ── */
    .cart-card {
        background: var(--surface);
        border-radius: var(--radius-lg);
        box-shadow: var(--shadow-sm);
        margin-bottom: 1rem;
        transition: box-shadow 0.25s, transform 0.25s;
        border: 1px solid var(--border);
        overflow: hidden;
    }
    .cart-card:hover {
        box-shadow: var(--shadow-md);
        transform: translateY(-2px);
    }
    .cart-card-inner {
        display: flex;
        align-items: flex-start;
        padding: 1.25rem;
        gap: 1.25rem;
        position: relative;
    }

    .product-thumb {
        width: 110px; height: 110px;
        border-radius: var(--radius-md);
        background: var(--bg);
        flex-shrink: 0;
        overflow: hidden;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .product-thumb img { width: 100%; height: 100%; object-fit: cover; }
    .thumb-placeholder {
        width: 100%; height: 100%;
        display: flex; align-items: center; justify-content: center;
        color: var(--text-muted);
        font-size: 2rem;
    }

    .product-info { flex: 1; min-width: 0; }
    .product-meta {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        margin-bottom: 0.35rem;
    }
    .product-brand {
        font-size: 0.78rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: var(--text-muted);
    }
    .price-pill {
        background: #fef3c7;
        color: #d97706;
        font-size: 0.7rem;
        font-weight: 600;
        padding: 0.15rem 0.6rem;
        border-radius: 100px;
    }
    .product-name {
        font-size: 1.05rem;
        font-weight: 600;
        color: var(--text-primary);
        margin: 0 0 0.5rem;
        line-height: 1.35;
    }
    .stock-alert {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        background: var(--danger-light);
        color: var(--danger);
        font-size: 0.78rem;
        font-weight: 500;
        padding: 0.3rem 0.75rem;
        border-radius: 100px;
        margin-bottom: 0.75rem;
    }

    .product-footer {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 0.75rem;
        margin-top: 0.75rem;
    }

    /* ── Qty Stepper ── */
    .qty-stepper {
        display: flex;
        align-items: center;
        gap: 0;
        background: var(--bg);
        border-radius: 100px;
        padding: 0.25rem;
    }
    .qty-btn {
        width: 32px; height: 32px;
        border-radius: 50%;
        border: none;
        background: var(--surface);
        color: var(--text-primary);
        font-size: 0.75rem;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.2s;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        flex-shrink: 0;
    }
    .qty-btn:hover:not(:disabled) {
        background: var(--primary);
        color: white;
    }
    .qty-btn:disabled { opacity: 0.35; cursor: not-allowed; }
    .qty-value {
        width: 46px;
        text-align: center;
        border: none;
        background: transparent;
        font-family: var(--font-mono);
        font-size: 0.95rem;
        font-weight: 500;
        color: var(--text-primary);
        -moz-appearance: textfield;
    }
    .qty-value::-webkit-inner-spin-button,
    .qty-value::-webkit-outer-spin-button { -webkit-appearance: none; }
    .qty-value:focus { outline: none; }

    /* ── Price Block ── */
    .price-block { text-align: right; }
    .unit-price {
        font-size: 0.8rem;
        color: var(--text-muted);
        font-family: var(--font-mono);
    }
    .total-price {
        font-size: 1.1rem;
        font-weight: 700;
        color: var(--primary);
        font-family: var(--font-mono);
    }

    /* ── Remove btn ── */
    .remove-form { flex-shrink: 0; }
    .remove-btn {
        width: 32px; height: 32px;
        border-radius: 50%;
        border: 1px solid var(--border);
        background: var(--surface);
        color: var(--text-muted);
        font-size: 0.75rem;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.2s;
    }
    .remove-btn:hover {
        background: var(--danger);
        border-color: var(--danger);
        color: white;
    }

    /* ── Summary Card ── */
    .summary-card {
        background: var(--surface);
        border-radius: var(--radius-lg);
        box-shadow: var(--shadow-sm);
        border: 1px solid var(--border);
        overflow: hidden;
        position: sticky;
        top: 100px;
    }
    .summary-header {
        padding: 1.5rem 1.5rem 0;
    }
    .summary-header h4 {
        font-size: 1.15rem;
        font-weight: 700;
        margin: 0;
        color: var(--text-primary);
    }
    .summary-body { padding: 1.25rem 1.5rem; }
    .summary-line {
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 0.9rem;
        color: var(--text-secondary);
        margin-bottom: 0.9rem;
    }
    .shipping-tag {
        background: var(--primary-light);
        color: var(--primary-dark);
        font-size: 0.8rem;
        font-weight: 600;
        padding: 0.2rem 0.65rem;
        border-radius: 100px;
    }
    .summary-divider {
        height: 1px;
        background: var(--border);
        margin: 1rem 0;
    }
    .summary-total {
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 1.15rem;
        font-weight: 700;
        color: var(--text-primary);
    }
    .summary-total span:last-child {
        color: var(--primary);
        font-family: var(--font-mono);
    }

    .summary-actions { padding: 0 1.5rem 1.5rem; }

    .btn-checkout {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 100%;
        padding: 0.9rem 1.5rem;
        background: var(--primary);
        color: white;
        border: none;
        border-radius: var(--radius-md);
        font-family: var(--font);
        font-size: 1rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.25s;
        margin-bottom: 0.75rem;
        letter-spacing: -0.01em;
    }
    .btn-checkout:hover {
        background: var(--primary-dark);
        transform: translateY(-1px);
        box-shadow: 0 4px 16px rgba(22,196,127,0.35);
    }
    .btn-checkout:active { transform: translateY(0); }

    .btn-outline-round {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 100%;
        padding: 0.7rem 1.25rem;
        background: transparent;
        color: var(--text-secondary);
        border: 1px solid var(--border);
        border-radius: var(--radius-md);
        font-family: var(--font);
        font-size: 0.9rem;
        font-weight: 500;
        text-decoration: none;
        transition: all 0.2s;
        margin-bottom: 0.5rem;
    }
    .btn-outline-round:hover {
        background: var(--bg);
        color: var(--text-primary);
        border-color: var(--text-muted);
    }

    .payment-methods {
        padding: 1rem 1.5rem;
        border-top: 1px solid var(--border);
        display: flex;
        align-items: center;
        gap: 0.75rem;
        background: var(--bg);
    }
    .pm-label { font-size: 0.75rem; color: var(--text-muted); flex-shrink: 0; }
    .pm-icons { display: flex; gap: 0.5rem; align-items: center; }
    .pm-icons i { font-size: 1.5rem; color: var(--text-muted); }
    .pm-icons .fa-cc-visa { color: #1a1f71; }
    .pm-icons .fa-cc-mastercard { color: #eb001b; }
    .pm-icons .fa-mobile-alt { color: var(--primary); }

    /* ── Empty Cart ── */
    .empty-cart {
        text-align: center;
        padding: 5rem 2rem;
        background: var(--surface);
        border-radius: var(--radius-xl);
        border: 1px solid var(--border);
        box-shadow: var(--shadow-sm);
    }
    .empty-bag-visual { margin-bottom: 2rem; }
    .bag-circle {
        width: 90px; height: 90px;
        background: var(--primary-light);
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 2.25rem;
        color: var(--primary);
        margin-bottom: 1rem;
    }
    .empty-dots { display: flex; justify-content: center; gap: 6px; }
    .empty-dots span {
        width: 8px; height: 8px;
        border-radius: 50%;
        background: var(--border);
        animation: dotPulse 1.5s ease-in-out infinite;
    }
    .empty-dots span:nth-child(2) { animation-delay: 0.3s; }
    .empty-dots span:nth-child(3) { animation-delay: 0.6s; }
    @keyframes dotPulse {
        0%, 100% { opacity: 0.3; transform: scale(0.8); }
        50% { opacity: 1; transform: scale(1); }
    }
    .empty-cart h3 { font-size: 1.5rem; font-weight: 700; margin-bottom: 0.5rem; }
    .empty-cart p { color: var(--text-secondary); margin-bottom: 2rem; }
    .btn-primary-pill {
        display: inline-flex;
        align-items: center;
        background: var(--primary);
        color: white;
        padding: 0.8rem 2rem;
        border-radius: 100px;
        font-weight: 600;
        text-decoration: none;
        transition: all 0.25s;
        box-shadow: 0 4px 14px rgba(22,196,127,0.3);
    }
    .btn-primary-pill:hover {
        background: var(--primary-dark);
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(22,196,127,0.4);
        color: white;
    }

    /* ── Recommended ── */
    .recommended-section { margin-top: 3rem; }
    .section-label {
        font-size: 1.2rem;
        font-weight: 700;
        color: var(--text-primary);
        margin-bottom: 1.25rem;
        letter-spacing: -0.02em;
    }

    .rec-card {
        background: var(--surface);
        border-radius: var(--radius-lg);
        border: 1px solid var(--border);
        overflow: hidden;
        transition: all 0.25s;
        box-shadow: var(--shadow-sm);
        height: 100%;
    }
    .rec-card:hover {
        transform: translateY(-4px);
        box-shadow: var(--shadow-md);
    }
    .rec-image {
        height: 130px;
        background: var(--bg);
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
    }
    .rec-image img { width: 100%; height: 100%; object-fit: cover; }
    .rec-placeholder {
        width: 100%; height: 100%;
        display: flex; align-items: center; justify-content: center;
        color: var(--text-muted);
    }
    .rec-body { padding: 1rem; }
    .rec-brand {
        font-size: 0.72rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: var(--text-muted);
        margin-bottom: 0.35rem;
    }
    .rec-name {
        font-size: 0.9rem;
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 0.75rem;
        line-height: 1.35;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }
    .rec-footer {
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .rec-price {
        font-weight: 700;
        font-size: 0.95rem;
        color: var(--primary);
        font-family: var(--font-mono);
    }
    .rec-add-btn {
        width: 32px; height: 32px;
        border-radius: 50%;
        background: var(--primary-light);
        color: var(--primary);
        border: none;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.75rem;
        cursor: pointer;
        transition: all 0.2s;
    }
    .rec-add-btn:hover {
        background: var(--primary);
        color: white;
    }

    /* ── Toast ── */
    #toast-container {
        position: fixed;
        top: 90px;
        right: 1.25rem;
        z-index: 9999;
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
        pointer-events: none;
    }
    .toast-pill {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        background: var(--text-primary);
        color: white;
        padding: 0.75rem 1.25rem;
        border-radius: 100px;
        font-size: 0.875rem;
        font-weight: 500;
        pointer-events: all;
        box-shadow: 0 4px 16px rgba(0,0,0,0.2);
        animation: toastSlide 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        white-space: nowrap;
    }
    .toast-pill.toast-success { background: var(--primary); }
    .toast-pill.toast-error { background: var(--danger); }
    @keyframes toastSlide {
        from { transform: translateX(100px); opacity: 0; }
        to   { transform: translateX(0); opacity: 1; }
    }
    .toast-out { animation: toastOut 0.25s ease-in forwards; }
    @keyframes toastOut {
        to { transform: translateX(100px); opacity: 0; }
    }

    /* ── Animations ── */
    @keyframes fadeUp {
        from { opacity: 0; transform: translateY(16px); }
        to   { opacity: 1; transform: translateY(0); }
    }
    .fade-in { animation: fadeUp 0.45s ease-out both; }

    /* ══════════════════════════════════════════
       MODAL STYLES
    ══════════════════════════════════════════ */
    .modal-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.55);
        backdrop-filter: blur(4px);
        z-index: 10000;
        align-items: center;
        justify-content: center;
        padding: 1rem;
    }
    .modal-overlay.active {
        display: flex;
        animation: overlayFadeIn 0.25s ease;
    }
    @keyframes overlayFadeIn {
        from { opacity: 0; }
        to   { opacity: 1; }
    }

    .modal-box {
        background: var(--surface);
        border-radius: var(--radius-xl);
        width: 100%;
        max-width: 520px;
        box-shadow: 0 24px 64px rgba(0,0,0,0.22);
        overflow: hidden;
        animation: modalSlideUp 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
    }
    @keyframes modalSlideUp {
        from { transform: translateY(40px) scale(0.97); opacity: 0; }
        to   { transform: translateY(0) scale(1); opacity: 1; }
    }

    .modal-header {
        display: flex;
        align-items: center;
        gap: 1rem;
        padding: 1.5rem 1.5rem 1rem;
        border-bottom: 1px solid var(--border);
    }
    .modal-icon {
        width: 48px; height: 48px;
        background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
        border-radius: 14px;
        display: flex; align-items: center; justify-content: center;
        color: white;
        font-size: 1.2rem;
        flex-shrink: 0;
    }
    .modal-header h5 {
        font-size: 1.1rem;
        font-weight: 700;
        margin: 0 0 0.15rem;
        color: var(--text-primary);
    }
    .modal-header p {
        font-size: 0.82rem;
        color: var(--text-muted);
        margin: 0;
    }
    .modal-close {
        margin-left: auto;
        width: 34px; height: 34px;
        border-radius: 50%;
        border: 1px solid var(--border);
        background: none;
        color: var(--text-muted);
        font-size: 0.85rem;
        display: flex; align-items: center; justify-content: center;
        cursor: pointer;
        transition: all 0.2s;
        flex-shrink: 0;
    }
    .modal-close:hover { background: var(--danger); border-color: var(--danger); color: white; }

    .modal-body { padding: 1.5rem; }

    .form-group { margin-bottom: 1.1rem; }
    .form-label {
        display: flex;
        align-items: center;
        gap: 0.4rem;
        font-size: 0.85rem;
        font-weight: 600;
        color: var(--text-secondary);
        margin-bottom: 0.4rem;
    }
    .form-label i { color: var(--primary); font-size: 0.8rem; }
    .form-label .req { color: var(--danger); }
    .form-input {
        width: 100%;
        padding: 0.75rem 1rem;
        border: 1.5px solid var(--border);
        border-radius: var(--radius-sm);
        font-family: var(--font);
        font-size: 0.95rem;
        color: var(--text-primary);
        background: var(--bg);
        transition: border-color 0.2s, box-shadow 0.2s;
        outline: none;
    }
    .form-input:focus {
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(12, 177, 0, 0.12);
        background: white;
    }
    .form-input.is-invalid {
        border-color: var(--danger);
        box-shadow: 0 0 0 3px rgba(239,68,68,0.1);
    }
    .field-error {
        font-size: 0.78rem;
        color: var(--danger);
        margin-top: 0.3rem;
        min-height: 1em;
    }

    /* Mini order summary inside modal */
    .modal-order-summary {
        background: var(--bg);
        border-radius: var(--radius-md);
        padding: 1rem 1.1rem;
        margin: 1.25rem 0;
        border: 1px solid var(--border);
    }
    .mos-title {
        font-size: 0.8rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.07em;
        color: var(--text-muted);
        margin-bottom: 0.75rem;
    }
    .mos-items { margin-bottom: 0.75rem; }
    .mos-item {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        font-size: 0.85rem;
        color: var(--text-secondary);
        padding: 0.3rem 0;
        border-bottom: 1px solid var(--border);
        gap: 0.5rem;
    }
    .mos-item:last-child { border-bottom: none; }
    .mos-item-name { flex: 1; }
    .mos-item-qty { color: var(--text-muted); font-size: 0.78rem; white-space: nowrap; }
    .mos-item-price { font-weight: 600; white-space: nowrap; font-family: var(--font-mono); }
    .mos-total {
        display: flex;
        justify-content: space-between;
        font-size: 0.95rem;
        font-weight: 700;
        color: var(--text-primary);
        padding-top: 0.5rem;
        border-top: 2px solid var(--border);
    }
    .mos-total span:last-child { color: var(--primary); }

    .modal-actions {
        display: flex;
        gap: 0.75rem;
        margin-top: 0.5rem;
    }
    .modal-btn-cancel {
        flex: 1;
        padding: 0.75rem;
        border: 1.5px solid var(--border);
        border-radius: var(--radius-md);
        background: none;
        font-family: var(--font);
        font-size: 0.95rem;
        font-weight: 500;
        color: var(--text-secondary);
        cursor: pointer;
        transition: all 0.2s;
    }
    .modal-btn-cancel:hover { background: var(--bg); color: var(--text-primary); }
    .modal-btn-confirm {
        flex: 2;
        padding: 0.75rem;
        border: none;
        border-radius: var(--radius-md);
        background: var(--primary);
        font-family: var(--font);
        font-size: 0.95rem;
        font-weight: 600;
        color: white;
        cursor: pointer;
        transition: all 0.25s;
        display: flex; align-items: center; justify-content: center; gap: 0.4rem;
    }
    .modal-btn-confirm:hover { background: var(--primary-dark); transform: translateY(-1px); }
    .modal-btn-confirm:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }

    /* Success step */
    .modal-success {
        text-align: center;
        padding: 1rem 0.5rem;
    }
    .success-icon {
        font-size: 3.5rem;
        color: var(--primary);
        margin-bottom: 1rem;
        animation: successPop 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
    }
    @keyframes successPop {
        from { transform: scale(0.5); opacity: 0; }
        to   { transform: scale(1); opacity: 1; }
    }
    /* Redirect countdown */
    .redirect-countdown {
        margin-top: 1.25rem;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 0.5rem;
    }
    .countdown-bar {
        width: 100%;
        max-width: 260px;
        height: 4px;
        background: var(--border);
        border-radius: 100px;
        overflow: hidden;
    }
    .countdown-fill {
        height: 100%;
        width: 100%;
        background: var(--primary);
        border-radius: 100px;
        transform-origin: left;
        animation: countdownShrink 3s linear forwards;
    }
    @keyframes countdownShrink {
        from { transform: scaleX(1); }
        to   { transform: scaleX(0); }
    }
    #countdownText { display: none; }

    .modal-success h5 {
        font-size: 1.3rem;
        font-weight: 700;
        margin-bottom: 0.5rem;
        color: var(--text-primary);
    }
    .modal-success p {
        color: var(--text-secondary);
        font-size: 0.9rem;
        margin-bottom: 1.5rem;
        line-height: 1.6;
    }
    .modal-success .modal-btn-confirm { flex: unset; padding: 0.75rem 2.5rem; }

    /* ── Responsive ── */
    @media (max-width: 768px) {
        .page-header { padding: 5.5rem 0 2.5rem; }
        .page-header h1 { font-size: 1.9rem; }
        .cart-card-inner { flex-wrap: wrap; }
        .product-thumb { width: 80px; height: 80px; }
        .summary-card { position: relative; top: auto; }
        .header-icon-wrap { display: none; }
        .modal-actions { flex-direction: column; }
        .modal-btn-cancel { flex: unset; }
        .modal-btn-confirm { flex: unset; }
    }
</style>

<!-- ══ Scripts ═══════════════════════════════════════════════════ -->
<script>
function updateQuantity(productId, quantity) {
    const qty = parseInt(quantity);
    if (isNaN(qty) || qty < 1) { showToast('Please enter a valid quantity', 'error'); return; }

    const form = document.createElement('form');
    form.method = 'POST';
    form.style.display = 'none';
    [['action','update_quantity'],['product_id',productId],['quantity',qty]].forEach(([n,v]) => {
        const i = document.createElement('input');
        i.type = 'hidden'; i.name = n; i.value = v;
        form.appendChild(i);
    });
    document.body.appendChild(form);
    form.submit();
}

function addToCart(productId, quantity = 1) {
    const button = event.target.closest('.rec-add-btn');
    const origHtml = button.innerHTML;
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    button.disabled = true;

    fetch('add-to-cart.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ product_id: productId, quantity })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            button.innerHTML = '<i class="fas fa-check"></i>';
            button.style.background = 'var(--primary)';
            button.style.color = 'white';
            showToast('Added to cart!', 'success');
            setTimeout(() => window.location.reload(), 1200);
        } else {
            if (data.redirect) { alert('Please login to add items to cart'); window.location.href = data.redirect; return; }
            showToast(data.message || 'Error adding item', 'error');
            button.innerHTML = origHtml;
            button.disabled = false;
        }
    })
    .catch(() => {
        showToast('Network error. Please try again.', 'error');
        button.innerHTML = origHtml;
        button.disabled = false;
    });
}

/* ══ Order Modal ══════════════════════════════════════════════════ */

function openOrderModal() {
    if (document.querySelectorAll('.stock-alert').length > 0) {
        if (!confirm('Some items have stock issues. Proceed anyway?')) return;
    }

    // Populate mini summary
    const mosItems = document.getElementById('mosItems');
    mosItems.innerHTML = CART_ITEMS.map(item => `
        <div class="mos-item">
            <span class="mos-item-name">${escHtml(item.name)}</span>
            <span class="mos-item-qty">×${item.quantity}</span>
            <span class="mos-item-price">${item.currency} ${numFmt(item.item_total)}</span>
        </div>
    `).join('');

    document.getElementById('mosTotal').textContent = CURRENCY + ' ' + numFmt(CART_TOTAL);

    // Reset form
    ['orderFullName','orderPhone','orderEmail'].forEach(id => {
        document.getElementById(id).value = '';
        document.getElementById(id).classList.remove('is-invalid');
    });
    ['nameError','phoneError','emailError'].forEach(id => {
        document.getElementById(id).textContent = '';
    });

    // Show form step, hide success
    document.getElementById('modalFormStep').style.display = '';
    document.getElementById('modalSuccessStep').style.display = 'none';

    document.getElementById('orderModal').classList.add('active');
    document.body.style.overflow = 'hidden';
    setTimeout(() => document.getElementById('orderFullName').focus(), 300);
}

function closeOrderModal() {
    document.getElementById('orderModal').classList.remove('active');
    document.body.style.overflow = '';
}

function closeModalOnOverlay(e) {
    if (e.target === document.getElementById('orderModal')) closeOrderModal();
}

// Close on Escape key
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeOrderModal();
});

function validateOrderForm() {
    let valid = true;

    const fullName = document.getElementById('orderFullName').value.trim();
    const phone    = document.getElementById('orderPhone').value.trim();
    const email    = document.getElementById('orderEmail').value.trim();

    if (!fullName) {
        setFieldError('orderFullName', 'nameError', 'Full name is required.');
        valid = false;
    } else { clearFieldError('orderFullName', 'nameError'); }

    if (!phone) {
        setFieldError('orderPhone', 'phoneError', 'Phone number is required.');
        valid = false;
    } else if (!/^[\d\s\+\-\(\)]{7,20}$/.test(phone)) {
        setFieldError('orderPhone', 'phoneError', 'Please enter a valid phone number.');
        valid = false;
    } else { clearFieldError('orderPhone', 'phoneError'); }

    if (!email) {
        setFieldError('orderEmail', 'emailError', 'Email address is required.');
        valid = false;
    } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        setFieldError('orderEmail', 'emailError', 'Please enter a valid email address.');
        valid = false;
    } else { clearFieldError('orderEmail', 'emailError'); }

    return valid;
}

function setFieldError(inputId, errorId, msg) {
    document.getElementById(inputId).classList.add('is-invalid');
    document.getElementById(errorId).textContent = msg;
}
function clearFieldError(inputId, errorId) {
    document.getElementById(inputId).classList.remove('is-invalid');
    document.getElementById(errorId).textContent = '';
}

function confirmOrder() {
    if (!validateOrderForm()) return;

    const btn = document.getElementById('confirmOrderBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Sending...';

    const fullName = document.getElementById('orderFullName').value.trim();
    const phone    = document.getElementById('orderPhone').value.trim();
    const email    = document.getElementById('orderEmail').value.trim();

    // Generate order number
    const orderNumber = 'ORD-' + Date.now().toString(36).toUpperCase();

    const payload = {
        full_name:    fullName,
        phone:        phone,
        email:        email,
        order_number: orderNumber,
        cart_items:   CART_ITEMS,
        subtotal:     CART_SUBTOTAL,
        shipping:     SHIPPING_COST,
        total:        CART_TOTAL,
        currency:     CURRENCY
    };

    fetch('send_order_email.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            document.getElementById('successOrderNumber').textContent = '#' + orderNumber;
            document.getElementById('modalFormStep').style.display = 'none';
            document.getElementById('modalSuccessStep').style.display = '';

            // Start countdown and redirect to home page
            let secs = 3;
            document.getElementById('countdownNum').textContent = secs;
            // Re-trigger the CSS animation by replacing the element
            const fill = document.getElementById('countdownFill');
            const clone = fill.cloneNode(true);
            fill.parentNode.replaceChild(clone, fill);

            const ticker = setInterval(() => {
                secs--;
                const el = document.getElementById('countdownNum');
                if (el) el.textContent = secs;
                if (secs <= 0) {
                    clearInterval(ticker);
                    window.location.href = 'index.php';
                }
            }, 1000);
        } else {
            showToast(data.message || 'Failed to place order. Please try again.', 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check me-1"></i> Confirm Order';
        }
    })
    .catch(() => {
        showToast('Network error. Please try again.', 'error');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check me-1"></i> Confirm Order';
    });
}

function showToast(message, type = 'info') {
    const icons = { success: 'check-circle', error: 'exclamation-circle', info: 'info-circle' };
    const container = document.getElementById('toast-container');

    const toast = document.createElement('div');
    toast.className = `toast-pill toast-${type}`;
    toast.innerHTML = `<i class="fas fa-${icons[type] || 'info-circle'}"></i> ${message}`;
    container.appendChild(toast);

    setTimeout(() => {
        toast.classList.add('toast-out');
        setTimeout(() => toast.remove(), 250);
    }, 3500);
}

function escHtml(str) {
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}

function numFmt(n) {
    return Number(n).toLocaleString();
}

document.addEventListener('DOMContentLoaded', function () {
    // Staggered entrance
    document.querySelectorAll('.cart-card').forEach((card, i) => {
        card.style.animationDelay = `${i * 80}ms`;
    });

    // Debounced quantity input
    document.querySelectorAll('.qty-value').forEach(input => {
        let timer;
        input.addEventListener('input', function () {
            clearTimeout(timer);
            const pid = this.dataset.productId;
            timer = setTimeout(() => updateQuantity(pid, this.value), 900);
        });
    });

    // Keyboard: C → continue shopping
    document.addEventListener('keydown', e => {
        if (e.key === 'c' && !e.ctrlKey && !e.metaKey && document.activeElement.tagName !== 'INPUT') {
            window.location.href = 'products.php';
        }
    });
});

function printReceipt() { window.print(); }
function shareCart() {
    if (navigator.share) {
        navigator.share({ title: 'ITshop.LK Cart', text: 'Check out my cart!', url: location.href });
    } else {
        navigator.clipboard.writeText(location.href).then(() => showToast('Link copied!', 'success'));
    }
}
</script>

<?php include 'footer.php'; ?>