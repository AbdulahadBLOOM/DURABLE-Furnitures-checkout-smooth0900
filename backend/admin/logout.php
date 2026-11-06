<?php
require __DIR__ . '/_auth.php';
$_SESSION = [];
$p = session_get_cookie_params();
setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'httponly' => true, 'samesite' => 'Strict']);
session_destroy();
header('Location: ' . ADMIN_SIGNIN_URL);
