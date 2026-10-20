<?php
if (!isset($snapshot, $renderMapSeat)) {
    http_response_code(404);
    exit;
}

$renderBalconySeat = static function (string $row, int $number) use ($renderMapSeat): void {
    $renderMapSeat('B-' . $row . $number, $number);
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
