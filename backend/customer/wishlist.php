<?php
require __DIR__ . '/_auth.php'; $c = customer_required(); $pdo = db(); $cid = (int) $c['id'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $ajax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
    if (isset($_POST['toggle'])) {
        $pid = (int) $_POST['toggle'];
        $ok = $pdo->prepare('SELECT 1 FROM products WHERE id = ?'); $ok->execute([$pid]);
        if (!$ok->fetch()) { if ($ajax) { header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => 'Product not found']); exit; } redirect('wishlist.php'); }
        $has = $pdo->prepare('SELECT 1 FROM wishlist WHERE customer_id = ? AND product_id = ?'); $has->execute([$cid, $pid]);
        if ($has->fetch()) { $pdo->prepare('DELETE FROM wishlist WHERE customer_id = ? AND product_id = ?')->execute([$cid, $pid]); $saved = false; }
        else { $pdo->prepare('INSERT IGNORE INTO wishlist (customer_id, product_id) VALUES (?,?)')->execute([$cid, $pid]); $saved = true; }
        if ($ajax) { header('Content-Type: application/json'); echo json_encode(['ok' => true, 'saved' => $saved]); exit; }
    } elseif (isset($_POST['clear'])) { $pdo->prepare('DELETE FROM wishlist WHERE customer_id = ?')->execute([$cid]); flash_set('ok', 'Wishlist cleared.'); }
    redirect('wishlist.php');
}
$st = $pdo->prepare('SELECT p.*, w.created_at saved_at FROM wishlist w JOIN products p ON p.id = w.product_id WHERE w.customer_id = ? ORDER BY w.created_at DESC'); $st->execute([$cid]); $rows = $st->fetchAll();
$live = array_values(array_filter($rows, fn($p) => $p['active']));
$sum = array_sum(array_map(fn($p) => (int) $p['price'], $live));
$all = array_map(fn($p) => ['brand' => $p['brand'], 'name' => $p['name'], 'price' => (int) $p['price'], 'img' => $p['image'], 'qty' => 1], $live);
customer_head('My wishlist', 'wishlist', ['sub' => count($rows) . ' saved piece(s)' . ($live ? ' · ' . money($sum) . ' in total' : '') . '.',
    'actions' => ($live ? '<button type="button" data-reorder="' . h(json_encode($all)) . '" data-go="../../checkout.html"><i class="bi bi-cart-plus"></i>Add all to cart</button>' : '') . '<a class="pbtn ghost" href="shop.php">Browse more</a>']); ?>
<?php if ($rows): ?><div class="prod-grid"><?php foreach ($rows as $p): $j = h(json_encode(['brand' => $p['brand'], 'name' => $p['name'], 'price' => (int) $p['price'], 'img' => $p['image'], 'qty' => 1])); ?>
 <div class="pcard-prod" style="<?= $p['active'] ? '' : 'opacity:.6' ?>"><button type="button" class="heart on" data-wish="<?= $p['id'] ?>" data-url="wishlist.php" data-csrf="<?= csrf() ?>" data-remove="1" aria-label="Remove from wishlist"><i class="bi bi-heart-fill"></i></button>
  <div class="pimg"><?= $p['image'] ? '<img src="' . h($p['image']) . '" alt="" loading="lazy">' : '<i class="bi bi-lamp"></i>' ?></div>
  <div class="pbody"><span class="pbrand"><?= h($p['brand']) ?></span><h6><?= h($p['name']) ?></h6><span class="pprice"><?= money($p['price']) ?></span>
   <div class="pact"><?php if ($p['active']): ?><button data-addcart="<?= $j ?>"><i class="bi bi-cart-plus"></i>Add to cart</button><?php else: ?><span class="chip mute">No longer available</span><?php endif; ?></div></div></div><?php endforeach; ?></div>
<form method="post" style="margin-top:22px"><input type="hidden" name="csrf" value="<?= csrf() ?>"><button class="danger sm" name="clear" value="1" data-confirm="Remove everything from your wishlist?" data-confirm-yes="Clear all">Clear wishlist</button></form>
<?php else: ?><div class="pcard"><div class="empty"><i class="bi bi-heart"></i>Your wishlist is empty.<br>Tap the heart on any product to save it for later.<br><a href="shop.php">Browse the catalogue →</a></div></div><?php endif; ?>
<?php customer_foot();
