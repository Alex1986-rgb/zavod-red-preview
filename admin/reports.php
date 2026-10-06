<?php
declare(strict_types=1);
/**
 * Отчёты — аналитика по воронке за период: KPI с дельтой к прошлому периоду,
 * динамика, по типам/источникам, менеджеры, конверсия воронки, среднее время обработки.
 * Экспорт CSV/XLSX (api/export.php) + PDF (печать) + выгрузка аудиторий ретаргетинга.
 * Данные — server-side из crm_leads/crm_events.
 */
require __DIR__ . '/_guard.php';
require __DIR__ . '/_layout.php';

$pdo = pdo();
$dh = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$money = fn($n) => number_format((float)$n, 0, ',', ' ') . ' ₽';

// ---- период ----
$isDate = static fn($v): bool => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false;
$to   = $isDate($_GET['to'] ?? null)   ? $_GET['to']   : date('Y-m-d');
$from = $isDate($_GET['from'] ?? null) ? $_GET['from'] : date('Y-m-d', strtotime('-29 days'));
if (strtotime($from) > strtotime($to)) [$from, $to] = [$to, $from];
$fromDt = $from . ' 00:00:00'; $toDt = $to . ' 23:59:59';
$span = max(1, (int)round((strtotime($to) - strtotime($from)) / 86400) + 1);
$pFrom = date('Y-m-d', strtotime("$from -$span days")) . ' 00:00:00';
$pTo   = date('Y-m-d', strtotime("$from -1 day")) . ' 23:59:59';

$mgrFilter  = (int)($_GET['manager'] ?? 0);
$srcFilter  = trim((string)($_GET['source'] ?? ''));
$typeFilter = trim((string)($_GET['type'] ?? ''));
// Единый набор условий фильтров (алиас l = crm_leads) — подставляется во все запросы страницы.
$fltCond = ''; $fltParams = [];
if ($mgrFilter)         { $fltCond .= " AND l.manager_id = ?"; $fltParams[] = $mgrFilter; }
if ($srcFilter !== '')  { $fltCond .= " AND (l.utm_source = ? OR l.source = ?)"; $fltParams[] = $srcFilter; $fltParams[] = $srcFilter; }
if ($typeFilter !== '') { $fltCond .= " AND l.reducer_type = ?"; $fltParams[] = $typeFilter; }
// Для запросов только по crm_events — тот же фильтр через EXISTS по lead_id (при пустых фильтрах запрос не меняется).
$evFlt = fn(string $al): string => $fltCond === '' ? '' : " AND EXISTS (SELECT 1 FROM crm_leads l WHERE l.id = {$al}.lead_id{$fltCond})";

$transTo = function(string $status, string $a, string $b) use ($pdo, $evFlt, $fltParams): int {
    try { $st = $pdo->prepare("SELECT COUNT(*) FROM crm_events e WHERE e.type='status_changed' AND e.created_at BETWEEN ? AND ? AND e.payload LIKE ?" . $evFlt('e')); $st->execute(array_merge([$a, $b, '%"to":"' . $status . '"%'], $fltParams)); return (int)$st->fetchColumn(); } catch (Throwable $e) { return 0; }
};
$countNew = function(string $a, string $b) use ($pdo, $fltCond, $fltParams): int {
    try { $st = $pdo->prepare("SELECT COUNT(*) FROM crm_leads l WHERE l.created_at BETWEEN ? AND ?" . $fltCond); $st->execute(array_merge([$a, $b], $fltParams)); return (int)$st->fetchColumn(); } catch (Throwable $e) { return 0; }
};
$revSum = function(string $a, string $b) use ($pdo, $fltCond, $fltParams): float {
    try { $st = $pdo->prepare("SELECT COALESCE(SUM(l.amount),0) FROM crm_leads l WHERE l.status='won' AND l.updated_at BETWEEN ? AND ?" . $fltCond); $st->execute(array_merge([$a, $b], $fltParams)); return (float)$st->fetchColumn(); } catch (Throwable $e) { return 0; }
};

$cNew = $countNew($fromDt, $toDt);   $pNew = $countNew($pFrom, $pTo);
$cAppr = $transTo('approved', $fromDt, $toDt); $pAppr = $transTo('approved', $pFrom, $pTo);
$cSent = $transTo('sent', $fromDt, $toDt);     $pSent = $transTo('sent', $pFrom, $pTo);
$cWon  = $transTo('won', $fromDt, $toDt);        $pWon  = $transTo('won', $pFrom, $pTo);
$cRev  = $revSum($fromDt, $toDt);   $pRev = $revSum($pFrom, $pTo);
$delta = fn($cur, $prev) => $prev > 0 ? (int)round(($cur - $prev) / $prev * 100) : ($cur > 0 ? 100 : 0);

$KPI = [
    ['label'=>'Новые заявки','value'=>number_format($cNew,0,',',' '),'delta'=>$delta($cNew,$pNew),'color'=>'#3b82f6','bg'=>'#e0edff','icon'=>'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z M14 2v6h6'],
    ['label'=>'Одобрено КП','value'=>number_format($cAppr,0,',',' '),'delta'=>$delta($cAppr,$pAppr),'color'=>'#0ea5e9','bg'=>'#e0f2fe','icon'=>'M22 11.08V12a10 10 0 1 1-5.93-9.14 M22 4 12 14.01l-3-3'],
    ['label'=>'Отправлено КП','value'=>number_format($cSent,0,',',' '),'delta'=>$delta($cSent,$pSent),'color'=>'#8b5cf6','bg'=>'#ede9fe','icon'=>'M22 2 11 13 M22 2l-7 20-4-9-9-4z'],
    ['label'=>'Выиграно сделок','value'=>number_format($cWon,0,',',' '),'delta'=>$delta($cWon,$pWon),'color'=>'#10b981','bg'=>'#d1fae5','icon'=>'M8 21h8 M12 17v4 M7 4h10v5a5 5 0 0 1-10 0z'],
    ['label'=>'Выручка','value'=>$money($cRev),'delta'=>$delta($cRev,$pRev),'color'=>'#e11b1b','bg'=>'#fde7e7','icon'=>'M12 1v22 M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6'],
];

// динамика новых заявок по дням
$dynLabels = []; $dynData = []; $rowsDay = [];
try { $st = $pdo->prepare("SELECT DATE(l.created_at) d, COUNT(*) c FROM crm_leads l WHERE l.created_at BETWEEN ? AND ?" . $fltCond . " GROUP BY d"); $st->execute(array_merge([$fromDt, $toDt], $fltParams)); foreach ($st as $r) $rowsDay[(string)$r['d']] = (int)$r['c']; } catch (Throwable $e) {}
for ($i = 0; $i < $span; $i++) { $d = date('Y-m-d', strtotime("$from +$i days")); $dynLabels[] = date('d.m', strtotime($d)); $dynData[] = $rowsDay[$d] ?? 0; }

// по типам
$TYPES = [];
try { $st = $pdo->prepare("SELECT IF(l.reducer_type='' OR l.reducer_type IS NULL,'Не указан',l.reducer_type) t, COUNT(*) c FROM crm_leads l WHERE l.created_at BETWEEN ? AND ?" . $fltCond . " GROUP BY t ORDER BY c DESC LIMIT 6"); $st->execute(array_merge([$fromDt, $toDt], $fltParams)); $TYPES = $st->fetchAll(); } catch (Throwable $e) {}
$typeMax = max(1, ...array_map(fn($x) => (int)$x['c'], $TYPES ?: [['c'=>1]]));

// по источникам
$SRC = [];
try { $st = $pdo->prepare("SELECT COALESCE(NULLIF(l.utm_source,''),NULLIF(l.source,''),'Другое') s, COUNT(*) c FROM crm_leads l WHERE l.created_at BETWEEN ? AND ?" . $fltCond . " GROUP BY s ORDER BY c DESC LIMIT 6"); $st->execute(array_merge([$fromDt, $toDt], $fltParams)); $SRC = $st->fetchAll(); } catch (Throwable $e) {}
$srcTotal = max(1, array_sum(array_map(fn($x) => (int)$x['c'], $SRC)));

// менеджеры
$MGRS = [];
try {
    $st = $pdo->prepare("SELECT u.id, u.name, u.login, COUNT(l.id) leads, SUM(l.status='won') won,
        COALESCE(SUM(CASE WHEN l.status='won' THEN l.amount ELSE 0 END),0) revenue
      FROM crm_users u LEFT JOIN crm_leads l ON l.manager_id=u.id AND l.created_at BETWEEN ? AND ?" . $fltCond . "
      WHERE u.active=1 AND u.role IN ('manager','admin')" . ($mgrFilter ? " AND u.id = " . $mgrFilter : '') . " GROUP BY u.id ORDER BY leads DESC");
    $st->execute(array_merge([$fromDt, $toDt], $fltParams));
    foreach ($st as $r) {
        $rt = null;
        try {
            $q = $pdo->prepare("SELECT AVG(TIMESTAMPDIFF(MINUTE,l.created_at,e.created_at)) FROM crm_leads l
                JOIN crm_events e ON e.lead_id=l.id AND e.type='status_changed'
                WHERE l.manager_id=? AND l.created_at BETWEEN ? AND ?" . $fltCond . "
                AND e.created_at=(SELECT MIN(created_at) FROM crm_events e2 WHERE e2.lead_id=l.id AND e2.type='status_changed')");
            $q->execute(array_merge([(int)$r['id'], $fromDt, $toDt], $fltParams)); $rt = $q->fetchColumn();
        } catch (Throwable $e) {}
        $conv = (int)$r['leads'] > 0 ? round((int)$r['won'] / (int)$r['leads'] * 100, 1) : 0;
        $MGRS[] = ['name'=>($r['name'] ?: $r['login']),'leads'=>(int)$r['leads'],'rt'=>$rt !== null && $rt !== false ? (int)round((float)$rt) : null,'conv'=>$conv,'revenue'=>(float)$r['revenue']];
    }
} catch (Throwable $e) {}

// воронка конверсии
$fNew = $cNew ?: 1;
$FUNNEL = [
    ['Новые заявки', $cNew, 100.0, '#3b82f6'],
    ['Отправлено КП', $cSent, round($cSent / $fNew * 100, 1), '#10b981'],
    ['Одобрено КП', $cAppr, round($cAppr / $fNew * 100, 1), '#f59e0b'],
    ['Выиграно сделок', $cWon, round($cWon / $fNew * 100, 1), '#e11b1b'],
];
$convRate = round($cWon / $fNew * 100, 1);

// среднее время между этапами
$fmtDur = function($min): string {
    if ($min === null || $min === false) return '—';
    $min = (int)round((float)$min);
    if ($min < 60) return $min . ' мин';
    if ($min < 1440) return floor($min / 60) . ' ч ' . ($min % 60) . ' мин';
    return floor($min / 1440) . ' дн. ' . floor(($min % 1440) / 60) . ' ч';
};
$avgBetween = function(?string $f, string $t) use ($pdo, $fromDt, $toDt, $fltCond, $fltParams, $evFlt) {
    try {
        if ($f === null) {
            $q = $pdo->prepare("SELECT AVG(TIMESTAMPDIFF(MINUTE,l.created_at,e.created_at)) FROM crm_leads l JOIN crm_events e ON e.lead_id=l.id AND e.type='status_changed' AND e.payload LIKE ? WHERE l.created_at BETWEEN ? AND ?" . $fltCond);
            $q->execute(array_merge(['%"to":"' . $t . '"%', $fromDt, $toDt], $fltParams));
        } else {
            $q = $pdo->prepare("SELECT AVG(TIMESTAMPDIFF(MINUTE,e1.created_at,e2.created_at)) FROM crm_events e1 JOIN crm_events e2 ON e2.lead_id=e1.lead_id AND e2.payload LIKE ? WHERE e1.type='status_changed' AND e1.payload LIKE ? AND e2.created_at>e1.created_at AND e1.created_at BETWEEN ? AND ?" . $evFlt('e1'));
            $q->execute(array_merge(['%"to":"' . $t . '"%', '%"to":"' . $f . '"%', $fromDt, $toDt], $fltParams));
        }
        return $q->fetchColumn();
    } catch (Throwable $e) { return null; }
};
$TIMES = [
    ['⏱','Первичный ответ', $fmtDur($avgBetween(null, 'in_progress'))],
    ['📄','Подготовка КП', $fmtDur($avgBetween('in_progress', 'sent'))],
    ['✍','Согласование КП', $fmtDur($avgBetween('sent', 'approved'))],
    ['✔','Закрытие сделки', $fmtDur($avgBetween(null, 'won'))],
];

// фильтры
$allMgrs = $allSrc = $allTypes = [];
try { $allMgrs = $pdo->query("SELECT id, name, login FROM crm_users WHERE active=1 ORDER BY name")->fetchAll(); } catch (Throwable $e) {}
try { $allSrc = $pdo->query("SELECT DISTINCT COALESCE(NULLIF(utm_source,''),NULLIF(source,'')) s FROM crm_leads WHERE COALESCE(NULLIF(utm_source,''),NULLIF(source,'')) IS NOT NULL ORDER BY s")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) {}
try { $allTypes = $pdo->query("SELECT DISTINCT reducer_type FROM crm_leads WHERE reducer_type<>'' ORDER BY reducer_type")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) {}
// Ключи для эндпоинта экспорта: manager_id/reducer_type ('type' в export.php зарезервирован под формат csv/xlsx).
$qs = http_build_query(array_filter(['from'=>$from,'to'=>$to,'manager_id'=>$mgrFilter ?: '','source'=>$srcFilter,'reducer_type'=>$typeFilter], fn($v)=>$v!==''));

render_head('Отчёты', true);
render_sidebar('reports');
?>
<style>
/* Плитки KPI: сетку (.rep-kpi), фон, рамку, поля, размер значка и шрифты задаёт слой ПОЛКИ в admin.css.
   Здесь — только то, чего у общей плитки нет: дельта к прошлому периоду и подпись под ней. */
.rcard{transition:box-shadow .14s ease,transform .14s ease,border-color .14s ease}
.rcard:hover{box-shadow:var(--shadow-md);transform:translateY(-1px)}
.rcard__ico{display:grid;place-items:center}
.rcard__val{color:var(--ink)}
.rcard__delta{font-size:12px;font-weight:700}.rcard__delta.up{color:#059669}.rcard__delta.down{color:#dc2626}
.rcard__sub{font-size:11.5px;color:var(--muted)}
/* Панель фильтров .rep-filter, её поля (.fld, label) и «распорка» — в слое ПОЛКИ; поля — общий .input.
   У общего .input стоит appearance:none — у выпадающего списка пропадает стрелка; возвращаем её списку. */
.rep-filter select.input{appearance:auto}
/* ряды карточек: промежутки задаёт слой ПОЛКИ; карточка и её заголовок — общие .card / .card__title */
.rep-row3{display:grid;grid-template-columns:1.15fr 1fr 1fr}
.rep-row3b{display:grid;grid-template-columns:1.5fr 1fr 1fr}
@media(max-width:1000px){.rep-row3,.rep-row3b{grid-template-columns:1fr}}
.chart-wrap{position:relative;height:230px}.chart-wrap--sm{height:200px}
.hbar{display:grid;grid-template-columns:150px 1fr 34px;gap:10px;align-items:center;margin-bottom:11px;font-size:13px}
.hbar__nm{color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.hbar__track{background:var(--bg);border-radius:6px;height:16px;overflow:hidden}
.hbar__bar{height:100%;background:#3b82f6;border-radius:6px}
.hbar__n{text-align:right;font-weight:700;color:var(--ink)}
.src-wrap{display:flex;gap:14px;align-items:center}
.src-legend{flex:1;min-width:0}
.src-item{display:flex;align-items:center;gap:8px;font-size:13px;padding:4px 0}
.src-item .dot{width:9px;height:9px;border-radius:50%;flex:none}
.src-item .nm{flex:1;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.src-item b{color:var(--ink)}.src-item span.p{color:var(--muted);font-size:12px}
/* менеджеры: вид таблицы — общий .tbl; здесь только числа (и их заголовки) по правому краю */
table.mgr .num{text-align:right;font-variant-numeric:tabular-nums}
.rfunnel{display:flex;flex-direction:column;align-items:center;gap:3px}
.rfunnel__seg{color:#fff;font-weight:700;font-size:13px;text-align:center;padding:11px 0;border-radius:4px}
.rfunnel__conv{margin-top:10px;font-size:13px;color:var(--muted);text-align:center}
.rfunnel__conv b{color:#059669;font-size:15px}
.rtime{display:flex;justify-content:space-between;align-items:center;padding:11px 0;border-bottom:1px solid var(--line);font-size:13.5px}
.rtime:last-child{border-bottom:0}
.rtime__l{display:flex;align-items:center;gap:9px;color:var(--text)}
.rtime__v{font-weight:700;color:var(--ink)}
@media print{.sidebar,.appbar,.rep-filter,.topbar{display:none!important}.content{margin:0!important}}
</style>

<div class="page-head"><h1 class="page-title">Отчёты</h1></div>

<section class="rep-kpi">
  <?php foreach ($KPI as $k): $up = $k['delta'] >= 0; ?>
  <div class="rcard">
    <div class="rcard__ico" style="background:<?= $k['bg'] ?>;color:<?= $k['color'] ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="<?= $k['icon'] ?>"/></svg></div>
    <div class="rcard__label"><?= $dh($k['label']) ?></div>
    <div class="rcard__val"><?= $dh($k['value']) ?></div>
    <div class="rcard__delta <?= $up ? 'up' : 'down' ?>"><?= $up ? '+' : '' ?><?= $k['delta'] ?>%</div>
    <div class="rcard__sub">vs. прошлый период той же длины</div>
  </div>
  <?php endforeach; ?>
</section>

<form class="rep-filter" method="get" onsubmit="var f=this.from.value,t=this.to.value;if(f&&t&&f>t){if(window.ZR&&ZR.toast)ZR.toast('«С даты» позже «По дату» — поменяйте местами','error');return false;}">
  <div class="fld"><label>Период с</label><input class="input" type="date" name="from" value="<?= $dh($from) ?>"></div>
  <div class="fld"><label>по</label><input class="input" type="date" name="to" value="<?= $dh($to) ?>"></div>
  <div class="fld"><label>Менеджер</label><select class="input" name="manager"><option value="">Все</option><?php foreach ($allMgrs as $m): ?><option value="<?= (int)$m['id'] ?>" <?= $mgrFilter === (int)$m['id'] ? 'selected' : '' ?>><?= $dh($m['name'] ?: $m['login']) ?></option><?php endforeach; ?></select></div>
  <div class="fld"><label>Источник</label><select class="input" name="source"><option value="">Все</option><?php foreach ($allSrc as $s): ?><option value="<?= $dh($s) ?>" <?= $srcFilter === $s ? 'selected' : '' ?>><?= $dh($s) ?></option><?php endforeach; ?></select></div>
  <div class="fld"><label>Тип редуктора</label><select class="input" name="type"><option value="">Все</option><?php foreach ($allTypes as $t): ?><option value="<?= $dh($t) ?>" <?= $typeFilter === $t ? 'selected' : '' ?>><?= $dh($t) ?></option><?php endforeach; ?></select></div>
  <button class="btn btn--primary" type="submit">Применить</button>
  <a class="btn btn--ghost" href="reports.php">Сбросить</a>
  <div class="spacer"></div>
  <button class="btn btn--ghost" type="button" onclick="window.print()">🖨 Печать / PDF</button>
  <a class="btn btn--ghost" href="../api/export.php?type=csv&<?= $dh($qs) ?>">CSV</a>
  <a class="btn btn--ghost" href="../api/export.php?type=xlsx&<?= $dh($qs) ?>">Excel</a>
</form>

<section class="rep-row3">
  <div class="card"><h2 class="card__title">Динамика заявок</h2><div class="chart-wrap"><canvas id="ch_dyn"></canvas></div></div>
  <div class="card"><h2 class="card__title">Заявки по типу запроса</h2>
    <?php foreach ($TYPES as $t): $w = round((int)$t['c'] / $typeMax * 100); ?>
    <div class="hbar"><div class="hbar__nm"><?= $dh($t['t']) ?></div><div class="hbar__track"><div class="hbar__bar" style="width:<?= max(4,$w) ?>%"></div></div><div class="hbar__n"><?= (int)$t['c'] ?></div></div>
    <?php endforeach; ?>
    <?php if (!$TYPES): ?><div class="empty">За выбранный период заявок нет — измените даты или фильтры</div><?php endif; ?>
  </div>
  <div class="card"><h2 class="card__title">Заявки по источнику</h2>
    <div class="src-wrap">
      <div class="chart-wrap chart-wrap--sm" style="width:170px;flex:0 0 170px"><canvas id="ch_src"></canvas></div>
      <div class="src-legend">
        <?php $pal = ['#3b82f6','#10b981','#f59e0b','#8b5cf6','#0ea5e9','#94a3b8']; foreach ($SRC as $i => $s): ?>
        <div class="src-item"><span class="dot" style="background:<?= $pal[$i % 6] ?>"></span><span class="nm"><?= $dh($s['s']) ?></span><b><?= (int)$s['c'] ?></b> <span class="p">(<?= round((int)$s['c'] / $srcTotal * 100, 1) ?>%)</span></div>
        <?php endforeach; ?>
        <?php if (!$SRC): ?><div class="empty">Нет данных</div><?php endif; ?>
      </div>
    </div>
  </div>
</section>

<section class="rep-row3b">
  <div class="card"><h2 class="card__title">Менеджеры / инженеры</h2>
    <div class="table-wrap" style="border:0;box-shadow:none;border-radius:0"><table class="tbl mgr">
      <thead><tr><th>Менеджер</th><th class="num">Заявки</th><th class="num">Ср. ответ</th><th class="num">Конверсия</th><th class="num">Выручка</th></tr></thead>
      <tbody>
        <?php foreach ($MGRS as $m): ?>
        <tr><td><b><?= $dh($m['name']) ?></b></td><td class="num"><?= $m['leads'] ?></td><td class="num"><?= $m['rt'] !== null ? $fmtDur($m['rt']) : '—' ?></td><td class="num"><?= $m['conv'] ?>%</td><td class="num"><?= $money($m['revenue']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$MGRS): ?><tr><td colspan="5"><div class="empty">Нет данных</div></td></tr><?php endif; ?>
      </tbody>
    </table></div>
  </div>
  <div class="card"><h2 class="card__title">Конверсия воронки</h2>
    <div class="rfunnel">
      <?php foreach ($FUNNEL as $f): $w = 55 + 45 * ($f[2] / 100); ?>
      <div class="rfunnel__seg" style="width:<?= round($w) ?>%;background:<?= $f[3] ?>"><?= (int)$f[1] ?> (<?= $f[2] ?>%)</div>
      <?php endforeach; ?>
    </div>
    <div class="rfunnel__conv">Конверсия в сделку: <b><?= $convRate ?>%</b></div>
  </div>
  <div class="card"><h2 class="card__title">Среднее время обработки</h2>
    <?php foreach ($TIMES as $t): ?>
    <div class="rtime"><div class="rtime__l"><span><?= $t[0] ?></span><?= $dh($t[1]) ?></div><div class="rtime__v"><?= $dh($t[2]) ?></div></div>
    <?php endforeach; ?>
    <div class="rcard__sub" style="margin-top:8px">Среднее по всем заявкам за выбранный период</div>
  </div>
</section>

<section class="card">
  <h2 class="card__title">Ретаргетинг — выгрузка аудиторий (SHA-256)</h2>
  <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center">
    <a class="btn btn--ghost" href="../api/export.php?type=audience&<?= $dh($qs) ?>">Все контакты</a>
    <a class="btn btn--ghost" href="../api/export.php?type=audience&status=won&<?= $dh($qs) ?>">Выигранные (look-alike)</a>
    <a class="btn btn--ghost" href="../api/export.php?type=audience&status=lost&<?= $dh($qs) ?>">Проигранные (win-back)</a>
    <span class="rcard__sub">Телефоны и почты в виде хэшей SHA-256 — загружаются в Яндекс.Аудитории, VK и Google Customer Match без раскрытия персональных данных.</span>
  </div>
</section>

<script>
(function () {
  if (typeof Chart === 'undefined') return;
  Chart.defaults.font.family = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
  Chart.defaults.color = '#64748b';
  var dyn = document.getElementById('ch_dyn');
  if (dyn) new Chart(dyn, {
    type: 'line',
    data: { labels: <?= json_encode($dynLabels) ?>, datasets: [{ label: 'Новые заявки', data: <?= json_encode($dynData) ?>, borderColor: '#3b82f6', backgroundColor: '#3b82f622', fill: true, tension: 0.35, pointRadius: 2 }] },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: true, position: 'top', labels: { boxWidth: 10, usePointStyle: true } } }, scales: { y: { beginAtZero: true } } }
  });
  var src = document.getElementById('ch_src');
  var SD = <?= json_encode(array_map(fn($x) => (int)$x['c'], $SRC)) ?>;
  if (src && SD.length) new Chart(src, {
    type: 'doughnut',
    data: { labels: <?= json_encode(array_map(fn($x) => $x['s'], $SRC), JSON_UNESCAPED_UNICODE) ?>, datasets: [{ data: SD, backgroundColor: ['#3b82f6','#10b981','#f59e0b','#8b5cf6','#0ea5e9','#94a3b8'], borderWidth: 0 }] },
    options: { responsive: true, maintainAspectRatio: false, cutout: '64%', plugins: { legend: { display: false } } }
  });
})();
</script>
<?php render_foot();
