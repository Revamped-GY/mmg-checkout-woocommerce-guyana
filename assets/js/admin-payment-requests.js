(function($){
  'use strict';

  function reindexRows($table, rowSelector, prefix){
    var i = 0;
    $table.find(rowSelector).each(function(){
      var $row = $(this);
      $row.find('select, input, textarea').each(function(){
        var $el = $(this);
        var name = $el.attr('name');
        if(!name){ return; }
        // Replace leading index inside brackets: prefix[0][field]
        name = name.replace(new RegExp('^' + prefix + '\\[\\d+\\]'), prefix + '[' + i + ']');
        $el.attr('name', name);
      });
      i++;
    });
  }

  function initEnhanced($ctx){
    if(typeof $.fn.selectWoo === 'function'){
      // WooCommerce uses selectWoo. The wc-enhanced-select script will handle elements by class.
      // Trigger init in case WooCommerce didn't auto-init on this admin page.
      $(document.body).trigger('wc-enhanced-select-init');
    }
  }

  $(function(){
    // Copy link buttons
    $(document).on('click', '.mmgwc-copy', function(e){
      e.preventDefault();
      var text = $(this).data('copy') || '';
      if(!text){ return; }
      if(navigator && navigator.clipboard && navigator.clipboard.writeText){
        navigator.clipboard.writeText(text).then(function(){}, function(){});
      } else {
        var $tmp = $('<input>');
        $('body').append($tmp);
        $tmp.val(text).select();
        document.execCommand('copy');
        $tmp.remove();
      }
      var $btn = $(this);
      var original = $btn.text();
      $btn.text((window.mmgwcPR && mmgwcPR.copySuccess) ? mmgwcPR.copySuccess : 'Copied');
      setTimeout(function(){ $btn.text(original); }, 1200);
    });

    // Product rows
    $('#mmgwc-pr-add-product').on('click', function(){
      var $table = $('#mmgwc-pr-products tbody');
      var $first = $table.find('tr.mmgwc-pr-row:first');
      var $clone = $first.clone();
      $clone.find('select').val('').trigger('change');
      $clone.find('input[type=number]').val('1');
      $table.append($clone);
      reindexRows($('#mmgwc-pr-products tbody'), 'tr.mmgwc-pr-row', 'products');
      initEnhanced($clone);
    });

    $(document).on('click', '.mmgwc-pr-remove', function(){
      var $row = $(this).closest('tr');
      var $tbody = $('#mmgwc-pr-products tbody');
      if($tbody.find('tr.mmgwc-pr-row').length <= 1){
        $row.find('select').val('').trigger('change');
        $row.find('input[type=number]').val('1');
        return;
      }
      $row.remove();
      reindexRows($('#mmgwc-pr-products tbody'), 'tr.mmgwc-pr-row', 'products');
    });

    // Custom item rows
    $('#mmgwc-pr-add-custom').on('click', function(){
      var $table = $('#mmgwc-pr-custom tbody');
      var $first = $table.find('tr.mmgwc-pr-custom-row:first');
      var $clone = $first.clone();
      $clone.find('input').val('');
      $table.append($clone);
      reindexRows($('#mmgwc-pr-custom tbody'), 'tr.mmgwc-pr-custom-row', 'custom_items');
    });

    $(document).on('click', '.mmgwc-pr-remove-custom', function(){
      var $row = $(this).closest('tr');
      var $tbody = $('#mmgwc-pr-custom tbody');
      if($tbody.find('tr.mmgwc-pr-custom-row').length <= 1){
        $row.find('input').val('');
        return;
      }
      $row.remove();
      reindexRows($('#mmgwc-pr-custom tbody'), 'tr.mmgwc-pr-custom-row', 'custom_items');
    });

    initEnhanced($(document));
  });

})(jQuery);
