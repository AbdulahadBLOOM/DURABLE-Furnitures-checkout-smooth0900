<?php
/** Who is signed in? Used by the site header, checkout prefill and the account page. */
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../session.php';

$out = ['ok' => true, 'customer' => null, 'admin' => null, 'admin_signup' => 'closed'];

$cid = (int) (sess_peek('customer')['customer_id'] ?? 0);
if ($cid) {
    $st = db()->prepare('SELECT full_name, email, phone, address, area, city, postal_code FROM customers WHERE id = ? AND is_active = 1');
    $st->execute([$cid]);
    if ($c = $st->fetch()) {
        $out['customer'] = $c;
        $ad = db()->prepare('SELECT id, label, recipient, phone, address, area, city, postal_code, is_default FROM addresses WHERE customer_id = ? ORDER BY is_default DESC, id');
        $ad->execute([$cid]); $out['customer']['addresses'] = $ad->fetchAll();
    }
}
$au = (string) (sess_peek('admin')['admin'] ?? '');
if ($au !== '') {
    $st = db()->prepare('SELECT username, full_name FROM admins WHERE username = ?');
    $st->execute([$au]);
    if ($a = $st->fetch()) $out['admin'] = ['username' => $a['username'], 'name' => $a['full_name'] ?: $a['username']];
}
$out['admin_signup'] = admin_signup_mode();
header('Cache-Control: no-store');
json_out($out);
