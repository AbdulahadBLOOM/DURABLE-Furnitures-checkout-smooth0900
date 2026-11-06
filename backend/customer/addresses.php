<?php
require __DIR__ . '/_auth.php'; $c = customer_required(); $pdo = db(); $cid = (int) $c['id'];

/** The default address is mirrored onto the customer row so checkout pre-fills with it. */
function sync_default(PDO $pdo, int $cid): void {
    $st = $pdo->prepare('SELECT * FROM addresses WHERE customer_id = ? AND is_default = 1 LIMIT 1'); $st->execute([$cid]); $a = $st->fetch();
    if (!$a) return;
    $pdo->prepare('UPDATE customers SET address = ?, area = ?, city = ?, postal_code = ?, phone = IF(phone = \'\' AND ? <> \'\', ?, phone) WHERE id = ?')
        ->execute([$a['address'], $a['area'], $a['city'], $a['postal_code'], $a['phone'], $a['phone'], $cid]);
}
$edit = (int) ($_GET['edit'] ?? 0); $new = isset($_GET['new']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') { csrf_check();
    if (isset($_POST['save'])) {
        $aid = (int) ($_POST['aid'] ?? 0);
        $label = clean($_POST['label'] ?? '', 40) ?: 'Home'; $rec = clean($_POST['recipient'] ?? '', 120) ?: $c['full_name'];
        $phoneRaw = clean($_POST['phone'] ?? '', 30); $phone = $phoneRaw === '' ? '' : normalize_phone($phoneRaw);
        $addr = clean($_POST['address'] ?? '', 400); $area = clean($_POST['area'] ?? '', 120); $city = clean($_POST['city'] ?? '', 80); $zip = clean($_POST['postal_code'] ?? '', 12);
        $n = (int) $pdo->query("SELECT COUNT(*) FROM addresses WHERE customer_id = $cid")->fetchColumn();
        if (mb_strlen($addr) < 6 || $city === '') flash_set('err', 'Please enter the street address and city.');
        elseif ($phoneRaw !== '' && $phone === '') flash_set('err', 'Enter a valid phone number (e.g. 0300 1234567).');
        elseif (!$aid && $n >= 10) flash_set('err', 'You can save up to 10 addresses.');
        else {
            if ($aid) $pdo->prepare('UPDATE addresses SET label=?, recipient=?, phone=?, address=?, area=?, city=?, postal_code=? WHERE id=? AND customer_id=?')->execute([$label, $rec, $phone, $addr, $area, $city, $zip, $aid, $cid]);
            else { $pdo->prepare('INSERT INTO addresses (customer_id,label,recipient,phone,address,area,city,postal_code,is_default) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$cid, $label, $rec, $phone, $addr, $area, $city, $zip, $n === 0 ? 1 : 0]); $aid = (int) $pdo->lastInsertId(); }
            if (!empty($_POST['make_default'])) { $pdo->prepare('UPDATE addresses SET is_default = (id = ?) WHERE customer_id = ?')->execute([$aid, $cid]); }
            sync_default($pdo, $cid); flash_set('ok', 'Address saved.'); redirect('addresses.php');
        }
        redirect('addresses.php?' . ($aid ? 'edit=' . $aid : 'new=1'));
    } elseif (isset($_POST['default'])) {
        $pdo->prepare('UPDATE addresses SET is_default = (id = ?) WHERE customer_id = ?')->execute([(int) $_POST['default'], $cid]); sync_default($pdo, $cid); flash_set('ok', 'Default address updated – checkout will use it.');
    } elseif (isset($_POST['del'])) {
        $pdo->prepare('DELETE FROM addresses WHERE id = ? AND customer_id = ?')->execute([(int) $_POST['del'], $cid]);
        if (!$pdo->query("SELECT 1 FROM addresses WHERE customer_id = $cid AND is_default = 1")->fetch()) { $pdo->exec("UPDATE addresses SET is_default = 1 WHERE customer_id = $cid ORDER BY id LIMIT 1"); sync_default($pdo, $cid); }
        flash_set('ok', 'Address removed.');
    }
    redirect('addresses.php');
}
$st = $pdo->prepare('SELECT * FROM addresses WHERE customer_id = ? ORDER BY is_default DESC, id'); $st->execute([$cid]); $rows = $st->fetchAll();
$form = null;
if ($edit) foreach ($rows as $r) if ((int) $r['id'] === $edit) $form = $r;
if ($new) $form = ['id' => 0, 'label' => $rows ? '' : 'Home', 'recipient' => $c['full_name'], 'phone' => $c['phone'], 'address' => '', 'area' => '', 'city' => $c['city'], 'postal_code' => '', 'is_default' => $rows ? 0 : 1];
customer_head('Address book', 'addresses', ['sub' => 'Save the places you get deliveries. Your default address pre-fills checkout.', 'actions' => '<a class="pbtn" href="addresses.php?new=1"><i class="bi bi-plus-lg"></i>Add address</a>']); ?>
<?php if ($form): ?><form method="post" class="pcard" style="margin-bottom:24px"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="aid" value="<?= (int) $form['id'] ?>">
 <h4><span><i class="bi bi-geo-alt"></i><?= $form['id'] ? 'Edit address' : 'New address' ?></span><a href="addresses.php">Cancel</a></h4>
 <div class="formgrid three"><div class="fld"><label>Label</label><input name="label" list="lbls" maxlength="40" value="<?= h($form['label']) ?>" placeholder="Home, Office…"><datalist id="lbls"><option value="Home"><option value="Office"><option value="Parents"></datalist></div>
  <div class="fld"><label>Receiver name</label><input name="recipient" maxlength="120" value="<?= h($form['recipient']) ?>"></div><div class="fld"><label>Phone</label><input name="phone" maxlength="30" value="<?= h($form['phone']) ?>" placeholder="0300 1234567"></div>
  <div class="fld" style="grid-column:1/-1"><label>Street address</label><input name="address" required maxlength="400" value="<?= h($form['address']) ?>"></div>
  <div class="fld"><label>Area</label><input name="area" maxlength="120" value="<?= h($form['area']) ?>"></div><div class="fld"><label>City</label><input name="city" required maxlength="80" value="<?= h($form['city']) ?>"></div>
  <div class="fld"><label>Postal code</label><input name="postal_code" maxlength="12" value="<?= h($form['postal_code']) ?>"></div></div>
 <label class="checkrow"><input type="checkbox" name="make_default" value="1" <?= $form['is_default'] ? 'checked' : '' ?>> Use as my default delivery address</label>
 <button name="save" value="1"><i class="bi bi-check2-circle"></i>Save address</button></form><?php endif; ?>
<?php if ($rows): ?><form method="post"><input type="hidden" name="csrf" value="<?= csrf() ?>"><div class="addr-grid"><?php foreach ($rows as $a): ?>
 <div class="addr <?= $a['is_default'] ? 'def' : '' ?>"><h5><i class="bi bi-<?= stripos($a['label'], 'office') !== false ? 'building' : 'house-heart' ?>"></i><?= h($a['label']) ?> <?= $a['is_default'] ? '<span class="chip">Default</span>' : '' ?></h5>
  <p><b><?= h($a['recipient']) ?></b><?= $a['phone'] ? ' · ' . h($a['phone']) : '' ?></p><p><?= h($a['address']) ?><?= $a['area'] ? ', ' . h($a['area']) : '' ?><br><?= h($a['city']) ?> <?= h($a['postal_code']) ?></p>
  <div class="acts"><a class="pbtn ghost sm" href="addresses.php?edit=<?= $a['id'] ?>"><i class="bi bi-pencil"></i>Edit</a>
   <?php if (!$a['is_default']): ?><button class="sm ghostb" name="default" value="<?= $a['id'] ?>">Make default</button><?php endif; ?>
   <button class="ibtn bad" name="del" value="<?= $a['id'] ?>" data-confirm="Remove this address?" data-confirm-yes="Remove" title="Remove"><i class="bi bi-trash3"></i></button></div></div><?php endforeach; ?></div></form>
<?php elseif (!$form): ?><div class="pcard"><div class="empty"><i class="bi bi-geo-alt"></i>No saved addresses yet.<br><a href="addresses.php?new=1">Add your first address →</a></div></div><?php endif; ?>
<?php customer_foot();
