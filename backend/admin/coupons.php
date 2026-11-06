<?php
require __DIR__ . '/_auth.php'; admin_required(); $pdo = db();
$edit = (int) ($_GET['edit'] ?? 0); $new = isset($_GET['new']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') { csrf_check();
    if (isset($_POST['toggle'])) { $pdo->prepare('UPDATE coupons SET active = 1 - active WHERE id = ?')->execute([(int) $_POST['toggle']]); flash_set('ok', 'Coupon updated.'); }
    elseif (isset($_POST['del'])) { $pdo->prepare('DELETE FROM coupons WHERE id = ?')->execute([(int) $_POST['del']]); log_activity('coupon_delete', '#' . (int) $_POST['del']); flash_set('ok', 'Coupon deleted.'); }
    elseif (isset($_POST['save'])) {
        $cid = (int) ($_POST['cid'] ?? 0); $code = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($_POST['code'] ?? '')));
        $type = ($_POST['type'] ?? '') === 'fixed' ? 'fixed' : 'percent'; $val = (int) preg_replace('/\D/', '', (string) ($_POST['value'] ?? ''));
        $err = '';
        if (strlen($code) < 3) $err = 'Use a code of at least 3 letters or digits.'; elseif ($val < 1) $err = 'Enter a discount value.'; elseif ($type === 'percent' && $val > 90) $err = 'Percent discounts are limited to 90%.';
        if ($err) flash_set('err', $err);
        else {
            $row = [$code, clean($_POST['description'] ?? '', 160), $type, $val, (int) preg_replace('/\D/', '', (string) ($_POST['min_subtotal'] ?? '0')), (int) preg_replace('/\D/', '', (string) ($_POST['max_discount'] ?? '0')),
                    (int) preg_replace('/\D/', '', (string) ($_POST['usage_limit'] ?? '0')), valid_date($_POST['starts_at'] ?? '') ?: null, valid_date($_POST['expires_at'] ?? '') ?: null, empty($_POST['active']) ? 0 : 1];
            try {
                if ($cid) { $pdo->prepare('UPDATE coupons SET code=?, description=?, type=?, value=?, min_subtotal=?, max_discount=?, usage_limit=?, starts_at=?, expires_at=?, active=? WHERE id=?')->execute([...$row, $cid]); }
                else { $pdo->prepare('INSERT INTO coupons (code, description, type, value, min_subtotal, max_discount, usage_limit, starts_at, expires_at, active) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute($row); }
                log_activity($cid ? 'coupon_edit' : 'coupon_add', $code); flash_set('ok', 'Coupon saved.'); redirect('coupons.php');
            } catch (PDOException $e) { flash_set('err', ($e->errorInfo[1] ?? 0) == 1062 ? 'That coupon code already exists.' : 'Could not save the coupon.'); }
        }
        redirect('coupons.php?' . ($cid ? 'edit=' . $cid : 'new=1'));
    }
    redirect('coupons.php');
}
$rows = $pdo->query("SELECT c.*, (SELECT COALESCE(SUM(discount),0) FROM orders o WHERE o.coupon_code = c.code AND o.status<>'cancelled') given FROM coupons c ORDER BY c.active DESC, c.id DESC")->fetchAll();
$ec = null; if ($edit) { $x = $pdo->prepare('SELECT * FROM coupons WHERE id = ?'); $x->execute([$edit]); $ec = $x->fetch(); }
$show = $ec ?: ($new ? ['id' => 0, 'code' => '', 'description' => '', 'type' => 'percent', 'value' => 10, 'min_subtotal' => 0, 'max_discount' => 0, 'usage_limit' => 0, 'starts_at' => '', 'expires_at' => '', 'active' => 1] : null);
$given = array_sum(array_column($rows, 'given')); $liveN = count(array_filter($rows, fn($r) => $r['active']));
admin_head('Coupons', 'coupons', ['sub' => 'Create discount codes customers can apply at checkout.', 'actions' => '<a class="pbtn" href="coupons.php?new=1"><i class="bi bi-plus-lg"></i>New coupon</a>']); ?>
<?php if ($show): ?>
<form method="post" class="pcard" style="margin-bottom:24px"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="cid" value="<?= (int) $show['id'] ?>">
 <h4><span><i class="bi bi-ticket-perforated"></i><?= $show['id'] ? 'Edit coupon' : 'New coupon' ?></span><a href="coupons.php">Cancel</a></h4>
 <div class="formgrid three">
  <div class="fld"><label>Code</label><input name="code" required maxlength="40" value="<?= h($show['code']) ?>" placeholder="WELCOME10" style="text-transform:uppercase"></div>
  <div class="fld"><label>Type</label><select name="type"><option value="percent" <?= $show['type'] === 'percent' ? 'selected' : '' ?>>Percent off</option><option value="fixed" <?= $show['type'] === 'fixed' ? 'selected' : '' ?>>Fixed amount off (Rs.)</option></select></div>
  <div class="fld"><label>Value</label><input name="value" required inputmode="numeric" value="<?= h($show['value']) ?>"></div>
  <div class="fld"><label>Minimum cart (Rs., 0 = none)</label><input name="min_subtotal" inputmode="numeric" value="<?= h($show['min_subtotal']) ?>"></div>
  <div class="fld"><label>Max discount (Rs., 0 = none)</label><input name="max_discount" inputmode="numeric" value="<?= h($show['max_discount']) ?>"></div>
  <div class="fld"><label>Usage limit (0 = unlimited)</label><input name="usage_limit" inputmode="numeric" value="<?= h($show['usage_limit']) ?>"></div>
  <div class="fld"><label>Starts</label><input type="date" name="starts_at" value="<?= h($show['starts_at'] ?? '') ?>"></div>
  <div class="fld"><label>Expires</label><input type="date" name="expires_at" value="<?= h($show['expires_at'] ?? '') ?>"></div>
  <div class="fld"><label>Internal description</label><input name="description" maxlength="160" value="<?= h($show['description']) ?>"></div></div>
 <label class="checkrow"><input type="checkbox" name="active" value="1" <?= $show['active'] ? 'checked' : '' ?>> Active</label>
 <button name="save" value="1"><i class="bi bi-check2-circle"></i>Save coupon</button></form>
<?php endif; ?>
<div class="kpis"><div class="kpi"><span class="ico"><i class="bi bi-ticket-perforated"></i></span><div><small>Coupons</small><b><?= count($rows) ?></b><em><?= $liveN ?> active</em></div></div>
 <div class="kpi hl"><span class="ico"><i class="bi bi-gift"></i></span><div><small>Discount given</small><b><?= money($given) ?></b><em>on non-cancelled orders</em></div></div>
 <div class="kpi"><span class="ico"><i class="bi bi-bag-check"></i></span><div><small>Redemptions</small><b><?= array_sum(array_column($rows, 'used_count')) ?></b></div></div></div>
<form method="post"><input type="hidden" name="csrf" value="<?= csrf() ?>"><div class="tablewrap"><table><tr><th>Code</th><th>Discount</th><th>Rules</th><th class="r">Used</th><th>Valid</th><th>Status</th><th></th></tr>
<?php foreach ($rows as $c): $expired = $c['expires_at'] && $c['expires_at'] < date('Y-m-d'); ?><tr>
 <td><span class="code"><?= h($c['code']) ?></span><br><small class="mut"><?= h($c['description']) ?></small></td>
 <td><b><?= $c['type'] === 'percent' ? (int) $c['value'] . '% off' : money($c['value']) . ' off' ?></b><?= $c['max_discount'] ? '<br><small class="mut">up to ' . money($c['max_discount']) . '</small>' : '' ?></td>
 <td><small><?= $c['min_subtotal'] ? 'Min cart ' . money($c['min_subtotal']) : 'No minimum' ?></small></td>
 <td class="r"><?= (int) $c['used_count'] ?><?= $c['usage_limit'] ? ' / ' . (int) $c['usage_limit'] : '' ?><br><small class="mut"><?= money($c['given']) ?></small></td>
 <td class="nw"><small><?= $c['starts_at'] ? h(date('d M Y', strtotime($c['starts_at']))) : 'Now' ?> → <?= $c['expires_at'] ? h(date('d M Y', strtotime($c['expires_at']))) : 'No expiry' ?></small></td>
 <td><?= $expired ? '<span class="pbadge s-closed">expired</span>' : '<span class="pbadge ' . ($c['active'] ? 's-active' : 's-disabled') . '">' . ($c['active'] ? 'active' : 'off') . '</span>' ?></td>
 <td><div class="row-actions"><a class="ibtn-link" href="coupons.php?edit=<?= $c['id'] ?>" title="Edit"><i class="bi bi-pencil"></i></a>
  <button class="ibtn" name="toggle" value="<?= $c['id'] ?>" title="Turn on/off"><i class="bi bi-power"></i></button>
  <button class="ibtn bad" name="del" value="<?= $c['id'] ?>" title="Delete" data-confirm="Delete coupon <?= h($c['code']) ?>? Past orders keep their discount." data-confirm-yes="Delete"><i class="bi bi-trash3"></i></button></div></td></tr>
<?php endforeach; if (!$rows) echo '<tr><td colspan="7" class="empty"><i class="bi bi-ticket-perforated"></i>No coupons yet. Create one to run a promotion.</td></tr>'; ?></table></div></form>
<?php admin_foot();
