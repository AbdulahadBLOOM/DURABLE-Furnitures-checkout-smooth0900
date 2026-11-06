<?php
require __DIR__ . '/_bootstrap.php';
$no = clean($_GET['o'] ?? '', 24);
$tk = preg_replace('/[^a-f0-9]/', '', (string) ($_GET['t'] ?? ''));
if ($no === '' || strlen($tk) !== 32) json_out(['ok' => false, 'error' => 'Invalid order link.'], 400);
$o = load_order('order_number = ?', [$no]);
if (!$o || !hash_equals($o['access_token'], $tk)) json_out(['ok' => false, 'error' => 'Order not found.'], 404);
unset($o['access_token']);
json_out(['ok' => true, 'order' => $o, 'store' => [
    'name' => cfg('store_name'), 'phone' => cfg('store_phone'), 'email' => cfg('store_email'), 'address' => cfg('store_address')]]);
