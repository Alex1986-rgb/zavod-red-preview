<?php
/* Вход в кабинет. POST JSON {email,password}. Проверяет bcrypt-хеш, открывает сессию.
   Ответы: {status:"success",user:{...}} | {status:"error",message:"..."}. */
declare(strict_types=1);
require_once __DIR__ . '/db.php';
api_boot();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['status' => 'error', 'message' => 'Метод не поддерживается'], 405);

$in    = json_in();
$email = strtolower(trim((string)($in['email'] ?? '')));
$pass  = (string)($in['password'] ?? '');
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $pass === '') json_out(['status' => 'error', 'message' => 'Проверьте e-mail и пароль.'], 422);

$pdo = db();
if (!$pdo) json_out(['status' => 'error', 'message' => 'Бэкенд аккаунтов не настроен.'], 501);

try {
  $st = $pdo->prepare('SELECT id, pass_hash, name, company, phone FROM users WHERE email = ?');
  $st->execute([$email]);
  $u = $st->fetch();
  // одинаковый ответ на «нет пользователя» и «неверный пароль» — не раскрываем наличие email
  if (!$u || !password_verify($pass, $u['pass_hash'])) json_out(['status' => 'error', 'message' => 'Неверный e-mail или пароль.'], 401);

  session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => true]);
  session_start();
  $_SESSION['uid'] = (int)$u['id'];
  $_SESSION['email'] = $email;
  $pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([(int)$u['id']]);

  json_out(['status' => 'success', 'user' => ['name' => $u['name'], 'company' => $u['company'], 'phone' => $u['phone']]]);
} catch (Throwable $e) {
  error_log('[zr-login] ' . $e->getMessage());
  json_out(['status' => 'error', 'message' => 'Временная ошибка. Попробуйте позже.'], 500);
}
