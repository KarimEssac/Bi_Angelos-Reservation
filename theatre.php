<?php
const PERFORMANCE_DATES = ['2026-11-06' => 'Friday 6/11', '2026-11-07' => 'Saturday 7/11'];
const SEATING_AREAS = ['theatre' => 'Main Theatre', 'balcony' => 'Balcony'];
const TICKET_PRICES = ['theatre' => 120, 'khedma' => 100, 'balcony' => 75];

function theatreSides(): array
{
    return [
        'right' => [
            'PR' => 11, 'OR' => 11, 'NR' => 10, 'MR' => 9, 'LR' => 11, 'KR' => 11,
            'JR' => 11, 'IR' => 9, 'HR' => 11, 'GR' => 11, 'FR' => 11,
            'ER' => 10, 'DR' => 9, 'CR' => 11, 'BR' => 11, 'AR' => 11,
        ],
        'left' => [
            'PL' => 11, 'OL' => 11, 'NL' => 10, 'ML' => 9, 'LL' => 11, 'KL' => 11,
            'JL' => 11, 'IL' => 10, 'HL' => 11, 'GL' => 11, 'FL' => 11,
            'EL' => 10, 'DL' => 10, 'CL' => 11, 'BL' => 11, 'AL' => 11,
        ],
    ];
}

function balconySides(): array
{
    return [
        'right' => ['KR' => 5, 'JR' => 4, 'IR' => 5, 'HR' => 7, 'GR' => 9, 'FR' => 9,
            'ER' => 8, 'DR' => 7, 'CR' => 7, 'BR' => 12, 'AR' => 12],
        'left' => ['KL' => 5, 'JL' => 5, 'IL' => 5, 'HL' => 7, 'GL' => 9, 'FL' => 8,
            'EL' => 8, 'DL' => 7, 'CL' => 7, 'BL' => 12, 'AL' => 11],
    ];
}

function balconyCenter(): array
{
    return ['ML' => 3];
}

function reservationAreaLabel(string $seatCodes): string
{
    $areas = [];
    foreach (explode(',', $seatCodes) as $code) {
        $areas[str_starts_with(trim($code), 'B-') ? 'balcony' : 'theatre'] = true;
    }
    return implode(' / ', array_intersect_key(SEATING_AREAS, $areas));
}

function parseSeatCodes(string $input): array
{
    $codes = array_map('trim', explode(',', $input));
    $theatreRows = array_merge(theatreSides()['right'], theatreSides()['left']);
    $balconyRows = array_merge(balconySides()['right'], balconySides()['left'], balconyCenter());
    foreach ($codes as $code) {
        if (!preg_match('/\A(B-)?([A-P][RL])([1-9]|1[0-2])\z/', $code, $match)) {
            throw new InvalidArgumentException('Choose valid, available seats from the seat map.');
        }
        $isBalcony = $match[1] === 'B-';
        $rows = $isBalcony ? $balconyRows : $theatreRows;
        $row = $match[2];
        $number = (int) $match[3];
        if (!isset($rows[$row]) || $number > $rows[$row]
            || (!$isBalcony && (isSoundControl($row, $number) || isTheatreSeatBlocked($code)))) {
            throw new InvalidArgumentException('Choose valid, available seats from the seat map.');
        }
    }
    if (count(array_unique($codes)) !== count($codes)) {
        throw new InvalidArgumentException('Each seat may only be selected once.');
    }
    sort($codes, SORT_NATURAL);
    return $codes;
}

function isSoundControl($row, $seatNum): bool
{
    return in_array($row, ['PR', 'OR'], true) && $seatNum >= 1 && $seatNum <= 3;
}

function isTheatreSeatBlocked(string $seatCode): bool
{
    return in_array($seatCode, ['FL11', 'FR11', 'NR1'], true);
}

function isSeatReserved($seat, $reservedSeats): bool
{
    return in_array($seat, $reservedSeats, true);
}

function isSeatPending($seat, $pendingSeats): bool
{
    return in_array($seat, $pendingSeats, true);
}

function seatTicketPrice(string $seatCode, bool $hasServiceDiscount): int
{
    return str_starts_with(trim($seatCode), 'B-') ? TICKET_PRICES['balcony']
        : TICKET_PRICES[$hasServiceDiscount ? 'khedma' : 'theatre'];
}

function reservationTicketTotal(array $reservation): int
{
    $total = 0;
    foreach (explode(',', $reservation['seat_codes']) as $code) {
        $total += seatTicketPrice($code, !empty($reservation['has_service_discount']));
    }
    return $total;
}

function calculateTotalCollected(array $reservations): array
{
    $totals = ['total_revenue' => 0, 'breakdown' => []];
    foreach (TICKET_PRICES as $type => $price) {
        $totals['breakdown'][$type] = ['price' => $price, 'count' => 0, 'revenue' => 0];
    }
    foreach ($reservations as $reservation) {
        if (trim($reservation['customer_name']) === 'Sponsors') {
            continue;
        }
        foreach (explode(',', $reservation['seat_codes']) as $code) {
            $type = str_starts_with(trim($code), 'B-') ? 'balcony'
                : (!empty($reservation['has_service_discount']) ? 'khedma' : 'theatre');
            $price = seatTicketPrice($code, !empty($reservation['has_service_discount']));
            $totals['breakdown'][$type]['count']++;
            $totals['breakdown'][$type]['revenue'] += $price;
            $totals['total_revenue'] += $price;
        }
    }
    return $totals;
}
