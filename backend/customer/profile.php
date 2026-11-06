<?php
require __DIR__ . '/_auth.php'; $c = customer_required(); $pdo = db(); $cid = (int) $c['id'];

if (($_GET['export'] ?? '') === '1') {   // GDPR-style "download my data"
    $one = function (string $sql) use ($pdo, $cid) { $s = $pdo->prepare($sql); $s->execute([$cid]); return $s->fetchAll(); };
    $data = ['exported_at' => date('c'),
        'profile' => array_diff_key($c, ['password_hash' => 1]),
        'addresses' => $one('SELECT label, recipient, phone, address, area, city, postal_code, is_default FROM addresses WHERE customer_id = ?'),
        'orders' => $one('SELECT order_number, status, payment_status, payment_method, subtotal, discount, delivery_fee, total, address, city, created_at FROM orders WHERE customer_id = ? ORDER BY id'),
        'wishlist' => $one('SELECT p.brand, p.name, p.price FROM wishlist w JOIN products p ON p.id = w.product_id WHERE w.customer_id = ?'),
        'support_tickets' => $one('SELECT ticket_no, subject, category, status, created_at FROM tickets WHERE customer_id = ?')];
    log_activity('data_export', 'self', '', 'customer', (string) $cid);
    header('Content-Type: application/json'); header('Content-Disposition: attachment; filename="my-durable-furnitures-data.json"');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') { csrf_check();
    $tab = 'details';
    if (isset($_POST['save_profile'])) {
        $name = clean($_POST['full_name'] ?? '', 120); $phoneRaw = clean($_POST['phone'] ?? '', 30); $phone = $phoneRaw === '' ? '' : normalize_phone($phoneRaw);
        if (mb_strlen($name) < 3) flash_set('err', 'Please enter your full name.');
        elseif ($phoneRaw !== '' && $phone === '') flash_set('err', 'Enter a valid phone number (e.g. 0300 1234567).');
        else {
            $pdo->prepare('UPDATE customers SET full_name=?, phone=?, address=?, area=?, city=?, postal_code=? WHERE id=?')
                ->execute([$name, $phone, clean($_POST['address'] ?? '', 400), clean($_POST['area'] ?? '', 120), clean($_POST['city'] ?? '', 80), clean($_POST['postal_code'] ?? '', 12), $cid]);
            flash_set('ok', 'Profile saved.');
        }
    } elseif (isset($_POST['change_password'])) { $tab = 'security';
        $st = $pdo->prepare('SELECT password_hash FROM customers WHERE id = ?'); $st->execute([$cid]); $new = (string) ($_POST['new_password'] ?? '');
        if (!password_verify((string) ($_POST['current_password'] ?? ''), (string) $st->fetchColumn())) flash_set('err', 'Your current password is wrong.');
        elseif ($m = valid_password($new)) flash_set('err', $m);
        elseif ($new !== (string) ($_POST['confirm_password'] ?? '')) flash_set('err', 'The new passwords do not match.');
        else { $pdo->prepare('UPDATE customers SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $cid]); session_regenerate_id(true); log_activity('password_change', 'self', '', 'customer', (string) $cid); flash_set('ok', 'Password changed.'); }
    } elseif (isset($_POST['delete_account'])) { $tab = 'privacy';
        $st = $pdo->prepare('SELECT password_hash FROM customers WHERE id = ?'); $st->execute([$cid]);
        $active = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE customer_id = $cid AND status IN ('pending','confirmed','processing','shipped')")->fetchColumn();
        if (!password_verify((string) ($_POST['confirm_pw'] ?? ''), (string) $st->fetchColumn())) flash_set('err', 'Password incorrect – account not deleted.');
        elseif ($active) flash_set('err', 'You still have ' . $active . ' order(s) in progress. Please wait until they are delivered or cancel them first.');
        else {
            log_activity('account_delete', $c['email'], '', 'customer', (string) $cid);
            $pdo->prepare('DELETE FROM customers WHERE id = ?')->execute([$cid]);   // orders stay (as guest orders) for our legal / accounting records
            $_SESSION = []; header('Location: ../../index.html'); exit;
        }
    }
    redirect('profile.php#' . $tab);
}
$stats = $pdo->query("SELECT COUNT(*) n FROM orders WHERE customer_id = $cid")->fetch();
customer_head('Profile & security', 'profile', ['sub' => 'Manage your details, password and privacy.']); ?>
<div class="tabs" data-tabs><button type="button" class="tab" data-tab="details"><i class="bi bi-person"></i> Details</button><button type="button" class="tab" data-tab="security"><i class="bi bi-shield-lock"></i> Security</button><button type="button" class="tab" data-tab="privacy"><i class="bi bi-lock"></i> Data &amp; privacy</button></div>

<section data-pane="details"><div class="cols even">
 <form method="post" class="pcard"><input type="hidden" name="csrf" value="<?= csrf() ?>"><h4>Your details</h4>
  <div class="formgrid"><div class="fld full" style="grid-column:1/-1"><label>Full name</label><input name="full_name" value="<?= h($c['full_name']) ?>" required maxlength="120"></div>
   <div class="fld full" style="grid-column:1/-1"><label>Email (sign-in)</label><input value="<?= h($c['email']) ?>" disabled></div>
   <div class="fld"><label>Phone</label><input name="phone" value="<?= h($c['phone']) ?>" placeholder="0300 1234567"></div><div class="fld"><label>City</label><input name="city" value="<?= h($c['city']) ?>"></div>
   <div class="fld" style="grid-column:1/-1"><label>Delivery address</label><input name="address" value="<?= h($c['address']) ?>" maxlength="400"></div>
   <div class="fld"><label>Area</label><input name="area" value="<?= h($c['area']) ?>"></div><div class="fld"><label>Postal code</label><input name="postal_code" value="<?= h($c['postal_code']) ?>" maxlength="12"></div></div>
  <button name="save_profile" value="1">Save profile</button></form>
 <div class="pcard kv"><h4>Account</h4><div><span>Member since</span><span><?= h(dt($c['created_at'])) ?></span></div><div><span>Last sign-in</span><span><?= h(dt($c['last_login'])) ?></span></div><div><span>Orders</span><span><?= (int) $stats['n'] ?></span></div>
  <p class="mut" style="font-size:.84rem;margin:14px 0 0">Need more than one address? Use the <a href="addresses.php">address book</a> – your default address pre-fills checkout.</p></div></div></section>

<section data-pane="security" hidden><div class="cols even">
 <form method="post" class="pcard" autocomplete="off"><input type="hidden" name="csrf" value="<?= csrf() ?>"><h4>Change password</h4>
  <div class="fld"><label>Current password</label><input type="password" name="current_password" required autocomplete="current-password"></div>
  <div class="fld"><label>New password</label><div class="pwrap"><input type="password" id="np" name="new_password" required minlength="8" autocomplete="new-password" data-strength="meter1"><button type="button" class="eye" data-eye="np" aria-label="Show password"><i class="bi bi-eye"></i></button></div><div class="meter" id="meter1" data-s="0"><i></i><span></span></div><small>At least 8 characters with a letter and a number.</small></div>
  <div class="fld"><label>Confirm new password</label><input type="password" name="confirm_password" required minlength="8" autocomplete="new-password"></div>
  <button name="change_password" value="1">Update password</button></form>
 <div class="pcard"><h4>Sign-in activity</h4><ul class="people"><li><div><b>This device</b><small>Signed in now</small></div><span class="chip ok">Active</span></li><li><div><b>Previous sign-in</b><small><?= h(dt($c['last_login'])) ?></small></div></li></ul>
  <p class="mut" style="font-size:.84rem">If you do not recognise a sign-in, change your password straight away.</p><a class="pbtn ghost" href="logout.php"><i class="bi bi-box-arrow-right"></i>Sign out</a></div></div></section>

<section data-pane="privacy" hidden><div class="cols even">
 <div class="pcard"><h4>Download my data</h4><p class="mut" style="font-size:.9rem">Get a copy of your profile, addresses, orders, wishlist and support history as a JSON file.</p><a class="pbtn" href="profile.php?export=1"><i class="bi bi-download"></i>Download my data</a></div>
 <form method="post" class="pcard danger-zone"><input type="hidden" name="csrf" value="<?= csrf() ?>"><h4>Delete my account</h4>
  <p class="mut" style="font-size:.9rem">Your profile, saved addresses, wishlist and support requests are removed. Past orders are kept by the store for accounting, without a link to an account.</p>
  <div class="fld"><label>Confirm with your password</label><input type="password" name="confirm_pw" required autocomplete="current-password"></div>
  <button class="danger" name="delete_account" value="1" data-confirm="Delete your account permanently? This cannot be undone." data-confirm-title="Delete account" data-confirm-yes="Delete my account">Delete account</button></form></div></section>
<?php customer_foot();
