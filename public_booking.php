<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/reservations.php';

const INSTAPAY_ACCOUNT = ''; // Add the payment address here when supplied.
const BOOKING_WHATSAPP = '0128 9921193';
const BOOKING_WHATSAPP_URL = 'https://wa.me/201289921193';

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

function publicBookingSnapshot(PDO $pdo, int $performanceId, string $browserToken): array
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
    return ['revision' => hash('sha256', json_encode($seats, JSON_THROW_ON_ERROR)), 'seats' => (object) $seats];
}

function submitPublicBooking(PDO $pdo, int $performanceId, string $browserToken, array $input): void
{
    $csrf = $input['csrf_token'] ?? '';
    if (!is_string($csrf) || !hash_equals(hash_hmac('sha256', 'submit-booking', $browserToken), $csrf)) {
        throw new InvalidArgumentException('Please reopen the booking page and try again.');
    }
    if (($input['payment_acknowledged'] ?? '') !== '1') {
        throw new InvalidArgumentException('Complete the payment steps before confirming your request.');
    }
    $requestKey = $input['request_key'] ?? '';
    if (!is_string($requestKey) || !preg_match('/\A[a-f0-9]{32}\z/', $requestKey)) {
        throw new InvalidArgumentException('Please reopen the booking form and try again.');
    }
    // Public callers cannot set approval, collector, balance, ticket, or discount fields.
    $data = reservationInput([
        'customer_name' => $input['customer_name'] ?? '', 'customer_phone' => $input['customer_phone'] ?? '',
        'seat_codes' => $input['seat_codes'] ?? '', 'is_paid' => '1',
    ], true);
    if (!preg_match('/\A\+?[0-9][0-9\s()-]{6,19}\z/', $data['customer_phone'])) {
        throw new InvalidArgumentException('Enter a valid phone number.');
    }
    $requestHash = hash_hmac('sha256', $performanceId . ':' . $requestKey, $browserToken);
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT id FROM performances WHERE id = ? FOR UPDATE');
        $lock->execute([$performanceId]);
        if (!$lock->fetchColumn()) {
            throw new InvalidArgumentException('Performance not found.');
        }
        $existing = $pdo->prepare('SELECT id FROM reservations WHERE request_key_hash = ?');
        $existing->execute([$requestHash]);
        if ($existing->fetchColumn()) {
            $pdo->commit();
            return;
        }
        $conflicts = unavailableSeats($pdo, $performanceId, $data['seat_codes']);
        if ($conflicts) {
            throw new SeatConflictException($conflicts);
        }
        $stmt = $pdo->prepare("INSERT INTO reservations
            (performance_id, customer_name, customer_phone, approval_status, notes, browser_token_hash, request_key_hash)
            VALUES (?, ?, ?, 'pending', ?, ?, ?)");
        $stmt->execute([$performanceId, $data['customer_name'], $data['customer_phone'],
            'Customer reports Instapay payment. Verify the WhatsApp payment screenshot before approval.',
            hash('sha256', $browserToken), $requestHash]);
        $id = (int) $pdo->lastInsertId();
        $stmt = $pdo->prepare('INSERT INTO reservation_seats (reservation_id, performance_id, seat_code) VALUES (?, ?, ?)');
        foreach ($data['seat_codes'] as $code) {
            $stmt->execute([$id, $performanceId, $code]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($error instanceof PDOException && (int) ($error->errorInfo[1] ?? 0) === 1062) {
            throw new SeatConflictException(unavailableSeats($pdo, $performanceId, $data['seat_codes']) ?: $data['seat_codes']);
        }
        throw $error;
    }
}
