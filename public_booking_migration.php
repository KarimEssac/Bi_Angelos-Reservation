<?php
// Apply once from the command line: php public_booking_migration.php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/db.php';
$pdo->exec('ALTER TABLE reservations
    ADD COLUMN IF NOT EXISTS browser_token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS request_key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS booking_group_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS payment_recipient VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    ADD UNIQUE INDEX IF NOT EXISTS public_request_key (request_key_hash),
    ADD INDEX IF NOT EXISTS booking_group (booking_group_key)');
$pdo->exec("CREATE TABLE IF NOT EXISTS payment_rotation (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    next_recipient ENUM('maria', 'monica') NOT NULL DEFAULT 'maria',
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=ascii COLLATE=ascii_bin");
$pdo->exec("INSERT IGNORE INTO payment_rotation (id, next_recipient) VALUES (1, 'maria')");
$rows = $pdo->query('SELECT id, notes, browser_token_hash, reserved_by_admin_id, customer_name, customer_phone
    FROM reservations WHERE booking_group_key IS NULL')->fetchAll(PDO::FETCH_ASSOC);
$update = $pdo->prepare('UPDATE reservations SET booking_group_key = ? WHERE id = ? AND booking_group_key IS NULL');
foreach ($rows as $row) {
    $group = bin2hex(random_bytes(16));
    if (preg_match('/\ACombined booking ([A-F0-9]{10})\. /', $row['notes'] ?? '', $match)) {
        $owner = $row['browser_token_hash'] ?? ('admin:' . $row['reserved_by_admin_id']);
        $group = substr(hash('sha256', json_encode([$match[1], $owner, $row['customer_name'], $row['customer_phone']], JSON_THROW_ON_ERROR)), 0, 32);
    }
    $update->execute([$group, $row['id']]);
}
$pdo->exec("CREATE EVENT IF NOT EXISTS expire_pending_reservations
    ON SCHEDULE EVERY 1 MINUTE
    DO DELETE FROM reservations
        WHERE approval_status = 'pending' AND created_at <= DATE_SUB(NOW(), INTERVAL 12 HOUR)");
$pdo->exec('SET GLOBAL event_scheduler = ON');
echo "Public booking columns and the pending-request expiry schedule are installed.\n";
