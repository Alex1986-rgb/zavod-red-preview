<?php
declare(strict_types=1);

/**
 * Битрикс24: результаты робота — в поле «Инженер» сделки.
 *
 * Менеджеры работают в Битриксе: на каждую заявку там есть сделка, а в сделке — текстовое
 * поле «Инженер». Сюда CRM сама пишет всё, что подобрал робот и решил инженер: распознанные
 * позиции, аналог ZR, чего не хватает, решение и комментарий инженера, вложения (шильдики,
 * чертежи, счета), звонки робота. В админке CRM всё остаётся как было — это дублирование.
 *
 * Как устроено:
 *   - доступ — входящий вебхук Битрикс24 (Настройки → Интеграции, ключ b24_webhook), права «CRM»;
 *   - поле «Инженер» находится само по названию (crm.deal.fields), код кэшируется;
 *   - сделка по заявке: сохранённый b24_deal_id, иначе поиск контакта/компании/лида Битрикса
 *     по телефону и e-mail клиента (crm.duplicate.findbycomm) → последняя открытая сделка;
 *   - робот пишет ТОЛЬКО свой блок между метками «▼ ZR-робот» … «▲ конец блока робота».
 *     Текст, который менеджеры написали в поле сами, не трогается;
 *   - что обновлять: заявки, где после прошлой выгрузки появился подбор, заметка, смена
 *     статуса, файл из письма или звонок (b24_sync_due). Крон: api/bitrix_sync.php.
 */

require_once __DIR__ . '/helpers.php';

const B24_MARK_START = '▼ ZR-робот';
const B24_MARK_END = '▲ конец блока робота';

function b24_webhook(): string {
    $u = trim(secret('b24_webhook', (string)(cfg()['bitrix']['webhook'] ?? '')));
    return $u === '' ? '' : rtrim($u, '/') . '/';
}

function b24_ready(): bool { return b24_webhook() !== ''; }

/** Вызов REST-метода. Бросает RuntimeException с понятным текстом. */
function b24_call(string $method, array $params = []): array {
    $base = b24_webhook();
    if ($base === '') throw new RuntimeException('Битрикс24 не подключён: нет вебхука (Настройки → Интеграции)');
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $ch = curl_init($base . $method . '.json');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30,
        ]);
        $raw = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $d = is_string($raw) ? json_decode($raw, true) : null;
        // QUERY_LIMIT_EXCEEDED — Битрикс просит не чаще 2 запросов в секунду.
        if (($d['error'] ?? '') === 'QUERY_LIMIT_EXCEEDED' || $http === 503) { usleep(700000 * $attempt); continue; }
        if ($raw === false) throw new RuntimeException('Битрикс24: сеть — ' . $err);
        if (!is_array($d)) throw new RuntimeException('Битрикс24: ответ не JSON (HTTP ' . $http . ')');
        if (isset($d['error'])) throw new RuntimeException('Битрикс24: ' . ($d['error_description'] ?? $d['error']));
        return $d;
    }
    throw new RuntimeException('Битрикс24: превышен лимит запросов');
}

/** Код поля «Инженер» в сделке (UF_CRM_…). Ищется по названию, кэшируется в настройках. */
function b24_engineer_field(bool $refresh = false): string {
    $cached = (string)setting('b24_engineer_field', '');
    if ($cached !== '' && !$refresh) return $cached;
    $fields = b24_call('crm.deal.fields')['result'] ?? [];
    $want = mb_strtolower(trim((string)setting('b24_engineer_field_name', 'Инженер')), 'UTF-8');
    $found = '';
    foreach ($fields as $code => $f) {
        if (!str_starts_with((string)$code, 'UF_CRM_')) continue;
        foreach (['formLabel', 'listLabel', 'filterLabel', 'title'] as $k) {
            if (mb_strtolower(trim((string)($f[$k] ?? '')), 'UTF-8') === $want) { $found = (string)$code; break 2; }
        }
    }
    if ($found === '') throw new RuntimeException('В сделке не найдено поле «' . $want . '»');
    setting_set('b24_engineer_field', $found);
    return $found;
}

/* ------------------------------------------------------------------ заявка → сделка */

function b24_ensure(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    foreach ([
        "ALTER TABLE crm_leads ADD COLUMN b24_deal_id INT NULL",
        "ALTER TABLE crm_leads ADD COLUMN b24_synced_at DATETIME NULL",
    ] as $sql) {
        try { pdo()->exec($sql); } catch (Throwable $e) { /* уже есть */ }
    }
}

/** Телефон к виду для поиска в Битриксе: +7XXXXXXXXXX. */
function b24_phone(string $p): string {
    $d = preg_replace('/\D+/', '', $p) ?? '';
    if (strlen($d) === 11 && $d[0] === '8') $d = '7' . substr($d, 1);
    if (strlen($d) === 10) $d = '7' . $d;
    return strlen($d) === 11 ? '+' . $d : '';
}

/**
 * Сделка Битрикса для заявки CRM или null.
 * Порядок: сохранённая связь → поиск по телефону/почте среди контактов, компаний и лидов →
 * самая свежая открытая сделка (если открытых нет — самая свежая вообще).
 */
function b24_find_deal(array $lead): ?int {
    if (!empty($lead['b24_deal_id'])) return (int)$lead['b24_deal_id'];
    $comms = [];
    if (($ph = b24_phone((string)($lead['phone'] ?? ''))) !== '') $comms[] = ['PHONE', $ph];
    if (filter_var((string)($lead['email'] ?? ''), FILTER_VALIDATE_EMAIL)) $comms[] = ['EMAIL', strtolower((string)$lead['email'])];
    if (!$comms) return null;

    $ids = ['CONTACT' => [], 'COMPANY' => [], 'LEAD' => []];
    foreach ($comms as [$type, $value]) {
        foreach (array_keys($ids) as $entity) {
            $r = b24_call('crm.duplicate.findbycomm', ['type' => $type, 'values' => [$value], 'entity_type' => $entity])['result'] ?? [];
            foreach ((array)($r[$entity] ?? []) as $id) $ids[$entity][(int)$id] = true;
        }
    }
    $filters = [];
    foreach (array_keys($ids['CONTACT']) as $id) $filters[] = ['CONTACT_ID' => $id];
    foreach (array_keys($ids['COMPANY']) as $id) $filters[] = ['COMPANY_ID' => $id];
    foreach (array_keys($ids['LEAD']) as $id) $filters[] = ['LEAD_ID' => $id];
    $best = null;
    foreach (array_slice($filters, 0, 10) as $f) {
        $deals = b24_call('crm.deal.list', ['filter' => $f, 'order' => ['DATE_CREATE' => 'DESC'],
                                            'select' => ['ID', 'CLOSED', 'DATE_CREATE']])['result'] ?? [];
        foreach ($deals as $d) {
            $score = ($d['CLOSED'] === 'N' ? 1e10 : 0) + (int)strtotime((string)$d['DATE_CREATE']);
            if ($best === null || $score > $best[0]) $best = [$score, (int)$d['ID']];
        }
    }
    return $best ? $best[1] : null;
}

/* ------------------------------------------------------------------ текст для поля */

/** Блок робота для поля «Инженер»: всё, что CRM знает о подборе по заявке. */
function b24_engineer_text(array $lead): string {
    $id = (int)$lead['id'];
    $pdo = pdo();
    $L = [B24_MARK_START . ' · обновлено ' . date('d.m.Y H:i')];
    $L[] = 'Заявка CRM #' . $id . ' · статус: ' . status_label((string)$lead['status']);

    // Последний подбор (распознавание заявки / шильдика).
    $st = $pdo->prepare("SELECT type, content, created_at FROM crm_ai WHERE lead_id=? AND type IN ('recognize','nameplate','suggest') ORDER BY id DESC LIMIT 1");
    $st->execute([$id]);
    if ($ai = $st->fetch(PDO::FETCH_ASSOC)) {
        $d = json_decode((string)$ai['content'], true);
        if (is_array($d)) {
            $L[] = '';
            $L[] = 'ПОДБОР' . (isset($d['confidence']) ? ' (уверенность ' . (int)$d['confidence'] . ' %)' : '') . ':';
            if (!empty($d['summary'])) $L[] = trim((string)$d['summary']);
            foreach ((array)($d['positions'] ?? []) as $p) {
                $L[] = '• ' . trim(($p['model'] ?? $p['raw'] ?? '') . ' — ' . ($p['kind'] ?? '')
                     . (!empty($p['params']) ? ', ' . $p['params'] : '') . (!empty($p['qty']) ? ', ' . $p['qty'] . ' шт.' : ''), ' —,');
            }
            foreach ((array)($d['analogs'] ?? []) as $a) {
                $L[] = '→ ' . ($a['for'] ?? '?') . ' ⇒ ' . ($a['our'] ?? '?')
                     . (!empty($a['match']) ? ' (' . $a['match'] . ')' : '') . (!empty($a['note']) ? '. ' . $a['note'] : '');
            }
            if (!empty($d['missing'])) $L[] = 'Не хватает: ' . implode('; ', array_map('strval', (array)$d['missing']));
        } else {
            $L[] = '';
            $L[] = 'ПОДБОР:';
            $L[] = mb_substr(trim((string)$ai['content']), 0, 1500, 'UTF-8');
        }
    } else {
        $L[] = '';
        $L[] = 'Подбор ещё не сделан.';
    }

    // Цены из наших прошлых счетов/КП по тем же кодам ZR — подсказка менеджеру (не прайс).
    try {
        require_once __DIR__ . '/price_hist.php';
        $ph = ph_hint_lines(ph_lead_zr($id));
        if ($ph) {
            $L[] = '';
            $L[] = 'РАНЬШЕ ПРОДАВАЛИ (из наших счетов/КП — проверьте перед КП):';
            foreach ($ph as $line) $L[] = '• ' . $line;
        }
    } catch (Throwable $e) {}

    // Решение и комментарии инженера (заметки «✔ Одобрено», «✏ На доработку» и прочие).
    $st = $pdo->prepare("SELECT n.text, n.created_at, u.name FROM crm_notes n LEFT JOIN crm_users u ON u.id=n.user_id
                         WHERE n.lead_id=? ORDER BY n.id DESC LIMIT 3");
    $st->execute([$id]);
    $notes = $st->fetchAll(PDO::FETCH_ASSOC);
    if ($notes) {
        $L[] = '';
        $L[] = 'ИНЖЕНЕР / ЗАМЕТКИ:';
        foreach (array_reverse($notes) as $n) {
            $L[] = date('d.m H:i', strtotime((string)$n['created_at'])) . ($n['name'] ? ' ' . $n['name'] : '') . ': ' . mb_substr(trim((string)$n['text']), 0, 400, 'UTF-8');
        }
    }

    // Вложения из писем, разобранные ИИ.
    try {
        $st = $pdo->prepare("SELECT filename, kind, summary, direction FROM crm_mail_files WHERE lead_id=? AND status='done' ORDER BY id DESC LIMIT 6");
        $st->execute([$id]);
        $files = $st->fetchAll(PDO::FETCH_ASSOC);
        if ($files) {
            if (!function_exists('mf_kind_label')) require_once __DIR__ . '/mail_files.php';
            $L[] = '';
            $L[] = 'ВЛОЖЕНИЯ:';
            foreach ($files as $f) $L[] = '• ' . $f['filename'] . ' [' . mf_kind_label((string)$f['kind']) . ($f['direction'] === 'out' ? ', отправили мы' : '') . '] ' . $f['summary'];
        }
    } catch (Throwable $e) { /* таблицы ещё нет */ }

    // Звонки робота и итоги обзвона.
    $st = $pdo->prepare("SELECT direction, LEFT(body, 600) b, created_at FROM crm_messages WHERE lead_id=? AND channel='voice' ORDER BY id DESC LIMIT 2");
    $st->execute([$id]);
    $calls = $st->fetchAll(PDO::FETCH_ASSOC);
    try {
        $st = $pdo->prepare("SELECT t.status, t.attempts, RIGHT(COALESCE(t.result,''), 300) r, c.name FROM crm_call_targets t JOIN crm_call_campaigns c ON c.id=t.campaign_id WHERE t.lead_id=? ORDER BY t.id DESC LIMIT 2");
        $st->execute([$id]);
        $targets = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $targets = []; }
    if ($calls || $targets) {
        $L[] = '';
        $L[] = 'ЗВОНКИ:';
        foreach ($targets as $t) $L[] = '• обзвон «' . $t['name'] . '»: ' . $t['status'] . ', попыток ' . (int)$t['attempts'] . (trim((string)$t['r']) !== '' ? ' — ' . trim(str_replace("\n", '; ', (string)$t['r']), '; ') : '');
        foreach ($calls as $c) $L[] = '• ' . date('d.m H:i', strtotime((string)$c['created_at'])) . ($c['direction'] === 'out' ? ' исходящий' : ' входящий') . ': ' . trim(preg_replace('/\s+/u', ' ', (string)$c['b']) ?? '');
    }

    $L[] = '';
    $L[] = 'Карточка: ' . rtrim((string)(setting('site_url', 'https://zavod-red.ru/') ?: 'https://zavod-red.ru/'), '/') . '/admin/lead.php?id=' . $id;
    $L[] = B24_MARK_END;
    return implode("\n", $L);
}

/** Вставить/заменить блок робота в тексте поля, не трогая то, что написали люди. */
function b24_merge_block(string $current, string $block): string {
    $s = strpos($current, B24_MARK_START);
    $e = strpos($current, B24_MARK_END);
    if ($s !== false && $e !== false && $e > $s) {
        return substr($current, 0, $s) . $block . substr($current, $e + strlen(B24_MARK_END));
    }
    $current = rtrim($current);
    return $current === '' ? $block : $current . "\n\n" . $block;
}

/**
 * Выгрузить заявку в сделку. Возвращает ['ok','deal_id'|'error','skipped'?].
 * $dry=true — найти сделку и собрать текст, но ничего не записывать.
 */
function b24_push_lead(int $leadId, bool $dry = false): array {
    b24_ensure();
    $st = pdo()->prepare('SELECT * FROM crm_leads WHERE id=?');
    $st->execute([$leadId]);
    $lead = $st->fetch(PDO::FETCH_ASSOC);
    if (!$lead) return ['ok' => false, 'error' => 'нет заявки'];
    try {
        $field = b24_engineer_field();
        $dealId = b24_find_deal($lead);
        if (!$dealId) {
            if (!$dry) pdo()->prepare('UPDATE crm_leads SET b24_synced_at=NOW() WHERE id=?')->execute([$leadId]); // не искать заново каждые 5 минут
            return ['ok' => false, 'skipped' => true, 'error' => 'сделка в Битриксе не найдена (нет совпадения по телефону/почте)'];
        }
        $deal = b24_call('crm.deal.get', ['id' => $dealId])['result'] ?? null;
        if (!$deal) return ['ok' => false, 'error' => 'сделка #' . $dealId . ' недоступна'];
        $cur = $deal[$field] ?? '';
        if (is_array($cur)) $cur = implode("\n", $cur); // поле-множественное
        $text = b24_merge_block((string)$cur, b24_engineer_text($lead));
        if ($dry) return ['ok' => true, 'dry' => true, 'deal_id' => $dealId, 'field' => $field, 'text' => $text];
        b24_call('crm.deal.update', ['id' => $dealId, 'fields' => [$field => $text], 'params' => ['REGISTER_SONET_EVENT' => 'N']]);
        pdo()->prepare('UPDATE crm_leads SET b24_deal_id=?, b24_synced_at=NOW() WHERE id=?')->execute([$dealId, $leadId]);
        audit($leadId, null, 'b24_sync', ['deal' => $dealId]);
        return ['ok' => true, 'deal_id' => $dealId];
    } catch (Throwable $e) {
        audit($leadId, null, 'b24_error', ['error' => mb_substr($e->getMessage(), 0, 200, 'UTF-8')]);
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/** Заявки, где после прошлой выгрузки что-то изменилось (подбор, заметки, статус, файлы, звонки). */
function b24_sync_due(int $limit = 20): array {
    b24_ensure();
    $sql = "SELECT l.id FROM crm_leads l
            WHERE l.created_at >= NOW() - INTERVAL 60 DAY AND (
                 EXISTS (SELECT 1 FROM crm_ai a WHERE a.lead_id=l.id AND a.type IN ('recognize','nameplate','suggest','mail_file')
                         AND a.created_at > COALESCE(l.b24_synced_at, '1970-01-01'))
              OR EXISTS (SELECT 1 FROM crm_notes n WHERE n.lead_id=l.id AND n.created_at > COALESCE(l.b24_synced_at, '1970-01-01'))
              OR EXISTS (SELECT 1 FROM crm_events e WHERE e.lead_id=l.id AND e.type IN ('status_changed','call_result','call_queued','mail_file')
                         AND e.created_at > COALESCE(l.b24_synced_at, '1970-01-01'))
              OR EXISTS (SELECT 1 FROM crm_messages m WHERE m.lead_id=l.id AND m.channel='voice' AND m.created_at > COALESCE(l.b24_synced_at, '1970-01-01'))
            )
            ORDER BY l.updated_at DESC LIMIT " . max(1, $limit);
    return array_map('intval', pdo()->query($sql)->fetchAll(PDO::FETCH_COLUMN));
}

/** Прогон выгрузки (крон). Выключатель: настройка b24_sync = 0. */
function b24_sync_run(int $limit = 20): array {
    if (!b24_ready()) return ['ok' => true, 'skipped' => 'Битрикс24 не подключён'];
    if (setting('b24_sync', '1') !== '1') return ['ok' => true, 'skipped' => 'выгрузка выключена'];
    cron_heartbeat('b24');
    $out = ['ok' => true, 'pushed' => 0, 'no_deal' => 0, 'errors' => 0, 'last_error' => ''];
    foreach (b24_sync_due($limit) as $id) {
        $r = b24_push_lead($id);
        if ($r['ok']) $out['pushed']++;
        elseif (!empty($r['skipped'])) $out['no_deal']++;
        else { $out['errors']++; $out['last_error'] = (string)$r['error']; }
        if ($out['errors'] >= 3) break; // Битрикс лёг или вебхук отозван — не долбить
    }
    setting_set('b24_last_report', json_encode($out + ['at' => date('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE));
    return $out;
}
