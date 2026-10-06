<?php
declare(strict_types=1);

/**
 * /api/digest.php — CLI-крон: вечерняя сводка дня в Telegram.
 * БЕЗ авторизации (серверный процесс). Запускать ТОЛЬКО из cron/CLI.
 *
 * Cron:  0 19 * * * php /path/to/api/digest.php
 *
 * Сводка за сегодня: новых лидов, в работе, выиграно, сумма won, просрочка SLA (15 мин).
 * Отправка через Telegram Bot API sendMessage. token=secret('tg_token'),
 * chat=secret('tg_chat', secret('notify_chat')). Если не настроено — выход.
 */

require_once __DIR__ . '/helpers.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

cron_heartbeat('digest'); // пульс для health.php

$token = secret('tg_token', cfg()['telegram']['token'] ?? '');
$chat  = secret('tg_chat', secret('notify_chat', cfg()['telegram']['chat'] ?? ''));
if ($token === '' || $chat === '') {
    echo "telegram not configured\n";
    exit;
}

try {
    $pdo = pdo();

    // Новые лиды, созданные сегодня
    $newToday = (int)$pdo->query(
        "SELECT COUNT(*) FROM crm_leads WHERE DATE(created_at) = CURDATE()"
    )->fetchColumn();

    // В работе (текущий статус) — снимок на сейчас
    $inProgress = (int)$pdo->query(
        "SELECT COUNT(*) FROM crm_leads WHERE status = 'in_progress'"
    )->fetchColumn();

    // Выиграно сегодня (по дате создания) + сумма
    $row = $pdo->query(
        "SELECT COUNT(*) AS c, COALESCE(SUM(amount),0) AS s
         FROM crm_leads
         WHERE status = 'won' AND DATE(created_at) = CURDATE()"
    )->fetch();
    $wonCount = (int)($row['c'] ?? 0);
    $wonSum   = (float)($row['s'] ?? 0);

    // Просрочка SLA (15 мин): new старше 15 мин без ответной активности
    $slaCount = (int)$pdo->query(
        "SELECT COUNT(*) FROM crm_leads l
         WHERE l.status = 'new'
           AND l.created_at <= (NOW() - INTERVAL 15 MINUTE)
           AND NOT EXISTS (
               SELECT 1 FROM crm_events e
               WHERE e.lead_id = l.id
                 AND e.type IN ('msg_out','status_changed','email_sent','note_added')
           )"
    )->fetchColumn();

    $sumFmt = number_format($wonSum, 0, '.', ' ');
    $lines = [];
    $lines[] = '<b>Сводка за день — ' . date('d.m.Y') . '</b>';
    $lines[] = 'Новых заявок: ' . $newToday;
    $lines[] = 'В работе: ' . $inProgress;
    $lines[] = 'Выиграно сегодня: ' . $wonCount . ' на ' . $sumFmt . ' ₽';
    $lines[] = ($slaCount > 0 ? '⚠️ ' : '') . 'Просрочка ответа (SLA 15 мин): ' . $slaCount;
    $text = implode("\n", $lines);

    $payload = [
        'chat_id'    => $chat,
        'text'       => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ];
    $ch = curl_init('https://api.telegram.org/bot' . $token . '/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['content-type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw  = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        error_log('digest: cURL ' . $err);
        echo "send failed\n";
        exit;
    }
    if ($http !== 200) {
        error_log('digest: telegram HTTP ' . $http . ' ' . (string)$raw);
        echo "send failed http $http\n";
        exit;
    }
    echo "sent\n";
} catch (Throwable $e) {
    error_log('digest: ' . $e->getMessage());
    echo "error\n";
}
