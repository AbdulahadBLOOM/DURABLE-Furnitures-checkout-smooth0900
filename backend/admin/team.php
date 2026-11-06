<?php
require __DIR__ . '/_auth.php'; admin_required(); $pdo = db(); $me = $GLOBALS['ADMIN'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') { csrf_check();
    if (isset($_POST['add'])) {
        $u = strtolower(preg_replace('/[^A-Za-z0-9_.-]/', '', (string) ($_POST['username'] ?? ''))); $n = clean($_POST['full_name'] ?? '', 120); $em = strtolower(clean($_POST['email'] ?? '', 160)); $pw = (string) ($_POST['password'] ?? '');
        if (strlen($u) < 3) flash_set('err', 'Username must be at least 3 characters (letters, digits, . _ -).');
        elseif ($em !== '' && !filter_var($em, FILTER_VALIDATE_EMAIL)) flash_set('err', 'Enter a valid email.');
        elseif ($m = valid_password($pw)) flash_set('err', $m);
        else { try { $pdo->prepare('INSERT INTO admins (username, full_name, email, password_hash) VALUES (?,?,?,?)')->execute([$u, $n, $em ?: null, password_hash($pw, PASSWORD_DEFAULT)]); log_activity('admin_add', $u); flash_set('ok', "Admin “$u” created. Share the password with them securely."); }
               catch (PDOException $e) { flash_set('err', 'That username or email is already taken.'); } }
    } elseif (isset($_POST['reset'])) {
        $id = (int) $_POST['reset']; $pw = (string) ($_POST['new_password_' . $id] ?? '');
        if ($id === (int) $me['id']) flash_set('err', 'Change your own password from My profile.');
        elseif ($m = valid_password($pw)) flash_set('err', $m);
        else { $pdo->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), $id]); log_activity('admin_password_reset', '#' . $id); flash_set('ok', 'Password reset.'); }
    } elseif (isset($_POST['remove'])) {
        $id = (int) $_POST['remove'];
        if ($id === (int) $me['id']) flash_set('err', 'You cannot remove your own account.');
        elseif (admin_count() <= 1) flash_set('err', 'At least one admin must remain.');
        else { $pdo->prepare('DELETE FROM admins WHERE id = ?')->execute([$id]); log_activity('admin_remove', '#' . $id); flash_set('ok', 'Admin removed.'); }
    }
    redirect('team.php');
}
$rows = $pdo->query('SELECT * FROM admins ORDER BY id')->fetchAll();
admin_head('Team', 'team', ['sub' => 'Everyone who can sign in to this admin panel.']); ?>
<div class="cols">
 <form method="post" id="tf"><input type="hidden" name="csrf" value="<?= csrf() ?>"></form>
 <div class="tablewrap"><table><tr><th>Admin</th><th>Email</th><th>Last sign-in</th><th>Reset password</th><th></th></tr>
 <?php foreach ($rows as $r): $self = (int) $r['id'] === (int) $me['id']; ?><tr>
  <td><div class="who-cell"><span class="avatar sm"><?= h(initials($r['full_name'] ?: $r['username'])) ?></span><div><b><?= h($r['full_name'] ?: $r['username']) ?></b> <?= $self ? '<span class="chip">you</span>' : '' ?><br><small class="mut">@<?= h($r['username']) ?></small></div></div></td>
  <td><small><?= h($r['email'] ?: '—') ?></small></td><td class="nw"><small><?= $r['last_login'] ? h(ago($r['last_login'])) : 'never' ?></small></td>
  <td><?php if (!$self): ?><div class="inline-form"><input form="tf" type="password" name="new_password_<?= $r['id'] ?>" placeholder="New password" autocomplete="new-password" style="max-width:160px;padding:8px 10px"><button form="tf" class="sm ghostb" name="reset" value="<?= $r['id'] ?>">Reset</button></div><?php else: ?><small class="mut">—</small><?php endif; ?></td>
  <td class="r"><?php if (!$self): ?><button form="tf" class="ibtn bad" name="remove" value="<?= $r['id'] ?>" data-confirm="Remove admin <?= h($r['username']) ?>? They will lose access immediately." data-confirm-yes="Remove" title="Remove"><i class="bi bi-person-x"></i></button><?php endif; ?></td></tr>
 <?php endforeach; ?></table></div>
 <form method="post" class="pcard" autocomplete="off"><input type="hidden" name="csrf" value="<?= csrf() ?>"><h4><span><i class="bi bi-person-plus"></i>Add admin</span></h4>
  <div class="fld"><label>Username</label><input name="username" required minlength="3" maxlength="60"></div><div class="fld"><label>Full name</label><input name="full_name" maxlength="120"></div>
  <div class="fld"><label>Email</label><input type="email" name="email" maxlength="160"></div>
  <div class="fld"><label>Password</label><div class="pwrap"><input type="password" id="tp" name="password" required minlength="8" autocomplete="new-password" data-strength="meter2"><button type="button" class="eye" data-eye="tp" aria-label="Show"><i class="bi bi-eye"></i></button></div><div class="meter" id="meter2" data-s="0"><i></i><span></span></div></div>
  <button name="add" value="1" style="width:100%"><i class="bi bi-plus-lg"></i>Create admin</button></form></div>
<?php admin_foot();
