<?php
declare(strict_types=1);

/**
 * Обучение автоответчика на живой переписке.
 *
 * Это не дообучение модели (его у DeepSeek/Claude не купить под один завод), а память:
 *   1. Каждый ответ менеджера клиенту — из CRM или из Яндекс.Почты («Отправленные»
 *      забирает api/mail_sync.php) — пара «письмо клиента → наш ответ» в crm_examples.
 *   2. Каждый черновик робота, который менеджер отправил, — оценка: ушёл как есть
 *      (draft_ok) или с правками (draft_edit, похожесть 0..100). Правленый текст
 *      тоже становится примером — робот учится на исправлениях.
 *   3. При ответе на новое письмо в промпт подкладываются 2–3 самых похожих примера
 *      (site_examples() в site_kb.php) — модель берёт у менеджеров стиль и ход ответа.
 *
 * Автоотправку писем включать по цифрам learn_stats(): когда черновики долго
 * уходят без правок — робот готов (см. страницу «Автопилот»).
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/mime.php';

function learn_ensure(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        pdo()->exec("CREATE TABLE IF NOT EXISTS crm_examples (
            id INT AUTO_INCREMENT PRIMARY KEY,
            lead_id INT NULL,
            in_msg_id INT NULL,
            out_msg_id INT NULL,
            subject VARCHAR(255) NOT NULL DEFAULT '',
            in_text TEXT NOT NULL,
            out_text TEXT NOT NULL,
            source VARCHAR(16) NOT NULL DEFAULT 'sent',   -- sent | crm | draft_ok | draft_edit | backfill
            similarity TINYINT NULL,                        -- для черновиков: насколько отправленное похоже на черновик
            topic VARCHAR(120) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_src (source), KEY idx_created (created_at), UNIQUE KEY uq_out (out_msg_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { error_log('learn_ensure: ' . $e->getMessage()); }
    // Оценка тихого обучения по смыслу (DeepSeek): 0..100 и что робот упустил / лишнего пообещал.
    foreach (["ALTER TABLE crm_examples ADD COLUMN score TINYINT NULL", "ALTER TABLE crm_examples ADD COLUMN verdict VARCHAR(500) NOT NULL DEFAULT ''"] as $sql) {
        try { pdo()->exec($sql); } catch (Throwable $e) { /* уже есть */ }
    }
}

/**
 * Сравнить ответ робота с ответом менеджера ПО СУТИ (не по словам). null — ИИ недоступен.
 * ['score' => 0..100, 'verdict' => 'коротко: совпало / упустил / лишнее обещание'].
 */
function learn_judge(string $robot, string $manager): ?array {
    if (!function_exists('llm_ready')) require_once __DIR__ . '/llm.php';
    if (!llm_ready()) return null;
    $system = "Ты оцениваешь ИИ-помощника отдела продаж завода редукторов. Даны ответ РОБОТА и настоящий ответ МЕНЕДЖЕРА тому же клиенту.\n"
        . "Оцени, насколько ответ робота совпадает с ответом менеджера ПО СУТИ (не по словам): тот же аналог/модель ZR или тот же вывод; "
        . "те же уточняющие вопросы/запрошенные данные; то же следующее действие (КП, счёт, чертёж, передача инженеру). "
        . "Штрафуй сильно, если робот пообещал то, чего менеджер не обещал (цену, срок, скидку, наличие) или ошибся с моделью.\n"
        . "Робот НЕ МОЖЕТ прикладывать файлы и знать сегодняшние цены: если менеджер приложил КП/счёт/договор, засчитай роботу, "
        . "если он по сути сделал тот же шаг («направляю КП/счёт», «подготовим договор»). Конкретные цифры менеджера (цена, отсрочка) "
        . "робот знать не обязан — не штрафуй за их отсутствие, штрафуй за выдуманные.\n"
        . 'Верни СТРОГО JSON: {"score":0,"verdict":"до 25 слов: что совпало, что упущено, что лишнее"}';
    try {
        $raw = llm_call($system, "ОТВЕТ РОБОТА:\n" . mb_substr($robot, 0, 2500, 'UTF-8') . "\n\nОТВЕТ МЕНЕДЖЕРА:\n" . mb_substr($manager, 0, 2500, 'UTF-8'), 300);
        $t = trim((string)preg_replace(['/^```[a-z]*\s*/i', '/\s*```$/'], '', trim($raw)));
        $s = strpos($t, '{'); $e = strrpos($t, '}');
        $d = ($s !== false && $e !== false) ? json_decode(substr($t, $s, $e - $s + 1), true) : null;
        if (!is_array($d) || !isset($d['score'])) return null;
        return ['score' => max(0, min(100, (int)$d['score'])), 'verdict' => mb_substr(trim((string)($d['verdict'] ?? '')), 0, 500, 'UTF-8')];
    } catch (Throwable $e) { error_log('learn_judge: ' . $e->getMessage()); return null; }
}

/** Убрать подпись/цитату и лишние пробелы — в пример идёт только суть. */
function learn_clean(string $t, int $max = 2000): string {
    $t = mime_strip_quote($t);
    // Подпись «С уважением, …» и всё ниже — в обучении не нужна (у каждого менеджера своя).
    $t = (string)preg_replace('/\n\s*(С уважением|С наилучшими пожеланиями|Best regards|--\s*$).*$/isu', '', $t);
    $t = trim((string)preg_replace("/\n{3,}/", "\n\n", $t));
    return mb_substr($t, 0, $max, 'UTF-8');
}

/** Похожесть двух текстов 0..100 (по словам, устойчиво к переносам и регистру). */
function learn_similarity(string $a, string $b): int {
    $tok = static function (string $s): array {
        preg_match_all('/[a-zа-яё0-9]+/u', mb_strtolower($s, 'UTF-8'), $m);
        return $m[0];
    };
    $x = $tok($a); $y = $tok($b);
    if (!$x && !$y) return 100;
    if (!$x || !$y) return 0;
    // Мультимножества: сколько слов совпало с учётом повторов.
    $cx = array_count_values($x); $cy = array_count_values($y);
    $common = 0;
    foreach ($cx as $w => $n) $common += min($n, $cy[$w] ?? 0);
    return (int)round(200 * $common / (count($x) + count($y)));
}

/** Последнее входящее от этого контакта до момента ответа — на что отвечали. */
function learn_find_incoming(?int $leadId, string $contact, string $inReplyTo = '', ?string $before = null): ?array {
    try {
        if ($inReplyTo !== '') {
            $st = pdo()->prepare("SELECT id, lead_id, subject, body FROM crm_messages WHERE channel='email' AND direction='in' AND ext_id=? LIMIT 1");
            $st->execute([$inReplyTo]);
            if ($r = $st->fetch(PDO::FETCH_ASSOC)) return $r;
        }
        $before = $before ?: date('Y-m-d H:i:s');
        if ($leadId) {
            $st = pdo()->prepare("SELECT id, lead_id, subject, body FROM crm_messages
                                  WHERE lead_id=? AND direction='in' AND created_at <= ? ORDER BY created_at DESC, id DESC LIMIT 1");
            $st->execute([$leadId, $before]);
        } else {
            $st = pdo()->prepare("SELECT id, lead_id, subject, body FROM crm_messages
                                  WHERE channel='email' AND direction='in' AND contact=? AND created_at <= ? ORDER BY created_at DESC, id DESC LIMIT 1");
            $st->execute([strtolower($contact), $before]);
        }
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { return null; }
}

/**
 * Записать пример. Короткие/пустые пары и ответы-отписки («спасибо, получили»)
 * не берём — они ничему не учат. Возвращает id или 0.
 */
function learn_add(array $in, string $outText, string $source, ?int $outMsgId = null, ?int $similarity = null): int {
    learn_ensure();
    $inText = learn_clean((string)($in['body'] ?? ''));
    $outText = learn_clean($outText);
    if (mb_strlen($inText, 'UTF-8') < 15 || mb_strlen($outText, 'UTF-8') < 40) return 0;
    try {
        $st = pdo()->prepare('INSERT IGNORE INTO crm_examples (lead_id,in_msg_id,out_msg_id,subject,in_text,out_text,source,similarity,created_at)
                              VALUES (?,?,?,?,?,?,?,?,NOW())');
        $st->execute([
            isset($in['lead_id']) && $in['lead_id'] ? (int)$in['lead_id'] : null,
            isset($in['id']) ? (int)$in['id'] : null,
            $outMsgId,
            mb_substr((string)($in['subject'] ?? ''), 0, 255, 'UTF-8'),
            $inText, $outText, $source, $similarity,
        ]);
        return (int)pdo()->lastInsertId();
    } catch (Throwable $e) { error_log('learn_add: ' . $e->getMessage()); return 0; }
}

/** Ответ менеджера ушёл (из CRM или из Яндекс.Почты) — сохранить пример. */
function learn_from_reply(?int $leadId, string $contact, string $outText, ?int $outMsgId, string $source = 'crm', string $inReplyTo = ''): int {
    $in = learn_find_incoming($leadId, $contact, $inReplyTo);
    if (!$in) return 0;
    return learn_add($in, $outText, $source, $outMsgId);
}

/**
 * Черновик робота отправлен. $draftText — что предлагал робот, $sentText — что ушло.
 * Похожесть ≥ 95 — «принят как есть».
 */
function learn_from_draft(?int $leadId, string $contact, string $draftText, string $sentText, ?int $outMsgId): void {
    $sim = learn_similarity(learn_clean($draftText), learn_clean($sentText));
    $source = $sim >= 95 ? 'draft_ok' : 'draft_edit';
    $in = learn_find_incoming($leadId, $contact) ?? ['body' => '', 'lead_id' => $leadId];
    $id = learn_add($in, $sentText, $source, $outMsgId, $sim);
    if (!$id) {
        // Пары для примера не нашлось (входящее не в CRM), но оценку черновика всё равно учитываем.
        learn_ensure();
        try {
            pdo()->prepare("INSERT IGNORE INTO crm_examples (lead_id,out_msg_id,in_text,out_text,source,similarity,created_at) VALUES (?,?,'','',?,?,NOW())")
                ->execute([$leadId, $outMsgId, $source, $sim]);
        } catch (Throwable $e) {}
    }
    if ($leadId) audit($leadId, null, 'learn_draft', ['similarity' => $sim, 'accepted' => $source === 'draft_ok']);
}

/**
 * Тихая оценка робота: менеджер ответил клиенту сам — сравниваем его ответ с последним ответом,
 * который робот приготовил по этой заявке (crm_ai autoreply, не старше 7 дней, ещё не оценённым).
 * Пишется только строка статистики (source=shadow, similarity) — сам ответ менеджера уже пример (sent/crm).
 */
function learn_shadow_eval(?int $leadId, string $managerText, ?int $outMsgId = null): ?int {
    if (!$leadId || mb_strlen(trim($managerText), 'UTF-8') < 20) return null;
    learn_ensure();
    try {
        $st = pdo()->prepare("SELECT id, content FROM crm_ai WHERE lead_id=? AND type='autoreply'
                              AND created_at >= NOW() - INTERVAL 7 DAY AND content NOT LIKE '%\"evaluated\":true%'
                              ORDER BY id DESC LIMIT 1");
        $st->execute([$leadId]);
        $a = $st->fetch(PDO::FETCH_ASSOC);
        if (!$a) return null;
        $d = json_decode((string)$a['content'], true) ?: [];
        $robot = (string)($d['text'] ?? '');
        if ($robot === '') return null;
        $sim = learn_similarity(learn_clean($robot), learn_clean($managerText));
        $j = learn_judge(learn_clean($robot), learn_clean($managerText));
        // out_msg_id не пишем: то же письмо менеджера уже лежит примером (sent/crm) с уникальным out_msg_id,
        // и INSERT IGNORE молча выбрасывал оценку (29.09 записалась 1 из 17). Номер письма — в topic.
        pdo()->prepare("INSERT INTO crm_examples (lead_id,out_msg_id,in_text,out_text,source,similarity,score,verdict,topic,created_at) VALUES (?,NULL,'','','shadow',?,?,?,?,NOW())")
            ->execute([$leadId, $sim, $j['score'] ?? null, $j['verdict'] ?? '', $outMsgId ? 'msg:' . $outMsgId : '']);
        if ($j) { $d['score'] = $j['score']; $d['verdict'] = $j['verdict']; }
        $d['evaluated'] = true; $d['similarity'] = $sim;
        pdo()->prepare('UPDATE crm_ai SET content=? WHERE id=?')->execute([json_encode($d, JSON_UNESCAPED_UNICODE), (int)$a['id']]);
        audit($leadId, null, 'learn_shadow', ['similarity' => $sim]);
        return $sim;
    } catch (Throwable $e) { error_log('learn_shadow_eval: ' . $e->getMessage()); return null; }
}

/** Похожие примеры из базы для промпта: [['incoming','reply','subject'], …]. */
function learn_examples(string $incoming, int $limit = 3): array {
    learn_ensure();
    $tok = static function (string $s): array {
        preg_match_all('/[a-zа-яё0-9]{3,}/u', mb_strtolower($s, 'UTF-8'), $m);
        return array_map(static fn($t) => mb_substr($t, 0, 6, 'UTF-8'), $m[0]);
    };
    $q = array_flip($tok($incoming));
    if (!$q) return [];
    try {
        // Свежие 800 примеров: правленные менеджером и ручные ответы весомее «принятых как есть».
        $rows = pdo()->query("SELECT subject, in_text, out_text, source FROM crm_examples
                              WHERE in_text <> '' AND out_text <> '' ORDER BY id DESC LIMIT 800")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
    $w = ['draft_edit' => 1.3, 'crm' => 1.2, 'sent' => 1.2, 'backfill' => 1.0, 'draft_ok' => 0.9];
    $scored = [];
    foreach ($rows as $r) {
        $t = $tok($r['subject'] . ' ' . $r['in_text']);
        if (!$t) continue;
        $hit = 0;
        foreach ($t as $x) if (isset($q[$x])) $hit++;
        if ($hit < 2) continue;
        $scored[] = ['s' => $hit / sqrt(count($t)) * ($w[$r['source']] ?? 1.0), 'r' => $r];
    }
    usort($scored, static fn($a, $b) => $b['s'] <=> $a['s']);
    $out = [];
    foreach (array_slice($scored, 0, $limit) as $x) {
        $out[] = ['incoming' => $x['r']['in_text'], 'reply' => $x['r']['out_text'], 'subject' => $x['r']['subject']];
    }
    return $out;
}

/**
 * Цифры для решения «пора ли включать автоотправку».
 * Готово, когда за 30 дней ≥ 20 оценённых черновиков и ≥ 80 % ушли без правок.
 */
function learn_stats(int $days = 30): array {
    learn_ensure();
    $d = max(1, $days);
    $s = ['examples' => 0, 'by_source' => [], 'drafts' => 0, 'drafts_ok' => 0, 'avg_similarity' => null, 'ready' => false];
    try {
        $s['examples'] = (int)pdo()->query("SELECT COUNT(*) FROM crm_examples WHERE in_text <> ''")->fetchColumn();
        foreach (pdo()->query("SELECT source, COUNT(*) c FROM crm_examples GROUP BY source") as $r) $s['by_source'][$r['source']] = (int)$r['c'];
        $r = pdo()->query("SELECT COUNT(*) n, SUM(source='draft_ok') ok, AVG(similarity) av FROM crm_examples
                           WHERE source IN ('draft_ok','draft_edit') AND created_at >= NOW() - INTERVAL {$d} DAY")->fetch(PDO::FETCH_ASSOC);
        $s['drafts'] = (int)($r['n'] ?? 0);
        $s['drafts_ok'] = (int)($r['ok'] ?? 0);
        $s['avg_similarity'] = $r['av'] !== null ? (int)round((float)$r['av']) : null;
    } catch (Throwable $e) {}
    $s['accept_rate'] = $s['drafts'] ? (int)round(100 * $s['drafts_ok'] / $s['drafts']) : null;
    // Тихое обучение: насколько ответы робота похожи на настоящие ответы менеджеров.
    try {
        $r = pdo()->query("SELECT COUNT(*) n, AVG(similarity) av, SUM(similarity >= 60) close FROM crm_examples
                           WHERE source='shadow' AND created_at >= NOW() - INTERVAL {$d} DAY")->fetch(PDO::FETCH_ASSOC);
        $s['shadow'] = (int)($r['n'] ?? 0);
        $s['shadow_avg'] = $r['av'] !== null ? (int)round((float)$r['av']) : null;
        $s['shadow_close'] = $s['shadow'] ? (int)round(100 * (int)$r['close'] / $s['shadow']) : null;
        // По смыслу (оценка DeepSeek): средний балл и доля «хороших» (≥ 70).
        $q = pdo()->query("SELECT COUNT(*) n, AVG(score) av, SUM(score >= 70) good FROM crm_examples
                           WHERE source='shadow' AND score IS NOT NULL AND created_at >= NOW() - INTERVAL {$d} DAY")->fetch(PDO::FETCH_ASSOC);
        $s['judged'] = (int)($q['n'] ?? 0);
        $s['judged_avg'] = $q['av'] !== null ? (int)round((float)$q['av']) : null;
        $s['judged_good'] = $s['judged'] ? (int)round(100 * (int)$q['good'] / $s['judged']) : null;
    } catch (Throwable $e) { $s['shadow'] = 0; $s['shadow_avg'] = null; $s['shadow_close'] = null; }
    $s['ready'] = $s['drafts'] >= 20 && ($s['accept_rate'] ?? 0) >= 80;
    return $s;
}
