/* Durable Furnitures – admin + customer panel behaviour (vanilla JS, no dependencies). */
(function () {
  'use strict';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };

  /* ── toast (used by copy / wishlist / cart actions) ── */
  function toast(msg, bad) {
    var box = $('#ptoasts');
    if (!box) { box = document.createElement('div'); box.id = 'ptoasts'; document.body.appendChild(box); }
    var t = document.createElement('div'); t.className = 'ptoast' + (bad ? ' bad' : '');
    t.innerHTML = '<i class="bi ' + (bad ? 'bi-exclamation-triangle' : 'bi-check-circle') + '"></i>' + esc(msg);
    box.appendChild(t); requestAnimationFrame(function () { t.classList.add('in'); });
    setTimeout(function () { t.classList.remove('in'); setTimeout(function () { t.remove(); }, 300); }, 3200);
  }
  window.dfToast = toast;

  /* ── flash messages ── */
  $$('.flash').forEach(function (f) {
    var x = $('.flash-x', f); if (x) x.addEventListener('click', function () { f.remove(); });
    if (!f.classList.contains('err')) setTimeout(function () { f.classList.add('fade'); setTimeout(function () { f.remove(); }, 500); }, 7000);
  });

  /* ── confirm dialog: <button data-confirm="Delete this?"> or <form data-confirm> ── */
  var box = $('#confirmBox'), pending = null;
  function ask(msg, title, yesLabel, danger, go) {
    if (!box) { if (window.confirm(msg)) go(); return; }
    $('#confirmTitle').textContent = title || 'Are you sure?'; $('#confirmMsg').textContent = msg;
    var y = $('#confirmYes'); y.textContent = yesLabel || 'Confirm'; y.classList.toggle('danger', danger !== false);
    pending = go; box.hidden = false; y.focus();
  }
  if (box) {
    $('#confirmNo').addEventListener('click', function () { box.hidden = true; pending = null; });
    $('#confirmYes').addEventListener('click', function () { box.hidden = true; var f = pending; pending = null; if (f) f(); });
    box.addEventListener('click', function (e) { if (e.target === box) { box.hidden = true; pending = null; } });
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-confirm]'); if (!b || b.dataset.confirmed) return;
    var form = b.closest('form'), isForm = b.tagName === 'FORM';
    if (!form && !b.href) return;
    e.preventDefault();
    ask(b.dataset.confirm, b.dataset.confirmTitle, b.dataset.confirmYes, b.dataset.confirmDanger !== '0', function () {
      if (b.href && !form) { location.href = b.href; return; }
      if (b.name) { var h = document.createElement('input'); h.type = 'hidden'; h.name = b.name; h.value = b.value; form.appendChild(h); }
      form.submit();
    });
  });

  /* ── auto-submit filters ── */
  $$('[data-autosubmit]').forEach(function (el) { el.addEventListener('change', function () { el.form.submit(); }); });

  /* ── copy to clipboard ── */
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-copy]'); if (!b) return; e.preventDefault();
    var txt = b.dataset.copy;
    (navigator.clipboard ? navigator.clipboard.writeText(txt) : Promise.reject()).then(function () { toast('Copied: ' + txt); }, function () {
      var t = document.createElement('textarea'); t.value = txt; document.body.appendChild(t); t.select(); try { document.execCommand('copy'); toast('Copied: ' + txt); } catch (x) { toast('Could not copy', true); } t.remove();
    });
  });

  /* ── print ── */
  document.addEventListener('click', function (e) { if (e.target.closest('[data-print]')) { e.preventDefault(); window.print(); } });

  /* ── tabs: <div data-tabs><button data-tab="a">…</button> … <section data-pane="a"> ── */
  $$('[data-tabs]').forEach(function (wrap) {
    var btns = $$('[data-tab]', wrap), panes = $$('[data-pane]', wrap.parentNode);
    function show(id, push) {
      btns.forEach(function (b) { b.classList.toggle('on', b.dataset.tab === id); b.setAttribute('aria-selected', b.dataset.tab === id); });
      panes.forEach(function (p) { p.hidden = p.dataset.pane !== id; });
      if (push) history.replaceState(null, '', '#' + id);
    }
    btns.forEach(function (b) { b.addEventListener('click', function () { show(b.dataset.tab, true); }); });
    var h = location.hash.slice(1); show(btns.some(function (b) { return b.dataset.tab === h; }) ? h : btns[0].dataset.tab, false);
  });

  /* ── bulk select ── */
  $$('[data-bulk]').forEach(function (form) {
    var all = $('[data-bulk-all]', form), rows = $$('[data-bulk-row]', form), bar = $('.bulkbar', form), cnt = $('.bulk-count', form);
    function upd() {
      var n = rows.filter(function (r) { return r.checked; }).length;
      if (bar) bar.classList.toggle('show', n > 0); if (cnt) cnt.textContent = n + ' selected';
      if (all) { all.checked = n === rows.length && n > 0; all.indeterminate = n > 0 && n < rows.length; }
      rows.forEach(function (r) { var tr = r.closest('tr'); if (tr) tr.classList.toggle('sel', r.checked); });
    }
    if (all) all.addEventListener('change', function () { rows.forEach(function (r) { r.checked = all.checked; }); upd(); });
    rows.forEach(function (r) { r.addEventListener('change', upd); }); upd();
  });

  /* ── notification bell: mark as seen when opened ── */
  var bell = $('#bellMenu');
  if (bell) bell.addEventListener('show.bs.dropdown', function () {
    var dot = $('.bell-dot', bell.parentNode); if (!dot || !bell.dataset.seen) return;
    var fd = new FormData(); fd.append('csrf', bell.dataset.csrf); fd.append('seen', '1');
    fetch(bell.dataset.seen, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function () { setTimeout(function () { dot.remove(); }, 1500); });
  });

  /* ── storefront cart bridge (same localStorage key the shop uses) ── */
  function readCart() { try { return JSON.parse(localStorage.getItem('df_cart')) || []; } catch (e) { return []; } }
  function writeCart(c) { try { localStorage.setItem('df_cart', JSON.stringify(c)); } catch (e) {} }
  function addItems(items) {
    var cart = readCart();
    items.forEach(function (it) {
      var ex = cart.filter(function (c) { return c.name === it.name; })[0];
      var qty = Math.max(1, Math.min(10, parseInt(it.qty, 10) || 1));
      if (ex) ex.qty = Math.min(10, ex.qty + qty);
      else cart.push({ brand: it.brand, name: it.name, price: 'Rs. ' + Number(it.price).toLocaleString('en-IN'), badge: '', img: it.img || '', qty: qty });
    });
    writeCart(cart); return cart.length;
  }
  function cartCount() { return readCart().reduce(function (s, c) { return s + (c.qty || 1); }, 0); }
  function paintCart() { $$('[data-cartcount]').forEach(function (el) { var n = cartCount(); el.textContent = n; el.hidden = n === 0; }); }
  paintCart();
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-addcart]'); if (b) {
      e.preventDefault(); var d = JSON.parse(b.dataset.addcart); addItems([d]); paintCart(); toast(d.name + ' added to your cart');
      b.classList.add('done'); setTimeout(function () { b.classList.remove('done'); }, 1400); return;
    }
    var r = e.target.closest('[data-reorder]'); if (r) {
      e.preventDefault(); var list = JSON.parse(r.dataset.reorder); addItems(list); paintCart();
      toast(list.length + ' item(s) added to your cart');
      if (r.dataset.go) setTimeout(function () { location.href = r.dataset.go; }, 450);
    }
  });

  /* ── wishlist heart (AJAX) ── */
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-wish]'); if (!b) return; e.preventDefault();
    var fd = new FormData(); fd.append('csrf', b.dataset.csrf); fd.append('toggle', b.dataset.wish);
    b.disabled = true;
    fetch(b.dataset.url, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) { return r.json(); }).then(function (d) {
        b.disabled = false; if (!d.ok) { toast(d.error || 'Could not update', true); return; }
        b.classList.toggle('on', d.saved); var i = $('i', b); if (i) i.className = 'bi ' + (d.saved ? 'bi-heart-fill' : 'bi-heart');
        toast(d.saved ? 'Saved to your wishlist' : 'Removed from wishlist');
        if (b.dataset.remove && !d.saved) { var c = b.closest('.pcard-prod'); if (c) { c.classList.add('gone'); setTimeout(function () { c.remove(); }, 300); } }
      }).catch(function () { b.disabled = false; toast('Network error', true); });
  });

  /* ── admin command palette (Ctrl/⌘+K) ── */
  var cmd = $('#cmd');
  if (cmd) {
    var inp = $('#cmdInput'), res = $('#cmdRes'), timer = null, idx = -1, hint = res.innerHTML;
    function open() { cmd.hidden = false; inp.value = ''; res.innerHTML = hint; inp.focus(); idx = -1; }
    function close() { cmd.hidden = true; }
    var openBtn = $('#cmdOpen'); if (openBtn) openBtn.addEventListener('click', open);
    cmd.addEventListener('click', function (e) { if (e.target === cmd) close(); });
    document.addEventListener('keydown', function (e) {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); cmd.hidden ? open() : close(); }
      else if (e.key === 'Escape' && !cmd.hidden) close();
      else if (!cmd.hidden && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
        var links = $$('a', res); if (!links.length) return; e.preventDefault();
        idx = (idx + (e.key === 'ArrowDown' ? 1 : -1) + links.length) % links.length; links[idx].focus();
      } else if (!cmd.hidden && document.activeElement && res.contains(document.activeElement) && /^[a-z0-9]$/i.test(e.key)) inp.focus();
    });
    inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { var a = $('a', res); if (a) location.href = a.href; } });
    inp.addEventListener('input', function () {
      clearTimeout(timer); var q = inp.value.trim();
      if (q.length < 2) { res.innerHTML = hint; return; }
      timer = setTimeout(function () {
        fetch('search.php?q=' + encodeURIComponent(q), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
          if (!d.groups || !d.groups.length) { res.innerHTML = '<p class="cmd-hint">No matches for “' + esc(q) + '”.</p>'; return; }
          res.innerHTML = d.groups.map(function (g) {
            return '<div class="cmd-g"><span>' + esc(g.title) + '</span>' + g.items.map(function (i) {
              return '<a href="' + esc(i.url) + '"><i class="bi ' + esc(i.icon) + '"></i><div><b>' + esc(i.title) + '</b><small>' + esc(i.sub) + '</small></div>' + (i.badge ? '<em>' + esc(i.badge) + '</em>' : '') + '</a>';
            }).join('') + '</div>';
          }).join('');
        }).catch(function () { res.innerHTML = '<p class="cmd-hint">Search failed. Try again.</p>'; });
      }, 200);
    });
  }

  /* ── order / ticket thread: scroll to newest message ── */
  var th = $('.thread'); if (th && th.dataset.scroll) th.scrollTop = th.scrollHeight;

  /* ── textarea auto-grow ── */
  $$('textarea[data-grow]').forEach(function (t) { function g() { t.style.height = 'auto'; t.style.height = Math.min(320, t.scrollHeight + 2) + 'px'; } t.addEventListener('input', g); g(); });

  /* ── live character counter ── */
  $$('[data-count-for]').forEach(function (c) { var f = document.getElementById(c.dataset.countFor); if (!f) return; function u() { c.textContent = f.value.length + ' / ' + f.maxLength; } f.addEventListener('input', u); u(); });

  /* ── password strength meter ── */
  $$('[data-strength]').forEach(function (inp) {
    var m = document.getElementById(inp.dataset.strength); if (!m) return;
    inp.addEventListener('input', function () {
      var v = inp.value, s = 0; if (v.length >= 8) s++; if (/[A-Za-z]/.test(v) && /\d/.test(v)) s++; if (v.length >= 12) s++; if (/[^A-Za-z0-9]/.test(v) && /[A-Z]/.test(v)) s++;
      m.dataset.s = v ? s : 0; m.querySelector('span').textContent = ['', 'Weak', 'Fair', 'Good', 'Strong'][v ? Math.max(1, s) : 0];
    });
  });

  /* ── show/hide password ── */
  $$('[data-eye]').forEach(function (b) { b.addEventListener('click', function () { var i = document.getElementById(b.dataset.eye); if (!i) return; var show = i.type === 'password'; i.type = show ? 'text' : 'password'; b.querySelector('i').className = 'bi ' + (show ? 'bi-eye-slash' : 'bi-eye'); }); });
})();
