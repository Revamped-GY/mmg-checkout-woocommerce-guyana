(function () {
  'use strict';

  function ensureCheckoutNonce() {
    try {
      var nonceValue = (window.MMGWCCheckoutCompat && window.MMGWCCheckoutCompat.nonce) ? window.MMGWCCheckoutCompat.nonce : '';
      if (!nonceValue) {
        return;
      }

      // Classic checkout form.
      var form = document.querySelector('form.checkout');
      // Some themes (and order-pay) can use a different wrapper.
      if (!form) {
        form = document.querySelector('form#order_review');
      }
      if (!form) {
        return;
      }

      // WooCommerce expects this exact field name.
      if (form.querySelector('input[name="woocommerce-process-checkout-nonce"]')) {
        return;
      }

      var input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'woocommerce-process-checkout-nonce';
      input.value = nonceValue;
      form.appendChild(input);
    } catch (e) {
      // Silent fail.
    }
  }

  document.addEventListener('DOMContentLoaded', ensureCheckoutNonce);
  document.body.addEventListener('updated_checkout', ensureCheckoutNonce);
})();
