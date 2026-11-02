<?php
require __DIR__ . '/includes/functions.php';
require_admin();

$path = backup_resolve((string)($_GET['file'] ?? ''));

if ($path === null) {
    http_response_code(404);
    exit('Backup not found.');
}

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . basename($path) . '"');
header('Content-Length: ' . filesize($path));
header('Pragma: no-cache');
readfile($path);
exit;
