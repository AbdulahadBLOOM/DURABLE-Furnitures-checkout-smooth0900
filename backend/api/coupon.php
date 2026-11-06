<?php
/** Live coupon preview for the checkout page. place_order.php re-validates; this never changes any data. */
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'POST only'], 405);
$in = json_body(); $pdo = db();
$rl = $pdo->prepare("SELECT COUNT(*) FROM auth_attempts WHERE ip = ? AND kind = 'coupon' AND created_at > (NOW() - INTERVAL 10 MINUTE)");
try { $rl->execute([client_ip()]); if ((int) $rl->fetchColumn() > 30) json_out(['ok' => false, 'error' => 'Too many attempts. Please wait a few minutes.'], 429);
      $pdo->prepare("INSERT INTO auth_attempts (ip, kind) VALUES (?, 'coupon')")->execute([client_ip()]); } catch (Throwable $e) { /* limiter is best-effort */ }
$subtotal = 0; $find = $pdo->prepare('SELECT price FROM products WHERE name = ? AND price = ? AND active = 1 LIMIT 1');
foreach (array_slice((array) ($in['items'] ?? []), 0, 40) as $it) {
    $p = parse_price($it['price'] ?? ''); $q = max(0, min((int) cfg('max_qty_per_item'), (int) ($it['qty'] ?? 0)));
    $find->execute([clean($it['name'] ?? '', 160), $p]); if ($find->fetch()) $subtotal += $p * $q;
}
$r = coupon_evaluate((string) ($in['code'] ?? ''), $subtotal);
if (!$r['ok']) json_out(['ok' => false, 'error' => $r['error']], 200);
$c = $r['coupon'];
json_out(['ok' => true, 'code' => $c['code'], 'discount' => $r['discount'], 'label' => $c['type'] === 'percent' ? (int) $c['value'] . '% off' : money($c['value']) . ' off']);
