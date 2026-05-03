(function(){
  function closest(el, sel){
    while(el && el.nodeType === 1){
      if(el.matches(sel)) return el;
      el = el.parentElement;
    }
    return null;
  }

  function copyText(text){
    if(navigator.clipboard && navigator.clipboard.writeText){
      return navigator.clipboard.writeText(text);
    }
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.left = '-9999px';
    document.body.appendChild(ta);
    ta.select();
    try{ document.execCommand('copy'); }catch(e){}
    document.body.removeChild(ta);
    return Promise.resolve();
  }

  function request(container){
    var cfg = container.dataset || {};
    var data = new FormData();
    data.append('action','mmgwc_qr_create_template');
    data.append('nonce', (window.MMGWC_QR && MMGWC_QR.nonce) ? MMGWC_QR.nonce : '');
    data.append('type', cfg.type || 'amount');
    data.append('label', cfg.label || 'MMG Payment');
    data.append('amount', cfg.amount || '');
    data.append('product_id', cfg.productId || '');
    data.append('variation_id', cfg.variationId || '');
    data.append('qty', cfg.qty || '1');
    data.append('expires_days', cfg.expiresDays || '7');
    data.append('one_time', cfg.oneTime || 'yes');

    var btn = container.querySelector('.mmgwc-qr-generate');
    if(btn){ btn.disabled = true; btn.classList.add('is-busy'); }
    var msg = container.querySelector('.mmgwc-qr-msg');
    if(msg){ msg.textContent = ''; }

    return fetch((window.MMGWC_QR && MMGWC_QR.ajaxUrl) ? MMGWC_QR.ajaxUrl : '', {
      method: 'POST',
      credentials: 'same-origin',
      body: data
    }).then(function(r){ return r.json(); }).then(function(res){
      if(!res || !res.success){
        throw new Error((res && res.data && res.data.message) ? res.data.message : 'Could not create payment link.');
      }
      var link = res.data.link || '';
      var qrImg = res.data.qr_img || '';
      var linkEl = container.querySelector('.mmgwc-qr-link');
      var imgEl  = container.querySelector('.mmgwc-qr-img');

      if(linkEl){
        linkEl.href = link;
        linkEl.textContent = link;
      }
      if(imgEl && qrImg){
        imgEl.src = qrImg;
        imgEl.alt = 'MMG Payment QR';
      }
      container.classList.add('mmgwc-qr-ready');

      var auto = (cfg.autoRedirect || '').toLowerCase();
      if(auto === 'yes' || auto === 'true' || auto === '1'){
        window.location.href = link;
        return;
      }
      if(msg){ msg.textContent = 'QR link generated. Share it or scan to pay.'; }
    }).catch(function(err){
      if(msg){ msg.textContent = err.message || 'Something went wrong.'; }
    }).finally(function(){
      if(btn){ btn.disabled = false; btn.classList.remove('is-busy'); }
    });
  }

  document.addEventListener('click', function(e){
    var gen = closest(e.target, '.mmgwc-qr-generate');
    if(gen){
      e.preventDefault();
      var container = closest(gen, '.mmgwc-qr');
      if(container) request(container);
      return;
    }
    var copyBtn = closest(e.target, '.mmgwc-qr-copy');
    if(copyBtn){
      e.preventDefault();
      var container = closest(copyBtn, '.mmgwc-qr');
      if(!container) return;
      var linkEl = container.querySelector('.mmgwc-qr-link');
      if(!linkEl || !linkEl.href) return;
      copyText(linkEl.href).then(function(){
        copyBtn.textContent = 'Copied';
        setTimeout(function(){ copyBtn.textContent = 'Copy link'; }, 1200);
      });
    }
  });
})();