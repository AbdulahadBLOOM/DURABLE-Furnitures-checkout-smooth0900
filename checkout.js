/* Checkout, order confirmation and order tracking (needs app.js + jQuery) */
(function () {
  var API = 'backend/api/';
  var CFG = { payment_methods: [], delivery_fee: 0, free_delivery_over: 0, max_qty: 10, store: {} };

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function rs(n) { return 'Rs.' + Number(n || 0).toLocaleString('en-IN'); }
  function priceNum(p) { return parseInt(String(p).replace(/\D/g, ''), 10) || 0; }
  function fmtDate(s) { var d = new Date(String(s).replace(' ', 'T')); return isNaN(d) ? s : d.toLocaleString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }); }
  function api(path, opts) { return fetch(API + path, opts).then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Unexpected server response.' }; }); }); }
  var REDUCED = !!(window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches);
  function reflow(el) { return el && el.offsetWidth; }
  function bump($e, cls) { $e.removeClass(cls || 'co-bump'); reflow($e[0]); $e.addClass(cls || 'co-bump'); }
  /* smoothly count a number to its new value (ease-out) */
  function countTo(el, to, fmt) {
    if (!el) return; fmt = fmt || rs;
    var from = el._v == null ? 0 : el._v; el._v = to;
    cancelAnimationFrame(el._raf);
    if (REDUCED || from === to) { el.textContent = fmt(to); return; }
    var t0 = performance.now(), dur = 560;
    (function step(now) {
      var p = Math.min(1, (now - t0) / dur), e = 1 - Math.pow(1 - p, 3);
      el.textContent = fmt(Math.round(from + (to - from) * e));
      if (p < 1) el._raf = requestAnimationFrame(step);
    })(t0);
    bump($(el), 'co-pop');
  }
  function loadCfg() { return api('checkout_config.php').then(function (d) { if (d.ok) CFG = d; return CFG; }).catch(function () { return CFG; }); }

  /* ─────────── CHECKOUT PAGE ─────────── */
  function initCheckout() {
    var $form = $('#checkoutForm'); if (!$form.length) return;

    function totals() {
      var sub = cart.reduce(function (s, i) { return s + priceNum(i.price) * i.qty; }, 0);
      var del = CFG.delivery_fee; if (CFG.free_delivery_over > 0 && sub >= CFG.free_delivery_over) del = 0;
      var disc = coupon ? Math.min(coupon.discount, sub) : 0;
      return { sub: sub, del: del, disc: disc, total: sub - disc + del };
    }
    var coupon = null;
    function showCpMsg(text, ok) {
      var $m = $('#coCpMsg').addClass('d-none'); reflow($m[0]);
      $m.removeClass('d-none ok bad').addClass(ok ? 'ok' : 'bad').text(text);
      if (!ok) { var $r = $('.co-cp-row').removeClass('co-shake'); reflow($r[0]); $r.addClass('co-shake'); }
    }
    function applyCoupon(code, quiet) {
      if (!code) { showCpMsg('Enter a coupon code first.', false); return; }
      if (!quiet) $('#coApply').prop('disabled', true).addClass('busy');
      api('coupon.php', { method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ code: code, items: cart.map(function (i) { return { name: i.name, price: i.price, qty: i.qty }; }) }) }).then(function (d) {
        if (d.ok) { coupon = { code: d.code, discount: d.discount, label: d.label }; showCpMsg(d.code + ' applied – ' + d.label + ' (you save ' + rs(d.discount) + ')', true); $('#coApply').text('Remove'); }
        else { coupon = null; $('#coApply').text('Apply'); if (!quiet) showCpMsg(d.error || 'Invalid coupon.', false); else showCpMsg('Coupon removed: ' + (d.error || 'no longer valid.'), false); }
        $('#coApply').prop('disabled', false).removeClass('busy');
        renderSummary(true);
      }).catch(function () { $('#coApply').prop('disabled', false).removeClass('busy'); showCpMsg('Could not check the coupon. Please try again.', false); });
    }
    $('#coApply').on('click', function () {
      if (coupon) { coupon = null; $('#coCoupon').val(''); $('#coCpMsg').addClass('d-none'); $(this).text('Apply'); renderSummary(true); return; }
      applyCoupon($.trim($('#coCoupon').val()), false);
    });
    $('#coCoupon').on('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); $('#coApply').click(); } });
    function renderSummary(skipRecheck) {
      if (coupon && !skipRecheck && cart.length) { applyCoupon(coupon.code, true); }   // cart changed → re-validate against the new subtotal
      if (!cart.length) { $form.addClass('d-none'); $('#coEmpty').removeClass('d-none'); return; }
      $form.removeClass('d-none'); $('#coEmpty').addClass('d-none');
      var $items = $('#coItems'), $rows = $items.children('.co-item');
      var same = $rows.length === cart.length && cart.every(function (it, i) { return $rows.eq(i).attr('data-name') === it.name; });
      if (same) {
        // same items: update in place so nothing flickers
        cart.forEach(function (it, i) {
          var $r = $rows.eq(i), $q = $r.find('.co-qty b');
          if (+$q.text() !== it.qty) { $q.text(it.qty); bump($q); }
          countTo($r.find('.co-iprice')[0], priceNum(it.price) * it.qty);
        });
      } else {
        var html = '';
        cart.forEach(function (it, idx) {
          html += '<div class="co-item co-enter" style="--i:' + idx + '" data-name="' + esc(it.name) + '"><div class="co-thumb">' + (it.img ? '<img src="' + esc(it.img) + '" alt="">' : '<i class="bi bi-lamp"></i>') + '</div>' +
            '<div><div class="co-iname">' + esc(it.name) + '</div><div class="co-imeta">' + esc(it.brand) + ' · ' + esc(it.price) + ' each</div>' +
            '<div class="co-qty"><button type="button" data-q="-1" data-i="' + idx + '" aria-label="Decrease quantity">−</button><b>' + it.qty + '</b><button type="button" data-q="1" data-i="' + idx + '" aria-label="Increase quantity">+</button>' +
            '<button type="button" class="co-rm" data-rm="' + idx + '"><i class="bi bi-trash3"></i> Remove</button></div></div>' +
            '<div class="co-iprice">' + rs(priceNum(it.price) * it.qty) + '</div></div>';
        });
        $items.html(html);
        $items.find('.co-iprice').each(function (i) { this._v = priceNum(cart[i].price) * cart[i].qty; });
      }
      var t = totals();
      countTo($('#coSub')[0], t.sub); $('#coDel').text(t.del ? rs(t.del) : 'Free'); countTo($('#coTotal')[0], t.total);
      $('#coDiscRow').toggleClass('d-none', !t.disc); if (t.disc) { countTo($('#coDisc')[0], t.disc, function (n) { return '−' + rs(n); }); $('#coDiscLbl').text('Discount (' + coupon.code + ')'); }
    }
    $('#coItems').on('click', '[data-q]', function () {
      var it = cart[+$(this).data('i')]; if (!it) return;
      var nq = it.qty + (+$(this).data('q'));
      if (nq > CFG.max_qty) { showToast('Maximum ' + CFG.max_qty + ' per item'); return; }
      changeQty(it.name, +$(this).data('q')); renderSummary();
    }).on('click', '[data-rm]', function () {
      var it = cart[+$(this).data('rm')]; if (!it) return;
      var $row = $(this).closest('.co-item'); if ($row.hasClass('co-leave')) return;
      var done = function () { removeFromCart(it.name); renderSummary(); };
      if (REDUCED) return done();
      $row.addClass('co-leave'); setTimeout(done, 300);
    });

    function renderPay() {
      var html = '';
      CFG.payment_methods.forEach(function (m, i) {
        html += '<label class="co-pay co-enter' + (i === 0 ? ' sel' : '') + '" style="--i:' + i + '"><input type="radio" name="pay" value="' + esc(m.key) + '"' + (i === 0 ? ' checked' : '') + '>' +
          '<span><b>' + esc(m.label) + '</b><small>' + esc(m.note) + '</small></span></label>';
      });
      $('#coPayList').html(html || '<div class="co-err">Payment options unavailable. Please refresh.</div>');
    }
    $('#coPayList').on('change', 'input', function () { $('.co-pay').removeClass('sel'); $(this).closest('.co-pay').addClass('sel'); });

    // remember details for next time
    try {
      var saved = JSON.parse(localStorage.getItem('df_customer') || '{}');
      ['name', 'phone', 'email', 'address', 'area', 'city', 'postal'].forEach(function (k) {
        var id = '#co' + k.charAt(0).toUpperCase() + k.slice(1); if (saved[k]) $(id).val(saved[k]);
      });
    } catch (e) {}

    // signed-in customers: fill blanks from their account profile + show a small notice
    function applyAccount(me) {
      if (!me || !me.customer) return;
      var c = me.customer, map = { name: c.full_name, email: c.email, phone: c.phone, address: c.address, area: c.area, city: c.city, postal: c.postal_code };
      Object.keys(map).forEach(function (k) { var $f = $('#co' + k.charAt(0).toUpperCase() + k.slice(1)); if ($f.length && !$.trim($f.val()) && map[k]) $f.val(map[k]); });
      if (!$('#coAcctNote').length) $form.before('<div id="coAcctNote" class="co-alert" style="background:rgba(16,185,129,.1);border-color:rgba(16,185,129,.35);color:#10b981"><i class="bi bi-person-check me-1"></i>Signed in as <b>' + esc(c.full_name) + '</b> – this order will be saved to <a href="backend/customer/" style="color:inherit;text-decoration:underline">your account</a>.</div>');
    }
    if (window.dfMe) applyAccount(window.dfMe); else document.addEventListener('df:me', function (e) { applyAccount(e.detail); });

    function clearErrors() { $('.co-err').text(''); $('.co-input').removeClass('invalid'); $('#coAlert').addClass('d-none').empty(); reflow(document.body); }
    function showFieldErrors(f) {
      Object.keys(f || {}).forEach(function (k) { $('[data-for="' + k + '"]').text(f[k]); $('[name="' + k + '"]').addClass('invalid'); });
      var first = $('.co-input.invalid').first(); if (first.length) $('html,body').animate({ scrollTop: first.offset().top - 130 }, 300);
    }
    function clientValidate(c) {
      var e = {};
      if (c.name.length < 3) e.name = 'Please enter your full name.';
      if (!/^[+\d\s\-()]{10,18}$/.test(c.phone)) e.phone = 'Enter a valid phone number (e.g. 0300 1234567).';
      if (c.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(c.email)) e.email = 'Enter a valid email address.';
      if (c.address.length < 8) e.address = 'Please enter your full delivery address.';
      if (c.city.length < 2) e.city = 'Please enter your city.';
      return e;
    }

    $form.on('submit', function (ev) {
      ev.preventDefault(); clearErrors();
      if (!cart.length) return;
      var c = {
        name: $.trim($('#coName').val()), phone: $.trim($('#coPhone').val()), email: $.trim($('#coEmail').val()),
        address: $.trim($('#coAddress').val()), area: $.trim($('#coArea').val()), city: $.trim($('#coCity').val()),
        postal: $.trim($('#coPostal').val()), notes: $.trim($('#coNotes').val())
      };
      var errs = clientValidate(c);
      if (Object.keys(errs).length) { showFieldErrors(errs); return; }

      var key = sessionStorage.getItem('df_req_key');
      if (!key) { key = (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : ('k' + Date.now() + Math.random().toString(16).slice(2)); sessionStorage.setItem('df_req_key', key); }

      var $b = $('#coSubmit').prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Placing order…');
      api('place_order.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          customer: c, payment_method: $('input[name="pay"]:checked').val() || '', request_key: key, website: $('#coHp').val(), coupon: coupon ? coupon.code : '',
          items: cart.map(function (i) { return { brand: i.brand, name: i.name, price: i.price, qty: i.qty, img: i.img || '' }; })
        })
      }).then(function (d) {
        if (d.ok) {
          try { localStorage.setItem('df_customer', JSON.stringify({ name: c.name, phone: c.phone, email: c.email, address: c.address, area: c.area, city: c.city, postal: c.postal })); } catch (e) {}
          cart = []; saveCart(); sessionStorage.removeItem('df_req_key');
          $b.addClass('is-done').html('<i class="bi bi-check2-circle me-2"></i>Order placed');
          if (REDUCED) { window.location.href = d.redirect; return; }
          setTimeout(function () { document.documentElement.classList.add('pl-leaving'); }, 520);
          setTimeout(function () { window.location.href = d.redirect; }, 760);
          return;
        }
        $b.prop('disabled', false).html('<i class="bi bi-lock-fill me-2"></i>Place Order');
        if (d.fields) showFieldErrors(d.fields);
        var msg = '<i class="bi bi-exclamation-circle me-1"></i>' + esc(d.error || 'Could not place order.');
        if (d.code === 'bad_coupon') { coupon = null; $('#coApply').text('Apply'); renderSummary(true); }
        if (d.code === 'bad_items') {
          msg += '<br><button type="button" class="co-btn mt-2" id="coFix">Remove unavailable items</button>';
          $('#coAlert').html(msg).removeClass('d-none');
          $('#coFix').on('click', function () { (d.items || []).forEach(function (n) { removeFromCart(n.replace(/ \(quantity.*$/, '')); }); renderSummary(); clearErrors(); });
        } else $('#coAlert').html(msg).removeClass('d-none');
        $('html,body').animate({ scrollTop: $('#coAlert').offset().top - 130 }, 300);
        if (d.code !== 'bad_items') sessionStorage.removeItem('df_req_key');
      }).catch(function () {
        $b.prop('disabled', false).html('<i class="bi bi-lock-fill me-2"></i>Place Order');
        $('#coAlert').html('<i class="bi bi-wifi-off me-1"></i>Could not reach the server. Check your connection and try again.').removeClass('d-none');
      });
    });

    renderSummary();
    loadCfg().then(function () { renderPay(); renderSummary(); });
  }

  /* ─────────── shared order renderers ─────────── */
  var FLOW = ['pending', 'confirmed', 'processing', 'shipped', 'delivered'];
  function stepper(status) {
    if (status === 'cancelled') return '<div class="co-alert">This order was cancelled.</div>';
    var at = FLOW.indexOf(status), h = '<div class="steps">';
    FLOW.forEach(function (s, i) { h += '<div class="step ' + (i <= at ? 'done' : '') + '"><i>' + (i <= at ? '✓' : (i + 1)) + '</i>' + s + '</div>'; });
    return h + '</div>';
  }
  function history(o) {
    return '<ul class="tl-list">' + (o.history || []).slice().reverse().map(function (h) {
      return '<li><span class="co-badge st-' + esc(h.status) + '">' + esc(h.status) + '</span><small>' + esc(fmtDate(h.created_at)) + '</small>' + (h.note ? esc(h.note) : '') + '</li>';
    }).join('') + '</ul>';
  }
  function itemsTable(o) {
    var h = '';
    o.items.forEach(function (it) {
      h += '<div class="co-item"><div class="co-thumb">' + (it.image ? '<img src="' + esc(it.image) + '" alt="">' : '<i class="bi bi-lamp"></i>') + '</div>' +
        '<div><div class="co-iname">' + esc(it.name) + '</div><div class="co-imeta">' + esc(it.brand) + ' · ' + rs(it.unit_price) + ' × ' + it.qty + '</div></div>' +
        '<div class="co-iprice">' + rs(it.line_total) + '</div></div>';
    });
    return h + '<div class="co-line mt-2"><span>Subtotal</span><span>' + rs(o.subtotal) + '</span></div>' +
      '<div class="co-line"><span>Delivery</span><span>' + (+o.delivery_fee ? rs(o.delivery_fee) : 'Free') + '</span></div>' +
      '<div class="co-line total"><span>Total</span><span>' + rs(o.total) + '</span></div>';
  }
  function kv(a, b) { return '<div class="kv-row"><span>' + a + '</span><span>' + (b || '—') + '</span></div>'; }

  /* ─────────── ORDER SUCCESS PAGE ─────────── */
  function initSuccess() {
    if (!$('#osBody').length) return;
    var p = new URLSearchParams(location.search), no = p.get('o'), tk = p.get('t');
    if (!no || !tk) { $('#osLoading').addClass('d-none'); $('#osError').removeClass('d-none'); return; }
    api('get_order.php?o=' + encodeURIComponent(no) + '&t=' + encodeURIComponent(tk)).then(function (d) {
      $('#osLoading').addClass('d-none');
      if (!d.ok) { $('#osErrMsg').text(d.error || 'Order not found.'); $('#osError').removeClass('d-none'); return; }
      var o = d.order, st = d.store, wa = 'https://wa.me/' + String(st.phone).replace(/\D/g, '') + '?text=' + encodeURIComponent('Hi, my order number is ' + o.order_number);
      document.title = 'Order ' + o.order_number + ' – Durable Furnitures';
      var html =
        '<div class="co-card"><div class="ok-hero"><div class="ok-check"><i class="bi bi-check-lg"></i></div>' +
        '<h3 style="font-family:var(--font-serif);color:var(--text)">Order placed successfully!</h3>' +
        '<div class="ok-no">' + esc(o.order_number) + '</div>' +
        '<p class="co-imeta mt-2">Placed on ' + esc(fmtDate(o.created_at)) + (o.email ? ' · confirmation sent to ' + esc(o.email) : '') + '</p></div>' +
        stepper(o.status) + '</div>' +
        '<div class="row g-4"><div class="col-lg-7"><div class="co-card"><h4><i class="bi bi-bag-check"></i>Items Ordered</h4>' + itemsTable(o) + '</div>' +
        '<div class="co-card"><h4><i class="bi bi-clock-history"></i>Order Timeline</h4>' + history(o) + '</div></div>' +
        '<div class="col-lg-5"><div class="co-card"><h4><i class="bi bi-truck"></i>Delivery Details</h4>' +
        kv('Name', esc(o.customer_name)) + kv('Phone', esc(o.phone)) + kv('Email', esc(o.email)) +
        kv('Address', esc(o.address)) + kv('Area', esc(o.area)) + kv('City', esc(o.city) + (o.postal_code ? ' ' + esc(o.postal_code) : '')) +
        kv('Your note', esc(o.notes)) + kv('Estimated delivery', '7–10 working days') + '</div>' +
        '<div class="co-card"><h4><i class="bi bi-credit-card"></i>Payment</h4>' +
        kv('Method', esc(o.payment_label)) + kv('Status', '<span class="co-badge st-' + esc(o.payment_status) + '">' + esc(o.payment_status) + '</span>') + kv('Amount', '<b>' + rs(o.total) + '</b>') +
        (o.payment_note ? '<p class="co-imeta mt-3 mb-0">' + esc(o.payment_note) + '</p>' : '') + '</div>' +
        '<div class="no-print d-flex flex-wrap gap-2"><button class="co-btn" onclick="window.print()"><i class="bi bi-printer"></i>Print invoice</button>' +
        '<a class="co-wa" href="' + wa + '" target="_blank" rel="noopener"><i class="bi bi-whatsapp"></i>Message us</a>' +
        '<a class="co-btn" href="brands.html"><i class="bi bi-arrow-left"></i>Keep shopping</a></div>' +
        '<p class="co-imeta mt-3 no-print">Save this page link or your order number to track the order later at <a href="track-order.html">Track Order</a>.</p></div></div>' +
        '<p class="co-imeta text-center mt-3" style="display:none" id="osPrintFoot">' + esc(st.name) + ' · ' + esc(st.address) + ' · ' + esc(st.phone) + '</p>';
      $('#osBody').html(html).removeClass('d-none');
      if (p.get('print') === '1') setTimeout(function () { window.print(); }, 600);
    }).catch(function () { $('#osLoading').addClass('d-none'); $('#osErrMsg').text('Could not reach the server.'); $('#osError').removeClass('d-none'); });
  }

  /* ─────────── TRACK ORDER PAGE ─────────── */
  function initTrack() {
    var $f = $('#trackForm'); if (!$f.length) return;
    var p = new URLSearchParams(location.search);
    if (p.get('o')) $('#trNo').val(p.get('o'));
    $f.on('submit', function (e) {
      e.preventDefault(); $('#trErr').addClass('d-none'); $('#trResult').empty();
      var no = $.trim($('#trNo').val()).toUpperCase(), ph = $.trim($('#trPhone').val());
      if (!no || !ph) { $('#trErr').text('Enter both your order number and phone number.').removeClass('d-none'); return; }
      $('#trBtn').prop('disabled', true).text('Searching…');
      api('track_order.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ order_number: no, phone: ph }) })
        .then(function (d) {
          $('#trBtn').prop('disabled', false).text('Track');
          if (!d.ok) { $('#trErr').text(d.error || 'Order not found.').removeClass('d-none'); return; }
          var o = d.order;
          $('#trResult').html('<div class="co-card"><div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div><div class="ok-no">' + esc(o.order_number) + '</div><div class="co-imeta">Placed ' + esc(fmtDate(o.created_at)) + '</div></div>' +
            '<span class="co-badge st-' + esc(o.status) + '">' + esc(o.status) + '</span></div>' + stepper(o.status) + '</div>' +
            '<div class="row g-4"><div class="col-md-7"><div class="co-card"><h4><i class="bi bi-bag-check"></i>Items</h4>' + itemsTable(o) + '</div></div>' +
            '<div class="col-md-5"><div class="co-card"><h4><i class="bi bi-clock-history"></i>Timeline</h4>' + history(o) + '</div>' +
            '<div class="co-card"><h4><i class="bi bi-geo-alt"></i>Delivering to</h4>' + kv('Name', esc(o.customer_name)) + kv('Address', esc(o.address) + (o.area ? ', ' + esc(o.area) : '') + ', ' + esc(o.city)) +
            kv('Payment', esc(o.payment_label) + ' (' + esc(o.payment_status) + ')') + '</div>' +
            '<a class="co-btn" href="' + esc(o.invoice_link) + '"><i class="bi bi-receipt"></i>View full invoice</a></div></div>');
        }).catch(function () { $('#trBtn').prop('disabled', false).text('Track'); $('#trErr').text('Could not reach the server.').removeClass('d-none'); });
    });
  }

  $(function () { initCheckout(); initSuccess(); initTrack(); });
})();
