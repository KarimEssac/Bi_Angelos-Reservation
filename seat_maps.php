<?php
if (!isset($snapshot, $selectedArea)) {
    http_response_code(404);
    exit;
}
$publicView = $publicView ?? false;
$renderMapSeat = static function (string $code, int $number, bool $blocked = false, bool $soundControl = false) use ($snapshot, $publicView): void {
    $booking = $snapshot['seats']->$code ?? null;
    $class = 'available';
    $title = 'Available - Click to reserve';
    $onclick = 'toggleSeat(this)';
    if (isVipSeat($code)) {
        $class = 'vip';
        $title = 'VIP - Not available for reservation';
        $onclick = '';
    } elseif ($soundControl || $blocked) {
        $class = $soundControl ? 'sound-control' : 'blocked';
        $title = $soundControl ? 'Sound Control' : 'Blocked';
        $onclick = '';
    } elseif (!empty($booking['on_hold'])) {
        $class = 'on-hold';
        $title = 'On hold - Someone is completing a reservation';
        $onclick = '';
    } elseif ($booking) {
        $class = $booking['pending'] ? 'pending' : ($booking['remaining_amount'] > 0 ? 'reserved-unpaid' : 'reserved');
        $title = $booking['pending'] ? 'Pending confirmation' : 'Reserved';
        if (!empty($booking['customer_name'])) $title .= ' - ' . $booking['customer_name'];
        if ($publicView && !empty($booking['mine'])) $class .= ' own-seat';
        $arguments = [$code, $booking['customer_name'] ?? '', $booking['remaining_amount'], $booking['pending']];
        $onclick = 'showSeatInfo(' . implode(',', array_map(
            static fn($value) => json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), $arguments
        )) . ')';
    }
    ?>
    <div class="seat <?php echo $class; ?>" data-seat="<?php echo $code; ?>"
         title="<?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>"
         onclick="<?php echo htmlspecialchars($onclick, ENT_QUOTES, 'UTF-8'); ?>"><?php echo $number; ?></div>
    <?php
};
?>
<div class="theatre-scroll-container balcony-scroll-container" data-map-area="balcony" <?php if ($selectedArea !== 'balcony'): ?>hidden<?php endif; ?>>
    <?php require __DIR__ . '/balcony_map.php'; ?>
</div>
<div class="theatre-scroll-container" data-map-area="theatre" <?php if ($selectedArea !== 'theatre'): ?>hidden<?php endif; ?>>
    <div class="theatre-layout">
        <?php foreach (theatreSides() as $side => $rows): ?>
            <div class="side">
                <div class="exit-label"><?php echo $side === 'right' ? 'EXIT 3' : 'EXIT 1'; ?></div>
                <div class="side-label"><?php echo $side === 'right' ? 'Right Side' : 'Left Side (Entrance)'; ?></div>
                <?php foreach ($rows as $row => $count): ?>
                    <div class="row">
                        <?php if ($side === 'left'): ?><div class="row-label"><?php echo $row; ?></div><?php endif; ?>
                        <div class="seats" <?php if ($side === 'right'): ?>style="flex-direction: row-reverse;"<?php endif; ?>>
                            <?php for ($number = 1; $number <= 11; $number++): ?>
                                <?php $renderMapSeat($row . $number, $number,
                                    $number > $count || isTheatreSeatBlocked($row . $number), isSoundControl($row, $number)); ?>
                            <?php endfor; ?>
                        </div>
                        <?php if ($side === 'right'): ?><div class="row-label" style="margin-left: 8px; margin-right: 0;"><?php echo $row; ?></div><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
        <div class="stage">STAGE</div>
    </div>
</div>
