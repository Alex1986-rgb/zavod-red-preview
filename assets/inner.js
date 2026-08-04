/* Только светлая тема (переключатель темы убран) */
try{document.documentElement.setAttribute('data-theme','light');try{localStorage.setItem('zr_theme','light');}catch(_){}}catch(e){}

/* Мобильное меню */
(function(){
  var burger=document.querySelector('.burger'),header=document.querySelector('header');
  if(!burger||!header)return;
  burger.addEventListener('click',function(){
    var open=header.classList.toggle('open');
    burger.setAttribute('aria-expanded',open?'true':'false');
  });
  document.querySelectorAll('.menu .drop-toggle').forEach(function(t){
    // Доступность с клавиатуры: <a role=button> без href не попадал в tab-порядок и не
    // открывался с клавиатуры. Даём tabindex и обрабатываем Enter/Space (открыть) / Escape (закрыть).
    if(!t.hasAttribute('tabindex'))t.setAttribute('tabindex','0');
    t.addEventListener('keydown',function(e){
      if(e.key==='Enter'||e.key===' '){e.preventDefault();t.click();}
      else if(e.key==='Escape'){var d=t.closest('.dropdown');if(d){d.classList.remove('open');t.setAttribute('aria-expanded','false');}}
    });
    t.addEventListener('click',function(e){
      var dd=t.closest('.dropdown');
      // десктоп (есть ховер): клик по «О заводе» ведёт на страницу, сабменю раскрывается наведением.
      // мобилка (тач): клик раскрывает сабменю.
      if(window.matchMedia&&window.matchMedia('(hover:hover)').matches){
        var mainHref=t.getAttribute('href');
        if(!mainHref){var main=dd.querySelector('.submenu a[href$="/about"]')||dd.querySelector('.submenu a[href]');mainHref=main&&main.getAttribute('href');}
        if(mainHref){ location.href=mainHref; return; }
      }
      e.preventDefault();
      var open=dd.classList.toggle('open');
      t.setAttribute('aria-expanded',open?'true':'false');
    });
  });
  // A11y: у статичных .lead-form <label> не связан с полем (нет for/id) — даём полю
  // доступное имя из текста подписи, чтобы скринридер не читал поля безымянными.
  document.querySelectorAll('.lead-form .field').forEach(function(fl){
    var lb=fl.querySelector('label'),ip=fl.querySelector('input,textarea,select');
    if(lb&&ip&&!ip.getAttribute('aria-label'))ip.setAttribute('aria-label',lb.textContent.trim());
  });
  document.querySelectorAll('.menu a:not(.drop-toggle)').forEach(function(a){
    a.addEventListener('click',function(){header.classList.remove('open');burger.setAttribute('aria-expanded','false');document.querySelectorAll('.menu .dropdown.open').forEach(function(d){d.classList.remove('open');});});
  });
  document.addEventListener('click',function(e){
    if(!e.target.closest('.menu .dropdown'))document.querySelectorAll('.menu .dropdown.open').forEach(function(d){d.classList.remove('open');});
  });
})();

/* Маска телефона + форма-заявка (email + Telegram через /api/feedback.php) */
function wireLeadForm(formId, opts){
  opts=opts||{};
  var f=document.getElementById(formId);
  if(!f)return;
  var ph=f.querySelector('input[type=tel]');
  var name=f.querySelector('input[type=text]:not([name="work_email"])');
  var consent=f.querySelector('input[type=checkbox]');
  var res=f.querySelector('.form-result');
  var action=opts.action||((/\/(catalog|cases)\//.test(location.pathname)?'../':'')+'api/feedback.php');
  function show(msg,type){res.className='form-result show'+(type?' '+type:'');res.innerHTML=msg;}
  if(ph){
    ph.addEventListener('input',function(){
      var dg=this.value.replace(/\D/g,'');if(dg[0]==='7'||dg[0]==='8')dg=dg.slice(1);dg=dg.slice(0,10);var x=('7'+dg).match(/(\d{1})(\d{0,3})(\d{0,3})(\d{0,2})(\d{0,2})/);
      if(!x)return;
      if(x[1]!=='7'&&x[1]!=='8'&&x[1]!=='')x[2]=x[1]+(x[2]||'');
      x[1]='7';
      this.value=!x[2]?'+7 (':'+7 ('+x[2]+(x[3]?') '+x[3]:'')+(x[4]?'-'+x[4]:'')+(x[5]?'-'+x[5]:'');
    });
    ph.addEventListener('keydown',function(e){if(e.key==='Backspace'&&this.value.length<=4)e.preventDefault();});
    ph.addEventListener('focus',function(){if(this.value==='')this.value='+7 (';});
  }
  f.addEventListener('submit',async function(e){
    e.preventDefault();
    // Honeypot на клиенте не проверяем: спрятанное поле work_email автозаполняет
    // Яндекс.Браузер (имя содержит «email», а left:-9999px для автозаполнения —
    // обычное видимое поле), и отправка молча обрывалась. В запрос всё равно уходит
    // пустое значение; ловушка от ботов работает на сервере.
    if(name&&name.value.trim()===''){show('Укажите, как к вам обращаться.','err');return;}
    // По цифрам, а не по длине строки: автозаполненный номер приходит в своём формате.
    if(ph&&ph.value.replace(/\D/g,'').length<11){show('Введите телефон полностью: +7 (XXX) XXX-XX-XX.','err');return;}
    if(consent&&!consent.checked){show('Отметьте согласие на обработку персональных данных.','err');return;}
    var fd=new FormData();
    fd.append('work_email','');
    if(name)fd.append('text-562',name.value);
    if(ph)fd.append('tel-535',ph.value);
    fd.append('product_title',opts.title||('Заявка с сайта · '+document.title));
    show('Отправляем заявку…','');
    try{
      var r=await fetch(action,{method:'POST',body:fd});
      var d=await r.json();
      if(d.status==='success'){if(window.ym)ym(109758131,'reachGoal','zayavka');f.reset();show('Заявка принята. Инженер свяжется с вами и пришлёт коммерческое предложение.','ok');}
      else{show((d.message||'Не удалось отправить заявку.')+' Позвоните: +7 (495) 151-41-02.','err');}
    }catch(err){show('Сбой отправки. Позвоните нам: +7 (495) 151-41-02.','err');}
  });
}

/* Энхансер карточек (♥ в избранное + Подробнее) — подключается на всех страницах сайта */
(function(){try{if(window.__zrCardsEnh)return;var s=document.createElement('script');s.src='/assets/fav.js?v=2607c09d12d0';s.defer=true;(document.head||document.documentElement).appendChild(s);}catch(e){}})();

/* Карточка аналога: бренд импорта — крупно и жирно, наш аналог ZR — вторичной строкой.
   Работает на /analog/* (H1 вида «SEW F 107 — мотор-редуктор и российский аналог ZR 6106»). */
(function(){try{
  var h1=document.querySelector('h1.p2-h1');
  if(!h1||h1.querySelector('.p2-h1-imp'))return;
  var t=(h1.textContent||'').trim();
  var i=t.indexOf(' \u2014 ');
  if(i<1||!/\bZR\b/.test(t.slice(i)))return;
  var esc=function(s){return String(s).replace(/[&<>"]/g,function(c){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})};
  h1.innerHTML='<span class="p2-h1-imp">'+esc(t.slice(0,i))+'</span>'
              +'<span class="p2-h1-zr">'+esc(t.slice(i+3))+'</span>';
}catch(e){}})();

/* Лайтбокс для чертежей и фото: клик по главной картинке карточки или по
   плитке в каталоге чертежей открывает полноэкранный просмотр с зумом.
   Стили инжектятся отсюда — inner.js подключён на всех страницах, отдельный
   CSS-файл править не нужно. */
(function () {
  if (window.__zrLightbox) return;
  window.__zrLightbox = 1;
  var S = '.zrlb{position:fixed;inset:0;z-index:9999;background:rgba(10,14,18,.92);' +
    'display:none;align-items:center;justify-content:center;touch-action:none}' +
    '.zrlb.on{display:flex}' +
    '.zrlb img{position:relative;z-index:1;max-width:96vw;max-height:88vh;' +
    'transform-origin:center center;' +
    'transition:transform .12s ease-out;cursor:grab;background:#fff;user-select:none}' +
    '.zrlb img.drag{cursor:grabbing;transition:none}' +
    '.zrlb-bar{position:absolute;top:0;left:0;right:0;z-index:2;display:flex;gap:8px;' +
    'align-items:center;padding:10px 14px;color:#fff;font:600 13px/1.3 system-ui,sans-serif;' +
    'background:linear-gradient(rgba(0,0,0,.55),transparent)}' +
    '.zrlb-bar .t{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}' +
    '.zrlb-bar button,.zrlb-bar a{background:rgba(255,255,255,.14);color:#fff;border:0;' +
    'border-radius:8px;padding:7px 11px;font:600 13px/1 system-ui,sans-serif;cursor:pointer;' +
    'text-decoration:none}' +
    '.zrlb-bar button:hover,.zrlb-bar a:hover{background:rgba(255,255,255,.26)}' +
    '.zrlb-hint{position:absolute;bottom:12px;left:0;right:0;z-index:2;text-align:center;color:#9fb0bf;' +
    'font:500 12px system-ui,sans-serif}';
  var st = document.createElement('style'); st.textContent = S;
  document.head.appendChild(st);

  var box, img, title, dl, z = 1, ox = 0, oy = 0, dragging = false, sx = 0, sy = 0;

  function apply() {
    img.style.transform = 'translate(' + ox + 'px,' + oy + 'px) scale(' + z + ')';
  }
  function setZoom(nz) {
    z = Math.min(8, Math.max(1, nz));
    if (z === 1) { ox = 0; oy = 0; }
    apply();
  }
  function build() {
    box = document.createElement('div');
    box.className = 'zrlb';
    box.innerHTML = '<div class="zrlb-bar"><span class="t"></span>' +
      '<button data-a="out">&minus;</button><button data-a="in">+</button>' +
      '<button data-a="fit">1:1</button><a data-a="dl" download>Скачать</a>' +
      '<button data-a="close">&times;</button></div>' +
      '<img alt=""><div class="zrlb-hint">Колесо — масштаб · перетаскивание — сдвиг · Esc — закрыть</div>';
    document.body.appendChild(box);
    img = box.querySelector('img');
    title = box.querySelector('.t');
    dl = box.querySelector('[data-a="dl"]');

    box.addEventListener('click', function (e) {
      var a = e.target.getAttribute && e.target.getAttribute('data-a');
      if (a === 'in') { setZoom(z * 1.5); return; }
      if (a === 'out') { setZoom(z / 1.5); return; }
      if (a === 'fit') { setZoom(1); return; }
      if (a === 'dl') return;
      if (e.target === box || a === 'close') close();
    });
    box.addEventListener('wheel', function (e) {
      e.preventDefault();
      setZoom(z * (e.deltaY < 0 ? 1.18 : 1 / 1.18));
    }, { passive: false });
    img.addEventListener('pointerdown', function (e) {
      if (z <= 1) return;
      dragging = true; sx = e.clientX - ox; sy = e.clientY - oy;
      img.classList.add('drag'); img.setPointerCapture(e.pointerId);
    });
    img.addEventListener('pointermove', function (e) {
      if (!dragging) return;
      ox = e.clientX - sx; oy = e.clientY - sy; apply();
    });
    img.addEventListener('pointerup', function () {
      dragging = false; img.classList.remove('drag');
    });
    document.addEventListener('keydown', function (e) {
      if (!box.classList.contains('on')) return;
      if (e.key === 'Escape') close();
      if (e.key === '+' || e.key === '=') setZoom(z * 1.5);
      if (e.key === '-') setZoom(z / 1.5);
    });
  }
  function open(src, cap) {
    if (!box) build();
    img.src = src; img.alt = cap || '';
    title.textContent = cap || '';
    dl.href = src;
    z = 1; ox = 0; oy = 0; apply();
    box.classList.add('on');
    document.documentElement.style.overflow = 'hidden';
  }
  function close() {
    box.classList.remove('on');
    document.documentElement.style.overflow = '';
  }

  document.addEventListener('click', function (e) {
    var t = e.target.closest ? e.target.closest('[data-zoom],#p2img') : null;
    if (!t) return;
    var src = t.getAttribute('data-full') || t.getAttribute('src') ||
              t.getAttribute('href');
    if (!src) return;
    e.preventDefault();
    open(src, t.getAttribute('data-cap') || t.getAttribute('alt') || '');
  });
  // главная картинка карточки — показываем, что её можно увеличить
  document.addEventListener('DOMContentLoaded', function () {
    var m = document.getElementById('p2img');
    if (m) { m.style.cursor = 'zoom-in'; m.title = 'Нажмите, чтобы увеличить'; }
    // любые крупные картинки чертежей/иллюстраций делаем увеличиваемыми,
    // даже если у них не проставлен data-zoom в разметке
    var sel = '#chertezh img, .blog-hero-img, .p2-main img, figure img';
    document.querySelectorAll(sel).forEach(function (im) {
      if (im.hasAttribute('data-zoom')) return;
      if (im.naturalWidth && im.naturalWidth < 240) return;
      im.setAttribute('data-zoom', '');
      im.style.cursor = 'zoom-in';
    });
  });
})();
