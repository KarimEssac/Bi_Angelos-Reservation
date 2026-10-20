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
    ADD UNIQUE INDEX IF NOT EXISTS public_request_key (request_key_hash)');
$pdo->exec("CREATE EVENT IF NOT EXISTS expire_pending_reservations
    ON SCHEDULE EVERY 1 MINUTE
    DO DELETE FROM reservations
        WHERE approval_status = 'pending' AND created_at <= DATE_SUB(NOW(), INTERVAL 12 HOUR)");
$pdo->exec('SET GLOBAL event_scheduler = ON');
echo "Public booking columns and the pending-request expiry schedule are installed.\n";
