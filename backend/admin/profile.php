<?php
require __DIR__ . '/_auth.php'; admin_required(); $pdo = db(); $A = $GLOBALS['ADMIN'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') { csrf_check();
    if (isset($_POST['save_profile'])) {
        $name = clean($_POST['full_name'] ?? '', 120); $email = strtolower(clean($_POST['email'] ?? '', 160));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) flash_set('err', 'Enter a valid email address.');
        else { try { $pdo->prepare('UPDATE admins SET full_name = ?, email = ? WHERE id = ?')->execute([$name, $email ?: null, $A['id']]); log_activity('profile_update', $A['username']); flash_set('ok', 'Profile saved.'); }
               catch (PDOException $e) { flash_set('err', 'That email is already used by another admin.'); } }
    } elseif (isset($_POST['change_password'])) {
        $st = $pdo->prepare('SELECT password_hash FROM admins WHERE id = ?'); $st->execute([$A['id']]); $new = (string) ($_POST['new_password'] ?? '');
        if (!password_verify((string) ($_POST['current_password'] ?? ''), (string) $st->fetchColumn())) flash_set('err', 'Your current password is wrong.');
        elseif ($m = valid_password($new)) flash_set('err', $m);
        elseif ($new !== (string) ($_POST['confirm_password'] ?? '')) flash_set('err', 'The new passwords do not match.');
        else { $pdo->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $A['id']]); session_regenerate_id(true); log_activity('password_change', $A['username']); flash_set('ok', 'Password changed.'); }
    }
    redirect('profile.php');
}
$A = $pdo->query('SELECT * FROM admins WHERE id = ' . (int) $A['id'])->fetch();
$mine = $pdo->prepare('SELECT action, target, created_at FROM activity_log WHERE actor_type = ? AND actor = ? ORDER BY id DESC LIMIT 8'); $mine->execute(['admin', $A['username']]); $recent = $mine->fetchAll();
admin_head('My profile', 'profile', ['sub' => 'Your staff account and password.']); ?>
<div class="cols even">
 <form method="post" class="pcard"><input type="hidden" name="csrf" value="<?= csrf() ?>"><h4><span><i class="bi bi-person-gear"></i>Profile</span></h4>
  <div class="fld"><label>Username</label><input value="<?= h($A['username']) ?>" disabled></div>
  <div class="fld"><label>Full name</label><input name="full_name" maxlength="120" value="<?= h($A['full_name']) ?>"></div>
  <div class="fld"><label>Email</label><input type="email" name="email" maxlength="160" value="<?= h($A['email'] ?? '') ?>"></div>
  <p class="mut" style="font-size:.82rem">Last sign-in: <?= h(dt($A['last_login'])) ?> · Member since <?= h(dt($A['created_at'])) ?></p>
  <button name="save_profile" value="1">Save profile</button></form>
 <form method="post" class="pcard" autocomplete="off"><input type="hidden" name="csrf" value="<?= csrf() ?>"><h4><span><i class="bi bi-shield-lock"></i>Change password</span></h4>
  <div class="fld"><label>Current password</label><input type="password" name="current_password" required autocomplete="current-password"></div>
  <div class="fld"><label>New password</label><div class="pwrap"><input type="password" id="np" name="new_password" required minlength="8" autocomplete="new-password" data-strength="meter1"><button type="button" class="eye" data-eye="np" aria-label="Show"><i class="bi bi-eye"></i></button></div><div class="meter" id="meter1" data-s="0"><i></i><span></span></div></div>
  <div class="fld"><label>Confirm new password</label><input type="password" name="confirm_password" required minlength="8" autocomplete="new-password"></div>
  <button name="change_password" value="1">Update password</button></form></div>
<div class="pcard"><h4><span><i class="bi bi-clock-history"></i>Your recent actions</span><a href="activity.php">Full log</a></h4><ul class="people"><?php foreach ($recent as $r): ?><li><div><b><?= h(str_replace('_', ' ', $r['action'])) ?></b><small><?= h($r['target']) ?></small></div><small class="tm"><?= h(ago($r['created_at'])) ?></small></li><?php endforeach; if (!$recent) echo '<li class="mut">No actions logged yet.</li>'; ?></ul></div>
<?php admin_foot();
