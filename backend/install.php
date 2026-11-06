<?php
/**
 * One-time installer. Creates the database, tables, loads the product catalog
 * (data/products.json) and creates the first admin account.
 *
 *   CLI : php install.php admin YourStrongPassword
 *   Web : open /backend/install.php once, fill the form, then DELETE this file.
 */
require __DIR__ . '/db.php';

function run_install(string $user, string $pass): array {
    $log = [];
    $dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', cfg('db_host'), cfg('db_port'));
    $root = new PDO($dsn, cfg('db_user'), cfg('db_pass'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $dbn = preg_replace('/[^A-Za-z0-9_]/', '', cfg('db_name'));
    $root->exec("CREATE DATABASE IF NOT EXISTS `$dbn` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $log[] = "Database `$dbn` ready";

    $pdo = db();
    foreach (array_filter(array_map('trim', explode(';', file_get_contents(__DIR__ . '/schema.sql')))) as $sql) {
        $pdo->exec($sql);
    }
    $log[] = 'Tables created';

    $prods = json_decode(file_get_contents(__DIR__ . '/data/products.json'), true) ?: [];
    $ins = $pdo->prepare('INSERT INTO products (brand,name,price,image) VALUES (?,?,?,?)
                          ON DUPLICATE KEY UPDATE brand=VALUES(brand), image=IF(VALUES(image)<>"",VALUES(image),image), active=1');
    foreach ($prods as $p) $ins->execute([$p['brand'], $p['name'], $p['price'], $p['image'] ?? '']);
    $log[] = count($prods) . ' products loaded';

    if (strlen($pass) < 8) throw new RuntimeException('Admin password must be at least 8 characters');
    $pdo->prepare('INSERT INTO admins (username, password_hash) VALUES (?,?)
                   ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)')
        ->execute([$user, password_hash($pass, PASSWORD_DEFAULT)]);
    $log[] = "Admin `$user` saved";

    @mkdir(__DIR__ . '/data/orders', 0755, true);
    @file_put_contents(__DIR__ . '/data/installed.lock', date('c'));
    return $log;
}

if (PHP_SAPI === 'cli') {
    [$u, $p] = [$argv[1] ?? 'admin', $argv[2] ?? ''];
    try { echo implode("\n", run_install($u, $p)) . "\nDone.\n"; }
    catch (Throwable $e) { fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n"); exit(1); }
    exit;
}

// ── Web mode
if (is_file(__DIR__ . '/data/installed.lock')) {
    http_response_code(403);
    exit('Already installed. Delete backend/data/installed.lock to run again (and delete install.php when finished).');
}
$msg = ''; $ok = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $log = run_install(clean($_POST['user'] ?? 'admin', 60), (string) ($_POST['pass'] ?? ''));
        $ok = true; $msg = implode('<br>', array_map('h', $log)) . '<br><b>Now delete backend/install.php.</b> Admin panel: <a href="admin/">admin/</a>';
    } catch (Throwable $e) { $msg = h($e->getMessage()); }
}
?><!doctype html><meta charset="utf-8"><title>Install – Durable Furnitures</title>
<body style="font-family:Arial;max-width:420px;margin:60px auto;background:#14120f;color:#e8e2d8">
<h2>Install backend</h2>
<?php if ($msg): ?><p style="padding:12px;border-radius:8px;background:<?= $ok ? '#14532d' : '#7f1d1d' ?>"><?= $msg ?></p><?php endif; ?>
<?php if (!$ok): ?>
<p style="font-size:13px;color:#aaa">DB settings come from config.php / config.local.php. Choose your admin login:</p>
<form method="post">
  <input name="user" value="admin" style="width:100%;padding:10px;margin:6px 0"><br>
  <input name="pass" type="password" placeholder="Admin password (min 8 chars)" style="width:100%;padding:10px;margin:6px 0"><br>
  <button style="padding:10px 20px;background:#f97316;border:0;border-radius:6px;font-weight:700">Install</button>
</form>
<?php endif; ?>
