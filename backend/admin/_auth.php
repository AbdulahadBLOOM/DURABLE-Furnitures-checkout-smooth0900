<?php
require_once __DIR__ . '/../session.php';
require_once __DIR__ . '/../panel_ui.php';
sess_open('admin');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');

const ADMIN_SIGNIN_URL = '../../account.html?role=admin';

/** Redirects to the sign-in page unless a valid admin is logged in. */
function admin_required(): void {
    $u = (string) ($_SESSION['admin'] ?? '');
    if ($u !== '') {
        $st = db()->prepare('SELECT id, username, full_name, email, last_login FROM admins WHERE username = ?');
        $st->execute([$u]);
        if ($a = $st->fetch()) { $GLOBALS['ADMIN'] = $a; return; }
        $_SESSION = [];
    }
    header('Location: ' . ADMIN_SIGNIN_URL); exit;
}

/** Counters shown as nav badges + in the bell (cheap COUNT queries). */
function admin_counts(): array {
    static $c;
    if ($c) return $c;
    $pdo = db();
    return $c = [
        'pending'  => (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn(),
        'unread'   => (int) $pdo->query('SELECT COUNT(*) FROM contact_messages WHERE is_read = 0')->fetchColumn(),
        'tickets'  => (int) $pdo->query("SELECT COUNT(*) FROM tickets WHERE status = 'open'")->fetchColumn(),
        'reviews'  => (int) $pdo->query("SELECT COUNT(*) FROM reviews WHERE status = 'pending'")->fetchColumn() + (int) $pdo->query("SELECT COUNT(*) FROM site_feedback WHERE status = 'new'")->fetchColumn(),
        'unpaid'   => (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE payment_status = 'unpaid' AND status IN ('shipped','delivered')")->fetchColumn(),
    ];
}

function admin_bell(array $n): string {
    $total = $n['pending'] + $n['unread'] + $n['tickets'] + $n['unpaid'] + $n['reviews'];
    $rows = [
        [$n['pending'], 'bi-hourglass-split', 'pending order(s) waiting for confirmation', 'orders.php?status=pending'],
        [$n['tickets'], 'bi-life-preserver', 'open support ticket(s)', 'tickets.php?status=open'],
        [$n['unread'], 'bi-envelope-paper', 'unread contact message(s)', 'messages.php?filter=unread'],
        [$n['reviews'], 'bi-star', 'new review(s) / feedback to look at', 'reviews.php'],
        [$n['unpaid'], 'bi-cash-coin', 'shipped / delivered order(s) still unpaid', 'orders.php?pay=unpaid'],
    ];
    $o = '<li class="nav-item dropdown ms-1"><button class="bell-btn" id="bellMenu" data-bs-toggle="dropdown" aria-label="Alerts" aria-expanded="false"><i class="bi bi-bell"></i>'
       . ($total ? '<span class="bell-dot">' . ($total > 99 ? '99+' : $total) . '</span>' : '') . '</button>'
       . '<div class="dropdown-menu dropdown-menu-end bell-menu"><div class="bell-head">Needs attention<a href="activity.php">Activity log</a></div><div class="bell-list">';
    $any = false;
    foreach ($rows as [$cnt, $ic, $txt, $url]) if ($cnt > 0) { $any = true;
        $o .= '<a class="bell-item new" href="' . h($url) . '"><span class="bi-ic"><i class="bi ' . $ic . '"></i></span><div><b>' . $cnt . '</b><small>' . h($txt) . '</small></div></a>'; }
    if (!$any) $o .= '<div class="bell-empty"><i class="bi bi-check2-circle" style="font-size:1.8rem;color:var(--gold);display:block;margin-bottom:6px"></i>You are all caught up.</div>';
    return $o . '</div></div></li>';
}

function admin_head(string $title, string $active = '', array $hero = []): void {
    $a = $GLOBALS['ADMIN'] ?? ['username' => '', 'full_name' => ''];
    $n = admin_counts();
    panel_head($title, 'admin', [
        'index.php'     => ['Dashboard', 'dashboard', 'bi-speedometer2'],
        'orders.php'    => ['Orders', 'orders', 'bi-bag-check', null, $n['pending']],
        'customers.php' => ['Customers', 'customers', 'bi-people'],
        '#inbox'        => ['Inbox', 'inbox', 'bi-inbox', [['tickets.php', 'Support tickets', 'tickets', $n['tickets']], ['messages.php', 'Contact messages', 'messages', $n['unread']], ['reviews.php', 'Reviews & feedback', 'reviews', $n['reviews']]]],
        '#catalog'      => ['Catalogue', 'catalog', 'bi-boxes', [['products.php', 'Products', 'products'], ['coupons.php', 'Coupons & offers', 'coupons']]],
        '#reports'      => ['Reports', 'reports', 'bi-graph-up-arrow', [['reports.php', 'Sales reports', 'reports'], ['export.php?type=orders', 'Export orders (CSV)'], ['export.php?type=customers', 'Export customers (CSV)'], ['export.php?type=messages', 'Export messages (CSV)'], ['export.php?type=products', 'Export products (CSV)'], ['export.php?format=json', 'Export orders (JSON)']]],
    ], $active, $a['full_name'] ?: $a['username'], 'logout.php', $hero, admin_bell($n), [
        ['profile.php', 'My profile & password', 'bi-person-gear'], ['team.php', 'Team members', 'bi-people-fill'], ['activity.php', 'Activity log', 'bi-clock-history'],
    ]);
}
function admin_foot(): void { panel_foot(); }
