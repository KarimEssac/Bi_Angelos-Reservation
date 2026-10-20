<?php
if (!isset($pendingBookingGroups, $selectedDay, $selectedArea)) {
    http_response_code(404);
    exit;
}
$escapeBooking = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<?php if ($pendingBookingGroups): ?>
<section class="pending-section pending-inbox" aria-labelledby="pendingInboxTitle">
    <h2 id="pendingInboxTitle">Pending bookings &middot; all dates</h2>
    <p class="booking-help">Each card is one checkout, including seats from both dates. Verify its payment screenshot, then approve the whole booking. Requests expire after 12 hours.</p>
    <div class="pending-toolbar" data-pending-toolbar>
        <label class="pending-select-all"><input type="checkbox" id="selectAllPending"> Select all pending bookings</label>
        <form method="POST" action="" id="approveSelectedForm">
            <input type="hidden" name="action" value="approve_batch">
            <input type="hidden" name="day" value="<?php echo $selectedDay; ?>">
            <input type="hidden" name="area" value="<?php echo $selectedArea; ?>">
            <div id="selectedApprovalInputs" hidden></div>
            <button type="submit" class="btn-edit btn-approve" id="approveSelectedButton" disabled>Approve selected bookings</button>
        </form>
    </div>
    <div class="pending-booking-list">
        <?php foreach ($pendingBookingGroups as $group): ?>
            <?php $customer = $group['members'][0]; ?>
            <article class="pending-booking-card"
                data-search-records="<?php echo $escapeBooking(json_encode(array_map(static fn($row) => ['name' => $row['customer_name'], 'phone' => $row['customer_phone']], $group['members']))); ?>"
                data-approval-group="<?php echo $escapeBooking($group['key']); ?>"
                data-approval-version="<?php echo $group['version']; ?>"
                data-approval-ids="<?php echo $escapeBooking(json_encode($group['pending_ids'])); ?>">
                <header class="pending-booking-header">
                    <label class="pending-booking-select">
                        <input type="checkbox" data-approval-checkbox aria-label="Select booking for <?php echo $escapeBooking($customer['customer_name']); ?>">
                        <span><strong><?php echo $escapeBooking($customer['customer_name']); ?></strong><small><?php echo $escapeBooking($customer['customer_phone']); ?> &middot; Booking #<?php echo $escapeBooking(strtoupper(substr($group['key'], 0, 10))); ?></small></span>
                    </label>
                    <div class="pending-booking-total">EGP <?php echo number_format($group['total'], 2); ?><small>Whole booking total</small></div>
                </header>
                <?php foreach ($group['members'] as $member): ?>
                    <div class="pending-booking-member">
                        <div>
                            <strong><?php echo PERFORMANCE_DATES[$member['performance_date']]; ?></strong>
                            <span class="pending-member-status <?php echo $member['approval_status']; ?>"><?php echo $member['approval_status'] === 'pending' ? 'Pending approval' : 'Already approved'; ?></span>
                            <p><?php echo $escapeBooking($member['area_label']); ?> &middot; Reservation #<?php echo (int) $member['id']; ?></p>
                            <div class="pending-member-seats">
                                <?php foreach (explode(',', $member['seat_codes']) as $code): ?>
                                    <span class="seat-badge"><?php echo $escapeBooking($code); ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php if ($member['approval_status'] === 'pending'): ?>
                            <form method="POST" data-reservation-day="<?php echo $member['performance_date']; ?>" action="admoonzz?day=<?php echo $member['performance_date']; ?>&amp;area=<?php echo $selectedArea; ?>&amp;tab=reservations">
                                <input type="hidden" name="action" value="reject">
                                <input type="hidden" name="id" value="<?php echo (int) $member['id']; ?>">
                                <input type="hidden" name="day" data-fixed-day value="<?php echo $member['performance_date']; ?>">
                                <input type="hidden" name="area" value="<?php echo $selectedArea; ?>">
                                <button type="submit" class="btn-delete btn-reject" onclick="return confirm('Reject this date\'s pending reservation?')">Reject this date</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php $comments = array_unique(array_filter(array_column($group['members'], 'notes'), static fn($note) => $note !== null && trim($note) !== '')); ?>
                <?php foreach ($comments as $comment): ?>
                    <p class="pending-booking-comment"><strong>Comments:</strong> <?php echo nl2br($escapeBooking($comment)); ?></p>
                <?php endforeach; ?>
                <footer class="pending-booking-footer">
                    <span>Expires <?php echo date('M d, Y H:i', strtotime($group['expires_at'])); ?></span>
                    <form method="POST" action="" onsubmit="return confirm('Approve all pending reservations in this booking after verifying its payment?')">
                        <input type="hidden" name="action" value="approve_batch">
                        <input type="hidden" name="day" value="<?php echo $selectedDay; ?>">
                        <input type="hidden" name="area" value="<?php echo $selectedArea; ?>">
                        <?php foreach ($group['pending_ids'] as $id): ?><input type="hidden" name="reservation_ids[]" value="<?php echo $id; ?>"><?php endforeach; ?>
                        <button type="submit" class="btn-edit btn-approve">Approve booking</button>
                    </form>
                </footer>
            </article>
        <?php endforeach; ?>
    </div>
    <p class="booking-help" data-pending-empty hidden>No pending bookings match this search.</p>
</section>
<?php endif; ?>
