<?php
require __DIR__ . '/_auth.php'; admin_required();
$pdo = db(); $id = (int) ($_GET['id'] ?? 0);
$me = $_SESSION['admin'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $cur = load_order('id = ?', [$id], false);
    if ($cur) {
        if (isset($_POST['quick'])) {                       // one-click: "status:shipped" or "pay:paid"
            if (preg_match('/^(status|pay):([a-z]+)$/', (string) $_POST['quick'], $m)) {
                order_apply_update($id, $m[1] === 'status' ? ['status' => $m[2]] : ['payment_status' => $m[2]], '', $me);
                log_activity('order_' . $m[1], $cur['order_number'], $m[2]);
                flash_set('ok', 'Order ' . $cur['order_number'] . ' marked ' . $m[2] . '.');
            }
        } elseif (isset($_POST['resend'])) {
            if ($cur['email'] !== '') { send_mail($cur['email'], "Your order {$cur['order_number']}", order_email_html($cur, 'Here is a copy of your order details.')); flash_set('ok', 'Order email sent to ' . $cur['email'] . '.'); }
            else flash_set('err', 'This order has no email address.');
        } else {
            $ns = $_POST['status'] ?? $cur['status'];
            $set = ['status' => $ns, 'payment_status' => $_POST['payment_status'] ?? $cur['payment_status'], 'admin_notes' => $_POST['admin_notes'] ?? $cur['admin_notes'],
                    'tracking_no' => $_POST['tracking_no'] ?? $cur['tracking_no'], 'courier' => $_POST['courier'] ?? $cur['courier'], 'eta' => $_POST['eta'] ?? $cur['eta'],
                    'email_note' => !empty($_POST['email_note'])];
            if ($ns === 'cancelled') $set['cancel_reason'] = $_POST['cancel_reason'] ?? $cur['cancel_reason'];
            order_apply_update($id, $set, (string) ($_POST['note'] ?? ''), $me);
            log_activity('order_update', $cur['order_number'], 'status=' . $ns . ', payment=' . $set['payment_status']);
            flash_set('ok', 'Order updated.');
        }
    }
    redirect('order.php?id=' . $id);
}

$o = load_order('id = ?', [$id], false);
if (!$o) { http_response_code(404); exit('Order not found'); }
$other = null;
if ($o['customer_id']) { $x = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(CASE WHEN status<>'cancelled' THEN total END),0) s FROM orders WHERE customer_id = ?"); $x->execute([$o['customer_id']]); $other = $x->fetch(); }
$tk = $pdo->prepare('SELECT id, ticket_no, subject, status FROM tickets WHERE order_id = ? ORDER BY id DESC'); $tk->execute([$id]); $tickets = $tk->fetchAll();
$next = ['pending' => ['confirmed', 'Confirm order', 'bi-check2-circle'], 'confirmed' => ['processing', 'Start processing', 'bi-tools'], 'processing' => ['shipped', 'Mark as shipped', 'bi-truck'], 'shipped' => ['delivered', 'Mark as delivered', 'bi-house-heart']][$o['status']] ?? null;
$wa = preg_replace('/\D/', '', $o['phone']); if (strpos($wa, '0') === 0) $wa = '92' . substr($wa, 1);
$sub = (int) $o['subtotal'];

admin_head('Order ' . $o['order_number'], 'orders'); ?>
<div class="prow no-print"><a class="pbtn ghost" href="orders.php">← All orders</a>
 <a class="pbtn ghost" target="_blank" href="../../order-success.html?o=<?= urlencode($o['order_number']) ?>&t=<?= h($o['access_token']) ?>&print=1"><i class="bi bi-receipt"></i>Invoice</a>
 <a class="pbtn ghost" target="_blank" href="print.php?id=<?= $id ?>&type=slip"><i class="bi bi-box-seam"></i>Packing slip</a>
 <a class="pbtn ghost" target="_blank" href="print.php?id=<?= $id ?>&type=label"><i class="bi bi-tag"></i>Delivery label</a>
 <a class="pbtn ghost" target="_blank" rel="noopener" href="https://wa.me/<?= h($wa) ?>?text=<?= urlencode('Hello ' . $o['customer_name'] . ', regarding your Durable Furnitures order ' . $o['order_number'] . ': ') ?>"><i class="bi bi-whatsapp"></i>WhatsApp</a></div>
<div class="prow"><h2 style="margin:0"><?= h($o['order_number']) ?></h2><span class="pbadge s-<?= h($o['status']) ?>"><?= h($o['status']) ?></span><span class="pbadge s-<?= h($o['payment_status']) ?>"><?= h($o['payment_status']) ?></span>
 <button type="button" class="ibtn" data-copy="<?= h($o['order_number']) ?>" title="Copy order number"><i class="bi bi-clipboard"></i></button>
 <small class="mut">Placed <?= h(dt($o['created_at'])) ?> · <?= h(ago($o['created_at'])) ?></small></div>

<div class="pcard" style="margin-bottom:20px">
 <?= $o['status'] === 'cancelled' ? '<div class="flash err" style="margin:0"><i class="bi bi-x-octagon"></i><span>This order was cancelled' . ($o['cancel_reason'] ? ': ' . h($o['cancel_reason']) : '') . '.</span></div>' : order_stepper($o['status']) ?>
 <form method="post" class="quick-actions no-print" style="margin:18px 0 0"><input type="hidden" name="csrf" value="<?= csrf() ?>">
  <?php if ($next): ?><button name="quick" value="status:<?= $next[0] ?>"><i class="bi <?= $next[2] ?>"></i><?= $next[1] ?></button><?php endif; ?>
  <?php if ($o['payment_status'] === 'unpaid' && $o['status'] !== 'cancelled'): ?><button class="ghostb" name="quick" value="pay:paid"><i class="bi bi-cash-coin"></i>Mark as paid</button><?php endif; ?>
  <?php if ($o['payment_status'] === 'paid' && $o['status'] === 'cancelled'): ?><button class="ghostb" name="quick" value="pay:refunded" data-confirm="Mark this payment as refunded?" data-confirm-yes="Mark refunded" data-confirm-danger="0"><i class="bi bi-arrow-counterclockwise"></i>Mark refunded</button><?php endif; ?>
  <?php if (!in_array($o['status'], ['cancelled', 'delivered'], true)): ?><button class="ghostb" name="quick" value="status:cancelled" data-confirm="Cancel this order? The customer will be notified by email if one is on file." data-confirm-title="Cancel order" data-confirm-yes="Cancel order"><i class="bi bi-x-circle"></i>Cancel order</button><?php endif; ?>
  <?php if ($o['email']): ?><button class="ghostb" name="resend" value="1"><i class="bi bi-envelope-arrow-up"></i>Re-send order email</button><?php endif; ?>
 </form>
 <?php if ($o['tracking_no'] || $o['eta']): ?><div class="track-box"><i class="bi bi-truck"></i><div><b><?= h(trim($o['courier'] . ' ' . $o['tracking_no'])) ?: 'Delivery scheduled' ?></b><small><?= $o['eta'] ? 'Expected ' . h(date('D, d M Y', strtotime($o['eta']))) : 'No delivery date set' ?></small></div></div><?php endif; ?>
</div>

<div class="grid2"><div>
 <div class="tablewrap"><table><tr><th>Item</th><th class="r">Price</th><th class="r">Qty</th><th class="r">Total</th></tr>
 <?php foreach ($o['items'] as $it): ?><tr><td><div class="itemrow"><?= $it['image'] ? '<img src="' . h($it['image']) . '" alt="" loading="lazy">' : '<span class="noimg"><i class="bi bi-lamp"></i></span>' ?><div><b><?= h($it['name']) ?></b><br><small class="mut"><?= h($it['brand']) ?></small></div></div></td>
 <td class="r nw"><?= money($it['unit_price']) ?></td><td class="r"><?= (int) $it['qty'] ?></td><td class="r nw"><?= money($it['line_total']) ?></td></tr><?php endforeach; ?>
 <tr><td colspan="3" class="r">Subtotal</td><td class="r"><?= money($sub) ?></td></tr>
 <?php if ($o['discount']): ?><tr><td colspan="3" class="r">Discount<?= $o['coupon_code'] ? ' <span class="code">' . h($o['coupon_code']) . '</span>' : '' ?></td><td class="r">−<?= money($o['discount']) ?></td></tr><?php endif; ?>
 <tr><td colspan="3" class="r">Delivery</td><td class="r"><?= $o['delivery_fee'] ? money($o['delivery_fee']) : 'Free' ?></td></tr>
 <tr><td colspan="3" class="r"><b>Total</b></td><td class="r"><b><?= money($o['total']) ?></b></td></tr></table></div>

 <div class="pcard" style="margin-top:22px"><h4><span><i class="bi bi-clock-history"></i>Status history</span></h4><ul class="tl rich">
 <?php foreach (array_reverse($o['history']) as $hrow): ?><li><span class="pbadge s-<?= h($hrow['status']) ?>"><?= h($hrow['status']) ?></span>
 <small class="mut"> <?= h(dt($hrow['created_at'])) ?> · <?= h($hrow['changed_by']) ?></small><?= $hrow['note'] ? '<br>' . h($hrow['note']) : '' ?></li><?php endforeach; ?></ul></div>

 <?php if ($tickets): ?><div class="pcard" style="margin-top:22px"><h4><span><i class="bi bi-life-preserver"></i>Support tickets about this order</span></h4><ul class="people">
 <?php foreach ($tickets as $t): ?><li><div><a href="ticket.php?id=<?= $t['id'] ?>"><?= h($t['subject']) ?></a><small><?= h($t['ticket_no']) ?></small></div><span class="pbadge s-<?= h($t['status']) ?>"><?= h($t['status']) ?></span></li><?php endforeach; ?></ul></div><?php endif; ?>
</div><div>
 <div class="pcard kv"><h4><span><i class="bi bi-person-vcard"></i>Customer &amp; delivery</span></h4>
  <div><span>Name</span><span><?= h($o['customer_name']) ?></span></div>
  <div><span>Account</span><span><?= $o['customer_id'] ? '<a href="customer.php?id=' . (int) $o['customer_id'] . '">Registered · ' . (int) $other['n'] . ' order(s), ' . money_short($other['s']) . ' →</a>' : 'Guest checkout' ?></span></div>
  <div><span>Phone</span><span><a href="tel:<?= h($o['phone']) ?>"><?= h($o['phone']) ?></a></span></div>
  <div><span>Email</span><span><?= $o['email'] ? '<a href="mailto:' . h($o['email']) . '">' . h($o['email']) . '</a>' : '—' ?></span></div>
  <div><span>Address</span><span style="text-align:right"><?= h($o['address']) ?></span></div>
  <div><span>Area</span><span><?= h($o['area'] ?: '—') ?></span></div>
  <div><span>City</span><span><?= h($o['city']) ?> <?= h($o['postal_code']) ?></span></div>
  <div><span>Customer note</span><span style="text-align:right"><?= h($o['notes'] ?: '—') ?></span></div>
  <div><span>Payment</span><span><?= h($o['payment_label']) ?></span></div>
  <div><span>IP</span><span><?= h($o['ip']) ?></span></div></div>

 <form method="post" class="pcard" style="margin-top:18px"><input type="hidden" name="csrf" value="<?= csrf() ?>">
  <h4><span><i class="bi bi-pencil-square"></i>Update order</span></h4>
  <div class="fld"><label>Order status</label><select name="status"><?php foreach (ORDER_STATUSES as $s): ?><option <?= $s === $o['status'] ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select></div>
  <div class="fld"><label>Payment status</label><select name="payment_status"><?php foreach (PAYMENT_STATUSES as $s): ?><option <?= $s === $o['payment_status'] ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select></div>
  <div class="formgrid"><div class="fld"><label>Courier</label><input name="courier" maxlength="60" value="<?= h($o['courier']) ?>" placeholder="TCS, Leopards…"></div>
   <div class="fld"><label>Tracking no.</label><input name="tracking_no" maxlength="80" value="<?= h($o['tracking_no']) ?>"></div></div>
  <div class="fld"><label>Expected delivery date</label><input type="date" name="eta" value="<?= h($o['eta'] ?? '') ?>"></div>
  <div class="fld"><label>Cancellation reason (if cancelling)</label><input name="cancel_reason" maxlength="300" value="<?= h($o['cancel_reason']) ?>"></div>
  <div class="fld"><label>Note shown in history</label><input name="note" maxlength="400" placeholder="e.g. Dispatched with TCS, tracking 12345"></div>
  <label class="checkrow"><input type="checkbox" name="email_note" value="1"> Email this note to the customer even if the status is unchanged</label>
  <div class="fld"><label>Private admin notes</label><textarea name="admin_notes" rows="3" maxlength="2000"><?= h($o['admin_notes']) ?></textarea></div>
  <button style="width:100%"><i class="bi bi-check2-circle"></i>Save changes</button></form>
</div></div>
<?php admin_foot();
