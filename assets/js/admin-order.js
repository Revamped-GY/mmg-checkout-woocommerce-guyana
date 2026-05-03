(function($){
  'use strict';

  function esc(s){
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/\"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function setResult($box, type, msg){
    if(!$box || !$box.length){
      return;
    }
    var cls = 'notice notice-' + (type || 'info');
    $box.html('<div class="' + cls + '" style="margin:0"><p style="margin:6px 0">' + esc(msg) + '</p></div>');
  }

  function getAjaxUrl(){
    if(window.MMGWCAdminOrder && MMGWCAdminOrder.ajaxUrl){
      return MMGWCAdminOrder.ajaxUrl;
    }
    if(window.ajaxurl){
      return window.ajaxurl;
    }
    return '';
  }

  $(function(){
    // Delegated binding because WooCommerce may re-render order UI after DOM ready.
    $(document).on('submit', '.mmgwc-verify-form', function(e){
      e.preventDefault();

      var $form = $(this);
      var ajaxAction = $form.data('ajax-action');
      var nonce = $form.data('ajax-nonce');
      var orderId = $form.data('order-id');

      var $btn = $form.find('button[type=submit]');
      var $box = $form.find('.mmgwc-verify-result');
      if(!$box.length){
        $box = $('#mmgwc-verify-result');
      }

      if(!ajaxAction || !nonce || !orderId){
        setResult($box, 'error', 'MMG verification is not configured correctly on this screen. Refresh the page.');
        return;
      }

      var txnId = $.trim(String($form.find('input[name=txn_id]').val() || ''));
      if(!txnId){
        // Backwards compatibility with older markup.
        txnId = $.trim(String($('#mmgwc_txn_id').val() || ''));
      }
      if(!txnId){
        setResult($box, 'error', 'Please enter an MMG Transaction ID first.');
        return;
      }

      var ajaxUrl = getAjaxUrl();
      if(!ajaxUrl){
        setResult($box, 'error', 'Ajax URL not available.');
        return;
      }

      $btn.prop('disabled', true);

      var verifying = (window.MMGWCAdminOrder && MMGWCAdminOrder.i18nVerifying) ? MMGWCAdminOrder.i18nVerifying : 'Verifying payment...';
      setResult($box, 'info', verifying);

      $.post(ajaxUrl, {
        action: ajaxAction,
        order_id: orderId,
        txn_id: txnId,
        nonce: nonce
      })
        .done(function(resp){
          if(resp && resp.success){
            var msgOk = (resp.data && resp.data.message) ? resp.data.message : 'Verification complete.';
            setResult($box, 'success', msgOk);
            if(resp.data && resp.data.reload){
              setTimeout(function(){ window.location.reload(); }, 800);
            }
          } else {
            var msgFail = (resp && resp.data && resp.data.message) ? resp.data.message : 'Verification failed.';
            setResult($box, 'error', msgFail);
          }
        })
        .fail(function(xhr){
          var msg = 'Verification failed.';
          if(xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message){
            msg = xhr.responseJSON.data.message;
          }
          setResult($box, 'error', msg);
        })
        .always(function(){
          $btn.prop('disabled', false);
        });
    });
  });
})(jQuery);
