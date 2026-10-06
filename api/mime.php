<?php
declare(strict_types=1);

/**
 * Разбор письма (RFC 822 / MIME) на чистом PHP — без расширения imap.
 *
 * Зачем свой: расширение imap убрано из PHP 8.4 и есть не на каждом хостинге, а
 * прежний mail_poll() вложения вообще не сохранял — чертежи, счета и фото шильдиков
 * из писем до ИИ не доходили. Здесь письмо разбирается целиком: заголовки, текст,
 * HTML и все вложения (включая вложенные письма и картинки в теле).
 *
 * mime_parse($raw) → [
 *   'headers'     => ['from'=>…, 'to'=>…, …] (имена в нижнем регистре, значения декодированы),
 *   'from_email', 'from_name', 'to' (список адресов), 'cc', 'subject',
 *   'message_id', 'in_reply_to', 'references', 'date' (Y-m-d H:i:s или ''),
 *   'text' (лучший текст: plain, иначе из HTML), 'html',
 *   'attachments' => [['filename','mime','data','size','inline'], …],
 *   'auto' => ['auto_submitted'=>bool,'precedence_bulk'=>bool,'list_id'=>bool],
 * ]
 */

/** Разделить сырое письмо на блок заголовков и тело. */
function mime_split(string $raw): array {
    $raw = str_replace("\r\n", "\n", $raw);
    $p = strpos($raw, "\n\n");
    if ($p === false) return [$raw, ''];
    return [substr($raw, 0, $p), substr($raw, $p + 2)];
}

/** Заголовки → массив имя(нижний регистр) => [значения] с развёрнутыми переносами. */
function mime_headers(string $block): array {
    $block = preg_replace("/\n[ \t]+/", ' ', str_replace("\r\n", "\n", $block)) ?? $block;
    $out = [];
    foreach (explode("\n", $block) as $line) {
        $c = strpos($line, ':');
        if ($c === false || $c === 0) continue;
        $name = strtolower(trim(substr($line, 0, $c)));
        $out[$name][] = trim(substr($line, $c + 1));
    }
    return $out;
}

function mime_h(array $h, string $name): string {
    return (string)($h[strtolower($name)][0] ?? '');
}

/** Перекодировать строку в UTF-8 из указанной кодировки (windows-1251, koi8-r…). */
function mime_to_utf8(string $s, string $charset): string {
    $cs = strtolower(trim($charset, " \t\"'"));
    if ($cs === '' || $cs === 'utf-8' || $cs === 'utf8' || $cs === 'us-ascii') {
        return mb_check_encoding($s, 'UTF-8') ? $s : (mb_convert_encoding($s, 'UTF-8', 'Windows-1251') ?: $s);
    }
    $map = ['cp1251' => 'Windows-1251', 'win-1251' => 'Windows-1251', 'windows-1251' => 'Windows-1251',
            'koi8-r' => 'KOI8-R', 'koi8-u' => 'KOI8-U', 'iso-8859-5' => 'ISO-8859-5', 'cp866' => 'CP866',
            'iso-8859-1' => 'ISO-8859-1', 'latin1' => 'ISO-8859-1', 'windows-1252' => 'Windows-1252'];
    $from = $map[$cs] ?? $charset;
    try {
        $r = @mb_convert_encoding($s, 'UTF-8', $from);
        if (is_string($r) && $r !== '') return $r;
    } catch (Throwable $e) { /* неизвестная кодировка */ }
    if (function_exists('iconv')) {
        $r = @iconv($from, 'UTF-8//IGNORE', $s);
        if (is_string($r) && $r !== '') return $r;
    }
    return $s;
}

/** Декодировать заголовок с «=?utf-8?B?…?=» (RFC 2047), в т.ч. несколько слов подряд. */
function mime_decode_header(string $v): string {
    if (strpos($v, '=?') === false) return mime_to_utf8($v, '');
    // Между двумя закодированными словами пробелы не значимы.
    $v = preg_replace('/(\?=)\s+(=\?)/', '$1$2', $v) ?? $v;
    return (string)preg_replace_callback('/=\?([^?]+)\?([BbQq])\?([^?]*)\?=/', static function ($m) {
        $cs = preg_replace('/\*.*$/', '', $m[1]) ?? $m[1]; // «utf-8*ru» → utf-8
        $data = strtoupper($m[2]) === 'B'
            ? (string)base64_decode($m[3])
            : quoted_printable_decode(str_replace('_', ' ', $m[3]));
        return mime_to_utf8($data, $cs);
    }, $v);
}

/** «Content-Type: text/plain; charset="utf-8"; name=…» → ['value'=>'text/plain','charset'=>…]. */
function mime_params(string $v): array {
    $out = ['value' => strtolower(trim((string)strtok($v, ';')))];
    // Параметры, включая RFC 2231: filename*=utf-8''%D0%A1… и продолжения filename*0*=…
    preg_match_all('/;\s*([A-Za-z0-9_\-\*]+)\s*=\s*("(?:[^"\\\\]|\\\\.)*"|[^;]*)/', $v, $m, PREG_SET_ORDER);
    $cont = [];
    foreach ($m as $p) {
        $k = strtolower($p[1]);
        $val = trim($p[2]);
        if (strlen($val) >= 2 && $val[0] === '"') $val = stripcslashes(substr($val, 1, -1));
        if (preg_match('/^(.+?)\*(\d+)\*?$/', $k, $km)) {       // продолжение
            $cont[$km[1]][(int)$km[2]] = [$val, str_ends_with($k, '*')];
            continue;
        }
        if (str_ends_with($k, '*')) {                            // RFC 2231 одним куском
            $out[rtrim($k, '*')] = mime_rfc2231($val, true);
            continue;
        }
        $out[$k] = mime_decode_header($val);
    }
    foreach ($cont as $k => $parts) {
        ksort($parts);
        $charset = ''; $buf = '';
        foreach ($parts as $i => [$val, $enc]) {
            if ($i === 0 && $enc && preg_match("/^([^']*)'[^']*'(.*)$/s", $val, $mm)) { $charset = $mm[1]; $val = $mm[2]; }
            $buf .= $enc ? rawurldecode($val) : $val;
        }
        $out[$k] = mime_to_utf8($buf, $charset);
    }
    return $out;
}

function mime_rfc2231(string $val, bool $encoded): string {
    if ($encoded && preg_match("/^([^']*)'[^']*'(.*)$/s", $val, $m)) {
        return mime_to_utf8(rawurldecode($m[2]), $m[1]);
    }
    return mime_decode_header($val);
}

/** Раскодировать тело части по Content-Transfer-Encoding. */
function mime_decode_body(string $body, string $cte): string {
    switch (strtolower(trim($cte))) {
        case 'base64':           return (string)base64_decode(preg_replace('/\s+/', '', $body) ?? '');
        case 'quoted-printable': return quoted_printable_decode($body);
        default:                 return $body;
    }
}

/** Адреса из «Имя <a@b.ru>, c@d.ru» → [['email','name'], …]. */
function mime_addresses(string $v): array {
    $v = mime_decode_header($v);
    $out = [];
    // Разбиваем по запятым вне кавычек и угловых скобок.
    $parts = preg_split('/,(?=(?:[^"]*"[^"]*")*[^"]*$)(?![^<]*>)/', $v) ?: [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === '') continue;
        if (preg_match('/^(.*)<\s*([^>]+?)\s*>$/', $p, $m)) {
            $email = strtolower(trim($m[2]));
            $name = trim(trim($m[1]), " \"'");
        } else {
            $email = strtolower(trim($p, " <>\"'"));
            $name = '';
        }
        if (strpos($email, '@') !== false) $out[] = ['email' => $email, 'name' => $name];
    }
    return $out;
}

/** Грубый, но безопасный HTML → текст (для писем без text/plain). */
function mime_html_to_text(string $html): string {
    $html = preg_replace('~<(script|style|head)\b[^>]*>.*?</\1>~is', '', $html) ?? $html;
    $html = preg_replace('~<br\s*/?>|</(p|div|tr|li|h[1-6]|table)>~i', "\n", $html) ?? $html;
    $txt = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $txt = preg_replace("/[ \t\x{00A0}]+/u", ' ', $txt) ?? $txt;
    $txt = preg_replace("/\n\s*\n\s*\n+/", "\n\n", $txt) ?? $txt;
    return trim($txt);
}

/** Рекурсивный обход частей: собирает plain, html и вложения. */
function mime_walk(string $headBlock, string $body, array &$acc, int $depth = 0, bool $textOnly = false): void {
    if ($depth > 12) return; // защита от патологической вложенности
    $h = mime_headers($headBlock);
    $ct = mime_params(mime_h($h, 'content-type') ?: 'text/plain; charset=us-ascii');
    $type = $ct['value'] ?: 'text/plain';
    $cd = mime_params(mime_h($h, 'content-disposition'));
    $cte = mime_h($h, 'content-transfer-encoding');

    if (str_starts_with($type, 'multipart/')) {
        $b = $ct['boundary'] ?? '';
        if ($b === '') return;
        $body = str_replace("\r\n", "\n", $body);
        $chunks = explode("\n--" . $b, "\n" . $body);
        array_shift($chunks); // преамбула
        foreach ($chunks as $chunk) {
            if (str_starts_with($chunk, '--')) break; // закрывающая граница
            $chunk = (string)preg_replace('/^[ \t]*\n/', '', $chunk);
            [$ph, $pb] = mime_split($chunk);
            mime_walk($ph, $pb, $acc, $depth + 1, $textOnly);
        }
        return;
    }

    $filename0 = (string)($cd['filename'] ?? ($ct['name'] ?? ''));
    // Только текст (обучение на архиве): вложения не раскодируем — иначе письмо с чертежами на 30 МБ
    // съедает память (на Beget 256 МБ кончились на партии писем, 29.09.2026).
    if ($textOnly && !(($type === 'text/plain' || $type === 'text/html' || $type === 'message/rfc822') && $filename0 === '')) {
        $acc['attachments'][] = ['filename' => $filename0 !== '' ? $filename0 : 'file', 'mime' => $type, 'data' => '', 'size' => strlen($body), 'inline' => false];
        return;
    }
    $data = mime_decode_body($body, $cte);
    $filename = (string)($cd['filename'] ?? ($ct['name'] ?? ''));
    $disp = $cd['value'] ?? '';
    $isText = ($type === 'text/plain' || $type === 'text/html');

    // Вложенное письмо (переслали письмо клиента) — разбираем его тоже: там часто сама заявка.
    if ($type === 'message/rfc822' && $disp !== 'attachment') {
        [$ih, $ib] = mime_split($data);
        mime_walk($ih, $ib, $acc, $depth + 1, $textOnly);
        return;
    }

    if ($isText && $filename === '' && $disp !== 'attachment') {
        $txt = mime_to_utf8($data, (string)($ct['charset'] ?? ''));
        if ($type === 'text/plain') $acc['plain'][] = $txt; else $acc['html'][] = $txt;
        return;
    }

    if ($data === '') return;
    if ($filename === '') {
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'application/pdf' => 'pdf',
                'message/rfc822' => 'eml', 'text/html' => 'html', 'text/plain' => 'txt'][$type] ?? 'bin';
        $filename = 'file-' . (count($acc['attachments']) + 1) . '.' . $ext;
    }
    $acc['attachments'][] = [
        'filename' => $filename,
        'mime'     => $type,
        'data'     => $data,
        'size'     => strlen($data),
        'inline'   => $disp === 'inline' || mime_h($h, 'content-id') !== '',
    ];
}

/** Дата письма → 'Y-m-d H:i:s' по часовому поясу сервера или ''. */
function mime_date(string $v): string {
    $v = trim(preg_replace('/\s*\([^)]*\)\s*$/', '', $v) ?? $v);
    if ($v === '') return '';
    $t = strtotime($v);
    return $t ? date('Y-m-d H:i:s', $t) : '';
}

function mime_parse(string $raw, bool $textOnly = false): array {
    [$hb, $body] = mime_split($raw);
    $h = mime_headers($hb);
    $acc = ['plain' => [], 'html' => [], 'attachments' => []];
    mime_walk($hb, $body, $acc, 0, $textOnly);

    $from = mime_addresses(mime_h($h, 'from'))[0] ?? ['email' => '', 'name' => ''];
    $plain = trim(implode("\n\n", $acc['plain']));
    $html = implode("\n", $acc['html']);
    $text = $plain !== '' ? $plain : mime_html_to_text($html);

    $ids = static function (string $v): array {
        preg_match_all('/<[^>]+>/', $v, $m);
        return $m[0] ?: (trim($v) !== '' ? [trim($v)] : []);
    };
    $prec = strtolower(mime_h($h, 'precedence'));
    $autoSub = strtolower(mime_h($h, 'auto-submitted'));

    return [
        'headers'     => $h,
        'from_email'  => $from['email'],
        'from_name'   => $from['name'],
        'to'          => array_column(mime_addresses(mime_h($h, 'to')), 'email'),
        'cc'          => array_column(mime_addresses(mime_h($h, 'cc')), 'email'),
        'subject'     => trim(mime_decode_header(mime_h($h, 'subject'))),
        'message_id'  => trim(mime_h($h, 'message-id')),
        'in_reply_to' => $ids(mime_h($h, 'in-reply-to'))[0] ?? '',
        'references'  => $ids(mime_h($h, 'references')),
        'date'        => mime_date(mime_h($h, 'date')),
        'text'        => $text,
        'html'        => $html,
        'attachments' => $acc['attachments'],
        'auto'        => [
            'auto_submitted'  => $autoSub !== '' && $autoSub !== 'no',
            'precedence_bulk' => in_array($prec, ['bulk', 'list', 'junk'], true),
            'list_id'         => mime_h($h, 'list-id') !== '' || mime_h($h, 'list-unsubscribe') !== '',
        ],
    ];
}

/**
 * Отрезать цитату предыдущей переписки: «> …», «-----Original Message-----»,
 * «20.09.2026, 10:15, "Иван" <…>:» — для обучения нужен только новый текст.
 */
function mime_strip_quote(string $text): string {
    $lines = explode("\n", str_replace("\r\n", "\n", $text));
    $out = [];
    foreach ($lines as $i => $line) {
        $t = trim($line);
        if (preg_match('/^-{2,}\s*(Original Message|Исходное сообщение|Пересылаемое сообщение|Forwarded message)/iu', $t)) break;
        if (preg_match('/^(От|From):\s.+/u', $t) && $i > 0 && trim($lines[$i - 1] ?? '') === '') break;
        if (preg_match('/^\S.{0,120}(пишет|wrote|написал\(а\)|написал)\s*:$/iu', $t)) break;
        if (str_starts_with($t, '>')) continue;
        $out[] = $line;
    }
    return trim(implode("\n", $out));
}
