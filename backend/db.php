<?php
/** PDO connection + shared helpers. */
// Fallbacks so the backend still works on hosts without the mbstring extension.
if (!function_exists('mb_substr')) {
    function mb_substr(string $s, int $start, ?int $len = null): string {
        return preg_match_all('/./us', $s, $m) ? implode('', array_slice($m[0], $start, $len)) : substr($s, $start, $len ?? PHP_INT_MAX);
    }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $s): int { return preg_match_all('/./us', $s) ?: strlen($s); }
}

function cfg(?string $key = null) {
    static $c;
    if ($c === null) $c = require __DIR__ . '/config.php';
    return $key === null ? $c : ($c[$key] ?? null);
}

function db(): PDO {
    static $pdo;
    if ($pdo) return $pdo;
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        cfg('db_host'), cfg('db_port'), cfg('db_name'));
    $pdo = new PDO($dsn, cfg('db_user'), cfg('db_pass'), [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec("SET time_zone = '+05:00'");   // Pakistan Standard Time
    ensure_schema($pdo);
    ensure_schema_v3($pdo);
    ensure_schema_v4($pdo);
    return $pdo;
}

/**
 * Upgrades databases created before accounts existed (adds customers table, orders.customer_id,
 * admin profile columns, auth_attempts). Idempotent; a lock file stops it running on every request.
 */
function ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $lock = __DIR__ . '/data/schema_v2.lock';
    if (is_file($lock)) return;
    try {
        $has = fn(string $t) => (bool) $pdo->query("SHOW TABLES LIKE " . $pdo->quote($t))->fetch();
        $col = fn(string $t, string $c) => (bool) $pdo->query("SHOW COLUMNS FROM `$t` LIKE " . $pdo->quote($c))->fetch();
        if (!$has('orders') || !$has('admins')) return;   // fresh install: schema.sql is still running

        $pdo->exec("CREATE TABLE IF NOT EXISTS customers (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, full_name VARCHAR(120) NOT NULL, email VARCHAR(160) NOT NULL UNIQUE,
            phone VARCHAR(20) NOT NULL DEFAULT '', password_hash VARCHAR(255) NOT NULL, address VARCHAR(400) NOT NULL DEFAULT '',
            area VARCHAR(120) NOT NULL DEFAULT '', city VARCHAR(80) NOT NULL DEFAULT '', postal_code VARCHAR(12) NOT NULL DEFAULT '',
            is_active TINYINT(1) NOT NULL DEFAULT 1, last_login TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_cust_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS auth_attempts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, ip VARCHAR(45) NOT NULL, kind VARCHAR(20) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_ip_kind (ip, kind, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        if (!$col('orders', 'customer_id')) {
            $pdo->exec('ALTER TABLE orders ADD COLUMN customer_id INT UNSIGNED NULL AFTER access_token, ADD KEY idx_customer (customer_id)');
            try { $pdo->exec('ALTER TABLE orders ADD FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL'); } catch (Throwable $e) {}
        }
        if (!$col('admins', 'full_name')) $pdo->exec("ALTER TABLE admins ADD COLUMN full_name VARCHAR(120) NOT NULL DEFAULT '' AFTER username");
        if (!$col('admins', 'email'))     $pdo->exec('ALTER TABLE admins ADD COLUMN email VARCHAR(160) NULL UNIQUE AFTER full_name');
        if (!$col('admins', 'last_login')) $pdo->exec('ALTER TABLE admins ADD COLUMN last_login TIMESTAMP NULL DEFAULT NULL AFTER password_hash');
        @file_put_contents($lock, date('c'));
    } catch (Throwable $e) {
        error_log('[DF schema upgrade] ' . $e->getMessage());
    }
}

/**
 * v3 upgrade – powers the advanced customer + admin panels (address book, wishlist, support tickets,
 * coupons, audit log, order tracking fields, message replies). Idempotent; a lock file skips it afterwards.
 */
function ensure_schema_v3(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $lock = __DIR__ . '/data/schema_v3.lock';
    if (is_file($lock)) return;
    try {
        $has = fn(string $t) => (bool) $pdo->query("SHOW TABLES LIKE " . $pdo->quote($t))->fetch();
        $col = fn(string $t, string $c) => (bool) $pdo->query("SHOW COLUMNS FROM `$t` LIKE " . $pdo->quote($c))->fetch();
        if (!$has('orders') || !$has('customers') || !$has('admins') || !$has('contact_messages') || !$has('products')) return;   // fresh install: schema.sql still running

        $add = function (string $t, string $c, string $def) use ($pdo, $col) { if (!$col($t, $c)) $pdo->exec("ALTER TABLE `$t` ADD COLUMN `$c` $def"); };
        $add('customers', 'notif_seen_at', 'TIMESTAMP NULL DEFAULT NULL');
        $add('customers', 'admin_note', "VARCHAR(1000) NOT NULL DEFAULT ''");
        $add('orders', 'tracking_no', "VARCHAR(80) NOT NULL DEFAULT ''");
        $add('orders', 'courier', "VARCHAR(60) NOT NULL DEFAULT ''");
        $add('orders', 'eta', 'DATE NULL DEFAULT NULL');
        $add('orders', 'cancel_reason', "VARCHAR(300) NOT NULL DEFAULT ''");
        $add('orders', 'coupon_code', "VARCHAR(40) NOT NULL DEFAULT ''");
        $add('contact_messages', 'reply', 'TEXT NULL');
        $add('contact_messages', 'replied_at', 'TIMESTAMP NULL DEFAULT NULL');
        $add('contact_messages', 'replied_by', "VARCHAR(60) NOT NULL DEFAULT ''");

        foreach (array_filter(array_map('trim', explode(';', df_v3_sql()))) as $sql) $pdo->exec($sql);
        @file_put_contents($lock, date('c'));
    } catch (Throwable $e) {
        error_log('[DF schema v3 upgrade] ' . $e->getMessage());
    }
}

/** CREATE TABLE statements added in v3 (also used by schema.sql). */
function df_v3_sql(): string {
    return <<<'SQL'
CREATE TABLE IF NOT EXISTS addresses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, customer_id INT UNSIGNED NOT NULL,
  label VARCHAR(40) NOT NULL DEFAULT 'Home', recipient VARCHAR(120) NOT NULL DEFAULT '', phone VARCHAR(20) NOT NULL DEFAULT '',
  address VARCHAR(400) NOT NULL, area VARCHAR(120) NOT NULL DEFAULT '', city VARCHAR(80) NOT NULL, postal_code VARCHAR(12) NOT NULL DEFAULT '',
  is_default TINYINT(1) NOT NULL DEFAULT 0, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_addr_cust (customer_id), FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS wishlist (
  customer_id INT UNSIGNED NOT NULL, product_id INT UNSIGNED NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (customer_id, product_id),
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE, FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS tickets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, ticket_no VARCHAR(16) NOT NULL UNIQUE, customer_id INT UNSIGNED NOT NULL, order_id INT UNSIGNED NULL,
  subject VARCHAR(160) NOT NULL, category VARCHAR(30) NOT NULL DEFAULT 'general',
  priority ENUM('low','normal','high') NOT NULL DEFAULT 'normal', status ENUM('open','answered','closed') NOT NULL DEFAULT 'open',
  last_reply_by ENUM('customer','admin') NOT NULL DEFAULT 'customer',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_tk_status (status), KEY idx_tk_cust (customer_id), FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS ticket_messages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, ticket_id INT UNSIGNED NOT NULL, sender ENUM('customer','admin') NOT NULL,
  sender_name VARCHAR(120) NOT NULL DEFAULT '', body TEXT NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tm_ticket (ticket_id), FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS coupons (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(40) NOT NULL UNIQUE, description VARCHAR(160) NOT NULL DEFAULT '',
  type ENUM('percent','fixed') NOT NULL DEFAULT 'percent', value INT UNSIGNED NOT NULL, min_subtotal INT UNSIGNED NOT NULL DEFAULT 0,
  max_discount INT UNSIGNED NOT NULL DEFAULT 0, usage_limit INT UNSIGNED NOT NULL DEFAULT 0, used_count INT UNSIGNED NOT NULL DEFAULT 0,
  starts_at DATE NULL DEFAULT NULL, expires_at DATE NULL DEFAULT NULL, active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS activity_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, actor_type VARCHAR(12) NOT NULL DEFAULT 'admin', actor VARCHAR(80) NOT NULL DEFAULT '',
  action VARCHAR(60) NOT NULL, target VARCHAR(80) NOT NULL DEFAULT '', detail VARCHAR(400) NOT NULL DEFAULT '', ip VARCHAR(45) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_log_created (created_at), KEY idx_log_actor (actor_type, actor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL;
}

/** v4 – product reviews + website feedback (ratings). */
function ensure_schema_v4(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $lock = __DIR__ . '/data/schema_v4.lock';
    if (is_file($lock)) return;
    try {
        $has = fn(string $t) => (bool) $pdo->query("SHOW TABLES LIKE " . $pdo->quote($t))->fetch();
        if (!$has('customers') || !$has('products') || !$has('orders')) return;
        foreach (array_filter(array_map('trim', explode(';', df_v4_sql()))) as $sql) $pdo->exec($sql);
        @file_put_contents($lock, date('c'));
    } catch (Throwable $e) { error_log('[DF schema v4 upgrade] ' . $e->getMessage()); }
}

function df_v4_sql(): string {
    return <<<'SQL'
CREATE TABLE IF NOT EXISTS reviews (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, customer_id INT UNSIGNED NOT NULL, product_id INT UNSIGNED NOT NULL, order_id INT UNSIGNED NULL,
  rating TINYINT UNSIGNED NOT NULL, title VARCHAR(120) NOT NULL DEFAULT '', body TEXT NOT NULL,
  status ENUM('pending','approved','hidden') NOT NULL DEFAULT 'approved', admin_reply TEXT NULL, replied_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_review (customer_id, product_id), KEY idx_rev_product (product_id, status), KEY idx_rev_status (status),
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE, FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS site_feedback (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, customer_id INT UNSIGNED NOT NULL, rating TINYINT UNSIGNED NOT NULL,
  category VARCHAR(30) NOT NULL DEFAULT 'overall', message TEXT NOT NULL, allow_public TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('new','reviewed','hidden') NOT NULL DEFAULT 'new', admin_reply TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_sf_status (status),
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL;
}

function json_out(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function h($s): string { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
if (!function_exists('mb_strtoupper')) {
    function mb_strtoupper(string $s): string { return strtoupper($s); }
}

function money($n): string { return cfg('currency') . number_format((int) $n); }

function clean(?string $s, int $max = 255): string {
    $s = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $s));
    return mb_substr($s, 0, $max);
}

/** Accepts 03xx-xxxxxxx, +923xx…, 923xx…, landline w/ area code. Returns normalized "03xxxxxxxxx" or "" if invalid. */
function normalize_phone(string $p): string {
    $d = preg_replace('/\D/', '', $p);
    if (strpos($d, '0092') === 0) $d = substr($d, 4);
    elseif (strpos($d, '92') === 0 && strlen($d) >= 11) $d = substr($d, 2);
    if ($d !== '' && $d[0] !== '0') $d = '0' . $d;
    return preg_match('/^0\d{9,10}$/', $d) ? $d : '';
}

function parse_price($v): int { return (int) preg_replace('/\D/', '', (string) $v); }

function new_order_number(PDO $pdo): string {
    $alpha = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    for ($try = 0; $try < 10; $try++) {
        $s = '';
        for ($i = 0; $i < 4; $i++) $s .= $alpha[random_int(0, strlen($alpha) - 1)];
        $no = 'DF-' . date('ymd') . '-' . $s;
        $st = $pdo->prepare('SELECT 1 FROM orders WHERE order_number = ?');
        $st->execute([$no]);
        if (!$st->fetch()) return $no;
    }
    throw new RuntimeException('Could not generate order number');
}

const ORDER_STATUSES = ['pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled'];
const PAYMENT_STATUSES = ['unpaid', 'paid', 'refunded'];

/** Load a full order (with items + history). $where is "order_number = ?" etc. */
function load_order(string $where, array $args, bool $public = true): ?array {
    $pdo = db();
    $st = $pdo->prepare("SELECT * FROM orders WHERE $where LIMIT 1");
    $st->execute($args);
    $o = $st->fetch();
    if (!$o) return null;
    $i = $pdo->prepare('SELECT brand, name, image, unit_price, qty, line_total FROM order_items WHERE order_id = ? ORDER BY id');
    $i->execute([$o['id']]);
    $o['items'] = $i->fetchAll();
    $hs = $pdo->prepare('SELECT status, note, changed_by, created_at FROM order_status_history WHERE order_id = ? ORDER BY id');
    $hs->execute([$o['id']]);
    $o['history'] = $hs->fetchAll();
    $pm = cfg('payment_methods');
    $o['payment_label'] = $pm[$o['payment_method']]['label'] ?? $o['payment_method'];
    $o['payment_note']  = $pm[$o['payment_method']]['note'] ?? '';
    if ($public) unset($o['ip'], $o['user_agent'], $o['admin_notes'], $o['request_key']);
    return $o;
}

/** Mirror the order to data/orders/<number>.json (human-readable backup). */
function write_order_json(array $o): void {
    $dir = __DIR__ . '/data/orders';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    @file_put_contents("$dir/{$o['order_number']}.json",
        json_encode($o, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

/** Best-effort email; always logged so it works on localhost without a mail server. */
function send_mail(string $to, string $subject, string $html): void {
    if (!cfg('send_email') || !filter_var($to, FILTER_VALIDATE_EMAIL)) return;
    $from = cfg('store_email');
    $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n"
             . "From: " . cfg('store_name') . " <$from>\r\nReply-To: $from\r\n";
    $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $html, $headers);
    @file_put_contents(__DIR__ . '/data/mail.log',
        '[' . date('Y-m-d H:i:s') . '] ' . ($ok ? 'SENT' : 'NOT-SENT(no mail server)') . " to=$to subject=$subject\n",
        FILE_APPEND | LOCK_EX);
}

function order_email_html(array $o, string $intro): string {
    $rows = '';
    foreach ($o['items'] as $it) {
        $rows .= '<tr><td style="padding:8px;border-bottom:1px solid #eee">' . h($it['name']) . ' <small>(' . h($it['brand']) . ')</small></td>'
              .  '<td style="padding:8px;border-bottom:1px solid #eee;text-align:center">' . (int) $it['qty'] . '</td>'
              .  '<td style="padding:8px;border-bottom:1px solid #eee;text-align:right">' . money($it['line_total']) . '</td></tr>';
    }
    return '<div style="font-family:Arial,sans-serif;max-width:620px;margin:auto;color:#222">'
      . '<h2 style="margin-bottom:4px">' . h(cfg('store_name')) . '</h2><p>' . $intro . '</p>'
      . '<p><b>Order #' . h($o['order_number']) . '</b><br>Status: ' . h(ucfirst($o['status'])) . ' · Payment: ' . h($o['payment_label']) . '</p>'
      . '<table style="width:100%;border-collapse:collapse"><tr style="background:#f5f0e8"><th align="left" style="padding:8px">Item</th><th>Qty</th><th align="right" style="padding:8px">Amount</th></tr>' . $rows . '</table>'
      . '<p style="text-align:right">Subtotal: ' . money($o['subtotal']) . (!empty($o['discount']) ? '<br>Discount' . (!empty($o['coupon_code']) ? ' (' . h($o['coupon_code']) . ')' : '') . ': −' . money($o['discount']) : '') . '<br>Delivery: ' . ($o['delivery_fee'] > 0 ? money($o['delivery_fee']) : 'Free')
      . '<br><b>Total: ' . money($o['total']) . '</b></p>'
      . '<p><b>Deliver to:</b><br>' . h($o['customer_name']) . '<br>' . h($o['address']) . ($o['area'] ? ', ' . h($o['area']) : '') . ', ' . h($o['city']) . '<br>' . h($o['phone']) . '</p>'
      . '<p style="color:#777;font-size:12px">' . h(cfg('store_address')) . ' · ' . h(cfg('store_phone')) . '</p></div>';
}
