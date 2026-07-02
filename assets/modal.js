/* Всплывающая форма-заявка (popup) — единая по всему сайту. Самодостаточный модуль. */
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
   + '.zr-modal__ov{position:absolute;inset:0;background:rgba(5,10,14,.72);backdrop-filter:blur(4px);animation:zrf .2s}'
   + '.zr-modal__box{position:relative;width:100%;max-width:460px;max-height:calc(100vh - 40px);overflow-y:auto;background:linear-gradient(160deg,#15242f,#101c26);border:1px solid #22333f;border-radius:18px;padding:30px 28px;box-shadow:0 30px 80px rgba(0,0,0,.6);animation:zru .25s}'
   + '@keyframes zrf{from{opacity:0}}@keyframes zru{from{opacity:0;transform:translateY(16px)}}'
   + '.zr-modal__x{position:absolute;top:12px;right:14px;background:none;border:0;color:#94a8b5;font-size:30px;line-height:1;cursor:pointer;padding:4px 8px}'
   + '.zr-modal__x:hover{color:#fff}'
   + '.zr-modal h3{color:#e9eff4;font-size:23px;font-weight:800;margin:0 0 6px;letter-spacing:-.01em}'
   + '.zr-modal p{color:#94a8b5;font-size:14px;margin:0 0 18px;line-height:1.45}'
   + '.zr-in,.zr-sel{width:100%;background:#0c161e;border:1px solid #22333f;color:#fff;padding:14px 16px;border-radius:10px;font-size:15px;font-family:inherit;margin-bottom:12px;box-sizing:border-box}'
   + '.zr-sel{appearance:none;-webkit-appearance:none;cursor:pointer;background-image:url("data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'16\' height=\'16\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'%23e11b1b\' stroke-width=\'3\' stroke-linecap=\'round\' stroke-linejoin=\'round\'><path d=\'M6 9l6 6 6-6\'/></svg>");background-repeat:no-repeat;background-position:right 15px center;padding-right:44px}'
   + '.zr-sel:invalid{color:#7e93a2}'
   + '.zr-sel option{color:#13212c;background:#fff}'
   + '.zr-in:focus,.zr-sel:focus{outline:none;border-color:#e11b1b}'
   + '.zr-file{margin-bottom:12px}'
   + '.zr-file label{display:flex;align-items:center;gap:10px;background:#0c161e;border:1px dashed #33485a;color:#94a8b5;padding:13px 16px;border-radius:10px;font-size:13.5px;cursor:pointer;transition:.15s}'
   + '.zr-file label:hover{border-color:#e11b1b;color:#cdd9e1}'
   + '.zr-file input{position:absolute;left:-9999px}'
   + '.zr-file .zr-fico{font-size:17px}'
   + '.zr-file.has label{border-style:solid;border-color:#2ea043;color:#b7f0c4}'
   + '.zr-consent{display:block;position:relative;padding-left:26px;font-size:12.5px;color:#94a8b5;line-height:1.45;margin:4px 0 16px}'
   + '.zr-consent input{position:absolute;left:0;top:1px;width:16px;height:16px;accent-color:#e11b1b}'
   + '.zr-consent a{color:#7fa8c4;text-decoration:underline}'
   + '.zr-btn{width:100%;background:#e11b1b;color:#fff;font-weight:700;font-size:16px;padding:15px;border:0;border-radius:10px;cursor:pointer;transition:.18s}'
   + '.zr-btn:hover{background:#c81414}'
   + '.zr-hp{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden}'
   + '.zr-res{margin-top:14px;padding:13px 15px;border-radius:10px;font-size:14px;font-weight:500;display:none}'
   + '.zr-res.show{display:block}'
   + '.zr-res.err{background:rgba(225,27,27,.14);border:1px solid #ff3b30;color:#ffc9c9}'
   + '.zr-res.ok{background:rgba(46,160,67,.16);border:1px solid #2ea043;color:#b7f0c4}'
   + '.zr-res.busy{background:rgba(127,168,196,.12);border:1px solid #33485a;color:#cdd9e1}'
   + '.zr-prog{height:8px;background:#0c161e;border:1px solid #22333f;border-radius:6px;overflow:hidden;margin-top:9px}'
   + '.zr-prog i{display:block;height:100%;width:0;background:#e11b1b;border-radius:6px;transition:width .15s ease}'
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
   + '<form id="zrModalForm" novalidate>'
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
   + '<input class="zr-in" type="tel" id="zrPhone" placeholder="+7 (___) ___-__-__" required>'
   + '<input class="zr-in" type="email" id="zrEmail" placeholder="Почта (необязательно)">'
   + '<div class="zr-file" id="zrFileBox"><label for="zrFile"><span class="zr-fico">📎</span><span id="zrFileLbl">Фото шильда, чертёж или спецификация · JPG, PDF, до 10 МБ</span></label><input type="file" id="zrFile" name="file-174" accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.dwg"></div>'
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
  function open(e){if(e)e.preventDefault();res.className='zr-res';res.innerHTML='';form.style.display='';var i=document.getElementById('zrIntro');if(i)i.style.display='';modal.hidden=false;document.body.style.overflow='hidden';setTimeout(function(){document.getElementById('zrType').focus();},50);}
  function close(){modal.hidden=true;document.body.style.overflow='';}

  modal.addEventListener('click',function(e){if(e.target.hasAttribute('data-close'))close();});
  document.addEventListener('keydown',function(e){if(e.key==='Escape'&&!modal.hidden)close();});
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
    var x=this.value.replace(/\D/g,'').match(/(\d{0,1})(\d{0,3})(\d{0,3})(\d{0,2})(\d{0,2})/);
    if(!x)return;
    if(x[1]!=='7'&&x[1]!=='8'&&x[1]!=='')x[2]=x[1]+(x[2]||'');
    x[1]='7';
    this.value=!x[2]?'+7 (':'+7 ('+x[2]+(x[3]?') '+x[3]:'')+(x[4]?'-'+x[4]:'')+(x[5]?'-'+x[5]:'');
  });
  ph.addEventListener('keydown',function(e){if(e.key==='Backspace'&&this.value.length<=4)e.preventDefault();});
  ph.addEventListener('focus',function(){if(this.value==='')this.value='+7 (';});

  form.addEventListener('submit',async function(e){
    e.preventDefault();
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
    function unlock(){btn.disabled=false;btn.textContent='Получить расчёт';}
    btn.disabled=true;btn.textContent='Отправляем…';
    show((hasFile?'Загружаем файл…':'Отправляем заявку…')+'<div class="zr-prog"><i id="zrBar"></i></div>','busy');

    var xhr=new XMLHttpRequest();
    xhr.open('POST',ACTION);
    xhr.upload.onprogress=function(ev){
      if(!ev.lengthComputable)return;
      var pct=Math.round(ev.loaded/ev.total*100);
      var bar=document.getElementById('zrBar');if(bar)bar.style.width=pct+'%';
      if(pct>=100)show('Файл загружен, обрабатываем заявку…<div class="zr-prog"><i style="width:100%"></i></div>','busy');
    };
    xhr.onload=function(){
      unlock();
      var d;try{d=JSON.parse(xhr.responseText);}catch(e){d={};}
      if(xhr.status>=200&&xhr.status<300&&d.status==='success'){
        if(window.ym)ym(109758131,'reachGoal','zayavka');
        form.reset();document.getElementById('zrFileBox').className='zr-file';document.getElementById('zrFileLbl').textContent='Фото шильда, чертёж или спецификация · JPG, PDF, до 10 МБ';
        show('Заявка принята. Инженер свяжется с вами и пришлёт КП.','ok');
        var i=document.getElementById('zrIntro');if(i)i.style.display='none';form.style.display='none';
      } else {
        show((d.message||'Не удалось отправить заявку.')+' Позвоните: +7 (495) 151-41-02.','err');
      }
    };
    xhr.onerror=function(){unlock();show('Сбой отправки. Позвоните нам: +7 (495) 151-41-02.','err');};
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
   +'@media(max-width:600px){.zrw{right:14px}.zrw__panel{width:208px}.zrw__toggle{padding:12px 18px;font-size:14px}}'
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
    var nameEl=form.querySelector('input[type="text"]:not([name="work_email"])');
    var phoneEl=form.querySelector('input[type="tel"]');
    var emailEl=form.querySelector('input[type="email"]');
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
        show((d.message||'Не удалось отправить.')+' Позвоните: +7 (495) 151-41-02.','err');
      }
    }).catch(function(){
      if(btn){btn.disabled=false;btn.textContent=_t;}
      show('Ошибка сети. Позвоните: +7 (495) 151-41-02.','err');
    });
  });
})();
