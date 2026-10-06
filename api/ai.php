<?php
declare(strict_types=1);
/**
 * ИИ-агент на Claude (Opus 4.8): подбор аналога редуктора и черновик ответа клиенту.
 * Вызов Messages API по HTTP (cURL) — проект без Composer, как и Telegram в feedback.php.
 * Требует auth + CSRF. Ключ — в config.php (anthropic.api_key).
 */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/llm.php'; // единый вызов ИИ: DeepSeek или Claude
require_once __DIR__ . '/kb_context.php';
require_once __DIR__ . '/case_context.php';
require_once __DIR__ . '/site_kb.php';
require_once __DIR__ . '/nameplate.php'; // фото шильдика → три версии → ZR из справочника → черновик

$user = require_auth();

/**
 * Низкоуровневый вызов Claude Messages API. Возвращает текст ответа или бросает RuntimeException.
 * $attachments — массив блоков image/document (см. ai_attachment_block) для vision/PDF-распознавания.
 */
function claude_call(string $system, string $userText, int $maxTokens = 1500, array $attachments = []): string {
    return llm_call($system, $userText, $maxTokens, $attachments); // провайдер выбирает llm.php
}

/**
 * База знаний (grounding): kb.md + ТЕКСТ загруженных через админку файлов.
 * Раньше здесь читался только kb.md, и всё, что оператор грузил в crm-data/kb/
 * (прайсы, каталоги, таблицы соответствия), ИИ не видел. Сборка — kb_context.php.
 */
function kb(): string {
    // Ядро правил; остальное по конкретной заявке — case_context() (справочник ZR, учебник, похожие случаи).
    return kb_context(20000);
}

/**
 * Извлечь JSON-объект из ответа модели (снимает ```json-ограждения и мусор по краям).
 * Возвращает массив или бросает RuntimeException.
 */
function claude_json_extract(string $text): array {
    $t = trim($text);
    // срезаем markdown-ограждение ```json ... ```
    $t = preg_replace('/^```[a-zA-Z]*\s*/', '', $t);
    $t = preg_replace('/\s*```$/', '', (string)$t);
    $t = trim((string)$t);
    // вырезаем внешний {...}, если модель добавила пояснения вокруг
    $s = strpos($t, '{');
    $e = strrpos($t, '}');
    if ($s !== false && $e !== false && $e > $s) {
        $t = substr($t, $s, $e - $s + 1);
    }
    $data = json_decode($t, true);
    if (!is_array($data)) {
        throw new RuntimeException('Модель вернула не-JSON. Повторите распознавание.');
    }
    return $data;
}

/**
 * Системный промпт для СТРУКТУРНОГО распознавания заявки на редуктор.
 * Модель обязана вернуть строгий JSON (позиции + аналоги + чего не хватает + черновик).
 */
function recognize_system(): string {
    return "Ты — инженер-подборщик редукторов завода «Завод Редукторов». "
        . "Из данных заявки (PDF/фото шильдика/текст) распознай позиции оборудования и подбери аналоги по БАЗЕ ЗНАНИЙ. "
        . "Верни СТРОГО валидный JSON без markdown и пояснений, по схеме:\n"
        . '{'
        . '"positions":[{"raw":"строка из заявки","model":"модель/шильдик","kind":"тип редуктора","qty":1,"params":"мощность/обороты/i/момент, что распознал"}],'
        . '"analogs":[{"for":"оригинал (импорт/модель клиента)","our":"наш аналог (серия/модель)","match":"полная|частичная|под расчёт","note":"что сверить"}],'
        . '"missing":["каких данных не хватает для точного подбора"],'
        . '"confidence":0,'
        . '"summary":"1-2 строки итога подбора для инженера",'
        . '"draft":"вежливый черновик ответа клиенту на вы, без цен на импорт (по запросу), подпись: Завод Редукторов, +7 (495) 151-41-02"'
        . "}\n"
        . "Правила: не выдумывай характеристики/сроки/цены импорта; confidence — целое 0..100 (уверенность подбора). "
        . "Если позиций несколько — перечисли все. Если данных мало — заполни missing и снизь confidence.\n\n"
        . "=== БАЗА ЗНАНИЙ ===\n" . kb();
}

/** Контекст лида в текст. */
function lead_context(array $l): string {
    $parts = [];
    $parts[] = 'Тип/запрос: ' . ($l['reducer_type'] ?: '—');
    if (!empty($l['message']))    $parts[] = 'Сообщение клиента: ' . $l['message'];
    if (!empty($l['page_title'])) $parts[] = 'Страница: ' . $l['page_title'];
    if (!empty($l['name']))       $parts[] = 'Клиент: ' . $l['name'];
    return implode("\n", $parts);
}

/** Загрузить лид. */
function get_lead(int $id): ?array {
    $st = pdo()->prepare('SELECT * FROM crm_leads WHERE id = ?');
    $st->execute([$id]);
    $l = $st->fetch();
    return $l ?: null;
}

/**
 * Блок вложения для Claude vision/PDF по file_path лида (фото шильдика / PDF-заявка).
 * Возвращает content-блок image|document или null. Защита от path traversal (внутри uploads_dir).
 * Лимиты: ~15 МБ (наш аплоад), Claude принимает до 32 МБ / 600 стр.
 */
function ai_attachment_block(array $lead): ?array {
    $rel = trim((string)($lead['file_path'] ?? ''));
    if ($rel === '') return null;
    $uploadsDir = rtrim((string)(cfg()['uploads_dir'] ?? (__DIR__ . '/../crm-data/uploads')), '/');
    $realDir = realpath($uploadsDir);
    $realFile = realpath($uploadsDir . '/' . basename($rel));
    if ($realDir === false || $realFile === false || !is_file($realFile)) return null;
    if (strncmp($realFile, $realDir . DIRECTORY_SEPARATOR, strlen($realDir) + 1) !== 0) return null;
    if (filesize($realFile) > 20 * 1024 * 1024) return null; // слишком большой — не шлём

    $ext = strtolower(pathinfo($realFile, PATHINFO_EXTENSION));
    $imgTypes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
    $data = @file_get_contents($realFile);
    if ($data === false) return null;
    $b64 = base64_encode($data); // PHP base64_encode не добавляет переносов строк

    if (isset($imgTypes[$ext])) {
        return ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $imgTypes[$ext], 'data' => $b64]];
    }
    if ($ext === 'pdf') {
        return ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $b64]];
    }
    return null; // doc/xls/dwg и пр. — vision не поддерживает
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    if ($action === 'suggest') {
        csrf_check();
        $id = (int)($_POST['id'] ?? 0);
        $lead = get_lead($id);
        if (!$lead) json_out(['ok' => false, 'error' => 'Лид не найден'], 404);
        $extra = trim((string)($_POST['extra'] ?? '')); // доп. параметры от менеджера
        $system = "Ты — инженер-подборщик редукторов завода «Завод Редукторов». "
            . "На основе БАЗЫ ЗНАНИЙ и данных заявки предложи ТРИ ВАРИАНТА подбора. "
            . "Ответ строго по делу, на русском, в формате:\n"
            . "🎯 ВАРИАНТ 1 — Точный аналог: тип/серия, какому оригиналу соответствует, что сверить.\n"
            . "💸 ВАРИАНТ 2 — Экономичный: более доступное решение (проще серия/меньше запас), с оговоркой по ограничениям.\n"
            . "💪 ВАРИАНТ 3 — Усиленный: с запасом по моменту/сервис-фактору для тяжёлого режима.\n"
            . "Для каждого — 1–2 строки. Затем:\n"
            . "❓ Каких данных не хватает для точного подбора (2–4 пункта);\n⚙️ Ориентир по расчёту (i, момент), если данных достаточно.\n"
            . "Не выдумывай характеристики и сроки. Цены импорта не называй.\n\n=== БАЗА ЗНАНИЙ ===\n" . kb();
        $u = "ЗАЯВКА:\n" . lead_context($lead) . ($extra !== '' ? "\nДоп. от менеджера: " . $extra : '');
        $case = case_context($lead, lead_context($lead) . ' ' . $extra);
        if ($case !== '') $u .= "\n\n" . $case;
        $text = claude_call($system, $u, 1600);
        save_ai($id, $user['id'], 'suggest', $text);
        json_out(['ok' => true, 'text' => $text]);
    }

    if ($action === 'recognize') {
        csrf_check();
        $id = (int)($_POST['id'] ?? 0);
        $lead = get_lead($id);
        if (!$lead) json_out(['ok' => false, 'error' => 'Лид не найден'], 404);
        $incoming = trim((string)($_POST['incoming'] ?? ''));
        $rework   = trim((string)($_POST['rework'] ?? '')); // комментарий инженера при доработке
        $attach = ai_attachment_block($lead);
        if ($attach !== null && !llm_ready(true)) $attach = null; // без ключа Claude — только по тексту
        $u = "ЗАЯВКА:\n" . lead_context($lead);
        $case = case_context($lead, lead_context($lead) . ' ' . $incoming);
        if ($case !== '') $u .= "\n\n" . $case;
        if ($attach !== null) {
            $u .= "\n\nК заявке приложен " . ($attach['type'] === 'document' ? 'PDF-документ' : 'фото шильдика/чертёж')
                . " (см. вложение выше). Считай с него модель, серию, мощность, передаточное число, момент и прочие параметры.";
        } elseif (!empty($lead['file_path'])) {
            $u .= "\nВложение клиента: " . $lead['file_path'] . " (формат не распознаётся автоматически — ориентируйся на текст).";
        }
        if ($incoming !== '') $u .= "\n\nВХОДЯЩЕЕ ОТ КЛИЕНТА:\n" . $incoming;
        if ($rework !== '')   $u .= "\n\nКОММЕНТАРИЙ ИНЖЕНЕРА (учти при перерасчёте):\n" . $rework;
        $u .= "\n\nВерни только JSON по схеме.";
        $text = claude_call(recognize_system(), $u, 2200, $attach !== null ? [$attach] : []);
        $data = claude_json_extract($text);
        // нормализация полей
        $out = [
            'positions'  => array_values((array)($data['positions'] ?? [])),
            'analogs'    => array_values((array)($data['analogs'] ?? [])),
            'missing'    => array_values((array)($data['missing'] ?? [])),
            'confidence' => (int)($data['confidence'] ?? 0),
            'summary'    => (string)($data['summary'] ?? ''),
            'draft'      => (string)($data['draft'] ?? ''),
        ];
        // Детерминированный подбор ZR из базы аналогов (не доверяем догадкам модели).
        $out = enrich_analogs($out);
        save_ai($id, $user['id'], 'recognize', json_encode($out, JSON_UNESCAPED_UNICODE));
        // Комментарий инженера при доработке фиксируем в истории лида (а не только в промпте).
        if ($rework !== '') {
            try {
                pdo()->prepare('INSERT INTO crm_notes (lead_id,user_id,text,created_at) VALUES (?,?,?,NOW())')
                    ->execute([$id, $user['id'], '✏ Перераспознать: ' . $rework]);
            } catch (Throwable $e) { /* не валим распознавание */ }
        }
        // Заявка уходит инженеру на проверку (если ещё не в финале).
        $st = pdo()->prepare('SELECT status FROM crm_leads WHERE id = ?');
        $st->execute([$id]);
        $cur = (string)$st->fetchColumn();
        if (in_array($cur, ['new', 'in_progress', 'clarify', 'rework', 'picked'], true)) {
            pdo()->prepare("UPDATE crm_leads SET status='review', updated_at=NOW() WHERE id=?")->execute([$id]);
            if ($cur !== 'review') audit($id, $user['id'], 'status_changed', ['from' => $cur, 'to' => 'review']);
        }
        json_out(['ok' => true, 'data' => $out, 'raw' => $text]);
    }

    if ($action === 'nameplate') {
        // Черновик по фото шильдика. Только для человека: письмо не отправляется,
        // статус заявки не меняется — инженер читает версии и решает сам.
        csrf_check();
        $id = (int)($_POST['id'] ?? 0);
        $lead = get_lead($id);
        if (!$lead) json_out(['ok' => false, 'error' => 'Лид не найден'], 404);
        $attach = ai_attachment_block($lead);
        if ($attach === null || $attach['type'] !== 'image') {
            json_out(['ok' => false, 'error' => 'К заявке не приложено фото (jpg, png, webp) — читать нечего'], 422);
        }
        $text = claude_call(nameplate_system(),
            "Прочитай шильдик с приложенного фото. Верни только JSON по схеме.", 1200, [$attach]);
        $np = claude_json_extract($text);
        $hyp = array_values((array)($np['hypotheses'] ?? []));
        $matched = $hyp ? nameplate_match($hyp) : [];
        $params  = (array)($np['params'] ?? []);
        $missing = array_values((array)($np['missing'] ?? []));
        $qty     = (int)($np['qty'] ?? 1) ?: 1;
        $out = [
            'hypotheses' => $matched,
            'params'     => $params,
            'readable'   => (string)($np['readable'] ?? ''),
            'missing'    => $missing,
            'qty'        => $qty,
            'draft'      => nameplate_draft($matched, $params, $qty),
            // подсказка инженеру: прошёл бы этот результат планку автопилота
            'autosend_ok' => nameplate_can_autosend($matched, $missing),
        ];
        save_ai($id, (int)$user['id'], 'nameplate', json_encode($out, JSON_UNESCAPED_UNICODE));
        json_out(['ok' => true, 'data' => $out]);
    }

    if ($action === 'draft') {
        csrf_check();
        $id = (int)($_POST['id'] ?? 0);
        $lead = get_lead($id);
        if (!$lead) json_out(['ok' => false, 'error' => 'Лид не найден'], 404);
        $channel = ($_POST['channel'] ?? 'email') === 'messenger' ? 'мессенджер' : 'email';
        $incoming = trim((string)($_POST['incoming'] ?? '')); // входящее письмо/сообщение клиента
        $system = "Ты — менеджер завода «Завод Редукторов». Напиши вежливый, профессиональный ответ клиенту "
            . "для канала: {$channel}. На «вы», по делу, без воды. Помоги с подбором, предложи следующий шаг "
            . "(прислать шильд/параметры, подготовить КП, созвон). Используй факты из БАЗЫ ЗНАНИЙ; не выдумывай "
            . "сроки/характеристики; цену, срок изготовления и наличие не называй — «инженер посчитает под ваш "
            . "типоразмер и количество». Соответствие «импортная модель → ZR» бери только из справочника ниже: "
            . "нет там — напиши, что проверит инженер. Подпись: с уважением, "
            . "Завод Редукторов, +7 (495) 151-41-02.\n\n=== БАЗА ЗНАНИЙ ===\n" . kb();
        // справочник моделей и куски пояснений по тексту обращения (данные сайта)
        $siteCtx = site_context($incoming !== '' ? $incoming : lead_context($lead), 3000);
        if ($siteCtx !== '') $system .= "\n\n" . $siteCtx;
        $u = "ЗАЯВКА:\n" . lead_context($lead);
        if ($incoming !== '') $u .= "\n\nВХОДЯЩЕЕ ОТ КЛИЕНТА:\n" . $incoming;
        $u .= "\n\nНапиши только текст ответа клиенту.";
        $text = claude_call($system, $u, 1400);
        save_ai($id, $user['id'], 'draft', $text);
        json_out(['ok' => true, 'text' => $text]);
    }

    if ($action === 'recommend') {
        csrf_check();
        $pdo = pdo();
        $period = 30; // дней

        // --- Всего лидов за период ---
        $st = $pdo->prepare('SELECT COUNT(*) FROM crm_leads WHERE created_at >= (NOW() - INTERVAL 30 DAY)');
        $st->execute();
        $total = (int)$st->fetchColumn();

        // --- По статусам ---
        $st = $pdo->prepare(
            'SELECT status, COUNT(*) AS c FROM crm_leads '
            . 'WHERE created_at >= (NOW() - INTERVAL 30 DAY) GROUP BY status'
        );
        $st->execute();
        $byStatus = ['new' => 0, 'in_progress' => 0, 'quoted' => 0, 'won' => 0, 'lost' => 0];
        foreach ($st->fetchAll() as $row) {
            $s = (string)($row['status'] ?? '');
            if (isset($byStatus[$s])) $byStatus[$s] = (int)$row['c'];
        }
        $won = $byStatus['won'];
        $conv = $total > 0 ? round($won / $total * 100, 1) : 0.0;

        // --- Выручка (sum amount по won) ---
        $st = $pdo->prepare(
            'SELECT COALESCE(SUM(amount),0) FROM crm_leads '
            . "WHERE status = 'won' AND created_at >= (NOW() - INTERVAL 30 DAY)"
        );
        $st->execute();
        $revenue = (float)$st->fetchColumn();
        $avgCheck = $won > 0 ? $revenue / $won : 0.0;

        // --- По источникам (лидов и выиграно) ---
        $st = $pdo->prepare(
            "SELECT COALESCE(NULLIF(utm_source,''), NULLIF(source,''), 'не указан') AS src, "
            . "COUNT(*) AS leads, SUM(CASE WHEN status='won' THEN 1 ELSE 0 END) AS won "
            . 'FROM crm_leads WHERE created_at >= (NOW() - INTERVAL 30 DAY) '
            . 'GROUP BY src ORDER BY leads DESC LIMIT 12'
        );
        $st->execute();
        $bySource = $st->fetchAll();

        // --- Топ-5 причин отказа ---
        $st = $pdo->prepare(
            "SELECT TRIM(lost_reason) AS reason, COUNT(*) AS c FROM crm_leads "
            . "WHERE status = 'lost' AND created_at >= (NOW() - INTERVAL 30 DAY) "
            . "AND lost_reason IS NOT NULL AND TRIM(lost_reason) <> '' "
            . 'GROUP BY reason ORDER BY c DESC LIMIT 5'
        );
        $st->execute();
        $lostReasons = $st->fetchAll();

        // --- Аптайм сайта (crm_monitor, если есть) ---
        $monitorSummary = '';
        try {
            $st = $pdo->prepare(
                'SELECT COUNT(*) AS total, '
                . "SUM(CASE WHEN status='ok' OR status='up' OR http_code BETWEEN 200 AND 399 THEN 1 ELSE 0 END) AS ok, "
                . 'AVG(response_ms) AS avg_ms FROM crm_monitor '
                . 'WHERE checked_at >= (NOW() - INTERVAL 30 DAY)'
            );
            $st->execute();
            $m = $st->fetch();
            if ($m && (int)$m['total'] > 0) {
                $mTotal = (int)$m['total'];
                $mOk = (int)$m['ok'];
                $mAvg = $m['avg_ms'] !== null ? round((float)$m['avg_ms']) : null;
                $uptime = $mTotal > 0 ? round($mOk / $mTotal * 100, 1) : 0;
                $monitorSummary = "\nМОНИТОРИНГ САЙТА (30 дней): проверок $mTotal, успешных $mOk ($uptime% аптайм)"
                    . ($mAvg !== null ? ", средний ответ {$mAvg} мс" : '') . '.';
            }
        } catch (Throwable $e) {
            // таблицы может не быть — пропускаем
            $monitorSummary = '';
        }

        // --- Сборка текстовой сводки ---
        $lines = [];
        $lines[] = "СВОДКА ПО CRM ЗА 30 ДНЕЙ (завод редукторов):";
        $lines[] = "Всего заявок: $total.";
        $lines[] = "По статусам: новые {$byStatus['new']}, в работе {$byStatus['in_progress']}, "
            . "выставлено КП {$byStatus['quoted']}, выиграно {$byStatus['won']}, проиграно {$byStatus['lost']}.";
        $lines[] = "Конверсия в продажу: {$conv}%.";
        $lines[] = "Выручка по выигранным: " . number_format($revenue, 0, '.', ' ') . " ₽; "
            . "средний чек: " . number_format($avgCheck, 0, '.', ' ') . " ₽.";

        if ($bySource) {
            $srcParts = [];
            foreach ($bySource as $r) {
                $srcParts[] = ($r['src'] ?? '—') . ': ' . (int)$r['leads'] . ' заявок / ' . (int)$r['won'] . ' выиграно';
            }
            $lines[] = "По источникам — " . implode('; ', $srcParts) . '.';
        }

        if ($lostReasons) {
            $lrParts = [];
            foreach ($lostReasons as $r) {
                $lrParts[] = ($r['reason'] ?? '—') . ' (' . (int)$r['c'] . ')';
            }
            $lines[] = "Топ причин отказа: " . implode('; ', $lrParts) . '.';
        }

        if ($monitorSummary !== '') $lines[] = trim($monitorSummary);

        $summary = implode("\n", $lines);

        $system = "Ты — опытный директор по маркетингу и продажам промышленного предприятия "
            . "«Завод Редукторов» (B2B, производство и подбор редукторов, в т.ч. аналогов импортных). "
            . "На основе СВОДКИ по CRM дай КОНКРЕТНЫЕ, применимые рекомендации по маркетингу, продажам, "
            . "повышению конверсии и улучшению сайта. Требования к ответу:\n"
            . "- 5–8 пунктов, отсортированных по приоритету (сначала самое важное);\n"
            . "- каждый пункт: краткое действие + обоснование цифрами из сводки;\n"
            . "- только по делу, без воды, без общих фраз, без вступления и заключения;\n"
            . "- на русском языке;\n"
            . "- не выдумывай данные, которых нет в сводке; если данных мало — укажи, что замерить.";
        $u = $summary . "\n\nДай рекомендации по приоритету.";
        $text = claude_call($system, $u, 1500);
        // crm_ai.lead_id NOT NULL → используем 0 как «общая аналитика»
        save_ai(0, $user['id'], 'recommend', $text);
        json_out(['ok' => true, 'text' => $text]);
    }

    json_out(['ok' => false, 'error' => 'Неизвестное действие'], 400);
} catch (RuntimeException $e) {
    // Наши контролируемые сообщения (ИИ не настроен, пустой ответ, не-JSON) — показываем.
    json_out(['ok' => false, 'error' => $e->getMessage()], 500);
} catch (Throwable $e) {
    // Прочее (SQL и т.п.) — в лог, пользователю обобщённо.
    error_log('ai.php: ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'Внутренняя ошибка ИИ'], 500);
}

/** Сохранить результат ИИ + журнал. */
function save_ai(int $leadId, int $userId, string $type, string $text): void {
    try {
        pdo()->prepare('INSERT INTO crm_ai (lead_id,user_id,type,content,created_at) VALUES (?,?,?,?,NOW())')
            ->execute([$leadId, $userId, $type, $text]);
    } catch (Throwable $e) { /* таблицы может не быть до миграции — не валим основной поток */ }
    audit($leadId, $userId, 'ai_' . $type, []);
}
