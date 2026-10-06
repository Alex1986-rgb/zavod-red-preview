<?php
declare(strict_types=1);
/**
 * Синхронизация с Яндексом — офлайн-конверсии (Метрика→Директ) + Вебмастер.
 * Табы: Конверсии · Вебмастер · Настройки. Данные — api/yandex.php.
 */
require __DIR__ . '/_guard.php';
require __DIR__ . '/_layout.php';

$isAdmin = ((current_user()['role'] ?? '') === 'admin');

render_head('Синхронизация с Яндексом');
render_sidebar('yandex');
?>
<div class="page-head">
  <h1 class="page-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1"/><path d="M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1"/></svg> Синхронизация с Яндексом</h1>
  <div class="page-head__actions">
    <span id="yPill" class="ypill ypill--wait"><span class="ydot"></span><span id="yPillTxt">…</span></span>
    <button class="btn btn--ghost" onclick="yRefresh()">↻ Обновить</button>
  </div>
</div>

<div class="stabs" id="yTabs">
  <button class="stab-btn is-active" data-tab="conv">Конверсии</button>
  <button class="stab-btn" data-tab="wm">Вебмастер</button>
  <?php if ($isAdmin): ?><button class="stab-btn" data-tab="settings">Настройки</button><?php endif; ?>
</div>

<!-- КОНВЕРСИИ -->
<section class="ytab shelf" data-tab="conv">
  <div id="yKpi" class="kpi-grid">
    <div class="kpi"><div class="kpi__label">Ожидают отправки: заявки</div><div class="kpi__value">…</div></div>
    <div class="kpi"><div class="kpi__label">Ожидают отправки: заказы</div><div class="kpi__value">…</div></div>
    <div class="kpi"><div class="kpi__label">Последняя синхронизация</div><div class="kpi__value">…</div></div>
    <div class="kpi"><div class="kpi__label">Счётчик Метрики</div><div class="kpi__value">…</div></div>
  </div>
  <div class="card">
    <h3 class="card__title">Реальные заказы → оптимизация Директа</h3>
    <p class="hint">Выигранные заявки (статус «Успешно») с суммой и веб-заявки с рекламы
    (по <code>yclid</code>) отправляются в Яндекс.Метрику как офлайн-конверсии. Директ видит
    реальные продажи и оптимизирует кампании на них, а не только на клики.
    Звонки без веб-клика по рекламе в конверсии не попадают (нужен коллтрекинг).</p>
    <div class="filters y-mt">
      <button id="ySyncBtn" class="btn btn--primary" onclick="ySyncNow()">Синхронизировать сейчас</button>
      <button class="btn btn--ghost" onclick="yRefresh()">Пересчитать ожидающие</button>
    </div>
    <div id="ySyncMsg" class="hint y-mt"></div>
  </div>
</section>

<!-- ВЕБМАСТЕР -->
<section class="ytab shelf is-hidden" data-tab="wm">
  <div class="card">
    <h3 class="card__title">Индексация и поиск (Яндекс.Вебмастер)</h3>
    <div id="yWmSummary" class="hint">Загрузка…</div>
  </div>
  <h2>Топ поисковых запросов</h2>
  <div class="table-wrap">
    <table class="tbl"><thead><tr><th>Запрос</th><th>Показы</th><th>Клики</th></tr></thead>
      <tbody id="yWmQ"><tr><td colspan="3" class="muted">Загрузка…</td></tr></tbody></table>
  </div>
</section>

<?php if ($isAdmin): ?>
<!-- НАСТРОЙКИ -->
<section class="ytab shelf is-hidden" data-tab="settings">
  <div class="card" style="max-width:620px">
    <h3 class="card__title">Параметры Метрики и целей</h3>
    <p class="hint">Счётчик Метрики сайта и ID двух целей. Создайте в Метрике две цели типа
    «JavaScript-событие» (идентификаторы, напр. <code>lead</code> и <code>order</code>) и впишите
    их числовые/строковые ID. Директ будет оптимизироваться на них.</p>
    <div class="form-grid y-mt">
      <label class="field"><span class="field__label">ID счётчика Метрики</span>
        <input id="yCounter" class="input" placeholder="109758131"></label>
      <label class="field"><span class="field__label">Цель «Заявка» (goal_lead)</span>
        <input id="yGoalLead" class="input" placeholder="lead"></label>
      <label class="field"><span class="field__label">Цель «Заказ» (goal_order)</span>
        <input id="yGoalOrder" class="input" placeholder="order"></label>
      <label class="field"><span class="field__label">Отправка включена</span>
        <select id="yEnabled" class="input"><option value="0">выключена</option><option value="1">включена</option></select></label>
    </div>
    <label class="field y-mt"><span class="field__label">OAuth-токен Яндекса (Директ + Метрика + Вебмастер) <span id="yTokState" class="muted"></span></span>
      <input id="yToken" class="input" type="password" placeholder="оставьте пустым, чтобы не менять"></label>
    <div class="y-mt"><button class="btn btn--primary" onclick="ySaveSettings()">Сохранить</button></div>
  </div>

  <div class="card" style="max-width:620px">
    <h3 class="card__title">Автозапуск синхронизации</h3>
    <p class="hint">Чтобы конверсии уходили сами, дёргайте по расписанию (крон / автопилот Директа):</p>
    <label class="field"><span class="field__label">URL для крона (только чтение)</span>
      <input id="yCronUrl" class="input" readonly onclick="this.select()"></label>
    <div class="filters y-mt">
      <input id="yCronSecret" class="input y-grow" placeholder="секрет крона">
      <button class="btn btn--ghost" type="button" onclick="yGenCron()">Сгенерировать</button>
      <button class="btn btn--primary" type="button" onclick="ySaveCron()">Сохранить секрет</button>
    </div>
  </div>
</section>
<?php endif; ?>

<style>
/* Синхро Яндекса: вкладки — общие .stabs/.stab-btn, вкладка — .shelf, заголовки карточек — .card__title,
   ряды кнопок и полей — .filters, приглушённый текст — .muted. Здесь только то, чего в дизайн-системе нет. */
.ytab.is-hidden{display:none}
.y-mt{margin-top:10px}   /* следующий блок внутри карточки */
/* поле, растущее в ряду .filters; min-height — на телефоне (≤560) .filters становится колонкой,
   и flex:1 без него сплющивает поле до 16 px */
.y-grow{flex:1;min-height:var(--ctl-h)}
.ypill{display:inline-flex;align-items:center;gap:7px;padding:5px 12px;border-radius:var(--r-pill,999px);font-size:13px;font-weight:600;border:1px solid var(--line);background:var(--card);box-shadow:var(--shadow-sm,0 1px 3px rgba(16,24,40,.06));transition:border-color .14s ease,box-shadow .14s ease}
.ydot{width:8px;height:8px;border-radius:50%;background:var(--muted)}
.ypill--ok{color:#065f46;background:#ecfdf5;border-color:#a7f3d0}.ypill--ok .ydot{background:var(--green)}
.ypill--off{color:#9a3412;background:#fff7ed;border-color:#fed7aa}.ypill--off .ydot{background:var(--amber)}
.ypill--wait .ydot{animation:ypulse 1s infinite}@keyframes ypulse{50%{opacity:.3}}
</style>

<script>
const Y = { esc:(ZR&&ZR.escapeHtml)||(s=>String(s==null?'':s)), dt:(ZR&&ZR.dateTimeRu)||(s=>s) };
function yTab(t){
  document.querySelectorAll('#yTabs .stab-btn').forEach(b=>b.classList.toggle('is-active',b.dataset.tab===t));
  document.querySelectorAll('.ytab').forEach(s=>s.classList.toggle('is-hidden',s.dataset.tab!==t));
  if(t==='wm') yWebmaster();
}
document.querySelectorAll('#yTabs .stab-btn').forEach(b=>b.onclick=()=>yTab(b.dataset.tab));
function yPill(state,txt){ const p=document.getElementById('yPill'); p.className='ypill ypill--'+state; document.getElementById('yPillTxt').textContent=txt; }

function yRefresh(){
  ZR.apiGet('/api/yandex.php',{action:'status'}).then(d=>{
    if(!d.ok){ yPill('off','ошибка'); return; }
    yPill(d.enabled?'ok':'off', d.enabled?'синхронизация включена':'выключена');
    const cell=(l,v,acc)=>`<div class="kpi ${acc?'kpi--accent':''}"><div class="kpi__label">${Y.esc(l)}</div><div class="kpi__value">${Y.esc(v)}</div></div>`;
    document.getElementById('yKpi').innerHTML =
      cell('Ожидают отправки: заявки', d.pending_leads, d.pending_leads>0) +
      cell('Ожидают отправки: заказы', d.pending_orders, d.pending_orders>0) +
      cell('Последняя синхронизация', d.last_sync_at? Y.dt(new Date(d.last_sync_at*1000)) : '—') +
      cell('Счётчик Метрики', d.counter||'—', !!d.counter);
    let warn=[];
    if(!d.token_set) warn.push('не задан OAuth-токен Яндекса');
    if(!d.goal_lead) warn.push('не задана цель «Заявка»');
    if(!d.goal_order) warn.push('не задана цель «Заказ»');
    if(!d.enabled) warn.push('отправка выключена');
    document.getElementById('ySyncMsg').innerHTML = warn.length
      ? '⚠ Для работы: '+warn.map(Y.esc).join(', ')+' — вкладка «Настройки».' : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><path d="m4 13 5 5L20 7"/></svg> Готово к синхронизации.';
  }).catch(()=>yPill('off','сервис недоступен'));
}

function ySyncNow(){
  const m=document.getElementById('ySyncMsg'); m.textContent='Отправка…';
  const btn=document.getElementById('ySyncBtn'); if(btn)btn.disabled=true;
  ZR.apiPost('/api/yandex.php?action=sync_now',{}).then(d=>{
    if(d.ok){
      const sent=(d.sent_leads||0)+(d.sent_orders||0), errs=(d.errors&&d.errors.length)?d.errors:null;
      if(sent===0 && errs){ ZR.toast('Не отправлено: '+errs[0],'error'); }
      else ZR.toast(`Отправлено: заявок ${d.sent_leads}, заказов ${d.sent_orders}`, errs?'info':'success');
      if(errs) m.innerHTML='⚠ '+errs.map(Y.esc).join('<br>');
      yRefresh(); }
    else ZR.toast('Ошибка: '+(d.error||''),'error');
  }).catch(()=>ZR.toast('Ошибка сети','error'))
  .finally(()=>{ if(btn)btn.disabled=false; });
}

function yWebmaster(){
  document.getElementById('yWmSummary').textContent='Загрузка…';
  ZR.apiGet('/api/yandex.php',{action:'webmaster'}).then(d=>{
    const w=d.webmaster||{};
    if(w.error){ document.getElementById('yWmSummary').innerHTML='⚠ '+Y.esc(w.error); document.getElementById('yWmQ').innerHTML='<tr><td colspan=3 class=muted>—</td></tr>'; return; }
    const s=w.summary||{};
    document.getElementById('yWmSummary').innerHTML =
      `ИКС: <b>${Y.esc(s.sqi ?? '—')}</b> · В поиске страниц: <b>${Y.esc(s.searchable_pages_count ?? '—')}</b> · Исключено: <b>${Y.esc(s.excluded_pages_count ?? '—')}</b>`;
    const qs=w.queries||[];
    document.getElementById('yWmQ').innerHTML = qs.length
      ? qs.map(q=>{
          const ind=q.indicators||{};
          return `<tr><td>${Y.esc(q.query_text||q.text||'')}</td><td>${Y.esc(ind.TOTAL_SHOWS ?? '—')}</td><td>${Y.esc(ind.TOTAL_CLICKS ?? '—')}</td></tr>`;
        }).join('')
      : '<tr><td colspan=3 class=muted>Нет данных.</td></tr>';
  }).catch(()=>{ document.getElementById('yWmSummary').textContent='Ошибка сети'; });
}

<?php if ($isAdmin): ?>
function yLoadSettings(){
  ZR.apiGet('/api/yandex.php',{action:'settings_get'}).then(d=>{
    if(!d.ok) return;
    document.getElementById('yCounter').value=d.metrika_counter_id||'';
    document.getElementById('yGoalLead').value=d.metrika_goal_lead||'';
    document.getElementById('yGoalOrder').value=d.metrika_goal_order||'';
    document.getElementById('yEnabled').value=d.ya_sync_enabled?'1':'0';
    document.getElementById('yTokState').textContent=d.token_set?'— задан ✓':'— не задан';
    document.getElementById('yCronUrl').value = location.origin+'/api/yandex.php?action=sync_now&key='+(d.cron_secret_set?'<ваш-секрет>':'СГЕНЕРИРУЙТЕ_НИЖЕ');
  });
}
function ySaveSettings(){
  const body={ metrika_counter_id:document.getElementById('yCounter').value.trim(),
    metrika_goal_lead:document.getElementById('yGoalLead').value.trim(),
    metrika_goal_order:document.getElementById('yGoalOrder').value.trim(),
    ya_sync_enabled:document.getElementById('yEnabled').value };
  const tok=document.getElementById('yToken').value.trim(); if(tok) body.ya_oauth_token=tok;
  ZR.apiPost('/api/yandex.php?action=settings_save',body).then(d=>{
    if(d.ok){ ZR.toast('Сохранено','success'); document.getElementById('yToken').value=''; yLoadSettings(); yRefresh(); }
    else ZR.toast('Ошибка: '+(d.error||''),'error');
  });
}
function yGenCron(){ ZR.apiGet('/api/yandex.php',{action:'gen_cron_secret'}).then(d=>{ if(d.ok){ document.getElementById('yCronSecret').value=d.secret; ZR.toast('Секрет сгенерирован — сохраните','info'); } else ZR.toast('Ошибка: '+(d.error||'не удалось сгенерировать секрет'),'error'); }).catch(()=>ZR.toast('Ошибка сети','error')); }
function ySaveCron(){
  const s=document.getElementById('yCronSecret').value.trim();
  if(!s){ ZR.toast('Сгенерируйте секрет','error'); return; }
  ZR.apiPost('/api/yandex.php?action=settings_save',{ya_sync_cron_secret:s}).then(d=>{
    if(d.ok){ ZR.toast('Секрет крона сохранён','success'); document.getElementById('yCronUrl').value=location.origin+'/api/yandex.php?action=sync_now&key='+s; }
    else ZR.toast('Ошибка','error');
  });
}
yLoadSettings();
<?php endif; ?>

yRefresh();
</script>
<?php render_foot(); ?>
