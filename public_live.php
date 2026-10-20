<?php
require_once __DIR__ . '/public_booking.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
try {
    $token = visitorToken();
    $day = $_GET['day'] ?? '';
    $area = $_GET['area'] ?? 'theatre';
    if (!is_string($day) || !is_string($area) || !isset(SEATING_AREAS[$area])) {
        throw new InvalidArgumentException('Choose a valid date and seating area.');
    }
    $performance = performanceId($pdo, $day);
    $codes = isset($_GET['seats']) ? parseSeatCodes(is_string($_GET['seats']) ? $_GET['seats'] : '') : null;
    expireReservations($pdo);
    $holdKey = isset($_GET['hold_key']) ? validateSeatHoldKey($_GET['hold_key']) : null;
    $snapshot = publicCalendarSnapshot($pdo, $token, $holdKey);
    $changed = !is_string($_GET['revision'] ?? null) || $_GET['revision'] !== $snapshot['revision'];
    $response = ['day' => $day, 'area' => $area, 'revision' => $snapshot['revision'], 'changed' => $changed];
    if ($changed) {
        $response['calendar'] = $snapshot['calendar'];
        $response['seats'] = $snapshot['calendar'][$day]['seats'];
    }
    if ($codes !== null) {
        $response['unavailable_seats'] = array_values(array_filter($codes, function ($code) use ($snapshot, $day) {
            $seat = $snapshot['calendar'][$day]['seats']->$code ?? null;
            return $seat && empty($seat['hold_mine']);
        }));
    }
    echo json_encode($response, JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException $error) {
    http_response_code(400);
    echo json_encode(['error' => $error->getMessage()]);
} catch (Throwable $error) {
    http_response_code(500);
    error_log('Public availability failed: ' . $error->getMessage());
    echo json_encode(['error' => 'Could not refresh availability. Please try again.']);
}
