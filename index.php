<?php
require_once __DIR__ . '/public_booking.php';
define('PUBLIC_BOOKING_PAGE', true);
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($isPost) header('Content-Type: application/json; charset=utf-8');
try {
    $browserToken = visitorToken(!$isPost);
    $selectedDay = $isPost ? ($_POST['day'] ?? '') : ($_GET['day'] ?? '2026-11-06');
    $selectedArea = $isPost ? ($_POST['area'] ?? 'theatre') : ($_GET['area'] ?? 'theatre');
    if (!is_string($selectedDay) || !isset(PERFORMANCE_DATES[$selectedDay])
        || !is_string($selectedArea) || !isset(SEATING_AREAS[$selectedArea])
        || ($isPost && isset($_GET['day']) && $_GET['day'] !== $selectedDay)
        || ($isPost && isset($_GET['area']) && $_GET['area'] !== $selectedArea)) {
        throw new InvalidArgumentException('Choose a valid date and seating area.');
    }
    $performanceId = performanceId($pdo, $selectedDay);
    expireReservations($pdo);
    if ($isPost) {
        if (($_POST['action'] ?? '') !== 'request') throw new InvalidArgumentException('Invalid booking request.');
        submitPublicBooking($pdo, $performanceId, $browserToken, $_POST);
        echo json_encode(['ok' => true, 'redirect' => './?day=' . $selectedDay . '&area=' . $selectedArea . '&requested=1']);
        exit;
    }
    $snapshot = publicBookingSnapshot($pdo, $performanceId, $browserToken);
} catch (Throwable $error) {
    $status = $error instanceof SeatConflictException ? 409 : ($error instanceof InvalidArgumentException ? 400 : 500);
    http_response_code($status);
    $message = $status === 500 ? 'Could not complete your request. Please try again.' : $error->getMessage();
    if ($status === 500) error_log('Public booking failed: ' . $error->getMessage());
    if ($isPost) {
        $response = ['error' => $message];
        if ($error instanceof SeatConflictException) $response['unavailable_seats'] = $error->seatCodes;
        echo json_encode($response);
    } else {
        echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    }
    exit;
}
$publicView = true;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reserve Your Seats - Bi Angelos Theatre</title>
    <link rel="icon" type="image/jpeg" href="logo.jpg">
    <link rel="stylesheet" href="styles.css?v=<?php echo filemtime(__DIR__ . '/styles.css'); ?>">
</head>
<body class="public-booking">
    <div class="header">
        <img src="logo.jpg" alt="Bi Angelos Theatre" class="logo">
        <h1 dir="rtl">نظام حجز مسرحية ثورة الموتى</h1>
        <p class="subtitle">إختار الكرسي اللي تحب تتفرج من عليه</p>
    </div>


    <main class="container">
        <div class="booking-controls public-controls">
            <div class="control-field">
                <span class="control-label" id="performanceDateLabel">Date</span>
                <nav class="day-selector" aria-labelledby="performanceDateLabel">
                    <?php foreach (PERFORMANCE_DATES as $date => $label): ?>
                        <a href="?day=<?php echo $date; ?>&amp;area=<?php echo $selectedArea; ?>"
                           class="<?php echo $selectedDay === $date ? 'active' : ''; ?>"
                           <?php if ($selectedDay === $date): ?>aria-current="date"<?php endif; ?>><?php echo $label; ?></a>
                    <?php endforeach; ?>
                </nav>
            </div>
            <div class="control-field">
                <span class="control-label" id="seatingAreaLabel">Seating area</span>
                <nav class="day-selector area-selector" aria-labelledby="seatingAreaLabel">
                    <?php foreach (SEATING_AREAS as $area => $label): ?>
                        <a href="?day=<?php echo $selectedDay; ?>&amp;area=<?php echo $area; ?>" data-area="<?php echo $area; ?>"
                           class="<?php echo $selectedArea === $area ? 'active' : ''; ?>"
                           <?php if ($selectedArea === $area): ?>aria-current="true"<?php endif; ?>><?php echo $label; ?></a>
                    <?php endforeach; ?>
                </nav>
            </div>
        </div>
        <p id="liveNotice" class="live-notice" role="status" hidden></p>
        <?php if (isset($_GET['requested'])): ?>
            <div class="success-message" role="status">Your request was submitted. Follow your seats on the map for their latest status. Send your payment screenshot and booking name to <a href="<?php echo BOOKING_WHATSAPP_URL; ?>" target="_blank" rel="noopener noreferrer"><?php echo BOOKING_WHATSAPP; ?></a> on WhatsApp. Requests stay pending until approved and expire after 12 hours if unapproved.</div>
        <?php endif; ?>
        <div class="legend">
            <div class="legend-items">
                <div class="legend-item"><span class="legend-box available"></span><span>Available</span></div>
                <div class="legend-item"><span class="legend-box reserved"></span><span>Reserved</span></div>
                <div class="legend-item"><span class="legend-box pending"></span><span>Pending confirmation</span></div>
                <div class="legend-item"><span class="legend-box selected"></span><span>Selected</span></div>
                <div class="legend-item"><span class="legend-box own-seat"></span><span>Your seats</span></div>
                <div class="legend-item"><span class="legend-box blocked" style="background:#000"></span><span>Unavailable</span></div>
            </div>
        </div>
        <?php require __DIR__ . '/seat_maps.php'; ?>
        <div class="reservation-action" id="reservationAction">
            <button class="reserve-btn" onclick="openReservationModal()">Reserve Seats <span class="selected-count" id="selectedCount">0</span></button>
        </div>
    </main>

    <div id="seatInfoModal" class="seat-info-modal" role="dialog" aria-modal="true" aria-label="Seat information">
        <div class="seat-info-content" id="seatInfoContent">
            <h3>Seat: <span id="seatInfoSeatId"></span></h3>
            <p id="seatInfoStatus"></p>
            <p id="seatInfoOwnerLabel">Your reservation:</p>
            <p class="customer-name" id="seatInfoCustomerName"></p>
            <p class="remaining-info" id="seatInfoRemaining" hidden></p>
            <button class="close-seat-info" onclick="closeSeatInfo()">Close</button>
        </div>
    </div>

    <div id="reservationModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="reservationTitle">
        <div class="modal-content">
            <button type="button" class="close-modal" aria-label="Close reservation" onclick="closeReservationModal()">&times;</button>
            <div id="reservationEditor">
                <div class="modal-header">
                    <h2 id="reservationTitle">Reserve your seats</h2>
                    <p id="reservationLocation" class="modal-location"><?php echo PERFORMANCE_DATES[$selectedDay]; ?></p>
                </div>
                <ol class="booking-steps" aria-label="Reservation steps">
                    <li data-step-indicator="1" aria-current="step">1. Your details</li>
                    <li data-step-indicator="2">2. Payment</li>
                    <li data-step-indicator="3">3. Confirm</li>
                </ol>
                <form method="POST" action="./" id="reservationForm" novalidate>
                    <div id="reservationFormError" class="live-notice" role="alert" hidden></div>
                    <input type="hidden" name="action" value="request">
                    <input type="hidden" name="day" value="<?php echo $selectedDay; ?>">
                    <input type="hidden" name="area" value="<?php echo $selectedArea; ?>">
                    <input type="hidden" name="seat_codes" id="selectedSeatsInput">
                    <input type="hidden" name="csrf_token" value="<?php echo hash_hmac('sha256', 'submit-booking', $browserToken); ?>">
                    <input type="hidden" name="request_key" value="<?php echo bin2hex(random_bytes(16)); ?>">
                    <input type="hidden" name="payment_acknowledged" id="paymentAcknowledged" value="0">
                    <div class="selected-seats-display">
                        <h3>Selected Seats:</h3><div class="selected-seats-list" id="selectedSeatsList"></div>
                    </div>
                    <div class="khedma-price-info" id="newPriceInfo"></div>
                    <section data-booking-step="1">
                        <div class="form-group">
                            <label for="customer_name">Name *</label>
                            <input type="text" id="customer_name" name="customer_name" maxlength="100" autocomplete="name" required>
                        </div>
                        <div class="form-group">
                            <label for="phone_number">Phone number *</label>
                            <input type="tel" id="phone_number" name="customer_phone" maxlength="20" autocomplete="tel" required>
                        </div>
                        <p class="booking-help">Seats are held only after the final confirmation. Another person can request them while you complete these steps.</p>
                        <button type="button" class="submit-btn continue-btn" data-step-forward="2">Continue</button>
                    </section>
                    <section data-booking-step="2" hidden>
                        <?php require __DIR__ . '/payment_instructions.php'; ?>
                        <button type="button" class="submit-btn continue-btn" data-step-forward="3">I paid</button>
                        <button type="button" class="booking-back" data-step-back="1">Previous step</button>
                    </section>
                    <section data-booking-step="3" hidden>
                        <div class="confirmation-summary">
                            <h3>Confirm your payment and reservation</h3>
                            <p id="paymentCustomerSummary"></p>
                            <p>By confirming, you state that you paid the total above through Instapay and will send your payment screenshot on WhatsApp. Your seats will remain pending until an admin verifies the payment.</p>
                        </div>
                        <?php require __DIR__ . '/payment_instructions.php'; ?>
                        <button type="submit" class="submit-btn">Confirm reservation</button>
                        <button type="button" class="booking-back" data-step-back="2">Previous step</button>
                    </section>
                </form>
            </div>
            <div id="reservationConflict" class="availability-notice" role="alert" hidden>
                <div class="availability-icon" aria-hidden="true">&#9432;</div>
                <h2 id="reservationConflictTitle">Seat availability changed</h2>
                <p id="reservationConflictMessage"></p>
                <button type="button" id="reservationConflictClose" class="submit-btn" onclick="closeReservationModal()">Close</button>
            </div>
        </div>
    </div>
    <script id="theatre-state" type="application/json"><?php echo json_encode(['public' => true, 'day' => $selectedDay, 'area' => $selectedArea, 'day_label' => PERFORMANCE_DATES[$selectedDay], 'prices' => TICKET_PRICES, 'live_url' => 'public_live.php', 'submit_url' => './', 'whatsapp_url' => BOOKING_WHATSAPP_URL] + $snapshot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR); ?></script>
    <script src="app.js?v=<?php echo filemtime(__DIR__ . '/app.js'); ?>"></script>
</body>
</html>
