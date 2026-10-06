<?php
declare(strict_types=1);
/**
 * Голосовой менеджер — бэкенд для админки (admin/voice.php).
 * Проксирует к Python-сервису zavod-red-voice (звонки/очередь/обзвон) и
 * читает голосовые обращения из БД CRM (crm_messages канал 'voice').
 *
 * Настройки (crm_settings):
 *   voice_service_url  — базовый URL сервиса (по умолчанию http://127.0.0.1:5056)
 *   voice_turn_secret  — тот же, что TURN_SECRET в .env голосового сервиса
 *
 * Actions (auth required): status | state | db_calls | enqueue
 *                          settings_get | settings_save (admin)
 * Ответы — JSON {ok, ...}.
 */
require_once __DIR__ . '/helpers.php';

function voice_service_url(): string {
    return rtrim((string)setting('voice_service_url', 'http://127.0.0.1:5056'), '/');
}
function voice_turn_secret(): string {
    return secret('voice_turn_secret');
}

/** HTTP к Python-сервису. Возвращает [код, данные]. Сеть не роняет страницу. */
function voice_http(string $method, string $path, ?array $body = null): array {
    $url = voice_service_url() . $path;
    $ch = curl_init($url);
    $headers = ['Accept: application/json'];
    $secret = voice_turn_secret();
    if ($secret !== '') $headers[] = 'X-Turn-Secret: ' . $secret;
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_HTTPHEADER => $headers,
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_HTTPHEADER] = $headers;
        $opts[CURLOPT_POSTFIELDS] = json_encode($body ?? [], JSON_UNESCAPED_UNICODE);
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($resp === false) return [0, ['error' => 'Сервис недоступен: ' . $err]];
    $data = json_decode((string)$resp, true);
    return [$code, is_array($data) ? $data : ['raw' => $resp]];
}

/** Голосовые обращения из БД CRM (всегда доступно, даже если сервис офлайн). */
function voice_db_calls(int $limit = 40): array {
    $st = pdo()->prepare(
        "SELECT m.id, m.lead_id, m.direction, m.contact, m.ext_id, m.subject, m.body, m.created_at,
                l.name AS lead_name, l.phone AS lead_phone, l.status AS lead_status
         FROM crm_messages m
         LEFT JOIN crm_leads l ON l.id = m.lead_id
         WHERE m.channel = 'voice'
         ORDER BY m.id DESC LIMIT ?"
    );
    $st->bindValue(1, $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

// ---------------- роутер ----------------
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) !== realpath(__FILE__)) {
    return;
}
$action = $_GET['action'] ?? '';
$in = json_decode(file_get_contents('php://input') ?: '[]', true) ?: $_POST;
try {
    switch ($action) {
        case 'status':
            require_auth();
            [$code, $data] = voice_http('GET', '/health');
            json_out(['ok' => $code === 200, 'online' => $code === 200,
                      'service_url' => voice_service_url(),
                      'health' => $data]);

        case 'state':
            require_auth();
            [$code, $data] = voice_http('GET', '/api/state');
            if ($code === 200) {
                json_out(['ok' => true, 'online' => true] + $data);
            }
            // сервис офлайн — отдаём хотя бы историю из БД
            json_out(['ok' => true, 'online' => false, 'error' => $data['error'] ?? 'offline',
                      'calls' => [], 'outbound' => [], 'db_calls' => voice_db_calls()]);

        case 'db_calls':
            require_auth();
            json_out(['ok' => true, 'calls' => voice_db_calls((int)($_GET['limit'] ?? 40))]);

        case 'enqueue':
            require_auth(); csrf_check();
            $phone = trim((string)($in['phone'] ?? ''));
            if ($phone === '') json_out(['ok' => false, 'error' => 'Укажите телефон'], 422);
            [$code, $data] = voice_http('POST', '/calls', [
                'phone'  => $phone,
                'name'   => trim((string)($in['name'] ?? '')),
                'reason' => trim((string)($in['reason'] ?? '')),
            ]);
            json_out(['ok' => $code === 200, 'result' => $data], $code === 200 ? 200 : 502);

        case 'settings_get':
            require_auth('admin');
            // Публичный URL приёмника заявок — тот же хост, что и админка.
            $scheme = (($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['SERVER_PORT'] ?? '') === '443') ? 'https' : 'http';
            $host = (string)($_SERVER['HTTP_HOST'] ?? 'zavod-red.ru');
            json_out(['ok' => true,
                      'voice_service_url' => voice_service_url(),
                      'voice_turn_secret_set' => voice_turn_secret() !== '',
                      'webhook_url' => $scheme . '://' . $host . '/api/voice_webhook.php',
                      'webhook_secret_set' => secret('voice_webhook_secret') !== '']);

        case 'settings_save':
            require_auth('admin'); csrf_check();
            $st = pdo()->prepare('INSERT INTO crm_settings (skey,sval) VALUES (?,?) ON DUPLICATE KEY UPDATE sval=VALUES(sval)');
            if (isset($in['voice_service_url'])) {
                $st->execute(['voice_service_url', trim((string)$in['voice_service_url'])]);
            }
            if (isset($in['voice_turn_secret']) && $in['voice_turn_secret'] !== '') {
                $st->execute(['voice_turn_secret', trim((string)$in['voice_turn_secret'])]);
            }
            if (isset($in['voice_webhook_secret']) && $in['voice_webhook_secret'] !== '') {
                $st->execute(['voice_webhook_secret', trim((string)$in['voice_webhook_secret'])]);
            }
            json_out(['ok' => true]);

        case 'gen_secret':
            // Сгенерировать надёжный секрет (для webhook/turn) — админ вставит его в .env сервиса.
            require_auth('admin');
            json_out(['ok' => true, 'secret' => bin2hex(random_bytes(24))]);

        default:
            json_out(['ok' => false, 'error' => 'Неизвестное действие'], 404);
    }
} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => $e->getMessage()], 500);
}
