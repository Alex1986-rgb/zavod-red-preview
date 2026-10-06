<?php
declare(strict_types=1);
/**
 * Приёмник звонков голосового менеджера → CRM.
 * Положите файл в zavod-red-crm/api/voice_webhook.php и задеплойте.
 *
 * Вызывается сервисом zavod-red-voice (crm.py) по завершении звонка:
 * создаёт/находит лид по телефону и кладёт транскрипт в ленту сообщений.
 * Защита секретом: заголовок X-Webhook-Secret либо поле "secret" в JSON,
 * сравнивается с cfg()['voice']['webhook_secret'] (или secret('voice_webhook_secret')).
 *
 * В config.php добавьте:
 *   'voice' => ['webhook_secret' => 'ТОТ_ЖЕ_СЕКРЕТ_ЧТО_В_.env_ГОЛОСОВОГО_СЕРВИСА'],
 *
 * Ответ: JSON {"ok":true,"lead_id":123}. Ошибку не роняем 500 без нужды.
 */

require_once __DIR__ . '/inbox.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $raw = file_get_contents('php://input') ?: '';
    $in = json_decode($raw, true);
    if (!is_array($in)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'bad json']);
        exit;
    }

    // Проверка секрета — fail-closed: без настроенного секрета публичный приём
    // заявок ЗАКРЫТ (иначе кто угодно сможет создавать лиды). Секрет задаётся в
    // админке: Голос → Настройки (crm_settings.voice_webhook_secret) или в config.php.
    $secret = secret('voice_webhook_secret', (string)(cfg()['voice']['webhook_secret'] ?? ''));
    if ($secret === '') {
        http_response_code(503);
        echo json_encode(['ok' => false, 'error' => 'not configured']);
        exit;
    }
    $sent = (string)($_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? ($in['secret'] ?? ''));
    if (!hash_equals($secret, $sent)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'forbidden']);
        exit;
    }

    $callId    = trim((string)($in['call_id'] ?? ''));
    $direction = ((string)($in['direction'] ?? 'in')) === 'out' ? 'out' : 'in';
    $phone     = trim((string)($in['phone'] ?? ''));
    $name      = trim((string)($in['name'] ?? ''));
    $task      = trim((string)($in['task'] ?? ''));
    $product   = trim((string)($in['product'] ?? ''));
    $transcript = trim((string)($in['transcript'] ?? ''));

    // Лид по телефону/каналу.
    $leadId = lead_find_or_create([
        'channel' => 'voice',
        'ext_id'  => $callId,
        'phone'   => $phone,
        'name'    => $name !== '' ? $name : ('Звонок ' . ($phone ?: $callId)),
    ]);

    // Собираем тело сообщения: задача/продукт + расшифровка разговора.
    $head = [];
    if ($product !== '') $head[] = 'Подбор: ' . $product;
    if ($task !== '')    $head[] = 'Задача: ' . $task;
    $body = ($head ? implode("\n", $head) . "\n\n" : '')
          . ($transcript !== '' ? "Расшифровка звонка:\n" . $transcript : '');

    $subject = ($direction === 'out' ? 'Исходящий звонок' : 'Входящий звонок')
             . ($callId !== '' ? ' #' . $callId : '');

    msg_insert($leadId, 'voice', $direction, $body, [
        'contact' => $name ?: $phone,
        'ext_id'  => $callId,
        'subject' => $subject,
    ]);

    echo json_encode(['ok' => true, 'lead_id' => $leadId]);
    exit;
} catch (Throwable $e) {
    try {
        audit(null, null, 'voice_webhook_error', ['message' => $e->getMessage()]);
    } catch (Throwable $ignore) { /* журнал не должен ломать ответ */ }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'internal']);
    exit;
}
