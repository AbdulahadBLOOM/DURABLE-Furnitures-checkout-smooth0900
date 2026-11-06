<?php
require __DIR__ . '/_auth.php'; $c = customer_required(); $pdo = db(); $cid = (int) $c['id'];
$auto = cfg('reviews_auto_approve') !== false;

/** Products from DELIVERED orders only (verified purchase). */
$el = $pdo->prepare("SELECT oi.product_id, MAX(o.id) order_id, MAX(o.order_number) order_number, MAX(o.created_at) bought, p.name, p.brand, p.image
    FROM order_items oi JOIN orders o ON o.id = oi.order_id JOIN products p ON p.id = oi.product_id
    WHERE o.customer_id = ? AND o.status = 'delivered' GROUP BY oi.product_id, p.name, p.brand, p.image ORDER BY bought DESC");
$el->execute([$cid]); $eligible = array_column($el->fetchAll(), null, 'product_id');

if ($_SERVER['REQUEST_METHOD'] === 'POST') { csrf_check();
    if (isset($_POST['save_review'])) {
        $pid = (int) ($_POST['product_id'] ?? 0); $rating = (int) ($_POST['rating'] ?? 0); $title = clean($_POST['title'] ?? '', 120); $body = clean($_POST['body'] ?? '', 2000);
        if (!isset($eligible[$pid])) flash_set('err', 'You can review a product after your order with it has been delivered.');
        elseif ($rating < 1 || $rating > 5) flash_set('err', 'Please choose a star rating.');
        elseif (mb_strlen($body) < 10) flash_set('err', 'Please write at least a short sentence (10+ characters) about the product.');
        else {
            $pdo->prepare('INSERT INTO reviews (customer_id, product_id, order_id, rating, title, body, status) VALUES (?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE rating = VALUES(rating), title = VALUES(title), body = VALUES(body), status = VALUES(status)')
                ->execute([$cid, $pid, $eligible[$pid]['order_id'], $rating, $title, $body, $auto ? 'approved' : 'pending']);
            log_activity('review_save', $eligible[$pid]['name'], $rating . ' stars', 'customer', (string) $cid);
            flash_set('ok', $auto ? 'Thank you! Your review is live.' : 'Thank you! Your review will appear once our team approves it.');
        }
    } elseif (isset($_POST['delete_review'])) {
        $pdo->prepare('DELETE FROM reviews WHERE id = ? AND customer_id = ?')->execute([(int) $_POST['delete_review'], $cid]); flash_set('ok', 'Review deleted.');
    } elseif (isset($_POST['save_feedback'])) {
        $rating = (int) ($_POST['rating'] ?? 0); $msg = clean($_POST['message'] ?? '', 2000); $cat = isset(FEEDBACK_CATEGORIES[$_POST['category'] ?? '']) ? $_POST['category'] : 'overall';
        $recent = (int) $pdo->query("SELECT COUNT(*) FROM site_feedback WHERE customer_id = $cid AND created_at > (NOW() - INTERVAL 1 DAY)")->fetchColumn();
        if ($rating < 1 || $rating > 5) flash_set('err', 'Please choose a star rating.');
        elseif (mb_strlen($msg) < 5) flash_set('err', 'Please tell us a little about your experience.');
        elseif ($recent >= 3) flash_set('err', 'Thanks! You have sent several notes today – please try again tomorrow.');
        else {
            $pdo->prepare('INSERT INTO site_feedback (customer_id, rating, category, message, allow_public) VALUES (?,?,?,?,?)')->execute([$cid, $rating, $cat, $msg, empty($_POST['allow_public']) ? 0 : 1]);
            log_activity('site_feedback', $cat, $rating . ' stars', 'customer', (string) $cid);
            send_mail(cfg('store_email'), 'New website feedback (' . $rating . '★)', '<p><b>' . h($c['full_name']) . '</b> rated the website <b>' . $rating . '/5</b> (' . h(FEEDBACK_CATEGORIES[$cat]) . ')</p><p>' . nl2br(h($msg)) . '</p>');
            flash_set('ok', 'Thank you for your feedback – it helps us improve the store!');
        }
    }
    redirect('reviews.php' . (isset($_POST['save_feedback']) ? '#site' : ''));
}
$mine = $pdo->prepare('SELECT r.*, p.name, p.brand, p.image FROM reviews r JOIN products p ON p.id = r.product_id WHERE r.customer_id = ? ORDER BY r.updated_at DESC'); $mine->execute([$cid]); $mine = $mine->fetchAll();
$done = array_column($mine, null, 'product_id');
$todo = array_filter($eligible, fn($e, $k) => !isset($done[$k]), ARRAY_FILTER_USE_BOTH);
$fb = $pdo->prepare('SELECT * FROM site_feedback WHERE customer_id = ? ORDER BY id DESC LIMIT 5'); $fb->execute([$cid]); $fb = $fb->fetchAll();
$edit = (int) ($_GET['edit'] ?? 0); $rate = (int) ($_GET['rate'] ?? 0);

customer_head('Reviews & ratings', 'reviews', ['sub' => 'Tell other shoppers – and us – what you think.']); ?>
<div class="tabs" data-tabs><button type="button" class="tab" data-tab="todo"><i class="bi bi-pencil-square"></i> To review <?= count($todo) ? '<span class="nbadge">' . count($todo) . '</span>' : '' ?></button><button type="button" class="tab" data-tab="mine"><i class="bi bi-star"></i> My reviews (<?= count($mine) ?>)</button><button type="button" class="tab" data-tab="site"><i class="bi bi-window-star"></i> Rate our website</button></div>

<section data-pane="todo"><div class="pcard">
<?php if ($todo): ?><p class="mut" style="margin-top:0;font-size:.88rem"><i class="bi bi-patch-check" style="color:var(--gold)"></i> You can review products from orders that have been delivered.</p>
 <?php foreach ($todo as $e): ?><div class="revcard"><div class="rimg"><?= $e['image'] ? '<img src="' . h($e['image']) . '" alt="" loading="lazy">' : '<i class="bi bi-lamp"></i>' ?></div>
  <div class="grow"><h6><?= h($e['name']) ?></h6><small class="mut"><?= h($e['brand']) ?> · order <?= h($e['order_number']) ?></small>
   <details <?= $rate === (int) $e['product_id'] ? 'open' : '' ?>><summary style="cursor:pointer;color:var(--gold);font-weight:600;font-size:.88rem;margin-top:8px"><i class="bi bi-star"></i> Write a review</summary>
   <form method="post" class="rate-form"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="product_id" value="<?= (int) $e['product_id'] ?>">
    <div class="fld"><label>Your rating</label><?= star_input('rating') ?></div><div class="fld"><label>Headline (optional)</label><input name="title" maxlength="120" placeholder="e.g. Sturdy and exactly as pictured"></div>
    <div class="fld"><label>Your review</label><textarea name="body" required rows="4" maxlength="2000" placeholder="Quality, comfort, assembly, delivery…"></textarea></div><button name="save_review" value="1"><i class="bi bi-send"></i>Submit review</button></form></details></div></div><?php endforeach; ?>
<?php else: ?><div class="empty slim"><i class="bi bi-emoji-smile"></i><?= $mine ? 'You have reviewed everything you have received. Thank you!' : 'Nothing to review yet. Once an order is delivered, its products appear here.' ?></div><?php endif; ?></div></section>

<section data-pane="mine" hidden><div class="pcard">
<?php foreach ($mine as $r): ?><div class="revcard"><div class="rimg"><?= $r['image'] ? '<img src="' . h($r['image']) . '" alt="" loading="lazy">' : '<i class="bi bi-lamp"></i>' ?></div>
 <div class="grow"><h6><?= h($r['name']) ?> <span class="pbadge s-<?= $r['status'] === 'approved' ? 'active' : 'pending' ?>"><?= $r['status'] === 'approved' ? 'published' : h($r['status']) ?></span></h6>
  <?= stars((float) $r['rating']) ?> <small class="mut"><?= h(dt($r['updated_at'])) ?></small><?= $r['title'] ? '<div style="font-weight:600;margin-top:4px">' . h($r['title']) . '</div>' : '' ?><p><?= nl2br(h($r['body'])) ?></p>
  <?php if ($r['admin_reply']): ?><div class="reply"><b>Reply from Durable Furnitures:</b> <?= nl2br(h($r['admin_reply'])) ?></div><?php endif; ?>
  <details <?= $edit === (int) $r['id'] ? 'open' : '' ?>><summary style="cursor:pointer;color:var(--gold);font-weight:600;font-size:.84rem;margin-top:8px"><i class="bi bi-pencil"></i> Edit</summary>
   <form method="post" class="rate-form"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="product_id" value="<?= (int) $r['product_id'] ?>">
    <div class="fld"><label>Rating</label><?= star_input('rating', (int) $r['rating']) ?></div><div class="fld"><input name="title" maxlength="120" value="<?= h($r['title']) ?>" placeholder="Headline"></div>
    <div class="fld"><textarea name="body" required rows="4" maxlength="2000"><?= h($r['body']) ?></textarea></div>
    <div class="prow"><button name="save_review" value="1">Save changes</button><button class="danger" name="delete_review" value="<?= $r['id'] ?>" formnovalidate data-confirm="Delete this review?" data-confirm-yes="Delete">Delete</button></div></form></details></div></div><?php endforeach;
if (!$mine) echo '<div class="empty slim"><i class="bi bi-star"></i>You have not written any reviews yet.</div>'; ?></div></section>

<section data-pane="site" hidden><div class="cols even">
 <form method="post" class="pcard"><input type="hidden" name="csrf" value="<?= csrf() ?>"><h4><span><i class="bi bi-window-star"></i>How is our website?</span></h4>
  <div class="fld"><label>Your rating</label><?= star_input('rating') ?></div>
  <div class="fld"><label>What are you rating?</label><select name="category"><?php foreach (FEEDBACK_CATEGORIES as $k => $l): ?><option value="<?= $k ?>"><?= h($l) ?></option><?php endforeach; ?></select></div>
  <div class="fld"><label>Your thoughts</label><textarea id="fbm" name="message" required rows="5" maxlength="2000" placeholder="What did you like? What could we do better?"></textarea><span class="cnt" data-count-for="fbm"></span></div>
  <label class="checkrow"><input type="checkbox" name="allow_public" value="1"> You may show my comment (with my first name) on the website</label>
  <button name="save_feedback" value="1"><i class="bi bi-send"></i>Send feedback</button></form>
 <div class="pcard"><h4>Your recent feedback</h4><ul class="people"><?php foreach ($fb as $f): ?><li><div><?= stars((float) $f['rating'], 'sm') ?> <b><?= h(FEEDBACK_CATEGORIES[$f['category']] ?? '') ?></b><small><?= h(mb_strimwidth($f['message'], 0, 90, '…')) ?> · <?= h(ago($f['created_at'])) ?></small></div></li><?php endforeach;
  if (!$fb) echo '<li class="mut">Nothing sent yet.</li>'; ?></ul></div></div></section>
<?php customer_foot();
