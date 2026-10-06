<?php
declare(strict_types=1);

/**
 * /api/kb_context.php — ЕДИНЫЙ сборщик базы знаний для ИИ.
 *
 * Зачем: до этого файла ИИ читал ТОЛЬКО crm-data/kb.md. Всё, что оператор
 * загружал через админку «База знаний» (admin/knowledge.php → crm-data/kb/),
 * в промпт не попадало вообще, хотя UI обещал обратное. Здесь текст из файлов
 * извлекается и подмешивается в контекст.
 *
 * Что читаем:
 *   txt, md, csv, tsv, json   — как текст напрямую;
 *   xlsx, docx               — через ZipArchive (это zip с XML внутри), без Composer;
 *   pdf, doc, xls, изображения, архивы — НЕ читаем (нужна библиотека/зрение).
 *     Такие файлы перечисляем по именам, чтобы модель знала об их существовании
 *     и могла попросить текстовую версию, а админка — честно показать статус.
 *
 * Публичное API:
 *   kb_context(int $maxChars = 140000): string   — готовый блок «БАЗА ЗНАНИЙ»
 *   kb_files_status(): array                     — что реально читается (для админки)
 *   kb_extract_text(string $path): ?string       — текст одного файла или null
 *
 * Кеш: crm-data/kb/.kb-context.cache. Инвалидация по подписи (имя+размер+mtime
 * каждого файла + kb.md). Разбор xlsx на каждый вызов ИИ был бы расточительным.
 */

require_once __DIR__ . '/helpers.php';

/** Каталог загруженных материалов базы знаний. */
function kb_dir(): string { return __DIR__ . '/../crm-data/kb'; }

/** Главный текстовый файл базы знаний. */
function kb_text_path(): string { return __DIR__ . '/../crm-data/kb.md'; }

/** Расширения, из которых умеем достать текст. */
function kb_readable_ext(): array {
    return ['txt', 'md', 'csv', 'tsv', 'json', 'xlsx', 'docx'];
}

/**
 * Извлечь текст из файла базы знаний. null — формат не поддержан или пусто.
 * Лимит на файл — чтобы один жирный прайс не съел весь контекст.
 */
function kb_extract_text(string $path, int $limit = 40000): ?string {
    if (!is_file($path) || !is_readable($path)) return null;
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    if (in_array($ext, ['txt', 'md', 'csv', 'tsv', 'json'], true)) {
        $raw = (string)@file_get_contents($path, false, null, 0, $limit * 3);
        return kb_clean_text($raw, $limit);
    }
    if ($ext === 'docx') return kb_clean_text(kb_zip_xml_text($path, ['word/document.xml']), $limit);
    if ($ext === 'xlsx') return kb_clean_text(kb_xlsx_text($path, $limit), $limit);

    return null; // pdf/doc/xls/изображения/архивы — не наш формат
}

/** Нормализация: валидный UTF-8, без управляющих символов, схлопнутые пустые строки. */
function kb_clean_text(string $s, int $limit): ?string {
    if ($s === '') return null;
    if (function_exists('mb_check_encoding') && !mb_check_encoding($s, 'UTF-8')) {
        // Прайсы из 1С часто в CP1251 — иначе в промпт уйдёт мусор.
        $conv = @mb_convert_encoding($s, 'UTF-8', 'Windows-1251');
        if (is_string($conv) && $conv !== '') $s = $conv;
    }
    $s = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', ' ', $s);
    $s = (string)preg_replace("/[ \t]+/u", ' ', $s);
    $s = (string)preg_replace("/\n{3,}/u", "\n\n", $s);
    $s = trim($s);
    if ($s === '') return null;
    if (function_exists('mb_strlen') && mb_strlen($s, 'UTF-8') > $limit) {
        $s = mb_substr($s, 0, $limit, 'UTF-8') . "\n…[файл обрезан]";
    }
    return $s;
}

/**
 * Снять namespace-префиксы с тегов XML (<x:row> → <row>, </w:p> → </p>).
 * РЕАЛЬНЫЕ файлы это требуют: выгрузки из Google Sheets и Open XML SDK пишут
 * листы как <x:worksheet><x:sheetData><x:row>, и разбор «по <row» находил ноль строк.
 */
function kb_strip_ns(string $xml): string {
    return (string)preg_replace('~<(/?)[A-Za-z][A-Za-z0-9]*:~', '<$1', $xml);
}

/** Текст из XML-частей zip-контейнера (docx). */
function kb_zip_xml_text(string $path, array $entries): string {
    if (!class_exists('ZipArchive')) return '';
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return '';
    $out = '';
    foreach ($entries as $entry) {
        $xml = $zip->getFromName($entry);
        if ($xml === false) continue;
        $xml = kb_strip_ns($xml);
        // Абзацы и переводы строк — в \n, остальные теги выкидываем.
        $xml = (string)preg_replace('~</p>|<br\s*/>~', "\n", $xml);
        $out .= html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8') . "\n";
    }
    $zip->close();
    return $out;
}

/**
 * Текст из xlsx: sharedStrings + значения ячеек по всем листам, строка = строка таблицы.
 * Листы перечисляем ПО СОДЕРЖИМОМУ архива, а не угадываем sheet1..sheetN: имена
 * частей у разных генераторов различаются. Поддержаны t="s" (общая таблица строк),
 * t="inlineStr" (<is><t>) и t="str" (результат формулы).
 */
function kb_xlsx_text(string $path, int $limit): string {
    if (!class_exists('ZipArchive')) return '';
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return '';

    // 1) Общая таблица строк.
    $shared = [];
    $ss = $zip->getFromName('xl/sharedStrings.xml');
    if ($ss !== false) {
        $ss = kb_strip_ns($ss);
        if (preg_match_all('~<si>(.*?)</si>~s', $ss, $m)) {
            foreach ($m[1] as $si) {
                $txt = '';
                if (preg_match_all('~<t[^>]*>(.*?)</t>~s', $si, $tm)) $txt = implode('', $tm[1]);
                $shared[] = html_entity_decode($txt, ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }
    }

    // 2) Названия листов (для читаемых заголовков в контексте).
    $titles = [];
    $wbXml = $zip->getFromName('xl/workbook.xml');
    if ($wbXml !== false && preg_match_all('~<(?:[A-Za-z0-9]+:)?sheet\b[^>]*\sname="([^"]*)"~', $wbXml, $tm)) {
        $titles = $tm[1];
    }

    // 3) Все листы из архива, по порядку номера в имени.
    $sheets = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string)$zip->getNameIndex($i);
        if (preg_match('~^xl/worksheets/[^/]+\.xml$~', $name)) $sheets[] = $name;
    }
    natsort($sheets);

    $out = ''; $idx = 0;
    foreach ($sheets as $name) {
        $sheet = $zip->getFromName($name);
        if ($sheet === false) { $idx++; continue; }
        $sheet = kb_strip_ns($sheet);
        $title = $titles[$idx] ?? ('Лист ' . ($idx + 1));
        $idx++;
        if (!preg_match_all('~<row[^>]*>(.*?)</row>~s', $sheet, $rows)) continue;
        $block = '';
        foreach ($rows[1] as $row) {
            $cells = [];
            // Атрибуты ячейки берём одним куском и разбираем отдельно: попытка выцепить
            // t="s" прямо в этом шаблоне не работает — [^>]* съедает атрибут целиком,
            // и все строковые ячейки остаются числовыми индексами sharedStrings.
            if (preg_match_all('~<c\b(?<attr>[^>]*)>(?<in>.*?)</c>~s', $row, $cm, PREG_SET_ORDER)) {
                foreach ($cm as $c) {
                    $type = preg_match('~\bt="([^"]+)"~', $c['attr'], $tm2) ? $tm2[1] : '';
                    $val = '';
                    if ($type === 'inlineStr') {
                        if (preg_match_all('~<t[^>]*>(.*?)</t>~s', $c['in'], $vm)) $val = implode('', $vm[1]);
                    } elseif (preg_match('~<v>(.*?)</v>~s', $c['in'], $vm)) {
                        $val = $vm[1];
                    } elseif (preg_match('~<t[^>]*>(.*?)</t>~s', $c['in'], $vm)) {
                        $val = $vm[1];
                    }
                    if ($type === 's' && $val !== '' && ctype_digit($val) && isset($shared[(int)$val])) {
                        $val = $shared[(int)$val];
                    }
                    $val = trim(html_entity_decode($val, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                    if ($val !== '') $cells[] = $val;
                }
            }
            if ($cells) $block .= implode(' | ', $cells) . "\n";
            if (strlen($out) + strlen($block) > $limit * 3) break;
        }
        if (trim($block) !== '') $out .= "-- лист «" . $title . "» --\n" . $block . "\n";
        if (strlen($out) > $limit * 3) break;
    }
    $zip->close();
    return $out;
}

/**
 * Что лежит в базе знаний и что из этого ИИ реально читает.
 * Возвращает [['name','ext','size','readable'(bool),'chars'(int)], …].
 * Используется админкой, чтобы не обещать оператору лишнего.
 */
function kb_files_status(): array {
    $dir = kb_dir();
    if (!is_dir($dir)) return [];
    $res = [];
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..' || $f[0] === '.') continue;
        $p = $dir . '/' . $f;
        if (!is_file($p)) continue;
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        $readable = in_array($ext, kb_readable_ext(), true);
        $chars = 0;
        if ($readable) {
            $t = kb_extract_text($p);
            $chars = $t === null ? 0 : (function_exists('mb_strlen') ? mb_strlen($t, 'UTF-8') : strlen($t));
            if ($chars === 0) $readable = false; // формат наш, а текста не достали
        }
        $res[] = ['name' => $f, 'ext' => $ext, 'size' => (int)filesize($p),
                  'readable' => $readable, 'chars' => $chars];
    }
    usort($res, static fn($a, $b) => strcmp($a['name'], $b['name']));
    return $res;
}

/** Подпись состояния базы знаний — для инвалидации кеша. */
function kb_signature(): string {
    $parts = [];
    $main = kb_text_path();
    if (is_file($main)) $parts[] = 'kb.md:' . filesize($main) . ':' . filemtime($main);
    $dir = kb_dir();
    if (is_dir($dir)) {
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..' || $f[0] === '.') continue;
            $p = $dir . '/' . $f;
            if (is_file($p)) $parts[] = $f . ':' . filesize($p) . ':' . filemtime($p);
        }
    }
    return md5(implode('|', $parts));
}

/**
 * Готовый блок базы знаний для системного промпта.
 * Порядок: kb.md (главное) → текст загруженных файлов → перечень нечитаемых файлов.
 * $maxChars — потолок на весь блок (защита от разрастания цены запроса).
 */
function kb_context(int $maxChars = 140000): string {
    // Мемо по лимиту: автоответ просит короткий контекст, подбор — полный.
    // Один общий кеш отдавал бы обрезанную базу полноценному подбору.
    static $memo = [];
    if (isset($memo[$maxChars])) return $memo[$maxChars];

    $sig   = kb_signature();
    // Кеш на диске держим ОДИН — полной сборкой; под меньший лимит просто обрезаем.
    $cache = kb_dir() . '/.kb-context.cache';
    if (is_file($cache)) {
        $raw = (string)@file_get_contents($cache);
        $nl  = strpos($raw, "\n");
        if ($nl !== false && substr($raw, 0, $nl) === $sig) {
            $memo[$maxChars] = kb_cap(substr($raw, $nl + 1), $maxChars);
            return $memo[$maxChars];
        }
    }

    $out = '';
    $main = is_file(kb_text_path()) ? (string)file_get_contents(kb_text_path()) : '';
    if (trim($main) !== '') $out .= trim($main) . "\n";

    $unreadable = [];
    foreach (kb_files_status() as $f) {
        if (!$f['readable']) { $unreadable[] = $f['name']; continue; }
        $text = kb_extract_text(kb_dir() . '/' . $f['name']);
        if ($text === null) { $unreadable[] = $f['name']; continue; }
        $out .= "\n\n=== ФАЙЛ: {$f['name']} ===\n" . $text;
    }
    if ($unreadable) {
        $out .= "\n\n=== ФАЙЛЫ БЕЗ ТЕКСТА (не прочитаны, ссылаться на их содержимое нельзя) ===\n"
              . implode(', ', $unreadable) . "\n";
    }

    $out = trim($out);

    if (is_dir(kb_dir()) && is_writable(kb_dir())) {
        @file_put_contents($cache, $sig . "\n" . $out, LOCK_EX);
    }
    $memo[$maxChars] = kb_cap($out, $maxChars);
    return $memo[$maxChars];
}

/** Обрезать блок базы знаний по лимиту символов с честной пометкой. */
function kb_cap(string $s, int $maxChars): string {
    if (!function_exists('mb_strlen') || mb_strlen($s, 'UTF-8') <= $maxChars) return $s;
    return mb_substr($s, 0, $maxChars, 'UTF-8') . "\n…[база знаний обрезана по лимиту контекста]";
}
