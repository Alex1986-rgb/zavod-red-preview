<?php
declare(strict_types=1);

/**
 * /api/calls_cron.php — крон диспетчера обзвона (только CLI, как ai_auto.php).
 *
 *   каждые 10 минут в 10–19 по будням: php APP/api/calls_cron.php >> APP/crm-data/logs/calls.log
 *
 * Сам по себе никому не звонит: берёт ТОЛЬКО кампании в статусе active, уважает их
 * рабочие часы и суточный лимит, а при включённом сухом прогоне лишь помечает цели.
 * Живые звонки уходят в zavod-red-voice, когда администратор выключил сухой прогон
 * на странице «Обзвон». Без этого файла calls_dispatch() не запускался бы вовсе.
 */

require_once __DIR__ . '/calls.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

// Режим «робот забирает сам» (api/voice_queue.php): CRM никуда не толкает номера.
if (setting('calls_mode', 'push') === 'pull') { echo '[' . date('Y-m-d H:i') . "] обзвон: робот забирает очередь сам (calls_mode=pull)\n"; exit; }

$limit = max(1, (int)(setting('calls_per_run') ?: 5)); // номеров на кампанию за один прогон
$log = calls_dispatch($limit);

$stamp = date('Y-m-d H:i');
if (!$log) {
    echo "[$stamp] обзвон: активных кампаний нет или нечего ставить\n";
    exit;
}
foreach ($log as $row) {
    echo "[$stamp] " . json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
}
