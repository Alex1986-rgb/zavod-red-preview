<?php
declare(strict_types=1);

/**
 * /api/channel_send.php — ЕДИНАЯ отправка сообщения клиенту по каналу.
 *
 * Зачем отдельный файл: логика отправки жила внутри api/msg_send.php, а тот на
 * верхнем уровне делает require_auth() — то есть серверные процессы (крон почты,
 * вебхуки Telegram/MAX, движок автоответа) переиспользовать её не могли и должны
 * были бы дублировать cURL-код по третьему разу.
 *
 * Здесь ТОЛЬКО отправка. Запись в crm_messages, audit и смена статуса лида —
 * ответственность вызывающего: у ручного ответа менеджера и у автоответа разные
 * требования к журналу.
 *
 * channel_send(int $leadId, string $channel, string $body, string $subject = ''): array
 *   → ['ok'=>bool, 'error'=>string, 'via'=>string, 'contact'=>string, 'subject'=>string]
 */

require_once __DIR__ . '/helpers.php';

/** Последний ext_id (chat_id / user_id) лида по каналу, или '' если нет. */
function chan_last_ext_id(int $leadId, string $channel): string {
    $st = pdo()->prepare(
        "SELECT ext_id FROM crm_messages
         WHERE lead_id = ? AND channel = ? AND ext_id <> '' AND ext_id IS NOT NULL
         ORDER BY id DESC LIMIT 1"
    );
    $st->execute([$leadId, $channel]);
    $v = $st->fetchColumn();
    return $v !== false ? (string)$v : '';
}

/**
 * POST JSON через cURL. Возвращает [bool ok, string error].
 * ok = HTTP 2xx И (если ответ JSON) отсутствие флага ошибки API: Telegram умеет
 * отдавать {"ok":false} с кодом 200.
 */
function chan_http_post_json(string $url, array $payload, array $headers = []): array {
    if (!function_exists('curl_init')) return [false, 'cURL недоступен на сервере'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json; charset=utf-8'], $headers),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $resp  = curl_exec($ch);
    $errno = curl_errno($ch);
    $cerr  = curl_error($ch);
    $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0) return [false, 'сетевая ошибка (' . $cerr . ')'];
    if ($code < 200 || $code >= 300) {
        $detail = '';
        $j = json_decode((string)$resp, true);
        if (is_array($j)) $detail = (string)($j['description'] ?? $j['message'] ?? $j['error'] ?? '');
        return [false, 'HTTP ' . $code . ($detail !== '' ? ': ' . $detail : '')];
    }
    $j = json_decode((string)$resp, true);
    if (is_array($j) && array_key_exists('ok', $j) && $j['ok'] === false) {
        return [false, (string)($j['description'] ?? 'API вернул ok=false')];
    }
    return [true, ''];
}

/**
 * Отправить текст клиенту по каналу. Каналы: email | telegram | max.
 * Получатель: email — crm_leads.email; telegram/max — последний ext_id из переписки.
 */
function channel_send(int $leadId, string $channel, string $body, string $subject = ''): array {
    $channel = strtolower(trim($channel));
    $body    = trim($body);
    $fail = static fn(string $e, string $c = '') => ['ok' => false, 'error' => $e, 'via' => 'нет', 'contact' => $c, 'subject' => ''];

    if ($body === '') return $fail('Пустой текст сообщения');
    if (!in_array($channel, ['email', 'telegram', 'max'], true)) return $fail('Неизвестный канал: ' . $channel);

    $st = pdo()->prepare('SELECT * FROM crm_leads WHERE id = ? LIMIT 1');
    $st->execute([$leadId]);
    $lead = $st->fetch();
    if (!$lead) return $fail('Лид не найден');

    if ($channel === 'email') {
        $to = trim((string)($lead['email'] ?? ''));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return $fail('У лида нет корректного email-адреса');
        }
        $subj = $subject !== '' ? $subject : 'Ответ от Завода Редукторов';
        $subj = str_replace(["\r", "\n"], ' ', $subj); // header injection
        $html = nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));
        // Общий путь отправки: SMTP, при отказе — mail() с корректным конвертом
        // (см. notify_email_send в helpers.php — там весь свод по SPF/DKIM).
        $r = notify_email_send($to, $subj, $html);
        return [
            'ok'      => (bool)($r['ok'] ?? false),
            'error'   => (string)($r['error'] ?? ''),
            'via'     => (string)($r['via'] ?? 'нет'),
            'contact' => $to,
            'subject' => $subj,
        ];
    }

    if ($channel === 'telegram') {
        $chatId = chan_last_ext_id($leadId, 'telegram');
        if ($chatId === '') return $fail('Нет привязанного Telegram-чата у лида');
        $token = secret('tg_token', (string)(cfg()['telegram']['token'] ?? ''));
        if ($token === '' || $token === 'CHANGE_ME') return $fail('Не настроен токен Telegram-бота', $chatId);
        [$ok, $err] = chan_http_post_json(
            'https://api.telegram.org/bot' . $token . '/sendMessage',
            ['chat_id' => $chatId, 'text' => $body]
        );
        return ['ok' => $ok, 'error' => $ok ? '' : 'Telegram: ' . $err,
                'via' => $ok ? 'telegram' : 'нет', 'contact' => $chatId, 'subject' => ''];
    }

    // max
    $userId = chan_last_ext_id($leadId, 'max');
    if ($userId === '') return $fail('Нет привязанного MAX-получателя у лида');
    $token = secret('max_token', (string)(cfg()['max']['token'] ?? ''));
    if ($token === '' || $token === 'CHANGE_ME') return $fail('Не настроен токен MAX-бота', $userId);
    // MAX Bot API (dev.max.ru): токен в заголовке Authorization, получатель — в query.
    $base = rtrim(secret('max_api_base', 'https://platform-api2.max.ru'), '/');
    [$ok, $err] = chan_http_post_json(
        $base . '/messages?user_id=' . rawurlencode($userId),
        ['text' => $body],
        ['Authorization: ' . $token]
    );
    return ['ok' => $ok, 'error' => $ok ? '' : 'MAX: ' . $err,
            'via' => $ok ? 'max' : 'нет', 'contact' => $userId, 'subject' => ''];
}
