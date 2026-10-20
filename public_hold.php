<?php
require_once __DIR__ . '/public_booking.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['error' => 'Open the reservation modal to hold seats.']);
    exit;
}
try {
    $token = visitorToken(false);
    validatePaymentRequest($token, $_POST);
    $holdKey = validateSeatHoldKey($_POST['hold_key'] ?? null);
    $action = $_POST['hold_action'] ?? '';
    if (!is_string($action) || !in_array($action, ['acquire', 'renew', 'release'], true)) {
        throw new InvalidArgumentException('Choose a valid hold action.');
    }
    $basket = $action === 'release' ? [] : inputSeatBasket($_POST);
    changeSeatHold($pdo, seatHoldOwner($token, $holdKey), $action, $basket);
    echo json_encode(['ok' => true, 'expires_in' => SEAT_HOLD_SECONDS]);
} catch (SeatConflictException $error) {
    http_response_code(409);
    echo json_encode(['error' => $error->getMessage(), 'unavailable_seats' => $error->seatCodes,
        'hold_expired' => $error instanceof SeatHoldExpiredException]);
} catch (InvalidArgumentException $error) {
    http_response_code(400);
    echo json_encode(['error' => $error->getMessage()]);
} catch (Throwable $error) {
    http_response_code(500);
    error_log('Seat hold failed: ' . $error->getMessage());
    echo json_encode(['error' => 'Could not update your seat hold. Please try again.']);
}
