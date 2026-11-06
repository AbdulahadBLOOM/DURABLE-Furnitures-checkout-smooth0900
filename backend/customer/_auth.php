<?php
require_once __DIR__ . '/../session.php';
require_once __DIR__ . '/../panel_ui.php';
sess_open('customer');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');

const CUSTOMER_SIGNIN_URL = '../../account.html?role=customer';

/** Returns the signed-in customer row, or redirects to the sign-in page. */
function customer_required(): array {
    $c = current_customer();
    if (!$c) { header('Location: ' . CUSTOMER_SIGNIN_URL); exit; }
    $GLOBALS['CUSTOMER'] = $c;
    return $c;
}

/** Nav counters: wishlist size, tickets waiting on the customer, unseen notifications. */
function customer_counts(int $cid): array {
    static $c = [];
    if (isset($c[$cid])) return $c[$cid];
    $pdo = db();
    $q = function (string $sql) use ($pdo, $cid) { $s = $pdo->prepare($sql); $s->execute([$cid]); return (int) $s->fetchColumn(); };
    $seen = $pdo->prepare('SELECT notif_seen_at FROM customers WHERE id = ?'); $seen->execute([$cid]); $seenAt = $seen->fetchColumn() ?: '1970-01-01 00:00:00';
    $unseen = $pdo->prepare("SELECT (SELECT COUNT(*) FROM order_status_history h JOIN orders o ON o.id = h.order_id WHERE o.customer_id = ? AND h.changed_by <> 'customer' AND h.created_at > ?)
        + (SELECT COUNT(*) FROM ticket_messages m JOIN tickets t ON t.id = m.ticket_id WHERE t.customer_id = ? AND m.sender = 'admin' AND m.created_at > ?)");
    $unseen->execute([$cid, $seenAt, $cid, $seenAt]);
    return $c[$cid] = [
        'wish'     => $q('SELECT COUNT(*) FROM wishlist WHERE customer_id = ?'),
        'answered' => $q("SELECT COUNT(*) FROM tickets WHERE customer_id = ? AND status = 'answered'"),
        'toreview' => $q("SELECT COUNT(DISTINCT oi.product_id) FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE o.customer_id = ? AND o.status = 'delivered' AND oi.product_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM reviews r WHERE r.customer_id = o.customer_id AND r.product_id = oi.product_id)"),
        'active'   => $q("SELECT COUNT(*) FROM orders WHERE customer_id = ? AND status IN ('pending','confirmed','processing','shipped')"),
        'unseen'   => (int) $unseen->fetchColumn(),
    ];
}

function customer_bell(int $cid, int $unseen): string {
    $items = customer_notifications($cid, 6);
    $o = '<li class="nav-item dropdown ms-1"><button class="bell-btn" id="bellMenu" data-bs-toggle="dropdown" aria-label="Notifications" aria-expanded="false" data-seen="notifications.php" data-csrf="' . h(csrf()) . '"><i class="bi bi-bell"></i>'
       . ($unseen ? '<span class="bell-dot">' . ($unseen > 9 ? '9+' : $unseen) . '</span>' : '') . '</button>'
       . '<div class="dropdown-menu dropdown-menu-end bell-menu"><div class="bell-head">Notifications<a href="notifications.php">See all</a></div><div class="bell-list">';
    foreach ($items as $n)
        $o .= '<a class="bell-item ' . ($n['new'] ? 'new' : '') . '" href="' . h($n['url']) . '"><span class="bi-ic"><i class="bi ' . h($n['icon']) . '"></i></span><div><b>' . h($n['title']) . '</b><small>' . h(mb_strimwidth($n['text'], 0, 70, '…')) . '</small></div><time>' . h(ago($n['t'])) . '</time></a>';
    if (!$items) $o .= '<div class="bell-empty"><i class="bi bi-bell-slash" style="font-size:1.8rem;color:var(--gold);display:block;margin-bottom:6px"></i>No notifications yet.<br>Order updates and support replies show up here.</div>';
    return $o . '</div></div></li>';
}

function customer_head(string $title, string $active = '', array $hero = []): void {
    $c = $GLOBALS['CUSTOMER']; $n = customer_counts((int) $c['id']);
    panel_head($title, 'customer', [
        'index.php'   => ['Overview', 'home', 'bi-grid-1x2'],
        'orders.php'  => ['My orders', 'orders', 'bi-bag-check', null, $n['active']],
        '#shop'       => ['Shop', 'shop', 'bi-shop', [['shop.php', 'Browse catalogue', 'shop'], ['wishlist.php', 'My wishlist', 'wishlist', $n['wish']], ['../../checkout.html', 'My cart & checkout'], ['../../index.html', 'Back to store']]],
        'reviews.php' => ['Reviews', 'reviews', 'bi-star', null, $n['toreview']],
        'tickets.php' => ['Support', 'support', 'bi-life-preserver', null, $n['answered']],
        '#account'    => ['Account', 'account', 'bi-person-gear', [['profile.php', 'Profile & security', 'profile'], ['addresses.php', 'Address book', 'addresses'], ['notifications.php', 'Notifications', 'notifications']]],
    ], $active, $c['full_name'], 'logout.php', $hero, customer_bell((int) $c['id'], $n['unseen']), [
        ['profile.php', 'Profile & security', 'bi-person-gear'], ['addresses.php', 'Address book', 'bi-geo-alt'], ['wishlist.php', 'Wishlist', 'bi-heart'], ['reviews.php', 'Reviews & ratings', 'bi-star'],
    ]);
}
function customer_foot(): void { panel_foot(); }
