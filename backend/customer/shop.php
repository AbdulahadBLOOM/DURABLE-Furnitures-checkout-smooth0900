<?php
require __DIR__ . '/_auth.php'; $c = customer_required(); $pdo = db(); $cid = (int) $c['id'];
$brands = array_column($pdo->query('SELECT brand, COUNT(*) n FROM products WHERE active = 1 GROUP BY brand ORDER BY brand')->fetchAll(), 'n', 'brand');
$q = clean($_GET['q'] ?? '', 60); $brand = isset($brands[$_GET['brand'] ?? '']) ? $_GET['brand'] : '';
$min = (int) preg_replace('/\D/', '', (string) ($_GET['min'] ?? '')); $max = (int) preg_replace('/\D/', '', (string) ($_GET['max'] ?? ''));
$sort = in_array($_GET['sort'] ?? '', ['price', 'price_desc', 'name', 'popular'], true) ? $_GET['sort'] : 'popular';
$only = ($_GET['saved'] ?? '') === '1';
$page = max(1, (int) ($_GET['p'] ?? 1)); $per = 24;
$w = ['p.active = 1']; $a = [];
if ($q !== '') { $w[] = '(p.name LIKE ? OR p.brand LIKE ?)'; array_push($a, "%$q%", "%$q%"); }
if ($brand) { $w[] = 'p.brand = ?'; $a[] = $brand; } if ($min) { $w[] = 'p.price >= ?'; $a[] = $min; } if ($max) { $w[] = 'p.price <= ?'; $a[] = $max; }
if ($only) $w[] = 'EXISTS (SELECT 1 FROM wishlist x WHERE x.product_id = p.id AND x.customer_id = ' . $cid . ')';
$ws = implode(' AND ', $w);
$ord = ['price' => 'p.price ASC', 'price_desc' => 'p.price DESC', 'name' => 'p.name ASC', 'popular' => 'sold DESC, p.name ASC'][$sort];
$cnt = $pdo->prepare("SELECT COUNT(*) FROM products p WHERE $ws"); $cnt->execute($a); $total = (int) $cnt->fetchColumn();
$st = $pdo->prepare("SELECT p.*, (SELECT COALESCE(SUM(oi.qty),0) FROM order_items oi WHERE oi.product_id = p.id) sold,
    EXISTS (SELECT 1 FROM wishlist x WHERE x.product_id = p.id AND x.customer_id = $cid) saved,
    (SELECT ROUND(AVG(rating),1) FROM reviews r WHERE r.product_id = p.id AND r.status = 'approved') avg_r, (SELECT COUNT(*) FROM reviews r WHERE r.product_id = p.id AND r.status = 'approved') rev_n FROM products p WHERE $ws ORDER BY $ord LIMIT $per OFFSET " . (($page - 1) * $per));
$st->execute($a); $rows = $st->fetchAll();
$keep = array_filter(['q' => $q, 'brand' => $brand, 'min' => $min ?: '', 'max' => $max ?: '', 'sort' => $sort !== 'popular' ? $sort : '', 'saved' => $only ? 1 : '']);
$cartBtn = '<a class="pbtn ghost cartfab" href="../../checkout.html"><i class="bi bi-cart3"></i>Cart<span data-cartcount hidden>0</span></a>';
customer_head('Browse catalogue', 'shop', ['sub' => 'Every piece we sell – save favourites and add them straight to your cart.', 'actions' => $cartBtn]); ?>
<form class="fbar" method="get">
 <div class="f wide"><label>Search</label><input name="q" value="<?= h($q) ?>" placeholder="Sofa, bed, dining table…"></div>
 <div class="f"><label>Brand</label><select name="brand" data-autosubmit><option value="">All brands</option><?php foreach ($brands as $b => $n): ?><option value="<?= h($b) ?>" <?= $b === $brand ? 'selected' : '' ?>><?= h($b) ?> (<?= $n ?>)</option><?php endforeach; ?></select></div>
 <div class="f" style="min-width:110px"><label>Min Rs.</label><input name="min" inputmode="numeric" value="<?= $min ?: '' ?>"></div><div class="f" style="min-width:110px"><label>Max Rs.</label><input name="max" inputmode="numeric" value="<?= $max ?: '' ?>"></div>
 <div class="f"><label>Sort</label><select name="sort" data-autosubmit><option value="popular">Most popular</option><option value="price" <?= $sort === 'price' ? 'selected' : '' ?>>Price: low to high</option><option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : '' ?>>Price: high to low</option><option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>Name A–Z</option></select></div>
 <label class="checkrow" style="margin:0 0 8px"><input type="checkbox" name="saved" value="1" <?= $only ? 'checked' : '' ?> data-autosubmit> Saved only</label>
 <div class="fx"><button><i class="bi bi-search"></i>Search</button><a class="pbtn ghost" href="shop.php">Reset</a></div></form>
<p class="mut" style="margin:0 4px 14px;font-size:.86rem"><?= number_format($total) ?> product(s)</p>
<?php if ($rows): ?><div class="prod-grid"><?php foreach ($rows as $p): $j = h(json_encode(['brand' => $p['brand'], 'name' => $p['name'], 'price' => (int) $p['price'], 'img' => $p['image'], 'qty' => 1])); ?>
 <div class="pcard-prod"><button type="button" class="heart <?= $p['saved'] ? 'on' : '' ?>" data-wish="<?= $p['id'] ?>" data-url="wishlist.php" data-csrf="<?= csrf() ?>" aria-label="Save to wishlist"><i class="bi bi-heart<?= $p['saved'] ? '-fill' : '' ?>"></i></button>
  <div class="pimg"><?= $p['image'] ? '<img src="' . h($p['image']) . '" alt="' . h($p['name']) . '" loading="lazy">' : '<i class="bi bi-lamp"></i>' ?></div>
  <div class="pbody"><span class="pbrand"><?= h($p['brand']) ?></span><h6><?= h($p['name']) ?></h6><?= $p['rev_n'] ? '<span>' . stars((float) $p['avg_r'], 'sm') . ' <small class="mut">' . $p['avg_r'] . ' (' . (int) $p['rev_n'] . ')</small></span>' : '' ?><span class="pprice"><?= money($p['price']) ?></span>
   <div class="pact"><button data-addcart="<?= $j ?>"><i class="bi bi-cart-plus"></i>Add to cart</button></div></div></div><?php endforeach; ?></div>
<?= paginate($total, $page, $per, $keep) ?>
<?php else: ?><div class="pcard"><div class="empty"><i class="bi bi-search"></i>No products match your filters.<br><a href="shop.php">Clear filters</a></div></div><?php endif; ?>
<?php customer_foot();
