<?php
require __DIR__ . '/_auth.php'; admin_required(); $pdo = db();
$tab = ($_GET['tab'] ?? 'products') === 'site' ? 'site' : 'products';
if ($_SERVER['REQUEST_METHOD'] === 'POST') { csrf_check();
    $ids = array_slice(array_map('intval', (array) ($_POST['ids'] ?? [])), 0, 100);
    if (isset($_POST['bulk']) && $ids) {
        $in = implode(',', array_fill(0, count($ids), '?')); $b = (string) $_POST['bulk'];
        if (in_array($b, ['approved', 'hidden', 'pending'], true)) $pdo->prepare("UPDATE reviews SET status = ? WHERE id IN ($in)")->execute([$b, ...$ids]);
        elseif ($b === 'delete') $pdo->prepare("DELETE FROM reviews WHERE id IN ($in)")->execute($ids);
        log_activity('review_bulk', count($ids) . ' review(s)', $b); flash_set('ok', count($ids) . ' review(s) updated.');
    } elseif (isset($_POST['set_status'])) {
        $pdo->prepare('UPDATE reviews SET status = ? WHERE id = ?')->execute([in_array($_POST['status'] ?? '', ['approved', 'hidden', 'pending'], true) ? $_POST['status'] : 'pending', (int) $_POST['set_status']]); flash_set('ok', 'Review updated.');
    } elseif (isset($_POST['reply_to'])) {
        $rid = (int) $_POST['reply_to']; $txt = clean($_POST['reply_' . $rid] ?? '', 1500);
        $pdo->prepare('UPDATE reviews SET admin_reply = ?, replied_at = ' . ($txt === '' ? 'NULL' : 'NOW()') . ' WHERE id = ?')->execute([$txt === '' ? null : $txt, $rid]);
        log_activity('review_reply', '#' . $rid); flash_set('ok', $txt === '' ? 'Reply removed.' : 'Reply published under the review.');
    } elseif (isset($_POST['fb_status'])) {
        $pdo->prepare('UPDATE site_feedback SET status = ? WHERE id = ?')->execute([in_array($_POST['status'] ?? '', ['new', 'reviewed', 'hidden'], true) ? $_POST['status'] : 'reviewed', (int) $_POST['fb_status']]); flash_set('ok', 'Feedback updated.');
    } elseif (isset($_POST['fb_del'])) { $pdo->prepare('DELETE FROM site_feedback WHERE id = ?')->execute([(int) $_POST['fb_del']]); flash_set('ok', 'Feedback deleted.'); }
    redirect('reviews.php' . qs([], $_GET));
}
$st = $pdo->query("SELECT COUNT(*) n, COALESCE(AVG(CASE WHEN status='approved' THEN rating END),0) avg, COALESCE(SUM(status='pending'),0) pend, COALESCE(SUM(status='hidden'),0) hid FROM reviews")->fetch();
$dist = array_fill(1, 5, 0); foreach ($pdo->query("SELECT rating, COUNT(*) n FROM reviews WHERE status='approved' GROUP BY rating") as $r) $dist[(int) $r['rating']] = (int) $r['n'];
$sf = $pdo->query("SELECT COUNT(*) n, COALESCE(AVG(rating),0) avg, COALESCE(SUM(status='new'),0) fresh FROM site_feedback")->fetch();
$page = max(1, (int) ($_GET['p'] ?? 1)); $per = 20;

admin_head('Reviews & ratings', 'reviews', ['sub' => 'Moderate product reviews and read what customers think of the website.']); ?>
<div class="kpis"><div class="kpi hl"><span class="ico"><i class="bi bi-star-fill"></i></span><div><small>Product rating</small><b><?= number_format((float) $st['avg'], 1) ?> / 5</b><em><?= (int) $st['n'] ?> review(s)</em></div></div>
 <div class="kpi"><span class="ico"><i class="bi bi-hourglass-split"></i></span><div><small>Awaiting approval</small><b><?= (int) $st['pend'] ?></b><em><?= (int) $st['hid'] ?> hidden</em></div></div>
 <div class="kpi"><span class="ico"><i class="bi bi-window-star"></i></span><div><small>Website rating</small><b><?= number_format((float) $sf['avg'], 1) ?> / 5</b><em><?= (int) $sf['n'] ?> note(s), <?= (int) $sf['fresh'] ?> new</em></div></div></div>
<div class="stabs"><a href="?tab=products" class="<?= $tab === 'products' ? 'on' : '' ?>">Product reviews <b><?= (int) $st['n'] ?></b></a><a href="?tab=site" class="<?= $tab === 'site' ? 'on' : '' ?>">Website feedback <b><?= (int) $sf['n'] ?></b></a></div>

<?php if ($tab === 'products'):
 $status = in_array($_GET['status'] ?? '', ['approved', 'pending', 'hidden'], true) ? $_GET['status'] : ''; $rt = (int) ($_GET['rating'] ?? 0); $q = clean($_GET['q'] ?? '', 60);
 $w = []; $a = []; if ($status) { $w[] = 'r.status = ?'; $a[] = $status; } if ($rt >= 1 && $rt <= 5) { $w[] = 'r.rating = ?'; $a[] = $rt; }
 if ($q !== '') { $w[] = '(p.name LIKE ? OR c.full_name LIKE ? OR r.body LIKE ?)'; array_push($a, "%$q%", "%$q%", "%$q%"); }
 $ws = $w ? 'WHERE ' . implode(' AND ', $w) : '';
 $cnt = $pdo->prepare("SELECT COUNT(*) FROM reviews r JOIN products p ON p.id = r.product_id JOIN customers c ON c.id = r.customer_id $ws"); $cnt->execute($a); $total = (int) $cnt->fetchColumn();
 $rs = $pdo->prepare("SELECT r.*, p.name pname, p.brand, c.full_name, c.id cid FROM reviews r JOIN products p ON p.id = r.product_id JOIN customers c ON c.id = r.customer_id $ws ORDER BY (r.status='pending') DESC, r.id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
 $rs->execute($a); $rows = $rs->fetchAll(); $keep = array_filter(['tab' => 'products', 'status' => $status, 'rating' => $rt ?: '', 'q' => $q]); ?>
 <div class="cols"><div>
 <form class="fbar" method="get"><input type="hidden" name="tab" value="products"><div class="f wide"><label>Search</label><input name="q" value="<?= h($q) ?>" placeholder="Product, customer, text"></div>
  <div class="f"><label>Status</label><select name="status"><option value="">All</option><?php foreach (['pending', 'approved', 'hidden'] as $s): ?><option <?= $s === $status ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select></div>
  <div class="f"><label>Rating</label><select name="rating"><option value="">Any</option><?php for ($i = 5; $i >= 1; $i--): ?><option value="<?= $i ?>" <?= $i === $rt ? 'selected' : '' ?>><?= $i ?> ★</option><?php endfor; ?></select></div><div class="fx"><button>Filter</button><a class="pbtn ghost" href="reviews.php">Reset</a></div></form>
 <form method="post" data-bulk><input type="hidden" name="csrf" value="<?= csrf() ?>">
 <div class="bulkbar"><span class="bulk-count">0 selected</span><button name="bulk" value="approved" class="sm ghostb">Approve</button><button name="bulk" value="hidden" class="sm ghostb">Hide</button><button name="bulk" value="delete" class="sm danger" data-confirm="Delete selected reviews permanently?" data-confirm-yes="Delete">Delete</button></div>
 <?php if ($rows): ?><label class="checkrow"><input type="checkbox" data-bulk-all> Select all on this page</label><?php endif; ?>
 <?php foreach ($rows as $r): ?><div class="pcard" style="margin-bottom:14px;<?= $r['status'] === 'pending' ? 'border-color:var(--gold)' : '' ?>"><div class="revcard" style="padding:0;border:0"><input type="checkbox" name="ids[]" value="<?= $r['id'] ?>" data-bulk-row aria-label="Select">
  <div class="grow"><h6><?= h($r['pname']) ?> <small class="mut">· <?= h($r['brand']) ?></small></h6><?= stars((float) $r['rating']) ?> <span class="pbadge s-<?= $r['status'] === 'approved' ? 'active' : ($r['status'] === 'hidden' ? 'disabled' : 'pending') ?>"><?= h($r['status']) ?></span>
   <small class="mut"> by <a href="customer.php?id=<?= $r['cid'] ?>"><?= h($r['full_name']) ?></a> · <?= h(dt($r['created_at'])) ?></small>
   <?= $r['title'] ? '<div style="font-weight:600;margin-top:4px">' . h($r['title']) . '</div>' : '' ?><p><?= nl2br(h($r['body'])) ?></p>
   <?php if ($r['admin_reply']): ?><div class="reply"><b>Your reply:</b> <?= nl2br(h($r['admin_reply'])) ?></div><?php endif; ?>
   <details style="margin-top:10px"><summary style="cursor:pointer;color:var(--gold);font-weight:600;font-size:.84rem"><i class="bi bi-reply"></i> <?= $r['admin_reply'] ? 'Edit public reply' : 'Reply publicly' ?></summary>
    <div class="fld" style="margin-top:8px"><textarea name="reply_<?= $r['id'] ?>" rows="3" maxlength="1500" placeholder="Thank the customer or address their concern (shown under the review)"><?= h($r['admin_reply'] ?? '') ?></textarea></div><button class="sm" name="reply_to" value="<?= $r['id'] ?>">Save reply</button></details></div>
  <div class="row-actions" style="flex-direction:column"><?php if ($r['status'] !== 'approved'): ?><button class="ibtn" name="set_status" value="<?= $r['id'] ?>" onclick="this.form.insertAdjacentHTML('beforeend','<input type=hidden name=status value=approved>')" title="Approve"><i class="bi bi-check2"></i></button><?php endif; ?>
   <?php if ($r['status'] !== 'hidden'): ?><button class="ibtn bad" name="set_status" value="<?= $r['id'] ?>" onclick="this.form.insertAdjacentHTML('beforeend','<input type=hidden name=status value=hidden>')" title="Hide"><i class="bi bi-eye-slash"></i></button><?php endif; ?></div></div></div>
 <?php endforeach; if (!$rows) echo '<div class="pcard"><div class="empty slim"><i class="bi bi-star"></i>No reviews found.</div></div>'; ?></form>
 <?= paginate($total, $page, $per, $keep) ?></div>
 <div><div class="pcard avgbox"><h4>Rating breakdown</h4><div class="big"><?= number_format((float) $st['avg'], 1) ?></div><?= stars((float) $st['avg'], 'lg') ?>
  <div style="margin-top:14px;text-align:left"><?php $dm = max(1, ...array_values($dist)); for ($i = 5; $i >= 1; $i--): ?><div class="dist"><span><?= $i ?>★</span><span class="tr"><i style="width:<?= round($dist[$i] / $dm * 100) ?>%"></i></span><b><?= $dist[$i] ?></b></div><?php endfor; ?></div></div></div></div>

<?php else:
 $cnt = (int) $sf['n'];
 $rs = $pdo->prepare('SELECT f.*, c.full_name, c.id cid FROM site_feedback f JOIN customers c ON c.id = f.customer_id ORDER BY (f.status = \'new\') DESC, f.id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per)); $rs->execute(); $rows = $rs->fetchAll();
 $byCat = $pdo->query('SELECT category, ROUND(AVG(rating),1) a, COUNT(*) n FROM site_feedback GROUP BY category ORDER BY a DESC')->fetchAll(); ?>
 <div class="cols"><form method="post"><input type="hidden" name="csrf" value="<?= csrf() ?>">
 <?php foreach ($rows as $f): ?><div class="pcard" style="margin-bottom:14px;<?= $f['status'] === 'new' ? 'border-color:var(--gold)' : '' ?>"><div class="prow" style="margin-bottom:6px"><?= stars((float) $f['rating']) ?><span class="chip mute"><?= h(FEEDBACK_CATEGORIES[$f['category']] ?? $f['category']) ?></span>
  <?= $f['status'] === 'new' ? '<span class="chip">new</span>' : '' ?><?= $f['allow_public'] ? '<span class="chip ok"><i class="bi bi-globe"></i>may be shown publicly</span>' : '' ?>
  <small class="mut"><a href="customer.php?id=<?= $f['cid'] ?>"><?= h($f['full_name']) ?></a> · <?= h(dt($f['created_at'])) ?></small></div>
  <p style="margin:0 0 10px;color:var(--text-mid);line-height:1.7"><?= nl2br(h($f['message'])) ?></p>
  <div class="row-actions" style="justify-content:flex-start"><?php if ($f['status'] !== 'reviewed'): ?><button class="sm ghostb" name="fb_status" value="<?= $f['id'] ?>" onclick="this.form.insertAdjacentHTML('beforeend','<input type=hidden name=status value=reviewed>')">Mark reviewed</button><?php endif; ?>
   <?php if ($f['allow_public'] && $f['status'] !== 'hidden'): ?><button class="sm ghostb" name="fb_status" value="<?= $f['id'] ?>" onclick="this.form.insertAdjacentHTML('beforeend','<input type=hidden name=status value=hidden>')">Hide from website</button><?php endif; ?>
   <button class="ibtn bad" name="fb_del" value="<?= $f['id'] ?>" data-confirm="Delete this feedback?" data-confirm-yes="Delete"><i class="bi bi-trash3"></i></button></div></div>
 <?php endforeach; if (!$rows) echo '<div class="pcard"><div class="empty slim"><i class="bi bi-window-star"></i>No website feedback yet.</div></div>'; ?></form>
 <div><div class="pcard"><h4>Average by topic</h4><?php foreach ($byCat as $b): ?><div class="dist"><span style="min-width:120px"><?= h(FEEDBACK_CATEGORIES[$b['category']] ?? $b['category']) ?></span><span class="tr"><i style="width:<?= $b['a'] * 20 ?>%"></i></span><b><?= $b['a'] ?></b></div><?php endforeach; if (!$byCat) echo '<p class="mut">No data yet.</p>'; ?></div></div></div>
 <?= paginate($cnt, $page, $per, ['tab' => 'site']) ?>
<?php endif; ?>
<?php admin_foot();
