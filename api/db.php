<?php
/* Подключение к БД (PDO) + автосоздание таблицы users. Только если DB_ENABLED. */
declare(strict_types=1);
require_once __DIR__ . '/config.php';

function db(): ?PDO {
  static $pdo = null;
  if ($pdo instanceof PDO) return $pdo;
  if (!cfg('DB_ENABLED')) return null;
  try {
    $pdo = new PDO(cfg('DB_DSN'), cfg('DB_USER'), cfg('DB_PASS'), [
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec(
      "CREATE TABLE IF NOT EXISTS users (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(190) NOT NULL UNIQUE,
        pass_hash VARCHAR(255) NOT NULL,
        name VARCHAR(120) NOT NULL,
        company VARCHAR(190) DEFAULT '',
        phone VARCHAR(32) DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_login DATETIME NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    return $pdo;
  } catch (Throwable $e) {
    // не раскрываем детали БД наружу; лог — на сервере
    error_log('[zr-db] ' . $e->getMessage());
    return null;
  }
}
