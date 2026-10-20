<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/reservations.php';
header('Cache-Control: no-store, private');

if (isset($_GET['logout'])) {
    logoutAdmin($pdo);
    header('Location: login.php');
    exit;
}

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$jsonRequest = $isPost && wantsJsonResponse();
if ($jsonRequest) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
}
$selectedDay = $isPost ? ($_POST['day'] ?? null) : ($_GET['day'] ?? '2026-11-06');
if (!is_string($selectedDay) || !isset(PERFORMANCE_DATES[$selectedDay])
    || ($isPost && isset($_GET['day']) && $_GET['day'] !== $selectedDay)) {
    http_response_code(400);
    exit($jsonRequest ? json_encode(['error' => 'Choose Friday 6/11 or Saturday 7/11, 2026.']) : 'Choose Friday 6/11 or Saturday 7/11, 2026.');
}
$selectedArea = $_POST['area'] ?? $_GET['area'] ?? 'theatre';
if (!is_string($selectedArea) || !isset(SEATING_AREAS[$selectedArea])
    || ($isPost && isset($_GET['area']) && $_GET['area'] !== $selectedArea)) {
    http_response_code(400);
    exit($jsonRequest ? json_encode(['error' => 'Choose Main Theatre or Balcony.']) : 'Choose Main Theatre or Balcony.');
}
$selectedTab = $_GET['tab'] ?? 'map';
if (!is_string($selectedTab) || !in_array($selectedTab, ['map', 'reservations'], true)) {
    $selectedTab = 'map';
}
$performanceId = performanceId($pdo, $selectedDay);
expireReservations($pdo);
$error = '';
if ($isPost) {
    try {
        if (($_POST['action'] ?? '') === 'reserve') {
            $basket = inputSeatBasket($_POST);
            $fields = $_POST;
            $fields['seat_codes'] = implode(',', reset($basket));
            reserveSeatBasket($pdo, $basket, reservationInput($fields, true), (int) $admin['id']);
            $result = 'success';
        } elseif (($_POST['action'] ?? '') === 'approve_batch') {
            approveReservationBatch($pdo, (int) $admin['id'], $_POST['reservation_ids'] ?? []);
            $result = 'approved';
        } else {
            $result = performReservationAction($pdo, $performanceId, (int) $admin['id'], $_POST);
        }
        $tab = $result === 'success' ? 'map' : 'reservations';
        $redirect = 'admoonzz?day=' . $selectedDay . '&area=' . $selectedArea . '&tab=' . $tab . '&' . $result . '=1';
        if ($jsonRequest) {
            echo json_encode(['ok' => true, 'redirect' => $redirect]);
            exit;
        }
        header('Location: ' . $redirect);
        exit;
    } catch (SeatConflictException $e) {
        http_response_code(409);
        $error = $e->getMessage();
        if ($jsonRequest) {
            echo json_encode(['error' => $error, 'unavailable_seats' => $e->seatCodes]);
            exit;
        }
    } catch (InvalidArgumentException $e) {
        http_response_code(400);
        $error = $e->getMessage();
        if ($jsonRequest) {
            echo json_encode(['error' => $error]);
            exit;
        }
    } catch (Throwable $e) {
        http_response_code(500);
        error_log('Reservation action failed: ' . $e->getMessage());
        $error = 'Could not save the reservation. Please try again.';
        if ($jsonRequest) {
            echo json_encode(['error' => $error]);
            exit;
        }
    }
}
$calendarSnapshot = reservationCalendarSnapshot($pdo, $selectedArea);
$snapshot = $calendarSnapshot['calendar'][$selectedDay];
$allReservations = $snapshot['reservations'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ثورة الموتى - Bi Angelos Theatre</title>
    <link rel="icon" type="image/jpeg" href="logo.jpg">
    <link rel="stylesheet" href="mobile-inputs.css?v=<?php echo filemtime(__DIR__ . '/mobile-inputs.css'); ?>">
    <link rel="stylesheet" href="scrollbars.css?v=<?php echo filemtime(__DIR__ . '/scrollbars.css'); ?>">
    <link rel="stylesheet" href="styles.css?v=<?php echo filemtime(__DIR__ . '/styles.css'); ?>">
</head>
<body>
    <div class="header">
        <span class="header-admin" aria-label="Signed-in admin"><?php echo htmlspecialchars($admin['name'], ENT_QUOTES, 'UTF-8'); ?></span>
        <img src="logo.jpg" alt="Bi Angelos Theatre" class="logo">
        <h1 dir="rtl">نظام حجز مسرحية ثورة الموتى</h1>
        <p class="subtitle">إختار الكرسي اللي تحب تتفرج من عليه</p>
    </div>

    <div class="container">
        <?php if ($error): ?>
            <div class="error-message" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <div class="booking-controls">
            <div class="control-field">
                <span class="control-label" id="performanceDateLabel">Date</span>
                <nav class="day-selector" aria-labelledby="performanceDateLabel">
            <?php foreach (PERFORMANCE_DATES as $date => $label): ?>
                <a href="?day=<?php echo $date; ?>&amp;area=<?php echo $selectedArea; ?>&amp;tab=<?php echo $selectedTab; ?>"
                   class="<?php echo $selectedDay === $date ? 'active' : ''; ?>"
                   <?php if ($selectedDay === $date): ?>aria-current="date"<?php endif; ?>><?php echo $label; ?></a>
            <?php endforeach; ?>
        </nav>
            </div>
            <div class="control-field">
                <span class="control-label" id="seatingAreaLabel">Seating area</span>
                <nav class="day-selector area-selector" aria-labelledby="seatingAreaLabel">
            <?php foreach (SEATING_AREAS as $area => $label): ?>
                <a href="?day=<?php echo $selectedDay; ?>&amp;area=<?php echo $area; ?>&amp;tab=<?php echo $selectedTab; ?>"
                   data-area="<?php echo $area; ?>"
                   class="<?php echo $selectedArea === $area ? 'active' : ''; ?>"
                   <?php if ($selectedArea === $area): ?>aria-current="true"<?php endif; ?>><?php echo $label; ?></a>
            <?php endforeach; ?>
        </nav>
            </div>
            <div class="control-field control-view">
                <span class="control-label" id="viewLabel">View</span>
                <nav class="tab-navigation" aria-labelledby="viewLabel">
    <a href="?day=<?php echo $selectedDay; ?>&amp;area=<?php echo $selectedArea; ?>&amp;tab=map" class="tab-button <?php echo $selectedTab === 'map' ? 'active' : ''; ?>" <?php if ($selectedTab === 'map'): ?>aria-current="page"<?php endif; ?>><span>Seat Map</span></a>

        <a href="?day=<?php echo $selectedDay; ?>&amp;area=<?php echo $selectedArea; ?>&amp;tab=reservations" class="tab-button <?php echo $selectedTab === 'reservations' ? 'active' : ''; ?>" <?php if ($selectedTab === 'reservations'): ?>aria-current="page"<?php endif; ?>><span>Reservations Data</span></a>

</nav>
            </div>
        </div>
        <p id="liveNotice" class="live-notice" role="status" hidden></p>


        <?php if (isset($_GET['success'])): ?>
            <div class="success-message">

                    Reservation completed successfully!

            </div>
        <?php endif; ?>

        <?php if (isset($_GET['deleted'])): ?>
            <div class="success-message delete-message">
                Reservation deleted successfully!
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['updated'])): ?>
            <div class="success-message update-message">
                Reservation updated successfully!
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['approved'])): ?>
            <div class="success-message">
                Selected reservations approved successfully!
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['rejected'])): ?>
            <div class="success-message delete-message">
                Reservation rejected and removed!
            </div>
        <?php endif; ?>




        <!-- Theatre Map Tab -->
        <div class="tab-content <?php echo $selectedTab === 'map' ? 'active' : ''; ?>">
            <div class="legend">
                <div class="legend-items">
                    <div class="legend-item">
                        <span class="legend-box available"></span>
                        <span>Available</span>
                    </div>
                    <div class="legend-item">
                        <span class="legend-box reserved"></span>
                        <span>Reserved</span>
                    </div>
                    <div class="legend-item">
                        <span class="legend-box reserved-unpaid" style="background-color: #08a4a7;"></span>
                        <span>Reserved (Unpaid)</span>
                    </div>
                    <div class="legend-item">
                        <span class="legend-box pending"></span>
                        <span>Pending Approval</span>
                    </div>
                    <div class="legend-item">
                        <span class="legend-box sound-control"></span>
                        <span>Sound Control</span>
                    </div>
                    <div class="legend-item">
                        <span class="legend-box blocked" style="background-color: #000000;"></span>
                        <span>Blocked</span>
                    </div>
                    <div class="legend-item">
                        <span class="legend-box vip"></span>
                        <span>VIP (unavailable)</span>
                    </div>
                    <div class="legend-item">
                        <span class="legend-box selected"></span>
                        <span>Selected</span>
                    </div>
                </div>
            </div>
            
            <?php require __DIR__ . '/seat_maps.php'; ?>

            <!-- Reservation Action Button -->
            <div class="reservation-action" id="reservationAction">
                <button class="reserve-btn" onclick="openReservationModal()">
                    Reserve Seats
                    <span class="selected-count" id="selectedCount">0</span>
                </button>
            </div>
        </div>

        <!-- Reservations Data Tab -->
        <div class="tab-content reservation-data-view <?php echo $selectedTab === 'reservations' ? 'active' : ''; ?>">
            <div class="reservation-search">
                <div class="reservation-search-field">
                    <label for="reservationSearch">Search reservations</label>
                    <input type="search" id="reservationSearch" placeholder="Search by name or phone number" autocomplete="off" autocapitalize="off" spellcheck="false" aria-describedby="reservationSearchHelp reservationSearchStatus">
                </div>
                <button type="button" id="clearReservationSearch" disabled>Clear search</button>
                <p id="reservationSearchHelp">Search pending and confirmed reservations across both dates.</p>
                <p id="reservationSearchStatus" role="status" aria-live="polite"></p>
            </div>
            <div id="reservationDataPanel"><?php echo $snapshot['reservations_html']; ?></div>
        </div>
    </div>

    <!-- Seat Info Modal (Small) -->
    <div id="seatInfoModal" class="seat-info-modal">
        <div class="seat-info-content" id="seatInfoContent">
            <h3>Seat: <span id="seatInfoSeatId"></span></h3>
            <p id="seatInfoStatus"></p>
            <p id="seatInfoOwnerLabel">Reserved by:</p>
            <p class="customer-name" id="seatInfoCustomerName"></p>
            <p class="remaining-info" id="seatInfoRemaining" style="display: none;"></p>
            <button class="close-seat-info" onclick="closeSeatInfo()">Close</button>
        </div>
    </div>

    <!-- Reservation Modal -->
    <div id="reservationModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="reservationTitle">
        <div class="modal-content">
            <span class="close-modal" onclick="closeReservationModal()">&times;</span>
            <div id="reservationEditor">
            <div class="modal-header">
                <h2 id="reservationTitle">Complete Your Reservation</h2>
                <p id="reservationLocation" class="modal-location"><?php echo PERFORMANCE_DATES[$selectedDay]; ?></p>

            </div>
            
            <form method="POST" action="" id="reservationForm">
                <div id="reservationFormError" class="live-notice" role="alert" hidden></div>
                <input type="hidden" name="action" value="reserve">
                <input type="hidden" name="day" value="<?php echo $selectedDay; ?>">
                <input type="hidden" name="area" value="<?php echo $selectedArea; ?>">
                <input type="hidden" name="seat_codes" id="selectedSeatsInput">
                <input type="hidden" name="seat_basket" id="seatBasketInput">
                
                <div class="selected-seats-display">
                    <h3>Selected Seats:</h3>
                    <div class="selected-seats-list" id="selectedSeatsList"></div>
                </div>

                <div class="form-group">
                    <label for="customer_name">Customer Name *</label>
                    <input type="text" id="customer_name" name="customer_name" required>
                </div>

                <div class="form-group">
                    <label for="phone_number">Phone Number *</label>
                    <input type="tel" id="phone_number" name="customer_phone" required>
                </div>

                <div class="checkbox-group">
    <input type="checkbox" id="unpaidCheckbox" name="is_unpaid" value="1" onchange="toggleRemainingInput()">
    <label for="unpaidCheckbox">Unpaid Reservation (Has Remaining Amount)</label>
</div>

<div class="checkbox-group">
    <input type="checkbox" id="receivedTicketCheckbox" name="tickets_received" value="1">
    <label for="receivedTicketCheckbox">Received Ticket</label>
</div>

<div class="checkbox-group khedma-checkbox-group">
    <input type="checkbox" id="khedmaCheckbox" name="has_service_discount" value="1" onchange="updateNewPriceInfo()">
    <label for="khedmaCheckbox">Khedma (<?php echo TICKET_PRICES['khedma']; ?> EGP / theatre ticket)</label>
</div>

<div class="khedma-price-info" id="newPriceInfo" style="display:none;"></div>

                <div class="form-group remaining-input" id="remainingGroup">
                    <label for="remaining">Remaining Amount (EGP) *</label>
                    <input type="number" id="remaining" name="remaining_amount" min="0" step="0.01" value="0">
                </div>

                <input type="hidden" name="is_paid" id="isPaidInput" value="1">



                <button type="submit" class="submit-btn">
                    Confirm Reservation
                </button>
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

    <!-- Edit Reservation Modal -->

    <div id="editModal" class="modal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeEditModal()">&times;</span>
            <div class="modal-header">
                <h2>Edit Reservation</h2>
            </div>
            
            <form method="POST" action="" id="editForm">
                <div id="editLiveNotice" class="live-notice" role="alert" hidden></div>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="day" value="<?php echo $selectedDay; ?>">
                <input type="hidden" name="area" value="<?php echo $selectedArea; ?>">
                <input type="hidden" name="id" id="editId">
                
                <div class="form-group">
                    <label for="edit_customer_name">Customer Name *</label>
                    <input type="text" id="edit_customer_name" name="customer_name" required>
                </div>

                <div class="form-group">
                    <label for="edit_phone_number">Phone Number *</label>
                    <input type="tel" id="edit_phone_number" name="customer_phone" required>
                </div>

                <div class="form-group">
                    <label for="edit_reserved_desks">Reserved Seats *</label>
                    <textarea id="edit_reserved_desks" name="seat_codes" required oninput="updateEditPriceInfo(false)"></textarea>
                    <small>Main Theatre: AR1. Balcony: B-AR1. Keep the B- prefix for balcony seats.</small>
                </div>

                <div class="form-group">
    <label for="edit_remaining">Remaining Amount (EGP) *</label>
    <input type="number" id="edit_remaining" name="remaining_amount" min="0" step="0.01" required>
</div>

<div class="checkbox-group">
    <input type="checkbox" id="edit_received_ticket" name="tickets_received" value="1">
    <label for="edit_received_ticket">Received Ticket</label>
</div>

<div class="checkbox-group khedma-checkbox-group">
    <input type="checkbox" id="edit_khedmaCheckbox" name="has_service_discount" value="1" onchange="updateEditPriceInfo()">
    <label for="edit_khedmaCheckbox">Khedma (<?php echo TICKET_PRICES['khedma']; ?> EGP / theatre ticket)</label>
</div>

<div class="khedma-price-info" id="editPriceInfo" style="display:none;"></div>

<button type="submit" class="submit-btn">Update Reservation</button>
            </form>
        </div>
    </div>


    <!-- Delete Confirmation Form -->

    <form method="POST" action="" id="deleteForm" style="display: none;">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="day" value="<?php echo $selectedDay; ?>">
        <input type="hidden" name="area" value="<?php echo $selectedArea; ?>">
        <input type="hidden" name="id" id="deleteId">
    </form>


    <script id="theatre-state" type="application/json"><?php echo json_encode(['day' => $selectedDay, 'area' => $selectedArea, 'day_label' => PERFORMANCE_DATES[$selectedDay], 'days' => PERFORMANCE_DATES, 'prices' => TICKET_PRICES] + $calendarSnapshot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR); ?></script>
    <script src="app.js?v=<?php echo filemtime(__DIR__ . '/app.js'); ?>"></script>
</body>
</html>
