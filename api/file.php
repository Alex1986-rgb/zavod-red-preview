<?php
declare(strict_types=1);

/**
 * /api/file.php?id=<lead_id> — авторизованная выдача вложения заявки (PDF/фото);
 * /api/file.php?mf=<id> — вложение письма из почты (crm_mail_files).
 * Файлы лежат в crm-data/uploads (deny-all в .htaccess), поэтому отдаём только
 * залогиненным пользователям и только файл, привязанный к существующей заявке.
 * Защита от path traversal: реальный путь обязан быть внутри uploads_dir.
 */

require_once __DIR__ . '/helpers.php';

require_auth(); // только авторизованным

$id = (int)($_GET['id'] ?? 0);
$mf = (int)($_GET['mf'] ?? 0); // ?mf=<id> — вложение письма (crm_mail_files, api/mail_sync.php)
if ($id < 1 && $mf < 1) { http_response_code(400); exit('bad request'); }

$origName = '';
if ($mf > 0) {
    $st = pdo()->prepare('SELECT stored_name, filename FROM crm_mail_files WHERE id = ?');
    $st->execute([$mf]);
    $row = $st->fetch() ?: [];
    $rel = (string)($row['stored_name'] ?? '');
    $origName = (string)($row['filename'] ?? '');
} else {
    $st = pdo()->prepare('SELECT file_path FROM crm_leads WHERE id = ?');
    $st->execute([$id]);
    $rel = (string)($st->fetchColumn() ?: '');
}
if ($rel === '') { http_response_code(404); exit('no file'); }

$uploadsDir = rtrim((string)(cfg()['uploads_dir'] ?? (__DIR__ . '/../crm-data/uploads')), '/');
$base = basename($rel); // отбрасываем любые ../ — берём только имя файла
$path = $uploadsDir . '/' . $base;

$realDir  = realpath($uploadsDir);
$realFile = realpath($path);
if ($realFile === false || $realDir === false || strncmp($realFile, $realDir . DIRECTORY_SEPARATOR, strlen($realDir) + 1) !== 0 || !is_file($realFile)) {
    http_response_code(404); exit('not found');
}

$ext = strtolower(pathinfo($realFile, PATHINFO_EXTENSION));
$types = [
    'pdf' => 'application/pdf',
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
    'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif',
    'bmp' => 'image/bmp', 'heic' => 'image/heic', 'heif' => 'image/heif',
];
$ctype = $types[$ext] ?? 'application/octet-stream';
$inline = isset($types[$ext]) ? 'inline' : 'attachment';

header('Content-Type: ' . $ctype);
header('Content-Length: ' . (string)filesize($realFile));
// Исходное имя из письма («Чертёж вала.pdf») — по RFC 5987, иначе браузер покажет случайное имя.
$dlName = $origName !== '' ? $origName : $base;
header('Content-Disposition: ' . $inline . '; filename="' . rawurlencode($base) . '"; filename*=UTF-8\'\'' . rawurlencode($dlName));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');
readfile($realFile);
exit;
