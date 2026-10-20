<?php
if (!defined('PUBLIC_BOOKING_PAGE')) {
    http_response_code(404);
    exit;
}
?>
<div class="payment-details">
    <h3>Instapay payment</h3>
    <?php if (INSTAPAY_ACCOUNT !== ''): ?>
        <p class="instapay-account"><?php echo htmlspecialchars(INSTAPAY_ACCOUNT, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php else: ?>
        <p class="instapay-placeholder">Instapay details will be added here. Contact us on WhatsApp for the payment details before paying.</p>
    <?php endif; ?>
    <p>Send a screenshot of your Instapay payment and the exact name used for this reservation to
        <a class="payment-whatsapp" href="<?php echo BOOKING_WHATSAPP_URL; ?>" target="_blank" rel="noopener noreferrer"><?php echo BOOKING_WHATSAPP; ?></a> on WhatsApp.</p>
    <p class="payment-note">Your payment will be checked by an admin. Unapproved requests expire automatically after 12 hours.</p>
</div>
