<?php
session_start();
require_once 'db.php';

if (isset($_SESSION['user_email']) && isset($_SESSION['user_role'])) {

    $userEmail = $_SESSION['user_email'];
    $userRole = $_SESSION['user_role'];
} 
elseif (isset($_COOKIE['user_email']) && isset($_COOKIE['user_token'])) {
    $email = $_COOKIE['user_email'];
    $token = $_COOKIE['user_token'];
    $hashedToken = hash('sha256', $email . 'bi_angelos_secret_salt');
    if ($token === $hashedToken) {
        $stmt = $pdo->prepare("SELECT email, role FROM accounts WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_role'] = $user['role'];
            $userEmail = $user['email'];
            $userRole = $user['role'];
        } else {
            header("Location: login.php");
            exit;
        }
    } else {
        header("Location: login.php");
        exit;
    }
} 
else {
    header("Location: login.php");
    exit;
}

?>