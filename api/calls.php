<?php
declare(strict_types=1);

/**
 * /api/calls.php — модуль ОБЗВОНА ПО СПИСКУ (кампании).
 *
 * Что было до него: очередь на один номер в SQLite голосового сервиса и форма
 * «перезвонить» в админке. Списка, кампаний, повторных попыток и стоп-листа не было
 * вовсе: одна неудачная попытка — и номер оставался в статусе error навсегда.
 *
 * Что здесь:
 *   crm_call_campaigns — кампания (правила: часы, попытки, интервал, лимит в сутки)
 *   crm_call_targets   — цели кампании (компания, телефон, приоритет, попытки, результат)
 *   crm_dnc            — стоп-лист «не звонить» (по нормализованному номеру)
 *
 * Диспетчер calls_dispatch() отдаёт следующие номера голосовому сервису
 * (zavod-red-voice, POST /calls). В режиме dry_run звонки НЕ ставятся: цель
 * помечается как проверенная, и видно, что кампания поехала бы правильно —
 * это штатный режим, пока телефония (Voximplant) не подключена.
 *
 * ВАЖНО: сам факт звонка — наружу. Диспетчер не запускается сам по себе:
 * кампанию переводит в active человек, и у неё есть суточный лимит.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/voice.php'; // voice_http() — роутер voice.php при require не выполняется

/** Создать таблицы модуля, если их ещё нет. Идемпотентно. */
function calls_ensure(): void {
    static $done = false;
    if ($done) return;
    $pdo = pdo();
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_call_campaigns (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(160) NOT NULL,
        kind VARCHAR(16) NOT NULL DEFAULT 'cold',      -- client (по переписке) | cold (холодные)
        status VARCHAR(16) NOT NULL DEFAULT 'draft',   -- draft | active | paused | done
        hours VARCHAR(16) NOT NULL DEFAULT '10-19',
        max_attempts INT NOT NULL DEFAULT 3,
        retry_hours INT NOT NULL DEFAULT 24,
        daily_limit INT NOT NULL DEFAULT 50,
        dry_run TINYINT(1) NOT NULL DEFAULT 1,         -- по умолчанию НЕ звоним
        script MEDIUMTEXT NULL,                        -- что говорить: цель, вопросы, оффер
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_call_targets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        campaign_id INT NOT NULL,
        lead_id INT NULL,
        company VARCHAR(255) NOT NULL DEFAULT '',
        person VARCHAR(190) NOT NULL DEFAULT '',
        phone VARCHAR(64) NOT NULL DEFAULT '',
        phone_norm VARCHAR(32) NOT NULL DEFAULT '',
        email VARCHAR(190) NOT NULL DEFAULT '',
        city VARCHAR(120) NOT NULL DEFAULT '',
        segment VARCHAR(120) NOT NULL DEFAULT '',
        priority INT NOT NULL DEFAULT 5,               -- 1 — звонить первыми
        reason VARCHAR(500) NOT NULL DEFAULT '',       -- почему в приоритете (сигнал из переписки)
        note MEDIUMTEXT NULL,                          -- контекст для оператора/ИИ
        status VARCHAR(16) NOT NULL DEFAULT 'queued',  -- queued|calling|done|no_answer|refused|failed|skipped|dnc
        attempts INT NOT NULL DEFAULT 0,
        last_attempt_at DATETIME NULL,
        next_attempt_at DATETIME NULL,
        result MEDIUMTEXT NULL,
        call_id VARCHAR(64) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_camp_phone (campaign_id, phone_norm),
        KEY idx_camp_status (campaign_id, status, priority),
        KEY idx_phone (phone_norm),
        KEY idx_next (next_attempt_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_dnc (
        phone_norm VARCHAR(32) NOT NULL PRIMARY KEY,
        reason VARCHAR(255) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/**
 * Нормализовать российский номер к 11 цифрам с 7 впереди.
 * Возвращает '' если номер непригоден для звонка (короткий, буквенный, 8-800 оставляем).
 * Единый ключ дедупа и сверки со стоп-листом: в базах номер записан десятком способов.
 */
function calls_norm_phone(string $raw): string {
    $d = preg_replace('/\D+/', '', $raw) ?? '';
    if ($d === '') return '';
    if (strlen($d) === 11 && $d[0] === '8') $d = '7' . substr($d, 1);
    if (strlen($d) === 10) $d = '7' . $d;
    if (strlen($d) !== 11 || $d[0] !== '7') return '';
    return $d;
}

/** Телефон к виду +7 XXX XXX-XX-XX для показа человеку. */
function calls_fmt_phone(string $norm): string {
    if (strlen($norm) !== 11) return $norm;
    return sprintf('+%s %s %s-%s-%s', $norm[0], substr($norm,1,3), substr($norm,4,3), substr($norm,7,2), substr($norm,9,2));
}

/** В стоп-листе? */
function calls_is_dnc(string $phoneNorm): bool {
    if ($phoneNorm === '') return true;
    $st = pdo()->prepare('SELECT 1 FROM crm_dnc WHERE phone_norm = ? LIMIT 1');
    $st->execute([$phoneNorm]);
    return (bool)$st->fetchColumn();
}

/** Добавить в стоп-лист. */
function calls_dnc_add(string $phoneRaw, string $reason = ''): bool {
    $n = calls_norm_phone($phoneRaw);
    if ($n === '') return false;
    pdo()->prepare('INSERT INTO crm_dnc (phone_norm, reason) VALUES (?,?) ON DUPLICATE KEY UPDATE reason=VALUES(reason)')
        ->execute([$n, mb_substr($reason, 0, 255)]);
    // Уже стоящие в очереди цели с этим номером снимаем — иначе стоп-лист бесполезен.
    pdo()->prepare("UPDATE crm_call_targets SET status='dnc' WHERE phone_norm=? AND status IN ('queued','calling')")
        ->execute([$n]);
    return true;
}

/** Создать кампанию, вернуть id. */
function calls_campaign_create(array $o): int {
    calls_ensure();
    $st = pdo()->prepare(
        'INSERT INTO crm_call_campaigns (name,kind,status,hours,max_attempts,retry_hours,daily_limit,dry_run,script)
         VALUES (?,?,?,?,?,?,?,?,?)'
    );
    $st->execute([
        mb_substr(trim((string)($o['name'] ?? 'Обзвон')), 0, 160),
        in_array($o['kind'] ?? 'cold', ['client', 'cold'], true) ? ($o['kind'] ?? 'cold') : 'cold',
        in_array($o['status'] ?? 'draft', ['draft','active','paused','done'], true) ? ($o['status'] ?? 'draft') : 'draft',
        (string)($o['hours'] ?? '10-19'),
        max(1, (int)($o['max_attempts'] ?? 3)),
        max(1, (int)($o['retry_hours'] ?? 24)),
        max(1, (int)($o['daily_limit'] ?? 50)),
        !empty($o['dry_run']) || !isset($o['dry_run']) ? 1 : 0,
        (string)($o['script'] ?? ''),
    ]);
    return (int)pdo()->lastInsertId();
}

/**
 * Добавить цели в кампанию. Дедуп по (кампания, номер), стоп-лист уважается.
 * Возвращает ['added'=>int,'dup'=>int,'bad_phone'=>int,'dnc'=>int].
 */
function calls_targets_add(int $campaignId, array $rows): array {
    calls_ensure();
    $res = ['added' => 0, 'dup' => 0, 'bad_phone' => 0, 'dnc' => 0];
    $ins = pdo()->prepare(
        'INSERT INTO crm_call_targets
           (campaign_id,lead_id,company,person,phone,phone_norm,email,city,segment,priority,reason,note,status,next_attempt_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())'
    );
    foreach ($rows as $r) {
        $norm = calls_norm_phone((string)($r['phone'] ?? ''));
        if ($norm === '') { $res['bad_phone']++; continue; }
        if (calls_is_dnc($norm))  { $res['dnc']++; continue; }
        try {
            $ins->execute([
                $campaignId,
                !empty($r['lead_id']) ? (int)$r['lead_id'] : null,
                mb_substr((string)($r['company'] ?? ''), 0, 255),
                mb_substr((string)($r['person'] ?? ''), 0, 190),
                mb_substr(calls_fmt_phone($norm), 0, 64),
                $norm,
                mb_substr((string)($r['email'] ?? ''), 0, 190),
                mb_substr((string)($r['city'] ?? ''), 0, 120),
                mb_substr((string)($r['segment'] ?? ''), 0, 120),
                max(1, min(9, (int)($r['priority'] ?? 5))),
                mb_substr((string)($r['reason'] ?? ''), 0, 500),
                (string)($r['note'] ?? ''),
                'queued',
            ]);
            $res['added']++;
        } catch (Throwable $e) {
            // 1062 — тот же номер уже в этой кампании.
            if (strpos($e->getMessage(), '1062') !== false) $res['dup']++;
            else throw $e;
        }
    }
    return $res;
}

/** Внутри рабочих часов кампании («10-19»). */
function calls_within_hours(string $hours): bool {
    if (!preg_match('/^(\d{1,2})\s*-\s*(\d{1,2})$/', trim($hours), $m)) return true;
    $lo = (int)$m[1]; $hi = (int)$m[2]; $h = (int)date('G');
    return $lo <= $hi ? ($h >= $lo && $h < $hi) : ($h >= $lo || $h < $hi);
}

/** Сколько попыток кампания уже сделала за сутки (для суточного лимита). */
function calls_today_count(int $campaignId): int {
    $st = pdo()->prepare(
        'SELECT COUNT(*) FROM crm_call_targets
         WHERE campaign_id = ? AND last_attempt_at >= (NOW() - INTERVAL 24 HOUR)'
    );
    $st->execute([$campaignId]);
    return (int)$st->fetchColumn();
}

/** Сводка по кампании: сколько в каком статусе. */
function calls_campaign_stats(int $campaignId): array {
    $st = pdo()->prepare('SELECT status, COUNT(*) c FROM crm_call_targets WHERE campaign_id=? GROUP BY status');
    $st->execute([$campaignId]);
    $out = ['total' => 0];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $out[(string)$r['status']] = (int)$r['c'];
        $out['total'] += (int)$r['c'];
    }
    return $out;
}

/**
 * Диспетчер: поставить следующие номера активных кампаний на звонок.
 * Запускать кроном (каждые 5-10 минут). Возвращает журнал по каждой попытке.
 *
 * Правила: только active-кампании, только в рабочие часы, не больше daily_limit
 * за сутки, цель берётся по приоритету и сроку следующей попытки. Провал попытки
 * НЕ убивает номер — ставится next_attempt_at через retry_hours, пока не исчерпаны
 * max_attempts (именно этого и не хватало прежней очереди).
 */
/**
 * $deliver — как отдать номер роботу. null: CRM сама толкает на POST /calls робота (voice_http).
 * Иначе callback($target, $campaign): ?bool — режим «робот забирает сам» (api/voice_queue.php);
 * null — «больше не беру», цель не трогается.
 */
function calls_dispatch(int $limitPerCampaign = 5, ?callable $deliver = null): array {
    calls_ensure();
    cron_heartbeat('calls');
    $log = [];
    $camps = pdo()->query("SELECT * FROM crm_call_campaigns WHERE status='active' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($camps as $c) {
        $cid = (int)$c['id'];
        if (!calls_within_hours((string)$c['hours'])) {
            $log[] = ['campaign' => $cid, 'skip' => 'вне рабочих часов ' . $c['hours']];
            continue;
        }
        $done = calls_today_count($cid);
        $room = max(0, (int)$c['daily_limit'] - $done);
        if ($room === 0) {
            $log[] = ['campaign' => $cid, 'skip' => 'суточный лимит ' . $c['daily_limit'] . ' исчерпан'];
            continue;
        }
        $take = min($room, max(1, $limitPerCampaign));

        $st = pdo()->prepare(
            "SELECT * FROM crm_call_targets
             WHERE campaign_id = ? AND status = 'queued'
               AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())
               AND attempts < ?
             ORDER BY priority ASC, id ASC LIMIT ?"
        );
        $st->bindValue(1, $cid, PDO::PARAM_INT);
        $st->bindValue(2, (int)$c['max_attempts'], PDO::PARAM_INT);
        $st->bindValue(3, $take, PDO::PARAM_INT);
        $st->execute();
        $targets = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($targets as $t) {
            $tid = (int)$t['id'];
            // Стоп-лист мог пополниться после набора списка.
            if (calls_is_dnc((string)$t['phone_norm'])) {
                pdo()->prepare("UPDATE crm_call_targets SET status='dnc' WHERE id=?")->execute([$tid]);
                $log[] = ['target' => $tid, 'skip' => 'в стоп-листе'];
                continue;
            }

            if ((int)$c['dry_run'] === 1) {
                // Сухой прогон: ничего наружу, но фиксируем, что цель прошла все правила.
                pdo()->prepare(
                    "UPDATE crm_call_targets
                     SET status='skipped', attempts=attempts+1, last_attempt_at=NOW(),
                         result=CONCAT(COALESCE(result,''), 'dry-run ', NOW(), '\n')
                     WHERE id=?"
                )->execute([$tid]);
                $log[] = ['target' => $tid, 'phone' => $t['phone'], 'dry_run' => true];
                continue;
            }

            if ($deliver !== null) {
                $took = $deliver($t, $c);
                if ($took === null) continue; // получатель набрал порцию — цель остаётся в очереди как была
                $code = $took ? 200 : 0;
                $data = ['error' => 'робот не принял номер'];
            } else {
                [$code, $data] = voice_http('POST', '/calls', [
                    'phone'  => '+' . $t['phone_norm'],
                    'name'   => (string)($t['person'] ?: $t['company']),
                    'reason' => (string)($t['reason'] ?: ('обзвон: ' . $c['name'])),
                ]);
            }
            if ($code === 200) {
                pdo()->prepare(
                    "UPDATE crm_call_targets
                     SET status='calling', attempts=attempts+1, last_attempt_at=NOW(), next_attempt_at=NULL
                     WHERE id=?"
                )->execute([$tid]);
                if (!empty($t['lead_id'])) {
                    audit((int)$t['lead_id'], null, 'call_queued', ['campaign' => $cid, 'target' => $tid]);
                }
                $log[] = ['target' => $tid, 'phone' => $t['phone'], 'queued' => true];
            } else {
                // Повтор через retry_hours; когда попытки исчерпаны — статус failed.
                $err = (string)($data['error'] ?? ('HTTP ' . $code));
                $attempts = (int)$t['attempts'] + 1;
                $exhausted = $attempts >= (int)$c['max_attempts'];
                pdo()->prepare(
                    "UPDATE crm_call_targets
                     SET status = ?, attempts = ?, last_attempt_at = NOW(),
                         next_attempt_at = IF(?, NULL, NOW() + INTERVAL ? HOUR),
                         result = CONCAT(COALESCE(result,''), ?, '\n')
                     WHERE id = ?"
                )->execute([
                    $exhausted ? 'failed' : 'queued', $attempts,
                    $exhausted ? 1 : 0, (int)$c['retry_hours'],
                    date('Y-m-d H:i') . ' ошибка запуска: ' . mb_substr($err, 0, 200), $tid,
                ]);
                $log[] = ['target' => $tid, 'phone' => $t['phone'], 'error' => $err, 'exhausted' => $exhausted];
            }
        }
    }
    return $log;
}
