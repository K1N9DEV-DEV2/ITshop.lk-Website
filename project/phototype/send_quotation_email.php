<?php
/**
 * send_quotation_email.php
 * Handles two call modes:
 *  1. Cart quotation (cart.php)       — reads cart from DB / guest session
 *  2. Single-product quotation        — reads inline `single_product` payload (product-details.php)
 */

session_start();
include 'db.php';

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
$quotation_number = trim($data['quotation_number'] ?? '');
$currency         = $data['currency']              ?? 'LKR';
$is_guest         = (bool)($data['is_guest']       ?? false);
$message_note     = trim($data['message']          ?? '');
$single_product   = $data['single_product']        ?? null;   // ← set by product-details.php

// Validate required fields
if (!$full_name || !$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Please provide a valid name and email address.']);
    exit();
}
if (!$quotation_number) {
    // Auto-generate if missing
    $quotation_number = 'QUO-' . strtoupper(substr(md5(uniqid()), 0, 10));
}

// ── Resolve cart items ────────────────────────────────────────────────────────
$cart_items = [];

if ($single_product) {
    // ── Mode 2: single product from product-details.php ──────────────────────
    $cart_items[] = [
        'product_id' => $single_product['product_id'] ?? 0,
        'name'       => $single_product['name']       ?? '',
        'brand'      => $single_product['brand']      ?? '',
        'category'   => $single_product['category']   ?? '',
        'unit_price' => (float)($single_product['unit_price'] ?? 0),
        'quantity'   => (int)($single_product['quantity']  ?? 1),
        'item_total' => (float)($single_product['item_total'] ?? 0),
    ];
} elseif (!$is_guest && isset($_SESSION['user_id'])) {
    // ── Mode 1a: logged-in user cart from DB ─────────────────────────────────
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
    // ── Mode 1b: guest session cart ──────────────────────────────────────────
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
$shipping = 0;
$total    = $subtotal + $shipping;

$quotation_date = date('F j, Y');
$valid_until    = date('F j, Y', strtotime('+30 days'));
$generated_at   = date('F j, Y \a\t g:i A');

// ── Safe display vars ────────────────────────────────────────────────────────
$safe_name  = htmlspecialchars($full_name);
$safe_email = htmlspecialchars($email);
$safe_phone = htmlspecialchars($phone);
$sub_fmt    = number_format($subtotal, 2);
$ship_fmt   = number_format($shipping, 2);
$total_fmt  = number_format($total, 2);

// ── Config ───────────────────────────────────────────────────────────────────
define('ADMIN_EMAIL', 'noreply@itshop.lk');   // ← change to your admin address
define('STORE_NAME',  'IT Shop.LK');
define('STORE_URL',   'https://itshop.lk');

// ── Build line-item rows ──────────────────────────────────────────────────────
function buildQuoteRows(array $items, string $cur): string {
    $out = '';
    foreach ($items as $idx => $i) {
        $num   = str_pad($idx + 1, 2, '0', STR_PAD_LEFT);
        $name  = htmlspecialchars($i['name']      ?? '');
        $brand = htmlspecialchars($i['brand']      ?? '');
        $cat   = htmlspecialchars($i['category']   ?? '');
        $qty   = (int)($i['quantity']   ?? 1);
        $unit  = number_format((float)($i['unit_price']  ?? 0), 2);
        $tot   = number_format((float)($i['item_total']  ?? 0), 2);
        $tags  = '';
        if ($brand) $tags .= "<span style='display:inline-block;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:5px;padding:1px 8px;font-size:10px;color:#64748b;margin-right:4px;'>{$brand}</span>";
        if ($cat)   $tags .= "<span style='display:inline-block;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:5px;padding:1px 8px;font-size:10px;color:#64748b;'>{$cat}</span>";
        $out .= "
        <tr>
          <td style='padding:14px 16px;border-bottom:1px solid #f0f4f8;color:#94a3b8;font-size:12px;font-weight:600;vertical-align:top;'>{$num}</td>
          <td style='padding:14px 16px;border-bottom:1px solid #f0f4f8;vertical-align:top;'>
            <div style='font-weight:700;color:#0f172a;font-size:14px;margin-bottom:5px;'>{$name}</div>
            {$tags}
          </td>
          <td style='padding:14px 16px;border-bottom:1px solid #f0f4f8;text-align:center;color:#64748b;font-size:13px;vertical-align:top;'>{$cur} {$unit}</td>
          <td style='padding:14px 16px;border-bottom:1px solid #f0f4f8;text-align:center;vertical-align:top;'>
            <span style='display:inline-block;background:#0f172a;color:#fff;border-radius:20px;padding:3px 12px;font-size:12px;font-weight:700;'>×{$qty}</span>
          </td>
          <td style='padding:14px 16px;border-bottom:1px solid #f0f4f8;text-align:right;font-weight:700;color:#0cb100;font-size:14px;vertical-align:top;'>{$cur} {$tot}</td>
        </tr>";
    }
    return $out;
}

$rows = buildQuoteRows($cart_items, $currency);

// ── Optional: additional notes row in email ───────────────────────────────────
$notes_html = '';
if ($message_note) {
    $safe_note  = nl2br(htmlspecialchars($message_note));
    $notes_html = "
    <div style='background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:16px 20px;margin-bottom:28px;'>
      <div style='font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#94a3b8;margin-bottom:8px;'>Customer Notes</div>
      <div style='font-size:13px;color:#475569;line-height:1.7;'>{$safe_note}</div>
    </div>";
}

// ════════════════════════════════════════════════════════════════════════════
// CUSTOMER EMAIL
// ════════════════════════════════════════════════════════════════════════════
ob_start(); ?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Quotation #<?= $quotation_number ?></title></head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:'Segoe UI',Helvetica,Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:40px 16px;">
<tr><td align="center">
<table width="620" cellpadding="0" cellspacing="0" style="max-width:620px;width:100%;">

  <!-- ── DARK HERO HEADER ── -->
  <tr><td style="background:#0f172a;border-radius:20px 20px 0 0;padding:48px 40px 40px;text-align:center;">
    <!-- logo -->
    <div style="display:inline-block;background:rgba(12,177,0,.15);border:1px solid rgba(12,177,0,.4);border-radius:10px;padding:7px 20px;margin-bottom:28px;">
      <span style="color:#0cb100;font-size:18px;font-weight:900;letter-spacing:-0.02em;">IT<span style="color:#fff;"> Shop.LK</span></span>
    </div>

    <!-- badge -->
    <div style="display:inline-flex;align-items:center;gap:7px;background:rgba(255,255,255,.07);border:1px solid rgba(12,177,0,.35);border-radius:100px;padding:5px 16px;margin-bottom:20px;">
      <span style="display:inline-block;width:7px;height:7px;background:#0cb100;border-radius:50%;"></span>
      <span style="color:#d0fce4;font-size:10px;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;">Official Quotation</span>
    </div>

    <h1 style="color:#fff;font-size:42px;font-weight:900;margin:0 0 6px;letter-spacing:-0.04em;line-height:1;">
      Quota<span style="font-style:italic;font-weight:300;color:#6aff5e;">tion</span>
    </h1>
    <p style="color:rgba(255,255,255,.55);font-size:14px;margin:0 0 28px;">Prepared exclusively for <strong style="color:#fff;"><?= $safe_name ?></strong></p>

    <!-- quotation number chip -->
    <div style="display:inline-block;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.15);border-radius:10px;padding:10px 24px;">
      <div style="color:rgba(255,255,255,.5);font-size:10px;text-transform:uppercase;letter-spacing:.12em;margin-bottom:4px;">Quotation Number</div>
      <div style="color:#fff;font-size:18px;font-weight:800;letter-spacing:.05em;font-family:monospace;"><?= $quotation_number ?></div>
    </div>
  </td></tr>

  <!-- ── VALIDITY STRIP ── -->
  <tr><td style="background:#0cb100;padding:13px 40px;">
    <table width="100%" cellpadding="0" cellspacing="0">
      <tr>
        <td style="color:#fff;font-size:13px;font-weight:700;">✓ &nbsp;Valid for 30 days — contact us to confirm your order</td>
        <td style="text-align:right;color:rgba(255,255,255,.8);font-size:12px;">Until <?= $valid_until ?></td>
      </tr>
    </table>
  </td></tr>

  <!-- ── BODY ── -->
  <tr><td style="background:#fff;padding:40px;">

    <!-- Dates row -->
    <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:32px;">
      <tr>
        <td style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px 20px;">
          <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#94a3b8;margin-bottom:4px;">Date Issued</div>
          <div style="font-size:14px;font-weight:600;color:#0f172a;"><?= $quotation_date ?></div>
        </td>
        <td width="12"></td>
        <td style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px 20px;">
          <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#94a3b8;margin-bottom:4px;">Valid Until</div>
          <div style="font-size:14px;font-weight:600;color:#0f172a;"><?= $valid_until ?></div>
        </td>
      </tr>
    </table>

    <!-- Bill To -->
    <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:#94a3b8;margin-bottom:10px;">Bill To</div>
    <table width="100%" cellpadding="0" cellspacing="0" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;margin-bottom:32px;">
      <tr><td style="padding:18px 22px;">
        <table width="100%" cellpadding="0" cellspacing="0">
          <tr>
            <td style="padding:4px 0;font-size:13px;color:#0f172a;"><strong>Name:</strong> <?= $safe_name ?></td>
            <td style="padding:4px 0;font-size:13px;color:#0f172a;"><strong>Email:</strong> <?= $safe_email ?></td>
          </tr>
          <?php if ($safe_phone): ?>
          <tr>
            <td colspan="2" style="padding:4px 0;font-size:13px;color:#0f172a;"><strong>Phone:</strong> <?= $safe_phone ?></td>
          </tr>
          <?php endif; ?>
        </table>
      </td></tr>
    </table>

    <!-- Customer notes (if any) -->
    <?= $notes_html ?>

    <!-- Line Items -->
    <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:#94a3b8;margin-bottom:10px;">Line Items</div>
    <table width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border-radius:12px;overflow:hidden;margin-bottom:8px;border:1px solid #e2e8f0;">
      <tr style="background:#0f172a;">
        <td style="padding:10px 16px;font-size:10px;font-weight:700;text-transform:uppercase;color:rgba(255,255,255,.6);">#</td>
        <td style="padding:10px 16px;font-size:10px;font-weight:700;text-transform:uppercase;color:rgba(255,255,255,.6);">Product</td>
        <td style="padding:10px 16px;font-size:10px;font-weight:700;text-transform:uppercase;color:rgba(255,255,255,.6);text-align:center;">Unit Price</td>
        <td style="padding:10px 16px;font-size:10px;font-weight:700;text-transform:uppercase;color:rgba(255,255,255,.6);text-align:center;">Qty</td>
        <td style="padding:10px 16px;font-size:10px;font-weight:700;text-transform:uppercase;color:rgba(255,255,255,.6);text-align:right;">Total</td>
      </tr>
      <?= $rows ?>
    </table>

    <!-- Totals -->
    <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:32px;">
      <tr><td align="right">
        <table cellpadding="0" cellspacing="0" style="width:280px;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;">
          <tr style="background:#f8fafc;">
            <td style="padding:11px 18px;font-size:14px;color:#64748b;">Subtotal</td>
            <td style="padding:11px 18px;font-size:14px;text-align:right;color:#0f172a;font-weight:600;"><?= $currency ?> <?= $sub_fmt ?></td>
          </tr>
          <tr style="border-top:1px solid #e2e8f0;">
            <td style="padding:11px 18px;font-size:14px;color:#64748b;">Shipping</td>
            <td style="padding:11px 18px;font-size:14px;text-align:right;color:#0f172a;font-weight:600;"><?= $currency ?> <?= $ship_fmt ?></td>
          </tr>
          <tr style="background:#0f172a;">
            <td style="padding:14px 18px;font-size:15px;font-weight:700;color:#fff;">Grand Total</td>
            <td style="padding:14px 18px;text-align:right;font-size:18px;font-weight:900;color:#0cb100;font-family:monospace;"><?= $currency ?> <?= $total_fmt ?></td>
          </tr>
        </table>
      </td></tr>
    </table>

    <!-- Terms -->
    <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:32px;">
      <tr>
        <td style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:18px 20px;vertical-align:top;">
          <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#64748b;margin-bottom:12px;">📋 Terms &amp; Conditions</div>
          <div style="font-size:13px;color:#64748b;line-height:2;">
            — Prices valid for 30 days from issue date.<br>
            — All prices are inclusive of applicable taxes.<br>
            — Payment required before delivery.<br>
            — Warranty terms vary by product.
          </div>
        </td>
        <td width="12"></td>
        <td style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:18px 20px;vertical-align:top;">
          <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#64748b;margin-bottom:12px;">ℹ️ Important Notes</div>
          <div style="font-size:13px;color:#64748b;line-height:2;">
            — Stock availability is subject to change.<br>
            — Prices may vary based on final order date.<br>
            — Delivery timelines confirmed on order.<br>
            — Contact us for bulk order discounts.
          </div>
        </td>
      </tr>
    </table>

    <!-- CTA -->
    <div style="text-align:center;margin-bottom:8px;">
      <a href="<?= STORE_URL ?>" style="display:inline-block;background:#0cb100;color:#fff;text-decoration:none;font-weight:700;font-size:15px;padding:15px 44px;border-radius:100px;box-shadow:0 4px 20px rgba(12,177,0,.35);">
        Place Your Order →
      </a>
    </div>

  </td></tr>

  <!-- ── FOOTER ── -->
  <tr><td style="background:#0f172a;border-radius:0 0 20px 20px;padding:28px 40px;text-align:center;">
    <p style="color:rgba(255,255,255,.4);font-size:12px;margin:0 0 6px;">
      This is a computer-generated quotation and is valid without a physical signature.
    </p>
    <p style="color:rgba(255,255,255,.5);font-size:12px;margin:0 0 6px;">
      Questions? <a href="mailto:info@itshop.lk" style="color:#0cb100;text-decoration:none;">info@itshop.lk</a>
      &nbsp;·&nbsp; <a href="tel:+940779005652" style="color:#0cb100;text-decoration:none;">+94 077 900 5652</a>
    </p>
    <p style="color:rgba(255,255,255,.25);font-size:11px;margin:0;">© <?= date('Y') ?> IT Shop.LK · All rights reserved</p>
  </td></tr>

</table>
</td></tr>
</table>
</body></html>
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
<body style="margin:0;padding:0;background:#f1f5f9;font-family:'Segoe UI',Helvetica,Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:32px 16px;">
<tr><td align="center">
<table width="620" cellpadding="0" cellspacing="0" style="max-width:620px;width:100%;background:#fff;border-radius:16px;overflow:hidden;">
  <tr><td style="background:#0cb100;padding:24px 32px;">
    <div style="color:#fff;font-size:20px;font-weight:800;">📄 Quotation Sent to Customer</div>
    <div style="color:rgba(255,255,255,.8);font-size:13px;margin-top:4px;">#<?= $quotation_number ?> · <?= $generated_at ?> · via <?= $source_label ?></div>
  </td></tr>
  <tr><td style="padding:28px 32px;">

    <!-- Customer info -->
    <table width="100%" cellpadding="0" cellspacing="0" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;margin-bottom:20px;">
      <tr><td style="padding:16px 20px;">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:#0cb100;margin-bottom:10px;">Customer Details</div>
        <div style="font-size:14px;color:#0f172a;line-height:1.9;">
          <strong>Name:</strong> <?= $safe_name ?><br>
          <strong>Email:</strong> <a href="mailto:<?= $safe_email ?>" style="color:#0cb100;"><?= $safe_email ?></a><br>
          <?= $safe_phone ? "<strong>Phone:</strong> <a href='tel:{$safe_phone}' style='color:#0cb100;'>{$safe_phone}</a>" : '' ?>
          <?php if ($message_note): ?>
          <br><strong>Note:</strong> <?= nl2br(htmlspecialchars($message_note)) ?>
          <?php endif; ?>
        </div>
      </td></tr>
    </table>

    <!-- Items -->
    <table width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border-radius:10px;overflow:hidden;margin-bottom:20px;border:1px solid #e2e8f0;">
      <tr style="background:#0f172a;">
        <td style="padding:10px 14px;font-size:10px;font-weight:700;text-transform:uppercase;color:rgba(255,255,255,.6);">#</td>
        <td style="padding:10px 14px;font-size:10px;font-weight:700;text-transform:uppercase;color:rgba(255,255,255,.6);">Product</td>
        <td style="padding:10px 14px;font-size:10px;font-weight:700;text-transform:uppercase;color:rgba(255,255,255,.6);text-align:center;">Unit</td>
        <td style="padding:10px 14px;font-size:10px;font-weight:700;text-transform:uppercase;color:rgba(255,255,255,.6);text-align:center;">Qty</td>
        <td style="padding:10px 14px;font-size:10px;font-weight:700;text-transform:uppercase;color:rgba(255,255,255,.6);text-align:right;">Total</td>
      </tr>
      <?= $rows ?>
    </table>

    <!-- Totals -->
    <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:20px;">
      <tr>
        <td style="color:#64748b;font-size:14px;padding:4px 0;">Subtotal</td>
        <td style="text-align:right;font-size:14px;padding:4px 0;"><?= $currency ?> <?= $sub_fmt ?></td>
      </tr>
      <tr>
        <td style="color:#64748b;font-size:14px;padding:4px 0;">Shipping</td>
        <td style="text-align:right;font-size:14px;padding:4px 0;"><?= $currency ?> <?= $ship_fmt ?></td>
      </tr>
      <tr><td colspan="2" style="padding:6px 0;"><div style="height:2px;background:#e2e8f0;"></div></td></tr>
      <tr>
        <td style="font-size:17px;font-weight:700;padding:6px 0;">TOTAL</td>
        <td style="text-align:right;font-size:20px;font-weight:800;color:#0cb100;padding:6px 0;"><?= $currency ?> <?= $total_fmt ?></td>
      </tr>
    </table>

    <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:14px 18px;font-size:13px;color:#92400e;text-align:center;">
      ⚡ Follow up if the customer hasn't placed an order within <strong>48 hours</strong>.
    </div>

  </td></tr>
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