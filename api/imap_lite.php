<?php
declare(strict_types=1);

/**
 * Минимальный IMAP-клиент на сокетах — без расширения imap (убрано из PHP 8.4,
 * на хостинге может отсутствовать). Умеет ровно то, что нужно CRM:
 *   вход, список папок (с поиском «Отправленных» по флагу \Sent), выбор папки
 *   (UIDVALIDITY/UIDNEXT), поиск UID, выдачу письма целиком.
 *
 * Письма читаются через BODY.PEEK[] — флаг «прочитано» НЕ ставится: менеджер в
 * Яндекс.Почте по-прежнему видит непрочитанные. Ящик открывается EXAMINE (только
 * чтение) — CRM ничего в нём не меняет.
 *
 * $c = imapl_open('imap.yandex.ru', 993, $user, $pass);   // бросает RuntimeException
 * $box = imapl_examine($c, 'INBOX');   // ['exists'=>…, 'uidvalidity'=>…, 'uidnext'=>…]
 * $uids = imapl_uids_from($c, 1234);   // UID ≥ 1234 по возрастанию
 * $raw = imapl_fetch_raw($c, 1240);    // сырое письмо (RFC 822) или null
 * imapl_close($c);
 */

final class ImapLite {
    /** @var resource */
    public $fp;
    public int $tag = 0;
    public string $lastError = '';
}

function imapl_open(string $host, int $port, string $user, string $pass, int $timeout = 25): ImapLite {
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
    $scheme = $port === 993 ? 'ssl://' : 'tcp://';
    $fp = @stream_socket_client($scheme . $host . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) throw new RuntimeException("IMAP: нет соединения с {$host}:{$port} — {$errstr}");
    stream_set_timeout($fp, $timeout);
    $c = new ImapLite();
    $c->fp = $fp;
    $greet = imapl_readline($c);
    if (!str_starts_with($greet, '* OK')) throw new RuntimeException('IMAP: неожиданное приветствие сервера');
    [$ok] = imapl_cmd($c, 'LOGIN ' . imapl_q($user) . ' ' . imapl_q($pass));
    if (!$ok) throw new RuntimeException('IMAP: вход не удался (' . $c->lastError . '). Нужен пароль приложения Яндекса.');
    return $c;
}

function imapl_close(ImapLite $c): void {
    try { imapl_cmd($c, 'LOGOUT'); } catch (Throwable $e) {}
    if (is_resource($c->fp)) fclose($c->fp);
}

/** Строка в кавычках по правилам IMAP. */
function imapl_q(string $s): string {
    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"';
}

function imapl_readline(ImapLite $c): string {
    $line = fgets($c->fp);
    if ($line === false) {
        $meta = stream_get_meta_data($c->fp);
        throw new RuntimeException('IMAP: ' . (!empty($meta['timed_out']) ? 'таймаут' : 'соединение закрыто'));
    }
    return $line;
}

function imapl_read_exact(ImapLite $c, int $n): string {
    $buf = '';
    while (strlen($buf) < $n) {
        $chunk = fread($c->fp, min(65536, $n - strlen($buf)));
        if ($chunk === false || $chunk === '') {
            $meta = stream_get_meta_data($c->fp);
            if (!empty($meta['timed_out']) || feof($c->fp)) throw new RuntimeException('IMAP: обрыв при чтении письма');
        }
        $buf .= (string)$chunk;
    }
    return $buf;
}

/**
 * Выполнить команду. Возвращает [ok, $untagged] где $untagged — список ответов «* …»;
 * литералы {N} подставлены внутрь строки как ['line'=>…, 'literals'=>[…]].
 */
function imapl_cmd(ImapLite $c, string $cmd): array {
    $tag = 'A' . (++$c->tag);
    fwrite($c->fp, $tag . ' ' . $cmd . "\r\n");
    $untagged = [];
    while (true) {
        $line = imapl_readline($c);
        $literals = [];
        // Строка может заканчиваться литералом {N} — за ним N байт данных, потом продолжение.
        while (preg_match('/\{(\d+)\}\r?\n$/', $line, $m)) {
            $literals[] = imapl_read_exact($c, (int)$m[1]);
            $line .= "\x00LIT" . (count($literals) - 1) . "\x00" . imapl_readline($c);
        }
        if (str_starts_with($line, $tag . ' ')) {
            $rest = substr($line, strlen($tag) + 1);
            $ok = str_starts_with($rest, 'OK');
            if (!$ok) $c->lastError = trim($rest);
            return [$ok, $untagged];
        }
        if (str_starts_with($line, '+')) continue; // продолжение — нам не нужно
        $untagged[] = ['line' => rtrim($line, "\r\n"), 'literals' => $literals];
    }
}

/**
 * Папки ящика: [['name'=>сырое имя для SELECT, 'flags'=>[…]], …].
 * «Отправленные» у Яндекса называются по-разному, надёжный признак — флаг \Sent.
 */
function imapl_list(ImapLite $c): array {
    [$ok, $rows] = imapl_cmd($c, 'LIST "" "*"');
    if (!$ok) return [];
    $out = [];
    foreach ($rows as $r) {
        $line = $r['line'];
        if (!preg_match('/^\* LIST \(([^)]*)\) (?:"[^"]*"|NIL) (.+)$/i', $line, $m)) continue;
        $name = trim($m[2]);
        if (preg_match('/^\x00LIT(\d+)\x00/', $name, $lm)) $name = $r['literals'][(int)$lm[1]] ?? '';
        elseif (strlen($name) >= 2 && $name[0] === '"') $name = stripcslashes(substr($name, 1, -1));
        $out[] = ['name' => $name, 'flags' => preg_split('/\s+/', trim($m[1])) ?: []];
    }
    return $out;
}

/** Имя папки «Отправленные» или '' (сначала флаг \Sent, потом типовые имена). */
function imapl_sent_folder(ImapLite $c): string {
    $folders = imapl_list($c);
    foreach ($folders as $f) {
        foreach ($f['flags'] as $fl) if (strcasecmp($fl, '\\Sent') === 0) return $f['name'];
    }
    foreach ($folders as $f) {
        if (preg_match('/^(Sent|Sent Items|Sent Messages|Отправленные)$/iu', $f['name'])) return $f['name'];
    }
    return '';
}

/** Открыть папку только на чтение. */
function imapl_examine(ImapLite $c, string $folder): array {
    [$ok, $rows] = imapl_cmd($c, 'EXAMINE ' . imapl_q($folder));
    if (!$ok) throw new RuntimeException('IMAP: нет папки «' . $folder . '» (' . $c->lastError . ')');
    $info = ['exists' => 0, 'uidvalidity' => 0, 'uidnext' => 0];
    foreach ($rows as $r) {
        $l = $r['line'];
        if (preg_match('/^\* (\d+) EXISTS/i', $l, $m)) $info['exists'] = (int)$m[1];
        if (preg_match('/UIDVALIDITY (\d+)/i', $l, $m)) $info['uidvalidity'] = (int)$m[1];
        if (preg_match('/UIDNEXT (\d+)/i', $l, $m)) $info['uidnext'] = (int)$m[1];
    }
    return $info;
}

/** UID писем, начиная с $from, по возрастанию. */
function imapl_uids_from(ImapLite $c, int $from): array {
    [$ok, $rows] = imapl_cmd($c, 'UID SEARCH UID ' . max(1, $from) . ':*');
    if (!$ok) return [];
    return imapl_parse_search($rows, $from);
}

/** UID писем начиная с даты (для первого запуска: не тянуть весь архив в заявки). */
function imapl_uids_since(ImapLite $c, int $ts): array {
    [$ok, $rows] = imapl_cmd($c, 'UID SEARCH SINCE ' . date('j-M-Y', $ts));
    if (!$ok) return [];
    return imapl_parse_search($rows, 1);
}

function imapl_parse_search(array $rows, int $min): array {
    $uids = [];
    foreach ($rows as $r) {
        if (preg_match('/^\* SEARCH\s*(.*)$/i', $r['line'], $m)) {
            foreach (preg_split('/\s+/', trim($m[1])) ?: [] as $u) {
                // «n:*» по стандарту возвращает последнее письмо, даже если его UID < n.
                if ($u !== '' && ctype_digit($u) && (int)$u >= $min) $uids[] = (int)$u;
            }
        }
    }
    sort($uids);
    return array_values(array_unique($uids));
}

/**
 * Message-ID → UID для писем папки начиная с даты. Одним FETCH только заголовка Message-ID:
 * поиск «SEARCH HEADER Message-ID» Яндекс не поддерживает (отвечает «Backend error»).
 */
function imapl_msgid_map(ImapLite $c, int $sinceTs): array {
    $uids = imapl_uids_since($c, $sinceTs);
    $map = [];
    foreach (array_chunk($uids, 200) as $part) {
        [$ok, $rows] = imapl_cmd($c, 'UID FETCH ' . implode(',', $part) . ' (UID BODY.PEEK[HEADER.FIELDS (MESSAGE-ID)])');
        if (!$ok) continue;
        foreach ($rows as $r) {
            if (!preg_match('/UID (\d+)/', $r['line'], $m) || !$r['literals']) continue;
            if (preg_match('/^Message-ID:\s*(.+?)\s*$/im', preg_replace("/\r?\n[ \t]+/", ' ', $r['literals'][0]), $mm)) {
                $map[trim($mm[1])] = (int)$m[1];
            }
        }
    }
    return $map;
}

/** Письмо целиком по UID (без пометки «прочитано»). */
function imapl_fetch_raw(ImapLite $c, int $uid): ?string {
    [$ok, $rows] = imapl_cmd($c, 'UID FETCH ' . $uid . ' (UID BODY.PEEK[])');
    if (!$ok) return null;
    foreach ($rows as $r) {
        if (preg_match('/^\* \d+ FETCH .*UID ' . $uid . '\b/i', $r['line']) || count($rows) === 1) {
            if ($r['literals']) return $r['literals'][0];
        }
    }
    foreach ($rows as $r) if ($r['literals']) return $r['literals'][0];
    return null;
}
