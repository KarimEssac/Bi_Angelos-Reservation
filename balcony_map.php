<?php
if (!isset($snapshot)) {
    http_response_code(404);
    exit;
}

$renderBalconySeat = static function (string $row, int $number) use ($snapshot): void {
    $code = 'B-' . $row . $number;
    $booking = $snapshot['seats']->$code ?? null;
    $class = !$booking ? 'available' : ($booking['pending'] ? 'pending'
        : ($booking['remaining_amount'] > 0 ? 'reserved-unpaid' : 'reserved'));
    $title = 'Balcony - ' . $row . $number;
    $onclick = 'toggleSeat(this)';
    if ($booking) {
        $title .= ' - ' . $booking['customer_name'];
        $arguments = [$code, $booking['customer_name'], $booking['remaining_amount'], $booking['pending']];
        $onclick = 'showSeatInfo(' . implode(',', array_map(
            static fn($value) => json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
            $arguments
        )) . ')';
    }
    ?>
    <div class="seat <?php echo $class; ?>"
         data-seat="<?php echo $code; ?>"
         title="<?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>"
         onclick="<?php echo htmlspecialchars($onclick, ENT_QUOTES, 'UTF-8'); ?>"><?php echo $number; ?></div>
    <?php
};
?>
<div class="balcony-layout" aria-label="Balcony seat map, 172 seats">
    <div class="balcony-wing balcony-right" aria-label="Right side">
        <?php foreach (balconySides()['right'] as $row => $count): ?>
            <div class="row">
                <div class="seats">
                    <?php for ($number = 1; $number <= $count; $number++) { $renderBalconySeat($row, $number); } ?>
                </div>
                <div class="row-label"><?php echo $row; ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="balcony-center">
        <div class="balcony-control-room">Control Room</div>
        <?php foreach (balconyCenter() as $row => $count): ?>
            <div class="row">
                <div class="row-label"><?php echo $row; ?></div>
                <div class="seats">
                    <?php for ($number = 1; $number <= $count; $number++) { $renderBalconySeat($row, $number); } ?>
                </div>
            </div>
        <?php endforeach; ?>
        <div class="balcony-exit">EXIT 4</div>
    </div>
    <div class="balcony-wing balcony-left" aria-label="Left side">
        <?php foreach (balconySides()['left'] as $row => $count): ?>
            <div class="row">
                <div class="row-label"><?php echo $row; ?></div>
                <div class="seats">
                    <?php for ($number = 1; $number <= $count; $number++) { $renderBalconySeat($row, $number); } ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="stage">STAGE</div>
</div>
