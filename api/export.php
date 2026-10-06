<?php
declare(strict_types=1);

/**
 * /api/export.php — выгрузки лидов (auth required).
 *
 * GET-параметры:
 *   type     = csv | xlsx | audience | audience_plain   (по умолчанию csv)
 *   status   = new|in_progress|quoted|won|lost          (фильтр сегмента/статуса)
 *   from     = YYYY-MM-DD   (created_at >=)
 *   to       = YYYY-MM-DD   (created_at <=, включительно)
 *   source   = строка источника (точное совпадение)
 *   reducer_type = строка типа редуктора (точное совпадение)
 *
 * Все запросы — PDO prepared statements. Любой вывод XML экранируется htmlspecialchars.
 */

require_once __DIR__ . '/helpers.php';

$user = require_auth();

$type = $_GET['type'] ?? 'csv';
if (!in_array($type, ['csv', 'xlsx', 'audience', 'audience_plain'], true)) {
    json_out(['ok' => false, 'error' => 'Неизвестный type'], 400);
}
// Выгрузка НЕхэшированных ПДн (телефоны/почты клиентов в открытом виде) — только админ.
if ($type === 'audience_plain' && ($user['role'] ?? '') !== 'admin') {
    json_out(['ok' => false, 'error' => 'Выгрузка контактов в открытом виде доступна только администратору'], 403);
}

/* ---- сборка фильтров (prepared) ---- */
$where = [];
$args  = [];

$status = trim((string)($_GET['status'] ?? ''));
$validStatuses = ['new','in_progress','clarify','picked','review','rework','approved','sent','quoted','won','lost'];
if ($status !== '' && in_array($status, $validStatuses, true)) {
    $where[] = 'status = ?';
    $args[]  = $status;
}

$from = trim((string)($_GET['from'] ?? ''));
if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $where[] = 'created_at >= ?';
    $args[]  = $from . ' 00:00:00';
}

$to = trim((string)($_GET['to'] ?? ''));
if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $where[] = 'created_at <= ?';
    $args[]  = $to . ' 23:59:59';
}

$source = trim((string)($_GET['source'] ?? ''));
if ($source !== '') {
    // Как в lead_list (api/leads.php): источник может лежать и в utm_source.
    $where[] = '(source = ? OR utm_source = ?)';
    $args[]  = $source;
    $args[]  = $source;
}

$reducer = trim((string)($_GET['reducer_type'] ?? ''));
if ($reducer !== '') {
    $where[] = 'reducer_type = ?';
    $args[]  = $reducer;
}

// Менеджер (из фильтров списка/отчёта) — чтобы выгрузка совпадала с экраном
$mgr = (string)($_GET['manager_id'] ?? $_GET['manager'] ?? '');
if ($mgr !== '' && ctype_digit($mgr)) {
    if ($mgr === '0') {
        // «Без менеджера»: в БД это NULL, а не 0 — manager_id = 0 ничего не находит.
        $where[] = 'manager_id IS NULL';
    } else {
        $where[] = 'manager_id = ?';
        $args[]  = (int)$mgr;
    }
}

// Поиск по имени/телефону/email
$q = trim((string)($_GET['q'] ?? ''));
if ($q !== '') {
    $where[] = '(name LIKE ? OR phone LIKE ? OR email LIKE ?)';
    $like = '%' . $q . '%';
    $args[] = $like; $args[] = $like; $args[] = $like;
}

// Тег
$tag = trim((string)($_GET['tag'] ?? ''));
if ($tag !== '') {
    $where[] = 'tags LIKE ?';
    $args[]  = '%' . $tag . '%';
}

$sqlWhere = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

audit(null, isset($user['id']) ? (int)$user['id'] : null, 'export', [
    'type'   => $type,
    'filter' => ['status' => $status, 'from' => $from, 'to' => $to, 'source' => $source, 'reducer_type' => $reducer],
]);

$today = date('Y-m-d');

/* ===================== AUDIENCE (хэш) и AUDIENCE_PLAIN (открытый) ===================== */
if ($type === 'audience' || $type === 'audience_plain') {
    $st = pdo()->prepare('SELECT phone, email FROM crm_leads' . $sqlWhere);
    $st->execute($args);

    if ($type === 'audience_plain') {
        /*
         * ВНИМАНИЕ / WARNING: audience_plain отдаёт НЕхэшированные персональные данные
         * (телефон + email) в открытом виде. Предназначено ИСКЛЮЧИТЕЛЬНО для ручного
         * обзвона менеджером и доступно только под auth (require_auth выше).
         * НЕ загружать в рекламные кабинеты (Яндекс.Аудитории / VK / Google) —
         * туда грузится только хэшированный экспорт type=audience.
         */
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="audience_plain_' . $today . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM
        fputcsv($out, ['phone', 'email'], ';');
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $phone = normalize_phone((string)($r['phone'] ?? ''));
            $email = strtolower(trim((string)($r['email'] ?? '')));
            if ($phone === '' && $email === '') continue;
            fputcsv($out, [$phone, $email], ';');
        }
        fclose($out);
        exit;
    }

    // type=audience — SHA256 хэши для ретаргетинга
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="audience_' . $today . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM
    fputcsv($out, ['phone_sha256', 'email_sha256'], ';');
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $phone = normalize_phone((string)($r['phone'] ?? ''));
        $email = strtolower(trim((string)($r['email'] ?? '')));
        $ph = $phone !== '' ? hash('sha256', $phone) : '';
        $eh = $email !== '' ? hash('sha256', $email) : '';
        if ($ph === '' && $eh === '') continue; // пустые пропускаем
        fputcsv($out, [$ph, $eh], ';');
    }
    fclose($out);
    exit;
}

/* ===================== Полная выборка для CSV / XLSX ===================== */
$cols = [
    'id'           => 'ID',
    'created_at'   => 'Создан',
    'updated_at'   => 'Обновлён',
    'name'         => 'Имя',
    'phone'        => 'Телефон',
    'email'        => 'Email',
    'reducer_type' => 'Тип редуктора',
    'message'      => 'Сообщение',
    'status'       => 'Статус',
    'amount'       => 'Сумма',
    'manager_id'   => 'Менеджер',
    'lost_reason'  => 'Причина отказа',
    'source'       => 'Источник',
    'utm_source'   => 'UTM Source',
    'utm_medium'   => 'UTM Medium',
    'utm_campaign' => 'UTM Campaign',
    'utm_term'     => 'UTM Term',
    'utm_content'  => 'UTM Content',
    'referrer'     => 'Referrer',
    'gclid'        => 'gclid',
    'yclid'        => 'yclid',
    'page_url'     => 'Страница',
    'page_title'   => 'Заголовок страницы',
    'ip'           => 'IP',
];
$fields = array_keys($cols);

$select = implode(', ', $fields);
$st = pdo()->prepare('SELECT ' . $select . ' FROM crm_leads' . $sqlWhere . ' ORDER BY id DESC');
$st->execute($args);

/* ---------- CSV ---------- */
if ($type === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="leads_' . $today . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM
    fputcsv($out, array_values($cols), ';');
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $row = [];
        foreach ($fields as $f) {
            $row[] = csv_safe((string)($r[$f] ?? ''));
        }
        fputcsv($out, $row, ';');
    }
    fclose($out);
    exit;
}

/* ---------- XLSX (SpreadsheetML 2003, без библиотек) ---------- */
// Excel 2003 XML формат — открывается Excel/LibreOffice без сторонних либ.
header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="leads_' . $today . '.xls"');

$esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_XML1, 'UTF-8');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"'
   . ' xmlns:o="urn:schemas-microsoft-com:office:office"'
   . ' xmlns:x="urn:schemas-microsoft-com:office:excel"'
   . ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' . "\n";
echo '<Styles>'
   . '<Style ss:ID="hdr"><Font ss:Bold="1"/>'
   . '<Interior ss:Color="#E11B1B" ss:Pattern="Solid"/>'
   . '<Font ss:Bold="1" ss:Color="#FFFFFF"/></Style>'
   . '</Styles>' . "\n";
echo '<Worksheet ss:Name="Лиды"><Table>' . "\n";

// Заголовки RU
echo '<Row>';
foreach ($cols as $title) {
    echo '<Cell ss:StyleID="hdr"><Data ss:Type="String">' . $esc((string)$title) . '</Data></Cell>';
}
echo '</Row>' . "\n";

$numCols = ['id' => true, 'amount' => true, 'manager_id' => true];
while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    echo '<Row>';
    foreach ($fields as $f) {
        $v = (string)($r[$f] ?? '');
        if (isset($numCols[$f]) && $v !== '' && is_numeric($v)) {
            echo '<Cell><Data ss:Type="Number">' . $esc($v) . '</Data></Cell>';
        } else {
            echo '<Cell><Data ss:Type="String">' . $esc($v) . '</Data></Cell>';
        }
    }
    echo '</Row>' . "\n";
}

echo '</Table></Worksheet>' . "\n";
echo '</Workbook>' . "\n";
exit;
