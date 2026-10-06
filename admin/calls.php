<?php
declare(strict_types=1);

/**
 * /admin/calls.php — Обзвон по списку: кампании, цели, результат звонка, стоп-лист.
 *
 * Экран над api/calls.php (таблицы crm_call_campaigns / crm_call_targets / crm_dnc).
 * Сам звонок наружу ставит только диспетчер calls_dispatch() по крону, и только для
 * кампании в статусе active с выключенным сухим прогоном. Новая кампания создаётся
 * черновиком в сухом прогоне — отсюда никто случайно не позвонит.
 */

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../api/calls.php';

$user = require_auth_html();
$isAdmin = ($user['role'] ?? '') === 'admin';
calls_ensure();

$T_STATUS = [
    'queued'    => 'В очереди',
    'calling'   => 'Звоним',
    'done'      => 'Дозвонились',
    'no_answer' => 'Не ответил',
    'refused'   => 'Отказ',
    'failed'    => 'Попытки исчерпаны',
    'skipped'   => 'Сухой прогон',
    'dnc'       => 'Стоп-лист',
];
$C_STATUS = ['draft' => 'Черновик', 'active' => 'Идёт', 'paused' => 'Пауза', 'done' => 'Завершена'];

$flash = ['ok' => '', 'err' => ''];
$back = static function (array $q = []): void {
    header('Location: calls.php' . ($q ? '?' . http_build_query($q) : ''));
    exit;
};

/* ----------------------------- POST ----------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    start_session();
    $sent = (string)($_POST['csrf'] ?? '');
    $cid  = (int)($_POST['cid'] ?? 0);
    if (!$sent || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $sent)) {
        $flash['err'] = 'Сессия устарела — обновите страницу и повторите.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        $uid = (int)$user['id'];
        // Действия над номерами кампании — только в существующую кампанию.
        if (in_array($action, ['targets_import', 'targets_from_leads'], true)) {
            $chk = pdo()->prepare('SELECT 1 FROM crm_call_campaigns WHERE id=?');
            $chk->execute([$cid]);
            if (!$chk->fetchColumn()) { $action = 'no_campaign'; $flash['err'] = 'Кампания не найдена — обновите страницу.'; }
        }
        try {
            switch ($action) {
                case 'campaign_create': {
                    $name = trim((string)($_POST['name'] ?? ''));
                    if ($name === '') { $flash['err'] = 'Назовите кампанию.'; break; }
                    $hours = preg_replace('/\s+/', '', (string)($_POST['hours'] ?? '10-19')) ?? '';
                    if (!preg_match('/^\d{1,2}-\d{1,2}$/', $hours)) $hours = '10-19';
                    $cid = calls_campaign_create([
                        'name' => $name, 'kind' => (string)($_POST['kind'] ?? 'cold'), 'status' => 'draft',
                        'hours' => $hours,
                        'max_attempts' => min(10, (int)($_POST['max_attempts'] ?? 3)),
                        'retry_hours'  => min(720, (int)($_POST['retry_hours'] ?? 24)),
                        'daily_limit'  => min(1000, (int)($_POST['daily_limit'] ?? 50)),
                        'dry_run' => 1, 'script' => (string)($_POST['script'] ?? ''),
                    ]);
                    audit(null, $uid, 'calls_campaign_create', ['id' => $cid, 'name' => $name]);
                    $_SESSION['calls_flash'] = 'Кампания создана черновиком в сухом прогоне. Добавьте номера.';
                    $back(['id' => $cid]);
                }

                case 'campaign_update': {
                    $st = pdo()->prepare('SELECT * FROM crm_call_campaigns WHERE id=?');
                    $st->execute([$cid]);
                    $c = $st->fetch(PDO::FETCH_ASSOC);
                    if (!$c) { $flash['err'] = 'Кампания не найдена.'; break; }
                    $status = (string)($_POST['status'] ?? $c['status']);
                    if (!isset($C_STATUS[$status])) $status = (string)$c['status'];
                    $dry = isset($_POST['dry_run']) ? 1 : 0;
                    // Живые звонки включает только администратор, и только осознанно (подтверждение в форме).
                    if ($dry === 0 && (int)$c['dry_run'] === 1) {
                        if (!$isAdmin) { $flash['err'] = 'Выключить сухой прогон может только администратор.'; break; }
                        if (($_POST['confirm_live'] ?? '') !== '1') { $flash['err'] = 'Живые звонки не включены: нет подтверждения.'; break; }
                    }
                    $hours = preg_replace('/\s+/', '', (string)($_POST['hours'] ?? $c['hours'])) ?? '';
                    if (!preg_match('/^\d{1,2}-\d{1,2}$/', $hours)) $hours = (string)$c['hours'];
                    pdo()->prepare(
                        'UPDATE crm_call_campaigns SET name=?, status=?, hours=?, max_attempts=?, retry_hours=?,
                                daily_limit=?, dry_run=?, script=? WHERE id=?'
                    )->execute([
                        mb_substr(trim((string)($_POST['name'] ?? $c['name'])) ?: (string)$c['name'], 0, 160),
                        $status, $hours,
                        max(1, min(10, (int)($_POST['max_attempts'] ?? $c['max_attempts']))),
                        max(1, min(720, (int)($_POST['retry_hours'] ?? $c['retry_hours']))),
                        max(1, min(1000, (int)($_POST['daily_limit'] ?? $c['daily_limit']))),
                        $dry, (string)($_POST['script'] ?? $c['script']), $cid,
                    ]);
                    audit(null, $uid, 'calls_campaign_update', ['id' => $cid, 'status' => $status, 'dry_run' => $dry]);
                    $_SESSION['calls_flash'] = 'Кампания сохранена.' . ($dry === 0 ? ' Внимание: сухой прогон выключен — диспетчер будет ставить реальные звонки.' : '');
                    $back(['id' => $cid]);
                }

                case 'targets_import': {
                    $rows = [];
                    foreach (preg_split('/\R/u', (string)($_POST['list'] ?? '')) ?: [] as $line) {
                        $line = trim($line);
                        if ($line === '') continue;
                        // телефон ; компания ; контакт ; город ; приоритет ; причина  (разделитель ; , или таб)
                        $p = array_map('trim', preg_split('/\s*[;\t]\s*/u', $line) ?: []);
                        if (count($p) === 1 && str_contains($line, ',')) $p = array_map('trim', explode(',', $line));
                        $rows[] = ['phone' => $p[0] ?? '', 'company' => $p[1] ?? '', 'person' => $p[2] ?? '',
                                   'city' => $p[3] ?? '', 'priority' => $p[4] ?? 5, 'reason' => $p[5] ?? '',
                                   'segment' => 'импорт вручную'];
                    }
                    if (!$rows) { $flash['err'] = 'Список пуст.'; break; }
                    $r = calls_targets_add($cid, $rows);
                    audit(null, $uid, 'calls_targets_import', ['id' => $cid] + $r);
                    $_SESSION['calls_flash'] = "Добавлено {$r['added']}; уже были — {$r['dup']}; кривой номер — {$r['bad_phone']}; в стоп-листе — {$r['dnc']}.";
                    $back(['id' => $cid]);
                }

                case 'targets_from_leads': {
                    $statuses = array_values(array_intersect((array)($_POST['lead_status'] ?? []), funnel_codes()));
                    $days = max(1, min(3650, (int)($_POST['days'] ?? 90)));
                    if (!$statuses) { $flash['err'] = 'Отметьте хотя бы один статус заявок.'; break; }
                    $in = implode(',', array_fill(0, count($statuses), '?'));
                    $st = pdo()->prepare(
                        "SELECT id, name, phone, email, reducer_type, status FROM crm_leads
                          WHERE phone <> '' AND status IN ($in) AND created_at >= NOW() - INTERVAL $days DAY
                          ORDER BY created_at DESC LIMIT 2000"
                    );
                    $st->execute($statuses);
                    $rows = [];
                    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $l) {
                        $rows[] = ['lead_id' => $l['id'], 'phone' => $l['phone'], 'person' => $l['name'],
                                   'email' => $l['email'], 'segment' => 'заявка: ' . status_label((string)$l['status']),
                                   'priority' => 3, 'reason' => trim('Заявка #' . $l['id'] . ' ' . (string)$l['reducer_type'])];
                    }
                    $r = calls_targets_add($cid, $rows);
                    audit(null, $uid, 'calls_targets_leads', ['id' => $cid, 'statuses' => $statuses] + $r);
                    $_SESSION['calls_flash'] = 'Из заявок: найдено ' . count($rows) . ", добавлено {$r['added']}; уже были — {$r['dup']}; кривой номер — {$r['bad_phone']}; в стоп-листе — {$r['dnc']}.";
                    $back(['id' => $cid]);
                }

                case 'target_result': {
                    $tid = (int)($_POST['tid'] ?? 0);
                    $res = (string)($_POST['result'] ?? '');
                    $note = trim((string)($_POST['note'] ?? ''));
                    $st = pdo()->prepare('SELECT t.*, c.max_attempts, c.retry_hours FROM crm_call_targets t
                                          JOIN crm_call_campaigns c ON c.id=t.campaign_id WHERE t.id=?');
                    $st->execute([$tid]);
                    $t = $st->fetch(PDO::FETCH_ASSOC);
                    if (!$t || !in_array($res, ['done', 'no_answer', 'refused', 'dnc', 'queued'], true)) {
                        $flash['err'] = 'Цель не найдена или результат не распознан.'; break;
                    }
                    $line = date('Y-m-d H:i') . ' ' . ($user['name'] ?: $user['login']) . ': ' . $T_STATUS[$res] . ($note !== '' ? ' — ' . $note : '');
                    if ($res === 'dnc') {
                        calls_dnc_add((string)$t['phone_norm'], $note !== '' ? $note : 'отметил ' . ($user['login'] ?? ''));
                    }
                    $attempts = (int)$t['attempts'];
                    $retryH = null;    // через сколько часов повтор: 0 — сразу, null — не повторять
                    $status = $res;
                    if ($res === 'no_answer') {
                        // Недозвон — повтор через retry_hours, пока не исчерпаны попытки.
                        $attempts++;
                        if ($attempts < (int)$t['max_attempts']) {
                            $status = 'queued';
                            $retryH = (int)$t['retry_hours'];
                        } else {
                            $status = 'failed';
                        }
                    } elseif (in_array($res, ['done', 'refused'], true)) {
                        $attempts++;
                    } elseif ($res === 'queued') {
                        $retryH = 0;
                    }
                    // Срок повтора — часами БД: диспетчер сравнивает его с NOW() базы, а пояс PHP может отличаться.
                    pdo()->prepare(
                        "UPDATE crm_call_targets SET status=?, attempts=?, last_attempt_at=IF(?, NOW(), last_attempt_at),
                                next_attempt_at=IF(? IS NULL, NULL, NOW() + INTERVAL ? HOUR),
                                result=CONCAT(COALESCE(result,''), ?, '\n') WHERE id=?"
                    )->execute([$status, $attempts, $res === 'queued' ? 0 : 1, $retryH, (int)$retryH, $line, $tid]);
                    if (!empty($t['lead_id'])) {
                        audit((int)$t['lead_id'], $uid, 'call_result', ['target' => $tid, 'result' => $res, 'note' => $note]);
                    }
                    $_SESSION['calls_flash'] = 'Результат записан: ' . $t['phone'] . ' — ' . $T_STATUS[$status] . '.';
                    $back(array_filter(['id' => (int)$t['campaign_id'], 'f' => (string)($_POST['f'] ?? ''), 'p' => (int)($_POST['p'] ?? 0)]));
                }

                case 'dnc_add': {
                    if (!calls_dnc_add((string)($_POST['phone'] ?? ''), trim((string)($_POST['reason'] ?? '')))) {
                        $flash['err'] = 'Номер не распознан (нужен российский, 10–11 цифр).'; break;
                    }
                    audit(null, $uid, 'calls_dnc_add', ['phone' => calls_norm_phone((string)$_POST['phone'])]);
                    $_SESSION['calls_flash'] = 'Номер в стоп-листе, из очередей снят.';
                    $back(['view' => 'dnc']);
                }

                case 'dnc_remove': {
                    if (!$isAdmin) { $flash['err'] = 'Убрать номер из стоп-листа может только администратор.'; break; }
                    $n = calls_norm_phone((string)($_POST['phone'] ?? ''));
                    pdo()->prepare('DELETE FROM crm_dnc WHERE phone_norm=?')->execute([$n]);
                    audit(null, $uid, 'calls_dnc_remove', ['phone' => $n]);
                    $_SESSION['calls_flash'] = 'Номер убран из стоп-листа. Снятые ранее цели в очередь сами не возвращаются.';
                    $back(['view' => 'dnc']);
                }

                case 'no_campaign':
                    break;

                default:
                    $flash['err'] = 'Действие не распознано. Обновите страницу.';
            }
        } catch (Throwable $e) {
            error_log('admin/calls: ' . $e->getMessage());
            $flash['err'] = 'Не удалось выполнить действие. Повторите; если повторяется — смотрите журнал ошибок.';
        }
    }
} else {
    start_session();
    if (!empty($_SESSION['calls_flash'])) { $flash['ok'] = (string)$_SESSION['calls_flash']; unset($_SESSION['calls_flash']); }
}

/* ----------------------------- Данные ----------------------------- */
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$csrf = csrf_token();
$view = (string)($_GET['view'] ?? '');
$id   = (int)($_GET['id'] ?? 0);

$camps = pdo()->query('SELECT * FROM crm_call_campaigns ORDER BY FIELD(status,\'active\',\'paused\',\'draft\',\'done\'), id DESC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$stats = [];
foreach (pdo()->query('SELECT campaign_id, status, COUNT(*) c FROM crm_call_targets GROUP BY campaign_id, status')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
    $stats[(int)$r['campaign_id']][(string)$r['status']] = (int)$r['c'];
}
$dncCount = (int)pdo()->query('SELECT COUNT(*) FROM crm_dnc')->fetchColumn();
$kpi = ['active' => 0, 'queued' => 0, 'done7' => 0, 'today' => 0];
foreach ($camps as $c) if ($c['status'] === 'active') $kpi['active']++;
foreach ($stats as $st_) $kpi['queued'] += ($st_['queued'] ?? 0) + ($st_['calling'] ?? 0);
try {
    $kpi['done7'] = (int)pdo()->query("SELECT COUNT(*) FROM crm_call_targets WHERE status='done' AND last_attempt_at >= NOW() - INTERVAL 7 DAY")->fetchColumn();
    $kpi['today'] = (int)pdo()->query("SELECT COUNT(*) FROM crm_call_targets WHERE last_attempt_at >= CURDATE()")->fetchColumn();
} catch (Throwable $e) { /* сводка необязательна */ }

$cur = null;
foreach ($camps as $c) if ((int)$c['id'] === $id) $cur = $c;

$progress = static function (array $s): array {
    $total = array_sum($s);
    $open  = ($s['queued'] ?? 0) + ($s['calling'] ?? 0);
    return [$total, $total - $open, $total ? (int)round(100 * ($total - $open) / $total) : 0];
};

render_head('Обзвон');
render_sidebar('calls');
?>
<style>
  /* ПОЛКИ (28.09.2026): шапка, плитки, карточки, формы (.form-grid / .form-stack), поля (.input),
     бейджи (.badge), вкладки-фильтр (.stabs) — из admin.css. Здесь только плитки кампаний,
     раскладка колонок таблицы и смысловые цвета статусов. */
  .cl-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:12px}
  .cl-camp{display:block;color:inherit;text-decoration:none;border:1px solid var(--line);border-radius:12px;padding:12px 14px;background:var(--card);transition:border-color .12s,box-shadow .12s}
  .cl-camp:hover{border-color:var(--red)}
  .cl-camp.on{border-color:var(--red);box-shadow:0 0 0 3px var(--red-soft,rgba(225,27,27,.14))}
  .cl-camp b{display:block;font-size:14px;margin-bottom:6px}
  .cl-meta{font-size:12px;color:var(--muted);display:flex;gap:8px;flex-wrap:wrap;align-items:center}
  .cl-bar{height:6px;border-radius:4px;background:var(--bg);overflow:hidden;margin:8px 0 4px}
  .cl-bar i{display:block;height:100%;background:var(--red)}
  .badge--draft,.badge--done{background:var(--bg);color:var(--muted)}
  .badge--active{background:#dcfce7;color:#15803d}.badge--paused{background:#fef3c7;color:#b45309}
  .badge--dry{background:#e0edff;color:#1d4ed8}.badge--live{background:#fee2e2;color:#b91c1c}
  /* формы — общая .form-grid / .form-stack; здесь только полная строка и тёмная тема полей
     (в .form-grid input фон пока жёсткий #fff — предложено поправить в admin.css) */
  .cl-form > .wide{grid-column:1/-1}
  .cl-form input:not([type=checkbox]):not([type=radio]){background:var(--card)}
  .cl-two{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:12px 20px;align-items:start}
  .cl-checks{display:flex;gap:4px 14px;flex-wrap:wrap;font-size:12.5px}
  .cl-tbl td{white-space:normal;vertical-align:top}
  .cl-tbl .ph{white-space:nowrap;font-variant-numeric:tabular-nums}
  .cl-tbl .why{min-width:220px;max-width:340px}
  .cl-tbl .why div{display:-webkit-box;-webkit-line-clamp:4;-webkit-box-orient:vertical;overflow:hidden}
  .cl-tbl .why div:hover{-webkit-line-clamp:unset}
  .cl-tbl .res{font-size:12px;color:var(--muted);white-space:pre-line;max-width:260px}
  /* статус цели стоит первым в ячейке над «попыток N/M»: общий .tbl .badge{margin-left:6px} сдвигает его от текста */
  .cl-tbl .badge{margin-left:0}
  .cl-act{display:flex;gap:4px;flex-wrap:wrap;align-items:center}
  .cl-act input[name=note]{width:130px;height:30px}
  .cl-act input[name=q]{width:280px;max-width:100%}
  details.cl-d>summary{cursor:pointer;font-weight:600;padding:6px 0}
  @media (max-width:640px){
    .cl-act input[name=note]{width:100%}
    .cl-act .btn{flex:1 1 auto;justify-content:center}
  }
  .cl-pager{display:flex;gap:8px;align-items:center;margin-top:10px;font-size:13px}
</style>

<div class="page-head">
  <h1 class="page-title">Обзвон</h1>
  <div class="page-head__actions">
    <a class="btn btn--ghost" href="calls.php">Кампании</a>
    <a class="btn btn--ghost" href="calls.php?view=dnc">Стоп-лист · <?= $dncCount ?></a>
  </div>
</div>

<?php if ($flash['ok']): ?><div class="alert alert-ok"><?= $h($flash['ok']) ?></div><?php endif; ?>
<?php if ($flash['err']): ?><div class="alert alert-err"><?= $h($flash['err']) ?></div><?php endif; ?>

<?php if ($view === 'dnc'): ?>
  <?php
    $q = trim((string)($_GET['q'] ?? ''));
    $qn = preg_replace('/\D+/', '', $q) ?? '';
    $st = pdo()->prepare('SELECT * FROM crm_dnc WHERE (? = \'\' OR phone_norm LIKE ? OR reason LIKE ?) ORDER BY created_at DESC LIMIT 300');
    $st->execute([$q, '%' . ($qn !== '' ? $qn : $q) . '%', '%' . $q . '%']);
    $dnc = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  ?>
  <section class="card">
    <h2>Стоп-лист «не звонить»</h2>
    <p class="hint">Номер из стоп-листа не попадёт ни в одну кампанию, а уже стоящие в очереди цели с ним снимаются сразу.</p>
    <form method="post" class="form-grid cl-form" style="margin-bottom:14px">
      <input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="action" value="dnc_add">
      <label>Телефон<input name="phone" required placeholder="+7 495 000-00-00"></label>
      <label>Причина<input name="reason" placeholder="просил не звонить"></label>
      <div><button class="btn btn-primary" type="submit">Добавить</button></div>
    </form>
    <form method="get" class="cl-act" style="margin-bottom:8px">
      <input type="hidden" name="view" value="dnc">
      <input class="input" name="q" value="<?= $h($q) ?>" placeholder="Поиск по номеру или причине">
      <button class="btn btn-ghost">Найти</button>
    </form>
    <div class="table-wrap"><table class="tbl cl-tbl">
      <thead><tr><th>Телефон</th><th>Причина</th><th>Добавлен</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($dnc as $d): ?>
        <tr>
          <td class="ph"><?= $h(calls_fmt_phone((string)$d['phone_norm'])) ?></td>
          <td><?= $h($d['reason']) ?></td>
          <td class="ph"><?= $h(substr((string)$d['created_at'], 0, 16)) ?></td>
          <td><?php if ($isAdmin): ?>
            <form method="post" onsubmit="return confirm('Убрать номер из стоп-листа?')">
              <input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="action" value="dnc_remove">
              <input type="hidden" name="phone" value="<?= $h($d['phone_norm']) ?>">
              <button class="btn btn-ghost btn-sm">Убрать</button>
            </form><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$dnc): ?><tr><td colspan="4" class="muted">Пусто.</td></tr><?php endif; ?>
      </tbody>
    </table></div>
  </section>

<?php else: ?>
  <div class="help help--info"><span class="help__icon">💡</span><div class="help__body">
    Кампания звонит только в статусе <b>«Идёт»</b> и только с <b>выключенным сухим прогоном</b> — это делает диспетчер по крону, в рабочие часы и в пределах суточного лимита.
    Пока телефония не подключена, держите сухой прогон: диспетчер проверит правила, но никому не позвонит. Результаты ручных звонков отмечайте в таблице ниже.
  </div></div>

  <div class="kpi-grid">
    <div class="kpi<?= $kpi['active'] ? ' kpi--accent' : '' ?>"><div class="kpi__label">Кампаний идёт</div><div class="kpi__value"><?= $kpi['active'] ?></div></div>
    <div class="kpi"><div class="kpi__label">Номеров в очереди</div><div class="kpi__value"><?= $kpi['queued'] ?></div></div>
    <div class="kpi"><div class="kpi__label">Попыток сегодня</div><div class="kpi__value"><?= $kpi['today'] ?></div></div>
    <div class="kpi"><div class="kpi__label">Дозвонов за 7 дней</div><div class="kpi__value<?= $kpi['done7'] ? ' good' : '' ?>"><?= $kpi['done7'] ?></div></div>
    <div class="kpi"><div class="kpi__label">В стоп-листе</div><div class="kpi__value"><?= $dncCount ?></div></div>
  </div>

  <div class="cl-grid">
    <?php foreach ($camps as $c): [$tot, $closed, $pct] = $progress($stats[(int)$c['id']] ?? []); ?>
      <a class="cl-camp<?= $cur && (int)$cur['id'] === (int)$c['id'] ? ' on' : '' ?>" href="calls.php?id=<?= (int)$c['id'] ?>">
        <b><?= $h($c['name']) ?></b>
        <div class="cl-meta">
          <span class="badge badge--<?= $h($c['status']) ?>"><?= $h($C_STATUS[$c['status']] ?? $c['status']) ?></span>
          <span class="badge <?= (int)$c['dry_run'] ? 'badge--dry' : 'badge--live' ?>"><?= (int)$c['dry_run'] ? 'сухой прогон' : 'живые звонки' ?></span>
          <span><?= $c['kind'] === 'client' ? 'клиенты' : 'холодные' ?></span>
        </div>
        <div class="cl-bar"><i style="width:<?= $pct ?>%"></i></div>
        <div class="cl-meta"><span>Отработано <?= $closed ?> из <?= $tot ?> (<?= $pct ?>%)</span><span>дозвон: <?= (int)($stats[(int)$c['id']]['done'] ?? 0) ?></span></div>
      </a>
    <?php endforeach; ?>
    <?php if (!$camps): ?><div class="muted">Кампаний пока нет — создайте первую ниже.</div><?php endif; ?>
  </div>

  <?php if ($cur): $cs = $stats[(int)$cur['id']] ?? []; ?>
    <?php
      $f = (string)($_GET['f'] ?? '');
      if ($f !== '' && !isset($T_STATUS[$f])) $f = '';
      $page = max(0, (int)($_GET['p'] ?? 0));
      $per = 50;
      $where = 'campaign_id = ?' . ($f !== '' ? ' AND status = ?' : '');
      $args = $f !== '' ? [(int)$cur['id'], $f] : [(int)$cur['id']];
      $cnt = pdo()->prepare("SELECT COUNT(*) FROM crm_call_targets WHERE $where");
      $cnt->execute($args);
      $found = (int)$cnt->fetchColumn();
      $st = pdo()->prepare("SELECT * FROM crm_call_targets WHERE $where
                            ORDER BY FIELD(status,'calling','queued','no_answer','done','refused','failed','skipped','dnc'), priority, id
                            LIMIT $per OFFSET " . ($page * $per));
      $st->execute($args);
      $targets = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
      $today = calls_today_count((int)$cur['id']);
    ?>
    <section class="card">
      <h2><?= $h($cur['name']) ?> <span class="muted" style="font-weight:400;font-size:13px">· сегодня попыток <?= $today ?> из <?= (int)$cur['daily_limit'] ?> · часы <?= $h($cur['hours']) ?></span></h2>

      <details class="cl-d">
        <summary>Настройки кампании</summary>
        <form method="post" class="form-grid cl-form" id="campForm" data-dry="<?= (int)$cur['dry_run'] ?>">
          <input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="action" value="campaign_update">
          <input type="hidden" name="cid" value="<?= (int)$cur['id'] ?>"><input type="hidden" name="confirm_live" value="0">
          <label class="wide">Название<input name="name" value="<?= $h($cur['name']) ?>"></label>
          <label>Статус<select name="status"><?php foreach ($C_STATUS as $k => $v): ?><option value="<?= $k ?>"<?= $cur['status'] === $k ? ' selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></label>
          <label>Часы, МСК<input name="hours" value="<?= $h($cur['hours']) ?>" placeholder="10-19"></label>
          <label>Попыток на номер<input type="number" name="max_attempts" min="1" max="10" value="<?= (int)$cur['max_attempts'] ?>"></label>
          <label>Повтор через, ч<input type="number" name="retry_hours" min="1" max="720" value="<?= (int)$cur['retry_hours'] ?>"></label>
          <label>Лимит в сутки<input type="number" name="daily_limit" min="1" max="1000" value="<?= (int)$cur['daily_limit'] ?>"></label>
          <label class="check-label wide"><input type="checkbox" name="dry_run" <?= (int)$cur['dry_run'] ? 'checked' : '' ?><?= $isAdmin ? '' : ' disabled' ?>> Сухой прогон (не звонить)</label>
          <?php if (!$isAdmin && (int)$cur['dry_run']): ?><input type="hidden" name="dry_run" value="1"><?php endif; ?>
          <label class="wide">Скрипт: цель звонка, вопросы, оффер<textarea name="script" rows="5"><?= $h($cur['script']) ?></textarea></label>
          <div><button class="btn btn-primary" type="submit">Сохранить</button></div>
        </form>
      </details>

      <details class="cl-d">
        <summary>Добавить номера</summary>
        <div class="cl-two">
          <form method="post" class="form-stack cl-form">
            <input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="action" value="targets_import">
            <input type="hidden" name="cid" value="<?= (int)$cur['id'] ?>">
            <label>Списком — строка на номер: <code>телефон; компания; контакт; город; приоритет 1–9; причина</code>
              <textarea name="list" rows="6" placeholder="+7 495 151-41-02; ООО Пример; Иван; Москва; 2; спрашивал SEW R97"></textarea></label>
            <div><button class="btn btn-primary" type="submit">Добавить списком</button></div>
          </form>
          <form method="post" class="form-stack cl-form">
            <input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="action" value="targets_from_leads">
            <input type="hidden" name="cid" value="<?= (int)$cur['id'] ?>">
            <label>Из заявок CRM со статусом</label>
            <div class="cl-checks"><?php foreach (funnel() as $code => [$lbl]): ?>
              <label class="check-label"><input type="checkbox" name="lead_status[]" value="<?= $h($code) ?>"<?= in_array($code, ['clarify', 'sent'], true) ? ' checked' : '' ?>> <?= $h($lbl) ?></label>
            <?php endforeach; ?></div>
            <label>За последние, дней<input type="number" name="days" min="1" max="3650" value="90"></label>
            <div><button class="btn btn-ghost" type="submit">Добавить из заявок</button></div>
          </form>
        </div>
      </details>

      <div class="stabs" style="margin-top:10px">
        <a href="calls.php?id=<?= (int)$cur['id'] ?>" class="stab-btn<?= $f === '' ? ' is-active' : '' ?>">Все · <?= array_sum($cs) ?></a>
        <?php foreach ($T_STATUS as $k => $v): if (empty($cs[$k])) continue; ?>
          <a href="calls.php?id=<?= (int)$cur['id'] ?>&f=<?= $k ?>" class="stab-btn<?= $f === $k ? ' is-active' : '' ?>"><?= $v ?> · <?= (int)$cs[$k] ?></a>
        <?php endforeach; ?>
      </div>

      <div class="table-wrap"><table class="tbl cl-tbl">
        <thead><tr><th>Телефон</th><th>Компания / контакт</th><th>Почему звоним</th><th>Статус</th><th>Журнал</th><th>Результат звонка</th></tr></thead>
        <tbody>
        <?php foreach ($targets as $t): ?>
          <tr>
            <td class="ph"><a href="tel:+<?= $h($t['phone_norm']) ?>"><?= $h($t['phone']) ?></a><br><span class="muted">приоритет <?= (int)$t['priority'] ?></span></td>
            <td><?= $h($t['company']) ?><?= $t['person'] !== '' ? '<br><span class="muted">' . $h($t['person']) . '</span>' : '' ?>
              <?= !empty($t['lead_id']) ? '<br><a href="lead.php?id=' . (int)$t['lead_id'] . '">заявка #' . (int)$t['lead_id'] . '</a>' : '' ?>
              <?= $t['city'] !== '' ? '<br><span class="muted">' . $h($t['city']) . '</span>' : '' ?></td>
            <td class="why"><div title="<?= $h($t['reason']) ?>"><?= $h($t['reason']) ?></div><?= $t['segment'] !== '' ? '<br><span class="muted">' . $h($t['segment']) . '</span>' : '' ?></td>
            <td><span class="badge"><?= $h($T_STATUS[$t['status']] ?? $t['status']) ?></span><br><span class="muted">попыток <?= (int)$t['attempts'] ?>/<?= (int)$cur['max_attempts'] ?></span>
              <?= $t['next_attempt_at'] && $t['status'] === 'queued' && strtotime((string)$t['next_attempt_at']) > time() ? '<br><span class="muted">след. ' . $h(substr((string)$t['next_attempt_at'], 5, 11)) . '</span>' : '' ?></td>
            <td class="res"><?= $h(trim((string)$t['result'])) ?></td>
            <td>
              <?php if ($t['status'] !== 'dnc'): ?>
              <form method="post" class="cl-act">
                <input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="action" value="target_result">
                <input type="hidden" name="tid" value="<?= (int)$t['id'] ?>"><input type="hidden" name="f" value="<?= $h($f) ?>"><input type="hidden" name="p" value="<?= $page ?>">
                <input class="input" name="note" placeholder="заметка">
                <button class="btn btn-ghost btn-sm" name="result" value="done" title="Дозвонились, разговор состоялся">✓ Дозвон</button>
                <button class="btn btn-ghost btn-sm" name="result" value="no_answer" title="Повтор через <?= (int)$cur['retry_hours'] ?> ч">Не ответил</button>
                <button class="btn btn-ghost btn-sm" name="result" value="refused">Отказ</button>
                <button class="btn btn-ghost btn-sm" name="result" value="dnc" onclick="return confirm('Внести номер в стоп-лист? Он будет снят со всех кампаний.')">Не звонить</button>
                <?php if (!in_array($t['status'], ['queued', 'calling'], true)): ?><button class="btn btn-ghost btn-sm" name="result" value="queued" title="Вернуть в очередь">↺</button><?php endif; ?>
              </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$targets): ?><tr><td colspan="6" class="muted">Номеров нет.</td></tr><?php endif; ?>
        </tbody>
      </table></div>
      <?php $pages = (int)ceil($found / $per); if ($pages > 1): ?>
        <div class="cl-pager">
          <?php if ($page > 0): ?><a class="btn btn-ghost btn-sm" href="calls.php?<?= $h(http_build_query(array_filter(['id' => (int)$cur['id'], 'f' => $f, 'p' => $page - 1]))) ?>">← Назад</a><?php endif; ?>
          <span class="muted">Стр. <?= $page + 1 ?> из <?= $pages ?> · <?= $found ?> номеров</span>
          <?php if ($page + 1 < $pages): ?><a class="btn btn-ghost btn-sm" href="calls.php?<?= $h(http_build_query(array_filter(['id' => (int)$cur['id'], 'f' => $f, 'p' => $page + 1]))) ?>">Вперёд →</a><?php endif; ?>
        </div>
      <?php endif; ?>
    </section>
    <script>
    (function(){
      var f=document.getElementById('campForm'); if(!f) return;
      f.addEventListener('submit',function(e){
        var dry=f.querySelector('input[name=dry_run][type=checkbox]');
        if(f.dataset.dry==='1' && dry && !dry.checked){
          if(!confirm('Выключить сухой прогон? Диспетчер начнёт ставить НАСТОЯЩИЕ звонки клиентам, если кампания в статусе «Идёт».')){ e.preventDefault(); return; }
          f.querySelector('input[name=confirm_live]').value='1';
        }
      });
    })();
    </script>
  <?php endif; ?>

  <section class="card">
    <details class="cl-d"<?= $camps ? '' : ' open' ?>>
      <summary>Новая кампания</summary>
      <form method="post" class="form-grid cl-form">
        <input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="action" value="campaign_create">
        <label class="wide">Название<input name="name" required placeholder="Клиенты, ждущие КП — сентябрь"></label>
        <label>Тип<select name="kind"><option value="client">По клиентам (переписка)</option><option value="cold">Холодные</option></select></label>
        <label>Часы, МСК<input name="hours" value="10-19"></label>
        <label>Попыток на номер<input type="number" name="max_attempts" min="1" max="10" value="3"></label>
        <label>Повтор через, ч<input type="number" name="retry_hours" min="1" max="720" value="24"></label>
        <label>Лимит в сутки<input type="number" name="daily_limit" min="1" max="1000" value="50"></label>
        <label class="wide">Скрипт<textarea name="script" rows="3" placeholder="Цель звонка, 2–3 вопроса, что предложить"></textarea></label>
        <div><button class="btn btn-primary" type="submit">Создать черновик</button></div>
      </form>
    </details>
  </section>
<?php endif; ?>
<?php
render_foot();
