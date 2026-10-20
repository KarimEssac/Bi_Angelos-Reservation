<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/db.php';
$pdo->exec('CREATE TABLE IF NOT EXISTS seat_holds (
    performance_id INT NOT NULL,
    seat_code VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    owner_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at DATETIME NOT NULL,
    PRIMARY KEY (performance_id, seat_code),
    INDEX hold_owner (owner_hash),
    INDEX hold_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=ascii COLLATE=ascii_bin');
echo "Seat holds table installed.\n";
