<?php
require __DIR__ . '/_auth.php'; admin_required();
$pdo = db();

/* ── bulk actions ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $ids = array_slice(array_unique(array_map('intval', (array) ($_POST['ids'] ?? []))), 0, 100);
    $act = (string) ($_POST['bulk'] ?? ''); $note = clean($_POST['note'] ?? '', 400); $n = 0;
    if ($ids && preg_match('/^(status|pay):([a-z]+)$/', $act, $m)) {
        foreach ($ids as $id) {
            $set = $m[1] === 'status' ? ['status' => $m[2]] : ['payment_status' => $m[2]];
            if (order_apply_update($id, $set, $note, $_SESSION['admin'])) $n++;
        }
        log_activity('bulk_' . $m[1], $n . ' order(s)', $m[2] . ($note ? ' – ' . $note : ''));
        flash_set('ok', $n . ' order(s) updated to “' . $m[2] . '”.');
    } else flash_set('err', 'Select at least one order and choose an action.');
    redirect('orders.php' . qs([], $_GET));
}

$F = order_filter($_GET); $f = $F['f'];
$per = (int) ($_GET['per'] ?? 25); if (!in_array($per, [25, 50, 100], true)) $per = 25;
$page = max(1, (int) ($_GET['p'] ?? 1));

$cnt = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(CASE WHEN o.status<>'cancelled' THEN o.total END),0) FROM orders o {$F['where']}");
$cnt->execute($F['args']); [$total, $sumShown] = $cnt->fetch(PDO::FETCH_NUM); $total = (int) $total;
$st = $pdo->prepare("SELECT o.*, (SELECT SUM(qty) FROM order_items WHERE order_id = o.id) AS qty FROM orders o {$F['where']} ORDER BY {$F['order']} LIMIT $per OFFSET " . (($page - 1) * $per));
$st->execute($F['args']); $orders = $st->fetchAll();

$counts = array_column($pdo->query('SELECT status, COUNT(*) n FROM orders GROUP BY status')->fetchAll(), 'n', 'status');
$stats = $pdo->query("SELECT COUNT(*) n, SUM(DATE(created_at)=CURDATE()) today, COALESCE(SUM(CASE WHEN status<>'cancelled' THEN total END),0) valid_rev,
    COALESCE(SUM(CASE WHEN payment_status='unpaid' AND status<>'cancelled' THEN total END),0) outstanding FROM orders")->fetch();
$methods = cfg('payment_methods');
$keep = array_filter(['status' => $f['status'], 'pay' => $f['pay'], 'method' => $f['method'], 'who' => $f['who'], 'q' => $f['q'], 'from' => $f['from'], 'to' => $f['to'], 'sort' => $f['sort'], 'per' => $per !== 25 ? $per : '']);
$cur = $f['sort'] ?: 'date_desc';
$filtered = (bool) array_filter([$f['pay'], $f['method'], $f['who'], $f['q'], $f['from'], $f['to']]);

admin_head('Orders', 'orders', ['sub' => 'Search, filter, update in bulk and open any order.',
    'actions' => '<a class="pbtn ghost" href="export.php' . h(qs(['type' => 'orders'], $keep)) . '"><i class="bi bi-download"></i>Export' . ($filtered || $f['status'] ? ' filtered' : '') . ' CSV</a>']); ?>
<div class="kpis">
 <div class="kpi"><span class="ico"><i class="bi bi-bag-check"></i></span><div><small>Total orders</small><b><?= (int) $stats['n'] ?></b><em><?= (int) $stats['today'] ?> today</em></div></div>
 <div class="kpi"><span class="ico"><i class="bi bi-hourglass-split"></i></span><div><small>Pending</small><b><?= (int) ($counts['pending'] ?? 0) ?></b><em>need confirmation</em></div></div>
 <div class="kpi hl"><span class="ico"><i class="bi bi-cash-coin"></i></span><div><small>Revenue</small><b><?= money($stats['valid_rev']) ?></b><em>excluding cancelled</em></div></div>
 <a class="kpi" href="?pay=unpaid"><span class="ico"><i class="bi bi-wallet2"></i></span><div><small>Outstanding</small><b><?= money($stats['outstanding']) ?></b><em>unpaid, not cancelled</em></div></a>
</div>

<div class="stabs"><a href="orders.php" class="<?= $f['status'] === '' ? 'on' : '' ?>">All <b><?= (int) $stats['n'] ?></b></a>
<?php foreach (ORDER_STATUSES as $s): ?><a href="<?= h(qs(['status' => $s, 'p' => ''], $keep)) ?>" class="<?= $f['status'] === $s ? 'on' : '' ?>"><?= ucfirst($s) ?> <b><?= (int) ($counts[$s] ?? 0) ?></b></a><?php endforeach; ?></div>

<form class="fbar" method="get">
 <div class="f wide"><label>Search</label><input name="q" value="<?= h($f['q']) ?>" placeholder="Order #, name, phone, email, city, tracking no."></div>
 <div class="f"><label>Payment</label><select name="pay"><option value="">Any</option><?php foreach (PAYMENT_STATUSES as $p): ?><option value="<?= $p ?>" <?= $p === $f['pay'] ? 'selected' : '' ?>><?= ucfirst($p) ?></option><?php endforeach; ?></select></div>
 <div class="f"><label>Method</label><select name="method"><option value="">Any</option><?php foreach ($methods as $k => $m): ?><option value="<?= h($k) ?>" <?= $k === $f['method'] ? 'selected' : '' ?>><?= h($m['label']) ?></option><?php endforeach; ?></select></div>
 <div class="f"><label>Customer</label><select name="who"><option value="">All</option><option value="member" <?= $f['who'] === 'member' ? 'selected' : '' ?>>Registered</option><option value="guest" <?= $f['who'] === 'guest' ? 'selected' : '' ?>>Guest</option></select></div>
 <div class="f"><label>From</label><input type="date" name="from" value="<?= h($f['from']) ?>"></div>
 <div class="f"><label>To</label><input type="date" name="to" value="<?= h($f['to']) ?>"></div>
 <input type="hidden" name="status" value="<?= h($f['status']) ?>"><input type="hidden" name="sort" value="<?= h($f['sort']) ?>">
 <div class="fx"><button><i class="bi bi-funnel"></i>Filter</button><a class="pbtn ghost" href="orders.php">Reset</a></div>
</form>

<form method="post" data-bulk>
<input type="hidden" name="csrf" value="<?= csrf() ?>">
<div class="bulkbar"><span class="bulk-count">0 selected</span>
 <select name="bulk" required><option value="">Choose action…</option>
  <optgroup label="Order status"><?php foreach (ORDER_STATUSES as $s): ?><option value="status:<?= $s ?>">Mark as <?= $s ?></option><?php endforeach; ?></optgroup>
  <optgroup label="Payment"><?php foreach (PAYMENT_STATUSES as $p): ?><option value="pay:<?= $p ?>">Mark as <?= $p ?></option><?php endforeach; ?></optgroup></select>
 <input name="note" maxlength="400" placeholder="Optional note (shown to customers)">
 <button class="sm" data-confirm="Apply this change to all selected orders? Customers with an email on file are notified of status changes." data-confirm-title="Update selected orders" data-confirm-yes="Apply" data-confirm-danger="0">Apply</button></div>

<div class="tablewrap"><table><tr><th class="chk"><input type="checkbox" data-bulk-all aria-label="Select all"></th>
<?= sort_th('Order', 'date', $cur) ?><?= sort_th('Customer', 'name', $cur) ?><th>City</th><th class="r">Items</th><?= sort_th('Total', 'total', $cur, 'r') ?><th>Payment</th><th>Status</th><th></th></tr>
<?php foreach ($orders as $o): ?>
<tr><td class="chk"><input type="checkbox" name="ids[]" value="<?= $o['id'] ?>" data-bulk-row aria-label="Select order"></td>
<td><a href="order.php?id=<?= $o['id'] ?>"><b><?= h($o['order_number']) ?></b></a><br><small class="mut"><?= h(date('d M Y, h:i A', strtotime($o['created_at']))) ?></small></td>
<td><div class="who-cell"><span class="avatar sm"><?= h(initials($o['customer_name'])) ?></span><div><?= h($o['customer_name']) ?> <?= $o['customer_id'] ? '' : '<small class="mut">(guest)</small>' ?><br><small class="mut"><?= h($o['phone']) ?></small></div></div></td>
<td><?= h($o['city']) ?></td><td class="r"><?= (int) $o['qty'] ?></td><td class="r nw"><b><?= money($o['total']) ?></b><?= $o['discount'] ? '<br><small class="mut">−' . money($o['discount']) . ($o['coupon_code'] ? ' · ' . h($o['coupon_code']) : '') . '</small>' : '' ?></td>
<td><small><?= h($methods[$o['payment_method']]['label'] ?? $o['payment_method']) ?></small><br><span class="pbadge s-<?= h($o['payment_status']) ?>"><?= h($o['payment_status']) ?></span></td>
<td><span class="pbadge s-<?= h($o['status']) ?>"><?= h($o['status']) ?></span><?= $o['tracking_no'] ? '<br><small class="mut"><i class="bi bi-truck"></i> ' . h($o['tracking_no']) . '</small>' : '' ?></td>
<td class="r nw"><a class="ibtn-link" href="order.php?id=<?= $o['id'] ?>" title="Open"><i class="bi bi-arrow-right-circle"></i></a></td></tr>
<?php endforeach; if (!$orders): ?><tr><td colspan="9" class="empty"><i class="bi bi-inbox"></i>No orders match these filters.<br><a href="orders.php">Clear filters</a></td></tr><?php endif; ?>
</table></div>
</form>
<p class="mut" style="margin:12px 4px 0;font-size:.82rem"><?= number_format($total) ?> order(s) shown · <?= money($sumShown) ?> revenue in this view
 · per page: <?php foreach ([25, 50, 100] as $n): ?><a href="<?= h(qs(['per' => $n, 'p' => ''], $keep)) ?>" style="<?= $n === $per ? 'font-weight:700' : '' ?>"><?= $n ?></a> <?php endforeach; ?></p>
<?= paginate($total, $page, $per, $keep) ?>
<?php admin_foot();
