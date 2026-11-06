<?php
require __DIR__ . '/_bootstrap.php';
$in = $_SERVER['REQUEST_METHOD'] === 'POST' ? json_body() : $_GET;
$no = strtoupper(clean($in['order_number'] ?? '', 24));
$ph = normalize_phone((string) ($in['phone'] ?? ''));
if ($no === '' || $ph === '') json_out(['ok' => false, 'error' => 'Enter your order number and the phone number used for the order.'], 422);
usleep(250000); // slow down guessing
$o = load_order('order_number = ? AND phone = ?', [$no, $ph]);
if (!$o) json_out(['ok' => false, 'error' => 'No order found with those details. Check the order number and phone.'], 404);
$tk = $o['access_token']; unset($o['access_token']);
$o['invoice_link'] = 'order-success.html?o=' . urlencode($o['order_number']) . '&t=' . $tk;
json_out(['ok' => true, 'order' => $o]);
