<?php
/** Public, read-only: approved customer reviews for the storefront. GET ?limit=6  or  GET ?product=<exact name> */
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_out(['ok' => false, 'error' => 'GET only'], 405);
header('Cache-Control: public, max-age=300');
$pdo = db();
$who = function (string $name): string { $p = preg_split('/\s+/', trim($name)); return $p[0] . (isset($p[1]) ? ' ' . mb_substr($p[1], 0, 1) . '.' : ''); };   // "Ayesha K." – never the full name
$product = clean($_GET['product'] ?? '', 160);

if ($product !== '') {
    $st = $pdo->prepare("SELECT r.rating, r.title, r.body, r.admin_reply, r.created_at, c.full_name FROM reviews r JOIN products p ON p.id = r.product_id JOIN customers c ON c.id = r.customer_id
        WHERE p.name = ? AND r.status = 'approved' ORDER BY r.id DESC LIMIT 20");
    $st->execute([$product]); $rows = $st->fetchAll();
    $avg = $rows ? round(array_sum(array_column($rows, 'rating')) / count($rows), 1) : 0;
    json_out(['ok' => true, 'average' => $avg, 'count' => count($rows), 'reviews' => array_map(fn($r) => ['rating' => (int) $r['rating'], 'title' => $r['title'], 'body' => $r['body'], 'reply' => $r['admin_reply'], 'date' => substr($r['created_at'], 0, 10), 'name' => $who($r['full_name'])], $rows)]);
}
$limit = max(1, min(12, (int) ($_GET['limit'] ?? 6)));
$all = $pdo->query("SELECT COUNT(*) n, COALESCE(AVG(rating),0) a FROM reviews WHERE status = 'approved'")->fetch();
$st = $pdo->query("SELECT r.rating, r.title, r.body, r.created_at, c.full_name, p.name product FROM reviews r JOIN customers c ON c.id = r.customer_id JOIN products p ON p.id = r.product_id
    WHERE r.status = 'approved' AND r.rating >= 4 AND CHAR_LENGTH(r.body) >= 20 ORDER BY r.id DESC LIMIT 30");
$items = array_map(fn($r) => ['rating' => (int) $r['rating'], 'text' => $r['body'], 'name' => $who($r['full_name']), 'about' => $r['product'], 'date' => $r['created_at']], $st->fetchAll());
$st = $pdo->query("SELECT f.rating, f.message, f.created_at, c.full_name FROM site_feedback f JOIN customers c ON c.id = f.customer_id WHERE f.allow_public = 1 AND f.status <> 'hidden' AND f.rating >= 4 AND CHAR_LENGTH(f.message) >= 20 ORDER BY f.id DESC LIMIT 20");
foreach ($st as $r) $items[] = ['rating' => (int) $r['rating'], 'text' => $r['message'], 'name' => $who($r['full_name']), 'about' => 'Durable Furnitures', 'date' => $r['created_at']];
usort($items, fn($a, $b) => strcmp($b['date'], $a['date']));
json_out(['ok' => true, 'average' => round((float) $all['a'], 1), 'count' => (int) $all['n'], 'items' => array_slice($items, 0, $limit)]);
