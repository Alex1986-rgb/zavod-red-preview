<?php
declare(strict_types=1);

/**
 * /api/autoreply.php — ЕДИНЫЙ движок автоответа на входящие сообщения.
 *
 * До него автоответ существовал только для почты и был заглушкой: жёстко прошитый
 * текст в mail_poll() уходил НА ЛЮБОЕ входящее письмо — включая спам, рассылки,
 * ответы коллег и отчёты о недоставке. В Telegram и MAX автоответа не было вовсе:
 * клиент писал в бота и не получал ничего.
 *
 * Вызывается серверными процессами сразу после записи входящего сообщения:
 *   mail_poll()            — api/mail.php
 *   tg_webhook.php         — Telegram
 *   max_webhook.php        — MAX
 *
 * ГЛАВНОЕ ПРАВИЛО: по умолчанию режим 'draft' — движок готовит ответ, но НЕ
 * отправляет. Письма клиенту наружу включаются осознанно (ar_mode='auto').
 *
 * Порядок решения (первое сработавшее правило прекращает разбор):
 *   1. режим выключен                       → skip
 *   2. канал выключен                       → skip
 *   3. текст пустой / короче 3 символов     → skip
 *   4. служебный отправитель (noreply,
 *      mailer-daemon, Auto-Submitted, bulk) → skip   ← защита от петли
 *   5. адрес — наш собственный              → skip   ← защита от петли
 *   6. менеджер уже отвечал за 24 ч         → skip   (не лезем в живой диалог)
 *   7. автоответ по лиду был < cooldown     → skip
 *   8. лимит автоответов на контакт в сутки → skip
 *   9. стоп-слово в тексте                  → draft  (претензия/суд — только человек)
 *  10. вне рабочих часов                    → draft
 *  11. ИИ не уверен (< порога) / нужен
 *      человек / ИИ недоступен              → draft
 *  12. всё чисто и режим 'auto'             → send
 *
 * Возвращает ['action'=>'sent'|'draft'|'skipped', 'reason'=>string, 'text'=>string].
 * Никогда не бросает исключений наружу — приём сообщений важнее автоответа.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/llm.php';
require_once __DIR__ . '/kb_context.php';
require_once __DIR__ . '/site_kb.php';
require_once __DIR__ . '/channel_send.php';
require_once __DIR__ . '/inbox.php';
require_once __DIR__ . '/learn.php';

/* ------------------------------------------------------------------ настройки */

/** Значения по умолчанию. Осознанно консервативные: наружу молча ничего не уходит. */
function ar_defaults(): array {
    return [
        'ar_mode'              => 'learn',   // off | learn | draft | auto — learn: только обучение, ничего не кладём и не шлём
        'ar_ch_email'          => '1',
        'ar_ch_telegram'       => '1',
        'ar_ch_max'            => '1',
        'ar_hours'             => '9-19',    // МСК, вне них — только черновик
        'ar_min_confidence'    => '75',
        'ar_cooldown_min'      => '180',     // не чаще одного автоответа на лид
        'ar_per_contact_day'   => '3',
        'ar_stopwords'         => "претензия\nрекламация\nжалоба\nсуд\nнеустойка\nвозврат денег\nарбитраж",
        'ar_template'          => "Здравствуйте!\n\nМы получили ваше обращение. Инженер изучит запрос и свяжется с вами в рабочее время.\n\nС уважением,\nЗавод Редукторов\n+7 (495) 151-41-02",
        'ar_max_tokens'        => '900',
    ];
}

/** Настройка автоответа: crm_settings → значение по умолчанию. */
function ar_cfg(string $key): string {
    $d = ar_defaults();
    $v = setting($key, null);
    if ($v === null) return (string)($d[$key] ?? '');
    if ($v === '' && $key !== 'ar_stopwords') return (string)($d[$key] ?? ''); // стоп-слова можно осознанно очистить
    return (string)$v;
}

function ar_mode(): string {
    $m = strtolower(trim(ar_cfg('ar_mode')));
    return in_array($m, ['off', 'learn', 'draft', 'auto'], true) ? $m : 'learn';
}

/** Включён ли канал. */
function ar_channel_enabled(string $channel): bool {
    $map = ['email' => 'ar_ch_email', 'telegram' => 'ar_ch_telegram', 'max' => 'ar_ch_max'];
    if (!isset($map[$channel])) return false;
    return ar_cfg($map[$channel]) === '1';
}

/** Внутри рабочих часов (формат «9-19», локальное время = МСК из helpers). */
function ar_within_hours(): bool {
    $raw = trim(ar_cfg('ar_hours'));
    if ($raw === '' || $raw === '0-24') return true;
    if (!preg_match('/^(\d{1,2})\s*-\s*(\d{1,2})$/', $raw, $m)) return true;
    $lo = (int)$m[1]; $hi = (int)$m[2];
    $h = (int)date('G');
    return $lo <= $hi ? ($h >= $lo && $h < $hi) : ($h >= $lo || $h < $hi);
}

/** Наши собственные адреса — чтобы не отвечать самим себе (петля). */
function ar_self_addresses(): array {
    $list = [
        (string)(cfg()['mail']['to'] ?? ''),
        (string)(cfg()['mail']['from'] ?? ''),
        secret('mail_from', ''),
        secret('mail_imap_user', (string)(cfg()['mail']['imap_user'] ?? '')),
        function_exists('zr_mail_from') ? zr_mail_from() : '',
    ];
    $out = [];
    foreach ($list as $a) {
        $a = strtolower(trim($a));
        if ($a !== '' && strpos($a, '@') !== false) $out[$a] = true;
    }
    return array_keys($out);
}

/**
 * Похоже на служебное/автоматическое письмо, на которое отвечать нельзя.
 * $flags — то, что вызывающий вытащил из заголовков (auto_submitted, precedence).
 */
function ar_is_service_sender(string $contact, array $flags = []): bool {
    if (!empty($flags['auto_submitted']) || !empty($flags['precedence_bulk']) || !empty($flags['list_id'])) {
        return true;
    }
    $c = strtolower(trim($contact));
    if ($c === '') return false;
    $needles = ['noreply', 'no-reply', 'no_reply', 'donotreply', 'do-not-reply',
                'mailer-daemon', 'mailerdaemon', 'postmaster', 'bounce', 'bounces',
                'notification', 'notifications', 'newsletter', 'no.reply', 'robot@', 'daemon@'];
    foreach ($needles as $n) {
        if (strpos($c, $n) !== false) return true;
    }
    return false;
}

/**
 * Сработавшее стоп-слово или '' — такие темы к человеку, без ИИ-самодеятельности.
 *
 * Ищем по ОСНОВЕ, а не по точной форме: клиент пишет «направляем претензию», а в списке
 * стоит «претензия» — простое вхождение подстроки такой падеж не находило, и письмо о
 * претензии считалось чистым. Короткие слова (≤4 символов, например «суд») сверяем по
 * границам слова, иначе «судостроение» и «судно» ложно попадали бы в стоп-лист.
 */
function ar_stopword_hit(string $text): string {
    $words = preg_split('/\R+/u', ar_cfg('ar_stopwords')) ?: [];
    $norm = static fn(string $s): string => str_replace('ё', 'е', mb_strtolower(trim($s), 'UTF-8'));
    $t = $norm($text);
    foreach ($words as $raw) {
        $w = $norm($raw);
        if ($w === '') continue;
        // Фраза из нескольких слов («возврат денег») — как есть.
        if (mb_strpos($w, ' ', 0, 'UTF-8') !== false) {
            if (mb_strpos($t, $w, 0, 'UTF-8') !== false) return $raw;
            continue;
        }
        if (mb_strlen($w, 'UTF-8') <= 4) {
            if (preg_match('~(?<![а-яa-z])' . preg_quote($w, '~') . '(?![а-яa-z])~u', $t)) return $raw;
            continue;
        }
        // Основа: снимаем до двух окончаний-гласных/мягких знаков.
        $stem = (string)preg_replace('~[аяоеиыуюйьъ]{1,2}$~u', '', $w);
        if ($stem === '' || mb_strlen($stem, 'UTF-8') < 4) $stem = $w;
        if (preg_match('~(?<![а-яa-z])' . preg_quote($stem, '~') . '~u', $t)) return $raw;
    }
    return '';
}

/* -------------------------------------------------------------- история/лимиты */

/** Отвечал ли живой менеджер по лиду за последние $hours часов. */
function ar_human_replied_recently(int $leadId, int $hours = 24): bool {
    try {
        // Автоответы пишут событие autoreply_sent и user_id=NULL, поэтому
        // 'msg_sent' с непустым user_id — это гарантированно живой менеджер.
        // Интервал подставляем числом: MySQL не везде принимает placeholder внутри INTERVAL.
        $h = max(1, $hours);
        $st = pdo()->prepare(
            "SELECT COUNT(*) FROM crm_events
             WHERE lead_id = ? AND type = 'msg_sent' AND user_id IS NOT NULL
               AND created_at >= (NOW() - INTERVAL {$h} HOUR)"
        );
        $st->execute([$leadId]);
        return ((int)$st->fetchColumn()) > 0;
    } catch (Throwable $e) { return false; }
}

/**
 * Сколько автоответов по лиду за $minutes минут.
 * $withDrafts=true — считать и черновики: иначе на каждое следующее письмо клиента
 * в «Черновики» ложится ещё одна копия того же ответа, и менеджер разгребает мусор.
 */
function ar_sent_count(int $leadId, int $minutes, bool $withDrafts = false): int {
    try {
        $m = max(1, $minutes); // числом, а не placeholder — см. ar_human_replied_recently
        $types = $withDrafts ? "('autoreply_sent','autoreply_draft')" : "('autoreply_sent')";
        $st = pdo()->prepare(
            "SELECT COUNT(*) FROM crm_events
             WHERE lead_id = ? AND type IN {$types}
               AND created_at >= (NOW() - INTERVAL {$m} MINUTE)"
        );
        $st->execute([$leadId]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

/** Последние сообщения переписки лида — контекст для ИИ. */
function ar_thread_text(int $leadId, int $limit = 8): string {
    try {
        $st = pdo()->prepare(
            "SELECT direction, channel, subject, body, created_at
             FROM crm_messages WHERE lead_id = ? ORDER BY id DESC LIMIT ?"
        );
        $st->bindValue(1, $leadId, PDO::PARAM_INT);
        $st->bindValue(2, $limit, PDO::PARAM_INT);
        $st->execute();
        $rows = array_reverse($st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    } catch (Throwable $e) { return ''; }

    $out = [];
    foreach ($rows as $r) {
        $who = ($r['direction'] ?? '') === 'in' ? 'КЛИЕНТ' : 'МЫ';
        $b = trim((string)($r['body'] ?? ''));
        if ($b === '') continue;
        if (mb_strlen($b, 'UTF-8') > 1200) $b = mb_substr($b, 0, 1200, 'UTF-8') . '…';
        $out[] = '[' . $r['created_at'] . '] ' . $who . ' (' . $r['channel'] . '): ' . $b;
    }
    return implode("\n\n", $out);
}

/* ----------------------------------------------------------------------- ИИ */

/** Настроен ли ИИ. */
function ar_ai_ready(): bool {
    return llm_ready();
}

/**
 * Сгенерировать ответ через Claude. Возвращает
 * ['reply'=>string,'confidence'=>int,'needs_human'=>bool,'topic'=>string] или null.
 */
function ar_ai_reply(array $lead, string $channel, string $incoming, string $thread): ?array {
    if (!ar_ai_ready()) return null;

    $limit = $channel === 'email' ? 400 : 200; // в мессенджере ответ должен быть короче
    $system =
        "Ты — менеджер завода «Завод Редукторов». Отвечаешь на обращение клиента по канале «{$channel}».\n"
      . "Задача: дать полезный, короткий, вежливый ответ на «вы» — до {$limit} слов.\n"
      . "СТРОГИЕ ЗАПРЕТЫ: не выдумывать цены, сроки, наличие, характеристики и модели; "
      . "не обещать скидок и договорных условий; не называть цены на импортное оборудование; "
      . "не ссылаться на файлы, помеченные как непрочитанные.\n"
      . "ГЛАВНОЕ (по оценке ответов против настоящих менеджеров, 01.10.2026):\n"
      . "1) Сначала пойми ЭТАП СДЕЛКИ (блок «СОСТОЯНИЕ СДЕЛКИ» и переписка). Если уже идёт работа — КП/счёт/договор "
      . "отправлены, ждём оплату, отгрузку, документы — отвечай как менеджер на этом этапе: коротко подтверди и сделай "
      . "следующий шаг (пришлём счёт/договор/УПД, уточню статус у производства). НЕ начинай подбор заново и НЕ задавай "
      . "вопросы о параметрах, которые уже есть в переписке.\n"
      . "2) Отвечай ТОЛЬКО на то, о чём клиент спросил в новом сообщении. Сроки и условия — только если о них спросили.\n"
      . "3) Пиши коротко, как менеджеры: 2–5 предложений. Не перечисляй лишнего.\n"
      . "4) Если инженер уже подобрал аналог (блок «ПОДБОР ИНЖЕНЕРА») — называй именно его, а не другой из справочника.\n"
      . "5) Документы прикладывает человек: вместо вложения пиши «направляю/направим КП (счёт, чертёж)» — needs_human=false, "
      . "если это обычный шаг.\n"
      . "Если это ПЕРВОЕ обращение и данных не хватает — задай не больше трёх уточняющих вопросов "
      . "(мощность, обороты, передаточное отношение, момент, способ монтажа, количество).\n"
      . "Если тема вне твоей компетенции (претензия, рекламация, юридический вопрос, "
      . "нестандартный заказ, требование конкретной цены) — needs_human=true.\n"
      . "Цена, срок изготовления и наличие — недоказуемы: прайса у нас нет, а цифра в "
      . "справочнике заглушечная. Вместо них пиши: «инженер посчитает под ваш типоразмер "
      . "и количество и пришлёт расчёт». Срок называй, только если клиент спросил: по правилам из базы знаний, "
      . "точный срок подтвердит менеджер.\n"
      . "Соответствие «импортная модель → ZR» бери ТОЛЬКО из справочника ниже. "
      . "Нет в справочнике — так и напиши, что проверит инженер.\n"
      . "Подпись: Завод Редукторов, +7 (495) 151-41-02.\n\n"
      . "Верни СТРОГО валидный JSON без markdown:\n"
      . '{"reply":"текст ответа клиенту","confidence":0,"needs_human":false,"topic":"о чём обращение, 3-6 слов"}' . "\n"
      . "confidence — целое 0..100: насколько ответ обоснован БАЗОЙ ЗНАНИЙ и полон. "
      . "Если пришлось догадываться — ставь ниже 60.\n\n"
      // Раньше сюда лился kb_context(60000) — вся база на каждое письмо. Теперь ядро
      // правил плюс найденное по тексту обращения: справочник ZR и куски пояснений.
      . "=== БАЗА ЗНАНИЙ ===\n" . kb_context(20000);

    $siteCtx = site_context($incoming, 3000);
    if ($siteCtx !== '') $system .= "\n\n" . $siteCtx;

    $u = "КАРТОЧКА КЛИЕНТА: " . trim((string)($lead['name'] ?? '—'))
       . ($lead['reducer_type'] ? ("; интерес: " . $lead['reducer_type']) : '') . "\n\n";
    $deal = ar_deal_context($lead);
    if ($deal !== '') $u .= $deal . "\n\n";
    if ($thread !== '') $u .= "ПЕРЕПИСКА (старые сверху):\n" . $thread . "\n\n";
    $u .= "НОВОЕ СООБЩЕНИЕ КЛИЕНТА:\n" . $incoming . "\n\nВерни только JSON.";

    try {
        $text = ar_claude_call($system, $u, (int)ar_cfg('ar_max_tokens'));
        $data = ar_json_extract($text);
        $reply = trim((string)($data['reply'] ?? ''));
        if ($reply === '') return null;
        return [
            'reply'       => $reply,
            'confidence'  => max(0, min(100, (int)($data['confidence'] ?? 0))),
            'needs_human' => (bool)($data['needs_human'] ?? false),
            'topic'       => trim((string)($data['topic'] ?? '')),
        ];
    } catch (Throwable $e) {
        error_log('autoreply ИИ: ' . $e->getMessage());
        return null;
    }
}

/**
 * Где сейчас сделка: статус заявки, подбор инженера (аналог ZR), какие документы мы уже отправили
 * и что прислал клиент. Без этого робот отвечал на середину сделки как на первое обращение
 * (оценка 01.10.2026: средний балл 20/100, главная причина — «не видит этап»).
 */
function ar_deal_context(array $lead): string {
    $id = (int)($lead['id'] ?? 0);
    if (!$id) return '';
    $L = ['СОСТОЯНИЕ СДЕЛКИ: статус «' . status_label((string)($lead['status'] ?? 'new')) . '»'];
    try {
        $a = pdo()->prepare("SELECT content FROM crm_ai WHERE lead_id=? AND type IN ('recognize','nameplate') ORDER BY id DESC LIMIT 1");
        $a->execute([$id]);
        $d = json_decode((string)$a->fetchColumn(), true);
        if (is_array($d)) {
            $an = array_map(static fn($x) => ($x['for'] ?? '?') . ' → ' . ($x['our'] ?? '?'), (array)($d['analogs'] ?? []));
            $L[] = 'ПОДБОР ИНЖЕНЕРА: ' . trim(($d['summary'] ?? '') . ($an ? ' (' . implode('; ', $an) . ')' : ''));
        }
        $f = pdo()->prepare("SELECT direction, filename, kind FROM crm_mail_files WHERE lead_id=? AND status IN ('done','skipped') ORDER BY id DESC LIMIT 8");
        $f->execute([$id]);
        $out = []; $in = [];
        foreach ($f->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $x = $r['filename'] . ($r['kind'] !== '' ? ' (' . $r['kind'] . ')' : '');
            if ($r['direction'] === 'out') $out[] = $x; else $in[] = $x;
        }
        if ($out) $L[] = 'МЫ УЖЕ ОТПРАВИЛИ КЛИЕНТУ: ' . implode(', ', $out);
        if ($in) $L[] = 'КЛИЕНТ ПРИСЫЛАЛ: ' . implode(', ', $in);
        $n = pdo()->prepare("SELECT COUNT(*) FROM crm_messages WHERE lead_id=? AND direction='out' AND folder NOT IN ('drafts','trash','shadow')");
        $n->execute([$id]);
        $cnt = (int)$n->fetchColumn();
        $L[] = 'Наших ответов клиенту в переписке: ' . $cnt . ($cnt === 0 ? ' (первое обращение)' : ' (сделка уже идёт)');
    } catch (Throwable $e) {}
    return implode("\n", $L);
}

/** Вызов Claude Messages API с ретраями на 429/5xx. */
function ar_claude_call(string $system, string $userText, int $maxTokens = 900): string {
    return llm_call($system, $userText, $maxTokens);
}

/** Вытащить JSON из ответа модели (снимает ```-ограждения и текст по краям). */
function ar_json_extract(string $text): array {
    $t = trim($text);
    $t = (string)preg_replace('/^```[a-zA-Z]*\s*/', '', $t);
    $t = trim((string)preg_replace('/\s*```$/', '', $t));
    $s = strpos($t, '{'); $e = strrpos($t, '}');
    if ($s !== false && $e !== false && $e > $s) $t = substr($t, $s, $e - $s + 1);
    $d = json_decode($t, true);
    if (!is_array($d)) throw new RuntimeException('не-JSON от модели');
    return $d;
}

/* --------------------------------------------------------------- сохранение */

/**
 * Сохранить черновик автоответа.
 *  • всегда — запись в crm_ai (type='autoreply'), видна в карточке лида;
 *  • для e-mail дополнительно — исходящее в папке «Черновики» почтового клиента,
 *    где у менеджера уже есть кнопка отправки (api/mailbox.php action=send_draft).
 */
function ar_store_draft(int $leadId, string $channel, string $text, array $meta): void {
    try {
        pdo()->prepare('INSERT INTO crm_ai (lead_id,user_id,type,content,created_at) VALUES (?,NULL,?,?,NOW())')
            ->execute([$leadId, 'autoreply', json_encode([
                'channel' => $channel, 'text' => $text,
            ] + $meta, JSON_UNESCAPED_UNICODE)]);
    } catch (Throwable $e) { /* таблицы может не быть — не валим приём */ }

    if ($channel === 'email') {
        try {
            $st = pdo()->prepare('SELECT email FROM crm_leads WHERE id=? LIMIT 1');
            $st->execute([$leadId]);
            $to = (string)$st->fetchColumn();
            if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL)) {
                $mid = msg_insert($leadId, 'email', 'out', $text, [
                    'contact' => $to,
                    'subject' => (string)($meta['subject'] ?? 'Ответ от Завода Редукторов'),
                ]);
                if ($mid) {
                    pdo()->prepare("UPDATE crm_messages SET folder='drafts' WHERE id=?")->execute([$mid]);
                    // Метка «черновик робота»: по ней обучение считает, как часто его отправляют без правок.
                    try { pdo()->prepare("UPDATE crm_messages SET mail_kind='robot' WHERE id=?")->execute([$mid]); } catch (Throwable $e) {}
                }
            }
        } catch (Throwable $e) { /* колонки folder может не быть до mb_ensure() */ }
    }
}

/* ------------------------------------------------------------------- движок */

/**
 * Главная точка входа. Вызывать ПОСЛЕ записи входящего сообщения.
 *
 * @param int    $leadId  лид, к которому привязано сообщение
 * @param string $channel email | telegram | max
 * @param array  $msg     ['body','contact','subject','flags'=>['auto_submitted'=>bool,…]]
 */
function autoreply_handle(int $leadId, string $channel, array $msg): array {
    $skip = static function (string $reason) use ($leadId, $channel): array {
        // Пропуски тоже журналируем: иначе «почему бот молчал» не выяснить.
        try { audit($leadId, null, 'autoreply_skip', ['channel' => $channel, 'reason' => $reason]); } catch (Throwable $e) {}
        return ['action' => 'skipped', 'reason' => $reason, 'text' => ''];
    };

    try {
        $mode = ar_mode();
        if ($mode === 'off')                    return ['action' => 'skipped', 'reason' => 'автоответ выключен', 'text' => ''];
        if (!ar_channel_enabled($channel))      return ['action' => 'skipped', 'reason' => 'канал выключен', 'text' => ''];

        $body    = trim((string)($msg['body'] ?? ''));
        $contact = trim((string)($msg['contact'] ?? ''));
        $flags   = (array)($msg['flags'] ?? []);

        if (mb_strlen($body, 'UTF-8') < 3)      return $skip('пустое или слишком короткое сообщение');
        if (ar_is_service_sender($contact, $flags)) return $skip('служебный отправитель (рассылка/робот)');
        if (in_array(strtolower($contact), ar_self_addresses(), true)) return $skip('это наш собственный адрес');
        if (ar_human_replied_recently($leadId)) return $skip('менеджер уже в диалоге (ответ за последние 24 ч)');

        $cooldown = max(0, (int)ar_cfg('ar_cooldown_min'));
        // Черновики считаем тоже: повторные письма клиента не должны плодить копии.
        if ($cooldown > 0 && ar_sent_count($leadId, $cooldown, true) > 0) {
            return $skip("автоответ (или черновик) уже был менее {$cooldown} мин назад");
        }
        $perDay = max(0, (int)ar_cfg('ar_per_contact_day'));
        if ($perDay > 0 && ar_sent_count($leadId, 24 * 60) >= $perDay) {
            return $skip("исчерпан суточный лимит автоответов ({$perDay})");
        }

        $st = pdo()->prepare('SELECT * FROM crm_leads WHERE id=? LIMIT 1');
        $st->execute([$leadId]);
        $lead = $st->fetch() ?: [];

        // Причины, по которым отправлять нельзя даже в режиме 'auto'.
        $holdReasons = [];
        if (($hit = ar_stopword_hit($body)) !== '') $holdReasons[] = "стоп-слово «{$hit}»";
        if (!ar_within_hours())                    $holdReasons[] = 'вне рабочих часов (' . ar_cfg('ar_hours') . ')';

        $ai = ar_ai_reply($lead, $channel, $body, ar_thread_text($leadId));
        if ($ai === null) {
            $holdReasons[] = ar_ai_ready() ? 'ИИ не ответил' : 'ИИ не настроен';
            $text = ar_cfg('ar_template');
            $conf = 0; $topic = '';
        } else {
            $text  = $ai['reply'];
            $conf  = $ai['confidence'];
            $topic = $ai['topic'];
            if ($ai['needs_human'])  $holdReasons[] = 'ИИ считает, что нужен человек';
            $minConf = max(0, (int)ar_cfg('ar_min_confidence'));
            if ($conf < $minConf)    $holdReasons[] = "уверенность {$conf} < порога {$minConf}";
        }

        $subject = (string)($msg['subject'] ?? '');
        $subject = $subject !== '' ? ('Re: ' . preg_replace('/^(Re:\s*)+/iu', '', $subject)) : 'Ответ от Завода Редукторов';
        $meta = ['confidence' => $conf, 'topic' => $topic, 'subject' => $subject,
                 'hold' => $holdReasons, 'mode' => $mode];

        // Тихое обучение (решение Александра 29.09.2026: «должна чисто обучаться, не должна отправлять»):
        // ответ робота только запоминается. Ни черновика в почте, ни отправки. Когда менеджер ответит
        // клиенту сам (из CRM или Яндекс.Почты), learn_shadow_eval() сравнит его ответ с этим.
        if ($mode === 'learn') {
            try {
                pdo()->prepare('INSERT INTO crm_ai (lead_id,user_id,type,content,created_at) VALUES (?,NULL,?,?,NOW())')
                    ->execute([$leadId, 'autoreply', json_encode(['channel' => $channel, 'text' => $text, 'shadow' => true] + $meta, JSON_UNESCAPED_UNICODE)]);
            } catch (Throwable $e) {}
            audit($leadId, null, 'autoreply_shadow', ['channel' => $channel, 'confidence' => $conf]);
            return ['action' => 'shadow', 'reason' => 'режим обучения — ответ только запомнен', 'text' => $text];
        }

        if ($mode === 'draft' || $holdReasons) {
            ar_store_draft($leadId, $channel, $text, $meta);
            $reason = $mode === 'draft' && !$holdReasons
                ? 'режим «черновик»'
                : implode('; ', $holdReasons);
            audit($leadId, null, 'autoreply_draft', ['channel' => $channel, 'reason' => $reason, 'confidence' => $conf]);
            return ['action' => 'draft', 'reason' => $reason, 'text' => $text];
        }

        // Режим 'auto' и ни одного стоп-условия — отправляем.
        $res = channel_send($leadId, $channel, $text, $subject);
        if (!($res['ok'] ?? false)) {
            // Не ушло — держим черновиком, чтобы менеджер увидел и добил руками.
            ar_store_draft($leadId, $channel, $text, $meta + ['send_error' => (string)$res['error']]);
            audit($leadId, null, 'autoreply_failed', ['channel' => $channel, 'error' => (string)$res['error']]);
            return ['action' => 'draft', 'reason' => 'отправка не удалась: ' . $res['error'], 'text' => $text];
        }

        msg_insert($leadId, $channel, 'out', $text, [
            'contact' => (string)$res['contact'],
            'ext_id'  => $channel === 'email' ? '' : (string)$res['contact'],
            'subject' => $channel === 'email' ? (string)$res['subject'] : '',
        ]);
        audit($leadId, null, 'autoreply_sent', ['channel' => $channel, 'confidence' => $conf,
                                                'topic' => $topic, 'via' => (string)$res['via']]);
        return ['action' => 'sent', 'reason' => 'отправлено', 'text' => $text];

    } catch (Throwable $e) {
        // Автоответ НИКОГДА не должен ломать приём сообщения.
        error_log('autoreply: ' . $e->getMessage());
        return ['action' => 'skipped', 'reason' => 'внутренняя ошибка: ' . $e->getMessage(), 'text' => ''];
    }
}
