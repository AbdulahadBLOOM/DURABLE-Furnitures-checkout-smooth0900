<?php
require __DIR__ . '/_auth.php'; admin_required();
$pdo = db();
$range = (int) ($_GET['range'] ?? 14); if (!in_array($range, [7, 14, 30, 90], true)) $range = 14;

/* ── all-time headline numbers ── */
$s = $pdo->query("SELECT COUNT(*) n,
    COALESCE(SUM(status='pending'),0) pend,
    COALESCE(SUM(DATE(created_at)=CURDATE()),0) today,
    COALESCE(SUM(CASE WHEN status<>'cancelled' THEN total END),0) rev,
    COALESCE(SUM(CASE WHEN status<>'cancelled' AND DATE(created_at)=CURDATE() THEN total END),0) rev_today,
    COALESCE(SUM(status IN ('pending','confirmed','processing','shipped')),0) open_orders
    FROM orders")->fetch();
$validCnt = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status<>'cancelled'")->fetchColumn();
$aov = $validCnt ? (int) round($s['rev'] / $validCnt) : 0;
$cu = $pdo->query("SELECT COUNT(*) n FROM customers")->fetch();
$N = admin_counts();

/* ── selected period vs the period before it ── */
$per = function (string $from, string $to) use ($pdo) {
    $st = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(CASE WHEN status<>'cancelled' THEN total END),0) rev,
        COALESCE(SUM(status='cancelled'),0) canc FROM orders WHERE created_at >= ? AND created_at < ?");
    $st->execute([$from, $to]); $r = $st->fetch();
    $c = $pdo->prepare('SELECT COUNT(*) FROM customers WHERE created_at >= ? AND created_at < ?'); $c->execute([$from, $to]);
    $r['newc'] = (int) $c->fetchColumn(); $r['valid'] = (int) $r['n'] - (int) $r['canc'];
    $r['aov'] = $r['valid'] ? (int) round($r['rev'] / $r['valid']) : 0;
    return $r;
};
$curFrom = date('Y-m-d', strtotime('-' . ($range - 1) . ' day')); $tomorrow = date('Y-m-d', strtotime('+1 day'));
$prevFrom = date('Y-m-d', strtotime('-' . (2 * $range - 1) . ' day'));
$P = $per($curFrom, $tomorrow); $Q = $per($prevFrom, $curFrom);

/* ── daily series ── */
$map = [];
$st = $pdo->prepare("SELECT DATE(created_at) d, COUNT(*) n, COALESCE(SUM(CASE WHEN status<>'cancelled' THEN total END),0) rev
    FROM orders WHERE created_at >= ? GROUP BY DATE(created_at)");
$st->execute([$curFrom]);
foreach ($st as $r) $map[$r['d']] = $r;
$days = [];
for ($i = $range - 1; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i day"));
    $days[] = ['d' => $d, 'n' => (int) ($map[$d]['n'] ?? 0), 'rev' => (int) ($map[$d]['rev'] ?? 0)];
}

/* ── breakdowns (selected period) ── */
$byStatus = array_column($pdo->query('SELECT status, COUNT(*) n FROM orders GROUP BY status')->fetchAll(), 'n', 'status');
$q = function (string $sql) use ($pdo, $curFrom) { $x = $pdo->prepare($sql); $x->execute([$curFrom]); return $x->fetchAll(); };
$byBrand = $q("SELECT oi.brand, SUM(oi.line_total) rev FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE o.status<>'cancelled' AND o.created_at >= ? GROUP BY oi.brand ORDER BY rev DESC LIMIT 6");
$top     = $q("SELECT oi.name, oi.brand, SUM(oi.qty) q, SUM(oi.line_total) rev FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE o.status<>'cancelled' AND o.created_at >= ? GROUP BY oi.name, oi.brand ORDER BY q DESC, rev DESC LIMIT 6");
$byCity  = $q("SELECT city, COUNT(*) n, SUM(total) rev FROM orders WHERE status<>'cancelled' AND created_at >= ? GROUP BY city ORDER BY rev DESC LIMIT 5");
$byPay   = $q("SELECT payment_method m, COUNT(*) n, SUM(total) rev FROM orders WHERE status<>'cancelled' AND created_at >= ? GROUP BY payment_method ORDER BY rev DESC");
$couponUse = $q("SELECT COUNT(*) c FROM orders WHERE coupon_code <> '' AND created_at >= ?")[0]['c'] ?? 0;
$recent  = $pdo->query('SELECT id, order_number, customer_name, city, total, status, payment_status, customer_id, created_at FROM orders ORDER BY id DESC LIMIT 8')->fetchAll();
$newCust = $pdo->query('SELECT id, full_name, email, created_at FROM customers ORDER BY id DESC LIMIT 5')->fetchAll();
$tick    = $pdo->query("SELECT t.id, t.ticket_no, t.subject, t.priority, t.updated_at, c.full_name FROM tickets t JOIN customers c ON c.id = t.customer_id WHERE t.status = 'open' ORDER BY FIELD(t.priority,'high','normal','low'), t.updated_at LIMIT 4")->fetchAll();

/* ── live activity feed (built from real tables) ── */
$feed = [];
foreach ($pdo->query('SELECT id, order_number, customer_name, total, created_at FROM orders ORDER BY id DESC LIMIT 10') as $r)
    $feed[] = ['t' => $r['created_at'], 'i' => 'bi-bag-check', 'h' => '<a href="order.php?id=' . $r['id'] . '"><b>' . h($r['order_number']) . '</b></a> placed by ' . h($r['customer_name']) . ' · ' . money($r['total'])];
foreach ($pdo->query("SELECT h.status, h.changed_by, h.created_at, o.id, o.order_number FROM order_status_history h JOIN orders o ON o.id = h.order_id
    WHERE h.changed_by <> 'customer' ORDER BY h.id DESC LIMIT 10") as $r)
    $feed[] = ['t' => $r['created_at'], 'i' => 'bi-arrow-repeat', 'h' => '<a href="order.php?id=' . $r['id'] . '"><b>' . h($r['order_number']) . '</b></a> marked <span class="pbadge s-' . h($r['status']) . '">' . h($r['status']) . '</span> by ' . h($r['changed_by'])];
foreach ($pdo->query('SELECT id, full_name, created_at FROM customers ORDER BY id DESC LIMIT 8') as $r)
    $feed[] = ['t' => $r['created_at'], 'i' => 'bi-person-plus', 'h' => 'New customer <a href="customer.php?id=' . $r['id'] . '"><b>' . h($r['full_name']) . '</b></a> signed up'];
foreach ($pdo->query('SELECT t.id, t.ticket_no, t.created_at, c.full_name FROM tickets t JOIN customers c ON c.id = t.customer_id ORDER BY t.id DESC LIMIT 6') as $r)
    $feed[] = ['t' => $r['created_at'], 'i' => 'bi-life-preserver', 'h' => 'Ticket <a href="ticket.php?id=' . $r['id'] . '"><b>' . h($r['ticket_no']) . '</b></a> opened by ' . h($r['full_name'])];
foreach ($pdo->query('SELECT name, subject, created_at FROM contact_messages ORDER BY id DESC LIMIT 8') as $r)
    $feed[] = ['t' => $r['created_at'], 'i' => 'bi-envelope', 'h' => '<a href="messages.php">Message</a> from <b>' . h($r['name']) . '</b>' . ($r['subject'] ? ' – ' . h($r['subject']) : '')];
usort($feed, fn($a, $b) => strcmp($b['t'], $a['t']));
$feed = array_slice($feed, 0, 9);

$hr = (int) date('G'); $greet = $hr < 12 ? 'Good morning' : ($hr < 17 ? 'Good afternoon' : 'Good evening');
$A = $GLOBALS['ADMIN']; $first = explode(' ', trim($A['full_name'] ?: $A['username']))[0];
$statusColors = ['pending' => '#f59e0b', 'confirmed' => '#3b82f6', 'processing' => '#8b5cf6', 'shipped' => '#06b6d4', 'delivered' => '#10b981', 'cancelled' => '#ef4444'];
$parts = []; foreach (ORDER_STATUSES as $stt) $parts[] = [ucfirst($stt), (int) ($byStatus[$stt] ?? 0), $statusColors[$stt], $stt];
$payLabels = array_map(fn($m) => $m['label'], cfg('payment_methods'));

admin_head('Dashboard', 'dashboard', [
    'title_html' => $greet . ', <em>' . h($first) . '</em>',
    'sub'  => date('l, d F Y') . ' &middot; here is everything happening in your store.',
    'tall' => true,
    'actions' => '<a class="pbtn" href="orders.php?status=pending"><i class="bi bi-hourglass-split"></i>Review pending (' . (int) $s['pend'] . ')</a><a class="pbtn ghost" href="reports.php"><i class="bi bi-graph-up-arrow"></i>Reports</a><a class="pbtn ghost" href="index.php?range=' . $range . '"><i class="bi bi-arrow-clockwise"></i>Refresh</a>',
]); ?>

<div class="kpis overlap">
  <div class="kpi hl"><span class="ico"><i class="bi bi-cash-coin"></i></span><div><small>Revenue today</small><b data-count="<?= (int) $s['rev_today'] ?>" data-prefix="Rs."><?= money($s['rev_today']) ?></b><em><?= (int) $s['today'] ?> order(s) today</em></div></div>
  <div class="kpi"><span class="ico"><i class="bi bi-graph-up-arrow"></i></span><div><small>Last <?= $range ?> days</small><b><?= money($P['rev']) ?></b><em>vs previous <?= delta_badge($P['rev'], $Q['rev']) ?></em></div></div>
  <a class="kpi" href="orders.php"><span class="ico"><i class="bi bi-bag-check"></i></span><div><small>Orders (<?= $range ?>d)</small><b><?= (int) $P['n'] ?></b><em><?= (int) $s['open_orders'] ?> open <?= delta_badge($P['n'], $Q['n']) ?></em></div></a>
  <div class="kpi"><span class="ico"><i class="bi bi-receipt-cutoff"></i></span><div><small>Avg. order value</small><b><?= money($P['aov'] ?: $aov) ?></b><em>vs previous <?= delta_badge($P['aov'], $Q['aov']) ?></em></div></div>
  <a class="kpi" href="customers.php"><span class="ico"><i class="bi bi-people"></i></span><div><small>Customers</small><b data-count="<?= (int) $cu['n'] ?>"><?= (int) $cu['n'] ?></b><em>+<?= (int) $P['newc'] ?> in <?= $range ?>d <?= delta_badge($P['newc'], $Q['newc']) ?></em></div></a>
  <div class="kpi"><span class="ico"><i class="bi bi-wallet2"></i></span><div><small>Lifetime revenue</small><b><?= money_short($s['rev']) ?></b><em><?= (int) $s['n'] ?> orders in total</em></div></div>
</div>

<?php if ($N['pending'] || $N['unpaid'] || $N['unread'] || $N['tickets']): ?>
<div class="alertbar"><span class="ab-ico"><i class="bi bi-bell"></i></span><div class="ab-txt"><b>Needs your attention</b>
  <?php if ($N['pending']): ?><a href="orders.php?status=pending"><?= $N['pending'] ?> pending order(s) to confirm</a><?php endif; ?>
  <?php if ($N['tickets']): ?><a href="tickets.php?status=open"><?= $N['tickets'] ?> open support ticket(s)</a><?php endif; ?>
  <?php if ($N['unpaid']): ?><a href="orders.php?pay=unpaid"><?= $N['unpaid'] ?> shipped/delivered order(s) still unpaid</a><?php endif; ?>
  <?php if ($N['unread']): ?><a href="messages.php?filter=unread"><?= $N['unread'] ?> unread message(s)</a><?php endif; ?></div></div>
<?php endif; ?>

<div class="dgrid">
  <section class="pcard span-8">
    <h4><span><i class="bi bi-graph-up"></i>Revenue &middot; last <?= $range ?> days</span>
      <span class="hint"><?php foreach ([7, 14, 30, 90] as $r): ?><a href="?range=<?= $r ?>" style="<?= $r === $range ? 'font-weight:700;text-decoration:underline;' : '' ?>margin-left:10px"><?= $r ?>d</a><?php endforeach; ?></span></h4>
    <div class="chart-wrap"><?= revenue_chart($days, 'Revenue over the last ' . $range . ' days') ?></div>
    <?php if ((int) $P['rev'] === 0): ?><p class="chart-empty">No sales in this period yet. Revenue appears here as orders come in.</p><?php endif; ?>
  </section>

  <section class="pcard span-4">
    <h4><span><i class="bi bi-pie-chart"></i>Orders by status</span></h4>
    <?= donut_chart($parts, 'orders') ?>
    <ul class="legend"><?php foreach ($parts as [$l, $v, $c, $stt]): ?>
      <li><a href="orders.php?status=<?= $stt ?>"><i style="background:<?= $c ?>"></i><span><?= h($l) ?></span><b><?= $v ?></b></a></li><?php endforeach; ?></ul>
  </section>

  <section class="span-8">
    <div class="sec-head"><h3>Latest orders</h3><a href="orders.php">View all <i class="bi bi-arrow-right"></i></a></div>
    <div class="tablewrap"><table><tr><th>Order</th><th>Customer</th><th class="r">Total</th><th>Payment</th><th>Status</th><th>When</th></tr>
    <?php foreach ($recent as $o): ?><tr>
      <td><a href="order.php?id=<?= $o['id'] ?>"><b><?= h($o['order_number']) ?></b></a></td>
      <td><div class="who-cell"><span class="avatar sm"><?= h(initials($o['customer_name'])) ?></span><div><?= h($o['customer_name']) ?> <?= $o['customer_id'] ? '' : '<small class="mut">(guest)</small>' ?><br><small class="mut"><?= h($o['city']) ?></small></div></div></td>
      <td class="r nw"><b><?= money($o['total']) ?></b></td>
      <td><span class="pbadge s-<?= h($o['payment_status']) ?>"><?= h($o['payment_status']) ?></span></td>
      <td><span class="pbadge s-<?= h($o['status']) ?>"><?= h($o['status']) ?></span></td>
      <td class="nw"><small class="mut"><?= h(ago($o['created_at'])) ?></small></td></tr>
    <?php endforeach; if (!$recent) echo '<tr><td colspan="6" class="empty"><i class="bi bi-inbox"></i>No orders yet. They appear here the moment a customer checks out.</td></tr>'; ?></table></div>
  </section>

  <section class="pcard span-4">
    <h4><span><i class="bi bi-activity"></i>Live activity</span><a href="activity.php">Log <i class="bi bi-arrow-right"></i></a></h4>
    <ul class="feed"><?php foreach ($feed as $f): ?><li><span class="ic"><i class="bi <?= $f['i'] ?>"></i></span><span class="tx"><?= $f['h'] ?></span><span class="tm"><?= h(ago($f['t'])) ?></span></li><?php endforeach;
    if (!$feed) echo '<li class="mut">Nothing yet.</li>'; ?></ul>
  </section>

  <section class="pcard span-4">
    <h4><span><i class="bi bi-trophy"></i>Best sellers &middot; <?= $range ?>d</span><a href="products.php">Catalogue <i class="bi bi-arrow-right"></i></a></h4>
    <ol class="rank"><?php foreach ($top as $i => $p): ?><li><span class="no"><?= $i + 1 ?></span><div><b><?= h($p['name']) ?></b><small><?= h($p['brand']) ?></small></div><div class="r"><b><?= (int) $p['q'] ?> sold</b><small><?= money($p['rev']) ?></small></div></li><?php endforeach;
    if (!$top) echo '<li class="mut">No sales in this period.</li>'; ?></ol>
  </section>

  <section class="pcard span-4">
    <h4><span><i class="bi bi-bar-chart-steps"></i>Sales by brand</span></h4>
    <?php $bm = max(1, (int) ($byBrand[0]['rev'] ?? 1)); foreach ($byBrand as $b): ?>
      <div class="bar-h"><div class="t"><span><?= h($b['brand']) ?></span><b><?= money($b['rev']) ?></b></div><div class="tr"><i style="width:<?= round($b['rev'] / $bm * 100) ?>%"></i></div></div>
    <?php endforeach; if (!$byBrand) echo '<p class="mut">No sales in this period.</p>'; ?>
  </section>

  <section class="pcard span-4">
    <h4><span><i class="bi bi-geo-alt"></i>Top cities</span></h4>
    <?php $cm = max(1, (int) ($byCity[0]['rev'] ?? 1)); foreach ($byCity as $c): ?>
      <div class="hbar"><span class="lb"><?= h($c['city']) ?></span><span class="tr"><i style="width:<?= round($c['rev'] / $cm * 100) ?>%"></i></span><b><?= money_short($c['rev']) ?></b></div>
    <?php endforeach; if (!$byCity) echo '<p class="mut">No sales in this period.</p>'; ?>
    <?php if ($byPay): ?><hr style="border-color:var(--border);margin:18px 0 8px"><small class="mut" style="text-transform:uppercase;letter-spacing:.1em;font-size:.64rem;font-weight:700">Payment methods</small>
      <?php $pm = max(1, (int) $byPay[0]['rev']); foreach ($byPay as $b): ?><div class="hbar"><span class="lb"><?= h($payLabels[$b['m']] ?? $b['m']) ?></span><span class="tr"><i style="width:<?= round($b['rev'] / $pm * 100) ?>%"></i></span><b><?= (int) $b['n'] ?> order(s)</b></div><?php endforeach; endif; ?>
  </section>

  <section class="pcard span-4">
    <h4><span><i class="bi bi-life-preserver"></i>Open support tickets</span><a href="tickets.php">All <i class="bi bi-arrow-right"></i></a></h4>
    <ul class="people"><?php foreach ($tick as $t): ?><li><span class="avatar sm"><?= h(initials($t['full_name'])) ?></span><div><a href="ticket.php?id=<?= $t['id'] ?>"><?= h($t['subject']) ?></a><small><?= h($t['ticket_no']) ?> · <?= h($t['full_name']) ?></small></div><span class="pbadge s-<?= h($t['priority']) ?>"><?= h($t['priority']) ?></span></li><?php endforeach;
    if (!$tick) echo '<li class="mut"><i class="bi bi-check2-circle" style="color:var(--gold)"></i> Inbox zero – no open tickets.</li>'; ?></ul>
  </section>

  <section class="pcard span-4">
    <h4><span><i class="bi bi-person-plus"></i>Newest customers</span><a href="customers.php">All <i class="bi bi-arrow-right"></i></a></h4>
    <ul class="people"><?php foreach ($newCust as $c): ?><li><span class="avatar"><?= h(initials($c['full_name'])) ?></span><div><a href="customer.php?id=<?= $c['id'] ?>"><?= h($c['full_name']) ?></a><small><?= h($c['email']) ?></small></div><small class="tm"><?= h(ago($c['created_at'])) ?></small></li><?php endforeach;
    if (!$newCust) echo '<li class="mut">No customers have signed up yet.</li>'; ?></ul>
    <?php if ($couponUse): ?><p class="mut" style="margin:14px 0 0;font-size:.82rem"><i class="bi bi-ticket-perforated" style="color:var(--gold)"></i> <?= (int) $couponUse ?> order(s) used a coupon in this period.</p><?php endif; ?>
  </section>
</div>
<?php admin_foot();
