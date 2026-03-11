<?php
/**
 * send_quotation_email.php
 * Redesigned to match IT SHOP PVT LTD quotation document style.
 */

// ── Always return JSON, even on fatal errors ──────────────────────────────────
set_exception_handler(function($e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    exit();
});
set_error_handler(function($errno, $errstr) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => "PHP error [{$errno}]: {$errstr}"]);
    exit();
});

if (session_status() === PHP_SESSION_NONE) session_start();

// Auto-detect db.php path (works in root or subfolder)
$db_path = file_exists('db.php') ? 'db.php' : '../db.php';
include $db_path;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ── Parse request ────────────────────────────────────────────────────────────
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON data.']);
    exit();
}

$full_name        = trim($data['full_name']        ?? '');
$email            = trim($data['email']            ?? '');
$phone            = trim($data['phone']            ?? '');
$address          = trim($data['address']          ?? '');   // NEW: customer address
$city             = trim($data['city']             ?? '');   // NEW: customer city
$quotation_number = trim($data['quotation_number'] ?? '');
$currency         = $data['currency']              ?? 'LKR';
$is_guest         = (bool)($data['is_guest']       ?? false);
$message_note     = trim($data['message']          ?? '');
$single_product   = $data['single_product']        ?? null;
$job_no           = trim($data['job_no']           ?? '');   // NEW: job number
$salesperson      = trim($data['salesperson']      ?? 'Sales'); // NEW

// Validate required fields
if (!$full_name || !$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Please provide a valid name and email address.']);
    exit();
}
if (!$quotation_number) {
    $quotation_number = 'S' . str_pad(rand(1, 99999), 5, '0', STR_PAD_LEFT);
}

// ── Resolve cart items ────────────────────────────────────────────────────────
// Priority:
//  1. single_product  — from product-details.php
//  2. cart_items[]    — sent directly from cart.php JS (fixes "Network error")
//  3. DB cart         — logged-in user session fallback
//  4. Session cart    — guest fallback
$cart_items = [];

if ($single_product) {
    // ── Mode 1: single product from product-details.php ──────────────────────
    $cart_items[] = [
        'product_id' => $single_product['product_id'] ?? 0,
        'name'       => $single_product['name']        ?? '',
        'brand'      => $single_product['brand']       ?? '',
        'category'   => $single_product['category']    ?? '',
        'unit_price' => (float)($single_product['unit_price']  ?? 0),
        'quantity'   => (int)($single_product['quantity']      ?? 1),
        'item_total' => (float)($single_product['item_total']  ?? 0),
        'serial_no'  => $single_product['serial_no']   ?? '',
        'warranty'   => $single_product['warranty']    ?? '',
    ];

} elseif (!empty($data['cart_items']) && is_array($data['cart_items'])) {
    // ── Mode 2: cart_items sent from cart.php JS payload ─────────────────────
    // cart.php CART_ITEMS shape: { name, brand, quantity, price, item_total, currency }
    foreach ($data['cart_items'] as $item) {
        $qty        = max(1, (int)($item['quantity']    ?? 1));
        $unit_price = (float)($item['price']            ?? $item['unit_price'] ?? 0);
        $item_total = (float)($item['item_total']       ?? $unit_price * $qty);
        $cart_items[] = [
            'product_id' => $item['product_id'] ?? 0,
            'name'       => trim($item['name']   ?? ''),
            'brand'      => trim($item['brand']  ?? ''),
            'category'   => trim($item['category'] ?? ''),
            'unit_price' => $unit_price,
            'quantity'   => $qty,
            'item_total' => $item_total,
            'serial_no'  => $item['serial_no']  ?? '',
            'warranty'   => $item['warranty']   ?? '',
        ];
    }

} elseif (!$is_guest && isset($_SESSION['user_id'])) {
    // ── Mode 3: logged-in user cart from DB ──────────────────────────────────
    $user_id = (int)$_SESSION['user_id'];
    try {
        $stmt = $pdo->prepare("
            SELECT c.product_id, c.quantity,
                   p.name, p.brand, p.price AS unit_price, p.category,
                   (p.price * c.quantity) AS item_total
            FROM cart c
            JOIN products p ON c.product_id = p.id
            WHERE c.user_id = ?
            ORDER BY c.created_at DESC
        ");
        $stmt->execute([$user_id]);
        $cart_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Could not fetch cart: ' . $e->getMessage()]);
        exit();
    }

} else {
    // ── Mode 4: guest session cart ───────────────────────────────────────────
    foreach ($_SESSION['guest_cart'] ?? [] as $item) {
        $item['item_total'] = ($item['unit_price'] ?? 0) * ($item['quantity'] ?? 1);
        $cart_items[] = $item;
    }
}

if (empty($cart_items)) {
    echo json_encode(['success' => false, 'message' => 'No items to quote — cart is empty.']);
    exit();
}

// ── Totals ───────────────────────────────────────────────────────────────────
$subtotal = array_sum(array_column($cart_items, 'item_total'));
$tax      = 0; // set to e.g. $subtotal * 0.1 for 10% tax
$shipping = 0;
$total    = $subtotal + $tax + $shipping;

$quotation_date  = date('m/d/Y');
$expiration_date = date('m/d/Y', strtotime('+14 days')); // 14-day validity like image
$valid_until     = date('F j, Y', strtotime('+14 days'));
$generated_at    = date('F j, Y \a\t g:i A');

// ── Safe display vars ────────────────────────────────────────────────────────
$safe_name       = htmlspecialchars($full_name);
$safe_email      = htmlspecialchars($email);
$safe_phone      = htmlspecialchars($phone);
$safe_address    = htmlspecialchars($address);
$safe_city       = htmlspecialchars($city);
$safe_salesperson= htmlspecialchars($salesperson);
$safe_job_no     = htmlspecialchars($job_no);
$sub_fmt         = number_format($subtotal, 2);
$tax_fmt         = number_format($tax, 2);
$ship_fmt        = number_format($shipping, 2);
$total_fmt       = number_format($total, 2);

// ── Config ───────────────────────────────────────────────────────────────────
define('ADMIN_EMAIL',     'noreply@itshop.lk');
define('STORE_NAME',      'IT Shop.LK');
define('STORE_URL',       'https://itshop.lk');
define('STORE_LEGAL',     'IT SHOP PVT LTD');
define('STORE_ADDRESS1',  'No:743/8/A Muwanhellawatta Road');
define('STORE_ADDRESS2',  'Thalangama North, Malabe, Sri Lanka');
define('STORE_TEL',       '0112078665');
define('STORE_EMAIL_DSP', 'info@itshop.lk');
define('STORE_WEB',       'https://www.itshop.lk');
// Bank details (from the image)
define('BANK_NAME',       'IT SHOP (PVT) LTD');
define('BANK_BANK',       'Bank Of Ceylon (BOC)');
define('BANK_CODE',       '7010');
define('BANK_ACCOUNT',    '94129502');
define('BANK_BRANCH',     'Malabe');
define('BANK_BRANCH_CODE','763');

// ── Build line-item rows ──────────────────────────────────────────────────────
function buildQuoteRows(array $items, string $cur): string {
    $out = '';
    foreach ($items as $idx => $i) {
        $name      = htmlspecialchars($i['name']       ?? '');
        $brand     = htmlspecialchars($i['brand']      ?? '');
        $serial_no = htmlspecialchars($i['serial_no']  ?? '');
        $warranty  = htmlspecialchars($i['warranty']   ?? '');
        $qty       = (int)($i['quantity']   ?? 1);
        $unit      = number_format((float)($i['unit_price'] ?? 0), 2);
        $tot       = number_format((float)($i['item_total'] ?? 0), 2);

        // Description line — brand prefix if set
        $desc = $brand ? "{$brand} {$name}" : $name;
        if ($warranty) $desc .= " ({$warranty})";

        // Sub-line for serial / job info
        $sub_line = '';
        if ($serial_no) {
            $sub_line .= "<div style='font-size:11px;color:#555;margin-top:3px;'>S/N: {$serial_no}</div>";
        }

        $bg = ($idx % 2 === 0) ? '#ffffff' : '#f9f9f9';

        $out .= "
        <tr style='background:{$bg};'>
          <td style='padding:10px 14px;border:1px solid #d0e8d0;font-size:13px;color:#1a1a1a;vertical-align:top;'>{$desc}{$sub_line}</td>
          <td style='padding:10px 14px;border:1px solid #d0e8d0;text-align:center;font-size:13px;color:#1a1a1a;vertical-align:top;'>{$qty}.00 Units</td>
          <td style='padding:10px 14px;border:1px solid #d0e8d0;text-align:right;font-size:13px;color:#1a1a1a;vertical-align:top;'>{$unit}</td>
          <td style='padding:10px 14px;border:1px solid #d0e8d0;text-align:center;font-size:13px;color:#1a1a1a;vertical-align:top;'></td>
          <td style='padding:10px 14px;border:1px solid #d0e8d0;text-align:right;font-size:13px;font-weight:600;color:#1a1a1a;vertical-align:top;'>{$tot} Rs</td>
        </tr>";
    }
    return $out;
}

$rows = buildQuoteRows($cart_items, $currency);

// ── Notes / job number rows ───────────────────────────────────────────────────
$extra_rows = '';
if ($message_note) {
    $extra_rows .= "
    <tr style='background:#fff;'>
      <td colspan='5' style='padding:10px 14px;border:1px solid #d0e8d0;font-size:12px;color:#555;'>
        " . nl2br(htmlspecialchars($message_note)) . "
      </td>
    </tr>";
}
if ($job_no) {
    $extra_rows .= "
    <tr style='background:#fff;'>
      <td colspan='5' style='padding:10px 14px;border:1px solid #d0e8d0;font-size:12px;color:#333;font-weight:600;'>
        JOB NO - {$safe_job_no}
      </td>
    </tr>";
}

// ════════════════════════════════════════════════════════════════════════════
// CUSTOMER EMAIL — matches uploaded quotation document style
// ════════════════════════════════════════════════════════════════════════════
ob_start(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Quotation #<?= $quotation_number ?></title>
</head>
<body style="margin:0;padding:0;background:#f4f4f4;font-family:Arial,Helvetica,sans-serif;">

<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:30px 16px;">
<tr><td align="center">
<table width="680" cellpadding="0" cellspacing="0"
       style="max-width:680px;width:100%;background:#ffffff;border:1px solid #cccccc;">

  <!-- ══ HEADER: Logo left + Company address right ══ -->
  <tr>
    <td style="padding:28px 32px 20px;">
      <table width="100%" cellpadding="0" cellspacing="0">
        <tr>
          <!-- Logo block -->
          <td style="vertical-align:top;width:50%;">
            <!-- Logo box mimicking the image green box style -->
            <div style="display:inline-block;border:2px solid #2e7d32;border-radius:6px;padding:5px 10px;margin-bottom:8px;">
              <span style="font-size:20px;font-weight:900;color:#2e7d32;letter-spacing:-0.02em;">
                <span style="font-size:22px;">it</span> shop.lk
              </span>
            </div>
            <div style="font-size:10px;color:#2e7d32;font-style:italic;margin-top:2px;">Expand Your Limits</div>
          </td>
          <!-- Company address -->
          <td style="vertical-align:top;text-align:right;width:50%;">
            <div style="font-size:13px;font-weight:700;color:#1a1a1a;margin-bottom:4px;"><?= STORE_LEGAL ?></div>
            <div style="font-size:11px;color:#444;line-height:1.7;">
              <?= STORE_ADDRESS1 ?><br>
              Thalangama North<br>
              Malabe<br>
              Sri Lanka
            </div>
          </td>
        </tr>
      </table>
    </td>
  </tr>

  <!-- ══ DIVIDER ══ -->
  <tr><td style="padding:0 32px;"><div style="height:1px;background:#cccccc;"></div></td></tr>

  <!-- ══ BILL TO + QUOTATION NUMBER ══ -->
  <tr>
    <td style="padding:20px 32px 16px;">
      <table width="100%" cellpadding="0" cellspacing="0">
        <tr>
          <!-- Bill To (left) -->
          <td style="vertical-align:top;width:55%;">
            <div style="font-size:12px;font-weight:700;color:#2e7d32;text-transform:uppercase;margin-bottom:8px;">Bill To</div>
            <div style="font-size:13px;font-weight:700;color:#1a1a1a;margin-bottom:2px;"><?= strtoupper($safe_name) ?></div>
            <?php if ($safe_address): ?>
            <div style="font-size:12px;color:#444;line-height:1.7;">
              <?= $safe_address ?><br>
              <?php if ($safe_city): ?><?= $safe_city ?><br><?php endif; ?>
              Sri Lanka
            </div>
            <?php endif; ?>
            <?php if ($safe_phone): ?>
            <div style="font-size:12px;color:#444;margin-top:4px;">Tel: <?= $safe_phone ?></div>
            <?php endif; ?>
          </td>
          <!-- Quotation number (right) -->
          <td style="vertical-align:top;text-align:right;width:45%;">
            <div style="font-size:22px;font-weight:700;color:#2e7d32;margin-bottom:14px;">
              Quotation # <?= $quotation_number ?>
            </div>
          </td>
        </tr>
      </table>
    </td>
  </tr>

  <!-- ══ DATE / EXPIRATION / SALESPERSON BAR ══ -->
  <tr>
    <td style="padding:0 32px 20px;">
      <table width="100%" cellpadding="0" cellspacing="0"
             style="border:1px solid #999;border-radius:4px;overflow:hidden;">
        <tr>
          <td style="padding:9px 16px;border-right:1px solid #999;background:#fff;">
            <div style="font-size:10px;font-weight:700;color:#555;text-transform:uppercase;margin-bottom:3px;">Quotation Date</div>
            <div style="font-size:13px;color:#1a1a1a;font-weight:600;"><?= $quotation_date ?></div>
          </td>
          <td style="padding:9px 16px;border-right:1px solid #999;background:#fff;">
            <div style="font-size:10px;font-weight:700;color:#555;text-transform:uppercase;margin-bottom:3px;">Expiration</div>
            <div style="font-size:13px;color:#1a1a1a;font-weight:600;"><?= $expiration_date ?></div>
          </td>
          <td style="padding:9px 16px;background:#fff;">
            <div style="font-size:10px;font-weight:700;color:#555;text-transform:uppercase;margin-bottom:3px;">Salesperson</div>
            <div style="font-size:13px;color:#1a1a1a;font-weight:600;"><?= $safe_salesperson ?></div>
          </td>
        </tr>
      </table>
    </td>
  </tr>

  <!-- ══ LINE ITEMS TABLE ══ -->
  <tr>
    <td style="padding:0 32px 4px;">
      <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
        <!-- Table header — green background like image -->
        <tr style="background:#2e7d32;">
          <td style="padding:10px 14px;font-size:11px;font-weight:700;color:#fff;text-transform:uppercase;border:1px solid #2e7d32;width:45%;">Description</td>
          <td style="padding:10px 14px;font-size:11px;font-weight:700;color:#fff;text-transform:uppercase;border:1px solid #2e7d32;text-align:center;width:13%;">Quantity</td>
          <td style="padding:10px 14px;font-size:11px;font-weight:700;color:#fff;text-transform:uppercase;border:1px solid #2e7d32;text-align:right;width:14%;">Unit Price</td>
          <td style="padding:10px 14px;font-size:11px;font-weight:700;color:#fff;text-transform:uppercase;border:1px solid #2e7d32;text-align:center;width:10%;">Taxes</td>
          <td style="padding:10px 14px;font-size:11px;font-weight:700;color:#fff;text-transform:uppercase;border:1px solid #2e7d32;text-align:right;width:18%;">Amount</td>
        </tr>
        <!-- Product rows -->
        <?= $rows ?>
        <!-- Extra info rows (notes / job number) -->
        <?= $extra_rows ?>
        <!-- Spacer row -->
        <tr><td colspan="5" style="padding:6px 14px;border:1px solid #d0e8d0;background:#fff;"></td></tr>
        <!-- Subtotal row -->
        <tr style="background:#fff;">
          <td colspan="4" style="padding:9px 14px;border:1px solid #d0e8d0;text-align:right;font-size:13px;color:#555;">Subtotal</td>
          <td style="padding:9px 14px;border:1px solid #d0e8d0;text-align:right;font-size:13px;color:#1a1a1a;font-weight:600;">0.00 Rs</td>
        </tr>
      </table>
    </td>
  </tr>

  <!-- ══ TOTALS (right-aligned, like image) ══ -->
  <tr>
    <td style="padding:0 32px 24px;">
      <table width="100%" cellpadding="0" cellspacing="0">
        <tr>
          <td style="width:55%;">&nbsp;</td>
          <td style="width:45%;">
            <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
              <!-- Untaxed Amount -->
              <tr>
                <td style="padding:8px 14px;font-size:13px;color:#555;text-align:right;border:1px solid #d0e8d0;">Untaxed Amount</td>
                <td style="padding:8px 14px;font-size:13px;color:#1a1a1a;font-weight:600;text-align:right;border:1px solid #d0e8d0;"><?= $currency ?> <?= $sub_fmt ?> Rs</td>
              </tr>
              <?php if ($tax > 0): ?>
              <tr>
                <td style="padding:8px 14px;font-size:13px;color:#555;text-align:right;border:1px solid #d0e8d0;">Tax</td>
                <td style="padding:8px 14px;font-size:13px;color:#1a1a1a;font-weight:600;text-align:right;border:1px solid #d0e8d0;"><?= $currency ?> <?= $tax_fmt ?> Rs</td>
              </tr>
              <?php endif; ?>
              <!-- Grand Total — green background like image -->
              <tr style="background:#2e7d32;">
                <td style="padding:11px 14px;font-size:14px;font-weight:700;color:#fff;text-align:right;">Total</td>
                <td style="padding:11px 14px;font-size:15px;font-weight:800;color:#fff;text-align:right;"><?= $currency ?> <?= $total_fmt ?> Rs</td>
              </tr>
            </table>
          </td>
        </tr>
      </table>
    </td>
  </tr>

  <!-- ══ TERMS & CONDITIONS ══ -->
  <tr>
    <td style="padding:0 32px 20px;">
      <ul style="margin:0;padding-left:18px;font-size:12px;color:#333;line-height:2.1;">
        <li>Payment: Cheque to be drawn in favor of "<?= STORE_LEGAL ?>"</li>
        <li>Validity: Quotation valid only for 07 Days from the date of Quotation.</li>
        <li>Warranty: Manufacturer's "Carry in" Warranty. The defective part will be repaired and replaced within 14 days. No warranty for chip burnt, physical damage or corrosion.</li>
      </ul>
    </td>
  </tr>

  <!-- ══ BANK DETAILS ══ -->
  <tr>
    <td style="padding:0 32px 24px;">
      <div style="font-size:12px;color:#1a1a1a;line-height:2.0;">
        <div style="font-weight:700;margin-bottom:2px;">Bank Account Name : <?= BANK_NAME ?></div>
        Bank: <?= BANK_BANK ?><br>
        Bank Code : <?= BANK_CODE ?><br>
        Account Number: <?= BANK_ACCOUNT ?><br>
        Branch: <?= BANK_BRANCH ?><br>
        Branch Code: <?= BANK_BRANCH_CODE ?>
      </div>
      <div style="font-size:12px;color:#333;margin-top:12px;">
        <strong>Payment terms: Immediate Payment</strong>
      </div>
    </td>
  </tr>

  <!-- ══ DIVIDER ══ -->
  <tr><td style="padding:0 32px;"><div style="height:1px;background:#cccccc;"></div></td></tr>

  <!-- ══ FOOTER ══ -->
  <tr>
    <td style="padding:14px 32px;text-align:center;background:#fafafa;">
      <div style="font-size:11px;color:#555;">
        Tel: <?= STORE_TEL ?>
        &nbsp;&nbsp;|&nbsp;&nbsp;
        Email: <a href="mailto:<?= STORE_EMAIL_DSP ?>" style="color:#2e7d32;text-decoration:none;"><?= STORE_EMAIL_DSP ?></a>
        &nbsp;&nbsp;|&nbsp;&nbsp;
        Web: <a href="<?= STORE_WEB ?>" style="color:#2e7d32;text-decoration:none;"><?= STORE_WEB ?></a>
      </div>
      <div style="font-size:10px;color:#999;margin-top:6px;">Page 1 / 1</div>
    </td>
  </tr>

</table>
</td></tr>
</table>
</body>
</html>
<?php
$customer_html = ob_get_clean();

// ════════════════════════════════════════════════════════════════════════════
// ADMIN COPY EMAIL
// ════════════════════════════════════════════════════════════════════════════
$source_label = $single_product ? 'Product Page' : 'Cart Page';
ob_start(); ?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Quotation Copy #<?= $quotation_number ?></title></head>
<body style="margin:0;padding:0;background:#f4f4f4;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:32px 16px;">
<tr><td align="center">
<table width="620" cellpadding="0" cellspacing="0"
       style="max-width:620px;width:100%;background:#fff;border-radius:8px;overflow:hidden;border:1px solid #ddd;">
  <!-- Admin header -->
  <tr>
    <td style="background:#2e7d32;padding:22px 28px;">
      <div style="color:#fff;font-size:18px;font-weight:800;">📄 Quotation Sent to Customer</div>
      <div style="color:rgba(255,255,255,.8);font-size:12px;margin-top:4px;">
        #<?= $quotation_number ?> &nbsp;·&nbsp; <?= $generated_at ?> &nbsp;·&nbsp; via <?= $source_label ?>
      </div>
    </td>
  </tr>
  <tr>
    <td style="padding:24px 28px;">
      <!-- Customer info -->
      <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:14px 18px;margin-bottom:18px;">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:#2e7d32;margin-bottom:8px;">Customer Details</div>
        <div style="font-size:13px;color:#1a1a1a;line-height:1.9;">
          <strong>Name:</strong> <?= $safe_name ?><br>
          <strong>Email:</strong> <a href="mailto:<?= $safe_email ?>" style="color:#2e7d32;"><?= $safe_email ?></a><br>
          <?= $safe_phone ? "<strong>Phone:</strong> {$safe_phone}<br>" : '' ?>
          <?= $safe_address ? "<strong>Address:</strong> {$safe_address}<br>" : '' ?>
          <?= $message_note ? "<strong>Note:</strong> " . nl2br(htmlspecialchars($message_note)) : '' ?>
        </div>
      </div>

      <!-- Items -->
      <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin-bottom:18px;">
        <tr style="background:#2e7d32;">
          <td style="padding:9px 12px;font-size:10px;font-weight:700;text-transform:uppercase;color:#fff;border:1px solid #2e7d32;">Description</td>
          <td style="padding:9px 12px;font-size:10px;font-weight:700;text-transform:uppercase;color:#fff;border:1px solid #2e7d32;text-align:center;">Qty</td>
          <td style="padding:9px 12px;font-size:10px;font-weight:700;text-transform:uppercase;color:#fff;border:1px solid #2e7d32;text-align:right;">Unit</td>
          <td style="padding:9px 12px;font-size:10px;font-weight:700;text-transform:uppercase;color:#fff;border:1px solid #2e7d32;text-align:right;">Total</td>
        </tr>
        <?php foreach ($cart_items as $idx => $i):
            $bg = $idx % 2 === 0 ? '#fff' : '#f9f9f9';
            $brand = htmlspecialchars($i['brand'] ?? '');
            $name  = htmlspecialchars($i['name']  ?? '');
            $desc  = $brand ? "{$brand} {$name}" : $name;
            if (!empty($i['warranty'])) $desc .= ' (' . htmlspecialchars($i['warranty']) . ')';
        ?>
        <tr style="background:<?= $bg ?>;">
          <td style="padding:9px 12px;border:1px solid #d0e8d0;font-size:13px;color:#1a1a1a;"><?= $desc ?></td>
          <td style="padding:9px 12px;border:1px solid #d0e8d0;font-size:13px;text-align:center;"><?= (int)($i['quantity'] ?? 1) ?></td>
          <td style="padding:9px 12px;border:1px solid #d0e8d0;font-size:13px;text-align:right;"><?= number_format((float)($i['unit_price'] ?? 0), 2) ?></td>
          <td style="padding:9px 12px;border:1px solid #d0e8d0;font-size:13px;font-weight:700;text-align:right;color:#2e7d32;"><?= number_format((float)($i['item_total'] ?? 0), 2) ?></td>
        </tr>
        <?php endforeach; ?>
      </table>

      <!-- Totals -->
      <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:18px;">
        <tr>
          <td style="font-size:13px;color:#555;padding:4px 0;">Untaxed Amount</td>
          <td style="text-align:right;font-size:13px;padding:4px 0;"><?= $currency ?> <?= $sub_fmt ?></td>
        </tr>
        <?php if ($tax > 0): ?>
        <tr>
          <td style="font-size:13px;color:#555;padding:4px 0;">Tax</td>
          <td style="text-align:right;font-size:13px;padding:4px 0;"><?= $currency ?> <?= $tax_fmt ?></td>
        </tr>
        <?php endif; ?>
        <tr><td colspan="2" style="padding:5px 0;"><div style="height:2px;background:#e2e8f0;"></div></td></tr>
        <tr>
          <td style="font-size:15px;font-weight:700;padding:5px 0;">TOTAL</td>
          <td style="text-align:right;font-size:18px;font-weight:800;color:#2e7d32;padding:5px 0;"><?= $currency ?> <?= $total_fmt ?></td>
        </tr>
      </table>

      <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;padding:12px 16px;font-size:13px;color:#92400e;text-align:center;">
        ⚡ Follow up if the customer hasn't placed an order within <strong>48 hours</strong>.
      </div>
    </td>
  </tr>
</table>
</td></tr>
</table>
</body></html>
<?php
$admin_html = ob_get_clean();

// ── Send emails ───────────────────────────────────────────────────────────────
$subject_customer = "Your Quotation #{$quotation_number} — " . STORE_NAME;
$subject_admin    = "Quotation #{$quotation_number} sent to {$safe_name}";

$h_base  = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
$h_cust  = $h_base . "From: " . STORE_NAME . " <noreply@itshop.lk>\r\nReply-To: " . ADMIN_EMAIL . "\r\n";
$h_admin = $h_base . "From: " . STORE_NAME . " <noreply@itshop.lk>\r\nReply-To: {$safe_email}\r\n";

/*
 * OPTION A: PHP mail() — active by default
 * OPTION B: PHPMailer Gmail SMTP — comment out A, uncomment B below
 */

// Option A
$sent = mail($email, $subject_customer, $customer_html, $h_cust);
mail(ADMIN_EMAIL, $subject_admin, $admin_html, $h_admin);

/*
// Option B — PHPMailer Gmail SMTP
// composer require phpmailer/phpmailer
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
    'success'          => true,
    'quotation_number' => $quotation_number,
    'message'          => $sent
        ? "Quotation emailed to {$email} successfully!"
        : "Quotation saved but email delivery may be pending.",
]);