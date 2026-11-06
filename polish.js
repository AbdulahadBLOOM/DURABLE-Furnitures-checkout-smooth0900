/* Durable Furnitures – site-wide polish layer (progressive enhancement; the site works without it). */
(function () {
  'use strict';
  var doc = document, root = doc.documentElement;
  var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  var fine = window.matchMedia && matchMedia('(hover: hover) and (pointer: fine)').matches;
  root.classList.add('pl-js');

  /* ---------- 1. scroll progress bar ---------- */
  var bar = doc.createElement('div'); bar.id = 'plProgress'; bar.setAttribute('aria-hidden', 'true'); doc.body.appendChild(bar);
  var ticking = false;
  function onScroll() {
    if (ticking) return; ticking = true;
    requestAnimationFrame(function () {
      var h = root.scrollHeight - window.innerHeight;
      bar.style.transform = 'scaleX(' + (h > 0 ? Math.min(1, window.scrollY / h) : 0) + ')';
      if (heroBg && !reduce) { var y = window.scrollY; if (y < window.innerHeight * 1.2) heroBg.style.translate = '0 ' + (y * 0.22).toFixed(1) + 'px'; }
      ticking = false;
    });
  }
  var heroBg = doc.querySelector('.hero-section .hero-bg');
  window.addEventListener('scroll', onScroll, { passive: true }); onScroll();

  /* ---------- 2. reveal on scroll ---------- */
  var REVEAL = '.section-title, .section-eyebrow, .why-card, .review-card, .location-card, .contact-info-card, .contact-form-card, .co-card, ' +
               '.accordion-item, .brand-item, .luxury-brand-card, .faq-cta, .faq-tabs, .brand-logo-item, .feature-pill, .trust-item, .stat-item:not(.reveal), ' +
               '.kpi, .pcard, .tablewrap, .tile, .sec-head, .alertbar, .olist';
  var io = ('IntersectionObserver' in window) ? new IntersectionObserver(function (entries) {
    entries.forEach(function (e) {
      if (!e.isIntersecting) return;
      var el = e.target; el.classList.add('pl-in'); io.unobserve(el);
      var wait = 900 + (parseFloat(el.style.getPropertyValue('--pl-d')) || 0) * 1000;
      setTimeout(function () { el.classList.remove('pl-reveal', 'pl-in'); }, wait);   // hand control back to the component's own hover styles
    });
  }, { threshold: 0.08, rootMargin: '0px 0px -40px 0px' }) : null;

  function prepReveal(scope) {
    if (!io || reduce) return;
    var list = (scope || doc).querySelectorAll(REVEAL), idx = 0, lastTop = -1;
    list.forEach(function (el) {
      if (el.dataset.plr || el.classList.contains('reveal') || el.closest('.reveal') || el.closest('#mainNav') || el.closest('.cart-sidebar')) return;
      var r = el.getBoundingClientRect();
      if (r.top < window.innerHeight * 0.92 && r.bottom > 0 && window.scrollY < 40) return;   // already on screen at load: leave alone
      el.dataset.plr = '1'; el.classList.add('pl-reveal');
      // stagger siblings that enter together (same row)
      var top = Math.round(r.top / 40);
      idx = (top === lastTop) ? idx + 1 : 0; lastTop = top;
      el.style.setProperty('--pl-d', Math.min(idx, 5) * 0.08 + 's');
      io.observe(el);
    });
  }

  /* ---------- 3. cursor spotlight on cards ---------- */
  var SPOT = '.product-card, .why-card, .review-card, .location-card, .contact-info-card, .contact-form-card, .co-card, .luxury-brand-card, .cat-card, .brand-item, .kpi, .pcard, .tile';
  var raf = 0, curr = null, mx = 0, my = 0;
  function spotInit(el) {
    if (el.dataset.pls) return; el.dataset.pls = '1';
    var cs = getComputedStyle(el);
    if (cs.position === 'static') el.classList.add('pl-spot-rel');
    if (cs.display === 'none') return;
    el.classList.add('pl-spot');
    var g = doc.createElement('span'); g.className = 'pl-glow'; g.setAttribute('aria-hidden', 'true'); el.appendChild(g);
  }
  if (fine && !reduce) {
    doc.addEventListener('pointermove', function (e) {
      var el = e.target.closest && e.target.closest(SPOT); if (!el) return;
      spotInit(el); curr = el; mx = e.clientX; my = e.clientY;
      if (!raf) raf = requestAnimationFrame(function () {
        raf = 0; if (!curr) return; var r = curr.getBoundingClientRect();
        curr.style.setProperty('--mx', (mx - r.left) + 'px'); curr.style.setProperty('--my', (my - r.top) + 'px');
      });
    }, { passive: true });
  }

  /* ---------- 4. button ripple ---------- */
  var RIP = '.btn-primary-custom, .btn-outline-custom, .btn-checkout, .btn-cart, .btn-accent-sm, .co-submit, .pbtn, .filter-btn, .cat-filter-btn, .cat-filter-btn-luxury, .filter-btn-luxury, .faq-tab-btn, .brand-filter-pill';
  doc.addEventListener('pointerdown', function (e) {
    if (reduce) return; var b = e.target.closest && e.target.closest(RIP); if (!b || b.disabled) return;
    var cs = getComputedStyle(b);
    if (cs.position === 'static') b.classList.add('pl-rip'); else if (cs.overflow === 'visible') b.classList.add('pl-rip');
    var r = b.getBoundingClientRect(), d = Math.max(r.width, r.height) * 2, s = doc.createElement('span');
    s.className = 'pl-ripple'; s.style.cssText = 'width:' + d + 'px;height:' + d + 'px;left:' + (e.clientX - r.left - d / 2) + 'px;top:' + (e.clientY - r.top - d / 2) + 'px';
    b.appendChild(s); setTimeout(function () { s.remove(); }, 700);
  }, { passive: true });

  /* ---------- 5. images: lazy, async, shimmer + fade-in, graceful failure ---------- */
  function prepImg(img) {
    if (img.dataset.pli) return; img.dataset.pli = '1';
    if (img.closest('#mainNav, .hero-section, #pageLoader, .logo-icon')) return;
    if (!img.hasAttribute('loading')) img.setAttribute('loading', 'lazy');
    if (!img.hasAttribute('decoding')) img.setAttribute('decoding', 'async');
    if (img.complete && img.naturalWidth) return;           // already there: no animation needed
    img.addEventListener('load', function () { img.classList.add('pl-loaded'); }, { once: true });
    img.addEventListener('error', function () { img.classList.add('pl-fail'); }, { once: true });
  }
  doc.querySelectorAll('img').forEach(prepImg);

  /* ---------- 6. pick up content added later (product grids, search, cart) ---------- */
  if ('MutationObserver' in window) {
    var t = 0;
    new MutationObserver(function (muts) {
      if (t) return;
      t = setTimeout(function () {
        t = 0;
        muts.forEach(function (m) { m.addedNodes.forEach(function (n) { if (n.nodeType === 1) { if (n.tagName === 'IMG') prepImg(n); else n.querySelectorAll && n.querySelectorAll('img').forEach(prepImg); } }); });
        prepReveal();
      }, 80);
    }).observe(doc.body, { childList: true, subtree: true });
  }

  /* ---------- 7. smooth page transitions between internal pages ---------- */
  doc.addEventListener('click', function (e) {
    if (e.defaultPrevented || e.button || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    var a = e.target.closest && e.target.closest('a[href]'); if (!a) return;
    var href = a.getAttribute('href');
    if (!href || href.charAt(0) === '#' || a.target === '_blank' || a.hasAttribute('download') || a.hasAttribute('data-bs-toggle') || a.hasAttribute('data-no-transition')) return;
    if (/^(mailto:|tel:|javascript:|https?:\/\/wa\.me)/i.test(href) || /export\.php|logout/i.test(href)) return;
    var u; try { u = new URL(a.href, location.href); } catch (x) { return; }
    if (u.origin !== location.origin || (u.pathname === location.pathname && u.search === location.search)) return;
    if (reduce) return;
    e.preventDefault(); root.classList.add('pl-leaving');
    setTimeout(function () { location.href = a.href; }, 200);
    setTimeout(function () { root.classList.remove('pl-leaving'); }, 1800);     // safety (e.g. blocked navigation)
  });
  window.addEventListener('pageshow', function (e) { if (e.persisted) root.classList.remove('pl-leaving'); });

  /* ---------- 8. keyboard: "/" opens the site search ---------- */
  doc.addEventListener('keydown', function (e) {
    if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey) return;
    var t = e.target; if (t && (/^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName) || t.isContentEditable)) return;
    var btn = doc.getElementById('navSearchBtn'); if (!btn) return;
    e.preventDefault(); btn.click(); setTimeout(function () { var i = doc.getElementById('navSearchInput'); if (i) i.focus(); }, 60);
  });

  /* kick off */
  if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', function () { prepReveal(); });
  else prepReveal();
  window.addEventListener('load', function () { prepReveal(); });
})();
