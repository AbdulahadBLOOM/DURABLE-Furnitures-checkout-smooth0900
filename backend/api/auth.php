<?php
/**
 * Sign up / sign in / sign out for BOTH roles (customer + admin).
 * POST JSON: {"action": "customer_signup|customer_login|admin_signup|admin_login|logout", ...}
 */
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../session.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'POST only'], 405);
// A JSON content-type forces a CORS preflight for cross-site pages, which blocks cross-site form posts (CSRF).
if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) json_out(['ok' => false, 'error' => 'Unsupported request.'], 415);

$in = json_body();
if (!empty($in['website'])) json_out(['ok' => false, 'error' => 'Rejected'], 400);   // honeypot
$action = (string) ($in['action'] ?? '');

function fail(string $msg, int $code = 422, array $fields = []): void {
    json_out(['ok' => false, 'error' => $msg] + ($fields ? ['fields' => $fields] : []), $code);
}
function too_many_logins(): void {
    if (auth_throttled('fail', (int) cfg('max_failed_logins_per_15min'), 15))
        fail('Too many failed attempts. Please wait 15 minutes and try again.', 429);
}
function too_many_signups(): void {
    if (auth_throttled('signup', (int) cfg('max_signups_per_hour'), 60))
        fail('Too many accounts created from your connection. Please try again later.', 429);
}

switch ($action) {

    /* ───────────── CUSTOMER ───────────── */
    case 'customer_signup': {
        too_many_signups();
        $name  = clean($in['name'] ?? '', 120);
        $email = strtolower(clean($in['email'] ?? '', 160));
        $phoneRaw = clean($in['phone'] ?? '', 30);
        $phone = $phoneRaw === '' ? '' : normalize_phone($phoneRaw);
        $pass  = (string) ($in['password'] ?? '');
        $e = [];
        if (mb_strlen($name) < 3)                       $e['name']  = 'Please enter your full name.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $e['email'] = 'Enter a valid email address.';
        if ($phoneRaw !== '' && $phone === '')          $e['phone'] = 'Enter a valid phone number (e.g. 0300 1234567).';
        if ($msg = valid_password($pass))               $e['password'] = $msg;
        if ($e) fail('Please fix the highlighted fields.', 422, $e);

        $pdo = db();
        $x = $pdo->prepare('SELECT 1 FROM customers WHERE email = ?'); $x->execute([$email]);
        if ($x->fetch()) fail('An account with this email already exists. Try signing in.', 409, ['email' => 'This email is already registered.']);

        $pdo->prepare('INSERT INTO customers (full_name, email, phone, password_hash) VALUES (?,?,?,?)')
            ->execute([$name, $email, $phone, password_hash($pass, PASSWORD_DEFAULT)]);
        auth_record('signup');
        sess_open('customer'); session_regenerate_id(true);
        $_SESSION['customer_id'] = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE customers SET last_login = NOW() WHERE id = ?')->execute([$_SESSION['customer_id']]);
        json_out(['ok' => true, 'role' => 'customer', 'name' => $name, 'redirect' => 'backend/customer/']);
    }

    case 'customer_login': {
        too_many_logins(); usleep(350000);
        $email = strtolower(clean($in['email'] ?? '', 160));
        $st = db()->prepare('SELECT id, full_name, password_hash, is_active FROM customers WHERE email = ?');
        $st->execute([$email]);
        $c = $st->fetch();
        if (!$c || !password_verify((string) ($in['password'] ?? ''), $c['password_hash'])) {
            auth_record('fail'); fail('Wrong email or password.', 401);
        }
        if (!(int) $c['is_active']) fail('This account has been disabled. Please contact the store.', 403);
        sess_open('customer'); session_regenerate_id(true);
        $_SESSION['customer_id'] = (int) $c['id'];
        db()->prepare('UPDATE customers SET last_login = NOW() WHERE id = ?')->execute([$c['id']]);
        json_out(['ok' => true, 'role' => 'customer', 'name' => $c['full_name'], 'redirect' => 'backend/customer/']);
    }

    /* ───────────── ADMIN ───────────── */
    case 'admin_signup': {
        too_many_signups(); too_many_logins();
        $mode = admin_signup_mode();
        if ($mode === 'closed')
            fail('Admin sign-up is closed. Ask an existing admin, or set "admin_signup_code" in backend/config.local.php to enable it.', 403);
        if ($mode === 'code') {
            usleep(400000);
            if (!hash_equals((string) cfg('admin_signup_code'), (string) ($in['code'] ?? ''))) {
                auth_record('fail'); fail('Invalid admin access code.', 403, ['code' => 'That access code is not valid.']);
            }
        }
        $name  = clean($in['name'] ?? '', 120);
        $user  = clean($in['username'] ?? '', 60);
        $email = strtolower(clean($in['email'] ?? '', 160));
        $pass  = (string) ($in['password'] ?? '');
        $e = [];
        if (mb_strlen($name) < 3)                       $e['name']     = 'Please enter your full name.';
        if (!preg_match('/^[A-Za-z0-9_.-]{3,40}$/', $user)) $e['username'] = '3–40 characters: letters, numbers, dot, dash, underscore.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $e['email']    = 'Enter a valid email address.';
        if ($msg = valid_password($pass))               $e['password'] = $msg;
        if ($e) fail('Please fix the highlighted fields.', 422, $e);

        $pdo = db();
        $x = $pdo->prepare('SELECT username, email FROM admins WHERE username = ? OR email = ?'); $x->execute([$user, $email]);
        if ($dup = $x->fetch())
            fail('That username or email is already used by an admin.', 409, strcasecmp($dup['username'], $user) === 0 ? ['username' => 'Username already taken.'] : ['email' => 'Email already registered.']);

        $pdo->prepare('INSERT INTO admins (username, full_name, email, password_hash, last_login) VALUES (?,?,?,?,NOW())')
            ->execute([$user, $name, $email, password_hash($pass, PASSWORD_DEFAULT)]);
        auth_record('signup');
        sess_open('admin'); session_regenerate_id(true);
        $_SESSION['admin'] = $user;
        json_out(['ok' => true, 'role' => 'admin', 'name' => $name, 'redirect' => 'backend/admin/']);
    }

    case 'admin_login': {
        too_many_logins(); usleep(400000);
        $login = clean($in['login'] ?? '', 160);
        $st = db()->prepare('SELECT username, full_name, password_hash FROM admins WHERE username = ? OR email = ? LIMIT 1');
        $st->execute([$login, strtolower($login)]);
        $a = $st->fetch();
        if (!$a || !password_verify((string) ($in['password'] ?? ''), $a['password_hash'])) {
            auth_record('fail'); fail('Wrong username/email or password.', 401);
        }
        sess_open('admin'); session_regenerate_id(true);
        $_SESSION['admin'] = $a['username'];
        db()->prepare('UPDATE admins SET last_login = NOW() WHERE username = ?')->execute([$a['username']]);
        json_out(['ok' => true, 'role' => 'admin', 'name' => $a['full_name'] ?: $a['username'], 'redirect' => 'backend/admin/']);
    }

    /* ───────────── SIGN OUT ───────────── */
    case 'logout': {
        $role = ($in['role'] ?? '') === 'admin' ? 'admin' : 'customer';
        sess_open($role);
        $_SESSION = [];
        $p = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'httponly' => true, 'samesite' => $p['samesite'] ?? 'Lax']);
        session_destroy();
        json_out(['ok' => true]);
    }

    default: fail('Unknown action.', 400);
}
