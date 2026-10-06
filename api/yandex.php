<?php
declare(strict_types=1);
/**
 * Синхронизация с Яндексом — офлайн-конверсии (Метрика→Директ) + Вебмастер.
 *
 * Смысл: реальные заказы из CRM отправляются в Метрику как офлайн-конверсии
 * с привязкой по yclid → автостратегии Директа оптимизируются на реальные
 * продажи, а не только на клики. Две цели:
 *   - «Заявка»  (metrika_goal_lead)  — на каждый новый лид с yclid;
 *   - «Заказ»   (metrika_goal_order) — на лид в статусе won с суммой (выручка).
 *
 * Токен — единый OAuth Яндекса secret('direct_token') (нужны права Директ +
 * Метрика + Вебмастер). Счётчик — metrika_counter_id (по умолчанию cfg metrika_id).
 *
 * Водяные знаки без изменения схемы БД (важно: прод-миграции не гоняются):
 *   ya_sync_lead_wm     — max(id) отправленных лидов (лиды append-only по id);
 *   ya_order_sent_ids   — JSON-массив id уже отправленных заказов (заказов мало).
 *
 * Actions:
 *   status | sync_now | webmaster | settings_get | settings_save
 * sync_now можно звать по расписанию: GET ?action=sync_now&key=<ya_sync_cron_secret>
 * (без сессии) — для крона/пинга из автопилота Директа.
 */
require_once __DIR__ . '/helpers.php';

const METRIKA_API = 'https://api-metrika.yandex.net';
const WEBMASTER_API = 'https://api.webmaster.yandex.net/v4';

function ya_token(): string {
    // Единый токен Яндекса; можно переопределить отдельным ya_oauth_token.
    return ya_token_any('ya_oauth_token', 'direct_token');
}
function ya_counter(): string {
    return ya_counter_id();
}
function ya_goal(string $which): string {
    return (string)setting($which === 'order' ? 'metrika_goal_order' : 'metrika_goal_lead', '');
}
function ya_enabled(): bool {
    return setting('ya_sync_enabled', '0') === '1';
}

/** GET к API Яндекса с OAuth. Возвращает [http_code, data]. */
function ya_get(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Authorization: OAuth ' . ya_token()],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false) return [0, ['error' => $err]];
    $data = json_decode((string)$body, true);
    return [$code, is_array($data) ? $data : ['raw' => $body]];
}

/**
 * Выгрузка офлайн-конверсий по yclid в Метрику (multipart CSV).
 * $rows: [['yclid'=>..,'ts'=>unix,'price'=>float|null], ...]
 * Возвращает [http_code, data].
 */
function metrika_upload(string $counter, string $goal, array $rows, bool $withPrice): array {
    $header = $withPrice ? "Yclid,Target,DateTime,Price,Currency\n" : "Yclid,Target,DateTime\n";
    $csv = $header;
    foreach ($rows as $r) {
        $y = preg_replace('/[^0-9]/', '', (string)$r['yclid']);
        if ($y === '') continue;
        if ($withPrice) {
            $price = number_format((float)($r['price'] ?? 0), 2, '.', '');
            $csv .= "{$y},{$goal},{$r['ts']},{$price},RUB\n";
        } else {
            $csv .= "{$y},{$goal},{$r['ts']}\n";
        }
    }
    $tmp = tempnam(sys_get_temp_dir(), 'yaconv') . '.csv';
    file_put_contents($tmp, $csv);
    $url = METRIKA_API . '/management/v1/counter/' . rawurlencode($counter) . '/offline_conversions/upload';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['Authorization: OAuth ' . ya_token()],
        CURLOPT_POSTFIELDS => ['file' => new CURLFile($tmp, 'text/csv', 'conversions.csv')],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    @unlink($tmp);
    if ($body === false) return [0, ['error' => $err]];
    return [$code, json_decode((string)$body, true) ?: ['raw' => $body]];
}

/** Кандидаты на отправку: [lead_rows, order_rows] с учётом водяных знаков. */
function ya_pending(): array {
    $leadWm = (int)setting('ya_sync_lead_wm', '0');
    $sentOrders = json_decode((string)setting('ya_order_sent_ids', '[]'), true) ?: [];
    $sentOrders = array_map('intval', $sentOrders);

    // Заявки: новые лиды с yclid, id > watermark.
    $st = pdo()->prepare(
        "SELECT id, yclid, UNIX_TIMESTAMP(created_at) ts
         FROM crm_leads WHERE yclid <> '' AND id > ? ORDER BY id LIMIT 5000"
    );
    $st->execute([$leadWm]);
    $leads = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Заказы: won с yclid и суммой, ещё не отправленные.
    $st2 = pdo()->query(
        "SELECT id, yclid, amount, UNIX_TIMESTAMP(updated_at) ts
         FROM crm_leads WHERE yclid <> '' AND status = 'won' AND amount > 0 ORDER BY id"
    );
    $orders = array_values(array_filter($st2->fetchAll(PDO::FETCH_ASSOC) ?: [],
        fn($o) => !in_array((int)$o['id'], $sentOrders, true)));

    return [$leads, $orders];
}

/** Запуск синхронизации. $dry=true — только посчитать/показать, без отправки. */
function ya_sync_run(bool $dry = false): array {
    $counter = ya_counter();
    $goalLead = ya_goal('lead');
    $goalOrder = ya_goal('order');
    [$leads, $orders] = ya_pending();

    $out = ['dry' => $dry, 'counter' => $counter,
            'pending_leads' => count($leads), 'pending_orders' => count($orders),
            'sent_leads' => 0, 'sent_orders' => 0, 'errors' => []];

    if (!ya_enabled() && !$dry) { $out['errors'][] = 'Синхронизация выключена (Настройки)'; return $out; }
    if ($counter === '') { $out['errors'][] = 'Не задан счётчик Метрики'; return $out; }
    if (ya_token() === '' && !$dry) { $out['errors'][] = 'Не задан OAuth-токен Яндекса'; return $out; }

    // Заявки.
    if ($leads && $goalLead !== '') {
        if ($dry) { $out['sent_leads'] = count($leads); }
        else {
            [$c, $d] = metrika_upload($counter, $goalLead,
                array_map(fn($l) => ['yclid' => $l['yclid'], 'ts' => (int)$l['ts']], $leads), false);
            if ($c === 200) {
                $out['sent_leads'] = count($leads);
                $maxId = max(array_map(fn($l) => (int)$l['id'], $leads));
                ya_set('ya_sync_lead_wm', (string)$maxId);
            } else { $out['errors'][] = "Метрика (заявки) HTTP $c: " . json_encode($d, JSON_UNESCAPED_UNICODE); }
        }
    } elseif ($leads && $goalLead === '') {
        $out['errors'][] = 'Не задана цель «Заявка» (metrika_goal_lead)';
    }

    // Заказы.
    if ($orders && $goalOrder !== '') {
        if ($dry) { $out['sent_orders'] = count($orders); }
        else {
            [$c, $d] = metrika_upload($counter, $goalOrder,
                array_map(fn($o) => ['yclid' => $o['yclid'], 'ts' => (int)$o['ts'], 'price' => (float)$o['amount']], $orders), true);
            if ($c === 200) {
                $out['sent_orders'] = count($orders);
                $sent = json_decode((string)setting('ya_order_sent_ids', '[]'), true) ?: [];
                foreach ($orders as $o) $sent[] = (int)$o['id'];
                ya_set('ya_order_sent_ids', json_encode(array_values(array_unique($sent))));
            } else { $out['errors'][] = "Метрика (заказы) HTTP $c: " . json_encode($d, JSON_UNESCAPED_UNICODE); }
        }
    } elseif ($orders && $goalOrder === '') {
        $out['errors'][] = 'Не задана цель «Заказ» (metrika_goal_order)';
    }

    ya_set('ya_sync_last_at', (string)time());
    return $out;
}

function ya_set(string $k, string $v): void {
    pdo()->prepare('INSERT INTO crm_settings (skey,sval) VALUES (?,?) ON DUPLICATE KEY UPDATE sval=VALUES(sval)')
        ->execute([$k, $v]);
}

/** Сводка Вебмастера: индексация + топ поисковых запросов по хосту. */
function ya_webmaster(): array {
    if (ya_token() === '') return ['error' => 'Не задан OAuth-токен Яндекса'];
    [$c, $u] = ya_get(WEBMASTER_API . '/user');
    if ($c !== 200 || empty($u['user_id'])) return ['error' => "Вебмастер: нет доступа (HTTP $c). Нужен scope webmaster:read", 'raw' => $u];
    $uid = $u['user_id'];
    [$hc, $hosts] = ya_get(WEBMASTER_API . "/user/{$uid}/hosts");
    $list = $hosts['hosts'] ?? [];
    // Ищем хост zavod-red.ru.
    $hostId = '';
    foreach ($list as $h) {
        if (stripos((string)($h['unicode_host_url'] ?? $h['host_url'] ?? ''), 'zavod-red.ru') !== false) {
            $hostId = (string)$h['host_id']; break;
        }
    }
    if ($hostId === '' && $list) $hostId = (string)$list[0]['host_id'];
    if ($hostId === '') return ['error' => 'В Вебмастере нет подтверждённых хостов', 'user_id' => $uid];

    [$sc, $summary] = ya_get(WEBMASTER_API . "/user/{$uid}/hosts/{$hostId}/summary");
    $q = WEBMASTER_API . "/user/{$uid}/hosts/{$hostId}/search-queries/popular"
        . "?order_by=TOTAL_SHOWS&query_indicator=TOTAL_SHOWS&query_indicator=TOTAL_CLICKS&limit=20";
    [$qc, $queries] = ya_get($q);
    return ['host_id' => $hostId, 'summary' => $summary,
            'queries' => $queries['queries'] ?? ($queries['text_indicator_to_statistics'] ?? $queries)];
}

// ---------------- роутер ----------------
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) !== realpath(__FILE__)) {
    return;
}
$action = $_GET['action'] ?? '';
$in = json_decode(file_get_contents('php://input') ?: '[]', true) ?: $_POST;
try {
    // Крон-вход без сессии: ?action=sync_now&key=<secret>
    if ($action === 'sync_now' && isset($_GET['key'])) {
        $cron = secret('ya_sync_cron_secret');
        if ($cron === '' || !hash_equals($cron, (string)$_GET['key'])) {
            json_out(['ok' => false, 'error' => 'forbidden'], 403);
        }
        json_out(['ok' => true] + ya_sync_run(false));
    }

    switch ($action) {
        case 'status':
            require_auth();
            [$leads, $orders] = ya_pending();
            json_out(['ok' => true,
                'enabled' => ya_enabled(),
                'token_set' => ya_token() !== '',
                'counter' => ya_counter(),
                'goal_lead' => ya_goal('lead'),
                'goal_order' => ya_goal('order'),
                'pending_leads' => count($leads),
                'pending_orders' => count($orders),
                'last_sync_at' => (int)setting('ya_sync_last_at', '0'),
                'lead_wm' => (int)setting('ya_sync_lead_wm', '0'),
            ]);

        case 'preview':
            require_auth();
            json_out(['ok' => true] + ya_sync_run(true));

        case 'sync_now':
            require_auth(); csrf_check();
            json_out(['ok' => true] + ya_sync_run(false));

        case 'webmaster':
            require_auth();
            json_out(['ok' => true, 'webmaster' => ya_webmaster()]);

        case 'settings_get':
            require_auth('admin');
            json_out(['ok' => true,
                'metrika_counter_id' => ya_counter(),
                'metrika_goal_lead' => ya_goal('lead'),
                'metrika_goal_order' => ya_goal('order'),
                'ya_sync_enabled' => ya_enabled(),
                'token_set' => ya_token() !== '',
                'cron_secret_set' => secret('ya_sync_cron_secret') !== '',
            ]);

        case 'settings_save':
            require_auth('admin'); csrf_check();
            $map = ['metrika_counter_id', 'metrika_goal_lead', 'metrika_goal_order'];
            foreach ($map as $k) { if (isset($in[$k])) ya_set($k, trim((string)$in[$k])); }
            // ya_counter_id() берёт metrika_id первым — без этого новый счётчик отсюда молча игнорировался
            if (isset($in['metrika_counter_id']) && trim((string)$in['metrika_counter_id']) !== '') ya_set('metrika_id', trim((string)$in['metrika_counter_id']));
            if (isset($in['ya_sync_enabled'])) ya_set('ya_sync_enabled', $in['ya_sync_enabled'] ? '1' : '0');
            if (!empty($in['ya_oauth_token'])) ya_set('ya_oauth_token', trim((string)$in['ya_oauth_token']));
            if (!empty($in['ya_sync_cron_secret'])) ya_set('ya_sync_cron_secret', trim((string)$in['ya_sync_cron_secret']));
            json_out(['ok' => true]);

        case 'gen_cron_secret':
            require_auth('admin');
            json_out(['ok' => true, 'secret' => bin2hex(random_bytes(20))]);

        default:
            json_out(['ok' => false, 'error' => 'Неизвестное действие'], 404);
    }
} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => $e->getMessage()], 500);
}
