<?php
declare(strict_types=1);

/**
 * Единая база знаний завода (crm_knowledge) — на хостинге, в базе CRM, с полнотекстовым поиском.
 *
 * Что в ней (пополняется само, kn_build() из cron_all.php каждые 5 минут):
 *   site  — тексты сайта: условия, марки, глоссарий, статьи (crm-data/kb/site-chunks.jsonl);
 *   pair  — «вопрос клиента → ответ менеджера» (crm_examples: из почты, CRM, правок черновиков);
 *   mail  — вся переписка (входящие и отправленные, архив и новая), без спама, рассылок и служебных;
 *           цитаты и подписи срезаны, одинаковые письма рассылки — одной записью;
 *   doc   — распознанные вложения: счета, КП, шильдики, чертежи, ТЗ (тип, суть, модели, позиции);
 *   guide — «учебник ответов»: темы обращений и как на них отвечают наши менеджеры (собран Claude по
 *           всей переписке, tools/knowledge/guide_load.php), без цен и контактов.
 *
 * Поиск kn_find(): полнотекстовый (MATCH … AGAINST) + добор по кодам моделей («R97», «SK 9072»),
 * которые обычный полнотекстовый поиск режет как слишком короткие. Для текстов, которые уходят
 * клиенту (автоответ, робот), суммы вырезаются — цену называет только человек.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/mime.php';

function kn_ensure(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        pdo()->exec("CREATE TABLE IF NOT EXISTS crm_knowledge (
            id INT AUTO_INCREMENT PRIMARY KEY,
            src VARCHAR(8) NOT NULL,                 -- site | pair | mail | doc | guide
            ref VARCHAR(190) NOT NULL,               -- откуда: msg:ID, body:md5, file:sha1, pair:ID, site:N
            lead_id INT NULL,
            title VARCHAR(255) NOT NULL DEFAULT '',
            body MEDIUMTEXT NOT NULL,
            url VARCHAR(255) NOT NULL DEFAULT '',
            who VARCHAR(190) NOT NULL DEFAULT '',    -- контакт / направление
            dt DATETIME NULL,
            UNIQUE KEY uq_ref (ref), KEY idx_src (src), KEY idx_lead (lead_id),
            FULLTEXT KEY ft_all (title, body)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { error_log('kn_ensure: ' . $e->getMessage()); }
}

/** Суммы и цены из текста — для всего, что может уйти клиенту. */
function kn_no_prices(string $t): string {
    $t = preg_replace('/\d[\d\s.,]*\s*(₽|руб\.?|рублей|рубля|р\.|тыс\.?\s*руб|млн)/iu', '[сумма скрыта]', $t) ?? $t;
    return preg_replace('/(цена|стоимость|итого|сумма)\s*[:\-–]?\s*\d[\d\s.,]*/iu', '$1 [скрыто]', $t) ?? $t;
}

function kn_clean_mail(string $t): string {
    $t = mime_strip_quote($t);
    $t = (string)preg_replace('/\n\s*(С уважением|С наилучшими пожеланиями|Best regards|Отправлено из|Sent from)[\s\S]*$/iu', '', $t);
    $t = trim((string)preg_replace("/[ \t]+/u", ' ', (string)preg_replace("/\n{3,}/", "\n\n", $t)));
    return mb_substr($t, 0, 4000, 'UTF-8');
}

/** Вставка/обновление записей пачкой (по уникальному ref). */
function kn_put(array $rows): int {
    if (!$rows) return 0;
    $sql = 'INSERT INTO crm_knowledge (src,ref,lead_id,title,body,url,who,dt) VALUES '
         . implode(',', array_fill(0, count($rows), '(?,?,?,?,?,?,?,?)'))
         . ' ON DUPLICATE KEY UPDATE title=VALUES(title), body=VALUES(body), lead_id=COALESCE(VALUES(lead_id), lead_id), dt=VALUES(dt)';
    $vals = [];
    foreach ($rows as $r) {
        array_push($vals, $r['src'], mb_substr($r['ref'], 0, 190, 'UTF-8'), $r['lead_id'] ?? null,
            mb_substr((string)($r['title'] ?? ''), 0, 255, 'UTF-8'), (string)$r['body'],
            mb_substr((string)($r['url'] ?? ''), 0, 255, 'UTF-8'), mb_substr((string)($r['who'] ?? ''), 0, 190, 'UTF-8'), $r['dt'] ?? null);
    }
    pdo()->prepare($sql)->execute($vals);
    return count($rows);
}

/**
 * Пополнить базу (инкрементально, в пределах $budget секунд). Позиции — в setting kn_state.
 * Возвращает, сколько добавлено по каждому источнику.
 */
function kn_build(int $budget = 60): array {
    kn_ensure();
    $deadline = time() + max(10, $budget);
    $st = json_decode((string)setting('kn_state', '{}'), true) ?: [];
    $out = ['site' => 0, 'pair' => 0, 'mail' => 0, 'doc' => 0];
    $pdo = pdo();

    // Сайт: перезаливка, когда файл кусков изменился (после пересборки tools/site_kb_build.py).
    $f = __DIR__ . '/../crm-data/kb/site-chunks.jsonl';
    if (is_file($f) && (int)($st['site_mtime'] ?? 0) !== (int)filemtime($f)) {
        $pdo->exec("DELETE FROM crm_knowledge WHERE src='site'");
        $fh = fopen($f, 'r'); $n = 0; $batch = [];
        while ($fh && ($line = fgets($fh)) !== false) {
            $r = json_decode($line, true);
            if (!is_array($r) || empty($r['text'])) continue;
            $batch[] = ['src' => 'site', 'ref' => 'site:' . (++$n), 'title' => (string)($r['title'] ?? ''), 'body' => (string)$r['text'],
                        'url' => (string)($r['url'] ?? ''), 'who' => (string)($r['kind'] ?? '')];
            if (count($batch) >= 200) { $out['site'] += kn_put($batch); $batch = []; }
        }
        if ($fh) fclose($fh);
        $out['site'] += kn_put($batch);
        $st['site_mtime'] = (int)filemtime($f);
    }

    // Пары «вопрос → ответ».
    try {
        $rows = $pdo->query('SELECT id, lead_id, subject, in_text, out_text, created_at FROM crm_examples WHERE in_text<>\'\' AND id > ' . (int)($st['pair_id'] ?? 0) . ' ORDER BY id LIMIT 2000')->fetchAll(PDO::FETCH_ASSOC);
        $batch = [];
        foreach ($rows as $r) {
            $batch[] = ['src' => 'pair', 'ref' => 'pair:' . $r['id'], 'lead_id' => $r['lead_id'] ?: null, 'title' => (string)$r['subject'],
                        'body' => "Клиент: " . $r['in_text'] . "\n\nОтвет менеджера: " . $r['out_text'], 'dt' => $r['created_at']];
            $st['pair_id'] = (int)$r['id'];
            if (count($batch) >= 200) { $out['pair'] += kn_put($batch); $batch = []; }
        }
        $out['pair'] += kn_put($batch);
    } catch (Throwable $e) {}

    // Переписка: входящие и отправленные, кроме мусора. Одинаковые тексты (рассылка) — одной записью.
    while (time() < $deadline) {
        $rows = $pdo->query("SELECT id, lead_id, direction, contact, subject, body, created_at, mail_kind FROM crm_messages
                             WHERE channel='email' AND id > " . (int)($st['msg_id'] ?? 0) . "
                             ORDER BY id LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) break;
        $batch = [];
        foreach ($rows as $r) {
            $st['msg_id'] = (int)$r['id'];
            if (in_array((string)$r['mail_kind'], ['spam', 'newsletter', 'service', 'robot'], true)) continue;
            $body = kn_clean_mail((string)$r['body']);
            if (mb_strlen($body, 'UTF-8') < 40) continue;
            $in = $r['direction'] === 'in';
            $batch[] = ['src' => 'mail', 'ref' => $in ? 'msg:' . $r['id'] : 'body:' . md5($body), 'lead_id' => $r['lead_id'] ?: null,
                        'title' => (string)$r['subject'], 'body' => $body, 'who' => ($in ? 'от ' : 'нами → ') . $r['contact'], 'dt' => $r['created_at']];
        }
        $out['mail'] += kn_put($batch);
        setting_set('kn_state', json_encode($st));
    }

    // Документы: распознанные вложения (один на одинаковый файл).
    try {
        $rows = $pdo->query("SELECT MIN(id) id, sha1, MIN(filename) filename, MIN(kind) kind, MIN(summary) summary, MIN(data) data, MIN(lead_id) lead_id,
                                    MIN(direction) direction, MAX(analyzed_at) dt
                             FROM crm_mail_files WHERE status='done' AND analyzed_at > '" . addslashes((string)($st['doc_at'] ?? '1970-01-01')) . "'
                             GROUP BY sha1 ORDER BY dt LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
        $batch = [];
        foreach ($rows as $r) {
            $d = json_decode((string)$r['data'], true) ?: [];
            $parts = [(string)$r['summary']];
            $np = array_filter((array)($d['nameplate'] ?? []));
            if ($np) $parts[] = 'Шильдик: ' . implode(', ', array_map(static fn($k, $v) => "$k $v", array_keys($np), $np));
            if (!empty($d['models'])) $parts[] = 'Модели: ' . implode(', ', array_map('strval', (array)$d['models']));
            $doc = (array)($d['doc'] ?? []);
            foreach (['number' => '№', 'date' => 'от', 'seller' => 'продавец', 'buyer' => 'покупатель', 'inn' => 'ИНН', 'total' => 'итого'] as $k => $l) {
                if (!empty($doc[$k])) $parts[] = "$l {$doc[$k]}";
            }
            foreach ((array)($doc['items'] ?? []) as $it) {
                if (!empty($it['name'])) $parts[] = '• ' . $it['name'] . (!empty($it['qty']) ? ' ×' . $it['qty'] : '') . (!empty($it['price']) ? ' — ' . $it['price'] : '');
            }
            $batch[] = ['src' => 'doc', 'ref' => 'file:' . $r['sha1'], 'lead_id' => $r['lead_id'] ?: null,
                        'title' => $r['filename'] . ' (' . $r['kind'] . ')', 'body' => implode("\n", $parts),
                        'url' => 'file.php?mf=' . $r['id'], 'who' => $r['direction'] === 'out' ? 'отправили мы' : 'прислали нам', 'dt' => $r['dt']];
            $st['doc_at'] = (string)$r['dt'];
        }
        $out['doc'] += kn_put($batch);
    } catch (Throwable $e) {}

    $st['at'] = date('Y-m-d H:i:s');
    setting_set('kn_state', json_encode($st));
    return $out;
}

/**
 * Поиск по базе. $o: src (массив источников), limit, client (true — вырезать суммы), maxChars (на запись).
 * Возвращает [['src','title','body','url','who','dt','lead_id','score'], …].
 */
function kn_find(string $q, array $o = []): array {
    kn_ensure();
    $q = trim($q);
    if ($q === '') return [];
    $limit = max(1, min(50, (int)($o['limit'] ?? 5)));
    $src = array_values(array_intersect((array)($o['src'] ?? ['guide', 'site', 'pair', 'mail', 'doc']), ['guide', 'site', 'pair', 'mail', 'doc']));
    if (!$src) return [];
    $inSrc = "'" . implode("','", $src) . "'";
    // Слова ≥3 букв — в полнотекстовый; коды моделей с цифрами («R97», «SK 9072», «ZR 999») — отдельным LIKE.
    preg_match_all('/[a-zа-яё0-9]{3,}/iu', $q, $m);
    $words = array_slice(array_unique(array_map(static fn($w) => mb_strtolower($w, 'UTF-8'), $m[0])), 0, 12);
    preg_match_all('/\b([a-z]{1,4})[\s\-]?(\d{2,5})\b/iu', $q, $cm, PREG_SET_ORDER);
    $rows = [];
    try {
        if ($words) {
            $st = pdo()->prepare("SELECT id, src, title, body, url, who, dt, lead_id, MATCH(title, body) AGAINST (?) AS score
                                  FROM crm_knowledge WHERE src IN ($inSrc) AND MATCH(title, body) AGAINST (?)
                                  ORDER BY score DESC LIMIT " . ($limit * 3));
            $st->execute([implode(' ', $words), implode(' ', $words)]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $rows[(int)$r['id']] = $r;
        }
        foreach (array_slice($cm, 0, 3) as $c) {
            $st = pdo()->prepare("SELECT id, src, title, body, url, who, dt, lead_id, 5 AS score FROM crm_knowledge
                                  WHERE src IN ($inSrc) AND (body LIKE ? OR body LIKE ? OR title LIKE ?) ORDER BY dt DESC LIMIT " . $limit);
            $st->execute(['%' . $c[1] . ' ' . $c[2] . '%', '%' . $c[1] . $c[2] . '%', '%' . $c[1] . '%' . $c[2] . '%']);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $id = (int)$r['id'];
                $rows[$id] = isset($rows[$id]) ? array_merge($r, ['score' => (float)$rows[$id]['score'] + 5]) : $r;
            }
        }
    } catch (Throwable $e) { error_log('kn_find: ' . $e->getMessage()); return []; }
    usort($rows, static fn($a, $b) => (float)$b['score'] <=> (float)$a['score']);
    $rows = array_slice($rows, 0, $limit);
    $max = (int)($o['maxChars'] ?? 700);
    foreach ($rows as &$r) {
        $b = (string)$r['body'];
        if (!empty($o['client'])) $b = kn_no_prices($b);
        $r['body'] = mb_strlen($b, 'UTF-8') > $max ? mb_substr($b, 0, $max, 'UTF-8') . '…' : $b;
    }
    return $rows;
}

/** Блок для промпта ИИ: найденное в нашей переписке и документах (без сумм). Пусто — ничего не нашлось. */
function kn_prompt_block(string $incoming, int $limit = 3): string {
    $L = [];
    // Сначала «учебник ответов»: как наши менеджеры отвечают на такую тему (главный ориентир стиля и хода ответа).
    $g = kn_find($incoming, ['src' => ['guide'], 'limit' => 2, 'client' => true, 'maxChars' => 1400]);
    if ($g) {
        $L[] = '=== КАК МЫ ОТВЕЧАЕМ НА ТАКИЕ ОБРАЩЕНИЯ (учебник по нашей переписке; цены не называть) ===';
        foreach ($g as $r) $L[] = '[' . $r['title'] . '] ' . $r['body'];
    }
    $rows = kn_find($incoming, ['src' => ['mail', 'doc'], 'limit' => $limit, 'client' => true, 'maxChars' => 500]);
    if ($rows) {
        $L[] = '=== ИЗ НАШЕЙ ПЕРЕПИСКИ И ДОКУМЕНТОВ (как решали похожее; цены не называть) ===';
        foreach ($rows as $r) $L[] = '[' . substr((string)$r['dt'], 0, 10) . ', ' . $r['who'] . '] ' . $r['title'] . ': ' . $r['body'];
    }
    return implode("\n", $L);
}

/** Сводка для админки. */
function kn_stats(): array {
    kn_ensure();
    $s = [];
    foreach (pdo()->query('SELECT src, COUNT(*) c FROM crm_knowledge GROUP BY src') as $r) $s[$r['src']] = (int)$r['c'];
    return $s;
}
