<?php
/**
 * add-to-cart.php
 * Accepts JSON POST: { product_id: int, quantity: int }
 * Returns JSON:      { success, cart_count, cart_total, message, redirect? }
 */

ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/cart_error.log');

header('Content-Type: application/json');
header('Cache-Control: no-store');

function json_out(array $data, int $status = 200): void {
    ob_clean();
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if (!file_exists(__DIR__ . '/db.php')) {
    json_out(['success' => false, 'message' => 'Server configuration missing'], 500);
}
require_once __DIR__ . '/db.php';

if (!isset($pdo)) {
    json_out(['success' => false, 'message' => 'Database connection unavailable'], 500);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['success' => false, 'message' => 'Method not allowed'], 405);
}

if (empty($_SESSION['user_id'])) {
    json_out([
        'success'  => false,
        'message'  => 'Please log in to add items to your cart',
        'redirect' => 'login.php',
    ], 401);
}

$user_id = (int) $_SESSION['user_id'];

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($body)) {
    json_out(['success' => false, 'message' => 'Invalid request format'], 400);
}

$product_id = isset($body['product_id']) ? (int) $body['product_id'] : 0;
$quantity   = isset($body['quantity'])   ? (int) $body['quantity']   : 1;

if ($product_id <= 0) {
    json_out(['success' => false, 'message' => 'Invalid product ID'], 400);
}
if ($quantity < 1) $quantity = 1;

// Validate product & stock
try {
    $stmt = $pdo->prepare("SELECT id, name, price, stock_count FROM products WHERE id = ? LIMIT 1");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('add-to-cart product fetch: ' . $e->getMessage());
    json_out(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
}

if (!$product) {
    json_out(['success' => false, 'message' => 'Product not found'], 404);
}
if ((int)$product['stock_count'] <= 0) {
    json_out(['success' => false, 'message' => 'This product is out of stock']);
}

// Upsert into `cart` table (matches cart.php)
try {
    $stmt = $pdo->prepare("SELECT id, quantity FROM cart WHERE user_id = ? AND product_id = ? LIMIT 1");
    $stmt->execute([$user_id, $product_id]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $new_qty = min($existing['quantity'] + $quantity, (int)$product['stock_count']);

        if ($new_qty === (int)$existing['quantity']) {
            json_out(['success' => false, 'message' => 'You already have the maximum available quantity in your cart']);
        }

        $stmt = $pdo->prepare("UPDATE cart SET quantity = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$new_qty, $existing['id']]);
    } else {
        $qty_to_add = min($quantity, (int)$product['stock_count']);
        $stmt = $pdo->prepare("
            INSERT INTO cart (user_id, product_id, quantity, price, created_at, updated_at)
            VALUES (?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([$user_id, $product_id, $qty_to_add, $product['price']]);
    }
} catch (PDOException $e) {
    error_log('add-to-cart upsert: ' . $e->getMessage());
    json_out(['success' => false, 'message' => 'Could not update cart: ' . $e->getMessage()], 500);
}

// Return updated cart summary
try {
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(c.quantity), 0)             AS cart_count,
            COALESCE(SUM(c.quantity * p.price), 0.00) AS cart_total
        FROM cart c
        INNER JOIN products p ON p.id = c.product_id
        WHERE c.user_id = ?
          AND p.stock_count > 0
    ");
    $stmt->execute([$user_id]);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('add-to-cart summary: ' . $e->getMessage());
    $summary = ['cart_count' => 0, 'cart_total' => 0];
}

json_out([
    'success'    => true,
    'message'    => htmlspecialchars($product['name']) . ' added to cart!',
    'cart_count' => (int)   $summary['cart_count'],
    'cart_total' => (float) $summary['cart_total'],
]);