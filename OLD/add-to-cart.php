<?php
// Start session for user management
session_start();

// Set content type to JSON
header('Content-Type: application/json');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed'
    ]);
    exit;
}

// Include database connection
include 'db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Please login to add items to cart',
        'redirect' => 'login.php'
    ]);
    exit;
}

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validate input
    if (!$input || !isset($input['product_id'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid product data'
        ]);
        exit;
    }
    
    $product_id = (int)$input['product_id'];
    $quantity = isset($input['quantity']) ? max(1, (int)$input['quantity']) : 1;
    $user_id = $_SESSION['user_id'];
    
    // Validate product exists and get details
    $stmt = $pdo->prepare("SELECT id, name, price, stock_count, in_stock FROM products WHERE id = ?");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$product) {
        echo json_encode([
            'success' => false,
            'message' => 'Product not found'
        ]);
        exit;
    }
    
    // Check if product is in stock
    if (!$product['in_stock']) {
        echo json_encode([
            'success' => false,
            'message' => 'Product is out of stock'
        ]);
        exit;
    }
    
    // Check if requested quantity is available
    if ($product['stock_count'] && $quantity > $product['stock_count']) {
        echo json_encode([
            'success' => false,
            'message' => "Only {$product['stock_count']} items available in stock"
        ]);
        exit;
    }
    
    // Check if item already exists in cart
    $stmt = $pdo->prepare("SELECT id, quantity FROM cart WHERE user_id = ? AND product_id = ?");
    $stmt->execute([$user_id, $product_id]);
    $existing_item = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing_item) {
        // Update existing cart item
        $new_quantity = $existing_item['quantity'] + $quantity;
        
        // Check if total quantity doesn't exceed stock
        if ($product['stock_count'] && $new_quantity > $product['stock_count']) {
            echo json_encode([
                'success' => false,
                'message' => "Cannot add {$quantity} more items. Only " . 
                           ($product['stock_count'] - $existing_item['quantity']) . " more available"
            ]);
            exit;
        }
        
        $stmt = $pdo->prepare("UPDATE cart SET quantity = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$new_quantity, $existing_item['id']]);
        
        $message = "Cart updated! Total quantity: {$new_quantity}";
    } else {
        // Add new item to cart
        $stmt = $pdo->prepare("INSERT INTO cart (user_id, product_id, quantity, price, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$user_id, $product_id, $quantity, $product['price']]);
        
        $message = $quantity > 1 ? 
                  "{$quantity} items added to cart!" : 
                  "Item added to cart!";
    }
    
    // Get updated cart totals
    $stmt = $pdo->prepare("SELECT COUNT(*) as count, SUM(price * quantity) as total FROM cart WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $cart_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Log the cart action (optional)
    try {
        $stmt = $pdo->prepare("INSERT INTO cart_logs (user_id, product_id, action, quantity, created_at) VALUES (?, ?, 'add', ?, NOW())");
        $stmt->execute([$user_id, $product_id, $quantity]);
    } catch (Exception $e) {
        // Don't fail the main operation if logging fails
        error_log("Cart logging failed: " . $e->getMessage());
    }
    
    echo json_encode([
        'success' => true,
        'message' => $message,
        'product_name' => $product['name'],
        'quantity_added' => $quantity,
        'cart_count' => (int)($cart_data['count'] ?? 0),
        'cart_total' => (float)($cart_data['total'] ?? 0)
    ]);

} catch (PDOException $e) {
    error_log("Database error in add-to-cart.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred'
    ]);
} catch (Exception $e) {
    error_log("General error in add-to-cart.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while adding item to cart'
    ]);
}
?>