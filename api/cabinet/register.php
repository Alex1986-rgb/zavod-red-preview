<?php
/* Регистрация аккаунта кабинета. POST JSON {name,email,phone,company,password}.
   Создаёт запись в users (пароль хешируется bcrypt), при настроенном CRM — пробрасывает.
   Лид в отдел продаж создаёт фронтенд отдельным запросом в feedback.php (не дублируем).
   Ответы: {status:"success"} | {status:"exists"} | {status:"error",message:"..."}. */
declare(strict_types=1);
require_once __DIR__ . '/db.php';
api_boot();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['status' => 'error', 'message' => 'Метод не поддерживается'], 405);

$in    = json_in();
$name  = trim((string)($in['name'] ?? ''));
$email = strtolower(trim((string)($in['email'] ?? '')));
$phone = trim((string)($in['phone'] ?? ''));
$company = trim((string)($in['company'] ?? ''));
$pass  = (string)($in['password'] ?? '');

// валидация
if (mb_strlen($name) < 2 || mb_strlen($name) > 120)          json_out(['status' => 'error', 'message' => 'Проверьте имя.'], 422);
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) json_out(['status' => 'error', 'message' => 'Проверьте e-mail.'], 422);
if (preg_replace('/\D/', '', $phone) === '' || mb_strlen($phone) > 32)     json_out(['status' => 'error', 'message' => 'Проверьте телефон.'], 422);
if (mb_strlen($pass) < 6 || mb_strlen($pass) > 200)          json_out(['status' => 'error', 'message' => 'Пароль минимум 6 символов.'], 422);

$pdo = db();
if (!$pdo) json_out(['status' => 'error', 'message' => 'Бэкенд аккаунтов не настроен.'], 501);

try {
  $st = $pdo->prepare('SELECT id FROM users WHERE email = ?');
  $st->execute([$email]);
  if ($st->fetch()) json_out(['status' => 'exists', 'message' => 'E-mail уже зарегистрирован.'], 409);

  $hash = password_hash($pass, PASSWORD_DEFAULT);
  $ins = $pdo->prepare('INSERT INTO users (email, pass_hash, name, company, phone) VALUES (?,?,?,?,?)');
  $ins->execute([$email, $hash, $name, $company, $phone]);

  // сессия
  session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => true]);
  session_start();
  $_SESSION['uid'] = (int)$pdo->lastInsertId();
  $_SESSION['email'] = $email;

  // CRM (best-effort, не блокирует ответ клиенту)
  if (cfg('CRM_ENABLED') && cfg('CRM_URL')) {
    @forward_to_crm(['name' => $name, 'email' => $email, 'phone' => $phone, 'company' => $company]);
  }

  json_out(['status' => 'success', 'user' => ['name' => $name, 'company' => $company, 'phone' => $phone]]);
} catch (Throwable $e) {
  error_log('[zr-register] ' . $e->getMessage());
  json_out(['status' => 'error', 'message' => 'Временная ошибка. Попробуйте позже.'], 500);
}

function forward_to_crm(array $client): void {
  $ch = curl_init(cfg('CRM_URL'));
  if ($ch === false) return;
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($client, JSON_UNESCAPED_UNICODE),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . cfg('CRM_TOKEN')],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 4,
  ]);
  curl_exec($ch);
  curl_close($ch);
}
