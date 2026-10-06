<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
start_session();

$action = $_REQUEST['action'] ?? 'me';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* --- LOGIN --- */
if ($action === 'login' && $method === 'POST') {
    $login = trim((string)($_POST['login'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($login === '' || $password === '') {
        json_out(['ok' => false, 'error' => 'Укажите логин и пароль'], 400);
    }

    // Брутфорс-лок: 5 попыток / 15 минут на пару IP+логин.
    if (!rate_limit('login:' . client_ip() . ':' . $login, 5, 900)
        || !rate_limit('login-user:' . mb_strtolower($login), 20, 900)) { // перебор одного логина с разных IP
        json_out(['ok' => false, 'error' => 'Слишком много попыток, повторите позже'], 429);
    }

    // Без config.local.php конфиг откатывается к DB_ENABLED=false, pdo() возвращает null,
    // и обращение к ->prepare() валило запрос в пустой HTTP 500: форма входа показывала
    // «Ошибка сервера» и не давала понять, что дело в отсутствующих доступах к базе.
    if (!pdo()) {
        json_out(array("ok" => false, "error" => "База данных недоступна: на сервере нет api/config.local.php с доступами. Вход в панель невозможен, пока файл не восстановлен."), 503);
    }

    $st = pdo()->prepare(
        'SELECT id, login, pass_hash, name, role FROM crm_users WHERE login = ? AND active = 1 LIMIT 1'
    );
    $st->execute([$login]);
    $row = $st->fetch();

    if (!$row || !password_verify($password, (string)$row['pass_hash'])) {
        json_out(['ok' => false, 'error' => 'Неверный логин или пароль'], 401);
    }

    $user = [
        'id'    => (int)$row['id'],
        'login' => $row['login'],
        'name'  => $row['name'],
        'role'  => $row['role'],
    ];

    session_regenerate_id(true);
    $_SESSION['user'] = $user;
    $_SESSION['auth_ts'] = time();          // для абсолютного срока жизни сессии
    $_SESSION['last_activity'] = time();     // для idle-таймаута

    $up = pdo()->prepare('UPDATE crm_users SET last_login = NOW() WHERE id = ?');
    $up->execute([$user['id']]);

    audit(null, $user['id'], 'login');

    json_out(['ok' => true, 'user' => $user]);
}

/* --- LOGOUT --- */
if ($action === 'logout') {
    // Только POST: GET-ссылка с чужой страницы разлогинивала менеджера.
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['ok' => false, 'error' => 'Выход только POST'], 405);
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    json_out(['ok' => true]);
}

/* --- ME (текущий пользователь + csrf-токен для фронта) --- */
json_out([
    'ok'   => true,
    'user' => $_SESSION['user'] ?? null,
    'csrf' => csrf_token(),
]);
