/* Всплывающая форма-заявка (popup) — единая по всему сайту. Самодостаточный модуль. */
/* Глобальная страховка: форма поиска в шапке НИКОГДА не делает нативный submit —
   иначе автоцель Яндекс.Метрики «отправка формы» засчитывает поиск как заявку. Не
   зависит от энхансера (тот гейтится .nav-right); ловит любую form.msearch на всех стр. */
document.addEventListener('submit',function(e){var f=e.target;if(f&&f.classList&&f.classList.contains('msearch')){e.preventDefault();}},true);
(function(){
  // глубина страницы → пути к api и privacy
  var sub = /\/(catalog|cases|uslugi|brands|blog|analog|reduktor|ispolnenie|tiporazmer|glossary|otrasli)\//.test(location.pathname);
  var pfx = sub ? '../' : '';
  var ACTION = '/api/feedback.php';
  var PRIVACY = '/privacy.html';
  var MAXB = 10 * 1024 * 1024; // лимит файла 10 МБ

  var css = ''
   + '.zr-modal{position:fixed;inset:0;z-index:1000;display:flex;align-items:center;justify-content:center;padding:20px}'
   + '.zr-modal[hidden]{display:none}'
   + '.zr-modal__ov{position:absolute;inset:0;background:rgba(14,26,36,.55);backdrop-filter:blur(4px);animation:zrf .2s}'
   + '.zr-modal__box{position:relative;width:100%;max-width:460px;max-height:calc(100vh - 40px);overflow-y:auto;background:#fff;border:1px solid rgba(14,26,36,.14);border-radius:18px;padding:30px 28px;box-shadow:0 30px 80px rgba(14,26,36,.22);animation:zru .25s}'
   + '@keyframes zrf{from{opacity:0}}@keyframes zru{from{opacity:0;transform:translateY(16px)}}'
   + '.zr-modal__x{position:absolute;top:12px;right:14px;background:none;border:0;color:#66747e;font-size:30px;line-height:1;cursor:pointer;padding:4px 8px}'
   + '.zr-modal__x:hover{color:#0e1a24}'
   + '.zr-modal h3{color:#0e1a24;font-size:23px;font-weight:800;margin:0 0 6px;letter-spacing:-.01em}'
   + '.zr-modal p{color:#526069;font-size:14px;margin:0 0 18px;line-height:1.45}'
   + '.zr-in,.zr-sel{width:100%;background:#fff;border:1px solid rgba(14,26,36,.22);color:#0e1a24;padding:14px 16px;border-radius:10px;font-size:15px;font-family:inherit;margin-bottom:12px;box-sizing:border-box}'
   + '.zr-in::placeholder{color:#66747e;opacity:1}'
   + '.zr-sel{appearance:none;-webkit-appearance:none;cursor:pointer;background-image:url("data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'16\' height=\'16\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'%23e11b1b\' stroke-width=\'3\' stroke-linecap=\'round\' stroke-linejoin=\'round\'><path d=\'M6 9l6 6 6-6\'/></svg>");background-repeat:no-repeat;background-position:right 15px center;padding-right:44px}'
   + '.zr-sel:invalid{color:#66747e}'
   + '.zr-sel option{color:#0e1a24;background:#fff}'
   + '.zr-in:focus,.zr-sel:focus{outline:none;border-color:#cf1616;box-shadow:0 0 0 3px rgba(207,22,22,.12)}'
   + '.zr-file{margin-bottom:12px}'
   + '.zr-file label{display:flex;align-items:center;gap:10px;background:#f6f8fa;border:1px dashed rgba(14,26,36,.28);color:#526069;padding:13px 16px;border-radius:10px;font-size:13.5px;cursor:pointer;transition:.15s}'
   + '.zr-file label:hover{border-color:#cf1616;color:#0e1a24}'
   + '.zr-file input{position:absolute;left:-9999px}'
   + '.zr-file .zr-fico{font-size:17px}'
   + '.zr-file.has label{border-style:solid;border-color:#1a7f37;color:#1a7f37;background:#f2fbf4}'
   + '.zr-consent{display:block;position:relative;padding-left:26px;font-size:12.5px;color:#526069;line-height:1.45;margin:4px 0 16px}'
   + '.zr-consent input{position:absolute;left:0;top:1px;width:16px;height:16px;accent-color:#cf1616}'
   + '.zr-consent a{color:#1f5f8b;text-decoration:underline}'
   + '.zr-btn{width:100%;background:#cf1616;color:#fff;font-weight:700;font-size:16px;padding:15px;border:0;border-radius:10px;cursor:pointer;transition:.18s}'
   + '.zr-btn:hover{background:#b01212}'
   + '.zr-hp{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden}'
   + '.zr-res{margin-top:14px;padding:13px 15px;border-radius:10px;font-size:14px;font-weight:500;display:none}'
   + '.zr-res.show{display:block}'
   + '.zr-res.err{background:#fdeceb;border:1px solid #cf1616;color:#a51212}'
   + '.zr-res.ok{background:#f2fbf4;border:1px solid #1a7f37;color:#14682c}'
   + '.zr-res.busy{background:#f4f6f8;border:1px solid rgba(14,26,36,.18);color:#526069}'
   + '.zr-prog{height:8px;background:#eef2f6;border:1px solid rgba(14,26,36,.14);border-radius:6px;overflow:hidden;margin-top:9px}'
   + '.zr-prog i{display:block;height:100%;width:0;background:#cf1616;border-radius:6px;transition:width .15s ease}'
   + '.zr-btn[disabled]{opacity:.55;cursor:default}';
  var st=document.createElement('style');st.textContent=css;document.head.appendChild(st);

  var html=''
   + '<div class="zr-modal" id="zrModal" hidden>'
   + '<div class="zr-modal__ov" data-close></div>'
   + '<div class="zr-modal__box" role="dialog" aria-modal="true" aria-label="Оставить заявку">'
   + '<button class="zr-modal__x" data-close aria-label="Закрыть">&times;</button>'
   + '<h3>Получите расчёт стоимости</h3>'
   + '<p id="zrIntro">Заполните форму — инженер свяжется в течение 15 минут, рассчитает подбор и пришлёт коммерческое предложение.</p>'
   + '<div class="zr-res"></div>'
   + '<form id="zrModalForm">'
   + '<div class="zr-hp"><input type="text" name="work_email" tabindex="-1" autocomplete="off"></div>'
   + '<select class="zr-sel" id="zrType">'
   + '<option value="" selected>Какой редуктор нужен?</option>'
   + '<option>Червячный</option>'
   + '<option>Цилиндрический</option>'
   + '<option>Плоский цилиндрический</option>'
   + '<option>Соосно-цилиндрический</option>'
   + '<option>Цилиндро-конический</option>'
   + '<option>Вариатор</option>'
   + '<option>Мотор-редуктор / электродвигатель</option>'
   + '<option>Аналог импортного (SEW, NORD, Bonfiglioli…)</option>'
   + '<option>Другое / затрудняюсь ответить</option>'
   + '</select>'
   + '<input class="zr-in" type="text" id="zrName" placeholder="Ваше имя" required>'
   + '<input class="zr-in" type="tel" id="zrPhone" placeholder="+7 (___) ___-__-__" required pattern="\\+7 \\(\\d{3}\\) \\d{3}-\\d{2}-\\d{2}" title="Введите телефон полностью: +7 (XXX) XXX-XX-XX">'
   + '<input class="zr-in" type="email" id="zrEmail" placeholder="Почта (необязательно)">'
   + '<div class="zr-file" id="zrFileBox"><label for="zrFile"><span class="zr-fico">📎</span><span id="zrFileLbl">Фото шильда, чертёж или спецификация · JPG, PDF, до 10 МБ</span></label><input type="file" id="zrFile" name="file-174" accept="image/*,.jfif,.heic,.heif,.avif,.tif,.tiff,.pdf,.doc,.docx,.xls,.xlsx,.csv,.dwg,.dxf,.stp,.step,.zip,.rar"></div>'
   + '<textarea class="zr-in" id="zrMsg" rows="2" placeholder="Или опишите задачу: мощность, обороты, что заменяем"></textarea>'
   + '<label class="zr-consent"><input type="checkbox" id="zrConsent" required> Отправляя заявку, я даю согласие на обработку персональных данных в соответствии с <a href="'+PRIVACY+'" target="_blank" rel="noopener">политикой конфиденциальности</a>.</label>'
   + '<button class="zr-btn" type="submit">Получить расчёт</button>'
   + '</form></div></div>';
  var wrap=document.createElement('div');wrap.innerHTML=html;document.body.appendChild(wrap.firstChild);

  var modal=document.getElementById('zrModal');
  var form=document.getElementById('zrModalForm');
  var ph=document.getElementById('zrPhone');
  var fileInp=document.getElementById('zrFile');
  var res=modal.querySelector('.zr-res');
  function show(m,t){res.className='zr-res show'+(t?' '+t:'');res.innerHTML=m;var box=modal.querySelector('.zr-modal__box');if(box)box.scrollTop=0;}
  var _lastFocus=null;
  function open(e){if(e)e.preventDefault();_lastFocus=document.activeElement;res.className='zr-res';res.innerHTML='';form.style.display='';var i=document.getElementById('zrIntro');if(i)i.style.display='';modal.hidden=false;document.body.style.overflow='hidden';setTimeout(function(){document.getElementById('zrType').focus();},50);}
  function close(){modal.hidden=true;document.body.style.overflow='';if(_lastFocus&&_lastFocus.focus){try{_lastFocus.focus();}catch(e){}}}
  function trapTab(e){
    if(modal.hidden||e.key!=='Tab')return;
    var box=modal.querySelector('.zr-modal__box'); if(!box)return;
    var f=[].slice.call(box.querySelectorAll('a[href],button:not([disabled]),input:not([disabled]):not([type=hidden]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])')).filter(function(el){return el.offsetParent!==null;});
    if(!f.length)return;
    var first=f[0],last=f[f.length-1];
    if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus();}
    else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus();}
  }

  modal.addEventListener('click',function(e){if(e.target.hasAttribute('data-close'))close();});
  document.addEventListener('keydown',function(e){if(e.key==='Escape'&&!modal.hidden)close();else trapTab(e);});
  // триггеры: [data-zayavka] или ссылки на #zayavka
  document.addEventListener('click',function(e){
    var t=e.target.closest('[data-zayavka],a[href="#zayavka"],a[href$="#zayavka"]');
    if(t)open(e);
  });

  // имя файла на плашке
  fileInp.addEventListener('change',function(){
    var box=document.getElementById('zrFileBox');
    var lbl=document.getElementById('zrFileLbl');
    if(this.files&&this.files[0]){
      if(this.files[0].size>MAXB){this.value='';box.className='zr-file';lbl.textContent='Фото шильда, чертёж или спецификация · JPG, PDF, до 10 МБ';show('Файл больше 10 МБ. Сожмите его или прикрепите только нужный фрагмент — либо отправьте заявку без файла, мы запросим его в ответ.','err');return;}
      box.className='zr-file has';lbl.textContent=this.files[0].name;
    }
    else{box.className='zr-file';lbl.textContent='Фото шильда, чертёж или спецификация · JPG, PDF, до 10 МБ';}
  });

  ph.addEventListener('input',function(){
    var dg=this.value.replace(/\D/g,'');if(dg[0]==='7'||dg[0]==='8')dg=dg.slice(1);dg=dg.slice(0,10);var x=('7'+dg).match(/(\d{1})(\d{0,3})(\d{0,3})(\d{0,2})(\d{0,2})/);
    if(!x)return;
    if(x[1]!=='7'&&x[1]!=='8'&&x[1]!=='')x[2]=x[1]+(x[2]||'');
    x[1]='7';
    this.value=!x[2]?'+7 (':'+7 ('+x[2]+(x[3]?') '+x[3]:'')+(x[4]?'-'+x[4]:'')+(x[5]?'-'+x[5]:'');
  });
  ph.addEventListener('keydown',function(e){if(e.key==='Backspace'&&this.value.length<=4)e.preventDefault();});
  ph.addEventListener('focus',function(){if(this.value==='')this.value='+7 (';});

  form.addEventListener('submit',async function(e){
    e.preventDefault();
    if(form.__zrSending)return;                 // отправка уже идёт — второй submit игнорируем
    if(form.querySelector('input[name="work_email"]').value!=='')return;
    var type=document.getElementById('zrType').value;
    if(document.getElementById('zrName').value.trim()===''){show('Укажите, как к вам обращаться.','err');return;}
    if(ph.value.length<18){show('Введите телефон полностью: +7 (XXX) XXX-XX-XX.','err');return;}
    if(!document.getElementById('zrConsent').checked){show('Отметьте согласие на обработку персональных данных.','err');return;}
    if(fileInp.files&&fileInp.files[0]&&fileInp.files[0].size>MAXB){show('Файл больше 10 МБ. Сожмите его или отправьте заявку без файла — мы запросим его в ответ.','err');return;}
    var fd=new FormData();
    fd.append('work_email','');
    fd.append('text-562',document.getElementById('zrName').value);
    fd.append('tel-535',ph.value);
    var em=document.getElementById('zrEmail').value.trim();
    if(em!=='')fd.append('email-727',em);
    var msg=document.getElementById('zrMsg').value.trim();
    if(msg!=='')fd.append('textarea-725',msg);
    if(fileInp.files&&fileInp.files[0])fd.append('file-174',fileInp.files[0]);
    fd.append('product_title','Заявка (раскрывающаяся форма) · '+(type||'тип не указан')+' · '+document.title);
    // UTM / источник / referrer для CRM и ретаргетинга (first-touch в localStorage)
    try{
      var _p=new URLSearchParams(location.search);
      var _ks=['utm_source','utm_medium','utm_campaign','utm_term','utm_content','gclid','yclid'];
      var _st={};try{_st=JSON.parse(localStorage.getItem('zr_utm')||'{}');}catch(e){}
      var _has=false;_ks.forEach(function(k){var v=_p.get(k);if(v){_st[k]=v;_has=true;}});
      if(_has){try{localStorage.setItem('zr_utm',JSON.stringify(_st));}catch(e){}}
      _ks.forEach(function(k){fd.append(k,_p.get(k)||_st[k]||'');});
      fd.append('referrer',document.referrer||'');
      fd.append('page_url',location.href);
    }catch(e){}

    var hasFile=fileInp.files&&fileInp.files[0];
    var btn=form.querySelector('.zr-btn');
    function unlock(){form.__zrSending=0;btn.disabled=false;btn.textContent='Получить расчёт';}
    form.__zrSending=1;btn.disabled=true;btn.textContent='Отправляем…';
    show((hasFile?'Загружаем файл…':'Отправляем заявку…')+'<div class="zr-prog"><i id="zrBar"></i></div>','busy');

    var xhr=new XMLHttpRequest();
    xhr.open('POST',ACTION);
    /* Защита от «зависания навсегда»: если 30 с нет НИКАКОГО прогресса (мёртвая
       связь/стойкий обрыв) — прерываем и показываем понятную ошибку, а не крутим
       «Отправляем…» бесконечно. Легитимную медленную загрузку не режем — таймер
       сбрасывается на каждом событии прогресса (аплоад и ответ). */
    var _stall;
    function _bump(){ clearTimeout(_stall); _stall=setTimeout(function(){ try{xhr.abort();}catch(_e){} }, 30000); }
    xhr.onprogress=_bump;
    xhr.onabort=function(){ clearTimeout(_stall); unlock(); if(window.ym)ym(109758131,'reachGoal','zayavka_error'); show('Похоже, пропала связь — заявка не ушла. Позвоните: +7 (495) 151-41-02, или попробуйте ещё раз.','err'); };
    xhr.upload.onprogress=function(ev){
      _bump();
      if(!ev.lengthComputable)return;
      var pct=Math.round(ev.loaded/ev.total*100);
      var bar=document.getElementById('zrBar');if(bar)bar.style.width=pct+'%';
      if(pct>=100)show('Файл загружен, обрабатываем заявку…<div class="zr-prog"><i style="width:100%"></i></div>','busy');
    };
    xhr.onload=function(){
      clearTimeout(_stall);
      unlock();
      var d;try{d=JSON.parse(xhr.responseText);}catch(e){d={};}
      if(xhr.status>=200&&xhr.status<300&&d.status==='success'){
        if(window.ym)ym(109758131,'reachGoal','zayavka');
        form.reset();document.getElementById('zrFileBox').className='zr-file';document.getElementById('zrFileLbl').textContent='Фото шильда, чертёж или спецификация · JPG, PDF, до 10 МБ';
        show('Заявка принята. Инженер свяжется с вами и пришлёт КП.','ok');
        var i=document.getElementById('zrIntro');if(i)i.style.display='none';form.style.display='none';
      } else {
        // Цель на ОШИБКУ: раньше неуспешные отправки были невидимы в Метрике
        // (цель слалась только при success) — потери заявок никак не проявлялись.
        if(window.ym)ym(109758131,'reachGoal','zayavka_error');
        show((d.message||'Не удалось отправить заявку.')+' Позвоните: +7 (495) 151-41-02.','err');
      }
    };
    xhr.onerror=function(){clearTimeout(_stall);unlock();if(window.ym)ym(109758131,'reachGoal','zayavka_error');show('Сбой отправки. Позвоните нам: +7 (495) 151-41-02.','err');};
    _bump();
    xhr.send(fd);
  });
})();

/* ===== Плавающий виджет «Напишите нам» (Telegram / MAX / Почта) ===== */
(function(){
  // ⚠️ ССЫЛКИ-ЗАГЛУШКИ — заменить на реальные:
  var TG   = 'https://t.me/+79511178737';           // по номеру (работает, если в приватности TG «номер видят все»)
  var MAX  = 'https://max.ru/u/f9LHodD0cOIBbNxN8VFtBfWRqU_P-puLvgjJREORzznuFy0Pt_mY02s_NoI'; // персональная ссылка-приглашение MAX (МАХЗавод ООО НИИ АТТ)
  var MAIL = 'mailto:zr@zavod-red.ru';

  var css=''
   +'.zrw{position:fixed;right:20px;bottom:20px;z-index:990;display:flex;flex-direction:column;align-items:flex-end;gap:10px;font-family:inherit}'
   +'.zrw__panel{display:flex;flex-direction:column;gap:8px;width:228px;max-height:0;overflow:hidden;opacity:0;transform:translateY(12px);transition:max-height .28s ease,opacity .2s,transform .2s;pointer-events:none}'
   +'.zrw.open .zrw__panel{max-height:280px;opacity:1;transform:none;pointer-events:auto}'
   +'.zrw__item{display:flex;align-items:center;gap:11px;background:#15242f;border:1px solid #22333f;color:#e9eff4;font-weight:600;font-size:14.5px;padding:12px 16px;border-radius:11px;box-shadow:0 8px 22px rgba(0,0,0,.35);transition:.15s}'
   +'.zrw__item:hover{border-color:#33485a;transform:translateX(-3px)}'
   +'.zrw__item svg{width:20px;height:20px;flex:0 0 auto}'
   +'.zrw__item.tg svg{color:#2aabee}.zrw__item.mx svg{color:#7c5cff}.zrw__item.ml svg{color:#e11b1b}'
   +'.zrw__toggle{display:inline-flex;align-items:center;gap:10px;background:#e11b1b;color:#fff;font-weight:700;font-size:15px;padding:13px 21px;border:0;border-radius:30px;cursor:pointer;box-shadow:0 10px 28px rgba(225,27,27,.42);transition:.15s}'
   +'.zrw__toggle:hover{background:#c81414}'
   +'.zrw__toggle .zrw__dot{width:9px;height:9px;border-radius:50%;background:#37e06a;box-shadow:0 0 0 3px rgba(55,224,106,.22)}'
   +'.zrw__toggle svg{width:20px;height:20px}'
   +'.zrw.open .zrw__lbl-open{display:none}.zrw__lbl-close{display:none}.zrw.open .zrw__lbl-close{display:inline}'
   /* На телефоне широкая плашка «Напишите нам» (208 px из 375) висит поверх страницы
      и накрывает правую часть кнопок «Заказать» в таблице подбора — палец попадает
      в чат вместо карточки товара. Сворачиваем её в компактный кружок с иконкой:
      канал связи остаётся, перекрытие падает с 208 px до 52 px. */
   +'@media(max-width:600px){.zrw{right:14px}.zrw__panel{width:208px}'
   +'.zrw__toggle{padding:0;width:52px;height:52px;justify-content:center;gap:0;border-radius:50%}'
   +'.zrw__toggle .zrw__lbl-open,.zrw__toggle .zrw__lbl-close{display:none}'
   +'.zrw.open .zrw__toggle{width:52px;height:52px}}'
   +'@media(max-width:720px){.zrw{bottom:80px}}';
  var st=document.createElement('style');st.textContent=css;document.head.appendChild(st);

  var chat='<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 3C6.5 3 2 6.6 2 11c0 2.3 1.2 4.4 3.3 5.9-.2 1.3-.9 2.6-1.9 3.6 1.7-.2 3.3-.8 4.6-1.8 1.2.4 2.5.6 4 .6 5.5 0 10-3.6 10-8.3S17.5 3 12 3z"/></svg>';
  var tg='<svg viewBox="0 0 24 24" fill="currentColor"><path d="M9.78 18.65l.28-4.23 7.68-6.92c.34-.31-.07-.46-.52-.19L7.74 13.3 3.64 12c-.88-.25-.89-.86.2-1.3l15.97-6.16c.73-.33 1.43.18 1.15 1.3l-2.72 12.81c-.19.91-.74 1.13-1.5.71L12.6 16.3l-1.99 1.93c-.23.23-.42.42-.83.42z"/></svg>';
  var mx='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H9l-5 4v-4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"/></svg>';
  var ml='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3.5 7 8.5 6 8.5-6"/></svg>';

  var html=''
   +'<div class="zrw" id="zrWidget">'
   +'<div class="zrw__panel">'
   +'<a class="zrw__item mx" href="'+MAX+'" target="_blank" rel="noopener">'+mx+'MAX</a>'
   +'<a class="zrw__item ml" href="'+MAIL+'">'+ml+'Почта</a>'
   +'</div>'
   +'<button class="zrw__toggle" type="button" aria-label="Написать нам"><span class="zrw__dot"></span>'+chat+'<span class="zrw__lbl-open">Напишите нам</span><span class="zrw__lbl-close">Закрыть</span></button>'
   +'</div>';
  var wrap=document.createElement('div');wrap.innerHTML=html;document.body.appendChild(wrap.firstChild);

  var w=document.getElementById('zrWidget');
  w.querySelector('.zrw__toggle').addEventListener('click',function(){w.classList.toggle('open');});
  document.addEventListener('click',function(e){if(!w.contains(e.target))w.classList.remove('open');});
})();

/* ===== Липкая мобильная CTA-панель (Позвонить / Заявка) ===== */
(function(){
  var TEL='+74951514102';
  var css=''
   +'.zr-mbar{position:fixed;left:0;right:0;bottom:0;z-index:985;display:none;gap:8px;padding:8px 10px calc(8px + env(safe-area-inset-bottom));background:rgba(11,21,29,.94);backdrop-filter:blur(10px);border-top:1px solid #22333f;font-family:inherit}'
   +'.zr-mbar a{flex:1;display:flex;align-items:center;justify-content:center;gap:8px;margin:0;padding:13px 10px;border-radius:10px;font-weight:700;font-size:15px;line-height:1.2;white-space:nowrap;text-decoration:none}'
   +'.zr-mbar .zrmb-call{background:#15242f;border:1px solid #2a3b48;color:#e9eff4}'
   +'.zr-mbar .zrmb-lead{background:#e11b1b;color:#fff}'
   +'.zr-mbar svg{width:18px;height:18px;flex:0 0 auto}'
   +'@media(max-width:720px){.zr-mbar{display:flex}body{padding-bottom:70px}.zrw{bottom:80px}.zr-cookie{bottom:80px}}';
  var st=document.createElement('style');st.textContent=css;document.head.appendChild(st);
  var phone='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.8 19.8 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.8 19.8 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/></svg>';
  var spark='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M5 12V5a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2h-5"/><path d="m3 21 3-3-3-3"/><path d="M9 18H4"/></svg>';
  var bar=document.createElement('div');
  bar.className='zr-mbar';
  bar.innerHTML='<a class="zrmb-lead" href="#zayavka" data-zayavka>'+spark+'Получить расчёт</a>';
  document.body.appendChild(bar);
})();

/* ===== Cookie-баннер (152-ФЗ / практика РКН) ===== */
(function(){
  var KEY='zr_cookie_consent';
  try{ if(localStorage.getItem(KEY)) return; }catch(e){}
  var sub=/\/(catalog|cases|uslugi|brands|blog|analog|reduktor|ispolnenie|tiporazmer|glossary|otrasli)\//.test(location.pathname);
  var PRIVACY='/privacy.html';
  var css=''
   +'.zr-cookie{position:fixed;left:16px;right:16px;bottom:16px;z-index:995;max-width:760px;margin:0 auto;'
   +'background:#15242f;border:1px solid #2a3b48;border-radius:13px;padding:16px 18px;'
   +'box-shadow:0 14px 40px rgba(0,0,0,.45);display:flex;gap:16px;align-items:center;flex-wrap:wrap;'
   +'font-family:inherit;color:#dfe7ee;font-size:14px;line-height:1.45;animation:zrck .3s ease}'
   +'@keyframes zrck{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:none}}'
   +'.zr-cookie p{margin:0;flex:1 1 320px}'
   +'.zr-cookie a{color:#ff6a6a;text-decoration:underline}'
   +'.zr-cookie__btn{background:#e11b1b;color:#fff;border:0;border-radius:9px;padding:11px 22px;'
   +'font-weight:700;font-size:14px;cursor:pointer;white-space:nowrap;transition:.15s}'
   +'.zr-cookie__btn:hover{background:#c81414}'
   +'@media(max-width:560px){.zr-cookie{flex-direction:column;align-items:stretch;text-align:left;gap:11px;padding:14px 15px;left:10px;right:10px;bottom:80px;font-size:13px;line-height:1.4}.zr-cookie p{flex:none}.zr-cookie__btn{width:100%;padding:11px}}';
  var st=document.createElement('style');st.textContent=css;document.head.appendChild(st);
  var bar=document.createElement('div');
  bar.className='zr-cookie';
  bar.innerHTML='<p>Мы используем cookie и сервис Яндекс.Метрика для аналитики посещаемости. '
   +'Оставаясь на сайте, вы соглашаетесь с обработкой данных в соответствии с '
   +'<a href="'+PRIVACY+'" target="_blank" rel="noopener">Политикой обработки персональных данных</a>.</p>'
   +'<button class="zr-cookie__btn" type="button">Принять</button>';
  document.body.appendChild(bar);
  bar.querySelector('.zr-cookie__btn').addEventListener('click',function(){
    try{ localStorage.setItem(KEY,'1'); }catch(e){}
    bar.style.display='none';
  });
})();

/* ===== Переключатель светлой/тёмной темы ===== */
(function(){
  function apply(t){document.documentElement.setAttribute('data-theme',t);try{localStorage.setItem('zr_theme',t);}catch(e){}}
  document.addEventListener('click',function(e){
    var b=e.target.closest('.theme-toggle');
    if(!b)return;
    var cur=document.documentElement.getAttribute('data-theme')||'dark';
    apply(cur==='light'?'dark':'light');
  });
})();

/* ===== Универсальный обработчик статичных лид-форм (.lead-form, напр. #impForm) ===== */
(function(){
  var sub=/\/(catalog|cases|uslugi|brands|blog|analog|reduktor|ispolnenie|tiporazmer|glossary|otrasli)\//.test(location.pathname);
  var ACTION='/api/feedback.php';
  document.addEventListener('submit',function(e){
    var form=e.target;
    if(!form.classList||!form.classList.contains('lead-form'))return;
    e.preventDefault();
    // honeypot
    var hp=form.querySelector('input[name="work_email"]');
    if(hp&&hp.value){ return; }
    var res=form.querySelector('.form-result');
    function show(msg,cls){ if(res){res.textContent=msg;res.className='form-result '+(cls||'');} else { alert(msg); } }
    // поля по типу
    // Поля ищем не только по type: на части страниц телефон/почта размечены как text,
    // из-за чего контакты раньше вообще не уходили (заявки приходили без телефона и почты).
    var nameEl=form.querySelector('input[type="text"]:not([name="work_email"]):not([name*="phone"]):not([name*="mail"])');
    var phoneEl=form.querySelector('input[type="tel"],input[name*="phone"],input[name*="tel"],input[id*="phone"],input[id*="tel"],input[placeholder*="елефон"]');
    var emailEl=form.querySelector('input[type="email"],input[name*="email"]:not([name="work_email"]),input[id*="email"],input[placeholder*="mail"]');
    var msgEl=form.querySelector('textarea');
    var consent=form.querySelector('input[type="checkbox"]');
    var name=nameEl?nameEl.value.trim():'';
    var phone=phoneEl?phoneEl.value.trim():'';
    var email=emailEl?emailEl.value.trim():'';
    if(!name){ show('Укажите имя.','err'); return; }
    if(phoneEl&&(phone.replace(/\D/g,'').length<10)&&!email){ show('Укажите корректный телефон или email.','err'); return; }
    if(consent&&!consent.checked){ show('Подтвердите согласие на обработку данных.','err'); return; }
    var fd=new FormData();
    fd.append('work_email','');
    fd.append('text-562',name);
    if(phone)fd.append('tel-535',phone);
    if(email)fd.append('email-727',email);
    if(msgEl&&msgEl.value.trim())fd.append('textarea-725',msgEl.value.trim());
    fd.append('product_title','Заявка (форма на странице) · '+document.title);
    try{
      var p=new URLSearchParams(location.search),ks=['utm_source','utm_medium','utm_campaign','utm_term','utm_content','gclid','yclid'],st={};
      try{st=JSON.parse(localStorage.getItem('zr_utm')||'{}');}catch(_){}
      ks.forEach(function(k){fd.append(k,p.get(k)||st[k]||'');});
      fd.append('referrer',document.referrer||'');fd.append('page_url',location.href);
    }catch(_){}
    var btn=form.querySelector('button[type="submit"],button');
    if(btn){btn.disabled=true;var _t=btn.textContent;btn.textContent='Отправляем…';}
    show('Отправляем заявку…','busy');
    fetch(ACTION,{method:'POST',body:fd}).then(function(r){return r.json().catch(function(){return {};});}).then(function(d){
      if(btn){btn.disabled=false;btn.textContent=_t;}
      if(d.status==='success'||d.ok){
        if(window.ym)ym(109758131,'reachGoal','zayavka');
        form.reset();
        show('Заявка принята. Инженер свяжется с вами и пришлёт КП.','ok');
      } else {
        if(window.ym)ym(109758131,'reachGoal','zayavka_error');
        show((d.message||'Не удалось отправить.')+' Позвоните: +7 (495) 151-41-02.','err');
      }
    }).catch(function(){
      if(btn){btn.disabled=false;btn.textContent=_t;}
      if(window.ym)ym(109758131,'reachGoal','zayavka_error');
      show('Ошибка сети. Позвоните: +7 (495) 151-41-02.','err');
    });
  });
})();

/* Кабинет + Корзина: бейдж корзины (кнопки в разметке новой шапки .hdr2;
   на страницах со старой шапкой — инжект) */
(function(){
  if(window.__zrCabBtns) return; window.__zrCabBtns=1;
  var nr=document.querySelector('.nav-right'); if(!nr) return;
  function cnt(){try{var c=JSON.parse(localStorage.getItem('zr_cart')||'{"items":[]}');return (c.items||[]).reduce(function(s,i){return s+(i.qty||1)},0)}catch(e){return 0}}
  var host=nr.querySelector('[data-zr-cab]')?nr:null;
  if(!host){
    var w=document.createElement('div'); w.setAttribute('data-zr-cab','1');
    w.style.cssText='display:flex;gap:8px;align-items:center;margin-right:2px';
    w.innerHTML='<a href="/cabinet" title="Кабинет клиента" aria-label="Кабинет" style="display:flex;align-items:center;justify-content:center;width:40px;height:40px;background:var(--card,#14222e);border:1px solid var(--line,#22333f);border-radius:11px;color:var(--text,#e9eff4)"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-6 8-6s8 2 8 6"/></svg></a><a href="/cart" title="Корзина" aria-label="Корзина" style="position:relative;display:flex;align-items:center;justify-content:center;width:40px;height:40px;background:var(--card,#14222e);border:1px solid var(--line,#22333f);border-radius:11px;color:var(--text,#e9eff4)"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="9" cy="20" r="1.6"/><circle cx="17" cy="20" r="1.6"/><path d="M3 4h2l2.6 11h10.2L21 8H6.2"/></svg><span data-zr-badge style="position:absolute;top:-5px;right:-5px;min-width:17px;height:17px;border-radius:9px;background:var(--red,#e11b1b);color:#fff;font-size:10.5px;font-weight:700;display:none;align-items:center;justify-content:center;padding:0 4px"></span></a>';
    var tt=document.getElementById('themeToggle');
    if(tt&&tt.parentNode===nr) nr.insertBefore(w,tt); else nr.insertBefore(w,nr.firstChild);
    host=nr;
  }
  window.zrRefreshBadge=function(){var b=host.querySelector('[data-zr-badge]'),n=cnt();if(b){b.textContent=n;b.style.display=n?'flex':'none'}};
  window.zrRefreshBadge();
  window.addEventListener('storage',function(e){if(e.key==='zr_cart')window.zrRefreshBadge()});

  /* ===== Быстрый поиск в шапке: кнопка «Найти» + живые подсказки-карточки (RU/EN, приблизительно) ===== */
  try{ (function(){
    var form=document.querySelector('form.msearch'); if(!form) return;
    var input=form.querySelector('input[name=q]'); if(!input || form.__zrEnh) return; form.__zrEnh=1;
    var go=document.createElement('button'); go.type='button'; go.className='ms-go'; go.setAttribute('aria-label','Найти'); go.textContent='Найти'; form.appendChild(go);
    /* Навигация ВРУЧНУЮ, без нативного submit формы. Иначе каждый поиск порождает
       событие submit, которое автоцель Яндекс.Метрики «отправка формы» засчитывает
       как отправленную заявку — конверсии раздувались ложными «отправками данных».
       Кнопка type=button (не submit), Enter перехватываем ниже — submit не рождается. */
    function goSearch(){ var v=(input.value||'').trim(); if(v){ location.href='/podbor?q='+encodeURIComponent(v); } else { input.focus(); } }
    go.addEventListener('click',function(e){ e.preventDefault(); goSearch(); });
    form.addEventListener('submit',function(e){ e.preventDefault(); goSearch(); }); /* страховка: если submit всё же случится — гасим */
    var dd=document.createElement('div'); dd.className='ms-dd'; dd.hidden=true; form.appendChild(dd);
    // «по шильду» — кликабельно: открывает заявку с загрузкой фото шильдика
    var chip=form.querySelector('.ms-chip');
    if(chip){
      chip.setAttribute('data-zayavka',''); chip.setAttribute('role','button'); chip.setAttribute('tabindex','0');
      chip.setAttribute('title','Подбор по фото шильдика — прикрепите фото, инженер определит модель и аналог');
      chip.style.cursor='pointer';
      chip.addEventListener('keydown',function(ev){ if(ev.key==='Enter'||ev.key===' '){ ev.preventDefault(); chip.click(); } });
    }
    var BRAND_RU={sew:['сью','сев','сэв'],nord:['норд'],bonfiglioli:['бонфильоли','бонфиглиоли','бонфилиоли'],motovario:['мотоварио'],bauer:['бауэр','бауер'],lenze:['ленце','лензе'],varvel:['варвель','варвел'],siti:['сити'],stm:['стм'],rossi:['росси'],watt:['ватт'],yilmaz:['йилмаз','йылмаз'],transtecno:['транстекно'],innovari:['инновари'],vemper:['вемпер']};
    var TYPE_EN={0:['worm'],1:['coaxial','inline'],2:['bevel'],3:['flat'],4:['helical','cylindrical']};
    /* Слово-тип из запроса → индекс типа. Тип трактуем как жёсткий фильтр (как бренд),
       чтобы «коническо 15 квт» не смешивался с червячными, а «планетарный» (типа нет) не тащил чужое. */
    var TYPEW={червяч:0,worm:0,соосн:1,coaxial:1,inline:1,коническ:2,bevel:2,плоск:3,flat:3,цилиндр:4,helical:4};
    var STOP={редуктор:1,редуктора:1,редукторы:1,мотор:1,моторредуктор:1,привод:1,купить:1,аналог:1,цена:1};
    /* Единицы измерения — не считаем их поисковыми токенами (значение берём отдельно). */
    var UNIT={квт:1,kw:1,kwt:1,вт:1,нм:1,nm:1,об:1,обмин:1,rpm:1,i:1,и:1,передат:1,передаточное:1,мощность:1,момент:1};
    var IMG={0:'cat_worm',1:'cat_coaxial',2:'cat_bevel',3:'cat_flat',4:'cat_cylindrical'};
    function nn(s){return String(s==null?'':s).toLowerCase().replace(/ё/g,'е').replace(/[^0-9a-zа-я]+/g,'');}
    function tk(s){return String(s==null?'':s).toLowerCase().replace(/ё/g,'е').split(/[^0-9a-zа-я]+/).filter(Boolean);}
    function esc(s){return String(s==null?'':s).replace(/[<>&"]/g,function(c){return {'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;'}[c];});}
    function lev1(a,b){if(a===b)return true;var la=a.length,lb=b.length;if(Math.abs(la-lb)>1)return false;var i=0,j=0,d=0;while(i<la&&j<lb){if(a[i]===b[j]){i++;j++;}else{if(++d>1)return false;if(la>lb)i++;else if(lb>la)j++;else{i++;j++;}}}if(i<la||j<lb)d++;return d<=1;}
    var IDX=null,DF=null,T=null,loading=false,IMPB=null,VALS=[],BQ='';
    /* Диапазон «0,1–30» / «4–303» → [lo,hi] числами (десятичная запятая, разные тире). */
    function rng(s){ if(!s)return null; var p=String(s).split(/[–—-]/); function n(x){x=String(x).replace(',','.').replace(/[^\d.]/g,'');return x?parseFloat(x):NaN;} var a=n(p[0]),b=p.length>1?n(p[1]):a; if(isNaN(a))return null; if(isNaN(b))b=a; return a<=b?[a,b]:[b,a]; }
    /* Из запроса вынимаем числовые значения с единицей (кВт/i) и «очищенный» текст под токены. */
    function qvals(raw){ var s=' '+String(raw==null?'':raw).toLowerCase().replace(/ё/g,'е')+' '; var vals=[];
      function grab(re,u){ s=s.replace(re,function(m,d){ var v=parseFloat(String(d).replace(',','.')); if(!isNaN(v))vals.push({v:v,u:u}); return ' '; }); }
      grab(/(\d+(?:[.,]\d+)?)\s*(?:квт|kwt|kw)\b/g,'pw');
      grab(/\bi\s*[=:]?\s*(\d+(?:[.,]\d+)?)\b/g,'i');
      grab(/(\d+(?:[.,]\d+)?)\s*передат\w*/g,'i');
      grab(/(?:^|\s)(\d+[.,]\d+)(?=\s)/g,'');      // голая дробь без единицы — вероятнее мощность
      return {vals:vals, rest:s};
    }
    /* Насколько значение подходит записи: попало в диапазон — максимум, рядом — меньше. */
    function valFit(qn,gr){ function near(r,v){ if(!r)return 0; if(v>=r[0]&&v<=r[1])return 1; var lo=r[0]||1,hi=r[1]||1,d=v<r[0]?(r[0]-v)/lo:(v-hi)/hi; return d<0.6?(0.6-d):0; }
      if(qn.u==='pw')return gr.__pw?(near(gr.__pw,qn.v)>=1?75:Math.round(near(gr.__pw,qn.v)*45)):0;
      if(qn.u==='i')return gr.__i?(near(gr.__i,qn.v)>=1?68:Math.round(near(gr.__i,qn.v)*42)):0;
      var bp=near(gr.__pw,qn.v),bi=near(gr.__i,qn.v); return Math.round(Math.max(bp,bi)*48);
    }
    /* Ключи бренда для сопоставления: первое слово и склейка целиком
       («SEW-Eurodrive» → sew и seweurodrive), чтобы короткий запрос «SEW» тоже ловил бренд. */
    function bkeys(b){ var p=String(b||'').split(/[\s\-]+/); var f=nn(p[0]),w=nn(b); return f===w?[f]:[f,w]; }
    function prep(data){ T=data.t;
      var imp=data.i||[],arr=[];
      /* Бренды импорта, у которых есть адресные записи i. Их токены НЕ индексируем в
         групповых записях g — иначе запрос «SEW» снова уводил бы на обезличенную группу ZR. */
      IMPB={}; imp.forEach(function(r){ bkeys(r.b).forEach(function(k){IMPB[k]=1;}); });
      imp.forEach(function(r){ var t={}; function add(s){tk(s).forEach(function(x){t[x]=1;});}
        add(r.b); bkeys(r.b).forEach(function(k){ t[k]=1; (BRAND_RU[k]||[]).forEach(function(x){t[x]=1;}); });
        /* Одна запись может нести несколько моделей («BF 06, BF 10, BF 20») — токенизируем каждую. */
        var ms=String(r.m||'').split(',').map(function(s){return s.trim();}).filter(Boolean);
        ms.forEach(function(m){ add(m); t[nn(m)]=1; });
        if(r.z){ add(r.z); t[nn(r.z)]=1; }
        add(T[r.t]); (TYPE_EN[r.t]||[]).forEach(function(w){t[w]=1;});
        r.__pw=rng(r.pw); r.__i=rng(r.i);
        r.__nums=Object.keys(t).filter(function(x){return /^\d/.test(x);});
        r.__t=t; r.__b=Object.keys(t).join(' '); r.__ms=ms; r.__imp=1; arr.push(r);
      });
      (data.g||[]).forEach(function(gr){ var t={}; function add(s){tk(s).forEach(function(x){t[x]=1;});}
        add(gr.e); add(gr.p); if(gr.z)add(gr.z); add(T[gr.t]); (TYPE_EN[gr.t]||[]).forEach(function(w){t[w]=1;});
        /* Из группы индексируем только НАШИ обозначения (МР, Ч, РЧУ…), которых нет в каталоге
           импорта. Бренды импорта пропускаем — они живут в адресных записях i. */
        gr.b.forEach(function(b,i){ if(IMPB[nn(String(b).split(/[\s\-]+/)[0])])return;
          add(b); (BRAND_RU[nn(b.split(' ')[0])]||[]).forEach(function(x){t[x]=1;});
          var m=gr.m[i]; if(m){ add(m); t[nn(m)]=1; } });
        t[nn(gr.e)]=1; if(gr.p)t[nn(gr.p)]=1; if(gr.z)t[nn(gr.z)]=1;
        gr.__pw=rng(gr.pw); gr.__i=rng(gr.i);
        gr.__t=t; gr.__b=Object.keys(t).join(' '); gr.__imp=0; arr.push(gr);
      });
      DF={}; arr.forEach(function(gr){for(var x in gr.__t)DF[x]=(DF[x]||0)+1;}); return arr;
    }
    function brandFor(x){ if(BRAND_RU[x])return x; for(var k in BRAND_RU)if(BRAND_RU[k].indexOf(x)>=0)return k; return x; }
    /* Распознан ли в запросе бренд импорта — прямо («sew») или по кириллице («сью»). */
    function qBrand(qt){ for(var i=0;i<qt.length;i++){ if(IMPB[brandFor(qt[i])])return brandFor(qt[i]); } return ''; }
    function score(qt,qf,gr){ var s=0,m=0,mt=0,cm=IDX.length*0.9;
      /* Бренд в запросе задан — держим выдачу строго внутри этого бренда. */
      if(BQ){ if(!gr.__imp||!gr.__t[BQ])return 0; }
      if(qf&&gr.__t[qf]){s+=90;m++;mt++;}
      for(var i=0;i<qt.length;i++){var q=qt[i],b=0,h=false;
        if(gr.__t[q]){ if((DF[q]||0)>=cm)b=4; else {b=(/\d/.test(q)?55:26);h=true;} }
        else if(q.length>=3&&gr.__b.indexOf(q)>=0){b=12;h=true;}
        else if(/^\d+$/.test(q)&&q.length>=2&&gr.__nums){ for(var n=0;n<gr.__nums.length;n++){ if(gr.__nums[n]!==q&&gr.__nums[n].indexOf(q)===0){b=30;h=true;break;} } }
        if(!h&&q.length>=4){for(var t in gr.__t){if(Math.abs(t.length-q.length)<=1&&lev1(t,q)){b=9;h=true;break;}}}
        if(h){m++;mt++;} s+=b; }
      /* Есть текстовые токены (тип/модель/бренд), но ни один не совпал — запись не наша. */
      if(qt.length&&mt===0)return 0;
      /* Значение (кВт / передаточное i / типоразмер) — сортируем по попаданию в диапазон. */
      if(VALS.length){ for(var k=0;k<VALS.length;k++){ var vb=valFit(VALS[k],gr); if(vb>0){s+=vb;m++;} } }
      if(!m)return 0; if(!BQ&&qt.length>=2&&mt<Math.ceil(qt.length/2))return 0; return s+m*4;
    }
    function rank(query){ var qv=qvals(query);
      /* Токены из «очищенного» запроса (без единиц и значений); «R107» слитно → «r»+«107». */
      var qt0=tk(qv.rest).filter(function(t){var k=nn(t);return !STOP[k]&&!UNIT[k];}).map(nn).filter(Boolean);
      var qt=[]; qt0.forEach(function(q){ qt.push(q); var mm=q.match(/^([a-zа-я]+)(\d+)$/)||q.match(/^(\d+)([a-zа-я]+)$/); if(mm){ if(mm[1])qt.push(mm[1]); if(mm[2])qt.push(mm[2]); } });
      VALS=qv.vals; BQ=qBrand(qt);
      if(!qt.length&&!VALS.length)return[];
      /* Тип передачи в запросе → жёсткий фильтр по типу (bevel/worm/…). */
      var qType=null; for(var ti=0;ti<qt.length;ti++){ for(var w in TYPEW){ if(qt[ti].length>=4&&(qt[ti].indexOf(w)===0||w.indexOf(qt[ti])===0)){ qType=TYPEW[w]; break; } } if(qType!=null)break; }
      var qf=nn(qv.rest),qb=qt.map(brandFor);
      /* Пришёл по бренду импорта — обезличенные ZR-группы в выдачу не пускаем вообще:
         пользователь должен попасть на брендовую страницу, а не на нашу карточку. */
      var pool=BQ?IDX.filter(function(r){return r.__imp;}):IDX;
      if(qType!=null)pool=pool.filter(function(r){return r.t===qType;});
      /* Бренда в запросе нет — значит спрашивают наше (ZR 603, ПР 4110, «червячный»),
         и наверху должна быть наша группа, а не случайный импорт с тем же аналогом. */
      /* Групповой буст ослабляем, если задано значение — иначе наши ZR-группы задавили бы
         импортные модели, реально покрывающие запрошенные кВт/i. */
      var gb=VALS.length?1.1:1.5;
      var sc=pool.map(function(gr){var s=score(qt,qf,gr); if(!BQ&&!gr.__imp)s=Math.round(s*gb); return {gr:gr,s:s};}).filter(function(x){return x.s>0;});
      sc.sort(function(a,b){return b.s-a.s;}); return sc.slice(0,7).map(function(x){x.qb=qb;x.qt=qt;x.bq=BQ;return x;});
    }
    function analog(gr,qb){ for(var i=0;i<gr.b.length;i++){ if(qb.indexOf(nn(gr.b[i].split(' ')[0]))>=0) return gr.b[i].split(' ')[0]+' '+(gr.m[i]||''); } return gr.b.length?(gr.b[0].split(' ')[0]+' '+(gr.m[0]||'')):''; }
    /* Запись может нести несколько моделей — в заголовок ставим ту, про которую спросили. */
    function pickModel(r,qt){ var ms=r.__ms||[]; if(ms.length<2)return ms[0]||r.m||'';
      for(var i=0;i<ms.length;i++){ var n=nn(ms[i]);
        for(var j=0;j<qt.length;j++){ if(/\d/.test(qt[j])&&qt[j].length>=2&&n.indexOf(qt[j])>=0)return ms[i]; } }
      return r.m; }
    function impHref(r,model){
      if(r.u)return '/analog/'+r.u;
      /* Своей страницы у позиции нет — ведём на нашу карточку ZR, но с ?imp=,
         по которому reduktor/*.html перестраивает H1/title/крошки под бренд (ZR_IMPCTX). */
      if(r.r)return '/reduktor/'+r.r+'?imp='+encodeURIComponent(r.b+' '+model);
      return '/podbor?q='+encodeURIComponent(r.b+' '+model);
    }
    function img(t){ return '<img src="/assets/catalog/'+(IMG[t]||'cat_cylindrical')+'.webp" alt="" loading="lazy" onerror="this.style.visibility=\'hidden\'">'; }
    function row(x){ var gr=x.gr;
      if(gr.__imp){ var model=pickModel(gr,x.qt||[]);
        /* Главное — бренд импорта крупно; наш ZR уходит мелкой вторичной строкой. */
        return '<a class="ms-row" href="'+esc(impHref(gr,model))+'">'+img(gr.t)+'<span class="ms-rb"><span class="ms-rm">'+esc(gr.b+' '+model)+'</span><span class="ms-rt">'+esc(T[gr.t])+' · '+esc(gr.pw)+' кВт · i '+esc(gr.i)+'</span>'+(gr.z?'<span class="ms-rz" style="font-size:11px;color:var(--h-dim)">наш аналог '+esc(gr.z)+'</span>':'')+'</span><span class="ms-rp">по запросу</span></a>';
      }
      /* Наша группа: показываем только маркировку ZR — EVL остаётся невидимым токеном поиска. */
      var mark=gr.z,sub=gr.p,an=analog(gr,x.qb);
      return '<a class="ms-row" href="/podbor?q='+encodeURIComponent(gr.z)+'">'+img(gr.t)+'<span class="ms-rb"><span class="ms-rm">'+esc(mark)+(sub?' <em>'+esc(sub)+'</em>':'')+'</span><span class="ms-rt">'+esc(T[gr.t])+(an?' · '+esc(an):'')+' · '+esc(gr.pw)+' кВт · i '+esc(gr.i)+'</span></span><span class="ms-rp">по запросу</span></a>';
    }
    function render(q){ var top=rank(q);
      if(!top.length){ dd.innerHTML='<div class="ms-empty">По «'+esc(q)+'» точных карточек нет. Нажмите «Найти» — откроем полный подбор.</div>'; dd.hidden=false; return; }
      dd.innerHTML=top.map(row).join('')+'<a class="ms-all" href="/podbor?q='+encodeURIComponent(q)+'">Показать все результаты в подборе →</a>'; dd.hidden=false;
    }
    function load(cb){ if(IDX)return cb&&cb(); if(loading)return; loading=true; fetch('/assets/search-index.json?v=3').then(function(r){return r.json();}).then(function(d){IDX=prep(d);loading=false;cb&&cb();}).catch(function(){loading=false;}); }
    var t; function deb(){ clearTimeout(t); var q=input.value.trim(); if(q.length<2){dd.hidden=true;return;} t=setTimeout(function(){ if(IDX)render(q); else load(function(){render(q);}); },140); }
    input.addEventListener('focus',function(){ load(); if(input.value.trim().length>=2)deb(); });
    input.addEventListener('input',deb);
    input.addEventListener('keydown',function(e){ if(e.key==='Escape'){dd.hidden=true;} else if(e.key==='Enter'){ e.preventDefault(); goSearch(); } });
    document.addEventListener('click',function(e){ if(!form.contains(e.target))dd.hidden=true; });
  })(); }catch(e){}

  /* ---------- Учёт контактных обращений ----------
     Форма — не единственный способ обратиться: звонят по tel:, уходят в
     Telegram/MAX/WhatsApp, пишут на почту. Раньше такие обращения видела
     только Метрика, а в CRM их не было. Фиксируем их как заявки, чтобы
     менеджер видел ВСЕ обращения, а не только отправленные формы. */
  try { (function(){
    var API = '/api/contact_click.php';
    var SENT = {};                       // канал → уже отправляли в этой сессии
    var KIND = [
      [/^tel:/i,                          'phone'],
      [/^https?:\/\/(t\.me|telegram\.(me|dog))\//i, 'telegram'],
      [/^https?:\/\/max\.ru\//i,          'max'],
      [/^https?:\/\/(wa\.me|api\.whatsapp\.com)\//i, 'whatsapp'],
      [/^mailto:/i,                       'email']
    ];

    function kindOf(href){
      if (!href) return '';
      for (var i = 0; i < KIND.length; i++) if (KIND[i][0].test(href)) return KIND[i][1];
      return '';
    }

    function utm(){
      var out = {}, p;
      try { p = new URLSearchParams(location.search); } catch (e) { return out; }
      ['utm_source','utm_medium','utm_campaign','utm_term','utm_content'].forEach(function(k){
        var v = p.get(k); if (v) out[k] = v;
      });
      // Метки могли прийти на первой странице визита — форма сайта хранит их тут же.
      try {
        var saved = JSON.parse(sessionStorage.getItem('zr_utm') || 'null');
        if (saved) for (var k in saved) if (!out[k] && saved[k]) out[k] = saved[k];
      } catch (e) {}
      return out;
    }

    function track(kind, contact){
      // Один канал — одно обращение за вкладку: без спама при повторных кликах.
      if (SENT[kind]) return;
      SENT[kind] = 1;
      var body = utm();
      body.kind = kind;
      body.contact = contact || '';
      body.page_url = location.href;
      body.page_title = (document.title || '').slice(0, 200);
      body.referrer = document.referrer || '';

      var fd = new FormData();
      for (var k in body) fd.append(k, body[k]);

      // keepalive — чтобы запрос дожил при уходе на tel:/мессенджер.
      try {
        if (navigator.sendBeacon) navigator.sendBeacon(API, fd);
        else fetch(API, { method: 'POST', body: fd, keepalive: true });
      } catch (e) {
        try { fetch(API, { method: 'POST', body: fd }); } catch (e2) {}
      }
    }

    document.addEventListener('click', function(e){
      var a = e.target && e.target.closest && e.target.closest('a[href]');
      if (!a) return;
      var k = kindOf(a.getAttribute('href') || '');
      if (k) track(k, (a.getAttribute('href') || '').slice(0, 200));
    }, true);
  })(); } catch(e){}

  /* Клик по e-mail: у посетителя без настроенного почтового клиента ссылка mailto:
     не делает НИЧЕГО видимого — человек решает, что сайт сломан, и уходит.
     Поведение mailto не отменяем (у кого клиент есть — письмо откроется), но
     дополнительно копируем адрес и показываем подсказку с переходом на форму. */
  try { (function(){
    var TOAST_CSS = ''
     + '.zr-mailhint{position:fixed;left:50%;bottom:22px;transform:translateX(-50%) translateY(12px);z-index:1200;'
     + 'display:flex;align-items:center;gap:12px;max-width:calc(100vw - 28px);background:#fff;color:#0e1a24;'
     + 'border:1px solid rgba(14,26,36,.14);border-left:4px solid #cf1616;border-radius:12px;padding:13px 16px;'
     + 'box-shadow:0 16px 44px rgba(14,26,36,.22);font-size:14px;line-height:1.4;opacity:0;transition:.22s}'
     + '.zr-mailhint.show{opacity:1;transform:translateX(-50%) translateY(0)}'
     + '.zr-mailhint b{display:block;font-size:14.5px;margin-bottom:2px}'
     + '.zr-mailhint span{color:#526069;font-size:13px}'
     + '.zr-mailhint button{flex:none;background:#cf1616;color:#fff;border:0;border-radius:8px;'
     + 'padding:9px 14px;font-size:13.5px;font-weight:700;font-family:inherit;cursor:pointer}'
     + '.zr-mailhint button:hover{background:#b01212}'
     + '@media(max-width:520px){.zr-mailhint{flex-direction:column;align-items:stretch;gap:9px;bottom:76px}'
     + '.zr-mailhint button{width:100%}}';
    var st = document.createElement('style'); st.textContent = TOAST_CSS; document.head.appendChild(st);

    var box = null, timer = null;
    function toast(mail, copied){
      if (box) { clearTimeout(timer); box.remove(); }
      box = document.createElement('div');
      box.className = 'zr-mailhint';
      box.innerHTML = '<div><b>' + (copied ? 'Адрес скопирован: ' : 'Наша почта: ') + mail + '</b>'
        + '<span>Не открылся почтовый клиент? Напишите нам прямо здесь — ответим за 15 минут.</span></div>'
        + '<button type="button">Написать здесь</button>';
      document.body.appendChild(box);
      requestAnimationFrame(function(){ box.classList.add('show'); });
      box.querySelector('button').addEventListener('click', function(){
        box.remove(); box = null;
        // модалка вешается на клик по [data-zayavka] / ссылке #zayavka (см. выше)
        var trigger = document.querySelector('[data-zayavka],a[href="#zayavka"],a[href$="#zayavka"]');
        if (trigger) { trigger.click(); return; }
        location.href = '/#zayavka';
      });
      timer = setTimeout(function(){ if (box) { box.classList.remove('show');
        setTimeout(function(){ if (box) { box.remove(); box = null; } }, 260); } }, 9000);
    }

    document.addEventListener('click', function(e){
      var a = e.target && e.target.closest && e.target.closest('a[href^="mailto:"]');
      if (!a) return;
      // Почтовый клиент настроен не у всех — по клику на адрес НЕ открываем mailto:
      // (у части посетителей он «ничего не делает»), а открываем форму заявки, чтобы
      // клик всегда давал результат. Адрес тихо кладём в буфер — кто хочет написать
      // из своей почты, вставит его.
      e.preventDefault();
      var mail = (a.getAttribute('href') || '').replace(/^mailto:/i, '').split('?')[0];
      try {
        if (mail && navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(mail);
        } else if (mail) {
          var ta = document.createElement('textarea');
          ta.value = mail; ta.setAttribute('readonly','');
          ta.style.cssText = 'position:absolute;left:-9999px';
          document.body.appendChild(ta); ta.select();
          try { document.execCommand('copy'); } catch(e2) {}
          ta.remove();
        }
      } catch (e3) {}
      // Открываем модальную форму — тот же триггер, что и «Получить расчёт».
      var trigger = document.querySelector('[data-zayavka],a[href="#zayavka"],a[href$="#zayavka"]');
      if (trigger) { trigger.click(); } else { location.href = '/#zayavka'; }
    }, false);
  })(); } catch(e){}

})();
