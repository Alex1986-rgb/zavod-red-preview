<?php
declare(strict_types=1);

/**
 * /api/msg_send.php — ЕДИНЫЙ исходящий ответ из CRM (требует авторизации).
 *
 *   POST (csrf) поля:
 *     lead_id   int     — ID лида в crm_leads (обязательно)
 *     channel   string  — email | telegram | max (обязательно)
 *     body      string  — текст ответа (обязательно)
 *     subject   string  — тема (только email, необязательно)
 *
 * Логика:
 *   - email:    отправка через smtp_send() (api/mail.php) на crm_leads.email
 *   - telegram: cURL Telegram Bot API sendMessage, chat_id = последний
 *               crm_messages.ext_id (channel='telegram') этого лида
 *   - max:      cURL MAX Bot API sendMessage, user_id = последний
 *               crm_messages.ext_id (channel='max') этого лида
 *
 * Успех:  msg_insert(out) + audit + json_out(['ok'=>true]).
 * Ошибка канала: 502. Нет контакта в канале: 400.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/inbox.php';
require_once __DIR__ . '/channel_send.php'; // channel_send() — общая отправка по каналам

$user = require_auth();
$uid  = (int)($user['id'] ?? 0);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
    json_out(['ok' => false, 'error' => 'Метод не поддерживается'], 405);
}

csrf_check();

try {
    $leadId  = (int)($_POST['lead_id'] ?? 0);
    $channel = strtolower(trim((string)($_POST['channel'] ?? '')));
    $body    = trim((string)($_POST['body'] ?? ''));
    $subject = trim((string)($_POST['subject'] ?? ''));

    if ($leadId <= 0) {
        json_out(['ok' => false, 'error' => 'Не указан lead_id'], 400);
    }
    if (!in_array($channel, ['email', 'telegram', 'max'], true)) {
        json_out(['ok' => false, 'error' => 'Неизвестный канал'], 400);
    }
    if ($body === '') {
        json_out(['ok' => false, 'error' => 'Пустой текст сообщения'], 400);
    }

    // Загрузить лид.
    $st = pdo()->prepare('SELECT * FROM crm_leads WHERE id = ? LIMIT 1');
    $st->execute([$leadId]);
    $lead = $st->fetch();
    if (!$lead) {
        json_out(['ok' => false, 'error' => 'Лид не найден'], 404);
    }

    // Идемпотентность: не более одной отправки по (лид+канал) за 5 секунд (двойной клик).
    if (!rate_limit('msgsend:' . $leadId . ':' . $channel, 1, 5)) {
        json_out(['ok' => false, 'error' => 'Сообщение уже отправляется, подождите'], 429);
    }

    // Отправка — через общий channel_send() (api/channel_send.php), тот же путь,
    // которым пользуются автоответ и вебхуки. Сбой канала НЕ прерывает выполнение:
    // исходящее всё равно пишется в переписку, чтобы менеджер видел неудачную попытку.
    $res     = channel_send($leadId, $channel, $body, $subject);
    $ok      = (bool)$res['ok'];
    $err     = (string)$res['error'];
    $contact = (string)$res['contact'];

    // Нечего отправлять (нет адреса/чата/токена) — это ошибка ввода, а не сбой канала:
    // отвечаем сразу и ничего не пишем в переписку.
    if (!$ok && $contact === '') {
        json_out(['ok' => false, 'error' => $err], 400);
    }

    msg_insert($leadId, $channel, 'out', $body, [
        'contact' => $contact,
        'ext_id'  => $channel === 'email' ? '' : $contact,
        'subject' => $channel === 'email' ? (string)$res['subject'] : '',
    ]);

    if (!$ok) {
        audit($leadId, $uid, 'msg_failed', ['channel' => $channel, 'reason' => $err]);
        json_out(['ok' => false, 'error' => $err, 'saved' => true], 502);
    }

    audit($leadId, $uid, 'msg_sent', ['channel' => $channel, 'via' => (string)$res['via']]);

    json_out(['ok' => true, 'via' => (string)$res['via']]);
} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => 'Внутренняя ошибка'], 500);
}
