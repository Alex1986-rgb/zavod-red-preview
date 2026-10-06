<?php
declare(strict_types=1);

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_layout.php';

render_head('База знаний');
render_sidebar('kb');
?>
<style>
.kb-search{position:relative}
.kb-search input{width:100%;padding:15px 16px 15px 46px;border:1px solid var(--line);border-radius:var(--r-lg,14px);background:var(--card);color:var(--text);font-size:15px;box-shadow:var(--shadow-sm);transition:border-color .14s,box-shadow .14s}
.kb-search input:focus{outline:none;border-color:var(--red);box-shadow:0 0 0 3px var(--red-soft,rgba(225,27,27,.16))}
.kb-search svg{position:absolute;left:16px;top:50%;transform:translateY(-50%);width:20px;height:20px;color:var(--muted)}
.kb-search kbd{position:absolute;right:14px;top:50%;transform:translateY(-50%);font:600 11px/1 -apple-system,sans-serif;color:var(--muted);background:var(--bg);border:1px solid var(--line);border-radius:6px;padding:5px 8px}
.kb-cols{display:grid;grid-template-columns:1fr 320px;gap:20px;align-items:start}
@media(max-width:1000px){.kb-cols{grid-template-columns:1fr}}
.kb-cats{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px}
.kb-cat{display:flex;gap:13px;align-items:center;padding:16px;background:var(--card);border:1px solid var(--line);border-radius:var(--r-lg,14px);box-shadow:var(--shadow-sm);cursor:pointer;transition:.14s;text-align:left}
.kb-cat:hover{transform:translateY(-2px);border-color:rgba(225,27,27,.28);box-shadow:var(--shadow-md)}
.kb-cat.is-active{border-color:var(--red);box-shadow:0 0 0 2px var(--red-soft)}
.kb-cat__ico{width:46px;height:46px;border-radius:var(--r-md,10px);display:grid;place-items:center;flex:0 0 auto}
.kb-cat__ico svg{width:22px;height:22px}
.kb-cat__t{font-weight:700;color:var(--ink);font-size:14.5px;line-height:1.25}
.kb-cat__n{font-size:20px;font-weight:800;color:var(--ink);line-height:1.1}
.kb-cat__s{font-size:12px;color:var(--muted)}
/* шапка карточки: заголовок .card__title слева, ссылка/пометка справа; по базовой линии —
   у .card h2 общий margin-bottom:6px !important, при center текст съезжал бы на 3 px */
.card__hd{display:flex;justify-content:space-between;align-items:baseline;gap:10px;flex-wrap:wrap;margin-bottom:8px}
.kb-sm{font-size:13px}
/* таблица статей = .tbl; здесь только перенос длинных колонок и фокус с клавиатуры */
.kb-table td:first-child,.kb-table td:nth-child(3){white-space:normal}
.kb-table tbody tr:focus-visible{outline:2px solid var(--red);outline-offset:-2px;background:var(--bg)}
#kbList.is-busy{opacity:.45;pointer-events:none;transition:opacity .12s}
.kb-title{font-weight:600;color:var(--ink)}
.kb-tag{display:inline-block;background:var(--bg);border:1px solid var(--line);color:var(--muted);font-size:11.5px;padding:3px 8px;border-radius:6px;margin:0 4px 4px 0}
.kb-catbadge{display:inline-block;font-size:12px;padding:3px 9px;border-radius:6px;font-weight:600}
.kb-pop{display:flex;gap:11px;align-items:flex-start;padding:9px 0;border-bottom:1px solid var(--line)}
.kb-pop:last-child{border-bottom:0}
.kb-pop__i{width:22px;height:22px;border-radius:6px;background:var(--red-soft);color:var(--red);font-weight:800;font-size:12px;display:grid;place-items:center;flex:0 0 auto}
.kb-pop a{color:var(--ink);font-weight:600;font-size:13.5px;display:block;cursor:pointer}
.kb-pop small{color:var(--muted);font-size:12px}
.kb-upd{display:flex;gap:10px;padding:8px 0;border-bottom:1px solid var(--line);font-size:13px}
.kb-upd:last-child{border-bottom:0}
.kb-upd time{color:var(--muted);flex:0 0 84px;font-size:12px}
.kb-upd a{color:var(--ink);cursor:pointer}
/* пример аналога */
.kb-ex{display:grid;grid-template-columns:1fr 1fr auto;gap:14px;align-items:stretch}
@media(max-width:760px){.kb-ex{grid-template-columns:1fr}}
.kb-ex table{width:100%;border-collapse:collapse;font-size:13px}
.kb-ex th{text-align:left;color:var(--muted);font-weight:600;padding:6px 8px;background:var(--bg);border-radius:6px}
.kb-ex td{padding:6px 8px;border-bottom:1px solid var(--line)}
.kb-ex__ok{background:#ecfdf5;border:1px solid #a7f3d0;border-radius:var(--r-md,10px);padding:16px;display:flex;flex-direction:column;justify-content:center;text-align:center;min-width:200px}
.kb-ex__ok .ic{width:40px;height:40px;border-radius:50%;background:#10b981;color:#fff;display:grid;place-items:center;margin:0 auto 8px}
/* drawer */
.kb-drawer{position:fixed;inset:0;background:rgba(15,23,42,.45);display:none;z-index:60}
.kb-drawer.open{display:block}
.kb-drawer__panel{position:absolute;top:0;right:0;bottom:0;width:min(560px,100%);background:var(--card);box-shadow:-8px 0 30px rgba(0,0,0,.2);overflow-y:auto;padding:18px 22px}
.kb-drawer__x{position:absolute;top:18px;right:20px;font-size:22px;color:var(--muted);cursor:pointer;background:none;border:0;transition:color .14s}
.kb-drawer__x:hover{color:var(--red)}
.kb-drawer h2{font-size:20px;margin:0 40px 6px 0}
.kb-meta{color:var(--muted);font-size:13px;margin-bottom:16px}
.kb-body{font-size:14.5px;line-height:1.65;color:var(--text)}
.kb-body h3{font-size:16px;margin:18px 0 8px}
.kb-body ul{padding-left:20px}
.kb-body table{border-collapse:collapse;width:100%;margin:10px 0}
.kb-body td,.kb-body th{border:1px solid var(--line);padding:7px 10px;font-size:13.5px}
/* поля редактора = .field + .field__label + .input; у .input фиксированная высота, поэтому textarea — своя.
   Шаг между полями — сверху: у .field общий margin-bottom:0 !important */
.kb-field + div{margin-top:14px}
.kb-field textarea{width:100%;min-height:220px;padding:10px 12px;border:1px solid var(--line);border-radius:8px;background:var(--card);color:var(--text);font:13px/1.5 ui-monospace,Menlo,monospace;resize:vertical;transition:border-color .14s,box-shadow .14s}
</style>

<div class="page-head">
  <div>
    <h1 class="page-title">База знаний</h1>
    <p class="page-lead">Инженерная база: аналоги, инструкции, шильдики, шаблоны и ответы на частые вопросы.</p>
  </div>
  <div class="page-head__actions"><button class="btn btn--primary" onclick="kbEdit(0)">+ Новая статья</button></div>
</div>

<div class="kb-search">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
  <input id="kbq" type="search" placeholder="Поиск по базе знаний, моделям, аналогам…" autocomplete="off">
  <kbd>Ctrl + K</kbd>
</div>

<div id="kbCats" class="kb-cats"></div>

<div class="kb-cols">
  <div>
    <div class="card">
      <div class="card__hd"><h2 class="card__title" id="kbListTitle">Последние статьи</h2><a href="#" class="muted kb-sm" onclick="kbClearCat();return false">Сбросить фильтр</a></div>
      <div id="kbList"></div>
    </div>

    <div class="card">
      <div class="card__hd"><h2 class="card__title">Пример подбора аналога</h2><span class="muted kb-sm">Lenze → наш аналог</span></div>
      <div class="kb-ex">
        <table><tr><th>Оригинал (Lenze)</th></tr>
          <tr><td>Модель — <b>G500-B142</b></td></tr>
          <tr><td>Тип — цилиндрический</td></tr>
          <tr><td>Передаточное — i = 23,64</td></tr>
          <tr><td>Момент — 520 Н·м</td></tr>
          <tr><td>Присоединение — IEC 90L</td></tr>
        </table>
        <table><tr><th>Наш аналог</th></tr>
          <tr><td>Модель — <b>3РЦ-160-23,64-У3</b></td></tr>
          <tr><td>Тип — цилиндрический</td></tr>
          <tr><td>Передаточное — i = 23,64</td></tr>
          <tr><td>Момент — 520 Н·м</td></tr>
          <tr><td>Присоединение — IEC 90L</td></tr>
        </table>
        <div class="kb-ex__ok">
          <div class="ic"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6L9 17l-5-5"/></svg></div>
          <b>Аналог подобран</b>
          <small class="muted" style="margin-top:5px">Полная взаимозаменяемость по присоединительным размерам и характеристикам.</small>
        </div>
      </div>
    </div>
  </div>

  <div class="kb-side">
    <div class="card">
      <h2 class="card__title">🔥 Популярные статьи</h2>
      <div id="kbPopular"></div>
    </div>
    <div class="card">
      <h2 class="card__title">🕐 Недавно обновлено</h2>
      <div id="kbUpdated"></div>
    </div>
  </div>
</div>

<!-- drawer -->
<div id="kbDrawer" class="kb-drawer" onclick="if(event.target===this)kbClose()">
  <div class="kb-drawer__panel">
    <button class="kb-drawer__x" onclick="kbClose()">×</button>
    <div id="kbView"></div>
  </div>
</div>

<script>
const API = '../api/kb.php';
/* Фолбэк-словарь категорий = kb_categories() в api/kb.php.
   Нужен, чтобы <select id="fCat"> не был пустым до/вместо ответа сервера. */
const CATS_FALLBACK = {
  'analog-sew':        'Аналоги SEW',
  'analog-nord':       'Аналоги NORD',
  'analog-bonfiglioli':'Аналоги Bonfiglioli',
  'nameplates':        'Шильдики и расшифровка',
  'faq':               'Частые вопросы',
  'kp':                'Шаблоны КП',
  'guides':            'Инструкции инженеру',
};
let CATS = Object.assign({}, CATS_FALLBACK), CURCAT = '';
const CAT_ICON = {
  'analog-sew':        ['#fde7e7','#e11b1b','<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/>'],
  'analog-nord':       ['#e0edff','#3b82f6','<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/>'],
  'analog-bonfiglioli':['#ede9fe','#8b5cf6','<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/>'],
  'nameplates':        ['#d1fae5','#10b981','<rect x="3" y="6" width="18" height="12" rx="2"/><path d="M7 10h6M7 14h4"/>'],
  'faq':               ['#fef3c7','#f59e0b','<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>'],
  'kp':                ['#ede9fe','#8b5cf6','<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/>'],
  'guides':            ['#ccfbf1','#14b8a6','<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>'],
};
function catBadge(slug){const c=CAT_ICON[slug]||['var(--bg)','var(--muted)',''];return '<span class="kb-catbadge" style="background:'+c[0]+';color:'+c[1]+'">'+ZR.escapeHtml(CATS[slug]||slug)+'</span>';}

let _seq = 0;
async function load(){
  const my = ++_seq;
  const list = document.getElementById('kbList');
  list.classList.add('is-busy');
  let r = null;
  try {
    r = await ZR.apiGet(API, {action:'list', category:CURCAT, q:document.getElementById('kbq').value.trim(), sort:'recent'});
  } catch(e) {
    r = null;
  } finally {
    if(my === _seq) list.classList.remove('is-busy');
  }
  if(my !== _seq) return; // ответ устаревшего запроса — не перетираем свежий результат
  if(r && r.categories) CATS = r.categories;
  if(!r || !r.ok){
    const stub = '<div class="muted kb-sm">Ошибка загрузки</div>';
    list.innerHTML = '<div class="empty">Ошибка загрузки</div>';
    document.getElementById('kbCats').innerHTML = stub;
    document.getElementById('kbPopular').innerHTML = stub;
    document.getElementById('kbUpdated').innerHTML = stub;
    ZR.toast('Не удалось загрузить базу знаний','error');
    return;
  }
  renderCats(r.counts);
  renderList(r.articles);
  renderSide('kbPopular', r.popular, true);
  renderSide('kbUpdated', r.updated, false);
  document.getElementById('kbListTitle').textContent = CURCAT ? CATS[CURCAT] : (document.getElementById('kbq').value.trim() ? 'Результаты поиска' : 'Последние статьи');
}
function renderCats(counts){
  const box = document.getElementById('kbCats'); let h='';
  Object.keys(CATS).forEach(function(slug){
    const c = CAT_ICON[slug]||['var(--bg)','var(--muted)',''];
    h += '<button class="kb-cat'+(slug===CURCAT?' is-active':'')+'" onclick="kbSetCat(\''+slug+'\')">'
      + '<span class="kb-cat__ico" style="background:'+c[0]+';color:'+c[1]+'"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">'+c[2]+'</svg></span>'
      + '<span><span class="kb-cat__t">'+ZR.escapeHtml(CATS[slug])+'</span><br><span class="kb-cat__n">'+(counts[slug]||0)+'</span> <span class="kb-cat__s">статей</span></span>'
      + '</button>';
  });
  box.innerHTML = h;
}
function renderList(rows){
  const box = document.getElementById('kbList');
  if(!rows.length){ box.innerHTML='<div class="empty">Пока нет статей. Нажмите «+ Новая статья».</div>'; return; }
  let h = '<div class="table-wrap" style="border:0;box-shadow:none"><table class="tbl kb-table"><thead><tr><th>Статья</th><th>Категория</th><th>Теги</th><th>Обновлено</th><th>Автор</th><th class="r">Просмотры</th></tr></thead><tbody>';
  rows.forEach(function(a){
    const tags = (a.tags||'').split(',').map(function(t){t=t.trim();return t?'<span class="kb-tag">'+ZR.escapeHtml(t)+'</span>':'';}).join('');
    h += '<tr tabindex="0" role="link" onclick="kbOpen('+a.id+')" onkeydown="if(event.key===\'Enter\'){event.preventDefault();kbOpen('+a.id+')}">'
      + '<td><span class="kb-title">'+ZR.escapeHtml(a.title)+'</span><br><small class="muted">'+ZR.escapeHtml(a.excerpt||'')+'</small></td>'
      + '<td>'+catBadge(a.category)+'</td>'
      + '<td>'+(tags||'<span class="muted">—</span>')+'</td>'
      + '<td>'+ZR.dateRu(a.updated_at)+'</td>'
      + '<td>'+ZR.escapeHtml(a.author||'—')+'</td>'
      + '<td class="num">'+ZR.num(a.views)+'</td>'
      + '</tr>';
  });
  box.innerHTML = h + '</tbody></table></div>';
}
function renderSide(id, rows, pop){
  const box = document.getElementById(id);
  if(!rows.length){ box.innerHTML='<div class="muted kb-sm">Нет данных</div>'; return; }
  let h='';
  rows.forEach(function(a,i){
    const href = '?id='+a.id, click = 'kbOpen('+a.id+');return false';
    if(pop) h += '<div class="kb-pop"><span class="kb-pop__i">'+(i+1)+'</span><div><a href="'+href+'" onclick="'+click+'">'+ZR.escapeHtml(a.title)+'</a><small>'+ZR.num(a.views)+' просмотров</small></div></div>';
    else    h += '<div class="kb-upd"><time>'+ZR.dateRu(a.updated_at)+'</time><a href="'+href+'" onclick="'+click+'">'+ZR.escapeHtml(a.title)+'</a></div>';
  });
  box.innerHTML = h;
}
function kbSetCat(s){ CURCAT = (CURCAT===s?'':s); load(); }
function kbClearCat(){ CURCAT=''; document.getElementById('kbq').value=''; load(); }

async function kbOpen(id){
  let r = null;
  try { r = await ZR.apiGet(API, {action:'get', id:id}); }
  catch(e){ ZR.toast('Нет связи с сервером','error'); return; }
  if(!r || !r.ok){ ZR.toast((r&&r.error)||'Не удалось открыть', 'error'); return; }
  const a = r.article;
  const tags = (a.tags||'').split(',').map(function(t){t=t.trim();return t?'<span class="kb-tag">'+ZR.escapeHtml(t)+'</span>':'';}).join('');
  document.getElementById('kbView').innerHTML =
      catBadge(a.category)
    + '<h2 style="margin-top:10px">'+ZR.escapeHtml(a.title)+'</h2>'
    + '<div class="kb-meta">'+ZR.escapeHtml(a.author||'—')+' · обновлено '+ZR.dateRu(a.updated_at)+' · '+ZR.num(a.views)+' просмотров</div>'
    + '<div style="margin-bottom:14px">'+tags+'</div>'
    + '<div class="kb-body">'+(a.body||'<p class="muted">Без содержимого.</p>')+'</div>'
    + '<div style="margin-top:22px;display:flex;gap:10px"><button class="btn btn--ghost" onclick="kbEdit('+a.id+')">Редактировать</button><button class="btn btn--danger" onclick="kbDel('+a.id+')">Удалить</button></div>';
  openDrawer();
}
async function kbEdit(id){
  let a = {id:0, category:'faq', title:'', excerpt:'', body:'', tags:''};
  if(id){
    let r = null;
    try { r = await ZR.apiGet(API,{action:'get',id:id}); }
    catch(e){ ZR.toast('Нет связи с сервером','error'); return; }
    if(!r || !r.ok){ ZR.toast((r&&r.error)||'Не удалось открыть статью','error'); return; }
    a = r.article;
  }
  let opts=''; Object.keys(CATS).forEach(function(s){opts+='<option value="'+s+'"'+(s===a.category?' selected':'')+'>'+ZR.escapeHtml(CATS[s])+'</option>';});
  document.getElementById('kbView').innerHTML =
      '<h2>'+(id?'Редактирование статьи':'Новая статья')+'</h2><div style="height:12px"></div>'
    + '<div class="field kb-field"><label class="field__label">Заголовок</label><input class="input" id="fTitle" value="'+ZR.escapeHtml(a.title)+'"></div>'
    + '<div class="field kb-field"><label class="field__label">Категория</label><select class="input" id="fCat">'+opts+'</select></div>'
    + '<div class="field kb-field"><label class="field__label">Теги (через запятую)</label><input class="input" id="fTags" value="'+ZR.escapeHtml(a.tags)+'" placeholder="SEW, R37, подбор"></div>'
    + '<div class="field kb-field"><label class="field__label">Краткое описание</label><input class="input" id="fExc" value="'+ZR.escapeHtml(a.excerpt)+'" placeholder="1 строка для списка"></div>'
    + '<div class="field kb-field"><label class="field__label">Содержимое (HTML: h3, p, ul, table)</label><textarea id="fBody">'+ZR.escapeHtml(a.body||'')+'</textarea></div>'
    + '<div style="display:flex;gap:10px"><button class="btn btn--primary" onclick="kbSave('+a.id+')">Сохранить</button><button class="btn btn--ghost" onclick="kbClose()">Отмена</button></div>';
  openDrawer();
}
async function kbSave(id){
  const data = {action:'save', id:id,
    title:document.getElementById('fTitle').value.trim(),
    category:document.getElementById('fCat').value,
    tags:document.getElementById('fTags').value.trim(),
    excerpt:document.getElementById('fExc').value.trim(),
    body:document.getElementById('fBody').value};
  if(!data.title){ ZR.toast('Введите заголовок','error'); return; }
  const btn=document.querySelector('#kbDrawer .btn--primary,#kbDrawer .btn-primary'); const old=btn?btn.textContent:''; if(btn){btn.disabled=true;btn.textContent='Сохранение…';}
  try{
    const r = await ZR.apiPost(API, data);
    if(!r || !r.ok){ ZR.toast((r&&r.error)||'Не сохранено','error'); return; }
    ZR.toast('Статья сохранена','success'); kbClose(); load();
  } catch(e){ ZR.toast('Нет связи с сервером','error'); }
  finally { if(btn){btn.disabled=false;btn.textContent=old;} }
}
async function kbDel(id){
  if(!confirm('Удалить статью?')) return;
  const btn=document.querySelector('#kbDrawer .btn--danger'); const old=btn?btn.textContent:'';
  if(btn){btn.disabled=true;btn.textContent='Удаление…';}
  try{
    const r = await ZR.apiPost(API, {action:'del', id:id});
    if(!r || !r.ok){ ZR.toast((r&&r.error)||'Не удалено','error'); return; }
    ZR.toast('Удалено','success'); kbClose(); load();
  } catch(e){ ZR.toast('Нет связи с сервером','error'); }
  finally { if(btn){btn.disabled=false;btn.textContent=old;} }
}
function openDrawer(){ document.getElementById('kbDrawer').classList.add('open'); }
function kbClose(){ document.getElementById('kbDrawer').classList.remove('open'); }

let _t; document.getElementById('kbq').addEventListener('input', function(){ clearTimeout(_t); _t=setTimeout(load, 250); });
/* e.code — физическая клавиша: работает и в русской раскладке, и с CapsLock/Shift */
document.addEventListener('keydown', function(e){ if((e.ctrlKey||e.metaKey)&&e.code==='KeyK'){ e.preventDefault(); document.getElementById('kbq').focus(); } if(e.key==='Escape') kbClose(); });
load();
/* Ctrl+клик по ссылке в списках открывает kb.php?id=N — сразу показываем статью */
const _openId = parseInt(new URLSearchParams(location.search).get('id') || '', 10);
if(_openId > 0) kbOpen(_openId);
</script>
<?php render_foot();
