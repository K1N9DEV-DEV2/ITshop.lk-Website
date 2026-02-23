<?php
/**
 * send_order_email.php
 * 1. Saves order + order_items to DB (creates columns/table if missing)
 * 2. Sends confirmation email to admin@itshop.lk
 * 3. Clears the user's cart
 */

session_start();
header('Content-Type: application/json');

// ── Auth check ────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// ── Read JSON body ────────────────────────────────────────────────
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) {
    echo json_encode(['success' => false, 'message' => 'Invalid request data']);
    exit();
}

// ── Sanitise & validate ───────────────────────────────────────────
$full_name    = htmlspecialchars(trim($data['full_name']    ?? ''));
$phone        = htmlspecialchars(trim($data['phone']        ?? ''));
$email        = filter_var(trim($data['email'] ?? ''), FILTER_SANITIZE_EMAIL);
$order_number = htmlspecialchars(trim($data['order_number'] ?? ''));
$cart_items   = $data['cart_items']  ?? [];
$subtotal     = (float)($data['subtotal']  ?? 0);
$shipping     = (float)($data['shipping']  ?? 0);
$total        = (float)($data['total']     ?? 0);
$currency     = htmlspecialchars($data['currency'] ?? 'LKR');

if (!$full_name || !$phone || !filter_var($email, FILTER_VALIDATE_EMAIL) || empty($cart_items)) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit();
}

// ── DB: add columns if missing, save order ────────────────────────
$db_order_id = null;
try {
    include_once 'db.php'; // provides $pdo

    // Add extra columns to orders table if they don't exist yet
    $alter_sqls = [
        "ALTER TABLE orders ADD COLUMN IF NOT EXISTS order_number   VARCHAR(60)   NOT NULL DEFAULT ''",
        "ALTER TABLE orders ADD COLUMN IF NOT EXISTS customer_name  VARCHAR(150)  NOT NULL DEFAULT ''",
        "ALTER TABLE orders ADD COLUMN IF NOT EXISTS customer_phone VARCHAR(30)   NOT NULL DEFAULT ''",
        "ALTER TABLE orders ADD COLUMN IF NOT EXISTS customer_email VARCHAR(150)  NOT NULL DEFAULT ''",
        "ALTER TABLE orders ADD COLUMN IF NOT EXISTS shipping_cost  DECIMAL(12,2) NOT NULL DEFAULT 0",
    ];
    foreach ($alter_sqls as $sql) {
        try { $pdo->exec($sql); } catch (PDOException $e) { /* column may already exist */ }
    }

    // Create order_items table if it doesn't exist
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS order_items (
            id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id     INT UNSIGNED NOT NULL,
            product_name VARCHAR(255) NOT NULL DEFAULT '',
            brand        VARCHAR(100) NOT NULL DEFAULT '',
            quantity     INT UNSIGNED NOT NULL DEFAULT 1,
            unit_price   DECIMAL(12,2) NOT NULL DEFAULT 0,
            line_total   DECIMAL(12,2) NOT NULL DEFAULT 0,
            created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_order_id (order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->beginTransaction();

    // Insert order row
    $stmt = $pdo->prepare("
        INSERT INTO orders
            (user_id, order_number, customer_name, customer_phone, customer_email,
             total, shipping_cost, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
    ");
    $stmt->execute([
        (int)$_SESSION['user_id'],
        $order_number,
        $full_name,
        $phone,
        $email,
        $total,
        $shipping,
    ]);
    $db_order_id = (int)$pdo->lastInsertId();

    // Insert order items
    $item_stmt = $pdo->prepare("
        INSERT INTO order_items (order_id, product_name, brand, quantity, unit_price, line_total)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    foreach ($cart_items as $item) {
        $item_stmt->execute([
            $db_order_id,
            $item['name']         ?? '',
            $item['brand']        ?? '',
            (int)($item['quantity']    ?? 1),
            (float)($item['price']     ?? 0),
            (float)($item['item_total'] ?? 0),
        ]);
    }

    // Clear user's cart inside the same transaction
    $pdo->prepare("DELETE FROM cart WHERE user_id = ?")->execute([(int)$_SESSION['user_id']]);

    $pdo->commit();

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log("Order DB save failed for #{$order_number}: " . $e->getMessage());
    // Don't abort — still attempt to send email below
}

// ── Build HTML email ──────────────────────────────────────────────
$order_date   = date('F j, Y  H:i');
$subtotal_fmt = number_format($subtotal);
$shipping_fmt = number_format($shipping);
$total_fmt    = number_format($total);

$items_rows = '';
foreach ($cart_items as $item) {
    $name       = htmlspecialchars($item['name']       ?? '');
    $brand      = htmlspecialchars($item['brand']      ?? '');
    $qty        = (int)($item['quantity']   ?? 1);
    $price      = number_format((float)($item['price']      ?? 0));
    $item_total = number_format((float)($item['item_total'] ?? 0));

    $items_rows .= "
        <tr>
            <td style='padding:10px 14px;border-bottom:1px solid #f0f0f0;'>
                <div style='font-weight:600;color:#0f172a;'>{$name}</div>
                <div style='font-size:12px;color:#94a3b8;margin-top:2px;'>{$brand}</div>
            </td>
            <td style='padding:10px 14px;border-bottom:1px solid #f0f0f0;text-align:center;color:#64748b;'>{$qty}</td>
            <td style='padding:10px 14px;border-bottom:1px solid #f0f0f0;text-align:right;color:#64748b;font-family:monospace;'>{$currency} {$price}</td>
            <td style='padding:10px 14px;border-bottom:1px solid #f0f0f0;text-align:right;font-weight:700;color:#0cb100;font-family:monospace;'>{$currency} {$item_total}</td>
        </tr>";
}

$html_body = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>New Order #{$order_number}</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:'Segoe UI',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:32px 0;">
  <tr><td align="center">
    <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;">
      <tr>
        <td style="background:#000;border-radius:16px 16px 0 0;padding:28px 32px;text-align:center;">
          <div style="display:inline-block;background:rgba(255,255,255,0.12);border-radius:12px;padding:8px 20px;margin-bottom:12px;">
            <span style="color:#0cb100;font-size:13px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;">STC Electronics Store</span>
          </div>
          <h1 style="color:#ffffff;margin:0 0 6px;font-size:24px;font-weight:700;">New Order Received 🛍️</h1>
          <p style="color:rgba(255,255,255,0.65);margin:0;font-size:14px;">Order #{$order_number} &nbsp;·&nbsp; {$order_date}</p>
        </td>
      </tr>
      <tr>
        <td style="background:#ffffff;padding:28px 32px;">
          <h2 style="font-size:16px;font-weight:700;color:#0f172a;margin:0 0 16px;padding-bottom:10px;border-bottom:2px solid #f0f0f0;">Customer Details</h2>
          <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px;">
            <tr>
              <td style="padding:6px 0;width:140px;"><span style="font-size:13px;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">Full Name</span></td>
              <td style="padding:6px 0;"><span style="font-size:15px;font-weight:600;color:#0f172a;">{$full_name}</span></td>
            </tr>
            <tr>
              <td style="padding:6px 0;"><span style="font-size:13px;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">Phone</span></td>
              <td style="padding:6px 0;"><a href="tel:{$phone}" style="font-size:15px;font-weight:600;color:#0cb100;text-decoration:none;">{$phone}</a></td>
            </tr>
            <tr>
              <td style="padding:6px 0;"><span style="font-size:13px;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">Email</span></td>
              <td style="padding:6px 0;"><a href="mailto:{$email}" style="font-size:15px;font-weight:600;color:#0cb100;text-decoration:none;">{$email}</a></td>
            </tr>
          </table>
          <h2 style="font-size:16px;font-weight:700;color:#0f172a;margin:0 0 16px;padding-bottom:10px;border-bottom:2px solid #f0f0f0;">Order Items</h2>
          <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px;border-radius:10px;overflow:hidden;border:1px solid #e2e8f0;">
            <thead>
              <tr style="background:#f8fafc;">
                <th style="padding:10px 14px;text-align:left;font-size:12px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.06em;">Product</th>
                <th style="padding:10px 14px;text-align:center;font-size:12px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.06em;">Qty</th>
                <th style="padding:10px 14px;text-align:right;font-size:12px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.06em;">Unit Price</th>
                <th style="padding:10px 14px;text-align:right;font-size:12px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.06em;">Total</th>
              </tr>
            </thead>
            <tbody>{$items_rows}</tbody>
          </table>
          <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:8px;">
            <tr>
              <td style="padding:5px 0;color:#64748b;font-size:14px;">Subtotal</td>
              <td style="padding:5px 0;text-align:right;color:#64748b;font-size:14px;font-family:monospace;">{$currency} {$subtotal_fmt}</td>
            </tr>
            <tr>
              <td style="padding:5px 0;color:#64748b;font-size:14px;">Shipping</td>
              <td style="padding:5px 0;text-align:right;color:#64748b;font-size:14px;font-family:monospace;">{$currency} {$shipping_fmt}</td>
            </tr>
            <tr><td colspan="2" style="padding:8px 0 4px;border-top:2px solid #e2e8f0;"></td></tr>
            <tr>
              <td style="padding:4px 0;color:#0f172a;font-size:18px;font-weight:700;">Order Total</td>
              <td style="padding:4px 0;text-align:right;color:#0cb100;font-size:20px;font-weight:700;font-family:monospace;">{$currency} {$total_fmt}</td>
            </tr>
          </table>
        </td>
      </tr>
      <tr>
        <td style="background:#f8fafc;border-radius:0 0 16px 16px;padding:20px 32px;text-align:center;border-top:1px solid #e2e8f0;">
          <p style="margin:0 0 6px;font-size:13px;color:#94a3b8;">This order was placed via <strong style="color:#0f172a;">ITshop.LK</strong></p>
          <p style="margin:0;font-size:12px;color:#cbd5e1;">Please contact the customer to confirm the order at your earliest convenience.</p>
        </td>
      </tr>
    </table>
  </td></tr>
</table>
</body>
</html>
HTML;

// ── Plain-text fallback ───────────────────────────────────────────
$plain_items = '';
foreach ($cart_items as $item) {
    $plain_items .= sprintf(
        "  - %s (%s)  x%d  %s %s  [Total: %s %s]\n",
        $item['name'] ?? '',
        $item['brand'] ?? '',
        (int)($item['quantity'] ?? 1),
        $currency, number_format((float)($item['price'] ?? 0)),
        $currency, number_format((float)($item['item_total'] ?? 0))
    );
}

$plain_body = <<<TEXT
NEW ORDER RECEIVED – #{$order_number}
Placed: {$order_date}
==========================================

CUSTOMER DETAILS
  Full Name : {$full_name}
  Phone     : {$phone}
  Email     : {$email}

ORDER ITEMS
{$plain_items}
------------------------------------------
  Subtotal  : {$currency} {$subtotal_fmt}
  Shipping  : {$currency} {$shipping_fmt}
  TOTAL     : {$currency} {$total_fmt}
==========================================

Please contact the customer to confirm this order.

ITshop.LK
TEXT;

// ── Send email ────────────────────────────────────────────────────
$to       = 'admin@itshop.lk';
$subject  = "New Order #{$order_number} from {$full_name}";
$boundary = '----=_Part_' . md5(uniqid('', true));

$headers  = "From: ITshop.LK Orders <noreply@itshop.lk>\r\n";
$headers .= "Reply-To: {$full_name} <{$email}>\r\n";
$headers .= "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
$headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";

$message  = "--{$boundary}\r\n";
$message .= "Content-Type: text/plain; charset=UTF-8\r\n";
$message .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
$message .= $plain_body . "\r\n";
$message .= "--{$boundary}\r\n";
$message .= "Content-Type: text/html; charset=UTF-8\r\n";
$message .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
$message .= quoted_printable_encode($html_body) . "\r\n";
$message .= "--{$boundary}--\r\n";

$sent = mail($to, $subject, $message, $headers);
if (!$sent) {
    error_log("Order email failed for #{$order_number} – {$full_name} <{$email}>");
}

// Return success if either DB save OR email worked
$ok = ($db_order_id !== null) || $sent;
echo json_encode([
    'success'      => $ok,
    'order_number' => $order_number,
    'message'      => $ok ? null : 'Failed to place order. Please try again or call us directly.',
]);