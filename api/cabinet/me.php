<?php
/* Текущий пользователь по серверной сессии (для входа с любого устройства).
   GET. Ответы: {status:"success",user:{...}} | {status:"guest"}. */
declare(strict_types=1);
require_once __DIR__ . '/db.php';
api_boot();
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => true]);
session_start();

$uid = $_SESSION['uid'] ?? null;
$pdo = db();
if (!$uid || !$pdo) json_out(['status' => 'guest']);
try {
  $st = $pdo->prepare('SELECT email, name, company, phone FROM users WHERE id = ?');
  $st->execute([(int)$uid]);
  $u = $st->fetch();
  if (!$u) json_out(['status' => 'guest']);
  json_out(['status' => 'success', 'user' => $u]);
} catch (Throwable $e) {
  error_log('[zr-me] ' . $e->getMessage());
  json_out(['status' => 'guest']);
}
