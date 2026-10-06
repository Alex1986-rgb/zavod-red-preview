<?php
declare(strict_types=1);
/**
 * «Автопилот» — одна страница, на которой видно, что вся автоматика работает:
 * фоновые задачи, приём почты (входящие + «Отправленные»), письма клиентов без ответа,
 * распознанные вложения (шильдики, чертежи, счета, чеки), обучение автоответчика
 * и готовность к автоотправке. Только чтение — ничего отсюда не отправляется.
 */
require __DIR__ . '/_guard.php';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../api/mail_sync.php';

$h = static fn($s): string => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$pdo = pdo();
ms_ensure();

/* ---- фоновые задачи (heartbeat cron_<name>_last) ---- */
$jobs = [
    'mail'       => ['Приём почты', 15, 'каждые 5 мин'],
    'mail_files' => ['Разбор вложений', 15, 'вместе с почтой'],
    'ai_auto'    => ['ИИ: подбор по новым заявкам', 30, 'каждые 10 мин'],
    'b24'        => ['Выгрузка в Битрикс24', 15, 'с почтой и подбором'],
    'calls'      => ['Обзвон по кампаниям', 60, 'будни 10–19'],
    'monitor'    => ['Мониторинг сайта', 30, 'каждые 10 мин'],
    'backup'     => ['Бэкап базы', 36 * 60, 'ночью'],
    'digest'     => ['Вечерняя сводка', 26 * 60, '19:00'],
];
$ago = static function (?string $at, ?int $min = null): array {
    if (!$at && $min === null) return [null, 'не запускалась'];
    $min ??= (int)floor((time() - strtotime($at)) / 60);
    if ($min < 1) return [$min, 'только что'];
    if ($min < 60) return [$min, $min . ' мин назад'];
    if ($min < 48 * 60) return [$min, floor($min / 60) . ' ч назад'];
    return [$min, floor($min / 1440) . ' дн назад'];
};

/* ---- почта ---- */
$creds = ms_creds();
$mailReady = $creds['user'] !== '' && $creds['pass'] !== '';
$report = json_decode((string)setting('mail_sync_last_report', ''), true) ?: null;
$kinds = [];
foreach ($pdo->query("SELECT mail_kind k, COUNT(*) c FROM crm_messages
                      WHERE channel='email' AND direction='in' AND created_at >= NOW() - INTERVAL 7 DAY
                      GROUP BY mail_kind") as $r) {
    $kinds[$r['k'] === '' ? 'old' : $r['k']] = (int)$r['c'];
}
$labels = ms_kind_labels() + ['old' => 'До сортировки'];

/* ---- письма клиентов без ответа: последнее входящее по заявке, после него от нас ничего ---- */
$unanswered = $pdo->query(
    "SELECT m.id, m.lead_id, m.contact, m.subject, m.created_at, m.mail_kind, l.name, l.status,
            TIMESTAMPDIFF(MINUTE, m.created_at, NOW()) AS wait_min,
            (SELECT COUNT(*) FROM crm_messages d WHERE d.lead_id=m.lead_id AND d.folder='drafts' AND d.created_at >= m.created_at) AS drafts
     FROM crm_messages m JOIN crm_leads l ON l.id = m.lead_id
     WHERE m.channel='email' AND m.direction='in' AND m.folder NOT IN ('spam','trash','archive')
       AND (m.mail_kind IN ('request','client') OR m.mail_kind='')
       AND l.status NOT IN ('lost','won')
       AND m.created_at >= NOW() - INTERVAL 14 DAY AND m.created_at <= NOW() - INTERVAL 3 HOUR
       AND m.id = (SELECT MAX(x.id) FROM crm_messages x WHERE x.lead_id=m.lead_id AND x.direction='in')
       AND NOT EXISTS (SELECT 1 FROM crm_messages o WHERE o.lead_id=m.lead_id AND o.direction='out'
                       AND o.folder NOT IN ('drafts','trash') AND o.created_at >= m.created_at)
     ORDER BY m.created_at ASC LIMIT 50"
)->fetchAll();

/* ---- вложения ---- */
$fq = ['new' => 0, 'done' => 0, 'skipped' => 0, 'error' => 0];
foreach ($pdo->query("SELECT status, COUNT(*) c FROM crm_mail_files GROUP BY status") as $r) $fq[$r['status']] = (int)$r['c'];
$fkinds = $pdo->query("SELECT kind, COUNT(*) c FROM crm_mail_files WHERE status='done' AND created_at >= NOW() - INTERVAL 30 DAY GROUP BY kind ORDER BY c DESC")->fetchAll();
$files = $pdo->query("SELECT f.id, f.lead_id, f.filename, f.kind, f.summary, f.direction, f.created_at, l.name
                      FROM crm_mail_files f LEFT JOIN crm_leads l ON l.id=f.lead_id
                      WHERE f.status='done' ORDER BY f.id DESC LIMIT 15")->fetchAll();

/* ---- автоответчик и обучение ---- */
$learn = learn_stats(30);
$arMode = ar_mode();
$ar = ['autoreply_sent' => 0, 'autoreply_draft' => 0, 'autoreply_skip' => 0, 'autoreply_failed' => 0];
foreach ($pdo->query("SELECT type, COUNT(*) c FROM crm_events WHERE type LIKE 'autoreply%' AND created_at >= NOW() - INTERVAL 7 DAY GROUP BY type") as $r) $ar[$r['type']] = (int)$r['c'];
$aiLabel = llm_ready() ? llm_label() : '';
$vision = llm_ready(true);

render_head('Автопилот');
render_sidebar('autopilot');
?>
<style>
  .ap-jobs{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:10px}
  .ap-job{border:1px solid var(--line);border-radius:var(--r-md,10px);padding:10px 12px;background:var(--card)}
  .ap-job b{display:block;font-size:13.5px}
  .ap-job small{color:var(--muted)}
  .ap-dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:6px;vertical-align:middle}
  .ap-dot--ok{background:#16a34a}.ap-dot--warn{background:#d97706}.ap-dot--bad{background:#dc2626}
  .ap-chips{display:flex;flex-wrap:wrap;gap:6px}
  .ap-chip{border:1px solid var(--line);border-radius:999px;padding:3px 10px;font-size:12.5px;background:var(--bg)}
  .ap-chip b{margin-left:4px}
  .ap-meter{height:8px;border-radius:4px;background:var(--bg);border:1px solid var(--line);overflow:hidden;margin:6px 0 4px}
  .ap-meter i{display:block;height:100%;background:var(--red)}
</style>

<div class="shelf">
<div class="page-head"><h1 class="page-title">Автопилот</h1></div>
<p class="page-lead">Что автоматика делает сама и где нужен человек. Страница только показывает — отсюда ничего не отправляется.</p>

<h2>Фоновые задачи</h2>
<div class="ap-jobs">
<?php foreach ($jobs as $key => [$label, $maxMin, $when]):
    [$min, $txt] = $ago(setting('cron_' . $key . '_last'));
    $cls = $min === null ? 'bad' : ($min > $maxMin ? 'warn' : 'ok'); ?>
  <div class="ap-job"><b><span class="ap-dot ap-dot--<?= $cls ?>"></span><?= $h($label) ?></b>
    <small><?= $h($txt) ?> · <?= $h($when) ?></small></div>
<?php endforeach; ?>
</div>
<?php if (!setting('cron_mail_last')): ?>
<div class="alert alert-warn">Фоновые задачи не запускались — на хостинге не настроен крон. Строки для панели Beget — в <code>deploy/crontab.example</code>.</div>
<?php endif; ?>

<h2>Почта</h2>
<section class="card">
<?php if (!$mailReady): ?>
  <div class="alert alert-warn">Не задан логин/пароль почты — письма не забираются. <a href="settings.php">Настройки → Интеграции → Почта</a> (нужен пароль приложения Яндекса).</div>
<?php else: ?>
  <p class="muted">Ящик <b><?= $h($creds['user']) ?></b>: входящие и «Отправленные». Письма не помечаются прочитанными.
  <?php if ($report): ?>Последний прогон: <?= $h($report['at'] ?? '—') ?>
    <?php if (!($report['ok'] ?? false)): ?> — <b style="color:var(--red)">ошибка: <?= $h($report['error'] ?? '') ?></b><?php else: ?>
    — входящих <?= (int)($report['inbox']['saved'] ?? 0) ?>, ответов менеджеров <?= (int)($report['sent']['saved'] ?? 0) ?>, новых заявок <?= (int)($report['inbox']['leads_new'] ?? 0) ?>.<?php endif; ?>
  <?php endif; ?></p>
<?php endif; ?>
  <div class="card__title" style="margin-top:6px">Входящие за 7 дней по типам</div>
  <?php if (!$kinds): ?><p class="muted">Писем за неделю нет.</p><?php else: ?>
  <div class="ap-chips"><?php foreach ($kinds as $k => $c): ?><span class="ap-chip"><?= $h($labels[$k] ?? $k) ?><b><?= $c ?></b></span><?php endforeach; ?></div>
  <p class="muted" style="margin-top:8px">Заявка заводится только на «Обращение» и «Клиент». Рассылки, спам, поставщики и уведомления лежат в <a href="mail.php">Письмах</a> с меткой, без заявки.</p>
  <?php endif; ?>
</section>

<h2>Битрикс24 — поле «Инженер» в сделках</h2>
<section class="card">
<?php
require_once __DIR__ . '/../api/bitrix.php';
$b24rep = json_decode((string)setting('b24_last_report', ''), true) ?: null;
if (!b24_ready()): ?>
  <div class="alert alert-warn">Битрикс24 не подключён — подбор виден только здесь, в админке. Вставьте входящий вебхук в <a href="settings.php">Настройки → Интеграции → Битрикс24</a> и нажмите «Проверить Битрикс24».</div>
<?php elseif (setting('b24_sync', '1') !== '1'): ?>
  <div class="alert alert-info">Вебхук есть, но автовыгрузка выключена (Настройки → Интеграции → Битрикс24).</div>
<?php else:
  b24_ensure();
  $b24n = $pdo->query("SELECT COUNT(*) FROM crm_leads WHERE b24_deal_id IS NOT NULL")->fetchColumn(); ?>
  <p>Подбор, решение инженера, вложения и звонки пишутся в поле сделки «Инженер» (блок робота, текст менеджеров не трогается).
     Связано заявок со сделками: <b><?= (int)$b24n ?></b>.
     <?php if ($b24rep): ?>Последняя выгрузка: <?= $h($b24rep['at'] ?? '') ?> — обновлено сделок <?= (int)($b24rep['pushed'] ?? 0) ?>, без сделки <?= (int)($b24rep['no_deal'] ?? 0) ?><?= !empty($b24rep['errors']) ? ', ошибок ' . (int)$b24rep['errors'] . ' (' . $h($b24rep['last_error'] ?? '') . ')' : '' ?>.<?php endif; ?></p>
<?php endif; ?>
</section>

<h2>Клиенты ждут ответа <span class="badge <?= $unanswered ? 'badge--rework' : 'badge--ok' ?>"><?= count($unanswered) ?></span></h2>
<section class="card">
<?php if (!$unanswered): ?>
  <p class="muted">Все письма клиентов за 14 дней отвечены (или прошло меньше 3 часов).</p>
<?php else: ?>
  <div class="table-wrap"><table class="tbl">
    <thead><tr><th>Ждёт</th><th>Клиент</th><th>Тема</th><th>Черновик робота</th></tr></thead>
    <tbody>
    <?php foreach ($unanswered as $u): [, $wait] = $ago(null, (int)$u['wait_min']); ?>
      <tr>
        <td><?= $h(str_replace(' назад', '', $wait)) ?></td>
        <td><a href="lead.php?id=<?= (int)$u['lead_id'] ?>"><?= $h($u['name'] ?: $u['contact']) ?></a><br><small class="muted"><?= $h($u['contact']) ?></small></td>
        <td><a href="mail.php"><?= $h($u['subject'] ?: '(без темы)') ?></a></td>
        <td><?= (int)$u['drafts'] ? '<span class="badge badge--review">есть — проверить и отправить</span>' : '<span class="muted">нет</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
<?php endif; ?>
</section>

<h2>Вложения из писем</h2>
<section class="card">
  <div class="ap-chips">
    <span class="ap-chip">Разобрано<b><?= $fq['done'] ?></b></span>
    <span class="ap-chip">В очереди<b><?= $fq['new'] ?></b></span>
    <span class="ap-chip">Не читается ИИ (DWG, архивы…)<b><?= $fq['skipped'] ?></b></span>
    <?php if ($fq['error']): ?><span class="ap-chip">Ошибки<b><?= $fq['error'] ?></b></span><?php endif; ?>
    <?php foreach ($fkinds as $k): ?><span class="ap-chip"><?= $h(mf_kind_label((string)$k['kind'])) ?><b><?= (int)$k['c'] ?></b></span><?php endforeach; ?>
  </div>
  <?php if ($fq['new'] && !$vision): ?>
  <div class="alert alert-warn" style="margin-top:10px">Фото и PDF читает только Claude — добавьте ключ Anthropic в <a href="settings.php">Настройки → Интеграции</a>, иначе шильдики и сканы счетов ждут в очереди.</div>
  <?php endif; ?>
  <?php if ($files): ?>
  <div class="table-wrap" style="margin-top:10px"><table class="tbl">
    <thead><tr><th>Файл</th><th>Тип</th><th>Что внутри</th><th>Заявка</th></tr></thead>
    <tbody>
    <?php foreach ($files as $f): ?>
      <tr>
        <td><a href="../api/file.php?mf=<?= (int)$f['id'] ?>" target="_blank" rel="noopener"><?= $h($f['filename']) ?></a><br><small class="muted"><?= $f['direction'] === 'out' ? 'отправили мы' : 'прислали нам' ?> · <?= $h(substr((string)$f['created_at'], 0, 16)) ?></small></td>
        <td><span class="badge badge--muted"><?= $h(mf_kind_label((string)$f['kind'])) ?></span></td>
        <td><?= $h($f['summary']) ?></td>
        <td><?= $f['lead_id'] ? '<a href="lead.php?id=' . (int)$f['lead_id'] . '">' . $h($f['name'] ?: '#' . $f['lead_id']) . '</a>' : '<span class="muted">—</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
</section>

<h2>Автоответчик и обучение</h2>
<section class="card">
  <p>Режим: <b><?= ['off' => 'выключен', 'learn' => 'только обучение (ничего не отправляет)', 'draft' => 'черновики (отправляет человек)', 'auto' => 'автоотправка уверенных ответов'][$arMode] ?></b>
     · ИИ: <b><?= $aiLabel !== '' ? $h($aiLabel) : 'не настроен' ?></b>
     · за 7 дней: отправлено <?= $ar['autoreply_sent'] ?>, черновиков <?= $ar['autoreply_draft'] ?>, пропущено <?= $ar['autoreply_skip'] ?><?= $ar['autoreply_failed'] ? ', ошибок ' . $ar['autoreply_failed'] : '' ?>.
     <a href="settings.php">Настроить</a></p>
  <p>Примеров «письмо клиента → ответ менеджера»: <b><?= (int)$learn['examples'] ?></b>
     <?php if ($learn['by_source']): ?><span class="muted">(<?php
        $src = ['sent' => 'из Яндекс.Почты', 'crm' => 'из CRM', 'draft_edit' => 'правки черновиков', 'draft_ok' => 'черновики без правок', 'backfill' => 'из архива'];
        echo $h(implode(', ', array_map(static fn($k, $v) => ($src[$k] ?? $k) . ' ' . $v, array_keys($learn['by_source']), $learn['by_source'])));
     ?>)</span><?php endif; ?>. Самые похожие подкладываются роботу при каждом ответе.</p>
  <?php if (($learn['shadow'] ?? 0) > 0 || $arMode === 'learn'): ?>
  <div class="card__title" style="margin-top:6px">Тихое обучение: робот против ответов менеджеров</div>
  <?php $cl = (int)($learn['shadow_close'] ?? 0); ?>
  <div class="ap-meter"><i style="width:<?= min(100, $cl) ?>%"></i></div>
  <?php if (($learn['judged'] ?? 0) > 0): ?>
  <p><b>По смыслу</b> (DeepSeek сравнивает ответ робота с ответом менеджера): оценено <b><?= (int)$learn['judged'] ?></b>, средний балл <b><?= (int)$learn['judged_avg'] ?> / 100</b>, хороших (≥ 70) — <b><?= (int)$learn['judged_good'] ?> %</b>.
    <?php $lastV = $pdo->query("SELECT score, verdict FROM crm_examples WHERE source='shadow' AND score IS NOT NULL ORDER BY id DESC LIMIT 3")->fetchAll(); ?>
    <?php foreach ($lastV as $v): ?><br><small class="muted"><?= (int)$v['score'] ?>: <?= $h($v['verdict']) ?></small><?php endforeach; ?></p>
  <?php endif; ?>
  <p class="muted">За 30 дней сравнений: <b><?= (int)($learn['shadow'] ?? 0) ?></b>; средняя похожесть ответа робота на ответ менеджера: <b><?= $learn['shadow_avg'] === null ? '—' : (int)$learn['shadow_avg'] . ' %' ?></b>; близких (≥ 60 %): <b><?= $learn['shadow_close'] === null ? '—' : $cl . ' %' ?></b>. Робот ничего не отправляет — только учится и сравнивает.</p>
  <?php endif; ?>
  <div class="card__title" style="margin-top:6px">Готовность к автоотправке</div>
  <?php $rate = (int)($learn['accept_rate'] ?? 0); ?>
  <div class="ap-meter"><i style="width:<?= min(100, $rate) ?>%"></i></div>
  <p class="muted">За 30 дней оценено черновиков робота: <b><?= (int)$learn['drafts'] ?></b>, ушли без правок: <b><?= $learn['accept_rate'] === null ? '—' : $rate . ' %' ?></b>.
    Порог: 20 черновиков и 80 % без правок.</p>
  <?php if ($learn['ready'] && $arMode !== 'auto'): ?>
  <div class="alert alert-ok">Робот готов: черновики уходят без правок. Можно включить автоотправку уверенных ответов (Настройки → Автоответы → режим «авто»). Стоп-слова, ночные часы и неуверенные ответы всё равно пойдут черновиком.</div>
  <?php elseif (!$learn['ready']): ?>
  <div class="alert alert-info"><?= $arMode === 'learn'
      ? 'Режим обучения: робот ничего не отправляет. Менеджеры отвечают как обычно (из Яндекс.Почты или CRM) — система сама сравнивает их ответы с ответами робота (шкала выше).'
      : 'Пока черновики. Отправляйте ответы робота из «Писем → Черновики» — как есть или поправив: каждая отправка учит робота и двигает эту шкалу.' ?></div>
  <?php endif; ?>
</section>
</div>
<?php render_foot();
