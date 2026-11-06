<?php
require __DIR__ . '/_auth.php'; $c = customer_required(); $pdo = db(); $cid = (int) $c['id'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') { csrf_check();
    $pdo->prepare('UPDATE customers SET notif_seen_at = NOW() WHERE id = ?')->execute([$cid]);
    if (isset($_POST['seen'])) { header('Content-Type: application/json'); echo '{"ok":true}'; exit; }
    flash_set('ok', 'All notifications marked as read.'); redirect('notifications.php');
}
$items = customer_notifications($cid, 40); $unseen = count(array_filter($items, fn($i) => $i['new']));
customer_head('Notifications', 'notifications', ['sub' => 'Order updates and replies from our support team.',
  'actions' => $unseen ? '<form method="post" style="display:inline"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><button><i class="bi bi-check2-all"></i>Mark all as read</button></form>' : '']); ?>
<div class="pcard"><?php if ($items): ?><ul class="notelist"><?php foreach ($items as $n): ?><li class="<?= $n['new'] ? 'new' : '' ?>"><a href="<?= h($n['url']) ?>"><span class="nic"><i class="bi <?= h($n['icon']) ?>"></i></span><div><b><?= h($n['title']) ?></b><small><?= h($n['text']) ?></small></div><time><?= h(ago($n['t'])) ?></time></a></li><?php endforeach; ?></ul>
<?php else: ?><div class="empty"><i class="bi bi-bell-slash"></i>You are all caught up – nothing new yet.</div><?php endif; ?></div>
<?php customer_foot();
