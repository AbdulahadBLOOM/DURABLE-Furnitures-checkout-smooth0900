<?php
require __DIR__ . '/_auth.php'; admin_required();
$pdo = db();
$q = clean($_GET['q'] ?? '', 80);
$state = in_array($_GET['state'] ?? '', ['active', 'disabled'], true) ? $_GET['state'] : '';
$seg = in_array($_GET['seg'] ?? '', ['vip', 'new', 'repeat', 'inactive', 'noorders'], true) ? $_GET['seg'] : '';
$sort = in_array($_GET['sort'] ?? '', ['name', 'name_desc', 'orders', 'orders_desc', 'spent', 'spent_desc', 'joined', 'joined_desc', 'login', 'login_desc'], true) ? $_GET['sort'] : 'joined_desc';
$page = max(1, (int) ($_GET['p'] ?? 1)); $per = 25;

$where = []; $args = []; $having = [];
if ($q !== '') { $where[] = '(c.full_name LIKE ? OR c.email LIKE ? OR c.phone LIKE ? OR c.city LIKE ?)'; array_push($args, ...array_fill(0, 4, "%$q%")); }
if ($state) { $where[] = 'c.is_active = ?'; $args[] = $state === 'active' ? 1 : 0; }
$vip = 150000;
if ($seg === 'new')      $where[] = 'c.created_at >= (NOW() - INTERVAL 30 DAY)';
if ($seg === 'inactive') $where[] = '(c.last_login IS NULL OR c.last_login < (NOW() - INTERVAL 90 DAY))';
if ($seg === 'vip')      $having[] = "spent >= $vip";
if ($seg === 'repeat')   $having[] = 'orders >= 2';
if ($seg === 'noorders') $having[] = 'orders = 0';
$w = $where ? 'WHERE ' . implode(' AND ', $where) : ''; $hv = $having ? 'HAVING ' . implode(' AND ', $having) : '';
$ord = ['name' => 'c.full_name ASC', 'name_desc' => 'c.full_name DESC', 'orders' => 'orders ASC', 'orders_desc' => 'orders DESC', 'spent' => 'spent ASC', 'spent_desc' => 'spent DESC',
        'joined' => 'c.id ASC', 'joined_desc' => 'c.id DESC', 'login' => 'c.last_login ASC', 'login_desc' => 'c.last_login DESC'][$sort];
$base = "SELECT c.*, COUNT(o.id) orders, COALESCE(SUM(CASE WHEN o.status<>'cancelled' THEN o.total END),0) spent, MAX(o.created_at) last_order
         FROM customers c LEFT JOIN orders o ON o.customer_id = c.id $w GROUP BY c.id $hv";
$cnt = $pdo->prepare("SELECT COUNT(*) FROM ($base) x"); $cnt->execute($args); $total = (int) $cnt->fetchColumn();
$st = $pdo->prepare("$base ORDER BY $ord LIMIT $per OFFSET " . (($page - 1) * $per)); $st->execute($args); $rows = $st->fetchAll();

$k = $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(created_at >= (NOW() - INTERVAL 30 DAY)),0) new30, COALESCE(SUM(is_active=0),0) off,
    COALESCE(SUM(last_login >= (NOW() - INTERVAL 30 DAY)),0) act30 FROM customers")->fetch();
$buyers = (int) $pdo->query('SELECT COUNT(DISTINCT customer_id) FROM orders WHERE customer_id IS NOT NULL')->fetchColumn();
$keep = array_filter(['q' => $q, 'state' => $state, 'seg' => $seg, 'sort' => $sort !== 'joined_desc' ? $sort : '']);
$segs = ['' => 'All', 'new' => 'New (30d)', 'repeat' => 'Repeat buyers', 'vip' => 'VIP (' . money_short($vip) . '+)', 'noorders' => 'No orders yet', 'inactive' => 'Inactive 90d+'];

admin_head('Customers', 'customers', ['sub' => number_format($total) . ' customer account(s) match.', 'actions' => '<a class="pbtn ghost" href="export.php?type=customers"><i class="bi bi-download"></i>Export all CSV</a>']); ?>
<div class="kpis">
 <div class="kpi"><span class="ico"><i class="bi bi-people"></i></span><div><small>Accounts</small><b><?= (int) $k['n'] ?></b><em>+<?= (int) $k['new30'] ?> in 30 days</em></div></div>
 <div class="kpi"><span class="ico"><i class="bi bi-bag-heart"></i></span><div><small>Have ordered</small><b><?= $buyers ?></b><em><?= $k['n'] ? round($buyers / $k['n'] * 100) : 0 ?>% conversion</em></div></div>
 <div class="kpi"><span class="ico"><i class="bi bi-activity"></i></span><div><small>Active (30d)</small><b><?= (int) $k['act30'] ?></b><em>signed in recently</em></div></div>
 <div class="kpi"><span class="ico"><i class="bi bi-person-slash"></i></span><div><small>Disabled</small><b><?= (int) $k['off'] ?></b><em>cannot sign in</em></div></div>
</div>
<div class="stabs"><?php foreach ($segs as $key => $lab): ?><a href="<?= h(qs(['seg' => $key, 'p' => ''], $keep)) ?>" class="<?= $seg === $key ? 'on' : '' ?>"><?= h($lab) ?></a><?php endforeach; ?></div>
<form class="fbar" method="get"><input type="hidden" name="seg" value="<?= h($seg) ?>">
 <div class="f wide"><label>Search</label><input name="q" value="<?= h($q) ?>" placeholder="Name, email, phone, city"></div>
 <div class="f"><label>Account</label><select name="state"><option value="">All</option><option value="active" <?= $state === 'active' ? 'selected' : '' ?>>Active</option><option value="disabled" <?= $state === 'disabled' ? 'selected' : '' ?>>Disabled</option></select></div>
 <div class="fx"><button><i class="bi bi-funnel"></i>Filter</button><a class="pbtn ghost" href="customers.php">Reset</a></div></form>
<div class="tablewrap"><table><tr><?= sort_th('Customer', 'name', $sort) ?><th>Phone / City</th><?= sort_th('Orders', 'orders', $sort, 'r') ?><?= sort_th('Spent', 'spent', $sort, 'r') ?><th>Last order</th><?= sort_th('Joined', 'joined', $sort) ?><?= sort_th('Last sign-in', 'login', $sort) ?><th>Status</th></tr>
<?php foreach ($rows as $c): $t = loyalty((int) $c['spent']); ?><tr>
 <td><div class="who-cell"><span class="avatar sm"><?= h(initials($c['full_name'])) ?></span><div><a href="customer.php?id=<?= $c['id'] ?>"><b><?= h($c['full_name']) ?></b></a> <span class="chip mute" style="padding:1px 8px;font-size:.66rem"><?= h($t['name']) ?></span><br><small class="mut"><?= h($c['email']) ?></small></div></div></td>
 <td><?= h($c['phone'] ?: '—') ?><br><small class="mut"><?= h($c['city'] ?: '') ?></small></td>
 <td class="r"><?= (int) $c['orders'] ?></td><td class="r nw"><b><?= money($c['spent']) ?></b></td>
 <td class="nw"><small><?= $c['last_order'] ? h(ago($c['last_order'])) : '—' ?></small></td>
 <td class="nw"><small><?= h(dt($c['created_at'])) ?></small></td><td class="nw"><small><?= $c['last_login'] ? h(ago($c['last_login'])) : 'never' ?></small></td>
 <td><span class="pbadge <?= $c['is_active'] ? 's-active' : 's-disabled' ?>"><?= $c['is_active'] ? 'active' : 'disabled' ?></span></td></tr>
<?php endforeach; if (!$rows) echo '<tr><td colspan="8" class="empty"><i class="bi bi-people"></i>No customers found.</td></tr>'; ?></table></div>
<?= paginate($total, $page, $per, $keep) ?>
<?php admin_foot();
