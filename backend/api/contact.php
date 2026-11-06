<?php
require __DIR__ . '/_bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'POST only'], 405);
$in = json_body();
if (!empty($in['website'])) json_out(['ok' => false, 'error' => 'Rejected'], 400);
$name = clean($in['name'] ?? '', 120); $email = clean($in['email'] ?? '', 160);
$phone = clean($in['phone'] ?? '', 30); $subj = clean($in['subject'] ?? '', 160); $msg = clean($in['message'] ?? '', 4000);
if (mb_strlen($name) < 3 || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($msg) < 10)
    json_out(['ok' => false, 'error' => 'Please complete name, a valid email and a message (10+ characters).'], 422);
db()->prepare('INSERT INTO contact_messages (name, phone, email, subject, message) VALUES (?,?,?,?,?)')
    ->execute([$name, $phone, $email, $subj, $msg]);
send_mail(cfg('store_email'), 'New contact message from ' . $name,
    '<p><b>' . h($name) . '</b> (' . h($email) . ' / ' . h($phone) . ')</p><p>' . h($subj) . '</p><p>' . nl2br(h($msg)) . '</p>');
json_out(['ok' => true]);
