<?php
declare(strict_types=1);

/**
 * POST /api/voice_context.php — «кто звонит»: история клиента для голосового робота.
 * Тело: {"phone":"+7…"} + секрет (см. voice_hook.php).
 *
 * Робот зовёт это в начале звонка (по номеру звонящего) и когда клиент говорит «я вам писал».
 * Возвращает коротко и по делу: заявки и их подбор (аналог ZR, чего не хватает), последние
 * письма и звонки, что было во вложениях (шильдик, чертёж), решение инженера.
 *
 * ЦЕН здесь нет и быть не должно: робот цену не называет (правило с 19.08.2026), поэтому суммы
 * из переписки и документов вырезаются ещё в CRM — модели нечего повторить.
 */

require_once __DIR__ . '/voice_hook.php';
require_once __DIR__ . '/inbox.php';

/** Убрать суммы и цены из текста для робота. */
function vc_no_prices(string $t): string {
    $t = preg_replace('/\d[\d\s.,]*\s*(₽|руб\.?|рублей|рубля|р\.|тыс\.?\s*руб|млн)/iu', '[сумма скрыта]', $t) ?? $t;
    $t = preg_replace('/(цена|стоимость|итого|сумма)\s*[:\-–]?\s*\d[\d\s.,]*/iu', '$1 [скрыто]', $t) ?? $t;
    return $t;
}

function vc_short(string $t, int $n): string {
    $t = trim(preg_replace('/\s+/u', ' ', $t) ?? $t);
    return mb_strlen($t, 'UTF-8') > $n ? mb_substr($t, 0, $n, 'UTF-8') . '…' : $t;
}

/** Контекст клиента по телефону (и по e-mail его заявок). Пустой known=false — клиент новый. */
function voice_client_context(string $phoneRaw): array {
    $pdo = pdo();
    $phone = normalize_phone($phoneRaw);
    if (strlen($phone) < 10) return ['known' => false, 'reason' => 'номер не распознан'];

    $st = $pdo->prepare(
        "SELECT * FROM crm_leads
         WHERE RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone,' ',''),'(',''),')',''),'-',''),'+',''), 10) = RIGHT(?, 10)
         ORDER BY id DESC LIMIT 5"
    );
    $st->execute([$phone]);
    $leads = $st->fetchAll(PDO::FETCH_ASSOC);
    // Тот же клиент мог писать с почты без телефона — подтягиваем заявки по e-mail найденных.
    $emails = array_values(array_unique(array_filter(array_map(static fn($l) => strtolower(trim((string)$l['email'])), $leads))));
    if ($emails) {
        $in = implode(',', array_fill(0, count($emails), '?'));
        $st = $pdo->prepare("SELECT * FROM crm_leads WHERE LOWER(email) IN ($in) ORDER BY id DESC LIMIT 5");
        $st->execute($emails);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) $leads[] = $l;
    }
    $byId = [];
    foreach ($leads as $l) $byId[(int)$l['id']] = $l;
    krsort($byId);
    $byId = array_slice($byId, 0, 5, true);
    if (!$byId) return ['known' => false];

    $ids = array_keys($byId);
    $in = implode(',', $ids);
    $first = reset($byId);
    $out = [
        'known'  => true,
        'client' => ['name' => (string)$first['name'], 'email' => (string)$first['email'], 'leads' => count($byId)],
        'leads'  => [],
        'recent' => [],
        'files'  => [],
    ];

    foreach ($byId as $id => $l) {
        $row = ['id' => $id, 'date' => substr((string)$l['created_at'], 0, 10), 'status' => status_label((string)$l['status'])];
        if (!empty($l['reducer_type'])) $row['interest'] = (string)$l['reducer_type'];
        $a = $pdo->query("SELECT content FROM crm_ai WHERE lead_id=$id AND type IN ('recognize','nameplate','suggest') ORDER BY id DESC LIMIT 1")->fetchColumn();
        $d = $a ? json_decode((string)$a, true) : null;
        if (is_array($d)) {
            if (!empty($d['summary'])) $row['selection'] = vc_no_prices((string)$d['summary']);
            foreach ((array)($d['analogs'] ?? []) as $x) $row['analogs'][] = trim(($x['for'] ?? '') . ' → ' . ($x['our'] ?? '') . (!empty($x['match']) ? ' (' . $x['match'] . ')' : ''));
            if (!empty($d['missing'])) $row['missing'] = array_values(array_map('strval', (array)$d['missing']));
        }
        $n = $pdo->query("SELECT text FROM crm_notes WHERE lead_id=$id ORDER BY id DESC LIMIT 1")->fetchColumn();
        if ($n) $row['engineer_note'] = vc_short(vc_no_prices((string)$n), 250);
        $out['leads'][] = $row;
    }

    foreach ($pdo->query("SELECT channel, direction, subject, body, created_at FROM crm_messages
                          WHERE lead_id IN ($in) AND folder NOT IN ('drafts','trash','spam')
                          ORDER BY created_at DESC, id DESC LIMIT 6") as $m) {
        $out['recent'][] = [
            'date' => substr((string)$m['created_at'], 0, 16),
            'who'  => $m['direction'] === 'in' ? 'клиент' : 'мы',
            'via'  => (string)$m['channel'],
            'subject' => vc_short((string)$m['subject'], 90),
            'text' => vc_short(vc_no_prices(function_exists('mime_strip_quote') ? mime_strip_quote((string)$m['body']) : (string)$m['body']), 300),
        ];
    }

    try {
        foreach ($pdo->query("SELECT filename, kind, summary, direction, created_at FROM crm_mail_files
                              WHERE lead_id IN ($in) AND status='done' AND kind IN ('nameplate','drawing','spec','photo')
                              ORDER BY id DESC LIMIT 5") as $f) {
            $out['files'][] = ['date' => substr((string)$f['created_at'], 0, 10), 'file' => (string)$f['filename'],
                               'kind' => (string)$f['kind'], 'what' => vc_short(vc_no_prices((string)$f['summary']), 200)];
        }
    } catch (Throwable $e) { /* таблицы ещё нет */ }

    // Одна фраза-сводка, с которой робот может начать: «вижу ваше обращение от … про …».
    $l0 = $out['leads'][0];
    $out['summary'] = 'Клиент уже обращался (' . count($out['leads']) . ' заявк.). Последняя ' . $l0['date'] . ', статус «' . $l0['status'] . '»'
        . (!empty($l0['selection']) ? '. Подбор: ' . $l0['selection'] : '')
        . ($out['files'] ? '. Присылал: ' . implode(', ', array_map(static fn($f) => $f['file'] . ' (' . $f['what'] . ')', array_slice($out['files'], 0, 2))) : '')
        . '. Цены и сроки не называть — только инженер.';
    return $out;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    $in = voice_hook_read();
    require_once __DIR__ . '/mime.php';
    try {
        echo json_encode(['ok' => true] + voice_client_context((string)($in['phone'] ?? '')), JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('voice_context: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'internal']);
    }
}
