<?php
require __DIR__ . '/_auth.php'; admin_required(); $pdo = db();
$filter = in_array($_GET['filter'] ?? '', ['unread', 'read', 'replied'], true) ? $_GET['filter'] : '';
$q = clean($_GET['q'] ?? '', 80); $page = max(1, (int) ($_GET['p'] ?? 1)); $per = 20;

if ($_SERVER['REQUEST_METHOD'] === 'POST') { csrf_check();
    $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
    if (isset($_POST['read']))   $pdo->prepare('UPDATE contact_messages SET is_read = 1 WHERE id = ?')->execute([(int) $_POST['read']]);
    if (isset($_POST['unread'])) $pdo->prepare('UPDATE contact_messages SET is_read = 0 WHERE id = ?')->execute([(int) $_POST['unread']]);
    if (isset($_POST['del']))    { $pdo->prepare('DELETE FROM contact_messages WHERE id = ?')->execute([(int) $_POST['del']]); log_activity('message_delete', '#' . (int) $_POST['del']); flash_set('ok', 'Message deleted.'); }
    if (isset($_POST['bulk']) && $ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        if ($_POST['bulk'] === 'read')   $pdo->prepare("UPDATE contact_messages SET is_read = 1 WHERE id IN ($in)")->execute($ids);
        if ($_POST['bulk'] === 'unread') $pdo->prepare("UPDATE contact_messages SET is_read = 0 WHERE id IN ($in)")->execute($ids);
        if ($_POST['bulk'] === 'delete') { $pdo->prepare("DELETE FROM contact_messages WHERE id IN ($in)")->execute($ids); log_activity('message_delete', count($ids) . ' message(s)'); }
        flash_set('ok', count($ids) . ' message(s) updated.');
    }
    if (isset($_POST['reply_to'])) {
        $mid = (int) $_POST['reply_to']; $body = clean($_POST['reply'] ?? '', 4000);
        $m = $pdo->prepare('SELECT * FROM contact_messages WHERE id = ?'); $m->execute([$mid]); $m = $m->fetch();
        if (!$m || mb_strlen($body) < 3) flash_set('err', 'Write a reply first.');
        else {
            $html = '<div style="font-family:Arial,sans-serif;max-width:620px;margin:auto;color:#222"><h2>' . h(cfg('store_name')) . '</h2><p>Hello ' . h($m['name']) . ',</p><p>' . nl2br(h($body)) . '</p>'
                  . '<hr style="border:0;border-top:1px solid #eee"><p style="color:#777;font-size:12px">In reply to your message:<br><i>' . nl2br(h(mb_substr($m['message'], 0, 600))) . '</i></p><p style="color:#777;font-size:12px">' . h(cfg('store_address')) . ' · ' . h(cfg('store_phone')) . '</p></div>';
            send_mail($m['email'], 'Re: ' . ($m['subject'] ?: 'Your message to ' . cfg('store_name')), $html);
            $pdo->prepare('UPDATE contact_messages SET reply = ?, replied_at = NOW(), replied_by = ?, is_read = 1 WHERE id = ?')->execute([$body, $_SESSION['admin'], $mid]);
            log_activity('message_reply', '#' . $mid, $m['email']); flash_set('ok', 'Reply emailed to ' . $m['email'] . ' and saved.');
        }
    }
    redirect('messages.php' . qs([], $_GET));
}
$w = []; $a = [];
if ($filter === 'unread') $w[] = 'is_read = 0'; if ($filter === 'read') $w[] = 'is_read = 1'; if ($filter === 'replied') $w[] = 'replied_at IS NOT NULL';
if ($q !== '') { $w[] = '(name LIKE ? OR email LIKE ? OR phone LIKE ? OR subject LIKE ? OR message LIKE ?)'; array_push($a, ...array_fill(0, 5, "%$q%")); }
$ws = $w ? 'WHERE ' . implode(' AND ', $w) : '';
$cnt = $pdo->prepare("SELECT COUNT(*) FROM contact_messages $ws"); $cnt->execute($a); $total = (int) $cnt->fetchColumn();
$st = $pdo->prepare("SELECT * FROM contact_messages $ws ORDER BY id DESC LIMIT $per OFFSET " . (($page - 1) * $per)); $st->execute($a); $rows = $st->fetchAll();
$c = $pdo->query('SELECT COUNT(*) n, COALESCE(SUM(is_read=0),0) unread, COALESCE(SUM(replied_at IS NOT NULL),0) replied FROM contact_messages')->fetch();
$keep = array_filter(['filter' => $filter, 'q' => $q]);
$canned = ['Thank you for contacting Durable Furnitures! ', 'Yes, this item is available and we can deliver within 3–5 working days. ', 'Please call or WhatsApp us on ' . cfg('store_phone') . ' and we will help you right away. '];
admin_head('Messages', 'messages', ['sub' => 'Enquiries sent from the contact form – reply by email right from here.', 'actions' => '<a class="pbtn ghost" href="export.php?type=messages"><i class="bi bi-download"></i>Export CSV</a>']); ?>
<div class="stabs"><a href="messages.php" class="<?= $filter === '' ? 'on' : '' ?>">All <b><?= (int) $c['n'] ?></b></a><a href="?filter=unread" class="<?= $filter === 'unread' ? 'on' : '' ?>">Unread <b><?= (int) $c['unread'] ?></b></a>
 <a href="?filter=read" class="<?= $filter === 'read' ? 'on' : '' ?>">Read</a><a href="?filter=replied" class="<?= $filter === 'replied' ? 'on' : '' ?>">Replied <b><?= (int) $c['replied'] ?></b></a></div>
<form class="fbar" method="get"><input type="hidden" name="filter" value="<?= h($filter) ?>"><div class="f wide"><label>Search</label><input name="q" value="<?= h($q) ?>" placeholder="Name, email, subject, message text"></div><div class="fx"><button>Search</button><a class="pbtn ghost" href="messages.php">Reset</a></div></form>
<form method="post" data-bulk><input type="hidden" name="csrf" value="<?= csrf() ?>">
<?php if ($rows): ?><div class="bulkbar"><span class="bulk-count">0 selected</span><button name="bulk" value="read" class="sm ghostb">Mark read</button><button name="bulk" value="unread" class="sm ghostb">Mark unread</button><button name="bulk" value="delete" class="sm danger" data-confirm="Delete the selected messages?" data-confirm-yes="Delete">Delete</button></div>
<label class="checkrow"><input type="checkbox" data-bulk-all> Select all on this page</label><?php endif; ?>
<?php foreach ($rows as $m): ?><div class="pcard" style="margin-bottom:14px;<?= $m['is_read'] ? '' : 'border-color:var(--gold)' ?>">
 <div class="prow" style="margin-bottom:8px"><input type="checkbox" name="ids[]" value="<?= $m['id'] ?>" data-bulk-row aria-label="Select"><span class="avatar sm"><?= h(initials($m['name'])) ?></span>
  <div class="grow"><b><?= h($m['name']) ?></b> <?= $m['is_read'] ? '' : '<span class="chip">new</span>' ?> <?= $m['replied_at'] ? '<span class="chip ok"><i class="bi bi-reply"></i>replied</span>' : '' ?><br>
   <small class="mut"><a href="mailto:<?= h($m['email']) ?>"><?= h($m['email']) ?></a><?= $m['phone'] ? ' · ' . h($m['phone']) : '' ?> · <?= h(dt($m['created_at'])) ?></small></div>
  <div class="row-actions"><?php if (!$m['is_read']): ?><button class="ibtn" name="read" value="<?= $m['id'] ?>" title="Mark read"><i class="bi bi-check2-all"></i></button><?php else: ?><button class="ibtn" name="unread" value="<?= $m['id'] ?>" title="Mark unread"><i class="bi bi-envelope"></i></button><?php endif; ?>
   <button class="ibtn bad" name="del" value="<?= $m['id'] ?>" title="Delete" data-confirm="Delete this message?" data-confirm-yes="Delete"><i class="bi bi-trash3"></i></button></div></div>
 <?= $m['subject'] ? '<div style="font-weight:600;margin-bottom:4px">' . h($m['subject']) . '</div>' : '' ?><p style="margin:0 0 12px;color:var(--text-mid);line-height:1.7"><?= nl2br(h($m['message'])) ?></p>
 <?php if ($m['reply']): ?><div class="bubble me" style="max-width:100%"><div class="who"><b>Your reply</b><span><?= h(dt($m['replied_at'])) ?> · <?= h($m['replied_by']) ?></span></div><?= nl2br(h($m['reply'])) ?></div><?php endif; ?>
 <details style="margin-top:12px"><summary style="cursor:pointer;color:var(--gold);font-weight:600;font-size:.88rem"><i class="bi bi-reply"></i> <?= $m['reply'] ? 'Send another reply' : 'Reply by email' ?></summary>
  <div class="replybox" style="border:0;margin-top:10px;padding-top:0"><div class="canned"><?php foreach ($canned as $cn): ?><button type="button" class="canned-b" onclick="var t=this.closest('details').querySelector('textarea');t.value+=<?= h(json_encode($cn)) ?>;t.focus()"><?= h(mb_substr($cn, 0, 30)) ?>…</button><?php endforeach; ?></div>
  <textarea name="reply_<?= $m['id'] ?>" rows="4" maxlength="4000" placeholder="Write your reply to <?= h($m['name']) ?>…"></textarea>
  <button class="sm" style="margin-top:10px" name="reply_to" value="<?= $m['id'] ?>" onclick="this.form.querySelector('[name=reply]')&&this.form.querySelector('[name=reply]').remove();var h=document.createElement('input');h.type='hidden';h.name='reply';h.value=this.closest('details').querySelector('textarea').value;this.form.appendChild(h)"><i class="bi bi-send"></i>Send reply</button></div></details></div>
<?php endforeach; if (!$rows) echo '<div class="pcard"><div class="empty slim"><i class="bi bi-envelope-open"></i>No messages found.</div></div>'; ?>
</form>
<?= paginate($total, $page, $per, $keep) ?>
<?php admin_foot();
