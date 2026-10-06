<?php
declare(strict_types=1);
/**
 * Директ — маркетинг: единый хаб с табами (обзор, ключи, запросы, сегментация,
 * конкуренты, стратег, креативы, настройки). Данные — api/direct.php.
 * Read-only мониторинг + ИИ (Claude/DALL·E через настроенные ключи).
 */
require __DIR__ . '/_guard.php';
require __DIR__ . '/_layout.php';

render_head('Директ — маркетинг', true);
render_sidebar('direct');
$csrf = csrf_token();
?>
<style>
/* Директ: вкладки — .stabs + .shelf, заголовки карточек — .card__title, ряды полей — .filters,
   переключатели фильтра кампаний — .stab-btn, предупреждение — .help--warn, таблицы — .table-wrap + .tbl.
   Здесь только то, чего в дизайн-системе нет. */
.d-mt{margin-top:10px}                          /* следующий блок внутри карточки */
.d-diag{font-size:12px;margin:0 0 4px}          /* строки диагностики над списком кампаний */
.d-diag--sm{font-size:11px;margin-bottom:3px}
.d-block{margin:8px 0}                          /* предупреждение и панель поиска над таблицей кампаний */
.d-chips{display:flex;gap:6px;flex-wrap:wrap}
/* поле, растущее в ряду .filters; min-height — на телефоне (≤560) .filters становится колонкой,
   и flex:1 без него сплющивает поле до 16 px */
.d-grow{flex:1;min-width:200px;min-height:var(--ctl-h)}
/* конкурент в списке — карточка без колоночной раскладки .card: домен и кнопки в одну строку */
.d-comp{display:block;margin:8px 0}
</style>
<div class="page-head">
  <h1 class="page-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><path d="M3 17l6-6 4 4 8-8"/><path d="M15 7h6v6"/></svg> Директ — маркетинг</h1>
  <div class="page-head__actions">
    <select id="dDays" class="input">
      <option value="LAST_7_DAYS">7 дней</option>
      <option value="LAST_30_DAYS" selected>30 дней</option>
      <option value="THIS_MONTH">Этот месяц</option>
      <option value="LAST_MONTH">Прошлый месяц</option>
    </select>
  </div>
</div>

<div class="stabs" id="dTabs">
  <button class="stab-btn is-active" data-tab="overview">Обзор</button>
  <button class="stab-btn" data-tab="keywords">Ключи</button>
  <button class="stab-btn" data-tab="queries">Запросы</button>
  <button class="stab-btn" data-tab="seg">Сегментация</button>
  <button class="stab-btn" data-tab="competitors">Конкуренты</button>
  <button class="stab-btn" data-tab="strategist">Стратег</button>
  <button class="stab-btn" data-tab="creative">Креативы</button>
  <button class="stab-btn" data-tab="settings">Настройки</button>
</div>

<!-- ОБЗОР -->
<section class="dtab shelf" data-tab="overview">
  <div id="dKpi" class="kpi-grid"></div>
  <div class="dchart-row">
    <div class="card dchart"><h3 class="card__title">Топ кампаний — клики и конверсии</h3><div class="dchart-box"><canvas id="dOvBar"></canvas></div></div>
    <div class="card dchart"><h3 class="card__title">Клики: Поиск vs Сети (РСЯ)</h3><div class="dchart-box dchart-box--donut"><canvas id="dOvDonut"></canvas></div></div>
  </div>
  <div class="card"><h3 class="card__title">Кампании</h3><div id="dCamps"><div class="empty">Загрузка…</div></div></div>
</section>

<!-- КЛЮЧИ -->
<section class="dtab shelf hidden" data-tab="keywords">
  <div class="card"><h3 class="card__title">Топ ключей по конверсиям</h3>
    <canvas id="dKwChart" height="120"></canvas>
    <div id="dKwTable" class="d-mt"><span class="muted">—</span></div></div>
</section>

<!-- ЗАПРОСЫ -->
<section class="dtab shelf hidden" data-tab="queries">
  <div class="card"><h3 class="card__title">Поисковые запросы (что реально вводят люди)</h3>
    <p class="hint">Мусор (клики без заявок) → добавь в минус-слова. Конвертящие → в ключи.</p>
    <div id="dQueries"><div class="empty">Загрузка…</div></div></div>
</section>

<!-- СЕГМЕНТАЦИЯ -->
<section class="dtab shelf hidden" data-tab="seg">
  <div class="card"><h3 class="card__title">Конверсии по устройствам и регионам</h3>
    <div id="dSeg"><div class="empty">Загрузка…</div></div></div>
</section>

<!-- КОНКУРЕНТЫ -->
<section class="dtab shelf hidden" data-tab="competitors">
  <div class="card"><h3 class="card__title">Конкуренты</h3>
    <p class="hint">Добавь домен конкурента → «Анализировать» (ИИ разберёт офферы, ключи, УТП, гэпы).</p>
    <div class="filters">
      <input id="dCompDomain" class="input d-grow" placeholder="домен, напр. example.ru">
      <button id="dCompAddBtn" class="btn btn--primary" onclick="dCompAdd()">Добавить</button>
    </div>
    <div id="dComps" class="d-mt"><span class="muted">—</span></div></div>
</section>

<!-- СТРАТЕГ -->
<section class="dtab shelf hidden" data-tab="strategist">
  <div class="card"><h3 class="card__title">AI-стратег: медиаплан</h3>
    <p class="hint">Опиши цель — ИИ соберёт структуру кампаний, ключи, объявления, минус-слова, бюджет.</p>
    <textarea id="dStGoal" class="input" rows="2" placeholder="Напр.: максимум заявок на редукторы и аналоги по РФ"></textarea>
    <div class="filters d-mt">
      <input id="dStBudget" class="input" type="number" value="1000" style="width:150px" placeholder="бюджет ₽/день">
      <input id="dStGeo" class="input" value="Россия" style="width:200px">
      <button id="dStBtn" class="btn btn--primary" onclick="dStrategist()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><path d="M9 4a3 3 0 0 0-3 3 3 3 0 0 0-1 5.8V15a4 4 0 0 0 4 4h1V4H9Z"/><path d="M15 4a3 3 0 0 1 3 3 3 3 0 0 1 1 5.8V15a4 4 0 0 1-4 4h-1V4h1Z"/></svg> Сгенерировать</button>
    </div>
    <div id="dStOut" class="d-mt"></div></div>
</section>

<!-- КРЕАТИВЫ -->
<section class="dtab shelf hidden" data-tab="creative">
  <div class="card"><h3 class="card__title">Креативы (генерация баннера, DALL·E)</h3>
    <p class="hint">Опиши баннер — ИИ нарисует. Нужен ключ OpenAI в Настройках.</p>
    <textarea id="dCrPrompt" class="input" rows="2" placeholder="Напр.: индустриальный баннер редукторов, завод, синие механизмы, без текста"></textarea>
    <div class="d-mt"><button id="dCrBtn" class="btn btn--primary" onclick="dCreative()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><circle cx="12" cy="12" r="9"/><circle cx="8.5" cy="10" r="1.2"/><circle cx="12" cy="7.5" r="1.2"/><circle cx="15.5" cy="10" r="1.2"/><path d="M12 21a3 3 0 0 0 3-3 2 2 0 0 0-2-2h-1a2 2 0 0 1 0-4h1"/></svg> Сгенерировать</button></div>
    <div id="dCrOut" class="d-mt"></div></div>
</section>

<!-- НАСТРОЙКИ -->
<section class="dtab shelf hidden" data-tab="settings">
  <div class="card"><h3 class="card__title">Настройки Директа</h3>
    <p class="hint">Ключи и токены задаются в <a href="settings.php#integrations">Настройки → Интеграции</a>. Здесь — статус.</p>
    <div id="dSettings"><div class="empty">Загрузка…</div></div>
  </div>
</section>

<script>
const dCsrf='<?= htmlspecialchars($csrf, ENT_QUOTES, "UTF-8") ?>';
const dGet=(a)=>fetch('/api/direct.php?action='+a+'&days='+document.getElementById('dDays').value,{credentials:'same-origin'}).then(r=>r.json());
const dPost=(a,body)=>fetch('/api/direct.php?action='+a,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF':dCsrf},body:JSON.stringify(body||{})}).then(r=>r.json());
const dEsc=s=>String(s==null?'':s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#x27;'}[c]));
const dNum=n=>Number(n||0).toLocaleString('ru-RU');
const dMd=t=>dEsc(t).replace(/^### (.*)$/gm,'<h4>$1</h4>').replace(/^## (.*)$/gm,'<h3>$1</h3>').replace(/\*\*(.+?)\*\*/g,'<b>$1</b>').replace(/^- (.*)$/gm,'• $1').replace(/\n/g,'<br>');
let dKwChartObj=null, dLoaded={};

// табы — переключение через inline display (не зависит от кэша admin.css)
function dShowTab(tab){
  document.querySelectorAll('#dTabs .stab-btn').forEach(x=>x.classList.toggle('is-active', x.dataset.tab===tab));
  document.querySelectorAll('.dtab').forEach(s=>{ var on=s.dataset.tab===tab; s.style.display=on?'':'none'; s.classList.toggle('hidden',!on); });
  dLoadTab(tab);
}
document.querySelectorAll('#dTabs .stab-btn').forEach(b=>b.onclick=()=>dShowTab(b.dataset.tab));
// начальное состояние: показать только активный таб принудительно (на случай устаревшего CSS)
(function(){ var act=(document.querySelector('#dTabs .stab-btn.is-active')||{}).dataset; var t=act&&act.tab||'overview';
  document.querySelectorAll('.dtab').forEach(s=>{ s.style.display=(s.dataset.tab===t)?'':'none'; }); })();
document.getElementById('dDays').addEventListener('change',()=>{dLoaded={};dLoadTab(document.querySelector('#dTabs .stab-btn.is-active').dataset.tab);});

function dLoadTab(t){
  if(dLoaded[t] && t!=='settings') return; dLoaded[t]=true;
  const fn={overview:dOverview,keywords:dKeywords,queries:dQueries,seg:dSeg,competitors:dComps,settings:dSet}[t];
  if(!fn) return;
  // при ошибке загрузки сбрасываем флаг — повторный клик по табу перезагрузит данные
  Promise.resolve(fn()).then(ok=>{ if(ok===false) dLoaded[t]=false; }).catch(()=>{ dLoaded[t]=false; });
}

async function dOverview(){
  const [ov,bal]=await Promise.all([dGet('overview'),dGet('balance')]);
  if(!ov.ok){document.getElementById('dCamps').textContent='Ошибка: '+(ov.error||'');return false;}
  const t=ov.totals, kpi=(l,v,cls)=>`<div class="kpi"><div class="kpi__label">${l}</div><div class="kpi__value${cls?' '+cls:''}">${v}</div></div>`;
  document.getElementById('dKpi').innerHTML=
    kpi('Показы',dNum(Math.round(t.impr||0)))+
    kpi('Клики',dNum(Math.round(t.clicks)))+
    kpi('CTR',(t.ctr!=null?t.ctr:0)+' %')+
    kpi('Ср. цена клика',t.cpc?dNum(t.cpc)+' ₽':'—')+
    kpi('Расход',dNum(Math.round(t.cost))+' ₽')+
    kpi('Конверсии',dNum(Math.round(t.conv)),'acc')+
    kpi('Цена лида · CPA',t.cpa?dNum(t.cpa)+' ₽':'—')+
    kpi('Кампаний',t.campaigns)+
    kpi('Активных',t.active,'good')+
    kpi('Баланс',(bal.ok&&bal.balance.amount!=null)?dNum(Math.round(bal.balance.amount))+' ₽':'—');
  // Диагностика: почему кампаний столько (архивные, таймаут отчёта, тип аккаунта).
  var dg=ov.diag||{}, diagHtml='';
  var warn=[];
  if(dg.report_failed) warn.push('Отчёт-статистика не пришёл (таймаут API) — цифры показов/расхода могут быть нулевыми. Обновите через 20–30 сек.');
  if(dg.states_failed) warn.push('Список кампаний не загрузился — показаны только кампании с активностью за период.');
  var stateStr=dg.by_state?Object.keys(dg.by_state).map(function(k){return dEsc(k)+':'+dg.by_state[k];}).join('  '):'';
  var typeStr=dg.by_type?Object.keys(dg.by_type).map(function(k){return dEsc(k)+':'+dg.by_type[k];}).join('  '):'';
  var info='Кабинет токена: <b>'+(dg.account?dEsc(dg.account):'—')+'</b>'
    +' · всего кампаний: <b>'+(dg.in_account!=null?dg.in_account:'—')+'</b>'
    +(t.archived?' · архивных: '+t.archived:'')
    +' · с активностью за период: '+(dg.in_report!=null?dg.in_report:'—');
  diagHtml='<div class="muted d-diag">'+info+'</div>';
  if(stateStr) diagHtml+='<div class="muted d-diag d-diag--sm">По статусам: '+stateStr+'</div>';
  if(typeStr) diagHtml+='<div class="muted d-diag d-diag--sm">По типам: '+typeStr+'</div>';
  if(warn.length) diagHtml+='<div class="help help--warn d-block"><div class="help__body">⚠ '+warn.join('<br>⚠ ')+'</div></div>';
  // Все кампании (вкл. новые с 0 показов) рисуются всегда; панель фильтра ниже —
  // чтобы среди 50+ кампаний мгновенно найти нужную, а новые (без показов) не
  // «терялись» внизу списка, отсортированного по расходу.
  window._dCampsAll = ov.campaigns||[];
  window.dPayBadge = function(p){ return p==='conv'
    ? '<span class="badge badge--muted" title="Оплата за конверсию: клики бесплатны, списание только за заявку (форму). Поэтому у таких кампаний Расход 0 ₽, пока не было оплачиваемой заявки.">за&nbsp;конв.</span>'
    : (p==='click' ? '<span class="badge" title="Оплата за клики: каждый клик платный">за&nbsp;клики</span>' : '<span class="muted">—</span>'); };
  window.dCampRow = function(c){ return `<tr><td><span class="badge ${c.state==='ON'?'badge--ok':(c.state==='ARCHIVED'?'badge--muted':'badge--muted')}" ${c.state==='ARCHIVED'?'title="Архивная кампания"':''}>${dEsc(c.state)}</span></td><td>${dEsc(c.name)}</td><td>${dPayBadge(c.pay)}</td><td class="num">${dNum(Math.round(c.impr||0))}</td><td class="num">${dNum(Math.round(c.clicks))}</td><td class="num">${(c.ctr!=null?c.ctr:0)}%</td><td class="num">${dNum(Math.round(c.cost))} ₽</td><td class="num">${c.cpc?dNum(c.cpc)+' ₽':'—'}</td><td class="num"><b>${dNum(Math.round(c.conv))}</b></td><td class="num">${c.cpa?dNum(c.cpa)+' ₽':'—'}</td></tr>`; };
  window._dCampFilter='all';
  window.dCampChip=function(b){ document.querySelectorAll('#dCampChips .dchip').forEach(x=>x.classList.remove('is-active')); b.classList.add('is-active'); window._dCampFilter=b.dataset.f; dFilterCamps(); };
  window.dFilterCamps=function(){
    var si=document.getElementById('dCampSearch'); var q=(si?si.value:'').trim().toLowerCase();
    var f=window._dCampFilter||'all', all=window._dCampsAll||[];
    var list=all.filter(function(c){
      if(q && String(c.name||'').toLowerCase().indexOf(q)<0) return false;
      if(f==='impr')   return (c.impr||0)>0;
      if(f==='noimpr') return (c.impr||0)===0;
      if(f==='on')     return c.state==='ON';
      if(f==='off')    return c.state!=='ON';
      return true;
    });
    var tb=document.getElementById('dCampBody');
    if(tb) tb.innerHTML=list.length?list.map(window.dCampRow).join(''):'<tr><td colspan="10"><div class="empty">— ничего не найдено —</div></td></tr>';
    var cnt=document.getElementById('dCampCount'); if(cnt) cnt.textContent='показано '+list.length+' из '+all.length;
  };
  var _noimpr=(ov.campaigns||[]).filter(c=>(c.impr||0)===0).length;
  var filterBar=
    '<div class="filters d-block">'
    +'<input id="dCampSearch" class="input d-grow" placeholder="поиск по названию кампании…" oninput="dFilterCamps()">'
    +'<div id="dCampChips" class="d-chips">'
    +'<button class="stab-btn dchip is-active" data-f="all" onclick="dCampChip(this)">Все</button>'
    +'<button class="stab-btn dchip" data-f="impr" onclick="dCampChip(this)">С показами</button>'
    +'<button class="stab-btn dchip" data-f="noimpr" onclick="dCampChip(this)">Без показов'+(_noimpr?' ('+_noimpr+')':'')+'</button>'
    +'<button class="stab-btn dchip" data-f="on" onclick="dCampChip(this)">Активные</button>'
    +'<button class="stab-btn dchip" data-f="off" onclick="dCampChip(this)">Выкл/архив</button>'
    +'</div>'
    +'<span id="dCampCount" class="muted" style="font-size:12px;margin-left:auto;white-space:nowrap"></span>'
    +'</div>';
  document.getElementById('dCamps').innerHTML=diagHtml+filterBar+'<div class="table-wrap"><table class="tbl"><thead><tr><th>Сост.</th><th>Кампания</th><th>Оплата</th><th class="r">Показы</th><th class="r">Клики</th><th class="r">CTR</th><th class="r">Расход</th><th class="r">Ср.&nbsp;клик</th><th class="r">Конв.</th><th class="r">CPA</th></tr></thead><tbody id="dCampBody"></tbody></table></div>';
  dFilterCamps();
  // ---- диаграммы обзора ----
  window._dch=window._dch||{};
  var _mk=function(id,cfg){var el=document.getElementById(id);if(!el||!window.Chart)return;if(_dch[id])_dch[id].destroy();_dch[id]=new Chart(el,cfg);};
  var _cs=ov.campaigns.filter(c=>c.clicks>0), _sn=n=>{n=String(n||'');return n.length>26?n.slice(0,24)+'…':n;};
  var _top=_cs.slice().sort((a,b)=>b.clicks-a.clicks).slice(0,8);
  _mk('dOvBar',{type:'bar',data:{labels:_top.map(c=>_sn(c.name)),datasets:[{label:'Клики',data:_top.map(c=>Math.round(c.clicks)),backgroundColor:'#e11b1b',borderRadius:5},{label:'Конверсии',data:_top.map(c=>Math.round(c.conv)),backgroundColor:'#f7a26b',borderRadius:5}]},options:{indexAxis:'y',responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom',labels:{boxWidth:12,font:{size:11}}}},scales:{x:{beginAtZero:true,grid:{color:'rgba(0,0,0,.05)'}},y:{grid:{display:false},ticks:{font:{size:11}}}}}});
  var _sk=0,_nt=0,_ot=0;_cs.forEach(c=>{var n=String(c.name||'');if(/РСЯ|сет/i.test(n))_nt+=c.clicks;else if(/поиск/i.test(n))_sk+=c.clicks;else _ot+=c.clicks;});
  _mk('dOvDonut',{type:'doughnut',data:{labels:['Поиск','Сети (РСЯ)','Прочее'],datasets:[{data:[Math.round(_sk),Math.round(_nt),Math.round(_ot)],backgroundColor:['#e11b1b','#f7a26b','#c9d3db'],borderWidth:0}]},options:{responsive:true,maintainAspectRatio:false,cutout:'60%',plugins:{legend:{position:'bottom',labels:{boxWidth:12,font:{size:11}}}}}});
}
async function dKeywords(){
  const d=await dGet('keywords');
  if(!d.ok){document.getElementById('dKwTable').textContent='Ошибка: '+(d.error||'');return false;}
  const conv=(d.converting&&d.converting.length)?d.converting:d.top.slice(0,12);
  if(dKwChartObj)dKwChartObj.destroy();
  dKwChartObj=new Chart(document.getElementById('dKwChart'),{type:'bar',
    data:{labels:conv.slice(0,12).map(k=>k.keyword.slice(0,40)),datasets:[{label:'Конверсии',data:conv.slice(0,12).map(k=>k.conv||k.clicks),backgroundColor:'#e11b1b'}]},
    options:{indexAxis:'y',plugins:{legend:{display:false}},scales:{x:{beginAtZero:true}}}});
  document.getElementById('dKwTable').innerHTML=`<p class="muted">Всего ключей: <b>${d.total}</b>, с конверсиями: <b>${d.with_conversions}</b></p><div class="table-wrap"><table class="tbl"><thead><tr><th>Ключ</th><th>Кампания</th><th class="r">Переходы</th><th class="r">Конв.</th></tr></thead><tbody>`+
    d.top.slice(0,25).map(k=>`<tr><td>${dEsc(k.keyword)}</td><td class="muted">${dEsc(k.campaign.slice(0,26))}</td><td class="num">${dNum(Math.round(k.clicks))}</td><td class="num"><b>${dNum(Math.round(k.conv))}</b></td></tr>`).join('')+'</tbody></table></div>';
}
async function dQueries(){
  const d=await dGet('queries');
  if(!d.ok){document.getElementById('dQueries').textContent='Ошибка: '+(d.error||'');return false;}
  const tbl=arr=>'<div class="table-wrap"><table class="tbl"><thead><tr><th>Запрос</th><th class="r">Клики</th><th class="r">Конв.</th></tr></thead><tbody>'+arr.map(x=>`<tr><td>${dEsc(x.query)}</td><td class="num">${dNum(Math.round(x.clicks))}</td><td class="num"><b>${dNum(Math.round(x.conv))}</b></td></tr>`).join('')+'</tbody></table></div>';
  document.getElementById('dQueries').innerHTML=`<p class="muted">Всего запросов: <b>${dNum(d.total)}</b></p><h4 style="color:#e11b1b"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><path d="M4 7h16"/><path d="M10 11v6M14 11v6"/><path d="M6 7l1 13h10l1-13"/><path d="M9 7V4h6v3"/></svg> Мусор (в минус-слова):</h4>`+tbl(d.junk)+`<h4 style="color:#1a8a3a;margin-top:10px"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><path d="m4 13 5 5L20 7"/></svg> Конвертящие (в ключи):</h4>`+tbl(d.converting);
}
async function dSeg(){
  const d=await dGet('segmentation');
  if(!d.ok){document.getElementById('dSeg').textContent='Ошибка: '+(d.error||'');return false;}
  const bars=(arr)=>{if(!arr||arr.error||!arr.length)return '<span class="muted">нет данных</span>';const mx=Math.max(...arr.map(x=>x.conv),1);return arr.slice(0,8).map(x=>`<div style="display:flex;align-items:center;gap:8px;margin:3px 0"><span style="width:140px;color:var(--muted)">${dEsc(x.label)}</span><div style="height:14px;background:#e11b1b;border-radius:2px;width:${Math.max(4,x.conv/mx*220)}px"></div><span>${Math.round(x.conv)} конв · ${Math.round(x.clicks)} кл</span></div>`).join('');};
  document.getElementById('dSeg').innerHTML=`<h4>По устройствам</h4>${bars(d.seg.device)}<h4 style="margin-top:10px">По регионам</h4>${bars(d.seg.region)}`;
}
async function dComps(){
  const d=await dGet('competitors');
  const box=document.getElementById('dComps');
  if(!d.ok){box.textContent='Ошибка: '+(d.error||'');return false;}
  box.innerHTML=d.items.length?d.items.map(c=>`<div class="card d-comp"><b>${dEsc(c.domain)}</b>
    <button class="btn btn--ghost btn-sm" data-act="analyze" data-domain="${dEsc(c.domain)}">Анализировать</button>
    <button class="btn btn--danger btn-sm" data-act="remove" data-domain="${dEsc(c.domain)}">Удалить</button>
    ${c.analysis?'<div style="margin-top:8px">'+dMd(c.analysis)+'</div>':'<div class="muted">не анализирован</div>'}</div>`).join(''):'<span class="muted">Добавь домен конкурента.</span>';
}
// делегированный обработчик кнопок конкурентов (вместо inline onclick)
document.getElementById('dComps').addEventListener('click',e=>{
  const b=e.target.closest('button[data-act]'); if(!b||!b.dataset.domain) return;
  if(b.dataset.act==='analyze') dCompAnalyze(b.dataset.domain);
  else if(b.dataset.act==='remove') dCompRemove(b.dataset.domain);
});
async function dCompAdd(){
  const inp=document.getElementById('dCompDomain'), dom=inp.value.trim(); if(!dom)return;
  const btn=document.getElementById('dCompAddBtn'); if(btn)btn.disabled=true;
  try{
    const r=await dPost('competitor_add',{domain:dom});
    if(r&&r.ok){ inp.value=''; dComps(); }
    else { if(window.ZR&&ZR.toast)ZR.toast((r&&r.error)||'Ошибка','error'); else alert((r&&r.error)||'Ошибка'); }
  }catch(e){ if(window.ZR&&ZR.toast)ZR.toast('Ошибка сети','error'); else alert('Ошибка сети'); }
  finally{ if(btn)btn.disabled=false; }
}
async function dCompRemove(dom){if(!confirm('Удалить конкурента '+dom+'?'))return;const r=await dPost('competitor_remove',{domain:dom});if(r&&r.ok){if(window.ZR&&ZR.toast)ZR.toast('Удалено','success');}else if(window.ZR&&ZR.toast)ZR.toast((r&&r.error)||'Ошибка','error');dComps();}
async function dCompAnalyze(dom){document.getElementById('dComps').insertAdjacentHTML('afterbegin','<div class="muted">⏳ ИИ анализирует '+dEsc(dom)+'…</div>');const r=await dPost('competitor_analyze',{domain:dom});if(!r.ok)(window.ZR&&ZR.toast?ZR.toast('Ошибка: '+(r.error||''),'error'):alert('Ошибка: '+(r.error||'')));dComps();}
async function dStrategist(){
  const out=document.getElementById('dStOut');out.innerHTML='<span class="muted"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><path d="M9 4a3 3 0 0 0-3 3 3 3 0 0 0-1 5.8V15a4 4 0 0 0 4 4h1V4H9Z"/><path d="M15 4a3 3 0 0 1 3 3 3 3 0 0 1 1 5.8V15a4 4 0 0 1-4 4h-1V4h1Z"/></svg> ИИ думает…</span>';
  const btn=document.getElementById('dStBtn'); if(btn)btn.disabled=true;
  try{
    const r=await dPost('strategist',{goal:document.getElementById('dStGoal').value,budget:document.getElementById('dStBudget').value,geo:document.getElementById('dStGeo').value});
    out.innerHTML=r.ok?('<div class="card">'+dMd(r.plan)+'</div>'):('Ошибка: '+(r.error||''));
  }catch(e){ out.innerHTML='Ошибка сети'; }
  finally{ if(btn)btn.disabled=false; }
}
async function dCreative(){
  const out=document.getElementById('dCrOut');out.innerHTML='<span class="muted"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><circle cx="12" cy="12" r="9"/><circle cx="8.5" cy="10" r="1.2"/><circle cx="12" cy="7.5" r="1.2"/><circle cx="15.5" cy="10" r="1.2"/><path d="M12 21a3 3 0 0 0 3-3 2 2 0 0 0-2-2h-1a2 2 0 0 1 0-4h1"/></svg> рисую (до минуты)…</span>';
  const btn=document.getElementById('dCrBtn'); if(btn)btn.disabled=true;
  try{
    const r=await dPost('creative',{prompt:document.getElementById('dCrPrompt').value});
    out.innerHTML=r.ok?('<img src="'+r.image+'" style="max-width:512px;border-radius:8px">'):('Ошибка: '+(r.error||''));
  }catch(e){ out.innerHTML='Ошибка сети'; }
  finally{ if(btn)btn.disabled=false; }
}
async function dSet(){
  const [ov,bal]=await Promise.all([dGet('overview'),dGet('balance')]);
  const row=(l,v)=>`<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--line)"><span>${l}</span><span><b>${v}</b></span></div>`;
  document.getElementById('dSettings').innerHTML=
    row('Токен Директа',ov.ok?'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><path d="m4 13 5 5L20 7"/></svg> работает':'❌ '+dEsc(ov.error||'не задан'))+
    row('Баланс аккаунта',(bal.ok&&bal.balance.amount!=null)?dNum(Math.round(bal.balance.amount))+' ₽':'—')+
    row('Кампаний в аккаунте',ov.ok?ov.totals.campaigns:'—')+
    `<p class="hint d-mt">Токен Директа, счётчик Метрики, ключ OpenAI, Telegram-алерты — в <a href="settings.php#integrations">Настройки → Интеграции</a>.</p>`;
}
dLoadTab('overview');
</script>
<?php render_foot(); ?>
