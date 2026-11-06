<?php
/**
 * Sessions + authentication helpers shared by the admin panel, customer panel and JSON API.
 * Admin and customer use SEPARATE session cookies, so one browser can be both without mixing rights.
 */
require_once __DIR__ . '/db.php';

function session_cookie_name(string $role): string {
    return cfg($role === 'admin' ? 'admin_session' : 'customer_session');
}

/** Open the (writable) session for a role. Call once per request. */
function sess_open(string $role): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name(session_cookie_name($role));
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => $role === 'admin' ? 'Strict' : 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
        'path'     => '/',
    ]);
    session_start();
}

/** Read a role's session without locking or touching the active one. Returns [] when not signed in. */
function sess_peek(string $role): array {
    $name = session_cookie_name($role);
    $id = (string) ($_COOKIE[$name] ?? '');
    if (!preg_match('/^[A-Za-z0-9,-]{16,128}$/', $id)) return [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        // another role's session is open in this request: peek via a saved/restored state
        $keepName = session_name(); $keepId = session_id();
        session_write_close();
    }
    session_name($name);
    session_id($id);
    @session_start(['read_and_close' => true]);
    $data = $_SESSION ?? [];
    $_SESSION = [];
    if (isset($keepName)) { session_name($keepName); session_id($keepId); session_start(); }
    return is_array($data) ? $data : [];
}

function csrf(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_check(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) { http_response_code(403); exit('Bad CSRF token'); }
}

/* ───────────── brute-force / spam throttle (per IP, stored in DB) ───────────── */
function auth_ip(): string { return substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45); }

function auth_throttled(string $kind, int $max, int $minutes): bool {
    $st = db()->prepare("SELECT COUNT(*) FROM auth_attempts WHERE ip = ? AND kind = ? AND created_at > (NOW() - INTERVAL $minutes MINUTE)");
    $st->execute([auth_ip(), $kind]);
    return (int) $st->fetchColumn() >= $max;
}
function auth_record(string $kind): void {
    db()->prepare('INSERT INTO auth_attempts (ip, kind) VALUES (?, ?)')->execute([auth_ip(), $kind]);
    if (random_int(1, 40) === 1) db()->exec('DELETE FROM auth_attempts WHERE created_at < (NOW() - INTERVAL 2 DAY)');
}

/* ───────────── validation ───────────── */
function valid_password(string $p): ?string {
    if (strlen($p) < 8)   return 'Password must be at least 8 characters.';
    if (strlen($p) > 200) return 'Password is too long.';
    if (!preg_match('/[A-Za-z]/', $p) || !preg_match('/\d/', $p)) return 'Use at least one letter and one number in your password.';
    return null;
}

/* ───────────── customer ───────────── */
/** Fresh customer row for the logged-in customer (null if signed out / disabled). Requires the customer session to be open. */
function current_customer(): ?array {
    $id = (int) ($_SESSION['customer_id'] ?? 0);
    if (!$id) return null;
    $st = db()->prepare('SELECT id, full_name, email, phone, address, area, city, postal_code, is_active, created_at, last_login FROM customers WHERE id = ?');
    $st->execute([$id]);
    $c = $st->fetch();
    if (!$c || !(int) $c['is_active']) { unset($_SESSION['customer_id']); return null; }
    return $c;
}

/** Customer id from the cookie only (used by place_order.php so checkout can link the order to an account). */
function customer_id_from_cookie(): ?int {
    $id = (int) (sess_peek('customer')['customer_id'] ?? 0);
    if (!$id) return null;
    $st = db()->prepare('SELECT id FROM customers WHERE id = ? AND is_active = 1');
    $st->execute([$id]);
    return $st->fetchColumn() ? $id : null;
}

/* ───────────── admin ───────────── */
function admin_count(): int { return (int) db()->query('SELECT COUNT(*) FROM admins')->fetchColumn(); }

/** 'first' = no admin exists yet (open), 'code' = needs the access code, 'closed' = disabled. */
function admin_signup_mode(): string {
    if (admin_count() === 0) return 'first';
    return cfg('admin_signup_code') !== '' && cfg('admin_signup_code') !== null ? 'code' : 'closed';
}
