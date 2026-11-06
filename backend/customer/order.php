<?php
require __DIR__ . '/_auth.php'; $c = customer_required(); $pdo = db(); $cid = (int) $c['id']; $id = (int) ($_GET['id'] ?? 0);
$o = load_order('id = ? AND customer_id = ?', [$id, $cid], true);   // customers only ever see their own orders
if (!$o) { http_response_code(404); customer_head('Order not found'); echo '<div class="empty pcard">Order not found. <a href="orders.php">Back to my orders</a></div>'; customer_foot(); exit; }
$cancellable = in_array($o['status'], cfg('customer_cancel_statuses') ?: ['pending', 'confirmed'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel'])) {
    csrf_check();
    if (!$cancellable) flash_set('err', 'This order can no longer be cancelled online. Please contact support.');
    else {
        $reason = clean($_POST['reason'] ?? '', 300) ?: 'Cancelled by customer';
        order_apply_update($id, ['status' => 'cancelled', 'cancel_reason' => $reason], 'Cancelled by customer: ' . $reason, 'customer', false);
        log_activity('order_cancel', $o['order_number'], $reason, 'customer', (string) $cid);
        $paidNote = $o['payment_status'] === 'paid' ? ' This order was already paid – our team will contact you about the refund.' : '';
        send_mail(cfg('store_email'), 'Customer cancelled ' . $o['order_number'], '<p>' . h($c['full_name']) . ' cancelled order <b>' . h($o['order_number']) . '</b> (' . h(money($o['total'])) . ', ' . h($o['payment_status']) . ').<br>Reason: ' . h($reason) . '</p>');
        if ($o['email']) send_mail($o['email'], "Order {$o['order_number']} cancelled", '<p>Your order <b>' . h($o['order_number']) . '</b> has been cancelled as requested.' . h($paidNote) . '</p>');
        flash_set('ok', 'Your order was cancelled.' . $paidNote);
    }
    redirect('order.php?id=' . $id);
}

/* items → current catalogue entries (needed to re-add to the cart at today's price) */
$it = $pdo->prepare('SELECT oi.brand, oi.name, oi.image, oi.qty, p.price cur_price, p.active FROM order_items oi LEFT JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ? ORDER BY oi.id');
$it->execute([$id]); $items = $it->fetchAll();
$reorder = []; $unavail = 0;
foreach ($items as $x) { if ($x['cur_price'] !== null && (int) $x['active']) $reorder[] = ['brand' => $x['brand'], 'name' => $x['name'], 'price' => (int) $x['cur_price'], 'img' => $x['image'], 'qty' => (int) $x['qty']]; else $unavail++; }
$tk = $pdo->prepare('SELECT id, ticket_no, subject, status FROM tickets WHERE order_id = ? AND customer_id = ? ORDER BY id DESC'); $tk->execute([$id, $cid]); $tickets = $tk->fetchAll();
$token = $o['access_token'];
customer_head('Order ' . $o['order_number'], 'orders'); ?>
<div class="prow no-print"><a class="pbtn ghost" href="orders.php">← My orders</a>
 <a class="pbtn ghost" target="_blank" href="../../order-success.html?o=<?= urlencode($o['order_number']) ?>&t=<?= h($token) ?>&print=1"><i class="bi bi-receipt"></i>Invoice</a>
 <?php if ($reorder): ?><button type="button" data-reorder="<?= h(json_encode($reorder)) ?>" data-go="../../checkout.html"><i class="bi bi-arrow-repeat"></i>Order again</button><?php endif; ?>
 <?php if ($o['status'] === 'delivered'): ?><a class="pbtn ghost" href="reviews.php"><i class="bi bi-star"></i>Rate your purchase</a><?php endif; ?>
 <a class="pbtn ghost" href="tickets.php?new=1&order=<?= $id ?>"><i class="bi bi-life-preserver"></i>Need help?</a></div>
<div class="prow"><h2 style="margin:0"><?= h($o['order_number']) ?></h2><span class="pbadge s-<?= h($o['status']) ?>"><?= h($o['status']) ?></span><span class="pbadge s-<?= h($o['payment_status']) ?>"><?= h($o['payment_status']) ?></span>
 <button type="button" class="ibtn" data-copy="<?= h($o['order_number']) ?>" title="Copy order number"><i class="bi bi-clipboard"></i></button><small class="mut">Placed <?= h(dt($o['created_at'])) ?></small></div>
<?php if ($unavail && $reorder): ?><div class="pill-note"><i class="bi bi-info-circle"></i><div><?= $unavail ?> item(s) from this order are no longer available and will not be added when you order again.</div></div><?php endif; ?>

<?php if ($o['status'] !== 'cancelled'): ?><div class="pcard" style="margin-bottom:18px"><h4>Order progress</h4><?= order_stepper($o['status']) ?>
 <?php if ($o['tracking_no'] || $o['eta']): ?><div class="track-box"><i class="bi bi-truck"></i><div><b><?= h(trim($o['courier'] . ' ' . $o['tracking_no'])) ?: 'Delivery scheduled' ?></b><small><?= $o['eta'] ? 'Expected ' . h(date('l, d M Y', strtotime($o['eta']))) : 'We will confirm the delivery date soon' ?></small></div>
  <?php if ($o['tracking_no']): ?><button type="button" class="ibtn" style="margin-left:auto" data-copy="<?= h($o['tracking_no']) ?>" title="Copy tracking number"><i class="bi bi-clipboard"></i></button><?php endif; ?></div><?php endif; ?></div>
<?php else: ?><div class="flash err"><i class="bi bi-x-octagon"></i><span>This order was cancelled<?= $o['cancel_reason'] ? ': ' . h($o['cancel_reason']) : '' ?>.</span></div><?php endif; ?>

<div class="grid2"><div>
 <div class="tablewrap"><table><tr><th>Item</th><th class="r">Price</th><th class="r">Qty</th><th class="r">Total</th></tr>
 <?php foreach ($o['items'] as $x): ?><tr><td><div class="itemrow"><?= $x['image'] ? '<img src="' . h($x['image']) . '" alt="" loading="lazy">' : '<span class="noimg"><i class="bi bi-lamp"></i></span>' ?><div><b><?= h($x['name']) ?></b><br><small class="mut"><?= h($x['brand']) ?></small></div></div></td>
  <td class="r nw"><?= money($x['unit_price']) ?></td><td class="r"><?= (int) $x['qty'] ?></td><td class="r nw"><?= money($x['line_total']) ?></td></tr><?php endforeach; ?>
 <tr><td colspan="3" class="r">Subtotal</td><td class="r"><?= money($o['subtotal']) ?></td></tr>
 <?php if ($o['discount']): ?><tr><td colspan="3" class="r">Discount<?= $o['coupon_code'] ? ' <span class="code">' . h($o['coupon_code']) . '</span>' : '' ?></td><td class="r">−<?= money($o['discount']) ?></td></tr><?php endif; ?>
 <tr><td colspan="3" class="r">Delivery</td><td class="r"><?= $o['delivery_fee'] ? money($o['delivery_fee']) : 'Free' ?></td></tr>
 <tr><td colspan="3" class="r"><b>Total</b></td><td class="r"><b><?= money($o['total']) ?></b></td></tr></table></div>
 <div class="pcard" style="margin-top:18px"><h4><span><i class="bi bi-clock-history"></i>Status history</span></h4><ul class="tl rich">
 <?php foreach (array_reverse($o['history']) as $hrow): ?><li><span class="pbadge s-<?= h($hrow['status']) ?>"><?= h($hrow['status']) ?></span> <small class="mut"><?= h(dt($hrow['created_at'])) ?></small><?= $hrow['note'] ? '<br>' . h($hrow['note']) : '' ?></li><?php endforeach; ?></ul></div>
</div><div>
 <div class="pcard kv"><h4>Delivery &amp; payment</h4>
  <div><span>Name</span><span><?= h($o['customer_name']) ?></span></div><div><span>Phone</span><span><?= h($o['phone']) ?></span></div>
  <div><span>Address</span><span style="text-align:right"><?= h($o['address']) ?><?= $o['area'] ? ', ' . h($o['area']) : '' ?>, <?= h($o['city']) ?> <?= h($o['postal_code']) ?></span></div>
  <div><span>Payment</span><span><?= h($o['payment_label']) ?></span></div><?php if ($o['notes']): ?><div><span>Your note</span><span style="text-align:right"><?= h($o['notes']) ?></span></div><?php endif; ?></div>
 <?php if ($o['payment_note'] && $o['payment_status'] === 'unpaid' && $o['status'] !== 'cancelled'): ?><div class="pill-note" style="margin-top:18px"><i class="bi bi-credit-card"></i><div><?= h($o['payment_note']) ?></div></div><?php endif; ?>
 <?php if ($tickets): ?><div class="pcard" style="margin-top:18px"><h4>Your requests about this order</h4><ul class="people"><?php foreach ($tickets as $t): ?><li><div><a href="ticket.php?id=<?= $t['id'] ?>"><?= h($t['subject']) ?></a><small><?= h($t['ticket_no']) ?></small></div><span class="pbadge s-<?= h($t['status']) ?>"><?= h($t['status']) ?></span></li><?php endforeach; ?></ul></div><?php endif; ?>
 <?php if ($cancellable): ?><form method="post" class="pcard danger-zone" style="margin-top:18px"><input type="hidden" name="csrf" value="<?= csrf() ?>"><h4>Cancel this order</h4>
  <p class="mut" style="font-size:.86rem;margin:0 0 12px">You can cancel until the order is processed. <?= $o['payment_status'] === 'paid' ? 'Because it is already paid, our team will contact you about your refund.' : '' ?></p>
  <div class="fld"><label>Reason (optional)</label><input name="reason" maxlength="300" placeholder="e.g. Ordered by mistake"></div>
  <button class="danger" name="cancel" value="1" data-confirm="Cancel order <?= h($o['order_number']) ?>? This cannot be undone." data-confirm-title="Cancel order" data-confirm-yes="Yes, cancel it" style="width:100%">Cancel order</button></form><?php endif; ?>
</div></div>
<?php customer_foot();
