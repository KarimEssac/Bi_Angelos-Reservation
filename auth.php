<?php
require_once __DIR__ . '/db.php';

function wantsJsonResponse(): bool
{
    return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
}

function authCookieOptions(int $expires): array
{
    return ['expires' => $expires, 'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true, 'samesite' => 'Lax'];
}

function clearLegacyLogin(): void
{
    foreach (['user_email', 'user_token', 'controller_name'] as $cookie) {
        if (isset($_COOKIE[$cookie])) {
            setcookie($cookie, '', authCookieOptions(time() - 3600));
        }
    }
}

function currentAdmin(PDO $pdo): ?array
{
    clearLegacyLogin();
    $token = $_COOKIE['admin_session'] ?? '';
    if (!is_string($token) || !preg_match('/\A[a-f0-9]{64}\z/', $token)) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT a.id, a.name, a.email FROM admin_sessions s
        JOIN admins a ON a.id = s.admin_id
        WHERE s.token_hash = ? AND s.expires_at > NOW()');
    $stmt->execute([hash('sha256', $token)]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function loginAdmin(PDO $pdo, string $email, string $password): bool
{
    $stmt = $pdo->prepare('SELECT id, password FROM admins WHERE email = ?');
    $stmt->execute([$email]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    // Passwords are intentionally stored and compared as plain text, per project requirements.
    if (!$admin || !hash_equals($admin['password'], $password)) {
        return false;
    }
    $token = bin2hex(random_bytes(32));
    $pdo->exec('DELETE FROM admin_sessions WHERE expires_at <= NOW()');
    $stmt = $pdo->prepare('INSERT INTO admin_sessions (admin_id, token_hash, expires_at)
        VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))');
    $stmt->execute([$admin['id'], hash('sha256', $token)]);
    setcookie('admin_session', $token, authCookieOptions(time() + 30 * 86400));
    return true;
}

function logoutAdmin(PDO $pdo): void
{
    $token = $_COOKIE['admin_session'] ?? '';
    if (is_string($token)) {
        $stmt = $pdo->prepare('DELETE FROM admin_sessions WHERE token_hash = ?');
        $stmt->execute([hash('sha256', $token)]);
    }
    setcookie('admin_session', '', authCookieOptions(time() - 3600));
    clearLegacyLogin();
}
