<?php
require __DIR__ . '/_auth.php'; $c = customer_required(); $pdo = db(); $cid = (int) $c['id'];
$orders = $pdo->prepare('SELECT id, order_number FROM orders WHERE customer_id = ? ORDER BY id DESC LIMIT 30'); $orders->execute([$cid]); $orders = $orders->fetchAll(PDO::FETCH_KEY_PAIR);
if ($_SERVER['REQUEST_METHOD'] === 'POST') { csrf_check();
    $subject = clean($_POST['subject'] ?? '', 160); $body = clean($_POST['body'] ?? '', 4000); $cat = isset(TICKET_CATEGORIES[$_POST['category'] ?? '']) ? $_POST['category'] : 'general';
    $oid = isset($orders[(int) ($_POST['order_id'] ?? 0)]) ? (int) $_POST['order_id'] : null;
    $open = (int) $pdo->query("SELECT COUNT(*) FROM tickets WHERE customer_id = $cid AND status <> 'closed'")->fetchColumn();
    $recent = (int) $pdo->query("SELECT COUNT(*) FROM tickets WHERE customer_id = $cid AND created_at > (NOW() - INTERVAL 1 HOUR)")->fetchColumn();
    if (mb_strlen($subject) < 4 || mb_strlen($body) < 10) flash_set('err', 'Please add a short subject and describe your question (at least 10 characters).');
    elseif ($open >= 8 || $recent >= 3) flash_set('err', 'You have several open requests already. Please wait for a reply or close one first.');
    else {
        $no = new_ticket_number($pdo);
        $pdo->prepare('INSERT INTO tickets (ticket_no, customer_id, order_id, subject, category, priority, status, last_reply_by) VALUES (?,?,?,?,?,?,?,?)')->execute([$no, $cid, $oid, $subject, $cat, $cat === 'return' || $cat === 'payment' ? 'high' : 'normal', 'open', 'customer']);
        $tid = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO ticket_messages (ticket_id, sender, sender_name, body) VALUES (?,?,?,?)')->execute([$tid, 'customer', $c['full_name'], $body]);
        log_activity('ticket_open', $no, $subject, 'customer', (string) $cid);
        send_mail(cfg('store_email'), "New support ticket $no", '<p><b>' . h($c['full_name']) . '</b> (' . h($c['email']) . ') opened <b>' . h($no) . '</b>: ' . h($subject) . '</p><p>' . nl2br(h($body)) . '</p>');
        flash_set('ok', "Request $no sent. We usually reply within one working day."); redirect('ticket.php?id=' . $tid);
    }
    redirect('tickets.php?new=1');
}
$status = in_array($_GET['status'] ?? '', ['open', 'answered', 'closed'], true) ? $_GET['status'] : '';
$st = $pdo->prepare('SELECT t.*, o.order_number, (SELECT COUNT(*) FROM ticket_messages m WHERE m.ticket_id = t.id) msgs FROM tickets t LEFT JOIN orders o ON o.id = t.order_id WHERE t.customer_id = ?' . ($status ? ' AND t.status = ?' : '') . ' ORDER BY (t.status = \'closed\'), t.updated_at DESC');
$st->execute($status ? [$cid, $status] : [$cid]); $rows = $st->fetchAll();
$new = isset($_GET['new']); $preOrder = (int) ($_GET['order'] ?? 0);
customer_head('Support', 'support', ['sub' => 'Questions about an order, a payment or a product? We are here to help.', 'actions' => '<a class="pbtn" href="tickets.php?new=1"><i class="bi bi-plus-lg"></i>New request</a><a class="pbtn ghost" target="_blank" href="https://wa.me/' . h(preg_replace('/\D/', '', cfg('store_phone'))) . '"><i class="bi bi-whatsapp"></i>WhatsApp us</a>']); ?>
<?php if ($new): ?><form method="post" class="pcard" style="margin-bottom:24px"><input type="hidden" name="csrf" value="<?= csrf() ?>"><h4><span><i class="bi bi-chat-left-text"></i>How can we help?</span><a href="tickets.php">Cancel</a></h4>
 <div class="formgrid"><div class="fld"><label>Topic</label><select name="category"><?php foreach (TICKET_CATEGORIES as $k => $l): ?><option value="<?= $k ?>" <?= ($preOrder && $k === 'order') ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
  <div class="fld"><label>Related order (optional)</label><select name="order_id"><option value="0">— None —</option><?php foreach ($orders as $oid => $on): ?><option value="<?= $oid ?>" <?= $oid === $preOrder ? 'selected' : '' ?>><?= h($on) ?></option><?php endforeach; ?></select></div>
  <div class="fld full" style="grid-column:1/-1"><label>Subject</label><input name="subject" required maxlength="160" placeholder="e.g. Delivery date for my sofa"></div>
  <div class="fld full" style="grid-column:1/-1"><label>Message</label><textarea id="tmsg" name="body" required rows="5" maxlength="4000" data-grow placeholder="Tell us what happened…"></textarea><span class="cnt" data-count-for="tmsg"></span></div></div>
 <button><i class="bi bi-send"></i>Send request</button></form><?php endif; ?>
<div class="stabs"><a href="tickets.php" class="<?= !$status ? 'on' : '' ?>">All</a><a href="?status=open" class="<?= $status === 'open' ? 'on' : '' ?>">Waiting for us</a><a href="?status=answered" class="<?= $status === 'answered' ? 'on' : '' ?>">Answered</a><a href="?status=closed" class="<?= $status === 'closed' ? 'on' : '' ?>">Closed</a></div>
<?php if ($rows): ?><div class="olist pcard flush"><?php foreach ($rows as $t): ?><a class="oitem" href="ticket.php?id=<?= $t['id'] ?>"><span class="oic"><i class="bi bi-chat-left-text"></i></span>
 <div class="om"><b><?= h($t['subject']) ?></b><small><?= h($t['ticket_no']) ?> · <?= h(TICKET_CATEGORIES[$t['category']] ?? '') ?><?= $t['order_number'] ? ' · ' . h($t['order_number']) : '' ?> · <?= (int) $t['msgs'] ?> message(s) · <?= h(ago($t['updated_at'])) ?></small></div>
 <?= $t['status'] === 'answered' ? '<span class="chip ok">Reply received</span>' : '' ?><span class="pbadge s-<?= h($t['status']) ?>"><?= h($t['status'] === 'open' ? 'waiting' : $t['status']) ?></span><i class="bi bi-chevron-right chev"></i></a><?php endforeach; ?></div>
<?php elseif (!$new): ?><div class="pcard"><div class="empty"><i class="bi bi-life-preserver"></i>No support requests yet.<br><a href="tickets.php?new=1">Start a conversation →</a></div></div><?php endif; ?>
<?php customer_foot();
