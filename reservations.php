<?php
require_once __DIR__ . '/theatre.php';

class SeatConflictException extends InvalidArgumentException
{
    public function __construct(public array $seatCodes)
    {
        $labels = array_map(static function (string $code): string {
            if (!str_contains($code, '|')) return $code;
            [$day, $seat] = explode('|', $code, 2);
            return (PERFORMANCE_DATES[$day] ?? $day) . ' - ' . $seat;
        }, $seatCodes);
        parent::__construct('These seats are no longer available: ' . implode(', ', $labels) . '.');
    }
}

function parseSeatBasket(string $json): array
{
    try {
        $input = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new InvalidArgumentException('Choose valid seats for your booking.');
    }
    if (!is_array($input) || !$input || count($input) > count(PERFORMANCE_DATES)) {
        throw new InvalidArgumentException('Select at least one seat.');
    }
    $basket = [];
    foreach ($input as $day => $codes) {
        if (!isset(PERFORMANCE_DATES[$day]) || !is_array($codes) || !$codes
            || array_filter($codes, fn($code) => !is_string($code))) {
            throw new InvalidArgumentException('Choose valid seats and dates.');
        }
        $basket[$day] = parseSeatCodes(implode(',', $codes));
    }
    ksort($basket);
    return $basket;
}

function inputSeatBasket(array $input): array
{
    if (isset($input['seat_basket'])) {
        if (!is_string($input['seat_basket'])) throw new InvalidArgumentException('Invalid seat selection.');
        return parseSeatBasket($input['seat_basket']);
    }
    $day = $input['day'] ?? '';
    if (!is_string($day) || !isset(PERFORMANCE_DATES[$day])) throw new InvalidArgumentException('Choose a valid date.');
    return [$day => parseSeatCodes(is_string($input['seat_codes'] ?? null) ? $input['seat_codes'] : '')];
}

function basketConflictKeys(PDO $pdo, array $basket, array $performances): array
{
    $conflicts = [];
    foreach ($basket as $day => $codes) {
        foreach (unavailableSeats($pdo, $performances[$day], $codes) as $code) $conflicts[] = $day . '|' . $code;
    }
    return $conflicts;
}

function reserveSeatBasket(PDO $pdo, array $basket, array $data, ?int $adminId = null,
    ?string $browserToken = null, ?string $requestKey = null): void
{
    $performances = [];
    $prices = [];
    foreach ($basket as $day => $codes) {
        $performances[$day] = performanceId($pdo, $day);
        $prices[$day] = reservationTicketTotal(['seat_codes' => implode(',', $codes),
            'has_service_discount' => $data['has_service_discount']]);
    }
    asort($performances, SORT_NUMERIC);
    $public = $browserToken !== null;
    $hashes = [];
    if ($public) {
        foreach ($performances as $day => $id) $hashes[$day] = hash_hmac('sha256', $id . ':' . $requestKey, $browserToken);
    }
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT id FROM performances WHERE id = ? FOR UPDATE');
        foreach ($performances as $id) {
            $lock->execute([$id]);
            if (!$lock->fetchColumn()) throw new InvalidArgumentException('Performance not found.');
        }
        if ($public) {
            $existing = $pdo->prepare('SELECT id FROM reservations WHERE request_key_hash = ?');
            $found = 0;
            foreach ($hashes as $hash) {
                $existing->execute([$hash]);
                if ($existing->fetchColumn()) $found++;
            }
            if ($found === count($hashes)) {
                $pdo->commit();
                return;
            }
            if ($found) throw new InvalidArgumentException('This request was already submitted. Reopen the booking page for a new request.');
        }
        $conflicts = basketConflictKeys($pdo, $basket, $performances);
        if ($conflicts) throw new SeatConflictException($conflicts);
        $remainingCents = (int) round((float) $data['remaining_amount'] * 100);
        $unallocated = $remainingCents;
        $total = array_sum($prices);
        $lastDay = array_key_last($basket);
        $groupKey = bin2hex(random_bytes(16));
        $reference = strtoupper(substr($groupKey, 0, 10));
        foreach ($basket as $day => $codes) {
            $dayCents = $day === $lastDay ? $unallocated : (int) floor($remainingCents * $prices[$day] / $total);
            $unallocated -= $dayCents;
            $notes = count($basket) > 1 ? 'Combined booking ' . $reference . '. ' : '';
            if ($public) $notes .= 'Customer reports Instapay payment. Verify the WhatsApp payment screenshot before approval.';
            if ($public && ($data['notes'] ?? '') !== '') $notes = $data['notes'];
            $stmt = $pdo->prepare('INSERT INTO reservations
                (performance_id, customer_name, customer_phone, remaining_amount, approval_status, notes,
                    tickets_received, has_service_discount, reserved_by_admin_id, browser_token_hash, request_key_hash, booking_group_key)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$performances[$day], $data['customer_name'], $data['customer_phone'],
                number_format($dayCents / 100, 2, '.', ''), $public ? 'pending' : 'approved', $notes ?: null,
                $public ? 0 : $data['tickets_received'], $public ? 0 : $data['has_service_discount'], $public ? null : $adminId,
                $public ? hash('sha256', $browserToken) : null, $hashes[$day] ?? null, $groupKey]);
            $reservationId = (int) $pdo->lastInsertId();
            $stmt = $pdo->prepare('INSERT INTO reservation_seats (reservation_id, performance_id, seat_code) VALUES (?, ?, ?)');
            foreach ($codes as $code) $stmt->execute([$reservationId, $performances[$day], $code]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($error instanceof PDOException && (int) ($error->errorInfo[1] ?? 0) === 1062) {
            $conflicts = basketConflictKeys($pdo, $basket, $performances);
            if (!$conflicts) {
                foreach ($basket as $day => $codes) {
                    foreach ($codes as $code) $conflicts[] = $day . '|' . $code;
                }
            }
            throw new SeatConflictException($conflicts);
        }
        throw $error;
    }
}

function pendingBookingGroups(array $reservations): array
{
    $groups = [];
    foreach ($reservations as $row) {
        $key = $row['booking_group_key'] ?: 'single-' . $row['id'];
        $groups[$key]['members'][] = $row;
    }
    foreach ($groups as $key => &$group) {
        usort($group['members'], fn($a, $b) => [$a['performance_date'], $a['id']] <=> [$b['performance_date'], $b['id']]);
        $pending = array_values(array_filter($group['members'], fn($row) => $row['approval_status'] === 'pending'));
        if (!$pending) {
            unset($groups[$key]);
            continue;
        }
        $group['key'] = $key;
        $group['pending_ids'] = array_map('intval', array_column($pending, 'id'));
        $group['expires_at'] = min(array_column($pending, 'expires_at'));
        $group['total'] = array_sum(array_map('reservationTicketTotal', $group['members']));
        $group['version'] = hash('sha256', json_encode($group['members'], JSON_THROW_ON_ERROR));
    }
    unset($group);
    return $groups;
}

function preventVipApproval(PDO $pdo, array $reservationIds): void
{
    $placeholders = implode(',', array_fill(0, count($reservationIds), '?'));
    $stmt = $pdo->prepare("SELECT seat_code FROM reservation_seats WHERE reservation_id IN ($placeholders)");
    $stmt->execute($reservationIds);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $code) {
        if (isVipSeat($code)) throw new InvalidArgumentException('VIP seats cannot be approved. Reject the affected reservation and select available seats.');
    }
}

function approveReservationBatch(PDO $pdo, int $adminId, mixed $input): int
{
    if (!is_array($input) || !$input) throw new InvalidArgumentException('Select at least one pending booking.');
    $ids = [];
    foreach ($input as $value) {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$id) throw new InvalidArgumentException('Choose valid pending bookings.');
        $ids[] = $id;
    }
    $ids = array_values(array_unique($ids));
    sort($ids, SORT_NUMERIC);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $pdo->beginTransaction();
    try {
        // Use the same lock order as combined seat reservations.
        $pdo->query('SELECT id FROM performances ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN);
        $stmt = $pdo->prepare("SELECT id, booking_group_key, approval_status,
            created_at <= DATE_SUB(NOW(), INTERVAL 12 HOUR) AS expired
            FROM reservations WHERE id IN ($placeholders) ORDER BY id FOR UPDATE");
        $stmt->execute($ids);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== count($ids)) {
            throw new InvalidArgumentException('A selected request was removed or expired. Review the latest pending bookings.');
        }
        $groupKeys = [];
        foreach ($rows as $row) {
            if ($row['approval_status'] !== 'pending' || $row['expired']) {
                throw new InvalidArgumentException('A selected request changed or expired. Review the latest pending bookings.');
            }
            if ($row['booking_group_key']) $groupKeys[] = $row['booking_group_key'];
        }
        $groupKeys = array_values(array_unique($groupKeys));
        if ($groupKeys) {
            $groupPlaceholders = implode(',', array_fill(0, count($groupKeys), '?'));
            $stmt = $pdo->prepare("SELECT id FROM reservations WHERE booking_group_key IN ($groupPlaceholders)
                AND approval_status = 'pending' ORDER BY id FOR UPDATE");
            $stmt->execute($groupKeys);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $siblingId) {
                if (!in_array((int) $siblingId, $ids, true)) {
                    throw new InvalidArgumentException('A combined booking changed. Select its full pending booking again.');
                }
            }
        }
        preventVipApproval($pdo, $ids);
        $stmt = $pdo->prepare("UPDATE reservations SET approval_status = 'approved', reserved_by_admin_id = ?
            WHERE id IN ($placeholders)");
        $stmt->execute(array_merge([$adminId], $ids));
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    return count($ids);
}

function reservationCalendarSnapshot(PDO $pdo, string $area, bool $includeHtml = true): array
{
    $calendar = [];
    $pdo->beginTransaction();
    try {
        foreach (PERFORMANCE_DATES as $day => $label) $calendar[$day] = reservationSnapshot($pdo, performanceId($pdo, $day));
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    $revision = hash('sha256', json_encode(array_column($calendar, 'revision'), JSON_THROW_ON_ERROR));
    if ($includeHtml) {
        $allDates = array_merge(...array_column($calendar, 'reservations'));
        foreach ($calendar as $day => &$snapshot) $snapshot['reservations_html'] = renderReservationData($snapshot['reservations'], $day, $area, $allDates);
    }
    unset($snapshot);
    return ['calendar' => $calendar, 'revision' => $revision];
}

function unavailableCalendarSeats(array $calendar, array $basket): array
{
    $unavailable = [];
    foreach ($basket as $day => $codes) {
        foreach ($codes as $code) if (isset($calendar[$day]['seats']->$code)) $unavailable[] = $day . '|' . $code;
    }
    return $unavailable;
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
            r.reserved_by_admin_id, r.created_at, r.booking_group_key, p.performance_date, a.name AS reserved_by_name,
            CASE WHEN r.approval_status = \'pending\' THEN DATE_ADD(r.created_at, INTERVAL 12 HOUR) ELSE NULL END AS expires_at
            FROM reservations r JOIN performances p ON p.id = r.performance_id
            LEFT JOIN admins a ON a.id = r.reserved_by_admin_id
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

function renderReservationData(array $allReservations, string $selectedDay, string $selectedArea = 'theatre', array $calendarReservations = []): string
{
    $approvedReservations = array_filter($allReservations, fn($row) => $row['approval_status'] === 'approved');
    $pendingReservations = array_filter($allReservations, fn($row) => $row['approval_status'] === 'pending');
    $pendingBookingGroups = pendingBookingGroups($calendarReservations ?: $allReservations);
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
            preventVipApproval($pdo, [(int) $id]);
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
