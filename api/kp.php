<?php
declare(strict_types=1);

/**
 * /api/kp.php — конструктор коммерческих предложений.
 *   GET  ?action=get&lead_id=      последнее сохранённое КП по лиду (позиции/условия/итого)
 *   POST ?action=save (csrf)       lead_id, items(json), terms(json), total → сохранить + обновить сумму сделки
 *   POST ?action=send (csrf)       lead_id → отправить КП клиенту (email) + статус 'sent' + запись в переписку
 * Таблица crm_kp создаётся автоматически.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/inbox.php'; // msg_insert

function kp_ensure(): void {
    static $done = false;
    if ($done) return;
    pdo()->exec(
        "CREATE TABLE IF NOT EXISTS crm_kp (
            id INT AUTO_INCREMENT PRIMARY KEY,
            lead_id INT NOT NULL,
            kp_no VARCHAR(32) NOT NULL DEFAULT '',
            items MEDIUMTEXT NULL,
            terms MEDIUMTEXT NULL,
            total DECIMAL(12,2) NOT NULL DEFAULT 0,
            author VARCHAR(120) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_lead (lead_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $done = true;
}

$user   = require_auth();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? ($_POST['action'] ?? 'get');

try {
    kp_ensure();
    if ($method === 'GET') {
        kp_get();
    } else {
        csrf_check();
        if ($action === 'save') kp_save($user);
        elseif ($action === 'send') kp_send($user);
        else json_out(['ok' => false, 'error' => 'Неизвестное действие'], 400);
    }
} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => 'Внутренняя ошибка'], 500);
}

function kp_get(): void {
    $leadId = (int)($_GET['lead_id'] ?? 0);
    $st = pdo()->prepare("SELECT * FROM crm_kp WHERE lead_id=? ORDER BY id DESC LIMIT 1");
    $st->execute([$leadId]);
    $row = $st->fetch();
    if ($row) {
        $row['items'] = json_decode((string)$row['items'], true) ?: [];
        $row['terms'] = json_decode((string)$row['terms'], true) ?: [];
    }
    json_out(['ok' => true, 'kp' => $row ?: null]);
}

function kp_save(array $user): void {
    $leadId = (int)($_POST['lead_id'] ?? 0);
    if ($leadId < 1) json_out(['ok' => false, 'error' => 'Не указан лид'], 400);
    $items = json_decode((string)($_POST['items'] ?? '[]'), true);
    $terms = json_decode((string)($_POST['terms'] ?? '{}'), true);
    if (!is_array($items)) $items = [];
    if (!is_array($terms)) $terms = [];
    // пересчёт итого на сервере (доверяем не клиенту)
    $total = 0.0;
    $nf = fn($v) => (float)str_replace([' ', ','], ['', '.'], (string)$v);
    foreach ($items as $it) {
        $price = $nf($it['price'] ?? 0);
        if ($price <= 0) continue; // позиции «цена по запросу» не входят в итог
        $total += $nf($it['qty'] ?? 0) * $price;
    }
    $kpNo = sprintf('ЗР-%05d', $leadId);
    $author = (string)($user['name'] ?? $user['login'] ?? '');
    $itemsJson = json_encode($items, JSON_UNESCAPED_UNICODE);
    $termsJson = json_encode($terms, JSON_UNESCAPED_UNICODE);

    $st = pdo()->prepare("SELECT id FROM crm_kp WHERE lead_id=? ORDER BY id DESC LIMIT 1");
    $st->execute([$leadId]);
    $existing = (int)($st->fetchColumn() ?: 0);
    if ($existing) {
        pdo()->prepare("UPDATE crm_kp SET items=?, terms=?, total=?, updated_at=NOW() WHERE id=?")
             ->execute([$itemsJson, $termsJson, $total, $existing]);
        $kpId = $existing;
    } else {
        pdo()->prepare("INSERT INTO crm_kp (lead_id,kp_no,items,terms,total,author) VALUES (?,?,?,?,?,?)")
             ->execute([$leadId, $kpNo, $itemsJson, $termsJson, $total, $author]);
        $kpId = (int)pdo()->lastInsertId();
    }
    // обновляем сумму сделки в лиде
    try { pdo()->prepare("UPDATE crm_leads SET amount=?, updated_at=NOW() WHERE id=?")->execute([$total, $leadId]); } catch (Throwable $e) {}
    audit($leadId, (int)($user['id'] ?? 0), 'kp_saved', ['total' => $total, 'positions' => count($items)]);

    json_out(['ok' => true, 'kp_id' => $kpId, 'total' => $total, 'kp_no' => $kpNo]);
}

function kp_send(array $user): void {
    $leadId = (int)($_POST['lead_id'] ?? 0);
    $st = pdo()->prepare("SELECT * FROM crm_leads WHERE id=?");
    $st->execute([$leadId]);
    $lead = $st->fetch();
    if (!$lead) json_out(['ok' => false, 'error' => 'Лид не найден'], 404);
    $email = trim((string)($lead['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_out(['ok' => false, 'error' => 'У клиента нет корректного e-mail'], 400);

    $stk = pdo()->prepare("SELECT * FROM crm_kp WHERE lead_id=? ORDER BY id DESC LIMIT 1");
    $stk->execute([$leadId]);
    $kp = $stk->fetch();
    if (!$kp) json_out(['ok' => false, 'error' => 'Сначала сохраните КП'], 400);
    $items = json_decode((string)$kp['items'], true) ?: [];
    $total = (float)$kp['total'];

    $lines = [];
    $pricedTotal = 0.0; // итог только по позициям с ценой; «по запросу» не считаем
    $hasPriced = false;
    foreach ($items as $it) {
        $name = trim((string)($it['name'] ?? ''));
        if ($name === '') continue;
        $qty = (float)str_replace([' ', ','], ['', '.'], (string)($it['qty'] ?? 0));
        $price = (float)str_replace([' ', ','], ['', '.'], (string)($it['price'] ?? 0));
        $qtyStr = rtrim(rtrim(number_format($qty, 2, ',', ' '), '0'), ',');
        if ($price <= 0) {
            // цена не указана — не пугаем клиента «0 ₽»
            $lines[] = "• {$name} — {$qtyStr} шт — цена по запросу";
            continue;
        }
        $sum = $qty * $price;
        $pricedTotal += $sum;
        $hasPriced = true;
        $lines[] = "• {$name} — {$qtyStr} шт × "
                 . number_format($price, 0, ',', ' ') . " ₽ = " . number_format($sum, 0, ',', ' ') . " ₽";
    }
    // КП без позиций отправлять нельзя — клиенту уйдёт пустое письмо.
    if (!$lines) json_out(['ok' => false, 'error' => 'КП пустое — добавьте хотя бы одну позицию перед отправкой'], 400);
    // Терминальные статусы: не воскрешаем отказ (lost) и закрытую сделку (won).
    // Статус 'sent' НЕ блокирует: его ставит и инженер при ответе клиенту, и КП после
    // этого отправить было нельзя. Повтор ловим по факту доставки именно этого КП.
    $curStatus = (string)$lead['status'];
    if (in_array($curStatus, ['won', 'lost'], true)) {
        json_out(['ok' => false, 'error' => 'Заявка уже в статусе «' . status_label($curStatus) . '». Отправка КП отменена.'], 409);
    }
    $dupSt = pdo()->prepare("SELECT 1 FROM crm_events WHERE lead_id = ? AND type = 'kp_sent' AND payload LIKE ? AND payload LIKE '%\"delivered\":true%' LIMIT 1");
    $dupSt->execute([$leadId, '%"kp_no":' . json_encode((string)$kp['kp_no'], JSON_UNESCAPED_UNICODE) . '%']);
    if ($dupSt->fetchColumn() && ($_POST['force'] ?? '') !== '1') {
        json_out(['ok' => false, 'error' => 'КП № ' . $kp['kp_no'] . ' уже отправлено клиенту. Измените КП или отправьте повторно осознанно.'], 409);
    }
    // Идемпотентность: не более одной отправки КП по лиду за 10 секунд (защита от двойного клика).
    if (!rate_limit('kpsend:' . $leadId, 1, 10)) {
        json_out(['ok' => false, 'error' => 'КП уже отправляется, подождите'], 429);
    }
    $me = (string)($user['name'] ?? 'Менеджер');
    // «Итого» печатаем только если есть хотя бы одна позиция с ценой.
    $itemsBlock = implode("\n", $lines) . "\n"
        . ($hasPriced ? ("\nИтого: " . number_format($pricedTotal, 0, ',', ' ') . " ₽\n") : "")
        . "\n";
    $body = "Здравствуйте!\n\nНаправляем коммерческое предложение № {$kp['kp_no']} от Завода Редукторов.\n\n"
        . $itemsBlock
        . kp_terms_text(is_array($kp['terms'] ?? null) ? $kp['terms'] : (json_decode((string)($kp['terms'] ?? ''), true) ?: []))
        . "Готовы ответить на вопросы и уточнить спецификацию.\n\n"
        . "С уважением,\n{$me}\nЗавод Редукторов · ООО «НИИ АТТ» · +7 (495) 151-41-02";

    $subject = "Коммерческое предложение № {$kp['kp_no']} — Завод Редукторов";
    $sent = false;
    if (is_file(__DIR__ . '/mail.php')) {
        require_once __DIR__ . '/mail.php';
        if (function_exists('smtp_send')) $sent = smtp_send($email, $subject, nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8')), true);
    }
    msg_insert($leadId, 'email', 'out', $body, ['contact' => $email, 'subject' => $subject]);
    // Статус → 'sent' ТОЛЬКО при реальной отправке и только из нетерминального статуса.
    // Если SMTP не настроен/не сработал — КП записано в переписку, но статус не двигаем
    // (иначе лид помечен «отправлено», хотя письмо не ушло, и никто не переотправит).
    if ($sent) {
        pdo()->prepare("UPDATE crm_leads SET status='sent', updated_at=NOW() WHERE id=? AND status NOT IN ('won','lost','sent')")->execute([$leadId]);
    }
    audit($leadId, (int)($user['id'] ?? 0), 'kp_sent', ['email' => $email, 'total' => $total, 'delivered' => $sent, 'kp_no' => (string)$kp['kp_no']]);

    json_out(['ok' => true, 'sent' => $sent, 'note' => $sent ? 'КП отправлено клиенту' : 'КП записано в переписку (SMTP не настроен — статус не изменён)']);
}

/** Условия КП для письма — из того, что менеджер вписал в КП (раньше в письме был жёсткий текст). */
function kp_terms_text(array $t): string {
    $rows = [
        'Гарантия' => trim((string)($t['warranty'] ?? '')) ?: '36 месяцев',
        'Срок отгрузки' => trim((string)($t['term'] ?? '')) ?: 'от 3 рабочих дней',
        'Условия оплаты' => trim((string)($t['pay'] ?? '')),
        'Предложение действительно' => trim((string)($t['valid'] ?? '')),
    ];
    $out = '';
    foreach ($rows as $k => $v) if ($v !== '') $out .= $k . ': ' . $v . "\n";
    return $out . "Доставка по РФ.\n";
}
