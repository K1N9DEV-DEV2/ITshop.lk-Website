<?php
// Start session for user management
session_start();

// Database connection
include 'db.php';

// ── Guest session init ──────────────────────────────────────────
if (!isset($_SESSION['guest_cart'])) {
    $_SESSION['guest_cart'] = [];
}

$is_logged_in  = isset($_SESSION['user_id']);
$is_guest      = !$is_logged_in;
$user_id       = $is_logged_in ? $_SESSION['user_id'] : null;
$user_currency = 'LKR';
$cart_items    = [];
$cart_count    = 0;
$subtotal      = 0;
$shipping_cost = 0;
$tax_rate      = 0;
$tax_amount    = 0;
$cart_total    = 0;

// ── Handle cart actions ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action     = $_POST['action']     ?? '';
    $product_id = (int)($_POST['product_id'] ?? 0);
    $quantity   = (int)($_POST['quantity']   ?? 1);

    if ($is_guest) {
        switch ($action) {
            case 'update_quantity':
                if ($quantity > 0) {
                    $_SESSION['guest_cart'][$product_id] = $quantity;
                } else {
                    unset($_SESSION['guest_cart'][$product_id]);
                }
                break;
            case 'remove_item':
                unset($_SESSION['guest_cart'][$product_id]);
                break;
            case 'clear_cart':
                $_SESSION['guest_cart'] = [];
                break;
        }
        header('Location: cart.php');
        exit();
    } else {
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
}

// ── Fetch cart items ────────────────────────────────────────────
if ($is_guest) {
    if (!empty($_SESSION['guest_cart'])) {
        try {
            $ids          = array_keys($_SESSION['guest_cart']);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("
                SELECT id AS product_id, name, brand, image, price AS current_price,
                       original_price, stock_count, in_stock, category
                FROM products WHERE id IN ($placeholders)
            ");
            $stmt->execute($ids);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($products as $p) {
                $qty        = $_SESSION['guest_cart'][$p['product_id']] ?? 1;
                $item_total = $p['current_price'] * $qty;
                $cart_items[] = array_merge($p, [
                    'quantity'         => $qty,
                    'cart_price'       => $p['current_price'],
                    'item_total'       => $item_total,
                    'price_changed'    => false,
                    'sufficient_stock' => ($p['in_stock'] && $p['stock_count'] >= $qty),
                ]);
                $subtotal   += $item_total;
                $cart_count += $qty;
            }
        } catch (PDOException $e) {
            $error_message = "Error fetching cart: " . $e->getMessage();
        }
    }
} else {
    try {
        $stmt = $pdo->prepare("
            SELECT c.product_id, c.quantity, c.price AS cart_price,
                   p.name, p.brand, p.image, p.price AS current_price,
                   p.original_price, p.stock_count, p.in_stock, p.category
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
    } catch (PDOException $e) {
        $error_message = "Error fetching cart items: " . $e->getMessage();
        $cart_items    = [];
    }
}

// ── Totals ──────────────────────────────────────────────────────
$tax_amount = $subtotal * $tax_rate;
$cart_total = $subtotal + $tax_amount + ($subtotal > 0 ? $shipping_cost : 0);

// ── Recommended products ────────────────────────────────────────
$recommended_products = [];
try {
    if (!empty($cart_items)) {
        $categories   = array_unique(array_column($cart_items, 'category'));
        $placeholders = str_repeat('?,', count($categories) - 1) . '?';
        $cart_product_ids = array_column($cart_items, 'product_id');

        if ($is_logged_in) {
            $exclude_sql = "AND id NOT IN (SELECT product_id FROM cart WHERE user_id = ?)";
            $params      = array_merge($categories, [$user_id]);
        } else {
            if (!empty($cart_product_ids)) {
                $excl_ph     = implode(',', array_fill(0, count($cart_product_ids), '?'));
                $exclude_sql = "AND id NOT IN ($excl_ph)";
                $params      = array_merge($categories, $cart_product_ids);
            } else {
                $exclude_sql = '';
                $params      = $categories;
            }
        }

        $stmt = $pdo->prepare("
            SELECT id, name, brand, price, original_price, image, rating, category
            FROM products
            WHERE category IN ($placeholders) AND in_stock = 1 $exclude_sql
            ORDER BY rating DESC, reviews DESC LIMIT 4
        ");
        $stmt->execute($params);
        $recommended_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    // Silently fall back
}

$page_title       = 'Shopping Cart - IT Shop.LK';
$page_description = 'Review your selected items and proceed to checkout';

include 'header.php';

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
        <!-- ── Empty Cart ── -->
        <div class="empty-cart fade-in">
            <div class="empty-bag-visual">
                <div class="bag-circle"><i class="fas fa-shopping-bag"></i></div>
                <div class="empty-dots"><span></span><span></span><span></span></div>
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
                        <?php if ($is_guest): ?>
                            <span class="guest-label"><i class="fas fa-user-clock"></i> Guest</span>
                        <?php endif; ?>
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
                        <div class="product-thumb">
                            <img src="<?php echo htmlspecialchars($item['image']); ?>"
                                 alt="<?php echo htmlspecialchars($item['name']); ?>"
                                 onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                            <div class="thumb-placeholder" style="display:none;">
                                <i class="fas fa-laptop"></i>
                            </div>
                        </div>

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

                                <div class="price-block">
                                    <div class="unit-price"><?php echo $user_currency; ?> <?php echo number_format($item['current_price']); ?></div>
                                    <div class="total-price"><?php echo $user_currency; ?> <?php echo number_format($item['item_total']); ?></div>
                                </div>
                            </div>
                        </div>

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
                            <span><?php echo $user_currency; ?> <?php echo number_format($subtotal + $tax_amount + $shipping_cost); ?></span>
                        </div>
                    </div>

                    <div class="summary-actions">
                        <button class="btn-checkout" onclick="openOrderModal()">
                            <i class="fas fa-bag-shopping me-2"></i>Place Order
                        </button>
                        <button class="btn-quotation" onclick="openQuotationModal()">
                            <i class="fas fa-file-invoice me-2"></i>Get Quotation via Mail
                        </button>
                        <a href="products.php" class="btn-outline-round">
                            <i class="fas fa-arrow-left me-1"></i> Continue Shopping
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
<div id="orderModal" class="modal-overlay" onclick="closeModalOnOverlay(event, 'orderModal')">
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="orderModalTitle">
        <div class="modal-header">
            <div class="modal-icon">
                <i class="fas fa-shopping-bag"></i>
            </div>
            <div>
                <h5 id="orderModalTitle">Complete Your Order</h5>
                <p>Fill in your details to place the order</p>
            </div>
            <button class="modal-close" onclick="closeModal('orderModal')" aria-label="Close">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="modal-body">
            <?php if ($is_guest): ?>
            <div class="modal-guest-bar">
                <div class="mgb-inner">
                    <div class="mgb-text">
                        <i class="fas fa-user-circle"></i>
                        <span>Have an account? <a href="login.php?redirect=cart.php">Sign in</a> for faster checkout.</span>
                    </div>
                    <button type="button" class="mgb-dismiss" onclick="this.closest('.modal-guest-bar').style.display='none'">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
            <?php endif; ?>

            <div id="orderFormStep">
                <div class="form-group">
                    <label class="form-label" for="orderFullName"><i class="fas fa-user"></i> Full Name <span class="req">*</span></label>
                    <input type="text" id="orderFullName" class="form-input" placeholder="Enter your full name" autocomplete="name">
                    <div class="field-error" id="orderNameError"></div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="orderPhone"><i class="fas fa-phone"></i> Phone Number <span class="req">*</span></label>
                    <input type="tel" id="orderPhone" class="form-input" placeholder="e.g. 0771234567" autocomplete="tel">
                    <div class="field-error" id="orderPhoneError"></div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="orderEmail"><i class="fas fa-envelope"></i> Email Address <span class="req">*</span></label>
                    <input type="email" id="orderEmail" class="form-input" placeholder="you@example.com" autocomplete="email">
                    <div class="field-error" id="orderEmailError"></div>
                </div>

                <div class="modal-order-summary">
                    <div class="mos-title">Order Summary</div>
                    <div id="orderMosItems" class="mos-items"></div>
                    <div class="mos-total"><span>Total</span><span id="orderMosTotal"></span></div>
                </div>

                <div class="modal-actions">
                    <button class="modal-btn-cancel" onclick="closeModal('orderModal')">Cancel</button>
                    <button class="modal-btn-confirm" id="confirmOrderBtn" onclick="confirmOrder()">
                        <i class="fas fa-check me-1"></i> Confirm Order
                    </button>
                </div>
            </div>

            <div id="orderSuccessStep" style="display:none;" class="modal-success">
                <div class="success-icon"><i class="fas fa-check-circle"></i></div>
                <h5>Order Placed Successfully!</h5>
                <p>Thank you! Your order <strong id="successOrderNumber"></strong> has been received.<br>We'll contact you shortly to confirm.</p>
                <?php if ($is_guest): ?>
                <div class="success-guest-cta">
                    <p class="sgc-text">Create a free account to track this order and save your details.</p>
                    <a href="register.php" class="sgc-btn"><i class="fas fa-user-plus me-1"></i>Create Account</a>
                </div>
                <?php endif; ?>
                <div class="redirect-countdown">
                    <div class="countdown-bar"><div id="orderCountdownFill" class="countdown-fill"></div></div>
                    <span id="orderCountdownText">Redirecting in <strong id="orderCountdownNum">3</strong>s…</span>
                </div>
            </div>
        </div>
    </div>
</div>


<!-- ══ Get Quotation Modal ════════════════════════════════════════ -->
<div id="quotationModal" class="modal-overlay" onclick="closeModalOnOverlay(event, 'quotationModal')">
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="quotationModalTitle">
        <div class="modal-header">
            <div class="modal-icon modal-icon--quote">
                <i class="fas fa-file-invoice"></i>
            </div>
            <div>
                <h5 id="quotationModalTitle">Request a Quotation</h5>
                <p>We'll email you a formal quotation for these items</p>
            </div>
            <button class="modal-close" onclick="closeModal('quotationModal')" aria-label="Close">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="modal-body">
            <?php if ($is_guest): ?>
            <div class="modal-guest-bar">
                <div class="mgb-inner">
                    <div class="mgb-text">
                        <i class="fas fa-user-circle"></i>
                        <span>Have an account? <a href="login.php?redirect=cart.php">Sign in</a> for faster checkout.</span>
                    </div>
                    <button type="button" class="mgb-dismiss" onclick="this.closest('.modal-guest-bar').style.display='none'">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
            <?php endif; ?>

            <div id="quotationFormStep">
                <div class="form-group">
                    <label class="form-label" for="quoteFullName"><i class="fas fa-user"></i> Full Name <span class="req">*</span></label>
                    <input type="text" id="quoteFullName" class="form-input" placeholder="Enter your full name" autocomplete="name">
                    <div class="field-error" id="quoteNameError"></div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="quotePhone"><i class="fas fa-phone"></i> Phone Number</label>
                    <input type="tel" id="quotePhone" class="form-input" placeholder="e.g. 0771234567" autocomplete="tel">
                </div>
                <div class="form-group">
                    <label class="form-label" for="quoteEmail"><i class="fas fa-envelope"></i> Email Address <span class="req">*</span></label>
                    <input type="email" id="quoteEmail" class="form-input" placeholder="you@example.com" autocomplete="email">
                    <div class="field-error" id="quoteEmailError"></div>
                </div>

                <!-- Items preview -->
                <div class="modal-order-summary">
                    <div class="mos-title">Items to Quote</div>
                    <div id="quoteMosItems" class="mos-items"></div>
                    <div class="mos-total"><span>Estimated Total</span><span id="quoteMosTotal"></span></div>
                </div>

                <!-- Valid for note -->
                <div class="quote-validity-note">
                    <i class="fas fa-clock"></i>
                    Quotation will be valid for <strong>30 days</strong> from the issue date.
                </div>

                <div class="modal-actions">
                    <button class="modal-btn-cancel" onclick="closeModal('quotationModal')">Cancel</button>
                    <button class="modal-btn-confirm modal-btn-confirm--quote" id="confirmQuoteBtn" onclick="confirmQuotation()">
                        <i class="fas fa-paper-plane me-1"></i> Send Quotation
                    </button>
                </div>
            </div>

            <div id="quotationSuccessStep" style="display:none;" class="modal-success">
                <div class="success-icon success-icon--quote"><i class="fas fa-envelope-open-text"></i></div>
                <h5>Quotation Sent!</h5>
                <p>Your quotation <strong id="successQuoteNumber"></strong> has been emailed to <strong id="successQuoteEmail"></strong>.<br>It's valid for 30 days — contact us to confirm your order.</p>
                <button class="modal-btn-confirm modal-btn-confirm--quote" style="width:100%;margin-top:1rem;" onclick="closeModal('quotationModal')">
                    <i class="fas fa-check me-1"></i> Done
                </button>
            </div>
        </div>
    </div>
</div>


<!-- Toast container -->
<div id="toast-container"></div>

<!-- Pass PHP cart data to JS -->
<script>
const CART_ITEMS    = <?php echo $cart_items_json; ?>;
const CART_TOTAL    = <?php echo $cart_total; ?>;
const CART_SUBTOTAL = <?php echo $subtotal; ?>;
const SHIPPING_COST = <?php echo $shipping_cost; ?>;
const CURRENCY      = '<?php echo $user_currency; ?>';
const IS_GUEST      = <?php echo $is_guest ? 'true' : 'false'; ?>;
</script>

<style>
    :root {
        --primary: #0cb100;
        --primary-dark: #087600;
        --primary-light: #f6f7ffb4;
        --quote: #2563eb;
        --quote-dark: #1d4ed8;
        --quote-light: #eff6ff;
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
        --guest: #f59e0b;
        --guest-light: #fffbeb;
    }

    * { box-sizing: border-box; }
    body { font-family: var(--font); background: var(--bg); color: var(--text-primary); }

    /* ── Page Header ── */
    .page-header { padding: 7rem 0 3.5rem; margin-top: 4x; position: relative; overflow: hidden; background: #000; }
    .page-header::before { content: ''; position: absolute; inset: 0; background: radial-gradient(ellipse at 80% 50%, rgba(59,246,62,0.25) 0%, transparent 60%), radial-gradient(ellipse at 20% 80%, rgba(22,196,127,0.2) 0%, transparent 50%); }
    .page-header .container { position: relative; }
    .breadcrumb { background: transparent; padding: 0; margin-bottom: 1.5rem; }
    .breadcrumb-item a { color: rgba(255,255,255,0.7); text-decoration: none; font-size: .875rem; transition: color .2s; }
    .breadcrumb-item a:hover { color: #fff; }
    .breadcrumb-item.active { color: rgba(255,255,255,0.9); font-size: .875rem; }
    .breadcrumb-item + .breadcrumb-item::before { color: rgba(255,255,255,0.4); }
    .header-content { display: flex; align-items: center; gap: 1.25rem; flex-wrap: wrap; }
    .header-icon-wrap { width: 60px; height: 60px; background: rgba(255,255,255,0.15); backdrop-filter: blur(10px); border-radius: 18px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; color: white; flex-shrink: 0; border: 1px solid rgba(255,255,255,0.2); }
    .page-header h1 { font-size: 2.5rem; font-weight: 700; color: white; margin: 0 0 .25rem; letter-spacing: -.03em; }
    .page-header .lead { color: rgba(255,255,255,0.75); font-size: 1rem; margin: 0; }

    /* ── Cart Section ── */
    .cart-section { padding: 2.5rem 0 4rem; }
    .alert-pill { display: flex; align-items: center; gap: .75rem; background: var(--danger-light); color: var(--danger); border: 1px solid rgba(239,68,68,0.2); border-radius: 100px; padding: .75rem 1.25rem; margin-bottom: 1.5rem; font-size: .9rem; font-weight: 500; }
    .pill-close { margin-left: auto; background: none; border: none; color: var(--danger); cursor: pointer; padding: 0; opacity: .6; }
    .pill-close:hover { opacity: 1; }
    .section-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.25rem; }
    .section-badge { display: inline-flex; align-items: center; gap: .5rem; font-size: .95rem; font-weight: 600; color: var(--text-secondary); }
    .section-badge > span:first-child { background: var(--primary); color: white; font-size: .8rem; font-weight: 700; width: 26px; height: 26px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; }
    .guest-label { display: inline-flex; align-items: center; gap: .3rem; background: var(--guest-light); color: var(--guest); border: 1px solid rgba(245,158,11,0.3); font-size: .72rem; font-weight: 600; padding: .15rem .6rem; border-radius: 100px; }
    .btn-ghost-danger { background: none; border: none; color: var(--text-muted); font-size: .85rem; font-weight: 500; cursor: pointer; padding: .4rem .75rem; border-radius: 8px; transition: all .2s; font-family: var(--font); }
    .btn-ghost-danger:hover { background: var(--danger-light); color: var(--danger); }

    /* ── Cart Card ── */
    .cart-card { background: var(--surface); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); margin-bottom: 1rem; transition: box-shadow .25s, transform .25s; border: 1px solid var(--border); overflow: hidden; }
    .cart-card:hover { box-shadow: var(--shadow-md); transform: translateY(-2px); }
    .cart-card-inner { display: flex; align-items: flex-start; padding: 1.25rem; gap: 1.25rem; position: relative; }
    .product-thumb { width: 110px; height: 110px; border-radius: var(--radius-md); background: var(--bg); flex-shrink: 0; overflow: hidden; display: flex; align-items: center; justify-content: center; }
    .product-thumb img { width: 100%; height: 100%; object-fit: cover; }
    .thumb-placeholder { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; color: var(--text-muted); font-size: 2rem; }
    .product-info { flex: 1; min-width: 0; }
    .product-meta { display: flex; align-items: center; gap: .5rem; margin-bottom: .35rem; }
    .product-brand { font-size: .78rem; font-weight: 600; text-transform: uppercase; letter-spacing: .08em; color: var(--text-muted); }
    .price-pill { background: #fef3c7; color: #d97706; font-size: .7rem; font-weight: 600; padding: .15rem .6rem; border-radius: 100px; }
    .product-name { font-size: 1.05rem; font-weight: 600; color: var(--text-primary); margin: 0 0 .5rem; line-height: 1.35; }
    .stock-alert { display: inline-flex; align-items: center; gap: .4rem; background: var(--danger-light); color: var(--danger); font-size: .78rem; font-weight: 500; padding: .3rem .75rem; border-radius: 100px; margin-bottom: .75rem; }
    .product-footer { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: .75rem; margin-top: .75rem; }
    .qty-stepper { display: flex; align-items: center; gap: 0; background: var(--bg); border-radius: 100px; padding: .25rem; }
    .qty-btn { width: 32px; height: 32px; border-radius: 50%; border: none; background: var(--surface); color: var(--text-primary); font-size: .75rem; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all .2s; box-shadow: 0 1px 3px rgba(0,0,0,0.1); flex-shrink: 0; }
    .qty-btn:hover:not(:disabled) { background: var(--primary); color: white; }
    .qty-btn:disabled { opacity: .35; cursor: not-allowed; }
    .qty-value { width: 46px; text-align: center; border: none; background: transparent; font-family: var(--font-mono); font-size: .95rem; font-weight: 500; color: var(--text-primary); -moz-appearance: textfield; }
    .qty-value::-webkit-inner-spin-button, .qty-value::-webkit-outer-spin-button { -webkit-appearance: none; }
    .qty-value:focus { outline: none; }
    .price-block { text-align: right; }
    .unit-price { font-size: .8rem; color: var(--text-muted); font-family: var(--font-mono); }
    .total-price { font-size: 1.1rem; font-weight: 700; color: var(--primary); font-family: var(--font-mono); }
    .remove-form { flex-shrink: 0; }
    .remove-btn { width: 32px; height: 32px; border-radius: 50%; border: 1px solid var(--border); background: var(--surface); color: var(--text-muted); font-size: .75rem; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all .2s; }
    .remove-btn:hover { background: var(--danger); border-color: var(--danger); color: white; }

    /* ── Summary Card ── */
    .summary-card { background: var(--surface); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); border: 1px solid var(--border); overflow: hidden; position: sticky; top: 100px; }
    .summary-header { padding: 1.5rem 1.5rem 0; }
    .summary-header h4 { font-size: 1.15rem; font-weight: 700; margin: 0; color: var(--text-primary); }
    .summary-body { padding: 1.25rem 1.5rem; }
    .summary-line { display: flex; justify-content: space-between; align-items: center; font-size: .9rem; color: var(--text-secondary); margin-bottom: .9rem; }
    .shipping-tag { background: var(--primary-light); color: var(--primary-dark); font-size: .8rem; font-weight: 600; padding: .2rem .65rem; border-radius: 100px; }
    .summary-divider { height: 1px; background: var(--border); margin: 1rem 0; }
    .summary-total { display: flex; justify-content: space-between; align-items: center; font-size: 1.15rem; font-weight: 700; color: var(--text-primary); }
    .summary-total span:last-child { color: var(--primary); font-family: var(--font-mono); }
    .summary-actions { padding: 0 1.5rem 1.5rem; }

    /* Place Order button */
    .btn-checkout { display: flex; align-items: center; justify-content: center; width: 100%; padding: .9rem 1.5rem; background: var(--primary); color: white; border: none; border-radius: var(--radius-md); font-family: var(--font); font-size: 1rem; font-weight: 600; cursor: pointer; transition: all .25s; margin-bottom: .75rem; letter-spacing: -.01em; }
    .btn-checkout:hover { background: var(--primary-dark); transform: translateY(-1px); box-shadow: 0 4px 16px rgba(22,196,127,0.35); }
    .btn-checkout:active { transform: translateY(0); }

    /* Get Quotation button — styled distinctly in blue */
    .btn-quotation { display: flex; align-items: center; justify-content: center; width: 100%; padding: .9rem 1.5rem; background: var(--quote); color: white; border: none; border-radius: var(--radius-md); font-family: var(--font); font-size: 1rem; font-weight: 600; cursor: pointer; transition: all .25s; margin-bottom: .75rem; letter-spacing: -.01em; }
    .btn-quotation:hover { background: var(--quote-dark); transform: translateY(-1px); box-shadow: 0 4px 16px rgba(37,99,235,0.35); }
    .btn-quotation:active { transform: translateY(0); }

    .btn-outline-round { display: flex; align-items: center; justify-content: center; width: 100%; padding: .7rem 1.25rem; background: transparent; color: var(--text-secondary); border: 1px solid var(--border); border-radius: var(--radius-md); font-family: var(--font); font-size: .9rem; font-weight: 500; text-decoration: none; transition: all .2s; margin-bottom: .5rem; }
    .btn-outline-round:hover { background: var(--bg); color: var(--text-primary); border-color: var(--text-muted); }

    .payment-methods { padding: 1rem 1.5rem; border-top: 1px solid var(--border); display: flex; align-items: center; gap: .75rem; background: var(--bg); }
    .pm-label { font-size: .75rem; color: var(--text-muted); flex-shrink: 0; }
    .pm-icons { display: flex; gap: .5rem; align-items: center; }
    .pm-icons i { font-size: 1.5rem; color: var(--text-muted); }
    .pm-icons .fa-cc-visa { color: #1a1f71; }
    .pm-icons .fa-cc-mastercard { color: #eb001b; }
    .pm-icons .fa-mobile-alt { color: var(--primary); }

    /* ── Empty Cart ── */
    .empty-cart { text-align: center; padding: 5rem 2rem; background: var(--surface); border-radius: var(--radius-xl); border: 1px solid var(--border); box-shadow: var(--shadow-sm); }
    .empty-bag-visual { margin-bottom: 2rem; }
    .bag-circle { width: 90px; height: 90px; background: var(--primary-light); border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 2.25rem; color: var(--primary); margin-bottom: 1rem; }
    .empty-dots { display: flex; justify-content: center; gap: 6px; }
    .empty-dots span { width: 8px; height: 8px; border-radius: 50%; background: var(--border); animation: dotPulse 1.5s ease-in-out infinite; }
    .empty-dots span:nth-child(2) { animation-delay: .3s; }
    .empty-dots span:nth-child(3) { animation-delay: .6s; }
    @keyframes dotPulse { 0%, 100% { opacity: .3; transform: scale(.8); } 50% { opacity: 1; transform: scale(1); } }
    .empty-cart h3 { font-size: 1.5rem; font-weight: 700; margin-bottom: .5rem; }
    .empty-cart p { color: var(--text-secondary); margin-bottom: 2rem; }
    .btn-primary-pill { display: inline-flex; align-items: center; background: var(--primary); color: white; padding: .8rem 2rem; border-radius: 100px; font-weight: 600; text-decoration: none; transition: all .25s; box-shadow: 0 4px 14px rgba(22,196,127,0.3); }
    .btn-primary-pill:hover { background: var(--primary-dark); transform: translateY(-2px); box-shadow: 0 6px 20px rgba(22,196,127,0.4); color: white; }

    /* ── Recommended ── */
    .recommended-section { margin-top: 3rem; }
    .section-label { font-size: 1.2rem; font-weight: 700; color: var(--text-primary); margin-bottom: 1.25rem; letter-spacing: -.02em; }
    .rec-card { background: var(--surface); border-radius: var(--radius-lg); border: 1px solid var(--border); overflow: hidden; transition: all .25s; box-shadow: var(--shadow-sm); height: 100%; }
    .rec-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-md); }
    .rec-image { height: 130px; background: var(--bg); display: flex; align-items: center; justify-content: center; overflow: hidden; }
    .rec-image img { width: 100%; height: 100%; object-fit: cover; }
    .rec-placeholder { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; color: var(--text-muted); }
    .rec-body { padding: 1rem; }
    .rec-brand { font-size: .72rem; font-weight: 600; text-transform: uppercase; letter-spacing: .08em; color: var(--text-muted); margin-bottom: .35rem; }
    .rec-name { font-size: .9rem; font-weight: 600; color: var(--text-primary); margin-bottom: .75rem; line-height: 1.35; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
    .rec-footer { display: flex; align-items: center; justify-content: space-between; }
    .rec-price { font-weight: 700; font-size: .95rem; color: var(--primary); font-family: var(--font-mono); }
    .rec-add-btn { width: 32px; height: 32px; border-radius: 50%; background: var(--primary-light); color: var(--primary); border: none; display: flex; align-items: center; justify-content: center; font-size: .75rem; cursor: pointer; transition: all .2s; }
    .rec-add-btn:hover { background: var(--primary); color: white; }

    /* ── Toast ── */
    #toast-container { position: fixed; top: 90px; right: 1.25rem; z-index: 9999; display: flex; flex-direction: column; gap: .5rem; pointer-events: none; }
    .toast-pill { display: flex; align-items: center; gap: .75rem; background: var(--text-primary); color: white; padding: .75rem 1.25rem; border-radius: 100px; font-size: .875rem; font-weight: 500; pointer-events: all; box-shadow: 0 4px 16px rgba(0,0,0,0.2); animation: toastSlide .3s cubic-bezier(0.34,1.56,0.64,1); white-space: nowrap; }
    .toast-pill.toast-success { background: var(--primary); }
    .toast-pill.toast-error { background: var(--danger); }
    .toast-pill.toast-quote { background: var(--quote); }
    @keyframes toastSlide { from { transform: translateX(100px); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
    .toast-out { animation: toastOut .25s ease-in forwards; }
    @keyframes toastOut { to { transform: translateX(100px); opacity: 0; } }

    @keyframes fadeUp { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: translateY(0); } }
    .fade-in { animation: fadeUp .45s ease-out both; }

    /* ══ MODALS ══════════════════════════════════════════════════ */
    .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.55); backdrop-filter: blur(4px); z-index: 10000; align-items: center; justify-content: center; padding: 1rem; }
    .modal-overlay.active { display: flex; animation: overlayFadeIn .25s ease; }
    @keyframes overlayFadeIn { from { opacity: 0; } to { opacity: 1; } }
    .modal-box { background: var(--surface); border-radius: var(--radius-xl); width: 100%; max-width: 520px; box-shadow: 0 24px 64px rgba(0,0,0,0.22); overflow: hidden; animation: modalSlideUp .35s cubic-bezier(0.34,1.56,0.64,1); max-height: 90vh; overflow-y: auto; }
    @keyframes modalSlideUp { from { transform: translateY(40px) scale(.97); opacity: 0; } to { transform: translateY(0) scale(1); opacity: 1; } }

    .modal-header { display: flex; align-items: center; gap: 1rem; padding: 1.5rem 1.5rem 1rem; border-bottom: 1px solid var(--border); position: sticky; top: 0; background: white; z-index: 1; }
    .modal-icon { width: 48px; height: 48px; background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%); border-radius: 14px; display: flex; align-items: center; justify-content: center; color: white; font-size: 1.2rem; flex-shrink: 0; }
    .modal-icon--quote { background: linear-gradient(135deg, var(--quote) 0%, var(--quote-dark) 100%); }
    .modal-header h5 { font-size: 1.1rem; font-weight: 700; margin: 0 0 .15rem; color: var(--text-primary); }
    .modal-header p { font-size: .82rem; color: var(--text-muted); margin: 0; }
    .modal-close { margin-left: auto; width: 34px; height: 34px; border-radius: 50%; border: 1px solid var(--border); background: none; color: var(--text-muted); font-size: .85rem; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all .2s; flex-shrink: 0; }
    .modal-close:hover { background: var(--danger); border-color: var(--danger); color: white; }
    .modal-body { padding: 1.5rem; }

    .modal-guest-bar { background: var(--guest-light); border: 1px solid rgba(245,158,11,0.25); border-radius: var(--radius-md); padding: .75rem 1rem; margin-bottom: 1.1rem; }
    .mgb-inner { display: flex; align-items: center; gap: .75rem; }
    .mgb-text { flex: 1; font-size: .82rem; color: var(--text-secondary); display: flex; align-items: center; gap: .5rem; }
    .mgb-text i { color: var(--guest); font-size: 1rem; flex-shrink: 0; }
    .mgb-text a { color: var(--guest); font-weight: 600; text-decoration: underline; text-underline-offset: 2px; }
    .mgb-dismiss { background: none; border: none; color: var(--text-muted); font-size: .75rem; cursor: pointer; padding: 0; flex-shrink: 0; }

    .form-group { margin-bottom: 1.1rem; }
    .form-label { display: flex; align-items: center; gap: .4rem; font-size: .85rem; font-weight: 600; color: var(--text-secondary); margin-bottom: .4rem; }
    .form-label i { color: var(--primary); font-size: .8rem; }
    .form-label .req { color: var(--danger); }
    .form-input { width: 100%; padding: .75rem 1rem; border: 1.5px solid var(--border); border-radius: var(--radius-sm); font-family: var(--font); font-size: .95rem; color: var(--text-primary); background: var(--bg); transition: border-color .2s, box-shadow .2s; outline: none; }
    .form-input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(12,177,0,0.12); background: white; }
    .form-input.is-invalid { border-color: var(--danger); box-shadow: 0 0 0 3px rgba(239,68,68,0.1); }
    .field-error { font-size: .78rem; color: var(--danger); margin-top: .3rem; min-height: 1em; }

    .modal-order-summary { background: var(--bg); border-radius: var(--radius-md); padding: 1rem 1.1rem; margin: 1.25rem 0; border: 1px solid var(--border); }
    .mos-title { font-size: .8rem; font-weight: 700; text-transform: uppercase; letter-spacing: .07em; color: var(--text-muted); margin-bottom: .75rem; }
    .mos-items { margin-bottom: .75rem; }
    .mos-item { display: flex; justify-content: space-between; align-items: flex-start; font-size: .85rem; color: var(--text-secondary); padding: .3rem 0; border-bottom: 1px solid var(--border); gap: .5rem; }
    .mos-item:last-child { border-bottom: none; }
    .mos-item-name { flex: 1; }
    .mos-item-qty { color: var(--text-muted); font-size: .78rem; white-space: nowrap; }
    .mos-item-price { font-weight: 600; white-space: nowrap; font-family: var(--font-mono); }
    .mos-total { display: flex; justify-content: space-between; font-size: .95rem; font-weight: 700; color: var(--text-primary); padding-top: .5rem; border-top: 2px solid var(--border); }
    .mos-total span:last-child { color: var(--primary); }

    /* Validity note inside quotation modal */
    .quote-validity-note { display: flex; align-items: center; gap: .6rem; background: var(--quote-light); border: 1px solid rgba(37,99,235,0.2); border-radius: var(--radius-sm); padding: .7rem 1rem; font-size: .83rem; color: var(--quote); font-weight: 500; margin-bottom: 1.25rem; }
    .quote-validity-note i { flex-shrink: 0; }

    .modal-actions { display: flex; gap: .75rem; margin-top: .5rem; }
    .modal-btn-cancel { flex: 1; padding: .75rem; border: 1.5px solid var(--border); border-radius: var(--radius-md); background: none; font-family: var(--font); font-size: .95rem; font-weight: 500; color: var(--text-secondary); cursor: pointer; transition: all .2s; }
    .modal-btn-cancel:hover { background: var(--bg); color: var(--text-primary); }
    .modal-btn-confirm { flex: 2; padding: .75rem; border: none; border-radius: var(--radius-md); background: var(--primary); font-family: var(--font); font-size: .95rem; font-weight: 600; color: white; cursor: pointer; transition: all .25s; display: flex; align-items: center; justify-content: center; gap: .4rem; }
    .modal-btn-confirm:hover { background: var(--primary-dark); transform: translateY(-1px); }
    .modal-btn-confirm:disabled { opacity: .6; cursor: not-allowed; transform: none; }
    .modal-btn-confirm--quote { background: var(--quote); }
    .modal-btn-confirm--quote:hover { background: var(--quote-dark); }

    /* Success step */
    .modal-success { text-align: center; padding: 1rem .5rem; }
    .success-icon { font-size: 3.5rem; color: var(--primary); margin-bottom: 1rem; animation: successPop .5s cubic-bezier(0.34,1.56,0.64,1); }
    .success-icon--quote { color: var(--quote); }
    @keyframes successPop { from { transform: scale(.5); opacity: 0; } to { transform: scale(1); opacity: 1; } }
    .modal-success h5 { font-size: 1.3rem; font-weight: 700; margin-bottom: .5rem; color: var(--text-primary); }
    .modal-success p { color: var(--text-secondary); font-size: .9rem; margin-bottom: 1.5rem; line-height: 1.6; }
    .success-guest-cta { background: var(--guest-light); border: 1px solid rgba(245,158,11,0.25); border-radius: var(--radius-md); padding: 1rem 1.25rem; margin-bottom: 1.25rem; text-align: left; }
    .sgc-text { font-size: .85rem; color: var(--text-secondary); margin: 0 0 .75rem; }
    .sgc-btn { display: inline-flex; align-items: center; background: var(--guest); color: white; border: none; border-radius: 8px; padding: .5rem 1.1rem; font-size: .85rem; font-weight: 600; text-decoration: none; transition: background .2s; }
    .sgc-btn:hover { background: #d97706; color: white; }
    .redirect-countdown { margin-top: 1.25rem; display: flex; flex-direction: column; align-items: center; gap: .5rem; }
    .countdown-bar { width: 100%; max-width: 260px; height: 4px; background: var(--border); border-radius: 100px; overflow: hidden; }
    .countdown-fill { height: 100%; width: 100%; background: var(--primary); border-radius: 100px; transform-origin: left; animation: countdownShrink 3s linear forwards; }
    @keyframes countdownShrink { from { transform: scaleX(1); } to { transform: scaleX(0); } }
    #orderCountdownText { font-size: .82rem; color: var(--text-muted); }

    @media (max-width: 768px) {
        .page-header { padding: 5.5rem 0 2.5rem; }
        .page-header h1 { font-size: 1.9rem; }
        .cart-card-inner { flex-wrap: wrap; }
        .product-thumb { width: 80px; height: 80px; }
        .summary-card { position: relative; top: auto; }
        .header-icon-wrap { display: none; }
        .modal-actions { flex-direction: column; }
        .modal-btn-cancel, .modal-btn-confirm { flex: unset; }
    }
</style>

<script>
// ── Helpers ────────────────────────────────────────────────────────────────
function escHtml(str) { const d = document.createElement('div'); d.textContent = str; return d.innerHTML; }
function numFmt(n)    { return Number(n).toLocaleString(); }

function showToast(message, type = 'info') {
    const icons = { success: 'check-circle', error: 'exclamation-circle', info: 'info-circle', quote: 'file-invoice' };
    const container = document.getElementById('toast-container');
    const toast = document.createElement('div');
    toast.className = `toast-pill toast-${type}`;
    toast.innerHTML = `<i class="fas fa-${icons[type] || 'info-circle'}"></i> ${message}`;
    container.appendChild(toast);
    setTimeout(() => { toast.classList.add('toast-out'); setTimeout(() => toast.remove(), 250); }, 3500);
}

function openModal(id) {
    document.getElementById(id).classList.add('active');
    document.body.style.overflow = 'hidden';
}
function closeModal(id) {
    document.getElementById(id).classList.remove('active');
    document.body.style.overflow = '';
}
function closeModalOnOverlay(e, id) {
    if (e.target === document.getElementById(id)) closeModal(id);
}
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        closeModal('orderModal');
        closeModal('quotationModal');
    }
});

// ── Populate mini-summary ──────────────────────────────────────────────────
function populateSummary(itemsContainerId, totalId) {
    document.getElementById(itemsContainerId).innerHTML = CART_ITEMS.map(item => `
        <div class="mos-item">
            <span class="mos-item-name">${escHtml(item.name)}</span>
            <span class="mos-item-qty">×${item.quantity}</span>
            <span class="mos-item-price">${item.currency} ${numFmt(item.item_total)}</span>
        </div>
    `).join('');
    const total = CART_ITEMS.reduce((s, i) => s + i.item_total, 0) + SHIPPING_COST;
    document.getElementById(totalId).textContent = CURRENCY + ' ' + numFmt(total);
}

// ── Validate helper ────────────────────────────────────────────────────────
function validateFields(fields) {
    let valid = true;
    fields.forEach(({ inputId, errorId, required, type }) => {
        const val = document.getElementById(inputId).value.trim();
        document.getElementById(inputId).classList.remove('is-invalid');
        if (errorId) document.getElementById(errorId).textContent = '';
        if (required && !val) {
            document.getElementById(inputId).classList.add('is-invalid');
            if (errorId) document.getElementById(errorId).textContent = 'This field is required.';
            valid = false;
        } else if (type === 'email' && val && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val)) {
            document.getElementById(inputId).classList.add('is-invalid');
            if (errorId) document.getElementById(errorId).textContent = 'Please enter a valid email address.';
            valid = false;
        } else if (type === 'phone' && val && !/^[\d\s\+\-\(\)]{7,20}$/.test(val)) {
            document.getElementById(inputId).classList.add('is-invalid');
            if (errorId) document.getElementById(errorId).textContent = 'Please enter a valid phone number.';
            valid = false;
        }
    });
    return valid;
}

// ── Quantity / cart helpers ────────────────────────────────────────────────
function updateQuantity(productId, quantity) {
    const qty = parseInt(quantity);
    if (isNaN(qty) || qty < 1) { showToast('Please enter a valid quantity', 'error'); return; }
    const form = document.createElement('form');
    form.method = 'POST'; form.style.display = 'none';
    [['action','update_quantity'],['product_id',productId],['quantity',qty]].forEach(([n,v]) => {
        const i = document.createElement('input'); i.type = 'hidden'; i.name = n; i.value = v; form.appendChild(i);
    });
    document.body.appendChild(form); form.submit();
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
            button.style.background = 'var(--primary)'; button.style.color = 'white';
            showToast('Added to cart!', 'success');
            setTimeout(() => window.location.reload(), 1200);
        } else {
            showToast(data.message || 'Error adding item', 'error');
            button.innerHTML = origHtml; button.disabled = false;
        }
    })
    .catch(() => {
        showToast('Network error. Please try again.', 'error');
        button.innerHTML = origHtml; button.disabled = false;
    });
}

// ══ ORDER MODAL ═══════════════════════════════════════════════════════════
function openOrderModal() {
    if (document.querySelectorAll('.stock-alert').length > 0) {
        if (!confirm('Some items have stock issues. Proceed anyway?')) return;
    }
    populateSummary('orderMosItems', 'orderMosTotal');
    ['orderFullName','orderPhone','orderEmail'].forEach(id => {
        document.getElementById(id).value = '';
        document.getElementById(id).classList.remove('is-invalid');
    });
    ['orderNameError','orderPhoneError','orderEmailError'].forEach(id => {
        document.getElementById(id).textContent = '';
    });
    document.getElementById('orderFormStep').style.display = '';
    document.getElementById('orderSuccessStep').style.display = 'none';
    openModal('orderModal');
    setTimeout(() => document.getElementById('orderFullName').focus(), 300);
}

function confirmOrder() {
    const valid = validateFields([
        { inputId: 'orderFullName', errorId: 'orderNameError',  required: true },
        { inputId: 'orderPhone',    errorId: 'orderPhoneError', required: true, type: 'phone' },
        { inputId: 'orderEmail',    errorId: 'orderEmailError', required: true, type: 'email' },
    ]);
    if (!valid) return;

    const btn = document.getElementById('confirmOrderBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Sending...';

    const orderNumber = 'ORD-' + Date.now().toString(36).toUpperCase();
    const payload = {
        full_name:    document.getElementById('orderFullName').value.trim(),
        phone:        document.getElementById('orderPhone').value.trim(),
        email:        document.getElementById('orderEmail').value.trim(),
        order_number: orderNumber,
        cart_items:   CART_ITEMS,
        subtotal:     CART_SUBTOTAL,
        shipping:     SHIPPING_COST,
        total:        CART_TOTAL,
        currency:     CURRENCY,
        is_guest:     IS_GUEST
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
            document.getElementById('orderFormStep').style.display  = 'none';
            document.getElementById('orderSuccessStep').style.display = '';
            if (IS_GUEST) {
                fetch('cart.php', { method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'}, body: 'action=clear_cart' });
            }
            let secs = 3;
            const fill  = document.getElementById('orderCountdownFill');
            const clone = fill.cloneNode(true); fill.parentNode.replaceChild(clone, fill);
            const ticker = setInterval(() => {
                secs--;
                const el = document.getElementById('orderCountdownNum');
                if (el) el.textContent = secs;
                if (secs <= 0) { clearInterval(ticker); window.location.href = 'index.php'; }
            }, 1000);
        } else {
            showToast(data.message || 'Failed to place order. Please try again.', 'error');
            btn.disabled = false; btn.innerHTML = '<i class="fas fa-check me-1"></i> Confirm Order';
        }
    })
    .catch(() => {
        showToast('Network error. Please try again.', 'error');
        btn.disabled = false; btn.innerHTML = '<i class="fas fa-check me-1"></i> Confirm Order';
    });
}

// ══ QUOTATION MODAL ═══════════════════════════════════════════════════════
function openQuotationModal() {
    populateSummary('quoteMosItems', 'quoteMosTotal');
    ['quoteFullName','quotePhone','quoteEmail'].forEach(id => {
        document.getElementById(id).value = '';
        document.getElementById(id).classList.remove('is-invalid');
    });
    ['quoteNameError','quoteEmailError'].forEach(id => {
        document.getElementById(id).textContent = '';
    });
    document.getElementById('quotationFormStep').style.display   = '';
    document.getElementById('quotationSuccessStep').style.display = 'none';
    openModal('quotationModal');
    setTimeout(() => document.getElementById('quoteFullName').focus(), 300);
}

function confirmQuotation() {
    const valid = validateFields([
        { inputId: 'quoteFullName', errorId: 'quoteNameError',  required: true },
        { inputId: 'quoteEmail',    errorId: 'quoteEmailError', required: true, type: 'email' },
    ]);
    if (!valid) return;

    const btn = document.getElementById('confirmQuoteBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Sending...';

    const quotationNumber = 'QUO-' + Date.now().toString(36).toUpperCase();
    const payload = {
        full_name:        document.getElementById('quoteFullName').value.trim(),
        phone:            document.getElementById('quotePhone').value.trim(),
        email:            document.getElementById('quoteEmail').value.trim(),
        quotation_number: quotationNumber,
        cart_items:       CART_ITEMS,
        subtotal:         CART_SUBTOTAL,
        shipping:         SHIPPING_COST,
        total:            CART_TOTAL,
        currency:         CURRENCY,
        is_guest:         IS_GUEST
    };

    fetch('send_quotation_email.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            document.getElementById('successQuoteNumber').textContent = '#' + quotationNumber;
            document.getElementById('successQuoteEmail').textContent  = document.getElementById('quoteEmail').value.trim();
            document.getElementById('quotationFormStep').style.display   = 'none';
            document.getElementById('quotationSuccessStep').style.display = '';
            showToast('Quotation sent successfully!', 'quote');
        } else {
            showToast(data.message || 'Failed to send quotation. Please try again.', 'error');
            btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane me-1"></i> Send Quotation';
        }
    })
    .catch(() => {
        showToast('Network error. Please try again.', 'error');
        btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane me-1"></i> Send Quotation';
    });
}

// ── Init ──────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.cart-card').forEach((card, i) => {
        card.style.animationDelay = `${i * 80}ms`;
    });
    document.querySelectorAll('.qty-value').forEach(input => {
        let timer;
        input.addEventListener('input', function () {
            clearTimeout(timer);
            const pid = this.dataset.productId;
            timer = setTimeout(() => updateQuantity(pid, this.value), 900);
        });
    });
});
</script>

<?php include 'footer.php'; ?>