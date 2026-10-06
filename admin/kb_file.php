<?php
declare(strict_types=1);
/** Отдаёт файл базы знаний только авторизованному пользователю (crm-data закрыт от прямого доступа). */
require __DIR__ . '/_guard.php';

$f = basename((string)($_GET['f'] ?? ''));
$p = __DIR__ . '/../crm-data/kb/' . $f;
if ($f === '' || !is_file($p)) { http_response_code(404); exit; }

$ext   = strtolower(pathinfo($p, PATHINFO_EXTENSION));
$mimes = [
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
    'webp' => 'image/webp', 'gif' => 'image/gif', 'pdf' => 'application/pdf',
    'txt' => 'text/plain; charset=utf-8', 'csv' => 'text/csv; charset=utf-8',
];
header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($p));
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');
readfile($p);
