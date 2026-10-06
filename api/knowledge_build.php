<?php
declare(strict_types=1);
/**
 * Крон: пополнить единую базу знаний (api/knowledge.php) — сайт, пары «вопрос → ответ», переписка, документы.
 *   php api/knowledge_build.php [--budget=60]
 * Запускается из cron_all.php каждые 5 минут.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/knowledge.php';
$budget = 60;
foreach (array_slice($argv, 1) as $a) if (preg_match('/^--budget=(\d+)$/', $a, $m)) $budget = (int)$m[1];
cron_heartbeat('knowledge');
echo json_encode(['added' => kn_build($budget), 'total' => kn_stats()], JSON_UNESCAPED_UNICODE), "\n";
