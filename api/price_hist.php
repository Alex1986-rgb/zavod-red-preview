<?php
declare(strict_types=1);

/**
 * История цен из НАШИХ документов: позиции отправленных клиентам счетов и КП.
 *
 * Прайса нет, а цена в справочнике — заглушка (26 130 ₽ против 184 900 ₽ в реальном счёте
 * ZR 959). Зато настоящие цены лежат в счетах и КП, которые менеджеры отправляли из почты:
 * api/mail_files.php уже разбирает их ИИ (номер, дата, позиции, цены). Здесь позиции
 * складываются в crm_price_hist, и по коду ZR видно, за сколько такое уже продавали.
 *
 * Это ПОДСКАЗКА ЧЕЛОВЕКУ (инженеру в карточке, менеджеру в поле «Инженер» Битрикса).
 * Голосовой робот и автоответ цену не называют — правило не меняется.
 */

require_once __DIR__ . '/helpers.php';

function ph_ensure(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        pdo()->exec("CREATE TABLE IF NOT EXISTS crm_price_hist (
            id INT AUTO_INCREMENT PRIMARY KEY,
            file_id INT NOT NULL,
            lead_id INT NULL,
            doc_kind VARCHAR(16) NOT NULL DEFAULT '',
            doc_no VARCHAR(60) NOT NULL DEFAULT '',
            doc_date VARCHAR(20) NOT NULL DEFAULT '',
            buyer VARCHAR(190) NOT NULL DEFAULT '',
            item VARCHAR(400) NOT NULL,
            zr VARCHAR(40) NOT NULL DEFAULT '',
            qty DECIMAL(12,2) NULL,
            price DECIMAL(14,2) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_zr (zr), KEY idx_file (file_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { error_log('ph_ensure: ' . $e->getMessage()); }
}

/** «184 900,00 ₽» → 184900.0; пусто/мусор → null. */
function ph_num($v): ?float {
    if (is_int($v) || is_float($v)) return (float)$v;
    $s = preg_replace('/[^\d.,]/u', '', (string)$v) ?? '';
    if ($s === '' || !preg_match('/\d/', $s)) return null;
    // Десятичный знак — последний из «.»/«,», если после него 1–2 цифры; остальные — разделители тысяч.
    $pos = max((int)strrpos($s, '.'), (int)strrpos($s, ','));
    $hasSep = strpbrk($s, '.,') !== false;
    if ($hasSep && strlen($s) - $pos - 1 <= 2) {
        $int = preg_replace('/[.,]/', '', substr($s, 0, $pos)) ?? '';
        return (float)($int . '.' . substr($s, $pos + 1));
    }
    return (float)(preg_replace('/[.,]/', '', $s) ?? '0');
}

/** Код ZR из наименования: «Мотор-редуктор ZR 999 i=23,33» → «ZR 999». */
function ph_zr(string $name): string {
    if (!preg_match('/\bZR[\s\-]*([0-9]{2,5}(?:\/[0-9]{2,5})?)/iu', $name, $m)) return '';
    return 'ZR ' . $m[1];
}

/** Разложить разобранные исходящие счета/КП по позициям. Идемпотентно (по file_id). */
function ph_ingest(int $limit = 200): int {
    ph_ensure();
    try {
        $rows = pdo()->query("SELECT f.id, f.lead_id, f.kind, f.data FROM crm_mail_files f
            WHERE f.status='done' AND f.direction='out' AND f.kind IN ('invoice','kp')
              AND NOT EXISTS (SELECT 1 FROM crm_price_hist p WHERE p.file_id=f.id)
            ORDER BY f.id LIMIT " . max(1, $limit))->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return 0; }
    $n = 0;
    $ins = pdo()->prepare('INSERT INTO crm_price_hist (file_id,lead_id,doc_kind,doc_no,doc_date,buyer,item,zr,qty,price) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $seen = [];
    foreach ($rows as $r) {
        $d = json_decode((string)$r['data'], true) ?: [];
        $doc = (array)($d['doc'] ?? []);
        $items = (array)($doc['items'] ?? []);
        // Одинаковый документ в разных письмах (разослан нескольким) — одна запись о цене.
        $key = trim((string)($doc['number'] ?? '')) . '|' . trim((string)($doc['date'] ?? ''));
        $dup = false;
        if ($key !== '|') {
            if (isset($seen[$key])) $dup = true;
            else {
                $q = pdo()->prepare('SELECT 1 FROM crm_price_hist WHERE doc_no=? AND doc_date=? AND price IS NOT NULL LIMIT 1');
                $q->execute([mb_substr(trim((string)($doc['number'] ?? '')), 0, 60, 'UTF-8'), mb_substr(trim((string)($doc['date'] ?? '')), 0, 20, 'UTF-8')]);
                $dup = (bool)$q->fetchColumn(); // тот же документ уже учтён из другого письма
            }
        }
        $seen[$key] = true;
        $wrote = false;
        foreach ($dup ? [] : $items as $it) {
            $name = trim((string)($it['name'] ?? ''));
            $price = ph_num($it['price'] ?? '');
            if ($name === '' || $price === null || $price <= 0) continue;
            $ins->execute([(int)$r['id'], $r['lead_id'] ?: null, (string)$r['kind'],
                mb_substr(trim((string)($doc['number'] ?? '')), 0, 60, 'UTF-8'), mb_substr(trim((string)($doc['date'] ?? '')), 0, 20, 'UTF-8'),
                mb_substr((string)($doc['buyer'] ?? ''), 0, 190, 'UTF-8'), mb_substr($name, 0, 400, 'UTF-8'),
                ph_zr($name), ph_num($it['qty'] ?? ''), $price]);
            $n++; $wrote = true;
        }
        // Пометка «обработан», даже если позиций с ценой нет — чтобы не перебирать файл снова.
        if (!$wrote) $ins->execute([(int)$r['id'], null, (string)$r['kind'], '', '', '', '(без позиций с ценой)', '', null, null]);
    }
    return $n;
}

/**
 * Прошлые цены по кодам ZR: ['ZR 999' => [['price','qty','doc','date','buyer'], …]] — до 3 на код, свежие первыми.
 */
function ph_hints(array $zrCodes): array {
    ph_ensure();
    $out = [];
    $st = pdo()->prepare("SELECT price, qty, doc_kind, doc_no, doc_date, buyer FROM crm_price_hist
                          WHERE zr=? AND price IS NOT NULL ORDER BY id DESC LIMIT 3");
    foreach (array_unique(array_filter(array_map('ph_zr', $zrCodes))) as $zr) {
        $st->execute([$zr]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) $out[$zr] = $rows;
    }
    return $out;
}

/** Строки подсказки для человека. */
function ph_hint_lines(array $zrCodes): array {
    $lines = [];
    foreach (ph_hints($zrCodes) as $zr => $rows) {
        $parts = [];
        foreach ($rows as $r) {
            $parts[] = number_format((float)$r['price'], 0, ',', ' ') . ' ₽'
                . ($r['qty'] ? ' ×' . rtrim(rtrim((string)$r['qty'], '0'), '.') : '')
                . ' (' . ($r['doc_kind'] === 'kp' ? 'КП' : 'счёт') . ($r['doc_no'] !== '' ? ' №' . $r['doc_no'] : '')
                . ($r['doc_date'] !== '' ? ' от ' . $r['doc_date'] : '') . ($r['buyer'] !== '' ? ', ' . $r['buyer'] : '') . ')';
        }
        $lines[] = $zr . ': ' . implode('; ', $parts);
    }
    return $lines;
}

/** Коды ZR из последнего подбора по заявке (crm_ai recognize/nameplate/suggest). */
function ph_lead_zr(int $leadId): array {
    $a = pdo()->prepare("SELECT content FROM crm_ai WHERE lead_id=? AND type IN ('recognize','nameplate','suggest') ORDER BY id DESC LIMIT 1");
    $a->execute([$leadId]);
    $d = json_decode((string)$a->fetchColumn(), true);
    $codes = [];
    foreach ((array)($d['analogs'] ?? []) as $x) {
        if (preg_match_all('/\bZR[\s\-]*[0-9]{2,5}(?:\/[0-9]{2,5})?/iu', (string)($x['our'] ?? ''), $m)) $codes = array_merge($codes, $m[0]);
    }
    return $codes;
}
