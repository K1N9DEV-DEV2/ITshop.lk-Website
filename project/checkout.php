<?php
// ============================================================
//  checkout.php  —  STC Electronics Store
//  PayHere Checkout API Integration (Redirect Method)
// ============================================================
session_start();
include 'db.php';

// ── PayHere Configuration ────────────────────────────────────
define('PAYHERE_MERCHANT_ID',  'YOUR_MERCHANT_ID');   // ← Replace
define('PAYHERE_MERCHANT_SECRET', 'YOUR_MERCHANT_SECRET'); // ← Replace
define('PAYHERE_MODE', 'sandbox'); // 'sandbox' or 'live'
define('SITE_URL', 'https://yourdomain.com');          // ← Replace (no trailing slash)

define('PAYHERE_URL', PAYHERE_MODE === 'live'
    ? 'https://www.payhere.lk/pay/checkout'
    : 'https://sandbox.payhere.lk/pay/checkout');

// ── Auth ─────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=checkout.php');
    exit();
}

$user_id       = $_SESSION['user_id'];
$user_currency = 'LKR';
$shipping_cost = 500;
$tax_rate      = 0;
$cart_items    = [];
$subtotal      = 0;
$cart_count    = 0;
$errors        = [];

// ── Fetch user ───────────────────────────────────────────────
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) { $user = []; }

// ── Fetch cart ───────────────────────────────────────────────
try {
    $stmt = $pdo->prepare("
        SELECT c.product_id, c.quantity,
               p.name, p.brand, p.image, p.price AS current_price,
               p.stock_count, p.in_stock, p.category
        FROM cart c
        JOIN products p ON c.product_id = p.id
        WHERE c.user_id = ?
        ORDER BY c.created_at DESC
    ");
    $stmt->execute([$user_id]);
    $cart_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($cart_items)) { header('Location: cart.php'); exit(); }

    foreach ($cart_items as &$item) {
        $item['item_total'] = $item['current_price'] * $item['quantity'];
        $subtotal          += $item['item_total'];
        $cart_count        += $item['quantity'];
    }
    unset($item);

    $tax_amount  = $subtotal * $tax_rate;
    $order_total = $subtotal + $tax_amount + $shipping_cost;

} catch (PDOException $e) {
    die("Error loading cart. Please try again.");
}

// ── Handle form → create pending order → redirect to PayHere ─
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['proceed_payhere'])) {

    $full_name      = trim($_POST['full_name']      ?? '');
    $email          = trim($_POST['email']          ?? '');
    $phone          = trim($_POST['phone']          ?? '');
    $address        = trim($_POST['address']        ?? '');
    $city           = trim($_POST['city']           ?? '');
    $postal_code    = trim($_POST['postal_code']    ?? '');
    $payment_method = trim($_POST['payment_method'] ?? 'payhere');
    $notes          = trim($_POST['notes']          ?? '');

    // Validate
    if (empty($full_name))  $errors[] = 'Full name is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
    if (empty($phone))      $errors[] = 'Phone number is required.';
    if (empty($address))    $errors[] = 'Address is required.';
    if (empty($city))       $errors[] = 'City is required.';

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // Split name for PayHere
            $name_parts  = explode(' ', $full_name, 2);
            $first_name  = $name_parts[0];
            $last_name   = $name_parts[1] ?? '';

            // Create PENDING order
            $stmt = $pdo->prepare("
                INSERT INTO orders
                    (user_id, first_name, last_name, email, phone, address, city,
                     postal_code, payment_method, notes, subtotal, shipping_cost,
                     tax_amount, total, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
            ");
            $stmt->execute([
                $user_id, $first_name, $last_name, $email, $phone,
                $address, $city, $postal_code, $payment_method,
                $notes, $subtotal, $shipping_cost, $tax_amount, $order_total
            ]);
            $order_id = $pdo->lastInsertId();

            // Insert order items
            $item_stmt = $pdo->prepare("
                INSERT INTO order_items (order_id, product_id, quantity, price, total)
                VALUES (?, ?, ?, ?, ?)
            ");
            foreach ($cart_items as $item) {
                $item_stmt->execute([
                    $order_id, $item['product_id'], $item['quantity'],
                    $item['current_price'], $item['item_total']
                ]);
            }

            $pdo->commit();

            // Build PayHere hash
            $order_ref   = 'STC-' . str_pad($order_id, 6, '0', STR_PAD_LEFT);
            $amount_fmt  = number_format($order_total, 2, '.', '');
            $currency    = 'LKR';
            $hash        = strtoupper(md5(
                PAYHERE_MERCHANT_ID .
                $order_ref .
                $amount_fmt .
                $currency .
                strtoupper(md5(PAYHERE_MERCHANT_SECRET))
            ));

            // Build items string
            $items_str = implode(', ', array_map(fn($i) => $i['name'], $cart_items));

            // Store PayHere params in session for the auto-submit form
            $_SESSION['payhere_data'] = [
                'merchant_id'  => PAYHERE_MERCHANT_ID,
                'return_url'   => SITE_URL . '/payment-success.php',
                'cancel_url'   => SITE_URL . '/payment-cancel.php',
                'notify_url'   => SITE_URL . '/payhere-notify.php',
                'order_id'     => $order_ref,
                'items'        => substr($items_str, 0, 255),
                'currency'     => $currency,
                'amount'       => $amount_fmt,
                'first_name'   => $first_name,
                'last_name'    => $last_name,
                'email'        => $email,
                'phone'        => $phone,
                'address'      => $address,
                'city'         => $city,
                'country'      => 'Sri Lanka',
                'hash'         => $hash,
            ];
            $_SESSION['pending_order_id'] = $order_id;

            header('Location: checkout.php?step=pay');
            exit();

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = 'Could not create order. Please try again.';
        }
    }
}

$page_title = 'Checkout – STC Electronics Store';
include 'header.php';
?>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">

<!-- Page Header -->
<section class="page-header">
    <div class="container">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                <li class="breadcrumb-item"><a href="cart.php">Cart</a></li>
                <li class="breadcrumb-item active">Checkout</li>
            </ol>
        </nav>
        <div class="header-content">
            <div class="header-icon-wrap"><i class="fas fa-lock"></i></div>
            <div>
                <h1>Secure Checkout</h1>
                <p class="lead">Complete your order safely and securely</p>
            </div>
        </div>
    </div>
</section>

<!-- Progress Steps -->
<div class="progress-bar-wrap">
    <div class="container">
        <div class="checkout-steps">
            <div class="step completed">
                <div class="step-dot"><i class="fas fa-check"></i></div>
                <span>Cart</span>
            </div>
            <div class="step-line completed"></div>
            <div class="step <?php echo !isset($_GET['step']) ? 'active' : 'completed'; ?>">
                <div class="step-dot"><i class="fas fa-<?php echo isset($_GET['step']) ? 'check' : 'user'; ?>"></i></div>
                <span>Details</span>
            </div>
            <div class="step-line <?php echo isset($_GET['step']) ? 'completed' : ''; ?>"></div>
            <div class="step <?php echo isset($_GET['step']) ? 'active' : ''; ?>">
                <div class="step-dot"><i class="fas fa-credit-card"></i></div>
                <span>Payment</span>
            </div>
            <div class="step-line"></div>
            <div class="step">
                <div class="step-dot"><span>4</span></div>
                <span>Done</span>
            </div>
        </div>
    </div>
</div>

<section class="checkout-section">
    <div class="container">

<?php if (isset($_GET['step']) && $_GET['step'] === 'pay' && isset($_SESSION['payhere_data'])): ?>
<!-- ══ STEP 2: PayHere redirect confirmation ══════════════════ -->
<?php $ph = $_SESSION['payhere_data']; ?>
<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="pay-confirm-card fade-in">
            <div class="pay-confirm-icon">
                <img src="https://www.payhere.lk/downloads/images/payhere_logo.png"
                     alt="PayHere" style="height:36px;" onerror="this.style.display='none'">
            </div>
            <h3>Ready to Pay</h3>
            <p>You'll be securely redirected to PayHere to complete your payment.</p>

            <div class="order-summary-mini">
                <div class="mini-line"><span>Order Ref</span><strong><?php echo htmlspecialchars($ph['order_id']); ?></strong></div>
                <div class="mini-line"><span>Amount</span><strong>LKR <?php echo number_format((float)$ph['amount'], 2); ?></strong></div>
                <div class="mini-line"><span>Items</span><strong><?php echo htmlspecialchars(substr($ph['items'], 0, 60)) . (strlen($ph['items']) > 60 ? '…' : ''); ?></strong></div>
            </div>

            <!-- Auto-submit PayHere form -->
            <form id="payhere-form" method="POST" action="<?php echo PAYHERE_URL; ?>">
                <?php foreach ($ph as $key => $val): ?>
                    <input type="hidden" name="<?php echo htmlspecialchars($key); ?>"
                           value="<?php echo htmlspecialchars($val); ?>">
                <?php endforeach; ?>

                <button type="submit" class="btn-payhere" id="payhere-btn">
                    <i class="fas fa-lock me-2"></i>
                    Pay LKR <?php echo number_format((float)$ph['amount'], 2); ?> via PayHere
                </button>
            </form>

            <a href="checkout.php" class="btn-outline-round mt-2" onclick="cancelPending()">
                <i class="fas fa-arrow-left me-1"></i> Go Back
            </a>

            <div class="payhere-badges">
                <i class="fab fa-cc-visa"></i>
                <i class="fab fa-cc-mastercard"></i>
                <i class="fas fa-mobile-alt"></i>
                <i class="fas fa-university"></i>
                <span class="ssl-badge"><i class="fas fa-lock"></i> SSL Secured</span>
            </div>
        </div>
    </div>
</div>

<script>
// Auto-submit after 2s countdown
let countdown = 3;
const btn = document.getElementById('payhere-btn');
function tick() {
    if (countdown > 0) {
        btn.innerHTML = `<i class="fas fa-lock me-2"></i> Redirecting to PayHere in ${countdown}s…`;
        countdown--;
        setTimeout(tick, 1000);
    } else {
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Redirecting…';
        document.getElementById('payhere-form').submit();
    }
}
setTimeout(tick, 800);

function cancelPending() {
    fetch('cancel-pending-order.php', { method: 'POST' });
}
</script>

<?php else: ?>
<!-- ══ STEP 1: Delivery + Payment method form ════════════════ -->

<?php if (!empty($errors)): ?>
<div class="alert-pill alert-pill-danger">
    <i class="fas fa-exclamation-circle"></i>
    <div><?php foreach ($errors as $e) echo '<div>' . htmlspecialchars($e) . '</div>'; ?></div>
    <button class="pill-close" onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>
</div>
<?php endif; ?>

<form method="POST" id="checkout-form" novalidate>
<div class="row g-4">

    <!-- Left: forms -->
    <div class="col-lg-7">

        <!-- Delivery card -->
        <div class="form-card fade-in">
            <div class="form-card-header">
                <div class="card-icon"><i class="fas fa-map-marker-alt"></i></div>
                <div><h5>Delivery Information</h5><p>Where should we send your order?</p></div>
            </div>
            <div class="form-body">
                <div class="row g-3">
                    <div class="col-12">
                        <div class="field-group">
                            <label class="field-label">Full Name <span class="req">*</span></label>
                            <div class="input-wrap">
                                <i class="fas fa-user field-icon"></i>
                                <input type="text" name="full_name" class="field-input" placeholder="John Doe"
                                       value="<?php echo htmlspecialchars($_POST['full_name'] ?? ($user['name'] ?? '')); ?>" required>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="field-group">
                            <label class="field-label">Email <span class="req">*</span></label>
                            <div class="input-wrap">
                                <i class="fas fa-envelope field-icon"></i>
                                <input type="email" name="email" class="field-input" placeholder="you@email.com"
                                       value="<?php echo htmlspecialchars($_POST['email'] ?? ($user['email'] ?? '')); ?>" required>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="field-group">
                            <label class="field-label">Phone <span class="req">*</span></label>
                            <div class="input-wrap">
                                <i class="fas fa-phone field-icon"></i>
                                <input type="tel" name="phone" class="field-input" placeholder="+94 71 234 5678"
                                       value="<?php echo htmlspecialchars($_POST['phone'] ?? ($user['phone'] ?? '')); ?>" required>
                            </div>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="field-group">
                            <label class="field-label">Street Address <span class="req">*</span></label>
                            <div class="input-wrap">
                                <i class="fas fa-home field-icon"></i>
                                <input type="text" name="address" class="field-input" placeholder="123 Main Street"
                                       value="<?php echo htmlspecialchars($_POST['address'] ?? ($user['address'] ?? '')); ?>" required>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-7">
                        <div class="field-group">
                            <label class="field-label">City <span class="req">*</span></label>
                            <div class="input-wrap">
                                <i class="fas fa-city field-icon"></i>
                                <input type="text" name="city" class="field-input" placeholder="Colombo"
                                       value="<?php echo htmlspecialchars($_POST['city'] ?? ($user['city'] ?? '')); ?>" required>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-5">
                        <div class="field-group">
                            <label class="field-label">Postal Code</label>
                            <div class="input-wrap">
                                <i class="fas fa-hashtag field-icon"></i>
                                <input type="text" name="postal_code" class="field-input" placeholder="00100"
                                       value="<?php echo htmlspecialchars($_POST['postal_code'] ?? ($user['postal_code'] ?? '')); ?>">
                            </div>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="field-group">
                            <label class="field-label">Order Notes <span class="optional">(optional)</span></label>
                            <div class="input-wrap textarea-wrap">
                                <i class="fas fa-sticky-note field-icon field-icon-top"></i>
                                <textarea name="notes" class="field-input" rows="3"
                                          placeholder="Delivery instructions, special requests..."><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Payment method card -->
        <div class="form-card fade-in" style="animation-delay:100ms">
            <div class="form-card-header">
                <div class="card-icon"><i class="fas fa-credit-card"></i></div>
                <div><h5>Payment Method</h5><p>Choose how you'd like to pay</p></div>
            </div>
            <div class="form-body">
                <div class="payment-options">

                    <!-- PayHere (recommended) -->
                    <label class="payment-option payhere-option">
                        <input type="radio" name="payment_method" value="payhere" checked>
                        <div class="option-content">
                            <div class="option-left">
                                <div class="option-icons payhere-logo-wrap">
                                    <img src="https://www.payhere.lk/downloads/images/payhere_logo.png"
                                         alt="PayHere" style="height:28px;"
                                         onerror="this.outerHTML='<i class=\'fas fa-credit-card\'></i>'">
                                </div>
                                <div>
                                    <div class="option-title">PayHere <span class="recommended-tag">Recommended</span></div>
                                    <div class="option-sub">Visa · Mastercard · eZ Cash · mCash · Internet Banking</div>
                                </div>
                            </div>
                            <div class="option-check"><i class="fas fa-check"></i></div>
                        </div>
                        <div class="payhere-info">
                            <div class="payhere-cards">
                                <i class="fab fa-cc-visa"></i>
                                <i class="fab fa-cc-mastercard"></i>
                                <i class="fas fa-mobile-alt"></i>
                                <i class="fas fa-university"></i>
                            </div>
                            <p class="payhere-desc">You'll be securely redirected to PayHere's payment page to complete your purchase. Your card details are never shared with us.</p>
                        </div>
                    </label>

                    <!-- Bank Transfer -->
                    <label class="payment-option">
                        <input type="radio" name="payment_method" value="bank_transfer">
                        <div class="option-content">
                            <div class="option-left">
                                <div class="option-icons"><i class="fas fa-university" style="color:var(--primary);font-size:1.3rem;"></i></div>
                                <div>
                                    <div class="option-title">Bank Transfer</div>
                                    <div class="option-sub">Direct deposit — we'll confirm manually</div>
                                </div>
                            </div>
                            <div class="option-check"><i class="fas fa-check"></i></div>
                        </div>
                        <div class="bank-info">
                            <div class="bank-detail-row"><span>Bank</span><strong>Commercial Bank of Ceylon</strong></div>
                            <div class="bank-detail-row"><span>Account Name</span><strong>STC Electronics (Pvt) Ltd</strong></div>
                            <div class="bank-detail-row"><span>Account No.</span><strong>1234567890</strong></div>
                            <div class="bank-detail-row"><span>Branch</span><strong>Negombo</strong></div>
                            <p style="font-size:.78rem;color:var(--text-muted);margin-top:.75rem 0 0;">Please use your Order ID as the payment reference. Orders are confirmed after deposit verification (1–2 business days).</p>
                        </div>
                    </label>

                    <!-- Cash on Delivery -->
                    <label class="payment-option">
                        <input type="radio" name="payment_method" value="cash_on_delivery">
                        <div class="option-content">
                            <div class="option-left">
                                <div class="option-icons"><i class="fas fa-money-bill-wave" style="color:#16a34a;font-size:1.3rem;"></i></div>
                                <div>
                                    <div class="option-title">Cash on Delivery</div>
                                    <div class="option-sub">Pay when you receive your order</div>
                                </div>
                            </div>
                            <div class="option-check"><i class="fas fa-check"></i></div>
                        </div>
                    </label>

                </div>
            </div>
        </div>

        <div class="security-notice fade-in" style="animation-delay:180ms">
            <i class="fas fa-shield-alt"></i>
            <span>All transactions are encrypted with 256-bit SSL. We never store your card details.</span>
        </div>
    </div><!-- /col-lg-7 -->

    <!-- Right: Order summary -->
    <div class="col-lg-5">
        <div class="summary-card">
            <div class="summary-header">
                <h4>Order Summary</h4>
                <a href="cart.php" class="edit-link"><i class="fas fa-pencil-alt me-1"></i>Edit</a>
            </div>
            <div class="order-items">
                <?php foreach ($cart_items as $item): ?>
                <div class="order-item">
                    <div class="item-thumb">
                        <img src="<?php echo htmlspecialchars($item['image']); ?>"
                             alt="<?php echo htmlspecialchars($item['name']); ?>"
                             onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                        <div class="thumb-placeholder" style="display:none;"><i class="fas fa-laptop"></i></div>
                        <span class="item-qty-badge"><?php echo $item['quantity']; ?></span>
                    </div>
                    <div class="item-info">
                        <div class="item-brand"><?php echo htmlspecialchars($item['brand']); ?></div>
                        <div class="item-name"><?php echo htmlspecialchars($item['name']); ?></div>
                    </div>
                    <div class="item-price">LKR <?php echo number_format($item['item_total']); ?></div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="summary-divider"></div>
            <div class="summary-body">
                <div class="summary-line">
                    <span>Subtotal (<?php echo $cart_count; ?> items)</span>
                    <span>LKR <?php echo number_format($subtotal); ?></span>
                </div>
                <div class="summary-line">
                    <span>Shipping</span>
                    <span class="shipping-tag">LKR <?php echo number_format($shipping_cost); ?></span>
                </div>
                <?php if ($tax_rate > 0): ?>
                <div class="summary-line">
                    <span>Tax (<?php echo $tax_rate * 100; ?>%)</span>
                    <span>LKR <?php echo number_format($tax_amount); ?></span>
                </div>
                <?php endif; ?>
                <div class="summary-divider"></div>
                <div class="summary-total">
                    <span>Total</span>
                    <span>LKR <?php echo number_format($order_total); ?></span>
                </div>
            </div>

            <div class="summary-actions">
                <button type="submit" name="proceed_payhere" class="btn-checkout" id="submit-btn">
                    <i class="fas fa-lock me-2"></i>Continue to Payment
                </button>
                <a href="cart.php" class="btn-outline-round">
                    <i class="fas fa-arrow-left me-1"></i>Back to Cart
                </a>
            </div>
            <div class="payment-methods">
                <span class="pm-label">Accepted</span>
                <div class="pm-icons">
                    <i class="fab fa-cc-visa"></i>
                    <i class="fab fa-cc-mastercard"></i>
                    <i class="fas fa-mobile-alt"></i>
                    <i class="fas fa-university"></i>
                </div>
            </div>
        </div>
    </div>
</div><!-- /row -->
</form>

<?php endif; /* step */ ?>

    </div><!-- /container -->
</section>

<!-- ══ STYLES ═══════════════════════════════════════════════════ -->
<style>
:root{
    --primary:#0cb100;--primary-dark:#087600;--primary-light:#f0fff0;
    --danger:#EF4444;--danger-light:#fff1f1;
    --text-primary:#0f172a;--text-secondary:#64748b;--text-muted:#94a3b8;
    --bg:#f1f5f9;--surface:#fff;--border:#e2e8f0;
    --radius-md:16px;--radius-lg:24px;--radius-xl:32px;
    --shadow-sm:0 1px 4px rgba(0,0,0,.04),0 4px 12px rgba(0,0,0,.06);
    --shadow-md:0 4px 16px rgba(0,0,0,.08),0 8px 32px rgba(0,0,0,.06);
    --font:'Red Hat Display',sans-serif;
}
*{box-sizing:border-box}
body{font-family:var(--font);background:var(--bg);color:var(--text-primary)}

/* Header */
.page-header{padding:7rem 0 3.5rem;margin-top:80px;position:relative;overflow:hidden;background:#000}
.page-header::before{content:'';position:absolute;inset:0;
    background:radial-gradient(ellipse at 80% 50%,rgba(59,246,62,.25) 0%,transparent 60%),
               radial-gradient(ellipse at 20% 80%,rgba(22,196,127,.2) 0%,transparent 50%)}
.page-header .container{position:relative}
.breadcrumb{background:transparent;padding:0;margin-bottom:1.5rem}
.breadcrumb-item a{color:rgba(255,255,255,.7);text-decoration:none;font-size:.875rem;transition:color .2s}
.breadcrumb-item a:hover{color:#fff}
.breadcrumb-item.active{color:rgba(255,255,255,.9);font-size:.875rem}
.breadcrumb-item+.breadcrumb-item::before{color:rgba(255,255,255,.4)}
.header-content{display:flex;align-items:center;gap:1.25rem}
.header-icon-wrap{width:60px;height:60px;background:rgba(255,255,255,.15);backdrop-filter:blur(10px);border-radius:18px;display:flex;align-items:center;justify-content:center;font-size:1.5rem;color:#fff;flex-shrink:0;border:1px solid rgba(255,255,255,.2)}
.page-header h1{font-size:2.5rem;font-weight:700;color:#fff;margin:0 0 .25rem;letter-spacing:-.03em}
.page-header .lead{color:rgba(255,255,255,.75);font-size:1rem;margin:0}

/* Progress */
.progress-bar-wrap{background:var(--surface);border-bottom:1px solid var(--border);padding:1.25rem 0}
.checkout-steps{display:flex;align-items:center;justify-content:center;max-width:520px;margin:0 auto}
.step{display:flex;flex-direction:column;align-items:center;gap:.4rem;flex-shrink:0}
.step span{font-size:.72rem;font-weight:500;color:var(--text-muted)}
.step.active span{color:var(--primary);font-weight:600}
.step.completed span{color:var(--text-secondary)}
.step-dot{width:36px;height:36px;border-radius:50%;background:var(--bg);border:2px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:.8rem;color:var(--text-muted);font-weight:700;transition:all .3s}
.step.active .step-dot{background:var(--primary);border-color:var(--primary);color:#fff;box-shadow:0 0 0 4px rgba(12,177,0,.15)}
.step.completed .step-dot{background:var(--primary);border-color:var(--primary);color:#fff}
.step-line{flex:1;height:2px;background:var(--border);margin:0 .5rem .8rem;transition:background .3s}
.step-line.completed{background:var(--primary)}

/* Checkout section */
.checkout-section{padding:2.5rem 0 4rem}

/* Alert */
.alert-pill{display:flex;align-items:flex-start;gap:.75rem;background:var(--danger-light);color:var(--danger);border:1px solid rgba(239,68,68,.2);border-radius:var(--radius-md);padding:1rem 1.25rem;margin-bottom:1.5rem;font-size:.9rem;font-weight:500}
.pill-close{margin-left:auto;background:none;border:none;color:var(--danger);cursor:pointer;opacity:.6;flex-shrink:0}
.pill-close:hover{opacity:1}

/* Form card */
.form-card{background:var(--surface);border-radius:var(--radius-lg);box-shadow:var(--shadow-sm);border:1px solid var(--border);margin-bottom:1.25rem;overflow:hidden}
.form-card-header{display:flex;align-items:center;gap:1rem;padding:1.5rem;border-bottom:1px solid var(--border);background:var(--bg)}
.card-icon{width:44px;height:44px;background:var(--primary-light);border-radius:12px;display:flex;align-items:center;justify-content:center;color:var(--primary);font-size:1rem;flex-shrink:0}
.form-card-header h5{margin:0;font-size:1.05rem;font-weight:700}
.form-card-header p{margin:0;font-size:.85rem;color:var(--text-secondary)}
.form-body{padding:1.5rem}

/* Fields */
.field-group{display:flex;flex-direction:column;gap:.4rem}
.field-label{font-size:.85rem;font-weight:600;color:var(--text-secondary)}
.req{color:var(--danger)}.optional{color:var(--text-muted);font-weight:400;font-size:.78rem}
.input-wrap{position:relative;display:flex;align-items:center}
.field-icon{position:absolute;left:1rem;color:var(--text-muted);font-size:.875rem;pointer-events:none;z-index:1}
.field-icon-top{top:1rem;align-self:flex-start}
.field-input{width:100%;padding:.8rem 1rem .8rem 2.75rem;border:1.5px solid var(--border);border-radius:var(--radius-md);font-family:var(--font);font-size:.95rem;color:var(--text-primary);background:var(--surface);transition:border-color .2s,box-shadow .2s;outline:none}
textarea.field-input{resize:vertical;padding-top:.8rem}
.field-input:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(12,177,0,.12)}
.field-input::placeholder{color:var(--text-muted)}

/* Payment options */
.payment-options{display:flex;flex-direction:column;gap:.75rem}
.payment-option{cursor:pointer;border:1.5px solid var(--border);border-radius:var(--radius-md);overflow:hidden;transition:border-color .2s,box-shadow .2s;display:block}
.payment-option input[type=radio]{display:none}
.payment-option:has(input:checked){border-color:var(--primary);box-shadow:0 0 0 3px rgba(12,177,0,.12)}
.payhere-option:has(input:checked){border-color:var(--primary);background:linear-gradient(135deg,#f0fff0 0%,#fff 60%)}
.option-content{display:flex;align-items:center;justify-content:space-between;padding:1rem 1.25rem}
.option-left{display:flex;align-items:center;gap:1rem}
.option-icons{display:flex;gap:.35rem;font-size:1.6rem;flex-shrink:0}
.payhere-logo-wrap{align-items:center}
.option-title{font-size:.95rem;font-weight:600;color:var(--text-primary)}
.option-sub{font-size:.78rem;color:var(--text-muted)}
.recommended-tag{background:var(--primary);color:#fff;font-size:.65rem;font-weight:700;padding:.15rem .5rem;border-radius:100px;margin-left:.4rem;vertical-align:middle}
.option-check{width:22px;height:22px;border-radius:50%;border:2px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:.65rem;color:transparent;transition:all .2s;flex-shrink:0}
.payment-option:has(input:checked) .option-check{background:var(--primary);border-color:var(--primary);color:#fff}

/* Expandable sections */
.payhere-info,.bank-info{display:none;padding:0 1.25rem 1.25rem;animation:fadeDown .25s ease}
@keyframes fadeDown{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:translateY(0)}}
.payment-option:has(input[value=payhere]:checked) .payhere-info,
.payment-option:has(input[value=bank_transfer]:checked) .bank-info{display:block}
.payhere-cards{display:flex;gap:.5rem;font-size:1.6rem;color:var(--text-muted);margin-bottom:.75rem}
.payhere-cards .fa-cc-visa{color:#1a1f71}
.payhere-cards .fa-cc-mastercard{color:#eb001b}
.payhere-cards .fa-mobile-alt,.payhere-cards .fa-university{color:var(--primary)}
.payhere-desc{font-size:.8rem;color:var(--text-muted);margin:0;line-height:1.5;background:var(--bg);padding:.75rem 1rem;border-radius:10px;border:1px solid var(--border)}
.bank-detail-row{display:flex;justify-content:space-between;padding:.5rem 0;border-bottom:1px solid var(--border);font-size:.875rem}
.bank-detail-row:last-of-type{border-bottom:none}
.bank-detail-row span{color:var(--text-muted)}

/* Security notice */
.security-notice{display:flex;align-items:center;gap:.75rem;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-md);padding:.9rem 1.25rem;font-size:.82rem;color:var(--text-muted)}
.security-notice .fa-shield-alt{color:var(--primary);font-size:1rem;flex-shrink:0}

/* Summary card */
.summary-card{background:var(--surface);border-radius:var(--radius-lg);box-shadow:var(--shadow-sm);border:1px solid var(--border);overflow:hidden;position:sticky;top:100px}
.summary-header{padding:1.5rem;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border)}
.summary-header h4{font-size:1.1rem;font-weight:700;margin:0}
.edit-link{font-size:.82rem;color:var(--primary);text-decoration:none;font-weight:500}
.edit-link:hover{text-decoration:underline}
.order-items{padding:1rem 1.5rem;max-height:260px;overflow-y:auto}
.order-item{display:flex;align-items:center;gap:.9rem;padding:.65rem 0;border-bottom:1px solid var(--border)}
.order-item:last-child{border-bottom:none}
.item-thumb{width:52px;height:52px;border-radius:10px;background:var(--bg);overflow:hidden;flex-shrink:0;display:flex;align-items:center;justify-content:center;position:relative}
.item-thumb img{width:100%;height:100%;object-fit:cover}
.thumb-placeholder{width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:var(--text-muted)}
.item-qty-badge{position:absolute;top:-4px;right:-4px;width:18px;height:18px;background:var(--primary);color:#fff;border-radius:50%;font-size:.65rem;font-weight:700;display:flex;align-items:center;justify-content:center}
.item-info{flex:1;min-width:0}
.item-brand{font-size:.7rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted)}
.item-name{font-size:.85rem;font-weight:600;color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.item-price{font-size:.9rem;font-weight:700;color:var(--primary);flex-shrink:0}
.summary-body{padding:1rem 1.5rem}
.summary-line{display:flex;justify-content:space-between;font-size:.9rem;color:var(--text-secondary);margin-bottom:.85rem}
.shipping-tag{background:var(--primary-light);color:var(--primary-dark);font-size:.78rem;font-weight:600;padding:.15rem .6rem;border-radius:100px}
.summary-divider{height:1px;background:var(--border);margin:.75rem 0}
.summary-total{display:flex;justify-content:space-between;font-size:1.15rem;font-weight:700}
.summary-total span:last-child{color:var(--primary)}
.summary-actions{padding:0 1.5rem 1.5rem}
.btn-checkout{display:flex;align-items:center;justify-content:center;width:100%;padding:.9rem 1.5rem;background:var(--primary);color:#fff;border:none;border-radius:var(--radius-md);font-family:var(--font);font-size:1rem;font-weight:600;cursor:pointer;transition:all .25s;margin-bottom:.75rem;letter-spacing:-.01em;text-decoration:none}
.btn-checkout:hover{background:var(--primary-dark);transform:translateY(-1px);box-shadow:0 4px 16px rgba(22,196,127,.35);color:#fff}
.btn-checkout:disabled{opacity:.6;cursor:not-allowed;transform:none}
.btn-outline-round{display:flex;align-items:center;justify-content:center;width:100%;padding:.7rem 1.25rem;background:transparent;color:var(--text-secondary);border:1px solid var(--border);border-radius:var(--radius-md);font-family:var(--font);font-size:.9rem;font-weight:500;text-decoration:none;transition:all .2s;margin-bottom:.5rem}
.btn-outline-round:hover{background:var(--bg);color:var(--text-primary);border-color:var(--text-muted)}
.payment-methods{padding:1rem 1.5rem;border-top:1px solid var(--border);display:flex;align-items:center;gap:.75rem;background:var(--bg)}
.pm-label{font-size:.75rem;color:var(--text-muted);flex-shrink:0}
.pm-icons{display:flex;gap:.5rem;align-items:center}
.pm-icons i{font-size:1.5rem;color:var(--text-muted)}
.pm-icons .fa-cc-visa{color:#1a1f71}
.pm-icons .fa-cc-mastercard{color:#eb001b}
.pm-icons .fa-mobile-alt,.pm-icons .fa-university{color:var(--primary)}

/* PayHere confirm card */
.pay-confirm-card{background:var(--surface);border-radius:var(--radius-xl);box-shadow:var(--shadow-md);border:1px solid var(--border);padding:3rem 2.5rem;text-align:center}
.pay-confirm-icon{margin-bottom:1.5rem}
.pay-confirm-card h3{font-size:1.75rem;font-weight:800;margin-bottom:.5rem;letter-spacing:-.02em}
.pay-confirm-card>p{color:var(--text-secondary);margin-bottom:2rem}
.order-summary-mini{background:var(--bg);border-radius:var(--radius-md);padding:1.25rem;margin-bottom:2rem;text-align:left;border:1px solid var(--border)}
.mini-line{display:flex;justify-content:space-between;align-items:center;padding:.45rem 0;border-bottom:1px solid var(--border);font-size:.875rem}
.mini-line:last-child{border-bottom:none}
.mini-line span{color:var(--text-muted)}
.mini-line strong{color:var(--text-primary)}
.btn-payhere{display:flex;align-items:center;justify-content:center;width:100%;padding:1rem 1.5rem;background:var(--primary);color:#fff;border:none;border-radius:var(--radius-md);font-family:var(--font);font-size:1.05rem;font-weight:700;cursor:pointer;transition:all .25s;margin-bottom:1rem;letter-spacing:-.01em}
.btn-payhere:hover{background:var(--primary-dark);transform:translateY(-1px);box-shadow:0 6px 20px rgba(12,177,0,.4)}
.payhere-badges{display:flex;align-items:center;justify-content:center;gap:.75rem;margin-top:1.5rem;flex-wrap:wrap}
.payhere-badges i{font-size:1.75rem;color:var(--text-muted)}
.payhere-badges .fa-cc-visa{color:#1a1f71}
.payhere-badges .fa-cc-mastercard{color:#eb001b}
.payhere-badges .fa-mobile-alt,.payhere-badges .fa-university{color:var(--primary)}
.ssl-badge{background:var(--bg);border:1px solid var(--border);border-radius:100px;padding:.3rem .75rem;font-size:.75rem;color:var(--text-muted);font-weight:500;display:flex;align-items:center;gap:.3rem}

@keyframes fadeUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
.fade-in{animation:fadeUp .45s ease-out both}

@media(max-width:768px){
    .page-header{padding:5.5rem 0 2.5rem}
    .page-header h1{font-size:1.9rem}
    .summary-card{position:relative;top:auto}
    .header-icon-wrap{display:none}
    .pay-confirm-card{padding:2rem 1.25rem}
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Stagger form cards
    document.querySelectorAll('.form-card').forEach((c, i) => {
        c.style.animationDelay = `${i * 80}ms`;
    });

    // Submit button loading state
    const form = document.getElementById('checkout-form');
    if (form) {
        form.addEventListener('submit', function () {
            const btn = document.getElementById('submit-btn');
            if (btn) {
                btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Processing…';
                btn.disabled = true;
            }
        });
    }
});
</script>

<?php include 'footer.php'; ?>