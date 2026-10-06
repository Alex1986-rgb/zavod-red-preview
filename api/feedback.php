<?php
declare(strict_types=1);

/**
 * ПУБЛИЧНЫЙ приём заявки с сайта zavod-red.ru.
 * Поток: валидация → honeypot → rate-limit → сохранение файла → INSERT crm_leads
 *        → audit(lead_created) → Telegram (sendMessage + sendDocument) → email (mail()).
 * Контракт с фронтом: при успехе {ok:true, status:'success'}, при ошибке {ok:false, status:'error', message:'...'}.
 */

require_once __DIR__ . '/helpers.php';

/**
 * Журнал ПОПЫТОК отправки заявки.
 * Смысл: раньше любой fail() выбрасывал заявку бесследно — контакты клиента исчезали,
 * и никто об этом не узнавал (цель Метрики шлётся только при успехе). Теперь ЛЮБАЯ
 * попытка фиксируется вместе с контактами: сначала в файл (работает даже если БД лежит),
 * затем в таблицу crm_lead_attempts. Журнал никогда не должен ломать приём заявки.
 */
$ATTEMPT = ['name' => '', 'phone' => '', 'email' => '', 'message' => '',
            'page_url' => '', 'file' => '', 'ip' => '', 'ua' => ''];

function log_attempt(string $reason, string $outcome = 'rejected', ?int $leadId = null): void {
    static $done = false;
    if ($done) return;               // одна запись на запрос
    $done = true;
    global $ATTEMPT;
    // Мусорные пробы (GET/сканеры) без единого поля не пишем.
    if ($outcome === 'rejected' && $ATTEMPT['name'] === '' && $ATTEMPT['phone'] === ''
        && $ATTEMPT['email'] === '' && $ATTEMPT['message'] === '' && $outcome !== 'lost_post_limit') {
        if ($reason === 'Method not allowed') return;
    }
    $row = $ATTEMPT;
    $row['reason']     = $reason;
    $row['outcome']    = $outcome;
    $row['lead_id']    = $leadId;
    $row['created_at'] = date('Y-m-d H:i:s');

    // 1) Файловый фолбэк — пишем ПЕРВЫМ, он не зависит от БД.
    try {
        $dir = __DIR__ . '/../crm-data';
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        @file_put_contents($dir . '/lead_attempts.jsonl',
            json_encode($row, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    } catch (Throwable $e) { /* ignore */ }

    // 2) Таблица (создаём при первом обращении, чтобы не требовать ручной миграции).
    try {
        $pdo = pdo();
        $pdo->exec('CREATE TABLE IF NOT EXISTS crm_lead_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            created_at DATETIME NOT NULL,
            outcome VARCHAR(24) NOT NULL DEFAULT "rejected",
            reason VARCHAR(255) NOT NULL DEFAULT "",
            name VARCHAR(255) NOT NULL DEFAULT "",
            phone VARCHAR(64) NOT NULL DEFAULT "",
            email VARCHAR(255) NOT NULL DEFAULT "",
            message TEXT NULL,
            page_url VARCHAR(1000) NOT NULL DEFAULT "",
            file_name VARCHAR(255) NOT NULL DEFAULT "",
            ip VARCHAR(64) NOT NULL DEFAULT "",
            user_agent VARCHAR(500) NOT NULL DEFAULT "",
            lead_id INT NULL,
            KEY k_created (created_at),
            KEY k_outcome (outcome)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $st = $pdo->prepare('INSERT INTO crm_lead_attempts
            (created_at, outcome, reason, name, phone, email, message, page_url, file_name, ip, user_agent, lead_id)
            VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $st->execute([$outcome, mb_substr($reason, 0, 255), $row['name'], $row['phone'], $row['email'],
                      $row['message'], $row['page_url'], $row['file'], $row['ip'], $row['ua'], $leadId]);
    } catch (Throwable $e) { /* журнал не должен ломать приём заявки */ }
}

/** Ответ об ошибке в формате, совместимом с фронтом. Попытка фиксируется в журнале. */
function fail(string $message, int $code = 200): never {
    log_attempt($message, 'rejected');
    // Если успех уже отдан (respond_early), дописывать ошибку в тот же ответ нельзя.
    if (!empty($GLOBALS['zr_responded'])) exit;
    json_out(['ok' => false, 'status' => 'error', 'message' => $message], $code);
}

/**
 * Отдать ответ браузеру и продолжить работу скрипта в фоне.
 * Нужен, чтобы форма не ждала медленных внешних каналов (Telegram, SMTP): лид уже
 * в БД, и подтверждать клиенту доставку уведомления менеджеру незачем.
 *
 * Работает ТОЛЬКО под PHP-FPM (fastcgi_finish_request). Ручной трюк с
 * `Content-Length` + `Connection: close` здесь сознательно НЕ используется: в
 * .htaccess включён `AddOutputFilterByType DEFLATE application/json`, ответ уходит
 * сжатым, и вручную выставленная длина расходится с фактической — браузер ждёт
 * «недосланные» байты, то есть мы бы получили ровно то зависание, которое чиним.
 * Без FPM функция ничего не делает, и приём работает как раньше — без регрессии.
 */
function respond_early(array $payload): void {
    if (!function_exists('fastcgi_finish_request')) return;
    if (!empty($GLOBALS['zr_responded'])) return;
    $GLOBALS['zr_responded'] = true;
    ignore_user_abort(true);           // клиент отключился — уведомления всё равно дошлём
    @set_time_limit(120);
    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    fastcgi_finish_request();
}

/** Успешный ответ. */
function success(string $delivery = ''): never {
    // Ответ уже ушёл через respond_early() — второй раз тело не пишем, просто выходим,
    // иначе к JSON приклеился бы дубль и фронт не смог бы его разобрать.
    if (!empty($GLOBALS['zr_responded'])) exit;
    $out = ['ok' => true, 'status' => 'success'];
    // Диагностика доставки — ТОЛЬКО по явному флагу __diag, чтобы можно было
    // проверить канал снаружи, не имея доступа к базе. Ни ключей, ни паролей
    // здесь нет: только «ушло письмо или нет» и причина отказа.
    if ($delivery !== '' && !empty($_POST['__diag'])) {
        $out['delivery'] = $delivery;
    }
    // Видно ли снаружи, что форма перестала ждать Telegram/SMTP: под FPM ответ уходит
    // сразу после сохранения лида, иначе — как раньше, по завершении всех каналов.
    if (!empty($_POST['__diag'])) {
        $out['async_notify'] = function_exists('fastcgi_finish_request');
    }
    json_out($out, 200);
}

/**
 * Первое непустое POST-поле из списка ключей, очищенное sanitize().
 * Позволяет принимать и чистые семантические имена (name/phone/email/message),
 * и старые автогенерённые ID конструктора (text-562/tel-535/…), не ломая контракт.
 */
function post_any(array $keys): string {
    foreach ($keys as $k) {
        if (isset($_POST[$k]) && trim((string)$_POST[$k]) !== '') {
            return sanitize((string)$_POST[$k]);
        }
    }
    return '';
}

/** Первый пришедший файл из списка ключей $_FILES (успешно загруженный). */
function file_any(array $keys): ?array {
    foreach ($keys as $k) {
        if (isset($_FILES[$k]) && ($_FILES[$k]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
            && is_uploaded_file($_FILES[$k]['tmp_name'] ?? '')) {
            return $_FILES[$k];
        }
    }
    return null;
}

// --- Только POST ---
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    fail('Method not allowed', 405);
}

// --- КРИТИЧНО: превышен post_max_size. PHP в этом случае отбрасывает ВЕСЬ пакет:
//     $_POST и $_FILES приходят ПУСТЫМИ, хотя браузер отправил и контакты, и файл.
//     Внешне это выглядело как «заявка без контактных данных» / молчаливая потеря.
//     Фиксируем в журнале и отвечаем человеку понятной причиной. ---
$contentLen = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLen > 0 && empty($_POST) && empty($_FILES)) {
    $ATTEMPT['ip']       = client_ip();
    $ATTEMPT['ua']       = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
    $ATTEMPT['page_url'] = substr(sanitize((string)($_SERVER['HTTP_REFERER'] ?? '')), 0, 1000);
    $ATTEMPT['message']  = 'Данные не дошли до сервера: пакет отброшен целиком.';
    log_attempt(
        'POST отброшен: превышен post_max_size (' . (string)ini_get('post_max_size')
        . '), длина запроса ' . $contentLen . ' байт',
        'lost_post_limit'
    );
    json_out(['ok' => false, 'status' => 'error',
        'message' => 'Файл слишком большой для сервера. Отправьте заявку без вложения — '
                   . 'мы свяжемся и примем файл письмом на zr@zavod-red.ru.'], 200);
}

// --- Honeypot: непустой work_email = бот. Возвращаем success, чтобы не насторожить. ---
if (!empty($_POST['work_email'])) {
    success();
}

// --- Лимиты частоты по IP ОТКЛЮЧЕНЫ 12.08.2026 по решению владельца ---
// Раньше здесь стояло два ограничения: 30 заявок в час с адреса и 3 в минуту.
// Оба сняты: ни одна заявка не должна отклоняться, даже если несколько клиентов
// сидят за одним корпоративным интернетом (NAT — весь офис виден как ОДИН адрес)
// или человек отправил форму несколько раз подряд.
//
// Чем платим: форма открыта для ботов. 12.08 адрес 193.200.54.130 за 3 секунды
// отправил 12 форм и оставил 4 мусорные заявки — теперь такое не остановится
// само. Мусор чистится в админке, отличить его просто: один IP, отправки
// в пределах секунд, в поле «сообщение» бессмыслица.
//
// Если поток мусора станет мешать — вернуть можно одной строкой:
//   if (!rate_limit('burst:' . $ip, 3, 60)) { fail('Слишком часто', 429); }
// Защита от двойного клика (см. ниже, ключ dbl:) НЕ трогалась: она не отклоняет
// заявку, а лишь не даёт создать две одинаковые карточки на одном контакте.
$ip = client_ip();

// --- Поля формы: чистые семантические имена ИЛИ старые ID конструктора Nicepage ---
$name    = post_any(['name', 'text-562']);
$phone   = post_any(['phone', 'tel-535']);
$email   = post_any(['email', 'email-727']);
$message = post_any(['message', 'comment', 'textarea-725']);

// Контакты попали в журнал сразу после разбора — теперь любой последующий fail()
// (валидация, лимит частоты, ошибка БД) сохранит их, а не выбросит.
$ATTEMPT['name']     = $name;
$ATTEMPT['phone']    = $phone;
$ATTEMPT['email']    = $email;
$ATTEMPT['message']  = $message;
$ATTEMPT['ip']       = $ip;
$ATTEMPT['ua']       = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
$ATTEMPT['page_url'] = substr((string)(post_any(['page_url']) ?: sanitize((string)($_SERVER['HTTP_REFERER'] ?? ''))), 0, 1000);
$ATTEMPT['file']     = substr((string)($_FILES['file']['name'] ?? $_FILES['file-174']['name'] ?? ''), 0, 255);

// --- Защита от двойного клика ---
$productTitle = post_any(['product_title']);
// Явные поля, если фронт их шлёт напрямую (приоритетнее разбора product_title).
$reducerTypeExplicit = post_any(['reducer_type', 'type']);
$pageTitleExplicit   = post_any(['page_title']);

// product_title формата "Заявка … · {reducer_type} · {page_title}".
// Разбираем по разделителю "·"; берём последние два сегмента как тип и заголовок.
$reducerType = $reducerTypeExplicit;
$pageTitle   = $pageTitleExplicit;
if ($productTitle !== '' && ($reducerType === '' || $pageTitle === '')) {
    $parts = array_map('trim', explode('·', $productTitle));
    $parts = array_values(array_filter($parts, static fn($p) => $p !== ''));
    $n = count($parts);
    if ($n >= 2) {
        if ($reducerType === '') $reducerType = $parts[$n - 2];
        if ($pageTitle === '')   $pageTitle   = $parts[$n - 1];
    } elseif ($n === 1 && $pageTitle === '') {
        $pageTitle = $parts[0];
    }
}

// --- Прочие метаданные (UTM, источник, страница) ---
$utm = parse_utm($_POST);
$referrer  = isset($_POST['referrer'])  ? substr(sanitize((string)$_POST['referrer']),  0, 1000) : (isset($_SERVER['HTTP_REFERER']) ? substr(sanitize((string)$_SERVER['HTTP_REFERER']), 0, 1000) : '');
$pageUrl   = isset($_POST['page_url'])  ? substr(sanitize((string)$_POST['page_url']),   0, 1000) : '';
$userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 500) : '';
$source    = $utm['utm_source'] !== '' ? $utm['utm_source'] : 'site';

// --- Валидация обязательных полей ---
// Фронт (модалка и .lead-form data-light) позволяет отправить с телефоном ИЛИ почтой.
// Требовать телефон — терять email-лидов (фронт пропускает, бэкенд отвергал).
if ($name === '') {
    fail('Пожалуйста, заполните обязательное поле: Имя.');
}
// Достаточно ОДНОГО контакта: телефон ИЛИ почта.
if ($phone === '' && $email === '') {
    fail('Оставьте телефон или почту — как с вами связаться.');
}
if ($phone !== '' && strlen((string)preg_replace('/\D/', '', $phone)) < 10) {
    fail('Пожалуйста, введите корректный номер телефона.');
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fail('Проверьте почту: похоже, в адресе опечатка.');
}

// --- Анти-дубль ОТКЛЮЧЁН 12.08.2026 по решению владельца ---
// Здесь стояла защита от двойного клика: повторная отправка тем же телефоном
// (или email) в течение 90 секунд не создавала вторую карточку — клиент видел
// успех, а в CRM оставалась одна заявка.
//
// Снято по прямому указанию: форма должна принимать столько отправок, сколько
// человек захочет сделать. Теперь КАЖДОЕ нажатие «Отправить» создаёт отдельную
// заявку — в том числе нетерпеливый двойной клик и повтор после таймаута сети.
// В списке заявок это выглядит как несколько одинаковых карточек подряд
// с разницей в секунды; лишние удаляются в админке.
//
// Вернуть, если задвоения станут мешать (нужен и $phoneDigits выше):
//   $dblKey = $phoneDigits !== '' ? 'p:' . $phoneDigits : 'e:' . md5($email);
//   if (!rate_limit('dbl:' . $dblKey, 1, 90)) {
//       log_attempt('Повторная отправка (тот же контакт) за 90 секунд — дубль не создан', 'duplicate');
//       success();
//   }

// --- Хук мониторинга: проверка живости формы без записи в БД и без уведомлений ---
// Валидация полей уже пройдена выше. Сравниваем токен в постоянном времени.
if (!empty($_POST['__monitor'])) {
    $expected = secret('monitor_test_secret');
    if ($expected !== '' && hash_equals($expected, (string)$_POST['__monitor'])) {
        json_out(['ok' => true, 'status' => 'success', 'test' => true], 200);
    }
}

// --- Валидация вложения (белый список расширений + ≤10МБ) ---
$fileOk      = false;
// Широкий белый список: .jfif — обычный JPEG (его отдаёт Windows/браузер по умолчанию),
// его отсутствие ранее приводило к полной потере заявки. Плюс tif/avif, архивы и CAD-обмен.
$allowedExt  = ['jpg','jpeg','jpe','jfif','pjpeg','png','webp','gif','avif','heic','heif','bmp','tif','tiff',
                'pdf','doc','docx','xls','xlsx','csv','txt','rtf','odt','ods',
                'dwg','dxf','stp','step','igs','iges',
                'zip','rar','7z'];
$maxFileSize = 10 * 1024 * 1024; // 10 МБ
$fileReject  = ''; // причина, если вложение не приняли (заявку всё равно сохраняем)
$fileTmp     = '';
$fileName    = '';
$fileType    = '';
$fileExt     = '';

$upload = file_any(['file', 'file-174', 'attachment']);
if ($upload !== null) {
    $fileExt = strtolower(pathinfo((string)$upload['name'], PATHINFO_EXTENSION));
    if (in_array($fileExt, $allowedExt, true)
        && $upload['size'] > 0
        && $upload['size'] <= $maxFileSize) {
        $fileOk   = true;
        $fileTmp  = (string)$upload['tmp_name'];
        // Убираем кавычки/CRLF/управляющие — имя идёт в MIME-заголовки письма name="…".
        $fileName = preg_replace('/[\r\n"\\\\\x00-\x1f]/', '_', basename((string)$upload['name'])) ?: 'file';
        $fileType = (string)($upload['type'] ?: 'application/octet-stream');
    } else {
        // КРИТИЧНО: НЕ роняем заявку из-за вложения — раньше здесь был fail(), и лид
        // терялся целиком (ни в БД, ни в почту, ни в Telegram). Теперь сохраняем лид,
        // а про вложение оставляем пометку менеджеру, чтобы он запросил файл отдельно.
        $sizeMb = round(((int)($upload['size'] ?? 0)) / 1048576, 1);
        $fileReject = 'ВЛОЖЕНИЕ НЕ ПРИНЯТО: '
            . ($fileExt !== '' ? '.' . $fileExt : 'без расширения')
            . ', ' . $sizeMb . ' МБ. Запросить файл у клиента отдельно.';
    }
}

// Пометка о непринятом вложении идёт в сообщение лида — попадёт и в БД, и в Telegram, и в почту.
if ($fileReject !== '') {
    $message = ($message !== '' ? $message . "\n\n" : '') . '⚠ ' . $fileReject;
}

// --- Сохранение файла в uploads_dir под случайным именем ---
$filePathRel = null; // относительный путь для БД
if ($fileOk) {
    // Дефолт как у ai.php/leads.php/file.php: если ключа нет в config.php — вложение
    // всё равно сохранится в crm-data/uploads (иначе file_path=null и ИИ не увидит файл).
    $uploadsDir = rtrim((string)(cfg()['uploads_dir'] ?? (__DIR__ . '/../crm-data/uploads')), '/');
    if (!is_dir($uploadsDir)) {
        @mkdir($uploadsDir, 0750, true);
    }
    $randName  = bin2hex(random_bytes(8)) . ($fileExt !== '' ? '.' . $fileExt : '');
    $destPath  = $uploadsDir . '/' . $randName;
    if (@move_uploaded_file($fileTmp, $destPath)) {
        // Telegram/email читают сохранённый файл по новому пути.
        $fileTmp     = $destPath;
        $filePathRel = 'crm-data/uploads/' . $randName;
    }
    // если переместить не удалось — продолжаем с tmp-файлом (он валиден до конца запроса)
}

// --- Заявка в crm_leads: дедуп по контакту, иначе новый лид ---
require_once __DIR__ . '/inbox.php';
$leadId = null;
$isExisting = false;
$dbDown = false; // true → CRM недоступна, заявка идёт менеджеру напрямую (почта + Telegram)
try {
    $existing = lead_find_existing($email, $phone);
    if ($existing) {
        // Повторное обращение известного клиента — НЕ плодим дубль-лид.
        // Обновляем свежими данными заявки; first-touch source/utm НЕ трогаем.
        $isExisting = true;
        $leadId = $existing;

        $prev = pdo()->prepare('SELECT name, status, message FROM crm_leads WHERE id=?');
        $prev->execute([$leadId]);
        $prevRow = $prev->fetch(PDO::FETCH_ASSOC) ?: ['name' => '', 'status' => 'new', 'message' => ''];

        // Закрытый лид от нового обращения возвращается в воронку: иначе заявка молча
        // падала в колонку «Успешно»/«Отказ», где менеджер её уже не смотрит.
        // Статусы активной работы не трогаем — не сбивать менеджеру текущий этап.
        $newStatus = in_array((string)$prevRow['status'], ['won', 'lost'], true)
                   ? 'new' : (string)$prevRow['status'];

        // Имя перезаписываем только если прежнее пустое или обезличенное: клиент мог
        // во второй раз назваться короче, и терять уже известное ФИО нельзя.
        $prevName = trim((string)$prevRow['name']);
        $newName  = ($prevName === '' || mb_strtolower($prevName) === 'клиент с сайта') && $name !== ''
                  ? $name : $prevName;

        // Текст прошлого обращения сохраняем под разделителем — раньше он затирался,
        // и ТЗ из первой заявки исчезало из карточки (в ленте оставалось, но менеджер
        // работает по полю «Сообщение»).
        $prevMsg = trim((string)($prevRow['message'] ?? ''));
        $newMsg  = $message;
        if ($prevMsg !== '' && $prevMsg !== $message) {
            $newMsg = ($message !== '' ? $message . "\n\n" : '')
                    . '--- предыдущее обращение ---' . "\n" . $prevMsg;
            $newMsg = mb_substr($newMsg, 0, 60000);
        }

        if (lead_repeat_columns_ready()) {
            pdo()->prepare(
                'UPDATE crm_leads SET name=?, reducer_type=?, message=?, page_url=?, page_title=?,
                    file_path=COALESCE(?, file_path), status=?,
                    last_inquiry_at=NOW(), inquiries_count=inquiries_count+1, updated_at=NOW()
                 WHERE id=?'
            )->execute([$newName, $reducerType, $newMsg, $pageUrl, $pageTitle,
                        $filePathRel, $newStatus, $leadId]);
        } else {
            pdo()->prepare(
                'UPDATE crm_leads SET name=?, reducer_type=?, message=?, page_url=?, page_title=?,
                    file_path=COALESCE(?, file_path), status=?, updated_at=NOW() WHERE id=?'
            )->execute([$newName, $reducerType, $newMsg, $pageUrl, $pageTitle,
                        $filePathRel, $newStatus, $leadId]);
        }
    } else {
        // Новый клиент — полная запись со всеми метками привлечения.
        $st = pdo()->prepare(
            'INSERT INTO crm_leads
                (created_at, updated_at, name, phone, email, reducer_type, message,
                 page_url, page_title, file_path, source,
                 utm_source, utm_medium, utm_campaign, utm_term, utm_content,
                 referrer, gclid, yclid, ip, user_agent, status)
             VALUES
                (NOW(), NOW(), ?, ?, ?, ?, ?,
                 ?, ?, ?, ?,
                 ?, ?, ?, ?, ?,
                 ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            $name, $phone, $email, $reducerType, $message,
            $pageUrl, $pageTitle, $filePathRel, $source,
            $utm['utm_source'], $utm['utm_medium'], $utm['utm_campaign'], $utm['utm_term'], $utm['utm_content'],
            $referrer, $utm['gclid'], $utm['yclid'], $ip, $userAgent, 'new',
        ]);
        $leadId = (int)pdo()->lastInsertId();
    }
} catch (Throwable $e) {
    // БД/CRM недоступна (плановое отключение админки, обрыв MySQL). Заявку НЕ теряем:
    // ниже она уйдёт менеджеру напрямую — письмом и в Telegram, — а клиент получит
    // обычное подтверждение. Контакты уже в файловом журнале ($ATTEMPT / log_attempt).
    $dbDown = true;
    $leadId = null;
    error_log('[zr-feedback] БД недоступна, заявка уходит напрямую: ' . $e->getMessage());
}

// --- Журнал активности ---
audit($leadId, null, $isExisting ? 'lead_reinquiry' : 'lead_created', [
    'name' => $name, 'phone' => $phone, 'email' => $email,
    'reducer_type' => $reducerType, 'page_title' => $pageTitle,
    'source' => $source, 'has_file' => $fileOk, 'repeat' => $isExisting,
]);

// --- Запись обращения в ленту сообщений лида (омниканальная история) ---
if ($leadId && empty($_POST['__monitor'])) {
    try {
        $msgBody = $message !== '' ? $message
            : ('Заявка с сайта' . ($reducerType !== '' ? ' · ' . $reducerType : '')
               . ($pageTitle !== '' ? ' · ' . $pageTitle : '') . ($fileOk ? ' · с вложением' : ''));
        msg_insert($leadId, 'form', 'in', $msgBody, [
            'contact' => $email !== '' ? $email : $phone,
            'subject' => $pageTitle !== '' ? $pageTitle : 'Заявка с сайта',
        ]);
    } catch (Throwable $e) { /* не ломаем ответ формы */ }
}

// --- Заявка сохранена: отвечаем браузеру СЕЙЧАС, уведомления досылаем после ---
// Раньше форма ждала ответа, пока скрипт по очереди ходил в Telegram (curl 10–15 с)
// и в SMTP (fsockopen 20 с на коннект + до 20 с на каждое из ~9 чтений диалога).
// В худшем случае — минуты «Отправляем…» на кнопке при уже сохранённом лиде: клиент
// видел зависание и уходил, хотя заявка была принята. Приём в БД от доставки не
// зависит, поэтому подтверждение уходит сразу после INSERT, а Telegram/почта
// доделываются в фоне (ignore_user_abort). Диагностику (__diag) не трогаем — ей
// нужен реальный итог доставки, поэтому она остаётся синхронной.
if ($leadId && !$dbDown && empty($_POST['__diag']) && empty($_POST['__monitor'])) {
    respond_early(['ok' => true, 'status' => 'success']);
}

// --- Авто-распределение + уведомление в Telegram ---
// Новый лид: назначаем менеджера и уведомляем. Повторный: только уведомляем
// (менеджер уже закреплён; important — увидеть новое обращение известного клиента).
if ($leadId && empty($_POST['__monitor'])) {
    try {
        if (!$isExisting) auto_assign($leadId);
        notify_new_lead($leadId);
        notify_max($leadId);
    } catch (Throwable $e) { /* не ломаем ответ формы */ }
}

// --- Telegram: только пересылка вложения ---
// Текстовое уведомление о заявке уже отправил notify_new_lead() выше (с кнопками
// смены статуса) — второй «плоский» sendMessage здесь был дублем, убран.
$tgCfg   = cfg()['telegram'] ?? [];
$tgToken = secret('tg_token', (string)($tgCfg['token'] ?? ''));
$tgChat  = secret('tg_chat', (string)($tgCfg['chat'] ?? ''));
if ($tgToken !== '' && $tgToken !== 'CHANGE_ME' && $tgChat !== '' && $tgChat !== 'CHANGE_ME' && function_exists('curl_init')) {
    // CRM недоступна → notify_new_lead() выше не отработал (ему нужен лид в БД).
    // Шлём заявку в Telegram напрямую, чтобы менеджер увидел её сразу.
    if ($dbDown && empty($_POST['__monitor'])) {
        $tgLines = ["⚠️ Заявка МИМО CRM (база недоступна)", "", "👤 {$name}", "📞 {$phone}"];
        if ($email !== '')       $tgLines[] = "✉️ {$email}";
        if ($reducerType !== '') $tgLines[] = "⚙️ {$reducerType}";
        if ($message !== '')     $tgLines[] = "💬 {$message}";
        if ($pageTitle !== '')   $tgLines[] = "📄 {$pageTitle}";
        if ($pageUrl !== '')     $tgLines[] = $pageUrl;
        $tgLines[] = "";
        $tgLines[] = "Занесите в CRM вручную после включения базы.";
        $chm = curl_init("https://api.telegram.org/bot{$tgToken}/sendMessage");
        if ($chm !== false) {
            curl_setopt_array($chm, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => ['chat_id' => $tgChat, 'text' => implode("\n", $tgLines)],
                CURLOPT_TIMEOUT        => 15,
            ]);
            curl_exec($chm);
            curl_close($chm);
        }
    }
    // Пересылаем вложение как документ.
    if ($fileOk && is_file($fileTmp)) {
        $doc = new CURLFile($fileTmp, $fileType, $fileName);
        $chd = curl_init("https://api.telegram.org/bot{$tgToken}/sendDocument");
        if ($chd !== false) {
            curl_setopt_array($chd, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => ['chat_id' => $tgChat, 'caption' => "📎 Вложение к заявке: {$name}", 'document' => $doc],
                CURLOPT_TIMEOUT        => 25,
            ]);
            $r = curl_exec($chd);
            $code = (int)curl_getinfo($chd, CURLINFO_HTTP_CODE);
            curl_close($chd);
            if ($leadId) audit($leadId, null, 'notify_telegram_doc', ['ok' => ($r !== false && $code === 200)]);
        }
    }
}

/**
 * Письмо с необязательным вложением.
 * Без файла — обычный путь (SMTP с фолбэком на mail()). С файлом — multipart/mixed,
 * чтобы менеджер получал фото шильда или чертёж прямо в почте и мог работать
 * по одному письму, не заходя в CRM.
 * ⚠️ 11.08.2026: вложения в письмо ОТКЛЮЧЕНЫ на вызове (см. ниже) — письма
 * mail+attach молча пропадали на приёмнике (ящик на Яндексе, DMARC у
 * отправителя нет): #122/#123 дошло одно из двух, #124 (.docx) не дошло вовсе,
 * хотя журнал по всем «отправлено». Лёгкое письмо без вложения доходит.
 * Файл клиента при этом никуда не девается: он в карточке CRM (ссылка в письме)
 * и уходит менеджеру документом в Telegram. Функция оставлена рабочей — если
 * появится DKIM/DMARC и вложения решат вернуть, достаточно вернуть аргументы.
 */
function zr_mail_send(string $to, string $subject, string $html,
                      string $filePath = '', string $fileName = '',
                      string $replyToOverride = ''): array {
    if ($filePath === '' || !is_readable($filePath)) {
        return notify_email_send($to, $subject, $html, $replyToOverride, true);
    }
    $size = (int)(@filesize($filePath) ?: 0);
    // Крупные файлы почтой не гоняем — письмо рискует не дойти целиком.
    if ($size <= 0 || $size > 8 * 1024 * 1024) {
        $note = '<p><em>Файл ' . htmlspecialchars($fileName, ENT_QUOTES, 'UTF-8')
              . ' (' . round($size / 1048576, 1) . ' МБ) слишком большой для письма — '
              . 'он сохранён на сервере и доступен в карточке заявки.</em></p>';
        return notify_email_send($to, $subject, $html . $note, $replyToOverride, true);
    }
    $data = @file_get_contents($filePath);
    if ($data === false) return notify_email_send($to, $subject, $html, $replyToOverride, true);

    $from     = zr_mail_from();
    // From — голым адресом (см. helpers.php): «Имя <адрес>» ломает доставку через mail().
    $safeName = preg_replace('/[\r\n"]/', '_', $fileName !== '' ? $fileName : 'attachment');
    $b        = '=_zr_' . bin2hex(random_bytes(12));
    // Reply-To — почта клиента (см. notify_email_send): «Ответить» должно уводить к нему,
    // а не на наш же ящик приёма, который Битрикс считает своим и потому игнорирует письмо.
    $replyTo  = trim($replyToOverride) !== '' && filter_var(trim($replyToOverride), FILTER_VALIDATE_EMAIL)
              ? trim($replyToOverride)
              : $from;   // без почты клиента — сам отправитель, но НЕ ящик приёма
    // Date + Message-ID — как в notify_email_send: без них Битрикс не заводит лид.
    $msgDom   = (strpos($from, '@') !== false) ? substr(strrchr($from, '@'), 1) : 'zavod-red.ru';
    $headers  = "MIME-Version: 1.0\r\nDate: " . date('r') . "\r\n"
              . "From: {$from}\r\n"
              . "Reply-To: {$replyTo}\r\n"
              . "Message-ID: <" . bin2hex(random_bytes(12)) . "@{$msgDom}>\r\n"
              . "Content-Type: multipart/mixed; boundary=\"{$b}\"\r\n";
    $body  = "--{$b}\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n{$html}\r\n";
    $body .= "--{$b}\r\nContent-Type: application/octet-stream; name=\"{$safeName}\"\r\n"
           . "Content-Transfer-Encoding: base64\r\n"
           . "Content-Disposition: attachment; filename=\"{$safeName}\"\r\n\r\n";
    $body .= chunk_split(base64_encode($data)) . "\r\n--{$b}--";
    // Тема с кириллицей — только в MIME-кодировке (RFC 2047), иначе «(Без темы)».
    $encSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    // Конверт-отправитель — та же правка, что в helpers.php (17.08.2026): без -f
    // mail() ставит в конверт системный адрес хостинга, он не совпадает с From,
    // и письмо не проходит выравнивание у Яндекса. Подробное пояснение — там.
    $envelope = filter_var($from, FILTER_VALIDATE_EMAIL) ? '-f' . escapeshellarg($from) : '';
    $ok = $envelope !== ''
        ? @mail($to, $encSubject, $body, $headers, $envelope)
        : @mail($to, $encSubject, $body, $headers);
    return ['ok' => (bool)$ok, 'via' => 'mail+attach'];
}

// --- Email-уведомление (SMTP с фолбэком на mail(), результат фиксируется) ---
$mailCfg = cfg()['mail'] ?? [];
$to      = (string)($mailCfg['to'] ?? 'zr@zavod-red.ru');
$subject = ($isExisting ? 'ПОВТОРНО: ' : '') . 'Новая заявка с сайта zavod-red.ru';
$e       = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// Тема и тело — РОВНО как в версии, которая доставлялась. Телефон в теме и
// таблица с UTM/IP оказались спам-триггерами: письма перестали доходить.
// Менять здесь что-либо можно только по одному пункту с проверкой доставки.

$bodyHtml  = '<h2>' . ($isExisting ? 'ПОВТОРНОЕ обращение' : 'Новая заявка') . ' с сайта zavod-red.ru</h2>';
if ($isExisting) {
		$bodyHtml .= '<p style="color:#e67e22;font-weight:700;">⚠️ Клиент уже есть в базе — это повторная заявка. Предыдущие обращения в карточке лида.</p>';
	}
	$bodyHtml .= "<p><strong>Имя:</strong> {$e($name)}</p>";
$bodyHtml .= "<p><strong>Телефон:</strong> {$e($phone)}</p>";
if ($email !== '')       $bodyHtml .= "<p><strong>Email:</strong> {$e($email)}</p>";
if ($reducerType !== '') $bodyHtml .= "<p><strong>Тип:</strong> {$e($reducerType)}</p>";
if ($pageTitle !== '')   $bodyHtml .= "<p><strong>Страница:</strong> {$e($pageTitle)}</p>";
if ($message !== '')     $bodyHtml .= "<p><strong>Сообщение/Вопрос:</strong><br>" . nl2br($e($message)) . "</p>";
if ($source !== '')      $bodyHtml .= "<p><strong>Источник:</strong> {$e($source)}</p>";
if ($pageUrl !== '')     $bodyHtml .= "<p><strong>Ссылка:</strong> {$e($pageUrl)}</p>";
if ($fileOk)             $bodyHtml .= "<p><strong>📎 Вложение:</strong> " . $e($fileName)
                                    . " — файл в карточке заявки в CRM"
                                    . ($leadId ? " (ссылка ниже)" : "") . " и в Telegram.</p>";
if ($leadId)             $bodyHtml .= "<p><a href=\"https://zavod-red.ru/admin/lead.php?id={$leadId}\">Открыть заявку #{$leadId} в CRM</a></p>";

if ($dbDown) {
    $subject   = 'СРОЧНО: заявка мимо CRM — ' . $name . ', ' . $phone;
    $bodyHtml .= "<p style=\"color:#b31414\"><strong>База CRM была недоступна — заявка НЕ сохранена в системе.</strong><br>"
               . "Свяжитесь с клиентом и занесите заявку вручную после включения базы.</p>";
}

// Письмо уходит всегда: и по сохранённому лиду, и когда CRM недоступна —
// иначе при отключённой базе заявка не дошла бы до менеджера ни одним каналом.
$mailOutcome = 'письмо не отправлялось';
if (empty($_POST['__monitor'])) {
    // Отдельный try: отказ почты не должен ронять приём заявки.
    try {
        // $email — почта клиента из формы: уходит в Reply-To, чтобы «Ответить» вело
        // к клиенту, а Битрикс видел внешнего собеседника и заводил лид.
        // Вложение в письмо НЕ кладём (11.08.2026): mail+attach пропадали на Яндексе,
        // файл доступен по ссылке на карточку (в теле письма) и уходит в Telegram.
        $mailRes = zr_mail_send($to, $subject, $bodyHtml, '', '', $email);
        // Итог доставки кладём в журнал попыток — иначе понять, ушло письмо или нет,
        // можно было только через доступ к базе. Теперь видно прямо в «Попытках».
        // Reply-To видно в диагностике: именно по нему Битрикс определяет собеседника,
        // и именно из-за нашего же адреса в этом поле лиды раньше не создавались.
        $replyToUsed = ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL))
                     ? $email : zr_mail_from();
        $mailOutcome = ($mailRes['ok'] ? 'письмо отправлено' : 'ПИСЬМО НЕ УШЛО')
                     . ' (' . (string)($mailRes['via'] ?? '?') . ' → ' . $to
                     . '; from=' . zr_mail_from() . '; reply-to=' . $replyToUsed . ')'
                     . (!empty($mailRes['error']) ? '; ' . (string)$mailRes['error'] : '');
        // Фиксируем отправку письма (видно в ленте заявки и в диагностике).
        if ($leadId) audit($leadId, null, 'notify_email', ['ok' => $mailRes['ok'], 'to' => $to, 'via' => $mailRes['via']]);

        // --- Копия на почтовый ящик, подключённый к Битрикс24 ---
        // Битрикс создаёт лид ТОЛЬКО из письма, пришедшего с ЧУЖОГО для него домена:
        // письмо со своего же адреса он считает внутренним и лид не заводит. Поэтому
        // адрес приёма живёт на отдельном домене (infozr-crm.ru), который нигде больше
        // не используется и НЕ является отправителем. Адрес переопределяется настройкой
        // mail_to_crm в админке. Копия обёрнута в свой try: её сбой (нет MX, домен ещё
        // не разошёлся) не должен влиять на основное письмо менеджерам.
        // Пусто по умолчанию: сейчас lead@infozr-crm.ru — ОТПРАВИТЕЛЬ, и копия на него
        // же была бы письмом самому себе (Битрикс такое за обращение не считает).
        // Битрикс подключается к ящику-ПОЛУЧАТЕЛЮ (zr@zavod-red.ru): отправитель для
        // него внешний → лид создаётся. Если понадобится второй получатель — задать
        // адрес настройкой mail_to_crm в админке, код это подхватит.
        $toCrm = trim((string)secret('mail_to_crm', ''));
        if ($toCrm !== '' && strcasecmp($toCrm, $to) !== 0
            && strcasecmp($toCrm, zr_mail_from()) !== 0) {
            try {
                // Копия в CRM-ящик — тоже без вложения (та же причина, что и выше).
                $crmRes = zr_mail_send($toCrm, $subject, $bodyHtml, '', '');
                if ($leadId) audit($leadId, null, 'notify_email_crm',
                                   ['ok' => $crmRes['ok'], 'to' => $toCrm, 'via' => $crmRes['via']]);
            } catch (Throwable $eCrm) {
                if ($leadId) { try { audit($leadId, null, 'notify_email_crm',
                                     ['ok' => false, 'to' => $toCrm, 'error' => $eCrm->getMessage()]); }
                               catch (Throwable $e3) { /* ignore */ } }
            }
        }
    } catch (Throwable $eMail) {
        $mailOutcome = 'ОШИБКА ОТПРАВКИ: ' . $eMail->getMessage();
        if ($leadId) {
            try { audit($leadId, null, 'notify_email',
                        ['ok' => false, 'to' => $to, 'error' => $eMail->getMessage()]); }
            catch (Throwable $e2) { /* ignore */ }
        }
    }
}

// --- Заявка принята: фронт ждёт status:'success' ---
// Успех тоже пишем в журнал — тогда «Попытки» можно сверять с CRM один-в-один
// и сразу видеть расхождение (отправлено N, сохранено M).
if ($dbDown) {
    // Заявка ушла напрямую менеджеру. В журнале — отдельный статус, чтобы после
    // включения базы было видно, что занести в CRM руками.
    log_attempt('CRM недоступна — отправлено напрямую (почта' . ($tgToken !== '' ? ' + Telegram' : '') . ')',
                'direct', null);
} else {
    log_attempt(
        ($fileReject !== '' ? 'Вложение не принято: ' . $fileReject . '; ' : '') . $mailOutcome,
        'saved', $leadId ? (int)$leadId : null
    );
}
success($mailOutcome);
