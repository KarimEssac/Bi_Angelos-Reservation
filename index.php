<?php
// Database configuration
$host = 'localhost';
$dbname = 'bi_angelos_2025';
$username = 'root';
$password = '';

// Connect to database
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Handle delete operation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $id = intval($_POST['id']);
    $day = $_POST['day'];
    $tableName = 'reservations_' . $day;
    
    try {
        $stmt = $pdo->prepare("DELETE FROM $tableName WHERE id = ?");
        $stmt->execute([$id]);
        
        header("Location: ?day=$day&tab=reservations&deleted=1");
        exit;
    } catch(PDOException $e) {
        $error = "Delete failed: " . $e->getMessage();
    }
}

// Handle update operation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {
    $id = intval($_POST['id']);
    $day = $_POST['day'];
    $customerName = trim($_POST['customer_name']);
    $phoneNumber = trim($_POST['phone_number']);
    $selectedSeats = $_POST['reserved_desks'];
    $remaining = floatval($_POST['remaining']);
    
    $tableName = 'reservations_' . $day;
    
    try {
        $stmt = $pdo->prepare("UPDATE $tableName SET customer_name = ?, phone_number = ?, reserved_desks = ?, remaining = ? WHERE id = ?");
        $stmt->execute([$customerName, $phoneNumber, $selectedSeats, $remaining, $id]);
        
        header("Location: ?day=$day&tab=reservations&updated=1");
        exit;
    } catch(PDOException $e) {
        $error = "Update failed: " . $e->getMessage();
    }
}

// Handle reservation submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reserve') {
    $day = $_POST['day'];
    $customerName = trim($_POST['customer_name']);
    $phoneNumber = trim($_POST['phone_number']);
    $selectedSeats = $_POST['selected_seats'];
    $isPaid = isset($_POST['is_paid']) && $_POST['is_paid'] === '1';
    $remaining = $isPaid ? 0 : floatval($_POST['remaining']);
    
    $tableName = 'reservations_' . $day;
    
    try {
        $stmt = $pdo->prepare("INSERT INTO $tableName (customer_name, phone_number, reserved_desks, remaining) VALUES (?, ?, ?, ?)");
        $stmt->execute([$customerName, $phoneNumber, $selectedSeats, $remaining]);
        
        header("Location: ?day=$day&tab=map&success=1");
        exit;
    } catch(PDOException $e) {
        $error = "Reservation failed: " . $e->getMessage();
    }
}

// Get selected day from URL parameter, default to 7nov
$selectedDay = isset($_GET['day']) ? $_GET['day'] : '7nov';
$tableName = 'reservations_' . $selectedDay;

// Get selected tab from URL parameter, default to 'map'
$selectedTab = isset($_GET['tab']) ? $_GET['tab'] : 'map';

// Fetch all reservations for the selected day
$stmt = $pdo->query("SELECT * FROM $tableName ORDER BY created_at DESC");
$allReservations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Parse reserved seats into an array with customer info
$reservedSeats = [];
$seatOwners = [];
foreach ($allReservations as $reservation) {
    $seats = explode(',', $reservation['reserved_desks']);
    foreach ($seats as $seat) {
        $seatTrimmed = trim($seat);
        $reservedSeats[] = $seatTrimmed;
        $seatOwners[$seatTrimmed] = $reservation['customer_name'];
    }
}

// Define seat configuration - REVERSED ORDER (P to A, back to front)
$rightSide = [
    'PR' => 11, 'OR' => 11, 'NR' => 10, 'MR' => 9, 'LR' => 11, 'KR' => 11,
    'JR' => 11, 'IR' => 9, 'HR' => 11, 'GR' => 11, 'FR' => 11,
    'ER' => 10, 'DR' => 9, 'CR' => 11, 'BR' => 11, 'AR' => 11
];

$leftSide = [
    'PL' => 11, 'OL' => 11, 'NL' => 10, 'ML' => 9, 'LL' => 11, 'KL' => 11,
    'JL' => 11, 'IL' => 10, 'HL' => 11, 'GL' => 11, 'FL' => 11,
    'EL' => 10, 'DL' => 10, 'CL' => 11, 'BL' => 11, 'AL' => 11
];

// Function to check if seat is reserved
function isSeatReserved($seat, $reservedSeats) {
    return in_array($seat, $reservedSeats);
}

// Function to check if seat is sound control (PR1-3, OR1-3)
function isSoundControl($row, $seatNum) {
    return (($row === 'PR' || $row === 'OR') && $seatNum >= 1 && $seatNum <= 3);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bi Angelos Theatre - Seat Reservation</title>
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
        
        .day-selector {
            text-align: center;
            margin-bottom: 20px;
            display: flex;
            gap: 10px;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .day-selector a {
            display: inline-block;
            padding: 12px 25px;
            background-color: #A1CAE3;
            color: #207FBD;
            text-decoration: none;
            border-radius: 8px;
            font-weight: bold;
            transition: all 0.3s;
            font-size: clamp(14px, 3vw, 16px);
            border: 2px solid transparent;
        }
        
        .day-selector a.active {
            background-color: #207FBD;
            color: #FFFEFF;
            border: 2px solid #FC723E;
        }
        
        .day-selector a:hover {
            background-color: #207FBD;
            color: #FFFEFF;
            transform: translateY(-2px);
        }

        /* Tab Navigation */
        .tab-navigation {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            padding: 10px;
            background: linear-gradient(135deg, rgba(161, 202, 227, 0.3) 0%, rgba(255, 254, 255, 0.5) 100%);
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(32, 127, 189, 0.15);
        }

        .tab-button {
            padding: 15px 40px;
            background: linear-gradient(135deg, #A1CAE3 0%, #FFFEFF 100%);
            color: #207FBD;
            text-decoration: none;
            border-radius: 12px;
            font-weight: bold;
            transition: all 0.4s cubic-bezier(0.68, -0.55, 0.265, 1.55);
            font-size: clamp(14px, 3vw, 17px);
            border: 3px solid #A1CAE3;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            box-shadow: 0 4px 10px rgba(32, 127, 189, 0.2);
        }

        .tab-button::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(252, 114, 62, 0.3);
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }

        .tab-button:hover::before {
            width: 300px;
            height: 300px;
        }

        .tab-button span {
            position: relative;
            z-index: 1;
        }

        .tab-button.active {
            background: linear-gradient(135deg, #FC723E 0%, #ff8c5a 100%);
            color: #FFFEFF;
            border: 3px solid #207FBD;
            transform: scale(1.05);
            box-shadow: 0 8px 25px rgba(252, 114, 62, 0.4);
        }

        .tab-button.active::after {
            content: '✓';
            position: absolute;
            top: -5px;
            right: -5px;
            background: #207FBD;
            color: white;
            width: 25px;
            height: 25px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
        }

        .tab-button:hover {
            transform: translateY(-3px) scale(1.02);
            box-shadow: 0 8px 20px rgba(252, 114, 62, 0.3);
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
        
        .legend-box.available {
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
            margin-bottom: 20px;
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
            background: linear-gradient(135deg, #8B4513 0%, #654321 100%);
            color: #FFFEFF;
            text-align: center;
            padding: 20px 15px;
            margin-bottom: 20px;
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
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .modal.active {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: linear-gradient(135deg, #FFFEFF 0%, #f5f9fc 100%);
            padding: 40px;
            border-radius: 20px;
            max-width: 600px;
            width: 90%;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: slideUp 0.4s ease;
            position: relative;
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
        /* Mobile responsiveness for table */
        @media (max-width: 767px) {
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

            .modal-content {
                padding: 25px;
                width: 95%;
            }

            .action-buttons {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <img src="logo.jpg" alt="Bi Angelos Theatre" class="logo">
        <h1>الساكن في ستر العلي، نظام الحجز</h1>
        <p class="subtitle">إختار الكرسي اللي تحب تتفرج من عليه</p>
    </div>
    
    <div class="container">
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

        <div class="day-selector">
            <a href="?day=7nov&tab=<?php echo $selectedTab; ?>" class="<?php echo $selectedDay === '7nov' ? 'active' : ''; ?>">November 7</a>
            <a href="?day=8nov&tab=<?php echo $selectedTab; ?>" class="<?php echo $selectedDay === '8nov' ? 'active' : ''; ?>">November 8</a>
        </div>

        <div class="tab-navigation">
            <a href="?day=<?php echo $selectedDay; ?>&tab=map" class="tab-button <?php echo $selectedTab === 'map' ? 'active' : ''; ?>">
                Theatre Map
            </a>
            <a href="?day=<?php echo $selectedDay; ?>&tab=reservations" class="tab-button <?php echo $selectedTab === 'reservations' ? 'active' : ''; ?>">
                Reservations Data
            </a>
        </div>

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
            
            <div class="theatre-scroll-container">
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
    $isSC = isSoundControl($row, $i);
    
    // Check if seat has remaining amount
    $hasRemaining = false;
    $ownerName = '';
    if ($isReserved) {
        foreach ($allReservations as $reservation) {
            $seats = explode(',', $reservation['reserved_desks']);
            foreach ($seats as $seat) {
                if (trim($seat) === $seatId) {
                    $hasRemaining = $reservation['remaining'] > 0;
                    $ownerName = htmlspecialchars($reservation['customer_name']);
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
    
    if ($isSC) {
        $class = 'sound-control';
        $title = 'Sound Control';
        $onclick = '';
    } elseif ($isBlocked) {
        $class = 'blocked';
        $title = 'Blocked - Structure';
        $onclick = '';
    } elseif ($isReserved) {
        $class = $hasRemaining ? 'reserved-unpaid' : 'reserved';
        $title = 'Reserved by ' . $ownerName . ($hasRemaining ? ' (Unpaid)' : '');
        $remaining = 0;
foreach ($allReservations as $reservation) {
    $seats = explode(',', $reservation['reserved_desks']);
    foreach ($seats as $seat) {
        if (trim($seat) === $seatId) {
            $remaining = $reservation['remaining'];
            break 2;
        }
    }
}
$onclick = "showSeatInfo('$seatId', '$ownerName', '$remaining')";
    } else {
        $class = 'available';
        $title = 'Available - Click to reserve';
        $onclick = 'toggleSeat(this)';
    }
?>
                                        <div class="seat <?php echo $class; ?>" 
                                             data-seat="<?php echo $seatId; ?>" 
                                             title="<?php echo $title; ?>"
                                             onclick="<?php echo $onclick; ?>">
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
    
    // Check if seat has remaining amount
    $hasRemaining = false;
    $ownerName = '';
    if ($isReserved) {
        foreach ($allReservations as $reservation) {
            $seats = explode(',', $reservation['reserved_desks']);
            foreach ($seats as $seat) {
                if (trim($seat) === $seatId) {
                    $hasRemaining = $reservation['remaining'] > 0;
                    $ownerName = htmlspecialchars($reservation['customer_name']);
                    break 2;
                }
            }
        }
    }
    
    $isBlocked = $i > $seatCount;
    
    if ($isBlocked) {
        $class = 'blocked';
        $title = 'Blocked - Structure';
        $onclick = '';
    } elseif ($isReserved) {
        $class = $hasRemaining ? 'reserved-unpaid' : 'reserved';
        $title = 'Reserved by ' . $ownerName . ($hasRemaining ? ' (Unpaid)' : '');
        $remaining = 0;
foreach ($allReservations as $reservation) {
    $seats = explode(',', $reservation['reserved_desks']);
    foreach ($seats as $seat) {
        if (trim($seat) === $seatId) {
            $remaining = $reservation['remaining'];
            break 2;
        }
    }
}
$onclick = "showSeatInfo('$seatId', '$ownerName', '$remaining')";
    } else {
        $class = 'available';
        $title = 'Available - Click to reserve';
        $onclick = 'toggleSeat(this)';
    }
?>
    <div class="seat <?php echo $class; ?>" 
         data-seat="<?php echo $seatId; ?>" 
         title="<?php echo $title; ?>"
         onclick="<?php echo $onclick; ?>">
        <?php echo $i; ?>
    </div>
<?php endfor; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            
            <div class="stage">STAGE</div>

            <!-- Reservation Action Button -->
            <div class="reservation-action" id="reservationAction">
                <button class="reserve-btn" onclick="openReservationModal()">
                    Reserve Seats
                    <span class="selected-count" id="selectedCount">0</span>
                </button>
            </div>
        </div>

        <!-- Reservations Data Tab -->
        <div class="tab-content <?php echo $selectedTab === 'reservations' ? 'active' : ''; ?>">
            <?php
            // Calculate statistics
            $totalReservations = count($allReservations);
            $totalSeatsReserved = count($reservedSeats);
            $totalRemaining = array_sum(array_column($allReservations, 'remaining'));
            ?>

            <div class="stats-container">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $totalReservations; ?></div>
                    <div class="stat-label">Total Reservations</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $totalSeatsReserved; ?></div>
                    <div class="stat-label">Seats Reserved</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number">EGP <?php echo number_format($totalRemaining, 2); ?></div>
                    <div class="stat-label">Total Remaining</div>
                </div>
            </div>

            <div class="reservations-container">
                <?php if ($totalReservations > 0): ?>
                    <table class="reservations-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Customer Name</th>
                                <th>Phone Number</th>
                                <th>Reserved Seats</th>
                                <th>Remaining Amount</th>
                                <th>Created At</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($allReservations as $reservation): ?>
                                <tr>
                                    <td><strong>#<?php echo htmlspecialchars($reservation['id']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($reservation['customer_name']); ?></td>
                                    <td><?php echo htmlspecialchars($reservation['phone_number']); ?></td>
                                    <td>
                                        <div class="seats-cell">
                                            <?php 
                                            $seats = explode(',', $reservation['reserved_desks']);
                                            foreach ($seats as $seat): 
                                            ?>
                                                <span class="seat-badge"><?php echo trim(htmlspecialchars($seat)); ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="remaining-amount <?php echo $reservation['remaining'] == 0 ? 'remaining-paid' : ''; ?>">
                                            <?php if ($reservation['remaining'] == 0): ?>
                                                Paid
                                            <?php else: ?>
                                                EGP <?php echo number_format($reservation['remaining'], 2); ?>
                                            <?php endif; ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M d, Y H:i', strtotime($reservation['created_at'])); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn-edit" onclick='openEditModal(<?php echo json_encode($reservation); ?>)'>Edit</button>
                                            <button class="btn-delete" onclick="confirmDelete(<?php echo $reservation['id']; ?>)">Delete</button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-reservations">
                        <p>No reservations found for this day.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

<!-- Seat Info Modal (Small) -->
<div id="seatInfoModal" class="seat-info-modal">
    <div class="seat-info-content" id="seatInfoContent">
        <h3>Seat: <span id="seatInfoSeatId"></span></h3>
        <p>Reserved by:</p>
        <p class="customer-name" id="seatInfoCustomerName"></p>
        <p class="remaining-info" id="seatInfoRemaining" style="display: none;"></p>
        <button class="close-seat-info" onclick="closeSeatInfo()">Close</button>
    </div>
</div>

    <!-- Reservation Modal -->
    <div id="reservationModal" class="modal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeReservationModal()">&times;</span>
            <div class="modal-header">
                <h2>Complete Your Reservation</h2>
            </div>
            
            <form method="POST" action="" id="reservationForm">
                <input type="hidden" name="action" value="reserve">
                <input type="hidden" name="day" value="<?php echo $selectedDay; ?>">
                <input type="hidden" name="selected_seats" id="selectedSeatsInput">
                
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
                    <input type="tel" id="phone_number" name="phone_number" required>
                </div>

                <div class="checkbox-group">
                    <input type="checkbox" id="unpaidCheckbox" name="is_unpaid" value="1" onchange="toggleRemainingInput()">
                    <label for="unpaidCheckbox">Unpaid Reservation (Has Remaining Amount)</label>
                </div>

                <div class="form-group remaining-input" id="remainingGroup">
                    <label for="remaining">Remaining Amount (EGP) *</label>
                    <input type="number" id="remaining" name="remaining" min="0" step="0.01" value="0">
                </div>

                <input type="hidden" name="is_paid" id="isPaidInput" value="1">

                <button type="submit" class="submit-btn">Confirm Reservation</button>
            </form>
        </div>
    </div>

    <!-- Edit Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeEditModal()">&times;</span>
            <div class="modal-header">
                <h2>Edit Reservation</h2>
            </div>
            
            <form method="POST" action="" id="editForm">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="day" value="<?php echo $selectedDay; ?>">
                <input type="hidden" name="id" id="editId">
                
                <div class="form-group">
                    <label for="edit_customer_name">Customer Name *</label>
                    <input type="text" id="edit_customer_name" name="customer_name" required>
                </div>

                <div class="form-group">
                    <label for="edit_phone_number">Phone Number *</label>
                    <input type="tel" id="edit_phone_number" name="phone_number" required>
                </div>

                <div class="form-group">
                    <label for="edit_reserved_desks">Reserved Seats *</label>
                    <textarea id="edit_reserved_desks" name="reserved_desks" required></textarea>
                </div>

                <div class="form-group">
                    <label for="edit_remaining">Remaining Amount (EGP) *</label>
                    <input type="number" id="edit_remaining" name="remaining" min="0" step="0.01" required>
                </div>

                <button type="submit" class="submit-btn">Update Reservation</button>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Form (Hidden) -->
    <form method="POST" action="" id="deleteForm" style="display: none;">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="day" value="<?php echo $selectedDay; ?>">
        <input type="hidden" name="id" id="deleteId">
    </form>

    <script>
        let selectedSeats = [];

function showSeatInfo(seatId, customerName, remaining) {
    const modal = document.getElementById('seatInfoModal');
    const content = document.getElementById('seatInfoContent');
    const remainingInfo = document.getElementById('seatInfoRemaining');
    
    document.getElementById('seatInfoSeatId').textContent = seatId;
    document.getElementById('seatInfoCustomerName').textContent = customerName;
    
    if (remaining && parseFloat(remaining) > 0) {
        content.classList.add('unpaid');
        remainingInfo.style.display = 'block';
        remainingInfo.innerHTML = '💰 Remaining Amount: <br><strong>EGP ' + parseFloat(remaining).toFixed(2) + '</strong>';
    } else {
        content.classList.remove('unpaid');
        remainingInfo.style.display = 'none';
    }
    
    modal.classList.add('active');
}

function closeSeatInfo() {
    document.getElementById('seatInfoModal').classList.remove('active');
}

        function closeSeatInfo() {
            document.getElementById('seatInfoModal').classList.remove('active');
        }

        function toggleSeat(element) {
            const seatId = element.getAttribute('data-seat');
            
            if (element.classList.contains('selected')) {
                // Deselect seat
                element.classList.remove('selected');
                selectedSeats = selectedSeats.filter(seat => seat !== seatId);
            } else {
                // Select seat
                element.classList.add('selected');
                selectedSeats.push(seatId);
            }
            
            updateReservationButton();
        }

        function updateReservationButton() {
            const actionButton = document.getElementById('reservationAction');
            const countElement = document.getElementById('selectedCount');
            
            if (selectedSeats.length > 0) {
                actionButton.classList.add('visible');
                countElement.textContent = selectedSeats.length;
            } else {
                actionButton.classList.remove('visible');
            }
        }

        function openReservationModal() {
            if (selectedSeats.length === 0) return;
            
            const modal = document.getElementById('reservationModal');
            const selectedSeatsList = document.getElementById('selectedSeatsList');
            const selectedSeatsInput = document.getElementById('selectedSeatsInput');
            
            // Update selected seats display
            selectedSeatsList.innerHTML = '';
            selectedSeats.forEach(seat => {
                const tag = document.createElement('span');
                tag.className = 'selected-seat-tag';
                tag.textContent = seat;
                selectedSeatsList.appendChild(tag);
            });
            
            // Update hidden input
            selectedSeatsInput.value = selectedSeats.join(', ');
            
            modal.classList.add('active');
        }

        function closeReservationModal() {
            const modal = document.getElementById('reservationModal');
            modal.classList.remove('active');
        }

        function toggleRemainingInput() {
            const checkbox = document.getElementById('unpaidCheckbox');
            const remainingGroup = document.getElementById('remainingGroup');
            const remainingInput = document.getElementById('remaining');
            const isPaidInput = document.getElementById('isPaidInput');
            
            if (checkbox.checked) {
                remainingGroup.classList.add('visible');
                remainingInput.required = true;
                isPaidInput.value = '0';
            } else {
                remainingGroup.classList.remove('visible');
                remainingInput.required = false;
                remainingInput.value = '0';
                isPaidInput.value = '1';
            }
        }

        function openEditModal(reservation) {
            document.getElementById('editId').value = reservation.id;
            document.getElementById('edit_customer_name').value = reservation.customer_name;
            document.getElementById('edit_phone_number').value = reservation.phone_number;
            document.getElementById('edit_reserved_desks').value = reservation.reserved_desks;
            document.getElementById('edit_remaining').value = reservation.remaining;
            
            document.getElementById('editModal').classList.add('active');
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.remove('active');
        }

        function confirmDelete(id) {
            if (confirm('Are you sure you want to delete this reservation? This action cannot be undone.')) {
                document.getElementById('deleteId').value = id;
                document.getElementById('deleteForm').submit();
            }
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const reservationModal = document.getElementById('reservationModal');
            const editModal = document.getElementById('editModal');
            const seatInfoModal = document.getElementById('seatInfoModal');
            
            if (event.target === reservationModal) {
                closeReservationModal();
            }
            if (event.target === editModal) {
                closeEditModal();
            }
            if (event.target === seatInfoModal) {
                closeSeatInfo();
            }
        }

        // Form validation
        document.getElementById('reservationForm').addEventListener('submit', function(e) {
            const unpaidCheckbox = document.getElementById('unpaidCheckbox');
            const remainingInput = document.getElementById('remaining');
            
            if (unpaidCheckbox.checked) {
                const remainingValue = parseFloat(remainingInput.value);
                if (isNaN(remainingValue) || remainingValue <= 0) {
                    e.preventDefault();
                    alert('Please enter a valid remaining amount greater than 0');
                    return false;
                }
            }
        });
    </script>
</body>
</html>