<?php

// Reserve attempts before checking credentials, including concurrent requests.
function consumeLoginAttempt(PDO $pdo, string $ipAddress): int
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS login_rate_limits (
        ip_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
        attempts VARCHAR(64) NOT NULL,
        expires_at BIGINT UNSIGNED NOT NULL,
        INDEX login_limit_expiry (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=ascii COLLATE=ascii_bin');
    $pdo->exec('DELETE FROM login_rate_limits WHERE expires_at <= UNIX_TIMESTAMP()');
    $ipHash = hash('sha256', $ipAddress);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO login_rate_limits (ip_hash, attempts, expires_at)
            VALUES (?, '[]', UNIX_TIMESTAMP() + 300)
            ON DUPLICATE KEY UPDATE ip_hash = VALUES(ip_hash)");
        $stmt->execute([$ipHash]);
        $stmt = $pdo->prepare('SELECT attempts FROM login_rate_limits WHERE ip_hash = ? FOR UPDATE');
        $stmt->execute([$ipHash]);
        $now = (int) $pdo->query('SELECT UNIX_TIMESTAMP()')->fetchColumn();
        $attempts = array_values(array_filter(
            json_decode($stmt->fetchColumn(), true, 512, JSON_THROW_ON_ERROR),
            static fn (int $attempt): bool => $attempt > $now - 300
        ));
        if (count($attempts) >= 3) {
            $pdo->commit();
            return $attempts[0] + 300 - $now;
        }

        $attempts[] = $now;
        $stmt = $pdo->prepare('UPDATE login_rate_limits SET attempts = ?, expires_at = ? WHERE ip_hash = ?');
        $stmt->execute([json_encode($attempts, JSON_THROW_ON_ERROR), $now + 300, $ipHash]);
        $pdo->commit();
        return 0;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}
