<?php
declare(strict_types=1);

/**
 * /api/leads.php — CRM по лидам (требует авторизации).
 *   GET  ?action=list   фильтры status, manager_id, source, q, from, to + пагинация page, per
 *   GET  ?action=get&id=  лид + заметки + события timeline
 *   POST ?action=update  (csrf) изменение полей лида + audit
 *   POST ?action=note    (csrf) добавить заметку + audit
 *   POST ?action=email   (csrf) письмо клиенту + audit + заметка
 *   POST ?action=tags    (csrf) id, tags (через запятую) → UPDATE crm_leads.tags
 *   GET  ?action=dups    группы дублей по нормализованному phone/email (>1)
 *   POST ?action=merge   (csrf) from_id, to_id → перенос связей + слияние + удаление
 *   POST ?action=delete  (csrf) ids (csv) | id → удаление заявок со снимком в журнал
 */

require_once __DIR__ . '/helpers.php';

$user = require_auth();
$uid  = (int)($user['id'] ?? 0);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? ($_POST['action'] ?? 'list');

try {
    if ($method === 'GET') {
        if ($action === 'get') {
            lead_get();
        } elseif ($action === 'timeline') {
            lead_timeline();
        } elseif ($action === 'dups') {
            lead_dups();
        } elseif ($action === 'diag') {
            lead_diag();
        } else {
            lead_list();
        }
    } elseif ($method === 'POST') {
        csrf_check();
        switch ($action) {
            case 'create_manual': lead_create_manual($uid); break;
            case 'update': lead_update($uid); break;
            case 'workflow': lead_workflow($uid); break;
            case 'note':   lead_note($uid);   break;
            case 'email':  lead_email($uid);  break;
            case 'tags':   lead_tags($uid);   break;
            case 'take':   lead_take($uid);   break;
            case 'bulk':   lead_bulk($uid);   break;
            case 'upload': lead_upload($uid); break;
            case 'merge':  lead_merge($uid);  break;
            case 'delete': lead_delete($uid); break;
            case 'test_notify': lead_test_notify($uid); break;
            default: json_out(['ok' => false, 'error' => 'Неизвестное действие'], 400);
        }
    } else {
        json_out(['ok' => false, 'error' => 'Метод не поддерживается'], 405);
    }
} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => 'Внутренняя ошибка'], 500);
}

/**
 * GET ?action=diag — диагностика: почему заявок не видно.
 * Показывает реальную картину БД: всего, по источникам, по статусам, последняя
 * заявка, приход за 24ч, и проверку схемы (нет ли недостающих колонок, из-за
 * которых приём с сайта мог бы падать). Только для авторизованных.
 */
function lead_diag(): void {
    $pdo = pdo();
    $total = (int)$pdo->query("SELECT COUNT(*) FROM crm_leads")->fetchColumn();

    $bySource = $pdo->query(
        "SELECT COALESCE(NULLIF(source,''), NULLIF(utm_source,''), 'не указан') AS src, COUNT(*) c
         FROM crm_leads GROUP BY src ORDER BY c DESC LIMIT 20"
    )->fetchAll(PDO::FETCH_ASSOC);

    $byStatus = $pdo->query(
        "SELECT status, COUNT(*) c FROM crm_leads GROUP BY status ORDER BY c DESC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $last = $pdo->query(
        "SELECT id, name, phone, COALESCE(NULLIF(source,''), utm_source, '') src, created_at
         FROM crm_leads ORDER BY id DESC LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC) ?: null;

    $last24 = (int)$pdo->query(
        "SELECT COUNT(*) FROM crm_leads WHERE created_at >= (NOW() - INTERVAL 1 DAY)"
    )->fetchColumn();

    // Повторные обращения за сутки: заявка была, письмо ушло, но НОВОЙ карточки нет —
    // обновилась существующая. Именно это расхождение читалось как «в CRM не приходит».
    $repeat24 = 0;
    if (lead_repeat_columns_ready()) {
        $repeat24 = (int)$pdo->query(
            "SELECT COUNT(*) FROM crm_leads
             WHERE last_inquiry_at >= (NOW() - INTERVAL 1 DAY)
               AND last_inquiry_at > created_at"
        )->fetchColumn();
    }

    // Веб-заявки (форма сайта): source пустой/site/форма или есть page_url.
    $fromSite = (int)$pdo->query(
        "SELECT COUNT(*) FROM crm_leads
         WHERE source IN ('site','form','') OR page_url LIKE 'http%'"
    )->fetchColumn();

    // Проверка схемы: колонки, которые пишет приём с сайта (api/feedback.php).
    $need = ['name','phone','email','reducer_type','message','page_url','page_title',
             'file_path','source','utm_source','utm_medium','utm_campaign','utm_term',
             'utm_content','referrer','gclid','yclid','ip','user_agent','status','amount','tags'];
    $have = $pdo->query("SHOW COLUMNS FROM crm_leads")->fetchAll(PDO::FETCH_COLUMN);
    $missing = array_values(array_diff($need, $have));

    // --- Каналы уведомлений о новой заявке (куда уходит каждая заявка) ---
    $isSet = static fn($v) => $v !== '' && $v !== 'CHANGE_ME';
    $tgToken = secret('tg_token', (string)(cfg()['telegram']['token'] ?? ''));
    $tgChat  = secret('tg_chat', secret('notify_chat', (string)(cfg()['telegram']['chat'] ?? '')));
    $maxTok  = secret('max_token', (string)(cfg()['max']['token'] ?? ''));
    $channels = [
        'email'    => ['on' => true, 'to' => (string)(cfg()['mail']['to'] ?? 'zr@zavod-red.ru')],
        'telegram' => ['on' => $isSet($tgToken) && $isSet($tgChat)],
        'max'      => ['on' => $isSet($maxTok) && secret('max_notify_chat') !== ''],
    ];

    // --- Статистика доставки за 30 дней (из журнала событий crm_events) ---
    $delivery = ['email' => ['ok' => 0, 'fail' => 0], 'telegram' => ['ok' => 0, 'fail' => 0], 'max' => ['ok' => 0, 'fail' => 0]];
    try {
        $rows = $pdo->query(
            "SELECT type, payload FROM crm_events
             WHERE type IN ('notify_email','notify_telegram','notify_telegram_doc','notify_max')
               AND created_at >= (NOW() - INTERVAL 30 DAY)"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $ev) {
            $p = json_decode((string)$ev['payload'], true) ?: [];
            $key = $ev['type'] === 'notify_email' ? 'email' : ($ev['type'] === 'notify_max' ? 'max' : 'telegram');
            $delivery[$key][!empty($p['ok']) ? 'ok' : 'fail']++;
        }
    } catch (Throwable $e) { /* журнал не обязателен */ }

    json_out([
        'ok'         => true,
        'total'      => $total,
        'last24h'    => $last24,
        'repeat24h'  => $repeat24,
        'from_site'  => $fromSite,
        'by_source'  => $bySource,
        'by_status'  => $byStatus,
        'last_lead'  => $last,
        'schema_ok'  => empty($missing),
        'missing_columns' => $missing,
        'channels'   => $channels,
        'delivery'   => $delivery,
    ]);
}

/**
 * POST ?action=test_notify — проверка каналов уведомлений по требованию.
 * Реально шлёт тест на email и в Telegram (в свои же каналы), возвращает ✓/✗.
 * MAX — только статус настройки (исходящая отправка не настроена). Пишет событие.
 */
function lead_test_notify(int $uid): never {
    require_once __DIR__ . '/inbox.php';
    $out = [];

    // Email — через тот же путь, что и боевые уведомления (SMTP → фолбэк mail()).
    $to = trim((string)(cfg()['mail']['to'] ?? 'zr@zavod-red.ru'));
    $html = '<h3>Тест уведомлений — CRM Завод Редукторов</h3>'
          . '<p>Если вы видите это письмо — канал <b>email</b> работает.</p>'
          . '<p>Отправлено вручную из админки (Заявки → Проверить каналы).</p>';
    $r = notify_email_send($to, 'Тест уведомлений — zavod-red.ru', $html);
    $out['email'] = ['ok' => $r['ok'], 'via' => $r['via'], 'to' => $to];

    // Telegram — в общий чат/чат менеджера.
    $chat = secret('tg_chat', secret('notify_chat'));
    if (secret('tg_token') === '' || $chat === '') {
        $out['telegram'] = ['ok' => false, 'reason' => 'не задан токен бота или чат'];
    } else {
        $ok = tg_api('sendMessage', [
            'chat_id' => $chat,
            'text'    => "🔔 Тест уведомлений CRM Завод Редукторов.\nЕсли вы видите это сообщение — Telegram-канал работает.",
        ]);
        $out['telegram'] = ['ok' => $ok, 'chat' => tg_mask_chat($chat)];
    }

    // MAX — реально шлём тест, если настроены токен и чат уведомлений.
    if (max_notify_ready()) {
        $ok = max_api_send(secret('max_notify_chat'),
            "🔔 Тест уведомлений CRM Завод Редукторов.\nЕсли вы видите это сообщение — MAX-канал работает.");
        $out['max'] = ['ok' => $ok, 'configured' => true];
    } else {
        $out['max'] = ['configured' => false];
    }

    audit(null, $uid, 'notify_test', $out);
    json_out(['ok' => true, 'result' => $out]);
}

/** GET ?action=list */
function lead_list(): never {
    $where  = [];
    $params = [];

    $status = trim((string)($_GET['status'] ?? ''));
    $valid  = status_valid();
    if ($status !== '' && in_array($status, $valid, true)) {
        $where[] = 'l.status = ?';
        $params[] = $status;
    }

    $managerId = $_GET['manager_id'] ?? '';
    if ($managerId !== '' && ctype_digit((string)$managerId)) {
        if ((string)$managerId === '0') {
            // «Без менеджера»: в БД это NULL, а не 0 — manager_id = 0 ничего не находит.
            $where[] = 'l.manager_id IS NULL';
        } else {
            $where[] = 'l.manager_id = ?';
            $params[] = (int)$managerId;
        }
    }

    // Раздел «Заявки» — это обращения клиентов, а не почтовый ящик. Письма, которые
    // затягивает mail_sync (source='email'), живут в разделе «Почта» и по умолчанию
    // сюда не попадают: иначе десятки писем в день заслоняют настоящие заявки.
    // Посмотреть их можно, выбрав источник «почта» явно.
    $source = trim((string)($_GET['source'] ?? ''));
    if ($source !== '') {
        $where[] = '(l.source = ? OR l.utm_source = ?)';
        $params[] = $source;
        $params[] = $source;
    } else {
        $where[] = "COALESCE(l.source,'') <> 'email'";
    }

    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') {
        $where[] = '(l.name LIKE ? OR l.phone LIKE ? OR l.email LIKE ?)';
        $like = '%' . $q . '%';
        $params[] = $like; $params[] = $like; $params[] = $like;
    }

    // Тег (как в api/export.php)
    $tag = trim((string)($_GET['tag'] ?? ''));
    if ($tag !== '') {
        $where[] = 'l.tags LIKE ?';
        $params[] = '%' . $tag . '%';
    }

    // Период считаем по дате ПОСЛЕДНЕГО обращения: повторная заявка известного клиента
    // не создаёт новый лид, и по created_at (дате первого касания) она не попадала бы
    // в фильтр «за сегодня» — менеджер видел письмо, но в отфильтрованном списке пусто.
    $inquiryAt = lead_inquiry_at_sql('l');
    $from = trim((string)($_GET['from'] ?? ''));
    if ($from !== '') {
        $where[] = "$inquiryAt >= ?";
        $params[] = $from . ' 00:00:00';
    }
    $to = trim((string)($_GET['to'] ?? ''));
    if ($to !== '') {
        $where[] = "$inquiryAt <= ?";
        $params[] = $to . ' 23:59:59';
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // Пагинация (per_page — синоним per). Канбан просит 500, чтобы видеть всю воронку:
    // при потолке 200 колонки молча обрезались.
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per  = (int)($_GET['per'] ?? ($_GET['per_page'] ?? 30));
    if ($per < 1)   $per = 30;
    if ($per > 500) $per = 500;
    $offset = ($page - 1) * $per;

    $pdo = pdo();

    // total
    $stTotal = $pdo->prepare("SELECT COUNT(*) FROM crm_leads l $whereSql");
    $stTotal->execute($params);
    $total = (int)$stTotal->fetchColumn();

    // items
    // Сортировка по последнему обращению, а не по дате создания: повторная заявка
    // обновляет старую карточку, и при ORDER BY created_at она оставалась внизу списка —
    // выглядело как «заявка не пришла в CRM». inquiry_at отдаём фронту для колонки «Дата».
    $sql = "SELECT l.*, u.name AS manager_name, $inquiryAt AS inquiry_at
            FROM crm_leads l
            LEFT JOIN crm_users u ON u.id = l.manager_id
            $whereSql
            ORDER BY $inquiryAt DESC, l.id DESC
            LIMIT $per OFFSET $offset";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $items = $st->fetchAll();

    // Канбан: count по статусам (с учётом тех же фильтров)
    $stK = $pdo->prepare("SELECT l.status, COUNT(*) AS c FROM crm_leads l $whereSql GROUP BY l.status");
    $stK->execute($params);
    $kanban = ['new' => 0, 'in_progress' => 0, 'quoted' => 0, 'won' => 0, 'lost' => 0];
    foreach ($stK->fetchAll() as $r) {
        $kanban[(string)$r['status']] = (int)$r['c'];
    }

    json_out([
        'ok'     => true,
        'items'  => $items,
        'total'  => $total,
        'page'   => $page,
        'per'    => $per,
        'kanban' => $kanban,
    ]);
}

/** GET ?action=get&id= */
function lead_get(): never {
    $id = (int)($_GET['id'] ?? 0);
    if ($id < 1) json_out(['ok' => false, 'error' => 'Не указан id'], 400);

    $pdo = pdo();
    $st = $pdo->prepare(
        "SELECT l.*, u.name AS manager_name
         FROM crm_leads l
         LEFT JOIN crm_users u ON u.id = l.manager_id
         WHERE l.id = ?"
    );
    $st->execute([$id]);
    $lead = $st->fetch();
    if (!$lead) json_out(['ok' => false, 'error' => 'Лид не найден'], 404);

    // Заметки (новые сверху)
    $stN = $pdo->prepare(
        "SELECT n.*, u.name AS user_name
         FROM crm_notes n
         LEFT JOIN crm_users u ON u.id = n.user_id
         WHERE n.lead_id = ?
         ORDER BY n.created_at DESC, n.id DESC"
    );
    $stN->execute([$id]);
    $notes = $stN->fetchAll();

    // События timeline (новые сверху)
    $stE = $pdo->prepare(
        "SELECT e.*, u.name AS user_name
         FROM crm_events e
         LEFT JOIN crm_users u ON u.id = e.user_id
         WHERE e.lead_id = ?
         ORDER BY e.created_at DESC, e.id DESC"
    );
    $stE->execute([$id]);
    $events = $stE->fetchAll();
    foreach ($events as &$ev) {
        $ev['payload'] = $ev['payload'] !== null ? json_decode((string)$ev['payload'], true) : null;
    }
    unset($ev);

    json_out([
        'ok'     => true,
        'lead'   => $lead,
        'notes'  => $notes,
        'events' => $events,
    ]);
}

/**
 * GET ?action=timeline&id= — единая хронология по лиду.
 * Объединяет crm_messages, crm_events, crm_notes, crm_ai в одну ленту (ASC по ts).
 * Каждый элемент: {ts, kind, icon, title, body}.
 */
function lead_timeline(): never {
    $id = (int)($_GET['id'] ?? 0);
    if ($id < 1) json_out(['ok' => false, 'error' => 'Не указан id'], 400);

    $pdo = pdo();
    $exists = $pdo->prepare('SELECT id FROM crm_leads WHERE id = ?');
    $exists->execute([$id]);
    if (!$exists->fetchColumn()) json_out(['ok' => false, 'error' => 'Лид не найден'], 404);

    $items = [];

    // Сообщения (омниканал)
    $chLabels = ['email' => 'Email', 'telegram' => 'Telegram', 'max' => 'MAX', 'form' => 'Форма сайта'];
    try {
        $st = $pdo->prepare('SELECT * FROM crm_messages WHERE lead_id = ? ORDER BY created_at ASC, id ASC');
        $st->execute([$id]);
        foreach ($st->fetchAll() as $m) {
            $dir   = (string)($m['direction'] ?? '');
            $isOut = ($dir === 'out');
            $ch    = (string)($m['channel'] ?? '');
            $chLbl = $chLabels[$ch] ?? ($ch !== '' ? $ch : '—');
            $items[] = [
                'ts'    => (string)($m['created_at'] ?? ''),
                'kind'  => $isOut ? 'msg_out' : 'msg_in',
                'icon'  => $isOut ? '📤' : '📥',
                'title' => $chLbl . ' · ' . ($isOut ? 'исходящее' : 'входящее'),
                'body'  => (string)($m['body'] ?? ''),
            ];
        }
    } catch (Throwable $e) { /* таблицы может не быть */ }

    // События (журнал)
    // Подписи — из единой воронки (раньше тут было 5 старых статусов, и review/approved/sent
    // показывались в ленте сырыми кодами).
    $statusLbl = [];
    foreach (funnel_codes() as $code) $statusLbl[$code] = status_label($code);
    $statusLbl['quoted'] = status_label('quoted');
    try {
        $st = $pdo->prepare(
            'SELECT e.*, u.name AS user_name FROM crm_events e
             LEFT JOIN crm_users u ON u.id = e.user_id
             WHERE e.lead_id = ? ORDER BY e.created_at ASC, e.id ASC'
        );
        $st->execute([$id]);
        foreach ($st->fetchAll() as $e) {
            $type    = (string)($e['type'] ?? '');
            $payload = $e['payload'] !== null ? json_decode((string)$e['payload'], true) : null;
            $who     = (string)($e['user_name'] ?? '');
            $title   = '';
            $body    = '';
            switch ($type) {
                case 'lead_created':
                    $title = 'Лид создан';
                    break;
                case 'status_changed':
                    $f = (string)($payload['from'] ?? '');
                    $t = (string)($payload['to'] ?? '');
                    $title = 'Статус: ' . ($statusLbl[$f] ?? $f) . ' → ' . ($statusLbl[$t] ?? $t);
                    break;
                case 'assigned':
                    $title = 'Назначен';
                    break;
                case 'msg_in':
                    $title = 'Входящее сообщение';
                    $body  = isset($payload['channel']) ? ('Канал: ' . $payload['channel']) : '';
                    break;
                case 'msg_out':
                    $title = 'Исходящее сообщение';
                    $body  = isset($payload['channel']) ? ('Канал: ' . $payload['channel']) : '';
                    break;
                case 'email_sent':
                    $title = 'Отправлено письмо';
                    $body  = (string)($payload['subject'] ?? '');
                    break;
                case 'note_added':
                    $title = 'Добавлена заметка';
                    break;
                case 'tags_updated':
                    $title = 'Обновлены теги';
                    $body  = (string)($payload['tags'] ?? '');
                    break;
                case 'lead_merged':
                    $title = 'Лиды объединены';
                    break;
                case 'login':
                    $title = 'Вход';
                    break;
                default:
                    if (str_starts_with($type, 'ai_')) {
                        $title = 'ИИ: ' . substr($type, 3);
                    } else {
                        $title = $type !== '' ? $type : 'Событие';
                    }
            }
            if ($who !== '') {
                $body = ($body !== '' ? $body . ' · ' : '') . $who;
            }
            $items[] = [
                'ts'    => (string)($e['created_at'] ?? ''),
                'kind'  => 'event',
                'icon'  => '⚙️',
                'title' => $title,
                'body'  => $body,
            ];
        }
    } catch (Throwable $e) { /* пропуск */ }

    // Заметки
    try {
        $st = $pdo->prepare(
            'SELECT n.*, u.name AS user_name FROM crm_notes n
             LEFT JOIN crm_users u ON u.id = n.user_id
             WHERE n.lead_id = ? ORDER BY n.created_at ASC, n.id ASC'
        );
        $st->execute([$id]);
        foreach ($st->fetchAll() as $n) {
            $who = (string)($n['user_name'] ?? '');
            $items[] = [
                'ts'    => (string)($n['created_at'] ?? ''),
                'kind'  => 'note',
                'icon'  => '📝',
                'title' => 'Заметка' . ($who !== '' ? ' · ' . $who : ''),
                'body'  => (string)($n['text'] ?? ''),
            ];
        }
    } catch (Throwable $e) { /* пропуск */ }

    // ИИ
    $aiLbl = ['suggest' => 'Подбор аналога', 'draft' => 'Черновик ответа', 'recommend' => 'Рекомендация',
              'recognize' => 'Распознавание заявки', 'nameplate' => 'Черновик по шильдику', 'autoreply' => 'Автоответ'];
    // Результаты распознавания хранятся JSON-ом — в ленте показываем человеку суть, а не скобки.
    $aiReadable = static function (string $type, string $body): string {
        if ($body === '' || $body[0] !== '{') return $body;
        $d = json_decode($body, true);
        if (!is_array($d)) return $body;
        $lines = [];
        $hyp = $d['hypotheses'] ?? ($d['nameplate']['hypotheses'] ?? []);
        foreach ((array)$hyp as $i => $h) {
            $lines[] = ($i + 1) . '. ' . trim(($h['brand'] ?? '') . ' ' . ($h['model'] ?? ''))
                . ' → ' . (($h['zr'] ?? '') !== '' ? $h['zr'] : 'нет в справочнике') . ' · ' . (int)($h['confidence'] ?? 0) . '%';
        }
        foreach ((array)($d['analogs'] ?? []) as $a) {
            $lines[] = ($a['for'] ?? '—') . ' → ' . ($a['our'] ?? '—') . (!empty($a['verified']) ? ' ✓' : '');
        }
        if (!empty($d['summary'])) array_unshift($lines, (string)$d['summary']);
        if (isset($d['confidence']) && !$hyp) $lines[] = 'уверенность ' . (int)$d['confidence'] . '%';
        if (!empty($d['missing'])) $lines[] = 'Не хватает: ' . implode('; ', (array)$d['missing']);
        if (!empty($d['draft'])) $lines[] = "\nЧерновик:\n" . (string)$d['draft'];
        if (!empty($d['text']) && !$lines) $lines[] = (string)$d['text'];
        return $lines ? implode("\n", $lines) : $body;
    };
    try {
        $st = $pdo->prepare('SELECT * FROM crm_ai WHERE lead_id = ? ORDER BY created_at ASC, id ASC');
        $st->execute([$id]);
        foreach ($st->fetchAll() as $a) {
            $atype = (string)($a['type'] ?? '');
            $body  = (string)($a['result'] ?? ($a['text'] ?? ($a['output'] ?? ($a['content'] ?? ''))));
            $body  = $aiReadable($atype, $body);
            $items[] = [
                'ts'    => (string)($a['created_at'] ?? ''),
                'kind'  => 'ai',
                'icon'  => '🤖',
                'title' => 'ИИ: ' . ($aiLbl[$atype] ?? ($atype !== '' ? $atype : 'ассистент')),
                'body'  => $body,
            ];
        }
    } catch (Throwable $e) { /* пропуск */ }

    // Сортировка ASC по ts (stable)
    usort($items, static function (array $a, array $b): int {
        $c = strcmp((string)$a['ts'], (string)$b['ts']);
        return $c;
    });

    json_out(['ok' => true, 'items' => $items]);
}

/**
 * POST ?action=take&id= — взять лид в работу за текущим менеджером.
 * manager_id = current user; статус 'new' → 'in_progress'. audit assigned + status_changed.
 */
function lead_take(int $uid): never {
    $id = (int)($_POST['id'] ?? 0);
    if ($id < 1) json_out(['ok' => false, 'error' => 'Не указан id'], 400);
    if ($uid < 1) json_out(['ok' => false, 'error' => 'Не определён пользователь'], 400);

    $pdo = pdo();
    $st = $pdo->prepare('SELECT id, status, manager_id FROM crm_leads WHERE id = ?');
    $st->execute([$id]);
    $lead = $st->fetch();
    if (!$lead) json_out(['ok' => false, 'error' => 'Лид не найден'], 404);

    $set = [];
    $params = [];

    if ((int)($lead['manager_id'] ?? 0) !== $uid) {
        $set[] = 'manager_id = ?';
        $params[] = $uid;
        audit($id, $uid, 'assigned', ['from' => $lead['manager_id'], 'to' => $uid]);
    }

    if ((string)($lead['status'] ?? '') === 'new') {
        $set[] = 'status = ?';
        $params[] = 'in_progress';
        audit($id, $uid, 'status_changed', ['from' => 'new', 'to' => 'in_progress']);
    }

    if ($set) {
        $set[] = 'updated_at = NOW()';
        $params[] = $id;
        $pdo->prepare('UPDATE crm_leads SET ' . implode(', ', $set) . ' WHERE id = ?')
            ->execute($params);
    }

    json_out(['ok' => true]);
}

/**
 * POST ?action=workflow — переход по инженерной воронке (кнопки инженера/менеджера).
 * Поля: id, status (целевой код воронки), note (опц. комментарий/причина).
 * Пишет status_changed в журнал + (если есть) заметку. Возвращает подпись нового статуса.
 */
function lead_workflow(int $uid): never {
    $id     = (int)($_POST['id'] ?? 0);
    $status = trim((string)($_POST['status'] ?? ''));
    $note   = trim((string)($_POST['note'] ?? ''));
    if ($id < 1) json_out(['ok' => false, 'error' => 'Не указан id'], 400);
    if (!in_array($status, funnel_codes(), true)) json_out(['ok' => false, 'error' => 'Неверный статус'], 400);

    $pdo = pdo();
    $st = $pdo->prepare('SELECT id, status, manager_id FROM crm_leads WHERE id = ?');
    $st->execute([$id]);
    $lead = $st->fetch();
    if (!$lead) json_out(['ok' => false, 'error' => 'Лид не найден'], 404);

    $from = (string)$lead['status'];

    // Мягкая валидация перехода: закрытую заявку (won/lost) нельзя телепортировать
    // сразу в середину воронки — сначала переоткрыть в «Новая»/«В работе». Всё
    // остальное (прямой поток, ручные ходы, переоткрытие) остаётся свободным.
    if (in_array($from, ['won', 'lost'], true)
        && $from !== $status
        && !in_array($status, ['new', 'in_progress'], true)) {
        json_out(['ok' => false, 'error' => 'Заявка закрыта («' . status_label($from)
            . '»). Сначала переоткройте её — переведите в «Новая» или «В работе».'], 409);
    }

    $set = ['status = ?', 'updated_at = NOW()'];
    $params = [$status];

    // при взятии в работу без менеджера — назначаем текущего
    if ($status === 'in_progress' && !$lead['manager_id']) {
        $set[] = 'manager_id = ?';
        $params[] = $uid;
        audit($id, $uid, 'assigned', ['from' => null, 'to' => $uid]);
    }
    // причина отказа → в lost_reason
    if ($status === 'lost' && $note !== '') {
        $set[] = 'lost_reason = ?';
        $params[] = mb_substr($note, 0, 255);
    }

    $params[] = $id;
    $pdo->prepare('UPDATE crm_leads SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($params);

    if ($from !== $status) audit($id, $uid, 'status_changed', ['from' => $from, 'to' => $status]);
    if ($note !== '') {
        $pdo->prepare('INSERT INTO crm_notes (lead_id,user_id,text,created_at) VALUES (?,?,?,NOW())')
            ->execute([$id, $uid, $note]);
    }

    json_out(['ok' => true, 'status' => $status, 'label' => status_label($status)]);
}

/**
 * POST ?action=create_manual — ручное создание лида.
 * Поля: name (required), phone|email (хотя бы одно), reducer_type, message,
 * manager_id (опц), amount (опц), status (по умолчанию 'new'). source='manual'.
 */
function lead_create_manual(int $uid): never {
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') {
        json_out(['ok' => false, 'error' => 'Не указано имя'], 400);
    }
    $name = mb_substr($name, 0, 160); // = VARCHAR(160) в схеме

    $phone = trim((string)($_POST['phone'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    if ($phone === '' && $email === '') {
        json_out(['ok' => false, 'error' => 'Укажите телефон или email'], 400);
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_out(['ok' => false, 'error' => 'Некорректный email'], 400);
    }
    $phone = mb_substr($phone, 0, 40); // = VARCHAR(40)
    $email = mb_substr($email, 0, 160); // = VARCHAR(160)

    $reducerType = mb_substr(trim((string)($_POST['reducer_type'] ?? '')), 0, 120); // = VARCHAR(120)
    $message     = trim((string)($_POST['message'] ?? ''));

    $status = trim((string)($_POST['status'] ?? 'new'));
    $valid  = status_valid();
    if (!in_array($status, $valid, true)) $status = 'new';

    $mgrRaw     = (string)($_POST['manager_id'] ?? '');
    $managerId  = ($mgrRaw === '' || $mgrRaw === '0') ? null : (int)$mgrRaw;

    $amount = null;
    if (array_key_exists('amount', $_POST) && trim((string)$_POST['amount']) !== '') {
        $amount = (float)str_replace([' ', ','], ['', '.'], (string)$_POST['amount']);
        if ($amount < 0) $amount = 0.0;
    }

    $pdo = pdo();
    $st = $pdo->prepare(
        "INSERT INTO crm_leads (name, phone, email, reducer_type, message, status, manager_id, amount, source, created_at, updated_at)
         VALUES (?,?,?,?,?,?,?,?, 'manual', NOW(), NOW())"
    );
    $st->execute([
        $name,
        $phone,        // NOT NULL DEFAULT '' — пустая строка, не null
        $email,        // NOT NULL DEFAULT ''
        $reducerType,  // NOT NULL DEFAULT ''
        $message !== '' ? $message : null, // message TEXT NULL — null допустим
        $status,
        $managerId,    // manager_id INT NULL — null допустим
        $amount ?? 0,  // amount NOT NULL DEFAULT 0
    ]);
    $id = (int)$pdo->lastInsertId();

    audit($id, $uid, 'lead_created', ['manual' => 1]);

    json_out(['ok' => true, 'id' => $id]);
}

/** POST ?action=update */
function lead_update(int $uid): never {
    $id = (int)($_POST['id'] ?? 0);
    if ($id < 1) json_out(['ok' => false, 'error' => 'Не указан id'], 400);

    $pdo = pdo();
    $st = $pdo->prepare('SELECT * FROM crm_leads WHERE id = ?');
    $st->execute([$id]);
    $lead = $st->fetch();
    if (!$lead) json_out(['ok' => false, 'error' => 'Лид не найден'], 404);

    $set = [];
    $params = [];

    // status
    if (array_key_exists('status', $_POST)) {
        $status = trim((string)$_POST['status']);
        $valid  = status_valid();
        if (!in_array($status, $valid, true)) {
            json_out(['ok' => false, 'error' => 'Неверный статус'], 400);
        }
        $curStatus = (string)$lead['status'];
        // Тот же guard, что и в lead_workflow: закрытую заявку (won/lost) нельзя
        // телепортировать в середину воронки в обход — сначала переоткрыть.
        if (in_array($curStatus, ['won', 'lost'], true)
            && $status !== $curStatus
            && !in_array($status, ['new', 'in_progress'], true)) {
            json_out(['ok' => false, 'error' => 'Заявка закрыта («' . status_label($curStatus)
                . '»). Сначала переоткройте её — переведите в «Новая» или «В работе».'], 409);
        }
        if ($status !== $curStatus) {
            $set[] = 'status = ?';
            $params[] = $status;
            audit($id, $uid, 'status_changed', ['from' => $lead['status'], 'to' => $status]);
        }
    }

    // manager_id (назначение)
    if (array_key_exists('manager_id', $_POST)) {
        $mgrRaw = (string)$_POST['manager_id'];
        $mgr = ($mgrRaw === '' || $mgrRaw === '0') ? null : (int)$mgrRaw;
        if ((string)$mgr !== (string)$lead['manager_id']) {
            $set[] = 'manager_id = ?';
            $params[] = $mgr;
            audit($id, $uid, 'assigned', ['from' => $lead['manager_id'], 'to' => $mgr]);
        }
    }

    // amount
    if (array_key_exists('amount', $_POST)) {
        $amount = (float)str_replace([' ', ','], ['', '.'], (string)$_POST['amount']);
        if ($amount < 0) $amount = 0.0;
        $set[] = 'amount = ?';
        $params[] = $amount;
    }

    // lost_reason
    if (array_key_exists('lost_reason', $_POST)) {
        $reason = trim((string)$_POST['lost_reason']);
        $set[] = 'lost_reason = ?';
        $params[] = ($reason === '' ? null : mb_substr($reason, 0, 255));
    }

    // next_action_at
    if (array_key_exists('next_action_at', $_POST)) {
        $na = trim((string)$_POST['next_action_at']);
        $set[] = 'next_action_at = ?';
        $params[] = ($na === '' ? null : $na);
    }

    if (!$set) {
        json_out(['ok' => true]); // нечего обновлять
    }

    $set[] = 'updated_at = NOW()';
    $sql = 'UPDATE crm_leads SET ' . implode(', ', $set) . ' WHERE id = ?';
    $params[] = $id;
    $pdo->prepare($sql)->execute($params);

    json_out(['ok' => true]);
}

/**
 * POST ?action=upload — прикрепить PDF/фото к заявке (multipart, поле file).
 * Валидация расширения и размера; файл кладём в uploads_dir под случайным именем;
 * crm_leads.file_path = 'crm-data/uploads/<name>'. Отдаётся через api/file.php.
 */
function lead_upload(int $uid): never {
    $id = (int)($_POST['id'] ?? 0);
    if ($id < 1) json_out(['ok' => false, 'error' => 'Не указан id'], 400);
    if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        json_out(['ok' => false, 'error' => 'Файл не получен'], 400);
    }
    $f = $_FILES['file'];
    if ((int)$f['size'] > 15 * 1024 * 1024) json_out(['ok' => false, 'error' => 'Файл больше 15 МБ'], 413);

    $allowed = ['pdf','jpg','jpeg','png','webp','gif','bmp','heic','heif','doc','docx','xls','xlsx','dwg','dxf'];
    $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) json_out(['ok' => false, 'error' => 'Недопустимый тип файла'], 415);

    $pdo = pdo();
    $ex = $pdo->prepare('SELECT id FROM crm_leads WHERE id = ?');
    $ex->execute([$id]);
    if (!$ex->fetchColumn()) json_out(['ok' => false, 'error' => 'Лид не найден'], 404);

    $uploadsDir = rtrim((string)(cfg()['uploads_dir'] ?? (__DIR__ . '/../crm-data/uploads')), '/');
    if (!is_dir($uploadsDir)) @mkdir($uploadsDir, 0750, true);
    try { $rand = bin2hex(random_bytes(16)); } catch (Throwable $e) { $rand = md5(uniqid('', true)); }
    $name = $rand . '.' . $ext;
    $dest = $uploadsDir . '/' . $name;
    if (!@move_uploaded_file($f['tmp_name'], $dest)) {
        json_out(['ok' => false, 'error' => 'Не удалось сохранить файл'], 500);
    }
    @chmod($dest, 0640);
    $rel = 'crm-data/uploads/' . $name;
    $pdo->prepare('UPDATE crm_leads SET file_path = ?, updated_at = NOW() WHERE id = ?')->execute([$rel, $id]);
    audit($id, $uid, 'file_uploaded', ['name' => mb_substr((string)$f['name'], 0, 120)]);

    json_out(['ok' => true, 'file_path' => $rel]);
}

/**
 * POST ?action=bulk — массовое обновление выбранных заявок.
 * Поля: ids (csv), опц. status (код воронки), manager_id (int|0=снять).
 * Применяет к каждой заявке status и/или назначение с записью в журнал.
 */
function lead_bulk(int $uid): never {
    $idsRaw = (string)($_POST['ids'] ?? '');
    $ids = array_values(array_filter(array_map('intval', explode(',', $idsRaw)), fn($v) => $v > 0));
    $ids = array_slice(array_unique($ids), 0, 500);
    if (!$ids) json_out(['ok' => false, 'error' => 'Не выбраны заявки'], 400);

    $setStatus = null;
    if (array_key_exists('status', $_POST) && trim((string)$_POST['status']) !== '') {
        $s = trim((string)$_POST['status']);
        if (!in_array($s, status_valid(), true)) json_out(['ok' => false, 'error' => 'Неверный статус'], 400);
        $setStatus = $s;
    }
    $setMgr = null; $mgrProvided = false;
    if (array_key_exists('manager_id', $_POST) && (string)$_POST['manager_id'] !== '') {
        $mgrProvided = true;
        $raw = (string)$_POST['manager_id'];
        $setMgr = ($raw === '0') ? null : (int)$raw;
    }
    if ($setStatus === null && !$mgrProvided) json_out(['ok' => false, 'error' => 'Нечего применять'], 400);

    $pdo = pdo();
    $updated = 0;
    // текущее состояние выбранных — для корректного журнала
    $place = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT id, status, manager_id FROM crm_leads WHERE id IN ($place)");
    $st->execute($ids);
    foreach ($st->fetchAll() as $lead) {
        $id = (int)$lead['id'];
        $set = []; $params = [];
        if ($setStatus !== null && (string)$lead['status'] !== $setStatus) {
            $set[] = 'status = ?'; $params[] = $setStatus;
            audit($id, $uid, 'status_changed', ['from' => $lead['status'], 'to' => $setStatus, 'bulk' => true]);
        }
        if ($mgrProvided && (string)$lead['manager_id'] !== (string)$setMgr) {
            $set[] = 'manager_id = ?'; $params[] = $setMgr;
            audit($id, $uid, 'assigned', ['from' => $lead['manager_id'], 'to' => $setMgr, 'bulk' => true]);
        }
        if ($set) {
            $set[] = 'updated_at = NOW()';
            $params[] = $id;
            $pdo->prepare('UPDATE crm_leads SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($params);
            $updated++;
        }
    }
    json_out(['ok' => true, 'updated' => $updated, 'selected' => count($ids)]);
}

/** POST ?action=note */
function lead_note(int $uid): never {
    $id   = (int)($_POST['id'] ?? 0);
    $text = trim((string)($_POST['text'] ?? ''));
    if ($id < 1)      json_out(['ok' => false, 'error' => 'Не указан id'], 400);
    if ($text === '') json_out(['ok' => false, 'error' => 'Пустая заметка'], 400);

    $pdo = pdo();
    $exists = $pdo->prepare('SELECT id FROM crm_leads WHERE id = ?');
    $exists->execute([$id]);
    if (!$exists->fetchColumn()) json_out(['ok' => false, 'error' => 'Лид не найден'], 404);

    $st = $pdo->prepare(
        'INSERT INTO crm_notes (lead_id, user_id, text, created_at) VALUES (?,?,?,NOW())'
    );
    $st->execute([$id, $uid ?: null, $text]);
    $noteId = (int)$pdo->lastInsertId();

    audit($id, $uid, 'note_added', ['note_id' => $noteId]);

    $stN = $pdo->prepare(
        "SELECT n.*, u.name AS user_name
         FROM crm_notes n LEFT JOIN crm_users u ON u.id = n.user_id
         WHERE n.id = ?"
    );
    $stN->execute([$noteId]);
    $note = $stN->fetch();

    json_out(['ok' => true, 'note' => $note]);
}

/** POST ?action=email */
function lead_email(int $uid): never {
    $id      = (int)($_POST['id'] ?? 0);
    $subject = trim((string)($_POST['subject'] ?? ''));
    $body    = trim((string)($_POST['body'] ?? ''));
    if ($id < 1)         json_out(['ok' => false, 'error' => 'Не указан id'], 400);
    if ($subject === '') json_out(['ok' => false, 'error' => 'Не указана тема'], 400);
    if ($body === '')    json_out(['ok' => false, 'error' => 'Пустое письмо'], 400);

    $pdo = pdo();
    $st = $pdo->prepare('SELECT id, email, name FROM crm_leads WHERE id = ?');
    $st->execute([$id]);
    $lead = $st->fetch();
    if (!$lead) json_out(['ok' => false, 'error' => 'Лид не найден'], 404);

    $to = trim((string)$lead['email']);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        json_out(['ok' => false, 'error' => 'У лида нет корректного email'], 400);
    }

    // Идемпотентность (как в msg_send): не более одной отправки по лиду за 5 секунд.
    if (!rate_limit('leademail:' . $id, 1, 5)) {
        json_out(['ok' => false, 'error' => 'Письмо уже отправляется, подождите'], 429);
    }

    $mailCfg = cfg()['mail'] ?? [];
    $from    = $mailCfg['from'] ?? 'no-reply@zavod-red.ru';
    $replyTo = $mailCfg['to'] ?? $from;
    $headers = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/plain; charset=utf-8';
    $headers[] = 'From: ' . $from;
    $headers[] = 'Reply-To: ' . $replyTo;

    $encSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $sent = @mail($to, $encSubject, $body, implode("\r\n", $headers));

    if (!$sent) {
        json_out(['ok' => false, 'error' => 'Не удалось отправить письмо'], 502);
    }

    audit($id, $uid, 'email_sent', ['to' => $to, 'subject' => $subject]);

    // Исходящее в «Переписку» (crm_messages) — той же структурой, что msg_send.
    require_once __DIR__ . '/inbox.php';
    try {
        msg_insert($id, 'email', 'out', $body, ['contact' => $to, 'subject' => $subject]);
    } catch (Throwable $e) { /* переписка не должна ломать отправку */ }

    // Лог письма как заметка
    $noteText = "Отправлено письмо: «{$subject}»\n\n{$body}";
    $pdo->prepare('INSERT INTO crm_notes (lead_id, user_id, text, created_at) VALUES (?,?,?,NOW())')
        ->execute([$id, $uid ?: null, $noteText]);

    json_out(['ok' => true]);
}

/** POST ?action=tags — id, tags (строка через запятую) → UPDATE crm_leads.tags. */
function lead_tags(int $uid): never {
    $id = (int)($_POST['id'] ?? 0);
    if ($id < 1) json_out(['ok' => false, 'error' => 'Не указан id'], 400);

    // Нормализация: trim, дедуп, без пустых, в нижний регистр.
    $raw = (string)($_POST['tags'] ?? '');
    $parts = array_filter(array_map(
        static fn($t) => mb_strtolower(trim($t)),
        explode(',', $raw)
    ), static fn($t) => $t !== '');
    $parts = array_values(array_unique($parts));
    $tags = mb_substr(implode(',', $parts), 0, 255);

    $pdo = pdo();
    $ex = $pdo->prepare('SELECT id FROM crm_leads WHERE id = ?');
    $ex->execute([$id]);
    if (!$ex->fetchColumn()) json_out(['ok' => false, 'error' => 'Лид не найден'], 404);

    $pdo->prepare('UPDATE crm_leads SET tags = ?, updated_at = NOW() WHERE id = ?')
        ->execute([$tags, $id]); // колонка NOT NULL DEFAULT '': null ронял запрос в строгом режиме

    audit($id, $uid, 'tags_updated', ['tags' => $tags]);

    json_out(['ok' => true, 'tags' => $tags]);
}

/** GET ?action=dups — группы дублей по нормализованному phone или email (>1). */
function lead_dups(): never {
    $pdo = pdo();
    $st = $pdo->query(
        "SELECT id, name, phone, email, status, created_at FROM crm_leads ORDER BY created_at ASC, id ASC"
    );
    $rows = $st->fetchAll();

    $byPhone = [];
    $byEmail = [];
    foreach ($rows as $r) {
        $ph = normalize_phone((string)($r['phone'] ?? ''));
        if ($ph !== '') $byPhone[$ph][] = $r;
        $em = mb_strtolower(trim((string)($r['email'] ?? '')));
        if ($em !== '') $byEmail[$em][] = $r;
    }

    $groups = [];
    foreach ($byPhone as $key => $list) {
        if (count($list) > 1) $groups[] = ['by' => 'phone', 'value' => $key, 'leads' => $list];
    }
    foreach ($byEmail as $key => $list) {
        if (count($list) > 1) $groups[] = ['by' => 'email', 'value' => $key, 'leads' => $list];
    }

    json_out(['ok' => true, 'groups' => $groups, 'count' => count($groups)]);
}

/**
 * POST ?action=merge — from_id, to_id.
 * Переносит связанные записи (messages/notes/events/ai/tasks) с from на to,
 * копирует в to пустые поля из from, удаляет from-лид. Транзакция + audit.
 */
function lead_merge(int $uid): never {
    $fromId = (int)($_POST['from_id'] ?? 0);
    $toId   = (int)($_POST['to_id'] ?? 0);
    if ($fromId < 1 || $toId < 1) json_out(['ok' => false, 'error' => 'Не указаны id'], 400);
    if ($fromId === $toId)        json_out(['ok' => false, 'error' => 'Нельзя слить лид сам с собой'], 400);

    $pdo = pdo();

    $st = $pdo->prepare('SELECT * FROM crm_leads WHERE id = ?');
    $st->execute([$fromId]);
    $from = $st->fetch();
    if (!$from) json_out(['ok' => false, 'error' => 'Лид-источник не найден'], 404);

    $st->execute([$toId]);
    $to = $st->fetch();
    if (!$to) json_out(['ok' => false, 'error' => 'Лид-приёмник не найден'], 404);

    // Колонки, которые НЕ переносим/не трогаем при копировании.
    $skipCols = ['id', 'created_at', 'updated_at'];

    $pdo->beginTransaction();
    try {
        // Перенос связанных таблиц (каждая — опционально, может отсутствовать).
        foreach (['crm_messages', 'crm_notes', 'crm_events', 'crm_ai', 'crm_tasks', 'crm_kp'] as $tbl) {
            try {
                $pdo->prepare("UPDATE {$tbl} SET lead_id = ? WHERE lead_id = ?")
                    ->execute([$toId, $fromId]);
            } catch (Throwable $e) { /* таблицы может не быть — пропускаем */ }
        }

        // Копируем в to пустые поля из from.
        $set = [];
        $params = [];
        foreach ($from as $col => $val) {
            if (in_array($col, $skipCols, true)) continue;
            if ($val === null || $val === '') continue;          // в from нечего брать
            $cur = $to[$col] ?? null;
            if ($cur !== null && $cur !== '') continue;          // в to уже есть значение
            $set[] = "`{$col}` = ?";
            $params[] = $val;
        }
        if ($set) {
            $params[] = $toId;
            $pdo->prepare('UPDATE crm_leads SET ' . implode(', ', $set) . ', updated_at = NOW() WHERE id = ?')
                ->execute($params);
        }

        // Удаляем лид-источник.
        $pdo->prepare('DELETE FROM crm_leads WHERE id = ?')->execute([$fromId]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_out(['ok' => false, 'error' => 'Не удалось слить лиды'], 500);
    }

    audit($toId, $uid, 'lead_merged', ['from_id' => $fromId, 'to_id' => $toId]);

    json_out(['ok' => true, 'to_id' => $toId]);
}

/**
 * POST ?action=delete — удаление заявок (одной или пачкой).
 *
 * Раньше удаления в CRM не было вовсе: заявку можно было только переводить по статусам,
 * а тестовые и спамные карточки оставались в списке навсегда (запрос оператора 06.08.2026).
 *
 * Поведение:
 *   • принимает ids (csv) или один id;
 *   • перед удалением пишет в журнал ПОЛНЫЙ снимок заявки (crm_events, тип lead_deleted),
 *     чтобы удаление было отслеживаемым и данные не исчезали бесследно;
 *   • подчищает связанные записи в тех же таблицах, что и слияние, — иначе остаются
 *     осиротевшие сообщения и заметки, которые всплывают в поиске;
 *   • всё в одной транзакции: либо удаляется целиком, либо ничего.
 */
function lead_delete(int $uid): never {
    $idsRaw = (string)($_POST['ids'] ?? ($_POST['id'] ?? ''));
    $ids = array_values(array_filter(array_map('intval', explode(',', $idsRaw)), fn($v) => $v > 0));
    $ids = array_slice(array_unique($ids), 0, 200);
    if (!$ids) json_out(['ok' => false, 'error' => 'Не выбраны заявки'], 400);

    $pdo = pdo();
    $place = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT * FROM crm_leads WHERE id IN ($place)");
    $st->execute($ids);
    $rows = $st->fetchAll();
    if (!$rows) json_out(['ok' => false, 'error' => 'Заявки не найдены'], 404);

    // Снимок в журнал ДО удаления: после DELETE взять его будет неоткуда.
    foreach ($rows as $r) {
        audit((int)$r['id'], $uid, 'lead_deleted', [
            'name'      => $r['name']       ?? '',
            'phone'     => $r['phone']      ?? '',
            'email'     => $r['email']      ?? '',
            'status'    => $r['status']     ?? '',
            'page_url'  => $r['page_url']   ?? '',
            'created_at'=> $r['created_at'] ?? '',
        ]);
    }

    $deleted = 0;
    $pdo->beginTransaction();
    try {
        foreach (['crm_messages', 'crm_notes', 'crm_ai', 'crm_tasks', 'crm_kp'] as $tbl) {
            try {
                $pdo->prepare("DELETE FROM {$tbl} WHERE lead_id IN ($place)")->execute($ids);
            } catch (Throwable $e) { /* таблицы может не быть — пропускаем */ }
        }
        // crm_events НЕ чистим: там лежит журнал, в том числе только что записанный снимок.
        $q = $pdo->prepare("DELETE FROM crm_leads WHERE id IN ($place)");
        $q->execute($ids);
        $deleted = $q->rowCount();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_out(['ok' => false, 'error' => 'Не удалось удалить заявки'], 500);
    }

    json_out(['ok' => true, 'deleted' => $deleted, 'selected' => count($ids)]);
}
