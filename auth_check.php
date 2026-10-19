<?php
require_once __DIR__ . '/auth.php';

$admin = currentAdmin($pdo);
if (!$admin) {
    if (wantsJsonResponse()) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['authenticated' => false, 'error' => 'Please sign in again.']);
        exit;
    }
    header('Location: login.php');
    exit;
}
