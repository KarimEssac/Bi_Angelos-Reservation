<?php
// Holds share the performance locks used by reservations, so availability changes are atomic.
const SEAT_HOLD_SECONDS = 90;

function validateSeatHoldKey(mixed $key): string
{
    if (!is_string($key) || !preg_match('/\A[a-f0-9]{32}\z/', $key)) {
        throw new InvalidArgumentException('Reopen the reservation modal to hold your seats.');
    }
    return $key;
}

function seatHoldOwner(string $browserToken, string $holdKey): string
{
    return hash_hmac('sha256', 'seat-hold:' . $holdKey, $browserToken);
}

function seatHoldSnapshot(PDO $pdo, int $performanceId, ?string $owner = null): array
{
    $stmt = $pdo->prepare('SELECT seat_code, owner_hash FROM seat_holds
        WHERE performance_id = ? AND expires_at > NOW() ORDER BY seat_code');
    $stmt->execute([$performanceId]);
    $seats = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $seats[$row['seat_code']] = ['on_hold' => true, 'hold_mine' => $owner !== null && hash_equals($owner, $row['owner_hash']),
            'pending' => false, 'mine' => false, 'customer_name' => null, 'remaining_amount' => 0];
    }
    return $seats;
}

function changeSeatHold(PDO $pdo, string $owner, string $action, array $basket): void
{
    $performances = [];
    foreach ($basket as $day => $codes) $performances[$day] = performanceId($pdo, $day);
    $pdo->beginTransaction();
    try {
        $pdo->query('SELECT id FROM performances ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN);
        if ($action === 'release') {
            $stmt = $pdo->prepare('DELETE FROM seat_holds WHERE owner_hash = ?');
            $stmt->execute([$owner]);
            $pdo->commit();
            return;
        }
        $pdo->exec('DELETE FROM seat_holds WHERE expires_at <= NOW()');
        $conflicts = basketConflictKeys($pdo, $basket, $performances, $owner);
        if ($conflicts) throw new SeatConflictException($conflicts);
        if ($action === 'renew') requireSeatHold($pdo, $basket, $performances, $owner);
        // Replace this modal's basket together; a failed acquire never leaves partial holds.
        $stmt = $pdo->prepare('DELETE FROM seat_holds WHERE owner_hash = ?');
        $stmt->execute([$owner]);
        $stmt = $pdo->prepare('INSERT INTO seat_holds (performance_id, seat_code, owner_hash, expires_at)
            VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ' . SEAT_HOLD_SECONDS . ' SECOND))');
        foreach ($basket as $day => $codes) {
            foreach ($codes as $code) $stmt->execute([$performances[$day], $code, $owner]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function requireSeatHold(PDO $pdo, array $basket, array $performances, string $owner): void
{
    $missing = [];
    foreach ($basket as $day => $codes) {
        $holds = seatHoldSnapshot($pdo, $performances[$day], $owner);
        foreach ($codes as $code) {
            if (empty($holds[$code]['hold_mine'])) $missing[] = $day . '|' . $code;
        }
    }
    if ($missing) throw new SeatHoldExpiredException($missing);
}

class SeatHoldExpiredException extends SeatConflictException {}
