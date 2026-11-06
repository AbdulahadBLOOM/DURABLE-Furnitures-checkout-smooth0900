<?php
require_once __DIR__ . '/lib.php';
/** Shared page chrome for the admin and customer panels – uses the SITE's own CSS, navbar and footer. */

function initials(string $name): string {
    $p = preg_split('/\s+/', trim($name)); $s = '';
    foreach (array_slice($p, 0, 2) as $w) $s .= mb_strtoupper(mb_substr($w, 0, 1));
    return $s ?: '?';
}

/**
 * $nav   : file => [label, key, icon] or file => [label, key, icon, [[href, label], ...]] (dropdown)
 * $hero  : title, title_html, sub, actions (html), extra (html), eyebrow, tall (bool)
 */
function panel_head(string $title, string $role, array $nav, string $active, string $who, string $logoutUrl, array $hero = [], string $bell = '', array $menu = []): void {
    $isAdmin = $role === 'admin';
    $eyebrow = $hero['eyebrow'] ?? ($isAdmin ? 'Admin Panel' : 'My Account');
    $eicon   = $isAdmin ? 'bi-shield-lock' : 'bi-person-circle';
    $ht = $hero['title'] ?? $title;
    if (isset($hero['title_html'])) $h1 = $hero['title_html'];
    else {
        $w = preg_split('/(\s+)/', $ht, -1, PREG_SPLIT_DELIM_CAPTURE);
        $last = count($w) - 1;
        $w[$last] = '<em>' . h($w[$last]) . '</em>';
        for ($i = 0; $i < $last; $i++) $w[$i] = h($w[$i]);
        $h1 = implode('', $w);
    } ?>
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($title) ?> – Durable Furnitures</title>
<script>(function(){var t=localStorage.getItem("df-theme");document.documentElement.setAttribute("data-theme",(t==="light"||t==="dark")?t:"dark");})();</script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="../../style.css">
<link rel="stylesheet" href="../../luxury-upgrade.css">
<link rel="stylesheet" href="../site-theme.css">
<link rel="stylesheet" href="../panel.css?v=5">
<link rel="stylesheet" href="../panel-pro.css?v=1">
<link rel="stylesheet" href="../../polish.css">
</head><body class="panel-body">

<nav id="mainNav" class="navbar navbar-expand-lg">
  <div class="container">
    <a class="navbar-brand d-flex align-items-center gap-2 text-decoration-none" href="../../index.html" title="Back to the store">
      <div class="logo-icon"><i class="bi bi-house-heart-fill"></i></div>
      <div><div class="brand-name">DURABLE</div><div class="brand-sub">Furnitures</div></div>
    </a>
    <span class="panel-role d-none d-xl-inline-flex"><?= $isAdmin ? 'Admin' : 'Account' ?></span>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#panelNav"><span class="navbar-toggler-icon"></span></button>
    <div class="collapse navbar-collapse" id="panelNav">
      <ul class="navbar-nav ms-auto align-items-lg-center gap-1">
        <?php foreach ($nav as $file => $n):
            [$label, $key, $icon] = $n; $kids = $n[3] ?? null; $badge = (int) ($n[4] ?? 0); ?>
          <?php if ($kids): $kact = false; $ksum = 0; foreach ($kids as $k) { if (($k[2] ?? '') === $active) $kact = true; $ksum += (int) ($k[3] ?? 0); } ?>
            <li class="nav-item dropdown"><a class="nav-link dropdown-toggle <?= $kact ? 'active' : '' ?>" href="#" role="button" data-bs-toggle="dropdown"><i class="bi <?= h($icon) ?>"></i><?= h($label) ?><?php if ($ksum > 0): ?><span class="nbadge"><?= $ksum > 99 ? '99+' : $ksum ?></span><?php endif; ?></a>
              <ul class="dropdown-menu"><?php foreach ($kids as $k): ?><li><a class="dropdown-item <?= ($k[2] ?? '') === $active ? 'active' : '' ?>" href="<?= h($k[0]) ?>"><?= h($k[1]) ?><?php if ((int) ($k[3] ?? 0) > 0): ?><span class="nbadge"><?= (int) $k[3] ?></span><?php endif; ?></a></li><?php endforeach; ?></ul></li>
          <?php else: ?>
            <li class="nav-item"><a class="nav-link <?= $active === $key ? 'active' : '' ?>" href="<?= h($file) ?>"><i class="bi <?= h($icon) ?>"></i><?= h($label) ?><?php if ($badge > 0): ?><span class="nbadge"><?= $badge > 99 ? '99+' : $badge ?></span><?php endif; ?></a></li>
          <?php endif; endforeach; ?>
        <?php if ($isAdmin): ?><li class="nav-item"><button type="button" class="nav-icon-btn" id="cmdOpen" aria-label="Search everything (Ctrl+K)" title="Search (Ctrl+K)"><i class="bi bi-search"></i></button></li><?php endif; ?>
        <?= $bell ?>
        <li class="nav-item ms-1">
          <button class="theme-toggle-btn" id="themeToggleBtn" aria-label="Toggle dark/light mode" title="Toggle theme"><span class="theme-toggle-track"><span class="theme-toggle-thumb">
            <i class="bi bi-moon-stars-fill theme-icon-dark"></i><i class="bi bi-sun-fill theme-icon-light"></i></span></span></button>
        </li>
        <li class="nav-item dropdown ms-lg-2">
          <a class="nav-link user-chip dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown"><span class="avatar sm"><?= h(initials($who)) ?></span><span class="d-none d-xl-inline"><?= h($who) ?></span></a>
          <ul class="dropdown-menu dropdown-menu-end">
            <?php foreach ($menu as [$mh, $ml, $mi]): ?><li><a class="dropdown-item" href="<?= h($mh) ?>"><i class="bi <?= h($mi) ?> me-2"></i><?= h($ml) ?></a></li><?php endforeach; ?>
            <li><a class="dropdown-item" href="../../index.html"><i class="bi bi-shop me-2"></i>View store</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="<?= h($logoutUrl) ?>"><i class="bi bi-box-arrow-right me-2"></i>Sign out</a></li>
          </ul>
        </li>
      </ul>
    </div>
  </div>
</nav>

<div class="page-hero panel-hero<?= !empty($hero['tall']) ? ' tall' : '' ?>">
  <div class="container">
    <div class="ph-row">
      <div>
        <p class="section-eyebrow"><i class="bi <?= $eicon ?> me-1"></i><?= h($eyebrow) ?></p>
        <h1><?= $h1 ?></h1>
        <?php if (!empty($hero['sub'])): ?><p class="ph-sub"><?= $hero['sub'] ?></p><?php endif; ?>
      </div>
      <?php if (!empty($hero['actions'])): ?><div class="ph-actions"><?= $hero['actions'] ?></div><?php endif; ?>
    </div>
    <?= $hero['extra'] ?? '' ?>
  </div>
</div>

<main class="container panel-main">
<?php foreach (flash_take() as [$ft, $fm]): ?><div class="flash<?= $ft === 'err' ? ' err' : '' ?>" role="status"><i class="bi <?= $ft === 'err' ? 'bi-exclamation-triangle' : 'bi-check-circle' ?>"></i><span><?= h($fm) ?></span><button type="button" class="flash-x" aria-label="Dismiss">&times;</button></div><?php endforeach; ?>
<?php }

function panel_foot(): void { $isAdmin = defined('ADMIN_SIGNIN_URL'); ?>
</main>

<footer class="footer">
  <div class="container">
    <div class="row g-4">
      <div class="col-lg-4">
        <div class="d-flex align-items-center gap-2 mb-3">
          <div class="logo-icon"><i class="bi bi-house-heart-fill"></i></div>
          <div><div class="brand-name">DURABLE</div><div class="brand-sub">Furnitures</div></div>
        </div>
        <p class="footer-text">Premium furniture experience with trusted Pakistani brands. Making every home beautiful since 1999.</p>
      </div>
      <div class="col-6 col-lg-2">
        <p class="footer-heading">Pages</p>
        <ul class="footer-links">
          <li><a href="../../index.html">Home</a></li>
          <li><a href="../../about.html">About Us</a></li>
          <li><a href="../../brands.html">All Brands</a></li>
          <li><a href="../../compare.html">Compare</a></li>
          <li><a href="../../faq.html">FAQ</a></li>
          <li><a href="../../location.html">Locations</a></li>
          <li><a href="../../contact.html">Contact</a></li>
        </ul>
      </div>
      <div class="col-6 col-lg-2">
        <p class="footer-heading">Brands</p>
        <ul class="footer-links">
          <li><a href="../../brand-durian.html">Durian</a></li>
          <li><a href="../../brand-godrej.html">Godrej</a></li>
          <li><a href="../../brand-nilkamal.html">Nilkamal</a></li>
          <li><a href="../../brand-hometown.html">HomeTown</a></li>
        </ul>
      </div>
      <div class="col-lg-4">
        <p class="footer-heading">Contact</p>
        <p class="footer-text"><i class="bi bi-geo-alt me-2" style="color:var(--accent)"></i><?= h(cfg('store_address')) ?></p>
        <p class="footer-text"><i class="bi bi-telephone me-2" style="color:var(--accent)"></i><?= h(cfg('store_phone')) ?></p>
        <p class="footer-text"><i class="bi bi-envelope me-2" style="color:var(--accent)"></i><?= h(cfg('store_email')) ?></p>
      </div>
    </div>
    <hr class="footer-hr" />
    <div class="footer-bottom text-center">© 2026 Durable Furnitures. All rights reserved.</div>
  </div>
</footer>
<?php if ($isAdmin): ?>
<div class="cmd" id="cmd" hidden><div class="cmd-box" role="dialog" aria-label="Search">
  <div class="cmd-in"><i class="bi bi-search"></i><input id="cmdInput" type="text" placeholder="Search orders, customers, products, tickets…" autocomplete="off"><kbd>Esc</kbd></div>
  <div class="cmd-res" id="cmdRes"><p class="cmd-hint">Type an order number, customer name, phone, email or product.<br>Jump to: <a href="orders.php">Orders</a> · <a href="customers.php">Customers</a> · <a href="tickets.php">Tickets</a> · <a href="reports.php">Reports</a></p></div>
</div></div>
<?php endif; ?>
<div class="confirm" id="confirmBox" hidden><div class="confirm-card"><h5 id="confirmTitle">Are you sure?</h5><p id="confirmMsg"></p><div class="confirm-act"><button type="button" class="pbtn ghost" id="confirmNo">Cancel</button><button type="button" class="pbtn danger" id="confirmYes">Confirm</button></div></div></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="../../polish.js"></script>
<script src="../panel.js?v=1"></script>
<script>
(function(){
  var b=document.getElementById("themeToggleBtn");
  if(b)b.addEventListener("click",function(){var c=document.documentElement.getAttribute("data-theme"),n=c==="light"?"dark":"light";document.documentElement.setAttribute("data-theme",n);localStorage.setItem("df-theme",n);});
  var nav=document.getElementById("mainNav");
  function sc(){nav.classList.toggle("scrolled",window.scrollY>30)} window.addEventListener("scroll",sc,{passive:true}); sc();
  /* count-up numbers */
  var reduce=window.matchMedia&&matchMedia("(prefers-reduced-motion: reduce)").matches;
  document.querySelectorAll("[data-count]").forEach(function(el){
    var end=parseFloat(el.dataset.count)||0,pre=el.dataset.prefix||"",suf=el.dataset.suffix||"";
    function put(v){el.textContent=pre+Math.round(v).toLocaleString("en-US")+suf}
    if(reduce||end===0){put(end);return}
    var t0=null,dur=900;(function step(t){if(!t0)t0=t;var p=Math.min(1,(t-t0)/dur);put(end*(1-Math.pow(1-p,3)));if(p<1)requestAnimationFrame(step)})(performance.now());
  });
})();
</script>
</body></html>
<?php }

/** Pretty "5 min ago" / date. */
function ago(string $ts): string {
    $d = time() - strtotime($ts);
    if ($d < 60) return 'just now';
    if ($d < 3600) return floor($d / 60) . ' min ago';
    if ($d < 86400) return floor($d / 3600) . ' h ago';
    if ($d < 86400 * 7) return floor($d / 86400) . ' d ago';
    return date('d M Y', strtotime($ts));
}
function dt(?string $ts): string { return $ts ? date('d M Y, h:i A', strtotime($ts)) : '—'; }

/** Order progress stepper (pending → delivered) with icons. */
function order_stepper(string $status): string {
    $flow = ['pending' => 'bi-hourglass-split', 'confirmed' => 'bi-check2-circle', 'processing' => 'bi-tools', 'shipped' => 'bi-truck', 'delivered' => 'bi-house-heart'];
    $idx = array_search($status, array_keys($flow), true);
    $o = '<div class="prog' . ($status === 'cancelled' ? ' cancelled' : '') . '">';
    $i = 0;
    foreach ($flow as $k => $ic) {
        $done = $idx !== false && $i <= $idx;
        $o .= '<div class="st' . ($done ? ' done' : '') . ($idx === $i ? ' now' : '') . '"><i><span class="bi ' . ($done && $idx !== $i ? 'bi-check-lg' : $ic) . '"></span></i>' . h($k) . '</div>';
        $i++;
    }
    return $o . '</div>';
}

/** Smooth SVG area chart. $days = [['d'=>'Y-m-d','n'=>orders,'rev'=>amount], ...] */
function revenue_chart(array $days, string $aria = 'Revenue over time'): string {
    $W = 680; $H = 250; $pl = 54; $pr = 14; $pt = 14; $pb = 30;
    $max = max(1, ...array_column($days, 'rev'));
    $step = pow(10, floor(log10($max))); $nice = ceil($max / $step) * $step;
    if ($nice / $step < 2) $nice = 2 * $step;
    $n = count($days); $iw = $W - $pl - $pr; $ih = $H - $pt - $pb;
    $pts = [];
    foreach ($days as $i => $d) $pts[] = [$pl + ($n > 1 ? $i * $iw / ($n - 1) : 0), $pt + $ih - ($d['rev'] / $nice) * $ih];
    $line = 'M' . round($pts[0][0], 1) . ',' . round($pts[0][1], 1);
    for ($i = 1; $i < $n; $i++) {
        $mx = ($pts[$i - 1][0] + $pts[$i][0]) / 2;
        $line .= ' C' . round($mx, 1) . ',' . round($pts[$i - 1][1], 1) . ' ' . round($mx, 1) . ',' . round($pts[$i][1], 1) . ' ' . round($pts[$i][0], 1) . ',' . round($pts[$i][1], 1);
    }
    $base = $pt + $ih;
    $area = $line . ' L' . round($pts[$n - 1][0], 1) . ',' . $base . ' L' . round($pts[0][0], 1) . ',' . $base . ' Z';
    $fmt = fn($v) => $v >= 1000000 ? round($v / 1000000, 1) . 'M' : ($v >= 1000 ? round($v / 1000) . 'k' : (string) $v);
    $svg = '<svg class="chart" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . h($aria) . '"><defs><linearGradient id="rg" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="var(--gold)" stop-opacity=".38"/><stop offset="100%" stop-color="var(--gold)" stop-opacity="0"/></linearGradient></defs>';
    for ($g = 0; $g <= 4; $g++) {
        $y = $pt + $ih - $g * $ih / 4;
        $svg .= '<line class="grid" x1="' . $pl . '" x2="' . ($W - $pr) . '" y1="' . round($y, 1) . '" y2="' . round($y, 1) . '"/><text x="' . ($pl - 10) . '" y="' . round($y + 4, 1) . '" text-anchor="end">' . $fmt((int) ($nice * $g / 4)) . '</text>';
    }
    $svg .= '<path class="area" d="' . $area . '"/><path class="line" d="' . $line . '"/>';
    foreach ($days as $i => $d) {
        $tip = date('D, d M', strtotime($d['d'])) . ': ' . $d['n'] . ' order(s), Rs.' . number_format($d['rev']);
        $svg .= '<g class="pt"><circle class="hit" cx="' . round($pts[$i][0], 1) . '" cy="' . round($pts[$i][1], 1) . '" r="13"/><circle class="dot' . ($d['rev'] ? ' has' : '') . '" cx="' . round($pts[$i][0], 1) . '" cy="' . round($pts[$i][1], 1) . '" r="4"/><title>' . h($tip) . '</title></g>';
        if ($i % max(1, (int) ceil($n / 8)) === 0 || $i === $n - 1) $svg .= '<text x="' . round($pts[$i][0], 1) . '" y="' . ($H - 8) . '" text-anchor="middle">' . date('d M', strtotime($d['d'])) . '</text>';
    }
    return $svg . '</svg>';
}

/** Donut chart. $parts = [[label, value, color, href], ...] */
function donut_chart(array $parts, string $centerLabel): string {
    $total = array_sum(array_column($parts, 1)); $r = 62; $C = 2 * M_PI * $r;
    $s = '<div class="donut"><svg viewBox="0 0 170 170"><circle cx="85" cy="85" r="' . $r . '" class="ring-bg"/>';
    $off = 0;
    if ($total > 0) foreach ($parts as [$l, $v, $c]) {
        if ($v <= 0) continue; $len = $v / $total * $C;
        $s .= '<circle cx="85" cy="85" r="' . $r . '" class="seg" stroke="' . $c . '" stroke-dasharray="' . round(max(0, $len - 3), 2) . ' ' . round($C - max(0, $len - 3), 2) . '" stroke-dashoffset="' . round(-$off, 2) . '"><title>' . h($l . ': ' . $v) . '</title></circle>';
        $off += $len;
    }
    return $s . '</svg><div class="mid"><b>' . $total . '</b><span>' . h($centerLabel) . '</span></div></div>';
}
