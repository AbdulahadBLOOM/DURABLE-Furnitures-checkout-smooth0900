<?php
require __DIR__ . '/_auth.php'; $c = customer_required(); $pdo = db(); $cid = (int) $c['id'];
$status = in_array($_GET['status'] ?? '', ORDER_STATUSES, true) ? $_GET['status'] : '';
$q = clean($_GET['q'] ?? '', 60); $from = valid_date($_GET['from'] ?? ''); $to = valid_date($_GET['to'] ?? '');
$sort = in_array($_GET['sort'] ?? '', ['old', 'high', 'low'], true) ? $_GET['sort'] : 'new';
$page = max(1, (int) ($_GET['p'] ?? 1)); $per = 10;
$w = ['o.customer_id = ?']; $a = [$cid];
if ($status) { $w[] = 'o.status = ?'; $a[] = $status; }
if ($q !== '') { $w[] = '(o.order_number LIKE ? OR EXISTS (SELECT 1 FROM order_items i WHERE i.order_id = o.id AND i.name LIKE ?))'; array_push($a, "%$q%", "%$q%"); }
if ($from) { $w[] = 'o.created_at >= ?'; $a[] = "$from 00:00:00"; } if ($to) { $w[] = 'o.created_at < ?'; $a[] = date('Y-m-d', strtotime("$to +1 day")); }
$ws = implode(' AND ', $w);
$ord = ['new' => 'o.id DESC', 'old' => 'o.id ASC', 'high' => 'o.total DESC', 'low' => 'o.total ASC'][$sort];
$cnt = $pdo->prepare("SELECT COUNT(*) FROM orders o WHERE $ws"); $cnt->execute($a); $total = (int) $cnt->fetchColumn();
$st = $pdo->prepare("SELECT o.id, o.order_number, o.total, o.status, o.payment_status, o.created_at, o.tracking_no,
    (SELECT SUM(qty) FROM order_items WHERE order_id = o.id) qty, (SELECT GROUP_CONCAT(name SEPARATOR ', ') FROM order_items WHERE order_id = o.id) names
    FROM orders o WHERE $ws ORDER BY $ord LIMIT $per OFFSET " . (($page - 1) * $per)); $st->execute($a); $rows = $st->fetchAll();
$cn = $pdo->prepare('SELECT status, COUNT(*) n FROM orders WHERE customer_id = ? GROUP BY status'); $cn->execute([$cid]); $cn = array_column($cn->fetchAll(), 'n', 'status'); $all = array_sum($cn);
$keep = array_filter(['status' => $status, 'q' => $q, 'from' => $from, 'to' => $to, 'sort' => $sort !== 'new' ? $sort : '']);
customer_head('My orders', 'orders', ['sub' => 'Track, search and reorder everything you have bought.']); ?>
<div class="stabs"><a href="orders.php" class="<?= !$status ? 'on' : '' ?>">All <b><?= $all ?></b></a><?php foreach (ORDER_STATUSES as $s) if (!empty($cn[$s])): ?><a href="<?= h(qs(['status' => $s, 'p' => ''], $keep)) ?>" class="<?= $status === $s ? 'on' : '' ?>"><?= ucfirst($s) ?> <b><?= $cn[$s] ?></b></a><?php endif; ?></div>
<form class="fbar" method="get"><input type="hidden" name="status" value="<?= h($status) ?>">
 <div class="f wide"><label>Search</label><input name="q" value="<?= h($q) ?>" placeholder="Order number or product name"></div>
 <div class="f"><label>From</label><input type="date" name="from" value="<?= h($from) ?>"></div><div class="f"><label>To</label><input type="date" name="to" value="<?= h($to) ?>"></div>
 <div class="f"><label>Sort</label><select name="sort" data-autosubmit><option value="new">Newest first</option><option value="old" <?= $sort === 'old' ? 'selected' : '' ?>>Oldest first</option><option value="high" <?= $sort === 'high' ? 'selected' : '' ?>>Highest total</option><option value="low" <?= $sort === 'low' ? 'selected' : '' ?>>Lowest total</option></select></div>
 <div class="fx"><button><i class="bi bi-search"></i>Search</button><a class="pbtn ghost" href="orders.php">Reset</a></div></form>
<?php if ($rows): ?><div class="olist pcard flush"><?php foreach ($rows as $o): ?>
 <a class="oitem" href="order.php?id=<?= $o['id'] ?>"><span class="oic"><i class="bi bi-bag-check"></i></span>
  <div class="om"><b><?= h($o['order_number']) ?></b><small><?= h(dt($o['created_at'])) ?> · <?= (int) $o['qty'] ?> item(s) · <?= h(mb_strimwidth((string) $o['names'], 0, 60, '…')) ?></small></div>
  <span class="pbadge s-<?= h($o['payment_status']) ?> d-none d-md-inline-flex"><?= h($o['payment_status']) ?></span><span class="pbadge s-<?= h($o['status']) ?>"><?= h($o['status']) ?></span>
  <b class="otot"><?= money($o['total']) ?></b><i class="bi bi-chevron-right chev"></i></a><?php endforeach; ?></div>
<?= paginate($total, $page, $per, $keep) ?>
<?php else: ?><div class="pcard"><div class="empty"><i class="bi bi-bag"></i><?= $keep ? 'No orders match your search.<br><a href="orders.php">Clear filters</a>' : 'You have not placed any orders yet.<br><a href="shop.php">Start shopping →</a>' ?></div></div><?php endif; ?>
<?php customer_foot();
