<?php
/**
 * Память по данным сайта: справочник моделей + поиск по пояснениям.
 *
 * Зачем: kb_context() кладёт в промпт всю базу целиком — до 140 тыс. знаков на
 * каждое письмо. Это дорого и мешает модели: нужный абзац тонет среди прочего.
 * Здесь два узких инструмента вместо одного широкого.
 *
 *   site_lookup_models($text)  — какие импортные модели названы в письме и какой
 *                                ZR им соответствует. ТОЛЬКО из справочника,
 *                                собранного с карточек сайта. Ничего не додумывает.
 *   site_kb_search($q, $k)     — 2-3 куска пояснений (условия, шильдики, термины).
 *
 * Данные готовит tools/site_catalog_index.py и tools/site_kb_build.py.
 * Если файлов нет — обе функции возвращают пустой результат, вызывающий код
 * должен работать и без них.
 */

function site_series_path(): string { return __DIR__ . '/../crm-data/site_series.json'; }
function site_chunks_path(): string { return __DIR__ . '/../crm-data/kb/site-chunks.jsonl'; }
function site_examples_path(): string { return __DIR__ . '/../crm-data/kb/examples.jsonl'; }

/**
 * Примеры «типовое обращение → как отвечаем», собранные из реальной переписки
 * (tools/build_examples.py, обращения анонимизированы). Модель берёт отсюда
 * стиль и длину ответа, а не факты.
 */
function site_examples(string $incoming, int $limit = 2): array {
    $p = site_examples_path();
    if (!is_file($p)) return [];
    $rows = [];
    foreach (file($p, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $r = json_decode($line, true);
        if (is_array($r) && !empty($r['incoming'])) $rows[] = $r;
    }
    if (!$rows) return [];

    $q = array_flip(array_filter(site_tokens($incoming), static fn($t) => mb_strlen($t) > 2));
    $scored = [];
    foreach ($rows as $r) {
        $tokens = site_tokens($r['incoming'] . ' ' . ($r['subject'] ?? ''));
        if (!$tokens) continue;
        $hit = 0;
        foreach ($tokens as $t) if (isset($q[$t])) $hit++;
        if ($hit) $scored[] = ['s' => $hit / sqrt(count($tokens)), 'r' => $r];
    }
    if (!$scored) return [];
    usort($scored, static fn($a, $b) => $b['s'] <=> $a['s']);
    return array_map(static fn($x) => $x['r'], array_slice($scored, 0, $limit));
}

/**
 * «R 97», «R97» — одно обозначение. Плюс снимаем ведущие нули в типоразмере:
 * на шильдиках пишут «SRT050», а в каталоге серия называется «SRT 50»
 * (реальный случай с фото заявки — без этого замена не находилась).
 */
function site_compact(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    $s = preg_replace('/[^a-zа-яё0-9]+/u', '', $s) ?? '';
    return preg_replace('/(?<=[a-zа-яё])0+(?=\d)/u', '', $s) ?? $s;
}

function site_norm(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    return preg_replace('/[\s\-_\/]+/u', ' ', $s) ?? '';
}

/** Справочник серий (750 серий, ~100 КБ) — кешируется в статике на запрос. */
function site_series(): array {
    static $data = null;
    if ($data !== null) return $data;
    $data = ['brands' => [], 'series' => []];
    $p = site_series_path();
    if (is_file($p)) {
        $j = json_decode((string)file_get_contents($p), true);
        if (is_array($j)) $data = $j + $data;
    }
    return $data;
}

/**
 * Модели, названные в тексте, с подтверждённым ZR.
 * Возвращает [['brand','model','zr'(array),'type','kw','variants','confirmed'(bool)], …].
 * Если марка знакома, а серии нет — вернёт confirmed=false: это сигнал ответить
 * «проверит инженер», а не выдумывать соответствие.
 */
function site_lookup_models(string $text, int $limit = 3): array {
    $d = site_series();
    if (!$d['series']) return [];
    $t  = site_norm($text);
    $tc = site_compact($text);

    $hits = [];
    foreach ($d['series'] as $s) {
        $brand = site_norm((string)$s['brand']);
        if ($brand === '' || mb_strpos($t, $brand) === false) continue;
        $model = site_norm((string)$s['model']);
        if ($model === '') continue;
        foreach (explode(',', $model) as $alias) {
            $alias = trim($alias);
            if ($alias === '') continue;
            $ac = site_compact($alias);
            if (mb_strpos($t, $alias) !== false || (mb_strlen($ac) >= 3 && mb_strpos($tc, $ac) !== false)) {
                $hits[] = ['len' => mb_strlen($ac), 'row' => $s];
                break;
            }
        }
    }
    if ($hits) {
        usort($hits, static fn($a, $b) => $b['len'] <=> $a['len']);
        $out = [];
        $seen = [];
        foreach ($hits as $h) {
            $s = $h['row'];
            $key = $s['brand'] . '|' . $s['model'];
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $kw = '';
            if ($s['kw_min'] !== null) {
                $kw = rtrim(rtrim(number_format((float)$s['kw_min'], 2, '.', ''), '0'), '.')
                    . '–' . rtrim(rtrim(number_format((float)$s['kw_max'], 2, '.', ''), '0'), '.');
            }
            $out[] = [
                'brand' => $s['brand'], 'model' => $s['model'], 'zr' => $s['zr'],
                'type' => $s['type'], 'kw' => $kw, 'variants' => (int)$s['variants'],
                'confirmed' => !empty($s['zr']),
            ];
            if (count($out) >= $limit) break;
        }
        return $out;
    }

    foreach ($d['brands'] as $brand) {
        if (mb_strpos($t, site_norm((string)$brand)) !== false) {
            return [[
                'brand' => $brand, 'model' => '', 'zr' => [], 'type' => '',
                'kw' => '', 'variants' => 0, 'confirmed' => false,
            ]];
        }
    }
    return [];
}

/** Токены запроса с грубой нормализацией окончаний (корпус узкий, технический). */
function site_tokens(string $s): array {
    $s = mb_strtolower($s, 'UTF-8');
    preg_match_all('/[a-zа-яё0-9]+/u', $s, $m);
    $out = [];
    foreach ($m[0] as $t) {
        if (mb_strlen($t) > 6 && !ctype_digit($t)) $t = mb_substr($t, 0, 6, 'UTF-8');
        $out[] = $t;
    }
    return $out;
}

/**
 * Поиск по корпусу сайта (условия, марки, глоссарий, статьи).
 * Читаем построчно и скорим на лету: файл ~12 МБ, держать его в памяти PHP незачем.
 * Возвращает [['title','url','text'], …] суммарно не длиннее $maxChars.
 */
function site_kb_search(string $query, int $k = 3, int $maxChars = 2400): array {
    $p = site_chunks_path();
    if (!is_file($p)) return [];
    $q = array_unique(array_filter(site_tokens($query), static fn($t) => mb_strlen($t) > 2));
    if (!$q) return [];
    $qset = array_flip($q);

    // условия работы завода весомее статьи блога на ту же тему
    $weight = ['core' => 1.8, 'info' => 1.6, 'markings' => 1.5, 'brand' => 1.2,
               'glossary' => 1.1, 'blog' => 1.0];

    $best = [];
    $fh = fopen($p, 'r');
    if (!$fh) return [];
    while (($line = fgets($fh)) !== false) {
        if ($line === '' || $line[0] !== '{') continue;
        // дешёвый предварительный отсев: если ни одного слова запроса нет в строке
        $low = mb_strtolower($line, 'UTF-8');
        $any = false;
        foreach ($q as $t) { if (mb_strpos($low, $t) !== false) { $any = true; break; } }
        if (!$any) continue;

        $rec = json_decode($line, true);
        if (!is_array($rec) || empty($rec['text'])) continue;
        $tokens = site_tokens(($rec['title'] ?? '') . ' ' . $rec['text']);
        if (!$tokens) continue;
        $tf = 0;
        foreach ($tokens as $t) { if (isset($qset[$t])) $tf++; }
        if (!$tf) continue;
        // нормируем на длину, чтобы длинные куски не выигрывали автоматически
        $score = ($tf / sqrt(count($tokens))) * ($weight[$rec['kind'] ?? ''] ?? 1.0);
        $best[] = ['score' => $score, 'rec' => $rec];
    }
    fclose($fh);
    if (!$best) return [];

    usort($best, static fn($a, $b) => $b['score'] <=> $a['score']);
    $out = [];
    $used = 0;
    foreach (array_slice($best, 0, $k) as $b) {
        $text = (string)$b['rec']['text'];
        if ($used + mb_strlen($text) > $maxChars) {
            $text = mb_substr($text, 0, max(0, $maxChars - $used), 'UTF-8');
        }
        if (trim($text) === '') break;
        $out[] = ['title' => (string)($b['rec']['title'] ?? ''),
                  'url' => (string)($b['rec']['url'] ?? ''), 'text' => $text];
        $used += mb_strlen($text);
    }
    return $out;
}

/**
 * Готовый блок для системного промпта: справочник + пояснения по тексту обращения.
 * Пусто, если данных сайта нет — тогда вызывающий код остаётся на старом kb_context().
 */
function site_context(string $incoming, int $maxChars = 3000): string {
    $parts = [];

    $models = site_lookup_models($incoming);
    if ($models) {
        $lines = ["=== СПРАВОЧНИК ЗАВОДА (единственный источник ZR-кодов) ==="];
        foreach ($models as $m) {
            if ($m['confirmed']) {
                $lines[] = sprintf(
                    '%s %s → замена %s (%s, исполнения %s кВт, вариантов %d)',
                    $m['brand'], $m['model'], implode(', ', $m['zr']), $m['type'], $m['kw'], $m['variants']
                );
            } else {
                $lines[] = $m['brand'] . ': марка известна, но названной серии в справочнике НЕТ. '
                         . 'Соответствие не подтверждай — напиши, что проверит инженер.';
            }
        }
        $parts[] = implode("\n", $lines);
    }

    // Сначала живые примеры из переписки менеджеров (api/learn.php — пополняются сами),
    // затем стартовый набор из examples.jsonl.
    $ex = [];
    if (function_exists('learn_examples')) $ex = learn_examples($incoming, 2);
    foreach (site_examples($incoming, 2) as $e) { if (count($ex) >= 3) break; $ex[] = $e; }
    if ($ex) {
        $lines = ['=== КАК МЫ ОТВЕЧАЕМ НА ПОХОЖИЕ ОБРАЩЕНИЯ (образец стиля, не источник фактов) ==='];
        foreach ($ex as $e) {
            $lines[] = 'Клиент: ' . mb_substr((string)$e['incoming'], 0, 300, 'UTF-8');
            $lines[] = 'Ответ: ' . (string)$e['reply'];
        }
        $parts[] = implode("\n", $lines);
    }

    // Единая база знаний: как мы уже решали похожее в переписке и документах (суммы вырезаны).
    try {
        require_once __DIR__ . '/knowledge.php';
        $kn = kn_prompt_block($incoming, 3);
        if ($kn !== '') $parts[] = $kn;
    } catch (Throwable $e) { /* базы ещё нет — отвечаем без неё */ }

    $found = site_kb_search($incoming, 3, $maxChars);
    if ($found) {
        $lines = ["=== ПОЯСНЕНИЯ С САЙТА (можно пересказать своими словами) ==="];
        foreach ($found as $f) {
            $lines[] = '[' . ($f['title'] !== '' ? $f['title'] : 'без названия') . '] ' . $f['text'];
        }
        $lines[] = 'ВНИМАНИЕ: это тексты с сайта, в них попадаются обещания цены, сроков и наличия. '
                 . 'Не повторяй их — запрет выше сильнее.';
        $parts[] = implode("\n", $lines);
    }

    return implode("\n\n", $parts);
}
