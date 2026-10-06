<?php
declare(strict_types=1);
/**
 * Входящий вебхук Telegram Bot API.
 * Вызывается серверами Telegram — БЕЗ require_auth, но защищён секретом:
 * заголовок X-Telegram-Bot-Api-Secret-Token сверяется с
 * cfg()['telegram']['webhook_secret'] (если задан в конфиге).
 *
 * Установка вебхука (secret_token можно опустить, если в конфиге секрет не задан):
 *
 *   curl -s "https://api.telegram.org/bot<TOKEN>/setWebhook" \
 *        -d "url=https://zavod-red.ru/api/tg_webhook.php" \
 *        -d "secret_token=<WEBHOOK_SECRET>"
 *
 * Проверка / снятие:
 *   curl -s "https://api.telegram.org/bot<TOKEN>/getWebhookInfo"
 *   curl -s "https://api.telegram.org/bot<TOKEN>/deleteWebhook"
 *
 * Вебхук НИКОГДА не отдаёт 500 Telegram — при любой ошибке логируем в crm_events
 * через audit() и всё равно отвечаем 'ok' (HTTP 200).
 */

require_once __DIR__ . '/inbox.php';

header('Content-Type: text/plain; charset=utf-8');

/** Найти менеджера по его Telegram chat id (crm_users.tg_chat). */
function tg_manager_by_chat(string $chatId): ?array {
    if ($chatId === '') return null;
    $st = pdo()->prepare(
        "SELECT id, name FROM crm_users WHERE tg_chat = ? AND active = 1 AND role IN ('manager','admin') LIMIT 1"
    );
    $st->execute([$chatId]);
    $u = $st->fetch();
    return $u ?: null;
}

/** Подтвердить нажатие inline-кнопки (закрыть «часики» у клиента). */
function tg_answer_callback(string $callbackId, string $text = ''): void {
    tg_api('answerCallbackQuery', array_filter([
        'callback_query_id' => $callbackId,
        'text'              => $text,
    ], static fn($v) => $v !== ''));
}

/** Обработка callback_query от менеджера: take:{id} | status:{id}:{st}. */
function tg_handle_callback(array $cb): void {
    $cbId = (string)($cb['id'] ?? '');
    $data = trim((string)($cb['data'] ?? ''));
    $from = $cb['from'] ?? [];
    $msg  = $cb['message'] ?? [];

    $fromId = isset($from['id']) ? (string)$from['id'] : '';
    $mgr    = $fromId !== '' ? tg_manager_by_chat($fromId) : null;
    if (!$mgr) { tg_answer_callback($cbId, 'Нет доступа'); return; }

    $pdo = pdo();
    $allowed = ['new', 'in_progress', 'quoted', 'won', 'lost'];

    if (preg_match('/^take:(\d+)$/', $data, $m)) {
        $leadId = (int)$m[1];
        $st = $pdo->prepare('UPDATE crm_leads SET manager_id = ?, status = ?, updated_at = NOW() WHERE id = ?');
        $st->execute([(int)$mgr['id'], 'in_progress', $leadId]);
        audit($leadId, (int)$mgr['id'], 'assigned', ['via' => 'tg', 'manager' => (int)$mgr['id'], 'status' => 'in_progress']);
        tg_answer_callback($cbId, "Лид #{$leadId} закреплён за вами");
        tg_edit_status($msg, "✅ Лид #{$leadId} — взят ({$mgr['name']})");
        return;
    }

    if (preg_match('/^status:(\d+):([a-z_]+)$/', $data, $m)) {
        $leadId = (int)$m[1];
        $st     = $m[2];
        if (!in_array($st, $allowed, true)) { tg_answer_callback($cbId, 'Неизвестный статус'); return; }
        $up = $pdo->prepare('UPDATE crm_leads SET status = ?, updated_at = NOW() WHERE id = ?');
        $up->execute([$st, $leadId]);
        audit($leadId, (int)$mgr['id'], 'status_changed', ['via' => 'tg', 'status' => $st]);
        tg_answer_callback($cbId, "Лид #{$leadId}: {$st}");
        tg_edit_status($msg, "✏️ Лид #{$leadId} → {$st} ({$mgr['name']})");
        return;
    }

    tg_answer_callback($cbId, '');
}

/** Дописать строку статуса под исходным сообщением (если возможно). */
function tg_edit_status(array $msg, string $note): void {
    $chatId = isset($msg['chat']['id']) ? (string)$msg['chat']['id'] : '';
    $msgId  = isset($msg['message_id']) ? (int)$msg['message_id'] : 0;
    $base   = (string)($msg['text'] ?? '');
    if ($chatId === '' || $msgId === 0) return;
    tg_api('editMessageText', [
        'chat_id'    => $chatId,
        'message_id' => $msgId,
        'text'       => trim($base . "\n\n" . $note),
    ]);
}

/** Прислать менеджеру список 5 последних открытых лидов. */
function tg_send_leads_list(string $chatId): void {
    $rows = pdo()->query(
        "SELECT id, name, status FROM crm_leads
         WHERE status NOT IN ('won','lost')
         ORDER BY id DESC LIMIT 5"
    )->fetchAll();
    if (!$rows) { tg_api('sendMessage', ['chat_id' => $chatId, 'text' => 'Открытых лидов нет.']); return; }
    $text = "📋 Последние открытые лиды:\n";
    foreach ($rows as $r) {
        $text .= "#{$r['id']} — {$r['name']} [{$r['status']}]\n";
    }
    tg_api('sendMessage', ['chat_id' => $chatId, 'text' => $text]);
}

try {
    // 1) Проверка секрета — fail-closed (как voice_webhook): без настроенного секрета
    // приём ЗАКРЫТ, иначе кто угодно POST-ом создаёт фейковые лиды/сообщения.
    // Секрет задаётся в config.php telegram.webhook_secret и в setWebhook(secret_token=...).
    $secret = secret('tg_webhook_secret', (string)(cfg()['telegram']['webhook_secret'] ?? ''));
    if ($secret === '') {
        http_response_code(503);
        echo 'not configured';
        exit;
    }
    $sent = (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
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

    // 3a) Нажатие inline-кнопки менеджером (callback_query).
    if (is_array($update['callback_query'] ?? null)) {
        tg_handle_callback($update['callback_query']);
        echo 'ok';
        exit;
    }

    // 3) Обычное входящее сообщение.
    $message = $update['message'] ?? null;
    if (!is_array($message)) {
        echo 'ok';
        exit;
    }

    $chat   = $message['chat'] ?? [];
    $from   = $message['from'] ?? [];
    $chatId = isset($chat['id']) ? (string)$chat['id'] : '';
    $text   = trim((string)($message['text'] ?? ''));

    if ($chatId === '') {
        echo 'ok';
        exit;
    }

    // 3b) Сообщение от менеджера: распознаём по from.id в crm_users.tg_chat.
    //     Команда «/leads» или «лиды» → список последних открытых лидов.
    $fromId = isset($from['id']) ? (string)$from['id'] : '';
    $mgr    = $fromId !== '' ? tg_manager_by_chat($fromId) : null;
    if ($mgr) {
        $cmd = mb_strtolower($text, 'UTF-8');
        if ($cmd === '/leads' || $cmd === 'лиды') {
            tg_send_leads_list($chatId);
        }
        // Прочие сообщения менеджера не пишем в клиентский инбокс.
        echo 'ok';
        exit;
    }

    // 4) Имя контакта (входящее от клиента).
    $first    = trim((string)($from['first_name'] ?? ''));
    $last     = trim((string)($from['last_name'] ?? ''));
    $username = trim((string)($from['username'] ?? ''));
    $name     = trim($first . ' ' . $last);
    if ($name === '') $name = $username !== '' ? '@' . $username : ('Telegram ' . $chatId);

    // 5) Лид + сообщение в инбокс.
    $leadId = lead_find_or_create([
        'channel' => 'telegram',
        'ext_id'  => $chatId,
        'name'    => $name,
    ]);

    msg_insert($leadId, 'telegram', 'in', $text, [
        'contact' => $username !== '' ? '@' . $username : $name,
        'ext_id'  => $chatId,
    ]);

    // Автоответ. Раньше клиент писал в бота и не получал вообще ничего: входящее
    // ложилось в базу и ждало менеджера. Решение принимает api/autoreply.php
    // (режим по умолчанию — черновик, наружу молча ничего не уходит).
    require_once __DIR__ . '/autoreply.php';
    autoreply_handle($leadId, 'telegram', [
        'body'    => $text,
        'contact' => $username !== '' ? '@' . $username : $name,
    ]);

    echo 'ok';
    exit;
} catch (Throwable $e) {
    // Никаких 500 мессенджеру — мягко логируем и подтверждаем приём.
    try {
        audit(null, null, 'tg_webhook_error', [
            'message' => $e->getMessage(),
            'ip'      => client_ip(),
        ]);
    } catch (Throwable $ignore) { /* журнал не должен ломать ответ */ }
    echo 'ok';
    exit;
}
