<?php
declare(strict_types=1);

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_layout.php';

render_head('Письма');
render_sidebar('mail');
?>
<div class="page-head"><h1 class="page-title">Письма</h1></div>
<style>
.ml-wrap{display:grid;grid-template-columns:210px 340px 1fr;gap:16px;align-items:start}
@media(max-width:1180px){.ml-wrap{grid-template-columns:180px 300px 1fr}}
@media(max-width:900px){.ml-wrap{grid-template-columns:1fr}}
/* колонки — как .card из дизайн-системы (фон, рамка, радиус 12 px, тень), но без её внутренних отступов: у списка строки во всю ширину */
.ml-card{background:var(--card);border:1px solid var(--line);border-radius:12px;box-shadow:var(--shadow-sm)}
/* колонка папок */
.ml-fold{padding:14px}
.ml-fold .btn{margin-bottom:14px}
.ml-fold .ml-nav{display:flex;justify-content:space-between;align-items:center;gap:8px;width:100%;background:none;border:0;font:inherit;text-align:left;padding:9px 11px;border-radius:var(--r-md,10px);color:var(--text);font-size:14px;cursor:pointer;margin-bottom:2px;transition:background .14s ease,color .14s ease}
.ml-fold .ml-nav:hover{background:var(--bg)}
.ml-fold .ml-nav:focus-visible{outline:2px solid var(--red);outline-offset:1px}
.ml-fold .ml-nav.active{background:var(--red-soft);color:var(--red);font-weight:600}
.ml-fold .ml-nav .n{font-size:12px;color:var(--muted);font-weight:600}
.ml-fold .ml-nav.active .n{color:var(--red)}
.ml-fold .ml-nav .n[hidden]{display:none}
.ml-fold h4{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);margin:16px 0 6px 11px}
/* колонка списка */
.ml-list-hd{padding:12px 14px;border-bottom:1px solid var(--line);display:flex;gap:8px}
.ml-list-hd .input{flex:1}
.ml-list{max-height:calc(100vh - 190px);overflow-y:auto}
.ml-item{padding:13px 15px;border-bottom:1px solid var(--line);cursor:pointer;transition:background .14s ease}
.ml-item:hover{background:var(--bg)}
.ml-item.sel{background:var(--red-soft)}
.ml-item.unread .ml-from{font-weight:800}
.ml-item.unread::before{content:'';display:inline-block;width:7px;height:7px;border-radius:50%;background:var(--blue);margin-right:7px;vertical-align:middle}
.ml-item__top{display:flex;justify-content:space-between;gap:8px}
.ml-from{color:var(--ink);font-weight:600;font-size:14px}
.ml-time{color:var(--muted);font-size:12px;flex:0 0 auto}
.ml-subj{color:var(--text);font-size:13.5px;margin:3px 0 2px;font-weight:600}
.ml-prev{color:var(--muted);font-size:12.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ml-chip{display:inline-block;font-size:11px;padding:2px 8px;border-radius:5px;margin-top:6px;font-weight:600}
.ml-chip--new{background:#e0edff;color:#2563eb}
.ml-chip--todo{background:#fef3c7;color:#b45309}
.ml-chip--done{background:#d1fae5;color:#047857}
.ml-chip--kind{background:color-mix(in srgb,var(--blue) 13%,transparent);color:var(--blue)}
.ml-chip--muted{background:var(--bg);color:var(--muted);border:1px solid var(--line)}
a.ml-att__i--link{cursor:pointer;color:inherit;text-decoration:none}
a.ml-att__i--link:hover{border-color:var(--blue)}
.ml-clip{color:var(--muted)}
/* колонка чтения */
.ml-read{padding:22px 24px;min-height:400px}
.ml-empty{display:grid;place-items:center;height:400px;color:var(--muted);text-align:center}
.ml-read__hd{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;border-bottom:1px solid var(--line);padding-bottom:14px;margin-bottom:16px}
.ml-read__hd h2{font-size:19px}
.ml-read__meta{color:var(--muted);font-size:13px;margin-top:5px}
.ml-read__meta b{color:var(--text);font-weight:600}
.ml-star{background:none;border:0;font-size:20px;cursor:pointer;color:var(--line-strong);transition:color .14s ease,transform .14s ease}
.ml-star:hover{color:#f59e0b;transform:scale(1.12)}
.ml-star.on{color:#f59e0b}
.ml-body{font-size:14.5px;line-height:1.7;color:var(--text);white-space:pre-wrap;margin-bottom:18px}
.ml-att{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:18px}
/* информационный блок, не кликабелен: файлы вложений в CRM не сохраняются */
.ml-att__i{display:flex;gap:9px;align-items:center;border:1px solid var(--line);border-radius:var(--r-md,10px);padding:10px 13px;font-size:13px;background:var(--bg);cursor:default}
.ml-att__i .x{width:34px;height:34px;border-radius:var(--r-sm,8px);background:var(--red-soft);color:var(--red);display:grid;place-items:center;font-weight:700;font-size:11px}
.ml-actions{display:flex;gap:9px;flex-wrap:wrap;border-top:1px solid var(--line);padding-top:16px}
/* Две карточки рядом, только если колонке письма хватает ширины (~2×260 px); иначе одна под другой.
   Раньше колонки стояли жёстко 1fr 1fr и при окне ~1000 px вылезали за экран. */
.ml-ai{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px;margin-top:18px}
.ml-ai > *{min-width:0}
.ml-ai__card{border:1px solid var(--line);border-radius:var(--r-lg,14px);padding:14px 16px;background:var(--bg);transition:box-shadow .14s ease,border-color .14s ease}
.ml-ai__card:hover{box-shadow:var(--shadow-sm,0 1px 3px rgba(16,24,40,.06))}
.ml-ai__hd{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}
.ml-ai__hd b{display:flex;align-items:center;gap:7px;font-size:13.5px;color:var(--ink)}
.ml-ai__ava{min-width:22px;height:22px;padding:0 6px;border-radius:6px;background:#ede9fe;color:#7c3aed;display:grid;place-items:center;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.02em}
.ml-conf{font-size:11px;background:#d1fae5;color:#047857;padding:3px 8px;border-radius:20px;font-weight:600}
.ml-rec{font-size:13px}
.ml-rec div{display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px dashed var(--line)}
.ml-rec div:last-child{border-bottom:0}
.ml-rec span{color:var(--muted)}
.ml-rec b{color:var(--ink);font-weight:600;text-align:right}
.ml-draft{font-size:13px;line-height:1.6;white-space:pre-wrap;color:var(--text);max-height:220px;overflow-y:auto}
.ml-compose{margin-top:14px;border-top:1px solid var(--line);padding-top:14px}
.ml-compose textarea{width:100%;min-height:120px;padding:11px 13px;border:1px solid var(--line);border-radius:var(--r-md,10px);font:inherit;font-size:14px;transition:border-color .14s ease,box-shadow .14s ease}
.ml-compose textarea:focus{outline:none;border-color:var(--red);box-shadow:0 0 0 3px var(--red-soft,rgba(225,27,27,.16))}
</style>

<div class="ml-wrap">
  <!-- папки -->
  <aside class="ml-card ml-fold">
    <button class="btn btn--primary btn--block" onclick="openCompose()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5Z"/></svg> Написать письмо</button>
    <button class="btn btn--ghost btn--block" id="pollBtn" onclick="pollMail()">↻ Проверить почту</button>
    <button type="button" class="ml-nav" data-f="inbox"   onclick="setFolder('inbox')"><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><path d="M12 3v10"/><path d="m8 9 4 4 4-4"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/></svg> Входящие</span><span class="n" id="c-inbox" title="Непрочитанных" hidden>0</span></button>
    <button type="button" class="ml-nav" data-f="starred" onclick="setFolder('starred')"><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><path d="m12 3 2.7 5.6 6.1.9-4.4 4.3 1 6.1-5.4-2.9-5.4 2.9 1-6.1L3.2 9.5l6.1-.9L12 3Z"/></svg> Помеченные</span><span class="n" id="c-starred" hidden>0</span></button>
    <button type="button" class="ml-nav" data-f="sent"    onclick="setFolder('sent')"><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><path d="m3 11 18-7-7 18-2.5-8.5L3 11Z"/></svg> Отправленные</span><span class="n" id="c-sent" hidden>0</span></button>
    <button type="button" class="ml-nav" data-f="drafts"  onclick="setFolder('drafts')"><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5Z"/></svg> Черновики</span><span class="n" id="c-drafts" hidden>0</span></button>
    <button type="button" class="ml-nav" data-f="spam"    onclick="setFolder('spam')"><span>⚠ Спам</span><span class="n" id="c-spam" hidden>0</span></button>
    <button type="button" class="ml-nav" data-f="trash"   onclick="setFolder('trash')"><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><path d="M4 7h16"/><path d="M10 11v6M14 11v6"/><path d="M6 7l1 13h10l1-13"/><path d="M9 7V4h6v3"/></svg> Корзина</span><span class="n" id="c-trash" hidden>0</span></button>
    <div id="mlFilters">
      <h4>Фильтры</h4>
      <button type="button" class="ml-nav" data-flt="unread" onclick="setFilter('unread')"><span>Непрочитанные</span></button>
      <button type="button" class="ml-nav" data-flt="attach" onclick="setFilter('attach')"><span>С вложениями</span></button>
      <button type="button" class="ml-nav" data-flt="clients" onclick="setFilter('clients')"><span>Только клиенты</span></button>
    </div>
  </aside>

  <!-- список -->
  <section class="ml-card">
    <div class="ml-list-hd">
      <input id="mlq" class="input" type="search" placeholder="Поиск по письмам…" autocomplete="off">
    </div>
    <div id="mlList" class="ml-list"><div class="empty">Загрузка…</div></div>
  </section>

  <!-- чтение -->
  <section class="ml-card ml-read" id="mlRead">
    <div class="ml-empty">Выберите письмо слева</div>
  </section>
</div>

<!-- Компоновщик нового письма -->
<div id="composeBack" class="cmp-back" hidden>
  <div class="cmp">
    <div class="cmp__hd"><b><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5Z"/></svg> Новое письмо</b><button class="cmp__x" onclick="closeCompose()" aria-label="Закрыть">✕</button></div>
    <div class="cmp__row">
      <label class="field cmp__f"><span class="field__label">Кому (email)</span><input class="input" type="email" id="cmpTo" placeholder="client@example.com"></label>
      <label class="field cmp__f"><span class="field__label">Тема</span><input class="input" type="text" id="cmpSubj" placeholder="Тема письма"></label>
    </div>
    <label class="field cmp__f"><span class="field__label">Текст</span><textarea id="cmpBody" rows="7" placeholder="Текст письма…"></textarea></label>
    <div class="cmp__act">
      <button class="btn btn--primary" id="cmpSend" onclick="sendCompose()">Отправить</button>
      <button class="btn btn--ghost" onclick="closeCompose()">Отмена</button>
      <span class="muted" style="font-size:12.5px;margin-left:auto">Если SMTP не настроен — письмо сохранится в «Черновиках».</span>
    </div>
  </div>
</div>
<style>
  .cmp-back{position:fixed;inset:0;background:rgba(15,23,42,.5);display:flex;align-items:flex-start;justify-content:center;padding:40px 16px;z-index:120}
  .cmp-back[hidden]{display:none}
  .cmp{background:var(--card);border-radius:var(--r-lg,14px);box-shadow:var(--shadow-lg,0 20px 50px rgba(16,24,40,.18));width:100%;max-width:460px;padding:16px 18px 18px}
  .cmp__row{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px}
  .cmp__hd{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
  .cmp__hd b{font-size:16px;color:var(--ink)}
  .cmp__x{background:none;border:0;font-size:18px;color:var(--muted);cursor:pointer;transition:color .14s ease}
  .cmp__x:hover{color:var(--red)}
  /* поля = .field + .field__label + .input; у .input фиксированная высота, поэтому textarea — своя (фокус даёт общий .field textarea:focus).
     Отступы — на строке полей и панели кнопок: у .field общий margin-bottom:0 !important */
  .cmp__f textarea{border:1px solid var(--line);border-radius:8px;padding:10px 12px;font:inherit;font-size:14px;color:var(--ink);background:var(--card);width:100%;box-sizing:border-box;resize:vertical;transition:border-color .14s ease,box-shadow .14s ease}
  .cmp__act{display:flex;align-items:center;gap:9px;margin-top:16px}
</style>

<script>
const API = '../api/mailbox.php';
/* Тип письма (api/mail_sync.php): кто написал и зачем. */
const KIND = {request:'Обращение', client:'Клиент', supplier:'Поставщик', invoice:'Счёт нам', newsletter:'Рассылка', spam:'Спам', service:'Уведомление', other:'Разобрать'};
let FOLDER='inbox', FILTER='', CURID=0, DRAFT_ID=0;

/** Бейдж папки: число + скрытие при нуле. */
function setCount(f,n){ const el=document.getElementById('c-'+f); if(!el) return; n=Number(n)||0; el.textContent=n; el.hidden = n===0; }
/** Блокировка кнопки на время запроса + единый тост при обрыве сети. */
async function withBtn(btn, fn){
  if(btn) btn.disabled=true;
  try{ return await fn(); }
  catch(e){ ZR.toast('Ошибка сети','error'); return null; }
  finally{ if(btn) btn.disabled=false; }
}

async function load(){
  const box = document.getElementById('mlList');
  let r;
  try{
    r = await ZR.apiGet(API,{action:'list', folder:FOLDER, filter:FILTER, q:document.getElementById('mlq').value.trim()});
  }catch(e){
    box.innerHTML='<div class="muted" style="padding:16px;text-align:center">Нет связи с сервером<br><button type="button" class="btn btn--ghost" style="margin-top:10px" onclick="load()">Повторить</button></div>';
    ZR.toast('Ошибка сети','error'); return;
  }
  if(!r || !r.ok){ box.innerHTML='<div class="empty">Ошибка загрузки</div>'; ZR.toast((r&&r.error)||'Ошибка загрузки','error'); return; }
  // у «Входящих» цифра = непрочитанные (как в почтовых клиентах), у остальных — всего писем
  setCount('inbox', r.unread);
  ['starred','sent','drafts','spam','trash'].forEach(function(f){ setCount(f, r.counts?r.counts[f]:0); });
  document.querySelectorAll('.ml-fold [data-f]').forEach(a=>a.classList.toggle('active', a.dataset.f===FOLDER));
  document.querySelectorAll('.ml-fold [data-flt]').forEach(a=>a.classList.toggle('active', a.dataset.flt===FILTER));
  // фильтры «Непрочитанные»/«С вложениями» осмысленны только для входящих писем
  document.getElementById('mlFilters').style.display = (FOLDER==='sent'||FOLDER==='drafts') ? 'none' : '';
  if(!r.messages.length){ box.innerHTML='<div class="empty">Писем нет</div>'; return; }
  let h='';
  r.messages.forEach(function(m){
    const isIn = m.direction!=='out';
    const noise = ['newsletter','service','spam','supplier','invoice','other','archive'].indexOf(m.mail_kind||'')>=0;
    const todo = isIn && !noise && (!(m.lead_id&&m.lead_id!='0') || m.lead_status==='new');
    const kind = (isIn && m.mail_kind && KIND[m.mail_kind]) ? ' <span class="ml-chip ml-chip--'+(noise?'muted':'kind')+'">'+KIND[m.mail_kind]+'</span>' : '';
    h += '<div class="ml-item'+(m.is_read=='0'?' unread':'')+(m.id==CURID?' sel':'')+'" onclick="openMsg('+m.id+')">'
      + '<div class="ml-item__top"><span class="ml-from">'+(isIn?'':'Кому: ')+ZR.escapeHtml(m.contact||'—')+'</span><span class="ml-time">'+fmtTime(m.created_at)+'</span></div>'
      + '<div class="ml-subj">'+ZR.escapeHtml(m.subject||'(без темы)')+'</div>'
      + '<div class="ml-prev">'+ZR.escapeHtml((m.preview||'').replace(/<[^>]+>/g,' ').trim())+'</div>'
      + (m.has_attach=='1'?'<span class="ml-clip">📎 вложение</span>':'')
      + kind + (todo?' <span class="ml-chip ml-chip--todo">Требует обработки</span>':'')
      + '</div>';
  });
  // сервер отдаёт максимум 200 писем — честно предупреждаем, что список обрезан
  if(r.messages.length>=200) h += '<div class="muted" style="padding:12px 15px;text-align:center;font-size:12.5px">Показаны последние 200 писем. Чтобы найти более старые — уточните поиск.</div>';
  box.innerHTML=h;
}
function fmtTime(s){ const d=new Date((s||'').replace(' ','T')); const now=new Date(); if(d.toDateString()===now.toDateString()) return String(d.getHours()).padStart(2,'0')+':'+String(d.getMinutes()).padStart(2,'0'); return ZR.dateRu(s); }

async function openMsg(id){
  CURID=id;
  window._draft=''; // черновик относится к конкретному письму — сбрасываем при открытии другого
  const box = document.getElementById('mlRead');
  box.innerHTML='<div class="ml-empty">Открываю письмо…</div>'; // иначе на медленной сети видно предыдущее письмо
  let r;
  try{ r = await ZR.apiGet(API,{action:'get', id:id}); }
  catch(e){ box.innerHTML='<div class="ml-empty">Нет связи с сервером</div>'; ZR.toast('Ошибка сети','error'); return; }
  if(!r || !r.ok){ box.innerHTML='<div class="ml-empty">Не удалось открыть письмо</div>'; return; }
  const m=r.message, rec=r.recognized||{}, lead=r.lead;
  const isIn = m.direction!=='out';
  const folder = String(m.folder||'inbox');
  const inBin = (folder==='trash'||folder==='spam');
  // Вложения: сохранённые новым приёмом почты — ссылкой и с тем, что распознал ИИ;
  // у писем, пришедших до новой синхронизации, файл остался только в почтовом ящике.
  const files = r.files||[];
  const att = files.length
    ? '<div class="ml-att">'+files.map(function(f){
        const st = f.status==='done' ? ZR.escapeHtml(f.summary||'') : (f.status==='new' ? 'ждёт разбора ИИ' : ZR.escapeHtml(f.summary||'не разобран'));
        return '<a class="ml-att__i ml-att__i--link" href="../api/file.php?mf='+f.id+'" target="_blank" rel="noopener"><span class="x">📎</span><div><b>'+ZR.escapeHtml(f.filename)+'</b> <span class="ml-chip ml-chip--kind">'+ZR.escapeHtml(f.kind_label||'')+'</span><br><small class="muted">'+st+'</small></div></a>';
      }).join('')+'</div>'
    : (m.has_attach=='1' ? '<div class="ml-att"><div class="ml-att__i"><span class="x">📎</span><div><b>Есть вложение</b><br><small class="muted">письмо пришло до новой синхронизации — файл остался в почтовом ящике</small></div></div></div>' : '');
  const leadLink = lead ? '<a href="lead.php?id='+lead.id+'">Заявка #'+lead.id+' ('+ZR.escapeHtml(lead.status)+')</a>' : '<span class="muted">не привязана</span>';

  let acts='';
  if(folder==='drafts'){
    acts += '<button class="btn btn--primary" onclick="sendDraft('+m.id+',this)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><path d="m3 11 18-7-7 18-2.5-8.5L3 11Z"/></svg> Отправить</button>'
         +  '<button class="btn btn--ghost" onclick="editDraft('+m.id+',this)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5Z"/></svg> Редактировать</button>';
  } else if(isIn){
    acts += '<button class="btn btn--primary" onclick="makeLead('+m.id+',this)">🗇 Создать заявку</button>'
         +  '<button class="btn btn--ghost" onclick="toEngineer('+m.id+',this)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><path d="M14.7 6.3a4 4 0 0 1-5.4 5.4L4 17v3h3l5.3-5.3a4 4 0 0 1 5.4-5.4l-2.6 2.6-1.4-1.4 2.6-2.6Z"/></svg> Передать инженеру</button>'
         +  '<button class="btn btn--ghost" onclick="recognize('+m.id+',this)">↻ Распознать</button>'
         +  '<button class="btn btn--ghost" onclick="toggleReply()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg> Ответить</button>';
  }
  acts += inBin
    ? '<button class="btn btn--ghost" onclick="moveTo('+m.id+',\'inbox\',this)">↩ Восстановить</button>'
    : ((isIn?'<button class="btn btn--ghost" onclick="moveTo('+m.id+',\'spam\',this)">⚠ В спам</button>':'')
       + '<button class="btn btn--ghost" onclick="moveTo('+m.id+',\'trash\',this)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><path d="M4 7h16"/><path d="M10 11v6M14 11v6"/><path d="M6 7l1 13h10l1-13"/><path d="M9 7V4h6v3"/></svg> В корзину</button>');

  const aiBlock = (isIn && folder!=='drafts')
    ? '<div class="ml-ai">'
      +   '<div class="ml-ai__card"><div class="ml-ai__hd"><b><span class="ml-ai__ava" title="Разбор текста по правилам, без обращения к ИИ">авто</span> Распознано из письма</b><span class="ml-conf" id="mlConf" title="Грубая оценка: сколько параметров удалось вытащить из текста письма">'+confLabel(rec._confidence)+'</span></div><div class="ml-rec" id="mlRec"></div></div>'
      +   '<div class="ml-ai__card"><div class="ml-ai__hd"><b><span class="ml-ai__ava" title="Шаблон по распознанным параметрам, без обращения к ИИ">авто</span> Черновик ответа</b><button class="btn btn--ghost btn--sm" onclick="genDraft('+m.id+',this)">Собрать черновик</button></div><div class="ml-draft" id="mlDraft"><span class="muted">Нажмите «Собрать черновик» — соберём ответ-шаблон по распознанным параметрам.</span></div></div>'
      + '</div>'
      + '<div class="ml-compose" id="mlCompose" style="display:none"><textarea id="mlReply" placeholder="Текст ответа…"></textarea><div style="margin-top:10px;display:flex;gap:9px"><button class="btn btn--primary" onclick="sendReply('+m.id+',this)">Отправить ответ</button><button class="btn btn--ghost" id="mlUseDraft" onclick="useDraft()" disabled>← Вставить черновик</button></div></div>'
    : '';

  box.innerHTML =
      '<div class="ml-read__hd"><div><h2>'+ZR.escapeHtml(m.subject||'(без темы)')+'</h2>'
    + '<div class="ml-read__meta">'+(isIn?'От':'Кому')+': <b>'+ZR.escapeHtml(m.contact||'—')+'</b> · '+ZR.dateTimeRu(m.created_at)+'<br>Заявка: '+leadLink+'</div></div>'
    + '<button class="ml-star'+(m.is_starred=='1'?' on':'')+'" id="mlStar" onclick="toggleStar('+m.id+','+(m.is_starred=='1'?0:1)+',this)" title="'+(m.is_starred=='1'?'Снять пометку':'Пометить')+'">★</button></div>'
    + '<div class="ml-body">'+ZR.escapeHtml((m.body||'').replace(/<[^>]+>/g,'')).trim()+'</div>'
    + att
    + '<div class="ml-actions">' + acts + '</div>'
    + aiBlock;
  if(isIn && folder!=='drafts') renderRec(rec);
  load(); // обновить список (прочитано)
}
/** Подпись бейджа полноты разбора: число — не «уверенность ИИ», а доля вытащенных параметров. */
function confLabel(v){ return 'полнота '+(Number(v)||0)+'%'; }
function renderRec(rec){
  const map=[['client','Клиент'],['phone','Телефон'],['type','Тип редуктора'],['power','Мощность двигателя'],['rpm','Частота вращения'],['ratio','Передаточное число'],['torque','Выходной момент'],['mount','Способ установки'],['position','Рабочая позиция']];
  let h=''; map.forEach(function(p){ if(rec[p[0]]) h+='<div><span>'+p[1]+'</span><b>'+ZR.escapeHtml(rec[p[0]])+'</b></div>'; });
  document.getElementById('mlRec').innerHTML = h || '<span class="muted">Параметры не распознаны — письмо без ТЗ.</span>';
}
async function recognize(id,btn){ await withBtn(btn, async function(){
  const r=await ZR.apiPost(API,{action:'recognize',id:id});
  if(r&&r.ok){ renderRec(r.recognized); const c=document.getElementById('mlConf'); if(c) c.textContent=confLabel(r.recognized._confidence); ZR.toast('Распознано','success'); }
  else ZR.toast((r&&r.error)||'Не удалось распознать','error');
}); }
async function genDraft(id,btn){
  const box=document.getElementById('mlDraft');
  box.innerHTML='<span class="muted">Собираю черновик…</span>';
  // withBtn вернёт null при обрыве сети — тогда снимаем «Собираю…», иначе надпись залипает навсегда
  const r = await withBtn(btn, function(){ return ZR.apiPost(API,{action:'draft',id:id}); });
  if(r&&r.ok){ window._draft=r.draft||r.text||''; box.textContent=window._draft; const u=document.getElementById('mlUseDraft'); if(u) u.disabled=!window._draft; }
  else if(r){ box.textContent='Не удалось'; ZR.toast(r.error||'Не удалось собрать черновик','error'); }
  else { box.textContent='Нет связи с сервером'; }
}
function toggleReply(){ const c=document.getElementById('mlCompose'); if(!c) return; c.style.display = c.style.display==='none'?'block':'none'; if(c.style.display==='block') document.getElementById('mlReply').focus(); }
function useDraft(){ if(!window._draft){ ZR.toast('Сначала сгенерируйте черновик','error'); return; } document.getElementById('mlReply').value=window._draft; }
async function sendReply(id,btn){ const ta=document.getElementById('mlReply'); const b=ta.value.trim(); if(!b){ ZR.toast('Пустой ответ','error'); return; }
  const old=btn?btn.textContent:''; if(btn) btn.textContent='Отправляю…';
  await withBtn(btn, async function(){
    const r=await ZR.apiPost(API,{action:'reply',id:id,body:b});
    if(r&&r.ok){
      // сервер сохранил ответ в любом случае — панель закрываем, чтобы не плодить дубли
      ZR.toast(r.note||(r.sent?'Ответ отправлен':'Сохранено в «Черновиках»'), r.sent?'success':'info');
      ta.value=''; toggleReply(); load();
    } else ZR.toast((r&&r.error)||'Ошибка','error');
  });
  if(btn) btn.textContent=old; }
async function makeLead(id,btn){ await withBtn(btn, async function(){
  const r=await ZR.apiPost(API,{action:'make_lead',id:id});
  if(r&&r.ok){ ZR.toast(r.note||'Заявка создана','success'); setTimeout(function(){location.href='lead.php?id='+r.lead_id;},700); }
  else ZR.toast((r&&r.error)||'Ошибка','error');
}); }
async function toEngineer(id,btn){ await withBtn(btn, async function(){
  const r=await ZR.apiPost(API,{action:'to_engineer',id:id});
  if(r&&r.ok){ ZR.toast(r.note||'Передано инженеру','success'); setTimeout(function(){location.href='engineer.php';},800); }
  else ZR.toast((r&&r.error)||'Ошибка','error');
}); }

/* ---------- Черновики: отправить как есть / открыть в компоновщике ---------- */
async function sendDraft(id,btn){ await withBtn(btn, async function(){
  const r=await ZR.apiPost(API,{action:'send_draft',id:id});
  if(r&&r.ok){
    ZR.toast(r.note||(r.sent?'Письмо отправлено':'Осталось в «Черновиках»'), r.sent?'success':'info');
    if(r.sent){ CURID=0; document.getElementById('mlRead').innerHTML='<div class="ml-empty">Выберите письмо слева</div>'; }
    load();
  } else ZR.toast((r&&r.error)||'Ошибка','error');
}); }
async function editDraft(id,btn){ await withBtn(btn, async function(){
  const r=await ZR.apiGet(API,{action:'get',id:id});
  if(!r||!r.ok||!r.message){ ZR.toast((r&&r.error)||'Не удалось открыть черновик','error'); return; }
  openCompose({to:r.message.contact||'', subject:r.message.subject||'', body:r.message.body||'', draft_id:id});
}); }

/* ---------- Ручной приём почты из IMAP ---------- */
async function pollMail(){
  const btn=document.getElementById('pollBtn'); const old=btn.textContent;
  btn.disabled=true; btn.textContent='Проверяю…';
  try{
    const r=await ZR.apiPost(API,{action:'poll'});
    if(r&&r.ok){
      const i=(r.inbox&&r.inbox.saved)||0, o=(r.sent&&r.sent.saved)||0, nl=(r.inbox&&r.inbox.leads_new)||0;
      const parts=[]; if(i) parts.push('входящих: '+i); if(nl) parts.push('новых заявок: '+nl); if(o) parts.push('ответов менеджеров: '+o);
      ZR.toast(parts.length?('Почта: '+parts.join(', ')):'Новых писем нет','success');
      if(i||o) load();
    } else {
      ZR.toast((r&&r.error)||'Почта недоступна','error');
    }
  }catch(e){ ZR.toast('Ошибка сети','error'); }
  finally{ btn.disabled=false; btn.textContent=old; }
}

/* ---------- Компоновщик нового письма ---------- */
function openCompose(pre){
  DRAFT_ID = (pre && pre.draft_id) ? pre.draft_id : 0;
  document.getElementById('cmpTo').value    = pre ? (pre.to||'')      : '';
  document.getElementById('cmpSubj').value  = pre ? (pre.subject||'') : '';
  document.getElementById('cmpBody').value  = pre ? (pre.body||'')    : '';
  document.getElementById('composeBack').hidden=false;
  setTimeout(function(){document.getElementById(DRAFT_ID?'cmpBody':'cmpTo').focus();},50);
}
function closeCompose(){ DRAFT_ID=0; document.getElementById('composeBack').hidden=true; ['cmpTo','cmpSubj','cmpBody'].forEach(function(id){document.getElementById(id).value='';}); }
async function sendCompose(){
  const to=document.getElementById('cmpTo').value.trim();
  const subject=document.getElementById('cmpSubj').value.trim();
  const body=document.getElementById('cmpBody').value.trim();
  if(!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(to)){ ZR.toast('Укажите корректный email','error'); return; }
  if(!body){ ZR.toast('Пустое письмо','error'); return; }
  const btn=document.getElementById('cmpSend'); const old=btn.textContent; btn.disabled=true; btn.textContent='Отправляю…';
  try{
    const r=await ZR.apiPost(API,{action:'compose',to:to,subject:subject,body:body,draft_id:DRAFT_ID||''});
    // письмо сохранено в любом случае — модалку закрываем, чтобы повторные клики не плодили черновики
    if(r&&r.ok){ ZR.toast(r.note||(r.sent?'Письмо отправлено':'Сохранено в «Черновиках»'), r.sent?'success':'info'); closeCompose(); load(); }
    else ZR.toast((r&&r.error)||'Ошибка','error');
  }catch(e){ ZR.toast('Ошибка сети','error'); }
  finally{ btn.disabled=false; btn.textContent=old; }
}
document.addEventListener('keydown',function(e){ if(e.key==='Escape') closeCompose(); });
document.getElementById('composeBack').addEventListener('click',function(e){ if(e.target===this) closeCompose(); });
async function toggleStar(id,v,btn){ await withBtn(btn, async function(){
  const r=await ZR.apiPost(API,{action:'flag',id:id,field:'star',value:v});
  if(r&&r.ok){ const s=document.getElementById('mlStar'); if(s){s.classList.toggle('on',!!v); s.title=v?'Снять пометку':'Пометить'; s.setAttribute('onclick','toggleStar('+id+','+(v?0:1)+',this)');} load(); }
  else ZR.toast((r&&r.error)||'Ошибка','error');
}); }
const FOLDER_NAME={inbox:'Входящие',spam:'Спам',trash:'Корзина'};
async function moveTo(id,f,btn){ await withBtn(btn, async function(){
  const r=await ZR.apiPost(API,{action:'flag',id:id,field:'folder',value:f});
  if(r&&r.ok){ ZR.toast('Перемещено в «'+(FOLDER_NAME[f]||f)+'»','success'); CURID=0; document.getElementById('mlRead').innerHTML='<div class="ml-empty">Выберите письмо слева</div>'; load(); }
  else ZR.toast((r&&r.error)||'Ошибка','error');
}); }

function setFolder(f){ FOLDER=f; FILTER=''; load(); }
function setFilter(f){ FILTER=(FILTER===f?'':f); load(); }
let _t; document.getElementById('mlq').addEventListener('input', function(){ clearTimeout(_t); _t=setTimeout(load,250); });
load();
</script>
<?php render_foot();
