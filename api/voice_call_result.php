<?php
declare(strict_types=1);

/**
 * POST /api/voice_call_result.php — итог звонка по цели обзвона.
 * Тело: {"phone":"+7…","call_id":"…","outcome":"…","note":"…"} + секрет (см. voice_hook.php).
 *
 * Зачем: calls_dispatch() ставит цель в 'calling' и отдаёт номер роботу, а обратно
 * статус никто не возвращал — цель зависала в 'calling' навсегда.
 *
 * outcome: done (поговорили) | transferred (переведён на менеджера) | refused (отказ) |
 *          dnc (просит не звонить → стоп-лист) | no_answer | busy | failed.
 * Недозвон/занято/сбой — следующая попытка через retry_hours кампании, пока не
 * исчерпаны max_attempts; дальше статус no_answer/failed.
 */

require_once __DIR__ . '/voice_hook.php';
require_once __DIR__ . '/calls.php';

$in = voice_hook_read();
try {
    calls_ensure();
    $outcome = strtolower(trim((string)($in['outcome'] ?? '')));
    $allowed = ['done', 'transferred', 'refused', 'dnc', 'no_answer', 'busy', 'failed'];
    if (!in_array($outcome, $allowed, true)) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'bad outcome']); exit; }
    $phoneNorm = calls_norm_phone((string)($in['phone'] ?? ''));
    $callId = mb_substr(trim((string)($in['call_id'] ?? '')), 0, 64);

    // Цель: по target_id (выдан из voice_queue.php), по call_id, иначе последняя «в звонке» с этим номером.
    $t = null;
    if (!empty($in['target_id'])) {
        $st = pdo()->prepare("SELECT t.*, c.max_attempts, c.retry_hours FROM crm_call_targets t JOIN crm_call_campaigns c ON c.id=t.campaign_id WHERE t.id=?");
        $st->execute([(int)$in['target_id']]);
        $t = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$t && $callId !== '') {
        $st = pdo()->prepare("SELECT t.*, c.max_attempts, c.retry_hours FROM crm_call_targets t JOIN crm_call_campaigns c ON c.id=t.campaign_id WHERE t.call_id=? ORDER BY t.id DESC LIMIT 1");
        $st->execute([$callId]);
        $t = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$t && $phoneNorm !== '') {
        $st = pdo()->prepare("SELECT t.*, c.max_attempts, c.retry_hours FROM crm_call_targets t JOIN crm_call_campaigns c ON c.id=t.campaign_id
                              WHERE t.phone_norm=? AND t.status='calling' ORDER BY t.last_attempt_at DESC, t.id DESC LIMIT 1");
        $st->execute([$phoneNorm]);
        $t = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if ($outcome === 'dnc' && $phoneNorm !== '') calls_dnc_add($phoneNorm, 'голосовой робот: просит не звонить');
    if (!$t) { echo json_encode(['ok' => true, 'target' => null, 'note' => 'цель обзвона не найдена — звонок вне кампании']); exit; }

    $retry = in_array($outcome, ['no_answer', 'busy', 'failed'], true);
    $exhausted = (int)$t['attempts'] >= (int)$t['max_attempts'];
    $status = match (true) {
        in_array($outcome, ['done', 'transferred'], true) => 'done',
        $outcome === 'refused' => 'refused',
        $outcome === 'dnc' => 'dnc',
        $retry && !$exhausted => 'queued',
        $outcome === 'failed' => 'failed',
        default => 'no_answer',
    };
    $note = mb_substr(trim((string)($in['note'] ?? '')), 0, 300);
    $line = date('Y-m-d H:i') . ' робот: ' . $outcome . ($note !== '' ? ' — ' . $note : '') . ($callId !== '' ? ' [' . $callId . ']' : '');
    pdo()->prepare(
        "UPDATE crm_call_targets
         SET status=?, call_id=IF(?<>'', ?, call_id),
             next_attempt_at=IF(?='queued', NOW() + INTERVAL ? HOUR, NULL),
             result=CONCAT(COALESCE(result,''), ?, '\n')
         WHERE id=?"
    )->execute([$status, $callId, $callId, $status, max(1, (int)$t['retry_hours']), $line, (int)$t['id']]);
    if (!empty($t['lead_id'])) audit((int)$t['lead_id'], null, 'call_result', ['outcome' => $outcome, 'target' => (int)$t['id']]);
    echo json_encode(['ok' => true, 'target' => (int)$t['id'], 'status' => $status]);
} catch (Throwable $e) {
    error_log('voice_call_result: ' . $e->getMessage());
    http_response_code(500); echo json_encode(['ok' => false, 'error' => 'internal']);
}
