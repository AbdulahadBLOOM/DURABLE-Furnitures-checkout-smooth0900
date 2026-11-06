<?php
require __DIR__ . '/_auth.php'; $c = customer_required(); $pdo = db(); $cid = (int) $c['id'];
$s = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(status IN ('pending','confirmed','processing','shipped')),0) open_n,
    COALESCE(SUM(CASE WHEN status<>'cancelled' THEN total END),0) spent, COALESCE(SUM(CASE WHEN status<>'cancelled' THEN discount END),0) saved FROM orders WHERE customer_id = ?");
$s->execute([$cid]); $st = $s->fetch();
$r = $pdo->prepare('SELECT id, order_number, total, status, payment_status, created_at, (SELECT SUM(qty) FROM order_items WHERE order_id = orders.id) qty FROM orders WHERE customer_id = ? ORDER BY id DESC LIMIT 5');
$r->execute([$cid]); $orders = $r->fetchAll();
$ac = $pdo->prepare("SELECT * FROM orders WHERE customer_id = ? AND status NOT IN ('delivered','cancelled') ORDER BY id DESC LIMIT 1"); $ac->execute([$cid]); $active = $ac->fetch();
$tier = loyalty((int) $st['spent']);

/* spend per month, last 6 months */
$m = []; for ($i = 5; $i >= 0; $i--) $m[date('Y-m', strtotime("first day of -$i month"))] = 0;
$sp = $pdo->prepare("SELECT DATE_FORMAT(created_at,'%Y-%m') ym, SUM(total) t FROM orders WHERE customer_id = ? AND status<>'cancelled' AND created_at >= ? GROUP BY ym");
$sp->execute([$cid, date('Y-m-01', strtotime('-5 month'))]); foreach ($sp as $x) if (isset($m[$x['ym']])) $m[$x['ym']] = (int) $x['t'];
$mx = max(1, ...array_values($m));

$buy = $pdo->prepare("SELECT p.id, p.brand, p.name, p.price, p.image, SUM(oi.qty) q FROM order_items oi JOIN orders o ON o.id = oi.order_id JOIN products p ON p.id = oi.product_id
    WHERE o.customer_id = ? AND o.status<>'cancelled' AND p.active = 1 GROUP BY p.id ORDER BY MAX(o.id) DESC LIMIT 4"); $buy->execute([$cid]); $again = $buy->fetchAll();
$wl = $pdo->prepare('SELECT p.id, p.brand, p.name, p.price, p.image FROM wishlist w JOIN products p ON p.id = w.product_id WHERE w.customer_id = ? AND p.active = 1 ORDER BY w.created_at DESC LIMIT 4'); $wl->execute([$cid]); $wish = $wl->fetchAll();
$tk = $pdo->prepare("SELECT id, ticket_no, subject, status, updated_at FROM tickets WHERE customer_id = ? AND status <> 'closed' ORDER BY updated_at DESC LIMIT 3"); $tk->execute([$cid]); $tickets = $tk->fetchAll();
$notes = customer_notifications($cid, 4);
$addrN = (int) $pdo->query('SELECT COUNT(*) FROM addresses WHERE customer_id = ' . $cid)->fetchColumn();

$first = explode(' ', trim($c['full_name']))[0];
$checks = [['Name & email', true], ['Phone number', $c['phone'] !== ''], ['Delivery address', $c['address'] !== '' || $addrN > 0], ['City', $c['city'] !== '' || $addrN > 0], ['Postal code', $c['postal_code'] !== '' || $addrN > 0]];
$doneN = count(array_filter($checks, fn($x) => $x[1])); $pct = (int) round($doneN / count($checks) * 100);
$R = 44; $C = 2 * M_PI * $R;
$hr = (int) date('G'); $greet = $hr < 12 ? 'Good morning' : ($hr < 17 ? 'Good afternoon' : 'Good evening');
$cardJson = fn($p) => h(json_encode(['brand' => $p['brand'], 'name' => $p['name'], 'price' => (int) $p['price'], 'img' => $p['image'], 'qty' => 1]));

customer_head('Overview', 'home', [
    'title_html' => $greet . ', <em>' . h($first) . '</em>',
    'sub' => 'Member since ' . date('F Y', strtotime($c['created_at'])) . ' &middot; here is what is happening with your orders.', 'tall' => true,
    'actions' => '<a class="pbtn" href="shop.php"><i class="bi bi-shop"></i>Browse furniture</a><a class="pbtn ghost" href="tickets.php?new=1"><i class="bi bi-life-preserver"></i>Get help</a>',
]); ?>
<div class="kpis overlap">
  <div class="kpi hl"><span class="ico"><i class="bi bi-bag-heart"></i></span><div><small>Orders placed</small><b data-count="<?= (int) $st['n'] ?>"><?= (int) $st['n'] ?></b><em>since you joined</em></div></div>
  <div class="kpi"><span class="ico"><i class="bi bi-truck"></i></span><div><small>In progress</small><b data-count="<?= (int) $st['open_n'] ?>"><?= (int) $st['open_n'] ?></b><em>on their way to you</em></div></div>
  <div class="kpi"><span class="ico"><i class="bi bi-wallet2"></i></span><div><small>Total spent</small><b data-count="<?= (int) $st['spent'] ?>" data-prefix="Rs."><?= money($st['spent']) ?></b><em>excluding cancelled</em></div></div>
  <a class="kpi" href="wishlist.php"><span class="ico"><i class="bi bi-heart"></i></span><div><small>Wishlist</small><b><?= count($wish) ? customer_counts($cid)['wish'] : 0 ?></b><em>saved for later</em></div></a>
  <?php if ($st['saved'] > 0): ?><div class="kpi"><span class="ico"><i class="bi bi-piggy-bank"></i></span><div><small>You saved</small><b><?= money($st['saved']) ?></b><em>with coupons</em></div></div><?php endif; ?>
</div>

<div class="dgrid">
  <section class="pcard span-8">
    <?php if ($active): ?>
      <h4><span><i class="bi bi-box-seam"></i>Current order &middot; <a href="order.php?id=<?= $active['id'] ?>"><?= h($active['order_number']) ?></a></span><span class="pbadge s-<?= h($active['status']) ?>"><?= h($active['status']) ?></span></h4>
      <?= order_stepper($active['status']) ?>
      <?php if ($active['tracking_no'] || $active['eta']): ?><div class="track-box"><i class="bi bi-truck"></i><div><b><?= h(trim($active['courier'] . ' ' . $active['tracking_no'])) ?: 'Delivery on the way' ?></b><small><?= $active['eta'] ? 'Expected ' . h(date('l, d M', strtotime($active['eta']))) : 'We will confirm the delivery date soon' ?></small></div></div><?php endif; ?>
      <div class="cur-meta"><span><i class="bi bi-calendar3"></i>Placed <?= h(dt($active['created_at'])) ?></span><span><i class="bi bi-receipt"></i><?= money($active['total']) ?></span><a class="pbtn ghost sm" href="order.php?id=<?= $active['id'] ?>">View details <i class="bi bi-arrow-right"></i></a></div>
    <?php else: ?>
      <h4><span><i class="bi bi-box-seam"></i>Current order</span></h4>
      <div class="empty slim"><i class="bi bi-cart2"></i>No orders in progress right now.<br><a href="shop.php">Find something you love &rarr;</a></div>
    <?php endif; ?>
  </section>

  <section class="span-4"><div class="tier"><small>Your membership</small><h3><?= h($tier['name']) ?></h3>
    <div class="bar"><i style="width:<?= $tier['pct'] ?>%"></i></div>
    <p><?= $tier['next'] ? 'Spend ' . money($tier['to_next']) . ' more to reach <b>' . h($tier['next']) . '</b>.' : 'You have reached our top tier – thank you!' ?></p></div></section>

  <section class="pcard span-8">
    <h4><span><i class="bi bi-bar-chart"></i>Your spending &middot; last 6 months</span></h4>
    <div class="minibars"><?php foreach ($m as $ym => $v): ?><div class="mb" title="<?= h(date('F Y', strtotime($ym . '-01')) . ': ' . money($v)) ?>"><i class="<?= $v ? '' : 'z' ?>" style="height:<?= max(3, round($v / $mx * 100)) ?>%"></i><span><?= h(date('M', strtotime($ym . '-01'))) ?></span></div><?php endforeach; ?></div>
  </section>

  <section class="pcard span-4">
    <h4><span><i class="bi bi-person-vcard"></i>Profile</span><a href="profile.php">Edit <i class="bi bi-arrow-right"></i></a></h4>
    <div class="ringrow"><div class="ring"><svg viewBox="0 0 110 110"><circle cx="55" cy="55" r="<?= $R ?>" class="ring-bg"/><circle cx="55" cy="55" r="<?= $R ?>" class="ring-fg" stroke-dasharray="<?= round($C * $pct / 100, 2) ?> <?= round($C, 2) ?>"/></svg><b><?= $pct ?>%</b></div>
      <div><b>Profile <?= $pct === 100 ? 'complete' : 'completeness' ?></b><small><?= $pct === 100 ? 'Checkout is fully pre-filled.' : 'Finish it to speed up checkout.' ?></small></div></div>
    <ul class="checks"><?php foreach ($checks as [$l, $ok]): ?><li class="<?= $ok ? 'ok' : '' ?>"><i class="bi <?= $ok ? 'bi-check-circle-fill' : 'bi-circle' ?>"></i><?= h($l) ?></li><?php endforeach; ?></ul>
  </section>

  <section class="span-12">
    <div class="sec-head"><h3>Recent orders</h3><a href="orders.php">View all <i class="bi bi-arrow-right"></i></a></div>
    <?php if ($orders): ?><div class="olist pcard flush"><?php foreach ($orders as $o): ?>
      <a class="oitem" href="order.php?id=<?= $o['id'] ?>"><span class="oic"><i class="bi bi-bag-check"></i></span>
        <div class="om"><b><?= h($o['order_number']) ?></b><small><?= h(dt($o['created_at'])) ?> &middot; <?= (int) $o['qty'] ?> item(s)</small></div>
        <span class="pbadge s-<?= h($o['payment_status']) ?> d-none d-md-inline-flex"><?= h($o['payment_status']) ?></span><span class="pbadge s-<?= h($o['status']) ?>"><?= h($o['status']) ?></span>
        <b class="otot"><?= money($o['total']) ?></b><i class="bi bi-chevron-right chev"></i></a><?php endforeach; ?></div>
    <?php else: ?><div class="pcard"><div class="empty slim"><i class="bi bi-bag"></i>You have no orders yet.<br><a href="shop.php">Start shopping &rarr;</a></div></div><?php endif; ?>
  </section>

  <?php if ($again): ?><section class="span-12"><div class="sec-head"><h3>Buy again</h3><a href="shop.php">Catalogue <i class="bi bi-arrow-right"></i></a></div>
    <div class="prod-grid"><?php foreach ($again as $p): ?><div class="pcard-prod"><div class="pimg"><?= $p['image'] ? '<img src="' . h($p['image']) . '" alt="" loading="lazy">' : '<i class="bi bi-lamp"></i>' ?></div>
      <div class="pbody"><span class="pbrand"><?= h($p['brand']) ?></span><h6><?= h($p['name']) ?></h6><span class="pprice"><?= money($p['price']) ?></span>
      <div class="pact"><button data-addcart="<?= $cardJson($p) ?>"><i class="bi bi-cart-plus"></i>Add to cart</button></div></div></div><?php endforeach; ?></div></section><?php endif; ?>

  <section class="pcard span-4"><h4><span><i class="bi bi-bell"></i>Latest updates</span><a href="notifications.php">All</a></h4>
    <ul class="notelist"><?php foreach ($notes as $n): ?><li class="<?= $n['new'] ? 'new' : '' ?>"><a href="<?= h($n['url']) ?>"><span class="nic"><i class="bi <?= h($n['icon']) ?>"></i></span><div><b><?= h($n['title']) ?></b><small><?= h(mb_strimwidth($n['text'], 0, 60, '…')) ?></small></div></a></li><?php endforeach;
    if (!$notes) echo '<li class="mut" style="padding:8px 0">Nothing new yet.</li>'; ?></ul></section>
  <section class="pcard span-4"><h4><span><i class="bi bi-life-preserver"></i>Support</span><a href="tickets.php">All</a></h4>
    <ul class="people"><?php foreach ($tickets as $t): ?><li><div><a href="ticket.php?id=<?= $t['id'] ?>"><?= h($t['subject']) ?></a><small><?= h($t['ticket_no']) ?> · <?= h(ago($t['updated_at'])) ?></small></div><span class="pbadge s-<?= h($t['status']) ?>"><?= h($t['status']) ?></span></li><?php endforeach;
    if (!$tickets) echo '<li class="mut">No open requests. <a href="tickets.php?new=1">Ask us anything →</a></li>'; ?></ul></section>
  <section class="pcard span-4"><h4><span><i class="bi bi-heart"></i>Wishlist</span><a href="wishlist.php">All</a></h4>
    <ul class="people"><?php foreach ($wish as $p): ?><li><div><a href="shop.php?q=<?= urlencode($p['name']) ?>"><?= h($p['name']) ?></a><small><?= h($p['brand']) ?></small></div><b><?= money($p['price']) ?></b></li><?php endforeach;
    if (!$wish) echo '<li class="mut">Tap the heart on any product to save it. <a href="shop.php">Browse →</a></li>'; ?></ul></section>
</div>
<?php customer_foot();
