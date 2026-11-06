<?php
require __DIR__ . '/_auth.php'; $c = customer_required(); $pdo = db(); $cid = (int) $c['id']; $id = (int) ($_GET['id'] ?? 0);
$st = $pdo->prepare('SELECT t.*, o.order_number FROM tickets t LEFT JOIN orders o ON o.id = t.order_id WHERE t.id = ? AND t.customer_id = ?'); $st->execute([$id, $cid]); $t = $st->fetch();
if (!$t) { http_response_code(404); customer_head('Not found'); echo '<div class="empty pcard">Request not found. <a href="tickets.php">Back to support</a></div>'; customer_foot(); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') { csrf_check();
    if (isset($_POST['reply'])) {
        $body = clean($_POST['body'] ?? '', 4000);
        if (mb_strlen($body) < 2) flash_set('err', 'Write your message first.');
        else {
            $pdo->prepare('INSERT INTO ticket_messages (ticket_id, sender, sender_name, body) VALUES (?,?,?,?)')->execute([$id, 'customer', $c['full_name'], $body]);
            $pdo->prepare("UPDATE tickets SET status = 'open', last_reply_by = 'customer' WHERE id = ?")->execute([$id]);
            send_mail(cfg('store_email'), 'Customer reply on ' . $t['ticket_no'], '<p><b>' . h($c['full_name']) . '</b> replied on <b>' . h($t['ticket_no']) . '</b> – ' . h($t['subject']) . '</p><p>' . nl2br(h($body)) . '</p>');
            flash_set('ok', 'Reply sent.');
        }
    } elseif (isset($_POST['close'])) { $pdo->prepare("UPDATE tickets SET status = 'closed' WHERE id = ?")->execute([$id]); flash_set('ok', 'Request closed. You can reopen it any time by replying.'); }
    redirect('ticket.php?id=' . $id);
}
$ms = $pdo->prepare('SELECT * FROM ticket_messages WHERE ticket_id = ? ORDER BY id'); $ms->execute([$id]); $msgs = $ms->fetchAll();
customer_head($t['ticket_no'], 'support'); ?>
<div class="prow"><a class="pbtn ghost" href="tickets.php">← Support</a><h2 style="margin:0 0 0 8px"><?= h($t['subject']) ?></h2><span class="pbadge s-<?= h($t['status']) ?>"><?= h($t['status'] === 'open' ? 'waiting for us' : $t['status']) ?></span></div>
<p class="mut" style="margin:0 0 16px"><?= h($t['ticket_no']) ?> · <?= h(TICKET_CATEGORIES[$t['category']] ?? '') ?><?= $t['order_id'] ? ' · <a href="order.php?id=' . (int) $t['order_id'] . '">order ' . h($t['order_number']) . '</a>' : '' ?> · opened <?= h(dt($t['created_at'])) ?></p>
<div class="pcard"><div class="thread" data-scroll="1"><?php foreach ($msgs as $m): $mine = $m['sender'] === 'customer'; ?>
 <div class="bubble <?= $mine ? 'me' : 'staff' ?>"><div class="who"><b><?= $mine ? 'You' : h($m['sender_name'] ?: 'Durable Furnitures support') ?></b><span><?= h(dt($m['created_at'])) ?></span></div><?= nl2br(h($m['body'])) ?></div><?php endforeach; ?></div>
 <form method="post" class="replybox"><input type="hidden" name="csrf" value="<?= csrf() ?>">
  <textarea id="rb" name="body" rows="3" maxlength="4000" data-grow placeholder="<?= $t['status'] === 'closed' ? 'Reply to reopen this request…' : 'Write a reply…' ?>" required></textarea>
  <div class="prow" style="margin:12px 0 0"><button name="reply" value="1"><i class="bi bi-send"></i>Send reply</button><?php if ($t['status'] !== 'closed'): ?><button class="ghostb" name="close" value="1" formnovalidate data-confirm="Mark this request as resolved and close it?" data-confirm-yes="Close request" data-confirm-danger="0">Mark as resolved</button><?php endif; ?></div></form></div>
<?php customer_foot();
