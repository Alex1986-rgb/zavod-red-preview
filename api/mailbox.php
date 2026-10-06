<?php
declare(strict_types=1);

/**
 * /api/mailbox.php — почтовый клиент CRM (UI над crm_messages, channel=email).
 *   GET  ?action=list&folder=inbox|starred|sent|drafts|spam|trash[&filter=unread|attach][&q=]
 *   GET  ?action=get&id=            письмо + распознанные поля + черновик-подсказка
 *   POST ?action=flag (csrf) id, field=star|read|folder, value
 *   POST ?action=recognize (csrf) id   эвристический разбор письма → поля заявки
 *   POST ?action=draft (csrf) id        черновик ответа (ИИ при наличии ключа, иначе шаблон)
 *   POST ?action=reply (csrf) id, body  отправить ответ на email отправителя
 *   POST ?action=make_lead (csrf) id    создать/привязать заявку из письма
 *   POST ?action=send_draft (csrf) id   отправить существующий черновик как есть
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/inbox.php'; // lead_find_or_create, msg_insert

function mb_ensure(): void {
    static $done = false;
    if ($done) return;
    foreach ([
        "ALTER TABLE crm_messages ADD COLUMN folder VARCHAR(16) NOT NULL DEFAULT 'inbox'",
        "ALTER TABLE crm_messages ADD COLUMN is_read TINYINT NOT NULL DEFAULT 0",
        "ALTER TABLE crm_messages ADD COLUMN is_starred TINYINT NOT NULL DEFAULT 0",
        "ALTER TABLE crm_messages ADD COLUMN has_attach TINYINT NOT NULL DEFAULT 0",
        "ALTER TABLE crm_messages ADD COLUMN mail_kind VARCHAR(16) NOT NULL DEFAULT ''",
        "ALTER TABLE crm_messages ADD COLUMN in_reply_to VARCHAR(190) NOT NULL DEFAULT ''",
    ] as $sql) {
        try { pdo()->exec($sql); } catch (Throwable $e) { /* колонка уже есть */ }
    }
    $done = true;
}

$user   = require_auth();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? ($_POST['action'] ?? 'list');

try {
    mb_ensure();
    if ($method === 'GET') {
        switch ($action) {
            case 'get':  mb_get();  break;
            case 'list': default: mb_list(); break;
        }
    } elseif ($method === 'POST') {
        csrf_check();
        switch ($action) {
            case 'flag':      mb_flag();            break;
            case 'recognize': mb_recognize();       break;
            case 'draft':     mb_draft($user);      break;
            case 'reply':     mb_reply($user);      break;
            case 'make_lead': mb_make_lead();       break;
            case 'compose':   mb_compose($user);    break;
            case 'send_draft': mb_send_draft();      break;
            case 'to_engineer': mb_to_engineer($user); break;
            case 'poll':      mb_poll();             break;
            default: json_out(['ok' => false, 'error' => 'Неизвестное действие'], 400);
        }
    } else {
        json_out(['ok' => false, 'error' => 'Метод не поддерживается'], 405);
    }
} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => 'Внутренняя ошибка'], 500);
}

/** WHERE для папки. */
function mb_folder_where(string $folder): array {
    switch ($folder) {
        case 'sent':    return ["channel='email' AND direction='out' AND folder NOT IN ('drafts','trash','spam','archive','shadow')", []];
        case 'starred': return ["channel='email' AND is_starred=1", []];
        case 'drafts':  return ["channel='email' AND folder='drafts'", []];
        case 'spam':    return ["channel='email' AND folder='spam'", []];
        case 'trash':   return ["channel='email' AND folder='trash'", []];
        case 'inbox':
        default:        return ["channel='email' AND direction='in' AND folder NOT IN ('spam','trash','drafts','archive')", []];
    }
}

function mb_list(): void {
    $folder = (string)($_GET['folder'] ?? 'inbox');
    $filter = (string)($_GET['filter'] ?? '');
    $q      = trim((string)($_GET['q'] ?? ''));
    [$w, $a] = mb_folder_where($folder);
    if ($filter === 'unread') $w .= ' AND is_read=0';
    if ($filter === 'attach') $w .= ' AND has_attach=1';
    if ($filter === 'clients') $w .= " AND (mail_kind IN ('request','client') OR (mail_kind='' AND lead_id IS NOT NULL))";
    if ($q !== '') { $w .= ' AND (subject LIKE ? OR body LIKE ? OR contact LIKE ?)'; $like='%'.$q.'%'; array_push($a,$like,$like,$like); }

    // lead_status подтягиваем подзапросом (не JOIN) — в $w колонки без префикса, JOIN бы их сделал неоднозначными
    $st = pdo()->prepare("SELECT id, lead_id, contact, subject, LEFT(body,160) AS preview, is_read, is_starred, has_attach, direction, folder, created_at, mail_kind,
                                 (SELECT l.status FROM crm_leads l WHERE l.id = crm_messages.lead_id) AS lead_status
                          FROM crm_messages WHERE $w ORDER BY created_at DESC LIMIT 200");
    $st->execute($a);
    $rows = $st->fetchAll();

    // счётчики папок
    $counts = [];
    foreach (['inbox','starred','sent','drafts','spam','trash'] as $f) {
        [$fw,$fa] = mb_folder_where($f);
        $stc = pdo()->prepare("SELECT COUNT(*) FROM crm_messages WHERE $fw");
        $stc->execute($fa);
        $counts[$f] = (int)$stc->fetchColumn();
    }
    $unread = (int)pdo()->query("SELECT COUNT(*) FROM crm_messages WHERE channel='email' AND direction='in' AND is_read=0 AND folder NOT IN ('spam','trash')")->fetchColumn();

    json_out(['ok'=>true, 'messages'=>$rows, 'counts'=>$counts, 'unread'=>$unread]);
}

function mb_get(): void {
    $id = (int)($_GET['id'] ?? 0);
    $st = pdo()->prepare("SELECT * FROM crm_messages WHERE id=? AND channel='email'");
    $st->execute([$id]);
    $m = $st->fetch();
    if (!$m) { json_out(['ok'=>false,'error'=>'Письмо не найдено'],404); }
    if (!(int)$m['is_read']) pdo()->prepare("UPDATE crm_messages SET is_read=1 WHERE id=?")->execute([$id]);
    $m['is_read'] = 1;
    $lead = null;
    if ($m['lead_id']) {
        // колонка называется reducer_type (см. migrations/schema.sql); прежнее "type" роняло весь запрос
        $ls = pdo()->prepare("SELECT id, name, phone, email, status, reducer_type FROM crm_leads WHERE id=?");
        $ls->execute([(int)$m['lead_id']]); $lead = $ls->fetch() ?: null;
    }
    // Сохранённые вложения (api/mail_sync.php) и что в них распознал ИИ.
    $files = [];
    try {
        $fs = pdo()->prepare("SELECT id, filename, kind, status, summary, size FROM crm_mail_files WHERE message_id=? ORDER BY id");
        $fs->execute([$id]);
        require_once __DIR__ . '/mail_files.php';
        foreach ($fs->fetchAll() as $f) { $f['kind_label'] = mf_kind_label((string)$f['kind']); $files[] = $f; }
    } catch (Throwable $e) { /* таблицы ещё нет — почта не синхронизировалась новым модулем */ }
    json_out(['ok'=>true, 'message'=>$m, 'lead'=>$lead, 'files'=>$files, 'recognized'=>mail_recognize((string)$m['body'], (string)$m['contact'])]);
}

function mb_flag(): void {
    $id = (int)($_POST['id'] ?? 0);
    $field = (string)($_POST['field'] ?? '');
    $val = (string)($_POST['value'] ?? '');
    if ($field === 'star')  pdo()->prepare("UPDATE crm_messages SET is_starred=? WHERE id=?")->execute([$val?1:0,$id]);
    elseif ($field === 'read') pdo()->prepare("UPDATE crm_messages SET is_read=? WHERE id=?")->execute([$val?1:0,$id]);
    elseif ($field === 'folder' && in_array($val,['inbox','spam','trash'],true)) pdo()->prepare("UPDATE crm_messages SET folder=? WHERE id=?")->execute([$val,$id]);
    else json_out(['ok'=>false,'error'=>'Некорректный флаг'],400);
    json_out(['ok'=>true]);
}

/** Эвристический разбор письма → поля заявки (без внешних сервисов). */
function mail_recognize(string $body, string $contact = ''): array {
    $t = ' ' . preg_replace('/\s+/u',' ',$body) . ' ';
    $res = [];
    $num = '\d+(?:[.,]\d+)?'; // число с необязательной дробной частью (без хвостовой запятой)
    // клиент — из подписи «ООО …» / «ИП …» или из домена контакта
    if (preg_match('/(ООО|АО|ЗАО|ПАО|ИП)\s+«[^»]{1,40}»/u',$body,$m)) $res['client'] = trim($m[0]);
    elseif (preg_match('/(ООО|АО|ЗАО|ПАО|ИП)\s+[A-ZА-ЯЁ][A-Za-zА-Яа-яЁё0-9\- ]{1,40}/u',$body,$m)) $res['client'] = trim($m[0]);
    elseif ($contact && preg_match('/@([a-z0-9.-]+)/i',$contact,$m)) $res['client'] = $m[1];
    // телефон
    if (preg_match('/(\+7|8)[\s\-\(]*\d{3}[\s\-\)]*\d{3}[\s\-]*\d{2}[\s\-]*\d{2}/u',$t,$m)) $res['phone'] = normalize_phone($m[0]);
    // тип редуктора
    $types = ['цилиндрическ'=>'Цилиндрический','червячн'=>'Червячный','коническо-цилиндрическ'=>'Коническо-цилиндрический','планетарн'=>'Планетарный','соосн'=>'Соосно-цилиндрический','мотор-редуктор'=>'Мотор-редуктор'];
    foreach ($types as $k=>$v) if (mb_stripos($t,$k)!==false){ $res['type']=$v; break; }
    // мощность кВт
    if (preg_match('/('.$num.')\s*квт/ui',$t,$m)) $res['power'] = str_replace('.',',',$m[1]).' кВт';
    // обороты об/мин
    if (preg_match('/(\d{2,4})\s*об\/?мин/ui',$t,$m)) $res['rpm'] = $m[1].' об/мин';
    // передаточное i=
    if (preg_match('/i\s*=?\s*('.$num.')/ui',$t,$m)) $res['ratio'] = str_replace('.',',',$m[1]);
    elseif (preg_match('/передаточн\w*\D{0,12}('.$num.')/ui',$t,$m)) $res['ratio'] = str_replace('.',',',$m[1]);
    // момент Н·м
    if (preg_match('/('.$num.')\s*н[·\.\*]?\s?м/ui',$t,$m)) $res['torque'] = '~ '.str_replace('.',',',$m[1]).' Н·м';
    // способ установки
    if (mb_stripos($t,'лап')!==false) $res['mount'] = 'Лапы';
    elseif (mb_stripos($t,'фланец')!==false||mb_stripos($t,'фланцев')!==false) $res['mount'] = 'Фланец';
    // позиция
    if (mb_stripos($t,'горизонт')!==false) $res['position'] = 'Горизонтальная';
    elseif (mb_stripos($t,'вертикал')!==false) $res['position'] = 'Вертикальная';

    $filled = count(array_filter($res, fn($v)=>$v!=='' && $v!==null));
    $res['_confidence'] = min(95, 40 + $filled*9); // грубая оценка уверенности
    return $res;
}

function mb_recognize(): void {
    $id = (int)($_POST['id'] ?? 0);
    $st = pdo()->prepare("SELECT body, contact FROM crm_messages WHERE id=?");
    $st->execute([$id]); $m = $st->fetch();
    if (!$m) { json_out(['ok'=>false,'error'=>'Письмо не найдено'],404); }
    json_out(['ok'=>true, 'recognized'=>mail_recognize((string)$m['body'], (string)$m['contact'])]);
}

/**
 * Ручной приём почты из UI: тянет непрочитанные письма из IMAP в CRM (как крон,
 * но по кнопке). Возвращает число забранных писем или понятную причину сбоя
 * (нет расширения imap / не заданы креды / ошибка подключения) — чтобы админ
 * сразу видел, работает канал почты или нет.
 */
function mb_poll(): void {
    require_once __DIR__ . '/mail_sync.php'; // CLI-блок в web не срабатывает
    // Из браузера — небольшая порция: разбор писем ИИ идёт по одному, остальное доберёт крон.
    $res = ms_run(['max' => 15]);
    json_out($res, ($res['ok'] ?? false) ? 200 : 200); // ошибку показываем в UI, не как HTTP-5xx
}

/** Черновик ответа: ИИ при наличии ключа, иначе аккуратный шаблон по распознанному. */
function mb_draft(array $user): void {
    $id = (int)($_POST['id'] ?? 0);
    $st = pdo()->prepare("SELECT * FROM crm_messages WHERE id=?");
    $st->execute([$id]); $m = $st->fetch();
    if (!$m) { json_out(['ok'=>false,'error'=>'Письмо не найдено'],404); }
    // умный шаблон по распознанным полям (ИИ-черновик доступен в карточке лида)
    $r = mail_recognize((string)$m['body'], (string)$m['contact']);
    $name = $r['client'] ?? 'коллеги';
    $spec = [];
    foreach (['type'=>'тип','power'=>'мощность','rpm'=>'обороты','ratio'=>'передаточное','torque'=>'момент'] as $k=>$lbl)
        if (!empty($r[$k])) $spec[] = $lbl.' '.$r[$k];
    $specLine = $spec ? ('соответствующий указанным параметрам ('.implode(', ',$spec).')') : 'по вашему запросу';
    $me = (string)($user['name'] ?? 'Менеджер');
    $draft = "Добрый день!\n\nСпасибо за обращение. Мы подобрали для вас редуктор, $specLine.\n"
        ."Во вложении направляем коммерческое предложение с техническими характеристиками, сроками изготовления и стоимостью.\n\n"
        ."Готовы ответить на дополнительные вопросы и уточнить детали.\n\nС уважением,\n$me\nЗавод Редукторов · ООО «НИИ АТТ»";
    json_out(['ok'=>true, 'draft'=>$draft, 'source'=>'template']);
}

function mb_reply(array $user): void {
    $id   = (int)($_POST['id'] ?? 0);
    $body = trim((string)($_POST['body'] ?? ''));
    if ($body === '') { json_out(['ok'=>false,'error'=>'Пустой ответ'],400); }
    $st = pdo()->prepare("SELECT * FROM crm_messages WHERE id=?");
    $st->execute([$id]); $m = $st->fetch();
    if (!$m || !filter_var($m['contact'], FILTER_VALIDATE_EMAIL)) { json_out(['ok'=>false,'error'=>'Нет адреса получателя'],400); }
    $subject = 'Re: ' . ($m['subject'] ?: 'Ваша заявка');
    $sent = false;
    if (is_file(__DIR__.'/mail.php')) {
        require_once __DIR__.'/mail.php';
        $sent = notify_email_send((string)$m['contact'], $subject, nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8')))['ok']; // smtp_send → запасной mail(), как в channel_send
    }
    // журналируем исходящее в переписку
    $mid = msg_insert($m['lead_id'] ? (int)$m['lead_id'] : null, 'email', 'out', $body, ['contact'=>$m['contact'], 'subject'=>$subject]);
    // «Менеджер в диалоге» — автоответчик не должен отвечать поверх живой переписки
    if ($sent && $m['lead_id']) audit((int)$m['lead_id'], (int)(current_user()['id'] ?? 0), 'msg_sent', ['channel' => 'email', 'via' => 'mailbox']);
    // Обучение: ответ менеджера на это письмо — пример для автоответчика.
    if ($sent) { require_once __DIR__ . '/learn.php'; learn_add($m, $body, 'crm', $mid ?: null); learn_shadow_eval($m['lead_id'] ? (int)$m['lead_id'] : null, $body, $mid ?: null); }
    // если реально НЕ отправлено (SMTP не настроен) — держим в «Черновиках», а не в «Отправленных»
    if (!$sent && $mid) {
        try { pdo()->prepare("UPDATE crm_messages SET folder='drafts' WHERE id=?")->execute([$mid]); } catch (Throwable $e) {}
    }
    // ok=true в обоих случаях: сообщение сохранено. Факт отправки — в поле sent.
    json_out(['ok'=>true, 'sent'=>$sent, 'id'=>$mid,
        'note'  => $sent ? 'Ответ отправлен' : 'SMTP не настроен — ответ сохранён в «Черновиках»']);
}

function mb_make_lead(): void {
    $id = (int)($_POST['id'] ?? 0);
    $st = pdo()->prepare("SELECT * FROM crm_messages WHERE id=?");
    $st->execute([$id]); $m = $st->fetch();
    if (!$m) { json_out(['ok'=>false,'error'=>'Письмо не найдено'],404); }
    if ($m['lead_id']) { json_out(['ok'=>true, 'lead_id'=>(int)$m['lead_id'], 'note'=>'Заявка уже привязана']); }
    $r = mail_recognize((string)$m['body'], (string)$m['contact']);
    $leadId = lead_find_or_create([
        'name'    => $r['client'] ?? ($m['contact'] ?: 'Из письма'),
        'email'   => filter_var($m['contact'], FILTER_VALIDATE_EMAIL) ? $m['contact'] : '',
        'phone'   => $r['phone'] ?? '',
        'channel' => 'email',
    ]);
    // тип редуктора — если распознан
    if (!empty($r['type'])) {
        try { pdo()->prepare("UPDATE crm_leads SET reducer_type=? WHERE id=? AND (reducer_type IS NULL OR reducer_type='')")->execute([$r['type'],$leadId]); } catch (Throwable $e) {}
    }
    pdo()->prepare("UPDATE crm_messages SET lead_id=? WHERE id=?")->execute([$leadId,$id]);
    json_out(['ok'=>true, 'lead_id'=>$leadId, 'note'=>'Заявка создана']);
}

/**
 * POST ?action=compose — написать новое письмо (Кому/Тема/Текст).
 * Отправляет через SMTP при наличии; иначе сохраняет в «Черновики». Привязывает к
 * заявке по email, если такая есть. Возвращает ok=факт отправки.
 */
function mb_compose(array $user): void {
    $to   = trim((string)($_POST['to'] ?? ''));
    $subj = trim((string)($_POST['subject'] ?? ''));
    $body = trim((string)($_POST['body'] ?? ''));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { json_out(['ok'=>false,'error'=>'Некорректный email получателя'],400); }
    if ($body === '') { json_out(['ok'=>false,'error'=>'Пустое письмо'],400); }
    if ($subj === '') $subj = 'Письмо от Завод Редукторов';
    $subj = str_replace(["\r","\n"], ' ', $subj); // защита от header-injection

    // привязка к существующей заявке по email
    $leadId = null;
    try {
        $st = pdo()->prepare("SELECT id FROM crm_leads WHERE email = ? ORDER BY id DESC LIMIT 1");
        $st->execute([$to]);
        $leadId = ($v = $st->fetchColumn()) ? (int)$v : null;
    } catch (Throwable $e) {}

    $sent = false;
    if (is_file(__DIR__.'/mail.php')) {
        require_once __DIR__.'/mail.php';
        $sent = notify_email_send($to, $subj, nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8')))['ok']; // smtp_send → запасной mail(), как в channel_send
    }
    $mid = msg_insert($leadId, 'email', 'out', $body, ['contact'=>$to, 'subject'=>$subj]);
    if ($sent && $leadId) audit($leadId, (int)(current_user()['id'] ?? 0), 'msg_sent', ['channel' => 'email', 'via' => 'mailbox']);
    if (!$sent && $mid) {
        try { pdo()->prepare("UPDATE crm_messages SET folder='drafts' WHERE id=?")->execute([$mid]); } catch (Throwable $e) {}
    }
    // Исходный черновик (если письмо редактировали из папки «Черновики») — в корзину, чтобы не плодить дубли.
    $draftId = (int)($_POST['draft_id'] ?? 0);
    if ($sent && $draftId && $draftId !== $mid) {
        // Обучение: насколько менеджер поправил черновик робота (или свой).
        try {
            $d = pdo()->prepare("SELECT body, mail_kind FROM crm_messages WHERE id=? AND direction='out' AND folder='drafts'");
            $d->execute([$draftId]);
            if ($dr = $d->fetch()) {
                require_once __DIR__ . '/learn.php';
                if (($dr['mail_kind'] ?? '') === 'robot') learn_from_draft($leadId, $to, (string)$dr['body'], $body, $mid ?: null);
                else learn_from_reply($leadId, $to, $body, $mid ?: null, 'crm');
            }
        } catch (Throwable $e) {}
    }
    if ($draftId && $draftId !== $mid) {
        try { pdo()->prepare("UPDATE crm_messages SET folder='trash' WHERE id=? AND direction='out' AND folder='drafts'")->execute([$draftId]); } catch (Throwable $e) {}
    }
    // ok=true в обоих случаях: письмо сохранено. Факт отправки — в поле sent.
    json_out(['ok'=>true, 'sent'=>$sent, 'id'=>$mid,
        'lead_id'=>$leadId,
        'note'  => $sent ? 'Письмо отправлено' : 'SMTP не настроен — сохранено в «Черновиках»']);
}

/**
 * POST ?action=send_draft&id= — повторная отправка уже сохранённого черновика
 * (папка «Черновики»). При успехе письмо переезжает в «Отправленные».
 */
function mb_send_draft(): void {
    $id = (int)($_POST['id'] ?? 0);
    $st = pdo()->prepare("SELECT * FROM crm_messages WHERE id=? AND channel='email'");
    $st->execute([$id]); $m = $st->fetch();
    if (!$m) { json_out(['ok'=>false,'error'=>'Письмо не найдено'],404); }
    if ((string)($m['folder'] ?? '') !== 'drafts') { json_out(['ok'=>false,'error'=>'Это письмо не в «Черновиках»'],400); }
    $to = (string)($m['contact'] ?? '');
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { json_out(['ok'=>false,'error'=>'Нет адреса получателя'],400); }
    $body = (string)($m['body'] ?? '');
    if (trim($body) === '') { json_out(['ok'=>false,'error'=>'Пустое письмо'],400); }
    $subj = (string)($m['subject'] ?: 'Письмо от Завод Редукторов');

    $sent = false;
    if (is_file(__DIR__.'/mail.php')) {
        require_once __DIR__.'/mail.php';
        $sent = notify_email_send($to, $subj, nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8')))['ok']; // smtp_send → запасной mail(), как в channel_send
    }
    // 'inbox' — папка по умолчанию: для direction='out' письмо попадает в «Отправленные»
    if ($sent) {
        try { pdo()->prepare("UPDATE crm_messages SET folder='inbox' WHERE id=?")->execute([$id]); } catch (Throwable $e) {}
        if (!empty($m['lead_id'])) audit((int)$m['lead_id'], (int)(current_user()['id'] ?? 0), 'msg_sent', ['channel' => 'email', 'via' => 'mailbox']);
        // Обучение: черновик робота ушёл как есть — робот ответил правильно.
        require_once __DIR__ . '/learn.php';
        $lid = !empty($m['lead_id']) ? (int)$m['lead_id'] : null;
        if (($m['mail_kind'] ?? '') === 'robot') learn_from_draft($lid, $to, $body, $body, $id);
        else learn_from_reply($lid, $to, $body, $id, 'crm');
    }
    json_out(['ok'=>true, 'sent'=>$sent, 'id'=>$id,
        'note' => $sent ? 'Письмо отправлено' : 'SMTP не настроен — письмо осталось в «Черновиках»']);
}

/**
 * POST ?action=to_engineer&id= — из письма завести/привязать заявку и поставить
 * её в очередь инженера (статус review). Замыкает сценарий: письмо → инженер.
 */
function mb_to_engineer(array $user): void {
    $id = (int)($_POST['id'] ?? 0);
    $uid = (int)($user['id'] ?? 0);
    $st = pdo()->prepare("SELECT * FROM crm_messages WHERE id=?");
    $st->execute([$id]); $m = $st->fetch();
    if (!$m) { json_out(['ok'=>false,'error'=>'Письмо не найдено'],404); }

    $leadId = (int)($m['lead_id'] ?? 0);
    $created = false;
    if (!$leadId) {
        $r = mail_recognize((string)$m['body'], (string)$m['contact']);
        $leadId = lead_find_or_create([
            'name'    => $r['client'] ?? ($m['contact'] ?: 'Из письма'),
            'email'   => filter_var($m['contact'], FILTER_VALIDATE_EMAIL) ? $m['contact'] : '',
            'phone'   => $r['phone'] ?? '',
            'channel' => 'email',
        ]);
        if (!empty($r['type'])) {
            try { pdo()->prepare("UPDATE crm_leads SET reducer_type=? WHERE id=? AND (reducer_type IS NULL OR reducer_type='')")->execute([$r['type'],$leadId]); } catch (Throwable $e) {}
        }
        pdo()->prepare("UPDATE crm_messages SET lead_id=? WHERE id=?")->execute([$leadId,$id]);
        $created = true;
    }

    // в очередь инженера, если ещё не в поздних стадиях
    $cur = (string)pdo()->query("SELECT status FROM crm_leads WHERE id=".(int)$leadId)->fetchColumn();
    if (!in_array($cur, ['review','approved','sent','won','lost'], true)) {
        pdo()->prepare("UPDATE crm_leads SET status='review', updated_at=NOW() WHERE id=?")->execute([$leadId]);
        audit($leadId, $uid, 'status_changed', ['from'=>$cur, 'to'=>'review']);
    }
    json_out(['ok'=>true, 'lead_id'=>$leadId, 'note'=>($created?'Заявка создана и ':'').'передана инженеру на проверку']);
}
