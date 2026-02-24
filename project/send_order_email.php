<?php
/**
 * send_order_email.php
 * Handles order placement and sends confirmation emails to both customer and store admin.
 * Called via AJAX POST from cart.php
 */

session_start();
include 'db.php'; // your existing DB connection

header('Content-Type: application/json');

// ── Only allow POST ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

// ── Must be logged in ────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Please log in to place an order.']);
    exit();
}

// ── Parse JSON body ──────────────────────────────────────────────
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) {
    echo json_encode(['success' => false, 'message' => 'Invalid data received.']);
    exit();
}

// ── Extract & sanitize fields ────────────────────────────────────
$full_name    = trim($data['full_name']    ?? '');
$phone        = trim($data['phone']        ?? '');
$email        = trim($data['email']        ?? '');
$order_number = trim($data['order_number'] ?? '');
$cart_items   = $data['cart_items']        ?? [];
$subtotal     = (float)($data['subtotal']  ?? 0);
$shipping     = (float)($data['shipping']  ?? 0);
$total        = (float)($data['total']     ?? 0);
$currency     = $data['currency']          ?? 'LKR';

// ── Basic server-side validation ─────────────────────────────────
if (!$full_name || !$phone || !$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Please fill in all required fields correctly.']);
    exit();
}

if (empty($cart_items)) {
    echo json_encode(['success' => false, 'message' => 'Your cart is empty.']);
    exit();
}

$user_id = $_SESSION['user_id'];

// ── Store order in the database ──────────────────────────────────
try {
    $pdo->beginTransaction();

    // Insert into orders table
    $stmt = $pdo->prepare("
        INSERT INTO orders (user_id, order_number, full_name, phone, email, subtotal, shipping_cost, total, currency, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
    ");
    $stmt->execute([$user_id, $order_number, $full_name, $phone, $email, $subtotal, $shipping, $total, $currency]);
    $order_id = $pdo->lastInsertId();

    // Insert order items
    $itemStmt = $pdo->prepare("
        INSERT INTO order_items (order_id, product_id, product_name, quantity, unit_price, total_price)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    foreach ($cart_items as $item) {
        // Try to get product_id from cart if available, else 0
        $product_id  = (int)($item['product_id'] ?? 0);
        $name        = $item['name']        ?? 'Unknown Product';
        $qty         = (int)($item['quantity'] ?? 1);
        $unit_price  = (float)($item['price']  ?? 0);
        $total_price = (float)($item['item_total'] ?? ($unit_price * $qty));
        $itemStmt->execute([$order_id, $product_id, $name, $qty, $unit_price, $total_price]);
    }

    // Clear the user's cart
    $pdo->prepare("DELETE FROM cart WHERE user_id = ?")->execute([$user_id]);

    $pdo->commit();

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    // Log error internally but don't expose details
    error_log('Order DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to save your order. Please try again.']);
    exit();
}

// ── Build the beautiful HTML email ──────────────────────────────
function buildItemRows(array $items, string $currency): string {
    $rows = '';
    foreach ($items as $item) {
        $name  = htmlspecialchars($item['name']       ?? '');
        $brand = htmlspecialchars($item['brand']      ?? '');
        $qty   = (int)($item['quantity']              ?? 1);
        $price = number_format((float)($item['item_total'] ?? 0));
        $unit  = number_format((float)($item['price'] ?? 0));

        $rows .= "
        <tr>
            <td style='padding:12px 16px; border-bottom:1px solid #f0f0f0;'>
                <div style='font-weight:600; color:#0f172a; font-size:14px;'>{$name}</div>
                " . ($brand ? "<div style='font-size:12px; color:#94a3b8; margin-top:2px;'>{$brand}</div>" : '') . "
            </td>
            <td style='padding:12px 16px; border-bottom:1px solid #f0f0f0; text-align:center; color:#64748b; font-size:14px;'>×{$qty}</td>
            <td style='padding:12px 16px; border-bottom:1px solid #f0f0f0; text-align:center; color:#64748b; font-size:13px;'>{$currency} {$unit}</td>
            <td style='padding:12px 16px; border-bottom:1px solid #f0f0f0; text-align:right; font-weight:700; color:#0cb100; font-size:14px;'>{$currency} {$price}</td>
        </tr>";
    }
    return $rows;
}

$item_rows       = buildItemRows($cart_items, $currency);
$subtotal_fmt    = number_format($subtotal);
$shipping_fmt    = number_format($shipping);
$total_fmt       = number_format($total);
$order_date      = date('F j, Y \a\t g:i A');
$safe_name       = htmlspecialchars($full_name);
$safe_phone      = htmlspecialchars($phone);
$safe_email      = htmlspecialchars($email);

$customer_html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Order Confirmation - {$order_number}</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:'Segoe UI',Arial,sans-serif;">

<table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:40px 16px;">
  <tr><td align="center">
  <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;">

    <!-- Header -->
    <tr>
      <td style="background:#000;border-radius:20px 20px 0 0;padding:40px 40px 30px;text-align:center;">
        <!-- Logo/Brand -->
        <div style="display:inline-block;background:rgba(12,177,0,0.15);border:1px solid rgba(12,177,0,0.3);border-radius:14px;padding:10px 20px;margin-bottom:20px;">
          <span style="color:#0cb100;font-size:18px;font-weight:800;letter-spacing:-0.03em;">STC Electronics</span>
        </div>

        <!-- Success icon -->
        <div style="width:72px;height:72px;background:linear-gradient(135deg,#0cb100,#087600);border-radius:50%;margin:0 auto 20px;display:flex;align-items:center;justify-content:center;">
          <span style="font-size:32px;">✓</span>
        </div>

        <h1 style="color:#ffffff;font-size:26px;font-weight:700;margin:0 0 8px;letter-spacing:-0.03em;">Order Confirmed!</h1>
        <p style="color:rgba(255,255,255,0.65);font-size:15px;margin:0;">
          Thank you for your purchase, {$safe_name}. We've received your order and will be in touch shortly.
        </p>
      </td>
    </tr>

    <!-- Order number banner -->
    <tr>
      <td style="background:#0cb100;padding:16px 40px;text-align:center;">
        <span style="color:rgba(255,255,255,0.7);font-size:12px;text-transform:uppercase;letter-spacing:0.1em;">Order Number</span>
        <div style="color:#ffffff;font-size:22px;font-weight:800;letter-spacing:0.05em;font-family:monospace;">#{$order_number}</div>
      </td>
    </tr>

    <!-- Body -->
    <tr>
      <td style="background:#ffffff;padding:36px 40px;">

        <!-- Order details -->
        <table width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border-radius:12px;overflow:hidden;margin-bottom:28px;">
          <tr style="background:#f1f5f9;">
            <td style="padding:12px 16px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#94a3b8;">Product</td>
            <td style="padding:12px 16px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#94a3b8;text-align:center;">Qty</td>
            <td style="padding:12px 16px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#94a3b8;text-align:center;">Unit</td>
            <td style="padding:12px 16px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#94a3b8;text-align:right;">Total</td>
          </tr>
          {$item_rows}
        </table>

        <!-- Price summary -->
        <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:28px;">
          <tr>
            <td style="padding:6px 0;color:#64748b;font-size:14px;">Subtotal</td>
            <td style="padding:6px 0;text-align:right;color:#0f172a;font-size:14px;font-weight:500;">{$currency} {$subtotal_fmt}</td>
          </tr>
          <tr>
            <td style="padding:6px 0;color:#64748b;font-size:14px;">Shipping</td>
            <td style="padding:6px 0;text-align:right;color:#0f172a;font-size:14px;font-weight:500;">{$currency} {$shipping_fmt}</td>
          </tr>
          <tr>
            <td colspan="2" style="padding:8px 0;">
              <div style="height:1px;background:#e2e8f0;"></div>
            </td>
          </tr>
          <tr>
            <td style="padding:8px 0;color:#0f172a;font-size:18px;font-weight:700;">Total</td>
            <td style="padding:8px 0;text-align:right;color:#0cb100;font-size:20px;font-weight:800;font-family:monospace;">{$currency} {$total_fmt}</td>
          </tr>
        </table>

        <!-- Contact info -->
        <table width="100%" cellpadding="0" cellspacing="0" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;margin-bottom:28px;">
          <tr>
            <td style="padding:20px 24px;">
              <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#0cb100;margin-bottom:12px;">Your Details</div>
              <table width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td style="padding:4px 0;color:#64748b;font-size:13px;width:100px;">Name</td>
                  <td style="padding:4px 0;color:#0f172a;font-size:13px;font-weight:600;">{$safe_name}</td>
                </tr>
                <tr>
                  <td style="padding:4px 0;color:#64748b;font-size:13px;">Phone</td>
                  <td style="padding:4px 0;color:#0f172a;font-size:13px;font-weight:600;">{$safe_phone}</td>
                </tr>
                <tr>
                  <td style="padding:4px 0;color:#64748b;font-size:13px;">Email</td>
                  <td style="padding:4px 0;color:#0f172a;font-size:13px;font-weight:600;">{$safe_email}</td>
                </tr>
                <tr>
                  <td style="padding:4px 0;color:#64748b;font-size:13px;">Date</td>
                  <td style="padding:4px 0;color:#0f172a;font-size:13px;font-weight:600;">{$order_date}</td>
                </tr>
              </table>
            </td>
          </tr>
        </table>

        <!-- What's next -->
        <div style="background:#f8fafc;border-radius:12px;padding:20px 24px;margin-bottom:28px;">
          <div style="font-size:13px;font-weight:700;color:#0f172a;margin-bottom:12px;">📋 What happens next?</div>
          <ol style="margin:0;padding-left:18px;color:#64748b;font-size:13px;line-height:1.8;">
            <li>Our team will review your order and contact you to confirm availability.</li>
            <li>You'll receive a call or message on <strong style="color:#0f172a;">{$safe_phone}</strong> within 24 hours.</li>
            <li>Once confirmed, we'll arrange delivery or pickup as convenient for you.</li>
          </ol>
        </div>

        <!-- CTA -->
        <div style="text-align:center;margin-bottom:10px;">
          <a href="https://itshop.lk" style="display:inline-block;background:#0cb100;color:#ffffff;text-decoration:none;font-weight:700;font-size:15px;padding:14px 36px;border-radius:100px;">
            Continue Shopping →
          </a>
        </div>

      </td>
    </tr>

    <!-- Footer -->
    <tr>
      <td style="background:#0f172a;border-radius:0 0 20px 20px;padding:28px 40px;text-align:center;">
        <p style="color:rgba(255,255,255,0.5);font-size:12px;margin:0 0 6px;">Questions? Contact us at <a href="mailto:info@itshop.lk" style="color:#0cb100;text-decoration:none;">info@itshop.lk</a></p>
        <p style="color:rgba(255,255,255,0.3);font-size:11px;margin:0;">© 2025 STC Electronics Store · All rights reserved</p>
      </td>
    </tr>

  </table>
  </td></tr>
</table>

</body>
</html>
HTML;

// ── Admin notification email ─────────────────────────────────────
$admin_html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>New Order - {$order_number}</title></head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:'Segoe UI',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:32px 16px;">
  <tr><td align="center">
  <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.08);">
    <tr>
      <td style="background:#0cb100;padding:24px 32px;">
        <div style="color:#fff;font-size:20px;font-weight:800;">🛒 New Order Received</div>
        <div style="color:rgba(255,255,255,0.8);font-size:13px;margin-top:4px;">Order #{$order_number} · {$order_date}</div>
      </td>
    </tr>
    <tr>
      <td style="padding:28px 32px;">
        <table width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border-radius:10px;overflow:hidden;margin-bottom:20px;">
          <tr style="background:#f1f5f9;">
            <td style="padding:10px 14px;font-size:11px;font-weight:700;text-transform:uppercase;color:#94a3b8;">Product</td>
            <td style="padding:10px 14px;font-size:11px;font-weight:700;text-transform:uppercase;color:#94a3b8;text-align:center;">Qty</td>
            <td style="padding:10px 14px;font-size:11px;font-weight:700;text-transform:uppercase;color:#94a3b8;text-align:right;">Total</td>
          </tr>
          {$item_rows}
        </table>

        <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:20px;">
          <tr>
            <td style="color:#64748b;font-size:14px;padding:4px 0;">Subtotal</td>
            <td style="text-align:right;font-size:14px;font-weight:500;padding:4px 0;">{$currency} {$subtotal_fmt}</td>
          </tr>
          <tr>
            <td style="color:#64748b;font-size:14px;padding:4px 0;">Shipping</td>
            <td style="text-align:right;font-size:14px;font-weight:500;padding:4px 0;">{$currency} {$shipping_fmt}</td>
          </tr>
          <tr>
            <td style="font-size:16px;font-weight:700;padding:8px 0;">TOTAL</td>
            <td style="text-align:right;font-size:18px;font-weight:800;color:#0cb100;padding:8px 0;">{$currency} {$total_fmt}</td>
          </tr>
        </table>

        <table width="100%" cellpadding="0" cellspacing="0" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:0;margin-bottom:16px;">
          <tr><td style="padding:16px 20px;">
            <div style="font-size:12px;font-weight:700;text-transform:uppercase;color:#0cb100;margin-bottom:10px;">Customer Details</div>
            <div style="font-size:14px;color:#0f172a;line-height:1.8;">
              <strong>Name:</strong> {$safe_name}<br>
              <strong>Phone:</strong> {$safe_phone}<br>
              <strong>Email:</strong> {$safe_email}
            </div>
          </td></tr>
        </table>

        <div style="font-size:12px;color:#94a3b8;text-align:center;">Please follow up with the customer within 24 hours.</div>
      </td>
    </tr>
  </table>
  </td></tr>
</table>
</body>
</html>
HTML;

// ── Send emails ──────────────────────────────────────────────────

/**
 * CONFIGURATION — edit these values
 * ─────────────────────────────────
 * OPTION A: PHP mail() — works if your server has sendmail configured.
 * OPTION B: PHPMailer with Gmail SMTP — recommended for Gmail delivery.
 *
 * Uncomment the method you want to use below.
 */

// ── Store admin email (receives new order notifications) ─────────
define('ADMIN_EMAIL', 'your-store-email@gmail.com');  // ← change this
define('STORE_NAME',  'STC Electronics Store');

$email_sent = false;

/* ============================================================
   OPTION A: PHP mail() — simple, works on most shared hosts
   ============================================================ */

$headers_customer  = "MIME-Version: 1.0\r\n";
$headers_customer .= "Content-Type: text/html; charset=UTF-8\r\n";
$headers_customer .= "From: " . STORE_NAME . " <noreply@itshop.lk>\r\n";
$headers_customer .= "Reply-To: " . ADMIN_EMAIL . "\r\n";
$headers_customer .= "X-Mailer: PHP/" . phpversion();

$headers_admin  = "MIME-Version: 1.0\r\n";
$headers_admin .= "Content-Type: text/html; charset=UTF-8\r\n";
$headers_admin .= "From: " . STORE_NAME . " <noreply@itshop.lk>\r\n";
$headers_admin .= "Reply-To: {$safe_email}\r\n";

$subject_customer = "✅ Order Confirmed - #{$order_number} | " . STORE_NAME;
$subject_admin    = "🛒 New Order #{$order_number} from {$safe_name}";

$sent_to_customer = mail($email, $subject_customer, $customer_html, $headers_customer);
$sent_to_admin    = mail(ADMIN_EMAIL, $subject_admin, $admin_html, $headers_admin);

$email_sent = $sent_to_customer; // Primary check: customer received confirmation


/* ============================================================
   OPTION B: PHPMailer + Gmail SMTP (recommended)
   Uncomment below and comment out OPTION A above.
   Install via: composer require phpmailer/phpmailer
   ============================================================

require 'vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

define('GMAIL_USER',     'your-gmail@gmail.com');      // ← your Gmail
define('GMAIL_APP_PASS', 'xxxx xxxx xxxx xxxx');       // ← Gmail App Password (not your login password)

function sendMail(string $to, string $toName, string $subject, string $htmlBody): bool {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = GMAIL_USER;
        $mail->Password   = GMAIL_APP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom(GMAIL_USER, STORE_NAME);
        $mail->addAddress($to, $toName);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('Mailer error: ' . $mail->ErrorInfo);
        return false;
    }
}

$sent_to_customer = sendMail($email, $full_name, "✅ Order Confirmed - #{$order_number}", $customer_html);
$sent_to_admin    = sendMail(ADMIN_EMAIL, 'Store Admin', "🛒 New Order #{$order_number} from {$full_name}", $admin_html);
$email_sent = $sent_to_customer;

*/

// ── Respond to AJAX ──────────────────────────────────────────────
if ($email_sent) {
    echo json_encode([
        'success'      => true,
        'order_number' => $order_number,
        'message'      => 'Order placed successfully! Confirmation email sent.'
    ]);
} else {
    // Order was saved to DB even if email failed — still a success for the customer
    // but log the failure
    error_log("Order {$order_number} saved but email to {$email} failed.");
    echo json_encode([
        'success'      => true,   // order IS saved, just email delivery uncertain
        'order_number' => $order_number,
        'message'      => 'Order placed successfully! (Email delivery pending)'
    ]);
}