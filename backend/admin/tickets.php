<?php
require __DIR__ . '/_auth.php'; admin_required(); $pdo = db();
$status = in_array($_GET['status'] ?? '', ['open', 'answered', 'closed'], true) ? $_GET['status'] : '';
$prio = in_array($_GET['priority'] ?? '', ['low', 'normal', 'high'], true) ? $_GET['priority'] : '';
$cat = isset(TICKET_CATEGORIES[$_GET['cat'] ?? '']) ? $_GET['cat'] : '';
$q = clean($_GET['q'] ?? '', 80); $page = max(1, (int) ($_GET['p'] ?? 1)); $per = 25;

if ($_SERVER['REQUEST_METHOD'] === 'POST') { csrf_check();
    $ids = array_slice(array_map('intval', (array) ($_POST['ids'] ?? [])), 0, 100);
    if ($ids && in_array($_POST['bulk'] ?? '', ['closed', 'open'], true)) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("UPDATE tickets SET status = ? WHERE id IN ($in)")->execute([$_POST['bulk'], ...$ids]);
        log_activity('ticket_bulk', count($ids) . ' ticket(s)', $_POST['bulk']); flash_set('ok', count($ids) . ' ticket(s) updated.');
    }
    redirect('tickets.php' . qs([], $_GET));
}
$w = []; $a = [];
if ($status) { $w[] = 't.status = ?'; $a[] = $status; } if ($prio) { $w[] = 't.priority = ?'; $a[] = $prio; } if ($cat) { $w[] = 't.category = ?'; $a[] = $cat; }
if ($q !== '') { $w[] = '(t.ticket_no LIKE ? OR t.subject LIKE ? OR c.full_name LIKE ? OR c.email LIKE ?)'; array_push($a, ...array_fill(0, 4, "%$q%")); }
$ws = $w ? 'WHERE ' . implode(' AND ', $w) : '';
$cnt = $pdo->prepare("SELECT COUNT(*) FROM tickets t JOIN customers c ON c.id = t.customer_id $ws"); $cnt->execute($a); $total = (int) $cnt->fetchColumn();
$st = $pdo->prepare("SELECT t.*, c.full_name, c.email, o.order_number, (SELECT COUNT(*) FROM ticket_messages m WHERE m.ticket_id = t.id) msgs
    FROM tickets t JOIN customers c ON c.id = t.customer_id LEFT JOIN orders o ON o.id = t.order_id $ws
    ORDER BY (t.status = 'closed'), FIELD(t.priority,'high','normal','low'), t.updated_at DESC LIMIT $per OFFSET " . (($page - 1) * $per));
$st->execute($a); $rows = $st->fetchAll();
$cn = array_column($pdo->query('SELECT status, COUNT(*) n FROM tickets GROUP BY status')->fetchAll(), 'n', 'status'); $all = array_sum($cn);
$keep = array_filter(['status' => $status, 'priority' => $prio, 'cat' => $cat, 'q' => $q]);
admin_head('Support tickets', 'tickets', ['sub' => 'Questions and issues raised by signed-in customers.']); ?>
<div class="stabs"><a href="tickets.php" class="<?= !$status ? 'on' : '' ?>">All <b><?= $all ?></b></a><?php foreach (['open' => 'Open – needs reply', 'answered' => 'Answered', 'closed' => 'Closed'] as $k => $l): ?><a href="<?= h(qs(['status' => $k, 'p' => ''], $keep)) ?>" class="<?= $status === $k ? 'on' : '' ?>"><?= $l ?> <b><?= (int) ($cn[$k] ?? 0) ?></b></a><?php endforeach; ?></div>
<form class="fbar" method="get"><input type="hidden" name="status" value="<?= h($status) ?>">
 <div class="f wide"><label>Search</label><input name="q" value="<?= h($q) ?>" placeholder="Ticket no., subject, customer"></div>
 <div class="f"><label>Priority</label><select name="priority"><option value="">Any</option><?php foreach (['high', 'normal', 'low'] as $p): ?><option <?= $p === $prio ? 'selected' : '' ?>><?= $p ?></option><?php endforeach; ?></select></div>
 <div class="f"><label>Category</label><select name="cat"><option value="">Any</option><?php foreach (TICKET_CATEGORIES as $k => $l): ?><option value="<?= $k ?>" <?= $k === $cat ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
 <div class="fx"><button>Filter</button><a class="pbtn ghost" href="tickets.php">Reset</a></div></form>
<form method="post" data-bulk><input type="hidden" name="csrf" value="<?= csrf() ?>">
<div class="bulkbar"><span class="bulk-count">0 selected</span><button name="bulk" value="closed" class="sm ghostb">Close</button><button name="bulk" value="open" class="sm ghostb">Re-open</button></div>
<div class="tablewrap"><table><tr><th class="chk"><input type="checkbox" data-bulk-all aria-label="Select all"></th><th>Ticket</th><th>Customer</th><th>Category</th><th>Priority</th><th>Status</th><th class="r">Msgs</th><th>Updated</th></tr>
<?php foreach ($rows as $t): ?><tr><td class="chk"><input type="checkbox" name="ids[]" value="<?= $t['id'] ?>" data-bulk-row aria-label="Select"></td>
 <td><a href="ticket.php?id=<?= $t['id'] ?>"><b><?= h($t['subject']) ?></b></a><br><small class="mut"><?= h($t['ticket_no']) ?><?= $t['order_number'] ? ' · order ' . h($t['order_number']) : '' ?></small></td>
 <td><?= h($t['full_name']) ?><br><small class="mut"><?= h($t['email']) ?></small></td><td><small><?= h(TICKET_CATEGORIES[$t['category']] ?? $t['category']) ?></small></td>
 <td><span class="pbadge s-<?= h($t['priority']) ?>"><?= h($t['priority']) ?></span></td><td><span class="pbadge s-<?= h($t['status']) ?>"><?= h($t['status']) ?></span><?= $t['status'] === 'open' ? '' : '' ?></td>
 <td class="r"><?= (int) $t['msgs'] ?></td><td class="nw"><small class="mut"><?= h(ago($t['updated_at'])) ?></small></td></tr>
<?php endforeach; if (!$rows) echo '<tr><td colspan="8" class="empty"><i class="bi bi-life-preserver"></i>No tickets found.</td></tr>'; ?></table></div></form>
<?= paginate($total, $page, $per, $keep) ?>
<?php admin_foot();
