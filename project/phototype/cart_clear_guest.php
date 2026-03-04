<?php
// cart_clear_guest.php
// Called via fetch() after a guest order is confirmed.
session_start();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_SESSION['guest_cart'] = [];
    echo json_encode(['success' => true]);
} else {
    http_response_code(405);
    echo json_encode(['success' => false]);
}