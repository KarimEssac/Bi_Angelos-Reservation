<?php
require_once __DIR__ . '/theatre.php';

class SeatConflictException extends InvalidArgumentException
{
    public function __construct(public array $seatCodes)
    {
        parent::__construct('These seats are no longer available: ' . implode(', ', $seatCodes) . '.');
    }
}

function unavailableSeats(PDO $pdo, int $performanceId, array $codes, ?int $exceptReservationId = null): array
{
    if (!$codes) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($codes), '?'));
    $sql = "SELECT seat_code FROM reservation_seats WHERE performance_id = ? AND seat_code IN ($placeholders)";
    $parameters = array_merge([$performanceId], $codes);
    if ($exceptReservationId !== null) {
        $sql .= ' AND reservation_id <> ?';
        $parameters[] = $exceptReservationId;
    }
    $stmt = $pdo->prepare($sql . ' ORDER BY seat_code');
    $stmt->execute($parameters);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function performanceId(PDO $pdo, string $date): int
{
    if (!isset(PERFORMANCE_DATES[$date])) {
        throw new InvalidArgumentException('Choose Friday 6/11 or Saturday 7/11, 2026.');
    }
    $stmt = $pdo->prepare('SELECT id FROM performances WHERE performance_date = ?');
    $stmt->execute([$date]);
    $id = $stmt->fetchColumn();
    if (!$id) {
        throw new RuntimeException('The selected performance has not been configured.');
    }
    return (int) $id;
}

function expireReservations(PDO $pdo): void
{
    $pdo->exec("DELETE FROM reservations
        WHERE (approval_status = 'pending' AND created_at <= DATE_SUB(NOW(), INTERVAL 12 HOUR))
        OR (approval_status = 'approved' AND remaining_amount > 0
            AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR))");
}

function listReservations(PDO $pdo, int $performanceId): array
{
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $stmt = $pdo->prepare('SELECT r.id, r.performance_id, r.customer_name, r.customer_phone,
            r.remaining_amount, r.approval_status, r.notes, r.tickets_received, r.has_service_discount,
            r.reserved_by_admin_id, r.created_at, a.name AS reserved_by_name,
            CASE WHEN r.approval_status = \'pending\' THEN DATE_ADD(r.created_at, INTERVAL 12 HOUR) ELSE NULL END AS expires_at
            FROM reservations r LEFT JOIN admins a ON a.id = r.reserved_by_admin_id
            WHERE r.performance_id = ? ORDER BY r.created_at DESC, r.id DESC');
        $stmt->execute([$performanceId]);
        $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt = $pdo->prepare('SELECT reservation_id, seat_code FROM reservation_seats
            WHERE performance_id = ? ORDER BY seat_code');
        $stmt->execute([$performanceId]);
        $seats = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $seat) {
            $seats[$seat['reservation_id']][] = $seat['seat_code'];
        }
        foreach ($reservations as &$reservation) {
            $reservation['seat_codes'] = implode(',', $seats[$reservation['id']] ?? []);
            $reservation['area_label'] = reservationAreaLabel($reservation['seat_codes']);
        }
        unset($reservation);
        if ($ownsTransaction) {
            $pdo->commit();
        }
        return $reservations;
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function reservationSnapshot(PDO $pdo, int $performanceId): array
{
    $reservations = listReservations($pdo, $performanceId);
    $seats = [];
    foreach ($reservations as $reservation) {
        foreach (explode(',', $reservation['seat_codes']) as $code) {
            $seats[$code] = [
                'reservation_id' => (int) $reservation['id'],
                'customer_name' => $reservation['customer_name'],
                'remaining_amount' => (float) $reservation['remaining_amount'],
                'pending' => $reservation['approval_status'] === 'pending',
            ];
        }
    }
    return ['revision' => hash('sha256', json_encode($reservations, JSON_THROW_ON_ERROR)),
        'reservations' => $reservations, 'seats' => (object) $seats];
}

function renderReservationData(array $allReservations, string $selectedDay, string $selectedArea = 'theatre'): string
{
    $approvedReservations = array_filter($allReservations, fn($row) => $row['approval_status'] === 'approved');
    $pendingReservations = array_filter($allReservations, fn($row) => $row['approval_status'] === 'pending');
    $reservedSeats = [];
    foreach ($approvedReservations as $row) {
        $reservedSeats = array_merge($reservedSeats, explode(',', $row['seat_codes']));
    }
    ob_start();
    try {
        require __DIR__ . '/reservation_data.php';
        return ob_get_clean();
    } catch (Throwable $error) {
        ob_end_clean();
        throw $error;
    }
}

function reservationInput(array $input, bool $creating): array
{
    foreach (['customer_name', 'customer_phone', 'seat_codes', 'remaining_amount'] as $field) {
        if (isset($input[$field]) && !is_string($input[$field])) {
            throw new InvalidArgumentException('Invalid reservation details.');
        }
    }
    $name = trim($input['customer_name'] ?? '');
    $phone = trim($input['customer_phone'] ?? '');
    if ($name === '' || mb_strlen($name) > 100 || $phone === '' || mb_strlen($phone) > 20) {
        throw new InvalidArgumentException('Enter a customer name and phone number within the field limits.');
    }
    $amount = $creating && ($input['is_paid'] ?? '') === '1' ? '0' : ($input['remaining_amount'] ?? '0');
    if (!preg_match('/\A\d{1,8}(?:\.\d{1,2})?\z/', $amount)) {
        throw new InvalidArgumentException('Enter a valid remaining amount of zero or more.');
    }
    return [
        'customer_name' => $name,
        'customer_phone' => $phone,
        'seat_codes' => parseSeatCodes($input['seat_codes'] ?? ''),
        'remaining_amount' => $amount,
        'tickets_received' => ($input['tickets_received'] ?? '') === '1' ? 1 : 0,
        'has_service_discount' => ($input['has_service_discount'] ?? '') === '1' ? 1 : 0,
    ];
}

function performReservationAction(PDO $pdo, int $performanceId, int $adminId, array $input): string
{
    $action = $input['action'] ?? '';
    if (!is_string($action) || !in_array($action, ['reserve', 'update', 'delete', 'approve', 'reject'], true)) {
        throw new InvalidArgumentException('Invalid reservation action.');
    }
    $data = in_array($action, ['reserve', 'update'], true) ? reservationInput($input, $action === 'reserve') : null;
    $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($action !== 'reserve' && !$id) {
        throw new InvalidArgumentException('Choose a valid reservation.');
    }
    $pdo->beginTransaction();
    try {
        // Serialize writes for this performance, then check availability immediately before saving.
        // The unique seat key still protects against writers outside this application.
        $stmt = $pdo->prepare('SELECT id FROM performances WHERE id = ? FOR UPDATE');
        $stmt->execute([$performanceId]);
        if (!$stmt->fetchColumn()) {
            throw new InvalidArgumentException('Performance not found.');
        }
        if ($action !== 'reserve') {
            $stmt = $pdo->prepare("SELECT approval_status,
                approval_status = 'pending' AND created_at <= DATE_SUB(NOW(), INTERVAL 12 HOUR) AS expired
                FROM reservations
                WHERE id = ? AND performance_id = ? FOR UPDATE");
            $stmt->execute([$id, $performanceId]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$existing) {
                throw new InvalidArgumentException('Reservation not found for this day.');
            }
            if ($existing['expired']) {
                throw new InvalidArgumentException('This pending request has expired. Refresh the page for the latest availability.');
            }
            $status = $existing['approval_status'];
            if (in_array($action, ['approve', 'reject'], true) && $status !== 'pending') {
                throw new InvalidArgumentException('This reservation is no longer pending approval.');
            }
        }
        if ($data !== null) {
            $conflicts = unavailableSeats($pdo, $performanceId, $data['seat_codes'], $action === 'update' ? (int) $id : null);
            if ($conflicts) {
                throw new SeatConflictException($conflicts);
            }
        }
        if ($action === 'reserve') {
            $stmt = $pdo->prepare('INSERT INTO reservations
                (performance_id, customer_name, customer_phone, remaining_amount, tickets_received,
                    has_service_discount, reserved_by_admin_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$performanceId, $data['customer_name'], $data['customer_phone'],
                $data['remaining_amount'], $data['tickets_received'], $data['has_service_discount'], $adminId]);
            $id = (int) $pdo->lastInsertId();
        } elseif ($action === 'update') {
            $stmt = $pdo->prepare('UPDATE reservations SET customer_name = ?, customer_phone = ?,
                remaining_amount = ?, tickets_received = ?, has_service_discount = ?
                WHERE id = ? AND performance_id = ?');
            $stmt->execute([$data['customer_name'], $data['customer_phone'], $data['remaining_amount'],
                $data['tickets_received'], $data['has_service_discount'], $id, $performanceId]);
            $stmt = $pdo->prepare('DELETE FROM reservation_seats WHERE reservation_id = ? AND performance_id = ?');
            $stmt->execute([$id, $performanceId]);
        } elseif ($action === 'approve') {
            $stmt = $pdo->prepare("UPDATE reservations SET approval_status = 'approved', reserved_by_admin_id = ?
                WHERE id = ? AND performance_id = ?");
            $stmt->execute([$adminId, $id, $performanceId]);
        } else {
            $stmt = $pdo->prepare('DELETE FROM reservations WHERE id = ? AND performance_id = ?');
            $stmt->execute([$id, $performanceId]);
        }
        if ($data !== null) {
            $stmt = $pdo->prepare('INSERT INTO reservation_seats (reservation_id, performance_id, seat_code) VALUES (?, ?, ?)');
            foreach ($data['seat_codes'] as $code) {
                $stmt->execute([$id, $performanceId, $code]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1062) {
            $conflicts = unavailableSeats($pdo, $performanceId, $data['seat_codes'], $action === 'update' ? (int) $id : null);
            throw new SeatConflictException($conflicts ?: $data['seat_codes']);
        }
        throw $e;
    }
    return ['reserve' => 'success', 'update' => 'updated', 'delete' => 'deleted', 'approve' => 'approved', 'reject' => 'rejected'][$action];
}
