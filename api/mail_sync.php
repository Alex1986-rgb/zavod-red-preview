<?php
declare(strict_types=1);

/**
 * /api/mail_sync.php — вся почта под контролем. Замена прежнего mail_poll().
 *
 * CLI (крон каждые 5 минут):  php api/mail_sync.php
 *   --no-files   не разбирать очередь вложений в этом прогоне
 *   --dry        только показать, что пришло, ничего не записывать
 *
 * Что изменилось против mail_poll():
 *   1. Письма берутся по UID-отметке, а не «непрочитанные»: письмо, которое кто-то
 *      открыл в Яндекс.Почте раньше CRM, больше не теряется. Флаг «прочитано» CRM не
 *      ставит (ящик открыт только на чтение).
 *   2. Читается и папка «Отправленные»: ответы менеджеров из Яндекс.Почты попадают в
 *      переписку заявки (автоответчик видит, что человек уже в диалоге) и в обучение.
 *   3. Вложения сохраняются и разбираются ИИ (api/mail_files.php): шильдики, чертежи,
 *      счета, чеки, КП, реквизиты.
 *   4. Письмо сначала классифицируется. Заявка заводится только на обращение клиента
 *      или продолжение переписки; рассылки, спам, поставщики и уведомления заявками
 *      больше не становятся — но лежат в почте CRM с меткой типа.
 *   5. Не нужно PHP-расширение imap (api/imap_lite.php + api/mime.php).
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/inbox.php';
require_once __DIR__ . '/llm.php';
require_once __DIR__ . '/mime.php';
require_once __DIR__ . '/imap_lite.php';
require_once __DIR__ . '/mail_files.php';
require_once __DIR__ . '/learn.php';
require_once __DIR__ . '/autoreply.php';

/** Колонки почтового клиента (как mb_ensure в mailbox.php) + тип письма и дата отправки. */
function ms_ensure(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    foreach ([
        "ALTER TABLE crm_messages ADD COLUMN folder VARCHAR(16) NOT NULL DEFAULT 'inbox'",
        "ALTER TABLE crm_messages ADD COLUMN is_read TINYINT NOT NULL DEFAULT 0",
        "ALTER TABLE crm_messages ADD COLUMN is_starred TINYINT NOT NULL DEFAULT 0",
        "ALTER TABLE crm_messages ADD COLUMN has_attach TINYINT NOT NULL DEFAULT 0",
        "ALTER TABLE crm_messages ADD COLUMN mail_kind VARCHAR(16) NOT NULL DEFAULT ''",
        "ALTER TABLE crm_messages ADD COLUMN in_reply_to VARCHAR(190) NOT NULL DEFAULT ''",
    ] as $sql) {
        try { pdo()->exec($sql); } catch (Throwable $e) { /* колонка уже есть */ }
    }
    mf_ensure();
    learn_ensure();
}

/** Учётка IMAP из настроек (Интеграции → Почта) или config.php. */
function ms_creds(): array {
    $mail = cfg()['mail'] ?? [];
    $hostRaw = (string)(secret('mail_imap_host', (string)($mail['imap_host'] ?? '')) ?: 'imap.yandex.ru');
    // Старый формат для ext-imap: «{imap.yandex.ru:993/imap/ssl}INBOX».
    $host = 'imap.yandex.ru'; $port = 993;
    if (preg_match('/^\{?([a-z0-9.\-]+)(?::(\d+))?/i', $hostRaw, $m)) { $host = $m[1]; if (!empty($m[2])) $port = (int)$m[2]; }
    return [
        'host' => $host, 'port' => $port,
        'user' => secret('mail_imap_user', (string)($mail['imap_user'] ?? '')),
        'pass' => secret('mail_imap_pass', (string)($mail['imap_pass'] ?? '')),
    ];
}

function ms_state(): array {
    $s = json_decode((string)setting('mail_sync_state', '{}'), true);
    return is_array($s) ? $s : [];
}

function ms_state_save(array $s): void {
    setting_set('mail_sync_state', json_encode($s, JSON_UNESCAPED_UNICODE));
}

/* ------------------------------------------------------------------ классификация */

/** Подписи типов писем для интерфейса. */
function ms_kind_labels(): array {
    return ['request' => 'Обращение', 'client' => 'Клиент', 'supplier' => 'Поставщик', 'invoice' => 'Счёт нам',
            'newsletter' => 'Рассылка', 'spam' => 'Спам', 'service' => 'Уведомление', 'other' => 'Разобрать',
            'manager' => 'Ответ менеджера'];
}

/** Домены завода: письма с них — внутренние или уведомления CRM. */
function ms_own_domains(): array {
    $d = ['zavod-red.ru', 'infozr-crm.ru', 'zr-zavod-red.ru'];
    foreach (ar_self_addresses() as $a) { $x = strtolower((string)substr(strrchr($a, '@') ?: '', 1)); if ($x !== '') $d[] = $x; }
    return array_values(array_unique($d));
}

/** Слова обращения клиента — запасной разбор, когда ИИ недоступен. */
function ms_request_words(): string {
    return '/редуктор|мотор-?редуктор|электродвигател|шильдик|аналог|подбор|подобрать|передаточн|типоразмер|'
         . 'коммерческ\w* предложени|\bкп\b|стоимост|цен[аыу]\b|сколько стоит|нужен|нужна|требуется|запрос|заявк|'
         . 'sew|nord|bonfiglioli|motovario|siti|varvel|rossi|lenze|flender|\bzr\b|червячн|цилиндрическ|планетарн/iu';
}

/**
 * Тип входящего письма: request | client | supplier | invoice | newsletter | spam | service | other.
 * Порядок: заголовки роботов → известный клиент → ИИ → слова.
 */
function ms_classify(array $p, ?int $knownLead): array {
    $from = (string)$p['from_email'];
    if (preg_match('/mailer-daemon|postmaster|bounce/i', $from)) return ['kind' => 'service', 'reason' => 'отчёт о доставке', 'confidence' => 95];
    if (!empty($p['auto']['list_id']) || !empty($p['auto']['precedence_bulk'])) return ['kind' => 'newsletter', 'reason' => 'заголовок рассылки', 'confidence' => 90];
    if (!empty($p['auto']['auto_submitted']) || ar_is_service_sender($from)) return ['kind' => 'service', 'reason' => 'автоматическое письмо', 'confidence' => 85];
    // Наши домены: коллеги и уведомления самой CRM («Новая заявка #…» шли с lead@infozr-crm.ru) — не заявки.
    $dom = strtolower((string)substr(strrchr($from, '@') ?: '', 1));
    if (in_array($dom, ms_own_domains(), true)) return ['kind' => 'service', 'reason' => 'наш домен / уведомление CRM', 'confidence' => 95];
    if ($knownLead) return ['kind' => 'client', 'reason' => 'отправитель уже есть в CRM', 'confidence' => 90];

    $text = mb_substr(mime_strip_quote((string)$p['text']), 0, 1800, 'UTF-8');
    $files = implode(', ', array_column($p['attachments'], 'filename'));

    if (llm_ready()) {
        $system = "Ты сортируешь входящую почту завода-производителя редукторов «Завод Редукторов» (бренд ZR).\n"
            . "Типы:\n"
            . "request — клиент спрашивает про редукторы/мотор-редукторы/двигатели/запчасти: подбор, аналог, цена, КП, "
            . "шильдик, чертёж, наличие, сроки; в том числе тендерные запросы и запросы снабженцев;\n"
            . "client — продолжение сделки: оплата, отгрузка, доставка, документы, претензия по поставке;\n"
            . "supplier — нам пишут поставщики, перевозчики, подрядчики по НАШИМ закупкам;\n"
            . "invoice — нам выставили счёт/акт/УПД на оплату;\n"
            . "newsletter — рассылка, новости, дайджест;\n"
            . "spam — реклама услуг нам (SEO, сайты, базы, кредиты), фишинг, нерелевантное;\n"
            . "service — уведомления сервисов (банк, маркетплейс, госуслуги, хостинг);\n"
            . "other — резюме, личное, непонятное.\n"
            . 'Верни СТРОГО JSON: {"kind":"…","reason":"до 8 слов","confidence":0}';
        $u = "От: {$p['from_name']} <{$from}>\nТема: {$p['subject']}\n"
           . ($files !== '' ? "Вложения: {$files}\n" : '') . "Текст:\n{$text}";
        try {
            $raw = llm_call($system, $u, 300);
            $d = ar_json_extract($raw);
            $k = (string)($d['kind'] ?? '');
            if (isset(ms_kind_labels()[$k]) && $k !== 'manager') {
                return ['kind' => $k, 'reason' => mb_substr((string)($d['reason'] ?? ''), 0, 80, 'UTF-8'),
                        'confidence' => max(0, min(100, (int)($d['confidence'] ?? 60)))];
            }
        } catch (Throwable $e) { error_log('ms_classify: ' . $e->getMessage()); }
    }

    // Без ИИ: лучше лишняя заявка, чем потерянный клиент.
    if (preg_match(ms_request_words(), $p['subject'] . "\n" . $text . "\n" . $files)) {
        return ['kind' => 'request', 'reason' => 'слова обращения (ИИ недоступен)', 'confidence' => 55];
    }
    if (preg_match('/сч[её]т\s*(на оплату|№)|акт\s*(№|сверки)|упд/iu', $p['subject'] . ' ' . $files)) {
        return ['kind' => 'invoice', 'reason' => 'счёт/акт в теме (ИИ недоступен)', 'confidence' => 50];
    }
    return ['kind' => 'other', 'reason' => 'не распознано (ИИ недоступен)', 'confidence' => 30];
}

/* ---------------------------------------------------------------------- приём */

/**
 * Сдвиг часов базы относительно PHP в секундах. created_at остальных записей ставит
 * MySQL (NOW()), а дату письма считает PHP — если пояса разные (на Маке разница была
 * 10 ч), письмо «переезжало» во времени и ломало расчёт «сколько ждёт ответа».
 */
function ms_db_offset(): int {
    static $off = null;
    if ($off === null) {
        try { $off = (int)strtotime((string)pdo()->query('SELECT NOW()')->fetchColumn()) - time(); }
        catch (Throwable $e) { $off = 0; }
        $off = (int)(round($off / 900) * 900); // до четверти часа: убрать секунды запроса
    }
    return $off;
}

/** Дата письма в часах базы, если она правдоподобна; иначе «сейчас». */
function ms_msg_date(array $p): string {
    $d = (string)($p['date'] ?? '');
    $t = $d !== '' ? strtotime($d) : false;
    if (!$t || $t > time() + 3600 || $t < time() - 400 * 86400) $t = time();
    return date('Y-m-d H:i:s', $t + ms_db_offset());
}

function ms_msg_exists(string $extId): ?int {
    if ($extId === '') return null;
    $st = pdo()->prepare("SELECT id FROM crm_messages WHERE channel='email' AND ext_id=? LIMIT 1");
    $st->execute([mb_substr($extId, 0, 190, 'UTF-8')]);
    $v = $st->fetchColumn();
    return $v ? (int)$v : null;
}

/** Дописать служебные поля письма (колонки из ms_ensure). */
function ms_mark(int $mid, array $fields): void {
    if (!$mid || !$fields) return;
    $set = []; $vals = [];
    foreach ($fields as $k => $v) { $set[] = "`$k`=?"; $vals[] = $v; }
    $vals[] = $mid;
    try { pdo()->prepare('UPDATE crm_messages SET ' . implode(',', $set) . ' WHERE id=?')->execute($vals); }
    catch (Throwable $e) { error_log('ms_mark: ' . $e->getMessage()); }
}

/**
 * Входящее письмо. $opts: live (bool — можно ли автоответ: не для первого/архивного прогона).
 * Возвращает ['action'=>…, 'kind'=>…, 'lead_id'=>…, 'files'=>n].
 */
function ms_inbound(array $p, string $extId, array $opts = []): array {
    if (ms_msg_exists($extId)) return ['action' => 'dup'];
    $from = (string)$p['from_email'];
    if ($from === '' || in_array($from, ar_self_addresses(), true)) return ['action' => 'self'];

    $known = lead_find_existing($from, '');
    $cls = ms_classify($p, $known);
    $kind = $cls['kind'];
    $isClient = in_array($kind, ['request', 'client'], true);

    $leadId = $known;
    $created = false;
    if (!$leadId && $isClient) {
        // Заявка из письма — без уведомления «Новая заявка»: само письмо клиента уже лежит во входящих
        // (решение Александра 28.09.2026: «не нужно рассылать»).
        $leadId = lead_find_or_create(['channel' => 'email', 'email' => $from, 'silent' => true,
                                       'name' => $p['from_name'] !== '' ? $p['from_name'] : $from], $created);
    }

    $body = (string)$p['text'];
    if ($body === '' && $p['attachments']) $body = '(письмо без текста, только вложения)';
    $mid = msg_insert($leadId, 'email', 'in', $body, [
        'contact' => $from, 'subject' => (string)$p['subject'], 'ext_id' => $extId,
        'has_attach' => $p['attachments'] ? 1 : 0,
    ]);
    ms_mark($mid, ['mail_kind' => $kind, 'in_reply_to' => mb_substr((string)$p['in_reply_to'], 0, 190, 'UTF-8'),
                   'folder' => $kind === 'spam' ? 'spam' : 'inbox', 'created_at' => ms_msg_date($p)]);

    // Вложения: у спама и рассылок не храним (место и мусор), у остальных — все.
    $files = [];
    if (!in_array($kind, ['spam', 'newsletter', 'service'], true)) {
        $files = mf_save($mid, $leadId, 'in', $p['attachments']);
    }

    if ($leadId && $files) {
        // Новая заявка без файла → первый снимок/PDF становится файлом заявки: его подхватит
        // автопилот ai_auto.php (распознавание шильдика → подбор ZR → черновик письма).
        $pic = null;
        foreach ($files as $f) {
            if (preg_match('/\.(jpe?g|png|webp|gif|pdf)$/i', $f['stored'])) { $pic = $f['stored']; break; }
        }
        if ($pic) {
            try { pdo()->prepare("UPDATE crm_leads SET file_path=? WHERE id=? AND (file_path IS NULL OR file_path='')")->execute([$pic, $leadId]); } catch (Throwable $e) {}
        }
        // Для ответа клиенту разберём вложения сразу (не больше трёх) — чтобы робот не
        // просил «пришлите фото шильдика», когда фото уже пришло.
        if ($isClient && !empty($opts['live'])) {
            foreach (array_slice($files, 0, 3) as $f) {
                $st = pdo()->prepare('SELECT * FROM crm_mail_files WHERE id=?');
                $st->execute([$f['id']]);
                if ($row = $st->fetch(PDO::FETCH_ASSOC)) mf_analyze($row);
            }
        }
    }

    if ($leadId) audit($leadId, null, 'mail_in', ['kind' => $kind, 'reason' => $cls['reason'], 'files' => count($files)]);

    if ($leadId && $isClient && !empty($opts['live'])) {
        $incoming = trim($body . "\n\n" . mf_context_for_message($mid));
        autoreply_handle($leadId, 'email', [
            'body' => $incoming, 'contact' => $from, 'subject' => (string)$p['subject'], 'flags' => $p['auto'],
        ]);
    }
    return ['action' => 'saved', 'kind' => $kind, 'lead_id' => $leadId, 'created' => $created, 'files' => count($files), 'id' => $mid];
}

/**
 * Письмо из «Отправленных». Письма, которые ушли из самой CRM, уже есть в переписке —
 * их узнаём по получателю и тексту и только проставляем Message-ID.
 */
function ms_sent(array $p, string $extId, array $opts = []): array {
    if (ms_msg_exists($extId)) return ['action' => 'dup'];
    $self = ar_self_addresses();
    $rcpt = array_values(array_filter(array_merge($p['to'], $p['cc']), static fn($a) => !in_array($a, $self, true)));
    if (!$rcpt) return ['action' => 'self'];
    $to = $rcpt[0];
    $body = (string)$p['text'];
    $date = ms_msg_date($p);

    // Копия письма, отправленного из CRM: то же направление и адрес в пределах 20 минут.
    try {
        $st = pdo()->prepare("SELECT id, body FROM crm_messages WHERE channel='email' AND direction='out' AND contact=?
                              AND (ext_id='' OR ext_id IS NULL) AND created_at BETWEEN (? - INTERVAL 20 MINUTE) AND (? + INTERVAL 20 MINUTE)");
        $st->execute([$to, $date, $date]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (learn_similarity(mime_strip_quote((string)$r['body']), mime_strip_quote($body)) >= 80) {
                ms_mark((int)$r['id'], ['ext_id' => mb_substr($extId, 0, 190, 'UTF-8')]);
                return ['action' => 'crm_copy'];
            }
        }
    } catch (Throwable $e) {}

    $leadId = null;
    foreach ($rcpt as $a) { if ($leadId = lead_find_existing($a, '')) { $to = $a; break; } }

    $mid = msg_insert($leadId, 'email', 'out', $body, ['contact' => $to, 'subject' => (string)$p['subject'],
                                                       'ext_id' => $extId, 'has_attach' => $p['attachments'] ? 1 : 0]);
    ms_mark($mid, ['mail_kind' => 'manager', 'in_reply_to' => mb_substr((string)$p['in_reply_to'], 0, 190, 'UTF-8'),
                   'folder' => !empty($opts['backfill']) ? 'archive' : 'inbox', 'is_read' => 1, 'created_at' => $date]);
    // Ответ менеджера за последние сутки — «человек в диалоге», автоответчик молчит.
    if ($leadId && strtotime($date) - ms_db_offset() > time() - 86400) {
        audit($leadId, 0, 'msg_sent', ['channel' => 'email', 'via' => 'yandex_mail']);
    }
    // Архив (backfill) — только текст для обучения: гигабайты старых вложений хостингу ни к чему.
    $files = !empty($opts['backfill']) ? [] : mf_save($mid, $leadId, 'out', $p['attachments']);
    $ex = learn_from_reply($leadId, $to, $body, $mid, !empty($opts['backfill']) ? 'backfill' : 'sent', (string)$p['in_reply_to']);
    if (empty($opts['backfill'])) learn_shadow_eval($leadId, $body, $mid ?: null); // оценка тихого ответа робота
    return ['action' => 'saved', 'lead_id' => $leadId, 'files' => count($files), 'example' => $ex, 'id' => $mid];
}

/* ------------------------------------------------------------------------ прогон */

/**
 * Один прогон синхронизации.
 * $o: max (писем на папку, 40), first_days (глубина первого запуска, 3), dry (bool),
 *     folders (['inbox','sent']), backfill_days (архивный режим: без заявок/автоответов,
 *     только примеры для обучения из «Отправленных»).
 */
function ms_run(array $o = []): array {
    cron_heartbeat('mail');
    $max = max(1, (int)($o['max'] ?? 40));
    $dry = !empty($o['dry']);
    $backfill = (int)($o['backfill_days'] ?? 0);
    $cr = ms_creds();
    if ($cr['user'] === '' || $cr['pass'] === '') {
        return ['ok' => false, 'error' => 'Не заданы логин и пароль почты (Настройки → Интеграции → Почта)'];
    }
    if (!$dry) ms_ensure();

    // Один прогон за раз: крон каждые 5 минут, а разбор с ИИ и большими вложениями бывает дольше.
    $lockPath = __DIR__ . '/../crm-data/logs/mail_sync.lock';
    @mkdir(dirname($lockPath), 0750, true);
    $lock = @fopen($lockPath, 'c');
    if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) return ['ok' => true, 'skipped' => 'предыдущий прогон ещё идёт'];
    $deadline = time() + max(30, (int)($o['budget_sec'] ?? 200)); // успеть до следующего запуска крона

    $report = ['ok' => true, 'inbox' => [], 'sent' => [], 'kinds' => []];
    try {
        $c = imapl_open($cr['host'], $cr['port'], $cr['user'], $cr['pass']);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
    $state = ms_state();
    try {
        $folders = ['inbox' => 'INBOX'];
        if (in_array('sent', (array)($o['folders'] ?? ['inbox', 'sent']), true)) {
            $sent = imapl_sent_folder($c);
            if ($sent !== '') $folders['sent'] = $sent;
            else $report['sent_note'] = 'папка «Отправленные» не найдена';
        }
        if (!in_array('inbox', (array)($o['folders'] ?? ['inbox', 'sent']), true)) unset($folders['inbox']);

        foreach ($folders as $key => $name) {
            $box = imapl_examine($c, $name);
            $st = $state[$key] ?? [];
            if ($backfill > 0) {
                // Архив не трогает свежие письма: всё новее отметки обычного прогона (или последних
                // first_days, если прогона ещё не было) обработает обычный прогон — с заявками и ответом.
                $liveFrom = (($st['uidvalidity'] ?? 0) === $box['uidvalidity'] && !empty($st['last_uid']))
                    ? (int)$st['last_uid'] : PHP_INT_MAX;
                $recent = array_flip(imapl_uids_since($c, time() - max(1, (int)($o['first_days'] ?? 3)) * 86400));
                // Сначала только заголовки: уже сохранённые письма повторно не скачиваем — архив идёт заходами.
                $map = imapl_msgid_map($c, time() - $backfill * 86400);
                $known = [];
                foreach (array_chunk(array_keys($map), 500) as $ids) {
                    $q = pdo()->prepare("SELECT ext_id FROM crm_messages WHERE channel='email' AND ext_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')');
                    $q->execute(array_map(static fn($x) => mb_substr((string)$x, 0, 190, 'UTF-8'), $ids));
                    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $e) $known[$e] = true;
                }
                $uids = [];
                foreach ($map as $mid => $u) {
                    if (isset($known[mb_substr((string)$mid, 0, 190, 'UTF-8')]) || $u > $liveFrom || ($liveFrom === PHP_INT_MAX && isset($recent[$u]))) continue;
                    $uids[] = $u;
                }
                sort($uids);
            } elseif (($st['uidvalidity'] ?? 0) === $box['uidvalidity'] && !empty($st['last_uid'])) {
                $uids = imapl_uids_from($c, (int)$st['last_uid'] + 1);
            } else {
                // Первый запуск (или ящик пересоздан): не тащим весь архив в заявки.
                $uids = imapl_uids_since($c, time() - max(1, (int)($o['first_days'] ?? 3)) * 86400);
                $first = true;
            }
            $total = count($uids);
            $uids = array_slice($uids, 0, $backfill > 0 ? max($max, 5000) : $max);
            $done = ['found' => $total, 'processed' => 0, 'saved' => 0, 'dup' => 0, 'leads_new' => 0, 'files' => 0, 'errors' => 0];
            foreach ($uids as $uid) {
                if (time() > $deadline) { $done['stopped'] = 'лимит времени — остальное в следующем прогоне'; break; }
                $raw = imapl_fetch_raw($c, $uid);
                if ($raw === null) { $done['errors']++; continue; }
                $p = mime_parse($raw, $backfill > 0); // архив — только текст: вложения старых писем не нужны и съедают память
                $extId = $p['message_id'] !== '' ? $p['message_id'] : ('<uid-' . $box['uidvalidity'] . '-' . $uid . '@' . $key . '.local>');
                $done['processed']++;
                if ($dry) {
                    $report[$key . '_preview'][] = ['uid' => $uid, 'from' => $p['from_email'], 'to' => $p['to'],
                        'subject' => $p['subject'], 'files' => array_column($p['attachments'], 'filename')];
                    continue;
                }
                try {
                    if ($key === 'inbox') {
                        if ($backfill > 0) {
                            // Архив: только сохранить входящее (без заявок и автоответа) — пара для обучения.
                            if (!ms_msg_exists($extId)) {
                                $lid = lead_find_existing($p['from_email'], '');
                                $mid = msg_insert($lid, 'email', 'in', (string)$p['text'], ['contact' => $p['from_email'],
                                    'subject' => $p['subject'], 'ext_id' => $extId, 'has_attach' => $p['attachments'] ? 1 : 0]);
                                ms_mark($mid, ['mail_kind' => 'archive', 'folder' => 'archive', 'is_read' => 1, 'created_at' => ms_msg_date($p)]);
                                $done['saved']++;
                            } else $done['dup']++;
                        } else {
                            $r = ms_inbound($p, $extId, ['live' => empty($first)]);
                            if (($r['action'] ?? '') === 'saved') {
                                $done['saved']++;
                                $done['files'] += (int)$r['files'];
                                if (!empty($r['created'])) $done['leads_new']++;
                                $report['kinds'][$r['kind']] = ($report['kinds'][$r['kind']] ?? 0) + 1;
                            } elseif (($r['action'] ?? '') === 'dup') $done['dup']++;
                        }
                    } else {
                        $r = ms_sent($p, $extId, ['backfill' => $backfill > 0]);
                        if (($r['action'] ?? '') === 'saved') { $done['saved']++; $done['files'] += (int)$r['files']; }
                        elseif (in_array($r['action'] ?? '', ['dup', 'crm_copy'], true)) $done['dup']++;
                    }
                } catch (Throwable $e) {
                    $done['errors']++;
                    error_log("mail_sync {$key} uid {$uid}: " . $e->getMessage());
                }
                if ($backfill === 0) {
                    $state[$key] = ['uidvalidity' => $box['uidvalidity'], 'last_uid' => $uid, 'at' => date('Y-m-d H:i:s')];
                    ms_state_save($state);
                }
            }
            // Пустой первый прогон всё равно фиксирует отметку — иначе каждый раз «первый запуск».
            if (!$dry && $backfill === 0 && !$uids) {
                $last = max(0, $box['uidnext'] - 1);
                if (empty($state[$key]['last_uid']) || ($state[$key]['uidvalidity'] ?? 0) !== $box['uidvalidity']) {
                    $state[$key] = ['uidvalidity' => $box['uidvalidity'], 'last_uid' => $last, 'at' => date('Y-m-d H:i:s')];
                    ms_state_save($state);
                }
            }
            $report[$key] = $done;
        }
    } catch (Throwable $e) {
        $report['ok'] = false;
        $report['error'] = $e->getMessage();
    }
    imapl_close($c);
    if (!$dry) setting_set('mail_sync_last_report', json_encode($report + ['at' => date('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE));
    return $report;
}

/**
 * Повторный забор вложений у уже сохранённых писем (по Message-ID), у которых файлов в CRM нет.
 * Нужен был 28.09.2026: на MySQL 8 таблица вложений не создалась (столбец «stored» —
 * зарезервированное слово), письма сохранились, а вложения — нет.
 */
function ms_refetch_files(int $days = 7, int $max = 200): array {
    ms_ensure();
    $cr = ms_creds();
    $c = imapl_open($cr['host'], $cr['port'], $cr['user'], $cr['pass']);
    $out = ['checked' => 0, 'found' => 0, 'files' => 0, 'missing' => 0];
    $d = max(1, $days);
    $folders = ['in' => 'INBOX', 'out' => imapl_sent_folder($c)];
    foreach ($folders as $dir => $folder) {
        if ($folder === '') continue;
        imapl_examine($c, $folder);
        $map = imapl_msgid_map($c, time() - ($d + 1) * 86400);
        $st = pdo()->prepare("SELECT m.id, m.lead_id, m.ext_id, m.mail_kind FROM crm_messages m
            WHERE m.channel='email' AND m.direction=? AND m.has_attach=1 AND m.ext_id<>''
              AND m.mail_kind NOT IN ('', 'spam', 'newsletter', 'service')
              AND m.created_at >= NOW() - INTERVAL {$d} DAY
              AND NOT EXISTS (SELECT 1 FROM crm_mail_files f WHERE f.message_id=m.id)
            ORDER BY m.id LIMIT " . max(1, $max));
        $st->execute([$dir]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $out['checked']++;
            $uid = $map[trim((string)$m['ext_id'])] ?? 0;
            $raw = $uid ? imapl_fetch_raw($c, $uid) : null;
            if ($raw === null) { $out['missing']++; continue; }
            $out['found']++;
            $p = mime_parse($raw);
            $lid = $m['lead_id'] ? (int)$m['lead_id'] : null;
            $saved = mf_save((int)$m['id'], $lid, $dir, $p['attachments']);
            $out['files'] += count($saved);
            if ($lid && $dir === 'in') {
                foreach ($saved as $f) {
                    if (preg_match('/\.(jpe?g|png|webp|gif|pdf)$/i', $f['stored'])) {
                        pdo()->prepare("UPDATE crm_leads SET file_path=? WHERE id=? AND (file_path IS NULL OR file_path='')")->execute([$f['stored'], $lid]);
                        break;
                    }
                }
            }
        }
    }
    imapl_close($c);
    return $out;
}

/**
 * Обучение на архиве почты: из «Отправленных» за $days дней берутся только ОТВЕТЫ менеджеров
 * (есть In-Reply-To), к каждому — письмо клиента из входящих; пара сразу идёт в crm_examples.
 * Рассылки (их в «Отправленных» тысячи) не скачиваются, в переписку CRM архив не пишется.
 * С продолжением: позиция в setting mail_learn_backfill, каждый заход — в пределах $budget секунд.
 */
function ms_learn_backfill(int $days = 180, int $budget = 165): array {
    ms_ensure();
    $deadline = time() + max(30, $budget);
    $save = static function (array $st): void { $st['at'] = date('Y-m-d H:i:s'); setting_set('mail_learn_backfill', json_encode($st, JSON_UNESCAPED_UNICODE)); };
    $cr = ms_creds();
    $c = imapl_open($cr['host'], $cr['port'], $cr['user'], $cr['pass']);
    $sent = imapl_sent_folder($c);
    if ($sent === '') { imapl_close($c); return ['ok' => false, 'error' => 'нет папки «Отправленные»']; }
    $st = json_decode((string)setting('mail_learn_backfill', '{}'), true) ?: [];
    $box = imapl_examine($c, $sent);
    if (($st['uidvalidity'] ?? 0) !== $box['uidvalidity'] || ($st['days'] ?? 0) !== $days) {
        $st = ['uidvalidity' => $box['uidvalidity'], 'days' => $days, 'next' => 0, 'replies' => 0, 'pairs' => 0,
               'pending' => [], 'scanned' => false, 'done' => false];
    }
    if (!empty($st['done'])) { imapl_close($c); return ['ok' => true] + $st; }

    // Шаг 1 (один раз, отдельным заходом): карта входящих Message-ID → UID, кэш в crm-data/logs.
    $cacheFile = __DIR__ . '/../crm-data/logs/inbox_msgid_map.json';
    $cache = is_file($cacheFile) ? (json_decode((string)file_get_contents($cacheFile), true) ?: []) : [];
    $ib = imapl_examine($c, 'INBOX');
    if (($cache['uidvalidity'] ?? 0) !== $ib['uidvalidity'] || ($cache['days'] ?? 0) !== $days) {
        $cache = ['uidvalidity' => $ib['uidvalidity'], 'days' => $days, 'map' => [], 'pos' => 0, 'complete' => false];
    }
    if (empty($cache['complete'])) {
        // Яндекс рвёт соединение на долгом сборе — собираем партиями по 200 и сохраняем после каждой.
        $all = imapl_uids_since($c, time() - ($days + 30) * 86400);
        try {
            while ($cache['pos'] < count($all) && time() < $deadline) {
                $part = array_slice($all, $cache['pos'], 200);
                [$ok, $rows] = imapl_cmd($c, 'UID FETCH ' . implode(',', $part) . ' (UID BODY.PEEK[HEADER.FIELDS (MESSAGE-ID)])');
                if ($ok) foreach ($rows as $r) {
                    if (preg_match('/UID (\\d+)/', $r['line'], $m) && $r['literals']
                        && preg_match('/^Message-ID:\\s*(<[^>]+>)/im', preg_replace("/\\r?\\n[ \\t]+/", ' ', $r['literals'][0]), $mm)) $cache['map'][$mm[1]] = (int)$m[1];
                }
                $cache['pos'] += count($part);
                @file_put_contents($cacheFile, json_encode($cache));
            }
        } catch (Throwable $e) { /* обрыв — продолжим со следующего захода */ }
        $cache['complete'] = $cache['pos'] >= count($all);
        @file_put_contents($cacheFile, json_encode($cache));
        try { imapl_close($c); } catch (Throwable $e) {}
        $save($st);
        return ['ok' => true, 'stage' => 'карта входящих: ' . $cache['pos'] . ' из ' . count($all) . ($cache['complete'] ? ' — готово' : ' — продолжу в следующем заходе')] + $st;
    }
    $inMap = $cache['map'];

    try {
    // Шаг 2: просмотр «Отправленных» (новые → старые), ответы менеджеров — в очередь.
    if (empty($st['scanned'])) {
        imapl_examine($c, $sent);
        $uids = array_reverse(imapl_uids_since($c, time() - $days * 86400));
        $st['total'] = count($uids);
        while ($st['next'] < count($uids) && time() < $deadline - 15) {
            $part = array_slice($uids, $st['next'], 150);
            [$ok, $rows] = imapl_cmd($c, 'UID FETCH ' . implode(',', $part) . ' (UID BODY.PEEK[HEADER.FIELDS (IN-REPLY-TO)])');
            $st['next'] += count($part);
            if ($ok) foreach ($rows as $r) {
                if (!preg_match('/UID (\\d+)/', $r['line'], $m) || !$r['literals']) continue;
                $h = preg_replace("/\\r?\\n[ \\t]+/", ' ', $r['literals'][0]);
                if (preg_match('/^In-Reply-To:\\s*(<[^>]+>)/im', $h, $mm) && isset($inMap[$mm[1]])) {
                    $st['pending'][] = [(int)$m[1], $inMap[$mm[1]]];   // [UID ответа, UID письма клиента]
                    $st['replies']++;
                }
            }
            $save($st);
        }
        $st['scanned'] = $st['next'] >= count($uids);
        $save($st);
    }

    // Шаг 3: пары партиями по 20 — письмо клиента + ответ → crm_examples.
    while ($st['pending'] && time() < $deadline) {
        $batch = array_splice($st['pending'], 0, 20);
        imapl_examine($c, 'INBOX');
        $in = [];
        foreach ($batch as [$su, $iu]) {
            $raw = imapl_fetch_raw($c, (int)$iu);
            if (!$raw) continue;
            $p = mime_parse($raw, true); unset($raw);
            $in[$su] = ['text' => $p['text'], 'subject' => $p['subject'], 'from_email' => $p['from_email']]; // только нужное
        }
        imapl_examine($c, $sent);
        foreach ($in as $su => $pin) {
            $raw = imapl_fetch_raw($c, (int)$su);
            if (!$raw) continue;
            $pout = mime_parse($raw, true); unset($raw);
            $lid = lead_find_existing((string)$pin['from_email'], '');
            if (learn_add(['body' => $pin['text'], 'subject' => $pin['subject'], 'lead_id' => $lid], (string)$pout['text'], 'backfill')) $st['pairs']++;
        }
        $save($st);
    }
    } catch (Throwable $e) {
        // Обрыв соединения: всё сделанное уже сохранено ($save после каждой партии), продолжим позже.
        $save($st);
        return ['ok' => true, 'interrupted' => $e->getMessage()] + $st + ['pending_n' => count($st['pending'])];
    }
    $st['done'] = !empty($st['scanned']) && !$st['pending'];
    $save($st);
    try { imapl_close($c); } catch (Throwable $e) {}
    $out = $st; $out['pending'] = count($st['pending']);
    return ['ok' => true] + $out;
}

/**
 * Весь архив ящика в CRM (для базы знаний): «Входящие» и «Отправленные» за $days дней — только текст,
 * без заявок, уведомлений и автоответов (папка archive). Шагами с сохранением позиции (setting
 * mail_archive): сначала пачкой заголовки Message-ID, уже известные письма не скачиваются.
 * Запускается из cron_all.php, пока не дойдёт до конца; дальше свежую почту ведёт обычный ms_run().
 */
function ms_archive_step(int $days = 400, int $budget = 150): array {
    ms_ensure();
    $deadline = time() + max(30, $budget);
    $st = json_decode((string)setting('mail_archive', '{}'), true) ?: [];
    if (!empty($st['done'])) return ['ok' => true, 'done' => true] + $st;
    $lockPath = __DIR__ . '/../crm-data/logs/mail_archive.lock';
    $lock = @fopen($lockPath, 'c');
    if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) return ['ok' => true, 'skipped' => 'идёт'];
    $cr = ms_creds();
    $c = imapl_open($cr['host'], $cr['port'], $cr['user'], $cr['pass']);
    $folders = ['inbox' => 'INBOX', 'sent' => imapl_sent_folder($c)];
    $save = static function (array $st): void { $st['at'] = date('Y-m-d H:i:s'); setting_set('mail_archive', json_encode($st, JSON_UNESCAPED_UNICODE)); };
    try {
        foreach ($folders as $key => $name) {
            if ($name === '' || !empty($st[$key]['done'])) continue;
            $box = imapl_examine($c, $name);
            if (($st[$key]['uidvalidity'] ?? 0) !== $box['uidvalidity']) $st[$key] = ['uidvalidity' => $box['uidvalidity'], 'next' => 0, 'saved' => 0];
            // Верхняя граница — отметка обычного прогона: свежее ведёт ms_run() с заявками и ответами.
            $live = (int)((ms_state()[$key]['last_uid'] ?? 0)) ?: PHP_INT_MAX;
            $uids = array_values(array_filter(imapl_uids_since($c, time() - $days * 86400), static fn($u) => $u <= $live));
            $st[$key]['total'] = count($uids);
            while ($st[$key]['next'] < count($uids) && time() < $deadline) {
                $part = array_slice($uids, $st[$key]['next'], 100);
                [$ok, $rows] = imapl_cmd($c, 'UID FETCH ' . implode(',', $part) . ' (UID BODY.PEEK[HEADER.FIELDS (MESSAGE-ID)])');
                $ids = [];
                if ($ok) foreach ($rows as $r) {
                    if (preg_match('/UID (\d+)/', $r['line'], $m) && $r['literals']
                        && preg_match('/^Message-ID:\s*(\S+)/im', preg_replace("/\r?\n[ \t]+/", ' ', $r['literals'][0]), $mm)) $ids[(int)$m[1]] = trim($mm[1]);
                }
                $known = [];
                if ($ids) {
                    $q = pdo()->prepare("SELECT ext_id FROM crm_messages WHERE channel='email' AND ext_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')');
                    $q->execute(array_map(static fn($x) => mb_substr($x, 0, 190, 'UTF-8'), array_values($ids)));
                    $known = array_flip($q->fetchAll(PDO::FETCH_COLUMN));
                }
                foreach ($part as $uid) {
                    $mid = $ids[$uid] ?? ('<uid-' . $box['uidvalidity'] . '-' . $uid . '@' . $key . '.local>');
                    if (isset($known[mb_substr($mid, 0, 190, 'UTF-8')])) continue;
                    $raw = imapl_fetch_raw($c, $uid);
                    if ($raw === null) continue;
                    $p = mime_parse($raw, true); unset($raw);
                    $date = ms_msg_date($p);
                    if ($key === 'inbox') {
                        $lid = $p['from_email'] !== '' ? lead_find_existing($p['from_email'], '') : null;
                        $m = msg_insert($lid, 'email', 'in', (string)$p['text'], ['contact' => $p['from_email'], 'subject' => $p['subject'], 'ext_id' => $mid, 'has_attach' => $p['attachments'] ? 1 : 0]);
                        ms_mark($m, ['mail_kind' => 'archive', 'folder' => 'archive', 'is_read' => 1, 'created_at' => $date]);
                    } else {
                        $self = ar_self_addresses();
                        $rcpt = array_values(array_filter(array_merge($p['to'], $p['cc']), static fn($a) => !in_array($a, $self, true)));
                        $to = $rcpt[0] ?? '';
                        $lid = null;
                        foreach ($rcpt as $a) if ($lid = lead_find_existing($a, '')) { $to = $a; break; }
                        $m = msg_insert($lid, 'email', 'out', (string)$p['text'], ['contact' => $to, 'subject' => $p['subject'], 'ext_id' => $mid, 'has_attach' => $p['attachments'] ? 1 : 0]);
                        ms_mark($m, ['mail_kind' => 'manager', 'folder' => 'archive', 'is_read' => 1, 'created_at' => $date, 'in_reply_to' => mb_substr((string)$p['in_reply_to'], 0, 190, 'UTF-8')]);
                    }
                    $st[$key]['saved']++;
                    if (time() > $deadline + 20) break;
                }
                $st[$key]['next'] += count($part);
                $save($st);
            }
            if ($st[$key]['next'] >= count($uids)) $st[$key]['done'] = true;
            $save($st);
            if (time() >= $deadline) break;
        }
    } catch (Throwable $e) {
        $save($st);
        try { imapl_close($c); } catch (Throwable $e2) {}
        return ['ok' => true, 'interrupted' => $e->getMessage()] + $st;
    }
    $st['done'] = !empty($st['inbox']['done']) && (($folders['sent'] ?? '') === '' || !empty($st['sent']['done']));
    $save($st);
    try { imapl_close($c); } catch (Throwable $e) {}
    return ['ok' => true] + $st;
}

// --- CLI ---
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $args = array_slice($argv, 1);
    $opt = ['dry' => in_array('--dry', $args, true)];
    foreach ($args as $a) {
        if (preg_match('/^--backfill=(\d+)$/', $a, $m)) $opt['backfill_days'] = (int)$m[1];
        if (preg_match('/^--max=(\d+)$/', $a, $m)) $opt['max'] = (int)$m[1];
        if (preg_match('/^--budget=(\d+)$/', $a, $m)) $opt['budget_sec'] = (int)$m[1]; // секунд на прогон (архив — дольше)
    }
    if (preg_match('/--learn-backfill(?:=(\d+))?/', implode(' ', $args), $lm)) {
        $days = (int)($lm[1] ?? 180) ?: 180;
        echo json_encode(ms_learn_backfill($days, (int)($opt['budget_sec'] ?? 165)), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
        exit(0);
    }
    if (in_array('--archive', $args, true)) {
        echo json_encode(ms_archive_step(400, (int)($opt['budget_sec'] ?? 150)), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
        exit(0);
    }
    if (in_array('--refetch-files', $args, true)) {
        echo json_encode(ms_refetch_files(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
        exit(0);
    }
    $res = ms_run($opt);
    if (!$opt['dry'] && !in_array('--no-files', $args, true) && ($res['ok'] ?? false)) {
        cron_heartbeat('mail_files');
        $res['files_queue'] = mf_run_queue(8);
        // Разобранные вложения и новые заявки — в поле «Инженер» сделок Битрикс24.
        try { require_once __DIR__ . '/bitrix.php'; $res['bitrix'] = b24_sync_run(); } catch (Throwable $e) { $res['bitrix'] = ['ok' => false, 'error' => $e->getMessage()]; }
    }
    echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(($res['ok'] ?? false) ? 0 : 1);
}
