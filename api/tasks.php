<?php
declare(strict_types=1);

/**
 * /api/tasks.php — задачи менеджеров + контроль SLA (требует авторизации).
 *   GET  ?action=list&scope=today|overdue|open|all  список задач + counts
 *   POST ?action=create (csrf) lead_id(опц), title, due_at(опц) → новая задача
 *   POST ?action=done   (csrf) id → отметить выполненной
 *   POST ?action=del    (csrf) id → удалить
 *   GET  ?action=sla    лиды status='new' старше 15 мин без ответного события
 */

require_once __DIR__ . '/helpers.php';

$user = require_auth();
$uid  = (int)($user['id'] ?? 0);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? ($_POST['action'] ?? 'list');

try {
    if ($method === 'GET') {
        switch ($action) {
            case 'sla':   tasks_sla();   break;
            case 'board': tasks_board(); break;
            case 'get':   tasks_get();   break;
            case 'list': default: tasks_list(); break;
        }
    } elseif ($method === 'POST') {
        csrf_check();
        switch ($action) {
            case 'create': tasks_create($uid); break;
            case 'move':   tasks_move($uid);   break;
            case 'done':   tasks_done($uid);   break;
            case 'del':    tasks_del($uid);    break;
            default: json_out(['ok' => false, 'error' => 'Неизвестное действие'], 400);
        }
    } else {
        json_out(['ok' => false, 'error' => 'Метод не поддерживается'], 405);
    }
} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => 'Внутренняя ошибка'], 500);
}

/** GET ?action=list&scope= */
function tasks_list(): never {
    $scope = trim((string)($_GET['scope'] ?? 'open'));
    if (!in_array($scope, ['today', 'overdue', 'open', 'all'], true)) $scope = 'open';

    $pdo = pdo();

    // Счётчики по корзинам (только незавершённые, кроме done)
    $stC = $pdo->query(
        "SELECT
            SUM(CASE WHEN done = 0 THEN 1 ELSE 0 END) AS open_cnt,
            SUM(CASE WHEN done = 0 AND due_at IS NOT NULL AND due_at <= NOW() THEN 1 ELSE 0 END) AS overdue_cnt,
            SUM(CASE WHEN done = 0 AND due_at IS NOT NULL AND DATE(due_at) = CURDATE() THEN 1 ELSE 0 END) AS today_cnt,
            COUNT(*) AS all_cnt
         FROM crm_tasks"
    );
    $c = $stC->fetch() ?: [];
    $counts = [
        'open'    => (int)($c['open_cnt'] ?? 0),
        'overdue' => (int)($c['overdue_cnt'] ?? 0),
        'today'   => (int)($c['today_cnt'] ?? 0),
        'all'     => (int)($c['all_cnt'] ?? 0),
    ];

    $where = [];
    if ($scope === 'open') {
        $where[] = 't.done = 0';
    } elseif ($scope === 'overdue') {
        $where[] = 't.done = 0';
        $where[] = 't.due_at IS NOT NULL';
        $where[] = 't.due_at <= NOW()';
    } elseif ($scope === 'today') {
        $where[] = 't.done = 0';
        $where[] = 't.due_at IS NOT NULL';
        $where[] = 'DATE(t.due_at) = CURDATE()';
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $sql = "SELECT t.*, l.name AS lead_name
            FROM crm_tasks t
            LEFT JOIN crm_leads l ON l.id = t.lead_id
            $whereSql
            ORDER BY (t.due_at IS NULL), t.due_at ASC, t.id DESC
            LIMIT 500";
    $items = $pdo->query($sql)->fetchAll();

    json_out([
        'ok'     => true,
        'scope'  => $scope,
        'items'  => $items,
        'counts' => $counts,
    ]);
}

/** Допустимые статусы канбана. */
function task_statuses(): array { return ['new' => 'Новые', 'in_progress' => 'В работе', 'review' => 'На проверке', 'done' => 'Готово']; }

/** GET ?action=board — задачи по колонкам канбана + стат-карточки. */
function tasks_board(): never {
    $pdo = pdo();
    $st = $pdo->query("SELECT t.*, l.name AS lead_name, u.name AS user_name
        FROM crm_tasks t LEFT JOIN crm_leads l ON l.id=t.lead_id LEFT JOIN crm_users u ON u.id=t.user_id
        ORDER BY (t.due_at IS NULL), t.due_at ASC, t.id DESC LIMIT 500");
    $all = $st->fetchAll();
    $cols = ['new' => [], 'in_progress' => [], 'review' => [], 'done' => []];
    foreach ($all as $t) {
        $s = (string)($t['status'] ?? 'new');
        if (!isset($cols[$s])) $s = ((int)$t['done'] === 1) ? 'done' : 'new';
        $cols[$s][] = $t;
    }
    $c = $pdo->query("SELECT
        SUM(CASE WHEN status<>'done' AND due_at IS NOT NULL AND due_at < NOW() THEN 1 ELSE 0 END) overdue,
        SUM(CASE WHEN status<>'done' AND due_at IS NOT NULL AND DATE(due_at)=CURDATE() THEN 1 ELSE 0 END) today,
        SUM(CASE WHEN status<>'done' AND due_at IS NOT NULL AND YEARWEEK(due_at,1)=YEARWEEK(CURDATE(),1) THEN 1 ELSE 0 END) week,
        SUM(CASE WHEN status='done' THEN 1 ELSE 0 END) done
        FROM crm_tasks")->fetch() ?: [];
    json_out(['ok' => true, 'columns' => $cols, 'labels' => task_statuses(),
        'stats' => ['overdue'=>(int)($c['overdue']??0),'today'=>(int)($c['today']??0),'week'=>(int)($c['week']??0),'done'=>(int)($c['done']??0)]]);
}

/** GET ?action=get&id= — карточка задачи. */
function tasks_get(): never {
    $id = (int)($_GET['id'] ?? 0);
    $st = pdo()->prepare("SELECT t.*, l.name AS lead_name, u.name AS user_name
        FROM crm_tasks t LEFT JOIN crm_leads l ON l.id=t.lead_id LEFT JOIN crm_users u ON u.id=t.user_id WHERE t.id=?");
    $st->execute([$id]);
    $t = $st->fetch();
    if (!$t) json_out(['ok' => false, 'error' => 'Задача не найдена'], 404);
    json_out(['ok' => true, 'task' => $t]);
}

/** POST ?action=move — сменить статус задачи в канбане. */
function tasks_move(int $uid): never {
    $id = (int)($_POST['id'] ?? 0);
    if ($id < 1) json_out(['ok' => false, 'error' => 'Не указан id'], 400);
    $status = (string)($_POST['status'] ?? '');
    if (!isset(task_statuses()[$status])) json_out(['ok' => false, 'error' => 'Неверный статус'], 400);

    // Без проверки существования UPDATE по удалённой задаче возвращал ok:true,
    // и доска показывала «Статус: …» на карточку, которой уже нет.
    $pdo = pdo();
    $ex = $pdo->prepare('SELECT id FROM crm_tasks WHERE id = ?');
    $ex->execute([$id]);
    if (!$ex->fetchColumn()) json_out(['ok' => false, 'error' => 'Задача не найдена'], 404);

    $done = $status === 'done' ? 1 : 0;
    $pdo->prepare("UPDATE crm_tasks SET status=?, done=? WHERE id=?")->execute([$status, $done, $id]);
    audit(null, $uid, 'task_moved', ['id' => $id, 'to' => $status]);
    json_out(['ok' => true, 'status' => $status]);
}

/** POST ?action=create */
function tasks_create(int $uid): never {
    $title = trim((string)($_POST['title'] ?? ''));
    if ($title === '') json_out(['ok' => false, 'error' => 'Не указан заголовок'], 400);
    $title = mb_substr($title, 0, 255);

    $leadRaw = (string)($_POST['lead_id'] ?? '');
    $leadId  = ($leadRaw === '' || $leadRaw === '0') ? null : (int)$leadRaw;

    $dueRaw = trim((string)($_POST['due_at'] ?? ''));
    // input[type=date] даёт голую дату — считаем сроком конец дня, иначе задача
    // «на сегодня» сразу же попадает в просроченные (due_at = сегодня 00:00:00).
    if ($dueRaw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueRaw)) $dueRaw .= ' 23:59:59';
    $dueAt  = ($dueRaw === '') ? null : $dueRaw;

    $pdo = pdo();

    if ($leadId !== null) {
        $ex = $pdo->prepare('SELECT id FROM crm_leads WHERE id = ?');
        $ex->execute([$leadId]);
        if (!$ex->fetchColumn()) json_out(['ok' => false, 'error' => 'Лид не найден'], 404);
    }

    $status   = (string)($_POST['status'] ?? 'new');
    if (!isset(task_statuses()[$status])) $status = 'new';
    $priority = (string)($_POST['priority'] ?? 'normal');
    if (!in_array($priority, ['low', 'normal', 'high'], true)) $priority = 'normal';
    $descr = mb_substr(trim((string)($_POST['descr'] ?? '')), 0, 2000);

    // Ответственный: выбранный в форме user_id (если валиден и активен), иначе — текущий пользователь.
    $assignee = $uid ?: null;
    $userRaw = (string)($_POST['user_id'] ?? '');
    if ($userRaw !== '' && ctype_digit($userRaw)) {
        $chk = $pdo->prepare('SELECT id FROM crm_users WHERE id = ? AND active = 1');
        $chk->execute([(int)$userRaw]);
        if ($chk->fetchColumn()) $assignee = (int)$userRaw;
    }

    $st = $pdo->prepare(
        'INSERT INTO crm_tasks (lead_id, user_id, title, due_at, done, status, priority, descr, created_at) VALUES (?,?,?,?,?,?,?,?,NOW())'
    );
    $st->execute([$leadId, $assignee, $title, $dueAt, $status === 'done' ? 1 : 0, $status, $priority, $descr]);
    $taskId = (int)$pdo->lastInsertId();

    audit($leadId, $uid, 'task_created', ['task_id' => $taskId, 'title' => $title]);

    $stT = $pdo->prepare(
        "SELECT t.*, l.name AS lead_name FROM crm_tasks t
         LEFT JOIN crm_leads l ON l.id = t.lead_id WHERE t.id = ?"
    );
    $stT->execute([$taskId]);

    json_out(['ok' => true, 'task' => $stT->fetch()]);
}

/** POST ?action=done */
function tasks_done(int $uid): never {
    $id = (int)($_POST['id'] ?? 0);
    if ($id < 1) json_out(['ok' => false, 'error' => 'Не указан id'], 400);

    $pdo = pdo();
    $st = $pdo->prepare('SELECT id, lead_id FROM crm_tasks WHERE id = ?');
    $st->execute([$id]);
    $task = $st->fetch();
    if (!$task) json_out(['ok' => false, 'error' => 'Задача не найдена'], 404);

    // status тоже переводим в 'done': иначе задача, закрытая этим действием, остаётся
    // висеть в колонке «Новые» на канбане (доска группирует по status, а не по done).
    $pdo->prepare("UPDATE crm_tasks SET done = 1, status = 'done' WHERE id = ?")->execute([$id]);
    audit($task['lead_id'] !== null ? (int)$task['lead_id'] : null, $uid, 'task_done', ['task_id' => $id]);

    json_out(['ok' => true]);
}

/** POST ?action=del */
function tasks_del(int $uid): never {
    $id = (int)($_POST['id'] ?? 0);
    if ($id < 1) json_out(['ok' => false, 'error' => 'Не указан id'], 400);

    $pdo = pdo();
    $st = $pdo->prepare('SELECT id, lead_id FROM crm_tasks WHERE id = ?');
    $st->execute([$id]);
    $task = $st->fetch();
    if (!$task) json_out(['ok' => false, 'error' => 'Задача не найдена'], 404);

    $pdo->prepare('DELETE FROM crm_tasks WHERE id = ?')->execute([$id]);
    audit($task['lead_id'] !== null ? (int)$task['lead_id'] : null, $uid, 'task_deleted', ['task_id' => $id]);

    json_out(['ok' => true]);
}

/** GET ?action=sla — лиды new старше 15 мин без ответной активности. */
function tasks_sla(): never {
    $pdo = pdo();
    $sql =
        "SELECT l.id, l.name, l.phone, l.email, l.created_at,
                TIMESTAMPDIFF(MINUTE, l.created_at, NOW()) AS waiting_min
         FROM crm_leads l
         WHERE l.status = 'new'
           AND l.created_at <= (NOW() - INTERVAL 15 MINUTE)
           AND NOT EXISTS (
               SELECT 1 FROM crm_events e
               WHERE e.lead_id = l.id
                 AND e.type IN ('msg_out','status_changed','email_sent','note_added')
           )
         ORDER BY l.created_at ASC
         LIMIT 200";
    $items = $pdo->query($sql)->fetchAll();

    json_out([
        'ok'    => true,
        'title' => 'Просрочка ответа (SLA 15 мин)',
        'items' => $items,
        'count' => count($items),
    ]);
}
