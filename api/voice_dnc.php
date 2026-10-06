<?php
declare(strict_types=1);

/**
 * POST /api/voice_dnc.php — робот услышал «не звоните» → номер в стоп-лист CRM.
 * Тело: {"phone":"+7…","reason":"…","call_id":"…"} + секрет (см. voice_hook.php).
 * Цели обзвона с этим номером снимаются из очереди (calls_dnc_add).
 */

require_once __DIR__ . '/voice_hook.php';
require_once __DIR__ . '/calls.php';

$in = voice_hook_read();
try {
    calls_ensure();
    $phone = trim((string)($in['phone'] ?? ''));
    $reason = trim('голосовой робот: ' . (string)($in['reason'] ?? 'просит не звонить')
        . (!empty($in['call_id']) ? ' (звонок ' . $in['call_id'] . ')' : ''));
    if (!calls_dnc_add($phone, $reason)) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'bad phone']); exit; }
    echo json_encode(['ok' => true, 'phone_norm' => calls_norm_phone($phone)]);
} catch (Throwable $e) {
    error_log('voice_dnc: ' . $e->getMessage());
    http_response_code(500); echo json_encode(['ok' => false, 'error' => 'internal']);
}
