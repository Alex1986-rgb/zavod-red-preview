<?php
declare(strict_types=1);

/**
 * /api/stats.php — единый JSON для дашборда Chart.js (требует авторизации).
 *   GET ?from=YYYY-MM-DD&to=YYYY-MM-DD
 * Возвращает: kpi, timeseries, by_status, by_type, by_source, funnel, managers, revenue_ts.
 * Все запросы — PDO prepared с диапазоном дат.
 */

require_once __DIR__ . '/helpers.php';

require_auth();

try {
    // --- Диапазон дат (по умолчанию: последние 30 дней) ---
    $to   = trim((string)($_GET['to'] ?? ''));
    $from = trim((string)($_GET['from'] ?? ''));
    $reDate = '/^\d{4}-\d{2}-\d{2}$/';
    if (!preg_match($reDate, $to))   $to   = date('Y-m-d');
    if (!preg_match($reDate, $from)) $from = date('Y-m-d', strtotime($to . ' -29 days'));
    if ($from > $to) { [$from, $to] = [$to, $from]; }

    $start = $from . ' 00:00:00';
    $end   = $to   . ' 23:59:59';
    $range = [$start, $end];

    $pdo = pdo();

    // ===== KPI =====
    $today      = date('Y-m-d');
    $weekStart  = date('Y-m-d', strtotime('monday this week'));
    $monthStart = date('Y-m-01');

    $kpi = [
        'today'         => count_leads($pdo, $today . ' 00:00:00', $today . ' 23:59:59'),
        'week'          => count_leads($pdo, $weekStart . ' 00:00:00', $today . ' 23:59:59'),
        'month'         => count_leads($pdo, $monthStart . ' 00:00:00', $today . ' 23:59:59'),
        'conv_pct'      => 0.0,
        'revenue'       => 0.0,
        'avg_response_h'=> 0.0,
    ];

    // Конверсия и выручка в диапазоне
    $st = $pdo->prepare(
        "SELECT COUNT(*) AS total,
                SUM(status = 'won') AS won,
                COALESCE(SUM(CASE WHEN status = 'won' THEN amount ELSE 0 END), 0) AS revenue
         FROM crm_leads
         WHERE created_at BETWEEN ? AND ?"
    );
    $st->execute($range);
    $row = $st->fetch() ?: ['total' => 0, 'won' => 0, 'revenue' => 0];
    $totalIn = (int)$row['total'];
    $wonIn   = (int)$row['won'];
    $kpi['conv_pct'] = $totalIn > 0 ? round($wonIn / $totalIn * 100, 1) : 0.0;
    $kpi['revenue']  = (float)$row['revenue'];

    // Среднее время первого ответа (часы): от создания лида до первого события status_changed/assigned/note_added/email_sent
    $stR = $pdo->prepare(
        "SELECT AVG(TIMESTAMPDIFF(SECOND, l.created_at, fe.first_at)) AS avg_sec
         FROM crm_leads l
         JOIN (
            SELECT lead_id, MIN(created_at) AS first_at
            FROM crm_events
            WHERE type IN ('status_changed','assigned','note_added','email_sent','call_logged')
            GROUP BY lead_id
         ) fe ON fe.lead_id = l.id
         WHERE l.created_at BETWEEN ? AND ?"
    );
    $stR->execute($range);
    $avgSec = $stR->fetchColumn();
    $kpi['avg_response_h'] = $avgSec !== null && $avgSec !== false
        ? round((float)$avgSec / 3600, 1) : 0.0;

    // ===== Timeseries по дням (кол-во лидов) =====
    $byDay = [];
    $stTs = $pdo->prepare(
        "SELECT DATE(created_at) AS d, COUNT(*) AS c
         FROM crm_leads WHERE created_at BETWEEN ? AND ?
         GROUP BY DATE(created_at)"
    );
    $stTs->execute($range);
    foreach ($stTs->fetchAll() as $r) $byDay[(string)$r['d']] = (int)$r['c'];

    // Выручка по дням (won)
    $revByDay = [];
    $stRev = $pdo->prepare(
        "SELECT DATE(created_at) AS d, COALESCE(SUM(amount),0) AS s
         FROM crm_leads
         WHERE status = 'won' AND created_at BETWEEN ? AND ?
         GROUP BY DATE(created_at)"
    );
    $stRev->execute($range);
    foreach ($stRev->fetchAll() as $r) $revByDay[(string)$r['d']] = (float)$r['s'];

    // Непрерывная ось дней
    $labels = $tsData = $revData = [];
    $cur = strtotime($from);
    $last = strtotime($to);
    while ($cur <= $last) {
        $d = date('Y-m-d', $cur);
        $labels[]  = $d;
        $tsData[]  = $byDay[$d] ?? 0;
        $revData[] = $revByDay[$d] ?? 0.0;
        $cur = strtotime('+1 day', $cur);
    }

    // ===== By status (вся инженерная воронка) =====
    $statusOrder  = funnel_codes();
    $statusLabels = array_map('status_label', $statusOrder);
    $stStat = $pdo->prepare(
        "SELECT status, COUNT(*) AS c FROM crm_leads
         WHERE created_at BETWEEN ? AND ? GROUP BY status"
    );
    $stStat->execute($range);
    $statMap = [];
    foreach ($stStat->fetchAll() as $r) $statMap[(string)$r['status']] = (int)$r['c'];
    $byStatusData = [];
    foreach ($statusOrder as $s) $byStatusData[] = $statMap[$s] ?? 0;

    // ===== By type (reducer_type) =====
    $stType = $pdo->prepare(
        "SELECT IF(reducer_type = '' OR reducer_type IS NULL, 'Не указан', reducer_type) AS t,
                COUNT(*) AS c
         FROM crm_leads WHERE created_at BETWEEN ? AND ?
         GROUP BY t ORDER BY c DESC LIMIT 15"
    );
    $stType->execute($range);
    $typeLabels = $typeData = [];
    foreach ($stType->fetchAll() as $r) { $typeLabels[] = (string)$r['t']; $typeData[] = (int)$r['c']; }

    // ===== By source =====
    $stSrc = $pdo->prepare(
        "SELECT IF(src = '' OR src IS NULL, 'Прямой/нет', src) AS source, COUNT(*) AS c FROM (
            SELECT COALESCE(NULLIF(utm_source,''), NULLIF(source,''), '') AS src
            FROM crm_leads WHERE created_at BETWEEN ? AND ?
         ) x GROUP BY source ORDER BY c DESC LIMIT 15"
    );
    $stSrc->execute($range);
    $srcLabels = $srcData = [];
    foreach ($stSrc->fetchAll() as $r) { $srcLabels[] = (string)$r['source']; $srcData[] = (int)$r['c']; }

    // ===== Funnel: Новые → В работе → Отправлено КП → Сделки =====
    // Накопительная воронка: на каждом шаге — лиды на этой стадии или дальше.
    $rank = [
        'new' => 0,
        'in_progress' => 1, 'clarify' => 1, 'picked' => 1, 'review' => 1, 'rework' => 1, 'approved' => 1,
        'sent' => 2, 'quoted' => 2,
        'won' => 3,
        'lost' => -1,
    ];
    $funnelData = [0, 0, 0, 0];
    foreach ($statMap as $s => $c) {
        $rk = $rank[$s] ?? -1;
        if ($rk < 0) continue; // lost не входит в воронку
        for ($i = 0; $i <= $rk; $i++) $funnelData[$i] += $c;
    }

    // ===== Managers (leads vs won) =====
    $stMgr = $pdo->prepare(
        "SELECT u.id, u.name,
                COUNT(l.id) AS leads,
                SUM(l.status = 'won') AS won
         FROM crm_users u
         JOIN crm_leads l ON l.manager_id = u.id AND l.created_at BETWEEN ? AND ?
         GROUP BY u.id, u.name
         ORDER BY leads DESC"
    );
    $stMgr->execute($range);
    $mgrLabels = $mgrLeads = $mgrWon = [];
    foreach ($stMgr->fetchAll() as $r) {
        $mgrLabels[] = (string)($r['name'] !== '' ? $r['name'] : ('#' . $r['id']));
        $mgrLeads[]  = (int)$r['leads'];
        $mgrWon[]    = (int)$r['won'];
    }

    // Алиасы ключей для совместимости со всеми потребителями
    // (дашборд index.php ждёт values/conversion/response_time/revenue_series; отчёты — data/conv_pct).
    $kpi['conversion']    = $kpi['conv_pct'] ?? 0;
    $kpi['response_time'] = (string)($kpi['avg_response_h'] ?? 0) . ' ч';
    $mk = static fn(array $labels, array $data): array => ['labels' => $labels, 'data' => $data, 'values' => $data];

    json_out([
        'ok'  => true,
        'kpi' => $kpi,
        'timeseries' => $mk($labels, $tsData),
        'by_status'  => $mk($statusLabels, $byStatusData),
        'by_type'    => $mk($typeLabels, $typeData),
        'by_source'  => $mk($srcLabels, $srcData),
        'funnel'     => $mk(['Новые','В работе','Отправлено КП','Сделки'], $funnelData),
        'managers'   => ['labels' => $mgrLabels, 'leads' => $mgrLeads, 'won' => $mgrWon, 'data' => $mgrLeads, 'values' => $mgrLeads],
        'revenue_ts'     => $mk($labels, $revData),
        'revenue_series' => $mk($labels, $revData),
        'range'      => ['from' => $from, 'to' => $to],
    ]);
} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => 'Внутренняя ошибка'], 500);
}

/** Кол-во лидов в диапазоне дат. */
function count_leads(PDO $pdo, string $start, string $end): int {
    $st = $pdo->prepare('SELECT COUNT(*) FROM crm_leads WHERE created_at BETWEEN ? AND ?');
    $st->execute([$start, $end]);
    return (int)$st->fetchColumn();
}
