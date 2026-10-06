<?php
declare(strict_types=1);

/**
 * «Незавершённые попытки» — заявки, которые НЕ доехали до crm_leads.
 *
 * Зачем: раньше любой отказ приёма (превышен post_max_size, не прошла валидация,
 * сработал лимит частоты, упала БД) выбрасывал заявку бесследно — контакты клиента
 * исчезали, и никто об этом не узнавал. Теперь api/feedback.php пишет КАЖДУЮ попытку
 * в crm_lead_attempts, а здесь менеджер видит их и может восстановить лид в один клик.
 */

require __DIR__ . '/_guard.php';
require __DIR__ . '/_layout.php';

$OUTCOMES = [
    'rejected'        => 'Отклонена',
    'lost_post_limit' => 'Пакет отброшен (лимит размера)',
    'saved'           => 'Сохранена',
    'converted'       => 'Восстановлена вручную',
    'duplicate'       => 'Дубль (двойной клик)',
    'direct'          => 'Отправлена напрямую (CRM была недоступна)',
];

$tableMissing = false;
$msg = '';

/** Есть ли таблица (её создаёт feedback.php при первой попытке). */
function attempts_table_exists(PDO $pdo): bool {
    try { $pdo->query('SELECT 1 FROM crm_lead_attempts LIMIT 1'); return true; }
    catch (Throwable $e) { return false; }
}

$pdo = pdo();

// --- Восстановление: создаём настоящий лид из попытки ---
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['convert_id'])) {
    $aid = (int)$_POST['convert_id'];
    start_session();
    $sentCsrf = (string)($_POST['csrf'] ?? '');
    if (!$sentCsrf || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $sentCsrf)) {
        $_SESSION['att_flash'] = 'Сессия устарела — обновите страницу и повторите.';
        header('Location: attempts.php'); exit;
    }
    try {
        // Уже восстановленную (или успешную) попытку второй раз в лид не превращаем:
        // иначе повторная отправка формы плодила дубль-заявки.
        $st = $pdo->prepare("SELECT * FROM crm_lead_attempts WHERE id = ? AND outcome NOT IN ('saved','converted') LIMIT 1");
        $st->execute([$aid]);
        $a = $st->fetch(PDO::FETCH_ASSOC);
        if ($a) {
            $ins = $pdo->prepare(
                'INSERT INTO crm_leads
                    (created_at, updated_at, name, phone, email, source, status,
                     page_url, message, ip, user_agent)
                 VALUES (NOW(), NOW(), ?, ?, ?, "site", "new", ?, ?, ?, ?)'
            );
            $ins->execute([
                (string)$a['name'], (string)$a['phone'], (string)$a['email'],
                (string)$a['page_url'],
                "Восстановлено из незавершённой попытки #{$aid}.\nПричина отказа: "
                    . (string)$a['reason']
                    . ((string)$a['message'] !== '' ? "\n\nСообщение клиента:\n" . (string)$a['message'] : '')
                    . ((string)$a['file_name'] !== '' ? "\n\nПрикладывал файл: " . (string)$a['file_name'] : ''),
                (string)$a['ip'], (string)$a['user_agent'],
            ]);
            $newId = (int)$pdo->lastInsertId();
            $upd = $pdo->prepare('UPDATE crm_lead_attempts SET outcome = "converted", lead_id = ? WHERE id = ?');
            $upd->execute([$newId, $aid]);
            $msg = "Заявка #{$newId} создана из попытки #{$aid}.";
        } else {
            $msg = "Попытка #{$aid} уже восстановлена или не найдена — новая заявка не создана.";
        }
    } catch (Throwable $e) {
        $msg = 'Не удалось восстановить: ' . $e->getMessage();
    }
    // PRG: после действия — на GET, чтобы F5 не повторял создание заявки.
    $_SESSION['att_flash'] = $msg;
    header('Location: attempts.php?outcome=' . rawurlencode((string)($_GET['outcome'] ?? 'problem')));
    exit;
}
start_session();
if (!empty($_SESSION['att_flash'])) { $msg = (string)$_SESSION['att_flash']; unset($_SESSION['att_flash']); }

$filter = (string)($_GET['outcome'] ?? 'problem');
$rows = [];
$counts = ['problem' => 0, 'saved' => 0, 'total' => 0];

if (!attempts_table_exists($pdo)) {
    $tableMissing = true;
} else {
    try {
        $c = $pdo->query('SELECT outcome, COUNT(*) c FROM crm_lead_attempts GROUP BY outcome')
                 ->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($c as $k => $v) {
            $counts['total'] += (int)$v;
            if ($k === 'saved') $counts['saved'] += (int)$v; else $counts['problem'] += (int)$v;
        }
        if ($filter === 'problem') {
            $st = $pdo->query('SELECT * FROM crm_lead_attempts WHERE outcome <> "saved"
                               ORDER BY id DESC LIMIT 500');
        } elseif ($filter === 'all') {
            $st = $pdo->query('SELECT * FROM crm_lead_attempts ORDER BY id DESC LIMIT 500');
        } else {
            $st = $pdo->prepare('SELECT * FROM crm_lead_attempts WHERE outcome = ? ORDER BY id DESC LIMIT 500');
            $st->execute([$filter]);
        }
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $tableMissing = true; }
}

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

render_head('Незавершённые попытки');
render_sidebar('attempts');
?>
<style>
  /* ПОЛКИ (28.09.2026): баннеры — .help, вкладки — .stabs, таблицы — .table-wrap + .tbl,
     бейджи — .badge, пустое состояние — .empty (всё из admin.css). Здесь только раскладка
     колонок журнала и смысловые цвета итогов попытки. */
  .att-tbl td{vertical-align:top}
  .att-tbl--wrap td:not(.nowrap){white-space:normal}
  .att-why{color:#a8071a;font-size:13px}
  .att-ct{font-weight:600}
  .att-msg{margin-top:4px;color:var(--muted)}
  .att-url{max-width:220px;word-break:break-all;font-size:12px;color:var(--muted)}
  .att-file{font-size:12px}
  /* бейдж итога стоит первым в ячейке: общий .tbl .badge{margin-left:6px} (писался под clients.php) уводит его от края колонки */
  .att-tbl .badge{margin-left:0}
  .tbl .badge--rejected{background:#fff1f0;color:#a8071a}
  .tbl .badge--lost_post_limit{background:#fff7e6;color:#ad4e00}
  .tbl .badge--saved{background:#f6ffed;color:#237804}
  .tbl .badge--converted{background:#e6f4ff;color:#0958d9}
</style>

<?php
/* Клики по контактам (06.08.2026). Заявку такой клик намеренно не создаёт — контактов
   нет, перезвонить некому. Но факт интереса терять незачем: contact_click.php пишет
   событие в crm_events, а заявка создаётся отдельно. Здесь — хронология кликов
   со ссылкой на заявку, чтобы видеть путь: нажал → карточка. */
$clicks = [];
try {
    $clicks = $pdo->query(
        "SELECT id, lead_id, payload, created_at FROM crm_events
         WHERE type = 'contact_click'
         ORDER BY id DESC LIMIT 100"
    )->fetchAll();
} catch (Throwable $e) { $clicks = []; }
?>
<div class="page-head"><h1 class="page-title">Незавершённые попытки</h1></div>
<p class="page-lead">
  Отправки формы, которые <b>не стали заявками</b>. Контакты сохранены — можно перезвонить
  или восстановить лид одной кнопкой.
</p>

<?php if ($msg !== ''): ?>
  <div class="help help--info"><div class="help__body"><?= $h($msg) ?></div></div>
<?php endif; ?>

<?php if ($tableMissing): ?>
  <div class="help help--info"><div class="help__body">
    Журнал попыток пока пуст — таблица <code>crm_lead_attempts</code> создастся автоматически
    при первой отправке формы после обновления. Это нормально для только что развёрнутой версии.
  </div></div>
<?php else: ?>
  <div class="help help--info"><div class="help__body">
    Всего записей: <b><?= (int)$counts['total'] ?></b> ·
    проблемных: <b style="color:#a8071a"><?= (int)$counts['problem'] ?></b> ·
    успешных: <b style="color:#237804"><?= (int)$counts['saved'] ?></b>.
    Успешные показываются для сверки «отправлено ↔ сохранено».
  </div></div>

  <div class="stabs">
    <a href="?outcome=problem" class="stab-btn<?= $filter === 'problem' ? ' is-active' : '' ?>">Только проблемные</a>
    <a href="?outcome=all" class="stab-btn<?= $filter === 'all' ? ' is-active' : '' ?>">Все</a>
    <a href="?outcome=saved" class="stab-btn<?= $filter === 'saved' ? ' is-active' : '' ?>">Успешные</a>
  </div>

  <div class="table-wrap"><div class="table-scroll">
  <table class="tbl att-tbl att-tbl--wrap">
    <thead><tr>
      <th>Когда</th><th>Итог</th><th>Контакты</th><th>Причина / сообщение</th>
      <th>Страница</th><th>Файл</th><th></th>
    </tr></thead>
    <tbody>
    <?php if (!$rows): ?>
      <tr><td colspan="7"><div class="empty">Записей нет — значит, все отправки доходят.</div></td></tr>
    <?php endif; ?>
    <?php foreach ($rows as $r): $out = (string)$r['outcome']; ?>
      <tr>
        <td class="nowrap"><?= $h(substr((string)$r['created_at'], 0, 16)) ?></td>
        <td><span class="badge badge--<?= $h($out) ?>"><?= $h($OUTCOMES[$out] ?? $out) ?></span></td>
        <td>
          <?php if ((string)$r['name'] !== ''): ?><div class="att-ct"><?= $h($r['name']) ?></div><?php endif; ?>
          <?php if ((string)$r['phone'] !== ''): ?>
            <div class="nowrap"><a href="tel:<?= $h(preg_replace('/[^\d+]/', '', (string)$r['phone'])) ?>"><?= $h($r['phone']) ?></a></div>
          <?php endif; ?>
          <?php if ((string)$r['email'] !== ''): ?>
            <div><a href="mailto:<?= $h($r['email']) ?>"><?= $h($r['email']) ?></a></div>
          <?php endif; ?>
          <?php if ((string)$r['name'] === '' && (string)$r['phone'] === '' && (string)$r['email'] === ''): ?>
            <span class="muted">— контактов нет —</span>
          <?php endif; ?>
        </td>
        <td>
          <div class="att-why"><?= $h($r['reason']) ?></div>
          <?php if ((string)$r['message'] !== ''): ?>
            <div class="att-msg"><?= nl2br($h(mb_substr((string)$r['message'], 0, 400))) ?></div>
          <?php endif; ?>
        </td>
        <td class="att-url"><?= $h($r['page_url']) ?></td>
        <td class="att-file"><?= $h($r['file_name']) ?></td>
        <td class="nowrap">
          <?php if ($out !== 'saved' && $out !== 'converted'
                    && ((string)$r['phone'] !== '' || (string)$r['email'] !== '')): ?>
            <form method="post" style="margin:0" onsubmit="return confirm('Создать заявку из этой попытки?')">
              <input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>">
              <input type="hidden" name="convert_id" value="<?= (int)$r['id'] ?>">
              <button class="btn" type="submit">Создать заявку</button>
            </form>
          <?php elseif ((int)$r['lead_id'] > 0): ?>
            <a href="lead.php?id=<?= (int)$r['lead_id'] ?>">Заявка #<?= (int)$r['lead_id'] ?></a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div></div>
<?php endif; ?>

<h2>Клики по контактам</h2>
<p class="page-lead">
  Нажатия на почту, телефон и мессенджеры. Каждое создаёт заявку в разделе
  «Заявки» — контакты посетитель не оставил, но обращение состоялось.
  Здесь видно, с какой страницы пришёл клик и в какую карточку он лёг.
</p>
<?php if (!$clicks): ?>
  <div class="card"><div class="empty">Кликов пока не зафиксировано.</div></div>
<?php else: ?>
  <div class="table-wrap"><table class="tbl att-tbl">
    <thead><tr><th>Когда</th><th>Куда нажали</th><th>Страница</th><th>Заявка</th></tr></thead>
    <tbody>
    <?php foreach ($clicks as $c):
        $d = json_decode((string)$c['payload'], true) ?: [];
        $page = (string)($d['page'] ?? '');
        $short = $page !== '' ? preg_replace('~^https?://[^/]+~', '', $page) : '';
    ?>
      <tr>
        <td><?= $h(date('d.m.Y H:i', strtotime((string)$c['created_at']))) ?></td>
        <td class="att-ct"><?= $h((string)($d['label'] ?? $d['kind'] ?? '—')) ?></td>
        <td><?php if ($page !== '' && preg_match('~^https?://~i', $page)): ?><a href="<?= $h($page) ?>" target="_blank" rel="noopener"><?= $h($short) ?></a><?php elseif ($page !== ''): ?><?= $h($page) ?><?php else: ?><span class="muted">—</span><?php endif; ?></td>
        <td><?php if ((int)$c['lead_id'] > 0): ?><a href="lead.php?id=<?= (int)$c['lead_id'] ?>">Заявка #<?= (int)$c['lead_id'] ?></a><?php else: ?><span class="muted">—</span><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
<?php endif; ?>

<?php render_foot();
