<?php
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../session.php';
require_once __DIR__ . '/../lib.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'POST only'], 405);

$in = json_body();
if (!empty($in['website'])) json_out(['ok' => false, 'error' => 'Rejected'], 400);   // honeypot

$pdo = db();
$c   = $in['customer'] ?? [];
$errors = [];

// ── Customer validation
$name   = clean($c['name'] ?? '', 120);
$phone  = normalize_phone((string) ($c['phone'] ?? ''));
$email  = clean($c['email'] ?? '', 160);
$addr   = clean($c['address'] ?? '', 400);
$area   = clean($c['area'] ?? '', 120);
$city   = clean($c['city'] ?? '', 80);
$postal = clean($c['postal'] ?? '', 12);
$notes  = clean($c['notes'] ?? '', 1000);
$pay    = (string) ($in['payment_method'] ?? '');

if (mb_strlen($name) < 3)                       $errors['name']    = 'Please enter your full name.';
if ($phone === '')                              $errors['phone']   = 'Enter a valid phone number (e.g. 0300 1234567).';
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email address.';
if (mb_strlen($addr) < 8)                       $errors['address'] = 'Please enter your full delivery address.';
if (mb_strlen($city) < 2)                       $errors['city']    = 'Please enter your city.';
if (!isset(cfg('payment_methods')[$pay]))       $errors['payment_method'] = 'Choose a payment method.';
if ($errors) json_out(['ok' => false, 'error' => 'Please fix the highlighted fields.', 'fields' => $errors], 422);

// ── Idempotency: same request key returns the existing order (double-click / retry safe)
$rk = preg_replace('/[^A-Za-z0-9\-]/', '', (string) ($in['request_key'] ?? ''));
$rk = $rk !== '' ? substr($rk, 0, 64) : null;
if ($rk) {
    $ex = $pdo->prepare('SELECT order_number, access_token FROM orders WHERE request_key = ?');
    $ex->execute([$rk]);
    if ($row = $ex->fetch()) {
        json_out(['ok' => true, 'duplicate' => true, 'order_number' => $row['order_number'],
                  'redirect' => 'order-success.html?o=' . urlencode($row['order_number']) . '&t=' . $row['access_token']]);
    }
}

// ── Rate limit
$rl = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE ip = ? AND created_at > (NOW() - INTERVAL 10 MINUTE)');
$rl->execute([client_ip()]);
if ((int) $rl->fetchColumn() >= (int) cfg('orders_per_ip_per_10min'))
    json_out(['ok' => false, 'error' => 'Too many orders from your connection. Please wait a few minutes or call us.'], 429);

// ── Items: re-priced on the server from the products table (never trust browser prices)
$cart = $in['items'] ?? [];
if (!is_array($cart) || !$cart) json_out(['ok' => false, 'error' => 'Your cart is empty.'], 422);
if (count($cart) > 40)          json_out(['ok' => false, 'error' => 'Too many different items in one order.'], 422);

$find = $pdo->prepare('SELECT id, brand, name, price, image FROM products WHERE name = ? AND price = ? AND active = 1 LIMIT 1');
$lines = []; $subtotal = 0; $bad = [];
foreach ($cart as $it) {
    $pname = clean($it['name'] ?? '', 160);
    $pprice = parse_price($it['price'] ?? '');
    $qty = (int) ($it['qty'] ?? 0);
    if ($qty < 1 || $qty > cfg('max_qty_per_item')) { $bad[] = "$pname (quantity must be 1–" . cfg('max_qty_per_item') . ')'; continue; }
    $find->execute([$pname, $pprice]);
    $p = $find->fetch();
    if (!$p) { $bad[] = $pname; continue; }
    $img = clean($it['img'] ?? '', 500);
    if ($img === '' || !preg_match('#^https://#i', $img)) $img = $p['image'];
    $lt = $p['price'] * $qty;
    $subtotal += $lt;
    $lines[] = ['pid' => $p['id'], 'brand' => $p['brand'], 'name' => $p['name'], 'image' => $img,
                'price' => (int) $p['price'], 'qty' => $qty, 'total' => $lt];
}
if ($bad) json_out(['ok' => false, 'code' => 'bad_items',
    'error' => 'Some items are unavailable or changed price: ' . implode(', ', $bad) . '. Please remove them from your cart and try again.',
    'items' => $bad], 422);

$delivery = (int) cfg('delivery_fee');
if ((int) cfg('free_delivery_over') > 0 && $subtotal >= (int) cfg('free_delivery_over')) $delivery = 0;

// ── Coupon (validated again here – the browser's preview is never trusted)
$couponCode = ''; $discount = 0;
$rawCoupon = clean($in['coupon'] ?? '', 40);
if ($rawCoupon !== '') {
    $cv = coupon_evaluate($rawCoupon, $subtotal);
    if (!$cv['ok']) json_out(['ok' => false, 'code' => 'bad_coupon', 'error' => $cv['error']], 422);
    $couponCode = $cv['coupon']['code']; $discount = $cv['discount'];
}
$total = $subtotal - $discount + $delivery;

// ── Save (transaction)
$token = bin2hex(random_bytes(16));
$customerId = customer_id_from_cookie();   // links the order to the signed-in customer's account (null for guests)
try {
    $pdo->beginTransaction();
    $orderNo = new_order_number($pdo);
    $pdo->prepare('INSERT INTO orders
        (order_number, access_token, customer_id, request_key, status, payment_method, payment_status, customer_name, phone, email,
         address, area, city, postal_code, notes, subtotal, discount, coupon_code, delivery_fee, total, ip, user_agent)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$orderNo, $token, $customerId, $rk, 'pending', $pay, 'unpaid', $name, $phone, $email,
                   $addr, $area, $city, $postal, $notes, $subtotal, $discount, $couponCode, $delivery, $total,
                   client_ip(), clean($_SERVER['HTTP_USER_AGENT'] ?? '', 255)]);
    $oid = (int) $pdo->lastInsertId();
    if ($couponCode !== '') {   // atomic redemption: fails (0 rows) if the usage limit was just reached by someone else
        $u = $pdo->prepare('UPDATE coupons SET used_count = used_count + 1 WHERE code = ? AND (usage_limit = 0 OR used_count < usage_limit)');
        $u->execute([$couponCode]);
        if ($u->rowCount() !== 1) throw new RuntimeException('coupon_exhausted');
    }
    $ii = $pdo->prepare('INSERT INTO order_items (order_id, product_id, brand, name, image, unit_price, qty, line_total) VALUES (?,?,?,?,?,?,?,?)');
    foreach ($lines as $l) $ii->execute([$oid, $l['pid'], $l['brand'], $l['name'], $l['image'], $l['price'], $l['qty'], $l['total']]);
    $pdo->prepare('INSERT INTO order_status_history (order_id, status, note, changed_by) VALUES (?,?,?,?)')
        ->execute([$oid, 'pending', 'Order placed by customer', 'customer']);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($e instanceof RuntimeException && $e->getMessage() === 'coupon_exhausted')
        json_out(['ok' => false, 'code' => 'bad_coupon', 'error' => 'Sorry, this coupon has just been fully redeemed. Remove it and try again.'], 422);
    // Race on request_key (double submit): return the winner
    if ($rk && $e instanceof PDOException && ($e->errorInfo[1] ?? 0) == 1062) {
        $ex = $pdo->prepare('SELECT order_number, access_token FROM orders WHERE request_key = ?');
        $ex->execute([$rk]);
        if ($row = $ex->fetch())
            json_out(['ok' => true, 'duplicate' => true, 'order_number' => $row['order_number'],
                      'redirect' => 'order-success.html?o=' . urlencode($row['order_number']) . '&t=' . $row['access_token']]);
    }
    throw $e;
}

// ── Post-save: JSON backup + emails (failures must never break the order)
$order = load_order('id = ?', [$oid], false);
try {
    write_order_json($order);
    if ($email !== '')
        send_mail($email, "Order received – {$orderNo}", order_email_html($order, 'Thank you for your order! We will call you shortly to confirm delivery details.'));
    send_mail(cfg('store_email'), "New order {$orderNo} – " . money($total), order_email_html($order, '<b>New order received.</b>'));
} catch (Throwable $e) { error_log('[DF post-order] ' . $e->getMessage()); }

json_out(['ok' => true, 'order_number' => $orderNo, 'token' => $token, 'total' => $total,
          'redirect' => 'order-success.html?o=' . urlencode($orderNo) . '&t=' . $token]);
