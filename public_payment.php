<?php
require_once __DIR__ . '/public_booking.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['error' => 'Open the reservation modal to load payment details.']);
    exit;
}
try {
    $token = visitorToken(false);
    $requestKey = validatePaymentRequest($token, $_POST);
    $recipient = $pdo->query('SELECT next_recipient FROM payment_rotation WHERE id = 1')->fetchColumn();
    if (!isset(INSTAPAY_RECIPIENTS[$recipient])) throw new RuntimeException('Payment rotation has not been configured.');
    echo json_encode(['recipient' => $recipient, 'assignment' => paymentAssignmentSignature($token, $requestKey, $recipient)]
        + INSTAPAY_RECIPIENTS[$recipient], JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException $error) {
    http_response_code(400);
    echo json_encode(['error' => $error->getMessage()]);
} catch (Throwable $error) {
    http_response_code(500);
    error_log('Payment assignment failed: ' . $error->getMessage());
    echo json_encode(['error' => 'Could not load payment details. Please try again.']);
}
