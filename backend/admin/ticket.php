<?php
require __DIR__ . '/_auth.php'; admin_required(); $pdo = db(); $id = (int) ($_GET['id'] ?? 0);
$st = $pdo->prepare('SELECT t.*, c.full_name, c.email, c.phone, o.order_number FROM tickets t JOIN customers c ON c.id = t.customer_id LEFT JOIN orders o ON o.id = t.order_id WHERE t.id = ?');
$st->execute([$id]); $t = $st->fetch();
if (!$t) { http_response_code(404); exit('Ticket not found'); }
$me = $GLOBALS['ADMIN']; $meName = $me['full_name'] ?: $me['username'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') { csrf_check();
    if (isset($_POST['reply'])) {
        $body = clean($_POST['body'] ?? '', 4000);
        if (mb_strlen($body) < 2) flash_set('err', 'Write a reply first.');
        else {
            $pdo->prepare('INSERT INTO ticket_messages (ticket_id, sender, sender_name, body) VALUES (?,?,?,?)')->execute([$id, 'admin', $meName, $body]);
            $newStatus = !empty($_POST['close']) ? 'closed' : 'answered';
            $pdo->prepare('UPDATE tickets SET status = ?, last_reply_by = ? WHERE id = ?')->execute([$newStatus, 'admin', $id]);
            send_mail($t['email'], 'Re: ' . $t['subject'] . ' [' . $t['ticket_no'] . ']', '<div style="font-family:Arial,sans-serif;max-width:620px;margin:auto;color:#222"><h2>' . h(cfg('store_name')) . '</h2><p>Hello ' . h($t['full_name']) . ',</p><p>' . nl2br(h($body)) . '</p><p style="color:#777;font-size:12px">You can reply from your account: Support → ' . h($t['ticket_no']) . '.</p></div>');
            log_activity('ticket_reply', $t['ticket_no']); flash_set('ok', 'Reply sent' . ($newStatus === 'closed' ? ' and ticket closed.' : '.'));
        }
    } elseif (isset($_POST['set'])) {
        $s = in_array($_POST['status'] ?? '', ['open', 'answered', 'closed'], true) ? $_POST['status'] : $t['status'];
        $p = in_array($_POST['priority'] ?? '', ['low', 'normal', 'high'], true) ? $_POST['priority'] : $t['priority'];
        $pdo->prepare('UPDATE tickets SET status = ?, priority = ? WHERE id = ?')->execute([$s, $p, $id]);
        log_activity('ticket_update', $t['ticket_no'], "status=$s priority=$p"); flash_set('ok', 'Ticket updated.');
    } elseif (isset($_POST['delete'])) {
        $pdo->prepare('DELETE FROM tickets WHERE id = ?')->execute([$id]); log_activity('ticket_delete', $t['ticket_no']); flash_set('ok', 'Ticket deleted.'); redirect('tickets.php');
    }
    redirect('ticket.php?id=' . $id);
}
$ms = $pdo->prepare('SELECT * FROM ticket_messages WHERE ticket_id = ? ORDER BY id'); $ms->execute([$id]); $msgs = $ms->fetchAll();
$canned = ['Thank you for reaching out! ', 'We have checked your order and it is on its way. ', 'We are sorry for the inconvenience – we will resolve this right away. ', 'Could you please share a photo of the issue? ', 'Your refund has been initiated and will reflect in 3–5 working days. '];
admin_head($t['ticket_no'], 'tickets'); ?>
<div class="prow"><a class="pbtn ghost" href="tickets.php">← All tickets</a><h2 style="margin:0 0 0 8px"><?= h($t['subject']) ?></h2><span class="pbadge s-<?= h($t['status']) ?>"><?= h($t['status']) ?></span><span class="pbadge s-<?= h($t['priority']) ?>"><?= h($t['priority']) ?></span></div>
<div class="grid2"><div>
 <div class="pcard"><div class="thread" data-scroll="1"><?php foreach ($msgs as $m): $mine = $m['sender'] === 'admin'; ?>
  <div class="bubble <?= $mine ? 'me staff' : '' ?>"><div class="who"><b><?= h($m['sender_name'] ?: ($mine ? 'Support' : $t['full_name'])) ?></b><span><?= $mine ? 'Staff' : 'Customer' ?> · <?= h(dt($m['created_at'])) ?></span></div><?= nl2br(h($m['body'])) ?></div><?php endforeach; ?></div>
  <form method="post" class="replybox"><input type="hidden" name="csrf" value="<?= csrf() ?>">
   <div class="canned"><?php foreach ($canned as $cn): ?><button type="button" class="canned-b" onclick="var t=document.getElementById('rb');t.value+=<?= h(json_encode($cn)) ?>;t.focus()"><?= h(mb_substr($cn, 0, 28)) ?>…</button><?php endforeach; ?></div>
   <textarea id="rb" name="body" rows="4" maxlength="4000" data-grow placeholder="Write your reply to <?= h($t['full_name']) ?> – they will also get it by email" required></textarea>
   <div class="prow" style="margin:12px 0 0"><button name="reply" value="1"><i class="bi bi-send"></i>Send reply</button><label class="checkrow" style="margin:0"><input type="checkbox" name="close" value="1"> Close ticket after replying</label></div></form></div>
</div><div>
 <div class="pcard kv"><h4>Details</h4>
  <div><span>Ticket</span><span><?= h($t['ticket_no']) ?></span></div><div><span>Category</span><span><?= h(TICKET_CATEGORIES[$t['category']] ?? $t['category']) ?></span></div>
  <div><span>Customer</span><span><a href="customer.php?id=<?= $t['customer_id'] ?>"><?= h($t['full_name']) ?></a></span></div>
  <div><span>Email</span><span><a href="mailto:<?= h($t['email']) ?>"><?= h($t['email']) ?></a></span></div><div><span>Phone</span><span><?= h($t['phone'] ?: '—') ?></span></div>
  <div><span>Order</span><span><?php if ($t['order_id']): ?><a href="order.php?id=<?= $t['order_id'] ?>"><?= h($t['order_number']) ?></a><?php else: ?>—<?php endif; ?></span></div>
  <div><span>Opened</span><span><?= h(dt($t['created_at'])) ?></span></div><div><span>Last activity</span><span><?= h(ago($t['updated_at'])) ?></span></div></div>
 <form method="post" class="pcard" style="margin-top:18px"><input type="hidden" name="csrf" value="<?= csrf() ?>"><h4>Manage</h4>
  <div class="fld"><label>Status</label><select name="status"><?php foreach (['open', 'answered', 'closed'] as $s): ?><option <?= $s === $t['status'] ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select></div>
  <div class="fld"><label>Priority</label><select name="priority"><?php foreach (['low', 'normal', 'high'] as $s): ?><option <?= $s === $t['priority'] ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select></div>
  <button name="set" value="1" style="width:100%">Save</button>
  <button name="delete" value="1" class="danger" style="width:100%;margin-top:10px" data-confirm="Delete this ticket and all its messages?" data-confirm-yes="Delete"><i class="bi bi-trash3"></i>Delete ticket</button></form>
</div></div>
<?php admin_foot();
