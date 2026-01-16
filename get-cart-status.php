<?php
// Start session for user management
session_start();

// Set content type to JSON
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Database connection
include 'db.php';

// Initialize response array
$response = [
    'success' => false,
    'cart_count' => 0,
    'cart_total' => 0,
    'cart_items' => [],
    'formatted_total' => 'LKR 0',
    'message' => ''
];

try {
    // Check if user is logged in
    if (!isset($_SESSION['user_id'])) {
        $response['message'] = 'User not logged in';
        echo json_encode($response);
        exit();
    }

    $user_id = $_SESSION['user_id'];
    $user_currency = 'LKR';

    // Get cart items with product details
    $stmt = $pdo->prepare("
        SELECT 
            c.id as cart_id,
            c.quantity,
            c.price as cart_price,
            c.added_at,
            p.id as product_id,
            p.name,
            p.category,
            p.price as current_price,
            p.original_price,
            p.image,
            p.brand,
            p.rating,
            p.stock_count,
            p.in_stock
        FROM cart c
        JOIN products p ON c.product_id = p.id
        WHERE c.user_id = ?
        ORDER BY c.added_at DESC
    ");
    $stmt->execute([$user_id]);
    $cart_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate totals
    $cart_count = 0;
    $cart_total = 0;
    $cart_items_formatted = [];

    foreach ($cart_items as $item) {
        $cart_count += $item['quantity'];
        $item_total = $item['cart_price'] * $item['quantity'];
        $cart_total += $item_total;

        // Format item for response
        $cart_items_formatted[] = [
            'cart_id' => $item['cart_id'],
            'product_id' => $item['product_id'],
            'name' => $item['name'],
            'brand' => $item['brand'],
            'category' => $item['category'],
            'image' => $item['image'],
            'quantity' => $item['quantity'],
            'price' => $item['cart_price'],
            'current_price' => $item['current_price'],
            'original_price' => $item['original_price'],
            'stock_count' => $item['stock_count'],
            'in_stock' => $item['in_stock'],
            'item_total' => $item_total,
            'formatted_price' => $user_currency . ' ' . number_format($item['cart_price']),
            'formatted_total' => $user_currency . ' ' . number_format($item_total),
            'stock_status' => getStockStatus($item),
            'has_discount' => $item['original_price'] > $item['cart_price'],
            'discount_amount' => $item['original_price'] - $item['cart_price'],
            'added_at' => $item['added_at']
        ];
    }

    // Calculate additional costs
    $free_shipping_threshold = 50000;
    $shipping_cost = ($cart_total > 0 && $cart_total < $free_shipping_threshold) ? 1500 : 0;
    
    // Apply coupon discount if any
    $coupon_discount = 0;
    $applied_coupon = $_SESSION['applied_coupon'] ?? null;
    if ($applied_coupon && $cart_total > 0) {
        if ($applied_coupon['type'] === 'percentage') {
            $coupon_discount = ($cart_total * $applied_coupon['value']) / 100;
            if ($applied_coupon['max_discount'] > 0) {
                $coupon_discount = min($coupon_discount, $applied_coupon['max_discount']);
            }
        } else {
            $coupon_discount = min($applied_coupon['value'], $cart_total);
        }
    }

    // Calculate tax on discounted total
    $tax_rate = 0.08; // 8% tax
    $taxable_amount = max(0, $cart_total - $coupon_discount);
    $tax_amount = $taxable_amount * $tax_rate;

    // Final total
    $final_total = $cart_total + $shipping_cost + $tax_amount - $coupon_discount;

    // Build successful response
    $response = [
        'success' => true,
        'cart_count' => $cart_count,
        'cart_total' => $cart_total,
        'shipping_cost' => $shipping_cost,
        'tax_amount' => $tax_amount,
        'coupon_discount' => $coupon_discount,
        'final_total' => $final_total,
        'cart_items' => $cart_items_formatted,
        'formatted_total' => $user_currency . ' ' . number_format($cart_total),
        'formatted_final_total' => $user_currency . ' ' . number_format($final_total),
        'currency' => $user_currency,
        'free_shipping_threshold' => $free_shipping_threshold,
        'free_shipping_eligible' => $cart_total >= $free_shipping_threshold,
        'amount_for_free_shipping' => max(0, $free_shipping_threshold - $cart_total),
        'applied_coupon' => $applied_coupon,
        'totals' => [
            'subtotal' => $cart_total,
            'shipping' => $shipping_cost,
            'tax' => $tax_amount,
            'discount' => $coupon_discount,
            'final' => $final_total
        ],
        'formatted_totals' => [
            'subtotal' => $user_currency . ' ' . number_format($cart_total),
            'shipping' => $shipping_cost > 0 ? $user_currency . ' ' . number_format($shipping_cost) : 'FREE',
            'tax' => $user_currency . ' ' . number_format($tax_amount),
            'discount' => $coupon_discount > 0 ? '-' . $user_currency . ' ' . number_format($coupon_discount) : null,
            'final' => $user_currency . ' ' . number_format($final_total)
        ],
        'has_items' => $cart_count > 0,
        'has_out_of_stock' => hasOutOfStockItems($cart_items),
        'has_low_stock' => hasLowStockItems($cart_items),
        'message' => $cart_count > 0 ? 'Cart loaded successfully' : 'Cart is empty'
    ];

} catch (PDOException $e) {
    error_log("Cart status query failed: " . $e->getMessage());
    $response['message'] = 'Database error occurred';
} catch (Exception $e) {
    error_log("Cart status error: " . $e->getMessage());
    $response['message'] = 'An error occurred while fetching cart status';
}

// Return JSON response
echo json_encode($response, JSON_PRETTY_PRINT);

/**
 * Helper function to determine stock status
 */
function getStockStatus($item) {
    if (!$item['in_stock'] || $item['stock_count'] <= 0) {
        return [
            'status' => 'out_of_stock',
            'message' => 'Out of Stock',
            'class' => 'out-of-stock',
            'icon' => 'fas fa-times-circle',
            'color' => 'danger'
        ];
    } elseif ($item['stock_count'] <= 5) {
        return [
            'status' => 'low_stock',
            'message' => 'Only ' . $item['stock_count'] . ' left',
            'class' => 'low-stock',
            'icon' => 'fas fa-exclamation-triangle',
            'color' => 'warning'
        ];
    } else {
        return [
            'status' => 'in_stock',
            'message' => 'In Stock',
            'class' => 'in-stock',
            'icon' => 'fas fa-check-circle',
            'color' => 'success'
        ];
    }
}

/**
 * Helper function to check if cart has out of stock items
 */
function hasOutOfStockItems($cart_items) {
    foreach ($cart_items as $item) {
        if (!$item['in_stock'] || $item['stock_count'] <= 0) {
            return true;
        }
    }
    return false;
}

/**
 * Helper function to check if cart has low stock items
 */
function hasLowStockItems($cart_items) {
    foreach ($cart_items as $item) {
        if ($item['in_stock'] && $item['stock_count'] <= 5 && $item['stock_count'] > 0) {
            return true;
        }
    }
    return false;
}
?>