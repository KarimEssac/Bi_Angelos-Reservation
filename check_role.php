<?php
require_once 'db.php';

header('Content-Type: application/json');

$email = isset($_POST['email']) ? trim($_POST['email']) : '';

if (empty($email)) {
    echo json_encode(['role' => null]);
    exit;
}

$stmt = $pdo->prepare("SELECT role FROM accounts WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user) {
    echo json_encode(['role' => $user['role']]);
} else {
    echo json_encode(['role' => null]);
}
