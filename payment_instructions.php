<?php
if (!defined('PUBLIC_BOOKING_PAGE')) {
    http_response_code(404);
    exit;
}
?>
<div class="payment-details">
    <h3>Instapay payment</h3>
    <p>Pay the full booking total to <strong>one</strong> of these accounts. Scan its QR code or open its direct link.</p>
    <div class="instapay-recipients">
        <?php foreach (INSTAPAY_RECIPIENTS as $recipient): ?>
            <div class="instapay-recipient">
                <h4><?php echo htmlspecialchars($recipient['name'], ENT_QUOTES, 'UTF-8'); ?></h4>
                <a href="<?php echo $recipient['qr']; ?>" target="_blank" rel="noopener noreferrer" aria-label="Open full-size QR code for <?php echo htmlspecialchars($recipient['name'], ENT_QUOTES, 'UTF-8'); ?>">
                    <img class="instapay-qr" src="<?php echo $recipient['qr']; ?>" alt="Instapay QR code for <?php echo htmlspecialchars($recipient['name'], ENT_QUOTES, 'UTF-8'); ?>" width="516" height="552">
                </a>
                <p class="instapay-address"><?php echo htmlspecialchars($recipient['address'], ENT_QUOTES, 'UTF-8'); ?></p>
                <a class="instapay-redirect" href="<?php echo htmlspecialchars($recipient['url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">Click here for direct redirect</a>
            </div>
        <?php endforeach; ?>
    </div>
    <p>Send a screenshot of your Instapay payment and the exact name used for this reservation to
        <a class="payment-whatsapp" href="<?php echo BOOKING_WHATSAPP_URL; ?>" target="_blank" rel="noopener noreferrer"><?php echo BOOKING_WHATSAPP; ?></a> on WhatsApp.</p>
    <p class="payment-note">Your payment will be checked by an admin. Unapproved requests expire automatically after 12 hours.</p>
</div>
