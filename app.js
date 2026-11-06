/* ===== DURABLE FURNITURES – app.js v2.0 ===== */

$(function () {

  // ── Active nav link
  var page = location.pathname.split('/').pop() || 'index.html';
  $('.navbar-nav .nav-link[href="' + page + '"]').addClass('active');
  $('.navbar-nav .nav-link[href="./' + page + '"]').addClass('active');

  // ── Navbar scroll effect
  $(window).on('scroll', function () {
    $('#mainNav').toggleClass('scrolled', $(this).scrollTop() > 40);
  });

  // ── Scroll reveal
  var obs = new IntersectionObserver(function (entries) {
    entries.forEach(function (e) { if (e.isIntersecting) $(e.target).addClass('visible'); });
  }, { threshold: 0.08 });
  document.querySelectorAll('.reveal').forEach(function (el) { obs.observe(el); });

  // ── FAQ toggle
  $('.faq-question').on('click', function () {
    var isOpen = $(this).hasClass('open');
    $('.faq-question, .faq-answer').removeClass('open');
    if (!isOpen) { $(this).addClass('open'); $(this).next('.faq-answer').addClass('open'); }
  });

  // ── Contact form
  $('#contactForm').on('submit', function (e) {
    e.preventDefault();
    var valid = true;

    // Clear previous errors
    $(this).find('.field-error').remove();
    $(this).find('.custom-input').removeClass('is-invalid');

    function showError($el, msg) {
      $el.addClass('is-invalid');
      $el.after('<div class="field-error" style="color:#ef4444;font-size:.78rem;margin-top:4px"><i class="bi bi-exclamation-circle me-1"></i>' + msg + '</div>');
      valid = false;
    }

    // Full Name
    var $name = $(this).find('input[type="text"]');
    var nameVal = $name.val().trim();
    if (!nameVal) {
      showError($name, 'Name is required.');
    } else if (nameVal.length < 3) {
      showError($name, 'Name must be at least 3 characters.');
    }

    // Phone (optional but validate if filled)
    var $phone = $(this).find('input[type="tel"]');
    var phoneVal = $phone.val().trim();
    if (phoneVal && !/^[+\d\s\-]{7,15}$/.test(phoneVal)) {
      showError($phone, 'Enter a valid phone number.');
    }

    // Email
    var $email = $(this).find('input[type="email"]');
    var emailVal = $email.val().trim();
    if (!emailVal) {
      showError($email, 'Email is required.');
    } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailVal)) {
      showError($email, 'Enter a valid email address.');
    }

    // Message
    var $msg = $(this).find('textarea');
    var msgVal = $msg.val().trim();
    if (!msgVal) {
      showError($msg, 'Message is required.');
    } else if (msgVal.length < 10) {
      showError($msg, 'Message must be at least 10 characters.');
    }

    if (!valid) return;

    var $form = $(this);
    var $btn = $form.find('button[type="submit"], .btn-primary-custom').first();
    $btn.prop('disabled', true);
    fetch('backend/api/contact.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        name: nameVal, phone: phoneVal, email: emailVal, message: msgVal,
        subject: ($form.find('select').val() || ''), website: ''
      })
    }).then(function (r) { return r.json(); }).then(function (d) {
      if (d && d.ok) {
        $form.slideUp(280);
        $('#formSuccess').removeClass('d-none').hide().fadeIn(320);
      } else { showToast('⚠️ ' + ((d && d.error) || 'Could not send message')); $btn.prop('disabled', false); }
    }).catch(function () { showToast('⚠️ Server not reachable. Please WhatsApp us.'); $btn.prop('disabled', false); });
  });

  // ── Hero scroll
  $('.hero-scroll').on('click', function () {
    $('html,body').animate({ scrollTop: $('#statsBar').offset().top }, 700, 'swing');
  });

  // ── Cart init
  loadCart(); renderCart(); updateBadge();

  // ── Cart open/close
  $('#cartBtn, #cartBtnMobile').on('click', openCart);
  $('#cartClose, #cartOverlay').on('click', closeCart);

  // ── Product modal
  injectModal();
  $(document).on('click', '.product-card', openModal);
  $(document).on('click', '#pdModalOverlay, #pdModalClose', closeModal);
  $(document).on('keydown', function (e) { if (e.key === 'Escape') closeModal(); });
});

// ===== CART =====
var cart = [];

function loadCart() {
  try { cart = JSON.parse(localStorage.getItem('df_cart')) || []; } catch (e) { cart = []; }
}
function saveCart() {
  try { localStorage.setItem('df_cart', JSON.stringify(cart)); } catch (e) {}
}

function goCheckout() {
  if (!cart.length) { showToast('Your cart is empty'); return; }
  window.location.href = 'checkout.html';
}

function openCart() {
  $('#cartSidebar, #cartOverlay').addClass('show');
  $('body').css('overflow', 'hidden');
}

function addToCart(brand, name, price, badge, img) {
  var item = cart.find(function (i) { return i.name === name; });
  if (item) { item.qty++; } else { cart.push({ brand: brand, name: name, price: price, badge: badge, img: img || '', qty: 1 }); }
  saveCart(); renderCart(); updateBadge();
  showToast('🛒 Added to cart — ' + name.split(' ').slice(0,3).join(' '));
  setTimeout(openCart, 180);
}
function removeFromCart(name) {
  cart = cart.filter(function (i) { return i.name !== name; });
  saveCart(); renderCart(); updateBadge();
}
function changeQty(name, delta) {
  var item = cart.find(function (i) { return i.name === name; });
  if (item) {
    item.qty += delta;
    if (item.qty <= 0) { removeFromCart(name); return; }
  }
  saveCart(); renderCart(); updateBadge();
}

function renderCart() {
  var $b = $('#cartBody');
  if (!$b.length) return;

  if (!cart.length) {
    $b.html(
      '<div class="cart-empty">' +
        '<div class="cart-empty-icon"><i class="bi bi-bag"></i></div>' +
        '<span class="cart-empty-title">Your cart is empty</span>' +
        '<p>Browse our premium furniture collection and find something you love.</p>' +
        '<a href="brands.html" class="btn-primary-custom" style="padding:11px 26px;font-size:.82rem">Browse Products</a>' +
      '</div>'
    );
    $('#cartFooter').hide();
    // Update header sub
    $('#cartHeaderSub').text('Your cart is empty');
    return;
  }

  var html = '', total = 0;
  cart.forEach(function (item) {
    var p = parseInt(item.price.replace(/\D/g, ''));
    total += p * item.qty;
    var n = item.name.replace(/'/g, "\\'");
    var lineTotal = 'Rs.' + (p * item.qty).toLocaleString('en-IN');
    html +=
      '<div class="cart-item">' +
        '<div class="cart-item-img">' + (item.img ? '<img src="' + item.img + '" alt="' + item.name + '" style="width:100%;height:100%;object-fit:cover;border-radius:inherit"/>' : '<i class="bi bi-sofa"></i>') + '</div>' +
        '<div class="cart-item-info">' +
          '<div class="cart-item-name">' + item.name + '</div>' +
          '<div class="cart-item-brand"><span class="brand-badge ' + item.badge + '" style="position:static;font-size:.62rem;padding:3px 8px">' + item.brand + '</span></div>' +
          '<div class="qty-control">' +
            '<button class="qty-btn" onclick="changeQty(\'' + n + '\',-1)"><i class="bi bi-dash"></i></button>' +
            '<span class="qty-num">' + item.qty + '</span>' +
            '<button class="qty-btn" onclick="changeQty(\'' + n + '\',1)"><i class="bi bi-plus"></i></button>' +
          '</div>' +
        '</div>' +
        '<div class="cart-item-right">' +
          '<div class="cart-item-price">' + lineTotal + '</div>' +
          '<button class="cart-item-remove" onclick="removeFromCart(\'' + n + '\')" title="Remove"><i class="bi bi-trash3"></i></button>' +
        '</div>' +
      '</div>';
  });

  $b.html(html);

  var totalStr = 'Rs.' + total.toLocaleString('en-IN');
  $('#cartTotal').text(totalStr);
  $('#cartSubtotalValue').text(totalStr);

  var count = cart.reduce(function(s,i){return s+i.qty;},0);
  $('#cartHeaderSub').text(count + ' item' + (count !== 1 ? 's' : '') + ' in cart');

  $('#cartFooter').show();
}

function updateBadge() {
  var c = cart.reduce(function (s, i) { return s + i.qty; }, 0);
  var $b = $('#cartBadge');
  var prev = parseInt($b.text()) || 0;
  $b.text(c);
  if (c > prev) { $b.addClass('show'); setTimeout(function(){ $b.removeClass('show'); }, 400); }
}

function closeCart() {
  $('#cartSidebar, #cartOverlay').removeClass('show');
  $('body').css('overflow', '');
}

// ===== TOAST =====
function showToast(msg) {
  $('#toastMsg').text(msg);
  var $t = $('#toast');
  $t.addClass('show');
  setTimeout(function () { $t.removeClass('show'); }, 3400);
}

// ===== FILTERS =====
function filterBrand(brand) {
  $('.product-card-wrap').each(function () {
    $(this).toggleClass('hidden', brand !== 'all' && $(this).data('brand') !== brand);
  });
  $('.filter-btn').removeClass('active');
  $('.filter-btn[data-brand="' + brand + '"]').addClass('active');
}
function filterCategory(cat) {
  $('.product-card-wrap').each(function () {
    $(this).toggleClass('hidden', cat !== 'all' && $(this).data('category') !== cat);
  });
  $('.cat-filter-btn').removeClass('active');
  $('.cat-filter-btn[data-cat="' + cat + '"]').addClass('active');
}

// ===== COMPARE =====
var compareItems = [];
function addToCompare(brand, name, price, material, category) {
  if (compareItems.length >= 4) { showToast('⚠️ Max 4 products in comparison'); return; }
  if (compareItems.find(function (i) { return i.name === name; })) { showToast('Already in comparison!'); return; }
  compareItems.push({ brand: brand, name: name, price: price, material: material, category: category });
  showToast('📊 Added to compare — ' + name.split(' ').slice(0,3).join(' '));
}
function clearCompare() { compareItems = []; showToast('Comparison cleared'); }

// ===== MODAL =====
function injectModal() {
  if ($('#pdModal').length) return;
  $('body').append(
    '<div id="pdModal"><div id="pdModalOverlay"></div>' +
    '<div id="pdModalBox"><button id="pdModalClose"><i class="bi bi-x-lg"></i></button>' +
    '<div id="pdModalBody"></div></div></div>'
  );
  $('<style>').text(
    '#pdModal{display:none;position:fixed;inset:0;z-index:9999;padding:var(--s5)}' +
    '#pdModal.show{display:flex;align-items:center;justify-content:center}' +
    '#pdModalOverlay{position:fixed;inset:0;background:rgba(10,9,8,.75);backdrop-filter:blur(10px) saturate(130%);animation:pdFadeIn .28s var(--ease) both}' +
    '#pdModalBox{position:relative;background:var(--white,#ffffff);border-radius:18px;width:min(920px,96vw);max-height:92vh;overflow-y:auto;box-shadow:0 40px 100px rgba(0,0,0,.38),0 0 0 1px rgba(255,255,255,.06);animation:pdSlideIn .36s cubic-bezier(.34,1.56,.64,1) both;z-index:1;scrollbar-width:thin;scrollbar-color:var(--border) transparent}' +
    '#pdModalBox::before{content:"";position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,var(--gold),var(--gold-light),var(--gold));border-radius:18px 18px 0 0;z-index:2}' +
    '@keyframes pdFadeIn{from{opacity:0}to{opacity:1}}' +
    '@keyframes pdSlideIn{from{opacity:0;transform:scale(.93) translateY(14px)}to{opacity:1;transform:scale(1) translateY(0)}}' +
    '#pdModalClose{position:sticky;top:14px;float:right;margin:14px 14px 0 0;width:38px;height:38px;border:1.5px solid rgba(128,128,128,.2);border-radius:10px;background:rgba(30,26,22,.9);backdrop-filter:blur(6px);color:var(--text,#e8e2d8);font-size:.88rem;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all .22s var(--ease);z-index:5}' +
    '#pdModalClose:hover{background:var(--ink);color:#fff;border-color:var(--ink);transform:rotate(90deg)}' +
    '#pdModalBody{display:grid;grid-template-columns:1fr 1fr;gap:0;padding:0;clear:both}' +
    '.pd-img-col{padding:28px 18px 28px 28px}.pd-img-wrap{position:relative;border-radius:14px;overflow:hidden;background:var(--stone)}' +
    '.pd-main-img{width:100%;height:310px;object-fit:cover;display:block;transition:transform .45s var(--ease)}' +
    '.pd-img-wrap:hover .pd-main-img{transform:scale(1.04)}' +
    '.pd-brand-badge{position:absolute;top:12px;left:12px;color:#fff;font-size:.68rem;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;padding:5px 12px;border-radius:6px}' +
    '.pd-discount-badge{position:absolute;top:12px;right:12px;background:#dc2626;color:#fff;font-size:.68rem;font-weight:700;padding:5px 10px;border-radius:6px}' +
    '.pd-thumb-row{display:flex;gap:8px;margin-top:10px}' +
    '.pd-thumb{width:68px;height:54px;border-radius:9px;overflow:hidden;cursor:pointer;border:2px solid transparent;transition:border-color .2s,transform .2s}' +
    '.pd-thumb:hover{transform:scale(1.04)}.pd-thumb.active{border-color:var(--gold)}' +
    '.pd-thumb img{width:100%;height:100%;object-fit:cover}' +
    '.pd-info-col{padding:28px 28px 28px 18px;display:flex;flex-direction:column;gap:14px}' +
    '.pd-eyebrow{font-size:.68rem;font-weight:700;letter-spacing:2.5px;text-transform:uppercase;margin:0;display:flex;align-items:center;gap:8px}' +
    '.pd-eyebrow::before{content:"";display:block;width:18px;height:1px;background:currentColor;flex-shrink:0}' +
    '.pd-title{font-family:var(--font-serif,"Playfair Display",serif);font-size:1.65rem;font-weight:600;color:var(--text,#2a2720);line-height:1.18;margin:0}' +
    '.pd-stars{color:#f59e0b;font-size:.9rem;display:flex;align-items:center;gap:6px}' +
    '.pd-rating-count{color:var(--text-faint,#9a9183);font-size:.78rem;font-family:var(--font-sans,Outfit,sans-serif)}' +
    '.pd-price-row{display:flex;align-items:baseline;gap:10px}' +
    '.pd-price{font-family:var(--font-serif,"Playfair Display",serif);font-size:1.85rem;font-weight:700;color:var(--text,#2a2720)}' +
    '.pd-original{font-size:1.02rem;color:var(--text-faint,#9a9183);text-decoration:line-through}' +
    '.pd-summary{color:var(--text-mid,#5a5346);font-size:.88rem;line-height:1.7;margin:0;font-family:var(--font-sans,Outfit,sans-serif);font-weight:300}' +
    '.pd-specs{display:grid;grid-template-columns:1fr 1fr;gap:10px;background:var(--stone,#f5f0e8);border-radius:12px;padding:14px}' +
    '.pd-spec-item{display:flex;align-items:center;gap:9px}' +
    '.pd-spec-item>i{font-size:1.05rem;color:var(--gold,#c4933f);flex-shrink:0}' +
    '.pd-spec-item span{display:block;font-size:.66rem;color:var(--text-faint,#9a9183);text-transform:uppercase;letter-spacing:.6px;font-family:var(--font-sans,Outfit,sans-serif)}' +
    '.pd-spec-item strong{display:block;font-size:.84rem;color:var(--text,#2a2720);font-weight:600;font-family:var(--font-sans,Outfit,sans-serif)}' +
    '.pd-features{display:flex;flex-wrap:wrap;gap:6px}' +
    /* pd-pill: fallbacks use LIGHT-mode values (safe default); dark override in style.css */
    '.pd-pill{background:var(--stone,#f5f0e8);border:1.5px solid var(--border,#e5ddd0);color:var(--text,#2a2720);font-size:.73rem;font-weight:500;padding:5px 12px;border-radius:7px;display:inline-flex;align-items:center;gap:5px;transition:all .18s;cursor:default}' +
    '.pd-pill:hover{border-color:var(--gold,#c4933f);color:var(--gold,#c4933f)}' +
    '.pd-pill i{color:var(--gold,#c4933f)}' +
    '.pd-actions{display:flex;gap:10px}' +
    '.pd-btn-cart{flex:1;background:var(--ink,#0f0e0c);color:#fff;border:none;border-radius:10px;padding:14px 18px;font-size:.84rem;font-weight:600;cursor:pointer;transition:all .24s var(--ease);display:flex;align-items:center;justify-content:center;gap:7px;letter-spacing:.03em;font-family:var(--font-sans,Outfit,sans-serif)}' +
    '.pd-btn-cart:hover{background:var(--gold,#c4933f);color:var(--ink,#0f0e0c);transform:translateY(-2px);box-shadow:0 6px 22px rgba(196,147,63,.38)}' +
    '.pd-btn-compare{flex:1;background:transparent;color:var(--text,#2a2720);border:2px solid var(--border,#e5ddd0);border-radius:10px;padding:14px 18px;font-size:.84rem;font-weight:600;cursor:pointer;transition:all .24s;display:flex;align-items:center;justify-content:center;gap:7px;font-family:var(--font-sans,Outfit,sans-serif)}' +
    '.pd-btn-compare:hover{border-color:var(--gold,#c4933f);color:var(--gold,#c4933f);background:var(--gold-pale,rgba(196,147,63,.1))}' +
    '.pd-download-row{display:flex;gap:10px}' +
    '.pd-btn-download{flex:1;border-radius:9px;padding:10px 14px;font-size:.8rem;font-weight:600;cursor:pointer;transition:all .22s;display:flex;align-items:center;justify-content:center;gap:6px;border:none;font-family:var(--font-sans,Outfit,sans-serif)}' +
    '.pd-btn-pdf{background:#fff0f0;color:#c0392b;border:1.5px solid rgba(192,57,43,.25)}.pd-btn-pdf:hover{background:#c0392b;color:#fff}' +
    '.pd-btn-word{background:#f0f4ff;color:#2563eb;border:1.5px solid rgba(37,99,235,.22)}.pd-btn-word:hover{background:#2563eb;color:#fff}' +
    '.pd-trust{display:flex;flex-wrap:wrap;gap:12px;padding:10px 14px;background:var(--stone,#f5f0e8);border-radius:10px}' +
    '.pd-trust span{font-size:.74rem;color:var(--text-mid,#5a5346);display:flex;align-items:center;gap:5px;font-family:var(--font-sans,Outfit,sans-serif)}' +
    '.pd-trust i{color:var(--gold,#c4933f)}' +
    '@media(max-width:640px){#pdModalBody{grid-template-columns:1fr}.pd-img-col{padding:20px 16px 10px}.pd-info-col{padding:10px 16px 24px}.pd-main-img{height:220px}.pd-title{font-size:1.35rem}.pd-price{font-size:1.5rem}}'
  ).appendTo('head');
}

function openModal() {
  var $card = $(this);
  if ($(event.target).closest('button, a').length) return;
  var $wrap = $card.closest('.product-card-wrap');
  var name     = $card.find('.product-name').text().trim();
  var price    = $card.find('.product-price').text().trim();
  var original = $card.find('.product-original-price').text().trim();
  var imgSrc   = $card.find('.product-img').attr('src') || '';
  var brand    = $wrap.data('brand') || '';
  var category = $wrap.data('category') || '';
  var summary  = $card.find('.product-summary').text().trim() || 'Premium quality furniture.';
  var material = summary.split(',')[0].trim();

  var brandColor = { Durian:'#c0392b', Godrej:'#0d7c4b', Nilkamal:'#1e50a0', HomeTown:'#5b21b6' }[brand] || '#c4933f';
  var badgeClass = { Durian:'durian-badge', Godrej:'godrej-badge', Nilkamal:'nilkamal-badge', HomeTown:'hometown-badge' }[brand] || 'durian-badge';
  var catLabel   = { sofa:'Sofas & Seating', bed:'Bedroom', dining:'Dining', storage:'Storage', office:'Office' }[category] || category;

  var discHtml = '';
  if (original) {
    var disc = Math.round((parseInt(original.replace(/\D/g,'')) - parseInt(price.replace(/\D/g,''))) / parseInt(original.replace(/\D/g,'')) * 100);
    if (disc > 0) discHtml = '<span class="pd-discount-badge">−' + disc + '%</span>';
  }

  var nb = escQ(name), bb = escQ(brand), pb = escQ(price), cb = escQ(category), mb = escQ(material);

  $('#pdModalBody').html(
    '<div class="pd-img-col">' +
      '<div class="pd-img-wrap">' +
        '<img src="' + imgSrc + '" class="pd-main-img" alt="' + name + '"/>' +
        '<span class="pd-brand-badge" style="background:' + brandColor + '">' + brand + '</span>' + discHtml +
      '</div>' +
      '<div class="pd-thumb-row">' +
        '<div class="pd-thumb active"><img src="' + imgSrc + '" alt=""/></div>' +
        '<div class="pd-thumb"><img src="https://images.unsplash.com/photo-1555041469-a586c61ea9bc?w=160&q=70" alt=""/></div>' +
        '<div class="pd-thumb"><img src="https://images.unsplash.com/photo-1631049307264-da0ec9d70304?w=160&q=70" alt=""/></div>' +
      '</div>' +
    '</div>' +
    '<div class="pd-info-col">' +
      '<p class="pd-eyebrow" style="color:' + brandColor + '">' + brand + ' · ' + catLabel + '</p>' +
      '<h2 class="pd-title">' + name + '</h2>' +
      '<div class="pd-stars">★★★★<span style="color:#ccc">★</span> <span class="pd-rating-count">4.2 · 138 reviews</span></div>' +
      '<div class="pd-price-row"><span class="pd-price">' + price + '</span>' + (original ? '<span class="pd-original">' + original + '</span>' : '') + '</div>' +
      '<p class="pd-summary">' + summary + '</p>' +
      '<div class="pd-specs">' +
        '<div class="pd-spec-item"><i class="bi bi-box-seam"></i><div><span>Material</span><strong>' + material + '</strong></div></div>' +
        '<div class="pd-spec-item"><i class="bi bi-tag"></i><div><span>Category</span><strong>' + catLabel + '</strong></div></div>' +
        '<div class="pd-spec-item"><i class="bi bi-shield-check"></i><div><span>Warranty</span><strong>' + (brand === 'Durian' ? '10 Years' : '5 Years') + '</strong></div></div>' +
        '<div class="pd-spec-item"><i class="bi bi-truck"></i><div><span>Delivery</span><strong>Free · 7–10 days</strong></div></div>' +
      '</div>' +
      '<div class="pd-features">' + getFeatures(brand, category) + '</div>' +
      '<div class="pd-actions">' +
        '<button class="pd-btn-cart" onclick="addToCart(\'' + bb + '\',\'' + nb + '\',\'' + pb + '\',\'' + badgeClass + '\',\'' + escQ(imgSrc) + '\');closeModal()"><i class="bi bi-bag-plus"></i>Add to Cart</button>' +
        '<button class="pd-btn-compare" onclick="addToCompare(\'' + bb + '\',\'' + nb + '\',\'' + pb + '\',\'' + mb + '\',\'' + cb + '\')"><i class="bi bi-bar-chart-line"></i>Compare</button>' +
      '</div>' +
      '<div class="pd-download-row">' +
        '<button class="pd-btn-download pd-btn-pdf" onclick="dlPDF(\'' + nb + '\',\'' + bb + '\',\'' + pb + '\',\'' + cb + '\',\'' + mb + '\')"><i class="bi bi-file-earmark-pdf-fill"></i>Download PDF</button>' +
        '<button class="pd-btn-download pd-btn-word" onclick="dlWord(\'' + nb + '\',\'' + bb + '\',\'' + pb + '\',\'' + cb + '\',\'' + mb + '\')"><i class="bi bi-file-earmark-word-fill"></i>Download Word</button>' +
      '</div>' +
      '<div class="pd-trust">' +
        '<span><i class="bi bi-award"></i> Certified Quality</span>' +
        '<span><i class="bi bi-arrow-counterclockwise"></i> 30-Day Returns</span>' +
        '<span><i class="bi bi-headset"></i> 24/7 Support</span>' +
      '</div>' +
    '</div>'
  );

  $('#pdModal').addClass('show');
  // Propagate current theme to modal so CSS vars resolve correctly
  var currentTheme = document.documentElement.getAttribute('data-theme') || 'dark';
  document.getElementById('pdModalBox').setAttribute('data-theme', currentTheme);
  $('body').css('overflow', 'hidden');

  $('#pdModalBody .pd-thumb').on('click', function () {
    var src = $(this).find('img').attr('src').replace('w=160','w=800');
    $('#pdModalBody .pd-main-img').attr('src', src);
    $('#pdModalBody .pd-thumb').removeClass('active');
    $(this).addClass('active');
  });
}

function closeModal() {
  $('#pdModal').removeClass('show');
  $('body').css('overflow', '');
}

function escQ(s) {
  return String(s).replace(/\\/g,'\\\\').replace(/'/g,"\\'").replace(/"/g,'&quot;');
}

// ===== PRODUCT FEATURES =====
function getFeatures(brand, category) {
  var base = {
    sofa:    [['bi-gem','Premium Upholstery'],['bi-arrows-angle-expand','Space Saving'],['bi-droplet-half','Stain Resistant'],['bi-tools','Easy Assembly']],
    bed:     [['bi-moon-stars','Orthopedic Support'],['bi-box-seam','Storage Option'],['bi-shield-check','Anti-termite'],['bi-rulers','King/Queen Size']],
    dining:  [['bi-people','6–8 Seater'],['bi-moisture','Scratch Resistant'],['bi-brush','Easy Clean'],['bi-clipboard-check','ISI Certified']],
    storage: [['bi-lock','Secure Locks'],['bi-grid-3x3-gap','Modular Design'],['bi-shield-shaded','Waterproof'],['bi-boxes','Large Capacity']],
    office:  [['bi-bicycle','Ergonomic'],['bi-arrow-up-down','Height Adjust'],['bi-wind','Breathable Mesh'],['bi-lightning','Quick Delivery']]
  };
  var extra = {
    Durian:   [['bi-patch-check','10-Yr Warranty'],['bi-gem','Imported Materials']],
    Godrej:   [['bi-award','ISI Certified'],['bi-recycle','Eco-Friendly']],
    Nilkamal: [['bi-wallet2','Best Value'],['bi-clock-history','7-Day Trial']],
    HomeTown: [['bi-house-check','Décor Match'],['bi-palette','Custom Colors']]
  };
  return (base[category] || base['sofa']).concat(extra[brand] || [])
    .map(function(p){ return '<span class="pd-pill"><i class="bi ' + p[0] + '"></i>' + p[1] + '</span>'; }).join('');
}

// ===== PRODUCT DATA =====
function getProductData(name, brand, price, category, material) {
  var catNames = { sofa:'Sofas & Seating', bed:'Bedroom', dining:'Dining', storage:'Storage & Wardrobe', office:'Office Furniture' };
  var features = {
    sofa:    ['Premium upholstery finish','High-density foam cushions','Solid wood frame','Scratch resistant legs','Waterproof surface coating','Easy assembly'],
    bed:     ['Solid wood structure','Hydraulic storage system','Anti-termite protection','Orthopedic support','Comfortable headboard'],
    dining:  ['6-seater arrangement','Premium wood construction','Scratch resistant tabletop','Waterproof coating','Easy maintenance'],
    storage: ['Modular design','Secure locking system','Waterproof finish','Large capacity','Smooth sliding drawers'],
    office:  ['Ergonomic posture support','Adjustable armrests','Breathable mesh back','Height adjustment','Lumbar support']
  };
  var brandExtra = {
    Durian:   ['10-Year warranty','Imported premium materials','Free installation service'],
    Godrej:   ['ISI certified','Eco-friendly manufacturing','5-year warranty'],
    Nilkamal: ['Best value for money','7-day free return','After-sales service'],
    HomeTown: ['Custom colour options','Interior design support','EMI available']
  };
  return {
    name:name, brand:brand, price:price,
    category: catNames[category] || category,
    material:material,
    warranty: brand === 'Durian' ? '10 Years' : '5 Years',
    delivery: 'Free delivery – 7 to 10 working days',
    returns:  '30-day hassle-free return policy',
    features: (features[category] || features['sofa']).concat(brandExtra[brand] || [])
  };
}

// ===== PDF DOWNLOAD =====
function dlPDF(name, brand, price, category, material) {
  if (typeof window.jspdf === 'undefined') {
    var s = document.createElement('script');
    s.src = 'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js';
    s.onload = function(){ _makePDF(name, brand, price, category, material); };
    document.head.appendChild(s);
  } else { _makePDF(name, brand, price, category, material); }
}
function _makePDF(name, brand, price, category, material) {
  var d = getProductData(name, brand, price, category, material);
  var bc = { Durian:[192,57,43], Godrej:[13,124,75], Nilkamal:[30,80,160], HomeTown:[91,33,182] }[brand] || [196,147,63];
  var doc = new window.jspdf.jsPDF({ unit:'mm', format:'a4' });
  var W=210, H=297, m=18, y=0;
  doc.setFillColor.apply(doc,bc); doc.rect(0,0,W,38,'F');
  doc.setTextColor(255,255,255); doc.setFontSize(20); doc.setFont('helvetica','bold');
  doc.text('DURABLE FURNITURES',m,16);
  doc.setFontSize(9); doc.setFont('helvetica','normal');
  doc.text('Product Specification Sheet',m,24);
  doc.text('www.durablefurnitures.pk  |  +92 300 1234567',m,31);
  doc.setFillColor(255,255,255); doc.roundedRect(W-m-32,8,30,12,3,3,'F');
  doc.setTextColor.apply(doc,bc); doc.setFontSize(9); doc.setFont('helvetica','bold');
  doc.text(brand.toUpperCase(),W-m-17,16,{align:'center'});
  y=50;
  doc.setTextColor(26,26,46); doc.setFontSize(18); doc.setFont('helvetica','bold');
  var splitName=doc.splitTextToSize(d.name,W-m*2);
  doc.text(splitName,m,y); y+=splitName.length*9+4;
  doc.setFillColor.apply(doc,bc); doc.roundedRect(m,y,50,11,3,3,'F');
  doc.setTextColor(255,255,255); doc.setFontSize(11); doc.setFont('helvetica','bold');
  doc.text(d.price,m+25,y+7.5,{align:'center'}); y+=18;
  doc.setDrawColor(220,215,205); doc.setLineWidth(0.5); doc.line(m,y,W-m,y); y+=8;
  doc.setTextColor.apply(doc,bc); doc.setFontSize(11); doc.setFont('helvetica','bold');
  doc.text('QUICK SPECIFICATIONS',m,y); y+=7;
  [['Category',d.category],['Material',d.material],['Warranty',d.warranty],['Delivery',d.delivery],['Returns',d.returns],['Brand',d.brand]].forEach(function(sp){
    doc.setFillColor(249,246,240); doc.rect(m,y,W-m*2,7,'F');
    doc.setFont('helvetica','bold'); doc.setFontSize(8); doc.setTextColor(120,110,100);
    doc.text(sp[0].toUpperCase(),m+2,y+4);
    doc.setFont('helvetica','normal'); doc.setTextColor(26,26,46); doc.setFontSize(9);
    doc.text(sp[1],m+58,y+4); y+=7;
  });
  y+=6;
  doc.setDrawColor(220,215,205); doc.line(m,y,W-m,y); y+=8;
  doc.setTextColor.apply(doc,bc); doc.setFontSize(11); doc.setFont('helvetica','bold');
  doc.text('DETAILED FEATURES',m,y); y+=8;
  doc.setFont('helvetica','normal'); doc.setFontSize(9); doc.setTextColor(40,40,60);
  d.features.forEach(function(feat){
    if(!feat)return;
    if(y>H-30){doc.addPage();y=20;}
    doc.setFillColor.apply(doc,bc); doc.circle(m+2,y-1.2,1.2,'F');
    var lines=doc.splitTextToSize(feat,W-m*2-10);
    doc.text(lines,m+6,y); y+=lines.length*5.5+1;
  });
  doc.setFillColor.apply(doc,bc); doc.rect(0,H-18,W,18,'F');
  doc.setTextColor(255,255,255); doc.setFontSize(8); doc.setFont('helvetica','normal');
  doc.text('Durable Furnitures  |  14, Furniture Market, Karachi – 75600  |  hello@durablefurnitures.pk',W/2,H-9,{align:'center'});
  doc.save(d.name.replace(/\s+/g,'_')+'_Details.pdf');
  showToast('📄 PDF downloaded — ' + name.split(' ').slice(0,2).join(' '));
}

// ===== WORD DOWNLOAD =====
function dlWord(name, brand, price, category, material) {
  var d = getProductData(name, brand, price, category, material);
  var color = { Durian:'#c0392b', Godrej:'#0d7c4b', Nilkamal:'#1e50a0', HomeTown:'#5b21b6' }[brand] || '#c4933f';
  var rows = d.features.map(function(f){ return f ? '<li style="margin-bottom:5px;font-size:13px">'+f+'</li>' : ''; }).join('');
  var specs = [['Category',d.category],['Material',d.material],['Brand',d.brand],['Price',d.price],['Warranty',d.warranty],['Delivery',d.delivery],['Returns',d.returns]]
    .map(function(r){ return '<tr><td>'+r[0]+'</td><td>'+r[1]+'</td></tr>'; }).join('');
  var html = '<!DOCTYPE html><html><head><meta charset="UTF-8"/><style>body{font-family:Arial,sans-serif;margin:40px}.header{background:'+color+';color:#fff;padding:24px 28px}.header h1{margin:0;font-size:22px}.badge{background:#fff;color:'+color+';font-weight:700;font-size:10px;padding:3px 12px;border-radius:12px;margin-top:8px;display:inline-block}.price{background:'+color+';color:#fff;display:inline-block;padding:6px 20px;border-radius:6px;font-size:15px;font-weight:700;margin:18px 0}table{width:100%;border-collapse:collapse;margin:14px 0}th,td{padding:9px 12px;border:1px solid #e8e0d0;font-size:12px}th{background:#f9f6f0;font-size:10px;text-transform:uppercase;color:#888}td:first-child{font-weight:600;width:130px;background:#fafaf8;color:#555}h3{font-size:12px;text-transform:uppercase;color:'+color+';margin:22px 0 8px;border-bottom:2px solid '+color+';padding-bottom:4px}.footer{margin-top:36px;padding-top:12px;border-top:2px solid '+color+';font-size:10px;color:#888;text-align:center}</style></head><body><div class="header"><h1>DURABLE FURNITURES</h1><p>Product Specification Sheet</p><span class="badge">'+brand.toUpperCase()+'</span></div><h2 style="margin:20px 0 6px">'+d.name+'</h2><div class="price">'+d.price+'</div><h3>Quick Specifications</h3><table><tr><th>Field</th><th>Details</th></tr>'+specs+'</table><h3>Detailed Features</h3><ul>'+rows+'</ul><div class="footer">Durable Furnitures | 14, Furniture Market, Karachi – 75600 | +92 300 1234567</div></body></html>';
  var blob = new Blob(['<html xmlns:o="urn:schemas-microsoft-com:office:office">'+html.replace('<html>','')],{type:'application/msword'});
  var a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = name.replace(/\s+/g,'_')+'_Details.doc';
  document.body.appendChild(a); a.click(); document.body.removeChild(a);
  showToast('📝 Word file downloaded — ' + name.split(' ').slice(0,2).join(' '));
}