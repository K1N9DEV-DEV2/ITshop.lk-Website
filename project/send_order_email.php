<?php
/**
 * send_order_email.php
 * Handles order placement + sends HTML confirmation emails.
 * Called via AJAX POST from cart.php
 */

session_start();
include 'db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Please log in to place an order.']);
    exit();
}

// Ensure PDO throws exceptions
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Parse JSON body
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON data received.']);
    exit();
}

$full_name    = trim($data['full_name']    ?? '');
$phone        = trim($data['phone']        ?? '');
$email        = trim($data['email']        ?? '');
$order_number = trim($data['order_number'] ?? '');
$shipping     = (float)($data['shipping']  ?? 0);
$currency     = $data['currency']          ?? 'LKR';
$user_id      = (int)$_SESSION['user_id'];

if (!$full_name || !$phone || !$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Please fill in all required fields correctly.']);
    exit();
}

// Fetch cart fresh from DB (reliable, includes product_id)
try {
    $cartStmt = $pdo->prepare("
        SELECT c.product_id, c.quantity,
               p.name, p.brand, p.price AS current_price,
               (p.price * c.quantity) AS item_total
        FROM cart c
        JOIN products p ON c.product_id = p.id
        WHERE c.user_id = ?
        ORDER BY c.created_at DESC
    ");
    $cartStmt->execute([$user_id]);
    $cart_items = $cartStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Cart fetch error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not read cart: ' . $e->getMessage()]);
    exit();
}

if (empty($cart_items)) {
    echo json_encode(['success' => false, 'message' => 'Your cart is empty.']);
    exit();
}

// Recalculate totals server-side
$db_subtotal = array_sum(array_column($cart_items, 'item_total'));
$db_total    = $db_subtotal + $shipping;

// Auto-create tables if they don't exist
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `orders` (
        `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
        `user_id`       INT UNSIGNED  NOT NULL,
        `order_number`  VARCHAR(64)   NOT NULL,
        `full_name`     VARCHAR(255)  NOT NULL,
        `phone`         VARCHAR(50)   NOT NULL,
        `email`         VARCHAR(255)  NOT NULL,
        `subtotal`      DECIMAL(12,2) NOT NULL DEFAULT 0,
        `shipping_cost` DECIMAL(12,2) NOT NULL DEFAULT 0,
        `total`         DECIMAL(12,2) NOT NULL DEFAULT 0,
        `currency`      VARCHAR(10)   NOT NULL DEFAULT 'LKR',
        `status`        VARCHAR(50)   NOT NULL DEFAULT 'pending',
        `notes`         TEXT NULL,
        `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `order_items` (
        `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
        `order_id`     INT UNSIGNED  NOT NULL,
        `product_id`   INT UNSIGNED  NOT NULL DEFAULT 0,
        `product_name` VARCHAR(500)  NOT NULL,
        `quantity`     INT UNSIGNED  NOT NULL DEFAULT 1,
        `unit_price`   DECIMAL(12,2) NOT NULL DEFAULT 0,
        `total_price`  DECIMAL(12,2) NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        KEY `idx_order_id` (`order_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (PDOException $e) {
    error_log('Table creation error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'DB table error: ' . $e->getMessage()]);
    exit();
}

// Save order to DB
try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        INSERT INTO `orders`
            (user_id, order_number, full_name, phone, email, subtotal, shipping_cost, total, currency, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
    ");
    $stmt->execute([$user_id, $order_number, $full_name, $phone, $email,
                    $db_subtotal, $shipping, $db_total, $currency]);
    $order_id = $pdo->lastInsertId();

    $itemStmt = $pdo->prepare("
        INSERT INTO `order_items` (order_id, product_id, product_name, quantity, unit_price, total_price)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    foreach ($cart_items as $item) {
        $itemStmt->execute([
            $order_id,
            (int)$item['product_id'],
            $item['name'],
            (int)$item['quantity'],
            (float)$item['current_price'],
            (float)$item['item_total'],
        ]);
    }

    $pdo->prepare("DELETE FROM cart WHERE user_id = ?")->execute([$user_id]);
    $pdo->commit();

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Order save error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
    exit();
}

// ── Config ───────────────────────────────────────────────────────
define('ADMIN_EMAIL', 'noreply@itshop.lk'); // ← CHANGE THIS
define('STORE_NAME',  'IT Shop.LK');
define('STORE_URL',   'https://itshop.lk');

// ── Helpers ──────────────────────────────────────────────────────
$order_date = date('F j, Y \a\t g:i A');
$safe_name  = htmlspecialchars($full_name);
$safe_phone = htmlspecialchars($phone);
$safe_email = htmlspecialchars($email);
$sub_fmt    = number_format($db_subtotal);
$ship_fmt   = number_format($shipping);
$total_fmt  = number_format($db_total);

function buildRows(array $items, string $cur): string {
    $out = '';
    foreach ($items as $i) {
        $n  = htmlspecialchars($i['name']  ?? '');
        $b  = htmlspecialchars($i['brand'] ?? '');
        $q  = (int)$i['quantity'];
        $u  = number_format((float)$i['current_price']);
        $t  = number_format((float)$i['item_total']);
        $out .= "<tr>
          <td style='padding:12px 16px;border-bottom:1px solid #f0f4f8;'>
            <div style='font-weight:600;color:#0f172a;font-size:14px;'>{$n}</div>
            " . ($b ? "<div style='font-size:11px;color:#94a3b8;margin-top:2px;text-transform:uppercase;letter-spacing:.05em;'>{$b}</div>" : '') . "
          </td>
          <td style='padding:12px 16px;border-bottom:1px solid #f0f4f8;text-align:center;color:#64748b;'>×{$q}</td>
          <td style='padding:12px 16px;border-bottom:1px solid #f0f4f8;text-align:center;color:#64748b;font-size:13px;'>{$cur} {$u}</td>
          <td style='padding:12px 16px;border-bottom:1px solid #f0f4f8;text-align:right;font-weight:700;color:#0cb100;'>{$cur} {$t}</td>
        </tr>";
    }
    return $out;
}

$rows = buildRows($cart_items, $currency);

ob_start(); ?>
<!DOCTYPE html><html lang="en">
<head><meta charset="UTF-8"><title>Order Confirmed #<?=$order_number?></title></head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:'Segoe UI',Helvetica,Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:40px 16px;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;">

  <tr><td style="background:#000;border-radius:20px 20px 0 0;padding:44px 40px 36px;text-align:center;">
    <div style="display:inline-block;background:rgba(12,177,0,.15);border:1px solid rgba(12,177,0,.35);border-radius:12px;padding:8px 20px;margin-bottom:24px;">
      <span style="color:#0cb100;font-size:17px;font-weight:800;">IT Shop.LK</span>
    </div>
    <div style="width:72px;height:72px;background:linear-gradient(135deg,#0cb100,#087600);border-radius:50%;margin:0 auto 20px;line-height:72px;text-align:center;font-size:36px;">✓</div>
    <h1 style="color:#fff;font-size:26px;font-weight:700;margin:0 0 10px;">Order Confirmed! 🎉</h1>
    <p style="color:rgba(255,255,255,.65);font-size:15px;margin:0;line-height:1.6;">
      Thank you, <strong style="color:#fff;"><?=$safe_name?></strong>!<br>
      We've received your order and will contact you shortly.
    </p>
  </td></tr>

  <tr><td style="background:#0cb100;padding:16px 40px;text-align:center;">
    <div style="color:rgba(255,255,255,.75);font-size:11px;text-transform:uppercase;letter-spacing:.12em;margin-bottom:4px;">Your Order Number</div>
    <div style="color:#fff;font-size:24px;font-weight:800;letter-spacing:.06em;font-family:monospace;">#<?=$order_number?></div>
    <div style="color:rgba(255,255,255,.7);font-size:12px;margin-top:4px;"><?=$order_date?></div>
  </td></tr>

  <tr><td style="background:#fff;padding:36px 40px;">

    <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#94a3b8;margin-bottom:12px;">Order Items</div>
    <table width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border-radius:12px;overflow:hidden;margin-bottom:28px;border:1px solid #e2e8f0;">
      <tr style="background:#f1f5f9;">
        <td style="padding:10px 16px;font-size:11px;font-weight:700;text-transform:uppercase;color:#94a3b8;">Product</td>
        <td style="padding:10px 16px;font-size:11px;font-weight:700;text-transform:uppercase;color:#94a3b8;text-align:center;">Qty</td>
        <td style="padding:10px 16px;font-size:11px;font-weight:700;text-transform:uppercase;color:#94a3b8;text-align:center;">Unit</td>
        <td style="padding:10px 16px;font-size:11px;font-weight:700;text-transform:uppercase;color:#94a3b8;text-align:right;">Total</td>
      </tr>
      <?=$rows?>
    </table>

    <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:28px;">
      <tr>
        <td style="padding:5px 0;color:#64748b;font-size:14px;">Subtotal</td>
        <td style="padding:5px 0;text-align:right;font-size:14px;"><?=$currency?> <?=$sub_fmt?></td>
      </tr>
      <tr>
        <td style="padding:5px 0;color:#64748b;font-size:14px;">Shipping</td>
        <td style="padding:5px 0;text-align:right;font-size:14px;"><?=$currency?> <?=$ship_fmt?></td>
      </tr>
      <tr><td colspan="2" style="padding:6px 0;"><div style="height:2px;background:#e2e8f0;border-radius:2px;"></div></td></tr>
      <tr>
        <td style="padding:8px 0;font-size:18px;font-weight:700;">Total</td>
        <td style="padding:8px 0;text-align:right;color:#0cb100;font-size:22px;font-weight:800;font-family:monospace;"><?=$currency?> <?=$total_fmt?></td>
      </tr>
    </table>

    <table width="100%" cellpadding="0" cellspacing="0" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:14px;margin-bottom:28px;">
      <tr><td style="padding:20px 24px;">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#0cb100;margin-bottom:14px;">Your Contact Details</div>
        <div style="font-size:14px;color:#0f172a;line-height:2;">
          <strong>Name:</strong> <?=$safe_name?><br>
          <strong>Phone:</strong> <?=$safe_phone?><br>
          <strong>Email:</strong> <?=$safe_email?>
        </div>
      </td></tr>
    </table>

    <table width="100%" cellpadding="0" cellspacing="0" style="background:#fafafa;border:1px solid #e2e8f0;border-radius:14px;margin-bottom:28px;">
      <tr><td style="padding:20px 24px;">
        <div style="font-size:13px;font-weight:700;color:#0f172a;margin-bottom:10px;">📋 What happens next?</div>
        <div style="font-size:13px;color:#64748b;line-height:2;">
          <strong style="color:#0cb100;">1.</strong> Our team verifies availability of your items.<br>
          <strong style="color:#0cb100;">2.</strong> We'll call or message you on <strong style="color:#0f172a;"><?=$safe_phone?></strong> within 24 hours.<br>
          <strong style="color:#0cb100;">3.</strong> Delivery or in-store pickup arranged at your convenience.
        </div>
      </td></tr>
    </table>

    <div style="text-align:center;">
      <a href="<?=STORE_URL?>" style="display:inline-block;background:#0cb100;color:#fff;text-decoration:none;font-weight:700;font-size:15px;padding:14px 40px;border-radius:100px;">Continue Shopping →</a>
    </div>

  </td></tr>

  <tr><td style="background:#0f172a;border-radius:0 0 20px 20px;padding:28px 40px;text-align:center;">
    <p style="color:rgba(255,255,255,.5);font-size:12px;margin:0 0 6px;">Questions? <a href="mailto:info@itshop.lk" style="color:#0cb100;text-decoration:none;">info@itshop.lk</a></p>
    <p style="color:rgba(255,255,255,.25);font-size:11px;margin:0;">© 2025 IT Shop.LK · All rights reserved</p>
  </td></tr>

</table>
</td></tr>
</table>
</body></html>
<?php
$customer_html = ob_get_clean();

// Admin email
ob_start(); ?>
<!DOCTYPE html><html lang="en">
<head><meta charset="UTF-8"><title>New Order #<?=$order_number?></title></head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:'Segoe UI',Helvetica,Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:32px 16px;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#fff;border-radius:16px;overflow:hidden;">
  <tr><td style="background:#0cb100;padding:24px 32px;">
    <div style="color:#fff;font-size:20px;font-weight:800;">🛒 New Order Received</div>
    <div style="color:rgba(255,255,255,.8);font-size:13px;margin-top:4px;">#<?=$order_number?> · <?=$order_date?></div>
  </td></tr>
  <tr><td style="padding:28px 32px;">

    <table width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border-radius:10px;overflow:hidden;margin-bottom:20px;border:1px solid #e2e8f0;">
      <tr style="background:#f1f5f9;">
        <td style="padding:10px 14px;font-size:11px;font-weight:700;text-transform:uppercase;color:#94a3b8;">Product</td>
        <td style="padding:10px 14px;font-size:11px;font-weight:700;text-transform:uppercase;color:#94a3b8;text-align:center;">Qty</td>
        <td style="padding:10px 14px;font-size:11px;font-weight:700;text-transform:uppercase;color:#94a3b8;text-align:center;">Unit</td>
        <td style="padding:10px 14px;font-size:11px;font-weight:700;text-transform:uppercase;color:#94a3b8;text-align:right;">Total</td>
      </tr>
      <?=$rows?>
    </table>

    <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:20px;">
      <tr>
        <td style="color:#64748b;font-size:14px;padding:4px 0;">Subtotal</td>
        <td style="text-align:right;font-size:14px;padding:4px 0;"><?=$currency?> <?=$sub_fmt?></td>
      </tr>
      <tr>
        <td style="color:#64748b;font-size:14px;padding:4px 0;">Shipping</td>
        <td style="text-align:right;font-size:14px;padding:4px 0;"><?=$currency?> <?=$ship_fmt?></td>
      </tr>
      <tr><td colspan="2" style="padding:6px 0;"><div style="height:2px;background:#e2e8f0;"></div></td></tr>
      <tr>
        <td style="font-size:17px;font-weight:700;padding:6px 0;">TOTAL</td>
        <td style="text-align:right;font-size:20px;font-weight:800;color:#0cb100;padding:6px 0;"><?=$currency?> <?=$total_fmt?></td>
      </tr>
    </table>

    <table width="100%" cellpadding="0" cellspacing="0" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;margin-bottom:16px;">
      <tr><td style="padding:16px 20px;">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:#0cb100;margin-bottom:10px;">Customer Details</div>
        <div style="font-size:14px;color:#0f172a;line-height:1.9;">
          <strong>Name:</strong> <?=$safe_name?><br>
          <strong>Phone:</strong> <a href="tel:<?=$safe_phone?>" style="color:#0cb100;"><?=$safe_phone?></a><br>
          <strong>Email:</strong> <a href="mailto:<?=$safe_email?>" style="color:#0cb100;"><?=$safe_email?></a>
        </div>
      </td></tr>
    </table>

    <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:14px 18px;font-size:13px;color:#92400e;text-align:center;">
      ⚡ Follow up with the customer within <strong>24 hours</strong>.
    </div>

  </td></tr>
</table>
</td></tr>
</table>
</body></html>
<?php
$admin_html = ob_get_clean();

// ── Send emails ──────────────────────────────────────────────────
$subject_customer = "Order Confirmed #{$order_number} - " . STORE_NAME;
$subject_admin    = "New Order #{$order_number} from {$safe_name}";
$h_base           = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";

$h_cust  = $h_base . "From: " . STORE_NAME . " <noreply@itshop.lk>\r\nReply-To: " . ADMIN_EMAIL . "\r\n";
$h_admin = $h_base . "From: " . STORE_NAME . " <noreply@itshop.lk>\r\nReply-To: {$safe_email}\r\n";

/*
 * OPTION A: PHP mail() — active by default
 * OPTION B: PHPMailer Gmail SMTP — comment out Option A and uncomment Option B below
 */

// Option A
$sent = mail($email, $subject_customer, $customer_html, $h_cust);
mail(ADMIN_EMAIL, $subject_admin, $admin_html, $h_admin);

/*
// Option B — PHPMailer Gmail SMTP
// composer require phpmailer/phpmailer
// Then create App Password at: myaccount.google.com/apppasswords
require __DIR__ . '/vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;

define('GMAIL_USER',     'your-gmail@gmail.com');
define('GMAIL_APP_PASS', 'xxxx xxxx xxxx xxxx');

function sendGmail(string $to, string $name, string $subj, string $html): bool {
    $m = new PHPMailer(true);
    try {
        $m->isSMTP(); $m->Host = 'smtp.gmail.com'; $m->SMTPAuth = true;
        $m->Username = GMAIL_USER; $m->Password = GMAIL_APP_PASS;
        $m->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; $m->Port = 587;
        $m->CharSet = 'UTF-8';
        $m->setFrom(GMAIL_USER, STORE_NAME);
        $m->addAddress($to, $name);
        $m->isHTML(true); $m->Subject = $subj; $m->Body = $html;
        $m->send(); return true;
    } catch (Exception $e) { error_log('Mail: ' . $m->ErrorInfo); return false; }
}
$sent = sendGmail($email, $full_name, $subject_customer, $customer_html);
sendGmail(ADMIN_EMAIL, 'Admin', $subject_admin, $admin_html);
*/

echo json_encode([
    'success'      => true,
    'order_number' => $order_number,
    'message'      => 'Order placed! ' . ($sent ? 'Confirmation email sent.' : 'Email pending.')
]);