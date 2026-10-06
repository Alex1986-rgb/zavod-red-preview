<?php
declare(strict_types=1);

require __DIR__ . '/_guard.php';
require __DIR__ . '/_layout.php';

$u = current_user() ?? [];
$isAdmin = ($u['role'] ?? '') === 'admin';
$mode = setting('ai_mode', 'review');

render_head('Инженер');
render_sidebar('engineer');
?>
<style>
  .eng-wrap{max-width:1400px}
  /* Режим обработки */
  .mode-bar{display:flex;align-items:center;gap:16px;flex-wrap:wrap;background:var(--card);border:1px solid var(--line);
    border-radius:var(--radius);box-shadow:var(--shadow-sm);padding:14px 18px}
  .mode-bar__title{font-weight:800;color:var(--ink);font-size:15px}
  .mode-seg{display:inline-flex;border:1px solid var(--line);border-radius:var(--r-md,10px);overflow:hidden}
  .mode-seg button{appearance:none;border:0;background:var(--card);padding:9px 15px;font-weight:600;font-size:13.5px;line-height:1;font-family:inherit;color:var(--muted);cursor:pointer;transition:.14s}
  .mode-seg button:hover:not(.on){background:var(--bg);color:var(--ink)}
  .mode-seg button:disabled{cursor:not-allowed}
  .mode-seg button:disabled:not(.on){opacity:.55}
  .mode-seg button:disabled:hover:not(.on){background:var(--card);color:var(--muted)}
  .mode-seg button+button{border-left:1px solid var(--line)}
  .mode-seg button.on{color:#fff}
  .mode-seg button.on[data-m=review]{background:var(--blue)}
  .mode-seg button.on[data-m=auto]{background:var(--red)}
  .mode-desc{font-size:13px;color:var(--muted);flex:1;min-width:200px}
  .mode-desc b{color:var(--ink)}
  /* Раскладка */
  .eng-grid{display:grid;grid-template-columns:340px 1fr;gap:18px;align-items:start}
  @media(max-width:1000px){.eng-grid{grid-template-columns:1fr}}
  /* Очередь */
  .queue{display:flex;flex-direction:column;gap:10px;max-height:78vh;overflow:auto;padding-right:2px}
  .qcard{background:var(--card);border:1px solid var(--line);border-radius:var(--r-lg,12px);box-shadow:var(--shadow-sm,var(--shadow));
    padding:12px 14px;cursor:pointer;transition:.14s;border-left:3px solid transparent}
  .qcard:hover{border-color:var(--line-strong);transform:translateY(-1px);box-shadow:var(--shadow-md,0 6px 18px rgba(16,24,40,.09))}
  .qcard.sel{border-left-color:var(--red);box-shadow:0 3px 14px rgba(225,27,27,.14)}
  .qcard__top{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:5px}
  .qcard__name{font-weight:700;color:var(--ink);font-size:14.5px}
  .qcard__sub{font-size:12.5px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .qcard__meta{display:flex;align-items:center;gap:8px;margin-top:7px;flex-wrap:wrap}
  .conf{font-size:11.5px;font-weight:700;padding:2px 8px;border-radius:var(--r-pill,999px)}
  .conf.hi{background:#d1fae5;color:#047857}.conf.mid{background:#fef3c7;color:#b45309}.conf.lo{background:#fee2e2;color:#b91c1c}.conf.none{background:var(--line);color:var(--muted)}
  /* Карточка проверки (сама карточка — .card дизайн-системы) */
  .rev__head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:6px}
  .rev__name{font-size:19px;font-weight:800;color:var(--ink)}
  .rev__contacts{color:var(--muted);font-size:13.5px;margin-top:2px}
  .rev__cols{display:grid;grid-template-columns:0.85fr 1.15fr;gap:20px;margin-top:16px}
  @media(max-width:900px){.rev__cols{grid-template-columns:1fr}}
  .panel-h{font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin:0 0 10px}
  .panel-h::before{content:"";display:inline-block;width:3px;height:15px;border-radius:2px;background:var(--red,#e11b1b);margin-right:7px;vertical-align:-3px}
  .filebox{border:1px solid var(--line);border-radius:var(--r-lg,12px);overflow:hidden;background:var(--bg)}
  .filebox iframe,.filebox img{width:100%;display:block;border:0}
  .filebox iframe{height:320px}
  .file-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;height:280px;color:var(--muted);text-align:center;padding:20px}
  .file-empty svg{width:46px;height:46px;opacity:.5}
  .file-name{padding:9px 12px;font-size:12.5px;color:var(--muted);border-top:1px solid var(--line);background:var(--card);display:flex;justify-content:space-between;gap:8px}
  .filedrop{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;height:280px;cursor:pointer;
    text-align:center;color:var(--muted);border:2px dashed var(--line);border-radius:var(--r-lg,12px);background:var(--bg);transition:.14s;padding:20px}
  .filedrop:hover{border-color:var(--red);color:var(--red);background:var(--card)}
  .filedrop svg{width:40px;height:40px;opacity:.7}
  .filedrop span{font-weight:700;color:var(--ink);font-size:14px}
  .filedrop:hover span{color:var(--red)}
  .filedrop small{font-size:12px;color:var(--muted)}
  /* Позиции / аналоги */
  .pos-list{display:flex;flex-direction:column;gap:8px}
  .pos{display:flex;gap:10px;align-items:flex-start;border:1px solid var(--line);border-radius:var(--r-md,10px);padding:10px 12px;background:var(--card);transition:.14s}
  .pos:hover{border-color:var(--line-strong);box-shadow:var(--shadow-sm,0 1px 3px rgba(16,24,40,.06))}
  .pos .chk{color:var(--green);flex:none;margin-top:1px}
  .pos .chk svg{width:18px;height:18px}
  .pos__b{min-width:0}
  .pos__t{font-weight:700;color:var(--ink);font-size:14px}
  .pos__d{font-size:12.5px;color:var(--muted);margin-top:2px;word-break:break-word}
  .analog{border:1px solid rgba(59,130,246,.2);background:rgba(59,130,246,.05);border-radius:var(--r-md,10px);padding:10px 12px;font-size:13px;transition:.14s}
  .analog:hover{box-shadow:var(--shadow-sm,0 1px 3px rgba(16,24,40,.06))}
  .analog__row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
  .analog__for{color:var(--muted)}.analog__arw{color:var(--blue);font-weight:800}.analog__our{font-weight:700;color:var(--ink)}
  .match{font-size:11px;font-weight:700;padding:1px 8px;border-radius:var(--r-pill,999px);margin-left:auto}
  .match.full{background:#d1fae5;color:#047857}.match.partial{background:#fef3c7;color:#b45309}.match.calc{background:#e0edff;color:#1d4ed8}
  .missing{margin:0;padding-left:18px;font-size:13px;color:#b45309}
  .missing li{margin:2px 0}
  .conf-wrap{display:flex;align-items:center;gap:10px;margin:2px 0 4px}
  .conf-bar{flex:1;height:8px;border-radius:var(--r-pill,999px);background:var(--line);overflow:hidden}
  .conf-bar i{display:block;height:100%;border-radius:var(--r-pill,999px);background:linear-gradient(90deg,#f59e0b,#10b981)}
  textarea.rev-ta{width:100%;box-sizing:border-box;border:1px solid var(--line);border-radius:var(--r-md,10px);padding:11px 13px;font:inherit;font-size:14px;color:var(--ink);resize:vertical;min-height:70px;line-height:1.5;background:var(--card);transition:border-color .14s,box-shadow .14s}
  textarea.rev-ta:hover{border-color:var(--line-strong)}
  textarea.rev-ta:focus{outline:none;border-color:var(--red);box-shadow:0 0 0 3px var(--red-soft,rgba(225,27,27,.16))}
  .rev-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}
  /* две решающие кнопки — .btn дизайн-системы, растянутые на ряд; «одобрить» — зелёная */
  .big-btn{flex:1;min-width:200px}
  /* в тёмной теме [data-theme=dark] .btn перекрашивает .btn--danger в серый — «переподбор» терял красный сигнал */
  .btn--danger.big-btn{background:var(--card);border-color:var(--red);color:var(--red)}
  .btn--danger.big-btn:hover:not(:disabled){background:var(--red);border-color:var(--red);color:#fff}
  .btn.big-ok{background:var(--green);border-color:var(--green);color:#fff}
  .btn.big-ok:hover:not(:disabled){background:#059669;border-color:#059669;color:#fff}
  /* строка «заголовок раздела + кнопка» */
  .sec-head{margin-bottom:10px}
  .sec-head > .card__title,.sec-head > .panel-h{margin:0}
  .hist{list-style:none;margin:10px 0 0;padding:0}
  .hist li{position:relative;padding:0 0 12px 20px;border-left:2px solid var(--line);font-size:13px}
  .hist li:last-child{border-left-color:transparent;padding-bottom:0}
  .hist .d{position:absolute;left:-6px;top:2px;width:10px;height:10px;border-radius:50%;background:var(--red);border:2px solid var(--card);box-shadow:0 0 0 1px var(--line)}
  .hist .tm{color:var(--muted);font-size:11.5px;margin-top:1px}
  .rev-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;min-height:400px;color:var(--muted);text-align:center}
  .rev-empty svg{width:56px;height:56px;opacity:.4}
  .section-gap{margin-top:18px}
</style>

<div class="page-head"><h1 class="page-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><path d="M14.7 6.3a4 4 0 0 1-5.4 5.4L4 17v3h3l5.3-5.3a4 4 0 0 1 5.4-5.4l-2.6 2.6-1.4-1.4 2.6-2.6Z"/></svg> Рабочее место инженера</h1></div>

<div class="eng-wrap shelf">
  <div class="help help--info">
    <span class="help__icon">🧭</span>
    <div class="help__body">
      <b>Последняя проверка перед клиентом.</b> ИИ распознаёт заявку (PDF или фото шильдика), подбирает аналоги ZR и готовит черновик письма — вам остаётся сверить и одобрить.
      <ul>
        <li><b>Слева</b> — очередь заявок: <b>на доработке</b> → <b>на проверке</b> → <b>подбор готов</b>.</li>
        <li>Проверьте распознанные позиции, аналоги и черновик ответа. Правьте текст письма прямо здесь.</li>
        <li><b><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5Z"/></svg> На доработку</b> — вернуть с комментарием (ИИ пересоберёт подбор). <b>✔ Одобрить</b> — письмо/КП уходит клиенту.</li>
      </ul>
    </div>
  </div>

  <!-- Режим обработки -->
  <div class="mode-bar">
    <span class="mode-bar__title">Режим обработки</span>
    <?php $modeBtnAttr = $isAdmin ? '' : ' disabled title="Режим переключает только администратор"'; ?>
    <div class="mode-seg" id="modeSeg" <?= $isAdmin ? '' : 'title="Режим переключает только администратор"' ?>>
      <button data-m="review" class="<?= $mode !== 'auto' ? 'on' : '' ?>"<?= $modeBtnAttr ?>><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6-10-6-10-6Z"/><circle cx="12" cy="12" r="2.6"/></svg> Проверка инженером</button>
      <button data-m="auto" class="<?= $mode === 'auto' ? 'on' : '' ?>"<?= $modeBtnAttr ?>><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><rect x="4" y="8" width="16" height="12" rx="3"/><path d="M12 4v4"/><circle cx="9" cy="14" r="1"/><circle cx="15" cy="14" r="1"/></svg> Автопилот</button>
    </div>
    <div class="mode-desc" id="modeDesc"></div>
  </div>

  <!-- KPI очереди -->
  <div class="kpi-grid">
    <div class="kpi"><div class="kpi__label"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5Z"/></svg> На доработке</div><div class="kpi__value" id="kRework">—</div></div>
    <div class="kpi kpi--accent"><div class="kpi__label"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6-10-6-10-6Z"/><circle cx="12" cy="12" r="2.6"/></svg> На проверке</div><div class="kpi__value" id="kReview">—</div></div>
    <div class="kpi"><div class="kpi__label"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><path d="m4 13 5 5L20 7"/></svg> Подбор готов</div><div class="kpi__value" id="kPicked">—</div></div>
  </div>

  <div class="eng-grid">
    <!-- Очередь -->
    <div>
      <div class="row-actions sec-head">
        <span class="card__title">Очередь заявок</span>
        <button class="btn btn-ghost btn-sm" id="btnBatch" hidden>🔁 Распознать все</button>
      </div>
      <div class="queue" id="queue"><div class="empty">Загрузка…</div></div>
    </div>

    <!-- Карточка проверки -->
    <div id="review">
      <div class="card rev-empty">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
        <div>Выберите заявку в очереди слева — здесь откроются позиции, аналоги и черновик ответа.</div>
      </div>
    </div>
  </div>
</div>

<script>
const EAPI = '../api/engineer.php';
const AIAPI = '../api/ai.php';
const IS_ADMIN = <?= $isAdmin ? 'true' : 'false' ?>;
const esc = ZR.escapeHtml;
let currentId = 0, currentMode = '<?= htmlspecialchars($mode, ENT_QUOTES) ?>';

const MODE_DESC = {
  review: '<b>Проверка инженером.</b> Заявки распознаются автоматически и попадают в очередь на проверку. Клиенту ничего не уходит без вашего одобрения.',
  auto:   '<b>Автопилот.</b> Система сама распознаёт заявку, готовит ответ и отправляет клиенту без ручной проверки. Инженер видит результат постфактум.'
};
function paintMode(){
  document.getElementById('modeDesc').innerHTML = MODE_DESC[currentMode] || '';
  document.querySelectorAll('#modeSeg button').forEach(b=>b.classList.toggle('on', b.dataset.m===currentMode));
}
paintMode();

document.querySelectorAll('#modeSeg button').forEach(b=>{
  b.addEventListener('click', async ()=>{
    if(!IS_ADMIN){ ZR.toast('Менять режим может администратор','error'); return; }
    const m=b.dataset.m; if(m===currentMode) return;
    const j = await ZR.apiPost(EAPI+'?action=mode', {mode:m});
    if(j&&j.ok){ currentMode=j.mode; paintMode(); ZR.toast('Режим: '+(m==='auto'?'Автопилот':'Проверка инженером'), 'success'); }
    else ZR.toast((j&&j.error)||'Ошибка','error');
  });
});

function confClass(c){ if(c==null) return 'none'; if(c>=75) return 'hi'; if(c>=45) return 'mid'; return 'lo'; }
function confText(c){ return c==null ? 'не распознано' : (c+'%'); }

async function loadQueue(){
  let j; try { j = await ZR.apiGet(EAPI, {action:'queue'}); } catch(_) { j = null; }
  const box = document.getElementById('queue');
  if(!j||!j.ok){ box.innerHTML='<div class="alert alert-err">Не удалось загрузить очередь — обновите страницу</div>'; return; }
  currentMode = j.mode || currentMode; paintMode();
  const c = j.counts||{};
  document.getElementById('kRework').textContent = c.rework||0;
  document.getElementById('kReview').textContent = c.review||0;
  document.getElementById('kPicked').textContent = c.picked||0;
  if(!j.items.length){ box.innerHTML='<div class="empty">Очередь пуста 🎉<br>Новые заявки на проверку появятся здесь автоматически.</div>'; return; }
  box.innerHTML = j.items.map(it=>{
    const cc=confClass(it.confidence);
    const sub=[it.reducer_type, it.phone].filter(Boolean).join(' · ');
    return `<div class="qcard ${it.id===currentId?'sel':''}" data-id="${it.id}">
      <div class="qcard__top">
        <span class="qcard__name">${esc(it.name)}</span>
        <span class="badge badge--${esc(it.status)}">${esc(it.status_label)}</span>
      </div>
      <div class="qcard__sub">${esc(sub||'—')}</div>
      <div class="qcard__meta">
        <span class="conf ${cc}">${it.positions?('📦 '+it.positions+' поз · '):''}${confText(it.confidence)}</span>
        <span class="qcard__sub" style="margin-left:auto">${ZR.dateTimeRu(it.updated_at)}</span>
      </div>
    </div>`;
  }).join('');
  box.querySelectorAll('.qcard').forEach(el=> el.addEventListener('click', ()=> openCard(parseInt(el.dataset.id,10))));

  // нераспознанные (confidence == null) → кнопка «Распознать все»
  UNRECOGNIZED = j.items.filter(it=>it.confidence==null).map(it=>it.id);
  const bb=document.getElementById('btnBatch');
  if(UNRECOGNIZED.length){ bb.hidden=false; bb.textContent='🔁 Распознать все ('+UNRECOGNIZED.length+')'; }
  else { bb.hidden=true; }
}

let UNRECOGNIZED=[];
document.getElementById('btnBatch').addEventListener('click', async (e)=>{
  const btn=e.currentTarget; const ids=UNRECOGNIZED.slice();
  if(!ids.length) return;
  const rb=document.getElementById('btnReco'); if(rb) rb.disabled=true; // блокируем одиночное на время пакета
  btn.disabled=true; BUSY=true;
  let done=0, fail=0;
  for(const id of ids){
    btn.textContent='Распознаю '+(done+fail+1)+'/'+ids.length+'…';
    try{
      const j=await ZR.apiPost(AIAPI+'?action=recognize', {id});
      if(j&&j.ok) done++; else fail++;
    }catch(_){ fail++; }
  }
  btn.disabled=false; BUSY=false; if(rb) rb.disabled=false;
  ZR.toast('Распознано: '+done+(fail?(', ошибок: '+fail):''), fail?'error':'success');
  loadQueue();
  if(currentId) openCard(currentId);
});

function uploadBox(id, label){
  return `<label class="filedrop">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M17 8l-5-5-5 5"/><path d="M12 3v12"/></svg>
      <span>${esc(label)}</span><small>PDF, фото или чертёж, до 15 МБ</small>
      <input type="file" id="fileInput" data-lead="${id}" accept=".pdf,.jpg,.jpeg,.png,.webp,.gif,.bmp,.heic,.dwg,.dxf,.doc,.docx,.xls,.xlsx,image/*" hidden>
    </label>`;
}
function fileViewer(fp, leadId){
  if(!fp) return uploadBox(leadId, 'Прикрепить PDF / фото шильдика клиента');
  const url = '../api/file.php?id=' + encodeURIComponent(leadId);
  const low = fp.toLowerCase();
  let inner;
  if(/\.(png|jpe?g|webp|gif|bmp)$/.test(low)) inner=`<img src="${url}" alt="Фото клиента">`;
  else if(/\.pdf$/.test(low)) inner=`<iframe src="${url}" title="PDF заявки"></iframe>`;
  else inner=`<div class="file-empty"><div>📎 ${esc(fp.split('/').pop())}</div></div>`;
  return inner + `<div class="file-name"><span>${esc(fp.split('/').pop())}</span>`
    + `<span style="display:flex;gap:12px"><a href="${url}" target="_blank" rel="noopener">Открыть ↗</a>`
    + `<label style="color:var(--red);cursor:pointer">Заменить<input type="file" id="fileInput" data-lead="${leadId}" accept=".pdf,.jpg,.jpeg,.png,.webp,.gif,.bmp,.heic,.dwg,.dxf,.doc,.docx,.xls,.xlsx,image/*" hidden></label></span></div>`;
}

function positionsHtml(rec){
  const pos=(rec&&rec.positions)||[];
  if(!pos.length) return '<div class="empty">Позиции не распознаны.</div>';
  return '<div class="pos-list">'+pos.map(p=>`
    <div class="pos"><span class="chk"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M20 6L9 17l-5-5"/></svg></span>
      <div class="pos__b">
        <div class="pos__t">${esc(p.model||p.raw||'Позиция')}${p.qty?(' × '+esc(p.qty)):''}</div>
        <div class="pos__d">${esc([p.kind,p.params].filter(Boolean).join(' · ')||p.raw||'')}</div>
      </div></div>`).join('')+'</div>';
}
function analogsHtml(rec){
  const a=(rec&&rec.analogs)||[];
  if(!a.length) return '';
  const cls=m=>{ m=(m||'').toLowerCase(); if(m.indexOf('полн')>=0) return 'full'; if(m.indexOf('част')>=0) return 'partial'; return 'calc'; };
  const vb=x=>{
    if(x.verified===true) return `<span class="match full" title="ZR-код подтверждён базой аналогов${x.price?', от '+esc(String(x.price))+' ₽':''}">✓ база${x.price?' · '+esc(String(x.price))+'₽':''}</span>`;
    if(x.verified===false) return `<span class="match partial" title="Аналог не найден в базе — подберите вручную">⚠ нет в базе</span>`;
    return '';
  };
  return `<div class="section-gap"><p class="panel-h">Предложенные аналоги</p>`+a.map(x=>`
    <div class="analog"><div class="analog__row">
      <span class="analog__for">${esc(x.for||'—')}</span><span class="analog__arw">→</span>
      <span class="analog__our">${esc(x.our||'—')}</span>
      ${vb(x)}
      <span class="match ${cls(x.match)}">${esc(x.match||'подбор')}</span>
    </div>${x.note?`<div class="pos__d" style="margin-top:5px">${esc(x.note)}</div>`:''}</div>`).join('')+`</div>`;
}
// Фото шильдика: три версии прочтения от автопилота (rec.nameplate) — ZR из справочника,
// уверенность, источник. Показываем, чтобы инженер видел, между чем выбирал ИИ.
function nameplateHtml(rec){
  const np=rec&&rec.nameplate; const h=(np&&np.hypotheses)||[];
  if(!h.length) return '';
  const rows=h.map((x,i)=>`<div class="analog"><div class="analog__row">
      <span class="analog__for">${i+1}. ${esc([x.brand,x.model].filter(Boolean).join(' ')||'—')}</span><span class="analog__arw">→</span>
      <span class="analog__our">${esc(x.zr||'—')}</span>
      ${x.verified?`<span class="match full" title="${esc(x.source||'')}">✓ ${esc(x.source||'справочник')}</span>`:`<span class="match partial">⚠ нет в справочнике</span>`}
      <span class="match calc">${x.confidence|0}%</span>
    </div>${x.why?`<div class="pos__d" style="margin-top:5px">${esc(x.why)}</div>`:''}</div>`).join('');
  return `<div class="section-gap"><p class="panel-h">Шильдик: версии прочтения</p>`
    +(np.readable?`<div class="pos__d" style="margin-bottom:8px">На табличке: ${esc(np.readable)}</div>`:'')+rows+`</div>`;
}
function missingHtml(rec){
  const m=(rec&&rec.missing)||[];
  if(!m.length) return '';
  return `<div class="section-gap"><p class="panel-h">Чего не хватает для точного подбора</p><ul class="missing">${m.map(x=>`<li>${esc(x)}</li>`).join('')}</ul></div>`;
}

async function openCard(id){
  currentId=id;
  document.querySelectorAll('.qcard').forEach(el=>el.classList.toggle('sel', parseInt(el.dataset.id,10)===id));
  const rv=document.getElementById('review');
  rv.innerHTML='<div class="card"><div class="empty">Загрузка карточки…</div></div>';
  const j = await ZR.apiGet(EAPI, {action:'card', id});
  if(!j||!j.ok){ rv.innerHTML='<div class="card"><div class="alert alert-err">Ошибка: '+esc((j&&j.error)||'')+'</div></div>'; return; }
  const l=j.lead, rec=j.recognition, notes=j.notes||[], hist=j.history||[];
  const conf = rec ? (rec.confidence|0) : null;
  const contacts=[l.phone,l.email].filter(Boolean).join(' · ');

  rv.innerHTML = `<div class="card">
    <div class="rev__head">
      <div>
        <div class="rev__name">${esc(l.name||'Заявка #'+l.id)} <span class="badge badge--${esc(l.status)}">${esc(l.status_label)}</span></div>
        <div class="rev__contacts">${esc(contacts||'—')} · ${esc(l.reducer_type||'тип не указан')} · <a href="lead.php?id=${l.id}">открыть лид #${l.id} ↗</a></div>
      </div>
      <button class="btn btn-ghost" id="btnReco">🔁 ${rec?'Перераспознать':'Распознать заявку'}</button>
    </div>

    <div class="rev__cols">
      <div>
        <p class="panel-h">📄 PDF / фото клиента</p>
        <div class="filebox">${fileViewer(l.file_path, l.id)}</div>
        ${l.message?`<div class="section-gap"><p class="panel-h">Текст заявки</p><div class="pos__d" style="white-space:pre-wrap">${esc(l.message)}</div></div>`:''}
      </div>
      <div>
        <p class="panel-h">📦 Распознанные позиции ${rec?'':'· <span style="color:var(--amber)">не распознано</span>'}</p>
        ${rec&&conf!=null?`<div class="conf-wrap"><span class="conf ${confClass(conf)}">${confText(conf)}</span><div class="conf-bar"><i style="width:${Math.max(4,conf)}%"></i></div></div>`:''}
        ${rec&&rec.summary?`<div class="pos__d" style="margin-bottom:10px">${esc(rec.summary)}</div>`:''}
        ${positionsHtml(rec)}
        ${nameplateHtml(rec)}
        ${analogsHtml(rec)}
        ${missingHtml(rec)}

        <div class="section-gap">
          <div class="row-actions sec-head">
            <p class="panel-h"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg> Черновик ответа клиенту</p>
            <button class="btn btn-ghost btn-sm" id="btnCopyDraft"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><rect x="7" y="4" width="10" height="16" rx="2"/><path d="M9 4V3h6v1"/><path d="M10 10h4M10 14h4"/></svg> Скопировать</button>
          </div>
          <textarea class="rev-ta" id="draftBox" rows="7" placeholder="${rec?'':'Нажмите «Распознать заявку» — ИИ подставит сюда черновик письма клиенту, его можно править'}">${esc(rec?(rec.draft||''):'')}</textarea>
        </div>

        <div class="section-gap">
          <p class="panel-h">💬 Комментарий инженера</p>
          <textarea class="rev-ta" id="noteBox" rows="2" placeholder="Что переподобрать или уточнить — ИИ учтёт это при доработке…"></textarea>
        </div>

        <div class="rev-actions">
          <button class="btn btn--danger big-btn" id="btnRework"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5Z"/></svg> Вернуть на переподбор</button>
          <button class="btn big-btn big-ok" id="btnApprove">✔ Одобрить и отправить клиенту</button>
        </div>
        <div class="hint" style="margin-top:8px">«Одобрить» отправит письмо на <b>${esc(l.email||'—')}</b> текстом из черновика выше. Снимите отправку — статус станет «Одобрено» без письма.</div>
        <label class="check-label" style="margin-top:6px"><input type="checkbox" id="sendChk" checked> Отправить письмо клиенту при одобрении</label>
      </div>
    </div>

    ${(j.price_hints&&j.price_hints.length)?`<div class="section-gap"><p class="panel-h">₽ Раньше продавали (наши счета и КП)</p><ul class="hist">${j.price_hints.map(p=>`<li><span class="d" style="background:var(--green,#16a34a)"></span><div>${esc(p)}</div></li>`).join('')}</ul><div class="hint">Подсказка по прошлым документам, не прайс: проверьте актуальность перед КП.</div></div>`:''}
    ${(hist.length||notes.length)?`<div class="section-gap"><p class="panel-h">🕘 История правок и комментариев</p><ul class="hist">
      ${hist.map(h=>`<li><span class="d"></span><div>${esc(h.label)}</div><div class="tm">${ZR.dateTimeRu(h.created_at)}${h.user_name?(' · '+esc(h.user_name)):''}</div></li>`).join('')}
      ${notes.map(n=>`<li><span class="d" style="background:var(--blue)"></span><div style="white-space:pre-wrap">${esc(n.text)}</div><div class="tm">${ZR.dateTimeRu(n.created_at)}${n.user_name?(' · '+esc(n.user_name)):''}</div></li>`).join('')}
    </ul></div>`:''}
  </div>`;

  document.getElementById('btnReco').addEventListener('click', reco);
  document.getElementById('btnRework').addEventListener('click', rework);
  document.getElementById('btnApprove').addEventListener('click', approve);
  document.getElementById('btnCopyDraft').addEventListener('click', async ()=>{
    const t=(document.getElementById('draftBox').value||'').trim();
    if(!t){ ZR.toast('Черновик пуст','error'); return; }
    try{ await navigator.clipboard.writeText(t); ZR.toast('Черновик скопирован','success'); }
    catch(_){ ZR.toast('Не удалось скопировать','error'); }
  });
  const fi=document.getElementById('fileInput');
  if(fi) fi.addEventListener('change', ()=>uploadFile(fi));
}

async function uploadFile(input){
  const file=input.files&&input.files[0];
  if(!file) return;
  if(file.size>15*1024*1024){ ZR.toast('Файл больше 15 МБ','error'); return; }
  ZR.toast('Загрузка файла…','info');
  const fd=new FormData();
  fd.append('csrf', window.CSRF||'');
  fd.append('id', input.dataset.lead);
  fd.append('file', file);
  try{
    const r=await fetch('../api/leads.php?action=upload',{method:'POST',headers:{'X-CSRF':window.CSRF||''},body:fd});
    const j=await r.json();
    if(j&&j.ok){ ZR.toast('Файл прикреплён','success'); await openCard(currentId); }
    else ZR.toast((j&&j.error)||'Ошибка загрузки','error');
  }catch(_){ ZR.toast('Ошибка сети','error'); }
}

async function reco(e){
  const btn=e.currentTarget, old=btn.textContent;
  const bb=document.getElementById('btnBatch'); if(bb) bb.disabled=true; // не даём запустить пакет параллельно
  btn.disabled=true; btn.textContent='ИИ распознаёт…'; BUSY=true;
  try{
    const note=(document.getElementById('noteBox')||{}).value||'';
    const j = await ZR.apiPost(AIAPI+'?action=recognize', {id:currentId, rework:note});
    if(j&&j.ok){ ZR.toast('Заявка распознана','success'); await openCard(currentId); loadQueue(); }
    else ZR.toast((j&&j.error)||'Ошибка распознавания','error');
  }catch(err){ ZR.toast('Ошибка сети','error'); }
  finally{ btn.disabled=false; btn.textContent=old; BUSY=false; if(bb) bb.disabled=false; }
}

async function rework(e){
  const note=(document.getElementById('noteBox').value||'').trim();
  if(!note){ ZR.toast('Опишите, что исправить, в поле «Комментарий инженера»','error'); document.getElementById('noteBox').focus(); return; }
  const btn=e.currentTarget; btn.disabled=true; BUSY=true;
  try{
    const j = await ZR.apiPost(EAPI+'?action=rework', {id:currentId, note});
    if(j&&j.ok){ ZR.toast('Возвращено на доработку','success'); await openCard(currentId); loadQueue(); }
    else ZR.toast((j&&j.error)||'Ошибка','error');
  } catch(_) { ZR.toast('Ошибка сети — повторите','error'); }
  finally { btn.disabled=false; BUSY=false; }
}

async function approve(e){
  const btn=e.currentTarget; const old=btn.textContent;
  const body=(document.getElementById('draftBox').value||'').trim();
  const note=(document.getElementById('noteBox').value||'').trim();
  const send=document.getElementById('sendChk').checked ? '1':'0';
  if(send==='1' && !body){ ZR.toast('Черновик письма пуст. Распознайте заявку или впишите текст вручную — иначе клиенту нечего отправить','error'); return; }
  btn.disabled=true; btn.textContent='Отправляю…'; BUSY=true;
  try{
    const j = await ZR.apiPost(EAPI+'?action=approve', {id:currentId, body, note, send});
    if(j&&j.ok){
      ZR.toast(j.sent?'Одобрено и отправлено клиенту ✅':'Одобрено'+(j.warn?' (без письма)':''), 'success');
      if(j.warn) ZR.toast(j.warn,'error');
      currentId=0; document.getElementById('review').innerHTML='<div class="card rev-empty"><div>Готово. Выберите следующую заявку.</div></div>';
      loadQueue();
    } else ZR.toast((j&&j.error)||'Ошибка','error');
  } catch(_) { ZR.toast('Ошибка сети — письмо не отправлено, повторите','error'); }
  finally { btn.disabled=false; btn.textContent=old; BUSY=false; }
}

var BUSY=false; // на время approve/rework/reco/batch пропускаем авто-тик
loadQueue();
setInterval(()=>{ if(!BUSY) loadQueue(); }, 60000); // авто-обновление очереди
</script>
<?php render_foot();
