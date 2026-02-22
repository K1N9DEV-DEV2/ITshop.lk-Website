<?php
session_start();
include '../db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['pending_order_id'])) {
    $stmt = $pdo->prepare("DELETE FROM orders WHERE id = ? AND status = 'pending'");
    $stmt->execute([$_SESSION['pending_order_id']]);

    // Also delete order items
    $stmt = $pdo->prepare("DELETE FROM order_items WHERE order_id = ?");
    $stmt->execute([$_SESSION['pending_order_id']]);

    unset($_SESSION['pending_order_id'], $_SESSION['payhere_data']);
}
http_response_code(200);