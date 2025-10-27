<?php
$host = 'localhost';
$dbname = 'bi_angelos_2025';
$username = 'root';
$password = '';
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET time_zone = '+02:00'");
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

?>