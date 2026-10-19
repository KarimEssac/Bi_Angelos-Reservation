<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/reservations.php';

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
expireUnpaidReservations($pdo);
$error = '';
if ($isPost) {
    try {
        $result = performReservationAction($pdo, $performanceId, (int) $admin['id'], $_POST);
        $tab = $result === 'success' ? 'map' : 'reservations';
        $redirect = '?day=' . $selectedDay . '&area=' . $selectedArea . '&tab=' . $tab . '&' . $result . '=1';
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
$snapshot = reservationSnapshot($pdo, $performanceId);
$allReservations = $snapshot['reservations'];
$pendingReservations = array_filter($allReservations, fn($res) => $res['approval_status'] === 'pending');
$approvedReservations = array_filter($allReservations, fn($res) => $res['approval_status'] === 'approved');
$reservedSeats = [];
$pendingSeats = [];
foreach ($allReservations as $reservation) {
    foreach (explode(',', $reservation['seat_codes']) as $seat) {
        if ($reservation['approval_status'] === 'pending') {
            $pendingSeats[] = $seat;
        } else {
            $reservedSeats[] = $seat;
        }
    }
}
$rightSide = theatreSides()['right'];
$leftSide = theatreSides()['left'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ثورة الموتى - Bi Angelos Theatre</title>
    <link rel="icon" type="image/jpeg" href="logo.jpg">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #A1CAE3 0%, #FFFEFF 100%);
            min-height: 100vh;
            padding: 10px;
        }
        
        .header {
            text-align: center;
            padding: 12px 10px;
            background-color: #FFFEFF;
            border-radius: 15px;
            margin: 0 auto 15px;
            box-shadow: 0 4px 15px rgba(32, 127, 189, 0.2);
            max-width: 1400px;
            position: relative;
        }

        .header-admin {
            position: absolute;
            top: 15px;
            right: 20px;
            padding: 8px 14px;
            border-radius: 20px;
            background-color: #eaf4fa;
            color: #207FBD;
            font-size: 13px;
            font-weight: 600;
            max-width: calc(100% - 40px);
            overflow-wrap: anywhere;
        }
        
        .logo {
            max-width: 150px;
            height: auto;
            margin-bottom: 8px;
        }
        
        h1 {
            color: #207FBD;
            font-size: clamp(18px, 4vw, 26px);
            margin-bottom: 5px;
        }
        
        .subtitle {
            color: #FC723E;
            font-size: clamp(12px, 2.5vw, 16px);
            font-weight: 500;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background-color: #FFFEFF;
            padding: 15px;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }
        
        .booking-controls {
            display: grid;
            grid-template-columns: 1fr 1fr 1.15fr;
            gap: 22px;
            align-items: end;
            padding: 18px 20px;
            margin-bottom: 24px;
            background: #f8fbfd;
            border: 1px solid #dce7ef;
            border-radius: 12px;
        }

        .control-field { min-width: 0; }
        .control-field + .control-field {
            padding-left: 22px;
            border-left: 1px solid #dce7ef;
        }
        .control-label {
            display: block;
            margin-bottom: 9px;
            color: #536a7b;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.04em;
        }

        .day-selector {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 4px;
            padding: 4px;
            background: #eaf1f6;
            border-radius: 9px;
        }
        .day-selector a {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 40px;
            padding: 8px 10px;
            border: 1px solid transparent;
            border-radius: 6px;
            color: #536a7b;
            font-size: 14px;
            font-weight: 600;
            line-height: 1.3;
            text-align: center;
            text-decoration: none;
            transition: background-color 0.15s, color 0.15s;
        }
        .day-selector a.active {
            background: #207FBD;
            color: white;
            border-color: #207FBD;
        }
        .day-selector a:not(.active):hover { background: #dce9f2; color: #185f8e; }
        .booking-controls a:focus-visible { outline: 3px solid #FC723E; outline-offset: 3px; }

        .tab-navigation {
            display: grid;
            grid-template-columns: 1fr 1.4fr;
            gap: 12px;
            min-height: 48px;
            border-bottom: 1px solid #cddde8;
        }
        .tab-button {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 10px 3px;
            border-bottom: 3px solid transparent;
            color: #536a7b;
            font-size: 14px;
            font-weight: 600;
            text-align: center;
            text-decoration: none;
            line-height: 1.3;
            transition: color 0.15s, border-color 0.15s;
        }
        .tab-button.active { color: #207FBD; border-bottom-color: #207FBD; }
        .tab-button:hover { color: #185f8e; border-bottom-color: #A1CAE3; }

        @media (max-width: 1000px) {
            .booking-controls { grid-template-columns: 1fr 1fr; gap: 16px 20px; }
            .control-view { grid-column: 1 / -1; }
            .control-field.control-view { padding: 14px 0 0; border-left: 0; border-top: 1px solid #dce7ef; }
        }
        @media (max-width: 560px) {
            .booking-controls { grid-template-columns: 1fr; padding: 15px; gap: 16px; }
            .control-field + .control-field { padding-left: 0; border-left: 0; }
            .control-label { margin-bottom: 7px; }
        }

        .error-message {
            background: #ffebee; color: #b71c1c; padding: 15px;
            border: 1px solid #ef9a9a; border-radius: 8px; margin-bottom: 20px;
        }

        /* Tab Content */
        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Reservations Table Styles */
        .reservations-container {
            overflow-x: auto;
            margin-top: 20px;
            border-radius: 15px;
            box-shadow: 0 8px 30px rgba(32, 127, 189, 0.15);
        }

        .reservations-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            background-color: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
        }

        .reservations-table thead {
            background: linear-gradient(135deg, #207FBD 0%, #4a9ed1 50%, #5db0e0 100%);
            color: white;
            position: relative;
        }

        .reservations-table thead::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 3px;
            background: linear-gradient(90deg, #FC723E, #ff8c5a, #FC723E);
        }

        .reservations-table th {
            padding: 18px 15px;
            text-align: left;
            font-weight: bold;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
            text-shadow: 1px 1px 3px rgba(0, 0, 0, 0.2);
            position: relative;
        }

        .reservations-table th::after {
            content: '';
            position: absolute;
            right: 0;
            top: 25%;
            height: 50%;
            width: 1px;
            background: rgba(255, 255, 255, 0.3);
        }

        .reservations-table th:last-child::after {
            display: none;
        }

        .reservations-table td {
            padding: 15px;
            border-bottom: 1px solid #e8f2f7;
            color: #333;
            transition: all 0.3s ease;
            font-size: 14px;
        }

        .reservations-table tbody tr {
            transition: all 0.3s ease;
            position: relative;
        }

        .reservations-table tbody tr::after {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            width: 4px;
            height: 100%;
            background: linear-gradient(135deg, #FC723E, #ff8c5a);
            transform: scaleY(0);
            transition: transform 0.3s ease;
        }

        .reservations-table tbody tr:hover {
            background: linear-gradient(90deg, #f0f8ff 0%, #e6f3fb 100%);
            transform: scale(1.01);
            box-shadow: 0 4px 15px rgba(32, 127, 189, 0.15);
        }

        .reservations-table tbody tr:hover::after {
            transform: scaleY(1);
        }

        .reservations-table tbody tr:last-child td {
            border-bottom: none;
        }

        .reservations-table tbody tr:nth-child(even) {
            background-color: #fafcfd;
        }

        .reservations-table tbody tr.ticket-received,
        .reservations-table tbody tr.ticket-received:nth-child(even) {
            background: linear-gradient(90deg, #e8f5e9 0%, #c8e6c9 100%);
        }

        .remaining-amount {
            font-weight: bold;
            color: #FC723E;
            padding: 6px 12px;
            border-radius: 8px;
            background: linear-gradient(135deg, rgba(252, 114, 62, 0.1) 0%, rgba(252, 114, 62, 0.05) 100%);
            display: inline-block;
            border: 2px solid rgba(252, 114, 62, 0.3);
            font-size: 15px;
            transition: all 0.3s ease;
        }

        .remaining-amount:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 12px rgba(252, 114, 62, 0.3);
        }

        .remaining-paid {
            color: #4CAF50;
            background: linear-gradient(135deg, rgba(76, 175, 80, 0.15) 0%, rgba(76, 175, 80, 0.08) 100%);
            border: 2px solid rgba(76, 175, 80, 0.4);
            font-weight: bold;
        }

        .remaining-paid::before {
            content: '✓ ';
            font-size: 16px;
            margin-right: 4px;
        }

        .seat-badge {
            display: inline-block;
            background: linear-gradient(135deg, #207FBD 0%, #4a9ed1 100%);
            color: white;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 13px;
            margin: 3px;
            font-weight: 600;
            box-shadow: 0 3px 8px rgba(32, 127, 189, 0.3);
            transition: all 0.3s ease;
            border: 2px solid rgba(255, 255, 255, 0.3);
        }

        .seat-badge:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(252, 114, 62, 0.4);
            background: linear-gradient(135deg, #FC723E 0%, #ff8c5a 100%);
        }

        .seats-cell {
            max-height: 120px;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 10px;
            scrollbar-width: thin;
            scrollbar-color: #207FBD #e8f2f7;
        }

        .seats-cell::-webkit-scrollbar {
            width: 6px;
        }

        .seats-cell::-webkit-scrollbar-track {
            background: #e8f2f7;
            border-radius: 3px;
        }

        .seats-cell::-webkit-scrollbar-thumb {
            background: #207FBD;
            border-radius: 3px;
        }

        .seats-cell::-webkit-scrollbar-thumb:hover {
            background: #FC723E;
        }

        .no-reservations {
            text-align: center;
            padding: 60px 40px;
            color: #666;
            font-size: 20px;
            background: linear-gradient(135deg, #f5f9fc 0%, #e6f3fb 100%);
            border-radius: 15px;
            border: 3px dashed #A1CAE3;
            position: relative;
            overflow: hidden;
        }

        .no-reservations::before {
            content: '📋';
            display: block;
            font-size: 60px;
            margin-bottom: 15px;
            opacity: 0.5;
            animation: float 3s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
        }

        .no-reservations p {
            position: relative;
            z-index: 1;
            font-weight: 600;
            color: #207FBD;
        }

        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 35px;
            padding: 10px;
        }

        .stat-card {
            background: linear-gradient(135deg, #207FBD 0%, #4a9ed1 50%, #A1CAE3 100%);
            color: white;
            padding: 30px 25px;
            border-radius: 20px;
            text-align: center;
            box-shadow: 0 10px 30px rgba(32, 127, 189, 0.4);
            position: relative;
            overflow: hidden;
            transition: all 0.4s ease;
            border: 3px solid rgba(255, 255, 255, 0.2);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, transparent 70%);
            animation: pulse 3s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 0.5; }
            50% { transform: scale(1.1); opacity: 0.8; }
        }

        .stat-card:hover {
            transform: translateY(-8px) scale(1.03);
            box-shadow: 0 15px 40px rgba(252, 114, 62, 0.5);
            border-color: #FC723E;
        }

        .stat-card:nth-child(2) {
            background: linear-gradient(135deg, #FC723E 0%, #ff8c5a 50%, #ffa77d 100%);
        }

        .stat-card:nth-child(3) {
            background: linear-gradient(135deg, #4CAF50 0%, #66bb6a 50%, #81c784 100%);
        }

        .stat-card:nth-child(4) {
            background: linear-gradient(135deg, #9C27B0 0%, #BA68C8 50%, #CE93D8 100%);
        }

        .stat-number {
            font-size: 42px;
            font-weight: bold;
            margin-bottom: 8px;
            text-shadow: 2px 2px 8px rgba(0, 0, 0, 0.3);
            position: relative;
            z-index: 1;
            animation: countUp 0.8s ease-out;
        }

        @keyframes countUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .stat-label {
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            position: relative;
            z-index: 1;
            font-weight: 600;
            text-shadow: 1px 1px 4px rgba(0, 0, 0, 0.2);
        }

        .stat-card::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 5px;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.6), transparent);
            animation: shimmer 2s infinite;
        }

        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }

        .revenue-card {
            background: linear-gradient(135deg, #9C27B0 0%, #BA68C8 50%, #CE93D8 100%);
            grid-column: span 1;
        }

        .revenue-breakdown {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 2px solid rgba(255, 255, 255, 0.3);
            font-size: 11px;
            text-align: left;
            line-height: 1.8;
        }

        .revenue-breakdown div {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
        }

        .revenue-breakdown strong {
            font-weight: 700;
        }

        /* Collector Breakdown Card */
        .collector-card {
            background: linear-gradient(135deg, #FFFEFF 0%, #f5f9fc 100%);
            border-radius: 20px;
            padding: 25px 30px;
            margin-bottom: 30px;
            box-shadow: 0 8px 30px rgba(32, 127, 189, 0.12);
            border: 2px solid rgba(161, 202, 227, 0.5);
        }

        .collector-card-title {
            font-size: 16px;
            font-weight: 700;
            color: #207FBD;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
            padding-bottom: 12px;
            border-bottom: 2px solid #A1CAE3;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .collector-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
        }

        .collector-row {
            display: flex;
            align-items: center;
            gap: 14px;
            background: linear-gradient(135deg, #f0f8ff 0%, #e6f3fb 100%);
            border: 2px solid #A1CAE3;
            border-radius: 14px;
            padding: 14px 16px;
            transition: all 0.3s ease;
        }

        .collector-row:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(32, 127, 189, 0.2);
            border-color: #207FBD;
        }

        .collector-avatar {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: linear-gradient(135deg, #207FBD 0%, #4a9ed1 100%);
            color: white;
            font-size: 18px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            box-shadow: 0 4px 10px rgba(32, 127, 189, 0.3);
        }

        .collector-info {
            flex: 1;
            min-width: 0;
        }

        .collector-name {
            font-weight: 700;
            color: #207FBD;
            font-size: 15px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .collector-seats {
            font-size: 12px;
            color: #666;
            margin-top: 2px;
        }

        .collector-total {
            font-weight: 800;
            font-size: 15px;
            color: #FC723E;
            white-space: nowrap;
        }
        
        .legend {
            text-align: center;
            margin-bottom: 20px;
            padding: 15px;
            background: linear-gradient(135deg, #A1CAE3 0%, #FFFEFF 100%);
            border-radius: 10px;
            border: 2px solid #207FBD;
            max-width: 1050px;
            margin: 0 auto;
            margin-bottom: 20px;
        }
        
        .legend-items {
            display: flex;
            justify-content: center;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: clamp(12px, 2.5vw, 14px);
            color: #207FBD;
            font-weight: 600;
        }
        
        .legend-box {
            width: 25px;
            height: 20px;
            border: 2px solid #207FBD;
            border-radius: 3px;
        }
        
        .legend-box.available
        {
            background-color: white;
        }
        
        .legend-box.reserved {
            background-color: #4CAF50;
        }
        
        .legend-box.sound-control {
            background-color: #FC723E;
        }

        .legend-box.selected {
            background-color: #FFD700;
        }
        
        .theatre-scroll-container {
            overflow-x: auto;
            overflow-y: hidden;
            -webkit-overflow-scrolling: touch;
            padding-bottom: 10px;
            margin-bottom: 10px;
            scrollbar-width: thin;
            scrollbar-color: #207FBD #A1CAE3;
        }
        
        .theatre-scroll-container::-webkit-scrollbar {
            height: 8px;
        }
        
        .theatre-scroll-container::-webkit-scrollbar-track {
            background: #A1CAE3;
            border-radius: 4px;
        }
        
        .theatre-scroll-container::-webkit-scrollbar-thumb {
            background: #207FBD;
            border-radius: 4px;
        }
        
        .theatre-scroll-container::-webkit-scrollbar-thumb:hover {
            background: #FC723E;
        }
        
        .theatre-layout {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            row-gap: 20px;
            min-width: 1200px;
            max-width: 1050px; 
            margin: 0 auto;
        }
        
        .side {
            background-color: #FFFEFF;
            padding: 15px;
            border-radius: 10px;
            border: 2px solid #A1CAE3;
        }
        
        .seat.blocked {
            background-color: #000000;
            color: white;
            cursor: not-allowed;
            border-color: #333333;
        }
        
        .exit-label {
            background: linear-gradient(135deg, #FC723E 0%, #e65a2e 100%);
            color: #FFFEFF;
            padding: 8px 15px;
            margin-bottom: 12px;
            text-align: center;
            font-weight: bold;
            border-radius: 6px;
            font-size: 16px;
        }
        
        .side-label {
            text-align: center;
            font-weight: bold;
            margin-bottom: 12px;
            font-size: 18px;
            color: #207FBD;
            padding: 8px;
            background-color: #A1CAE3;
            border-radius: 6px;
        }
        
        .row {
            display: flex;
            margin-bottom: 6px;
            align-items: center;
            justify-content: flex-start;
        }

        .side:first-child .row {
            justify-content: flex-end;
        }
        
        .row-label {
            width: 40px;
            min-width: 40px;
            text-align: center;
            font-weight: bold;
            font-size: 14px;
            color: #207FBD;
            background-color: #A1CAE3;
            padding: 4px;
            border-radius: 4px;
            margin-right: 8px;
        }
        
        .seats {
            display: flex;
            gap: 5px;
            flex-wrap: nowrap;
        }
        
        .seat {
            width: 35px;
            height: 30px;
            border: 2px solid #207FBD;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            cursor: pointer;
            transition: all 0.3s;
            border-radius: 4px;
            font-weight: 600;
        }
        
        .seat.available {
            background-color: white;
            color: #207FBD;
        }
        
        .seat.available:hover {
            background-color: #A1CAE3;
            transform: scale(1.1);
            box-shadow: 0 2px 8px rgba(32, 127, 189, 0.4);
        }
        
        .seat.reserved {
            background-color: #4CAF50;
            color: white;
            cursor: pointer;
            border-color: #45a049;
        }

        .seat.reserved:hover {
            background-color: #45a049;
            transform: scale(1.05);
        }
        
        .seat.sound-control {
            background-color: #FC723E;
            color: white;
            cursor: not-allowed;
            border-color: #e65a2e;
        }

        .seat.selected {
            background-color: #FFD700;
            color: #207FBD;
            border-color: #FFA500;
            transform: scale(1.15);
            box-shadow: 0 4px 12px rgba(255, 215, 0, 0.6);
        }
        
        .stage {
            grid-column: 1 / -1;
            background: linear-gradient(135deg, #8B4513 0%, #654321 100%);
            color: #FFFEFF;
            text-align: center;
            padding: 20px 15px;
            font-size: clamp(20px, 5vw, 32px);
            font-weight: bold;
            border-radius: 10px;
            box-shadow: 0 4px 10px rgba(139, 69, 19, 0.3);
            border: 3px solid #654321;
            position: relative;
        }
        
        .stage::before {
            content: "▼";
            display: block;
            font-size: clamp(16px, 4vw, 24px);
            margin-bottom: 5px;
        }

        /* Reservation Button */
        .reservation-action {
            position: fixed;
            bottom: 30px;
            right: 30px;
            z-index: 1000;
            opacity: 0;
            transform: translateY(100px);
            transition: all 0.4s ease;
            pointer-events: none;
        }

        .reservation-action.visible {
            opacity: 1;
            transform: translateY(0);
            pointer-events: all;
        }

        .reserve-btn {
            background: linear-gradient(135deg, #FC723E 0%, #ff8c5a 100%);
            color: white;
            border: none;
            padding: 18px 35px;
            border-radius: 50px;
            font-size: 18px;
            font-weight: bold;
            cursor: pointer;
            box-shadow: 0 8px 25px rgba(252, 114, 62, 0.5);
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .reserve-btn:hover {
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 12px 35px rgba(252, 114, 62, 0.7);
        }

        .selected-count {
            background: white;
            color: #207FBD;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 14px;
            margin-left: 10px;
            font-weight: bold;
        }

        /* Modal Styles */
        .modal {
    display: none;
    position: fixed;
    z-index: 2000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.6);
    backdrop-filter: blur(5px);
    animation: fadeIn 0.3s ease;
    overflow-y: auto; 
    -webkit-overflow-scrolling: touch;
}

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .modal.active {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px 0; 
}

.modal-content {
    background: linear-gradient(135deg, #FFFEFF 0%, #f5f9fc 100%);
    padding: 40px;
    border-radius: 20px;
    max-width: 600px;
    width: 90%;
    max-height: 90vh; 
    overflow-y: auto; 
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    animation: slideUp 0.4s ease;
    position: relative;
    margin: auto; 
}

.modal-content::-webkit-scrollbar {
    width: 8px;
}

.modal-content::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 4px;
}

.modal-content::-webkit-scrollbar-thumb {
    background: #207FBD;
    border-radius: 4px;
}

.modal-content::-webkit-scrollbar-thumb:hover {
    background: #FC723E;
}

        @keyframes slideUp {
            from { transform: translateY(50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .modal-header {
            margin-bottom: 30px;
            text-align: center;
            border-bottom: 3px solid #207FBD;
            padding-bottom: 15px;
        }

        .modal-header h2 {
            color: #207FBD;
            font-size: 28px;
            margin-bottom: 10px;
        }

        .close-modal {
            position: absolute;
            right: 20px;
            top: 20px;
            font-size: 32px;
            font-weight: bold;
            color: #999;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }

        .close-modal:hover {
            color: #FC723E;
            background: rgba(252, 114, 62, 0.1);
            transform: rotate(90deg);
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #207FBD;
            font-weight: 600;
            font-size: 15px;
        }

        .form-group input[type="text"],
        .form-group input[type="tel"],
        .form-group input[type="number"],
        .form-group textarea {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #A1CAE3;
            border-radius: 10px;
            font-size: 15px;
            transition: all 0.3s ease;
            background: white;
            font-family: inherit;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }

        .form-group input:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #207FBD;
            box-shadow: 0 0 0 3px rgba(32, 127, 189, 0.1);
        }

        .selected-seats-display {
            background: linear-gradient(135deg, #207FBD 0%, #4a9ed1 100%);
            color: white;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .selected-seats-display h3 {
            margin-bottom: 10px;
            font-size: 16px;
        }

        .selected-seats-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .selected-seat-tag {
            background: rgba(255, 255, 255, 0.3);
            padding: 6px 12px;
            border-radius: 6px;
            font-weight: 600;
            border: 1px solid rgba(255, 255, 255, 0.5);
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
            padding: 15px;
            background: #f5f9fc;
            border-radius: 10px;
            border: 2px solid #A1CAE3;
        }

        .checkbox-group input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
        }

        .checkbox-group label {
            margin: 0;
            cursor: pointer;
            font-weight: 600;
            color: #207FBD;
        }

        .remaining-input {
            display: none;
            animation: slideDown 0.3s ease;
        }
        
        .remaining-info {
            color: #08a4a7;
            font-weight: bold;
            font-size: 18px;
            margin-top: 15px;
            padding: 10px;
            background: linear-gradient(135deg, rgba(8, 164, 167, 0.1) 0%, rgba(8, 164, 167, 0.05) 100%);
            border-radius: 8px;
            border: 2px solid rgba(8, 164, 167, 0.3);
        }

        @keyframes slideDown {
            from { opacity: 0; max-height: 0; }
            to { opacity: 1; max-height: 200px; }
        }

        .remaining-input.visible {
            display: block;
        }

        .submit-btn {
            width: 100%;
            background: linear-gradient(135deg, #4CAF50 0%, #66bb6a 100%);
            color: white;
            border: none;
            padding: 15px;
            border-radius: 10px;
            font-size: 18px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 1px;
            box-shadow: 0 4px 15px rgba(76, 175, 80, 0.4);
        }

        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(76, 175, 80, 0.6);
        }

        .submit-btn:active {
            transform: translateY(0);
        }

        .success-message {
            background: linear-gradient(135deg, #4CAF50 0%, #66bb6a 100%);
            color: white;
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            text-align: center;
            font-weight: 600;
            box-shadow: 0 4px 15px rgba(76, 175, 80, 0.4);
            animation: slideDown 0.5s ease;
        }

        .success-message::before {
            content: '✓ ';
            font-size: 20px;
            margin-right: 8px;
        }

        .delete-message {
            background: linear-gradient(135deg, #f44336 0%, #e57373 100%);
        }

        .update-message {
            background: linear-gradient(135deg, #2196F3 0%, #64B5F6 100%);
        }

        /* Seat Info Modal - Small */
        .seat-info-modal {
            display: none;
            position: fixed;
            z-index: 3000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.4);
        }

        .seat-info-modal.active {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .seat-info-content {
            background: white;
            padding: 25px;
            border-radius: 15px;
            max-width: 350px;
            width: 90%;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
            animation: popIn 0.3s ease;
            text-align: center;
            border: 3px solid #4CAF50;
            transition: border-color 0.3s ease;
        }

        .seat-info-content.unpaid {
            border: 3px solid #08a4a7;
        }

        @keyframes popIn {
            from { transform: scale(0.8); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }

        .seat-info-content h3 {
            color: #207FBD;
            margin-bottom: 15px;
            font-size: 22px;
        }

        .seat-info-content p {
            color: #333;
            font-size: 18px;
            margin-bottom: 10px;
        }

        .seat-info-content .customer-name {
            color: #4CAF50;
            font-weight: bold;
            font-size: 20px;
        }

        .close-seat-info {
            margin-top: 20px;
            padding: 10px 25px;
            background: linear-gradient(135deg, #207FBD 0%, #4a9ed1 100%);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: bold;
            font-size: 16px;
            transition: all 0.3s ease;
        }

        .close-seat-info:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(32, 127, 189, 0.4);
        }

        /* Action Buttons for Table */
        .action-buttons {
            display: flex;
            gap: 10px;
            justify-content: center;
        }

        .btn-edit, .btn-delete {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            font-size: 13px;
            transition: all 0.3s ease;
            text-transform: uppercase;
        }

        .btn-edit {
            background: linear-gradient(135deg, #2196F3 0%, #64B5F6 100%);
            color: white;
        }

        .btn-edit:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(33, 150, 243, 0.4);
        }

        .btn-delete {
            background: linear-gradient(135deg, #f44336 0%, #e57373 100%);
            color: white;
        }

        .btn-delete:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(244, 67, 54, 0.4);
        }
        
        /* Large Desktop - no scroll needed */
        @media (min-width: 1280px) {
            .theatre-scroll-container {
                overflow-x: visible;
            }
            
            .theatre-layout {
                min-width: auto;
            }
        }
        
        /* Tablet and Desktop */
        @media (min-width: 768px) {
            body {
                padding: 20px;
            }
            
            .container {
                padding: 25px;
            }
            
            .logo {
                max-width: 200px;
            }

            .reservations-table th,
            .reservations-table td {
                font-size: 15px;
            }
        }
        
        /* Large Desktop */
        @media (min-width: 1024px) {
            .container {
                padding: 35px;
            }
            
            .logo {
                max-width: 250px;
            }
        }

        .seat.reserved-unpaid {
            background-color: #08a4a7;
            color: white;
            cursor: pointer;
            border-color: #067779;
        }

        .seat.reserved-unpaid:hover {
            background-color: #067779;
            transform: scale(1.05);
        }
.seat.pending {
    background-color: #5D5978;
    color: white;
    cursor: pointer;
    border-color: #4a4760;
}

.seat.pending:hover {
    background-color: #4a4760;
    transform: scale(1.05);
}

.legend-box.pending {
    background-color: #5D5978;
}

.pending-section {
    margin-bottom: 40px;
}

.pending-section h2 {
    color: #5D5978;
    font-size: 24px;
    margin-bottom: 20px;
    padding: 15px;
    background: linear-gradient(135deg, rgba(93, 89, 120, 0.1) 0%, rgba(93, 89, 120, 0.05) 100%);
    border-radius: 10px;
    border-left: 5px solid #5D5978;
}

.comment-cell {
    max-width: 300px;
    word-wrap: break-word;
    padding: 10px;
    background: #f9f9f9;
    border-radius: 6px;
    font-style: italic;
    color: #555;
}

.btn-approve {
    background: linear-gradient(135deg, #4CAF50 0%, #66bb6a 100%);
    color: white;
}

.btn-approve:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(76, 175, 80, 0.4);
}

.btn-reject {
    background: linear-gradient(135deg, #f44336 0%, #e57373 100%);
    color: white;
}

.btn-reject:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(244, 67, 54, 0.4);
}

.viewer-restricted {
    opacity: 0.5;
    pointer-events: none;
    user-select: none;
}

.viewer-message {
    background: linear-gradient(135deg, #FF9800 0%, #FFB74D 100%);
    color: white;
    padding: 12px 20px;
    border-radius: 10px;
    margin-bottom: 20px;
    text-align: center;
    font-weight: 600;
    box-shadow: 0 4px 15px rgba(255, 152, 0, 0.3);
}

.viewer-message::before {
    content: 'ℹ️ ';
    font-size: 18px;
    margin-right: 8px;
}

.khedma-checkbox-group {
    border: 2px solid #4CAF50;
    background: linear-gradient(135deg, rgba(76,175,80,0.08) 0%, rgba(76,175,80,0.04) 100%);
}

.khedma-checkbox-group label {
    color: #2e7d32 !important;
    font-weight: 700 !important;
}

.khedma-price-info {
    margin-bottom: 18px;
    padding: 10px 12px;
    background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
    border: 2px solid #4CAF50;
    border-radius: 10px;
    color: #1b5e20;
    font-size: 14px;
    line-height: 1.5;
    white-space: pre-line;
    animation: slideDown 0.3s ease;
}

.pending-badge {
    display: inline-block;
    background: linear-gradient(135deg, #5D5978 0%, #7a7691 100%);
    color: white;
    padding: 4px 12px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-left: 8px;
}

        @media (max-width: 767px) {
            .header {
                padding-top: 52px;
            }

            .header-admin {
                top: 10px;
                right: 10px;
                font-size: 12px;
                padding: 6px 10px;
            }

            .reservations-table {
                font-size: 12px;
            }

            .reservations-table th,
            .reservations-table td {
                padding: 10px 8px;
            }

            .stat-number {
                font-size: 24px;
            }

            .reservation-action {
                bottom: 15px;
                right: 15px;
            }

            .reserve-btn {
                padding: 15px 25px;
                font-size: 16px;
            }

            .modal.active {
        align-items: flex-start; 
        padding: 10px 0;
    }
    
    .modal-content {
        padding: 25px 20px;
        width: 95%;
        max-height: calc(100vh - 20px); 
        margin: 10px auto;
    }

    .close-modal {
        right: 15px;
        top: 15px;
        width: 35px;
        height: 35px;
        font-size: 28px;
    }

    .modal-header h2 {
        font-size: 22px;
        padding-right: 40px; 
    }

    .form-group input[type="text"],
    .form-group input[type="tel"],
    .form-group input[type="number"],
    .form-group textarea {
        font-size: 16px; 
    }

            .action-buttons {
                flex-direction: column;
            }
        }

        .reservation-scope { text-align: center; color: #546778; margin-bottom: 18px; }
        .modal-location { color: #546778; margin-top: 8px; }
        .balcony-scroll-container { overflow-x: auto; }
        .balcony-layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 180px minmax(0, 1fr);
            gap: 20px;
            min-width: 1280px;
            max-width: 1280px;
            margin: 0 auto;
        }
        .balcony-layout .row { height: 30px; margin-bottom: 6px; }
        .balcony-layout .seat { flex-shrink: 0; }
        .balcony-right .row { justify-content: flex-end; }
        .balcony-right .seats { flex-direction: row-reverse; }
        .balcony-right .row-label { margin-right: 0; margin-left: 8px; }
        .balcony-center { display: flex; flex-direction: column; align-items: center; }
        .balcony-control-room, .balcony-exit {
            background: #79502f;
            color: white;
            border-radius: 8px;
            font-weight: bold;
            text-align: center;
        }
        .balcony-control-room {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            height: 96px;
            margin-bottom: 12px;
        }
        .balcony-exit { padding: 12px 20px; margin-top: 24px; }
        [hidden] { display: none !important; }
        .live-notice { padding: 14px; margin-bottom: 16px; border: 1px solid #A1CAE3; border-radius: 8px; color: #185e8b; background: #edf7fd; line-height: 1.5; }
        .availability-notice { text-align: center; padding: 15px 0; }
        .availability-icon { color: #207FBD; font-size: 56px; margin-bottom: 15px; }
        .availability-notice h2 { color: #207FBD; margin-bottom: 15px; font-size: 24px; }
        .availability-notice p { color: #445566; line-height: 1.7; margin-bottom: 25px; }
        .submit-btn:disabled { opacity: 0.6; cursor: wait; }
    </style>
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
                <span class="control-label" id="performanceDateLabel">Performance date</span>
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
                Reservation approved successfully!
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
                        <span class="legend-box selected"></span>
                        <span>Selected</span>
                    </div>
                </div>
            </div>
            
            <div class="theatre-scroll-container balcony-scroll-container" data-map-area="balcony" <?php if ($selectedArea !== 'balcony'): ?>hidden<?php endif; ?>>
                <?php require __DIR__ . '/balcony_map.php'; ?>
            </div>
            <div class="theatre-scroll-container" data-map-area="theatre" <?php if ($selectedArea !== 'theatre'): ?>hidden<?php endif; ?>>
                <div class="theatre-layout">
                    <!-- Right Side -->
                    <div class="side">
                        <div class="exit-label">EXIT 3</div>
                        <div class="side-label">Right Side</div>
                        <?php foreach ($rightSide as $row => $seatCount): ?>
                            <div class="row">
                                <div class="seats" style="flex-direction: row-reverse;">
                                    <?php 
$startSeat = (($row === 'OR' || $row === 'PR') && $seatCount == 11) ? 4 : 1;

for ($i = 1; $i <= 11; $i++): 
    $seatId = $row . $i;
    $isReserved = isSeatReserved($seatId, $reservedSeats);
    $isPending = isSeatPending($seatId, $pendingSeats);
    $isSC = isSoundControl($row, $i);
    $hasRemaining = false;
    $ownerName = '';
    if ($isReserved || $isPending) {
        foreach ($allReservations as $reservation) {
            $seats = explode(',', $reservation['seat_codes']);
            foreach ($seats as $seat) {
                if (trim($seat) === $seatId) {
                    $hasRemaining = $reservation['remaining_amount'] > 0;
                    $ownerName = $reservation['customer_name'];
                    break 2;
                }
            }
        }
    }
    
    $isBlocked = false;
    if (($row === 'OR' || $row === 'PR')) {
        if ($i >= 1 && $i <= 3) {
            $isSC = true;
        } else {
            $isBlocked = ($i < $startSeat) || ($i > ($startSeat + $seatCount - 1));
        }
    } else {
        $isBlocked = ($i < $startSeat) || ($i > ($startSeat + $seatCount - 1));
    }
    
    $isBlocked = $isBlocked || isTheatreSeatBlocked($seatId);

    if ($isSC) {
        $class = 'sound-control';
        $title = 'Sound Control';
        $onclick = '';
    } elseif ($isBlocked) {
        $class = 'blocked';
        $title = 'Blocked - Structure';
        $onclick = '';
    } elseif ($isPending) {
        $class = 'pending';
        $title = 'Pending Approval - Reserved by ' . $ownerName;
        $remaining = 0;
        foreach ($allReservations as $reservation) {
            $seats = explode(',', $reservation['seat_codes']);
            foreach ($seats as $seat) {
                if (trim($seat) === $seatId) {
                    $remaining = $reservation['remaining_amount'];
                    break 2;
                }
            }
        }
        $onclick = 'showSeatInfo(' . json_encode($seatId) . ', ' . json_encode($ownerName) . ', ' . json_encode((float) $remaining) . ', true)';
    } elseif ($isReserved) {
        $class = $hasRemaining ? 'reserved-unpaid' : 'reserved';
        $title = 'Reserved by ' . $ownerName . ($hasRemaining ? ' (Unpaid)' : '');
        $remaining = 0;
        foreach ($allReservations as $reservation) {
            $seats = explode(',', $reservation['seat_codes']);
            foreach ($seats as $seat) {
                if (trim($seat) === $seatId) {
                    $remaining = $reservation['remaining_amount'];
                    break 2;
                }
            }
        }
        $onclick = 'showSeatInfo(' . json_encode($seatId) . ', ' . json_encode($ownerName) . ', ' . json_encode((float) $remaining) . ', false)';
    } else {
        $class = 'available';
        $title = 'Available - Click to reserve';
        $onclick = 'toggleSeat(this)';
    }
?>
                                        <div class="seat <?php echo $class; ?>" 
                                             data-seat="<?php echo $seatId; ?>" 
                                             title="<?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>"
                                             onclick="<?php echo htmlspecialchars($onclick, ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo $i; ?>
                                        </div>
                                    <?php endfor; ?>
                                </div>
                                <div class="row-label" style="margin-left: 8px; margin-right: 0;"><?php echo $row; ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Left Side -->
                    <div class="side">
                        <div class="exit-label">EXIT 1</div>
                        <div class="side-label">Left Side (Entrance)</div>
                        <?php foreach ($leftSide as $row => $seatCount): ?>
                            <div class="row">
                                <div class="row-label"><?php echo $row; ?></div>
                                <div class="seats">
                                    <?php for ($i = 1; $i <= 11; $i++): 
    $seatId = $row . $i;
    $isReserved = isSeatReserved($seatId, $reservedSeats);
    $isPending = isSeatPending($seatId, $pendingSeats);
    $hasRemaining = false;
    $ownerName = '';
    if ($isReserved || $isPending) {
        foreach ($allReservations as $reservation) {
            $seats = explode(',', $reservation['seat_codes']);
            foreach ($seats as $seat) {
                if (trim($seat) === $seatId) {
                    $hasRemaining = $reservation['remaining_amount'] > 0;
                    $ownerName = $reservation['customer_name'];
                    break 2;
                }
            }
        }
    }
    
    $isBlocked = $i > $seatCount || isTheatreSeatBlocked($seatId);
    
    if ($isBlocked) {
        $class = 'blocked';
        $title = 'Blocked - Structure';
        $onclick = '';
    } elseif ($isPending) {
        $class = 'pending';
        $title = 'Pending Approval - Reserved by ' . $ownerName;
        $remaining = 0;
        foreach ($allReservations as $reservation) {
            $seats = explode(',', $reservation['seat_codes']);
            foreach ($seats as $seat) {
                if (trim($seat) === $seatId) {
                    $remaining = $reservation['remaining_amount'];
                    break 2;
                }
            }
        }
        $onclick = 'showSeatInfo(' . json_encode($seatId) . ', ' . json_encode($ownerName) . ', ' . json_encode((float) $remaining) . ', true)';
    } elseif ($isReserved) {
        $class = $hasRemaining ? 'reserved-unpaid' : 'reserved';
        $title = 'Reserved by ' . $ownerName . ($hasRemaining ? ' (Unpaid)' : '');
        $remaining = 0;
        foreach ($allReservations as $reservation) {
            $seats = explode(',', $reservation['seat_codes']);
            foreach ($seats as $seat) {
                if (trim($seat) === $seatId) {
                    $remaining = $reservation['remaining_amount'];
                    break 2;
                }
            }
        }
        $onclick = 'showSeatInfo(' . json_encode($seatId) . ', ' . json_encode($ownerName) . ', ' . json_encode((float) $remaining) . ', false)';
    } else {
        $class = 'available';
        $title = 'Available - Click to reserve';
        $onclick = 'toggleSeat(this)';
    }
?>
                                        <div class="seat <?php echo $class; ?>" 
                                             data-seat="<?php echo $seatId; ?>" 
                                             title="<?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>"
                                             onclick="<?php echo htmlspecialchars($onclick, ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo $i; ?>
                                        </div>
                                    <?php endfor; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="stage">STAGE</div>
                </div>
            </div>

            <!-- Reservation Action Button -->
            <div class="reservation-action" id="reservationAction">
                <button class="reserve-btn" onclick="openReservationModal()">
                    Reserve Seats
                    <span class="selected-count" id="selectedCount">0</span>
                </button>
            </div>
        </div>

        <!-- Reservations Data Tab -->
        <div id="reservationDataPanel" class="tab-content <?php echo $selectedTab === 'reservations' ? 'active' : ''; ?>">
            <?php echo renderReservationData($allReservations, $selectedDay, $selectedArea); ?>
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


    <script id="theatre-state" type="application/json"><?php echo json_encode(['day' => $selectedDay, 'area' => $selectedArea, 'day_label' => PERFORMANCE_DATES[$selectedDay], 'prices' => TICKET_PRICES] + $snapshot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR); ?></script>
    <script src="app.js?v=<?php echo filemtime(__DIR__ . '/app.js'); ?>"></script>
</body>
</html>
