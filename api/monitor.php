<?php
declare(strict_types=1);

/**
 * /api/monitor.php — наблюдение за сайтом + трафик из Яндекс.Метрики.
 *
 *   action=run       (auth + csrf)  — запустить проверку URL, записать в crm_monitor
 *   action=status    (auth)         — последняя проверка по каждому URL + сводка
 *   action=metrika   (auth)         — визиты/посетители за 7 дней из Метрики
 *   action=ssl       (auth)         — дней до истечения SSL-сертификата
 *   action=chart     (auth)         — история времени отклика главной (50 проверок)
 *   action=scan      (auth + csrf)  — обход sitemap.xml, битые ссылки → crm_broken
 *   action=broken    (auth)         — список битых ссылок из crm_broken
 *   action=formcheck (auth + csrf)  — проверка живости формы api/feedback.php
 *
 * CLI: `php api/monitor.php` — выполняет проверку и печатает JSON (для cron):
 *   *\/10 * * * * php /path/api/monitor.php
 *
 * Все запросы — PDO prepared. STRICT UTF-8.
 */

require_once __DIR__ . '/helpers.php';

/**
 * Уведомление о падении страницы/сайта (переход ok→down).
 * Каналы (Telegram, e-mail) обёрнуты в try/catch — отсутствие настроек
 * не должно ломать проверку.
 */
/** Отправить произвольный алёрт в Telegram + e-mail (каналы опциональны). */
function monitor_notify(string $text, string $emailSubject = 'Мониторинг zavod-red.ru'): void {
    // --- Telegram ---
    try {
        $token = secret('tg_token');
        $chat  = secret('tg_chat', secret('notify_chat'));
        if ($token !== '' && $chat !== '' && function_exists('curl_init')) {
            $ch = curl_init("https://api.telegram.org/bot{$token}/sendMessage");
            if ($ch !== false) {
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => ['chat_id' => $chat, 'text' => $text],
                    CURLOPT_TIMEOUT        => 10,
                ]);
                curl_exec($ch);
                curl_close($ch);
            }
        }
    } catch (Throwable $e) { /* канал не настроен — игнор */ }

    // --- E-mail через api/mail.php smtp_send ---
    try {
        $to = secret('mail_smtp_user', (string)(cfg()['mail']['to'] ?? ''));
        if ($to !== '' && is_file(__DIR__ . '/mail.php')) {
            require_once __DIR__ . '/mail.php';
            if (function_exists('smtp_send')) smtp_send($to, $emailSubject, $text);
        }
    } catch (Throwable $e) { /* SMTP не настроен — игнор */ }
}

/** Уведомление о падении страницы/сайта (переход ok→down). */
function monitor_alert(string $url, int $code): void {
    monitor_notify("⚠️ Сайт/страница недоступна: {$url} (код {$code})", 'Мониторинг zavod-red.ru: страница недоступна');
}

/**
 * Алёрт о зависших фоновых задачах по heartbeat (cron_<name>_last).
 * Анти-спам: шлём один раз при переходе work→stale и один раз при восстановлении
 * (состояние в crm_settings.health_state_cron_<name>). Задачи, которые ни разу не
 * запускались, НЕ трогаем — это видно в health, и это не регресс (крон мог быть не нужен).
 */
function alert_stale_crons(): void {
    $jobs = ['mail' => 15, 'ai_auto' => 30, 'backup' => 36 * 60]; // порог свежести, мин
    $titles = ['mail' => 'Приём почты', 'ai_auto' => 'ИИ-распознавание', 'backup' => 'Бэкап БД'];
    foreach ($jobs as $name => $maxMin) {
        $last = setting('cron_' . $name . '_last');
        if ($last === null) continue; // ни разу не запускался — не алёртим
        $ts = strtotime((string)$last);
        $ageMin = $ts ? (time() - $ts) / 60 : 999999;
        $stale = $ageMin > $maxMin;
        $stateKey = 'health_state_cron_' . $name;
        $prev = setting($stateKey, 'ok');
        if ($stale && $prev !== 'down') {
            monitor_notify("🔴 Фоновая задача «{$titles[$name]}» молчит: последний запуск "
                . round($ageMin) . " мин назад. Проверьте cron.", 'Мониторинг: фоновая задача молчит');
            setting_set($stateKey, 'down');
        } elseif (!$stale && $prev !== 'ok') {
            monitor_notify("🟢 Фоновая задача «{$titles[$name]}» снова работает.");
            setting_set($stateKey, 'ok');
        }
    }
}

/**
 * Дней до истечения SSL-сертификата хоста (через validTo). null — не удалось.
 */
function ssl_days(string $host): ?int {
    $host = (string)preg_replace('#^https?://#', '', $host);
    $host = explode('/', $host)[0];
    if ($host === '') return null;

    $ctx = stream_context_create([
        'ssl' => [
            'capture_peer_cert' => true,
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'SNI_enabled'       => true,
            'peer_name'         => $host,
        ],
    ]);

    $client = @stream_socket_client(
        'ssl://' . $host . ':443',
        $errno, $errstr, 8,
        STREAM_CLIENT_CONNECT, $ctx
    );
    if ($client === false) return null;

    $params = stream_context_get_params($client);
    fclose($client);

    $cert = $params['options']['ssl']['peer_certificate'] ?? null;
    if ($cert === null) return null;

    $parsed = openssl_x509_parse($cert);
    if (!is_array($parsed) || empty($parsed['validTo_time_t'])) return null;

    $days = (int) floor(((int)$parsed['validTo_time_t'] - time()) / 86400);
    return $days;
}

/**
 * Проверяет ключевые URL сайта, измеряет код ответа и время (мс),
 * пишет результат в crm_monitor. Возвращает массив результатов.
 *
 * @return array<int,array{url:string,status_code:int,ms:int,ok:int,note:string}>
 */
function check_urls(): array {
    $base = secret('site_url', 'https://zavod-red.ru/');
    if (substr($base, -1) !== '/') $base .= '/';

    $urls = [
        $base,
        $base . 'podbor.html',
        $base . 'catalog/index.html',
        $base . 'api/feedback.php',
    ];

    $pdo = pdo();
    $ins = $pdo->prepare(
        'INSERT INTO crm_monitor (url, status_code, ms, ok, note, checked_at) VALUES (?,?,?,?,?,NOW())'
    );
    // Предыдущий статус по URL (последняя строка) — для детекта перехода ok→down.
    $prevStmt = $pdo->prepare(
        'SELECT ok FROM crm_monitor WHERE url = ? ORDER BY id DESC LIMIT 1'
    );

    $results = [];
    foreach ($urls as $url) {
        $isFeedback = (strpos($url, 'feedback.php') !== false);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET        => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'ZavodRed-Monitor/1.0',
        ]);

        $t0   = microtime(true);
        $body = curl_exec($ch);
        $ms   = (int) round((microtime(true) - $t0) * 1000);

        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = ($body === false) ? curl_error($ch) : '';
        curl_close($ch);

        $ok = (($code >= 200 && $code < 400) || ($isFeedback && $code === 405)) ? 1 : 0;

        if ($err !== '') {
            $note = 'Ошибка сети: ' . $err;
        } elseif ($isFeedback && $code === 405) {
            $note = 'OK (405 — эндпоинт жив)';
        } elseif ($ok) {
            $note = 'OK';
        } else {
            $note = 'Код ' . $code;
        }
        $note = substr($note, 0, 255);

        // Анти-спам: алёрт только на переходе ok=1 → ok=0.
        $prevStmt->execute([$url]);
        $prevOk = $prevStmt->fetchColumn();
        if ($prevOk !== false && (int)$prevOk === 1 && $ok === 0) {
            monitor_alert($url, $code);
        }

        $ins->execute([$url, $code, $ms, $ok, $note]);

        $results[] = [
            'url'         => $url,
            'status_code' => $code,
            'ms'          => $ms,
            'ok'          => $ok,
            'note'        => $note,
        ];
    }

    // --- SSL: одна запись за проверку ---
    $host = (string)preg_replace('#^https?://#', '', $base);
    $host = explode('/', $host)[0];
    if ($host !== '') {
        $days = ssl_days($host);
        if ($days !== null) {
            $sslOk   = $days > 7 ? 1 : 0;
            $sslNote = substr('истекает через ' . $days . ' дн', 0, 255);
            $sslUrl  = 'SSL:' . $host;

            $prevStmt->execute([$sslUrl]);
            $prevOk = $prevStmt->fetchColumn();
            if ($prevOk !== false && (int)$prevOk === 1 && $sslOk === 0) {
                monitor_alert($sslUrl, 0);
            }

            $ins->execute([$sslUrl, 0, 0, $sslOk, $sslNote]);
            $results[] = [
                'url'         => $sslUrl,
                'status_code' => 0,
                'ms'          => 0,
                'ok'          => $sslOk,
                'note'        => $sslNote,
            ];
        }
    }

    return $results;
}

/** История времени отклика главной (base url) за последние 50 проверок. */
function monitor_chart(): array {
    $base = secret('site_url', 'https://zavod-red.ru/');
    if (substr($base, -1) !== '/') $base .= '/';

    $st = pdo()->prepare(
        'SELECT ms, checked_at FROM crm_monitor WHERE url = ? ORDER BY id DESC LIMIT 50'
    );
    $st->execute([$base]);
    $rows = array_reverse($st->fetchAll());

    $labels = [];
    $data   = [];
    foreach ($rows as $r) {
        $labels[] = (string)$r['checked_at'];
        $data[]   = (int)$r['ms'];
    }
    return ['labels' => $labels, 'data' => $data];
}

/**
 * Обход sitemap.xml: HEAD/GET каждого <loc>, коды 404/5xx → crm_broken.
 * Ограничение: 150 URL, таймаут 8с. Старые записи очищаются.
 */
function monitor_scan(): array {
    $base = secret('site_url', 'https://zavod-red.ru/');
    if (substr($base, -1) !== '/') $base .= '/';

    $sitemap = $base . 'sitemap.xml';
    $ch = curl_init($sitemap);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'ZavodRed-Monitor/1.0',
    ]);
    $xml  = curl_exec($ch);
    $sErr = ($xml === false) ? curl_error($ch) : '';
    curl_close($ch);

    if ($xml === false || $xml === '') {
        return ['ok' => false, 'error' => 'sitemap.xml недоступен' . ($sErr !== '' ? ': ' . $sErr : '')];
    }

    // Парсинг <loc>…</loc> без внешних либ.
    $locs = [];
    if (preg_match_all('#<loc>\s*(.*?)\s*</loc>#is', $xml, $m)) {
        foreach ($m[1] as $loc) {
            $loc = html_entity_decode(trim($loc), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($loc !== '' && preg_match('#^https?://#i', $loc)) $locs[] = $loc;
        }
    }
    $locs = array_values(array_unique($locs));
    $locs = array_slice($locs, 0, 150);

    $pdo = pdo();
    $pdo->exec('DELETE FROM crm_broken');
    $ins = $pdo->prepare(
        'INSERT INTO crm_broken (url, status_code, checked_at) VALUES (?,?,NOW())'
    );

    $broken  = [];
    $checked = 0;
    foreach ($locs as $loc) {
        $checked++;
        // HEAD; при пустом/0 ответе — повтор через GET.
        $code = scan_http_code($loc, true);
        if ($code === 0 || $code === 405) {
            $code = scan_http_code($loc, false);
        }
        if ($code === 404 || ($code >= 500 && $code < 600)) {
            $ins->execute([substr($loc, 0, 1000), $code]);
            $broken[] = ['url' => $loc, 'status_code' => $code];
        }
    }

    return ['ok' => true, 'checked' => $checked, 'broken' => $broken];
}

/** Код ответа URL: HEAD ($head=true) или GET. 0 — сетевая ошибка. */
function scan_http_code(string $url, bool $head): int {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'ZavodRed-Monitor/1.0',
    ];
    if ($head) {
        $opts[CURLOPT_NOBODY] = true;
    } else {
        $opts[CURLOPT_HTTPGET] = true;
    }
    curl_setopt_array($ch, $opts);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return $code;
}

/** Список битых ссылок из crm_broken. */
function monitor_broken(): array {
    $rows = pdo()->query(
        'SELECT url, status_code, checked_at FROM crm_broken ORDER BY id DESC'
    )->fetchAll();
    return ['ok' => true, 'broken' => $rows];
}

/**
 * Проверка живости формы: POST на {site_url}api/feedback.php с тест-секретом.
 * Если monitor_test_secret пуст — генерирует и сохраняет в crm_settings.
 */
function monitor_formcheck(): array {
    $base = secret('site_url', 'https://zavod-red.ru/');
    if (substr($base, -1) !== '/') $base .= '/';

    $token = secret('monitor_test_secret');
    if ($token === '') {
        $token = bin2hex(random_bytes(16));
        try {
            $st = pdo()->prepare(
                'INSERT INTO crm_settings (skey, sval) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE sval = VALUES(sval)'
            );
            $st->execute(['monitor_test_secret', $token]);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Не удалось сохранить monitor_test_secret'];
        }
    }

    $ch = curl_init($base . 'api/feedback.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => [
            'text-562'  => 'Monitor Test',
            'tel-535'   => '+7 (000) 000-00-00',
            '__monitor' => $token,
        ],
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'ZavodRed-Monitor/1.0',
    ]);
    $body = curl_exec($ch);
    $err  = ($body === false) ? curl_error($ch) : '';
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'error' => 'Форма недоступна: ' . $err];
    }

    $data = json_decode((string)$body, true);
    $ok = is_array($data) && (($data['status'] ?? '') === 'success');
    return ['ok' => $ok];
}

/** Последняя проверка по каждому URL + сводка. */
function monitor_status(): array {
    $pdo = pdo();
    $sql = 'SELECT m.url, m.status_code, m.ms, m.ok, m.note, m.checked_at
            FROM crm_monitor m
            JOIN (SELECT url, MAX(id) AS mid FROM crm_monitor GROUP BY url) t
              ON t.mid = m.id
            ORDER BY m.url';
    $rows = $pdo->query($sql)->fetchAll();

    $total = count($rows);
    $okCnt = 0;
    $msSum = 0;
    foreach ($rows as $r) {
        if ((int)$r['ok'] === 1) $okCnt++;
        $msSum += (int)$r['ms'];
    }
    $avgMs = $total > 0 ? (int) round($msSum / $total) : 0;

    return [
        'rows'    => $rows,
        'summary' => [
            'ok'     => $okCnt,
            'total'  => $total,
            'avg_ms' => $avgMs,
        ],
    ];
}

/** GET к API Метрики с OAuth-токеном. Возвращает [data|null, err]. */
function metrika_get(string $url, string $token): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => ['Authorization: OAuth ' . $token],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = ($body === false) ? curl_error($ch) : '';
    curl_close($ch);
    if ($err !== '') return [null, 'Метрика недоступна: ' . $err];
    $data = json_decode((string)$body, true);
    if (!is_array($data)) return [null, 'Метрика вернула некорректный ответ'];
    if ($code !== 200) return [null, 'Метрика: ' . ($data['message'] ?? ('HTTP ' . $code))];
    return [$data, ''];
}

/** Визиты/посетители + достижения целей (заявки, формы, мессенджеры) за 7 дней. */
function monitor_metrika(): array {
    // Единый токен Яндекса: metrika_token ИЛИ ya_oauth_token со страницы «Синхро Яндекс»
    // (та же логика, что у Директа, — иначе токен «есть в Метрике, нет в админке»).
    $token = ya_token_any('metrika_token', 'ya_oauth_token');
    if ($token === '') {
        return ['ok' => false, 'error' => 'Не задан OAuth-токен Метрики (Настройки → Интеграции или «Синхро Яндекс»)'];
    }

    $id = ya_counter_id();
    if ($id === '') {
        return ['ok' => false, 'error' => 'Не задан номер счётчика Метрики (Настройки)'];
    }

    [$data, $err] = metrika_get('https://api-metrika.yandex.net/stat/v1/data?' . http_build_query([
        'ids' => $id, 'metrics' => 'ym:s:visits,ym:s:users', 'date1' => '7daysAgo', 'date2' => 'today',
    ]), $token);
    if ($data === null) return ['ok' => false, 'error' => $err];

    // Без dimensions API отдаёт totals ПЛОСКИМ массивом; с dimensions — вложенным.
    $flatTotals = function ($d) {
        $t = is_array($d) ? ($d['totals'] ?? null) : null;
        if (is_array($t) && isset($t[0]) && is_array($t[0])) $t = $t[0];
        return is_array($t) ? $t : [];
    };
    $totals = $flatTotals($data);
    if (count($totals) < 2) {
        return ['ok' => false, 'error' => 'Метрика: нет данных totals'];
    }

    // Цели счётчика (включая автоцели: отправка формы, клик в мессенджер, звонок).
    $goals = [];
    [$gl, ] = metrika_get('https://api-metrika.yandex.net/management/v1/counter/' . rawurlencode($id) . '/goals', $token);
    $list = is_array($gl) ? ($gl['goals'] ?? []) : [];
    foreach (array_chunk($list, 10) as $chunk) { // лимит метрик в одном stat-запросе
        $metrics = implode(',', array_map(fn($g) => 'ym:s:goal' . (int)$g['id'] . 'reaches', $chunk));
        [$st, ] = metrika_get('https://api-metrika.yandex.net/stat/v1/data?' . http_build_query([
            'ids' => $id, 'metrics' => $metrics, 'date1' => '7daysAgo', 'date2' => 'today',
        ]), $token);
        $reaches = $flatTotals($st);
        foreach ($chunk as $i => $g) {
            $n = (int) round((float)($reaches[$i] ?? 0));
            if ($n > 0) $goals[] = ['name' => (string)($g['name'] ?? ('Цель ' . $g['id'])), 'reaches' => $n];
        }
    }
    usort($goals, fn($a, $b) => $b['reaches'] <=> $a['reaches']);

    // Для сравнения: сколько заявок реально дошло до CRM за те же 7 дней (и по каналам).
    $crm7 = 0; $crmSrc = [];
    try {
        $crm7 = (int) pdo()->query("SELECT COUNT(*) FROM crm_leads WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
        foreach (pdo()->query("SELECT COALESCE(NULLIF(source,''),'site') s, COUNT(*) c FROM crm_leads WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY s ORDER BY c DESC") as $r) {
            $crmSrc[] = ['source' => (string)$r['s'], 'count' => (int)$r['c']];
        }
    } catch (Throwable $e) {}

    return [
        'ok'      => true,
        'visits'  => (int) round((float) $totals[0]),
        'users'   => (int) round((float) $totals[1]),
        'goals'   => $goals,
        'crm7'    => $crm7,
        'crm_src' => $crmSrc,
    ];
}

/* ===== CLI-вход (cron) ===== */
if (php_sapi_name() === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    cron_heartbeat('monitor');
    $cliAction = $argv[1] ?? 'run';
    if ($cliAction === 'scan') {
        echo json_encode(monitor_scan(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    } else {
        $results = check_urls();
        // Внешний алёрт о «молчащих» фоновых задачах (мониторинг ходит каждые 10 мин).
        try { alert_stale_crons(); } catch (Throwable $e) { /* не валим прогон */ }
        echo json_encode(['ok' => true, 'results' => $results], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    }
    exit;
}

/* ===== HTTP-вход ===== */
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

switch ($action) {
    case 'run':
        require_auth();
        csrf_check();
        try {
            $results = check_urls();
            json_out(['ok' => true, 'results' => $results]);
        } catch (Throwable $e) {
            json_out(['ok' => false, 'error' => 'Ошибка проверки: ' . $e->getMessage()], 500);
        }
        // no break (json_out exits)

    case 'status':
        require_auth();
        try {
            $st = monitor_status();
            json_out(['ok' => true, 'results' => $st['rows'], 'summary' => $st['summary']]);
        } catch (Throwable $e) {
            json_out(['ok' => false, 'error' => 'Ошибка статуса: ' . $e->getMessage()], 500);
        }

    case 'metrika':
        require_auth();
        try {
            $m = monitor_metrika();
            json_out($m, ($m['ok'] ?? false) ? 200 : 200);
        } catch (Throwable $e) {
            json_out(['ok' => false, 'error' => 'Ошибка Метрики: ' . $e->getMessage()], 500);
        }

    case 'ssl':
        require_auth();
        try {
            $base = secret('site_url', 'https://zavod-red.ru/');
            $host = (string)preg_replace('#^https?://#', '', $base);
            $host = explode('/', $host)[0];
            $days = ssl_days($host);
            json_out(['ok' => true, 'host' => $host, 'days' => $days]);
        } catch (Throwable $e) {
            json_out(['ok' => false, 'error' => 'Ошибка SSL: ' . $e->getMessage()], 500);
        }

    case 'chart':
        require_auth();
        try {
            json_out(monitor_chart());
        } catch (Throwable $e) {
            json_out(['ok' => false, 'error' => 'Ошибка графика: ' . $e->getMessage()], 500);
        }

    case 'scan':
        require_auth();
        csrf_check();
        try {
            json_out(monitor_scan());
        } catch (Throwable $e) {
            json_out(['ok' => false, 'error' => 'Ошибка сканирования: ' . $e->getMessage()], 500);
        }

    case 'broken':
        require_auth();
        try {
            json_out(monitor_broken());
        } catch (Throwable $e) {
            json_out(['ok' => false, 'error' => 'Ошибка списка: ' . $e->getMessage()], 500);
        }

    case 'formcheck':
        require_auth();
        csrf_check();
        try {
            json_out(monitor_formcheck());
        } catch (Throwable $e) {
            json_out(['ok' => false, 'error' => 'Ошибка проверки формы: ' . $e->getMessage()], 500);
        }

    default:
        json_out(['ok' => false, 'error' => 'Неизвестное действие'], 400);
}
