<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/reservations.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

try {
    $admin = currentAdmin($pdo);
    if (($_GET['auth'] ?? '') === '1') {
        echo json_encode(['authenticated' => $admin !== null]);
        exit;
    }
    if (!$admin) {
        http_response_code(401);
        echo json_encode(['authenticated' => false]);
        exit;
    }
    $day = $_GET['day'] ?? null;
    if (!is_string($day)) {
        throw new InvalidArgumentException('Choose a valid performance date.');
    }
    $performance = performanceId($pdo, $day);
    $area = $_GET['area'] ?? 'theatre';
    if (!is_string($area) || !isset(SEATING_AREAS[$area])) {
        throw new InvalidArgumentException('Choose Main Theatre or Balcony.');
    }
    $checkedSeats = isset($_GET['seats']) ? parseSeatCodes(is_string($_GET['seats']) ? $_GET['seats'] : '') : null;
    expireReservations($pdo);
    $snapshot = reservationSnapshot($pdo, $performance);
    $changed = !is_string($_GET['revision'] ?? null) || $_GET['revision'] !== $snapshot['revision'];
    $response = ['authenticated' => true, 'day' => $day, 'area' => $area, 'revision' => $snapshot['revision'], 'changed' => $changed];
    if ($changed) {
        $response['reservations'] = $snapshot['reservations'];
        $response['seats'] = $snapshot['seats'];
        $response['reservations_html'] = renderReservationData($snapshot['reservations'], $day, $area);
    }
    if ($checkedSeats !== null) {
        $response['unavailable_seats'] = array_values(array_filter($checkedSeats, fn($code) => isset($snapshot['seats']->$code)));
    }
    echo json_encode($response, JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException $error) {
    http_response_code(400);
    echo json_encode(['error' => $error->getMessage()]);
} catch (Throwable $error) {
    http_response_code(500);
    error_log('Live updates failed: ' . $error->getMessage());
    echo json_encode(['error' => 'Could not refresh reservations. Please try again.']);
}
