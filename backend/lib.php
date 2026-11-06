<?php
/**
 * Shared helpers for the advanced admin + customer panels:
 * flash messages, audit log, pagination, CSV, order updates, notifications, loyalty tiers, coupons.
 * Requires db.php (loaded by session.php).
 */
require_once __DIR__ . '/db.php';

/* ───────────── flash messages (post → redirect → get) ───────────── */
function flash_set(string $type, string $msg): void { $_SESSION['flash'][] = [$type, $msg]; }
function flash_take(): array { $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f; }
function redirect(string $url): void { header('Location: ' . $url); exit; }

/* ───────────── audit log ───────────── */
function log_activity(string $action, string $target = '', string $detail = '', string $type = 'admin', ?string $actor = null): void {
    try {
        $actor = $actor ?? (string) ($_SESSION['admin'] ?? ($_SESSION['customer_id'] ?? ''));
        db()->prepare('INSERT INTO activity_log (actor_type, actor, action, target, detail, ip) VALUES (?,?,?,?,?,?)')
            ->execute([$type, mb_substr($actor, 0, 80), mb_substr($action, 0, 60), mb_substr($target, 0, 80), mb_substr($detail, 0, 400), substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45)]);
        if (random_int(1, 80) === 1) db()->exec('DELETE FROM activity_log WHERE created_at < (NOW() - INTERVAL 180 DAY)');
    } catch (Throwable $e) { error_log('[DF log] ' . $e->getMessage()); }
}

/* ───────────── small helpers ───────────── */
function qs(array $over = [], array $base = null): string {
    $p = array_merge($base ?? $_GET, $over);
    $p = array_filter($p, fn($v) => $v !== '' && $v !== null);
    return '?' . http_build_query($p);
}
function pct_delta($cur, $prev): ?float { return $prev > 0 ? round(($cur - $prev) / $prev * 100, 1) : ($cur > 0 ? null : 0.0); }
function delta_badge($cur, $prev, bool $upIsGood = true): string {
    $d = pct_delta($cur, $prev);
    if ($d === null) return '<span class="delta new">new</span>';
    if ($d == 0) return '<span class="delta flat">— 0%</span>';
    $good = ($d > 0) === $upIsGood;
    return '<span class="delta ' . ($good ? 'up' : 'down') . '"><i class="bi bi-arrow-' . ($d > 0 ? 'up' : 'down') . '-short"></i>' . abs($d) . '%</span>';
}
function money_short($n): string {
    $n = (int) $n;
    if ($n >= 10000000) return cfg('currency') . round($n / 10000000, 2) . ' Cr';
    if ($n >= 100000)   return cfg('currency') . round($n / 100000, 2) . ' L';
    return money($n);
}
function valid_date(?string $d): string { return (is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d)) ? $d : ''; }
function sanitize_img(string $u): string { return preg_match('#^https://#i', $u) ? $u : ''; }

/** Page links with ellipsis. $params are the current query params (without p). */
function paginate(int $total, int $page, int $per, array $params = []): string {
    $pages = max(1, (int) ceil($total / $per));
    if ($pages < 2) return '';
    $url = fn(int $p) => '?' . http_build_query(array_filter(array_merge($params, ['p' => $p > 1 ? $p : null]), fn($v) => $v !== '' && $v !== null));
    $set = array_unique(array_filter([1, 2, $page - 1, $page, $page + 1, $pages - 1, $pages], fn($x) => $x >= 1 && $x <= $pages));
    sort($set);
    $o = '<nav class="pager" aria-label="Pages"><span class="pg-info">' . number_format(($page - 1) * $per + 1) . '–' . number_format(min($total, $page * $per)) . ' of ' . number_format($total) . '</span><div class="pg-links">';
    if ($page > 1) $o .= '<a href="' . h($url($page - 1)) . '" aria-label="Previous"><i class="bi bi-chevron-left"></i></a>';
    $prev = 0;
    foreach ($set as $p) {
        if ($prev && $p - $prev > 1) $o .= '<span class="gap">…</span>';
        $o .= '<a href="' . h($url($p)) . '" class="' . ($p === $page ? 'on' : '') . '">' . $p . '</a>'; $prev = $p;
    }
    if ($page < $pages) $o .= '<a href="' . h($url($page + 1)) . '" aria-label="Next"><i class="bi bi-chevron-right"></i></a>';
    return $o . '</div></nav>';
}

/** Sortable column header link. */
function sort_th(string $label, string $key, string $cur, string $class = ''): string {
    $asc = $cur === $key; $desc = $cur === $key . '_desc';
    $next = $asc ? $key . '_desc' : $key;
    $ic = $asc ? 'bi-sort-up' : ($desc ? 'bi-sort-down' : 'bi-arrow-down-up');
    return '<th class="' . h($class) . '"><a class="sortlink' . (($asc || $desc) ? ' on' : '') . '" href="' . h(qs(['sort' => $next, 'p' => ''])) . '">' . h($label) . ' <i class="bi ' . $ic . '"></i></a></th>';
}

/** Stream a CSV download (UTF-8 BOM for Excel, formula-injection guard). */
function csv_stream(string $name, array $header, iterable $rows): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '', $name) . '"');
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
    $safe = fn($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : $v;
    fputcsv($out, $header);
    foreach ($rows as $r) fputcsv($out, array_map($safe, $r));
    exit;
}

/* ───────────── loyalty tiers (edit thresholds in config.php) ───────────── */
function loyalty(int $spent): array {
    $tiers = cfg('loyalty_tiers') ?: [['Bronze', 0], ['Silver', 50000], ['Gold', 150000], ['Platinum', 400000]];
    $cur = $tiers[0]; $next = null;
    foreach ($tiers as $i => $t) if ($spent >= $t[1]) { $cur = $t; $next = $tiers[$i + 1] ?? null; }
    $pct = $next ? (int) min(100, round(($spent - $cur[1]) / max(1, $next[1] - $cur[1]) * 100)) : 100;
    return ['name' => $cur[0], 'next' => $next ? $next[0] : null, 'to_next' => $next ? max(0, $next[1] - $spent) : 0, 'pct' => $pct, 'idx' => array_search($cur, $tiers, true)];
}

/* ───────────── orders: one place that changes status / payment / tracking ───────────── */
function order_apply_update(int $id, array $set, string $note, string $actor, bool $email = true): ?array {
    $pdo = db();
    $cur = load_order('id = ?', [$id], false);
    if (!$cur) return null;
    $ns = $set['status'] ?? $cur['status'];       if (!in_array($ns, ORDER_STATUSES, true)) $ns = $cur['status'];
    $ps = $set['payment_status'] ?? $cur['payment_status']; if (!in_array($ps, PAYMENT_STATUSES, true)) $ps = $cur['payment_status'];
    $cols = ['status' => $ns, 'payment_status' => $ps];
    foreach (['admin_notes' => 2000, 'tracking_no' => 80, 'courier' => 60, 'cancel_reason' => 300] as $k => $max)
        if (array_key_exists($k, $set)) $cols[$k] = clean((string) $set[$k], $max);
    if (array_key_exists('eta', $set)) $cols['eta'] = valid_date((string) $set['eta']) ?: null;
    $sql = implode(', ', array_map(fn($c) => "$c = ?", array_keys($cols)));
    $pdo->prepare("UPDATE orders SET $sql WHERE id = ?")->execute([...array_values($cols), $id]);

    $note = clean($note, 400);
    $changedStatus = $ns !== $cur['status'];
    if ($changedStatus || $note !== '') $pdo->prepare('INSERT INTO order_status_history (order_id,status,note,changed_by) VALUES (?,?,?,?)')->execute([$id, $ns, $note, $actor]);
    if ($ps !== $cur['payment_status'])
        $pdo->prepare('INSERT INTO order_status_history (order_id,status,note,changed_by) VALUES (?,?,?,?)')->execute([$id, $ns, 'Payment marked ' . $ps, $actor]);
    $new = load_order('id = ?', [$id], false);
    write_order_json($new);
    if ($email && ($changedStatus || ($note !== '' && !empty($set['email_note']))) && $new['email'] !== '') {
        $extra = ($new['tracking_no'] !== '' ? '<br>Tracking: <b>' . h($new['courier'] ? $new['courier'] . ' – ' : '') . h($new['tracking_no']) . '</b>' : '')
               . ($new['eta'] ? '<br>Expected delivery: <b>' . h(date('d M Y', strtotime($new['eta']))) . '</b>' : '');
        send_mail($new['email'], "Order {$new['order_number']} is now " . ucfirst($ns),
            order_email_html($new, 'Your order status was updated to <b>' . h(ucfirst($ns)) . '</b>.' . ($note ? '<br>' . h($note) : '') . $extra));
    }
    return $new;
}

/* ───────────── coupons ───────────── */
/** Returns ['ok'=>bool,'discount'=>int,'coupon'=>row|null,'error'=>string]. */
function coupon_evaluate(string $code, int $subtotal): array {
    $code = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', $code));
    if ($code === '') return ['ok' => false, 'discount' => 0, 'coupon' => null, 'error' => 'Enter a coupon code.'];
    $st = db()->prepare('SELECT * FROM coupons WHERE code = ?'); $st->execute([$code]); $c = $st->fetch();
    $bad = fn(string $m) => ['ok' => false, 'discount' => 0, 'coupon' => null, 'error' => $m];
    if (!$c || !(int) $c['active'])                                   return $bad('This coupon code is not valid.');
    if ($c['starts_at'] && $c['starts_at'] > date('Y-m-d'))           return $bad('This coupon is not active yet.');
    if ($c['expires_at'] && $c['expires_at'] < date('Y-m-d'))         return $bad('This coupon has expired.');
    if ((int) $c['usage_limit'] > 0 && (int) $c['used_count'] >= (int) $c['usage_limit']) return $bad('This coupon has been fully redeemed.');
    if ($subtotal < (int) $c['min_subtotal'])                          return $bad('Add items worth ' . money($c['min_subtotal']) . ' or more to use this coupon.');
    $d = $c['type'] === 'percent' ? (int) floor($subtotal * min(100, (int) $c['value']) / 100) : (int) $c['value'];
    if ((int) $c['max_discount'] > 0) $d = min($d, (int) $c['max_discount']);
    $d = max(0, min($d, $subtotal));
    return ['ok' => true, 'discount' => $d, 'coupon' => $c, 'error' => ''];
}

/* ───────────── notifications ───────────── */
/** Customer notifications derived from real data: status changes made by staff, ticket replies. */
function customer_notifications(int $cid, int $limit = 10): array {
    $pdo = db(); $items = [];
    $seen = $pdo->prepare('SELECT notif_seen_at FROM customers WHERE id = ?'); $seen->execute([$cid]);
    $seenAt = $seen->fetchColumn() ?: '1970-01-01 00:00:00';
    $st = $pdo->prepare("SELECT h.status, h.note, h.created_at, o.id, o.order_number FROM order_status_history h JOIN orders o ON o.id = h.order_id
        WHERE o.customer_id = ? AND h.changed_by <> 'customer' ORDER BY h.id DESC LIMIT 20");
    $st->execute([$cid]);
    foreach ($st as $r) $items[] = ['t' => $r['created_at'], 'icon' => 'bi-box-seam', 'url' => 'order.php?id=' . $r['id'],
        'title' => 'Order ' . $r['order_number'] . ' · ' . ucfirst($r['status']), 'text' => $r['note'] ?: 'Your order status was updated.', 'new' => $r['created_at'] > $seenAt];
    $st = $pdo->prepare("SELECT t.id, t.ticket_no, t.subject, m.created_at FROM ticket_messages m JOIN tickets t ON t.id = m.ticket_id
        WHERE t.customer_id = ? AND m.sender = 'admin' ORDER BY m.id DESC LIMIT 10");
    $st->execute([$cid]);
    foreach ($st as $r) $items[] = ['t' => $r['created_at'], 'icon' => 'bi-chat-left-text', 'url' => 'ticket.php?id=' . $r['id'],
        'title' => 'Reply on ' . $r['ticket_no'], 'text' => $r['subject'], 'new' => $r['created_at'] > $seenAt];
    usort($items, fn($a, $b) => strcmp($b['t'], $a['t']));
    return array_slice($items, 0, $limit);
}

function new_ticket_number(PDO $pdo): string {
    for ($i = 0; $i < 10; $i++) {
        $no = 'TK-' . date('ymd') . '-' . random_int(1000, 9999);
        $st = $pdo->prepare('SELECT 1 FROM tickets WHERE ticket_no = ?'); $st->execute([$no]);
        if (!$st->fetch()) return $no;
    }
    throw new RuntimeException('Could not generate ticket number');
}

const TICKET_CATEGORIES = ['general' => 'General question', 'order' => 'Order / delivery', 'payment' => 'Payment', 'product' => 'Product enquiry', 'return' => 'Return / complaint', 'other' => 'Something else'];

/* ───────────── admin order filters (shared by the orders list, bulk export and CSV) ───────────── */
function order_filter(array $g): array {
    $f = [
        'status' => in_array($g['status'] ?? '', ORDER_STATUSES, true) ? $g['status'] : '',
        'pay'    => in_array($g['pay'] ?? '', PAYMENT_STATUSES, true) ? $g['pay'] : '',
        'method' => isset(cfg('payment_methods')[$g['method'] ?? '']) ? $g['method'] : '',
        'who'    => in_array($g['who'] ?? '', ['guest', 'member'], true) ? $g['who'] : '',
        'q'      => clean($g['q'] ?? '', 80),
        'from'   => valid_date($g['from'] ?? ''), 'to' => valid_date($g['to'] ?? ''),
        'sort'   => in_array($g['sort'] ?? '', ['date', 'date_desc', 'total', 'total_desc', 'name', 'name_desc'], true) ? $g['sort'] : '',
    ];
    $w = []; $a = [];
    if ($f['status']) { $w[] = 'o.status = ?'; $a[] = $f['status']; }
    if ($f['pay'])    { $w[] = 'o.payment_status = ?'; $a[] = $f['pay']; }
    if ($f['method']) { $w[] = 'o.payment_method = ?'; $a[] = $f['method']; }
    if ($f['who'] === 'guest')  $w[] = 'o.customer_id IS NULL';
    if ($f['who'] === 'member') $w[] = 'o.customer_id IS NOT NULL';
    if ($f['from']) { $w[] = 'o.created_at >= ?'; $a[] = $f['from'] . ' 00:00:00'; }
    if ($f['to'])   { $w[] = 'o.created_at < ?';  $a[] = date('Y-m-d', strtotime($f['to'] . ' +1 day')) . ' 00:00:00'; }
    if ($f['q'] !== '') {
        $w[] = '(o.order_number LIKE ? OR o.customer_name LIKE ? OR o.phone LIKE ? OR o.email LIKE ? OR o.city LIKE ? OR o.tracking_no LIKE ?)';
        array_push($a, ...array_fill(0, 6, '%' . $f['q'] . '%'));
    }
    $order = ['date' => 'o.id ASC', 'date_desc' => 'o.id DESC', 'total' => 'o.total ASC', 'total_desc' => 'o.total DESC', 'name' => 'o.customer_name ASC', 'name_desc' => 'o.customer_name DESC'][$f['sort'] ?: 'date_desc'];
    return ['f' => $f, 'where' => $w ? 'WHERE ' . implode(' AND ', $w) : '', 'args' => $a, 'order' => $order];
}

/* ───────────── reviews & ratings ───────────── */
const FEEDBACK_CATEGORIES = ['overall' => 'Overall experience', 'design' => 'Website design', 'products' => 'Product range', 'checkout' => 'Checkout & ordering', 'delivery' => 'Delivery', 'service' => 'Customer service'];
/** Star icons for a rating (supports halves). */
function stars(float $r, string $cls = ''): string {
    $o = '<span class="stars ' . $cls . '" role="img" aria-label="' . round($r, 1) . ' out of 5 stars">';
    for ($i = 1; $i <= 5; $i++) $o .= '<i class="bi bi-star' . ($r >= $i - 0.25 ? '-fill' : ($r >= $i - 0.75 ? '-half' : '')) . '"></i>';
    return $o . '</span>';
}
/** Interactive star picker (radio buttons, accessible, CSS-only). */
function star_input(string $name, int $val = 0): string {
    $u = substr(md5($name . microtime(true) . random_int(0, 99999)), 0, 6);
    $o = '<div class="starpick" role="radiogroup" aria-label="Rating">';
    for ($i = 5; $i >= 1; $i--) $o .= '<input type="radio" name="' . $name . '" id="s' . $u . $i . '" value="' . $i . '"' . ($val === $i ? ' checked' : '') . ' required>'
        . '<label for="s' . $u . $i . '" title="' . $i . ' star' . ($i > 1 ? 's' : '') . '"><i class="bi bi-star-fill"></i></label>';
    return $o . '</div>';
}
