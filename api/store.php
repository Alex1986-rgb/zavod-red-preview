<?php
/* Серверное хранилище данных кабинета (избранное, заказы) — по одному JSON на (пользователь, ключ).
   Позволяет видеть избранное/заказы с любого устройства. Требует активную сессию.
   GET  ?k=favorites|orders            → {status:"success", data:[...]}
   POST {k:"favorites", v:[...]}        → сохранить (upsert), {status:"success"}
   Таблица cab_store создаётся автоматически. */
declare(strict_types=1);
require_once __DIR__ . '/db.php';
api_boot();

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => true]);
session_start();
$uid = $_SESSION['uid'] ?? null;
if (!$uid) json_out(['status' => 'guest', 'data' => []], 200);

$pdo = db();
if (!$pdo) json_out(['status' => 'error', 'message' => 'Бэкенд не настроен.'], 501);

$ALLOWED = ['favorites', 'orders', 'requests', 'addresses'];

try {
  $pdo->exec(
    "CREATE TABLE IF NOT EXISTS cab_store (
      uid INT UNSIGNED NOT NULL,
      k VARCHAR(32) NOT NULL,
      v MEDIUMTEXT NOT NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (uid, k)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
  );

  $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
  if ($method === 'GET') {
    $k = (string)($_GET['k'] ?? '');
    if (!in_array($k, $ALLOWED, true)) json_out(['status' => 'error', 'message' => 'Неизвестный ключ.'], 422);
    $st = $pdo->prepare('SELECT v FROM cab_store WHERE uid = ? AND k = ?');
    $st->execute([(int)$uid, $k]);
    $row = $st->fetch();
    $data = $row ? json_decode($row['v'], true) : [];
    json_out(['status' => 'success', 'data' => is_array($data) ? $data : []]);
  }

  if ($method === 'POST') {
    $in = json_in();
    $k = (string)($in['k'] ?? '');
    $v = $in['v'] ?? null;
    if (!in_array($k, $ALLOWED, true)) json_out(['status' => 'error', 'message' => 'Неизвестный ключ.'], 422);
    if (!is_array($v)) json_out(['status' => 'error', 'message' => 'Некорректные данные.'], 422);
    if (count($v) > 500) $v = array_slice($v, 0, 500); // защита от разрастания
    $json = json_encode($v, JSON_UNESCAPED_UNICODE);
    if (strlen($json) > 600000) json_out(['status' => 'error', 'message' => 'Слишком много данных.'], 413);
    $up = $pdo->prepare('INSERT INTO cab_store (uid, k, v) VALUES (?,?,?) ON DUPLICATE KEY UPDATE v = VALUES(v)');
    $up->execute([(int)$uid, $k, $json]);
    json_out(['status' => 'success']);
  }

  json_out(['status' => 'error', 'message' => 'Метод не поддерживается'], 405);
} catch (Throwable $e) {
  error_log('[zr-store] ' . $e->getMessage());
  json_out(['status' => 'error', 'message' => 'Временная ошибка.'], 500);
}
