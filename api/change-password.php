<?php
/* Смена пароля аккаунта кабинета. POST JSON {old,new}. Требует активную сессию.
   Ответы: {status:"success"} | {status:"error",message:"..."}. */
declare(strict_types=1);
require_once __DIR__ . '/db.php';
api_boot();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['status' => 'error', 'message' => 'Метод не поддерживается'], 405);

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => true]);
session_start();
$uid = $_SESSION['uid'] ?? null;
if (!$uid) json_out(['status' => 'error', 'message' => 'Требуется вход.'], 401);

$in  = json_in();
$old = (string)($in['old'] ?? '');
$new = (string)($in['new'] ?? '');
if (mb_strlen($new) < 6 || mb_strlen($new) > 200) json_out(['status' => 'error', 'message' => 'Новый пароль минимум 6 символов.'], 422);

$pdo = db();
if (!$pdo) json_out(['status' => 'error', 'message' => 'Бэкенд аккаунтов не настроен.'], 501);

try {
  $st = $pdo->prepare('SELECT pass_hash FROM users WHERE id = ?');
  $st->execute([(int)$uid]);
  $u = $st->fetch();
  if (!$u || !password_verify($old, $u['pass_hash'])) json_out(['status' => 'error', 'message' => 'Текущий пароль неверен.'], 401);

  $hash = password_hash($new, PASSWORD_DEFAULT);
  $pdo->prepare('UPDATE users SET pass_hash = ? WHERE id = ?')->execute([$hash, (int)$uid]);
  json_out(['status' => 'success', 'message' => 'Пароль изменён.']);
} catch (Throwable $e) {
  error_log('[zr-chpass] ' . $e->getMessage());
  json_out(['status' => 'error', 'message' => 'Временная ошибка. Попробуйте позже.'], 500);
}
