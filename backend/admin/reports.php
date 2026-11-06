<?php
require __DIR__ . '/_auth.php'; admin_required(); $pdo = db();
$preset = $_GET['r'] ?? '30';
$today = date('Y-m-d');
$ranges = ['7' => [date('Y-m-d', strtotime('-6 day')), $today], '30' => [date('Y-m-d', strtotime('-29 day')), $today], '90' => [date('Y-m-d', strtotime('-89 day')), $today],
           'month' => [date('Y-m-01'), $today], 'lastmonth' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last month'))], 'year' => [date('Y-01-01'), $today]];
if ($preset === 'custom') { $from = valid_date($_GET['from'] ?? '') ?: $ranges['30'][0]; $to = valid_date($_GET['to'] ?? '') ?: $today; if ($from > $to) [$from, $to] = [$to, $from]; }
else { if (!isset($ranges[$preset])) $preset = '30'; [$from, $to] = $ranges[$preset]; }
$a = [$from . ' 00:00:00', date('Y-m-d', strtotime("$to +1 day")) . ' 00:00:00'];
$daysN = max(1, (int) round((strtotime($to) - strtotime($from)) / 86400) + 1);
$pf = date('Y-m-d', strtotime("$from -$daysN day")); $pa = [$pf . ' 00:00:00', $a[0]];
$Q = function (string $sql, array $args) use ($pdo) { $s = $pdo->prepare($sql); $s->execute($args); return $s->fetchAll(); };

$sum = function (array $r) use ($Q) { return $Q("SELECT COUNT(*) n, COALESCE(SUM(status='cancelled'),0) canc, COALESCE(SUM(CASE WHEN status<>'cancelled' THEN total END),0) rev,
    COALESCE(SUM(CASE WHEN status<>'cancelled' THEN discount END),0) disc, COALESCE(SUM(CASE WHEN status<>'cancelled' THEN delivery_fee END),0) deliv,
    COALESCE(SUM(CASE WHEN status<>'cancelled' AND payment_status='paid' THEN total END),0) paid,
    COALESCE(SUM(CASE WHEN status<>'cancelled' AND payment_status='unpaid' THEN total END),0) unpaid FROM orders WHERE created_at >= ? AND created_at < ?", $r)[0]; };
$S = $sum($a); $Pv = $sum($pa);
$valid = (int) $S['n'] - (int) $S['canc']; $pvalid = (int) $Pv['n'] - (int) $Pv['canc'];
$aov = $valid ? round($S['rev'] / $valid) : 0; $paov = $pvalid ? round($Pv['rev'] / $pvalid) : 0;
$newC = (int) $Q('SELECT COUNT(*) c FROM customers WHERE created_at >= ? AND created_at < ?', $a)[0]['c']; $pnewC = (int) $Q('SELECT COUNT(*) c FROM customers WHERE created_at >= ? AND created_at < ?', $pa)[0]['c'];
$units = (int) $Q("SELECT COALESCE(SUM(oi.qty),0) u FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE o.status<>'cancelled' AND o.created_at >= ? AND o.created_at < ?", $a)[0]['u'];
$cancelRate = $S['n'] ? round($S['canc'] / $S['n'] * 100, 1) : 0;
$repeat = $Q("SELECT COUNT(*) c FROM (SELECT customer_id FROM orders WHERE customer_id IS NOT NULL AND status<>'cancelled' AND created_at >= ? AND created_at < ? GROUP BY customer_id HAVING COUNT(*) > 1) x", $a)[0]['c'];

$map = []; foreach ($Q("SELECT DATE(created_at) d, COUNT(*) n, COALESCE(SUM(CASE WHEN status<>'cancelled' THEN total END),0) rev FROM orders WHERE created_at >= ? AND created_at < ? GROUP BY DATE(created_at)", $a) as $r) $map[$r['d']] = $r;
$days = []; for ($i = 0; $i < $daysN && $i < 400; $i++) { $d = date('Y-m-d', strtotime("$from +$i day")); $days[] = ['d' => $d, 'n' => (int) ($map[$d]['n'] ?? 0), 'rev' => (int) ($map[$d]['rev'] ?? 0)]; }
$brand = $Q("SELECT oi.brand k, SUM(oi.qty) q, SUM(oi.line_total) rev FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE o.status<>'cancelled' AND o.created_at >= ? AND o.created_at < ? GROUP BY oi.brand ORDER BY rev DESC", $a);
$prod = $Q("SELECT oi.name k, oi.brand b, SUM(oi.qty) q, SUM(oi.line_total) rev FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE o.status<>'cancelled' AND o.created_at >= ? AND o.created_at < ? GROUP BY oi.name, oi.brand ORDER BY rev DESC LIMIT 10", $a);
$city = $Q("SELECT city k, COUNT(*) n, SUM(total) rev FROM orders WHERE status<>'cancelled' AND created_at >= ? AND created_at < ? GROUP BY city ORDER BY rev DESC LIMIT 8", $a);
$pay = $Q("SELECT payment_method k, COUNT(*) n, SUM(total) rev FROM orders WHERE status<>'cancelled' AND created_at >= ? AND created_at < ? GROUP BY payment_method ORDER BY rev DESC", $a);
$stat = $Q('SELECT status k, COUNT(*) n FROM orders WHERE created_at >= ? AND created_at < ? GROUP BY status', $a);
$dow = array_fill(0, 7, 0); foreach ($Q("SELECT WEEKDAY(created_at) w, COUNT(*) n FROM orders WHERE status<>'cancelled' AND created_at >= ? AND created_at < ? GROUP BY w", $a) as $r) $dow[(int) $r['w']] = (int) $r['n'];
$topC = $Q("SELECT c.id, c.full_name, COUNT(o.id) n, SUM(o.total) rev FROM orders o JOIN customers c ON c.id = o.customer_id WHERE o.status<>'cancelled' AND o.created_at >= ? AND o.created_at < ? GROUP BY c.id ORDER BY rev DESC LIMIT 5", $a);
$pl = array_map(fn($m) => $m['label'], cfg('payment_methods'));

if (($_GET['csv'] ?? '') === '1') {
    log_activity('export', 'report ' . $from . '..' . $to);
    csv_stream("sales-report-$from-to-$to.csv", ['Date', 'Orders', 'Revenue (non-cancelled)'], array_map(fn($d) => [$d['d'], $d['n'], $d['rev']], $days));
}
$labels = ['7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days', 'month' => 'This month', 'lastmonth' => 'Last month', 'year' => 'This year', 'custom' => 'Custom'];
$bar = function (array $rows, string $kf, string $vf, callable $lab, callable $right) { $m = max(1, ...array_map(fn($r) => (int) $r[$vf], $rows ?: [[$vf => 1]])); $o = '';
    foreach ($rows as $r) $o .= '<div class="hbar"><span class="lb" title="' . h($lab($r)) . '">' . h($lab($r)) . '</span><span class="tr"><i style="width:' . round($r[$vf] / $m * 100) . '%"></i></span><b>' . $right($r) . '</b></div>';
    return $o ?: '<p class="mut">No data in this period.</p>'; };
admin_head('Reports', 'reports', ['sub' => date('d M Y', strtotime($from)) . ' – ' . date('d M Y', strtotime($to)) . ' · compared with the ' . $daysN . ' days before.',
  'actions' => '<a class="pbtn ghost" href="' . h(qs(['csv' => 1])) . '"><i class="bi bi-download"></i>Daily CSV</a><button type="button" class="pbtn ghost ghostb" data-print><i class="bi bi-printer"></i>Print</button>']); ?>
<div class="stabs no-print"><?php foreach ($labels as $k => $l): ?><a class="<?= $preset === $k ? 'on' : '' ?>" href="?r=<?= $k ?>"><?= $l ?></a><?php endforeach; ?></div>
<?php if ($preset === 'custom' || isset($_GET['from'])): ?><?php endif; ?>
<form class="fbar no-print" method="get"><input type="hidden" name="r" value="custom"><div class="f"><label>From</label><input type="date" name="from" value="<?= h($from) ?>"></div><div class="f"><label>To</label><input type="date" name="to" value="<?= h($to) ?>"></div><div class="fx"><button>Apply range</button></div></form>

<div class="kpis">
 <div class="kpi hl"><span class="ico"><i class="bi bi-cash-coin"></i></span><div><small>Revenue</small><b><?= money($S['rev']) ?></b><em>vs previous <?= delta_badge($S['rev'], $Pv['rev']) ?></em></div></div>
 <div class="kpi"><span class="ico"><i class="bi bi-bag-check"></i></span><div><small>Orders</small><b><?= (int) $S['n'] ?></b><em><?= $valid ?> valid <?= delta_badge($S['n'], $Pv['n']) ?></em></div></div>
 <div class="kpi"><span class="ico"><i class="bi bi-receipt-cutoff"></i></span><div><small>Avg. order</small><b><?= money($aov) ?></b><em>vs previous <?= delta_badge($aov, $paov) ?></em></div></div>
 <div class="kpi"><span class="ico"><i class="bi bi-box-seam"></i></span><div><small>Units sold</small><b><?= $units ?></b><em><?= $valid ? round($units / $valid, 1) : 0 ?> per order</em></div></div>
 <div class="kpi"><span class="ico"><i class="bi bi-person-plus"></i></span><div><small>New customers</small><b><?= $newC ?></b><em>vs previous <?= delta_badge($newC, $pnewC) ?></em></div></div>
 <div class="kpi"><span class="ico"><i class="bi bi-x-octagon"></i></span><div><small>Cancel rate</small><b><?= $cancelRate ?>%</b><em><?= (int) $S['canc'] ?> cancelled</em></div></div>
</div>
<div class="stat-row"><div class="mini-kpi"><small>Collected (paid)</small><b><?= money($S['paid']) ?></b></div><div class="mini-kpi"><small>Outstanding (unpaid)</small><b><?= money($S['unpaid']) ?></b></div>
 <div class="mini-kpi"><small>Discounts given</small><b><?= money($S['disc']) ?></b></div><div class="mini-kpi"><small>Delivery fees</small><b><?= money($S['deliv']) ?></b></div><div class="mini-kpi"><small>Repeat buyers</small><b><?= (int) $repeat ?></b></div></div>

<div class="dgrid">
 <section class="pcard span-12"><h4><span><i class="bi bi-graph-up"></i>Revenue by day</span><span class="hint"><?= count($days) ?> day(s)</span></h4><div class="chart-wrap"><?= revenue_chart($days, 'Revenue by day') ?></div></section>
 <section class="pcard span-4"><h4><span><i class="bi bi-bar-chart-steps"></i>By brand</span></h4><?= $bar($brand, 'k', 'rev', fn($r) => $r['k'], fn($r) => money_short($r['rev'])) ?></section>
 <section class="pcard span-4"><h4><span><i class="bi bi-geo-alt"></i>By city</span></h4><?= $bar($city, 'k', 'rev', fn($r) => $r['k'], fn($r) => money_short($r['rev'])) ?></section>
 <section class="pcard span-4"><h4><span><i class="bi bi-credit-card"></i>By payment method</span></h4><?= $bar($pay, 'k', 'rev', fn($r) => $pl[$r['k']] ?? $r['k'], fn($r) => $r['n'] . ' · ' . money_short($r['rev'])) ?></section>
 <section class="pcard span-8"><h4><span><i class="bi bi-trophy"></i>Top products</span></h4>
  <div class="tablewrap" style="border:0;box-shadow:none"><table><tr><th>#</th><th>Product</th><th class="r">Units</th><th class="r">Revenue</th></tr>
  <?php foreach ($prod as $i => $p): ?><tr><td><?= $i + 1 ?></td><td><b><?= h($p['k']) ?></b><br><small class="mut"><?= h($p['b']) ?></small></td><td class="r"><?= (int) $p['q'] ?></td><td class="r nw"><?= money($p['rev']) ?></td></tr><?php endforeach; if (!$prod) echo '<tr><td colspan="4" class="empty">No sales in this period.</td></tr>'; ?></table></div></section>
 <section class="pcard span-4"><h4><span><i class="bi bi-calendar-week"></i>Orders by weekday</span></h4>
  <div class="minibars"><?php $mx = max(1, ...$dow); foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $i => $d): ?><div class="mb" title="<?= $dow[$i] ?> order(s)"><i class="<?= $dow[$i] ? '' : 'z' ?>" style="height:<?= max(3, round($dow[$i] / $mx * 100)) ?>%"></i><span><?= $d ?></span></div><?php endforeach; ?></div>
  <hr style="border-color:var(--border);margin:18px 0 6px"><small class="mut" style="text-transform:uppercase;letter-spacing:.1em;font-size:.64rem;font-weight:700">Best customers</small>
  <ul class="people"><?php foreach ($topC as $c): ?><li><span class="avatar sm"><?= h(initials($c['full_name'])) ?></span><div><a href="customer.php?id=<?= $c['id'] ?>"><?= h($c['full_name']) ?></a><small><?= (int) $c['n'] ?> order(s)</small></div><b><?= money_short($c['rev']) ?></b></li><?php endforeach; if (!$topC) echo '<li class="mut">No registered-customer sales.</li>'; ?></ul></section>
</div>
<?php admin_foot();
