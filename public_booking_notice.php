<?php
// Temporary customer-only override. Set enabled to false to restore normal maps and booking.
const PUBLIC_BOOKING_CONTACT_ONLY = [
    'enabled' => false,
    'day' => '2026-11-06',
    'area' => 'theatre',
];

function publicBookingContactOnly(string $day, string $area): bool
{
    return PUBLIC_BOOKING_CONTACT_ONLY['enabled']
        && $day === PUBLIC_BOOKING_CONTACT_ONLY['day']
        && $area === PUBLIC_BOOKING_CONTACT_ONLY['area'];
}
