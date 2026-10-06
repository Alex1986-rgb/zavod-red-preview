<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * Контекст по конкретной заявке: что уже распознано во вложениях (шильдики/чертежи — прочитаны
 * Claude или DeepSeek раньше, так что и без зрения модель их «видит»), справочник ZR по моделям
 * из заявки, учебник ответов и похожие случаи из переписки и документов (без сумм).
 */
function case_context(array $lead, string $leadText): string {
    $parts = [];
    // Заявки из писем: суть — в самих письмах, поля заявки пустые. Раньше подбор их не видел
    // и писал «заявка пустая» (01.10.2026). Берём последние письма клиента (без цитат).
    try {
        $st = pdo()->prepare("SELECT subject, body, created_at FROM crm_messages WHERE lead_id=? AND direction='in'
                              AND folder NOT IN ('spam','trash') ORDER BY id DESC LIMIT 3");
        $st->execute([(int)($lead['id'] ?? 0)]);
        $msgs = [];
        foreach (array_reverse($st->fetchAll(PDO::FETCH_ASSOC)) as $m) {
            if (!function_exists('mime_strip_quote')) require_once __DIR__ . '/mime.php';
            $b = trim(mb_substr(mime_strip_quote((string)$m['body']), 0, 1500, 'UTF-8'));
            if ($b !== '') $msgs[] = '[' . substr((string)$m['created_at'], 0, 16) . '] ' . $m['subject'] . "\n" . $b;
        }
        if ($msgs) {
            $parts[] = "ПИСЬМА КЛИЕНТА (последние, старые сверху):\n" . implode("\n---\n", $msgs);
            $leadText .= ' ' . implode(' ', $msgs);
        }
    } catch (Throwable $e) {}
    try {
        $st = pdo()->prepare("SELECT content FROM crm_ai WHERE lead_id=? AND type='mail_file' ORDER BY id DESC LIMIT 40");
        $st->execute([(int)$lead['id']]);
        $lines = [];
        $models = [];
        $seenF = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $c) {
            $d = json_decode((string)$c, true) ?: [];
            // дубли одной заметки (до 02.10.2026 множились) и подписи/баннеры из писем — мимо
            $fk = ($d['filename'] ?? '') . '|' . ($d['summary'] ?? '');
            if (isset($seenF[$fk]) || ($d['kind'] ?? '') === 'other' || count($seenF) >= 5) continue;
            $seenF[$fk] = true;
            $np = array_filter((array)($d['data']['nameplate'] ?? []));
            $lines[] = '— ' . ($d['filename'] ?? 'файл') . ': ' . ($d['summary'] ?? '')
                . ($np ? ' [шильдик: ' . implode(', ', array_map(static fn($k, $v) => "$k $v", array_keys($np), $np)) . ']' : '');
            foreach ((array)($d['data']['models'] ?? []) as $m) $models[] = (string)$m;
        }
        if ($lines) {
            $parts[] = "ВО ВЛОЖЕНИЯХ КЛИЕНТА УЖЕ РАСПОЗНАНО:\n" . implode("\n", $lines);
            $leadText .= ' ' . implode(' ', $models);
        }
    } catch (Throwable $e) {}
    try {
        require_once __DIR__ . '/site_kb.php';
        $ctx = site_context($leadText, 3000);   // справочник ZR + учебник + похожие случаи + пояснения сайта
        if ($ctx !== '') $parts[] = $ctx;
    } catch (Throwable $e) {}
    return implode("\n\n", $parts);
}
