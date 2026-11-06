<?php
require __DIR__ . '/_auth.php'; admin_required();
$pdo = db(); $id = (int) ($_GET['id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (isset($_POST['toggle'])) {
        $pdo->prepare('UPDATE customers SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
        log_activity('customer_toggle', '#' . $id); flash_set('ok', 'Account status updated.');
    } elseif (isset($_POST['save_note'])) {
        $pdo->prepare('UPDATE customers SET admin_note = ? WHERE id = ?')->execute([clean($_POST['admin_note'] ?? '', 1000), $id]);
        log_activity('customer_note', '#' . $id); flash_set('ok', 'Private note saved.');
    } elseif (isset($_POST['delete_account'])) {
        $pdo->prepare('DELETE FROM customers WHERE id = ?')->execute([$id]);   // orders keep their data (customer_id becomes NULL)
        log_activity('customer_delete', '#' . $id); flash_set('ok', 'Customer account deleted. Their orders were kept as guest orders.'); redirect('customers.php');
    }
    redirect('customer.php?id=' . $id);
}
$st = $pdo->prepare('SELECT * FROM customers WHERE id = ?'); $st->execute([$id]); $c = $st->fetch();
if (!$c) { http_response_code(404); exit('Customer not found'); }
$os = $pdo->prepare('SELECT id, order_number, total, status, payment_status, created_at FROM orders WHERE customer_id = ? ORDER BY id DESC');
$os->execute([$id]); $orders = $os->fetchAll();
$spent = 0; $valid = 0; foreach ($orders as $o) if ($o['status'] !== 'cancelled') { $spent += $o['total']; $valid++; }
$t = loyalty($spent);
$ad = $pdo->prepare('SELECT * FROM addresses WHERE customer_id = ? ORDER BY is_default DESC, id'); $ad->execute([$id]); $addrs = $ad->fetchAll();
$tk = $pdo->prepare('SELECT id, ticket_no, subject, status, updated_at FROM tickets WHERE customer_id = ? ORDER BY id DESC LIMIT 10'); $tk->execute([$id]); $tickets = $tk->fetchAll();
$wl = $pdo->prepare('SELECT COUNT(*) FROM wishlist WHERE customer_id = ?'); $wl->execute([$id]); $wishN = (int) $wl->fetchColumn();
$fav = $pdo->prepare("SELECT oi.name, SUM(oi.qty) q FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE o.customer_id = ? AND o.status<>'cancelled' GROUP BY oi.name ORDER BY q DESC LIMIT 3");
$fav->execute([$id]); $favs = $fav->fetchAll();

admin_head($c['full_name'], 'customers'); ?>
<div class="prow"><a class="pbtn ghost" href="customers.php">← All customers</a><h2 style="margin:0 0 0 8px"><?= h($c['full_name']) ?></h2>
 <span class="pbadge <?= $c['is_active'] ? 's-active' : 's-disabled' ?>"><?= $c['is_active'] ? 'active' : 'disabled' ?></span><span class="chip"><i class="bi bi-award"></i><?= h($t['name']) ?></span></div>
<div class="grid2"><div>
 <div class="stat-row"><div class="mini-kpi"><small>Orders</small><b><?= count($orders) ?></b></div><div class="mini-kpi"><small>Total spent</small><b><?= money($spent) ?></b></div>
  <div class="mini-kpi"><small>Avg. order</small><b><?= money($valid ? round($spent / $valid) : 0) ?></b></div><div class="mini-kpi"><small>Wishlist</small><b><?= $wishN ?></b></div></div>
 <div class="tablewrap"><table><tr><th>Order</th><th class="r">Total</th><th>Payment</th><th>Status</th><th>Placed</th></tr>
 <?php foreach ($orders as $o): ?><tr><td><a href="order.php?id=<?= $o['id'] ?>"><b><?= h($o['order_number']) ?></b></a></td><td class="r"><?= money($o['total']) ?></td>
  <td><span class="pbadge s-<?= h($o['payment_status']) ?>"><?= h($o['payment_status']) ?></span></td><td><span class="pbadge s-<?= h($o['status']) ?>"><?= h($o['status']) ?></span></td>
  <td><small><?= h(dt($o['created_at'])) ?></small></td></tr>
 <?php endforeach; if (!$orders) echo '<tr><td colspan="5" class="empty">No orders placed while signed in.</td></tr>'; ?></table></div>
 <?php if ($tickets): ?><div class="pcard" style="margin-top:22px"><h4><span><i class="bi bi-life-preserver"></i>Support tickets</span></h4><ul class="people">
  <?php foreach ($tickets as $tk): ?><li><div><a href="ticket.php?id=<?= $tk['id'] ?>"><?= h($tk['subject']) ?></a><small><?= h($tk['ticket_no']) ?> · <?= h(ago($tk['updated_at'])) ?></small></div><span class="pbadge s-<?= h($tk['status']) ?>"><?= h($tk['status']) ?></span></li><?php endforeach; ?></ul></div><?php endif; ?>
</div><div>
 <div class="pcard kv"><h4>Account details</h4>
  <div><span>Email</span><span><a href="mailto:<?= h($c['email']) ?>"><?= h($c['email']) ?></a></span></div>
  <div><span>Phone</span><span><?= h($c['phone'] ?: '—') ?></span></div>
  <div><span>Address</span><span style="text-align:right"><?= h($c['address'] ?: '—') ?></span></div>
  <div><span>Area / City</span><span><?= h(trim($c['area'] . ' ' . $c['city']) ?: '—') ?></span></div>
  <div><span>Saved addresses</span><span><?= count($addrs) ?></span></div>
  <div><span>Most bought</span><span style="text-align:right"><?= $favs ? h(implode(', ', array_map(fn($f) => $f['name'], $favs))) : '—' ?></span></div>
  <div><span>Joined</span><span><?= h(dt($c['created_at'])) ?></span></div>
  <div><span>Last sign-in</span><span><?= h(dt($c['last_login'])) ?></span></div></div>
 <form method="post" class="pcard" style="margin-top:18px"><input type="hidden" name="csrf" value="<?= csrf() ?>">
  <h4><span><i class="bi bi-sticky"></i>Private note</span></h4>
  <div class="fld"><textarea name="admin_note" rows="3" maxlength="1000" placeholder="Only staff can see this (preferences, delivery quirks, VIP…)"><?= h($c['admin_note']) ?></textarea></div>
  <button name="save_note" value="1" class="sm">Save note</button></form>
 <form method="post" class="pcard" style="margin-top:18px"><input type="hidden" name="csrf" value="<?= csrf() ?>">
  <h4>Account access</h4><p class="mut" style="margin:0 0 12px;font-size:.86rem">A disabled customer cannot sign in. Their past orders are kept.</p>
  <button name="toggle" value="1" class="<?= $c['is_active'] ? 'danger' : '' ?>" style="width:100%" data-confirm="<?= $c['is_active'] ? 'Disable' : 'Re-enable' ?> this account?" data-confirm-yes="<?= $c['is_active'] ? 'Disable' : 'Re-enable' ?>" data-confirm-danger="<?= $c['is_active'] ? '1' : '0' ?>"><?= $c['is_active'] ? 'Disable account' : 'Re-enable account' ?></button>
  <button name="delete_account" value="1" class="danger" style="width:100%;margin-top:10px" data-confirm="Permanently delete this customer account? Orders are kept as guest orders; saved addresses, wishlist and tickets are removed. This cannot be undone." data-confirm-title="Delete account" data-confirm-yes="Delete forever"><i class="bi bi-trash3"></i>Delete account</button></form>
</div></div>
<?php admin_foot();
