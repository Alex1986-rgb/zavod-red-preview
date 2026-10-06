<?php
declare(strict_types=1);

require __DIR__ . '/_guard.php';
require __DIR__ . '/_layout.php';

$q = trim((string)($_GET['q'] ?? ''));
$leads = [];
$messages = [];
$leadsTotal = 0;
$messagesTotal = 0;
$err = '';

const SEARCH_LIMIT = 100;

if ($q !== '') {
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    $leadsWhere = 'name LIKE ? OR phone LIKE ? OR email LIKE ? OR message LIKE ? OR reducer_type LIKE ?';
    $leadsArgs  = [$like, $like, $like, $like, $like];
    $msgWhere   = 'body LIKE ? OR contact LIKE ?';
    $msgArgs    = [$like, $like];
    try {
        $st = pdo()->prepare(
            "SELECT id, created_at, name, phone, email, message, status
               FROM crm_leads
              WHERE $leadsWhere
              ORDER BY id DESC
              LIMIT " . SEARCH_LIMIT
        );
        $st->execute($leadsArgs);
        $leads = $st->fetchAll();

        $stc = pdo()->prepare("SELECT COUNT(*) FROM crm_leads WHERE $leadsWhere");
        $stc->execute($leadsArgs);
        $leadsTotal = (int)$stc->fetchColumn();

        $st2 = pdo()->prepare(
            "SELECT id, lead_id, created_at, channel, direction, contact, subject, body
               FROM crm_messages
              WHERE $msgWhere
              ORDER BY id DESC
              LIMIT " . SEARCH_LIMIT
        );
        $st2->execute($msgArgs);
        $messages = $st2->fetchAll();

        $stc2 = pdo()->prepare("SELECT COUNT(*) FROM crm_messages WHERE $msgWhere");
        $stc2->execute($msgArgs);
        $messagesTotal = (int)$stc2->fetchColumn();
    } catch (Throwable $e) {
        $err = 'Ошибка поиска. Проверьте подключение к базе.';
    }
}

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function snippet(?string $s, int $len = 140): string {
    $s = trim(preg_replace('/\s+/u', ' ', (string)$s) ?? '');
    if (function_exists('mb_strlen') && mb_strlen($s, 'UTF-8') > $len) {
        $s = mb_substr($s, 0, $len, 'UTF-8') . '…';
    } elseif (!function_exists('mb_strlen') && strlen($s) > $len) {
        $s = substr($s, 0, $len) . '…';
    }
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

render_head('Поиск');
render_sidebar('search');
?>
<style>
  /* Кнопка, поле, бейджи статусов, сообщение об ошибке и заголовки секций — из admin.css
     (.btn, .input, .badge--*, .alert-err, .shelf > h2). Здесь только раскладка поиска и таблиц. */
  .s-form{display:flex;gap:10px;align-items:center;max-width:680px}
  .s-form input[type=search]{flex:1}
  .s-count{background:var(--bg);color:var(--muted);border-radius:var(--r-pill);padding:1px 10px;font-size:13px;font-weight:600}
  /* Таблицы результатов: рамку и фон даёт .table-wrap; ячейки переносят длинные фрагменты текста */
  .s-table{width:100%;border-collapse:collapse}
  .s-table th,.s-table td{padding:10px 12px;text-align:left;border-bottom:1px solid var(--line);font-size:14px;vertical-align:top}
  .s-table tbody tr{transition:background .14s}
  .s-table tbody tr:hover{background:var(--bg)}
  .s-table th{background:var(--bg);font-size:12px;text-transform:uppercase;letter-spacing:.03em;color:var(--muted)}
  .s-table tr:last-child td{border-bottom:0}
  .s-table a{font-weight:600}
</style>

<div class="page-head"><h1 class="page-title">Поиск</h1></div>

<div class="help help--info">
  <span class="help__icon">💡</span>
  <div class="help__body">
    <b>Сквозной поиск по всей базе.</b> Одним запросом ищет сразу по заявкам и по истории сообщений.
    <ul>
      <li><b>Что можно искать:</b> имя клиента, телефон, email, текст обращения и тип редуктора, а также текст переписки и контакт в сообщениях.</li>
      <li><b>Как искать:</b> введите запрос (хотя бы 2–3 символа) и нажмите «Искать». Можно искать по части слова или номера — например, «черв» найдёт «червячный».</li>
      <li><b>Результаты</b> делятся на «Заявки» и «Сообщения». Нажмите на имя клиента или номер лида в результатах, чтобы открыть карточку заявки целиком.</li>
    </ul>
  </div>
</div>

<form method="get" class="s-form" role="search" onsubmit="if((this.q.value||'').trim().length<2){if(window.ZR&&ZR.toast)ZR.toast('Введите минимум 2 символа','error');this.q.focus();return false;}">
  <input type="search" class="input" name="q" minlength="2" value="<?= h($q) ?>" placeholder="Имя, телефон, email, текст сообщения…" autofocus>
  <button type="submit" class="btn btn--primary">Искать</button>
</form>
<p class="hint">Минимум 2–3 символа. Поиск идёт по части слова, регистр не важен.</p>

<?php if ($err !== ''): ?>
  <div class="alert alert-err"><?= h($err) ?></div>
<?php endif; ?>

<?php if ($q === ''): ?>
  <p class="muted">Введите запрос для поиска по заявкам и сообщениям.</p>
<?php else: ?>

  <div class="s-block shelf">
    <h2>Заявки <span class="s-count"><?= (int)$leadsTotal ?></span></h2>
    <?php if (!$leads): ?>
      <p class="muted">Ничего не найдено.</p>
    <?php else: ?>
      <?php if ($leadsTotal > count($leads)): ?>
        <p class="muted">Показаны первые <?= count($leads) ?> из <?= (int)$leadsTotal ?> — уточните запрос.</p>
      <?php endif; ?>
      <div class="table-wrap"><table class="s-table">
        <thead><tr><th>Имя</th><th>Телефон</th><th>Email</th><th>Запрос</th><th>Статус</th></tr></thead>
        <tbody>
        <?php foreach ($leads as $l): $st = (string)($l['status'] ?? 'new'); ?>
          <tr>
            <td><a href="lead.php?id=<?= (int)$l['id'] ?>"><?= h($l['name']) !== '' ? h($l['name']) : ('Лид #' . (int)$l['id']) ?></a></td>
            <td><?= h($l['phone']) ?: '<span class="muted">—</span>' ?></td>
            <td><?= h($l['email']) ?: '<span class="muted">—</span>' ?></td>
            <td><?= snippet($l['message']) ?: '<span class="muted">—</span>' ?></td>
            <td><span class="badge badge--<?= h($st) ?>"><?= h(status_label($st)) ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </div>

  <div class="s-block shelf">
    <h2>Сообщения <span class="s-count"><?= (int)$messagesTotal ?></span></h2>
    <?php if (!$messages): ?>
      <p class="muted">Ничего не найдено.</p>
    <?php else: ?>
      <?php if ($messagesTotal > count($messages)): ?>
        <p class="muted">Показаны первые <?= count($messages) ?> из <?= (int)$messagesTotal ?> — уточните запрос.</p>
      <?php endif; ?>
      <div class="table-wrap"><table class="s-table">
        <thead><tr><th>Канал</th><th>Контакт</th><th>Тема / текст</th><th>Лид</th></tr></thead>
        <tbody>
        <?php foreach ($messages as $m):
          $dir = ($m['direction'] ?? '') === 'out' ? '→' : '←';
        ?>
          <tr>
            <td><?= h($m['channel']) ?> <span class="muted"><?= $dir ?></span></td>
            <td><?= h($m['contact']) ?: '<span class="muted">—</span>' ?></td>
            <td>
              <?php if (($m['subject'] ?? '') !== ''): ?><strong><?= h($m['subject']) ?></strong><br><?php endif; ?>
              <?= snippet($m['body']) ?>
            </td>
            <td>
              <?php if (!empty($m['lead_id'])): ?>
                <a href="lead.php?id=<?= (int)$m['lead_id'] ?>">#<?= (int)$m['lead_id'] ?></a>
              <?php else: ?><span class="muted">—</span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </div>

<?php endif; ?>
<?php render_foot();
