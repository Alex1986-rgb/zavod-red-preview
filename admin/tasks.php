<?php
declare(strict_types=1);

require __DIR__ . '/_guard.php';
require __DIR__ . '/_layout.php';

$managers = [];
try { $managers = pdo()->query("SELECT id, name, login FROM crm_users WHERE active=1 ORDER BY name")->fetchAll(); } catch (Throwable $e) {}

render_head('Задачи');
render_sidebar('tasks');
?>
<style>
/* плитки .tk-stats/.tstat целиком задаёт слой ПОЛКИ в admin.css; здесь только то, чего там нет */
.tstat__ic{display:grid;place-items:center;flex:none}
.tstat__v{color:var(--ink)}
.tk-wrap{display:grid;grid-template-columns:1fr;gap:var(--shelf,12px)}
.tk-wrap.has-panel{grid-template-columns:1fr 340px}
@media(max-width:1000px){.tk-wrap.has-panel{grid-template-columns:1fr}}
.tk-board{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
.tk-board > .empty{grid-column:1/-1}
@media(max-width:900px){.tk-board{grid-template-columns:repeat(2,1fr)}}
@media(max-width:560px){.tk-board{grid-template-columns:1fr}}
.tcol{background:var(--bg);border:1px solid var(--line);border-radius:var(--r-lg,14px);padding:11px;min-height:140px}
.tcol.over{outline:2px dashed var(--red);outline-offset:-3px}
.tcol__hd{display:flex;justify-content:space-between;align-items:center;font-weight:700;font-size:13.5px;color:var(--ink);margin-bottom:10px}
.tcol__n{background:var(--card);border-radius:var(--r-pill,999px);padding:1px 9px;font-size:12px;color:var(--muted)}
.tcard{background:var(--card);border:1px solid var(--line);border-radius:var(--r-md,10px);padding:11px 12px;margin-bottom:9px;box-shadow:var(--shadow-sm);cursor:pointer;transition:.14s}
.tcard:hover{border-color:var(--line-strong);transform:translateY(-1px);box-shadow:var(--shadow-md)}
.tcard.drag{opacity:.4}
.tcard.sel{border-color:var(--red);box-shadow:0 0 0 2px var(--red-soft)}
.tcard__t{font-weight:600;font-size:13.5px;color:var(--ink);margin-bottom:7px;line-height:1.3}
.tcard__tags{display:flex;gap:5px;flex-wrap:wrap;margin-bottom:7px}
.pri{font-size:10.5px;font-weight:700;padding:2px 7px;border-radius:5px}
.pri--high{background:#fee2e2;color:#dc2626}.pri--normal{background:#fef3c7;color:#b45309}.pri--low{background:#e0edff;color:#2563eb}
.pri--muted{background:var(--line);color:var(--muted)}
.tcard__meta{display:flex;justify-content:space-between;font-size:11.5px;color:var(--muted)}
.tcard__due.over{color:#dc2626;font-weight:600}
/* «+ Новая задача» в колонке — .btn .btn--ghost, но пунктиром и во всю ширину */
.btn.tcol__add{width:100%;border-style:dashed;background:none}
/* панель */
.tpanel{background:var(--card);border:1px solid var(--line);border-radius:var(--r-lg,14px);box-shadow:var(--shadow-md);padding:18px 20px;position:sticky;top:16px;align-self:start}
.tpanel__x{float:right;font-size:20px;color:var(--muted);background:none;border:0;cursor:pointer;transition:color .14s}
.tpanel__x:hover{color:var(--red)}
.tpanel__tags{display:flex;gap:6px;margin-bottom:14px}
.tpanel__row{display:flex;justify-content:space-between;font-size:13px;padding:8px 0;border-bottom:1px solid var(--line)}
.tpanel__row span{color:var(--muted)}
.tpanel__desc{font-size:13.5px;line-height:1.6;color:var(--text);margin:12px 0;white-space:pre-wrap}
.tpanel__btns{display:flex;flex-direction:column;gap:8px;margin-top:14px}
/* кнопки панели — .btn дизайн-системы; синяя и зелёная — смысловые цвета статусов (в системе таких нет) */
.btn.tbtn--blue{background:#2563eb;border-color:#2563eb;color:#fff}
.btn.tbtn--blue:hover:not(:disabled){background:#1d4ed8;border-color:#1d4ed8;color:#fff}
.btn.tbtn--green{background:#10b981;border-color:#10b981;color:#fff}
.btn.tbtn--green:hover:not(:disabled){background:#059669;border-color:#059669;color:#fff}
/* модалка новой задачи */
.tk-modal{position:fixed;inset:0;background:rgba(15,23,42,.45);display:none;z-index:60;align-items:flex-start;justify-content:center;padding:60px 16px}
.tk-modal.open{display:flex}
.tk-modal__box{background:var(--card);border-radius:var(--r-lg,14px);padding:22px;width:100%;max-width:460px;box-shadow:var(--shadow-lg)}
.tk-modal label{display:block;font-size:12px;color:var(--muted);font-weight:600;margin:10px 0 4px}
/* .input снимает у select стрелку (appearance:none) — без неё список выглядит как текстовое поле; вернуть, пока в admin.css нет общего шеврона */
.tk-modal select.input{appearance:auto}
/* поля ввода и списки — .input; многострочное описание .input не подходит (у него фиксированная высота 36 px) */
.tk-modal textarea{width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid var(--line);border-radius:var(--r-md,10px);background:var(--card);color:var(--text);font:inherit;font-size:14px;resize:vertical;transition:border-color .14s,box-shadow .14s}
.tk-modal textarea:focus{outline:none;border-color:var(--red);box-shadow:0 0 0 3px var(--red-soft,rgba(225,27,27,.16))}
</style>

<div class="page-head">
  <h1 class="page-title">Задачи</h1>
  <button class="btn btn--primary" onclick="openNew('new')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><path d="M12 5v14M5 12h14"/></svg> Новая задача</button>
</div>

<section class="tk-stats">
  <div class="tstat"><div class="tstat__ic" style="background:#fee2e2;color:#dc2626">⚠️</div><div><div class="tstat__v" id="st-overdue">0</div><div class="tstat__l">Просрочено</div></div></div>
  <div class="tstat"><div class="tstat__ic" style="background:#fef3c7;color:#b45309">📅</div><div><div class="tstat__v" id="st-today">0</div><div class="tstat__l">Сегодня</div></div></div>
  <div class="tstat"><div class="tstat__ic" style="background:#e0edff;color:#2563eb">🗓</div><div><div class="tstat__v" id="st-week">0</div><div class="tstat__l">На этой неделе</div></div></div>
  <div class="tstat"><div class="tstat__ic" style="background:#d1fae5;color:#059669">✅</div><div><div class="tstat__v" id="st-done">0</div><div class="tstat__l">Выполнено</div></div></div>
</section>

<div class="tk-wrap" id="tkWrap">
  <div class="tk-board" id="tkBoard"><div class="empty">Загрузка…</div></div>
  <aside class="tpanel" id="tkPanel" style="display:none"></aside>
</div>

<div class="tk-modal" id="tkModal" onclick="if(event.target===this)closeNew()">
  <div class="tk-modal__box">
    <h2 class="card__title">Новая задача</h2>
    <label>Заголовок</label><input class="input" id="nTitle" placeholder="Что нужно сделать">
    <label>Описание</label><textarea id="nDescr" rows="3"></textarea>
    <div style="display:flex;gap:10px">
      <div style="flex:1"><label>Приоритет</label><select class="input" id="nPri"><option value="high">Высокий</option><option value="normal" selected>Средний</option><option value="low">Низкий</option></select></div>
      <div style="flex:1"><label>Срок</label><input class="input" type="date" id="nDue"></div>
    </div>
    <label>Ответственный</label>
    <select class="input" id="nUser"><option value="">—</option><?php foreach ($managers as $m): ?><option value="<?= (int)$m['id'] ?>"><?= htmlspecialchars($m['name'] ?: $m['login'], ENT_QUOTES) ?></option><?php endforeach; ?></select>
    <div style="display:flex;gap:9px;margin-top:16px">
      <button class="btn btn--primary" onclick="createTask()">Создать</button>
      <button class="btn btn--ghost" onclick="closeNew()">Отмена</button>
    </div>
  </div>
</div>

<script>
const API='../api/tasks.php';
let LABELS={}, CURID=0, NEW_STATUS='new';
function esc(s){const d=document.createElement('div');d.textContent=(s==null?'':String(s));return d.innerHTML;}
const PRI={high:'Высокий',normal:'Средний',low:'Низкий'};
function isOver(due){ return due && new Date(due.replace(' ','T')) < new Date(); }
function fmtDue(s){ if(!s)return '—'; const d=new Date(s.replace(' ','T')); return d.toLocaleDateString('ru-RU'); }

async function load(){
  const board=document.getElementById('tkBoard');
  let r=null;
  try{ r=await ZR.apiGet(API,{action:'board'}); }catch(e){ r=null; }
  if(!r||!r.ok){
    // Иначе при обрыве сети на доске навсегда залипает «Загрузка…».
    board.innerHTML='<div class="empty">Не удалось загрузить задачи. '
      +'<button class="btn btn--ghost" onclick="load()">Повторить</button></div>';
    ZR.toast((r&&r.error)||'Не удалось загрузить задачи','error');
    return;
  }
  LABELS=r.labels;
  document.getElementById('st-overdue').textContent=r.stats.overdue;
  document.getElementById('st-today').textContent=r.stats.today;
  document.getElementById('st-week').textContent=r.stats.week;
  document.getElementById('st-done').textContent=r.stats.done;
  let h='';
  Object.keys(LABELS).forEach(function(st){
    const list=r.columns[st]||[];
    h+='<div class="tcol" data-st="'+st+'" ondragover="dOver(event)" ondragleave="dLeave(event)" ondrop="dDrop(event)">'
      +'<div class="tcol__hd"><span>'+esc(LABELS[st])+'</span><span class="tcol__n">'+list.length+'</span></div>';
    list.forEach(function(t){
      const over=isOver(t.due_at)&&st!=='done';
      h+='<div class="tcard'+(t.id==CURID?' sel':'')+'" draggable="true" data-id="'+t.id+'" ondragstart="dStart(event)" ondragend="dEnd(event)" onclick="openTask('+t.id+')">'
        +'<div class="tcard__t">'+esc(t.title)+'</div>'
        +'<div class="tcard__tags"><span class="pri pri--'+(t.priority||'normal')+'">'+(PRI[t.priority]||'Средний')+'</span>'
        + (t.user_name?'<span class="pri pri--muted">'+esc(t.user_name)+'</span>':'')+'</div>'
        +'<div class="tcard__meta"><span>'+(t.lead_id?('#'+t.lead_id):'')+'</span><span class="tcard__due'+(over?' over':'')+'">'+fmtDue(t.due_at)+'</span></div>'
        +'</div>';
    });
    h+='<button class="btn btn--ghost tcol__add" onclick="openNew(\''+st+'\')">+ Новая задача</button></div>';
  });
  board.innerHTML=h;
}

async function openTask(id){
  let r=null;
  try{ r=await ZR.apiGet(API,{action:'get',id:id}); }catch(e){ r=null; }
  // Без тоста клик по карточке молча «ничего не делал» при ошибке/удалённой задаче.
  // Если открытая сейчас задача исчезла — закрываем панель, чтобы не висела устаревшая карточка.
  if(!r||!r.ok){ ZR.toast((r&&r.error)||'Не удалось открыть задачу','error'); if(id===CURID)closePanel(); else load(); return; }
  CURID=id;
  const t=r.task, p=document.getElementById('tkPanel');
  const lead=t.lead_id?'<a href="lead.php?id='+t.lead_id+'">Заявка #'+t.lead_id+(t.lead_name?' · '+esc(t.lead_name):'')+'</a>':'<span class="muted">—</span>';
  p.innerHTML='<button class="tpanel__x" onclick="closePanel()">×</button>'
    +'<h2 class="card__title">'+esc(t.title)+'</h2>'
    +'<div class="tpanel__tags"><span class="pri pri--'+(t.priority||'normal')+'">'+(PRI[t.priority]||'Средний')+'</span>'
    +'<span class="pri pri--muted">'+esc(LABELS[t.status]||t.status)+'</span></div>'
    +'<div class="tpanel__row"><span>Ответственный</span><b>'+(esc(t.user_name)||'—')+'</b></div>'
    +'<div class="tpanel__row"><span>Срок</span><b>'+fmtDue(t.due_at)+'</b></div>'
    +'<div class="tpanel__row"><span>Заявка</span>'+lead+'</div>'
    +(t.descr?'<div class="tpanel__desc">'+esc(t.descr)+'</div>':'<div class="tpanel__desc muted">Без описания</div>')
    +'<div class="tpanel__btns">'
    +  (t.status!=='in_progress'?'<button class="btn btn--primary tbtn" onclick="move('+id+',\'in_progress\')">Взять в работу</button>':'')
    +  (t.status!=='review'?'<button class="btn tbtn tbtn--blue" onclick="move('+id+',\'review\')">Отправить на проверку</button>':'')
    +  (t.status!=='done'?'<button class="btn tbtn tbtn--green" onclick="move('+id+',\'done\')">Завершить</button>':'')
    +  '<button class="btn btn--danger tbtn" onclick="delTask('+id+')">Удалить</button>'
    +'</div>';
  p.style.display='';
  document.getElementById('tkWrap').classList.add('has-panel');
  load();
}
function closePanel(){
  const p=document.getElementById('tkPanel');
  if(p.style.display==='none'){ CURID=0; return; } // Esc при закрытой панели не должен перезагружать доску
  CURID=0; p.style.display='none'; document.getElementById('tkWrap').classList.remove('has-panel'); load();
}

// Один запрос за раз: без этого двойной клик по «Завершить» или быстрый drag&drop
// шлют два POST подряд, а кнопки панели остаются активными.
let BUSY=false;
function panelBtns(dis){ document.querySelectorAll('#tkPanel .tbtn').forEach(function(b){ b.disabled=dis; }); }
async function move(id,status){
  if(BUSY)return; BUSY=true; panelBtns(true);
  let r=null;
  try{ r=await ZR.apiPost(API,{action:'move',id:id,status:status}); }catch(e){ r=null; }
  finally{ BUSY=false; panelBtns(false); }
  if(r&&r.ok){ ZR.toast('Статус: '+(LABELS[status]||status),'success'); if(CURID)openTask(id); else load(); }
  else { ZR.toast((r&&r.error)||'Не удалось сменить статус','error'); load(); }
}
async function delTask(id){
  if(BUSY||!confirm('Удалить задачу?'))return; BUSY=true; panelBtns(true);
  let r=null;
  try{ r=await ZR.apiPost(API,{action:'del',id:id}); }catch(e){ r=null; }
  finally{ BUSY=false; panelBtns(false); }
  if(r&&r.ok){ ZR.toast('Удалено','success'); closePanel(); }
  else { ZR.toast((r&&r.error)||'Ошибка удаления','error'); load(); }
}

// drag&drop
let dragId=null;
function dStart(e){dragId=e.target.dataset.id;e.target.classList.add('drag');}
function dEnd(e){e.target.classList.remove('drag');}
function dOver(e){e.preventDefault();e.currentTarget.classList.add('over');}
function dLeave(e){e.currentTarget.classList.remove('over');}
async function dDrop(e){e.preventDefault();const col=e.currentTarget;col.classList.remove('over');if(!dragId)return;const id=dragId;dragId=null;await move(id,col.dataset.st);}

// новая задача
// focus() сразу после снятия display:none срабатывает не во всех браузерах — ждём кадр отрисовки.
function openNew(status){ NEW_STATUS=status||'new'; document.getElementById('tkModal').classList.add('open'); requestAnimationFrame(function(){ document.getElementById('nTitle').focus(); }); }
function closeNew(){ document.getElementById('tkModal').classList.remove('open'); }
async function createTask(){
  const btn=document.querySelector('#tkModal .btn--primary,#tkModal .btn-primary');
  const title=document.getElementById('nTitle').value.trim();
  if(!title){ ZR.toast('Введите заголовок','error'); return; }
  if(btn){btn.disabled=true;}
  try{
    const r=await ZR.apiPost(API,{action:'create',title:title,descr:document.getElementById('nDescr').value,
      priority:document.getElementById('nPri').value,due_at:document.getElementById('nDue').value,status:NEW_STATUS,
      user_id:document.getElementById('nUser').value});
    if(r&&r.ok){ ZR.toast('Задача создана','success'); closeNew();
      document.getElementById('nTitle').value='';document.getElementById('nDescr').value='';document.getElementById('nDue').value='';document.getElementById('nUser').value='';
      document.getElementById('nPri').value='normal';
      load(); } else ZR.toast((r&&r.error)||'Ошибка','error');
  } catch(e){ ZR.toast('Сеть недоступна, задача не создана','error');
  } finally { if(btn){btn.disabled=false;} }
}
document.addEventListener('keydown',function(e){if(e.key==='Escape'){closeNew();closePanel();}});
load();
</script>
<?php render_foot();
