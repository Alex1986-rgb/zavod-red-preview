<?php
declare(strict_types=1);

/**
 * Общий вход для вебхуков голосового робота (zavod-red-voice → CRM):
 * JSON-тело + секрет в заголовке X-Webhook-Secret или поле "secret".
 * Секрет тот же, что у api/voice_webhook.php (Голос → Настройки или config.php).
 * Fail-closed: без настроенного секрета вебхуки закрыты.
 */

require_once __DIR__ . '/helpers.php';

/** Прочитать и проверить запрос; при ошибке отвечает сам и завершает скрипт. */
function voice_hook_read(): array {
    header('Content-Type: application/json; charset=utf-8');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405); echo json_encode(['ok' => false, 'error' => 'POST only']); exit;
    }
    $in = json_decode((string)(file_get_contents('php://input') ?: ''), true);
    if (!is_array($in)) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'bad json']); exit; }
    $secret = secret('voice_webhook_secret', (string)(cfg()['voice']['webhook_secret'] ?? ''));
    if ($secret === '') { http_response_code(503); echo json_encode(['ok' => false, 'error' => 'not configured']); exit; }
    $sent = (string)($_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? ($in['secret'] ?? ''));
    if (!hash_equals($secret, $sent)) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'forbidden']); exit; }
    return $in;
}
