<?php
declare(strict_types=1);

require __DIR__ . '/_guard.php';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../api/inbox.php';

$id = (int)($_GET['id'] ?? 0);
if ($id < 1) { http_response_code(404); echo 'Лид не найден. Возможно, он удалён или ссылка устарела. Вернитесь к списку заявок.'; exit; }

// Полная инженерная воронка из helpers.php (иначе бейдж/пайплайн показывают сырой код статуса).
$STATUSES = [];
foreach (funnel() as $code => $meta) { $STATUSES[$code] = $meta[0]; }

$managers = [];
try {
    $managers = pdo()->query("SELECT id, name, login FROM crm_users WHERE active = 1 ORDER BY name, login")->fetchAll();
} catch (Throwable $e) { $managers = []; }

$tpl_followup = setting('tpl_followup', '');

// Лента омниканальной переписки
$messages = [];
try { $messages = inbox_thread((int)$id); } catch (Throwable $e) { $messages = []; }
$CH_LABELS = ['email'=>'Email','telegram'=>'Telegram','max'=>'MAX','form'=>'Форма сайта'];
// последнее входящее сообщение (для «✍️ Черновик от ИИ»)
$lastIncoming = '';
foreach ($messages as $m) {
    if (($m['direction'] ?? '') === 'in') { $lastIncoming = (string)($m['body'] ?? ''); }
}

render_head('Лид #' . $id);
render_sidebar('leads');
?>
<style>
  .lead-grid{display:grid;grid-template-columns:340px 1fr;gap:var(--shelf,12px);align-items:start}
  .lbl{font-size:12px;color:var(--muted);margin-bottom:2px}
  .val{font-size:15px;margin-bottom:10px;word-break:break-word}
  .quick{display:flex;flex-wrap:wrap;gap:8px;margin-top:8px}
  .qbtn{display:inline-flex;align-items:center;gap:6px;padding:8px 12px;border-radius:var(--r-md,10px);text-decoration:none;font-size:13px;font-weight:600;color:#fff;transition:.14s;box-shadow:0 1px 2px rgba(0,0,0,.12)}
  .qbtn:hover{transform:translateY(-1px);filter:brightness(1.05);box-shadow:0 3px 8px rgba(0,0,0,.16)}
  .qb-call{background:#1f9d4d}.qb-mail{background:#2657d6}.qb-wa{background:#25d366}.qb-tg{background:#229ed9}
  .qbtn[aria-disabled=true]{opacity:.4;pointer-events:none;box-shadow:none}
  /* тег лида — бейдж дизайн-системы, свой только цвет */
  .badge--tag{background:#f0eaff;color:#6b3fd1}
  .pipe{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px}
  .pipe button{padding:7px 12px;border:1px solid var(--line);background:var(--card);color:var(--text);border-radius:var(--r-md,10px);cursor:pointer;font:inherit;font-size:13px;transition:.14s}
  .pipe button:hover{border-color:var(--red,#e11b1b);color:var(--red,#e11b1b)}
  .pipe button.on{background:var(--red,#e11b1b);color:#fff;border-color:var(--red,#e11b1b);box-shadow:0 2px 8px rgba(225,27,27,.22)}
  .pipe button.on:hover{color:#fff}
  input,select,textarea{font:inherit}
  .fld{display:flex;flex-direction:column;gap:4px;margin-bottom:12px}
  .fld label{font-size:12px;color:var(--muted)}
  .fld input,.fld select,.fld textarea{padding:9px 11px;border:1px solid var(--line);border-radius:var(--r-md,10px);background:var(--card);color:var(--text);width:100%;box-sizing:border-box;transition:border-color .14s,box-shadow .14s}
  .fld input:focus,.fld select:focus,.fld textarea:focus{outline:none;border-color:var(--red,#e11b1b);box-shadow:0 0 0 3px var(--red-soft,rgba(225,27,27,.16))}
  /* ряд кнопок внутри карточки */
  .btn-row{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
  .btn-row--gap{margin-bottom:12px}
  .wf-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}
  .wf-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:10px 12px;border-radius:var(--r-md,10px);border:1px solid var(--line);background:var(--card);color:var(--ink);font-family:inherit;font-size:13px;font-weight:600;line-height:1.2;cursor:pointer;text-decoration:none;text-align:center;transition:.12s}
  .wf-btn:hover{border-color:var(--muted);background:var(--bg);transform:translateY(-1px);box-shadow:0 2px 6px rgba(0,0,0,.08)}
  .wf-btn--ok{border-color:#bfe6cc;color:#178a45;background:#f2fbf5}
  .wf-btn--ok:hover{background:#e7f7ec}
  .wf-btn--warn{border-color:#f3d6b0;color:#b45309;background:#fff8ef}
  .wf-btn--bad{border-color:#f2c4c4;color:#c92a2a;background:#fdf3f3}
  .wf-btn--kp{border-color:#c9d6ff;color:#2657d6;background:#f2f6ff}
  .wf-steps{display:flex;flex-wrap:wrap;gap:5px;margin-bottom:12px}
  .wf-step{font-size:11px;padding:4px 9px;border-radius:999px;background:var(--bg);color:var(--muted);font-weight:600;white-space:nowrap}
  .wf-step.done{background:#e7f0ff;color:#3d6fd6}
  .wf-step.cur{background:var(--red);color:#fff}
  .timeline{list-style:none;margin:0;padding:0}
  .timeline li{position:relative;padding:0 0 16px 22px;border-left:2px solid var(--line)}
  .timeline li:last-child{border-left-color:transparent}
  .timeline .dot{position:absolute;left:-7px;top:2px;width:12px;height:12px;border-radius:50%;background:var(--red);border:2px solid var(--card);box-shadow:0 0 0 1px var(--line)}
  .timeline .ev-note .dot{background:#2657d6}
  .timeline .tx{font-size:14px;white-space:pre-wrap}
  .timeline .tm{font-size:12px;color:var(--muted);margin-top:2px}
  .ok-flash{color:#1f9d4d;font-size:13px;margin-left:8px}
  .lost-box{display:none}
  /* Единая лента активности */
  .feed{list-style:none;margin:0;padding:0}
  .feed li{position:relative;padding:0 0 16px 34px;border-left:2px solid var(--line)}
  .feed li:last-child{border-left-color:transparent;padding-bottom:0}
  /* служебная строка ленты («Загрузка…», «Активности пока нет») — без линии и отступа под иконку */
  .feed li.feed-empty{border:0;padding-left:0}
  .feed .ic{position:absolute;left:-13px;top:-2px;width:26px;height:26px;border-radius:50%;
            display:flex;align-items:center;justify-content:center;font-size:13px;
            background:var(--card);border:2px solid var(--line)}
  .feed .ft{font-size:14px;font-weight:600}
  .feed .fb{font-size:13px;color:var(--text);white-space:pre-wrap;word-break:break-word;margin-top:2px}
  .feed .fm{font-size:12px;color:var(--muted);margin-top:2px}
  .feed li.k-msg_in  .ic{border-color:#229ed9;color:#229ed9}
  .feed li.k-msg_out .ic{border-color:#c41616;color:#c41616}
  .feed li.k-event   .ic{border-color:var(--muted)}
  .feed li.k-note    .ic{border-color:#2657d6;color:#2657d6}
  .feed li.k-ai      .ic{border-color:#6b3fd1;color:#6b3fd1}
  .feed li.k-msg_in  .ft{color:#1577a3}
  .feed li.k-msg_out .ft{color:#c41616}
  .feed li.k-note    .ft{color:#2657d6}
  .feed li.k-ai      .ft{color:#6b3fd1}
  /* Переписка (омниканал): пузырь входящего — слева, исходящего — справа в фирменном тоне */
  .omsg{display:flex;justify-content:flex-start;margin-bottom:10px}
  .omsg--out{justify-content:flex-end}
  .omsg__b{max-width:80%;padding:9px 12px;border-radius:12px;border-bottom-left-radius:4px;font-size:14px;line-height:1.45;
           background:var(--bg);border:1px solid var(--line)}
  .omsg--out .omsg__b{background:var(--red-soft);border-color:rgba(225,27,27,.22);border-bottom-left-radius:12px;border-bottom-right-radius:4px}
  .omsg__ch{font-size:11px;font-weight:600;letter-spacing:.02em;margin-bottom:4px;color:var(--muted)}
  .omsg--out .omsg__ch{color:#c41616}
  .omsg__tx{word-break:break-word}
  .omsg__tm{font-size:11px;color:var(--muted);margin-top:5px}
  .omsg--out .omsg__tm{text-align:right}
  .take-row{display:flex;align-items:center;gap:10px;margin-bottom:12px;flex-wrap:wrap}
  .take-who{font-size:13px;color:#1f9d4d}
  @media(max-width:820px){.lead-grid{grid-template-columns:1fr}}
</style>

<div class="page-head">
  <div class="page-head__title">
    <a href="leads.php" class="btn btn-ghost btn-sm">← К списку</a>
    <h1 class="page-title">Лид #<?= $id ?> <span id="hdrBadge"></span></h1>
  </div>
  <!-- Удаление — отдельно справа и красным, чтобы не нажималось рядом с рабочими действиями. -->
  <div class="page-head__actions">
    <button class="btn btn-ghost lead-del" id="btnDeleteLead"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><path d="M4 7h16"/><path d="M10 11v6M14 11v6"/><path d="M6 7l1 13h10l1-13"/><path d="M9 7V4h6v3"/></svg>Удалить заявку</button>
  </div>
</div>

<div class="help help--info">
  <span class="help__icon">💡</span>
  <div class="help__body">
    <b>Как вести лид.</b> Меняйте «Статус сделки» по мере работы с клиентом.
    <ul>
      <li><b><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><path d="M11 11V4.5a1.5 1.5 0 0 1 3 0V11"/><path d="M14 10.5V3.5a1.5 1.5 0 0 1 3 0V12"/><path d="M17 11.5v-1a1.5 1.5 0 0 1 3 0V15a6 6 0 0 1-6 6h-2a6 6 0 0 1-6-6v-3a1.5 1.5 0 0 1 3 0"/></svg> Взять в работу</b> — закрепляет лид за вами. Дальше вы отвечаете за клиента и видны коллегам как ответственный.</li>
      <li><b><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><circle cx="12" cy="12" r="9"/><path d="m15 9-2 5-5 2 2-5 5-2Z"/></svg> Лента активности</b> — единая хронология по лиду: статусы, письма, сообщения, заметки.</li>
      <li><b><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><rect x="4" y="8" width="16" height="12" rx="3"/><path d="M12 4v4"/><circle cx="9" cy="14" r="1"/><circle cx="15" cy="14" r="1"/></svg> ИИ-кнопки</b> работают через Claude и требуют ключ Anthropic в Настройках (обращения платные). Результат всегда проверяйте перед отправкой.</li>
    </ul>
  </div>
</div>

<div class="lead-grid lead-grid--compact">
  <div>
    <div class="card">
      <h2 class="card__title">Контакты</h2>
      <!-- Повторное обращение: заявка от известного клиента не создаёт новую карточку,
           а обновляет эту. Без явной плашки менеджер считал, что заявка не дошла. -->
      <div id="cRepeat" style="display:none;margin:0 0 12px;padding:10px 12px;border-radius:10px;
           background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;font-size:13px;line-height:1.5"></div>
      <div class="lbl">Имя</div><div class="val" id="cName">—</div>
      <div class="lbl">Телефон</div><div class="val" id="cPhone">—</div>
      <div class="lbl">Email</div><div class="val" id="cEmail">—</div>
      <div class="lbl">Тип редуктора</div><div class="val" id="cType">—</div>
      <div class="lbl">Сообщение</div><div class="val" id="cMsg">—</div>
      <!-- Вложение: клиент часто присылает фото шильда — это самый ценный сигнал
           для подбора аналога, а в карточке его раньше не было видно вообще. -->
      <div class="lbl">Вложение</div><div class="val" id="cFile">—</div>
      <div class="lbl">Страница / источник</div><div class="val muted" id="cSrc" style="font-size:13px">—</div>
      <!-- Рекламная атрибуция: по какому запросу/кампании пришёл клиент.
           utm_term = ключевая фраза Директа, yclid = клик Яндекса. -->
      <div id="cAdBox" style="display:none">
        <div class="lbl">Рекламный запрос</div>
        <div class="val" id="cQuery" style="font-size:14px;color:var(--ink);font-weight:600">—</div>
        <div class="muted" id="cAdMeta" style="font-size:12px;margin:-4px 0 10px;line-height:1.6"></div>
      </div>
      <div class="quick" id="quick"></div>
      <div style="margin-top:10px">
        <a class="btn btn-ghost" id="kpPdf" href="kp.php?id=<?= $id ?>" target="_blank" rel="noopener"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5Z"/><path d="M14 3v5h5"/></svg> Открыть КП (печать в PDF)</a>
      </div>
      <div class="hint">Откроется страница КП — сохраните в PDF через печать. Проверьте цены и позиции перед отправкой клиенту.</div>
    </div>

    <div class="card">
      <h2 class="card__title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><path d="M3 11V4h7l11 11-7 7L3 11Z"/><circle cx="7.5" cy="7.5" r="1.4"/></svg> Теги</h2>
      <div id="tagsView" style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px"></div>
      <div class="fld">
        <label>Теги (через запятую)</label>
        <input type="text" id="tagsInput" placeholder="например: опт, срочно, vip">
      </div>
      <button class="btn btn-primary" id="saveTags">Сохранить теги</button>
      <span class="ok-flash" id="tagsFlash"></span>
    </div>

    <div class="card">
      <h2 class="card__title">Статус сделки</h2>
      <div class="take-row">
        <button class="btn btn-primary" id="btnTake"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><path d="M11 11V4.5a1.5 1.5 0 0 1 3 0V11"/><path d="M14 10.5V3.5a1.5 1.5 0 0 1 3 0V12"/><path d="M17 11.5v-1a1.5 1.5 0 0 1 3 0V15a6 6 0 0 1-6 6h-2a6 6 0 0 1-6-6v-3a1.5 1.5 0 0 1 3 0"/></svg> Взять в работу</button>
        <span class="take-who" id="takeWho"></span>
        <span class="ok-flash" id="takeFlash"></span>
      </div>
      <div class="pipe" id="pipe"></div>
      <div class="fld">
        <label>Менеджер</label>
        <select id="mgr">
          <option value="0">Не назначен</option>
          <?php foreach ($managers as $m): $nm = $m['name'] !== '' ? $m['name'] : $m['login']; ?>
            <option value="<?= (int)$m['id'] ?>"><?= htmlspecialchars($nm, ENT_QUOTES) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="fld">
        <label>Сумма сделки, ₽</label>
        <input type="number" id="amount" min="0" step="0.01" value="0">
      </div>
      <div class="fld lost-box" id="lostBox">
        <label>Причина отказа</label>
        <input type="text" id="lostReason" placeholder="дорого / выбрали конкурента / отложили закупку">
      </div>
      <button class="btn btn-primary" id="saveDeal">Сохранить</button>
      <span class="ok-flash" id="dealFlash"></span>
    </div>

    <div class="card">
      <h2 class="card__title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M19.1 4.9 17 7M7 17l-2.1 2.1"/></svg> Инженерный процесс</h2>
      <div class="hint">Этапы движения заявки: инженер подбирает → менеджер проверяет → КП → отправка клиенту. Кнопки меняют статус и пишутся в ленту.</div>
      <div class="wf-steps" id="wfSteps"></div>
      <div class="wf-grid">
        <button class="wf-btn" data-wf="in_progress"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><path d="M14.7 6.3a4 4 0 0 1-5.4 5.4L4 17v3h3l5.3-5.3a4 4 0 0 1 5.4-5.4l-2.6 2.6-1.4-1.4 2.6-2.6Z"/></svg> В работу</button>
        <button class="wf-btn" data-wf="clarify">📩 Запросить уточнение</button>
        <button class="wf-btn" data-wf="picked"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><path d="m4 13 5 5L20 7"/></svg> Подбор выполнен</button>
        <button class="wf-btn" data-wf="review"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6-10-6-10-6Z"/><circle cx="12" cy="12" r="2.6"/></svg> На проверку</button>
        <button class="wf-btn wf-btn--ok" data-wf="approved">✔ Одобрить</button>
        <button class="wf-btn wf-btn--warn" data-wf="rework" data-note="1"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5Z"/></svg> На доработку</button>
        <a class="wf-btn wf-btn--kp" href="kp.php?id=<?= $id ?>" target="_blank" rel="noopener" title="Откроется страница КП — сохраните в PDF через печать"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5Z"/><path d="M14 3v5h5"/></svg> Открыть КП (печать в PDF)</a>
        <button class="wf-btn" data-wf="sent"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><path d="M12 13V3"/><path d="m8 7 4-4 4 4"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/></svg> Отправлено клиенту</button>
        <button class="wf-btn wf-btn--ok" data-wf="won">🏆 Успешно</button>
        <button class="wf-btn wf-btn--bad" data-wf="lost" data-note="1">🚫 Отказ (клиент не купил)</button>
      </div>
      <span class="ok-flash" id="wfFlash"></span>
    </div>
  </div>

  <div>
      <div class="tabs">
        <button class="active" data-tab="feed">Лента</button>
        <button data-tab="email">Письмо</button>
        <button data-tab="omni">Переписка</button>
        <button data-tab="ai">ИИ</button>
        <button data-tab="note">Заметка</button>
      </div>
      <div class="tab-panel active" data-tab="feed">

    <div class="card">
      <h2 class="card__title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><circle cx="12" cy="12" r="9"/><path d="m15 9-2 5-5 2 2-5 5-2Z"/></svg> Лента активности</h2>
      <ul class="feed" id="feed" style="max-height:250px;overflow-y:auto"><li class="feed-empty muted">Загрузка…</li></ul>
    </div>

    </div>
    <div class="tab-panel" data-tab="email">
<div class="card">
      <h2 class="card__title">Письмо клиенту</h2>
      <div class="fld">
        <label>Тема</label>
        <input type="text" id="emSubj" value="Завод Редукторов — ответ по вашей заявке">
      </div>
      <div class="fld">
        <label>Текст</label>
        <textarea id="emBody" rows="5"><?= htmlspecialchars((string)$tpl_followup, ENT_QUOTES) ?></textarea>
      </div>
      <button class="btn btn-primary" id="sendEmail">Отправить письмо</button>
      <span class="ok-flash" id="emFlash"></span>
      <div class="hint">Письмо уйдёт на email клиента из заявки и сохранится в «Переписке» и «Ленте активности». Email в заявке должен быть заполнен.</div>
    </div>

    </div>
    <div class="tab-panel" data-tab="omni">
<div class="card">
      <h2 class="card__title">💬 Переписка (омниканал)</h2>
      <div id="omniThread" style="max-height:240px;overflow-y:auto;padding:4px 2px;margin-bottom:14px">
        <?php if (!$messages): ?>
          <div class="empty">Переписки пока нет. Первое сообщение появится здесь после ответа клиенту или его обращения.</div>
        <?php else: foreach ($messages as $m):
          $dir = (string)($m['direction'] ?? '');
          $isOut = ($dir === 'out');
          $ch = (string)($m['channel'] ?? '');
          $chLbl = $CH_LABELS[$ch] ?? ($ch !== '' ? $ch : '—');
          $body = (string)($m['body'] ?? '');
        ?>
          <div class="omsg<?= $isOut ? ' omsg--out' : '' ?>">
            <div class="omsg__b">
              <div class="omsg__ch">
                <?= htmlspecialchars($chLbl, ENT_QUOTES) ?> · <?= $isOut ? 'исходящее' : 'входящее' ?>
              </div>
              <div class="omsg__tx"><?= nl2br(htmlspecialchars($body, ENT_QUOTES)) ?></div>
              <div class="omsg__tm">
                <?= htmlspecialchars((string)($m['created_at'] ?? ''), ENT_QUOTES) ?>
              </div>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>

      <div class="hint">Выберите канал — сообщение уйдёт клиенту именно через него (Email, Telegram или MAX).</div>
      <div class="fld">
        <label>Канал ответа</label>
        <select id="omniChannel">
          <option value="email">Email</option>
          <option value="telegram">Telegram</option>
          <option value="max">MAX</option>
        </select>
      </div>
      <div class="fld">
        <label>Текст ответа</label>
        <textarea id="omniBody" rows="4" placeholder="Сообщение клиенту…"></textarea>
      </div>
      <div class="btn-row">
        <button class="btn btn-primary" id="omniSend">Отправить</button>
        <button class="btn btn-ghost" id="omniDraft"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5Z"/></svg> Черновик от ИИ</button>
        <span class="ok-flash" id="omniFlash"></span>
      </div>
      <div class="hint"><b><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5Z"/></svg> Черновик от ИИ</b> — Claude сгенерирует черновик ответа клиенту в поле выше. Прочитайте и поправьте перед отправкой.</div>
    </div>

    </div>
    <div class="tab-panel" data-tab="ai">
    <div class="card">
      <h2 class="card__title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><rect x="4" y="8" width="16" height="12" rx="3"/><path d="M12 4v4"/><circle cx="9" cy="14" r="1"/><circle cx="15" cy="14" r="1"/></svg> ИИ-ассистент <span class="tip" data-tip="Работает на Claude. Требует ключ Anthropic (Настройки). Каждый запрос платный — результат всегда проверяйте перед отправкой.">?</span></h2>
      <div class="fld">
        <label>Доп. параметры (мощность, обороты, бренд/модель)</label>
        <textarea id="aiExtra" rows="2" placeholder="например: 7.5 кВт, 1500 об/мин, аналог NMRV"></textarea>
      </div>
      <div class="btn-row btn-row--gap">
        <button class="btn btn-primary" id="aiSuggest">Подобрать аналог</button>
        <button class="btn btn-ghost" id="aiNameplate" title="Нужно приложенное к заявке фото шильдика">Черновик по шильдику</button>
      </div>
      <div class="hint"><b>ИИ-подбор аналога.</b> Claude по данным заявки и базе знаний предлагает подходящий аналог редуктора. Результат — подсказка, проверяйте по каталогу.</div>
      <div class="fld">
        <label>Результат подбора</label>
        <div id="aiResult" style="white-space:pre-wrap;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:13px;background:var(--bg);border:1px solid var(--line);border-radius:var(--r-sm,8px);padding:11px;min-height:42px;color:var(--ink)">—</div>
      </div>

      <hr style="border:0;border-top:1px solid var(--line);margin:14px 0">

      <div class="fld">
        <label>Канал</label>
        <select id="aiChannel">
          <option value="email">Email</option>
          <option value="messenger">Мессенджер</option>
        </select>
      </div>
      <div class="fld">
        <label>Входящее от клиента (необязательно)</label>
        <textarea id="aiIncoming" rows="2" placeholder="Текст вопроса/сообщения клиента…"></textarea>
      </div>
      <div class="btn-row btn-row--gap">
        <button class="btn btn-primary" id="aiDraft">Сгенерировать ответ клиенту</button>
      </div>
      <div class="hint"><b>ИИ-черновик письма.</b> Генерирует черновик ответа клиенту (с учётом входящего, если заполнено). Обязательно правьте текст перед отправкой.</div>
      <div class="fld">
        <label>Черновик ответа</label>
        <textarea id="aiDraftText" rows="6" placeholder="Здесь появится сгенерированный ответ…"></textarea>
      </div>
      <div class="btn-row">
        <button class="btn btn-ghost" id="aiCopy">Скопировать</button>
        <button class="btn btn-ghost" id="aiToEmail">Вставить в письмо</button>
      </div>
    </div>

    </div>
    <div class="tab-panel" data-tab="note">
    <div class="card">
      <h2 class="card__title">Заметка</h2>
      <div class="fld">
        <textarea id="noteText" rows="3" placeholder="Договорённость, следующий шаг, о чём созвонились…"></textarea>
      </div>
      <button class="btn btn-primary" id="addNote">Добавить заметку</button>
      <span class="ok-flash" id="noteFlash"></span>
    </div>

    <div class="card">
      <h2 class="card__title">Активность</h2>
      <ul class="timeline" id="timeline" style="max-height:200px;overflow-y:auto"><li class="tx muted" style="border:0">Загрузка…</li></ul>
    </div>
    </div>
  </div>
</div>

<script>
const LEAD_ID = <?= $id ?>;
const CURRENT_UID = <?= (int)((current_user()['id'] ?? 0)) ?>;
const LAST_INCOMING = <?= json_encode((string)$lastIncoming, JSON_UNESCAPED_UNICODE) ?>;
const API = '../api/leads.php';
const STATUSES = <?= json_encode($STATUSES, JSON_UNESCAPED_UNICODE) ?>;
const EV_LABELS = {
  lead_created:'Заявка создана', status_changed:'Смена статуса', note_added:'Добавлена заметка',
  assigned:'Назначен менеджер', email_sent:'Отправлено письмо', call_logged:'Звонок', login:'Вход',
  lead_reinquiry:'Повторное обращение',
  notify_email:'Уведомление на email', notify_telegram:'Уведомление в Telegram',
  notify_telegram_doc:'Вложение в Telegram', notify_max:'Уведомление в MAX',
  notify_test:'Тест каналов уведомлений'
};

function esc(s){const d=document.createElement('div');d.textContent=(s==null?'':String(s));return d.innerHTML;}
function fmtDate(s){if(!s)return '';const d=new Date(String(s).replace(' ','T'));return isNaN(d)?esc(s):d.toLocaleString('ru-RU');}
function digits(s){return (s||'').replace(/\D+/g,'');}
// Нормализация для tel:/wa.me/t.me: российское 8XXXXXXXXXX (11 цифр) → 7XXXXXXXXXX
function phoneDigits(s){let d=digits(s);if(d.length===11&&d[0]==='8')d='7'+d.slice(1);return d;}

async function post(extra){
  const body=new URLSearchParams(Object.assign({csrf:window.CSRF||''}, extra));
  const r=await fetch(API,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF':window.CSRF||''},body});
  return r.json();
}
function flash(el,msg,ok=true){el.textContent=msg;el.style.color=ok?'#1f9d4d':'#cf2020';setTimeout(()=>el.textContent='',2500);}

let lead=null;

async function load(){
  const r=await fetch(`${API}?action=get&id=${LEAD_ID}`,{headers:{'Accept':'application/json'}});
  const j=await r.json();
  if(!j||!j.ok||!(j.lead||j.item)){document.getElementById('cName').textContent='Лид не найден';return;}
  lead=j.lead||j.item;
  // Заметки тоже в ленту: раньше в «Активности» было только «Добавлена заметка» без текста.
  render([...(j.events||lead.events||[]), ...((j.notes||[]).map(n=>Object.assign({}, n, {kind:'note'})))]);
}

function render(events){
  document.getElementById('cName').textContent=lead.name||'—';
  document.getElementById('cPhone').textContent=lead.phone||'—';
  document.getElementById('cEmail').textContent=lead.email||'—';
  document.getElementById('cType').textContent=lead.reducer_type||'—';
  document.getElementById('cMsg').textContent=lead.message||'—';

  // Сколько раз клиент обращался. Новая заявка от него обновляет эту же карточку,
  // поэтому «нового лида нет» — это норма, а не потеря заявки.
  const rep=document.getElementById('cRepeat');
  const cnt=parseInt(lead.inquiries_count||1,10);
  if(cnt>1){
    rep.innerHTML='<b>⟳ Повторное обращение — '+cnt+'-е по счёту.</b><br>'
      +'Клиент уже был в базе, поэтому новая заявка обновила эту карточку, а не создала вторую. '
      +'Последнее обращение: <b>'+fmtDate(lead.last_inquiry_at||lead.updated_at)+'</b>. '
      +'Текст прошлого обращения сохранён в поле «Сообщение» под разделителем, полная история — в ленте активности.';
    rep.style.display='';
  } else {
    rep.style.display='none';
  }

  // Вложение: файл отдаёт защищённый /api/file.php (только авторизованным).
  // Для картинок сразу показываем превью — фото шильда должно читаться без скачивания.
  const fEl=document.getElementById('cFile');
  if(lead.file_path){
    const url='../api/file.php?id='+encodeURIComponent(lead.id);
    const nm=String(lead.file_path).split('/').pop()||'файл';
    const isImg=/\.(jpe?g|jfif|png|webp|gif|bmp|heic|heif|avif|tiff?)$/i.test(nm);
    fEl.innerHTML = isImg
      ? '<a href="'+url+'" target="_blank" rel="noopener">'
        + '<img src="'+url+'" alt="Вложение заявки" style="max-width:100%;max-height:260px;'
        + 'border:1px solid var(--line);border-radius:8px;display:block;margin-bottom:6px"></a>'
        + '<a href="'+url+'" target="_blank" rel="noopener">📎 '+nm+'</a>'
      : '<a href="'+url+'" target="_blank" rel="noopener">📎 '+nm+'</a>';
  } else {
    fEl.textContent='—';
  }

  const srcParts=[lead.page_title,lead.utm_source,lead.utm_campaign].filter(Boolean).join(' · ');
  document.getElementById('cSrc').textContent=srcParts||lead.source||'—';

  // Рекламная атрибуция: по какому запросу/кампании клиент пришёл и создал заявку.
  // Значения могут прийти из БД как экранированными (форма→sanitize), так и сырыми
  // (page_url из HTTP_REFERER в попытках). Поэтому строим строго через DOM API
  // (textContent/setAttribute) — XSS невозможен независимо от источника.
  (function(){
    const box=document.getElementById('cAdBox');
    // Раскодировать HTML-сущности (&amp;→&, &quot;→") для корректного отображения/URL.
    const dec=(s)=>{const t=document.createElement('textarea');t.innerHTML=String(s==null?'':s);return t.value.trim();};
    const term=dec(lead.utm_term), yclid=dec(lead.yclid), gclid=dec(lead.gclid),
          camp=dec(lead.utm_campaign), src=dec(lead.utm_source), pageUrl=dec(lead.page_url);
    if(!term&&!yclid&&!gclid&&!camp){ box.style.display='none'; return; }
    box.style.display='';
    const qEl=document.getElementById('cQuery');
    if(term){ qEl.textContent='«'+term+'»'; qEl.style.color='var(--ink)'; }
    else { qEl.textContent='запрос не передан рекламой'; qEl.style.color='var(--muted)'; qEl.style.fontWeight='400'; }

    const metaEl=document.getElementById('cAdMeta'); metaEl.textContent='';
    const addRow=(fn)=>{const d=document.createElement('div'); fn(d); metaEl.appendChild(d);};
    if(src||camp) addRow(d=>{ d.textContent='Кампания: '+[src,camp].filter(Boolean).join(' / '); });
    const clickRow=(label,val)=>addRow(d=>{ d.appendChild(document.createTextNode(label)); const c=document.createElement('code'); c.style.fontSize='11px'; c.textContent=val.slice(0,24)+(val.length>24?'…':''); d.appendChild(c); });
    if(yclid) clickRow('Яндекс-клик (yclid): ',yclid);
    if(gclid) clickRow('Google-клик (gclid): ',gclid);
    if(pageUrl) addRow(d=>{
      d.appendChild(document.createTextNode('Пришёл на: '));
      // Ссылка — только внутренний путь (/…) или http(s)://хост. Отсекаем //host,
      // javascript:, data: и прочее. setAttribute не парсит HTML — атрибут не сломать.
      const safe=/^https?:\/\/[^/]/i.test(pageUrl)||/^\/[^/]/.test(pageUrl);
      const txt=pageUrl.replace(/^https?:\/\/[^/]+/,'').slice(0,60)||pageUrl.slice(0,60);
      if(safe){ const a=document.createElement('a'); a.setAttribute('href',pageUrl); a.target='_blank'; a.rel='noopener'; a.textContent=txt; d.appendChild(a); }
      else { d.appendChild(document.createTextNode(txt)); }
    });
    if(!term&&(yclid||camp)) addRow(d=>{ d.style.color='#c47d00'; d.textContent='Точная фраза — на вкладке «Директ → Запросы» или в Метрике по yclid. Чтобы фраза приходила в заявку, добавьте в кампаниях метку utm_term={keyword}.'; });
  })();

  // быстрые действия
  const ph=phoneDigits(lead.phone), em=lead.email||'';
  const q=document.getElementById('quick');
  q.innerHTML=
    `<a class="qbtn qb-call" href="${ph?('tel:+'+ph):'#'}" ${ph?'':'aria-disabled=true'}>Позвонить</a>`
    +`<a class="qbtn qb-mail" href="${em?('mailto:'+ZR.escapeHtml(em)):'#'}" ${em?'':'aria-disabled=true'}>Email</a>`
    +`<a class="qbtn qb-wa" href="${ph?('https://wa.me/'+ph):'#'}" target="_blank" ${ph?'':'aria-disabled=true'}>WhatsApp</a>`
    +`<a class="qbtn qb-tg" href="${ph?('https://t.me/+'+ph):'#'}" target="_blank" ${ph?'':'aria-disabled=true'}>Telegram</a>`;

  // статус-бейдж в шапке
  document.getElementById('hdrBadge').innerHTML=`<span class="badge badge--${lead.status}">${esc(STATUSES[lead.status]||lead.status)}</span>`;

  // pipeline
  const pipe=document.getElementById('pipe');
  pipe.innerHTML='';
  for(const st in STATUSES){
    const b=document.createElement('button');
    b.textContent=STATUSES[st];
    if(st===lead.status)b.classList.add('on');
    b.onclick=()=>changeStatus(st);
    pipe.appendChild(b);
  }
  document.getElementById('mgr').value=String(lead.manager_id||0);
  document.getElementById('amount').value=lead.amount||0;
  document.getElementById('lostReason').value=lead.lost_reason||'';
  document.getElementById('lostBox').style.display = lead.status==='lost'?'flex':'none';

  renderTags(lead.tags);
  renderWfSteps();

  // Кто ведёт лид + кнопка «Взять в работу»
  const takeWho=document.getElementById('takeWho');
  const btnTake=document.getElementById('btnTake');
  const mid=parseInt(lead.manager_id||0,10)||0;
  if(mid){
    const isMine = CURRENT_UID && mid===CURRENT_UID;
    takeWho.textContent = isMine ? 'Ведёте вы' : ('Ведёт: '+(lead.manager_name||('менеджер #'+mid)));
    btnTake.style.display = isMine ? 'none' : '';
  } else {
    takeWho.textContent='';
    btnTake.style.display='';
  }

  renderTimeline(events);
  loadFeed();
}

// Удаление заявки. Необратимо, поэтому спрашиваем подтверждение и уводим на список.
// Обработчик вешается ОДИН раз на верхнем уровне: внутри render() он размножался при каждом load()
// и к тому же разрывал строку `const btnTake=…` — лента активности падала на «Загрузка…».
document.getElementById('btnDeleteLead')?.addEventListener('click', async ()=>{
  if(!confirm('Удалить эту заявку?\n\nДействие необратимо. Снимок заявки останется в журнале событий.')) return;
  const j = await post({action:'delete', id: LEAD_ID});
  if (j && j.ok) { location.href = 'leads.php'; }
  else if (j) ZR.toast((j && j.error) || 'Не удалось удалить','error');
});

async function loadFeed(){
  const el=document.getElementById('feed');
  if(!el) return;
  try{
    const r=await fetch(`${API}?action=timeline&id=${LEAD_ID}`,{headers:{'Accept':'application/json'}});
    const j=await r.json();
    if(!j||!j.ok||!Array.isArray(j.items)){el.innerHTML='<li class="feed-empty muted">Лента недоступна</li>';return;}
    if(!j.items.length){el.innerHTML='<li class="feed-empty muted">Активности пока нет</li>';return;}
    el.innerHTML=j.items.map(it=>{
      const kind=esc(it.kind||'event');
      const body=it.body?`<div class="fb">${esc(it.body)}</div>`:'';
      return `<li class="k-${kind}"><span class="ic">${esc(it.icon||'•')}</span>`
        +`<div class="ft">${esc(it.title||'')}</div>${body}`
        +`<div class="fm">${fmtDate(it.ts)}</div></li>`;
    }).join('');
  }catch(err){
    el.innerHTML='<li class="feed-empty" style="color:#cf2020">Ошибка загрузки ленты</li>';
  }
}

function parseTags(raw){
  return String(raw||'').split(',').map(s=>s.trim()).filter(Boolean);
}
function renderTags(raw){
  const list=parseTags(raw);
  const box=document.getElementById('tagsView');
  const inp=document.getElementById('tagsInput');
  if(inp) inp.value=list.join(', ');
  if(!box) return;
  if(!list.length){box.innerHTML='<span class="muted" style="font-size:13px">Тегов нет</span>';return;}
  box.innerHTML=list.map(t=>`<span class="badge badge--tag">${esc(t)}</span>`).join('');
}

function renderTimeline(events){
  const tl=document.getElementById('timeline');
  if(!events||!events.length){tl.innerHTML='<li class="tx muted" style="border:0">Событий пока нет</li>';return;}
  events.sort((a,b)=>String(b.created_at).localeCompare(String(a.created_at)));
  tl.innerHTML=events.map(e=>{
    const isNote=e.kind==='note'||e.type==='note';
    let txt;
    if(isNote){ txt=e.text||''; }
    else {
      txt=EV_LABELS[e.type]||e.type;
      let p=e.payload; if(typeof p==='string'){try{p=JSON.parse(p);}catch(_){p=null;}}
      if(p){
        if(p.ok!==undefined){ // события доставки уведомлений
          txt += p.ok ? ' · доставлено ✓' : ' · не удалось ✗';
          if(p.via) txt+=` через ${esc(p.via)}`;
          if(p.to && String(p.to).indexOf('@')>-1) txt+=` (${esc(p.to)})`;
          if(!p.ok && p.reason) txt+=` — ${esc(p.reason)}`;
        }
        else if(p.to&&p.from)txt+=`: ${esc(STATUSES[p.from]||p.from)} → ${esc(STATUSES[p.to]||p.to)}`;
        else if(p.status)txt+=`: ${esc(STATUSES[p.status]||p.status)}`;
        else if(p.manager)txt+=`: ${esc(p.manager)}`; }
    }
    const who=e.user_name?(' · '+esc(e.user_name)):'';
    return `<li class="${isNote?'ev-note':''}"><span class="dot"></span><div class="tx">${esc(txt)}</div><div class="tm">${fmtDate(e.created_at)}${who}</div></li>`;
  }).join('');
}

async function changeStatus(st){
  const extra={action:'update',id:LEAD_ID,status:st};
  if(st==='lost'){const lr=document.getElementById('lostReason').value;if(lr)extra.lost_reason=lr;}
  const j=await post(extra);
  if(j&&j.ok){load();}else (window.ZR&&ZR.toast?ZR.toast((j&&j.error)||'Ошибка','error'):alert((j&&j.error)||'Ошибка'));
}

// Инженерная воронка — степпер (done/cur) по порядку STATUSES, без итоговых won/lost.
function renderWfSteps(){
  const box=document.getElementById('wfSteps'); if(!box||!lead) return;
  const flow=Object.keys(STATUSES).filter(s=>s!=='won'&&s!=='lost');
  let curIdx=flow.indexOf(lead.status);
  box.innerHTML=flow.map((s,i)=>{
    let cls='wf-step';
    if(s===lead.status)cls+=' cur';
    else if(curIdx>=0&&i<curIdx)cls+=' done';
    return `<span class="${cls}">${esc(STATUSES[s])}</span>`;
  }).join('');
}

// Переход по воронке через action=workflow (кнопки инженера/менеджера).
async function wf(status, needsNote){
  let note='';
  if(needsNote){
    const q = status==='lost' ? 'Причина отказа:' : 'Что исправить / комментарий инженеру:';
    note = prompt(q, ''); if(note===null) return; // отмена
  }
  const j=await post({action:'workflow',id:LEAD_ID,status,note});
  if(j&&j.ok){
    if(window.ZR&&window.ZR.toast)window.ZR.toast('Статус: '+(j.label||status),'success');
    else flash(document.getElementById('wfFlash'),'Статус: '+(j.label||status));
    load();
  } else {
    flash(document.getElementById('wfFlash'),(j&&j.error)||'Ошибка',false);
  }
}
document.querySelectorAll('.wf-btn[data-wf]').forEach(b=>{
  b.addEventListener('click',()=>wf(b.dataset.wf, b.dataset.note==='1'));
});

document.getElementById('btnTake').onclick=async(e)=>{
  const btn=e.currentTarget;
  const old=btn.textContent;
  btn.disabled=true;btn.textContent='Беру…';
  try{
    const j=await post({action:'take',id:LEAD_ID});
    if(j&&j.ok){
      if(window.ZR&&typeof window.ZR.toast==='function')window.ZR.toast('Лид взят в работу','success');
      else flash(document.getElementById('takeFlash'),'Взято в работу');
      load();
    }else{
      flash(document.getElementById('takeFlash'),(j&&j.error)||'Ошибка',false);
    }
  }catch(err){
    flash(document.getElementById('takeFlash'),'Ошибка сети',false);
  }finally{
    btn.disabled=false;btn.textContent=old;
  }
};

document.getElementById('saveDeal').onclick=async(e)=>{
  const btn=e.currentTarget;
  const extra={action:'update',id:LEAD_ID,
    manager_id:document.getElementById('mgr').value,
    amount:document.getElementById('amount').value,
    lost_reason:document.getElementById('lostReason').value};
  btn.disabled=true;
  try{
    const j=await post(extra);
    if(j&&j.ok){flash(document.getElementById('dealFlash'),'Сохранено');load();}
    else flash(document.getElementById('dealFlash'),(j&&j.error)||'Ошибка',false);
  }catch(err){
    flash(document.getElementById('dealFlash'),'Ошибка сети',false);
  }finally{
    btn.disabled=false;
  }
};

document.getElementById('addNote').onclick=async(e)=>{
  const btn=e.currentTarget;
  const t=document.getElementById('noteText').value.trim();
  if(!t)return;
  btn.disabled=true;
  try{
    const j=await post({action:'note',id:LEAD_ID,text:t});
    if(j&&j.ok){document.getElementById('noteText').value='';flash(document.getElementById('noteFlash'),'Добавлено');load();}
    else flash(document.getElementById('noteFlash'),(j&&j.error)||'Ошибка',false);
  }catch(err){
    flash(document.getElementById('noteFlash'),'Ошибка сети',false);
  }finally{
    btn.disabled=false;
  }
};

document.getElementById('saveTags').onclick=async(e)=>{
  const btn=e.currentTarget;
  const raw=document.getElementById('tagsInput').value;
  const tags=parseTags(raw).join(',');
  const flashEl=document.getElementById('tagsFlash');
  btn.disabled=true;
  try{
    const j=await post({action:'tags',id:LEAD_ID,tags});
    if(j&&j.ok){
      flash(flashEl,'Сохранено');
      if(lead) lead.tags=tags;
      renderTags(tags);
    } else {
      flash(flashEl,(j&&j.error)||'Эндпоинт тегов недоступен',false);
    }
  }catch(err){
    flash(flashEl,'Ошибка сети',false);
  }finally{
    btn.disabled=false;
  }
};

document.getElementById('sendEmail').onclick=async(e)=>{
  const btn=e.currentTarget;
  const subject=document.getElementById('emSubj').value.trim();
  const bodyTxt=document.getElementById('emBody').value.trim();
  if(!bodyTxt)return;
  btn.disabled=true;
  try{
    const j=await post({action:'email',id:LEAD_ID,subject,body:bodyTxt});
    if(j&&j.ok){flash(document.getElementById('emFlash'),'Письмо отправлено');load();}
    else flash(document.getElementById('emFlash'),(j&&j.error)||'Ошибка отправки',false);
  }catch(err){
    flash(document.getElementById('emFlash'),'Ошибка сети',false);
  }finally{
    btn.disabled=false;
  }
};

// ===== ИИ-ассистент =====
const AI_API = '../api/ai.php';
const AI_ID = (function(){
  if (typeof LEAD_ID !== 'undefined' && LEAD_ID) return LEAD_ID;
  return parseInt(new URLSearchParams(location.search).get('id') || '0', 10) || 0;
})();

// POST с CSRF: предпочитаем window.ZR.apiPost, иначе fetch с X-CSRF + полем csrf
async function aiPost(url, fields){
  if (window.ZR && typeof window.ZR.apiPost === 'function') {
    return window.ZR.apiPost(url, fields);
  }
  const body = new URLSearchParams(Object.assign({csrf: window.CSRF || ''}, fields));
  const r = await fetch(url, {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF':window.CSRF||''},
    body
  });
  return r.json();
}
function aiToast(msg, type){
  if (window.ZR && typeof window.ZR.toast === 'function') { window.ZR.toast(msg, type); return; }
  alert(msg);
}

document.getElementById('aiSuggest').onclick = async (e) => {
  const btn = e.currentTarget;
  const old = btn.textContent;
  btn.disabled = true; btn.textContent = 'Думаю…';
  try {
    const j = await aiPost(`${AI_API}?action=suggest`, {
      id: AI_ID,
      extra: document.getElementById('aiExtra').value
    });
    if (j && j.ok) {
      document.getElementById('aiResult').textContent = j.text || '';
    } else {
      aiToast((j && j.error) || 'Ошибка', 'error');
    }
  } catch (err) {
    aiToast(String(err && err.message || err) || 'Ошибка сети', 'error');
  } finally {
    btn.disabled = false; btn.textContent = old;
  }
};

// Фото шильдика → три версии прочтения → ZR из справочника → черновик письма.
// Ничего не отправляет: версии в «Результат подбора», письмо — в поле черновика.
document.getElementById('aiNameplate').onclick = async (e) => {
  const btn = e.currentTarget;
  const old = btn.textContent;
  btn.disabled = true; btn.textContent = 'Читаю шильдик…';
  try {
    const j = await aiPost(`${AI_API}?action=nameplate`, { id: AI_ID });
    if (!j || !j.ok) { aiToast((j && j.error) || 'Ошибка', 'error'); return; }
    const d = j.data || {};
    const lines = [];
    if (d.readable) lines.push('На шильдике: ' + d.readable, '');
    lines.push('Версии прочтения:');
    (d.hypotheses || []).forEach((h, i) => {
      lines.push(`${i + 1}. ${h.brand} ${h.model} — уверенность ${h.confidence}% → `
        + (h.verified ? `${h.zr} (${h.source})` : 'нет в справочнике')
        + (h.price_text ? ` · ${h.price_text}` : ''));
    });
    if (!(d.hypotheses || []).length) lines.push('— модель не прочитана');
    const p = d.params || {};
    const pp = [['kw','кВт'],['ratio','i'],['rpm_out','об/мин вых.'],['torque_nm','Н·м'],['mount','крепление']]
      .filter(([k]) => p[k] && p[k] !== '—').map(([k, u]) => `${p[k]} ${u}`);
    if (pp.length) lines.push('', 'Параметры: ' + pp.join(', '));
    if ((d.missing || []).length) lines.push('', 'Не хватает: ' + d.missing.join('; '));
    lines.push('', d.autosend_ok ? '✓ Прошло бы планку автопилота' : '⚠ Автопилот сам бы не отправил — проверьте версии');
    document.getElementById('aiResult').textContent = lines.join('\n');
    document.getElementById('aiDraftText').value = d.draft || '';
    aiToast('Черновик по шильдику готов — проверьте перед отправкой', 'success');
  } catch (err) {
    aiToast(String(err && err.message || err) || 'Ошибка сети', 'error');
  } finally {
    btn.disabled = false; btn.textContent = old;
  }
};

document.getElementById('aiDraft').onclick = async (e) => {
  const btn = e.currentTarget;
  const old = btn.textContent;
  btn.disabled = true; btn.textContent = 'Думаю…';
  try {
    const j = await aiPost(`${AI_API}?action=draft`, {
      id: AI_ID,
      channel: document.getElementById('aiChannel').value,
      incoming: document.getElementById('aiIncoming').value
    });
    if (j && j.ok) {
      document.getElementById('aiDraftText').value = j.text || '';
    } else {
      aiToast((j && j.error) || 'Ошибка', 'error');
    }
  } catch (err) {
    aiToast(String(err && err.message || err) || 'Ошибка сети', 'error');
  } finally {
    btn.disabled = false; btn.textContent = old;
  }
};

document.getElementById('aiCopy').onclick = async () => {
  const txt = document.getElementById('aiDraftText').value;
  if (!txt) { aiToast('Сначала сгенерируйте черновик', 'error'); return; }
  try {
    await navigator.clipboard.writeText(txt);
    aiToast('Скопировано', 'success');
  } catch (err) {
    aiToast('Не удалось скопировать', 'error');
  }
};

document.getElementById('aiToEmail').onclick = () => {
  const txt = document.getElementById('aiDraftText').value;
  if (!txt) { aiToast('Нечего вставлять', 'error'); return; }
  const emBody = document.getElementById('emBody');
  if (emBody) {
    emBody.value = txt;
    aiToast('Вставлено в письмо', 'success');
  } else {
    navigator.clipboard.writeText(txt).then(
      () => aiToast('Поля письма нет — текст скопирован', 'success'),
      () => aiToast('Поля письма нет', 'error')
    );
  }
};

// ===== Переписка (омниканал) =====
document.getElementById('omniSend').onclick = async (e) => {
  const btn = e.currentTarget;
  const body = document.getElementById('omniBody').value.trim();
  const channel = document.getElementById('omniChannel').value;
  const flashEl = document.getElementById('omniFlash');
  if (!body) { flash(flashEl, 'Введите текст', false); return; }
  const old = btn.textContent;
  btn.disabled = true; btn.textContent = 'Отправляю…';
  try {
    const j = await aiPost('../api/msg_send.php', { lead_id: LEAD_ID, channel, body });
    if (j && j.ok) {
      aiToast('Отправлено', 'success');
      location.reload();
    } else {
      flash(flashEl, (j && j.error) || 'Ошибка отправки', false);
    }
  } catch (err) {
    flash(flashEl, String(err && err.message || err) || 'Ошибка сети', false);
  } finally {
    btn.disabled = false; btn.textContent = old;
  }
};

document.getElementById('omniDraft').onclick = async (e) => {
  const btn = e.currentTarget;
  const old = btn.textContent;
  btn.disabled = true; btn.textContent = 'Думаю…';
  try {
    // ai.php?action=draft понимает только email|messenger — telegram/max маппим в messenger
    const rawCh = document.getElementById('omniChannel').value;
    const j = await aiPost(`${AI_API}?action=draft`, {
      id: LEAD_ID,
      channel: (rawCh === 'telegram' || rawCh === 'max') ? 'messenger' : rawCh,
      incoming: LAST_INCOMING || ''
    });
    if (j && j.ok) {
      document.getElementById('omniBody').value = j.text || '';
      aiToast('Черновик готов', 'success');
    } else {
      aiToast((j && j.error) || 'Ошибка', 'error');
    }
  } catch (err) {
    aiToast(String(err && err.message || err) || 'Ошибка сети', 'error');
  } finally {
    btn.disabled = false; btn.textContent = old;
  }
};

load();
</script>
<?php render_foot();
