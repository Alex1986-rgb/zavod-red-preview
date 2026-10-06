<?php
declare(strict_types=1);
/**
 * Яндекс.Директ — мониторинг для админки (read-only).
 * Тянет данные через Direct API v5 (отчёты/кампании) и v4 Live (баланс) по cURL.
 * Токен — из настроек (secret('direct_token')). Ничего в аккаунте не меняет.
 *
 * Actions (auth required): overview | keywords | queries | balance
 * Ответы — JSON {ok, ...}.
 */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/llm.php';

const DIRECT_V5 = 'https://api.direct.yandex.com/json/v5/';
const DIRECT_V4 = 'https://api.direct.yandex.ru/live/v4/json/';

function direct_token(): string {
    // Единый OAuth Яндекса: сначала direct_token (Настройки→Интеграции),
    // затем ya_oauth_token (страница «Синхронизация с Яндексом») — один токен на оба места.
    return ya_token_any('direct_token', 'ya_oauth_token');
}

/**
 * Базовые заголовки v5. Для агентских (МСС) токенов Директ ОБЯЗАТЕЛЬНО требует
 * заголовок Client-Login с логином рекламодателя — без него агентский токен
 * не видит ни одной кампании клиента. Прямой рекламодатель логин не задаёт.
 */
function direct_v5_headers(string $token): array {
    $h = [
        'Authorization: Bearer ' . $token,
        'Accept-Language: ru',
        'Content-Type: application/json; charset=utf-8',
    ];
    $login = trim((string)secret('direct_client_login'));
    if ($login !== '') { $h[] = 'Client-Login: ' . $login; }
    return $h;
}

/** Вызов метода API v5 (JSON). Возвращает result или бросает исключение. */
function direct_call(string $service, string $method, array $params): array {
    $token = direct_token();
    if ($token === '') { throw new RuntimeException('Не задан токен Директа (Настройки → Интеграции)'); }
    $ch = curl_init(DIRECT_V5 . $service);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => direct_v5_headers($token),
        CURLOPT_POSTFIELDS => json_encode(['method' => $method, 'params' => $params], JSON_UNESCAPED_UNICODE),
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false) { throw new RuntimeException('Сеть: ' . $err); }
    $data = json_decode((string)$body, true);
    if (isset($data['error'])) {
        throw new RuntimeException('API ' . ($data['error']['error_code'] ?? '') . ': ' . ($data['error']['error_string'] ?? ''));
    }
    return $data['result'] ?? [];
}

/** Отчёт Direct API v5 → массив строк (каждая — массив полей). */
function direct_report(array $fields, string $reportType, string $days = 'LAST_30_DAYS'): array {
    $token = direct_token();
    if ($token === '') { throw new RuntimeException('Не задан токен Директа'); }
    $payload = ['params' => [
        'SelectionCriteria' => (object)[],
        'FieldNames'        => $fields,
        'ReportName'        => 'crm ' . $reportType . ' ' . time(),
        'ReportType'        => $reportType,
        'DateRangeType'     => $days,
        'Format'            => 'TSV',
        'IncludeVAT'        => 'YES',
    ]];
    $headers = array_merge(direct_v5_headers($token), [
        'processingMode: auto',
        'returnMoneyInMicros: false',
        'skipReportHeader: true',
        'skipReportSummary: true',
    ]);
    for ($i = 0; $i < 8; $i++) {
        $ch = curl_init(DIRECT_V5 . 'reports');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($code === 200) {
            $rows = [];
            foreach (explode("\n", (string)$body) as $line) {
                $line = rtrim($line, "\r");
                if ($line === '') continue;
                $rows[] = explode("\t", $line);
            }
            return $rows;
        }
        if ($code === 201 || $code === 202) { sleep(4); continue; }
        throw new RuntimeException('Отчёт HTTP ' . $code);
    }
    // Отчёт всё ещё формируется (201/202 после всех попыток) — это НЕ «пусто»,
    // а таймаут. Бросаем исключение, чтобы вызывающий показал предупреждение
    // «обновите позже», а не молча нулевые показы/расход.
    throw new RuntimeException('Отчёт Директа ещё формируется — обновите страницу через 20–30 сек');
}

/**
 * Все кампании аккаунта, включая АРХИВНЫЕ. Пустой SelectionCriteria у
 * campaigns.get по умолчанию НЕ возвращает архивные — из-за этого часть
 * кампаний «не подгружалась». Явно перечисляем все состояния + разбираем
 * постраничную выдачу (LimitedBy), иначе при >каждой странице часть теряется.
 */
function direct_campaign_states(): array {
    $states = [];
    $offset = 0;
    for ($guard = 0; $guard < 50; $guard++) { // 50×10000 = 500k кампаний — заведомо больше любого аккаунта
        $r = direct_call('campaigns', 'get', [
            'SelectionCriteria' => [
                'States' => ['ON','OFF','SUSPENDED','ENDED','CONVERTED','ARCHIVED'],
            ],
            'FieldNames' => ['Id','Name','State','Status','Type'],
            'TextCampaignFieldNames' => ['BiddingStrategy'],
            'Page' => ['Limit' => 10000, 'Offset' => $offset],
        ]);
        foreach ($r['Campaigns'] ?? [] as $c) { $states[(string)$c['Id']] = $c; }
        $limitedBy = $r['LimitedBy'] ?? null;
        if ($limitedBy === null) break;
        $offset = (int)$limitedBy;
    }
    return $states;
}

// Модель оплаты кампании по её стратегии: 'conv' — за конверсии (клики бесплатны,
// списание только за заявку), 'click' — за клики (каждый клик платный), '' — неизвестно.
function direct_pay_model(array $c): string {
    $b = $c['TextCampaign']['BiddingStrategy'] ?? [];
    $types = [(string)($b['Search']['BiddingStrategyType'] ?? ''),
              (string)($b['Network']['BiddingStrategyType'] ?? '')];
    foreach ($types as $t) { // за конверсии/CPA — приоритетно
        if (strpos($t, 'PAY_FOR_CONVERSION') !== false || $t === 'AVERAGE_CPA') return 'conv';
    }
    foreach ($types as $t) { // иначе — реальная клик-стратегия (не выключенный канал)
        if ($t !== '' && $t !== 'SERVING_OFF') return 'click';
    }
    return '';
}

/**
 * Логин рекламного кабинета, которому принадлежит токен. Позволяет оператору
 * сразу увидеть, ТОТ ли это аккаунт: если в кабинете новые кампании, а тут
 * показан старый логин — значит токен выписан не из того аккаунта.
 */
function direct_account_login(): string {
    try {
        $r = direct_call('clients', 'get', ['FieldNames' => ['Login']]);
        return (string)($r['Clients'][0]['Login'] ?? '');
    } catch (Throwable $e) { return ''; }
}

function direct_overview(string $days): array {
    // Нет токена — это НЕ «мягкий сбой»: показываем понятную причину, а не пустую
    // таблицу с общими предупреждениями (иначе оператор не поймёт, что делать).
    if (direct_token() === '') { throw new RuntimeException('Не задан токен Директа (Настройки → Интеграции)'); }

    $reportFailed = false; $reportErr = '';
    try {
        $rows = direct_report(['CampaignId','CampaignName','Impressions','Clicks','Cost','Conversions'],
                              'CAMPAIGN_PERFORMANCE_REPORT', $days);
    } catch (Throwable $e) { $rows = []; $reportFailed = true; $reportErr = $e->getMessage(); }
    // состояния кампаний (включая архивные, с пагинацией)
    $states = [];
    $statesFailed = false; $statesErr = '';
    try {
        $states = direct_campaign_states();
    } catch (Throwable $e) { $statesFailed = true; $statesErr = $e->getMessage(); }
    // Оба канала упали при наличии токена → это жёсткая ошибка (неверный токен,
    // нет доступа, сеть). Показываем реальную причину, а не «нет данных».
    if ($reportFailed && $statesFailed) {
        throw new RuntimeException($statesErr ?: $reportErr ?: 'Директ недоступен');
    }
    $out = []; $tot = ['impr'=>0.0,'clicks'=>0.0,'cost'=>0.0,'conv'=>0.0,'campaigns'=>0,'active'=>0];
    $seen = [];
    foreach ($rows as $c) {
        if (count($c) < 6) continue;
        [$cid,$name,$impr,$clicks,$cost,$conv] = $c;
        if (!is_numeric($clicks) || !is_numeric($conv)) continue; // пропустить строку-заголовок TSV
        $seen[$cid] = true;
        $st = $states[$cid] ?? [];
        $conv = (float)$conv; $cost = (float)$cost; $clicks = (float)$clicks; $impr = (float)$impr;
        $out[] = ['cid'=>$cid,'name'=>$name,'state'=>$st['State'] ?? '?','status'=>$st['Status'] ?? '?',
                  'pay'=>direct_pay_model($st),
                  'impr'=>$impr,'clicks'=>$clicks,'ctr'=>$impr > 0 ? round($clicks/$impr*100,2) : 0.0,
                  'cost'=>round($cost,2),'cpc'=>$clicks > 0 ? round($cost/$clicks,2) : 0.0,'conv'=>$conv,
                  'cpa'=>$conv > 0 ? round($cost/$conv) : null];
        $tot['impr']+=$impr; $tot['clicks']+=$clicks; $tot['cost']+=$cost; $tot['conv']+=$conv;
    }
    foreach ($states as $cid=>$st) {
        if (!isset($seen[$cid])) {
            $out[] = ['cid'=>$cid,'name'=>$st['Name'] ?? '','state'=>$st['State'] ?? '?',
                      'status'=>$st['Status'] ?? '?','pay'=>direct_pay_model($st),
                      'impr'=>0,'clicks'=>0,'ctr'=>0,'cost'=>0,'cpc'=>0,'conv'=>0,'cpa'=>null];
        }
    }
    // Активные и результативные — вверху; архивные (справочные) — в самом низу.
    usort($out, function($a,$b){
        $aa = $a['state']==='ARCHIVED' ? 1 : 0;
        $ab = $b['state']==='ARCHIVED' ? 1 : 0;
        if ($aa !== $ab) return $aa <=> $ab;
        return $b['cost'] <=> $a['cost'];
    });
    $tot['cost'] = round($tot['cost'],2);
    $tot['campaigns'] = count($out);
    $tot['active'] = count(array_filter($out, fn($r)=>$r['state']==='ON'));
    $tot['archived'] = count(array_filter($out, fn($r)=>$r['state']==='ARCHIVED'));
    $tot['cpa'] = $tot['conv'] > 0 ? round($tot['cost']/$tot['conv']) : null;
    $tot['ctr'] = $tot['impr'] > 0 ? round($tot['clicks']/$tot['impr']*100,2) : 0.0;
    $tot['cpc'] = $tot['clicks'] > 0 ? round($tot['cost']/$tot['clicks'],2) : 0.0;
    // Разбивка по статусам и типам — сразу видно, есть ли вообще новые/активные
    // кампании и не «прячет» ли их фильтр типов (напр. ЕПК — новый тип).
    $byState = []; $byType = [];
    foreach ($states as $c) {
        $s = (string)($c['State'] ?? '?'); $byState[$s] = ($byState[$s] ?? 0) + 1;
        $t = (string)($c['Type'] ?? '?');  $byType[$t]  = ($byType[$t] ?? 0) + 1;
    }
    // Диагностика — чтобы на странице было видно, ПОЧЕМУ кампаний столько
    // и ТОТ ЛИ это аккаунт (логин кабинета, которому принадлежит токен).
    $diag = [
        'report_failed'  => $reportFailed,   // отчёт-статистика не пришёл (таймаут/ошибка) → цифры по нулям
        'states_failed'  => $statesFailed,    // список кампаний не пришёл → показаны только с активностью
        'in_report'      => count($seen),     // кампаний с активностью за период
        'in_account'     => count($states),   // всего кампаний в аккаунте (вкл. архивные)
        'client_login'   => trim((string)secret('direct_client_login')) !== '' ? 'задан' : 'нет (прямой аккаунт)',
        'account'        => direct_account_login(),  // логин кабинета токена — ТОТ ли аккаунт
        'by_state'       => $byState,
        'by_type'        => $byType,
    ];
    return ['totals'=>$tot,'campaigns'=>$out,'diag'=>$diag];
}

function direct_keywords(string $days): array {
    $rows = direct_report(['CampaignName','Criterion','Clicks','Cost','Conversions'],
                          'CRITERIA_PERFORMANCE_REPORT', $days);
    $items = [];
    foreach ($rows as $c) {
        if (count($c) < 5 || !is_numeric($c[2]) || !is_numeric($c[4])) continue;
        $items[] = ['campaign'=>$c[0],'keyword'=>$c[1],'clicks'=>(float)$c[2],
                    'cost'=>round((float)$c[3],2),'conv'=>(float)$c[4]];
    }
    usort($items, fn($a,$b) => [$b['conv'],$b['clicks']] <=> [$a['conv'],$a['clicks']]);
    $converting = array_values(array_filter($items, fn($x)=>$x['conv']>0));
    return ['top'=>array_slice($items,0,40),'converting'=>array_slice($converting,0,40),
            'total'=>count($items),'with_conversions'=>count($converting)];
}

function direct_queries(string $days): array {
    $rows = direct_report(['CampaignName','Query','Clicks','Cost','Conversions'],
                          'SEARCH_QUERY_PERFORMANCE_REPORT', $days);
    $items = [];
    foreach ($rows as $c) {
        if (count($c) < 5 || !is_numeric($c[2]) || !is_numeric($c[4])) continue;
        $items[] = ['campaign'=>$c[0],'query'=>$c[1],'clicks'=>(float)$c[2],
                    'cost'=>round((float)$c[3],2),'conv'=>(float)$c[4]];
    }
    $junk = array_values(array_filter($items, fn($x)=>$x['conv']==0 && $x['clicks']>=6));
    usort($junk, fn($a,$b) => $b['clicks'] <=> $a['clicks']);
    $conv = array_values(array_filter($items, fn($x)=>$x['conv']>0));
    usort($conv, fn($a,$b) => $b['conv'] <=> $a['conv']);
    return ['total'=>count($items),'junk'=>array_slice($junk,0,40),
            'converting'=>array_slice($conv,0,30)];
}

function direct_balance(): array {
    $token = direct_token();
    if ($token === '') { throw new RuntimeException('Не задан токен Директа'); }
    $login = secret('direct_client_login');
    $body = ['method'=>'AccountManagement','token'=>$token,
             'param'=>['Action'=>'Get','SelectionCriteria'=>$login ? ['Logins'=>[$login]] : (object)[]]];
    $ch = curl_init(DIRECT_V4);
    curl_setopt_array($ch, [
        CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>40,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
        CURLOPT_POSTFIELDS=>json_encode($body, JSON_UNESCAPED_UNICODE),
    ]);
    $resp = curl_exec($ch); curl_close($ch);
    $data = json_decode((string)$resp, true);
    if (isset($data['error_code'])) { throw new RuntimeException($data['error_str'] ?? 'Ошибка v4'); }
    $acc = $data['data']['Accounts'][0] ?? null;
    if (!$acc) { throw new RuntimeException('нет данных по счёту'); }
    return ['amount'=>$acc['Amount'] ?? null, 'currency'=>$acc['Currency'] ?? 'RUB'];
}

/** Минимальный вызов Claude (переиспользуем интеграцию CRM, ключ anthropic_api_key). */
function direct_claude(string $system, string $user, int $maxTokens = 1500): string {
    return llm_call($system, $user, $maxTokens);
}

/** Сегментация конверсий: устройства + регионы (read-only). */
function direct_segmentation(string $days): array {
    $out = [];
    foreach (['device'=>'Device','region'=>'LocationOfPresenceName'] as $k=>$field) {
        try {
            $rows = direct_report([$field,'Clicks','Conversions'], 'CUSTOM_REPORT', $days);
            $seg = [];
            foreach ($rows as $c) {
                if (count($c) < 3 || !is_numeric($c[1]) || !is_numeric($c[2])) continue;
                $seg[] = ['label'=>$c[0],'clicks'=>(float)$c[1],'conv'=>(float)$c[2]];
            }
            usort($seg, fn($a,$b)=>$b['conv']<=>$a['conv']);
            $out[$k] = array_slice($seg,0,20);
        } catch (Throwable $e) { $out[$k] = ['error'=>substr($e->getMessage(),0,80)]; }
    }
    return $out;
}

/** Конкуренты — список/добавление/удаление (crm_settings.direct_competitors = JSON). */
function direct_competitors(): array {
    $raw = secret('direct_competitors', '[]');
    $arr = json_decode($raw, true);
    return is_array($arr) ? $arr : [];
}
function direct_competitors_save(array $list): void {
    $st = pdo()->prepare('INSERT INTO crm_settings (skey,sval) VALUES (?,?) ON DUPLICATE KEY UPDATE sval=VALUES(sval)');
    $st->execute(['direct_competitors', json_encode(array_values($list), JSON_UNESCAPED_UNICODE)]);
}

/** Анализ сайта конкурента через Claude. */
function direct_competitor_analyze(string $domain): array {
    $url = 'https://' . preg_replace('~^https?://~i', '', $domain);
    $ip = gethostbyname((string)parse_url($url, PHP_URL_HOST));
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return ['domain'=>$domain,'analysis'=>'Домен указывает на внутренний адрес — анализ не выполнен.'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>3,
        CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,
        CURLOPT_USERAGENT=>'Mozilla/5.0 (compatible; CRM/1.0)']);
    $html = (string)curl_exec($ch);
    $finalIp = (string)curl_getinfo($ch, CURLINFO_PRIMARY_IP); curl_close($ch);
    // редирект мог увести во внутреннюю сеть — такой ответ выбрасываем
    if ($finalIp !== '' && !filter_var($finalIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) $html = '';
    $text = trim(preg_replace('/\s+/u',' ', strip_tags(preg_replace('/<(script|style)[^>]*>.*?<\/\1>/is',' ',$html))));
    $text = mb_substr($text, 0, 6000);
    $sys = 'Ты — специалист по контекстной рекламе (редукторы, мотор-редукторы). Анализируй кратко, по-русски, верни markdown.';
    $u = "Проанализируй конкурента {$domain}. Текст с сайта:\n\"\"\"{$text}\"\"\"\n\nДай: 1) их товары/категории, 2) вероятные ключи, 3) их УТП, 4) гэпы для нас (чего у них нет), 5) 2-3 идеи объявлений, чтобы переиграть. Коротко.";
    return ['domain'=>$domain,'analysis'=>direct_claude($sys, $u, 1200)];
}

/** Медиаплан-стратег через Claude. */
function direct_strategist(string $goal, int $budget, string $geo): string {
    $sys = 'Ты — стратег по Яндекс.Директу для zavod-red.ru (редукторы и мотор-редукторы). Отвечай по-русски, структурировано (markdown).';
    $u = "Составь медиаплан. Цель: {$goal}. Дневной бюджет: {$budget} ₽. География: {$geo}.\n".
         "Дай: структуру кампаний (поиск/РСЯ), группы, по 5-8 ключей на группу, 2 варианта объявлений, минус-слова, распределение бюджета, ожидания. Реалистично, 3-5 кампаний.";
    return direct_claude($sys, $u, 2500);
}

/** Генерация баннера через OpenAI DALL·E (ключ openai_api_key). */
function direct_creative(string $prompt): array {
    $key = secret('openai_api_key');
    if ($key === '') { throw new RuntimeException('Задайте ключ OpenAI в Настройках (для генерации картинок)'); }
    $body = ['model'=>'dall-e-3','prompt'=>$prompt,'n'=>1,'size'=>'1024x1024','response_format'=>'b64_json'];
    $ch = curl_init('https://api.openai.com/v1/images/generations');
    curl_setopt_array($ch, [CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>120,
        CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$key,'Content-Type: application/json'],
        CURLOPT_POSTFIELDS=>json_encode($body)]);
    $resp = curl_exec($ch); curl_close($ch);
    $d = json_decode((string)$resp, true);
    if (isset($d['error'])) { throw new RuntimeException('OpenAI: '.($d['error']['message'] ?? '')); }
    $b64 = $d['data'][0]['b64_json'] ?? '';
    if ($b64 === '') { throw new RuntimeException('Пустой ответ OpenAI'); }
    return ['image'=>'data:image/png;base64,'.$b64];
}

// ---------------- роутер ----------------
// Если файл подключён как библиотека (include) — только функции, без выполнения роутера.
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) !== realpath(__FILE__)) {
    return;
}
$action = $_GET['action'] ?? '';
$days = preg_match('/^[A-Z_0-9]+$/', (string)($_GET['days'] ?? '')) ? $_GET['days'] : 'LAST_30_DAYS';
$in = json_decode(file_get_contents('php://input') ?: '[]', true) ?: $_POST;
try {
    switch ($action) {
        // read-only
        case 'overview':     require_auth(); json_out(['ok'=>true] + direct_overview($days));
        case 'keywords':     require_auth(); json_out(['ok'=>true] + direct_keywords($days));
        case 'queries':      require_auth(); json_out(['ok'=>true] + direct_queries($days));
        case 'balance':      require_auth(); json_out(['ok'=>true,'balance'=>direct_balance()]);
        case 'segmentation': require_auth(); json_out(['ok'=>true,'seg'=>direct_segmentation($days)]);
        // конкуренты
        case 'competitors':  require_auth(); json_out(['ok'=>true,'items'=>direct_competitors()]);
        case 'competitor_add': require_auth(); csrf_check();
            $dom = mb_strtolower(trim((string)($in['domain'] ?? '')));
            if ($dom==='') json_out(['ok'=>false,'error'=>'пустой домен'],400);
            if (!preg_match('/^[a-z0-9.-]{3,80}$/', $dom)) json_out(['ok'=>false,'error'=>'некорректный домен (допустимы латиница, цифры, точка, дефис; 3–80 символов)'],400);
            $list = direct_competitors(); $list[] = ['domain'=>$dom,'analysis'=>null]; direct_competitors_save($list);
            json_out(['ok'=>true,'items'=>$list]);
        case 'competitor_remove': require_auth(); csrf_check();
            $dom = trim((string)($in['domain'] ?? ''));
            $list = array_filter(direct_competitors(), fn($c)=>$c['domain']!==$dom); direct_competitors_save($list);
            json_out(['ok'=>true,'items'=>array_values($list)]);
        case 'competitor_analyze': require_auth(); csrf_check();
            $dom = mb_strtolower(trim((string)($in['domain'] ?? '')));
            // Анализируем только то, что уже в списке конкурентов и прошло проверку домена:
            // иначе сервер ходил curl'ом по любому адресу из запроса (SSRF во внутреннюю сеть).
            if (!preg_match('/^[a-z0-9.-]{3,80}$/', $dom) || !in_array($dom, array_column(direct_competitors(), 'domain'), true)) {
                json_out(['ok'=>false,'error'=>'Сначала добавьте домен в список конкурентов'],400);
            }
            $res = direct_competitor_analyze($dom);
            $list = direct_competitors();
            foreach ($list as &$c) { if ($c['domain']===$dom) $c['analysis']=$res['analysis']; } unset($c);
            direct_competitors_save($list);
            json_out(['ok'=>true] + $res);
        // ИИ
        case 'strategist': require_auth(); csrf_check();
            json_out(['ok'=>true,'plan'=>direct_strategist(
                trim((string)($in['goal'] ?? 'максимум заявок')),
                (int)($in['budget'] ?? 1000), trim((string)($in['geo'] ?? 'Россия')))]);
        case 'creative': require_auth(); csrf_check();
            json_out(['ok'=>true] + direct_creative(trim((string)($in['prompt'] ?? 'индустриальный баннер редукторов, завод, без текста'))));
        default: json_out(['ok'=>false,'error'=>'Неизвестное действие'], 400);
    }
} catch (Throwable $e) {
    json_out(['ok'=>false,'error'=>$e->getMessage()], 500);
}
