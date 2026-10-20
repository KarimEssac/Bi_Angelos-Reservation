<?php
// This fragment is rendered by the authenticated page and live endpoint.
if (!isset($allReservations, $approvedReservations, $pendingReservations, $reservedSeats, $selectedDay, $selectedArea)) {
    http_response_code(404);
    exit;
}
?>
            <?php
            $totalReservations = count($approvedReservations);
            $totalSeatsReserved = count($reservedSeats);
            $totalRemaining = array_sum(array_column($approvedReservations, 'remaining_amount'));
            $collectedData = calculateTotalCollected($approvedReservations);
            $searchableApprovedReservations = array_filter($calendarReservations ?: $allReservations,
                fn($row) => $row['approval_status'] === 'approved');
            ?>

            <div data-daily-overview>
            <p class="reservation-scope">Main Theatre and Balcony reservations &middot; <?php echo PERFORMANCE_DATES[$selectedDay]; ?></p>
            <div class="stats-container">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $totalReservations; ?></div>
                    <div class="stat-label">Total Reservations</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $totalSeatsReserved; ?></div>
                    <div class="stat-label">Seats Reserved</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number">EGP <?php echo number_format($totalRemaining, 2); ?></div>
                    <div class="stat-label">Total Remaining</div>
                </div>
                <div class="stat-card revenue-card">
                    <div class="stat-number">EGP <?php echo number_format($collectedData['total_revenue'], 2); ?></div>
                    <div class="stat-label">Total Collected</div>
                    <div class="revenue-breakdown">
                        <?php foreach ($collectedData['breakdown'] as $type => $group): ?>
                        <?php if ($group['count'] > 0): ?>
                        <div>
                            <span><?php echo ['theatre' => 'Main Theatre', 'khedma' => 'Khedma Theatre', 'balcony' => 'Balcony'][$type]; ?> (<?php echo $group['price']; ?> EGP):</span>
                            <strong><?php echo $group['count']; ?> &times; <?php echo $group['price']; ?> = EGP <?php echo number_format($group['revenue'], 2); ?></strong>
                        </div>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <?php
            // Per-collector breakdown
            $controllerTotals = [];
            foreach ($approvedReservations as $res) {
                if (trim($res['customer_name']) === 'Sponsors') continue;
                $by = !empty($res['reserved_by_name']) ? trim($res['reserved_by_name']) : 'Unknown';
                $seats = explode(',', $res['seat_codes']);
                $count = count($seats);
                if (!isset($controllerTotals[$by])) $controllerTotals[$by] = ['count' => 0, 'total' => 0];
                $controllerTotals[$by]['count'] += $count;
                $controllerTotals[$by]['total'] += reservationTicketTotal($res);
            }
            arsort($controllerTotals); // sort by array value order; re-sort by total descending
            uasort($controllerTotals, fn($a, $b) => $b['total'] <=> $a['total']);
            if (!empty($controllerTotals)):
            ?>
            <div class="collector-card">
                <div class="collector-card-title">
                    &#128100; Collected by Controller
                </div>
                <div class="collector-grid">
                    <?php foreach ($controllerTotals as $name => $data): ?>
                    <div class="collector-row">
                        <div class="collector-avatar"><?php echo htmlspecialchars(mb_strtoupper(mb_substr($name, 0, 1))); ?></div>
                        <div class="collector-info">
                            <div class="collector-name"><?php echo htmlspecialchars($name); ?></div>
                            <div class="collector-seats"><?php echo $data['count']; ?> seat<?php echo $data['count'] != 1 ? 's' : ''; ?></div>
                        </div>
                        <div class="collector-total">EGP <?php echo number_format($data['total'], 2); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            </div>
            <?php require __DIR__ . '/pending_bookings.php'; ?>

            <!-- Approved Reservations Section -->

            <h2 class="confirmed-reservations-title">Confirmed reservations <span data-confirmed-scope><?php echo PERFORMANCE_DATES[$selectedDay]; ?></span></h2>
            <div class="reservations-container" data-confirmed-results <?php if (!$totalReservations): ?>hidden<?php endif; ?>>
                <?php if ($searchableApprovedReservations): ?>
                    <table class="reservations-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Date</th>
                                <th>Customer Name</th>
                                <th>Phone Number</th>
                                <th>Area</th>
                                <th>Reserved Seats</th>
                                <th>Remaining Amount</th>
                                <th>Reserved By</th>
                                <th>Comments</th>
                                <th>Created At</th>

                                    <th>Actions</th>

                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($searchableApprovedReservations as $reservation): ?>
                                <tr data-confirmed-reservation data-performance-date="<?php echo $reservation['performance_date']; ?>"
                                    data-search-records="<?php echo htmlspecialchars(json_encode([['name' => $reservation['customer_name'], 'phone' => $reservation['customer_phone']]]), ENT_QUOTES, 'UTF-8'); ?>"
                                    <?php if ($reservation['performance_date'] !== $selectedDay): ?>hidden<?php endif; ?>
                                    class="<?php echo isset($reservation['tickets_received']) && $reservation['tickets_received'] == 1 ? 'ticket-received' : ''; ?>">
                                    <td><strong>#<?php echo htmlspecialchars($reservation['id']); ?></strong></td>
                                    <td><?php echo PERFORMANCE_DATES[$reservation['performance_date']]; ?></td>
                                    <td><?php echo htmlspecialchars($reservation['customer_name']); ?></td>
                                    <td><?php echo htmlspecialchars($reservation['customer_phone']); ?></td>
                                    <td><?php echo htmlspecialchars($reservation['area_label'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <div class="seats-cell">
                                            <?php 
                                            $seats = explode(',', $reservation['seat_codes']);
                                            foreach ($seats as $seat): 
                                            ?>
                                                <span class="seat-badge"><?php echo trim(htmlspecialchars($seat)); ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="remaining-amount <?php echo $reservation['remaining_amount'] == 0 ? 'remaining-paid' : ''; ?>">
                                            <?php if ($reservation['remaining_amount'] == 0): ?>
                                                Paid
                                            <?php else: ?>
                                                EGP <?php echo number_format($reservation['remaining_amount'], 2); ?>
                                            <?php endif; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo !empty($reservation['reserved_by_name']) ? htmlspecialchars($reservation['reserved_by_name']) : '<em>N/A</em>'; ?>
                                    </td>
                                    <td><div class="comment-cell"><?php echo !empty($reservation['notes']) ? nl2br(htmlspecialchars($reservation['notes'], ENT_QUOTES, 'UTF-8')) : '<em>No comment</em>'; ?></div></td>
                                    <td><?php echo date('M d, Y H:i', strtotime($reservation['created_at'])); ?></td>

                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn-edit" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($reservation), ENT_QUOTES, 'UTF-8'); ?>)">Edit</button>
                                                <button class="btn-delete" onclick="confirmDelete(<?php echo $reservation['id']; ?>, '<?php echo $reservation['performance_date']; ?>')">Delete</button>
                                            </div>
                                        </td>

                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            <p class="no-reservations" data-confirmed-empty <?php if ($totalReservations): ?>hidden<?php endif; ?>>No confirmed reservations found for this date.</p>
