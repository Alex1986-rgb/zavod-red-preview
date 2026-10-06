<?php
declare(strict_types=1);
/**
 * Омниканальный инбокс — общие хелперы для каналов (email/telegram/max/form).
 * Подключается из webhook'ов и поллеров. НЕ требует авторизации сам по себе
 * (вызывается серверными скриптами), но webhook'и должны проверять секрет/токен.
 */
require_once __DIR__ . '/helpers.php';

/**
 * Найти существующий лид по контакту (email точно ИЛИ телефон по последним 10 цифрам).
 * Единая точка дедупа контактов — используется и приёмом каналов, и формой сайта.
 */
function lead_find_existing(string $email, string $phone): ?int {
    $pdo = pdo();
    $email = trim($email);
    $phone = normalize_phone($phone);
    // по email
    if ($email !== '') {
        $st = $pdo->prepare('SELECT id FROM crm_leads WHERE email = ? ORDER BY id DESC LIMIT 1');
        $st->execute([$email]);
        if ($id = $st->fetchColumn()) return (int)$id;
    }
    // по телефону — ТОЧНОЕ совпадение по последним 10 цифрам (устойчиво к +7/8/формату).
    if (strlen($phone) >= 10) {
        $st = $pdo->prepare(
            "SELECT id FROM crm_leads
             WHERE RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone,' ',''),'(',''),')',''),'-',''),'+',''), 10) = RIGHT(?, 10)
             ORDER BY id DESC LIMIT 1"
        );
        $st->execute([$phone]);
        if ($id = $st->fetchColumn()) return (int)$id;
    }
    return null;
}

/**
 * Найти лид по контакту или создать новый.
 * $opts: channel, email, phone, ext_id, name. $created ← true, если лид реально создан.
 */
function lead_find_or_create(array $opts, bool &$created = false): int {
    $created = false;
    $pdo = pdo();
    $email = trim((string)($opts['email'] ?? ''));
    $phone = normalize_phone((string)($opts['phone'] ?? ''));
    $channel = (string)($opts['channel'] ?? '');
    $extId = (string)($opts['ext_id'] ?? '');

    // 1-2) по email / телефону
    if ($found = lead_find_existing($email, (string)($opts['phone'] ?? ''))) return (int)$found;
    // 3) по предыдущему сообщению того же канала с тем же ext_id
    if ($channel !== '' && $extId !== '') {
        $st = $pdo->prepare('SELECT lead_id FROM crm_messages WHERE channel=? AND ext_id=? AND lead_id IS NOT NULL ORDER BY id DESC LIMIT 1');
        $st->execute([$channel, $extId]);
        if ($id = $st->fetchColumn()) return (int)$id;
    }
    // 4) создать новый лид
    $created = true;
    $name = trim((string)($opts['name'] ?? '')) ?: ('Контакт ' . ($channel ?: 'web'));
    $st = $pdo->prepare('INSERT INTO crm_leads (created_at,updated_at,name,phone,email,source,status) VALUES (NOW(),NOW(),?,?,?,?,?)');
    $st->execute([$name, $phone, $email, $channel ?: 'inbox', 'new']);
    $id = (int)$pdo->lastInsertId();
    audit($id, null, 'lead_created', ['channel' => $channel]);

    // Тихий режим (разовые/архивные прогоны почты): заявка заводится без уведомлений —
    // 28.09.2026 первый прогон завёл 47 пропущенных писем и прислал 47 писем «Новая заявка» разом.
    if (!empty($opts['silent'])) return $id;

    // Авто-распределение и уведомления — только для реально нового лида.
    // notify_new_lead → Telegram; notify_lead_email → почта. Оба пишут событие
    // доставки (notify_telegram / notify_email), чтобы было видно в карточке.
    try { auto_assign($id); } catch (Throwable $e) { /* не ломаем приём */ }
    try { notify_new_lead($id); } catch (Throwable $e) { /* не ломаем приём */ }
    try { notify_lead_email($id); } catch (Throwable $e) { /* не ломаем приём */ }
    try { notify_max($id); } catch (Throwable $e) { /* не ломаем приём */ }

    return $id;
}

/**
 * Round-robin назначение менеджера на лид.
 * При assign_mode='roundrobin' выбирает следующего активного менеджера по кругу,
 * проставляет crm_leads.manager_id, двигает rr_pointer, пишет audit. Иначе null.
 */
function auto_assign(int $leadId): ?int {
    if (setting('assign_mode', 'off') !== 'roundrobin') return null;
    $pdo = pdo();

    $mgrs = $pdo->query(
        "SELECT id FROM crm_users WHERE active = 1 AND role IN ('manager','admin') ORDER BY id"
    )->fetchAll(PDO::FETCH_COLUMN);
    if (!$mgrs) return null;

    $ptr = (int)(setting('rr_pointer', '0') ?? 0);
    $idx = $ptr % count($mgrs);
    $managerId = (int)$mgrs[$idx];

    $up = $pdo->prepare('UPDATE crm_leads SET manager_id = ?, updated_at = NOW() WHERE id = ?');
    $up->execute([$managerId, $leadId]);

    $sp = $pdo->prepare(
        'INSERT INTO crm_settings (skey, sval) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE sval = VALUES(sval)'
    );
    $sp->execute(['rr_pointer', (string)(($idx + 1) % count($mgrs))]);

    audit($leadId, null, 'assigned', ['auto' => 1, 'manager' => $managerId]);
    return $managerId;
}

/** Низкоуровневый вызов Telegram Bot API через cURL. Возвращает true при ok:true. */
function tg_api(string $method, array $params): bool {
    // ВАЖНО: токен ищем и в настройках админки, и в config.php — как это делают
    // feedback.php/leads.php/digest.php. Раньше здесь был только secret('tg_token'),
    // поэтому при токене в config.php уведомления о заявках не уходили вообще,
    // а диагностика (она смотрит в config) рапортовала «Telegram работает».
    $token = secret('tg_token', (string)(cfg()['telegram']['token'] ?? ''));
    if ($token === '' || !function_exists('curl_init')) return false;
    $ch = curl_init("https://api.telegram.org/bot{$token}/{$method}");
    if ($ch === false) return false;
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $params,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false || $code !== 200) return false;
    $d = json_decode((string)$resp, true);
    return is_array($d) && !empty($d['ok']);
}

/**
 * Уведомление о новом лиде в Telegram с inline-кнопками действий.
 * Персонально менеджеру (crm_users.tg_chat), иначе notify_chat/tg_chat.
 */
function notify_new_lead(int $leadId): void {
    try {
        $st = pdo()->prepare('SELECT * FROM crm_leads WHERE id = ? LIMIT 1');
        $st->execute([$leadId]);
        $lead = $st->fetch();
        if (!$lead) return;

        // Кому слать: персонально назначенному менеджеру, иначе общий чат.
        $chat = '';
        if (!empty($lead['manager_id'])) {
            $mu = pdo()->prepare('SELECT tg_chat FROM crm_users WHERE id = ? LIMIT 1');
            $mu->execute([(int)$lead['manager_id']]);
            $chat = trim((string)$mu->fetchColumn());
        }
        // Тот же порядок источников, что и для токена: настройки → config.php.
        if ($chat === '') {
            $chat = secret('tg_chat', secret('notify_chat', (string)(cfg()['telegram']['chat'] ?? '')));
        }
        if ($chat === '') {
            // Канал Telegram не настроен — фиксируем, чтобы было видно в карточке.
            audit($leadId, null, 'notify_telegram', ['ok' => false, 'reason' => 'не задан чат/токен']);
            return;
        }

        $name   = (string)($lead['name'] ?? '');
        $phone  = (string)($lead['phone'] ?? '');
        $type   = (string)($lead['reducer_type'] ?? '');
        $source = (string)($lead['source'] ?? '');

        // Полный состав заявки: раньше в сообщении не было ни текста клиента, ни
        // признака вложения — менеджер не понимал, о чём заявка, и лез в CRM.
        $email2 = (string)($lead['email'] ?? '');
        $msg2   = trim((string)($lead['message'] ?? ''));
        $page2  = (string)($lead['page_title'] ?? '');
        $url2   = (string)($lead['page_url'] ?? '');
        $file2  = (string)($lead['file_path'] ?? '');

        $text  = "🔔 Новый лид #{$leadId}\n";
        $text .= "👤 {$name}\n";
        if ($phone !== '')  $text .= "📞 {$phone}\n";
        if ($email2 !== '') $text .= "✉️ {$email2}\n";
        if ($type !== '')   $text .= "⚙️ {$type}\n";
        if ($msg2 !== '')   $text .= "\n💬 " . mb_substr($msg2, 0, 700) . "\n";
        if ($page2 !== '')  $text .= "\n📄 {$page2}\n";
        if ($url2 !== '')   $text .= "{$url2}\n";
        if ($source !== '') $text .= "Источник: {$source}\n";
        if ($file2 !== '')  $text .= "📎 К заявке приложен файл — он придёт следующим сообщением.\n";

        $kb = ['inline_keyboard' => [
            [
                ['text' => '✋ Взять',       'callback_data' => "take:{$leadId}"],
                ['text' => '▶️ В работе',   'callback_data' => "status:{$leadId}:in_progress"],
            ],
            [
                ['text' => '✅ Успех',       'callback_data' => "status:{$leadId}:won"],
                ['text' => '❌ Отказ',       'callback_data' => "status:{$leadId}:lost"],
            ],
        ]];

        $payload = [
            'chat_id'      => $chat,
            'text'         => $text,
            'reply_markup' => json_encode($kb, JSON_UNESCAPED_UNICODE),
        ];
        $ok = tg_api('sendMessage', $payload);
        // Один повтор: сеть/Telegram могут моргнуть, а заявка ждать не должна.
        if (!$ok) { usleep(400000); $ok = tg_api('sendMessage', $payload); }

        // Запасной путь: если персональный чат менеджера не принял — дублируем в общий.
        // Без этого заявки round-robin уходили в тишину при неверном личном chat_id.
        $fallbackUsed = false;
        if (!$ok) {
            $common = secret('tg_chat', secret('notify_chat', (string)(cfg()['telegram']['chat'] ?? '')));
            if ($common !== '' && $common !== $chat) {
                $payload['chat_id'] = $common;
                $payload['text']    = "⚠️ Не доставлено назначенному менеджеру\n\n" . $text;
                $ok = tg_api('sendMessage', $payload);
                $fallbackUsed = true;
                if ($ok) $chat = $common;
            }
        }
        // Фиксируем факт доставки в Telegram — видно в ленте заявки.
        audit($leadId, null, 'notify_telegram', [
            'ok' => $ok, 'chat' => tg_mask_chat($chat),
            'fallback' => $fallbackUsed,
            'reason' => $ok ? '' : 'Telegram не принял сообщение (проверьте токен и chat_id)',
        ]);
    } catch (Throwable $e) { /* уведомление не должно ломать поток */ }
}

/** Маскирует ID чата/номер для журнала (не светим полностью). */
function tg_mask_chat(string $chat): string {
    $chat = trim($chat);
    if (strlen($chat) <= 4) return $chat;
    return substr($chat, 0, 3) . '…' . substr($chat, -2);
}

/**
 * Email-уведомление о новом лиде на общий ящик (cfg mail.to).
 * Для ВСЕХ каналов, кроме формы сайта (та шлёт письмо с вложением сама).
 * Пишет событие notify_email с исходом — видно в карточке и в диагностике.
 */
function notify_lead_email(int $leadId): bool {
    $to = trim((string)(cfg()['mail']['to'] ?? 'zr@zavod-red.ru'));
    if ($to === '' || !function_exists('mail')) return false;
    try {
        $st = pdo()->prepare('SELECT * FROM crm_leads WHERE id = ? LIMIT 1');
        $st->execute([$leadId]);
        $lead = $st->fetch();
        if (!$lead) return false;

        $from   = trim((string)(cfg()['mail']['from'] ?? 'no-reply@zavod-red.ru'));
        $name   = (string)($lead['name'] ?? '');
        $phone  = (string)($lead['phone'] ?? '');
        $email  = (string)($lead['email'] ?? '');
        $type   = (string)($lead['reducer_type'] ?? '');
        $source = (string)($lead['source'] ?? '');
        $esc    = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

        $link = 'https://zavod-red.ru/admin/lead.php?id=' . $leadId;
        $html  = "<h2>Новая заявка #{$leadId}</h2>";
        $html .= "<p><strong>Имя:</strong> {$esc($name)}</p>";
        if ($phone  !== '') $html .= "<p><strong>Телефон:</strong> {$esc($phone)}</p>";
        if ($email  !== '') $html .= "<p><strong>Email:</strong> {$esc($email)}</p>";
        if ($type   !== '') $html .= "<p><strong>Тип:</strong> {$esc($type)}</p>";
        if ($source !== '') $html .= "<p><strong>Источник:</strong> {$esc($source)}</p>";
        $html .= "<p><a href=\"{$link}\">Открыть заявку в CRM</a></p>";

        $r = notify_email_send($to, 'Новая заявка #' . $leadId . ' — zavod-red.ru', $html, (string)($lead['email'] ?? ''), true);
        audit($leadId, null, 'notify_email', ['ok' => $r['ok'], 'to' => $to, 'via' => $r['via']]);
        return $r['ok'];
    } catch (Throwable $e) {
        return false;
    }
}

/** Настроены ли исходящие уведомления в MAX (токен + чат). */
function max_notify_ready(): bool {
    $token = secret('max_token', (string)(cfg()['max']['token'] ?? ''));
    return $token !== '' && $token !== 'CHANGE_ME' && secret('max_notify_chat') !== '';
}

/**
 * Отправка сообщения ботом MAX. База platform-api2.max.ru, токен в заголовке
 * Authorization, получатель — chat_id в query, тело {text}. Возвращает ok.
 */
function max_api_send(string $chatId, string $text): bool {
    $token = secret('max_token', (string)(cfg()['max']['token'] ?? ''));
    if ($token === '' || $token === 'CHANGE_ME' || $chatId === '' || !function_exists('curl_init')) return false;
    $base = rtrim(secret('max_api_base', 'https://platform-api2.max.ru'), '/');
    $ch = curl_init($base . '/messages?chat_id=' . rawurlencode($chatId));
    if ($ch === false) return false;
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_HTTPHEADER     => ['Authorization: ' . $token, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode(['text' => $text], JSON_UNESCAPED_UNICODE),
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $resp !== false && $code >= 200 && $code < 300;
}

/**
 * MAX-уведомление о новом лиде (если настроен токен+чат). Пишет событие notify_max.
 */
function notify_max(int $leadId): bool {
    if (!max_notify_ready()) return false;
    try {
        $st = pdo()->prepare('SELECT * FROM crm_leads WHERE id = ? LIMIT 1');
        $st->execute([$leadId]);
        $lead = $st->fetch();
        if (!$lead) return false;
        $name   = (string)($lead['name'] ?? '');
        $phone  = (string)($lead['phone'] ?? '');
        $type   = (string)($lead['reducer_type'] ?? '');
        $source = (string)($lead['source'] ?? '');
        $text  = "🔔 Новая заявка #{$leadId}\nИмя: {$name}";
        if ($phone  !== '') $text .= "\nТел: {$phone}";
        if ($type   !== '') $text .= "\nТип: {$type}";
        if ($source !== '') $text .= "\nИсточник: {$source}";
        $ok = max_api_send(secret('max_notify_chat'), $text);
        audit($leadId, null, 'notify_max', ['ok' => $ok]);
        return $ok;
    } catch (Throwable $e) { return false; }
}

/** Записать сообщение в инбокс. */
function msg_insert(?int $leadId, string $channel, string $direction, string $body, array $opts = []): int {
    $st = pdo()->prepare(
        'INSERT INTO crm_messages (lead_id,channel,direction,contact,ext_id,subject,body,created_at) VALUES (?,?,?,?,?,?,?,NOW())'
    );
    // ВАЖНО: mb_substr, а не substr. Байтовая обрезка рвала UTF-8 посередине символа,
    // и MySQL отвергал вставку целиком («Incorrect string value: '\xD0'») — терялось
    // входящее сообщение с длинной кириллической темой.
    $st->execute([
        $leadId, $channel, $direction,
        mb_substr((string)($opts['contact'] ?? ''), 0, 190, 'UTF-8'),
        mb_substr((string)($opts['ext_id'] ?? ''), 0, 190, 'UTF-8'),
        mb_substr((string)($opts['subject'] ?? ''), 0, 255, 'UTF-8'),
        $body,
    ]);
    $mid = (int)pdo()->lastInsertId();
    // Флаг вложения ставим отдельным UPDATE: колонка has_attach появляется только
    // после mb_ensure() (api/mailbox.php), в базовой схеме её нет — INSERT бы упал.
    if ($mid && !empty($opts['has_attach'])) {
        try { pdo()->prepare('UPDATE crm_messages SET has_attach=1 WHERE id=?')->execute([$mid]); } catch (Throwable $e) { /* колонки ещё нет */ }
    }
    if ($leadId) audit($leadId, null, 'msg_' . $direction, ['channel' => $channel]);
    return $mid;
}

/** Лента сообщений лида (старые сверху). */
function inbox_thread(int $leadId): array {
    $st = pdo()->prepare('SELECT * FROM crm_messages WHERE lead_id=? ORDER BY created_at ASC, id ASC');
    $st->execute([$leadId]);
    return $st->fetchAll();
}
