<?php
declare(strict_types=1);
/**
 * Голосовой менеджер — панель в админке.
 * Табы: Статус · Звонки · Обзвон · Настройки. Данные — api/voice.php.
 * Стиль — родные классы админки (kpi/card/tbl/field) + светлая тема.
 */
require __DIR__ . '/_guard.php';
require __DIR__ . '/_layout.php';

$isAdmin = ((current_user()['role'] ?? '') === 'admin');

render_head('Голосовой менеджер');
render_sidebar('voice');
?>
<div class="page-head">
  <h1 class="page-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.2a2 2 0 0 1 2.1-.5c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2Z"/></svg> Голосовой менеджер</h1>
  <div class="page-head__actions">
    <span id="vPill" class="vpill vpill--wait"><span class="vdot"></span><span id="vPillTxt">проверка связи…</span></span>
    <button class="btn btn--ghost" onclick="vRefresh()">↻ Обновить</button>
  </div>
</div>

<div class="stabs" id="vTabs">
  <button class="stab-btn is-active" data-tab="status">Статус</button>
  <button class="stab-btn" data-tab="calls">Звонки</button>
  <button class="stab-btn" data-tab="outbound">Обзвон</button>
  <?php if ($isAdmin): ?><button class="stab-btn" data-tab="settings">Настройки</button><?php endif; ?>
</div>

<!-- СТАТУС -->
<section class="vtab shelf" data-tab="status">
  <div id="vKpi" class="kpi-grid">
    <div class="kpi"><div class="kpi__label">Сервис</div><div class="kpi__value">…</div></div>
    <div class="kpi"><div class="kpi__label">Мозг (LLM)</div><div class="kpi__value">…</div></div>
    <div class="kpi"><div class="kpi__label">Голос</div><div class="kpi__value">…</div></div>
    <div class="kpi"><div class="kpi__label">Телефония</div><div class="kpi__value">…</div></div>
  </div>
  <div class="card">
    <h3 class="card__title">Что делает менеджер</h3>
    <p class="hint">«Мария» принимает и совершает звонки голосом: консультирует по редукторам,
    подбирает аналоги по базе знаний, собирает заявку и кладёт её сюда, в CRM
    (канал «Голос», с расшифровкой). Телефония — Voximplant, голос — Yandex&nbsp;SpeechKit.</p>
    <h3 class="card__title">Готовность к запуску</h3>
    <ul id="vChecklist" class="vcheck"><li class="muted">Загрузка…</li></ul>
  </div>
</section>

<!-- ЗВОНКИ -->
<section class="vtab shelf is-hidden" data-tab="calls">
  <div id="vActive"></div>
  <div class="table-wrap">
    <table class="tbl"><thead><tr>
      <th>Когда</th><th>Напр.</th><th>Контакт</th><th>Тема</th><th></th>
    </tr></thead><tbody id="vCallsBody">
      <tr><td colspan="5" class="muted">Загрузка…</td></tr>
    </tbody></table>
  </div>
</section>

<!-- ОБЗВОН -->
<section class="vtab shelf is-hidden" data-tab="outbound">
  <div class="card">
    <h3 class="card__title">Поставить исходящий звонок</h3>
    <p class="hint">Менеджер перезвонит по номеру в рабочие часы. Удобно для перезвона по заявке.</p>
    <div class="filters">
      <label class="field"><span class="field__label">Телефон</span>
        <input id="vPhone" class="input" placeholder="+7…"></label>
      <label class="field"><span class="field__label">Имя (необязательно)</span>
        <input id="vName" class="input" placeholder="Иван"></label>
      <label class="field" style="flex:2"><span class="field__label">Причина / по заявке №</span>
        <input id="vReason" class="input" placeholder="перезвон по заявке #123"></label>
      <button id="vEnqBtn" class="btn btn--primary" onclick="vEnqueue()">Позвонить</button>
    </div>
  </div>
  <h2>Очередь обзвона</h2>
  <div class="table-wrap">
    <table class="tbl"><thead><tr>
      <th>Телефон</th><th>Имя</th><th>Статус</th><th>Причина</th>
    </tr></thead><tbody id="vQueueBody">
      <tr><td colspan="4" class="muted">Загрузка…</td></tr>
    </tbody></table>
  </div>
</section>

<?php if ($isAdmin): ?>
<!-- НАСТРОЙКИ -->
<section class="vtab shelf is-hidden" data-tab="settings">
  <div class="card" style="max-width:560px">
    <h3 class="card__title">Подключение сервиса</h3>
    <p class="hint">URL Python-сервиса голосового менеджера и секрет, которым подписываются
    запросы (совпадает с <code>TURN_SECRET</code> в его <code>.env</code>).</p>
    <label class="field v-mt"><span class="field__label">URL сервиса</span>
      <input id="vUrl" class="input" placeholder="http://127.0.0.1:5056"></label>
    <label class="field v-mt"><span class="field__label">Секрет (TURN_SECRET)</span>
      <input id="vSecret" class="input" type="password" placeholder="оставьте пустым, чтобы не менять"></label>
    <div class="v-mt"><button class="btn btn--primary" onclick="vSaveSettings()">Сохранить</button></div>
  </div>

  <div class="card" style="max-width:560px">
    <h3 class="card__title">Приём заявок в CRM (webhook)</h3>
    <p class="hint">Сервис по завершении звонка присылает заявку и расшифровку на этот адрес.
    Скопируйте URL и секрет в <code>.env</code> сервиса (<code>CRM_WEBHOOK_URL</code>,
    <code>CRM_WEBHOOK_SECRET</code>). Без заданного секрета приём заявок закрыт.</p>
    <label class="field v-mt"><span class="field__label">URL приёмника (только чтение)</span>
      <input id="vHookUrl" class="input" readonly onclick="this.select()"></label>
    <label class="field v-mt"><span class="field__label">Секрет приёма заявок <span id="vHookState" class="muted"></span></span>
      <div class="filters">
        <input id="vHookSecret" class="input v-grow" placeholder="вставьте или сгенерируйте">
        <button class="btn btn--ghost" type="button" onclick="vGenSecret()">Сгенерировать</button>
      </div></label>
    <div class="v-mt"><button class="btn btn--primary" onclick="vSaveHook()">Сохранить секрет</button></div>
  </div>
</section>
<?php endif; ?>

<style>
/* Голос: вкладки — общие .stabs/.stab-btn, вкладка — .shelf, заголовки карточек — .card__title,
   ряды полей — .filters, приглушённый текст — .muted. Здесь только то, чего в дизайн-системе нет. */
.vtab.is-hidden{display:none}
.v-mt{margin-top:10px}   /* следующий блок внутри карточки */
/* поле, растущее в ряду .filters; min-height — на телефоне (≤560) .filters становится колонкой,
   и flex:1 без него сплющивает поле до 16 px */
.v-grow{flex:1;min-height:var(--ctl-h)}
/* «Идут сейчас» над таблицей звонков: пустой блок не занимает шаг ритма, отступ даёт .shelf */
#vActive:empty{display:none}
#vActive .card{margin:0}
/* Индикатор связи */
.vpill{display:inline-flex;align-items:center;gap:7px;padding:5px 12px;border-radius:var(--r-pill,999px);
  font-size:13px;font-weight:600;border:1px solid var(--line);background:var(--card);
  box-shadow:var(--shadow-sm,0 1px 3px rgba(16,24,40,.06));transition:border-color .14s ease,box-shadow .14s ease}
.vdot{width:8px;height:8px;border-radius:50%;background:var(--muted)}
.vpill--ok{color:#065f46;background:#ecfdf5;border-color:#a7f3d0}.vpill--ok .vdot{background:var(--green)}
.vpill--off{color:#9a3412;background:#fff7ed;border-color:#fed7aa}.vpill--off .vdot{background:var(--amber)}
.vpill--wait .vdot{animation:vpulse 1s infinite}
@keyframes vpulse{50%{opacity:.3}}
/* Чек-лист готовности */
.vcheck{list-style:none;padding:0;margin:8px 0 0;display:grid;gap:8px}
.vcheck li{display:flex;align-items:center;gap:10px;font-size:14px}
.vbadge{display:inline-flex;width:20px;height:20px;border-radius:50%;align-items:center;justify-content:center;
  font-size:12px;font-weight:700;color:#fff;flex:none}
.vbadge--ok{background:var(--green)}.vbadge--no{background:var(--line);color:var(--muted)}
.vdir{font-size:12.5px;font-weight:700;padding:2px 8px;border-radius:var(--r-pill,999px)}
.vdir--in{color:#1d4ed8;background:#eff6ff}.vdir--out{color:#065f46;background:#ecfdf5}
.vtranscript{white-space:pre-wrap;font-size:13px;color:var(--text);background:var(--bg);
  border:1px solid var(--line);border-radius:var(--r-md,10px);padding:10px 12px;margin-top:6px;max-height:280px;overflow:auto}
.vlink{color:var(--red-dark);cursor:pointer;font-weight:600;transition:color .14s ease}
.vlink:hover{color:var(--red)}
</style>

<script>
const V = { esc: (ZR && ZR.escapeHtml) || (s => String(s==null?'':s)) };

function vTab(t){
  document.querySelectorAll('#vTabs .stab-btn').forEach(b=>b.classList.toggle('is-active', b.dataset.tab===t));
  document.querySelectorAll('.vtab').forEach(s=>s.classList.toggle('is-hidden', s.dataset.tab!==t));
}
document.querySelectorAll('#vTabs .stab-btn').forEach(b=>b.onclick=()=>vTab(b.dataset.tab));

function vPill(state){ // 'ok' | 'off' | 'wait'
  const p=document.getElementById('vPill'), txt=document.getElementById('vPillTxt');
  p.className='vpill vpill--'+state;
  txt.textContent = state==='ok'?'сервис онлайн':state==='off'?'сервис офлайн':'проверка связи…';
}

function vKpi(cfg, online){
  cfg=cfg||{};
  const good = v => v ? '' : '';
  const cell = (label,val,accent)=>`<div class="kpi ${accent?'kpi--accent':''}"><div class="kpi__label">${V.esc(label)}</div><div class="kpi__value">${V.esc(val)}</div></div>`;
  document.getElementById('vKpi').innerHTML =
    cell('Сервис', online?'онлайн':'офлайн', online) +
    cell('Мозг (LLM)', cfg.llm_provider||'—', cfg.llm_provider && cfg.llm_provider!=='fallback') +
    cell('Голос', cfg.tts_voice||'—') +
    cell('Телефония', cfg.vox_configured?'настроена':'нет', cfg.vox_configured);
}

function vChecklist(cfg){
  cfg=cfg||{};
  const row=(t,ok)=>`<li><span class="vbadge ${ok?'vbadge--ok':'vbadge--no'}">${ok?'✓':'—'}</span><span>${V.esc(t)}</span></li>`;
  document.getElementById('vChecklist').innerHTML =
    row('База знаний завода подключена', cfg.kb_path_exists) +
    row('Мозг: подключён реальный LLM (не заглушка)', cfg.llm_provider && cfg.llm_provider!=='fallback') +
    row('Ключ Yandex SpeechKit (голос)', cfg.yc_key) +
    row('Voximplant: номер и ключи', cfg.vox_configured) +
    row('Приём заявок в CRM (webhook secret)', cfg.crm_secret_set);
}

function vActive(calls, online){
  const box=document.getElementById('vActive');
  if(online===false){ box.innerHTML='<div class="muted">Сервис офлайн — состояние неизвестно.</div>'; return; }
  const act=(calls||[]).filter(c=>c.status==='active');
  box.innerHTML = act.length
    ? `<div class="card"><b>Идут сейчас:</b> ${act.map(c=>V.esc(c.caller||c.id)).join(', ')}</div>` : '';
}

function vCalls(rows){
  const b=document.getElementById('vCallsBody');
  if(!rows||!rows.length){ b.innerHTML='<tr><td colspan="5" class="muted">Пока нет голосовых обращений.</td></tr>'; return; }
  b.innerHTML = rows.map((r,i)=>{
    const dir = r.direction==='out'
      ? '<span class="vdir vdir--out">↗ исх</span>' : '<span class="vdir vdir--in">↘ вх</span>';
    const who = V.esc(r.lead_name||r.contact||r.lead_phone||'—');
    const subj = V.esc(r.subject||'');
    const when = (ZR&&ZR.dateTimeRu)?ZR.dateTimeRu(r.created_at):V.esc(r.created_at);
    const lead = r.lead_id?`<a class="btn btn--ghost btn-sm" href="lead.php?id=${encodeURIComponent(r.lead_id)}">Лид</a>`:'';
    const body = String(r.body||'');
    const main = `<tr><td>${when}</td><td>${dir}</td><td>${who}</td>`+
      `<td>${subj} ${body?`<span class="vlink" onclick="vToggle(${i})">расшифровка</span>`:''}</td><td>${lead}</td></tr>`;
    const tr = body?`<tr id="vtr${i}" style="display:none"><td colspan="5"><div class="vtranscript">${V.esc(body)}</div></td></tr>`:'';
    return main+tr;
  }).join('');
}
function vToggle(i){ const el=document.getElementById('vtr'+i); if(el) el.style.display = el.style.display==='none'?'':'none'; }

function vQueue(rows, online){
  const b=document.getElementById('vQueueBody');
  if(online===false){ b.innerHTML='<tr><td colspan="4" class="muted">Сервис офлайн — состояние неизвестно.</td></tr>'; return; }
  if(!rows||!rows.length){ b.innerHTML='<tr><td colspan="4" class="muted">Очередь пуста.</td></tr>'; return; }
  b.innerHTML = rows.map(r=>`<tr><td>${V.esc(r.phone)}</td><td>${V.esc(r.name||'')}</td><td>${V.esc(r.status)}</td><td>${V.esc(r.reason||'')}</td></tr>`).join('');
}

function vRefresh(){
  ZR.apiGet('/api/voice.php', {action:'state'}).then(d=>{
    const online = !!d.online;
    vPill(online?'ok':'off');
    vKpi(d.config, online);
    vChecklist(d.config);
    vActive(d.calls, online);
    vQueue(d.outbound||[], online);
  }).catch(()=>{ vPill('off'); vActive(null,false); vQueue(null,false); });
  // История звонков — из БД CRM (доступна даже если сервис офлайн).
  ZR.apiGet('/api/voice.php', {action:'db_calls'}).then(d=>vCalls(d.calls||[]))
    .catch(()=>{ document.getElementById('vCallsBody').innerHTML='<tr><td colspan="5" class="muted">Ошибка загрузки истории звонков.</td></tr>'; });
}

function vEnqueue(){
  const phone=document.getElementById('vPhone').value.trim();
  if(!phone){ ZR.toast('Укажите телефон','error'); return; }
  if((phone.match(/\d/g)||[]).length<10){ ZR.toast('Проверьте телефон — минимум 10 цифр','error'); return; }
  const btn=document.getElementById('vEnqBtn'); if(btn)btn.disabled=true;
  ZR.apiPost('/api/voice.php?action=enqueue', {
    phone, name:document.getElementById('vName').value.trim(), reason:document.getElementById('vReason').value.trim()
  }).then(d=>{
    if(d.ok){ ZR.toast('Готово — менеджер перезвонит в рабочие часы','success');
      ['vPhone','vName','vReason'].forEach(id=>document.getElementById(id).value=''); vRefresh(); }
    else ZR.toast('Ошибка: '+((d.result&&d.result.error)||d.error||'сервис недоступен'),'error');
  }).catch(()=>ZR.toast('Сервис недоступен','error'))
  .finally(()=>{ if(btn)btn.disabled=false; });
}

<?php if ($isAdmin): ?>
function vLoadSettings(){
  ZR.apiGet('/api/voice.php',{action:'settings_get'}).then(d=>{
    if(!d.ok) return;
    document.getElementById('vUrl').value=d.voice_service_url||'';
    document.getElementById('vSecret').placeholder = d.voice_turn_secret_set?'•••••• (задан)':'не задан';
    document.getElementById('vHookUrl').value = d.webhook_url||'';
    document.getElementById('vHookState').textContent = d.webhook_secret_set?'— задан ✓':'— не задан (приём закрыт)';
  });
}
function vSaveSettings(){
  const body={voice_service_url:document.getElementById('vUrl').value.trim()};
  const sec=document.getElementById('vSecret').value.trim(); if(sec) body.voice_turn_secret=sec;
  ZR.apiPost('/api/voice.php?action=settings_save', body).then(d=>{
    if(d.ok){ ZR.toast('Сохранено','success'); document.getElementById('vSecret').value=''; vRefresh(); }
    else ZR.toast('Ошибка: '+(d.error||''),'error');
  });
}
function vGenSecret(){
  ZR.apiGet('/api/voice.php',{action:'gen_secret'}).then(d=>{
    if(d.ok){ document.getElementById('vHookSecret').value=d.secret; ZR.toast('Секрет сгенерирован — не забудьте сохранить и вставить в .env сервиса','info'); }
    else ZR.toast('Ошибка: '+(d.error||'не удалось сгенерировать секрет'),'error');
  }).catch(()=>ZR.toast('Ошибка сети','error'));
}
function vSaveHook(){
  const sec=document.getElementById('vHookSecret').value.trim();
  if(!sec){ ZR.toast('Вставьте или сгенерируйте секрет','error'); return; }
  ZR.apiPost('/api/voice.php?action=settings_save', {voice_webhook_secret:sec}).then(d=>{
    if(d.ok){ ZR.toast('Секрет приёма заявок сохранён','success'); vLoadSettings(); }
    else ZR.toast('Ошибка: '+(d.error||''),'error');
  });
}
vLoadSettings();
<?php endif; ?>

vRefresh();
setInterval(()=>{ ZR.apiGet('/api/voice.php',{action:'state'}).then(d=>{ vPill(d.online?'ok':'off'); vKpi(d.config,!!d.online); }).catch(()=>vPill('off')); }, 20000);
</script>
<?php render_foot(); ?>
