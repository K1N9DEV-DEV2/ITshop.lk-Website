<?php
include '../db.php';

$merchant_id     = $_POST['merchant_id']     ?? '';
$order_id        = $_POST['order_id']        ?? '';
$payhere_amount  = $_POST['payhere_amount']  ?? '';
$payhere_currency= $_POST['payhere_currency']?? '';
$status_code     = $_POST['status_code']     ?? '';
$md5sig          = $_POST['md5sig']          ?? '';

define('PAYHERE_MERCHANT_SECRET', 'YOUR_MERCHANT_SECRET'); // same as checkout.php

// Verify hash
$local_md5 = strtoupper(md5(
    $merchant_id .
    $order_id .
    $payhere_amount .
    $payhere_currency .
    $status_code .
    strtoupper(md5(PAYHERE_MERCHANT_SECRET))
));

if ($local_md5 !== $md5sig) {
    http_response_code(400);
    die('Hash mismatch');
}

// status_code 2 = success, -1 = cancelled, -2 = failed, -3 = chargebacked
if ($status_code == 2) {
    // Extract numeric order ID from ref like "STC-000042"
    $numeric_id = (int) ltrim(str_replace('STC-', '', $order_id), '0');

    $stmt = $pdo->prepare("
        UPDATE orders SET status = 'paid', paid_at = NOW()
        WHERE id = ? AND status = 'pending'
    ");
    $stmt->execute([$numeric_id]);
}

http_response_code(200);
echo 'OK';