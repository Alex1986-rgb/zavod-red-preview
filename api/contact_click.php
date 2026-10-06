<?php
declare(strict_types=1);
/**
 * /api/contact_click.php — приём «контактных» обращений с сайта.
 *
 * Форма — не единственный способ обратиться: посетитель звонит по tel:,
 * уходит в Telegram/MAX/WhatsApp, пишет на почту. Такие обращения раньше
 * нигде не фиксировались: Метрика считала их целью, а в CRM не было ничего.
 * Этот эндпоинт создаёт по ним заявку, чтобы менеджер видел ВСЕ обращения.
 *
 * POST (form-data или JSON), без CSRF (публичный, как feedback.php):
 *   kind      — phone | telegram | max | whatsapp | email  (обязательно)
 *   contact   — куда именно кликнули (номер/ссылка), необязательно
 *   page_url, referrer, utm_* — контекст страницы
 *
 * Дедупликация: повторные клики того же посетителя за DEDUP_WINDOW секунд
 * не плодят заявки — они дописываются в существующую как сообщения.
 */

require_once __DIR__ . '/inbox.php';

header('Content-Type: application/json; charset=utf-8');

/** Окно дедупликации кликов одного посетителя (секунды). */
const CLICK_DEDUP_WINDOW = 21600; // 6 часов

/** Человеческие подписи каналов. */
const CLICK_KINDS = [
    'phone'    => ['Звонок с сайта',      'click_phone'],
    'telegram' => ['Переход в Telegram',  'click_telegram'],
    'max'      => ['Переход в MAX',       'click_max'],
    'whatsapp' => ['Переход в WhatsApp',  'click_whatsapp'],
    'email'    => ['Клик по e-mail',      'click_email'],
];

function cc_out(bool $ok, string $msg = '', array $extra = []): never {
    json_out(['ok' => $ok, 'status' => $ok ? 'success' : 'error', 'message' => $msg] + $extra, 200);
}

// --- Клик по контакту СОЗДАЁТ заявку (решение владельца, 06.08.2026).
//     Раньше здесь стоял безусловный выход: считалось, что «голый» клик без формы —
//     не обращение. Практика показала обратное: клиенты пишут в мессенджер и на почту
//     напрямую, и такие обращения нужно видеть в разделе «Заявки», а не в журнале.
//     В Яндекс.Директе на это же действие настроена цель, поэтому расхождение между
//     «цель сработала» и «заявки нет» мешало считать рекламу.
//     Контакты посетителя при этом неизвестны — об этом прямо сказано в тексте заявки,
//     а дедупликация идёт по паре IP + браузер, чтобы серия кликов не плодила карточки.



// --- Вход: form-data или JSON ---
$in = $_POST;
if (!$in) {
    $raw = file_get_contents('php://input');
    $j = json_decode((string)$raw, true);
    if (is_array($j)) $in = $j;
}

$kind = strtolower(trim((string)($in['kind'] ?? '')));
if (!isset(CLICK_KINDS[$kind])) {
    cc_out(false, 'Неизвестный тип обращения');
}

$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

// Лимит частоты ОТКЛЮЧЁН 12.08.2026 по решению владельца — фиксируем каждое
// обращение по клику на контакт, без ограничения по адресу. Раньше стояло
// 20 кликов в час с IP. Дедупликация ниже (тот же IP + браузер в течение 6 часов)
// сохранена: без неё один посетитель, несколько раз нажавший «позвонить»,
// плодил бы одинаковые заявки.

[$label, $source] = CLICK_KINDS[$kind];

$contact   = substr(sanitize((string)($in['contact'] ?? '')), 0, 200);
$pageUrl   = substr(sanitize((string)($in['page_url'] ?? '')), 0, 1000);
$referrer  = substr(sanitize((string)($in['referrer'] ?? ($_SERVER['HTTP_REFERER'] ?? ''))), 0, 1000);
$utm       = parse_utm(is_array($in) ? $in : []);
$userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);

// Телефон/почта посетителя нам неизвестны — известно лишь, КУДА он обратился.
// Поэтому дедупликация идёт по паре IP + user-agent за окно времени.
$pdo = pdo();
$leadId = 0;

// Уже было обращение этого посетителя недавно? Дописываем в него.
try {
    $st = $pdo->prepare(
        "SELECT id FROM crm_leads
          WHERE source LIKE 'click\\_%' AND ip = ? AND user_agent = ?
            AND created_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)
          ORDER BY id DESC LIMIT 1"
    );
    $st->execute([$ip, $userAgent, CLICK_DEDUP_WINDOW]);
    $leadId = (int)$st->fetchColumn();
} catch (Throwable $e) { $leadId = 0; }

$isNew = false;
if ($leadId < 1) {
    try {
        $st = $pdo->prepare(
            'INSERT INTO crm_leads
                (created_at, updated_at, name, phone, email, source, status,
                 page_url, page_title, message,
                 utm_source, utm_medium, utm_campaign, utm_term, utm_content,
                 referrer, ip, user_agent)
             VALUES (NOW(), NOW(), ?, "", "", ?, "new", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            $label,
            $source,
            substr($pageUrl, 0, 1000),
            substr((string)($in['page_title'] ?? $label), 0, 255),
            $label . ($contact !== '' ? ' → ' . $contact : '')
                . ($pageUrl !== '' ? "\nСтраница: " . $pageUrl : '')
                . "\n\nПосетитель обратился напрямую (не через форму) — контакты неизвестны."
                . ' Свяжитесь по каналу обращения или дождитесь сообщения.',
            $utm['utm_source'], $utm['utm_medium'], $utm['utm_campaign'], $utm['utm_term'], $utm['utm_content'],
            $referrer, $ip, $userAgent,
        ]);
        $leadId = (int)$pdo->lastInsertId();
        $isNew = true;
    } catch (Throwable $e) {
        cc_out(false, 'Не удалось зафиксировать обращение');
    }
}

if ($leadId < 1) cc_out(false, 'Не удалось зафиксировать обращение');

// Сообщение в ленту переписки — видно в карточке лида и в «Ленте активности».
try {
    msg_insert($leadId, 'form', 'in',
        $label . ($contact !== '' ? ': ' . $contact : '') . ($pageUrl !== '' ? "\nСо страницы: " . $pageUrl : ''),
        ['contact' => $contact]);
} catch (Throwable $e) { /* не ломаем ответ */ }

try { audit($leadId, null, 'contact_click', ['kind' => $kind, 'contact' => $contact, 'page' => $pageUrl]); } catch (Throwable $e) {}

// Уведомления — только на первое обращение, чтобы не спамить менеджера.
// ПИСЬМО на клик по телефону/почте НЕ шлём: оно тонкое (без данных заказа) и
// задваивало реальную заявку с формы (feedback.php шлёт полное письмо сам).
// На почту уходит ТОЛЬКО настоящая заявка с формы; клик-обращение фиксируем
// лидом и мгновенным Telegram — этого достаточно, дубля в почте больше нет.
if ($isNew) {
    try { auto_assign($leadId); }      catch (Throwable $e) {}
    try { notify_new_lead($leadId); }  catch (Throwable $e) {}
}

cc_out(true, 'Обращение зафиксировано', ['lead_id' => $leadId, 'new' => $isNew]);
