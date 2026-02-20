<?php
/**
 * get-cart-status.php
 * Returns the current user's cart item count and total as JSON.
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

require_once __DIR__ . '/db.php';

// Guest: return zeros
if (empty($_SESSION['user_id'])) {
    json_out([
        'success'    => true,
        'logged_in'  => false,
        'cart_count' => 0,
        'cart_total' => 0.00,
    ]);
}

$user_id = (int) $_SESSION['user_id'];

// Fetch cart summary from `cart` table (matches cart.php)
try {
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(c.quantity), 0)              AS cart_count,
            COALESCE(SUM(c.quantity * p.price), 0.00) AS cart_total
        FROM cart c
        INNER JOIN products p ON p.id = c.product_id
        WHERE c.user_id = ?
          AND p.stock_count > 0
    ");
    $stmt->execute([$user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    json_out([
        'success'    => true,
        'logged_in'  => true,
        'cart_count' => (int)   $row['cart_count'],
        'cart_total' => (float) $row['cart_total'],
    ]);
} catch (PDOException $e) {
    error_log('get-cart-status: ' . $e->getMessage());
    json_out(['success' => false, 'message' => 'Database error'], 500);
}