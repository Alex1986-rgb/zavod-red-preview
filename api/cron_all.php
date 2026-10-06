<?php
declare(strict_types=1);
/**
 * Единый крон CRM — ОДНА строка в панели Beget вместо шести:
 *   каждые 5 минут:  /usr/local/bin/php8.2 /home/z/zakazpxp/zavod-red.ru/public_html/api/cron_all.php
 *
 * Сам решает, что пора запускать (по московскому времени):
 *   каждый запуск     — почта (входящие + «Отправленные», вложения, обучение) → Битрикс;
 *                       докачка архива ящика (пока не дойдёт до конца); пополнение базы знаний;
 *   раз в 10 минут    — подбор по новым заявкам (ai_auto) → Битрикс, мониторинг сайта;
 *   будни 10–19, 10 мин — обзвон по активным кампаниям (сухой прогон по умолчанию);
 *   03:30             — бэкап базы;  19:00 — вечерняя сводка.
 * Каждая задача — отдельный процесс со своим таймаутом: зависшая не держит остальные.
 * Журнал: crm-data/logs/cron_all.log.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/helpers.php';

$lock = @fopen(__DIR__ . '/../crm-data/logs/cron_all.lock', 'c');
if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) { echo date('Y-m-d H:i') . " предыдущий запуск ещё идёт\n"; exit; }

$m = (int)date('i'); $h = (int)date('G'); $dow = (int)date('N');
$jobs = [['mail_sync.php', 200]];                                     // почта каждые 5 минут
// Весь архив ящика в CRM — докачивается шагами, пока не дойдёт до конца (потом шаг мгновенный).
$arch = json_decode((string)setting('mail_archive', '{}'), true) ?: [];
if (empty($arch['done'])) $jobs[] = ['mail_sync.php --archive --budget=120', 150];
$jobs[] = ['knowledge_build.php --budget=40', 90];                     // база знаний: сайт, переписка, документы
if ($m % 10 < 5) { $jobs[] = ['ai_auto.php', 280]; $jobs[] = ['monitor.php', 120]; }
if ($m % 10 < 5 && $dow <= 5 && $h >= 10 && $h < 19) $jobs[] = ['calls_cron.php', 60];
if ($h === 3 && $m >= 30 && $m < 35) $jobs[] = ['backup.php', 600];
if ($h === 19 && $m < 5) $jobs[] = ['digest.php', 120];

$php = PHP_BINARY ?: 'php';
$logDir = __DIR__ . '/../crm-data/logs';
@mkdir($logDir, 0750, true);
foreach ($jobs as [$script, $timeout]) {
    $t0 = microtime(true);
    $cmd = (is_executable('/usr/bin/timeout') ? '/usr/bin/timeout ' . (int)$timeout . ' ' : '')
         . escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/' . strtok($script, ' ')) . ' '
         . implode(' ', array_map('escapeshellarg', array_slice(explode(' ', $script), 1))) . ' 2>&1';
    $out = []; $code = 0;
    exec($cmd, $out, $code);
    $line = date('Y-m-d H:i:s') . " {$script} код {$code} за " . round(microtime(true) - $t0, 1) . " с";
    file_put_contents($logDir . '/cron_all.log', $line . "\n" . mb_substr(implode("\n", $out), 0, 2000, 'UTF-8') . "\n", FILE_APPEND);
    echo $line, "\n";
}
// Журнал не растёт бесконечно: больше 5 МБ — оставить последние ~2 МБ.
$lf = $logDir . '/cron_all.log';
if (is_file($lf) && filesize($lf) > 5 * 1024 * 1024) file_put_contents($lf, substr((string)file_get_contents($lf), -2 * 1024 * 1024));
