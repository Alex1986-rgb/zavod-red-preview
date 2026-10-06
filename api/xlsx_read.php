<?php
declare(strict_types=1);

/**
 * /api/xlsx_read.php — СТРУКТУРНОЕ чтение xlsx (строки и колонки), без Composer.
 *
 * Отличие от kb_context.php: тот собирает из таблицы плоский ТЕКСТ для промпта и
 * выбрасывает пустые ячейки. Для импорта так нельзя — пропуск пустой ячейки сдвигает
 * все следующие колонки, и телефон уезжает в поле email. Здесь позиция берётся из
 * ссылки ячейки (r="C7"), пропуски заполняются пустыми строками.
 *
 *   xlsx_sheets(string $path): array          — список листов ['title'=>..,'entry'=>..]
 *   xlsx_rows(string $path, $sheet=0): array  — [[c0,c1,…], …]; $sheet = индекс или имя
 */

/** Снять namespace-префиксы тегов (<x:row> → <row>). */
function xlsx_strip_ns(string $xml): string {
    return (string)preg_replace('~<(/?)[A-Za-z][A-Za-z0-9]*:~', '<$1', $xml);
}

/** «C» → 2, «AB» → 27. Индекс колонки с нуля. */
function xlsx_col_index(string $ref): int {
    if (!preg_match('/^([A-Z]+)/', strtoupper($ref), $m)) return 0;
    $n = 0;
    foreach (str_split($m[1]) as $ch) $n = $n * 26 + (ord($ch) - 64);
    return $n - 1;
}

/** Список листов в порядке книги. */
function xlsx_sheets(string $path): array {
    if (!class_exists('ZipArchive')) return [];
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return [];
    $titles = [];
    $wb = $zip->getFromName('xl/workbook.xml');
    if ($wb !== false && preg_match_all('~<(?:[A-Za-z0-9]+:)?sheet\b[^>]*\sname="([^"]*)"~', $wb, $m)) {
        $titles = array_map(static fn($t) => html_entity_decode($t, ENT_QUOTES | ENT_XML1, 'UTF-8'), $m[1]);
    }
    $entries = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $n = (string)$zip->getNameIndex($i);
        if (preg_match('~^xl/worksheets/[^/]+\.xml$~', $n)) $entries[] = $n;
    }
    natsort($entries);
    $entries = array_values($entries);
    $zip->close();

    $out = [];
    foreach ($entries as $i => $e) {
        $out[] = ['title' => $titles[$i] ?? ('Лист ' . ($i + 1)), 'entry' => $e, 'index' => $i];
    }
    return $out;
}

/** Общая таблица строк книги. */
function xlsx_shared(ZipArchive $zip): array {
    $shared = [];
    $ss = $zip->getFromName('xl/sharedStrings.xml');
    if ($ss === false) return $shared;
    $ss = xlsx_strip_ns($ss);
    if (preg_match_all('~<si>(.*?)</si>~s', $ss, $m)) {
        foreach ($m[1] as $si) {
            $txt = '';
            if (preg_match_all('~<t[^>]*>(.*?)</t>~s', $si, $tm)) $txt = implode('', $tm[1]);
            $shared[] = html_entity_decode($txt, ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
    }
    return $shared;
}

/**
 * Строки листа. $sheet — индекс (0-based) или точное имя листа.
 * Пустые строки сохраняются как пустые массивы, чтобы номера строк не съезжали.
 */
function xlsx_rows(string $path, $sheet = 0, int $maxRows = 100000): array {
    if (!class_exists('ZipArchive')) return [];
    $sheets = xlsx_sheets($path);
    if (!$sheets) return [];

    $entry = null;
    foreach ($sheets as $s) {
        if (is_int($sheet) ? $s['index'] === $sheet : $s['title'] === $sheet) { $entry = $s['entry']; break; }
    }
    if ($entry === null) return [];

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return [];
    $shared = xlsx_shared($zip);
    $xml = $zip->getFromName($entry);
    $zip->close();
    if ($xml === false) return [];
    $xml = xlsx_strip_ns($xml);

    $rows = [];
    if (!preg_match_all('~<row\b([^>]*)>(.*?)</row>~s', $xml, $rm, PREG_SET_ORDER)) return [];
    foreach ($rm as $r) {
        if (count($rows) >= $maxRows) break;
        $cells = [];
        if (preg_match_all('~<c\b(?<attr>[^>]*)(?:/>|>(?<in>.*?)</c>)~s', $r[2], $cm, PREG_SET_ORDER)) {
            foreach ($cm as $c) {
                $attr = $c['attr'];
                $in   = $c['in'] ?? '';
                $ref  = preg_match('~\br="([A-Z]+\d+)"~i', $attr, $rr) ? $rr[1] : '';
                $type = preg_match('~\bt="([^"]+)"~', $attr, $tt) ? $tt[1] : '';
                $val  = '';
                if ($type === 'inlineStr') {
                    if (preg_match_all('~<t[^>]*>(.*?)</t>~s', $in, $vm)) $val = implode('', $vm[1]);
                } elseif (preg_match('~<v>(.*?)</v>~s', $in, $vm)) {
                    $val = $vm[1];
                } elseif (preg_match('~<t[^>]*>(.*?)</t>~s', $in, $vm)) {
                    $val = $vm[1];
                }
                if ($type === 's' && $val !== '' && ctype_digit($val) && isset($shared[(int)$val])) {
                    $val = $shared[(int)$val];
                }
                $val = html_entity_decode($val, ENT_QUOTES | ENT_XML1, 'UTF-8');
                $idx = $ref !== '' ? xlsx_col_index($ref) : count($cells);
                // Пропуски заполняем — иначе колонки съезжают и телефон попадает в email.
                while (count($cells) < $idx) $cells[] = '';
                $cells[$idx] = trim($val);
            }
        }
        $rows[] = $cells;
    }
    return $rows;
}
