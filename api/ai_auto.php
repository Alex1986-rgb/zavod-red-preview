<?php
declare(strict_types=1);

/**
 * /api/ai_auto.php — CLI-крон: авто-подбор аналога редуктора для свежих лидов.
 * БЕЗ авторизации (серверный процесс). Запускать ТОЛЬКО из cron/CLI.
 *
 * Cron (каждые 10 минут):  [*]/10 * * * * php /path/to/api/ai_auto.php
 *
 * Логика: лиды status='new' за последние 2 часа, без записи crm_ai type='suggest',
 * до 10 штук за прогон → claude_call (тот же промпт подбора, что ai.php action=suggest)
 * → save_ai($leadId, null, 'suggest', $text). Если ключ ИИ не задан — тихий выход.
 *
 * ai.php не подключаем: там require_auth() на верхнем уровне (для web). Нужные
 * функции (claude_call/kb/lead_context/save_ai) дублируем локально под CLI.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/llm.php';
require_once __DIR__ . '/kb_context.php';
require_once __DIR__ . '/case_context.php';
require_once __DIR__ . '/nameplate.php';   // распознавание шильдика: три версии + подбор + цена

// Только CLI — не отдаём наружу через web.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

/** Низкоуровневый вызов Claude Messages API (копия из ai.php для CLI). */
function ai_auto_claude_call(string $system, string $userText, int $maxTokens = 1600, array $attachments = []): string {
    return llm_call($system, $userText, $maxTokens, $attachments);
}

/** База знаний — тот же сборщик, что у ai.php (kb.md + текст файлов из crm-data/kb/). */
function ai_auto_kb(): string {
    // Ядро правил (раньше — до 140 000 знаков на каждую заявку). Остальное по конкретной заявке
    // даёт ai_auto_case_context(): справочник ZR по моделям, учебник ответов, похожие случаи.
    return kb_context(20000);
}


/** Блок вложения image/PDF по file_path лида (копия из ai.php ai_attachment_block). */
function ai_auto_attachment_block(array $lead): ?array {
    $rel = trim((string)($lead['file_path'] ?? ''));
    if ($rel === '') return null;
    $uploadsDir = rtrim((string)(cfg()['uploads_dir'] ?? (__DIR__ . '/../crm-data/uploads')), '/');
    $realDir = realpath($uploadsDir);
    $realFile = realpath($uploadsDir . '/' . basename($rel));
    if ($realDir === false || $realFile === false || !is_file($realFile)) return null;
    if (strncmp($realFile, $realDir . DIRECTORY_SEPARATOR, strlen($realDir) + 1) !== 0) return null;
    if (filesize($realFile) > 20 * 1024 * 1024) return null;
    $ext = strtolower(pathinfo($realFile, PATHINFO_EXTENSION));
    $imgTypes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
    $data = @file_get_contents($realFile);
    if ($data === false) return null;
    $b64 = base64_encode($data);
    if (isset($imgTypes[$ext])) return ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $imgTypes[$ext], 'data' => $b64]];
    if ($ext === 'pdf')        return ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $b64]];
    return null;
}

/** Извлечь JSON из ответа модели (копия из ai.php). */
function ai_auto_json_extract(string $text): array {
    $t = trim($text);
    $t = preg_replace('/^```[a-zA-Z]*\s*/', '', $t);
    $t = preg_replace('/\s*```$/', '', (string)$t);
    $t = trim((string)$t);
    $s = strpos($t, '{'); $e = strrpos($t, '}');
    if ($s !== false && $e !== false && $e > $s) $t = substr($t, $s, $e - $s + 1);
    $data = json_decode($t, true);
    if (!is_array($data)) throw new RuntimeException('не-JSON от модели');
    return $data;
}

/**
 * Системный промпт структурного распознавания. Держим ИДЕНТИЧНЫМ ai.php recognize_system,
 * иначе автопилот извлекает хуже ручного режима (раньше схема была урезана).
 */
function ai_auto_recognize_system(): string {
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
        . "=== БАЗА ЗНАНИЙ ===\n" . ai_auto_kb();
}

/**
 * Отправить письмо клиенту (режим автопилота).
 *
 * Идёт через общий notify_email_send(): сначала SMTP, при отказе — mail() с
 * правильным конвертом/Reply-To/Message-ID. Раньше здесь был прямой @mail() с
 * From на @zavod-red.ru: SPF этого домена разрешает только Яндекс, а отправляет
 * Timeweb — письма автопилота отбивались и терялись без следа.
 */
function ai_auto_send_email(array $lead, string $subject, string $body): bool {
    $to = trim((string)($lead['email'] ?? ''));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $html = nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));
    $r = notify_email_send($to, $subject, $html);
    if (!($r['ok'] ?? false)) {
        error_log('ai_auto: письмо не отправлено (' . ($r['error'] ?? '') . ')');
    }
    return (bool)($r['ok'] ?? false);
}

/** Контекст лида в текст (копия из ai.php). */
function ai_auto_lead_context(array $l): string {
    $parts = [];
    $parts[] = 'Тип/запрос: ' . ($l['reducer_type'] ?? '' ?: '—');
    if (!empty($l['message']))    $parts[] = 'Сообщение клиента: ' . $l['message'];
    if (!empty($l['page_title'])) $parts[] = 'Страница: ' . $l['page_title'];
    if (!empty($l['name']))       $parts[] = 'Клиент: ' . $l['name'];
    return implode("\n", $parts);
}

/** Сохранить результат ИИ + журнал (копия из ai.php; user_id может быть null). */
function ai_auto_save_ai(int $leadId, ?int $userId, string $type, string $text): void {
    try {
        pdo()->prepare('INSERT INTO crm_ai (lead_id,user_id,type,content,created_at) VALUES (?,?,?,?,NOW())')
            ->execute([$leadId, $userId, $type, $text]);
    } catch (Throwable $e) { /* таблицы может не быть — не валим поток */ }
    audit($leadId, $userId, 'ai_' . $type, ['auto' => true]);
}

// Если ключ ИИ не задан — тихо выходим.
if (!llm_ready()) {
    echo json_encode(['processed' => 0], JSON_UNESCAPED_UNICODE), "\n";
    exit;
}

// Режим обработки: 'review' (по умолчанию) — распознать и отдать инженеру;
// 'auto' — распознать и, если auto_send=1, сразу отправить клиенту.
$mode     = setting('ai_mode', 'review');
$autoSend = setting('auto_send', '0') === '1';

cron_heartbeat('ai_auto'); // пульс для health.php — «ИИ-крон жив»

/**
 * Фото/скан из формы сайта → в общую очередь распознавания (crm_mail_files, как вложения писем).
 * DeepSeek картинок не видит: без этого шильдик из заявки не читал никто и подбор шёл по одному
 * тексту («бренд STM» — 01.10.2026). Распознаёт обработчик vision_queue (Claude по подписке),
 * результат ложится в crm_ai type='mail_file', и подбор пересобирается (см. выборку ниже).
 */
function ai_auto_enqueue_lead_files(): int {
    if (llm_ready(true)) return 0;              // есть модель со зрением — фото читает сам подбор
    require_once __DIR__ . '/mail_files.php';
    mf_ensure();
    $n = 0;
    try {
        $rows = pdo()->query("SELECT l.id, l.file_path FROM crm_leads l
            WHERE l.file_path IS NOT NULL AND l.file_path <> '' AND l.created_at >= (NOW() - INTERVAL 7 DAY)
              AND l.file_path REGEXP '[.](jpe?g|png|webp|gif|bmp|heic|heif|tiff?|pdf)$'
              AND NOT EXISTS (SELECT 1 FROM crm_mail_files f WHERE f.lead_id = l.id AND f.message_id = 0)
            ORDER BY l.id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $name = basename((string)$r['file_path']);
            $path = mf_path($name);
            if ($path === null) continue;
            $sha = (string)sha1_file($path);
            pdo()->prepare("INSERT INTO crm_mail_files (message_id,lead_id,direction,filename,stored_name,mime,size,sha1,status,created_at)
                            VALUES (0,?, 'in', ?, ?, ?, ?, ?, 'new', NOW())")
                ->execute([(int)$r['id'], $name, $name, (string)(mime_content_type($path) ?: ''), (int)filesize($path), $sha]);
            $id = (int)pdo()->lastInsertId();
            $n++;
            // Тот же файл уже распознан раньше (клиент прислал его и письмом) — берём готовое.
            $st = pdo()->prepare("SELECT data FROM crm_mail_files WHERE sha1=? AND status='done' AND data IS NOT NULL AND id<>? LIMIT 1");
            $st->execute([$sha, $id]);
            $done = json_decode((string)$st->fetchColumn(), true);
            if (is_array($done)) {
                $row = pdo()->query('SELECT * FROM crm_mail_files WHERE id=' . $id)->fetch(PDO::FETCH_ASSOC);
                mf_apply($row, $done);
            }
        }
    } catch (Throwable $e) { error_log('ai_auto enqueue: ' . $e->getMessage()); }
    return $n;
}

/** Шильдик, уже распознанный во вложениях заявки (crm_ai mail_file) → версии для nameplate_match. */
function ai_auto_recognized_nameplate(int $leadId): array {
    $st = pdo()->prepare("SELECT content FROM crm_ai WHERE lead_id=? AND type='mail_file' ORDER BY id DESC LIMIT 5");
    $st->execute([$leadId]);
    $hyp = []; $params = []; $seen = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $c) {
        $note = (array)json_decode((string)$c, true);
        // Только то, что прислал КЛИЕНТ: наши исходящие чертежи и КП («ZR 606 (П)») — не его оборудование.
        if (($note['direction'] ?? 'in') !== 'in' || !in_array($note['kind'] ?? '', ['nameplate', 'drawing', 'spec'], true)) continue;
        $d = (array)($note['data'] ?? []);
        $np = array_filter((array)($d['nameplate'] ?? []), static fn($v) => $v !== '' && $v !== null);
        $conf = (int)($d['confidence'] ?? 70);
        $cand = [];
        if (!empty($np['model'])) $cand[] = [(string)($np['brand'] ?? ''), (string)$np['model']];
        foreach ((array)($d['models'] ?? []) as $m) {
            if (preg_match('~^\s*(ZR|EVL|ЕВЛ)\b~ui', (string)$m)) continue;   // наша маркировка — не импорт для подбора
            $cand[] = ['', (string)$m];
        }
        foreach ($cand as [$b, $m]) {
            $k = mb_strtolower(trim($b . ' ' . $m), 'UTF-8');
            if ($k === '' || isset($seen[$k])) continue;
            $seen[$k] = true;
            $hyp[] = ['brand' => $b, 'model' => $m, 'confidence' => $conf, 'why' => 'прочитано с фото'];
        }
        if ($np && !$params) $params = $np;
    }
    return [$hyp, $params];
}

$enqueued = ai_auto_enqueue_lead_files();
$processed = 0; $sentCount = 0;
try {
    $pdo = pdo();
    // Берём свежие заявки (new/in_progress/rework за сутки) без распознавания.
    $st = $pdo->query(
        "SELECT l.*
         FROM crm_leads l
         WHERE (l.status IN ('new','in_progress','rework')
                AND l.created_at >= (NOW() - INTERVAL 24 HOUR)
                AND NOT EXISTS (SELECT 1 FROM crm_ai a WHERE a.lead_id = l.id AND a.type = 'recognize'))
            -- Заявки из писем, где подбор вышел «пустым» (до 01.10.2026 он не видел текст писем) — один повтор.
            OR (l.source = 'email' AND l.created_at >= (NOW() - INTERVAL 3 DAY)
                AND NOT EXISTS (SELECT 1 FROM crm_events e WHERE e.lead_id = l.id AND e.type = 'recognize_retry')
                AND (SELECT a.content FROM crm_ai a WHERE a.lead_id = l.id AND a.type = 'recognize' ORDER BY a.id DESC LIMIT 1)
                    REGEXP '\"confidence\":(1?[0-9])[,}]')
            -- «На доработку»: комментарий инженера свежее последнего распознавания → пересобрать подбор.
            -- Раньше такая заявка висела, пока инженер сам не нажмёт «Перераспознать».
            OR (l.status = 'rework'
                AND (SELECT MAX(n.created_at) FROM crm_notes n WHERE n.lead_id = l.id AND n.text LIKE '✏ На доработку%')
                    > COALESCE((SELECT MAX(a.created_at) FROM crm_ai a WHERE a.lead_id = l.id AND a.type = 'recognize'), '1970-01-01'))
            -- Фото/документ из заявки распознан ПОСЛЕ подбора → пересобрать с шильдиком (один раз на распознавание).
            OR (l.status IN ('new','in_progress','rework','review') AND l.created_at >= (NOW() - INTERVAL 7 DAY)
                AND (SELECT MAX(m.created_at) FROM crm_ai m WHERE m.lead_id = l.id AND m.type = 'mail_file'
                     AND m.content REGEXP '\"kind\":\"(nameplate|drawing|spec)\"' AND m.content LIKE '%\"direction\":\"in\"%')
                    > COALESCE((SELECT MAX(a.created_at) FROM crm_ai a WHERE a.lead_id = l.id AND a.type = 'recognize'), '1970-01-01'))
         ORDER BY l.created_at ASC
         LIMIT 10"
    );
    $leads = $st->fetchAll();
    $system = ai_auto_recognize_system();

    foreach ($leads as $lead) {
        $leadId = (int)$lead['id'];
        try {
            $attach = ai_auto_attachment_block($lead);
            // Без ключа Claude фото/PDF прочитать некому — распознаём по тексту заявки, а не роняем лид.
            if ($attach !== null && !llm_ready(true)) $attach = null;
            $u = "ЗАЯВКА:\n" . ai_auto_lead_context($lead);
            $case = case_context($lead, ai_auto_lead_context($lead));
            if ($case !== '') $u .= "\n\n" . $case;
            if ($attach !== null) {
                $u .= "\n\nК заявке приложен " . ($attach['type'] === 'document' ? 'PDF-документ' : 'фото шильдика/чертёж')
                    . " (см. вложение выше). Считай с него модель, серию, мощность, i, момент и прочие параметры.";
            } elseif (!empty($lead['file_path'])) {
                $u .= "\nВложение клиента: " . basename((string)$lead['file_path'])
                    . " (фото/скан; если его распознанного текста нет выше — он ещё в очереди: не выдумывай модель, "
                    . "в missing укажи «дождаться распознавания фото»).";
            }
            if ((string)$lead['status'] === 'rework') {
                $rn = $pdo->prepare("SELECT text FROM crm_notes WHERE lead_id = ? AND text LIKE '✏ На доработку%' ORDER BY created_at DESC LIMIT 1");
                $rn->execute([$leadId]);
                $reworkNote = trim(str_replace('✏ На доработку:', '', (string)$rn->fetchColumn()));
                if ($reworkNote !== '') $u .= "\n\nКОММЕНТАРИЙ ИНЖЕНЕРА (исправь подбор с учётом этого):\n" . $reworkNote;
            }
            $u .= "\n\nВерни только JSON по схеме.";
            $text = ai_auto_claude_call($system, $u, 2200, $attach !== null ? [$attach] : []);
            $data = ai_auto_json_extract($text);
            $out = [
                'positions'  => array_values((array)($data['positions'] ?? [])),
                'analogs'    => array_values((array)($data['analogs'] ?? [])),
                'missing'    => array_values((array)($data['missing'] ?? [])),
                'confidence' => (int)($data['confidence'] ?? 0),
                'summary'    => (string)($data['summary'] ?? ''),
                'draft'      => (string)($data['draft'] ?? ''),
            ];
            // Детерминированный подбор ZR из базы (как в ai.php) — не доверяем догадкам модели.
            $out = enrich_analogs($out);

            // Если к заявке приложено ФОТО — отдельный проход по шильдику: три версии
            // прочтения вместо одной, соответствие из справочника, цена из прайса.
            // Потёртый шильдик читается неоднозначно, и одна версия это скрывает.
            $nameplate = null;
            [$recHyp, $recParams] = $attach === null ? ai_auto_recognized_nameplate($leadId) : [[], []];
            if (($attach !== null && ($attach['type'] ?? '') === 'image') || $recHyp) {
                try {
                    if ($recHyp) {
                        $np = ['hypotheses' => $recHyp, 'params' => $recParams, 'readable' => 'по распознанному фото'];
                    } else {
                        $npText = ai_auto_claude_call(
                            nameplate_system(),
                            "Прочитай шильдик с приложенного фото. Верни только JSON по схеме.",
                            1200,
                            [$attach]
                        );
                        $np = ai_auto_json_extract($npText);
                    }
                    $hyp = array_values((array)($np['hypotheses'] ?? []));
                    if ($hyp) {
                        $matched = nameplate_match($hyp);
                        $nameplate = [
                            'hypotheses' => $matched,
                            'params'     => (array)($np['params'] ?? []),
                            'readable'   => (string)($np['readable'] ?? ''),
                            'missing'    => array_values((array)($np['missing'] ?? [])),
                            'qty'        => (int)($np['qty'] ?? 1) ?: 1,
                        ];
                        $out['nameplate'] = $nameplate;
                        // письмо собираем сами: с подтверждённым ZR и ценой из прайса,
                        // а не пересказом модели
                        if (!empty($matched[0]['verified'])) {
                            $out['draft'] = nameplate_draft($matched, $nameplate['params'], $nameplate['qty']);
                            // Шильдик подтверждён справочником — догадки модели по тексту заявки
                            // («бренд STM» → любые RMI) только путают инженера: оставляем аналог по шильдику.
                            $out['analogs'] = [];
                            $out['missing'] = array_values(array_filter($out['missing'], static fn($m) =>
                                !preg_match('~распознавани\w* фото|не найден\w* в базе|фото шильдика~ui', (string)$m)));
                            $out['analogs'][] = [
                                'for'      => trim($matched[0]['brand'] . ' ' . $matched[0]['model']),
                                'our'      => $matched[0]['zr'],
                                'match'    => 'по шильдику',
                                'verified' => true,
                                'source'   => $matched[0]['source'],
                            ];
                        }
                    }
                } catch (Throwable $e) {
                    error_log('ai_auto шильдик lead ' . $leadId . ': ' . $e->getMessage());
                }
            }
            ai_auto_save_ai($leadId, null, 'recognize', json_encode($out, JSON_UNESCAPED_UNICODE));
            $from = (string)$lead['status'];

            $autoDone = false;
            $maskEmail = static function (string $e): string {
                $at = strpos($e, '@');
                return ($at === false || $at < 1) ? ($e === '' ? '' : '***') : substr($e, 0, 1) . '***' . substr($e, $at);
            };
            // Порог автоотправки: не шлём клиенту, если модель не уверена, есть пробелы
            // или хоть один аналог не подтверждён базой (иначе рискуем отправить выдуманный ZR).
            $minConf   = (int)(setting('ai_auto_min_confidence') ?: 70);
            $canAuto   = $out['confidence'] >= $minConf
                         && empty($out['missing'])
                         && !empty($out['all_verified']);
            // По фото планка выше: две близкие версии прочтения с разными ZR —
            // это шанс отправить клиенту не тот редуктор, поэтому решает человек.
            if ($canAuto && $nameplate !== null) {
                $canAuto = nameplate_can_autosend(
                    $nameplate['hypotheses'], $nameplate['missing'], max($minConf, 80)
                );
            }
            if ($mode === 'auto' && $autoSend && $out['draft'] !== '' && $canAuto) {
                // Полный автопилот: сразу отправляем клиенту.
                if (ai_auto_send_email($lead, 'Завод Редукторов — по вашей заявке', $out['draft'])) {
                    $pdo->prepare("UPDATE crm_leads SET status='sent', updated_at=NOW() WHERE id=?")->execute([$leadId]);
                    audit($leadId, null, 'status_changed', ['from' => $from, 'to' => 'sent', 'auto' => true]);
                    audit($leadId, null, 'email_sent', ['to' => $maskEmail((string)$lead['email']), 'auto' => true]);
                    try {
                        $pdo->prepare("INSERT INTO crm_messages (lead_id,channel,direction,contact,subject,body,created_at) VALUES (?,?,?,?,?,?,NOW())")
                            ->execute([$leadId, 'email', 'out', (string)$lead['email'], 'Завод Редукторов — по вашей заявке', $out['draft']]);
                    } catch (Throwable $e) { /* опц. */ }
                    $sentCount++; $autoDone = true;
                } else {
                    // Автоотправка не удалась — фиксируем, чтобы неудача была видна инженеру, а не выглядела штатной постановкой на проверку.
                    audit($leadId, null, 'email_failed', ['auto' => true, 'reason' => 'send_failed']);
                    error_log('ai_auto lead ' . $leadId . ': автоотправка не удалась, ушёл на проверку инженеру');
                }
            }
            if (!$autoDone) {
                // Штатно (или после неудачной автоотправки): на проверку инженеру.
                $pdo->prepare("UPDATE crm_leads SET status='review', updated_at=NOW() WHERE id=?")->execute([$leadId]);
                if ($from !== 'review') audit($leadId, null, 'status_changed', ['from' => $from, 'to' => 'review', 'auto' => true]);
            }
            if ((string)($lead['source'] ?? '') === 'email') audit($leadId, null, 'recognize_retry', []); // повтор по письмам — один раз
            $processed++;
        } catch (Throwable $e) {
            error_log('ai_auto lead ' . $leadId . ': ' . $e->getMessage());
        }
    }
} catch (Throwable $e) {
    error_log('ai_auto: ' . $e->getMessage());
}

// Свежий подбор — сразу в поле «Инженер» сделки Битрикс24 (если Битрикс подключён).
$b24 = null;
try { require_once __DIR__ . '/bitrix.php'; $b24 = b24_sync_run(); } catch (Throwable $e) { error_log('ai_auto b24: ' . $e->getMessage()); }

echo json_encode(['processed' => $processed, 'sent' => $sentCount, 'photos_queued' => $enqueued, 'mode' => $mode, 'bitrix' => $b24], JSON_UNESCAPED_UNICODE), "\n";
