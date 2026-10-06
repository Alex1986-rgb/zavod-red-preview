<?php
declare(strict_types=1);
/**
 * Крон: результаты робота и инженера → поле «Инженер» сделок Битрикс24 (см. api/bitrix.php).
 *   php api/bitrix_sync.php            — выгрузить изменившиеся заявки
 *   php api/bitrix_sync.php --dry=585  — показать, что попадёт в сделку по заявке 585, не записывая
 * Вызывается и в конце api/mail_sync.php и api/ai_auto.php — отдельная строка крона не обязательна.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/bitrix.php';
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--dry=(\d+)$/', $a, $m)) {
        echo json_encode(b24_push_lead((int)$m[1], true), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
        exit(0);
    }
}
echo json_encode(b24_sync_run(), JSON_UNESCAPED_UNICODE), "\n";
