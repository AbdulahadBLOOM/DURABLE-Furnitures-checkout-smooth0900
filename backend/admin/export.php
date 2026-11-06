<?php
require __DIR__ . '/_auth.php'; admin_required(); $pdo = db();
$type = (string) ($_GET['type'] ?? 'orders'); if (!in_array($type, ['orders', 'customers', 'messages', 'products', 'coupons'], true)) $type = 'orders';
$stamp = date('Ymd-His');
log_activity('export', $type);

if ($type === 'customers') {
    $rows = $pdo->query("SELECT c.id, c.full_name, c.email, c.phone, c.city, c.area, c.address, c.is_active, c.created_at, c.last_login, COUNT(o.id) orders,
        COALESCE(SUM(CASE WHEN o.status<>'cancelled' THEN o.total END),0) spent FROM customers c LEFT JOIN orders o ON o.customer_id = c.id GROUP BY c.id ORDER BY c.id DESC")->fetchAll(PDO::FETCH_NUM);
    csv_stream("customers-$stamp.csv", ['ID', 'Name', 'Email', 'Phone', 'City', 'Area', 'Address', 'Active', 'Joined', 'Last sign-in', 'Orders', 'Total spent'], $rows);
}
if ($type === 'messages') {
    $rows = $pdo->query('SELECT id, created_at, name, email, phone, subject, message, is_read, replied_at FROM contact_messages ORDER BY id DESC')->fetchAll(PDO::FETCH_NUM);
    csv_stream("messages-$stamp.csv", ['ID', 'Date', 'Name', 'Email', 'Phone', 'Subject', 'Message', 'Read', 'Replied at'], $rows);
}
if ($type === 'products') {
    $rows = $pdo->query("SELECT p.id, p.brand, p.name, p.price, p.active, COALESCE(SUM(CASE WHEN o.status<>'cancelled' THEN oi.qty END),0) sold,
        COALESCE(SUM(CASE WHEN o.status<>'cancelled' THEN oi.line_total END),0) revenue FROM products p LEFT JOIN order_items oi ON oi.product_id = p.id LEFT JOIN orders o ON o.id = oi.order_id GROUP BY p.id ORDER BY p.brand, p.name")->fetchAll(PDO::FETCH_NUM);
    csv_stream("products-$stamp.csv", ['ID', 'Brand', 'Name', 'Price', 'Active', 'Units sold', 'Revenue'], $rows);
}
if ($type === 'coupons') {
    $rows = $pdo->query('SELECT code, type, value, min_subtotal, max_discount, usage_limit, used_count, starts_at, expires_at, active FROM coupons ORDER BY id DESC')->fetchAll(PDO::FETCH_NUM);
    csv_stream("coupons-$stamp.csv", ['Code', 'Type', 'Value', 'Min subtotal', 'Max discount', 'Usage limit', 'Used', 'Starts', 'Expires', 'Active'], $rows);
}

/* orders (honours the same filters as the Orders page) */
$F = order_filter($_GET);
$st = $pdo->prepare("SELECT o.* FROM orders o {$F['where']} ORDER BY {$F['order']}"); $st->execute($F['args']); $orders = $st->fetchAll();
$items = [];
foreach ($pdo->query('SELECT order_id, name, brand, unit_price, qty, line_total FROM order_items ORDER BY id') as $r) $items[$r['order_id']][] = $r;
if (($_GET['format'] ?? 'csv') === 'json') {
    foreach ($orders as &$o) { $o['items'] = $items[$o['id']] ?? []; unset($o['access_token'], $o['request_key']); }
    header('Content-Type: application/json'); header("Content-Disposition: attachment; filename=orders-$stamp.json");
    echo json_encode($orders, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE); exit;
}
$rows = (function () use ($orders, $items) {
    foreach ($orders as $o) {
        $list = implode(' | ', array_map(fn($i) => "{$i['name']} x{$i['qty']}", $items[$o['id']] ?? []));
        yield [$o['order_number'], $o['created_at'], $o['status'], $o['payment_method'], $o['payment_status'], $o['customer_name'], $o['phone'], $o['email'], $o['address'], $o['area'], $o['city'], $o['postal_code'],
               $o['notes'], $list, $o['subtotal'], $o['discount'], $o['coupon_code'], $o['delivery_fee'], $o['total'], $o['courier'], $o['tracking_no']];
    }
})();
csv_stream("orders-$stamp.csv", ['Order', 'Date', 'Status', 'Payment', 'Payment status', 'Name', 'Phone', 'Email', 'Address', 'Area', 'City', 'Postal', 'Customer note', 'Items', 'Subtotal', 'Discount', 'Coupon', 'Delivery', 'Total', 'Courier', 'Tracking'], $rows);
