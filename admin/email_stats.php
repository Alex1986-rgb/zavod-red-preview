<?php
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require __DIR__ . '/_layout.php';

$pdo = pdo();
$dh  = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// Создаём таблицу при первом открытии
$pdo->exec("CREATE TABLE IF NOT EXISTS email_opens (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign    VARCHAR(100) NOT NULL,
    ip          VARCHAR(45)  NOT NULL,
    device      VARCHAR(20)  NOT NULL DEFAULT 'desktop',
    mail_client VARCHAR(50)  NOT NULL DEFAULT 'unknown',
    user_agent  TEXT,
    opened_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_campaign (campaign),
    INDEX idx_opened_at (opened_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$campaign = trim((string)($_GET['c'] ?? ''));

// Список кампаний
$campaigns = $pdo->query(
    "SELECT campaign, COUNT(*) AS opens, MIN(opened_at) AS first, MAX(opened_at) AS last
     FROM email_opens GROUP BY campaign ORDER BY last DESC"
)->fetchAll();

$rows = $by_device = $by_client = $by_day = [];

if ($campaign) {
    $st = $pdo->prepare(
        "SELECT * FROM email_opens WHERE campaign = ? ORDER BY opened_at DESC LIMIT 500"
    );
    $st->execute([$campaign]);
    $rows = $st->fetchAll();

    foreach ($rows as $r) {
        $by_device[$r['device']] = ($by_device[$r['device']] ?? 0) + 1;
        $by_client[$r['mail_client']] = ($by_client[$r['mail_client']] ?? 0) + 1;
        $day = substr($r['opened_at'], 0, 10);
        $by_day[$day] = ($by_day[$day] ?? 0) + 1;
    }
    arsort($by_client);
    ksort($by_day);
}

$metrika_url = 'https://metrika.yandex.ru/stat/traffic_sources/utm?id=' . rawurlencode(ya_counter_id() ?: '109758131') . '&group=day&period=month';

render_head('Рассылка · Статистика', true);
render_sidebar('email_stats');
?>
<style>
/* Статистика рассылок: каркас — .shelf, карточки — .card/.card__title, таблицы — .table-wrap + .tbl,
   плитки — .kpi-grid/.kpi, пустое состояние — .empty. Здесь только то, чего в общей системе нет. */
.es-grid3 > .card{margin:0 !important} /* перебивает общий .card{margin-bottom:8px !important} — шаг даёт gap сетки */
.card__title .muted{font-weight:400;font-size:13px}
.es-sub{margin:6px 0 8px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted)}
.es-mono{font-family:ui-monospace,Menlo,Consolas,monospace}
.es-strong{font-weight:600}
/* число открытий — главный показатель строки; общий плотный слой ставит ячейкам .tbl font-size:12px !important */
table.tbl td.es-big{font-size:20px !important;font-weight:800}
.es-bar{height:6px;min-width:60px;background:var(--line);border-radius:3px}
.es-bar__fill{height:6px;background:var(--red);border-radius:3px}
.es-empty__title{font-size:16px;font-weight:600;color:var(--text)}
.es-code{max-width:520px;width:100%;margin-top:8px;padding:12px 16px;font-size:12px;text-align:left;background:var(--bg);border:1px solid var(--line);border-radius:var(--r-sm)}
.es-code code{font-size:11px;word-break:break-all;color:var(--red)}
</style>
<div class="shelf">

<div class="page-head">
  <h1 class="page-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><path d="m3 11 18-7-7 18-2.5-8.5L3 11Z"/></svg> Статистика рассылок</h1>
  <div class="page-head__actions">
    <a href="<?= $dh($metrika_url) ?>" target="_blank"
       class="btn btn--ghost">
      Яндекс.Метрика (UTM-клики) ↗
    </a>
    <a href="https://alex1986-rgb.github.io/zavod-red-catalog/tools/email-stats.html"
       target="_blank" class="btn btn--ghost">
      Публичная страница ↗
    </a>
  </div>
</div>

<?php if (empty($campaigns)): ?>
<div class="card">
  <div class="empty">
    <div class="empty__ico">📭</div>
    <div class="es-empty__title">Открытий ещё нет</div>
    <div>Пиксель сработает при первом открытии письма получателем</div>
    <div class="es-code">
      Пиксель в письме:<br>
      <code>
        &lt;img src="https://zavod-red.ru/api/email-track.php?c=calculator_launch" width="1" height="1"&gt;
      </code>
    </div>
  </div>
</div>
<?php else: ?>

<!-- Сводная таблица -->
<div class="card">
  <div class="card__title">
    Кампании
  </div>
  <div class="table-wrap">
  <table class="tbl">
    <thead>
      <tr>
        <th>Кампания</th>
        <th class="r">Открытий</th>
        <th>Первое</th>
        <th>Последнее</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($campaigns as $c): ?>
    <tr>
      <td class="es-strong"><?= $dh($c['campaign']) ?></td>
      <td class="num es-big"><?= $c['opens'] ?></td>
      <td class="muted"><?= substr($c['first'], 0, 16) ?></td>
      <td class="muted"><?= substr($c['last'], 0, 16) ?></td>
      <td>
        <a href="?c=<?= urlencode($c['campaign']) ?>"
           class="btn btn--sm <?= $campaign === $c['campaign'] ? 'btn--primary' : 'btn--ghost' ?>">
          Детали
        </a>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<?php if ($campaign && !empty($rows)): ?>

<!-- Три графика -->
<div class="es-grid3">
  <div class="card">
    <div class="card__title">По дням</div>
    <canvas id="chartDays" height="160"></canvas>
  </div>
  <div class="card">
    <div class="card__title">Устройства</div>
    <canvas id="chartDevice" height="160"></canvas>
  </div>
  <div class="card">
    <div class="card__title">Почтовый клиент</div>
    <canvas id="chartClient" height="160"></canvas>
  </div>
</div>


<?php if ($campaign && !empty($rows)): 
// IP-сводка
$ips = array_column($rows, 'ip');
$unique_ips = count(array_unique($ips));
$ip_counts = array_count_values($ips);
arsort($ip_counts);
$top_ips = array_slice($ip_counts, 0, 20);

// Повторные открытия
$repeat_openers = count(array_filter($ip_counts, fn($c) => $c > 1));
$max_opens = $ip_counts ? max($ip_counts) : 0;
$max_ip = $ip_counts ? array_search($max_opens, $ip_counts) : '';

// Определение страны по первому октету (грубо)
$ip_countries = ['ru' => 0, 'other' => 0];
foreach ($ips as $ip) {
    // Российские диапазоны: 5.x, 31.x, 37.x, 46.x, 77.x, 78.x, 79.x, 82.x, 85.x, 87.x, 88.x, 89.x, 90.x, 91.x, 92.x, 93.x, 94.x, 95.x, 109.x, 128.x, 176.x, 178.x, 185.x, 188.x, 193.x, 194.x, 212.x, 213.x, 217.x
    $first = (int)explode('.', $ip)[0];
    $ru_ranges = [5,31,37,46,77,78,79,82,85,87,88,89,90,91,92,93,94,95,109,128,176,178,185,188,193,194,212,213,217];
    if (in_array($first, $ru_ranges)) $ip_countries['ru']++;
    else $ip_countries['other']++;
}
?>

<div class="card">
  <div class="card__title">
    🌍 IP-сводка — <strong><?= $dh($campaign) ?></strong>
  </div>

  <!-- KPI row -->
  <div class="kpi-grid">
    <div class="kpi">
      <div class="kpi__label">Уникальных IP</div>
      <div class="kpi__value" style="color:var(--red)"><?= $unique_ips ?></div>
    </div>
    <div class="kpi">
      <div class="kpi__label">Повторных открытий</div>
      <div class="kpi__value"><?= $repeat_openers ?></div>
    </div>
    <div class="kpi">
      <div class="kpi__label">IP из России</div>
      <div class="kpi__value" style="color:var(--green)"><?= round(($ip_countries['ru'] / max(1, count($ips))) * 100) ?>%</div>
    </div>
    <div class="kpi">
      <div class="kpi__label">Макс. с одного IP</div>
      <div class="kpi__value" style="color:var(--amber)"><?= $max_opens ?></div>
    </div>
  </div>

  <!-- Топ IP -->
  <div>
    <div class="es-sub">
      Топ-10 IP по открытиям
    </div>
    <div class="table-wrap">
    <table class="tbl">
      <thead>
        <tr>
          <th>IP</th>
          <th class="r">Открытий</th>
          <th class="r">%</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php $i=0; foreach ($top_ips as $ip => $cnt): if ($i++ >= 10) break; ?>
        <tr>
          <td class="es-mono"><code><?= $dh($ip) ?></code></td>
          <td class="num es-strong"><?= $cnt ?></td>
          <td class="num muted"><?= round($cnt / max(1, count($rows)) * 100) ?>%</td>
          <td>
            <div class="es-bar">
              <div class="es-bar__fill" style="width:<?= round($cnt / max(1, count($rows)) * 100) ?>%"></div>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Журнал -->
<div class="card">
  <div class="card__title">
    Журнал открытий — <strong><?= $dh($campaign) ?></strong>
    <span class="muted">(<?= count($rows) ?> записей)</span>
  </div>
  <div class="table-wrap">
  <table class="tbl">
    <thead>
      <tr>
        <th>Дата / время</th>
        <th>IP</th>
        <th>Устройство</th>
        <th>Почтовый клиент</th>
      </tr>
    </thead>
    <tbody>
    <?php
    $icons = ['mobile'=>'📱','tablet'=>'💻','desktop'=>'🖥'];
    foreach ($rows as $r):
    ?>
    <tr>
      <td class="es-mono"><?= $dh(substr($r['opened_at'],0,16)) ?></td>
      <td class="es-mono"><?= $dh($r['ip']) ?></td>
      <td><?= ($icons[$r['device']] ?? '❓') ?> <?= $dh($r['device']) ?></td>
      <td class="muted"><?= $dh($r['mail_client']) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<script>
const RED='#CC2D2D',STEEL='#2F4A6E',PAL=[RED,STEEL,'#5B82B4','#8A9BB0','#A0B4C8'];
new Chart(document.getElementById('chartDays'),{type:'bar',
  data:{labels:<?= json_encode(array_keys($by_day)) ?>,datasets:[{data:<?= json_encode(array_values($by_day)) ?>,backgroundColor:RED,borderRadius:4}]},
  options:{plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{stepSize:1}}}}
});
new Chart(document.getElementById('chartDevice'),{type:'doughnut',
  data:{labels:<?= json_encode(array_keys($by_device)) ?>,datasets:[{data:<?= json_encode(array_values($by_device)) ?>,backgroundColor:PAL}]},
  options:{plugins:{legend:{position:'bottom'}}}
});
new Chart(document.getElementById('chartClient'),{type:'doughnut',
  data:{labels:<?= json_encode(array_keys($by_client)) ?>,datasets:[{data:<?= json_encode(array_values($by_client)) ?>,backgroundColor:PAL}]},
  options:{plugins:{legend:{position:'bottom'}}}
});
</script>
<?php endif; ?>
<?php endif; ?>

</div>
<?php render_foot(); ?>
