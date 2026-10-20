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
            ?>

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

            <?php if (count($pendingReservations) > 0): ?>
                <!-- Pending Reservations Section -->
                <div class="pending-section">
                    <p class="booking-help">Verify the WhatsApp payment screenshot before approving a customer request. Unapproved requests are automatically removed after 12 hours.</p>
                    <h2>⏳ Pending Reservations Awaiting Approval</h2>
                    <div class="reservations-container">
                        <table class="reservations-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Customer Name</th>
                                    <th>Phone Number</th>
                                    <th>Area</th>
                                    <th>Reserved Seats</th>
                                    <th>Payment</th>
                                    <th>Comment</th>
                                    <th>Created At</th>
                                    <th>Expires At</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pendingReservations as $reservation): ?>
                                    <tr class="<?php echo isset($reservation['tickets_received']) && $reservation['tickets_received'] == 1 ? 'ticket-received' : ''; ?>">
                                        <td><strong>#<?php echo htmlspecialchars($reservation['id']); ?></strong></td>
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
                                                    Reported paid
                                                <?php else: ?>
                                                    EGP <?php echo number_format($reservation['remaining_amount'], 2); ?>
                                                <?php endif; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="comment-cell">
                                                <?php echo !empty($reservation['notes']) ? htmlspecialchars($reservation['notes']) : '<em>No comment</em>'; ?>
                                            </div>
                                        </td>
                                        <td><?php echo date('M d, Y H:i', strtotime($reservation['created_at'])); ?></td>
                                        <td><?php echo date('M d, Y H:i', strtotime($reservation['expires_at'])); ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <form method="POST" action="" style="display: inline;">
                                                    <input type="hidden" name="action" value="approve">
                                                    <input type="hidden" name="day" value="<?php echo $selectedDay; ?>">
                                                    <input type="hidden" name="area" value="<?php echo $selectedArea; ?>">
                                                    <input type="hidden" name="id" value="<?php echo $reservation['id']; ?>">
                                                    <button type="submit" class="btn-edit btn-approve" onclick="return confirm('Approve this reservation?')">Approve</button>
                                                </form>
                                                <form method="POST" action="" style="display: inline;">
                                                    <input type="hidden" name="action" value="reject">
                                                    <input type="hidden" name="day" value="<?php echo $selectedDay; ?>">
                                                    <input type="hidden" name="area" value="<?php echo $selectedArea; ?>">
                                                    <input type="hidden" name="id" value="<?php echo $reservation['id']; ?>">
                                                    <button type="submit" class="btn-delete btn-reject" onclick="return confirm('Reject and delete this reservation?')">Reject</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Approved Reservations Section -->

            <div class="reservations-container">
                <?php if ($totalReservations > 0): ?>
                    <table class="reservations-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Customer Name</th>
                                <th>Phone Number</th>
                                <th>Area</th>
                                <th>Reserved Seats</th>
                                <th>Remaining Amount</th>
                                <th>Reserved By</th>
                                <th>Created At</th>

                                    <th>Actions</th>

                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($approvedReservations as $reservation): ?>
                                <tr class="<?php echo isset($reservation['tickets_received']) && $reservation['tickets_received'] == 1 ? 'ticket-received' : ''; ?>">
                                    <td><strong>#<?php echo htmlspecialchars($reservation['id']); ?></strong></td>
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
                                    <td><?php echo date('M d, Y H:i', strtotime($reservation['created_at'])); ?></td>

                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn-edit" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($reservation), ENT_QUOTES, 'UTF-8'); ?>)">Edit</button>
                                                <button class="btn-delete" onclick="confirmDelete(<?php echo $reservation['id']; ?>)">Delete</button>
                                            </div>
                                        </td>

                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-reservations">
                        <p>No reservations found for this day.</p>
                    </div>
                <?php endif; ?>
            </div>
