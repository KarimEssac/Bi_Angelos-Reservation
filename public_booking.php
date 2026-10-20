<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/reservations.php';

const INSTAPAY_RECIPIENTS = [
    'maria' => ['qr' => 'maria.jpeg', 'whatsapp_url' => 'https://wa.me/201211571484',
        'url' => 'https://ipn.eg/C/Q/marianabil2711/instapay?ISIGN=23052603MEUCIH0aqFMdPtnqwthntNY7aza0WIgt/0tko3YCtXrZmfu8AiEAu9V9Q8GtzC6wNRrskv0cuGP33Ka2omSNllUu35UPpA0='],
    'monica' => ['qr' => 'monica.jpeg', 'whatsapp_url' => 'https://wa.me/201289921193',
        'url' => 'https://ipn.eg/C/Q/monicanabil98/instapay?ISIGN=23052602MEYCIQCreltVhAlWTpgMbQ/TxX23oNwSDSLQcXXcAPMGfgy9zQIhAOq6c2j+LyipweCa0SiZD2wrTpIF9WOzMsZZpG0SWt27'],
];

function visitorToken(bool $create = true): string
{
    $token = $_COOKIE['booking_browser'] ?? '';
    if (is_string($token) && preg_match('/\A[a-f0-9]{64}\z/', $token)) {
        return $token;
    }
    if (!$create) {
        throw new InvalidArgumentException('Please reopen the booking page with cookies enabled.');
    }
    $token = bin2hex(random_bytes(32));
    $path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/') . '/';
    setcookie('booking_browser', $token, ['expires' => time() + 365 * 86400, 'path' => $path,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true, 'samesite' => 'Lax']);
    $_COOKIE['booking_browser'] = $token;
    return $token;
}

function validatePaymentRequest(string $browserToken, array $input): string
{
    $csrf = $input['csrf_token'] ?? '';
    if (!is_string($csrf) || !hash_equals(hash_hmac('sha256', 'submit-booking', $browserToken), $csrf)) {
        throw new InvalidArgumentException('Please reopen the booking page and try again.');
    }
    $requestKey = $input['request_key'] ?? '';
    if (!is_string($requestKey) || !preg_match('/\A[a-f0-9]{32}\z/', $requestKey)) {
        throw new InvalidArgumentException('Please reopen the booking form and try again.');
    }
    return $requestKey;
}

function paymentAssignmentSignature(string $browserToken, string $requestKey, string $recipient): string
{
    return hash_hmac('sha256', 'instapay:' . $requestKey . ':' . $recipient, $browserToken);
}

function paymentReceiptRecipient(PDO $pdo, string $browserToken, mixed $requestKey): ?array
{
    if (!is_string($requestKey) || !preg_match('/\A[a-f0-9]{32}\z/', $requestKey)) return null;
    $hashes = [];
    foreach (PERFORMANCE_DATES as $day => $label) {
        $hashes[] = hash_hmac('sha256', performanceId($pdo, $day) . ':' . $requestKey, $browserToken);
    }
    $placeholders = implode(',', array_fill(0, count($hashes), '?'));
    $stmt = $pdo->prepare("SELECT payment_recipient FROM reservations WHERE browser_token_hash = ?
        AND request_key_hash IN ($placeholders) AND payment_recipient IS NOT NULL LIMIT 1");
    $stmt->execute(array_merge([hash('sha256', $browserToken)], $hashes));
    $recipient = $stmt->fetchColumn();
    return INSTAPAY_RECIPIENTS[$recipient] ?? null;
}

function publicBookingSnapshot(PDO $pdo, int $performanceId, string $browserToken, ?string $holdOwner = null): array
{
    $stmt = $pdo->prepare("SELECT s.seat_code, r.approval_status,
        CASE WHEN r.browser_token_hash = ? THEN r.customer_name ELSE NULL END AS own_name
        FROM reservation_seats s JOIN reservations r
            ON r.id = s.reservation_id AND r.performance_id = s.performance_id
        WHERE s.performance_id = ? ORDER BY s.seat_code");
    $stmt->execute([hash('sha256', $browserToken), $performanceId]);
    $seats = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $seats[$row['seat_code']] = ['pending' => $row['approval_status'] === 'pending',
            'mine' => $row['own_name'] !== null, 'customer_name' => $row['own_name'],
            'remaining_amount' => 0];
    }
    $seats += seatHoldSnapshot($pdo, $performanceId, $holdOwner);
    return ['revision' => hash('sha256', json_encode($seats, JSON_THROW_ON_ERROR)), 'seats' => (object) $seats];
}

function submitPublicBooking(PDO $pdo, string $browserToken, array $input): void
{
    $requestKey = validatePaymentRequest($browserToken, $input);
    if (($input['payment_acknowledged'] ?? '') !== '1') {
        throw new InvalidArgumentException('Complete the payment steps before confirming your request.');
    }
    $recipient = $input['payment_recipient'] ?? '';
    $assignment = $input['payment_assignment'] ?? '';
    if (!is_string($recipient) || !isset(INSTAPAY_RECIPIENTS[$recipient]) || !is_string($assignment)
        || !hash_equals(paymentAssignmentSignature($browserToken, $requestKey, $recipient), $assignment)) {
        throw new InvalidArgumentException('Reopen the booking page to load your payment account before confirming.');
    }
    // Public callers cannot set approval, collector, balance, ticket, or discount fields.
    $basket = inputSeatBasket($input);
    $data = reservationInput([
        'customer_name' => $input['customer_name'] ?? '', 'customer_phone' => $input['customer_phone'] ?? '',
        'seat_codes' => implode(',', reset($basket)), 'is_paid' => '1',
    ], true);
    if (!preg_match('/\A\+?[0-9][0-9\s()-]{6,19}\z/', $data['customer_phone'])) {
        throw new InvalidArgumentException('Enter a valid phone number.');
    }
    $notes = $input['notes'] ?? '';
    if (!is_string($notes) || mb_strlen($notes) > 2000) {
        throw new InvalidArgumentException('Keep your comments within 2,000 characters.');
    }
    $data['notes'] = trim($notes);
    $data['payment_recipient'] = $recipient;
    $data['hold_key'] = validateSeatHoldKey($input['hold_key'] ?? null);
    reserveSeatBasket($pdo, $basket, $data, null, $browserToken, $requestKey);
}

function publicCalendarSnapshot(PDO $pdo, string $browserToken, ?string $holdKey = null): array
{
    $calendar = [];
    $pdo->beginTransaction();
    try {
        foreach (PERFORMANCE_DATES as $day => $label) {
            $calendar[$day] = publicBookingSnapshot($pdo, performanceId($pdo, $day), $browserToken,
                $holdKey === null ? null : seatHoldOwner($browserToken, $holdKey));
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    return ['calendar' => $calendar,
        'revision' => hash('sha256', json_encode(array_column($calendar, 'revision'), JSON_THROW_ON_ERROR))];
}
