<?php
declare(strict_types=1);

/**
 * /api/engineer.php — рабочее место инженера: проверка авто-подбора перед отправкой клиенту.
 * Требует авторизации + CSRF на изменяющих действиях.
 *
 *   GET  ?action=queue                 очередь заявок на проверку (picked|review|rework)
 *   GET  ?action=card&id=              карточка: лид + распознанные позиции/аналоги + история
 *   GET  ?action=mode                  текущий режим обработки (review|auto)
 *   POST ?action=recognize   id[,rework]   (re)распознать заявку (проксирует в ai.php-логику)
 *   POST ?action=approve     id        одобрить → отправить клиенту (email) → статус sent
 *   POST ?action=rework      id, note  вернуть на доработку с комментарием инженера
 *   POST ?action=comment     id, note  добавить комментарий инженера (заметка)
 *   POST ?action=mode        mode      (admin) переключить режим review|auto
 */

require_once __DIR__ . '/helpers.php';

$user = require_auth();
$uid  = (int)($user['id'] ?? 0);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? ($_POST['action'] ?? 'queue');

/** Последний структурный разбор заявки (crm_ai type=recognize) → массив или null. */
function eng_latest_recognize(int $leadId): ?array {
    try {
        $st = pdo()->prepare("SELECT content, created_at FROM crm_ai WHERE lead_id=? AND type='recognize' ORDER BY id DESC LIMIT 1");
        $st->execute([$leadId]);
        $row = $st->fetch();
        if (!$row) return null;
        $data = json_decode((string)$row['content'], true);
        if (!is_array($data)) return null;
        $data['_at'] = (string)$row['created_at'];
        return $data;
    } catch (Throwable $e) { return null; }
}

/** Маска e-mail для журнала аудита (a***@domain), чтобы не хранить PII в открытом виде. */
function eng_mask_email(string $e): string {
    $e = trim($e);
    $at = strpos($e, '@');
    if ($at === false || $at < 1) return $e === '' ? '' : '***';
    return substr($e, 0, 1) . '***' . substr($e, $at);
}

/** Отправить письмо клиенту (plain-text). true при успехе. */
function eng_send_email(array $lead, string $subject, string $body): bool {
    $to = trim((string)($lead['email'] ?? ''));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    // защита от header-injection: тема не должна содержать переводов строк
    $subject = str_replace(["\r", "\n"], ' ', $subject);
    $mailCfg = cfg()['mail'] ?? [];
    $from    = $mailCfg['from'] ?? 'no-reply@zavod-red.ru';
    $replyTo = $mailCfg['to'] ?? $from;
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=utf-8',
        'From: ' . $from,
        'Reply-To: ' . $replyTo,
    ];
    // Тот же путь, что у КП: smtp_send сам выбирает транспорт (mail_transport в Настройках)
    // и падает обратно на mail(). Голый mail() здесь обходил эту настройку.
    require_once __DIR__ . '/mail.php';
    if (function_exists('smtp_send')) {
        return smtp_send($to, $subject, nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8')), true);
    }
    $encSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    return @mail($to, $encSubject, $body, implode("\r\n", $headers));
}

try {
    if ($method === 'GET') {
        if ($action === 'card')      eng_card();
        elseif ($action === 'mode')  json_out(['ok' => true, 'mode' => setting('ai_mode', 'review'), 'auto_send' => setting('auto_send', '0')]);
        elseif ($action === 'count') eng_count();
        else                         eng_queue();
    } elseif ($method === 'POST') {
        csrf_check();
        switch ($action) {
            case 'approve':   eng_approve($uid);   break;
            case 'rework':    eng_rework($uid);    break;
            case 'comment':   eng_comment($uid);   break;
            case 'mode':      eng_set_mode($uid);  break;
            default: json_out(['ok' => false, 'error' => 'Неизвестное действие'], 400);
        }
    } else {
        json_out(['ok' => false, 'error' => 'Метод не поддерживается'], 405);
    }
} catch (Throwable $e) {
    error_log('engineer.php: ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'Внутренняя ошибка'], 500);
}

/** GET count — лёгкие счётчики «требуют действия» для колокольчика в шапке. */
function eng_count(): never {
    $pdo = pdo();
    $eng = 0; $new = 0; $overdue = 0;
    try { $eng = (int)$pdo->query("SELECT COUNT(*) FROM crm_leads WHERE status IN ('review','picked','rework')")->fetchColumn(); } catch (Throwable $e) {}
    try { $new = (int)$pdo->query("SELECT COUNT(*) FROM crm_leads WHERE status='new'")->fetchColumn(); } catch (Throwable $e) {}
    try { $overdue = (int)$pdo->query("SELECT COUNT(*) FROM crm_tasks WHERE done=0 AND due_at IS NOT NULL AND due_at < NOW()")->fetchColumn(); } catch (Throwable $e) {}
    json_out(['ok' => true, 'engineer' => $eng, 'new' => $new, 'overdue' => $overdue, 'total' => $eng + $new + $overdue]);
}

/** GET queue — заявки, ждущие инженера. */
function eng_queue(): never {
    $pdo = pdo();
    $st = $pdo->prepare(
        "SELECT l.*, u.name AS manager_name
         FROM crm_leads l LEFT JOIN crm_users u ON u.id = l.manager_id
         WHERE l.status IN ('picked','review','rework')
         ORDER BY FIELD(l.status,'rework','review','picked'), l.updated_at DESC
         LIMIT 100"
    );
    $st->execute();
    $rows = $st->fetchAll();

    // Последнее распознавание для ВСЕХ лидов очереди одним запросом (без N+1).
    $recMap = [];
    $ids = array_map(static fn($r) => (int)$r['id'], $rows);
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stR = $pdo->prepare(
            "SELECT a.lead_id, a.content FROM crm_ai a
             INNER JOIN (SELECT lead_id, MAX(id) mid FROM crm_ai
                         WHERE type='recognize' AND lead_id IN ($ph) GROUP BY lead_id) m
             ON a.id = m.mid"
        );
        $stR->execute($ids);
        foreach ($stR->fetchAll() as $r) {
            $d = json_decode((string)$r['content'], true);
            if (is_array($d)) $recMap[(int)$r['lead_id']] = $d;
        }
    }

    $items = [];
    foreach ($rows as $l) {
        $rec = $recMap[(int)$l['id']] ?? null;
        $items[] = [
            'id'          => (int)$l['id'],
            'name'        => (string)($l['name'] ?: '—'),
            'phone'       => (string)($l['phone'] ?? ''),
            'email'       => (string)($l['email'] ?? ''),
            'reducer_type'=> (string)($l['reducer_type'] ?? ''),
            'source'      => (string)($l['source'] ?: $l['utm_source'] ?? ''),
            'status'      => (string)$l['status'],
            'status_label'=> status_label((string)$l['status']),
            'manager'     => (string)($l['manager_name'] ?? ''),
            'file_path'   => (string)($l['file_path'] ?? ''),
            'updated_at'  => (string)$l['updated_at'],
            'confidence'  => $rec ? (int)($rec['confidence'] ?? 0) : null,
            'positions'   => $rec ? count((array)($rec['positions'] ?? [])) : 0,
            'summary'     => $rec ? (string)($rec['summary'] ?? '') : '',
        ];
    }
    // Счётчики по статусам очереди
    $stc = $pdo->query("SELECT status, COUNT(*) c FROM crm_leads WHERE status IN ('picked','review','rework') GROUP BY status");
    $counts = ['picked' => 0, 'review' => 0, 'rework' => 0];
    foreach ($stc->fetchAll() as $r) $counts[(string)$r['status']] = (int)$r['c'];
    json_out(['ok' => true, 'items' => $items, 'counts' => $counts, 'mode' => setting('ai_mode', 'review')]);
}

/** GET card&id — полная карточка для проверки. */
function eng_card(): never {
    $id = (int)($_GET['id'] ?? 0);
    if ($id < 1) json_out(['ok' => false, 'error' => 'Не указан id'], 400);
    $pdo = pdo();
    $st = $pdo->prepare(
        "SELECT l.*, u.name AS manager_name FROM crm_leads l
         LEFT JOIN crm_users u ON u.id = l.manager_id WHERE l.id = ?"
    );
    $st->execute([$id]);
    $lead = $st->fetch();
    if (!$lead) json_out(['ok' => false, 'error' => 'Лид не найден'], 404);

    $rec = eng_latest_recognize($id);

    // Комментарии/заметки (история правок)
    $notes = [];
    try {
        $stN = $pdo->prepare(
            "SELECT n.text, n.created_at, u.name AS user_name FROM crm_notes n
             LEFT JOIN crm_users u ON u.id = n.user_id WHERE n.lead_id = ?
             ORDER BY n.created_at DESC, n.id DESC LIMIT 50"
        );
        $stN->execute([$id]);
        $notes = $stN->fetchAll();
    } catch (Throwable $e) { $notes = []; }

    // История смены статусов
    $history = [];
    try {
        $stH = $pdo->prepare(
            "SELECT e.type, e.payload, e.created_at, u.name AS user_name FROM crm_events e
             LEFT JOIN crm_users u ON u.id = e.user_id
             WHERE e.lead_id = ? AND e.type IN ('status_changed','email_sent','ai_recognize')
             ORDER BY e.created_at DESC, e.id DESC LIMIT 50"
        );
        $stH->execute([$id]);
        foreach ($stH->fetchAll() as $h) {
            $p = $h['payload'] ? json_decode((string)$h['payload'], true) : null;
            $label = (string)$h['type'];
            if ($label === 'status_changed' && is_array($p)) {
                $label = status_label((string)($p['from'] ?? '')) . ' → ' . status_label((string)($p['to'] ?? ''));
            } elseif ($label === 'email_sent') {
                $label = 'Отправлено клиенту' . (is_array($p) && !empty($p['subject']) ? ': ' . $p['subject'] : '');
            } elseif ($label === 'ai_recognize') {
                $label = 'ИИ распознал заявку';
            }
            $history[] = [
                'label'     => $label,
                'user_name' => (string)($h['user_name'] ?? ''),
                'created_at'=> (string)$h['created_at'],
            ];
        }
    } catch (Throwable $e) { $history = []; }

    json_out([
        'ok'      => true,
        'lead'    => [
            'id'          => (int)$lead['id'],
            'name'        => (string)($lead['name'] ?? ''),
            'phone'       => (string)($lead['phone'] ?? ''),
            'email'       => (string)($lead['email'] ?? ''),
            'reducer_type'=> (string)($lead['reducer_type'] ?? ''),
            'message'     => (string)($lead['message'] ?? ''),
            'file_path'   => (string)($lead['file_path'] ?? ''),
            'source'      => (string)($lead['source'] ?: $lead['utm_source'] ?? ''),
            'status'      => (string)$lead['status'],
            'status_label'=> status_label((string)$lead['status']),
            'manager'     => (string)($lead['manager_name'] ?? ''),
            'created_at'  => (string)$lead['created_at'],
        ],
        'recognition' => $rec,
        // За сколько уже продавали те же ZR (из наших счетов/КП) — подсказка инженеру, не цена клиенту.
        'price_hints' => (static function (int $lid): array { try { require_once __DIR__ . '/price_hist.php'; return ph_hint_lines(ph_lead_zr($lid)); } catch (Throwable $e) { return []; } })((int)$lead['id']),
        'notes'       => $notes,
        'history'     => $history,
    ]);
}

/** POST approve — одобрить и отправить клиенту. */
function eng_approve(int $uid): never {
    $id = (int)($_POST['id'] ?? 0);
    if ($id < 1) json_out(['ok' => false, 'error' => 'Не указан id'], 400);
    $pdo = pdo();
    $st = $pdo->prepare('SELECT * FROM crm_leads WHERE id = ?');
    $st->execute([$id]);
    $lead = $st->fetch();
    if (!$lead) json_out(['ok' => false, 'error' => 'Лид не найден'], 404);

    $from = (string)$lead['status'];

    // Защита от повторной обработки терминальных статусов (иначе клиенту уйдёт дубль КП).
    if (in_array($from, ['sent', 'won', 'lost'], true)) {
        json_out(['ok' => false, 'error' => 'Заявка уже обработана (статус: ' . status_label($from) . '). Повторная отправка отменена.'], 409);
    }

    // АТОМАРНЫЙ захват: review→approved одним UPDATE с условием. При двойном клике /
    // двух инженерах реально сработает только один запрос (rowCount==1); второй получит 0
    // и будет отклонён — так исключается двойная отправка письма клиенту.
    // Исключение: повторная попытка для уже 'approved' лида (напр. письмо не ушло) — разрешаем.
    if ($from !== 'approved') {
        $claim = $pdo->prepare("UPDATE crm_leads SET status='approved', updated_at=NOW() WHERE id=? AND status NOT IN ('sent','approved','won','lost')");
        $claim->execute([$id]);
        if ($claim->rowCount() !== 1) {
            json_out(['ok' => false, 'error' => 'Заявка уже обрабатывается или обработана. Повторная отправка отменена.'], 409);
        }
        audit($id, $uid, 'status_changed', ['from' => $from, 'to' => 'approved']);
    }

    $rec  = eng_latest_recognize($id);

    // Текст письма: приоритет — правка инженера из POST, иначе черновик ИИ.
    $body = trim((string)($_POST['body'] ?? ''));
    if ($body === '' && $rec) $body = (string)($rec['draft'] ?? '');
    $subject = trim((string)($_POST['subject'] ?? 'Завод Редукторов — по вашей заявке'));

    $sent = false; $emailErr = '';
    $doSend = ($_POST['send'] ?? '1') !== '0';
    if ($doSend && $body !== '') {
        $sent = eng_send_email($lead, $subject, $body);
        if ($sent) {
            $pdo->prepare("UPDATE crm_leads SET status='sent', updated_at=NOW() WHERE id=? AND status='approved'")->execute([$id]);
            audit($id, $uid, 'status_changed', ['from' => 'approved', 'to' => 'sent']);
            audit($id, $uid, 'email_sent', ['to' => eng_mask_email((string)$lead['email']), 'subject' => $subject]);
            // лог как сообщение в переписку
            try {
                $pdo->prepare("INSERT INTO crm_messages (lead_id,channel,direction,contact,subject,body,created_at) VALUES (?,?,?,?,?,?,NOW())")
                    ->execute([$id, 'email', 'out', (string)$lead['email'], $subject, $body]);
            } catch (Throwable $e) { /* опц. */ }
        } else {
            $emailErr = 'Одобрено, но письмо не отправилось (проверьте email клиента / SMTP).';
        }
    }
    // комментарий инженера при одобрении (опц.)
    $note = trim((string)($_POST['note'] ?? ''));
    if ($note !== '') $pdo->prepare("INSERT INTO crm_notes (lead_id,user_id,text,created_at) VALUES (?,?,?,NOW())")->execute([$id, $uid, '✔ Одобрено: ' . $note]);

    json_out(['ok' => true, 'sent' => $sent, 'status' => $sent ? 'sent' : 'approved', 'warn' => $emailErr]);
}

/** POST rework — вернуть на доработку. */
function eng_rework(int $uid): never {
    $id   = (int)($_POST['id'] ?? 0);
    $note = trim((string)($_POST['note'] ?? ''));
    if ($id < 1) json_out(['ok' => false, 'error' => 'Не указан id'], 400);
    if ($note === '') json_out(['ok' => false, 'error' => 'Опишите, что исправить'], 400);
    $pdo = pdo();
    $st = $pdo->prepare('SELECT status FROM crm_leads WHERE id = ?');
    $st->execute([$id]);
    $from = $st->fetchColumn();
    if ($from === false) json_out(['ok' => false, 'error' => 'Лид не найден'], 404);

    $pdo->prepare("UPDATE crm_leads SET status='rework', updated_at=NOW() WHERE id=?")->execute([$id]);
    if ((string)$from !== 'rework') audit($id, $uid, 'status_changed', ['from' => (string)$from, 'to' => 'rework']);
    $pdo->prepare("INSERT INTO crm_notes (lead_id,user_id,text,created_at) VALUES (?,?,?,NOW())")
        ->execute([$id, $uid, '✏ На доработку: ' . $note]);
    json_out(['ok' => true, 'status' => 'rework']);
}

/** POST comment — комментарий инженера. */
function eng_comment(int $uid): never {
    $id   = (int)($_POST['id'] ?? 0);
    $note = trim((string)($_POST['note'] ?? ''));
    if ($id < 1) json_out(['ok' => false, 'error' => 'Не указан id'], 400);
    if ($note === '') json_out(['ok' => false, 'error' => 'Пустой комментарий'], 400);
    pdo()->prepare("INSERT INTO crm_notes (lead_id,user_id,text,created_at) VALUES (?,?,?,NOW())")
        ->execute([$id, $uid, $note]);
    audit($id, $uid, 'note_added', []);
    json_out(['ok' => true]);
}

/** POST mode — переключить режим обработки (admin). */
function eng_set_mode(int $uid): never {
    require_auth('admin');
    $mode = (string)($_POST['mode'] ?? 'review');
    if (!in_array($mode, ['review', 'auto'], true)) $mode = 'review';
    $autoSend = ($_POST['auto_send'] ?? null);
    $pdo = pdo();
    $pdo->prepare("INSERT INTO crm_settings (skey,sval) VALUES ('ai_mode',?) ON DUPLICATE KEY UPDATE sval=VALUES(sval)")
        ->execute([$mode]);
    if ($autoSend !== null) {
        $v = ($autoSend === '1' || $autoSend === 1 || $autoSend === true) ? '1' : '0';
        $pdo->prepare("INSERT INTO crm_settings (skey,sval) VALUES ('auto_send',?) ON DUPLICATE KEY UPDATE sval=VALUES(sval)")
            ->execute([$v]);
    }
    audit(null, $uid, 'settings_saved', ['ai_mode' => $mode]);
    json_out(['ok' => true, 'mode' => $mode, 'auto_send' => setting('auto_send', '0')]);
}
