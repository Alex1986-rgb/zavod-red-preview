<?php
declare(strict_types=1);

/**
 * POST /api/voice_queue.php — робот сам забирает номера для обзвона (режим pull).
 * Тело: {"limit":3} + секрет (см. voice_hook.php). Ответ: {"ok":true,"targets":[{target_id, phone,
 * name, company, reason, script, campaign}], "log":[…]}.
 *
 * Зачем «на себя»: робот живёт на зарубежном VPS (Gemini Live из РФ недоступен). Когда CRM сама
 * толкает номера на POST /calls робота, у робота должен быть открытый HTTPS-вход из интернета.
 * Когда робот спрашивает сам — вход не нужен, а все правила (часы кампании, суточный лимит,
 * попытки, стоп-лист, сухой прогон) по-прежнему проверяет CRM в calls_dispatch().
 * Выданная цель встаёт в «calling»; итог звонка робот шлёт в api/voice_call_result.php (target_id).
 * Включение: настройка calls_mode=pull (тогда calls_cron.php сам ничего не толкает).
 */

require_once __DIR__ . '/voice_hook.php';
require_once __DIR__ . '/calls.php';

$in = voice_hook_read();
try {
    calls_ensure();
    if (setting('calls_mode', 'push') !== 'pull') {
        echo json_encode(['ok' => true, 'targets' => [], 'note' => 'CRM в режиме push (calls_mode) — очередь не выдаётся']);
        exit;
    }
    $limit = max(1, min(10, (int)($in['limit'] ?? 3)));
    $out = [];
    $log = calls_dispatch($limit, static function (array $t, array $c) use (&$out, $limit): ?bool {
        if (count($out) >= $limit) return null;
        $out[] = [
            'target_id' => (int)$t['id'],
            'phone'     => '+' . $t['phone_norm'],
            'name'      => (string)($t['person'] ?: $t['company']),
            'company'   => (string)$t['company'],
            'reason'    => (string)($t['reason'] ?: ('обзвон: ' . $c['name'])),
            'script'    => (string)($c['script'] ?? ''),
            'campaign'  => (string)$c['name'],
        ];
        return true;
    });
    echo json_encode(['ok' => true, 'targets' => $out, 'log' => $log], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('voice_queue: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'internal']);
}
