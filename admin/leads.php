<?php
declare(strict_types=1);

require __DIR__ . '/_guard.php';
require __DIR__ . '/_layout.php';

// Инженерная воронка (из helpers.php funnel()): code => подпись.
$STATUSES = [];
foreach (funnel() as $code => $meta) { $STATUSES[$code] = $meta[0]; }

// Список менеджеров и источников для фильтров (read-only, через PDO).
$managers = [];
$sources  = [];
try {
    $managers = pdo()->query("SELECT id, name, login FROM crm_users WHERE active = 1 ORDER BY name, login")->fetchAll();
} catch (Throwable $e) { $managers = []; }
try {
    // Источник = канал (source: site/email/telegram/max/voice/manual) ИЛИ рекламная метка (utm_source).
    // Берём объединение обеих колонок — иначе заявки из мессенджеров/почты/звонков в фильтр не попадают.
    $sources = pdo()->query(
        "SELECT DISTINCT s FROM (
            SELECT source     AS s FROM crm_leads WHERE source     <> ''
            UNION
            SELECT utm_source AS s FROM crm_leads WHERE utm_source <> ''
         ) t ORDER BY s"
    )->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) { $sources = []; }

// Человеко-понятные подписи известных каналов; рекламные utm_source остаются как есть.
$SRC_LABELS = [
    'site' => 'Сайт (форма)', 'email' => 'Почта', 'inbox' => 'Почта',
    'telegram' => 'Telegram', 'max' => 'MAX', 'voice' => 'Звонок', 'manual' => 'Вручную',
    // Обращения по клику на контакт (contact_click.php): контактов посетителя нет по
    // определению — человек нажал «позвонить»/«написать». Подписываем явно, чтобы их
    // не путали с заявками из формы и было видно в фильтре «Источник».
    // ТОЛЬКО текст/эмодзи, БЕЗ HTML: подпись источника рендерится в таблице как текст
    // (экранируется) — inline-SVG здесь выводился сырым кодом прямо в колонке «Источник».
    'click_phone'    => '📞 Клик: звонок',
    'click_email'    => '✉️ Клик: e-mail',
    'click_telegram' => '📱 Клик: Telegram',
    'click_max'      => '📱 Клик: MAX',
    'click_whatsapp' => '📱 Клик: WhatsApp',
];

$qs = http_build_query(array_filter([
    'status'     => $_GET['status']     ?? '',
    'manager_id' => $_GET['manager_id'] ?? '',
    'source'     => $_GET['source']     ?? '',
    'from'    => $_GET['from']    ?? '',
    'to'      => $_GET['to']      ?? '',
    'q'       => $_GET['q']       ?? '',
    'tag'     => $_GET['tag']     ?? '',
], fn($v) => $v !== ''));

render_head('Заявки');
render_sidebar('leads');
?>
<style>
  /* Панель фильтров: раскладку, высоту и отступы задаёт общий слой (.leads-toolbar в admin.css),
     здесь только колонка «подпись над полем» и рамка полей в токенах темы. */
  .leads-toolbar .fld{display:flex;flex-direction:column}
  .leads-toolbar input,.leads-toolbar select{border:1px solid var(--line);font:inherit;color:var(--text);background:var(--card);transition:border-color .14s ease,box-shadow .14s ease}
  .leads-toolbar input:focus,.leads-toolbar select:focus{outline:none;border-color:var(--red);box-shadow:0 0 0 3px var(--red-soft)}
  .leads-toolbar input[type=date]{min-width:140px}
  .leads-toolbar .grow{min-width:160px}
  /* Переключатель «Список / Канбан» (JS ставит .on активной кнопке) */
  .seg{display:inline-flex;border:1px solid var(--line);border-radius:var(--r-sm);overflow:hidden}
  .seg button{padding:8px 14px;border:0;background:var(--card);cursor:pointer;font:inherit;color:var(--muted);transition:background .14s ease,color .14s ease}
  .seg button:not(.on):hover{color:var(--red);background:rgba(225,27,27,.06)}
  .seg button.on{background:var(--red);color:#fff}
  /* Таблица заявок: рамку, фон и скругление даёт обёртка .table-wrap */
  table.leads{width:100%;border-collapse:collapse}
  table.leads th,table.leads td{padding:7px 10px;text-align:left;border-bottom:1px solid var(--line);font-size:13px;vertical-align:middle;transition:background .14s ease}
  table.leads th{background:var(--bg);font-size:12px;text-transform:uppercase;letter-spacing:.03em;color:var(--muted)}
  table.leads tbody tr:last-child td{border-bottom:0}
  table.leads tbody tr:hover td{background:var(--bg)}
  table.leads .badge,.kcol .badge{white-space:nowrap}
  .ld-query{font-size:11px;color:var(--muted);margin-top:3px;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  table.leads a.lead-link{color:var(--red);font-weight:600;text-decoration:none;transition:color .14s ease}
  table.leads a.lead-link:hover{text-decoration:underline;color:var(--red-hover)}
  .pager{display:flex;gap:6px;justify-content:center;align-items:center;flex-wrap:wrap}
  .pager a,.pager span{padding:6px 12px;border-radius:var(--r-sm);border:1px solid var(--line);text-decoration:none;color:var(--ink);font-size:14px}
  .pager a{transition:border-color .14s ease,color .14s ease,box-shadow .14s ease}
  .pager a:hover{border-color:var(--red);color:var(--red);box-shadow:var(--shadow-sm)}
  .pager .cur{background:var(--red);color:#fff;border-color:var(--red)}
  /* Канбан */
  .kanban{display:grid;grid-auto-flow:column;grid-auto-columns:minmax(210px,1fr);gap:12px;overflow-x:auto;padding-bottom:12px}
  .kcol{background:var(--bg);border:1px solid var(--line);border-radius:var(--r-md);padding:8px;min-height:80px}
  .kcol h3{margin:0 0 10px;font-size:13px;display:flex;justify-content:space-between;align-items:center}
  .kcount{background:var(--card);border-radius:var(--r-pill);padding:1px 9px;font-size:12px;color:var(--muted);box-shadow:var(--shadow-sm)}
  .kcard{background:var(--card);border-radius:var(--r-sm);padding:8px;margin-bottom:6px;box-shadow:var(--shadow-sm);transition:box-shadow .14s ease,transform .14s ease;cursor:grab}
  .kcard:hover{box-shadow:var(--shadow-md),0 0 0 1px rgba(225,27,27,.14) inset;transform:translateY(-2px)}
  .kcard.drag{opacity:.4}
  .kcard .nm{font-weight:600;font-size:14px}
  .kcard .nm a{color:var(--ink);text-decoration:none;transition:color .14s ease}
  .kcard .nm a:hover{color:var(--red)}
  .kcard .meta{font-size:12px;color:var(--muted);margin-top:4px;display:flex;flex-direction:column;gap:2px}
  .kcol.over{outline:2px dashed var(--red);outline-offset:-4px}
  /* Модалка «Новая заявка» */
  .zr-modal{position:fixed;inset:0;z-index:1000;display:flex;align-items:flex-start;justify-content:center;padding:28px 16px;overflow:auto}
  .zr-modal.hidden{display:none}
  .zr-modal__backdrop{position:fixed;inset:0;background:rgba(15,23,42,.5)}
  .zr-modal__box{position:relative;background:var(--card);border-radius:var(--r-lg);padding:18px 20px;width:100%;max-width:440px;box-shadow:var(--shadow-lg)}
  .zr-modal__box h2{margin:0 0 14px;font-size:18px}
  .zr-modal__box h2::before{content:"";display:inline-block;width:3px;height:15px;background:var(--red);border-radius:2px;margin-right:8px;vertical-align:-2px}
  .nl-form .fld{display:flex;flex-direction:column;gap:4px;margin-bottom:9px}
  .nl-form label{font-size:12px;color:var(--muted)}
  .nl-form input,.nl-form select,.nl-form textarea{padding:6px 9px;border:1px solid var(--line);border-radius:var(--r-sm);font:inherit;color:var(--text);background:var(--card);width:100%;box-sizing:border-box;transition:border-color .14s ease,box-shadow .14s ease}
  .nl-form input:focus,.nl-form select:focus,.nl-form textarea:focus{outline:none;border-color:var(--red);box-shadow:0 0 0 3px var(--red-soft)}
  .nl-form textarea{resize:vertical}
  .nl-row{display:flex;gap:12px;flex-wrap:wrap}
  .nl-row .fld{flex:1 1 180px}
  .nl-err{min-height:18px;font-size:13px;margin-bottom:6px}
  .nl-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:6px}
  @media(max-width:720px){
    table.leads thead{display:none}
    table.leads tr{display:block;border-bottom:1px solid var(--line);padding:8px 0}
    table.leads td{display:flex;justify-content:space-between;border:0;padding:4px 12px}
    table.leads td::before{content:attr(data-l);color:var(--muted);font-size:12px;text-transform:uppercase}
  }
</style>

<div class="page-head">
  <h1 class="page-title">Заявки</h1>
  <div class="page-head__actions">
    <button type="button" class="btn btn-primary" id="newLeadBtn"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><path d="M12 5v14M5 12h14"/></svg> Новая заявка</button>
    <a class="btn btn-ghost" href="../api/export.php?type=csv<?= $qs ? '&' . htmlspecialchars($qs, ENT_QUOTES) : '' ?>">Экспорт CSV</a>
    <a class="btn btn-ghost" href="../api/export.php?type=xlsx<?= $qs ? '&' . htmlspecialchars($qs, ENT_QUOTES) : '' ?>" title="скачается .xls">Экспорт Excel</a>
  </div>
</div>

<div class="help help--info">
  <span class="help__icon">💡</span>
  <div class="help__body">
    <b>Заявки — обращения клиентов</b>: форма на сайте, Telegram, MAX, звонки и заведённые вручную.
    Письма из рабочего ящика сюда не попадают — они в разделе <a href="mailbox.php">Почта</a>;
    чтобы посмотреть их здесь, выберите в фильтре «Источник» значение «почта».
    <ul>
      <li><b>Фильтры.</b> Сузьте список по статусу, менеджеру, источнику, тегу или дате. Поле «Поиск» ищет по имени, телефону и email. «Применить» — показать результат, «Сбросить» — очистить все фильтры.</li>
      <li><b>Список / Канбан.</b> «Список» — таблица, «Канбан» — колонки по статусам; карточку можно перетащить мышью в другую колонку, чтобы сменить статус. Счётчик у заголовка колонки показывает число заявок в этом статусе.</li>
      <li><b>Новая заявка.</b> Кнопка «<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><path d="M12 5v14M5 12h14"/></svg> Новая заявка» заводит лид вручную — например, когда клиент позвонил по телефону.</li>
      <li><b>Экспорт.</b> Кнопки CSV / Excel выгрузят текущую выборку (с учётом фильтров) в файл.</li>
    </ul>
  </div>
</div>

<form method="get" class="leads-toolbar" id="filters">
  <input type="hidden" name="view" id="viewInput" value="<?= ($_GET['view'] ?? '') === 'kanban' ? 'kanban' : 'list' ?>">
  <div class="fld grow">
    <label>Поиск</label>
    <input type="search" name="q" placeholder="Имя, телефон или email" title="Ищет по имени, телефону и email клиента" value="<?= htmlspecialchars((string)($_GET['q'] ?? ''), ENT_QUOTES) ?>">
  </div>
  <div class="fld">
    <label>Статус <span class="tip" data-tip="Этап заявки: Новая — только поступила; В работе — менеджер занимается; КП отправлено — выслали предложение; Успех — сделка закрыта; Отказ — клиент не купил.">?</span></label>
    <select name="status">
      <option value="">Все</option>
      <?php foreach ($STATUSES as $k => $v): ?>
        <option value="<?= $k ?>" <?= ($_GET['status'] ?? '') === $k ? 'selected' : '' ?>><?= htmlspecialchars($v, ENT_QUOTES) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="fld">
    <label>Менеджер</label>
    <select name="manager_id">
      <option value="">Все</option>
      <option value="0" <?= ($_GET['manager_id'] ?? '') === '0' ? 'selected' : '' ?>>Не назначен</option>
      <?php foreach ($managers as $m): $nm = $m['name'] !== '' ? $m['name'] : $m['login']; ?>
        <option value="<?= (int)$m['id'] ?>" <?= (string)($_GET['manager_id'] ?? '') === (string)$m['id'] ? 'selected' : '' ?>><?= htmlspecialchars($nm, ENT_QUOTES) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="fld">
    <label>Источник <span class="tip" data-tip="Откуда пришла заявка: форма сайта, почта, Telegram, MAX, звонок или рекламная метка utm_source.">?</span></label>
    <select name="source">
      <option value="">Все</option>
      <?php foreach ($sources as $s): $lbl = $SRC_LABELS[strtolower((string)$s)] ?? (string)$s; ?>
        <option value="<?= htmlspecialchars((string)$s, ENT_QUOTES) ?>" <?= ($_GET['source'] ?? '') === $s ? 'selected' : '' ?>><?= htmlspecialchars($lbl, ENT_QUOTES) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="fld">
    <label>Тег <span class="tip" data-tip="Показать только заявки с этим тегом. Теги вы ставите сами в карточке лида — например «срочно», «опт», «vip».">?</span></label>
    <input type="search" name="tag" placeholder="срочно, опт…" value="<?= htmlspecialchars((string)($_GET['tag'] ?? ''), ENT_QUOTES) ?>">
  </div>
  <div class="fld">
    <label>С даты</label>
    <input type="date" name="from" value="<?= htmlspecialchars((string)($_GET['from'] ?? ''), ENT_QUOTES) ?>">
  </div>
  <div class="fld">
    <label>По дату</label>
    <input type="date" name="to" value="<?= htmlspecialchars((string)($_GET['to'] ?? ''), ENT_QUOTES) ?>">
  </div>
  <button type="submit" class="btn btn-primary">Применить</button>
  <a href="leads.php" class="btn btn-ghost">Сбросить фильтры</a>
</form>

<div class="row-actions">
  <div class="seg">
    <button type="button" id="tabList" class="on" onclick="switchView('list')">Список</button>
    <button type="button" id="tabKanban" onclick="switchView('kanban')">Канбан</button>
  </div>
</div>

<div id="newLeadModal" class="zr-modal hidden">
  <div class="zr-modal__backdrop" id="newLeadBackdrop"></div>
  <div class="zr-modal__box">
    <h2>Новая заявка</h2>
    <form id="newLeadForm" class="nl-form">
      <div class="fld">
        <label>Имя <span style="color:var(--red)">*</span></label>
        <input type="text" name="name" required maxlength="160">
      </div>
      <div class="nl-row">
        <div class="fld">
          <label>Телефон</label>
          <input type="text" name="phone" maxlength="40">
        </div>
        <div class="fld">
          <label>Email</label>
          <input type="email" name="email" maxlength="160">
        </div>
      </div>
      <div class="fld">
        <label>Тип редуктора</label>
        <select name="reducer_type">
          <option value="">—</option>
          <?php foreach (['Червячный','Цилиндрический','Соосно-цилиндрический','Цилиндро-конический','Планетарный','Вариатор','Мотор-редуктор','Аналог импортного','Другое'] as $rt): ?>
            <option value="<?= htmlspecialchars($rt, ENT_QUOTES) ?>"><?= htmlspecialchars($rt, ENT_QUOTES) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="fld">
        <label>Сообщение</label>
        <textarea name="message" rows="3"></textarea>
      </div>
      <div class="nl-row">
        <?php if ($managers): ?>
        <div class="fld">
          <label>Менеджер</label>
          <select name="manager_id">
            <option value="">Не назначен</option>
            <?php foreach ($managers as $m): $nm = $m['name'] !== '' ? $m['name'] : $m['login']; ?>
              <option value="<?= (int)$m['id'] ?>"><?= htmlspecialchars($nm, ENT_QUOTES) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <div class="fld">
          <label>Сумма, ₽</label>
          <input type="text" name="amount" inputmode="decimal">
        </div>
      </div>
      <div class="nl-err muted" id="newLeadErr" style="color:#cf2020"></div>
      <div class="nl-actions">
        <button type="button" class="btn btn-ghost" id="newLeadCancel">Отмена</button>
        <button type="submit" class="btn btn-primary" id="newLeadSubmit">Создать</button>
      </div>
    </form>
  </div>
</div>

<div id="bulkBar" class="bulk-bar" hidden>
  <span class="bulk-bar__count"><b id="bulkCount">0</b> выбрано</span>
  <select id="bulkStatus" class="input input--sm">
    <option value="">Сменить статус…</option>
    <?php foreach ($STATUSES as $k => $v): ?><option value="<?= $k ?>"><?= htmlspecialchars($v, ENT_QUOTES) ?></option><?php endforeach; ?>
  </select>
  <select id="bulkManager" class="input input--sm">
    <option value="">Назначить менеджера…</option>
    <option value="0">— снять назначение —</option>
    <?php foreach ($managers as $m): $nm = $m['name'] !== '' ? $m['name'] : $m['login']; ?><option value="<?= (int)$m['id'] ?>"><?= htmlspecialchars($nm, ENT_QUOTES) ?></option><?php endforeach; ?>
  </select>
  <button class="btn btn-primary btn-sm" id="bulkApply">Применить</button>
  <button class="btn btn-ghost btn-sm" id="bulkEngineer"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><path d="M14.7 6.3a4 4 0 0 1-5.4 5.4L4 17v3h3l5.3-5.3a4 4 0 0 1 5.4-5.4l-2.6 2.6-1.4-1.4 2.6-2.6Z"/></svg>На проверку инженеру</button>
  <button class="btn btn-ghost btn-sm" id="bulkClear">Снять выбор</button>
  <button class="btn btn--danger btn-sm bulk-del" id="bulkDelete" title="Удалить выбранные заявки безвозвратно"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><path d="M4 7h16"/><path d="M10 11v6M14 11v6"/><path d="M6 7l1 13h10l1-13"/><path d="M9 7V4h6v3"/></svg>Удалить</button>
</div>
<div id="leadDiag" class="lead-diag" hidden></div>
<div id="listView"><div class="empty">Загрузка…</div></div>
<div id="kanbanView" class="hidden"><div class="empty">Загрузка…</div></div>
<div id="pager" class="pager"></div>
<style>
  .bulk-bar{display:none;align-items:center;gap:10px;flex-wrap:wrap;background:var(--card);border:1px solid var(--red);
    border-radius:var(--r-lg);box-shadow:0 3px 14px rgba(225,27,27,.12);padding:11px 14px}
  .bulk-bar[hidden]{display:none}
  .bulk-bar.show{display:flex}
  .bulk-bar__count{font-weight:700;color:var(--ink);margin-right:4px}
  /* Удаление необратимо — отодвигаем от остальных кнопок, чтобы не нажать по соседству
     с «Применить». Цвет опасного действия — .btn--danger и .bulk-del из admin.css. */
  .bulk-del{margin-left:auto}
  .bulk-bar__count b{color:var(--red)}
  .cbcol{width:34px;text-align:center}
  .leads td.cbcol input,.leads th.cbcol input{width:16px;height:16px;accent-color:var(--red);cursor:pointer;margin:0}
  .leads tr.row-sel{background:rgba(225,27,27,.05)}
  /* Диагностическая сводка */
  .lead-diag{display:flex;flex-wrap:wrap;gap:10px}
  .ld-chip{background:var(--card);border:1px solid var(--line);border-radius:var(--r-md);padding:10px 14px;box-shadow:var(--shadow-sm);min-width:120px;transition:box-shadow .14s ease,transform .14s ease}
  .ld-chip:hover{box-shadow:var(--shadow-md);transform:translateY(-1px)}
  .ld-chip b{display:block;font-size:20px;font-weight:800;color:var(--ink);letter-spacing:-.4px}
  .ld-chip span{font-size:12px;color:var(--muted)}
  .ld-chip--accent{border-color:#a7f3d0;background:#ecfdf5}
  .ld-chip--warn{border-color:#fecaca;background:#fef2f2}
  .ld-chip--warn b{color:#b91c1c}
  .ld-alert{flex:1 1 100%;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:var(--r-md,10px);padding:12px 14px;font-size:14px}
  .ld-src{display:inline-flex;gap:6px;align-items:center;font-size:12px;color:var(--muted);margin-right:12px}
  .ld-src b{color:var(--ink);font-size:12px;font-weight:700}
  table.leads thead th{position:sticky;top:0;z-index:1}
  .src-badge{display:inline-block;padding:2px 8px;border-radius:var(--r-pill);background:var(--bg);color:var(--muted);font-size:12px;font-weight:600}
  .src-badge--site{background:#e0f2fe;color:#0369a1}
  .src-badge--ad{background:#fff7ed;color:#c2410c}
</style>

<script>
const STATUSES = <?= json_encode($STATUSES, JSON_UNESCAPED_UNICODE) ?>;
const SRC_LABELS = <?= json_encode($SRC_LABELS ?? [], JSON_UNESCAPED_UNICODE) ?>;
const API = '../api/leads.php';
const params = new URLSearchParams(location.search);
let page = parseInt(params.get('page') || '1', 10) || 1;
let curView = params.get('view') === 'kanban' ? 'kanban' : 'list';

function esc(s){const d=document.createElement('div');d.textContent=(s==null?'':String(s));return d.innerHTML;}
function badge(st){return `<span class="badge badge--${st}">${esc(STATUSES[st]||st)}</span>`;}
function fmtDate(s){if(!s)return '';const d=new Date(s.replace(' ','T'));return isNaN(d)?esc(s):d.toLocaleDateString('ru-RU')+' '+d.toLocaleTimeString('ru-RU',{hour:'2-digit',minute:'2-digit'});}
function fmtMoney(a){a=parseFloat(a)||0;return a?a.toLocaleString('ru-RU')+' ₽':'—';}
function timeAgo(s){
  if(!s) return '';
  const d=new Date(s.replace(' ','T')); if(isNaN(d)) return esc(s);
  const sec=Math.floor((Date.now()-d.getTime())/1000);
  if(sec<60) return 'только что';
  if(sec<3600) return Math.floor(sec/60)+' мин назад';
  if(sec<86400) return Math.floor(sec/3600)+' ч назад';
  const days=Math.floor(sec/86400);
  return days+' '+(days===1?'день':days<5?'дня':'дней')+' назад';
}
function srcBadge(raw){
  const s=String(raw||'').toLowerCase();
  const lbl=SRC_LABELS[s]||raw||'—';
  let cls='src-badge';
  if(['site','form',''].includes(s)) cls+=' src-badge--site';
  else if(['yandex','google','direct','cpc','ad'].includes(s)) cls+=' src-badge--ad';
  return `<span class="${cls}">${esc(lbl)}</span>`;
}

/* Проверка каналов уведомлений по требованию — шлёт тест на email/Telegram. */
async function testNotify(btn){
  const res=document.getElementById('testNotifyRes');
  if(btn){btn.disabled=true;} if(res) res.textContent='Отправляю тест…';
  try{
    const j=await window.ZR.apiPost(API+'?action=test_notify',{});
    if(btn) btn.disabled=false;
    if(!j||!j.ok){ if(res) res.textContent='Ошибка: '+esc((j&&j.error)||'нет ответа'); return; }
    const r=j.result||{};
    const em=r.email||{}, tg=r.telegram||{}, mx=r.max||{};
    const mark=(o)=>o.ok?'✓':'✗';
    let html=`✉ Email: <b style="color:${em.ok?'#059669':'#b91c1c'}">${mark(em)}</b>${em.via?' ('+esc(em.via)+' → '+esc(em.to||'')+')':''} · `;
    html+=`📱 Telegram: <b style="color:${tg.ok?'#059669':'#b91c1c'}">${mark(tg)}</b>${!tg.ok&&tg.reason?' ('+esc(tg.reason)+')':''} · `;
    html+=`💬 MAX: ${mx.configured?('<b style="color:'+(mx.ok?'#059669':'#b91c1c')+'">'+(mx.ok?'✓':'✗')+'</b>'):'<span style="color:var(--muted)">не настроен</span>'}`;
    if(res) res.innerHTML=html;
    if(window.ZR&&ZR.toast) ZR.toast(`Тест: email ${mark(em)}, Telegram ${mark(tg)}`, (em.ok&&tg.ok)?'success':'info');
  }catch(_){ if(btn) btn.disabled=false; if(res) res.textContent='Ошибка сети'; }
}

/* Диагностика: реальная картина БД — сразу видно, есть ли заявки с сайта. */
async function loadDiag(){
  const box=document.getElementById('leadDiag');
  try{
    const d=await api('diag',{});
    if(!d||!d.ok){ box.hidden=true; return; }
    let html='';
    html+=`<div class="ld-chip"><b>${d.total}</b><span>всего заявок</span></div>`;
    html+=`<div class="ld-chip ${d.from_site>0?'ld-chip--accent':''}"><b>${d.from_site}</b><span>с сайта / формы</span></div>`;
    html+=`<div class="ld-chip"><b>${d.last24h}</b><span>за 24 часа</span></div>`;
    // Повторные обращения объясняют, почему писем менеджеру больше, чем новых карточек.
    if(d.repeat24h>0){
      html+=`<div class="ld-chip ld-chip--accent" title="Клиент уже был в базе: заявка обновила его карточку и подняла её наверх списка, дубль не создавался."><b>${d.repeat24h}</b><span>из них повторных (без новой карточки)</span></div>`;
    }
    if(d.last_lead){
      html+=`<div class="ld-chip"><b style="font-size:14px;font-weight:700">${timeAgo(d.last_lead.created_at)}</b><span>последняя: ${esc(d.last_lead.name||'—')} · ${srcBadge(d.last_lead.src)}</span></div>`;
    }
    if(!d.schema_ok){
      html+=`<div class="ld-alert">⚠ В таблице заявок нет колонок: <b>${(d.missing_columns||[]).map(esc).join(', ')}</b>. Из-за этого приём с сайта может падать (заявка не сохраняется). Нужно применить миграции БД на боевом.</div>`;
    } else if(d.total===0){
      html+=`<div class="ld-alert" style="background:#fffbeb;border-color:#fde68a;color:#92400e">В базе нет ни одной заявки. Если с сайта «уходят», но тут пусто — проверьте, что форма шлёт на <code>/api/feedback.php</code> и приём отвечает без ошибок.</div>`;
    }
    if(d.by_source && d.by_source.length){
      html+='<div class="ld-chip" style="flex:1 1 100%;min-width:0"><span style="margin-bottom:4px;display:block">По источникам:</span>'
        + d.by_source.map(r=>`<span class="ld-src">${srcBadge(r.src)}<b>${r.c}</b></span>`).join('')+'</div>';
    }
    // Каналы уведомлений: куда уходит каждая новая заявка + доставка за 30 дней.
    if(d.channels){
      const ch=d.channels, dl=d.delivery||{email:{},telegram:{},max:{}};
      const dot=on=>`<span style="color:${on?'#059669':'#b91c1c'};font-weight:700">${on?'✓':'✗'}</span>`;
      const stat=s=>s&&(s.ok||s.fail)?` <span class="ld-src" style="margin:0">(${s.ok||0}✓${s.fail?' / '+s.fail+'✗':''} за 30д)</span>`:'';
      html+='<div class="ld-chip" style="flex:1 1 100%;min-width:0">'
        +'<span style="margin-bottom:6px;display:block">Куда уходит каждая заявка (уведомления):</span>'
        +`<span class="ld-src">✉ Email → <b>${esc(ch.email&&ch.email.to||'—')}</b> ${dot(ch.email&&ch.email.on)}${stat(dl.email)}</span>`
        +`<span class="ld-src">📱 Telegram ${dot(ch.telegram&&ch.telegram.on)}${stat(dl.telegram)}</span>`
        +`<span class="ld-src">💬 MAX ${dot(ch.max&&ch.max.on)}${stat(dl.max)}</span>`
        +'<div style="margin-top:10px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">'
        +'<button class="btn btn-ghost" type="button" onclick="testNotify(this)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg> Отправить тестовое уведомление</button>'
        +'<span id="testNotifyRes" class="ld-src"></span></div>'
        + (!(ch.telegram&&ch.telegram.on)?'<div class="ld-alert" style="margin-top:8px;background:#fffbeb;border-color:#fde68a;color:#92400e">Telegram-уведомления выключены — новые заявки не придут в мессенджер. Включите: Настройки → токен бота и ID чата.</div>':'')
        +'</div>';
    }
    box.innerHTML=html; box.hidden=false;
  }catch(_){ box.hidden=true; }
}

function filterParams(extra){
  const p = new URLSearchParams();
  ['status','manager_id','source','from','to','q','tag'].forEach(k=>{const v=params.get(k);if(v)p.set(k,v);});
  for(const k in (extra||{})) p.set(k, extra[k]);
  return p;
}

/* Повторное обращение известного клиента не создаёт новую карточку — без этой пометки
   в списке не видно, что клиент написал ещё раз (раньше это читалось как «заявка пропала»). */
function repeatBadge(r){
  const n = parseInt(r.inquiries_count||1, 10);
  if(!(n>1)) return '';
  return `<div class="badge" style="background:#fff7ed;color:#c2410c;margin-top:4px" title="Клиент обращался ${n} раз(а). Новая заявка обновила эту карточку, дубль не создавался.">⟳ ${n}-е обращение</div>`;
}

function leadTags(r){return String(r.tags||'').split(',').map(s=>s.trim()).filter(Boolean);}
function tagBadges(r){
  const list=leadTags(r);
  if(!list.length) return '';
  return list.map(t=>`<span class="badge" style="background:#f0eaff;color:#6b3fd1;margin:1px 2px 0 0">${esc(t)}</span>`).join('');
}

async function api(action, extra){
  const p = filterParams(Object.assign({action}, extra||{}));
  const r = await fetch(API+'?'+p.toString(), {headers:{'Accept':'application/json'}});
  return r.json();
}

async function loadList(){
  const data = await api('list', {page, per:25});
  const box = document.getElementById('listView');
  if(!data || !data.ok){ box.innerHTML='<div class="empty">Ошибка загрузки: '+esc((data&&data.error)||'нет ответа')+'</div>'; return; }
  const rows = data.items || data.leads || [];
  if(!rows.length){
    const hasTag = (params.get('tag')||'').trim() !== '';
    const hasFilter = ['status','manager_id','source','from','to','q','tag'].some(k=>params.get(k));
    const msg = hasTag ? 'Заявок с таким тегом нет. Проверьте написание тега.'
      : hasFilter ? 'Ничего не найдено под текущие фильтры. Расширьте период или <a href="leads.php" style="color:var(--red)">сбросьте фильтры</a>.'
      : 'Заявок ещё нет. Первая появится здесь сразу, как придёт с сайта или из мессенджера. Сводка выше покажет, доходят ли они до базы.';
    box.innerHTML='<div class="empty"><span>'+msg+'</span></div>';
    document.getElementById('pager').innerHTML=''; return;
  }
  let html = '<div class="table-wrap"><table class="leads"><thead><tr>'
    +'<th class="cbcol"><input type="checkbox" id="cbAll" title="Выбрать все"></th>'
    +'<th>Дата</th><th>Имя</th><th>Телефон</th><th>Тип</th><th>Теги</th><th>Источник</th><th>Статус</th><th>Менеджер</th><th>Сумма</th></tr></thead><tbody>';
  for(const r of rows){
    html += `<tr data-id="${r.id}"${SELECTED.has(String(r.id))?' class="row-sel"':''}>`
      +`<td class="cbcol"><input type="checkbox" class="cbrow" value="${r.id}"${SELECTED.has(String(r.id))?' checked':''}></td>`
      +`<td data-l="Дата">${fmtDate(r.inquiry_at||r.created_at)}${repeatBadge(r)}</td>`
      +`<td data-l="Имя"><a class="lead-link" href="lead.php?id=${r.id}">${esc(r.name)||'—'}</a></td>`
      +`<td data-l="Телефон">${esc(r.phone)||'—'}</td>`
      +`<td data-l="Тип">${esc(r.reducer_type)||'—'}</td>`
      +`<td data-l="Теги">${tagBadges(r)||'<span class="muted">—</span>'}</td>`
      +`<td data-l="Источник">${srcBadge(r.source||r.utm_source)}${(r.utm_term||'').trim()?`<div class="ld-query" title="Рекламный запрос">🔍 ${esc(r.utm_term)}</div>`:''}</td>`
      +`<td data-l="Статус">${badge(r.status)}</td>`
      +`<td data-l="Менеджер">${esc(r.manager_name)||'<span class="muted">—</span>'}</td>`
      +`<td data-l="Сумма">${fmtMoney(r.amount)}</td>`
      +'</tr>';
  }
  html += '</tbody></table></div>';
  box.innerHTML = html;
  bindBulk();
  renderPager(data.total||rows.length, data.per||25, data.page||page);
}

/* ---------- Массовые действия ---------- */
const SELECTED = new Set();
function updateBulkBar(){
  const bar=document.getElementById('bulkBar');
  document.getElementById('bulkCount').textContent=SELECTED.size;
  bar.classList.toggle('show', SELECTED.size>0);
  bar.hidden = SELECTED.size===0;
  const all=document.getElementById('cbAll');
  if(all){ const boxes=[...document.querySelectorAll('.cbrow')]; all.checked = boxes.length>0 && boxes.every(b=>b.checked); }
}
function bindBulk(){
  document.querySelectorAll('.cbrow').forEach(cb=>{
    cb.addEventListener('change',()=>{
      const id=cb.value, tr=cb.closest('tr');
      if(cb.checked){ SELECTED.add(id); tr&&tr.classList.add('row-sel'); }
      else { SELECTED.delete(id); tr&&tr.classList.remove('row-sel'); }
      updateBulkBar();
    });
  });
  const all=document.getElementById('cbAll');
  if(all) all.addEventListener('change',()=>{
    document.querySelectorAll('.cbrow').forEach(cb=>{ cb.checked=all.checked; cb.dispatchEvent(new Event('change')); });
  });
  updateBulkBar();
}
async function bulkPost(fields){
  const ids=[...SELECTED].join(',');
  if(!ids){ ZR.toast('Ничего не выбрано','error'); return null; }
  return ZR.apiPost(API+'?action=bulk', Object.assign({ids}, fields));
}
document.getElementById('bulkApply').addEventListener('click', async ()=>{
  const status=document.getElementById('bulkStatus').value;
  const mgr=document.getElementById('bulkManager').value;
  if(status==='' && mgr===''){ ZR.toast('Выберите статус или менеджера','error'); return; }
  const f={}; if(status!=='') f.status=status; if(mgr!=='') f.manager_id=mgr;
  const j=await bulkPost(f);
  if(j&&j.ok){ ZR.toast('Обновлено заявок: '+j.updated,'success'); SELECTED.clear(); document.getElementById('bulkStatus').value=''; document.getElementById('bulkManager').value=''; loadList(); }
  else if(j) ZR.toast((j&&j.error)||'Ошибка','error');
});
document.getElementById('bulkDelete').addEventListener('click', async ()=>{
  const ids=[...SELECTED];
  if(!ids.length){ ZR.toast('Ничего не выбрано','error'); return; }
  // Удаление необратимо, поэтому подтверждаем явно и показываем количество.
  if(!confirm('Удалить заявок: '+ids.length+'?\n\nДействие необратимо. Снимок каждой заявки останется в журнале событий.')) return;
  const j=await ZR.apiPost(API+'?action=delete', {ids:ids.join(',')});
  if(j&&j.ok){ ZR.toast('Удалено заявок: '+j.deleted,'success'); SELECTED.clear(); loadList(); }
  else if(j) ZR.toast((j&&j.error)||'Не удалось удалить','error');
});
document.getElementById('bulkEngineer').addEventListener('click', async ()=>{
  const j=await bulkPost({status:'review'});
  if(j&&j.ok){ ZR.toast(j.updated+' заявок передано инженеру','success'); SELECTED.clear(); loadList(); }
  else if(j) ZR.toast((j&&j.error)||'Ошибка','error');
});
document.getElementById('bulkClear').addEventListener('click', ()=>{ SELECTED.clear(); document.querySelectorAll('.cbrow').forEach(cb=>cb.checked=false); document.querySelectorAll('tr.row-sel').forEach(tr=>tr.classList.remove('row-sel')); updateBulkBar(); });

function renderPager(total, per, cur){
  const pages = Math.max(1, Math.ceil(total/per));
  const el = document.getElementById('pager');
  if(pages<=1){el.innerHTML='';return;}
  let h='';
  const go=(p)=>{const u=filterParams({view:curView,page:p});return 'leads.php?'+u.toString();};
  if(cur>1) h+=`<a href="${go(cur-1)}">‹</a>`;
  for(let p=1;p<=pages;p++){
    if(p===1||p===pages||Math.abs(p-cur)<=2) h+= p===cur?`<span class="cur">${p}</span>`:`<a href="${go(p)}">${p}</a>`;
    else if(Math.abs(p-cur)===3) h+='<span>…</span>';
  }
  if(cur<pages) h+=`<a href="${go(cur+1)}">›</a>`;
  el.innerHTML=h;
}

async function loadKanban(){
  document.getElementById('pager').innerHTML='';
  // Было per:200 — всё, что старше 200-й заявки, в канбан просто не попадало,
  // и счётчики в колонках врали (считались по урезанной выборке).
  const PER = 500;
  const data = await api('list', {per:PER, page:1});
  const box = document.getElementById('kanbanView');
  if(!data||!data.ok){box.innerHTML='<div class="empty">Ошибка загрузки</div>';return;}
  const rows = data.items || data.leads || [];
  const cols = {};
  Object.keys(STATUSES).forEach(s=>cols[s]=[]);
  // Заявки со статусом вне воронки раньше отбрасывались условием if(cols[r.status])
  // и исчезали из интерфейса совсем. Собираем их в отдельную колонку.
  const other = [];
  rows.forEach(r=>{ (cols[r.status] ? cols[r.status] : other).push(r); });
  let html='';
  // Честно предупреждаем, если показаны не все заявки — молчаливое усечение
  // выглядело как «заявки пропали».
  const total = Number(data.total||0);
  if(total > rows.length){
    html += '<div class="muted" style="padding:8px 2px 12px">Показаны последние '
          + rows.length + ' из ' + total + ' заявок. Остальные — в режиме списка с фильтрами.</div>';
  }
  html+='<div class="kanban">';
  for(const st in STATUSES){
    html+=`<div class="kcol" data-st="${st}" ondragover="kDragOver(event)" ondragleave="kDragLeave(event)" ondrop="kDrop(event)">`
      +`<h3>${badge(st)}<span class="kcount">${cols[st].length}</span></h3>`;
    for(const r of cols[st]){
      html+=`<div class="kcard" draggable="true" data-id="${r.id}" ondragstart="kDragStart(event)" ondragend="kDragEnd(event)">`
        +`<div class="nm"><a href="lead.php?id=${r.id}">${esc(r.name)||'Без имени'}</a></div>`
        +`<div class="meta"><span>${esc(r.phone)||''}</span><span>${esc(r.reducer_type)||''}</span><span>${fmtMoney(r.amount)}</span></div>`
        +repeatBadge(r)
        +(tagBadges(r)?`<div style="margin-top:4px">${tagBadges(r)}</div>`:'')
        +`</div>`;
    }
    html+='</div>';
  }
  // Колонка «Прочие» — статусы, которых нет в воронке. Без неё такие заявки
  // не отображались нигде и выглядели потерянными.
  if(other.length){
    html+='<div class="kcol" data-st=""><h3>Прочие<span class="kcount">'+other.length+'</span></h3>';
    for(const r of other){
      html+=`<div class="kcard" data-id="${r.id}">`
        +`<div class="nm"><a href="lead.php?id=${r.id}">${esc(r.name)||'Без имени'}</a></div>`
        +`<div class="meta"><span>${esc(r.phone)||''}</span><span>${esc(r.status)||'без статуса'}</span></div>`
        +`</div>`;
    }
    html+='</div>';
  }
  html+='</div>';
  box.innerHTML=html;
}

let dragId=null;
function kDragStart(e){dragId=e.target.dataset.id;e.target.classList.add('drag');e.dataTransfer.effectAllowed='move';}
function kDragEnd(e){e.target.classList.remove('drag');}
function kDragOver(e){e.preventDefault();e.currentTarget.classList.add('over');}
function kDragLeave(e){e.currentTarget.classList.remove('over');}
async function kDrop(e){
  e.preventDefault();
  const col=e.currentTarget; col.classList.remove('over');
  const st=col.dataset.st;
  if(!dragId) return;
  const id=dragId; dragId=null;
  const body=new URLSearchParams({action:'update', id, status:st, csrf:window.CSRF||''});
  const r=await fetch(API,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF':window.CSRF||''},body});
  const j=await r.json();
  if(j&&j.ok) loadKanban(); else ZR.toast((j&&j.error)||'Не удалось сменить статус — заявка осталась на месте. Повторите или обновите страницу.','error');
}

function switchView(v){
  curView=v;
  document.getElementById('tabList').classList.toggle('on', v==='list');
  document.getElementById('tabKanban').classList.toggle('on', v==='kanban');
  document.getElementById('listView').classList.toggle('hidden', v!=='list');
  document.getElementById('kanbanView').classList.toggle('hidden', v!=='kanban');
  document.getElementById('viewInput').value=v;
  const u=new URL(location); u.searchParams.set('view',v); history.replaceState(null,'',u);
  if(v==='kanban') loadKanban(); else loadList();
}

switchView(curView);
loadDiag();

/* ---------- Новая заявка ---------- */
(function(){
  const modal  = document.getElementById('newLeadModal');
  const form   = document.getElementById('newLeadForm');
  const errBox = document.getElementById('newLeadErr');
  const submit = document.getElementById('newLeadSubmit');
  if(!modal||!form) return;

  function open(){ errBox.textContent=''; form.reset(); modal.classList.remove('hidden'); const f=form.querySelector('[name=name]'); if(f) f.focus(); }
  function close(){ modal.classList.add('hidden'); }

  document.getElementById('newLeadBtn').addEventListener('click', open);
  if(new URLSearchParams(location.search).get('new')==='1') open(); // авто-открытие с дашборда
  document.getElementById('newLeadCancel').addEventListener('click', close);
  document.getElementById('newLeadBackdrop').addEventListener('click', close);
  document.addEventListener('keydown', e=>{ if(e.key==='Escape' && !modal.classList.contains('hidden')) close(); });

  form.addEventListener('submit', async e=>{
    e.preventDefault();
    errBox.textContent='';
    const fd = new FormData(form);
    const name  = (fd.get('name')||'').toString().trim();
    const phone = (fd.get('phone')||'').toString().trim();
    const email = (fd.get('email')||'').toString().trim();
    if(!name){ errBox.textContent='Укажите имя'; return; }
    if(!phone && !email){ errBox.textContent='Укажите телефон или email'; return; }

    const data = {
      name, phone, email,
      reducer_type: (fd.get('reducer_type')||'').toString(),
      message:      (fd.get('message')||'').toString(),
      manager_id:   (fd.get('manager_id')||'').toString(),
      amount:       (fd.get('amount')||'').toString()
    };
    submit.disabled = true;
    try {
      const j = await window.ZR.apiPost(API + '?action=create_manual', data);
      if(j && j.ok){ close(); location.reload(); }
      else { errBox.textContent = (j && j.error) || 'Не удалось создать заявку'; }
    } catch(_){
      errBox.textContent = 'Ошибка сети';
    } finally {
      submit.disabled = false;
    }
  });
})();
</script>
<?php render_foot();
