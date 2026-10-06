<?php
declare(strict_types=1);

/**
 * /api/seo.php — SEO-метрики для админки (требует авторизации).
 *
 *  action=psi       → Google PageSpeed Insights (Lighthouse) для site_url.
 *  action=webmaster → Сводка Яндекс.Вебмастера (SQI, исключённые страницы и пр.).
 *
 * Ключи/токены берутся из настроек CRM через secret()/setting().
 * PDO здесь не нужен — только cURL к внешним API. Все запросы с таймаутами.
 */

require_once __DIR__ . '/helpers.php';

require_auth();

/**
 * Универсальный GET-запрос cURL.
 * Возвращает [http_code:int, body:string, err:string].
 */
function seo_curl_get(string $url, array $headers = [], int $timeout = 25): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $headers),
        CURLOPT_USERAGENT      => 'zavod-red-crm/seo',
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return [$code, is_string($body) ? $body : '', $err];
}

$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');

try {
    switch ($action) {

        /* ---------------- PageSpeed Insights ---------------- */
        case 'psi': {
            $site = trim(secret('site_url'));
            if ($site === '') {
                json_out(['ok' => false, 'error' => 'Не задан адрес сайта (Настройки)']);
            }
            $params = [
                'url'      => $site,
                'strategy' => 'mobile',
                'category' => 'performance',
            ];
            $psiKey = trim(secret('psi_key'));
            if ($psiKey !== '') {
                $params['key'] = $psiKey; // без ключа тоже работает, но квота ниже
            }
            $url = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?' . http_build_query($params);

            [$code, $body, $err] = seo_curl_get($url, [], 60);
            if ($err !== '') {
                json_out(['ok' => false, 'error' => 'Ошибка соединения с PageSpeed: ' . $err]);
            }
            $data = json_decode($body, true);
            if (!is_array($data)) {
                json_out(['ok' => false, 'error' => 'PageSpeed вернул некорректный ответ (код ' . $code . ')']);
            }
            if ($code !== 200 || isset($data['error'])) {
                $msg = $data['error']['message'] ?? ('HTTP ' . $code);
                json_out(['ok' => false, 'error' => 'PageSpeed: ' . $msg]);
            }

            $lr     = $data['lighthouseResult'] ?? [];
            $perf   = $lr['categories']['performance']['score'] ?? null;
            $audits = $lr['audits'] ?? [];

            // Метрики Core Web Vitals (отображаемые значения, если есть).
            $metric = static function (array $audits, string $id): ?string {
                $a = $audits[$id] ?? null;
                if (!is_array($a)) return null;
                $v = $a['displayValue'] ?? null;
                return ($v !== null && $v !== '') ? (string)$v : null;
            };

            json_out([
                'ok'    => true,
                'score' => $perf !== null ? (int)round(((float)$perf) * 100) : null,
                'lcp'   => $metric($audits, 'largest-contentful-paint'),
                'cls'   => $metric($audits, 'cumulative-layout-shift'),
                'fcp'   => $metric($audits, 'first-contentful-paint'),
            ]);
        }

        /* ---------------- Яндекс.Вебмастер ---------------- */
        case 'webmaster': {
            $token = trim(ya_token_any('webmaster_token'));
            if ($token === '') {
                json_out(['ok' => false, 'error' => 'Не задан OAuth-токен Яндекса (Настройки → Интеграции)']);
            }
            $userId = trim(secret('webmaster_user_id'));
            $hostId = trim(secret('webmaster_host_id'));
            if ($userId === '' || $hostId === '') {
                // user_id и host_id узнаём у самого API один раз и запоминаем в настройках —
                // раньше страница просила пользователя сходить за ними curl'ом.
                $auth = ['Authorization: OAuth ' . $token];
                if ($userId === '') {
                    [$c, $b] = seo_curl_get('https://api.webmaster.yandex.net/v4/user/', $auth, 20);
                    $userId = (string)((json_decode($b, true) ?: [])['user_id'] ?? '');
                    if ($userId === '') json_out(['ok' => false, 'error' => 'Вебмастер не отдал user_id (HTTP ' . $c . '). Токену нужен scope webmaster:read']);
                    setting_set('webmaster_user_id', $userId);
                }
                if ($hostId === '') {
                    [$c, $b] = seo_curl_get('https://api.webmaster.yandex.net/v4/user/' . rawurlencode($userId) . '/hosts/', $auth, 20);
                    $hosts = (array)((json_decode($b, true) ?: [])['hosts'] ?? []);
                    $site = preg_replace('~^https?://|/$~', '', (string)setting('site_url', 'zavod-red.ru'));
                    foreach ($hosts as $hh) {
                        if (!empty($hh['verified']) && str_contains((string)($hh['unicode_host_url'] ?? $hh['host_id'] ?? ''), $site)) { $hostId = (string)$hh['host_id']; break; }
                    }
                    if ($hostId === '' && $hosts) $hostId = (string)($hosts[0]['host_id'] ?? '');
                    if ($hostId === '') json_out(['ok' => false, 'error' => 'В Вебмастере нет подтверждённых хостов (HTTP ' . $c . ')']);
                    setting_set('webmaster_host_id', $hostId);
                }
            }

            $url = 'https://api.webmaster.yandex.net/v4/user/'
                 . rawurlencode($userId) . '/hosts/'
                 . rawurlencode($hostId) . '/summary';

            [$code, $body, $err] = seo_curl_get($url, ['Authorization: OAuth ' . $token], 25);
            if ($err !== '') {
                json_out(['ok' => false, 'error' => 'Ошибка соединения с Вебмастером: ' . $err]);
            }
            $data = json_decode($body, true);
            if (!is_array($data)) {
                json_out(['ok' => false, 'error' => 'Вебмастер вернул некорректный ответ (код ' . $code . ')']);
            }
            if ($code !== 200 || isset($data['error_code']) || isset($data['error_message'])) {
                $msg = $data['error_message'] ?? ('HTTP ' . $code);
                json_out(['ok' => false, 'error' => 'Вебмастер: ' . $msg]);
            }

            // Распарсить то, что есть в summary (поля могут отсутствовать).
            $out = ['ok' => true];
            if (isset($data['sqi']))                     $out['sqi']             = (int)$data['sqi'];
            if (isset($data['excluded_pages_count']))    $out['excluded_pages']  = (int)$data['excluded_pages_count'];
            if (isset($data['searchable_pages_count']))  $out['searchable_pages'] = (int)$data['searchable_pages_count'];
            if (isset($data['site_problems']))           $out['site_problems']   = $data['site_problems'];

            json_out($out);
        }

        default:
            json_out(['ok' => false, 'error' => 'Неизвестное действие'], 400);
    }
} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => 'Внутренняя ошибка SEO-модуля'], 500);
}
