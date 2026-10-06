<?php
declare(strict_types=1);
// Публичный JSON-эндпоинт статистики рассылок. Наружу — только агрегаты; IP получателей
// и журнал открытий (?ip=1) — только вошедшему в CRM (раньше отдавались кому угодно, CORS *).
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=300');

// helpers.php — первым: на боевом config.php (его тянет db.php) объявляет json_out() под проверкой
// function_exists, и если db.php идёт раньше, helpers.php падает на повторном объявлении (500).
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

try {
    $pdo = pdo();

    // Создаём таблицу если нет
    $pdo->exec("CREATE TABLE IF NOT EXISTS email_opens (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        campaign    VARCHAR(100) NOT NULL,
        ip          VARCHAR(45)  NOT NULL,
        device      VARCHAR(20)  NOT NULL DEFAULT 'desktop',
        mail_client VARCHAR(50)  NOT NULL DEFAULT 'unknown',
        user_agent  TEXT,
        opened_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_campaign (campaign),
        INDEX idx_opened_at (opened_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $campaign = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['c'] ?? '');

    if ($campaign) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM email_opens WHERE campaign=?");
        $st->execute([$campaign]);
        $total = (int)$st->fetchColumn();

        $byDay = $pdo->prepare(
            "SELECT DATE(opened_at) as day, COUNT(*) as opens
             FROM email_opens WHERE campaign=? GROUP BY DATE(opened_at) ORDER BY day"
        );
        $byDay->execute([$campaign]);

        $byDevice = $pdo->prepare(
            "SELECT device, COUNT(*) as cnt FROM email_opens WHERE campaign=? GROUP BY device ORDER BY cnt DESC"
        );
        $byDevice->execute([$campaign]);

        $byClient = $pdo->prepare(
            "SELECT mail_client, COUNT(*) as cnt FROM email_opens WHERE campaign=? GROUP BY mail_client ORDER BY cnt DESC"
        );
        $byClient->execute([$campaign]);

        // IP-агрегация (если запрошена)
        if (isset($_GET['ip']) && current_user() !== null) {
            $ipSt = $pdo->prepare(
                "SELECT COUNT(DISTINCT ip) as unique_ips FROM email_opens WHERE campaign=?"
            );
            $ipSt->execute([$campaign]);
            $uniqueIps = (int)$ipSt->fetchColumn();

            $repeatSt = $pdo->prepare(
                "SELECT COUNT(*) FROM (SELECT ip, COUNT(*) as c FROM email_opens WHERE campaign=? GROUP BY ip HAVING c > 1) sub"
            );
            $repeatSt->execute([$campaign]);
            $repeatOpeners = (int)$repeatSt->fetchColumn();

            // Топ IP — с адресами и последней датой
            $topSt = $pdo->prepare(
                "SELECT ip, COUNT(*) as cnt, MAX(opened_at) as last_open FROM email_opens WHERE campaign=? GROUP BY ip ORDER BY cnt DESC LIMIT 20"
            );
            $topSt->execute([$campaign]);
            $topIps = $topSt->fetchAll();

            // Полный журнал с IP (последние 200)
            $journalSt = $pdo->prepare(
                "SELECT ip, device, mail_client, opened_at FROM email_opens WHERE campaign=? ORDER BY opened_at DESC LIMIT 200"
            );
            $journalSt->execute([$campaign]);
            $journal = $journalSt->fetchAll();

            // % IP из России (грубая оценка по первому октету)
            $ruSt = $pdo->prepare(
                "SELECT COUNT(*) FROM email_opens WHERE campaign=? AND ("
                . "ip LIKE '5.%' OR ip LIKE '31.%' OR ip LIKE '37.%' OR ip LIKE '46.%' OR "
                . "ip LIKE '77.%' OR ip LIKE '78.%' OR ip LIKE '79.%' OR ip LIKE '82.%' OR "
                . "ip LIKE '85.%' OR ip LIKE '87.%' OR ip LIKE '88.%' OR ip LIKE '89.%' OR "
                . "ip LIKE '90.%' OR ip LIKE '91.%' OR ip LIKE '92.%' OR ip LIKE '93.%' OR "
                . "ip LIKE '94.%' OR ip LIKE '95.%' OR ip LIKE '109.%' OR ip LIKE '128.%' OR "
                . "ip LIKE '176.%' OR ip LIKE '178.%' OR ip LIKE '185.%' OR ip LIKE '188.%' OR "
                . "ip LIKE '193.%' OR ip LIKE '194.%' OR ip LIKE '212.%' OR ip LIKE '213.%' OR "
                . "ip LIKE '217.%'"
                . ")"
            );
            $ruSt->execute([$campaign]);
            $ruCnt = (int)$ruSt->fetchColumn();
            $ruPct = $total > 0 ? round($ruCnt / $total * 100) : 0;

            echo json_encode([
                'ok'        => true,
                'campaign'  => $campaign,
                'total'     => $total,
                'by_day'    => $byDay->fetchAll(),
                'by_device' => $byDevice->fetchAll(),
                'by_client' => $byClient->fetchAll(),
                'unique_ips' => $uniqueIps,
                'repeat_openers' => $repeatOpeners,
                'ru_pct'    => $ruPct,
                'top_ips'   => $topIps,
                'journal'   => $journal,
            ], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode([
                'ok'        => true,
                'campaign'  => $campaign,
                'total'     => $total,
                'by_day'    => $byDay->fetchAll(),
                'by_device' => $byDevice->fetchAll(),
                'by_client' => $byClient->fetchAll(),
            ], JSON_UNESCAPED_UNICODE);
        }
    } else {
        $rows = $pdo->query(
            "SELECT campaign, COUNT(*) AS opens,
                    MIN(opened_at) AS first_open, MAX(opened_at) AS last_open
             FROM email_opens GROUP BY campaign ORDER BY last_open DESC"
        )->fetchAll();
        echo json_encode([
            'ok'        => true,
            'campaigns' => $rows,
            'total'     => array_sum(array_column($rows, 'opens')),
        ], JSON_UNESCAPED_UNICODE);
    }
} catch (Throwable $e) {
    http_response_code(500);
    error_log('email-stats-public: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Статистика временно недоступна']);
}
