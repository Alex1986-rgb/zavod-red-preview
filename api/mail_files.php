<?php
declare(strict_types=1);

/**
 * Вложения писем: сохранение и разбор ИИ.
 *
 * Раньше вложения из почты не сохранялись вовсе. Теперь каждое (кроме мелких картинок
 * подписи) ложится в crm-data/uploads под случайным именем — та же закрытая папка
 * (deny-all), что и файлы с формы сайта; выдача только через авторизованный api/file.php.
 *
 * Разбор (mf_analyze): тип документа и поля:
 *   nameplate — шильдик: марка, модель, i, мощность, обороты;
 *   drawing   — чертёж/эскиз: что изображено, размеры, упомянутые модели;
 *   invoice / receipt / kp / act — счёт, чек, КП, акт: номер, дата, стороны, ИНН, сумма, позиции;
 *   requisites — карточка предприятия; spec — опросный лист/ТЗ; photo; other.
 * Картинки и PDF читает только Claude (у DeepSeek нет зрения); docx/xlsx/txt — любой ИИ по тексту.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/llm.php';
require_once __DIR__ . '/kb_context.php';

function mf_ensure(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        pdo()->exec("CREATE TABLE IF NOT EXISTS crm_mail_files (
            id INT AUTO_INCREMENT PRIMARY KEY,
            message_id INT NOT NULL,
            lead_id INT NULL,
            direction VARCHAR(3) NOT NULL DEFAULT 'in',
            filename VARCHAR(255) NOT NULL,
            stored_name VARCHAR(120) NOT NULL,   -- не «stored»: в MySQL 8 это зарезервированное слово
            mime VARCHAR(120) NOT NULL DEFAULT '',
            size INT NOT NULL DEFAULT 0,
            sha1 CHAR(40) NOT NULL,
            kind VARCHAR(16) NOT NULL DEFAULT '',
            status VARCHAR(12) NOT NULL DEFAULT 'new',   -- new | done | skipped | error
            summary VARCHAR(500) NOT NULL DEFAULT '',
            data MEDIUMTEXT NULL,                         -- JSON извлечённых полей
            error VARCHAR(255) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            analyzed_at DATETIME NULL,
            KEY idx_msg (message_id), KEY idx_lead (lead_id), KEY idx_status (status), KEY idx_kind (kind), KEY idx_sha (sha1)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { error_log('mf_ensure: ' . $e->getMessage()); }
}

function mf_uploads_dir(): string {
    return rtrim((string)(cfg()['uploads_dir'] ?? (__DIR__ . '/../crm-data/uploads')), '/');
}

/** Расширения, которые храним как есть. Остальное — как .bin (никогда не исполняется). */
function mf_safe_ext(string $filename): string {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $ok = ['pdf','jpg','jpeg','png','webp','gif','bmp','heic','heif','tif','tiff','doc','docx','xls','xlsx','csv',
           'txt','rtf','odt','ods','dwg','dxf','step','stp','igs','iges','zip','rar','7z','eml','xml','json'];
    return in_array($ext, $ok, true) ? $ext : 'bin';
}

/** Мелочь из подписи (логотипы, иконки соцсетей) — не сохраняем. */
function mf_is_junk(array $att): bool {
    $mime = strtolower((string)$att['mime']);
    if (!empty($att['inline']) && str_starts_with($mime, 'image/') && (int)$att['size'] < 30000) return true;
    if (str_starts_with($mime, 'image/') && (int)$att['size'] < 4000) return true;
    if (in_array($mime, ['application/pkcs7-signature', 'application/x-pkcs7-signature', 'text/x-vcard', 'text/vcard'], true)) return true;
    return false;
}

/**
 * Сохранить вложения письма. Возвращает список сохранённых [id, filename, stored, mime, kind_hint].
 * Одинаковый файл (sha1) в той же переписке второй раз не кладём — клиенты пересылают его в каждом ответе.
 */
function mf_save(int $messageId, ?int $leadId, string $direction, array $attachments): array {
    mf_ensure();
    $dir = mf_uploads_dir();
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $saved = [];
    foreach ($attachments as $att) {
        if (mf_is_junk($att)) continue;
        if ((int)$att['size'] > 25 * 1024 * 1024) continue; // больше 25 МБ — не наш случай (и не влезет в ИИ)
        $sha = sha1((string)$att['data']);
        try {
            if ($leadId) {
                $st = pdo()->prepare('SELECT id FROM crm_mail_files WHERE sha1=? AND lead_id=? LIMIT 1');
                $st->execute([$sha, $leadId]);
                if ($st->fetchColumn()) continue;
            }
            // Тот же файл уже есть (каталог в каждом письме рассылки, пересланный чертёж) — не храним
            // копию и не разбираем ИИ заново: новая запись ссылается на тот же файл и берёт готовый разбор.
            $same = pdo()->prepare("SELECT stored_name, kind, status, summary, data FROM crm_mail_files WHERE sha1=? ORDER BY (status='done') DESC, id LIMIT 1");
            $same->execute([$sha]);
            $prev = $same->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($prev && mf_path((string)$prev['stored_name']) !== null) {
                $stored = (string)$prev['stored_name'];
            } else {
                $prev = null;
                $stored = 'm' . date('ymd') . '_' . bin2hex(random_bytes(8)) . '.' . mf_safe_ext((string)$att['filename']);
                if (@file_put_contents($dir . '/' . $stored, (string)$att['data']) === false) continue;
                @chmod($dir . '/' . $stored, 0640);
            }
            $st = pdo()->prepare('INSERT INTO crm_mail_files (message_id,lead_id,direction,filename,stored_name,mime,size,sha1,kind,status,summary,data,created_at)
                                  VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
            $st->execute([$messageId, $leadId, $direction, mb_substr((string)$att['filename'], 0, 255, 'UTF-8'),
                          $stored, mb_substr((string)$att['mime'], 0, 120), (int)$att['size'], $sha,
                          (string)($prev['kind'] ?? ''), $prev && $prev['status'] !== 'error' ? (string)$prev['status'] : 'new',
                          (string)($prev['summary'] ?? ''), $prev['data'] ?? null]);
            $saved[] = ['id' => (int)pdo()->lastInsertId(), 'filename' => (string)$att['filename'],
                        'stored' => $stored, 'mime' => (string)$att['mime']];
        } catch (Throwable $e) { error_log('mf_save: ' . $e->getMessage()); }
    }
    return $saved;
}

/** Путь к сохранённому файлу с проверкой, что он внутри uploads (защита от traversal). */
function mf_path(string $stored): ?string {
    $dir = realpath(mf_uploads_dir());
    $f = realpath(mf_uploads_dir() . '/' . basename($stored));
    if ($dir === false || $f === false || !is_file($f)) return null;
    if (strncmp($f, $dir . DIRECTORY_SEPARATOR, strlen($dir) + 1) !== 0) return null;
    return $f;
}

function mf_system(): string {
    return "Ты разбираешь вложение из деловой почты завода редукторов «Завод Редукторов» (бренд ZR).\n"
         . "Определи тип документа и вытащи поля. Ничего не выдумывай: нет поля в документе — не пиши его.\n"
         . "Типы: nameplate (шильдик/табличка редуктора или мотора), drawing (чертёж/эскиз/габаритка), "
         . "invoice (счёт на оплату), receipt (чек, платёжное поручение, подтверждение оплаты), kp (коммерческое предложение), "
         . "act (акт, УПД, накладная), requisites (карточка предприятия/реквизиты), spec (опросный лист, ТЗ, заявка-таблица), "
         . "photo (фото оборудования без таблички), other.\n"
         . "Верни СТРОГО JSON без markdown:\n"
         . '{"kind":"…","summary":"одно предложение по-русски, что это и главное из документа",'
         . '"nameplate":{"brand":"","model":"","ratio":"","power_kw":"","rpm_out":"","torque_nm":"","serial":""},'
         . '"doc":{"number":"","date":"","seller":"","buyer":"","inn":"","total":"","currency":"RUB","paid":false,'
         . '"items":[{"name":"","qty":"","price":"","sum":""}]},'
         . '"models":["все упомянутые модели редукторов/моторов, как написаны"],'
         . '"confidence":0}' . "\n"
         . "Блоки nameplate и doc заполняй только для своих типов, иначе пустые объекты. confidence 0..100.";
}

/**
 * Разобрать один файл. Возвращает ['ok'=>bool,'kind','summary','data'|'error'].
 * Пишет результат в crm_mail_files и, если есть заявка, заметку в crm_ai (type='mail_file').
 */
function mf_analyze(array $row): array {
    mf_ensure();
    $id = (int)$row['id'];
    $save = static function (string $status, string $kind = '', string $summary = '', ?array $data = null, string $err = '') use ($id): void {
        try {
            pdo()->prepare('UPDATE crm_mail_files SET status=?, kind=?, summary=?, data=?, error=?, analyzed_at=NOW() WHERE id=?')
                ->execute([$status, $kind, mb_substr($summary, 0, 500, 'UTF-8'),
                           $data !== null ? json_encode($data, JSON_UNESCAPED_UNICODE) : null, mb_substr($err, 0, 255, 'UTF-8'), $id]);
        } catch (Throwable $e) {}
    };
    $path = mf_path((string)$row['stored_name']);
    if ($path === null) { $save('error', '', '', null, 'файл не найден'); return ['ok' => false, 'error' => 'файл не найден']; }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $img = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];

    $attachments = [];
    $text = '';
    // PDF с текстовым слоем (счета, КП, акты, выписки из 1С) читаем как текст — это умеет и DeepSeek,
    // у которого нет зрения. Сканы и фото остаются для модели со зрением (Claude).
    if ($ext === 'pdf') {
        $pdfText = mf_pdf_text($path);
        if (mb_strlen(preg_replace('/\s+/u', '', $pdfText) ?? '', 'UTF-8') >= 150) {
            $text = mb_substr($pdfText, 0, 12000, 'UTF-8');
            if (!llm_ready()) return ['ok' => false, 'error' => 'ИИ не настроен', 'wait' => true];
        }
    }
    if ($text === '' && (isset($img[$ext]) || $ext === 'pdf')) {
        if (!llm_ready(true)) {
            // Без зрения: фото и сканы ждут в очереди с понятной причиной (видна на «Автопилоте»).
            try { pdo()->prepare('UPDATE crm_mail_files SET error=? WHERE id=?')->execute([$ext === 'pdf' ? 'скан без текста — нужно распознавание картинок' : 'фото — нужно распознавание картинок', $id]); } catch (Throwable $e) {}
            return ['ok' => false, 'error' => 'нужно распознавание картинок (ключ Anthropic)', 'wait' => true];
        }
        if (filesize($path) > 20 * 1024 * 1024) { $save('skipped', '', 'файл больше 20 МБ'); return ['ok' => false, 'error' => 'большой']; }
        $b64 = base64_encode((string)file_get_contents($path));
        $attachments[] = $ext === 'pdf'
            ? ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $b64]]
            : ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $img[$ext], 'data' => $b64]];
    } elseif ($text !== '') {
        // PDF с текстовым слоем — текст уже извлечён выше (mf_pdf_text), дальше как с документом.
    } elseif (in_array($ext, ['docx', 'xlsx', 'txt', 'csv', 'json', 'xml'], true)) {
        $tmpExt = $ext === 'xml' ? 'txt' : $ext;
        $text = (string)(kb_extract_text($path, 12000) ?? '');
        if ($tmpExt !== $ext && $text === '') $text = mb_substr((string)file_get_contents($path), 0, 12000, 'UTF-8');
        if (trim($text) === '') { $save('skipped', 'other', 'текст не извлекается'); return ['ok' => false, 'error' => 'пусто']; }
        if (!llm_ready()) return ['ok' => false, 'error' => 'ИИ не настроен', 'wait' => true];
    } else {
        // dwg/step/архивы/doc/xls: содержимое не читаем, но тип по имени понятен человеку.
        $kind = in_array($ext, ['dwg', 'dxf', 'step', 'stp', 'igs', 'iges'], true) ? 'drawing' : 'other';
        $save('skipped', $kind, 'формат ' . strtoupper($ext) . ' ИИ не читает — открыть вручную');
        return ['ok' => false, 'error' => 'формат не читается', 'kind' => $kind];
    }

    $u = 'Имя файла: ' . $row['filename'] . "\n"
       . ($row['direction'] === 'out' ? "Файл ОТПРАВИЛИ МЫ (завод) клиенту.\n" : "Файл ПРИСЛАЛ клиент/контрагент.\n")
       . ($text !== '' ? "Содержимое:\n" . $text . "\n" : "Документ во вложении выше.\n")
       . 'Верни только JSON.';
    try {
        $raw = llm_call(mf_system(), $u, 1500, $attachments);
        $t = trim((string)preg_replace(['/^```[a-z]*\s*/i', '/\s*```$/'], '', trim($raw)));
        $s = strpos($t, '{'); $e = strrpos($t, '}');
        $data = ($s !== false && $e !== false) ? json_decode(substr($t, $s, $e - $s + 1), true) : null;
        if (!is_array($data)) throw new RuntimeException('ИИ вернул не JSON');
    } catch (Throwable $e) {
        $save('error', '', '', null, $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage()];
    }
    return mf_apply($row, $data);
}

/**
 * Записать результат разбора файла — общий путь для ИИ на сервере (mf_analyze) и для внешнего
 * обработчика картинок на SmartApe (api/vision_queue.php, Claude по подписке).
 * Копии того же файла (sha1) получают тот же разбор; по каждой связанной заявке — запись в crm_ai.
 */
function mf_apply(array $row, array $data): array {
    mf_ensure();
    $id = (int)$row['id'];
    $kinds = ['nameplate','drawing','invoice','receipt','kp','act','requisites','spec','photo','other'];
    $kind = in_array($data['kind'] ?? '', $kinds, true) ? (string)$data['kind'] : 'other';
    $summary = mb_substr(trim((string)($data['summary'] ?? '')), 0, 500, 'UTF-8');
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    try {
        // Заметку в заявку пишем только тем копиям файла, что распознаются ВПЕРВЫЕ. Раньше её получали
        // все заявки с тем же sha1 при каждом применении: подписи и баннеры из писем (image012.jpg)
        // есть у десятков клиентов — заметки множились, и подбор по ним пересобирался по кругу (02.10.2026).
        $st = pdo()->prepare("SELECT id, lead_id, filename, direction FROM crm_mail_files
                              WHERE (id=? OR (sha1=? AND sha1<>'')) AND lead_id IS NOT NULL AND status IN ('new','error')");
        $st->execute([$id, (string)($row['sha1'] ?? '')]);
        $fresh = $st->fetchAll(PDO::FETCH_ASSOC);
        pdo()->prepare("UPDATE crm_mail_files SET status='done', kind=?, summary=?, data=?, error='', analyzed_at=NOW() WHERE id=? OR (sha1=? AND sha1<>'' AND status IN ('new','error'))")
            ->execute([$kind, $summary, $json, $id, (string)($row['sha1'] ?? '')]);
        $has = pdo()->prepare("SELECT 1 FROM crm_ai WHERE lead_id=? AND type='mail_file' AND content LIKE ? LIMIT 1");
        $seen = [];
        foreach ($fresh as $f) {
            $lid = (int)$f['lead_id'];
            if (isset($seen[$lid])) continue;
            $seen[$lid] = true;
            $has->execute([$lid, '{"file_id":' . (int)$f['id'] . ',%']);
            if ($has->fetchColumn()) continue;
            pdo()->prepare('INSERT INTO crm_ai (lead_id,user_id,type,content,created_at) VALUES (?,NULL,?,?,NOW())')
                ->execute([$lid, 'mail_file', json_encode(['file_id' => (int)$f['id'], 'filename' => $f['filename'], 'kind' => $kind,
                    'summary' => $summary, 'direction' => $f['direction'], 'data' => $data], JSON_UNESCAPED_UNICODE)]);
            audit($lid, null, 'mail_file', ['kind' => $kind, 'file' => $f['filename']]);
        }
    } catch (Throwable $e) { error_log('mf_apply: ' . $e->getMessage()); return ['ok' => false, 'error' => $e->getMessage()]; }
    return ['ok' => true, 'kind' => $kind, 'summary' => $summary, 'data' => $data];
}

/**
 * Текст из PDF через ghostscript (есть на Beget; pdftotext там нет). Первые 5 страниц.
 * Пусто — PDF без текстового слоя (скан) или gs недоступен.
 */
function mf_pdf_text(string $path): string {
    static $gs = null;
    if ($gs === null) {
        $gs = '';
        foreach (['/usr/bin/gs', '/usr/local/bin/gs', '/opt/homebrew/bin/gs'] as $p) if (is_executable($p)) { $gs = $p; break; }
    }
    if ($gs === '' || !function_exists('exec')) return '';
    $out = [];
    @exec(escapeshellarg($gs) . ' -q -dNOPAUSE -dBATCH -dSAFER -sDEVICE=txtwrite -dFirstPage=1 -dLastPage=5 -sOutputFile=- '
        . escapeshellarg($path) . ' 2>/dev/null', $out);
    $t = implode("\n", $out);
    if (!mb_check_encoding($t, 'UTF-8')) $t = mb_convert_encoding($t, 'UTF-8', 'UTF-8');
    $t = preg_replace("/[ \t]+/u", ' ', $t) ?? $t;
    return trim(preg_replace("/\n\s*\n+/u", "\n", $t) ?? $t);
}

/** Подпись типа для интерфейса. */
function mf_kind_label(string $k): string {
    return ['nameplate' => 'Шильдик', 'drawing' => 'Чертёж', 'invoice' => 'Счёт', 'receipt' => 'Оплата/чек',
            'kp' => 'КП', 'act' => 'Акт/УПД', 'requisites' => 'Реквизиты', 'spec' => 'Опросный лист/ТЗ',
            'photo' => 'Фото', 'other' => 'Другое', '' => 'Не разобран'][$k] ?? $k;
}

/** Текст для автоответа: что распознано во вложениях письма. */
function mf_context_for_message(int $messageId): string {
    mf_ensure();
    try {
        $st = pdo()->prepare("SELECT filename, kind, summary, data FROM crm_mail_files WHERE message_id=? AND status='done'");
        $st->execute([$messageId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return ''; }
    $out = [];
    foreach ($rows as $r) {
        $line = '— ' . $r['filename'] . ' (' . mf_kind_label((string)$r['kind']) . '): ' . $r['summary'];
        $d = json_decode((string)$r['data'], true) ?: [];
        $np = array_filter((array)($d['nameplate'] ?? []));
        if ($np) $line .= ' [шильдик: ' . implode(', ', array_map(static fn($k, $v) => "$k=$v", array_keys($np), $np)) . ']';
        if (!empty($d['models'])) $line .= ' [модели: ' . implode(', ', (array)$d['models']) . ']';
        $out[] = $line;
    }
    return $out ? "ВО ВЛОЖЕНИЯХ ПИСЬМА РАСПОЗНАНО:\n" . implode("\n", $out) : '';
}

/**
 * Очередь разбора (крон): до $limit файлов со статусом new, свежие первыми.
 * Файлы, которым нужен ключ, которого нет, остаются в очереди — дождутся ключа.
 */
function mf_run_queue(int $limit = 8): array {
    mf_ensure();
    $done = 0; $wait = 0; $err = 0;
    try {
        // Без модели со зрением фото и сканы всё равно не прочитать — не даём им занимать очередь.
        $skipVision = llm_ready(true) ? '' : " AND stored_name NOT REGEXP '\\.(jpe?g|png|webp|gif|bmp|heic|heif|tiff?)$'
                                                AND error NOT LIKE '%распознавание картинок%'";
        // Один файл (sha1) — один разбор: копии получат результат сами (см. mf_analyze).
        $rows = pdo()->query("SELECT * FROM crm_mail_files WHERE status='new'{$skipVision}
                              AND id IN (SELECT MIN(id) FROM crm_mail_files WHERE status='new' GROUP BY sha1)
                              ORDER BY id DESC LIMIT " . max(1, $limit))->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return ['ok' => false, 'error' => $e->getMessage()]; }
    foreach ($rows as $r) {
        $res = mf_analyze($r);
        if (!empty($res['ok'])) $done++;
        elseif (!empty($res['wait'])) $wait++;
        else $err++;
    }
    // Разобранные счета и КП, отправленные нами, → история цен (api/price_hist.php).
    $prices = 0;
    try { require_once __DIR__ . '/price_hist.php'; $prices = ph_ingest(); } catch (Throwable $e) {}
    return ['ok' => true, 'analyzed' => $done, 'waiting_key' => $wait, 'skipped_or_error' => $err, 'price_rows' => $prices];
}
