<?php
declare(strict_types=1);

/**
 * /api/vision_queue.php — очередь картинок для внешнего обработчика (SmartApe, Claude по подписке).
 *
 * DeepSeek не видит картинок, а платный ключ Anthropic не нужен: фото шильдиков, сканы и
 * чертежи распознаёт Claude Code на сервере SmartApe (tools/vision_worker/ в этом репозитории).
 * Обработчик сам ходит сюда:
 *   GET  ?action=list&limit=10      — файлы, ждущие распознавания (по одному на одинаковый файл)
 *   GET  ?action=file&id=N          — сам файл
 *   POST ?action=result  {"id":N,"data":{kind, summary, nameplate, doc, models, confidence}}
 *   POST ?action=fail    {"id":N,"error":"…"}
 *
 * Доступ — только с адресов из настройки vision_worker_ips (по умолчанию IP сервера SmartApe).
 * Пароля нет намеренно: не нужно передавать секрет между серверами; IP подделать по TCP нельзя.
 */

require_once __DIR__ . '/mail_files.php';

header('X-Content-Type-Options: nosniff');
$allowed = array_filter(array_map('trim', explode(',', (string)(setting('vision_worker_ips', '94.198.55.117') ?? ''))));
if (!in_array(client_ip(), $allowed, true)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}
cron_heartbeat('vision');
mf_ensure();
$action = (string)($_GET['action'] ?? 'list');
$pdo = pdo();
$img = "stored_name REGEXP '[.](jpe?g|png|webp|gif|bmp|heic|heif|tiff?|pdf)$'";

try {
    if ($action === 'list') {
        header('Content-Type: application/json; charset=utf-8');
        $limit = max(1, min(30, (int)($_GET['limit'] ?? 10)));
        // PDF с текстовым слоем разбирает DeepSeek на хостинге; сюда идут картинки и сканы.
        $rows = $pdo->query("SELECT MIN(id) id, sha1, MIN(filename) filename, MIN(direction) direction, MAX(size) size, MIN(stored_name) stored_name
                             FROM crm_mail_files WHERE status='new' AND $img
                               AND (stored_name NOT LIKE '%.pdf' OR error LIKE '%картинок%')
                             GROUP BY sha1 ORDER BY id DESC LIMIT $limit")->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['id' => (int)$r['id'], 'filename' => (string)$r['filename'], 'direction' => (string)$r['direction'],
                      'size' => (int)$r['size'], 'ext' => strtolower(pathinfo((string)$r['stored_name'], PATHINFO_EXTENSION))];
        }
        $left = (int)$pdo->query("SELECT COUNT(DISTINCT sha1) FROM crm_mail_files WHERE status='new' AND $img")->fetchColumn();
        echo json_encode(['ok' => true, 'files' => $out, 'left' => $left, 'prompt' => mf_system()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $id = (int)($_GET['id'] ?? 0);
    if ($action === 'file') {
        $st = $pdo->prepare("SELECT stored_name FROM crm_mail_files WHERE id=? AND status IN ('new','error')");
        $st->execute([$id]);
        $path = mf_path((string)$st->fetchColumn());
        if ($path === null) { http_response_code(404); exit('not found'); }
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['ok' => false]); exit; }
    $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $id = (int)($in['id'] ?? $id);
    $st = $pdo->prepare('SELECT * FROM crm_mail_files WHERE id=?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'нет файла']); exit; }

    if ($action === 'result') {
        $data = is_array($in['data'] ?? null) ? $in['data'] : null;
        if (!$data) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'нет data']); exit; }
        $r = mf_apply($row, $data);
        // Счета и КП, отправленные нами, → история цен.
        try { require_once __DIR__ . '/price_hist.php'; $r['price_rows'] = ph_ingest(); } catch (Throwable $e) {}
        echo json_encode($r, JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'fail') {
        $pdo->prepare("UPDATE crm_mail_files SET status='error', error=?, analyzed_at=NOW() WHERE id=?")
            ->execute([mb_substr('SmartApe: ' . (string)($in['error'] ?? ''), 0, 255, 'UTF-8'), $id]);
        echo json_encode(['ok' => true]);
        exit;
    }
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'неизвестное действие']);
} catch (Throwable $e) {
    error_log('vision_queue: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'internal']);
}
