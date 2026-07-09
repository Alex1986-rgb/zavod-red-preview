<?php
/* Конфиг бэкенда кабинета ЗР.
   ВАЖНО: реальные пароли/ключи НЕ здесь. Скопируйте config.local.sample.php в
   config.local.php на сервере и впишите доступы там. config.local.php в .gitignore
   и НЕ попадает в репозиторий/деплой. Пока config.local.php нет — бэкенд аккаунтов
   выключен (кабинет работает в локальном режиме, регистрация создаёт только лид). */
declare(strict_types=1);

// значения по умолчанию (выключено). Переопределяются в config.local.php.
$CFG = [
  'DB_ENABLED' => false,
  'DB_DSN'     => 'mysql:host=localhost;dbname=zr;charset=utf8mb4',
  'DB_USER'    => '',
  'DB_PASS'    => '',
  'CRM_ENABLED'=> false,
  'CRM_URL'    => '',        // напр. https://crm.example.ru/api/register
  'CRM_TOKEN'  => '',
  'ALLOW_ORIGIN' => 'https://zavod-red.ru',
];

$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
  $override = require $local;   // должен вернуть массив
  if (is_array($override)) $CFG = array_merge($CFG, $override);
}

function cfg(string $k) { global $CFG; return $CFG[$k] ?? null; }

// общие заголовки: только свой origin, JSON, без утечки версий
function api_boot(): void {
  header('Content-Type: application/json; charset=utf-8');
  header('X-Content-Type-Options: nosniff');
  $origin = cfg('ALLOW_ORIGIN');
  if ($origin) header('Access-Control-Allow-Origin: ' . $origin);
  header('Access-Control-Allow-Credentials: true');
  header('Vary: Origin');
  if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
}

function json_out(array $data, int $code = 200): void {
  http_response_code($code);
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

function json_in(): array {
  $raw = file_get_contents('php://input') ?: '';
  $d = json_decode($raw, true);
  return is_array($d) ? $d : [];
}
