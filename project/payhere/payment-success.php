<?php
session_start();
$order_id = $_SESSION['pending_order_id'] ?? null;
unset($_SESSION['payhere_data'], $_SESSION['pending_order_id']);
// Clear cart
include 'db.php';
if ($order_id && isset($_SESSION['user_id'])) {
    $pdo->prepare("DELETE FROM cart WHERE user_id = ?")->execute([$_SESSION['user_id']]);
}
// Show success message / redirect to order page
header('Location: order-confirmation.php?id=' . $order_id);
exit();