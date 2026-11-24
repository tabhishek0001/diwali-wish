<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json');

require_once $_SERVER['DOCUMENT_ROOT'] . '/backend/classes/RazorpayEngine.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $wishId = $input['wish_id'] ?? '';

    if (empty($wishId)) {
        throw new Exception('Missing Wish ID for order generation.');
    }

    $rzp = new RazorpayEngine();
    
    // DYNAMIC PRICE CONFIGURATION (Change price truly dynamically from here!)
    $priceInINR = 11.00; 

    $orderData = $rzp->createOrder($priceInINR, 'rcpt_' . substr(md5($wishId), 0, 10), [
        'wish_id' => $wishId,
        'app' => 'Diwali Viral Engine'
    ]);

    echo json_encode([
        'status' => 'success',
        'key_id' => $rzp->getKeyId(),
        'order_id' => $orderData['id'],
        'amount' => $orderData['amount'],
        'currency' => $orderData['currency']
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}