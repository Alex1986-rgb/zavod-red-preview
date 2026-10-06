<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/site_kb.php';   // справочник моделей с сайта: 20 марок, 754 серии

date_default_timezone_set(cfg()['timezone'] ?? 'Europe/Moscow');

// Прод: не выводить ошибки/варнинги в ответ (иначе ломают JSON AJAX); только в лог.
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

/** JSON-ответ и выход. */
function json_out(array $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => (($_SERVER['HTTPS'] ?? '') === 'on'),
    ]);
    session_start();
}

/** Текущий пользователь из сессии или null. */
function current_user(): ?array {
    if (isset($_SERVER['__user'])) return $_SERVER['__user']; // внутренний вызов (не сессия)
    start_session();
    $u = $_SESSION['user'] ?? null;
    if ($u === null) return null;
    // Таймаут: 8ч бездействия ИЛИ 12ч с момента логина (CRM с ПДн — не держим сессию вечно).
    $now   = time();
    $last  = (int)($_SESSION['last_activity'] ?? $now);
    $login = (int)($_SESSION['auth_ts'] ?? $now);
    if (($now - $last) > 8 * 3600 || ($now - $login) > 12 * 3600) {
        $_SESSION = [];
        session_destroy();
        return null;
    }
    $_SESSION['last_activity'] = $now;
    // Раз за запрос сверяем учётку с БД: деактивация и смена роли в Настройках раньше
    // не действовали на уже вошедшего до 12 часов.
    static $checked = null;
    if ($checked === null) {
        $checked = $u;
        try {
            $pdo = pdo();
            if ($pdo) {
                $st = $pdo->prepare('SELECT role, name, active FROM crm_users WHERE id = ? LIMIT 1');
                $st->execute([(int)($u['id'] ?? 0)]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if (!$row || (int)$row['active'] !== 1) {
                    $_SESSION = [];
                    session_destroy();
                    return $checked = null;
                }
                $checked['role'] = (string)$row['role'];
                $checked['name'] = (string)$row['name'];
                $_SESSION['user'] = $checked;
            }
        } catch (Throwable $e) { /* нет БД — не выбиваем, работаем по сессии */ }
    }
    return $checked;
}

/** Требует авторизацию. Для API → 401 JSON. role: null|'admin'. */
function require_auth(?string $role = null): array {
    $u = current_user();
    if (!$u) json_out(['ok' => false, 'error' => 'Требуется авторизация'], 401);
    if ($role === 'admin' && ($u['role'] ?? '') !== 'admin') {
        json_out(['ok' => false, 'error' => 'Недостаточно прав'], 403);
    }
    return $u;
}

/** Гард для HTML-страниц админки: редирект на логин. */
function require_auth_html(?string $role = null): array {
    $u = current_user();
    if (!$u) { header('Location: login.php'); exit; }
    if ($role === 'admin' && ($u['role'] ?? '') !== 'admin') { http_response_code(403); echo 'Недостаточно прав'; exit; }
    return $u;
}

function csrf_token(): string {
    start_session();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

/** Проверка CSRF (из POST['csrf'] или заголовка X-CSRF). */
function csrf_check(): void {
    start_session();
    $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? '');
    if (!$sent || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)$sent)) {
        json_out(['ok' => false, 'error' => 'Неверный CSRF-токен'], 419);
    }
}

/**
 * Значение для CSV: строка с сайта, начинающаяся с = + - @, в Excel станет формулой
 * (CSV-инъекция). Телефоны вида «+7 (495) …» не трогаем — это не формула.
 */
function csv_safe(?string $v): string {
    $v = (string)$v;
    if ($v !== '' && preg_match('/^[=+\-@\t\r]/', $v) && !preg_match('/^[+\d\s()\-]+$/', $v)) return "'" . $v;
    return $v;
}

function client_ip(): string {
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    // За доверенным локальным прокси (nginx→Apache) REMOTE_ADDR = адрес прокси, а реальный
    // клиент — в X-Forwarded-For. Берём ПОСЛЕДНИЙ (правый) элемент XFF: это хоп, с которого
    // прокси реально принял соединение; левые элементы клиент может подделать, правый — нет.
    // Если REMOTE_ADDR не локальный прокси — доверяем ему напрямую (XFF игнорируем).
    $isLocalProxy = $remote === '127.0.0.1' || $remote === '::1'
        || str_starts_with($remote, '10.') || str_starts_with($remote, '192.168.')
        || preg_match('/^172\.(1[6-9]|2\d|3[01])\./', $remote) === 1;
    if ($isLocalProxy && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = array_map('trim', explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']));
        $last  = end($parts);
        if ($last !== false && filter_var($last, FILTER_VALIDATE_IP)) return $last;
    }
    return $remote;
}

function sanitize(string $s): string {
    return htmlspecialchars(trim($s), ENT_QUOTES, 'UTF-8');
}

/** Извлечь utm/source-поля из массива (обычно $_POST). */
function parse_utm(array $src): array {
    $keys = ['utm_source','utm_medium','utm_campaign','utm_term','utm_content','gclid','yclid'];
    $out = [];
    foreach ($keys as $k) $out[$k] = isset($src[$k]) ? substr(sanitize((string)$src[$k]), 0, 255) : '';
    return $out;
}

/**
 * Простой файловый rate-limit. true = разрешено, false = превышен лимит.
 * $max обращений за $win секунд для ключа $key.
 */
function rate_limit(string $key, int $max, int $win): bool {
    $dir = sys_get_temp_dir() . '/zr_rl';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $f = $dir . '/' . md5($key) . '.json';
    $now = time();
    $hits = [];
    if (is_file($f)) {
        $hits = json_decode((string)@file_get_contents($f), true) ?: [];
        $hits = array_values(array_filter($hits, fn($t) => $t > $now - $win));
    }
    if (count($hits) >= $max) return false;
    $hits[] = $now;
    @file_put_contents($f, json_encode($hits), LOCK_EX);
    return true;
}

/** Запись события в журнал активности crm_events. */
function audit(?int $leadId, ?int $userId, string $type, array $payload = []): void {
    try {
        $st = pdo()->prepare(
            'INSERT INTO crm_events (lead_id, user_id, type, payload, created_at) VALUES (?,?,?,?,NOW())'
        );
        $st->execute([$leadId, $userId, $type, $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null]);
    } catch (Throwable $e) { /* журнал не должен ломать основной поток */ }
}

/**
 * Единая отправка уведомления на email: сперва SMTP (надёжно), при неудаче/
 * отсутствии настроек — фолбэк на PHP mail(). Возвращает ['ok'=>bool,'via'=>'smtp'|'mail'].
 * Используется приёмом заявок (feedback.php) и общим приёмником (inbox.php).
 */

/**
 * Адрес, которым сервер подписывает письма (заголовок From).
 *
 * Доказано запросом DNS: SPF домена zavod-red.ru разрешает отправку ТОЛЬКО серверам
 * Яндекса (v=spf1 redirect=_spf.yandex.net), а сайт стоит на Timeweb. Письма от такого
 * адреса Яндекс грейлистит — они доходят с задержкой в десятки минут или теряются.
 * Домен zr-zavod-red.ru наш хостинг разрешает (include:_spf.timeweb.ru), письма с него
 * проходят проверку сразу.
 *
 * Приоритет: явная настройка в админке → конфиг (если он НЕ на проблемном домене) →
 * рабочий адрес по умолчанию. Правильное решение — SMTP через Яндекс: тогда подпись
 * снова станет zr@zavod-red.ru и появится DKIM.
 */
function zr_mail_from(): string {
    // Кандидат: явная настройка админки → конфиг → пусто.
    $from = trim((string)secret('mail_from', ''));
    if ($from === '') $from = trim((string)(cfg()['mail']['from'] ?? ''));
    // ЗАЩИТА ОТ ПОТЕРИ ПИСЕМ: этот адрес — конверт-отправитель для mail(), а mail()
    // выполняется на сервере Timeweb. SPF домена zavod-red.ru разрешает отправку ТОЛЬКО
    // Яндексу (redirect=_spf.yandex.net), поэтому письмо с адреса @zavod-red.ru через
    // mail() отбивается по SPF и НЕ доходит (проверено боевым __diag: mail()→zr@zavod-red.ru
    // не доставлялось). Принудительно берём адрес на zr-zavod-red.ru — его SPF разрешает
    // Timeweb (include:_spf.timeweb.ru), письмо проходит сразу. Настройку админки уважаем
    // ТОЛЬКО если она не на «яндексовом» домене. Полноценная подпись zr@zavod-red.ru
    // вернётся, когда заработает SMTP через Яндекс (там отправляет сам Яндекс, SPF ок).
    // ПОДМЕНА СНЯТА 28.09.2026. Она стояла, пока сайт жил на Timeweb: SPF домена
    // zavod-red.ru разрешает отправку только Яндексу, а письма уходили с сервера
    // Timeweb — и адрес @zavod-red.ru принудительно заменялся на lead@infozr-crm.ru,
    // чей SPF Timeweb разрешал. Комментарий выше прямо оговаривал: вернуть родной
    // адрес, когда заработает SMTP через Яндекс. Он заработал — в настройках стоит
    // smtp.yandex.ru с паролем приложения, письмо отправляет сам Яндекс, SPF и DKIM
    // сходятся. Теперь уважаем то, что задано в админке; запасной адрес остаётся
    // только на случай, когда настройка пуста.
    if ($from === '') {
        // Отправитель заявок (по просьбе заказчика). Домен-отправитель на Timeweb
        // (его SPF разрешает сервер сайта) И должен иметь DKIM, иначе Яндекс → спам.
        // ВАЖНО: mail-zavod-red.ru работает без спама ТОЛЬКО после включения DKIM на
        // этом домене. Reply-To остаётся zr@zavod-red.ru, письмо приходит туда же.
        return 'lead@infozr-crm.ru';
    }
    return $from;
}

function notify_email_send(string $to, string $subject, string $html, string $replyToOverride = '', bool $internal = false): array {
    require_once __DIR__ . '/mail.php';
    // $internal — служебное уведомление нам (о заявке): отдельный ящик-отправитель и Reply-To клиента.
    if (function_exists('smtp_send') && smtp_send($to, $subject, $html, true, ['reply_to' => $replyToOverride, 'internal' => $internal])) {
        return ['ok' => true, 'via' => 'smtp', 'error' => ''];
    }
    // Причину отказа SMTP сохраняем: раньше она терялась, и «письмо не дошло»
    // было неотличимо от «письмо ушло». Дальше пробуем mail() как запасной путь.
    $smtpErr = function_exists('smtp_last_error') ? smtp_last_error() : '';
    // Отправитель настраивается из админки (mail_from). Это важно: SPF домена
    // zavod-red.ru разрешает отправку ТОЛЬКО Яндексу, а сайт стоит на Timeweb —
    // письма от no-reply@zavod-red.ru отбрасываются. Адрес на домене, чей SPF
    // разрешает Timeweb (zr-zavod-red.ru), проходит проверку и доставляется.
    $from = zr_mail_from();
    // ВНИМАНИЕ: From держим ГОЛЫМ адресом, без отображаемого имени.
    // На shared-хостинге mail() с «Имя <адрес>» отвергается (конверт-отправитель
    // перестаёт совпадать с разрешённым) — письма переставали доходить вообще.
    // Reply-To — почта КЛИЕНТА, если он её оставил. Раньше здесь всегда стоял наш же
    // ящик приёма (zr@zavod-red.ru): «Ответить» уводило письмо самому себе, а Битрикс,
    // определяя собеседника по Reply-To, видел собственный адрес и считал переписку
    // внутренней — лид из заявки не создавался. Фолбэк на прежний адрес сохраняем для
    // заявок без почты (там отвечать по e-mail всё равно некому).
    // Фолбэк — САМ ОТПРАВИТЕЛЬ, а не ящик приёма: заявка часто приходит без почты
    // клиента (только телефон), и Reply-To на наш же ящик снова выглядел бы для
    // Битрикса перепиской с самим собой.
    $replyTo = trim($replyToOverride) !== '' && filter_var(trim($replyToOverride), FILTER_VALIDATE_EMAIL)
             ? trim($replyToOverride)
             : $from;
    // Date + Message-ID ОБЯЗАТЕЛЬНЫ: без них письмо неполноценно, и почтовые-в-CRM
    // парсеры (Битрикс24) не опознают входящее как новое обращение → лид не создаётся,
    // хотя обычные (пересланные) письма с этими заголовками заводятся нормально.
    // Домен Message-ID берём из адреса отправителя, чтобы совпадал с From (выравнивание).
    $msgDom  = (strpos($from, '@') !== false) ? substr(strrchr($from, '@'), 1) : 'zavod-red.ru';
    $headers = "MIME-Version: 1.0\r\nDate: " . date('r') . "\r\n"
             . "From: {$from}\r\n"
             . "Reply-To: {$replyTo}\r\n"
             . "Message-ID: <" . bin2hex(random_bytes(12)) . "@{$msgDom}>\r\n"
             . "Content-Type: text/html; charset=UTF-8\r\n";
    // Тема с кириллицей ОБЯЗАНА быть закодирована по RFC 2047, иначе почтовые
    // сервисы её отбрасывают — заявки приходили с пометкой «(Без темы)» и терялись
    // среди обычной почты. smtp_send() кодирует, а этот фолбэк раньше — нет.
    $encSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    // КОНВЕРТ-ОТПРАВИТЕЛЬ (17.08.2026). Пятого аргумента здесь не было, и mail()
    // подставляла в конверт системный адрес хостинга (вида cu549563@vh434.timeweb.ru),
    // тогда как в From стоит lead@infozr-crm.ru. Домены не совпадали, из-за чего:
    //   • SPF проверялся для ЧУЖОГО домена, а не для нашего;
    //   • выравнивание (DMARC alignment) не проходило в принципе.
    // Для Яндекса, куда идёт ящик получателя (MX mx.yandex.net), такое письмо —
    // почти гарантированный спам. Передаём -f, чтобы конверт совпал с From:
    // SPF домена infozr-crm.ru разрешает Timeweb (include:_spf.timeweb.ru), и проверка
    // проходит по нашему домену. Адрес прогоняем через escapeshellarg: он приходит
    // из настройки в админке, и без экранирования это дыра в командную строку.
    $envelope = filter_var($from, FILTER_VALIDATE_EMAIL) ? '-f' . escapeshellarg($from) : '';
    $ok = $envelope !== ''
        ? @mail($to, $encSubject, $html, $headers, $envelope)
        : @mail($to, $encSubject, $html, $headers);
    return [
        'ok'    => (bool)$ok,
        // mail() лишь ПРИНИМАЕТ письмо в очередь — это не доставка. Помечаем честно,
        // чтобы статистика не рисовала 100% успеха там, где письма не доходят.
        'via'   => $ok ? 'mail (принято в очередь, доставка не гарантирована)' : 'нет',
        'error' => $smtpErr !== '' ? ('SMTP: ' . $smtpErr) : ($ok ? '' : 'mail() вернул false'),
    ];
}

/**
 * Секрет/настройка интеграции: сперва из crm_settings (вводится в админке),
 * иначе fallback (обычно из config.php). Значения '' и 'CHANGE_ME' считаются пустыми.
 */
function secret(string $key, string $fallback = ''): string {
    $v = setting($key, null);
    if ($v !== null && $v !== '' && $v !== 'CHANGE_ME') return $v;
    return $fallback;
}

/**
 * OAuth-токен Яндекса: один токен со scope direct:api + metrika:read/write + webmaster:read
 * закрывает Директ, Метрику, Вебмастер и синхронизацию. Каждый модуль сначала смотрит «свой»
 * ключ, затем любой другой из этого набора — иначе токен, вписанный на одной странице,
 * «не существовал» для остальных.
 */
function ya_token_any(string ...$prefer): string {
    foreach (array_merge($prefer, ['ya_oauth_token', 'direct_token', 'metrika_token', 'webmaster_token']) as $k) {
        $v = secret($k);
        if ($v !== '') return $v;
    }
    return '';
}

/** Номер счётчика Метрики: metrika_id (Настройки) → metrika_counter_id («Синхро Яндекс») → config. */
function ya_counter_id(): string {
    foreach ([setting('metrika_id'), setting('metrika_counter_id'), (string)(cfg()['metrika_id'] ?? '')] as $v) {
        if (trim((string)$v) !== '') return trim((string)$v);
    }
    return '';
}

/** Настройка из crm_settings. */
function setting(string $key, ?string $default = null): ?string {
    try {
        $st = pdo()->prepare('SELECT sval FROM crm_settings WHERE skey = ?');
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v !== false ? (string)$v : $default;
    } catch (Throwable $e) { return $default; }
}

/** Записать настройку в crm_settings (upsert). Не валит вызывающего при ошибке. */
function setting_set(string $key, string $val): void {
    try {
        pdo()->prepare('INSERT INTO crm_settings (skey,sval) VALUES (?,?) ON DUPLICATE KEY UPDATE sval=VALUES(sval)')
             ->execute([$key, $val]);
    } catch (Throwable $e) { /* фон не должен падать из-за heartbeat */ }
}

/**
 * Пульс фоновой задачи: фиксирует время последнего запуска в cron_<name>_last.
 * health.php сверяет свежесть → «молчащий» крон становится видимым отказом.
 */
function cron_heartbeat(string $name): void {
    setting_set('cron_' . $name . '_last', date('Y-m-d H:i:s'));
}

/**
 * Колонки учёта ПОВТОРНЫХ обращений: last_inquiry_at + inquiries_count.
 *
 * Зачем: приём с сайта не плодит дубль-лид, а обновляет карточку известного клиента
 * (см. api/feedback.php). При этом created_at остаётся старым, и повторная заявка
 * не всплывала в списке — менеджер видел письмо, но «в CRM ничего не пришло».
 * last_inquiry_at даёт дату ПОСЛЕДНЕГО обращения отдельно от даты первого касания
 * (updated_at для этого не годится: он меняется от любого действия менеджера).
 *
 * Миграция ленивая — на боевом /migrations удаляют после установки, запустить
 * там SQL-апгрейд нечем. Проверка одна на запрос (статик-кэш), ALTER — один раз
 * за всю жизнь базы. Провал не критичен: вызывающий откатывается на created_at.
 */
function lead_repeat_columns_ready(): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    $ready = false;
    try {
        $pdo  = pdo();
        $cols = $pdo->query("SHOW COLUMNS FROM crm_leads")->fetchAll(PDO::FETCH_COLUMN);
        $add  = [];
        if (!in_array('last_inquiry_at', $cols, true)) {
            $add[] = 'ADD COLUMN last_inquiry_at DATETIME NULL';
            $add[] = 'ADD KEY idx_last_inquiry (last_inquiry_at)';
        }
        if (!in_array('inquiries_count', $cols, true)) {
            $add[] = 'ADD COLUMN inquiries_count INT NOT NULL DEFAULT 1';
        }
        if ($add) $pdo->exec('ALTER TABLE crm_leads ' . implode(', ', $add));
        $ready = true;
    } catch (Throwable $e) {
        error_log('[zr-crm] не удалось добавить колонки повторных обращений: ' . $e->getMessage());
        $ready = false;
    }
    return $ready;
}

/**
 * SQL-выражение «когда заявка пришла»: дата последнего обращения, а для лидов без
 * повторов — дата создания. Используется и в сортировке, и в фильтре по периоду,
 * чтобы повторная заявка не пряталась в глубине списка под своей старой датой.
 */
function lead_inquiry_at_sql(string $alias = 'l'): string {
    return lead_repeat_columns_ready()
        ? "COALESCE({$alias}.last_inquiry_at, {$alias}.created_at)"
        : "{$alias}.created_at";
}

/** Нормализация телефона в цифры (для хэширования аудиторий, E.164-ish). */
function normalize_phone(string $p): string {
    $d = preg_replace('/\D+/', '', $p) ?? '';
    if (strlen($d) === 11 && $d[0] === '8') $d = '7' . substr($d, 1);
    return $d;
}

/**
 * Инженерная воронка (заявка → инженер → КП → продажа).
 * code => [подпись, класс-цвета]. Порядок массива = порядок этапов в канбане.
 */
function funnel(): array {
    return [
        'new'         => ['Новая',              'new'],
        'in_progress' => ['В работе',           'in_progress'],
        'clarify'     => ['Уточнение',          'clarify'],
        'picked'      => ['Подбор выполнен',     'picked'],
        'review'      => ['На проверке',         'review'],
        'rework'      => ['Исправить',           'rework'],
        'approved'    => ['Одобрено',            'approved'],
        'sent'        => ['Отправлено клиенту',  'sent'],
        'won'         => ['Успешно',             'won'],
        'lost'        => ['Отказ',               'lost'],
    ];
}
/** Список кодов статусов воронки. */
function funnel_codes(): array { return array_keys(funnel()); }
/** Подпись статуса (с поддержкой легаси-кода 'quoted'). */
function status_label(string $code): string {
    $f = funnel();
    if (isset($f[$code])) return $f[$code][0];
    $legacy = ['quoted' => 'КП отправлено'];
    return $legacy[$code] ?? $code;
}
/** Все допустимые коды статусов (воронка + легаси для совместимости фильтров). */
function status_valid(): array { return array_merge(funnel_codes(), ['quoted']); }

/** Нормализация кода редуктора для сопоставления: верхний регистр, только буквы/цифры. */
function analog_norm(string $s): string {
    $s = mb_strtoupper(trim($s), 'UTF-8');
    return (string)preg_replace('/[^A-Z0-9А-ЯЁ]/u', '', $s);
}

/**
 * ДЕТЕРМИНИРОВАННЫЙ подбор аналога ZR по оригинальному коду из базы crm-data/analogs.csv
 * (5453 позиции) + прямых соответствий crm-data/analog_codes.csv (EVL↔ZR).
 *
 * Смысл: модель ИИ читает оригинальный код с шильдика/PDF (это она умеет), а маппинг
 * «оригинал → наш ZR» берётся ИЗ ДАННЫХ, а не из догадок модели. Так исключаются
 * галлюцинации несуществующих ZR-кодов, уходящие клиенту.
 *
 * @return array{zr:string,price:string,category:string,original:string,source:string}|null
 */
function analog_lookup(string $original): ?array {
    static $map = null;
    if ($map === null) {
        $map = [];
        $dir = __DIR__ . '/../crm-data';
        // analogs.csv: original_model,power_kw,i,torque_nm,rpm,category,zr_analog,evl_ref,price_from,sku
        if (is_readable("$dir/analogs.csv") && ($fh = fopen("$dir/analogs.csv", 'r'))) {
            $hdr = fgetcsv($fh);
            $ix  = is_array($hdr) ? array_flip($hdr) : [];
            while (($r = fgetcsv($fh)) !== false) {
                $orig = (string)($r[$ix['original_model'] ?? 0] ?? '');
                $zr   = (string)($r[$ix['zr_analog'] ?? 6] ?? '');
                if ($orig === '' || $zr === '') continue;
                $key = analog_norm($orig);
                if ($key === '' || isset($map[$key])) continue;
                $map[$key] = [
                    'zr'       => $zr,
                    'price'    => (string)($r[$ix['price_from'] ?? 8] ?? ''),
                    'category' => (string)($r[$ix['category'] ?? 5] ?? ''),
                    'original' => $orig,
                    'source'   => 'база',
                ];
            }
            fclose($fh);
        }
        // analog_codes.csv: evl_code,zr_code,note — прямые EVL→ZR (подтверждённые исключения)
        if (is_readable("$dir/analog_codes.csv") && ($fh = fopen("$dir/analog_codes.csv", 'r'))) {
            fgetcsv($fh);
            while (($r = fgetcsv($fh)) !== false) {
                $evl = (string)($r[0] ?? ''); $zr = (string)($r[1] ?? '');
                if ($evl === '' || $zr === '') continue;
                $key = analog_norm($evl);
                if ($key !== '' && !isset($map[$key])) {
                    $map[$key] = ['zr'=>$zr,'price'=>'','category'=>'','original'=>$evl,'source'=>'база (EVL→ZR)'];
                }
            }
            fclose($fh);
        }
    }
    $q = analog_norm($original);
    if ($q === '') return null;
    if (isset($map[$q])) return $map[$q];                 // точное совпадение
    foreach ($map as $key => $v) {                        // код внутри длинной строки
        if (strlen($key) >= 4 && (str_contains($q, $key) || str_contains($key, $q))) return $v;
    }

    // Третий источник — справочник, собранный с карточек сайта: 20 марок, 754 серии.
    // В analogs.csv лежат только SEW и Motovario, поэтому Bauer, NORD, Bonfiglioli,
    // Rossi, Varvel и прочее раньше не находились вообще.
    if (function_exists('site_lookup_models')) {
        $hits = site_lookup_models($original, 1);
        if ($hits && !empty($hits[0]['confirmed'])) {
            $h = $hits[0];
            return [
                'zr'       => implode(', ', $h['zr']),
                'price'    => '',                        // прайса нет — цену не выдумываем
                'category' => (string)$h['type'],
                'original' => trim($h['brand'] . ' ' . $h['model']),
                'source'   => 'сайт (карточка)',
            ];
        }
    }
    return null;
}

/**
 * Обогатить результат распознавания подтверждёнными из базы аналогами.
 * Для каждого analog[].for и position[].model ищем ZR в базе: найдено → проставляем
 * авторитетный ZR + цену + verified=true; не найдено → verified=false и метка в missing.
 * Возвращает $out с изменёнными analogs/missing и добавленным all_verified.
 */
function enrich_analogs(array $out): array {
    $analogs = (array)($out['analogs'] ?? []);
    $positions = (array)($out['positions'] ?? []);
    $allVerified = true;
    foreach ($analogs as $i => $a) {
        $for = (string)($a['for'] ?? '');
        // если 'for' пустой — пробуем модель из соответствующей позиции
        if ($for === '' && isset($positions[$i]['model'])) $for = (string)$positions[$i]['model'];
        $hit = $for !== '' ? analog_lookup($for) : null;
        if ($hit) {
            $analogs[$i]['our']      = $hit['zr'];               // авторитетный ZR из базы
            $analogs[$i]['verified'] = true;
            $analogs[$i]['source']   = $hit['source'];
            if ($hit['price'] !== '' && empty($a['price'])) $analogs[$i]['price'] = $hit['price'];
        } else {
            $analogs[$i]['verified'] = false;
            $analogs[$i]['source']   = 'ИИ (нет в базе — проверить вручную)';
            $allVerified = false;
        }
    }
    $out['analogs'] = array_values($analogs);
    // Непроверенные аналоги — явный сигнал инженеру и стоп-фактор для автоотправки.
    if (!$allVerified) {
        $miss = (array)($out['missing'] ?? []);
        $miss[] = 'Часть аналогов не найдена в базе — требуется ручной подбор';
        $out['missing'] = array_values(array_unique($miss));
    }
    $out['all_verified'] = $allVerified && !empty($analogs);
    return $out;
}
