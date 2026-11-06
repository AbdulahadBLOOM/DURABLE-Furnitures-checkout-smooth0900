<?php
/** JSON for the Ctrl+K command palette. Admin only. */
require __DIR__ . '/_auth.php'; admin_required();
header('Content-Type: application/json; charset=utf-8');
$q = clean($_GET['q'] ?? '', 60);
if (mb_strlen($q) < 2) { echo json_encode(['groups' => []]); exit; }
$pdo = db(); $like = '%' . $q . '%'; $g = [];
$run = function (string $sql, int $n = 5) use ($pdo, $like) { $st = $pdo->prepare($sql); $st->execute(array_fill(0, substr_count($sql, '?'), $like)); return $st->fetchAll(); };

$r = $run('SELECT id, order_number, customer_name, total, status FROM orders WHERE order_number LIKE ? OR customer_name LIKE ? OR phone LIKE ? OR email LIKE ? OR tracking_no LIKE ? ORDER BY id DESC LIMIT 5');
if ($r) $g[] = ['title' => 'Orders', 'items' => array_map(fn($x) => ['url' => 'order.php?id=' . $x['id'], 'icon' => 'bi-bag-check', 'title' => $x['order_number'], 'sub' => $x['customer_name'] . ' · ' . money($x['total']), 'badge' => $x['status']], $r)];
$r = $run('SELECT id, full_name, email, phone, is_active FROM customers WHERE full_name LIKE ? OR email LIKE ? OR phone LIKE ? ORDER BY id DESC LIMIT 5');
if ($r) $g[] = ['title' => 'Customers', 'items' => array_map(fn($x) => ['url' => 'customer.php?id=' . $x['id'], 'icon' => 'bi-person', 'title' => $x['full_name'], 'sub' => $x['email'] . ($x['phone'] ? ' · ' . $x['phone'] : ''), 'badge' => $x['is_active'] ? '' : 'disabled'], $r)];
$r = $run('SELECT t.id, t.ticket_no, t.subject, t.status FROM tickets t WHERE t.ticket_no LIKE ? OR t.subject LIKE ? ORDER BY t.id DESC LIMIT 4');
if ($r) $g[] = ['title' => 'Support tickets', 'items' => array_map(fn($x) => ['url' => 'ticket.php?id=' . $x['id'], 'icon' => 'bi-life-preserver', 'title' => $x['subject'], 'sub' => $x['ticket_no'], 'badge' => $x['status']], $r)];
$r = $run('SELECT id, name, brand, price, active FROM products WHERE name LIKE ? OR brand LIKE ? ORDER BY name LIMIT 5');
if ($r) $g[] = ['title' => 'Products', 'items' => array_map(fn($x) => ['url' => 'products.php?q=' . urlencode($x['name']), 'icon' => 'bi-lamp', 'title' => $x['name'], 'sub' => $x['brand'] . ' · ' . money($x['price']), 'badge' => $x['active'] ? '' : 'hidden'], $r)];
$r = $run('SELECT id, name, subject, email FROM contact_messages WHERE name LIKE ? OR email LIKE ? OR subject LIKE ? OR message LIKE ? ORDER BY id DESC LIMIT 3');
if ($r) $g[] = ['title' => 'Messages', 'items' => array_map(fn($x) => ['url' => 'messages.php?q=' . urlencode($x['email']), 'icon' => 'bi-envelope', 'title' => $x['name'], 'sub' => $x['subject'] ?: $x['email'], 'badge' => ''], $r)];
echo json_encode(['groups' => $g], JSON_UNESCAPED_UNICODE);
