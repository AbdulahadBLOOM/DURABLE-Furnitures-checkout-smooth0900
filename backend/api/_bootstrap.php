<?php
require_once __DIR__ . '/../db.php';
header('X-Content-Type-Options: nosniff');
set_exception_handler(function (Throwable $e) {
    error_log('[DF API] ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'Something went wrong on our side. Please try again or contact us on WhatsApp.'], 500);
});
function json_body(): array {
    $raw = file_get_contents('php://input');
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}
function client_ip(): string { return substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45); }
