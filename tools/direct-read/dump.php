<?php
declare(strict_types=1);
/**
 * ТОЛЬКО ЧТЕНИЕ Яндекс.Директа и списка конкурентов из CRM (разрешение владельца 10.10.2026).
 * Использует функции CRM (api/direct.php подключается как библиотека — роутер не выполняется)
 * и её токен. В Директе и в базе ничего не меняет: только методы get и отчёты.
 *
 *   php dump.php <public_html> > out.json
 *
 * Выход — JSON: кампании, все ключевые фразы, объявления (заголовки, тексты, ссылки — куда ведут),
 * поисковые запросы за 90 дней (без денег), список конкурентов с разбором.
 */
$root = rtrim($argv[1] ?? '', '/');
$_SERVER['SCRIPT_FILENAME'] = __FILE__;
require $root . '/api/direct.php';

$out = ['generated' => date('c'), 'errors' => []];
$try = function (string $name, callable $fn) use (&$out) {
    try { $out[$name] = $fn(); }
    catch (Throwable $e) { $out['errors'][$name] = substr($e->getMessage(), 0, 300); }
};

$try('campaigns', function () {
    $r = direct_call('campaigns', 'get', ['SelectionCriteria' => (object)[],
        'FieldNames' => ['Id', 'Name', 'State', 'Status', 'Type']]);
    return $r['Campaigns'] ?? [];
});
$ids = array_column($out['campaigns'] ?? [], 'Id');

$paged = function (string $service, string $key, array $params) use ($ids): array {
    $all = [];
    foreach (array_chunk($ids, 10) as $chunk) {
        $offset = 0;
        do {
            $p = $params;
            $p['SelectionCriteria'] = ['CampaignIds' => $chunk];
            $p['Page'] = ['Limit' => 10000, 'Offset' => $offset];
            $r = direct_call($service, 'get', $p);
            $all = array_merge($all, $r[$key] ?? []);
            $offset = $r['LimitedBy'] ?? null;
        } while ($offset);
    }
    return $all;
};

$try('keywords', fn() => $paged('keywords', 'Keywords',
    ['FieldNames' => ['Keyword', 'CampaignId', 'AdGroupId', 'State', 'Status']]));
$try('ads', fn() => $paged('ads', 'Ads',
    ['FieldNames' => ['CampaignId', 'AdGroupId', 'State'],
     'TextAdFieldNames' => ['Title', 'Title2', 'Text', 'Href']]));
$try('queries_90d', function () {
    $rows = direct_report(['CampaignName', 'Query', 'Impressions', 'Clicks', 'Conversions'],
                          'SEARCH_QUERY_PERFORMANCE_REPORT', 'LAST_90_DAYS');
    $items = [];
    foreach ($rows as $c) {
        if (count($c) < 5 || !is_numeric($c[2])) continue;
        $items[] = ['campaign' => $c[0], 'query' => $c[1], 'impr' => (int)$c[2],
                    'clicks' => (int)$c[3], 'conv' => is_numeric($c[4]) ? (float)$c[4] : 0];
    }
    return $items;
});
$try('competitors', fn() => direct_competitors());

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
