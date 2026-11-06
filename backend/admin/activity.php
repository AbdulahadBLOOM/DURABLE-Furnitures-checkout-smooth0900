<?php
require __DIR__ . '/_auth.php'; admin_required(); $pdo = db();
$type = in_array($_GET['type'] ?? '', ['admin', 'customer'], true) ? $_GET['type'] : ''; $q = clean($_GET['q'] ?? '', 60); $page = max(1, (int) ($_GET['p'] ?? 1)); $per = 40;
$w = []; $a = []; if ($type) { $w[] = 'actor_type = ?'; $a[] = $type; } if ($q !== '') { $w[] = '(actor LIKE ? OR action LIKE ? OR target LIKE ? OR detail LIKE ?)'; array_push($a, ...array_fill(0, 4, "%$q%")); }
$ws = $w ? 'WHERE ' . implode(' AND ', $w) : '';
$c = $pdo->prepare("SELECT COUNT(*) FROM activity_log $ws"); $c->execute($a); $total = (int) $c->fetchColumn();
$s = $pdo->prepare("SELECT * FROM activity_log $ws ORDER BY id DESC LIMIT $per OFFSET " . (($page - 1) * $per)); $s->execute($a); $rows = $s->fetchAll();
admin_head('Activity log', 'activity', ['sub' => 'An audit trail of important actions by staff and customers (kept 180 days).']); ?>
<form class="fbar" method="get"><div class="f wide"><label>Search</label><input name="q" value="<?= h($q) ?>" placeholder="Actor, action, target…"></div>
 <div class="f"><label>Who</label><select name="type"><option value="">Everyone</option><option value="admin" <?= $type === 'admin' ? 'selected' : '' ?>>Staff</option><option value="customer" <?= $type === 'customer' ? 'selected' : '' ?>>Customers</option></select></div><div class="fx"><button>Filter</button><a class="pbtn ghost" href="activity.php">Reset</a></div></form>
<div class="tablewrap"><table><tr><th>When</th><th>Who</th><th>Action</th><th>Target</th><th>Detail</th><th>IP</th></tr>
<?php foreach ($rows as $r): ?><tr><td class="nw"><small><?= h(dt($r['created_at'])) ?></small></td><td><span class="chip <?= $r['actor_type'] === 'admin' ? '' : 'mute' ?>"><?= h($r['actor_type']) ?></span> <?= h($r['actor']) ?></td>
 <td><b><?= h(str_replace('_', ' ', $r['action'])) ?></b></td><td><?= h($r['target']) ?></td><td><small class="mut"><?= h($r['detail']) ?></small></td><td><small class="mut"><?= h($r['ip']) ?></small></td></tr>
<?php endforeach; if (!$rows) echo '<tr><td colspan="6" class="empty"><i class="bi bi-clock-history"></i>Nothing logged yet.</td></tr>'; ?></table></div>
<?= paginate($total, $page, $per, array_filter(['type' => $type, 'q' => $q])) ?>
<?php admin_foot();
