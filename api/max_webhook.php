<?php
declare(strict_types=1);
/**
 * Входящий вебхук MAX (мессенджер) — Bot API (dev.max.ru).
 * Вызывается серверами MAX — БЕЗ require_auth, но защищён секретом (fail-closed):
 * сравниваем переданный токен с cfg()['max']['webhook_secret'].
 * Секрет передаётся заголовком X-Max-Bot-Api-Secret-Token / X-Webhook-Secret / ?secret=.
 *
 * Base URL API: https://platform-api2.max.ru; токен — в заголовке Authorization
 * (query-параметр access_token больше не поддерживается).
 *
 * Регистрация вебхука (выполнить один раз):
 *
 *   curl -s "https://platform-api2.max.ru/subscriptions" \
 *        -H "Authorization: <TOKEN>" -H "Content-Type: application/json" \
 *        -d '{"url":"https://zavod-red.ru/api/max_webhook.php","update_types":["message_created"]}'
 *
 * Структура апдейта (message_created): update_type; message.sender.user_id (id),
 * message.sender.name/first_name (имя), message.body.text (текст), message.recipient.chat_id.
 *
 * Вебхук НИКОГДА не отдаёт 500 — при любой ошибке логируем через audit()
 * и всё равно отвечаем 'ok' (HTTP 200).
 */

require_once __DIR__ . '/inbox.php';

header('Content-Type: text/plain; charset=utf-8');

/** Достать первое непустое значение по списку путей вида 'a.b.c' из вложенного массива. */
function max_dig(array $data, array $paths): string {
    foreach ($paths as $path) {
        $node = $data;
        $ok = true;
        foreach (explode('.', $path) as $seg) {
            if (is_array($node) && array_key_exists($seg, $node)) {
                $node = $node[$seg];
            } else {
                $ok = false;
                break;
            }
        }
        if ($ok && (is_string($node) || is_int($node) || is_float($node))) {
            $v = trim((string)$node);
            if ($v !== '') return $v;
        }
    }
    return '';
}

try {
    // 1) Проверка секрета — fail-closed (как voice/telegram): без секрета приём ЗАКРЫТ.
    $secret = secret('max_webhook_secret', (string)(cfg()['max']['webhook_secret'] ?? ''));
    if ($secret === '') {
        http_response_code(503);
        echo 'not configured';
        exit;
    }
    $sent = (string)(
        $_SERVER['HTTP_X_MAX_BOT_API_SECRET_TOKEN']
        ?? $_SERVER['HTTP_X_WEBHOOK_SECRET']
        ?? ($_GET['secret'] ?? '')
    );
    if (!hash_equals($secret, $sent)) {
        http_response_code(403);
        echo 'ok';
        exit;
    }

    // 2) Чтение апдейта.
    $raw = file_get_contents('php://input') ?: '';
    $update = json_decode($raw, true);

    if (!is_array($update)) {
        echo 'ok';
        exit;
    }

    // 2b) Обрабатываем только новые сообщения. Служебные события (bot_started,
    //     user_added, message_removed и т.п.) содержат отправителя, но это не
    //     обращение клиента — иначе плодятся пустые лиды.
    $updType = max_dig($update, ['update_type', 'updates.0.update_type']);
    if ($updType !== '' && !in_array($updType, ['message_created', 'message_edited'], true)) {
        echo 'ok';
        exit;
    }
    // Не реагируем на сообщения от ботов (в т.ч. эхо нашего же бота) — защита от петель.
    if ((max_dig($update, ['message.sender.is_bot']) === '1')
        || ($update['message']['sender']['is_bot'] ?? false) === true) {
        echo 'ok';
        exit;
    }

    // 3) Текст сообщения (реальная структура MAX Bot API: message.body.text).
    $text = max_dig($update, [
        'message.body.text',
        'message.text',
        'updates.0.message.body.text',
        'body.text',
        'text',
    ]);

    // 4) ID отправителя.
    $userId = max_dig($update, [
        'message.sender.user_id',
        'message.sender.id',
        'message.from.user_id',
        'message.from.id',
        'update.message.sender.user_id',
        'sender.user_id',
        'from.id',
    ]);

    // Нет идентификатора отправителя — это не пользовательское сообщение
    // (служебный/неизвестный апдейт). Мягко выходим 200.
    if ($userId === '') {
        echo 'ok';
        exit;
    }

    // 5) Имя контакта.
    $name = max_dig($update, [
        'message.sender.name',
        'message.sender.first_name',
        'message.from.name',
        'message.from.first_name',
        'sender.name',
        'from.name',
    ]);
    if ($name === '') $name = 'MAX ' . $userId;

    // 6) Лид + сообщение в инбокс.
    $leadId = lead_find_or_create([
        'channel' => 'max',
        'ext_id'  => $userId,
        'name'    => $name,
    ]);

    msg_insert($leadId, 'max', 'in', $text, [
        'contact' => $name,
        'ext_id'  => $userId,
    ]);

    // Автоответ — тот же движок, что у почты и Telegram (api/autoreply.php):
    // фильтры служебных отправителей, защита от петли, лимиты, стоп-слова, часы.
    require_once __DIR__ . '/autoreply.php';
    autoreply_handle($leadId, 'max', [
        'body'    => $text,
        'contact' => $name,
    ]);

    echo 'ok';
    exit;
} catch (Throwable $e) {
    try {
        audit(null, null, 'max_webhook_error', [
            'message' => $e->getMessage(),
            'ip'      => client_ip(),
        ]);
    } catch (Throwable $ignore) { /* журнал не должен ломать ответ */ }
    echo 'ok';
    exit;
}
