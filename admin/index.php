<?php
declare(strict_types=1);

require __DIR__ . '/_guard.php';
require __DIR__ . '/_layout.php';

render_head('Дашборд', true);
render_sidebar('index');

/* ============ Данные дашборда (server-side, из crm_leads) ============ */
$pdo = pdo();
$dh = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$fmtMoney = fn($n) => number_format((float)$n, 0, ',', ' ') . ' ₽';
$isAdmin = ((current_user() ?? [])['role'] ?? '') === 'admin';

// Письма из ящика (source='email') — это раздел «Почта», а не заявки. На дашборде
// они не считаются: иначе десятки писем в день превращают сводку в счётчик переписки.
const NOMAIL = "COALESCE(source,'') <> 'email'";

// текущее распределение по статусам
$sc = [];
try { foreach ($pdo->query("SELECT status, COUNT(*) c FROM crm_leads WHERE " . NOMAIL . " GROUP BY status") as $r) $sc[(string)$r['status']] = (int)$r['c']; } catch (Throwable $e) {}
$g = fn($k) => (int)($sc[$k] ?? 0);
$grpWork = ['in_progress','clarify','picked','review','rework','approved'];
$cNew  = $g('new');
$cWork = array_sum(array_map($g, $grpWork));
$cEng  = $g('review') + $g('picked') + $g('rework'); // ждут инженера
$cSent = $g('sent') + $g('quoted');
$cWon  = $g('won');
$totalActive = max(1, $cNew + $cWork + $cSent + $cWon);
$revenue = 0.0;
try { $revenue = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM crm_leads WHERE status='won'")->fetchColumn(); } catch (Throwable $e) {}

// «+N за неделю» — созданных за 7 дней (для «новых») / перешедших (прочее приблизительно по updated_at)
$dw = function(string $cond) use ($pdo): int {
    try { return (int)$pdo->query("SELECT COUNT(*) FROM crm_leads WHERE (" . NOMAIL . ") AND ($cond)")->fetchColumn(); } catch (Throwable $e) { return 0; }
};
$wk = "'" . date('Y-m-d H:i:s', strtotime('-7 days')) . "'";
$dNew  = $dw("created_at >= $wk");
$dWork = $dw("updated_at >= $wk AND status IN ('in_progress','clarify','picked','review','rework','approved')");
$dEng  = $dw("updated_at >= $wk AND status IN ('review','picked','rework')");
$dSent = $dw("updated_at >= $wk AND status IN ('sent','quoted')");
$dWon  = $dw("updated_at >= $wk AND status = 'won'");

// 14-дневные спарклайны по группам статусов (по дате создания)
$spark = function(array $statuses) use ($pdo): array {
    $in = "'" . implode("','", $statuses) . "'";
    $rows = [];
    try { foreach ($pdo->query("SELECT DATE(created_at) d, COUNT(*) c FROM crm_leads WHERE status IN ($in) AND " . NOMAIL . " AND created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) GROUP BY d") as $r) $rows[(string)$r['d']] = (int)$r['c']; } catch (Throwable $e) {}
    $out = [];
    for ($i = 13; $i >= 0; $i--) { $out[] = $rows[date('Y-m-d', strtotime("-$i days"))] ?? 0; }
    return $out;
};
$revSpark = [];
try {
    $rr = [];
    foreach ($pdo->query("SELECT DATE(updated_at) d, SUM(amount) s FROM crm_leads WHERE status='won' AND updated_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) GROUP BY d") as $r) $rr[(string)$r['d']] = (float)$r['s'];
    for ($i = 13; $i >= 0; $i--) { $revSpark[] = $rr[date('Y-m-d', strtotime("-$i days"))] ?? 0; }
} catch (Throwable $e) { $revSpark = array_fill(0, 14, 0); }

$CARDS = [
    ['key'=>'new','label'=>'Новые заявки','value'=>$cNew,'delta'=>$dNew,'color'=>'#3b82f6','bg'=>'#e0edff','series'=>$spark(['new']),'icon'=>'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z M14 2v6h6'],
    ['key'=>'work','label'=>'В работе','value'=>$cWork,'delta'=>$dWork,'color'=>'#f59e0b','bg'=>'#fef3c7','series'=>$spark($grpWork),'icon'=>'M12 6v6l4 2 M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20z'],
    ['key'=>'engineer','label'=>'Ждут инженера','value'=>$cEng,'delta'=>$dEng,'color'=>'#6d28d9','bg'=>'#ede9fe','series'=>$spark(['review','picked','rework']),'icon'=>'M9 11l3 3L20 4 M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11','href'=>'engineer.php'],
    ['key'=>'sent','label'=>'Отправлено КП','value'=>$cSent,'delta'=>$dSent,'color'=>'#8b5cf6','bg'=>'#ede9fe','series'=>$spark(['sent','quoted']),'icon'=>'M22 2 11 13 M22 2l-7 20-4-9-9-4z'],
    ['key'=>'won','label'=>'Сделки','value'=>$cWon,'delta'=>$dWon,'color'=>'#10b981','bg'=>'#d1fae5','series'=>$spark(['won']),'icon'=>'M20 6 9 17l-5-5'],
    ['key'=>'rev','label'=>'Выручка','value'=>$fmtMoney($revenue),'delta'=>null,'color'=>'#e11b1b','bg'=>'#fde7e7','series'=>$revSpark,'icon'=>'M12 1v22 M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6'],
];

// воронка продаж — НЕПЕРЕСЕКАЮЩИЕСЯ этапы (каждый лид ровно в одном), % от суммы воронки
$fWorkOnly = $g('in_progress') + $g('clarify') + $g('picked') + $g('rework'); // без review
$FUNNEL = [
    ['Новые заявки', $cNew, '#3b82f6'],
    ['В работе', $fWorkOnly, '#f59e0b'],
    ['На проверке', $g('review'), '#8b5cf6'],
    ['Согласование / КП', $g('approved') + $cSent, '#0ea5e9'],
    ['Сделки', $cWon, '#10b981'],
];
$funnelMax = max(1, ...array_map(fn($r) => $r[1], $FUNNEL));
$funnelSum = max(1, array_sum(array_map(fn($r) => $r[1], $FUNNEL)));
$conv = $totalActive ? round($cWon / $totalActive * 100) : 0;

// источники
$SOURCES = [];
try { foreach ($pdo->query("SELECT COALESCE(NULLIF(utm_source,''),NULLIF(source,''),'Прямой') s, COUNT(*) c FROM crm_leads WHERE " . NOMAIL . " GROUP BY s ORDER BY c DESC LIMIT 6") as $r) $SOURCES[] = ['label'=>(string)$r['s'],'count'=>(int)$r['c']]; } catch (Throwable $e) {}
$srcTotal = max(1, array_sum(array_map(fn($x) => $x['count'], $SOURCES)));

// популярные типы
$TYPES = [];
try { foreach ($pdo->query("SELECT IF(reducer_type='' OR reducer_type IS NULL,'Не указан',reducer_type) t, COUNT(*) c FROM crm_leads WHERE " . NOMAIL . " GROUP BY t ORDER BY c DESC LIMIT 4") as $r) $TYPES[] = ['label'=>(string)$r['t'],'count'=>(int)$r['c']]; } catch (Throwable $e) {}
$typeTotal = max(1, array_sum(array_map(fn($x) => $x['count'], $TYPES)));

// загрузка инженеров/менеджеров (активные лиды)
$ENG = [];
try { foreach ($pdo->query("SELECT u.name, u.login, COUNT(l.id) c FROM crm_users u LEFT JOIN crm_leads l ON l.manager_id=u.id AND l.status NOT IN ('won','lost') WHERE u.active=1 AND u.role IN ('manager','admin') GROUP BY u.id ORDER BY c DESC LIMIT 4") as $r) $ENG[] = ['name'=>($r['name'] ?: $r['login']),'count'=>(int)$r['c']]; } catch (Throwable $e) {}
$engMax = max(1, ...array_map(fn($x) => $x['count'], $ENG ?: [['count'=>1]]));

// последние заявки
$RECENT = [];
try { $st = $pdo->query("SELECT l.id,l.created_at,l.name,l.phone,l.reducer_type,l.status,COALESCE(NULLIF(l.utm_source,''),NULLIF(l.source,''),'—') src,u.name mgr FROM crm_leads l LEFT JOIN crm_users u ON u.id=l.manager_id WHERE COALESCE(l.source,'') <> 'email' ORDER BY l.created_at DESC LIMIT 5"); $RECENT = $st->fetchAll(); } catch (Throwable $e) {}

// динамика: текущая неделя vs прошлая (по дням недели)
$curWeek = array_fill(0, 7, 0); $prevWeek = array_fill(0, 7, 0);
try {
    foreach ($pdo->query("SELECT DATE(created_at) d, COUNT(*) c FROM crm_leads WHERE " . NOMAIL . " AND created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) GROUP BY d") as $r) {
        $day = (string)$r['d']; $diff = (int)((strtotime(date('Y-m-d')) - strtotime($day)) / 86400);
        if ($diff >= 0 && $diff < 7) $curWeek[6 - $diff] = (int)$r['c'];
        elseif ($diff >= 7 && $diff < 14) $prevWeek[13 - $diff] = (int)$r['c'];
    }
} catch (Throwable $e) {}
$STLBL = [];
foreach (funnel() as $code => $m) $STLBL[$code] = $m[0];
?>
<div class="page-head">
  <h1 class="page-title">Дашборд</h1>
  <span class="page-lead">Данные обновляются в реальном времени · воронка «заявка → инженер → КП → продажа»</span>
</div>

<style>
/* Плитки KPI: сетку (.dash-kpi), фон, рамку, поля, размер значка и шрифты задаёт слой ПОЛКИ в admin.css.
   Здесь — только то, чего у общей плитки нет: ссылка-плитка, «+N за неделю» и спарклайн. */
.dcard{position:relative;overflow:hidden;transition:border-color .14s,box-shadow .14s}
a.dcard{text-decoration:none;cursor:pointer;transition:transform .14s,border-color .14s,box-shadow .14s}
a.dcard:hover{border-color:var(--red);transform:translateY(-2px);box-shadow:var(--shadow-md)}
a.dcard::after{content:"открыть →";position:absolute;right:14px;bottom:10px;font-size:11px;font-weight:700;color:var(--red);opacity:0;transition:.14s}
a.dcard:hover::after{opacity:1}
.dcard__top{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:6px}
.dcard__ico{display:grid;place-items:center}
.dcard__delta{font-size:11px;font-weight:700;color:#059669;background:#ecfdf5;padding:2px 7px;border-radius:var(--r-pill);white-space:nowrap}
.dcard__delta.is-zero{color:var(--muted);background:var(--bg)}
.dcard__val{color:var(--ink)}
/* спарклайн — до краёв плитки: поля плитки из ПОЛОК 16px по бокам и 14px снизу */
.dcard__spark{height:26px;margin:4px -16px -14px}
.spark{width:100%;height:26px;display:block}
/* ряды карточек: промежутки задаёт слой ПОЛКИ */
.dash-row3{display:grid;grid-template-columns:1fr 1.15fr 1fr}
.dash-row2{display:grid;grid-template-columns:1.6fr 1fr}
@media(max-width:1000px){.dash-row3,.dash-row2{grid-template-columns:1fr}}
.chart-wrap--sm{height:70px;flex:0 0 120px;width:120px}
/* заглушка графика отклика прячется атрибутом hidden — display:flex общего .chart-empty его перебивал */
.chart-empty[hidden]{display:none}
/* действие в заголовке карточки — к правому краю (float внутри flex-заголовка не работает) */
.card__title > .card__aside{margin-left:auto}
a.card__aside{font-size:13px;font-weight:600}
/* Аналитика/мониторинг свёрнуты по умолчанию — операционная часть влезает в 1 экран */
.dash-analytics{display:none}
body.analytics-open .dash-analytics{display:grid}
/* переключатель — обычная .btn .btn--ghost .btn--block, текст по левому краю */
.analytics-toggle{justify-content:flex-start}
body.analytics-open .analytics-toggle{color:var(--ink)}
/* списки в виджетах аналитики (SLA, задачи, битые ссылки, онбординг) */
.dw-list{list-style:none;margin:0;padding:0;font-size:13px}
.dw-list > li{padding:4px 0;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;gap:8px}
.dw-list--block > li{display:block;padding:6px 0}
/* воронка */
.funnel__row{display:grid;grid-template-columns:120px 1fr auto;gap:10px;align-items:center;margin-bottom:12px}
.funnel__lbl{font-size:13px;color:var(--text)}
.funnel__track{background:var(--bg);border-radius:var(--r-sm,8px);height:22px;overflow:hidden}
.funnel__bar{height:100%;border-radius:var(--r-sm,8px);transition:width .4s}
.funnel__val{font-size:13px;font-weight:700;color:var(--ink);white-space:nowrap}
.funnel__val span{color:var(--muted);font-weight:600;font-size:12px}
.funnel__conv{margin-top:8px;padding-top:12px;border-top:1px solid var(--line);font-size:13.5px;color:var(--muted)}
.funnel__conv b{color:#059669;font-size:16px}
/* источники */
.src-wrap{display:flex;gap:14px;align-items:center}
.src-legend{flex:1;min-width:0}
.src-item{display:flex;align-items:center;gap:8px;font-size:13px;padding:3px 0}
.src-item .dot{width:9px;height:9px;border-radius:50%;flex:none}
.src-item .src-name{flex:1;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.src-item b{color:var(--ink)}
.src-item .src-n{color:var(--muted);font-size:12px}
.src-total{margin-top:8px;padding-top:8px;border-top:1px solid var(--line);font-size:12.5px;color:var(--muted)}
/* последние заявки: вид таблицы — общий .tbl; здесь только обрезка длинных значений */
table.rl td{overflow:hidden;text-overflow:ellipsis;max-width:150px}
/* Статусы — пока свои плашки: внутри .tbl общее правило .tbl .badge (admin.css) красит все .badge--<статус>,
   кроме «новой», в серый, и цвет этапа пропадает. Перевести на .badge, когда это поправят в admin.css. */
.rl-badge{display:inline-block;font-size:11.5px;font-weight:600;padding:3px 9px;border-radius:var(--r-sm,6px)}
.st-new{background:#e0edff;color:#2563eb}.st-in_progress{background:#fef3c7;color:#b45309}
.st-clarify{background:#fff3e0;color:#d97706}.st-picked{background:#e6f7f2;color:#0d9488}
.st-review{background:#eef2ff;color:#4f46e5}.st-rework{background:#fdeede;color:#c2410c}
.st-approved{background:#dcfce7;color:#16a34a}.st-sent{background:#e0f2fe;color:#0369a1}
.st-won{background:#dcfce7;color:#15803d}.st-lost{background:#fee2e2;color:#dc2626}.st-quoted{background:#ede9fe;color:#6d28d9}
/* быстрые действия / загрузка / типы */
.dash-side{display:flex;flex-direction:column;gap:var(--shelf)}
.dash-side > .card{margin:0 !important} /* перебивает общий .card{margin-bottom:8px !important} — шаг даёт gap */
.qa{display:grid;grid-template-columns:1fr 1fr;gap:9px}
/* кнопки быстрых действий — общие .btn / .btn--primary (поле и шрифт — из системы), здесь только перенос текста */
.qa-btn{text-align:center;line-height:1.3}
.qa-btn:hover{transform:translateY(-1px);box-shadow:var(--shadow-sm)}
.qa-btn:not(.btn--primary):hover{background:var(--bg)}
.load{display:grid;grid-template-columns:110px 1fr 32px;gap:10px;align-items:center;margin-bottom:10px;font-size:13px}
.load__nm{color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.load__track{background:var(--bg);border-radius:var(--r-sm,6px);height:10px;overflow:hidden}
.load__bar{height:100%;background:linear-gradient(90deg,#f59e0b,#e11b1b);border-radius:var(--r-sm,6px)}
.load__pct{text-align:right;font-weight:700;color:var(--ink)}
.ptype{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--line);font-size:13.5px}
.ptype:last-child{border-bottom:0}
.ptype__nm{color:var(--text)}
.ptype__n{color:var(--muted)}.ptype__n b{color:var(--ink);margin-left:4px}
</style>

<div class="help help--info">
  <span class="help__icon">💡</span>
  <div class="help__body">
    <b>Дашборд — сводка по заявкам и здоровью системы.</b> Здесь видно, сколько заявок приходит, как они превращаются в продажи и всё ли в порядке с сайтом и интеграциями.
    <ul>
      <li><b>KPI вверху.</b> Текущее число новых заявок, в работе, ждущих инженера, отправленных КП, сделок и выручка; рядом «+N за неделю» и мини-график за 14 дней. Карточка «Ждут инженера» ведёт на страницу проверки.</li>
      <li><b>Воронка.</b> Распределение активных заявок по этапам (Новые → В работе → На проверке → Согласование → Сделки) и конверсия в сделку.</li>
      <li><b>Графики.</b> Динамика заявок по дням (текущая/прошлая неделя), разбивка по источникам; ниже — популярные типы запросов и загрузка менеджеров.</li>
      <li><b>Внизу.</b> Последние заявки и быстрые действия. Цифры считаются за фиксированные окна (7 и 14 дней) и обновляются при каждой перезагрузке страницы.</li>
    </ul>
  </div>
</div>

<?php
// генератор мини-спарклайна (SVG polyline)
$sparkSvg = function(array $s, string $color): string {
    $n = count($s); if ($n < 2) return '';
    $max = max($s) ?: 1; $min = min($s);
    $w = 120; $h = 34; $rng = ($max - $min) ?: 1;
    $pts = [];
    foreach ($s as $i => $v) {
        $x = round($i / ($n - 1) * $w, 1);
        $y = round($h - 2 - ($v - $min) / $rng * ($h - 4), 1);
        $pts[] = "$x,$y";
    }
    $poly = implode(' ', $pts);
    $area = "0,$h " . $poly . ",$w,$h";
    $id = 'g' . substr(md5($color . $poly), 0, 6);
    return '<svg class="spark" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none">'
        . '<defs><linearGradient id="' . $id . '" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="' . $color . '" stop-opacity=".22"/><stop offset="1" stop-color="' . $color . '" stop-opacity="0"/></linearGradient></defs>'
        . '<polygon points="' . $area . '" fill="url(#' . $id . ')"/>'
        . '<polyline points="' . $poly . '" fill="none" stroke="' . $color . '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/></svg>';
};
?>
<!-- KPI карточки -->
<section class="dash-kpi">
  <?php foreach ($CARDS as $c): $isLink = !empty($c['href']); $tag = $isLink ? 'a' : 'div'; $attr = $isLink ? ' href="' . $dh($c['href']) . '"' : ''; ?>
  <<?= $tag . $attr ?> class="dcard">
    <div class="dcard__top">
      <span class="dcard__ico" style="background:<?= $c['bg'] ?>;color:<?= $c['color'] ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="<?= $c['icon'] ?>"/></svg></span>
      <?php if ($c['delta'] !== null): ?><span class="dcard__delta<?= $c['delta'] > 0 ? '' : ' is-zero' ?>">+<?= (int)$c['delta'] ?> за неделю</span><?php endif; ?>
    </div>
    <div class="dcard__label"><?= $dh($c['label']) ?></div>
    <div class="dcard__val"><?= is_int($c['value']) ? number_format($c['value'], 0, ',', ' ') : $dh($c['value']) ?></div>
    <div class="dcard__spark"><?= $sparkSvg($c['series'], $c['color']) ?></div>
  </<?= $tag ?>>
  <?php endforeach; ?>
</section>

<!-- Воронка · Динамика · Источники -->
<section class="dash-row3">
  <div class="card">
    <h2 class="card__title">Воронка продаж</h2>
    <div class="funnel">
      <?php foreach ($FUNNEL as $f): $w = round($f[1] / $funnelMax * 100); ?>
      <div class="funnel__row">
        <div class="funnel__lbl"><?= $dh($f[0]) ?></div>
        <div class="funnel__track"><div class="funnel__bar" style="width:<?= max(6, $w) ?>%;background:<?= $f[2] ?>"></div></div>
        <div class="funnel__val"><?= (int)$f[1] ?> <span><?= (int)round($f[1] / $funnelSum * 100) ?>%</span></div>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="funnel__conv">Конверсия в сделку: <b><?= $conv ?>%</b></div>
  </div>

  <div class="card">
    <h2 class="card__title">Динамика новых заявок</h2>
    <div class="chart-wrap"><canvas id="ch_dynamics"></canvas></div>
  </div>

  <div class="card">
    <h2 class="card__title">Заявки по источникам</h2>
    <div class="src-wrap">
      <div class="chart-wrap chart-wrap--sm"><canvas id="ch_sources"></canvas></div>
      <div class="src-legend">
        <?php $srcPal = ['#3b82f6','#f59e0b','#10b981','#8b5cf6','#0ea5e9','#64748b']; foreach ($SOURCES as $i => $s): ?>
        <div class="src-item"><span class="dot" style="background:<?= $srcPal[$i % 6] ?>"></span><span class="src-name"><?= $dh($s['label']) ?></span><b><?= (int)round($s['count'] / $srcTotal * 100) ?>%</b><span class="src-n">(<?= $s['count'] ?>)</span></div>
        <?php endforeach; ?>
        <div class="src-total">Всего: <?= array_sum(array_map(fn($x) => $x['count'], $SOURCES)) ?></div>
      </div>
    </div>
  </div>
</section>

<!-- Последние заявки · Быстрые действия / инженеры / типы -->
<section class="dash-row2">
  <div class="card">
    <div class="card__title">Последние заявки <a class="card__aside" href="leads.php">Все заявки →</a></div>
    <div class="table-wrap">
      <table class="tbl rl">
        <thead><tr><th>Дата</th><th>Имя</th><th>Телефон</th><th>Тип</th><th>Источник</th><th>Статус</th><th>Менеджер</th></tr></thead>
        <tbody>
        <?php foreach ($RECENT as $r): ?>
          <tr onclick="location.href='lead.php?id=<?= (int)$r['id'] ?>'">
            <td><?= $dh(date('d.m.Y H:i', strtotime((string)$r['created_at']))) ?></td>
            <td><b><?= $dh($r['name'] ?: '—') ?></b></td>
            <td><?= $dh($r['phone'] ?: '—') ?></td>
            <td><?= $dh($r['reducer_type'] ?: '—') ?></td>
            <td><?= $dh($r['src']) ?></td>
            <td><span class="rl-badge st-<?= $dh($r['status']) ?>"><?= $dh($STLBL[$r['status']] ?? $r['status']) ?></span></td>
            <td><?= $dh($r['mgr'] ?: '—') ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$RECENT): ?><tr><td colspan="7"><div class="empty">Заявок пока нет — они появятся здесь сразу после первой отправки формы с сайта</div></td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="dash-side">
    <div class="card">
      <h2 class="card__title">Быстрые действия</h2>
      <div class="qa">
        <a class="btn btn--primary qa-btn" href="leads.php?new=1"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><path d="M12 5v14M5 12h14"/></svg> Добавить заявку вручную</a>
        <a class="btn btn--primary qa-btn" href="engineer.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><path d="M14.7 6.3a4 4 0 0 1-5.4 5.4L4 17v3h3l5.3-5.3a4 4 0 0 1 5.4-5.4l-2.6 2.6-1.4-1.4 2.6-2.6Z"/></svg> Очередь инженера</a>
        <a class="btn qa-btn" href="mail.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg> Письма</a>
        <a class="btn qa-btn" href="search.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.6-3.6"/></svg> Поиск</a>
      </div>
    </div>
    <div class="card">
      <h2 class="card__title">Загрузка менеджеров</h2>
      <?php foreach ($ENG as $e): $pct = (int)round($e['count'] / $engMax * 100); ?>
      <div class="load"><div class="load__nm"><?= $dh($e['name']) ?></div><div class="load__track"><div class="load__bar" style="width:<?= max(4, $pct) ?>%"></div></div><div class="load__pct"><?= $e['count'] ?></div></div>
      <?php endforeach; ?>
      <?php if (!$ENG): ?><div class="empty">Нет менеджеров с активными заявками</div><?php endif; ?>
    </div>
    <div class="card">
      <h2 class="card__title">Популярные типы запросов</h2>
      <?php foreach ($TYPES as $t): ?>
      <div class="ptype"><span class="ptype__nm"><?= $dh($t['label']) ?></span><span class="ptype__n"><?= $t['count'] ?> <b><?= (int)round($t['count'] / $typeTotal * 100) ?>%</b></span></div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<button type="button" id="toggleAnalytics" class="btn btn--ghost btn--block analytics-toggle">▸ Аналитика и мониторинг</button>

<!-- Наблюдение за сайтом + Трафик Метрики -->
<section class="charts-grid dash-analytics">
  <div class="card card--wide">
    <h2 class="card__title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a14 14 0 0 1 0 18a14 14 0 0 1 0-18Z"/></svg> Наблюдение за сайтом
      <button class="btn btn--ghost card__aside" type="button" id="mon-run">Проверить сейчас</button>
    </h2>
    <div id="mon-summary" class="hint"></div>
    <div id="mon-wrap"><div class="empty">Загрузка…</div></div>
  </div>
  <div class="card">
    <h2 class="card__title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><path d="M3 17l6-6 4 4 8-8"/><path d="M15 7h6v6"/></svg> Трафик (Яндекс.Метрика)</h2>
    <div id="metrika-wrap"><div class="empty">Загрузка…</div></div>
  </div>
</section>

<script>
/* Дашборд: динамика (тек. vs прошлая неделя) + источники (донат). Данные — с сервера. */
(function () {
  if (typeof Chart === 'undefined') return;
  Chart.defaults.font.family = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
  Chart.defaults.color = '#64748b';
  var CUR = <?= json_encode($curWeek) ?>, PREV = <?= json_encode($prevWeek) ?>;
  var SRC = <?= json_encode(array_map(fn($x) => $x['count'], $SOURCES)) ?>;
  var SRCL = <?= json_encode(array_map(fn($x) => $x['label'], $SOURCES), JSON_UNESCAPED_UNICODE) ?>;
  var PAL = ['#3b82f6','#f59e0b','#10b981','#8b5cf6','#0ea5e9','#64748b'];

  var days = ['Пн','Вт','Ср','Чт','Пт','Сб','Вс'];
  var dc = document.getElementById('ch_dynamics');
  if (dc) new Chart(dc, {
    type: 'line',
    data: { labels: days, datasets: [
      { label: 'Текущая неделя', data: CUR, borderColor: '#3b82f6', backgroundColor: '#3b82f622', fill: true, tension: 0.35, pointRadius: 3 },
      { label: 'Прошлая неделя', data: PREV, borderColor: '#94a3b8', borderDash: [5,4], fill: false, tension: 0.35, pointRadius: 0 }
    ] },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'top', labels: { boxWidth: 12, usePointStyle: true } } }, scales: { y: { beginAtZero: true } } }
  });

  var sc = document.getElementById('ch_sources');
  if (sc && SRC.length) new Chart(sc, {
    type: 'doughnut',
    data: { labels: SRCL, datasets: [{ data: SRC, backgroundColor: PAL, borderWidth: 0 }] },
    options: { responsive: true, maintainAspectRatio: false, cutout: '62%', plugins: { legend: { display: false } } }
  });
})();
</script>

<script>
/* Виджеты: наблюдение за сайтом + трафик Яндекс.Метрики */
(function () {
  var esc = ZR.escapeHtml;

  function shortUrl(u) {
    return String(u || '').replace(/^https?:\/\//, '').replace(/\/$/, '') || '/';
  }

  function renderStatus(res) {
    var wrap = document.getElementById('mon-wrap');
    var sum = document.getElementById('mon-summary');
    var rows = (res && res.results) || [];
    if (!rows.length) {
      wrap.innerHTML = '<div class="empty">Запустите проверку</div>';
      sum.textContent = '';
      return;
    }
    if (res.summary) {
      sum.textContent = 'Доступно ' + res.summary.ok + ' из ' + res.summary.total +
        ' • средний отклик ' + ZR.num(res.summary.avg_ms) + ' мс';
    }
    var html = '<div class="table-wrap"><table class="tbl"><thead><tr>' +
      '<th>URL</th><th>Статус</th><th>Код</th><th>мс</th></tr></thead><tbody>';
    rows.forEach(function (r) {
      var up = Number(r.ok) === 1;
      var dot = up
        ? '<span style="color:#10b981">● up</span>'
        : '<span style="color:#e11b1b">○ down</span>';
      html += '<tr>' +
        '<td title="' + esc(r.url) + '">' + esc(shortUrl(r.url)) + '</td>' +
        '<td>' + dot + '</td>' +
        '<td>' + esc(r.status_code) + '</td>' +
        '<td>' + ZR.num(r.ms) + '</td>' +
        '</tr>';
    });
    html += '</tbody></table></div>';
    wrap.innerHTML = html;
  }

  function loadStatus() {
    ZR.apiGet('../api/monitor.php', { action: 'status' })
      .then(function (res) {
        if (!res || res.ok === false) {
          document.getElementById('mon-wrap').innerHTML =
            '<div class="empty">' + esc((res && res.error) || 'Запустите проверку') + '</div>';
          return;
        }
        renderStatus(res);
      })
      .catch(function () {
        document.getElementById('mon-wrap').innerHTML =
          '<div class="empty">Сеть недоступна</div>';
      });
  }

  function runCheck() {
    var btn = document.getElementById('mon-run');
    btn.disabled = true;
    var old = btn.textContent;
    btn.textContent = 'Проверяю…';
    ZR.apiPost('../api/monitor.php?action=run', {})
      .then(function (res) {
        if (!res || res.ok === false) {
          ZR.toast((res && res.error) || 'Не удалось выполнить проверку', 'error');
        } else {
          ZR.toast('Проверка выполнена', 'success');
        }
        loadStatus();
      })
      .catch(function () { ZR.toast('Сеть недоступна', 'error'); })
      .finally(function () { btn.disabled = false; btn.textContent = old; });
  }

  function loadMetrika() {
    var wrap = document.getElementById('metrika-wrap');
    ZR.apiGet('../api/monitor.php', { action: 'metrika' })
      .then(function (res) {
        if (!res || res.ok === false) {
          wrap.innerHTML = '<div class="empty">' +
            esc((res && res.error) || 'Метрика не подключена') +
            '<br><small>Добавьте токен доступа в Настройках → Интеграции, чтобы видеть трафик</small></div>';
          return;
        }
        var goalsSum = (res.goals || []).reduce(function (a, g) { return a + g.reaches; }, 0);
        var html =
          '<div class="kpi-grid">' +
            '<div class="kpi"><div class="kpi__label">Визиты (7 дней)</div>' +
              '<div class="kpi__value">' + ZR.num(res.visits) + '</div></div>' +
            '<div class="kpi"><div class="kpi__label">Посетители (7 дней)</div>' +
              '<div class="kpi__value">' + ZR.num(res.users) + '</div></div>' +
            '<div class="kpi"><div class="kpi__label">Конверсии в Метрике</div>' +
              '<div class="kpi__value">' + ZR.num(goalsSum) + '</div></div>' +
            '<div class="kpi"><div class="kpi__label">Заявок в CRM (7 дней)</div>' +
              '<div class="kpi__value">' + ZR.num(res.crm7 || 0) + '</div></div>' +
          '</div>';
        if ((res.goals || []).length) {
          html += '<div style="margin-top:10px;font-size:12px;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.04em">Цели Метрики за 7 дней</div>' +
            '<div style="display:flex;flex-direction:column;gap:4px;margin-top:6px">' +
            res.goals.map(function (g) {
              return '<div style="display:flex;justify-content:space-between;gap:10px;font-size:13px;padding:5px 8px;border-radius:8px;background:var(--bg)">' +
                '<span>' + esc(g.name) + '</span><b>' + ZR.num(g.reaches) + '</b></div>';
            }).join('') + '</div>' +
            '<div style="margin-top:8px;font-size:12px;color:var(--muted);line-height:1.5">Клики по мессенджерам и телефону Метрика считает целью, но лид в CRM создаёт только отправка формы (или подключённый бот-канал в Настройках). Поэтому конверсий в Метрике обычно больше, чем заявок в CRM.</div>';
        }
        wrap.innerHTML = html;
      })
      .catch(function () {
        wrap.innerHTML = '<div class="empty">Сеть недоступна</div>';
      });
  }

  document.getElementById('mon-run').addEventListener('click', runCheck);
  // Ленивая загрузка: секция свёрнута по умолчанию — грузим при первом раскрытии
  (window.ZR_ANALYTICS_LOADERS = window.ZR_ANALYTICS_LOADERS || []).push(function () {
    loadStatus();
    loadMetrika();
  });
})();
</script>

<!-- Доп. виджеты: задачи/SLA, отклик сайта, битые ссылки, скорость/вебмастер -->
<section class="charts-grid dash-analytics">
  <div class="card">
    <h2 class="card__title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg> Задачи на сегодня + SLA</h2>
    <div id="w-sla" style="margin-bottom:12px"><div class="empty">Загрузка…</div></div>
    <div id="w-tasks"><div class="empty">Загрузка…</div></div>
  </div>
  <div class="card">
    <h2 class="card__title">📉 Время отклика сайта</h2>
    <div class="chart-wrap"><canvas id="ch_resp"></canvas></div>
    <div class="chart-empty" id="w-resp-empty" hidden>Нет данных</div>
  </div>
  <div class="card">
    <h2 class="card__title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1"/><path d="M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1"/></svg> Битые ссылки
      <button class="btn btn--ghost card__aside" type="button" id="w-scan">Сканировать sitemap</button>
    </h2>
    <div id="w-broken"><div class="empty">Загрузка…</div></div>
  </div>
  <div class="card">
    <h2 class="card__title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><path d="M5 15c-1 1-1 4-1 4s3 0 4-1"/><path d="M9 15 5 11c4-8 10-9 14-9 0 4-1 10-9 14Z"/><circle cx="14.5" cy="9.5" r="1.5"/></svg> Скорость (PageSpeed)</h2>
    <div id="w-psi"><div class="empty">Загрузка…</div></div>
    <h2 class="card__title" style="margin-top:14px"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.6-3.6"/></svg> Вебмастер</h2>
    <div id="w-webmaster"><div class="empty">Загрузка…</div></div>
  </div>
</section>

<script>
/* Доп. виджеты дашборда — каждый изолирован, ошибки не валят страницу. */
(function () {
  var esc = (ZR && ZR.escapeHtml) ? ZR.escapeHtml : function (s) {
    var d = document.createElement('div'); d.textContent = (s == null ? '' : String(s)); return d.innerHTML;
  };
  var num = (ZR && ZR.num) ? ZR.num : function (v) { return String(v == null ? '' : v); };
  var respChart = null;

  function empty(id, txt) {
    var el = document.getElementById(id);
    if (el) el.innerHTML = '<div class="empty">' + esc(txt) + '</div>';
  }

  /* ----- SLA: просрочка ответа ----- */
  function loadSla() {
    ZR.apiGet('../api/tasks.php', { action: 'sla' })
      .then(function (res) {
        var box = document.getElementById('w-sla');
        if (!res || res.ok === false) { empty('w-sla', (res && res.error) || 'SLA недоступен'); return; }
        var rows = res.items || [];
        var head = '<div class="field__label" style="margin-bottom:6px">⏱ ' +
          esc(res.title || 'Просрочка ответа') + ' · ' + num(res.count != null ? res.count : rows.length) + '</div>';
        if (!rows.length) { box.innerHTML = head + '<div class="empty">Все заявки в срок — просрочек по ответу нет</div>'; return; }
        var html = head + '<ul class="dw-list">';
        rows.slice(0, 8).forEach(function (r) {
          html += '<li>' +
            '<a href="lead.php?id=' + encodeURIComponent(r.id) + '" style="color:var(--red);font-weight:600;text-decoration:none">' +
              (esc(r.name) || ('#' + esc(r.id))) + '</a>' +
            '<span class="muted">' + num(r.waiting_min) + ' мин</span></li>';
        });
        html += '</ul>';
        box.innerHTML = html;
      })
      .catch(function () { empty('w-sla', 'Сеть недоступна'); });
  }

  /* ----- Задачи на сегодня ----- */
  function loadTasks() {
    ZR.apiGet('../api/tasks.php', { action: 'list', scope: 'today' })
      .then(function (res) {
        var box = document.getElementById('w-tasks');
        if (!res || res.ok === false) { empty('w-tasks', (res && res.error) || 'Задачи недоступны'); return; }
        var rows = res.items || [];
        var head = '<div class="field__label" style="margin-bottom:6px"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><rect x="7" y="4" width="10" height="16" rx="2"/><path d="M9 4V3h6v1"/><path d="M10 10h4M10 14h4"/></svg> Задачи на сегодня · ' +
          num((res.counts && res.counts.today) != null ? res.counts.today : rows.length) + '</div>';
        if (!rows.length) { box.innerHTML = head + '<div class="empty">На сегодня задач нет — можно заняться очередью инженера</div>'; return; }
        var html = head + '<ul class="dw-list">';
        rows.slice(0, 8).forEach(function (t) {
          var link = t.lead_id
            ? '<a href="lead.php?id=' + encodeURIComponent(t.lead_id) + '" style="color:var(--red);text-decoration:none">' + (esc(t.lead_name) || ('#' + esc(t.lead_id))) + '</a>'
            : '<span class="muted">—</span>';
          html += '<li>' +
            '<span>' + esc(t.title) + '</span>' + link + '</li>';
        });
        html += '</ul>';
        box.innerHTML = html;
      })
      .catch(function () { empty('w-tasks', 'Сеть недоступна'); });
  }

  /* ----- Время отклика сайта (line) ----- */
  function loadResp() {
    var emptyEl = document.getElementById('w-resp-empty');
    var canvas = document.getElementById('ch_resp');
    function showEmpty(txt) {
      if (canvas) canvas.style.display = 'none';
      if (emptyEl) { emptyEl.hidden = false; emptyEl.textContent = txt || 'Нет данных'; }
    }
    if (typeof Chart === 'undefined') { showEmpty('Chart.js не загружен'); return; }
    ZR.apiGet('../api/monitor.php', { action: 'chart' })
      .then(function (res) {
        if (!res || res.ok === false) { showEmpty((res && res.error) || 'Нет данных'); return; }
        var labels = (res.labels || []).map(function (d) { return ZR.dateRu ? ZR.dateRu(d) : d; });
        var data = res.data || [];
        if (!data.length) { showEmpty('Нет данных'); return; }
        if (emptyEl) emptyEl.hidden = true;
        if (canvas) canvas.style.display = '';
        if (respChart) { respChart.destroy(); respChart = null; }
        respChart = new Chart(canvas, {
          type: 'line',
          data: { labels: labels, datasets: [{ label: 'мс', data: data, borderColor: '#e11b1b', backgroundColor: '#e11b1b22', fill: true, tension: 0.3, pointRadius: 2 }] },
          options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
        });
      })
      .catch(function () { showEmpty('Сеть недоступна'); });
  }

  /* ----- Битые ссылки ----- */
  function loadBroken() {
    ZR.apiGet('../api/monitor.php', { action: 'broken' })
      .then(function (res) {
        var box = document.getElementById('w-broken');
        if (!res || res.ok === false) { empty('w-broken', (res && res.error) || 'Список недоступен'); return; }
        var rows = res.broken || [];
        if (!rows.length) { box.innerHTML = '<div class="empty">Битых ссылок не найдено — все страницы из sitemap отвечают</div>'; return; }
        var html = '<ul class="dw-list">';
        rows.slice(0, 12).forEach(function (r) {
          var u = String(r.url || '');
          html += '<li>' +
            '<a href="' + esc(u) + '" target="_blank" rel="noopener" title="' + esc(u) + '" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:75%">' +
              esc(u.replace(/^https?:\/\//, '')) + '</a>' +
            '<span style="color:#e11b1b;font-weight:600">' + esc(r.status_code) + '</span></li>';
        });
        html += '</ul>';
        box.innerHTML = html;
      })
      .catch(function () { empty('w-broken', 'Сеть недоступна'); });
  }

  function runScan() {
    var btn = document.getElementById('w-scan');
    if (!btn) return;
    var old = btn.textContent;
    btn.disabled = true; btn.textContent = 'Сканирую…';
    ZR.apiPost('../api/monitor.php?action=scan', {})
      .then(function (res) {
        if (!res || res.ok === false) { ZR.toast((res && res.error) || 'Не удалось отсканировать', 'error'); }
        else { ZR.toast('Сканирование завершено: проверено ' + num(res.checked) + ', битых ' + ((res.broken || []).length), 'success'); }
        loadBroken();
      })
      .catch(function () { ZR.toast('Сеть недоступна', 'error'); })
      .finally(function () { btn.disabled = false; btn.textContent = old; });
  }

  /* ----- PageSpeed ----- */
  function loadPsi() {
    ZR.apiGet('../api/seo.php', { action: 'psi' })
      .then(function (res) {
        var box = document.getElementById('w-psi');
        if (!res || res.ok === false) { empty('w-psi', (res && res.error) || 'PageSpeed недоступен'); return; }
        var score = res.score;
        var color = score == null ? 'var(--muted)' : (score >= 90 ? '#10b981' : (score >= 50 ? '#f59e0b' : '#e11b1b'));
        var html = '<div style="display:flex;align-items:baseline;gap:8px;margin-bottom:8px">' +
          '<span style="font-size:34px;font-weight:700;color:' + color + '">' + (score != null ? score : '—') + '</span>' +
          '<span class="muted">/ 100 (mobile)</span></div>';
        var parts = [];
        if (res.lcp) parts.push('LCP ' + esc(res.lcp));
        if (res.fcp) parts.push('FCP ' + esc(res.fcp));
        if (res.cls) parts.push('CLS ' + esc(res.cls));
        if (parts.length) html += '<div class="field__label">' + parts.join(' · ') + '</div>';
        box.innerHTML = html;
      })
      .catch(function () { empty('w-psi', 'Сеть недоступна'); });
  }

  /* ----- Вебмастер ----- */
  function loadWebmaster() {
    ZR.apiGet('../api/seo.php', { action: 'webmaster' })
      .then(function (res) {
        var box = document.getElementById('w-webmaster');
        if (!res || res.ok === false) { empty('w-webmaster', (res && res.error) || 'Вебмастер не подключён. Добавьте токен в Настройках → Интеграции, чтобы видеть ИКС и индексацию'); return; }
        var cells = [];
        if (res.sqi != null) cells.push(['ИКС (SQI)', num(res.sqi)]);
        if (res.searchable_pages != null) cells.push(['В поиске', num(res.searchable_pages)]);
        if (res.excluded_pages != null) cells.push(['Исключено', num(res.excluded_pages)]);
        if (!cells.length) { box.innerHTML = '<div class="empty">Нет данных Вебмастера</div>'; return; }
        var html = '<div class="kpi-grid">';
        cells.forEach(function (c) {
          html += '<div class="kpi"><div class="kpi__label">' + esc(c[0]) + '</div><div class="kpi__value">' + esc(c[1]) + '</div></div>';
        });
        html += '</div>';
        box.innerHTML = html;
      })
      .catch(function () { empty('w-webmaster', 'Сеть недоступна'); });
  }

  var scanBtn = document.getElementById('w-scan');
  if (scanBtn) scanBtn.addEventListener('click', runScan);

  // Ленивая загрузка: секция свёрнута по умолчанию — грузим при первом раскрытии
  (window.ZR_ANALYTICS_LOADERS = window.ZR_ANALYTICS_LOADERS || []).push(function () {
    loadSla(); loadTasks(); loadResp(); loadBroken(); loadPsi(); loadWebmaster();
  });
})();
</script>

<!-- Здоровье системы + Настройка системы (онбординг) -->
<section class="charts-grid dash-analytics">
  <div class="card">
    <h2 class="card__title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><path d="M6 3v6a5 5 0 0 0 10 0V3"/><path d="M11 14v2a5 5 0 0 0 10 0v-1"/><circle cx="20" cy="12" r="2"/></svg> Здоровье системы
      <?php if ($isAdmin): ?><button class="btn btn--ghost card__aside" type="button" id="hp-backup">Создать резервную копию</button><?php endif; ?>
    </h2>
    <div id="hp-checks"><div class="empty">Загрузка…</div></div>
  </div>
  <div class="card">
    <h2 class="card__title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><path d="m4 13 5 5L20 7"/></svg> Настройка системы</h2>
    <div id="hp-onboarding"><div class="empty">Загрузка…</div></div>
  </div>
</section>

<script>
/* Виджеты: здоровье системы + онбординг. Изолированы, ошибки не валят страницу. */
(function () {
  var esc = (ZR && ZR.escapeHtml) ? ZR.escapeHtml : function (s) {
    var d = document.createElement('div'); d.textContent = (s == null ? '' : String(s)); return d.innerHTML;
  };

  function renderChecks(checks) {
    var box = document.getElementById('hp-checks');
    if (!checks || !checks.length) { box.innerHTML = '<div class="empty">Нет данных</div>'; return; }
    var html = '<div class="table-wrap"><table class="tbl"><tbody>';
    checks.forEach(function (c) {
      var dot = (c.ok === true) ? '<span style="color:#10b981">●</span>'
        : (c.ok === false) ? '<span style="color:#e11b1b">●</span>'
        : '<span style="color:#f59e0b">▲</span>';
      html += '<tr>' +
        '<td style="width:24px;text-align:center">' + dot + '</td>' +
        '<td style="font-weight:600">' + esc(c.name) + '</td>' +
        '<td class="muted">' + esc(c.value) + '</td>' +
        '</tr>';
    });
    html += '</tbody></table></div>';
    box.innerHTML = html;
  }

  function renderOnboarding(items) {
    var box = document.getElementById('hp-onboarding');
    if (!items || !items.length) { box.innerHTML = '<div class="empty">Нет данных</div>'; return; }
    var pending = items.filter(function (i) { return !i.done; });
    var html = '';
    if (!pending.length) {
      html += '<div class="field__label" style="color:#10b981;font-weight:600;margin-bottom:8px">✓ Система настроена</div>';
    }
    html += '<ul class="dw-list dw-list--block">';
    items.forEach(function (i) {
      var mark = i.done ? '<span style="color:#10b981">✓</span>' : '<span style="color:#e11b1b">✗</span>';
      html += '<li>' +
        '<div style="display:flex;gap:8px;align-items:baseline">' +
          '<span>' + mark + '</span>' +
          '<span style="font-weight:600">' + esc(i.name) + '</span></div>';
      if (!i.done && i.hint) {
        html += '<div class="muted" style="margin-left:20px">' + esc(i.hint) + '</div>';
      }
      html += '</li>';
    });
    html += '</ul>';
    box.innerHTML = html;
  }

  function loadHealth() {
    ZR.apiGet('../api/health.php', {})
      .then(function (res) {
        if (!res || res.ok === false) {
          document.getElementById('hp-checks').innerHTML =
            '<div class="empty">' + esc((res && res.error) || 'Не удалось загрузить') + '</div>';
          document.getElementById('hp-onboarding').innerHTML =
            '<div class="empty">' + esc((res && res.error) || 'Не удалось загрузить') + '</div>';
          return;
        }
        renderChecks(res.checks || []);
        renderOnboarding(res.onboarding || []);
      })
      .catch(function () {
        document.getElementById('hp-checks').innerHTML = '<div class="empty">Сеть недоступна</div>';
        document.getElementById('hp-onboarding').innerHTML = '<div class="empty">Сеть недоступна</div>';
      });
  }

  function runBackup() {
    var btn = document.getElementById('hp-backup');
    if (!btn) return;
    var old = btn.textContent;
    btn.disabled = true; btn.textContent = 'Делаю бэкап…';
    ZR.apiPost('../api/backup.php?action=run', {})
      .then(function (res) {
        if (!res || res.ok === false) {
          ZR.toast((res && res.error) || 'Не удалось сделать бэкап', 'error');
        } else {
          ZR.toast('Бэкап создан: ' + esc(res.file) + ' (' + (Math.round((res.size || 0) / 1048576 * 10) / 10) + ' МБ)', 'success');
        }
        loadHealth();
      })
      .catch(function () { ZR.toast('Сеть недоступна', 'error'); })
      .finally(function () { btn.disabled = false; btn.textContent = old; });
  }

  var bBtn = document.getElementById('hp-backup');
  if (bBtn) bBtn.addEventListener('click', runBackup);
  // Ленивая загрузка: секция свёрнута по умолчанию — грузим при первом раскрытии
  (window.ZR_ANALYTICS_LOADERS = window.ZR_ANALYTICS_LOADERS || []).push(loadHealth);
})();
</script>
<script>
/* Аналитика/мониторинг: свёрнуты по умолчанию (операционный дашборд в 1 экран).
   Разворот запоминается; при показе — resize, чтобы графики заняли контейнер. */
(function () {
  var btn = document.getElementById('toggleAnalytics');
  if (!btn) return;
  var KEY = 'zr_dash_analytics';
  var loadersRan = false;
  function runLoaders() {
    // Ленивая загрузка виджетов аналитики: один раз, при первом раскрытии
    if (loadersRan) return;
    loadersRan = true;
    (window.ZR_ANALYTICS_LOADERS || []).forEach(function (fn) {
      try { fn(); } catch (e) {}
    });
  }
  function apply(open) {
    document.body.classList.toggle('analytics-open', open);
    btn.textContent = (open ? '▾' : '▸') + ' Аналитика и мониторинг';
    if (open) runLoaders();
    if (open) setTimeout(function () {
      // Графики, созданные в скрытом контейнере, имеют размер 0 — ресайзим явно.
      window.dispatchEvent(new Event('resize'));
      if (window.Chart && Chart.getChart) {
        document.querySelectorAll('.dash-analytics canvas').forEach(function (c) {
          var ch = Chart.getChart(c); if (ch) ch.resize();
        });
      }
    }, 80);
  }
  var open = false;
  try { open = localStorage.getItem(KEY) === '1'; } catch (e) {}
  apply(open);
  btn.addEventListener('click', function () {
    open = !document.body.classList.contains('analytics-open');
    apply(open);
    try { localStorage.setItem(KEY, open ? '1' : '0'); } catch (e) {}
  });
})();
</script>
<?php
render_foot();
