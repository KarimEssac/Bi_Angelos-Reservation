<?php
if (!defined('PUBLIC_BOOKING_PAGE')) {
    http_response_code(404);
    exit;
}
?>
<div class="payment-details">
    <h3>Instapay payment</h3>
    <p>Pay the full booking total to the account shown below. Scan its QR code or open the direct link.</p>
    <div class="instapay-recipients" data-payment-account hidden>
        <div class="instapay-recipient">
            <a data-payment-qr-link target="_blank" rel="noopener noreferrer" aria-label="Open the full-size Instapay QR code">
                <img class="instapay-qr" data-payment-qr alt="Instapay payment QR code" width="516" height="552">
            </a>
            <a class="instapay-redirect" data-payment-redirect target="_blank" rel="noopener noreferrer">Click here to pay with Instapay</a>
        </div>
    </div>
    <p>Send a screenshot of your Instapay payment and the exact name used for this reservation using
        <a class="payment-whatsapp" target="_blank" rel="noopener noreferrer">click here to go to whatsapp</a>
        <span data-payment-contact hidden>or contact <span data-payment-phone></span></span>.</p>
    <p class="payment-note">Your payment will be checked by an admin. Unapproved requests expire automatically after a short period.</p>
</div>
