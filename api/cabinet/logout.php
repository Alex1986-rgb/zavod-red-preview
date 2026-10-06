<?php
/* Выход: уничтожает сессию. POST. */
declare(strict_types=1);
require_once __DIR__ . '/config.php';
api_boot();
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => true]);
session_start();
$_SESSION = [];
if (ini_get('session.use_cookies')) {
  $p = session_get_cookie_params();
  setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();
json_out(['status' => 'success']);
