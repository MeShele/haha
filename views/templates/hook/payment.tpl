<div class="freedompay-payment-option">
    <a href="{$link->getModuleLink('freedompay', 'payment')}" class="freedompay-payment-link">
        <img src="{$module_dir}logo.png" alt="FreedomPay" class="freedompay-logo" width="60">
        <span>{l s='Pay securely with FreedomPay' mod='freedompay'}</span>
        <span class="booking-total">
            {l s='Total:' mod='freedompay'} {Tools::displayPrice($booking_total)}
        </span>
        <span class="payment-arrow">→</span>
    </a>
</div>