<?php
require __DIR__ . '/_auth.php'; admin_required(); $pdo = db();
$brands = array_column($pdo->query('SELECT DISTINCT brand FROM products ORDER BY brand')->fetchAll(), 'brand');
$edit = (int) ($_GET['edit'] ?? 0); $new = isset($_GET['new']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') { csrf_check();
    if (isset($_POST['toggle'])) {
        $pdo->prepare('UPDATE products SET active = 1 - active WHERE id = ?')->execute([(int) $_POST['toggle']]);
        log_activity('product_toggle', '#' . (int) $_POST['toggle']); flash_set('ok', 'Product visibility updated.');
    } elseif (isset($_POST['bulk'], $_POST['ids']) && in_array($_POST['bulk'], ['on', 'off'], true)) {
        $ids = array_map('intval', (array) $_POST['ids']); $in = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("UPDATE products SET active = ? WHERE id IN ($in)")->execute([$_POST['bulk'] === 'on' ? 1 : 0, ...$ids]);
        log_activity('product_bulk', count($ids) . ' product(s)', $_POST['bulk']); flash_set('ok', count($ids) . ' product(s) updated.');
    } elseif (isset($_POST['save'])) {
        $pid = (int) ($_POST['pid'] ?? 0); $brand = clean($_POST['brand'] ?? '', 40); $name = clean($_POST['name'] ?? '', 160);
        $price = parse_price($_POST['price'] ?? ''); $img = sanitize_img(clean($_POST['image'] ?? '', 500));
        if ($brand === '' || mb_strlen($name) < 3 || $price < 1) flash_set('err', 'Brand, a product name and a price are required.');
        else {
            try {
                if ($pid) { $pdo->prepare('UPDATE products SET brand=?, name=?, price=?, image=? WHERE id=?')->execute([$brand, $name, $price, $img, $pid]); log_activity('product_edit', $name, 'price=' . $price); flash_set('ok', 'Product saved.'); }
                else { $pdo->prepare('INSERT INTO products (brand,name,price,image) VALUES (?,?,?,?)')->execute([$brand, $name, $price, $img]); log_activity('product_add', $name); flash_set('ok', 'Product added.'); }
                redirect('products.php');
            } catch (PDOException $e) { flash_set('err', ($e->errorInfo[1] ?? 0) == 1062 ? 'A product with this name and price already exists.' : 'Could not save the product.'); }
            redirect('products.php?' . ($pid ? 'edit=' . $pid : 'new=1'));
        }
        redirect('products.php?' . ($pid ? 'edit=' . $pid : 'new=1'));
    }
    redirect('products.php' . qs([], $_GET));
}

$q = clean($_GET['q'] ?? '', 80); $brand = in_array($_GET['brand'] ?? '', $brands, true) ? $_GET['brand'] : '';
$vis = in_array($_GET['vis'] ?? '', ['on', 'off'], true) ? $_GET['vis'] : '';
$sort = in_array($_GET['sort'] ?? '', ['name', 'name_desc', 'price', 'price_desc', 'sold', 'sold_desc', 'rev', 'rev_desc'], true) ? $_GET['sort'] : 'name';
$page = max(1, (int) ($_GET['p'] ?? 1)); $per = 30;
$w = []; $a = [];
if ($q !== '') { $w[] = '(p.name LIKE ? OR p.brand LIKE ?)'; array_push($a, "%$q%", "%$q%"); } if ($brand) { $w[] = 'p.brand = ?'; $a[] = $brand; } if ($vis) $w[] = 'p.active = ' . ($vis === 'on' ? 1 : 0);
$ws = $w ? 'WHERE ' . implode(' AND ', $w) : '';
$ord = ['name' => 'p.name ASC', 'name_desc' => 'p.name DESC', 'price' => 'p.price ASC', 'price_desc' => 'p.price DESC', 'sold' => 'sold ASC', 'sold_desc' => 'sold DESC', 'rev' => 'rev ASC', 'rev_desc' => 'rev DESC'][$sort];
$cnt = $pdo->prepare("SELECT COUNT(*) FROM products p $ws"); $cnt->execute($a); $total = (int) $cnt->fetchColumn();
$st = $pdo->prepare("SELECT p.*, COALESCE(SUM(CASE WHEN o.status<>'cancelled' THEN oi.qty END),0) sold, COALESCE(SUM(CASE WHEN o.status<>'cancelled' THEN oi.line_total END),0) rev,
    (SELECT COUNT(*) FROM wishlist w WHERE w.product_id = p.id) wished
    FROM products p LEFT JOIN order_items oi ON oi.product_id = p.id LEFT JOIN orders o ON o.id = oi.order_id $ws GROUP BY p.id ORDER BY $ord LIMIT $per OFFSET " . (($page - 1) * $per));
$st->execute($a); $rows = $st->fetchAll();
$k = $pdo->query('SELECT COUNT(*) n, COALESCE(SUM(active),0) on_n, COUNT(DISTINCT brand) b, COALESCE(AVG(price),0) avg_p FROM products')->fetch();
$ep = null; if ($edit) { $x = $pdo->prepare('SELECT * FROM products WHERE id = ?'); $x->execute([$edit]); $ep = $x->fetch(); }
$keep = array_filter(['q' => $q, 'brand' => $brand, 'vis' => $vis, 'sort' => $sort !== 'name' ? $sort : '']);

admin_head('Products', 'products', ['sub' => 'Your catalogue as customers can order it.', 'actions' => '<a class="pbtn" href="products.php?new=1"><i class="bi bi-plus-lg"></i>Add product</a><a class="pbtn ghost" href="export.php?type=products"><i class="bi bi-download"></i>Export CSV</a>']); ?>
<div class="pill-note"><i class="bi bi-info-circle"></i><div>Checkout accepts a product only when its <b>name and price match this list exactly</b>. If you change a name or price here, update the matching product card in the storefront HTML too – otherwise customers holding the old price in their cart will be asked to remove it. Hiding a product blocks new orders for it immediately.</div></div>

<?php if ($ep || $new): $p = $ep ?: ['id' => 0, 'brand' => '', 'name' => '', 'price' => '', 'image' => '']; ?>
<form method="post" class="pcard" style="margin-bottom:24px"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="pid" value="<?= (int) $p['id'] ?>">
 <h4><span><i class="bi bi-pencil-square"></i><?= $p['id'] ? 'Edit product' : 'Add product' ?></span><a href="products.php">Cancel</a></h4>
 <div class="formgrid three"><div class="fld"><label>Brand</label><input name="brand" list="brandlist" required maxlength="40" value="<?= h($p['brand']) ?>"><datalist id="brandlist"><?php foreach ($brands as $b): ?><option value="<?= h($b) ?>"><?php endforeach; ?></datalist></div>
  <div class="fld"><label>Name</label><input name="name" required maxlength="160" value="<?= h($p['name']) ?>"></div>
  <div class="fld"><label>Price (Rs.)</label><input name="price" required inputmode="numeric" value="<?= h($p['price']) ?>"></div>
  <div class="fld" style="grid-column:1/-1"><label>Image URL (https)</label><input name="image" maxlength="500" value="<?= h($p['image']) ?>" placeholder="https://…"></div></div>
 <button name="save" value="1"><i class="bi bi-check2-circle"></i>Save product</button></form>
<?php endif; ?>

<div class="kpis"><div class="kpi"><span class="ico"><i class="bi bi-boxes"></i></span><div><small>Products</small><b><?= (int) $k['n'] ?></b><em><?= (int) $k['b'] ?> brands</em></div></div>
 <div class="kpi"><span class="ico"><i class="bi bi-eye"></i></span><div><small>Visible</small><b><?= (int) $k['on_n'] ?></b><em><?= (int) ($k['n'] - $k['on_n']) ?> hidden</em></div></div>
 <div class="kpi"><span class="ico"><i class="bi bi-tag"></i></span><div><small>Average price</small><b><?= money($k['avg_p']) ?></b></div></div></div>
<form class="fbar" method="get"><div class="f wide"><label>Search</label><input name="q" value="<?= h($q) ?>" placeholder="Product or brand"></div>
 <div class="f"><label>Brand</label><select name="brand"><option value="">All</option><?php foreach ($brands as $b): ?><option <?= $b === $brand ? 'selected' : '' ?>><?= h($b) ?></option><?php endforeach; ?></select></div>
 <div class="f"><label>Visibility</label><select name="vis"><option value="">All</option><option value="on" <?= $vis === 'on' ? 'selected' : '' ?>>Visible</option><option value="off" <?= $vis === 'off' ? 'selected' : '' ?>>Hidden</option></select></div>
 <div class="fx"><button>Filter</button><a class="pbtn ghost" href="products.php">Reset</a></div></form>
<form method="post" data-bulk><input type="hidden" name="csrf" value="<?= csrf() ?>">
<div class="bulkbar"><span class="bulk-count">0 selected</span><button name="bulk" value="on" class="sm ghostb">Show</button><button name="bulk" value="off" class="sm ghostb">Hide</button></div>
<div class="tablewrap"><table><tr><th class="chk"><input type="checkbox" data-bulk-all aria-label="Select all"></th><?= sort_th('Product', 'name', $sort) ?><?= sort_th('Price', 'price', $sort, 'r') ?><?= sort_th('Sold', 'sold', $sort, 'r') ?><?= sort_th('Revenue', 'rev', $sort, 'r') ?><th class="r">Wishlists</th><th>Status</th><th></th></tr>
<?php foreach ($rows as $p): ?><tr><td class="chk"><input type="checkbox" name="ids[]" value="<?= $p['id'] ?>" data-bulk-row aria-label="Select"></td>
 <td><div class="itemrow"><?= $p['image'] ? '<img src="' . h($p['image']) . '" alt="" loading="lazy">' : '<span class="noimg"><i class="bi bi-lamp"></i></span>' ?><div><b><?= h($p['name']) ?></b><br><small class="mut"><?= h($p['brand']) ?></small></div></div></td>
 <td class="r nw"><b><?= money($p['price']) ?></b></td><td class="r"><?= (int) $p['sold'] ?></td><td class="r nw"><?= money($p['rev']) ?></td><td class="r"><?= (int) $p['wished'] ?></td>
 <td><span class="pbadge <?= $p['active'] ? 's-active' : 's-disabled' ?>"><?= $p['active'] ? 'visible' : 'hidden' ?></span></td>
 <td><div class="row-actions"><a class="ibtn-link" href="products.php?edit=<?= $p['id'] ?>" title="Edit"><i class="bi bi-pencil"></i></a>
  <button class="ibtn" name="toggle" value="<?= $p['id'] ?>" title="<?= $p['active'] ? 'Hide' : 'Show' ?>"><i class="bi bi-eye<?= $p['active'] ? '-slash' : '' ?>"></i></button></div></td></tr>
<?php endforeach; if (!$rows) echo '<tr><td colspan="8" class="empty"><i class="bi bi-boxes"></i>No products found.</td></tr>'; ?></table></div></form>
<?= paginate($total, $page, $per, $keep) ?>
<?php admin_foot();
