<?php
declare(strict_types=1);

/**
 * «Клиенты» — зарегистрированные пользователи личного кабинета сайта.
 *
 * Зачем: регистрации попадали только в общий список заявок (и повторная регистрация
 * известного клиента вообще склеивалась с его старой карточкой) — базы «кому звонить,
 * кому слать рассылку» не было видно. Здесь полный список из таблицы users с контактами
 * и выгрузкой CSV для рассылки/обзвона.
 *
 * Источник данных — таблица users (её пишет api/register.php сайта, БД общая с CRM).
 *
 * ВЁРСТКА (обновлена 11.08.2026). Страница была написана на устаревшем наборе классов
 * `att-*` + `scrollbox` и полутора десятках инлайн-стилей — она осталась от старого
 * поколения админки и визуально выпадала из остальных разделов. Переведена на текущую
 * систему `admin.css`: page-head / kpi-grid / filters / table-wrap + tbl / empty, как на
 * дашборде и в отчётах. Логика запросов, экспорта и экранирования НЕ менялась.
 */

require __DIR__ . '/_guard.php';

$pdo = pdo();
$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

function users_table_exists(PDO $pdo): bool {
    try { $pdo->query('SELECT 1 FROM users LIMIT 1'); return true; }
    catch (Throwable $e) { return false; }
}

$tableMissing = !users_table_exists($pdo);
$q = trim((string)($_GET['q'] ?? ''));

$rows = [];
$counts = ['total' => 0, 'week' => 0, 'month' => 0, 'active' => 0];
if (!$tableMissing) {
    try {
        $counts['total'] = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $counts['week']  = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)')->fetchColumn();
        $counts['month'] = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)')->fetchColumn();
        // Заходили за месяц — это и есть «живая» база для обзвона: регистрация без единого
        // входа чаще всего означает брошенную корзину, а не активного клиента.
        $counts['active'] = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE last_login >= DATE_SUB(NOW(), INTERVAL 30 DAY)')->fetchColumn();
        if ($q !== '') {
            $st = $pdo->prepare(
                'SELECT id, name, email, phone, company, created_at, last_login FROM users
                  WHERE name LIKE ? OR email LIKE ? OR phone LIKE ? OR company LIKE ?
                  ORDER BY created_at DESC LIMIT 500'
            );
            $like = '%' . $q . '%';
            $st->execute([$like, $like, $like, $like]);
        } else {
            $st = $pdo->query(
                'SELECT id, name, email, phone, company, created_at, last_login FROM users
                  ORDER BY created_at DESC LIMIT 500'
            );
        }
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $rows = []; }
}

// --- Выгрузка CSV (для рассылки/обзвона) — ДО вывода layout, отдаём файл и выходим ---
if (isset($_GET['export']) && $_GET['export'] === 'csv' && !$tableMissing) {
    // Вся база клиентов с контактами — только администратору и с отметкой в журнале (как api/export.php).
    $meExp = current_user() ?? [];
    if (($meExp['role'] ?? '') !== 'admin') { http_response_code(403); exit('Выгрузка клиентов — только для администратора'); }
    audit(null, (int)($meExp['id'] ?? 0), 'clients_exported', []);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="clients_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM — Excel открывает кириллицу без кракозябр
    fputcsv($out, ['Имя', 'E-mail', 'Телефон', 'Компания', 'Зарегистрирован', 'Последний вход'], ';');
    try {
        $st = $pdo->query('SELECT name, email, phone, company, created_at, last_login FROM users ORDER BY created_at DESC');
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($out, [csv_safe($r['name']), csv_safe($r['email']), csv_safe($r['phone']), csv_safe($r['company']), $r['created_at'], $r['last_login'] ?: '—'], ';');
        }
    } catch (Throwable $e) { /* отдаём то, что успели */ }
    fclose($out);
    exit;
}

/** Регистрация свежее недели — помечаем, чтобы менеджер видел, кому звонить в первую очередь. */
$isFresh = static function (?string $ts): bool {
    if (!$ts) return false;
    $t = strtotime($ts);
    return $t !== false && $t >= strtotime('-7 days');
};

require __DIR__ . '/_layout.php';
render_head('Клиенты кабинета');   // без этих двух вызовов страница шла без стилей, скриптов и меню
render_sidebar('clients');
?>
<div class="page-head">
  <h1 class="page-title">Клиенты кабинета</h1>
  <div class="page-head__actions">
    <?php if (!$tableMissing && ((current_user() ?? [])['role'] ?? '') === 'admin'): ?>
      <a class="btn btn--primary" href="clients.php?export=csv">
        <svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M12 3v12"/><path d="m7 11 5 5 5-5"/><path d="M5 21h14"/>
        </svg>
        Скачать CSV
      </a>
    <?php endif; ?>
  </div>
</div>

<p class="page-lead">
  Все, кто зарегистрировался в личном кабинете сайта, — с контактами для рассылки или обзвона.
  Выгрузка CSV открывается в Excel без настройки кодировки.
</p>

<?php if ($tableMissing): ?>
  <div class="card">
    <div class="empty">
      <svg class="ic ic--lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"
           stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <circle cx="9" cy="8" r="3.5"/><path d="M3.5 20a5.5 5.5 0 0 1 11 0"/>
        <path d="M16 4.5a3.5 3.5 0 0 1 0 7M17.5 14.5a5.5 5.5 0 0 1 3 5.5"/>
      </svg>
      <b>Регистраций ещё не было</b>
      <span class="muted">Таблица <code>users</code> создастся автоматически после первой
      регистрации в кабинете на сайте.</span>
    </div>
  </div>
<?php else: ?>

  <div class="kpi-grid">
    <div class="kpi">
      <div class="kpi__label">Всего клиентов</div>
      <div class="kpi__value"><?= (int)$counts['total'] ?></div>
    </div>
    <div class="kpi kpi--accent">
      <div class="kpi__label">Новых за 7 дней</div>
      <div class="kpi__value">+<?= (int)$counts['week'] ?></div>
    </div>
    <div class="kpi">
      <div class="kpi__label">Новых за 30 дней</div>
      <div class="kpi__value">+<?= (int)$counts['month'] ?></div>
    </div>
    <div class="kpi">
      <div class="kpi__label">Заходили за 30 дней</div>
      <div class="kpi__value"><?= (int)$counts['active'] ?></div>
    </div>
  </div>

  <form class="filters clients-filters" method="get">
    <label class="field">
      <span class="field__label">Поиск по базе</span>
      <input class="input" type="search" name="q" value="<?= $h($q) ?>"
             placeholder="Имя, почта, телефон или компания…" autocomplete="off">
    </label>
    <?php /* Кнопки — одним блоком: на узком экране общая система растягивает каждую
             кнопку фильтра на всю строку, и до первой записи нужно было проматывать
             пол-экрана. В группе они делят одну строку при любой ширине. */ ?>
    <div class="clients-actions">
      <button class="btn btn--primary" type="submit">Найти</button>
      <?php if ($q !== ''): ?>
        <a class="btn btn--ghost" href="clients.php">Сбросить</a>
      <?php endif; ?>
    </div>
    <?php if ($rows): ?>
      <span class="field__label clients-count">
        Показано: <b><?= count($rows) ?></b><?= count($rows) === 500 ? ' из 500 максимум' : '' ?>
      </span>
    <?php endif; ?>
  </form>

  <div class="table-wrap">
    <div class="table-scroll">
      <table class="tbl">
        <thead>
          <tr>
            <th>Зарегистрирован</th>
            <th>Имя</th>
            <th>E-mail</th>
            <th>Телефон</th>
            <th>Компания</th>
            <th>Последний вход</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr>
            <td colspan="6">
              <div class="empty">
                <svg class="ic ic--lg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>
                </svg>
                <b><?= $q !== '' ? 'По запросу никого не нашли' : 'Регистраций пока нет' ?></b>
                <?php if ($q !== ''): ?>
                  <span class="muted">Попробуйте часть номера или домен почты —
                  поиск идёт по имени, почте, телефону и компании.</span>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="nowrap">
              <?= $h(date('d.m.Y', strtotime((string)$r['created_at']))) ?>
              <span class="muted"><?= $h(date('H:i', strtotime((string)$r['created_at']))) ?></span>
              <?php if ($isFresh((string)$r['created_at'])): ?>
                <span class="badge badge--new">новый</span>
              <?php endif; ?>
            </td>
            <td><b><?= $h($r['name']) ?></b></td>
            <td><a href="mailto:<?= $h($r['email']) ?>"><?= $h($r['email']) ?></a></td>
            <td class="nowrap">
              <?php if ($r['phone']): ?>
                <a href="tel:<?= $h(preg_replace('/[^+\d]/', '', (string)$r['phone'])) ?>"><?= $h($r['phone']) ?></a>
              <?php else: ?><span class="muted">—</span><?php endif; ?>
            </td>
            <td><?= $r['company'] ? $h($r['company']) : '<span class="muted">—</span>' ?></td>
            <td class="nowrap">
              <?php if ($r['last_login']): ?>
                <span class="muted"><?= $h(date('d.m.Y H:i', strtotime((string)$r['last_login']))) ?></span>
              <?php else: ?>
                <span class="badge badge--muted">не заходил</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if (count($rows) === 500): ?>
    <p class="hint">
      Показаны первые 500 записей — полный список выгружается в CSV.
    </p>
  <?php endif; ?>
<?php endif; ?>

<?php render_foot();
