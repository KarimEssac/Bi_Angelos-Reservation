<?php
session_start();

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

// Check if user is logged in via session
if (isset($_SESSION['user_email']) && isset($_SESSION['user_role'])) {
    // User is logged in via session
    $userEmail = $_SESSION['user_email'];
    $userRole = $_SESSION['user_role'];
} 
// Check if user has valid cookie
elseif (isset($_COOKIE['user_email']) && isset($_COOKIE['user_token'])) {
    $email = $_COOKIE['user_email'];
    $token = $_COOKIE['user_token'];
    
    // Verify the token matches the email
    $hashedToken = hash('sha256', $email . 'bi_angelos_secret_salt');
    if ($token === $hashedToken) {
        // Verify email exists in database
        $stmt = $pdo->prepare("SELECT email, role FROM accounts WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            // Restore session from cookie
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_role'] = $user['role'];
            $userEmail = $user['email'];
            $userRole = $user['role'];
        } else {
            // Cookie is invalid, redirect to login
            header("Location: login.php");
            exit;
        }
    } else {
        // Token doesn't match, redirect to login
        header("Location: login.php");
        exit;
    }
} 
else {
    // No valid session or cookie, redirect to login
    header("Location: login.php");
    exit;
}

// At this point, user is authenticated
// $userEmail and $userRole are available for use in the protected page
?>