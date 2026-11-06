/* Adds "Sign in" / "My Account" to the site navbar on every page. Safe to load anywhere (no jQuery needed). */
(function () {
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function build(me) {
    var track = document.querySelector('#mainNav a[href="track-order.html"]');
    var host = track ? track.closest('li') : null;
    var list = document.querySelector('#mainNav .navbar-nav'); if (!list) return;
    if (document.getElementById('dfAuthNav')) return;
    var li = document.createElement('li'); li.id = 'dfAuthNav';
    var c = me && me.customer, a = me && me.admin;
    if (!c && !a) {
      li.className = 'nav-item ms-lg-1';
      li.innerHTML = '<a class="nav-link" href="account.html"><i class="bi bi-person-circle me-1"></i>Sign in</a>';
    } else {
      var name = (c ? c.full_name : a.name).split(' ')[0];
      li.className = 'nav-item dropdown ms-lg-1';
      li.innerHTML = '<a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-person-check-fill me-1"></i>Hi, ' + esc(name) + '</a>' +
        '<ul class="dropdown-menu dropdown-menu-end">' +
        (c ? '<li><a class="dropdown-item" href="backend/customer/">My account</a></li><li><a class="dropdown-item" href="backend/customer/orders.php">My orders</a></li>' : '') +
        (a ? '<li><a class="dropdown-item" href="backend/admin/">Admin panel</a></li>' : '') +
        '<li><hr class="dropdown-divider"></li>' +
        (c ? '<li><a class="dropdown-item" href="#" data-df-out="customer">Sign out</a></li>' : '') +
        (a ? '<li><a class="dropdown-item" href="#" data-df-out="admin">Sign out (admin)</a></li>' : '') + '</ul>';
    }
    if (host && host.parentNode) host.parentNode.insertBefore(li, host.nextSibling); else list.appendChild(li);
    li.addEventListener('click', function (e) {
      var t = e.target.closest('[data-df-out]'); if (!t) return; e.preventDefault();
      fetch('backend/api/auth.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'logout', role: t.getAttribute('data-df-out') }) })
        .then(function () { li.remove(); window.dfMe = null; load(); });
    });
  }
  function load() {
    fetch('backend/api/me.php', { credentials: 'same-origin' }).then(function (r) { return r.json(); })
      .then(function (d) { window.dfMe = d && d.ok ? d : null; build(window.dfMe); document.dispatchEvent(new CustomEvent('df:me', { detail: window.dfMe })); })
      .catch(function () { build(null); });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', load); else load();
})();
