/* Всплывающая форма-заявка (popup) — единая по всему сайту. Самодостаточный модуль. */
/* Сайт light-only: переключатель тем скрыт (hdr.css). Форсим светлую и чистим прежний
   выбор — иначе тот, кто раньше успел включить «тёмную», застревал в недоведённой теме. */
try{document.documentElement.setAttribute('data-theme','light');localStorage.removeItem('zr_theme');}catch(e){}
/* Глобальная страховка: форма поиска в шапке НИКОГДА не делает нативный submit —
   иначе автоцель Яндекс.Метрики «отправка формы» засчитывает поиск как заявку. Не
   зависит от энхансера (тот гейтится .nav-right); ловит любую form.msearch на всех стр. */
document.addEventListener('submit',function(e){var f=e.target;if(f&&f.classList&&f.classList.contains('msearch')){e.preventDefault();}},true);
/* П.2 ТЗ: в шапке — 2 телефона + почта КРУПНО (почту копируют). Разметка топбара на разных
   страницах разная (email + один телефон), поэтому нормализуем её JS-энхансером на всех
   страницах, без правки 93k HTML. Идемпотентно. Стили — .tb-mail/.tb-phone в hdr.css. */
(function(){
  function enhTopbar(){
    var box=document.querySelector('.hdr2 .tb-contacts'); if(!box||box.__zrTb)return; box.__zrTb=1;
    var mail=box.querySelector('a[href^="mailto:"]');
    // убираем старые разделители «·» и прежние телефоны — соберём заново в нужном порядке
    Array.prototype.forEach.call(box.querySelectorAll('span'),function(s){s.remove();});
    Array.prototype.forEach.call(box.querySelectorAll('a[href^="tel:"]'),function(a){a.remove();});
    if(mail){ mail.classList.add('tb-mail'); mail.setAttribute('href','mailto:zr@zavod-red.ru'); mail.textContent='zr@zavod-red.ru'; }
    [['+7 (495) 151-41-02','+74951514102'],['+7 (904) 953-41-02','+79049534102']].forEach(function(p){
      var a=document.createElement('a'); a.className='tb-phone'; a.href='tel:'+p[1]; a.textContent=p[0]; box.appendChild(a);
    });
  }
  if(document.readyState!=='loading')enhTopbar(); else document.addEventListener('DOMContentLoaded',enhTopbar);
})();
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
   /* Само поле угнано за экран, поэтому при обходе с клавиатуры фокус был не виден:
      человек не понимал, на чём стоит. Рисуем рамку на видимой плашке-ярлыке. */
   + '.zr-file:focus-within label{border-color:#cf1616;box-shadow:0 0 0 3px rgba(207,22,22,.16)}'
   + '.zr-file .zr-fico{font-size:17px}'
   + '.zr-file.has label{border-style:solid;border-color:#1a7f37;color:#1a7f37;background:#f2fbf4}'
   + '.zr-consent{display:block;position:relative;padding-left:26px;font-size:12.5px;color:#526069;line-height:1.45;margin:4px 0 16px}'
   + '.zr-consent input{position:absolute;left:0;top:1px;width:16px;height:16px;accent-color:#cf1616}'
   + '.zr-consent a{color:#1f5f8b;text-decoration:underline}'
   /* Подсветка «почему кнопка не сработала»: галочка теперь не предустановлена,
      и без видимой причины отказ читается как поломка формы. */
   + '.zr-consent.need{background:#fdeceb;border:1px solid #cf1616;border-radius:9px;padding:8px 10px 8px 30px;color:#a51212;animation:zrshake .32s}'
   + '.zr-consent.need input{left:9px;top:9px}'
   + '@keyframes zrshake{0%,100%{transform:translateX(0)}25%{transform:translateX(-4px)}75%{transform:translateX(4px)}}'
   + '@media(prefers-reduced-motion:reduce){.zr-consent.need{animation:none}}'
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
   + '<button class="zr-modal__x" type="button" data-close aria-label="Закрыть">&times;</button>'
   + '<h3>Получите расчёт стоимости</h3>'
   + '<p id="zrIntro">Заполните форму — инженер свяжется в течение 15 минут, рассчитает подбор и пришлёт коммерческое предложение.</p>'
   + '<div class="zr-res"></div>'
   /* novalidate — ОБЯЗАТЕЛЬНО. Без него браузер проверяет поля САМ и, если что-то не
      сходится, вообще не порождает событие submit: наш обработчик не запускается,
      запрос не уходит, а пользователь видит лишь бледную системную подсказку, которую
      на телефоне легко не заметить. Внешне — «нажал кнопку, ничего не произошло».
      Особенно било по телефону с маской (см. ниже) и по галочке согласия.
      Все проверки делаем в JS — они показывают понятный текст прямо в форме.
      У статичных форм .lead-form novalidate стоял с самого начала, у модалки — нет. */
   + '<form id="zrModalForm" novalidate>'
   /* Ловушка для ботов. readonly + aria-hidden — чтобы автозаполнение браузера
      (особенно Яндекс.Браузера, он метит поле по имени «…email») не подставляло сюда
      почту пользователя: поле спрятано за экран, но для автозаполнения оно видимое.
      Ботам readonly не мешает — они пишут значение напрямую, и это ловит сервер. */
   + '<div class="zr-hp"><input type="text" name="work_email" tabindex="-1" autocomplete="off" readonly aria-hidden="true"></div>'
   /* Первые два поля (тип редуктора и «Ваше имя») убраны — облегчаем форму (правка ТЗ):
      достаточно телефона/почты, тип и имя инженер уточнит при звонке. Скрытые заглушки
      #zrType/#zrName сохранены (value=''), чтобы старый обработчик submit не падал на
      getElementById(...).value и product_title/CRM-поле имени продолжали работать. */
   + '<input type="hidden" id="zrType" value="">'
   + '<input type="hidden" id="zrName" value="">'
   /* Жёсткий pattern="\+7 \(\d{3}\)…" УБРАН: он требовал точь-в-точь формат маски.
      Автозаполнение (Яндекс.Браузер, Android) подставляет «+79991234567» или
      «8 999 123-45-67» — под шаблон не подходит, и браузер молча блокировал отправку.
      Номер проверяет JS по количеству цифр и говорит об этом человеческим текстом. */
   + '<input class="zr-in" type="tel" id="zrPhone" inputmode="tel" autocomplete="tel" placeholder="+7 (___) ___-__-__" required>'
   + '<input class="zr-in" type="email" id="zrEmail" placeholder="Почта" required>'
   + '<div class="zr-file" id="zrFileBox"><label for="zrFile"><span class="zr-fico">📎</span><span id="zrFileLbl">Фото шильда, чертёж или спецификация · JPG, PDF, до 10 МБ</span></label><input type="file" id="zrFile" name="file-174" accept="image/*,.jfif,.heic,.heif,.avif,.tif,.tiff,.pdf,.doc,.docx,.xls,.xlsx,.csv,.dwg,.dxf,.stp,.step,.zip,.rar"></div>'
   + '<textarea class="zr-in" id="zrMsg" rows="2" placeholder="Или опишите задачу: мощность, обороты, что заменяем"></textarea>'
   + '<button class="zr-btn" type="submit">Получить расчёт</button>'
   /* Согласие — ПОД кнопкой и БЕЗ предустановленной галочки (152-ФЗ, ст. 9: согласие
      должно быть конкретным и даваться активным действием; заранее проставленная
      галочка активным действием не считается — это прямой риск по проверке РКН).
      Формулировка от первого лица («Я даю согласие»), а не «нажимая кнопку, вы
      соглашаетесь»: последнее — про подразумеваемое согласие, которого для ПДн мало.
      Чтобы снятая галочка не превратилась в «кнопка не работает», при попытке
      отправки без неё блок подсвечивается, прокручивается в зону видимости и
      получает фокус — см. needConsent() ниже. */
   + '<label class="zr-consent" id="zrConsentLbl"><input type="checkbox" id="zrConsent" required> Я даю согласие на обработку моих персональных данных согласно <a href="'+PRIVACY+'" target="_blank" rel="noopener">политике конфиденциальности</a>.</label>'
   + '</form></div></div>';
  var wrap=document.createElement('div');wrap.innerHTML=html;document.body.appendChild(wrap.firstChild);

  var modal=document.getElementById('zrModal');
  var form=document.getElementById('zrModalForm');
  var ph=document.getElementById('zrPhone');
  var fileInp=document.getElementById('zrFile');
  // A11y: у полей формы только placeholder — даём доступные имена для скринридеров (select без имени вовсе).
  var _aria={zrType:'Тип заявки',zrName:'Ваше имя',zrPhone:'Телефон',zrEmail:'Электронная почта',zrMsg:'Сообщение'};
  Object.keys(_aria).forEach(function(id){var el=document.getElementById(id);if(el&&!el.getAttribute('aria-label'))el.setAttribute('aria-label',_aria[id]);});
  var res=modal.querySelector('.zr-res');
  function show(m,t){res.className='zr-res show'+(t?' '+t:'');res.innerHTML=m;var box=modal.querySelector('.zr-modal__box');if(box)box.scrollTop=0;}
  /* Галочка согласия больше не предустановлена (152-ФЗ). Чтобы отказ не выглядел
     «кнопка не нажимается», объясняем причину сразу тремя способами: текст в шапке
     формы, подсветка самой галочки и фокус на ней. Сообщение в .zr-res прокручивает
     форму наверх, поэтому галочку дополнительно подтягиваем в зону видимости. */
  function needConsent(){
    var lbl=document.getElementById('zrConsentLbl'),cb=document.getElementById('zrConsent');
    show('Чтобы отправить заявку, отметьте согласие на обработку персональных данных — галочка под кнопкой.','err');
    if(lbl){lbl.classList.remove('need');void lbl.offsetWidth;lbl.classList.add('need');
      try{lbl.scrollIntoView({block:'nearest'});}catch(e){}}
    if(cb){try{cb.focus({preventScroll:true});}catch(e){cb.focus();}}
  }
  (function(){var cb=document.getElementById('zrConsent');if(!cb)return;
    cb.addEventListener('change',function(){if(cb.checked){var l=document.getElementById('zrConsentLbl');if(l)l.classList.remove('need');}});})();
  var _lastFocus=null;
  function open(e){if(e)e.preventDefault();_lastFocus=document.activeElement;res.className='zr-res';res.innerHTML='';var _cl=document.getElementById('zrConsentLbl');if(_cl)_cl.classList.remove('need');form.style.display='';var i=document.getElementById('zrIntro');if(i)i.style.display='';modal.hidden=false;lockScroll(true);setTimeout(function(){var f=document.getElementById('zrPhone');if(f)f.focus();},50);}
  function close(){modal.hidden=true;lockScroll(false);if(_lastFocus&&_lastFocus.focus){try{_lastFocus.focus();}catch(e){}}}
  /* Блокировка фона. Одного overflow:hidden на <body> НЕ хватало: на части страниц
     (contacts, catalog — там у html/body своя вёрстка) прокручивается корневой
     элемент, а не body, и страница уезжала под открытой формой — человек
     возвращался после закрытия совсем в другое место. Глушим оба и в придачу
     фиксируем позицию: iOS Safari игнорирует overflow на html/body при
     инерционном скролле. */
  var _scrollY=0;
  function lockScroll(on){
    var b=document.body,h=document.documentElement;
    if(on){
      _scrollY=window.pageYOffset||h.scrollTop||0;
      h.style.overflow='hidden';
      b.style.overflow='hidden';
      b.style.position='fixed';b.style.top=(-_scrollY)+'px';b.style.left='0';b.style.right='0';b.style.width='100%';
    }else{
      h.style.overflow='';
      b.style.overflow='';
      b.style.position='';b.style.top='';b.style.left='';b.style.right='';b.style.width='';
      window.scrollTo(0,_scrollY);
    }
  }
  function trapTab(e){
    if(modal.hidden||e.key!=='Tab')return;
    var box=modal.querySelector('.zr-modal__box'); if(!box)return;
    var f=[].slice.call(box.querySelectorAll('a[href],button:not([disabled]),input:not([disabled]):not([type=hidden]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])')).filter(function(el){
      // Ловушка для ботов (.zr-hp) спрятана за экран left:-9999px, поэтому
      // offsetParent у неё НЕ null и она попадала в список как «первый» элемент:
      // Shift+Tab с телефона уводил фокус в невидимое поле. Выкидываем её и всё,
      // что явно убрано из tab-порядка.
      if(el.closest&&el.closest('.zr-hp'))return false;
      if(el.getAttribute('tabindex')==='-1')return false;
      return el.offsetParent!==null;
    });
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
    /* Honeypot НЕ проверяем на клиенте. Поле work_email спрятано за экран
       (left:-9999px), то есть для автозаполнения браузера оно обычное видимое поле,
       а имя содержит «email» — Яндекс.Браузер подставлял в него сохранённую почту.
       Прежний `if(value!=='')return;` на этом молча обрывал отправку: ни запроса, ни
       сообщения. В Chrome автозаполнение осторожнее, поэтому там форма работала —
       ровно жалоба «с Яндекса не отправляется, с Гугла отправляется».
       Смысла в этой проверке и не было: ниже в запрос всегда кладётся ПУСТОЙ
       work_email. Ловушка от ботов живёт на сервере (feedback.php) и не пострадала. */
    var type=document.getElementById('zrType').value;
    /* Проверка имени убрана: поле удалено из формы (облегчение ТЗ). Имя уточняет инженер. */
    /* Достаточно ОДНОГО контакта — телефон ИЛИ почта. Требовать оба — лишний барьер
       (особенно на мобиле), лиды отваливались. Цифры телефона считаем, а не длину маски:
       автозаполнение вставляет свой формат, и проверка по длине строки отвергала верный номер. */
    // Автофокус телефона подставляет префикс «+7 (» (1 цифра). Считаем телефон с ≤1 цифрой
    // ПУСТЫМ, иначе заявка «только по почте» падала с ошибкой «введите телефон полностью»
    // (регрессия облегчённой формы: терялись email-лиды на десктопе).
    var _phDigits=ph.value.replace(/\D/g,'');
    var _phRaw=(_phDigits.length<=1)?'':ph.value.trim(), _phOk=_phDigits.length>=11;
    var _em=document.getElementById('zrEmail').value.trim();
    var _emOk=_em!=='' && /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(_em);
    /* Порядок проверок важен. Раньше первым шло «оставьте телефон или почту», и
       человек, набравший половину номера или почту с опечаткой, получал именно его:
       поле-то он заполнил, а ему отвечают «оставьте контакт». Сначала разбираем то,
       что человек уже начал вводить, и только если оба поля пусты — просим контакт. */
    if(_phRaw!=='' && !_phOk){show('Введите телефон полностью: +7 (XXX) XXX-XX-XX.','err');ph.focus();return;}
    if(_em!=='' && !_emOk){show('Проверьте почту: похоже, в адресе опечатка.','err');document.getElementById('zrEmail').focus();return;}
    if(!_phOk && !_emOk){show('Оставьте телефон или почту — как с вами связаться.','err');ph.focus();return;}
    if(!document.getElementById('zrConsent').checked){needConsent();return;}
    if(fileInp.files&&fileInp.files[0]&&fileInp.files[0].size>MAXB){show('Файл больше 10 МБ. Сожмите его или отправьте заявку без файла — мы запросим его в ответ.','err');return;}
    var fd=new FormData();
    fd.append('work_email','');
    fd.append('text-562',document.getElementById('zrName').value||'Клиент с сайта');
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
    /* 90 с, а не 30: сервер отвечает сразу только под PHP-FPM (fastcgi_finish_request).
       Под mod_php ответ ждёт Telegram и SMTP — это десятки секунд, и короткий порог
       рвал УЖЕ СОХРАНЁННУЮ заявку, показывая ложное «пропала связь». Таймер сбрасываем
       и на получении заголовков ответа: с этого момента сервер жив, ждать можно. */
    function _bump(){ clearTimeout(_stall); _stall=setTimeout(function(){ try{xhr.abort();}catch(_e){} }, 90000); }
    xhr.onprogress=_bump;
    xhr.onreadystatechange=function(){ if(xhr.readyState===2)_bump(); };
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
    /* send() может бросить синхронно (например, блокировка расширением). Без catch
       кнопка навсегда оставалась бы «Отправляем…»: unlock() не вызван, флаг __zrSending
       не снят, и повторные нажатия молча игнорировались. */
    try{ xhr.send(fd); }
    catch(_e){ clearTimeout(_stall); unlock(); if(window.ym)ym(109758131,'reachGoal','zayavka_error');
      show('Не удалось отправить заявку. Позвоните: +7 (495) 151-41-02.','err'); }
  });
})();

/* ===== Плавающий виджет «Напишите нам» (MAX / заявка) ===== */
(function(){
  // ⚠️ ССЫЛКИ-ЗАГЛУШКИ — заменить на реальные:
  var TG   = 'https://t.me/+79511178737';           // по номеру (работает, если в приватности TG «номер видят все»)
  // MAX. Ссылка по номеру (max.ru/+7995…) отдаёт 404 — проверено 05.09.2026 курлом:
  // кнопка в виджете вела в никуда. Рабочий формат сейчас только веб-клиентский,
  // с идентификатором профиля; max.ru/u/<id>, max.ru/id<id>, max.ru/chat/<id> тоже 404.
  // Если снова отвалится — проверять формат курлом, а не полагаться на вид ссылки.
  var MAX  = 'https://web.max.ru/395024719';        // +7 995 380-46-01
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

  /* MAX в виджете — полноценный канал связи (с отдельной целью messenger_max).
     Заявка открывает модальную форму (цель zayavka по факту отправки).
     ВАЖНО: если в Метрике включены АВТОЦЕЛИ («клик по мессенджеру», «клик по email»,
     «отправка формы»), они срабатывают сами, мимо этого кода, и в Директе могут
     учитываться как конверсии. Отключается только в интерфейсе Метрики:
     Настройка счётчика → Цели → автоцели; в Директе — оставить конверсией zayavka
     и messenger_max. Кодом автоцели не выключаются. */
  var html=''
   +'<div class="zrw" id="zrWidget">'
   +'<div class="zrw__panel">'
   +'<a class="zrw__item ai" href="#" data-zrchat>'+chat+'Спросить ИИ-консультанта</a>'
   +'<a class="zrw__item mx" href="'+MAX+'" target="_blank" rel="noopener">'+mx+'MAX</a>'
   +'<a class="zrw__item ml" href="#zayavka" data-zayavka>'+ml+'Оставить заявку</a>'
   +'</div>'
   +'<button class="zrw__toggle" type="button" aria-label="Написать нам"><span class="zrw__dot"></span>'+chat+'<span class="zrw__lbl-open">Напишите нам</span><span class="zrw__lbl-close">Закрыть</span></button>'
   +'</div>';
  var wrap=document.createElement('div');wrap.innerHTML=html;document.body.appendChild(wrap.firstChild);

  var w=document.getElementById('zrWidget');
  /* Умный чат (ИИ-консультант) — грузится лениво, только по клику: assets/zr-chat.js. */
  window.zrOpenChat=function(){
    if(window.zrChatOpen){window.zrChatOpen();return;}
    var s=document.createElement('script');s.src='/assets/zr-chat.js?v=1';s.async=true;
    s.onload=function(){if(window.zrChatOpen)window.zrChatOpen();};document.head.appendChild(s);
  };
  document.addEventListener('click',function(e){
    var c=e.target.closest&&e.target.closest('[data-zrchat]');
    if(!c)return; e.preventDefault(); w.classList.remove('open'); window.zrOpenChat();
  });
  w.querySelector('.zrw__toggle').addEventListener('click',function(){w.classList.toggle('open');});
  document.addEventListener('click',function(e){if(!w.contains(e.target))w.classList.remove('open');});

  /* MAX → мессенджер (цель messenger_max), Заявка → форма (zayavka по факту отправки). */
  w.addEventListener('click',function(e){
    var a=e.target.closest&&e.target.closest('.zrw__item');
    if(!a||!window.ym)return;
    if(a.classList.contains('mx')){ try{ ym(109758131,'reachGoal','messenger_max'); }catch(_e){} }
  });
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
   +'.zr-mbar .zrmb-ai{flex:0 0 56px;font-weight:800}'
   +'@media(max-width:1024px){.zr-mbar{display:flex}body{padding-bottom:70px}.zrw{display:none}.zr-cookie{bottom:80px}}';
  var st=document.createElement('style');st.textContent=css;document.head.appendChild(st);
  var phone='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.8 19.8 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.8 19.8 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/></svg>';
  var spark='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M5 12V5a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2h-5"/><path d="m3 21 3-3-3-3"/><path d="M9 18H4"/></svg>';
  var bar=document.createElement('div');
  bar.className='zr-mbar';
  bar.innerHTML='<a class="zrmb-call" href="tel:'+TEL+'">'+phone+'Позвонить</a>'
    +'<a class="zrmb-call zrmb-ai" href="#" data-zrchat aria-label="Спросить ИИ-консультанта">ИИ</a>'
    +'<a class="zrmb-lead" href="#zayavka" data-zayavka>'+spark+'Получить расчёт</a>';
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
  /* Подсветка неотмеченного согласия на статичных формах (445 страниц). Стили кладём
     отсюда, а не в inner.css: модуль подключён везде, а разметку страниц не трогаем. */
  try{var _s=document.createElement('style');
    _s.textContent='.lead-form label.zr-need,.lead-form .consent.zr-need{display:block;background:#fdeceb;'
      +'border:1px solid #cf1616;border-radius:9px;padding:8px 10px;color:#a51212;animation:zrneed .32s}'
      +'@keyframes zrneed{0%,100%{transform:translateX(0)}25%{transform:translateX(-4px)}75%{transform:translateX(4px)}}'
      +'@media(prefers-reduced-motion:reduce){.lead-form label.zr-need{animation:none}}';
    document.head.appendChild(_s);}catch(_e){}
  document.addEventListener('change',function(e){
    var c=e.target; if(!c||c.type!=='checkbox'||!c.checked)return;
    var l=c.closest&&(c.closest('label')||c.parentNode);
    if(l&&l.classList)l.classList.remove('zr-need');
  },true);
  document.addEventListener('submit',function(e){
    var form=e.target;
    if(!form.classList||!form.classList.contains('lead-form'))return;
    e.preventDefault();
    /* Двойная отправка. Кнопку мы ниже блокируем (btn.disabled), но форму можно
       отправить и с клавиатуры — Enter в любом текстовом поле порождает submit мимо
       кнопки. Без флага нетерпеливый человек создавал два одинаковых лида в CRM. */
    if(form.__zrSending)return;
    // Honeypot на клиенте не проверяем — см. пояснение у модальной формы выше:
    // Яндекс.Браузер автозаполняет спрятанное поле work_email, и форма молча умирала.
    // В запрос уходит пустое значение, серверная ловушка от ботов работает как прежде.
    var res=form.querySelector('.form-result');
    /* Класс show ОБЯЗАТЕЛЕН: .form-result скрыт (display:none), показывает его только
       .form-result.show (inner.css). Без него заявка уходила молча — ни «Заявка принята»,
       ни ошибок валидации/сети пользователь не видел, и отправка выглядела как зависание. */
    function show(msg,cls){ if(res){res.textContent=msg;res.className='form-result show'+(cls?' '+cls:'');} else { alert(msg); } }
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
    // Облегчённый режим (атрибут data-light="1" на форме): имя необязательно, достаточно
    // телефона ИЛИ почты — как в модалке (правка ТЗ). Формы БЕЗ атрибута (напр. contacts)
    // сохраняют строгую проверку: имя + почта обязательны.
    var light = form.getAttribute && form.getAttribute('data-light')==='1';
    var _emOk = email!=='' && /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email);
    var _phOk = phone.replace(/\D/g,'').length>=10;
    if(light){
      if(!_phOk && !_emOk){ show('Оставьте телефон или почту — как с вами связаться.','err'); return; }
      if(phone!=='' && !_phOk && !_emOk){ show('Введите телефон полностью или укажите почту.','err'); return; }
      if(email!=='' && !_emOk){ show('Проверьте почту: похоже, в адресе опечатка.','err'); return; }
    }else{
      if(!name){ show('Укажите имя.','err'); return; }
      /* Почта обязательна и здесь: уходит в Reply-To, по ней отвечают клиенту и по ней
         CRM/Битрикс видит внешнего собеседника. Проверяем, только если поле есть в форме. */
      if(emailEl){
        if(email===''){ show('Укажите почту — на неё пришлём коммерческое предложение.','err'); return; }
        if(!_emOk){ show('Проверьте почту: похоже, в адресе опечатка.','err'); return; }
      }
      if(phoneEl&&!_phOk&&!email){ show('Укажите корректный телефон или email.','err'); return; }
    }
    /* Начатый, но недобранный номер («+7 (495) 15») раньше уезжал в CRM как есть,
       если почта была валидна: менеджер получал лид с нерабочим телефоном. Пустое
       поле и «затравку» маски («+7 (») по-прежнему считаем отсутствием номера. */
    if(phoneEl && phone.replace(/\D/g,'').length>1 && !_phOk){
      show('Введите телефон полностью: +7 (XXX) XXX-XX-XX — или очистите поле.','err');
      try{phoneEl.focus();}catch(_e){} return;
    }
    if(consent&&!consent.checked){
      show('Чтобы отправить заявку, отметьте согласие на обработку персональных данных.','err');
      var _cl=consent.closest('label')||consent.parentNode;
      if(_cl&&_cl.classList){_cl.classList.remove('zr-need');void _cl.offsetWidth;_cl.classList.add('zr-need');}
      try{consent.focus({preventScroll:true});}catch(_e){try{consent.focus();}catch(_e2){}}
      return;
    }
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
    form.__zrSending=1;
    show('Отправляем заявку…','busy');
    /* Таймаут: у fetch его нет по умолчанию, и при мёртвой связи кнопка «Отправляем…»
       висела бесконечно — заявку не отправить и непонятно почему. 45 с с запасом на
       медленный мобильный интернет, дальше — понятная ошибка с телефоном. */
    var _ac=('AbortController' in window)?new AbortController():null;
    var _to=setTimeout(function(){ if(_ac){try{_ac.abort();}catch(_e){}} },45000);
    fetch(ACTION,_ac?{method:'POST',body:fd,signal:_ac.signal}:{method:'POST',body:fd}).then(function(r){return r.json().catch(function(){return {};});}).then(function(d){
      clearTimeout(_to);
      form.__zrSending=0;
      if(btn){btn.disabled=false;btn.textContent=_t;}
      if(d.status==='success'||d.ok){
        if(window.ym)ym(109758131,'reachGoal','zayavka');
        form.reset();
        show('Заявка принята. Инженер свяжется с вами и пришлёт КП.','ok');
      } else {
        if(window.ym)ym(109758131,'reachGoal','zayavka_error');
        show((d.message||'Не удалось отправить.')+' Позвоните: +7 (495) 151-41-02.','err');
      }
    }).catch(function(err){
      clearTimeout(_to);
      form.__zrSending=0;
      if(btn){btn.disabled=false;btn.textContent=_t;}
      if(window.ym)ym(109758131,'reachGoal','zayavka_error');
      var aborted=err&&err.name==='AbortError';
      show(aborted?'Похоже, пропала связь — заявка не ушла. Позвоните: +7 (495) 151-41-02, или попробуйте ещё раз.'
                 :'Ошибка сети. Позвоните: +7 (495) 151-41-02.','err');
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
    var STOP={редуктор:1,редуктора:1,редукторы:1,мотор:1,моторредуктор:1,привод:1,приводы:1,купить:1,аналог:1,цена:1,gear:1,gearbox:1,gearmotor:1,motor:1,motoreducer:1,reducer:1,drive:1,buy:1,price:1};
    /* Единицы измерения — не считаем их поисковыми токенами (значение берём отдельно). */
    var UNIT={квт:1,kw:1,kwt:1,вт:1,нм:1,nm:1,об:1,обмин:1,rpm:1,i:1,и:1,передат:1,передаточное:1,мощность:1,момент:1};
    var IMG={0:'cat_worm',1:'cat_coaxial',2:'cat_bevel',3:'cat_flat',4:'cat_cylindrical'};
    /* ═══ Умный разбор запроса ═══
       Клиент почти никогда не пишет так, как лежит в базе. Четыре типовых расхождения:
       1) забыл переключить раскладку — «ыуц» вместо «sew», «мщквж» вместо «nord»;
       2) пишет бренд кириллицей или наше обозначение латиницей — «нмрв», «MP 2»;
       3) опускает ведущий ноль — «NMRV 63», а в каталоге «NMRV 063» (таких моделей 73);
       4) спрашивает не модель, а раздел — «планетарный», «вариатор», «чертежи». */
    var KB_RU='йцукенгшщзхъфывапролджэячсмитьбю', KB_EN="qwertyuiop[]asdfghjkl;'zxcvbnm,.";
    function swapLay(str){ var ru=0,en=0,i,c,out='';
      for(i=0;i<str.length;i++){ c=str[i].toLowerCase(); if(KB_RU.indexOf(c)>=0)ru++; else if(KB_EN.indexOf(c)>=0)en++; }
      if(!ru&&!en)return str;
      var from=ru>=en?KB_RU:KB_EN, to=ru>=en?KB_EN:KB_RU;
      for(i=0;i<str.length;i++){ var j=from.indexOf(str[i].toLowerCase()); out+= j>=0?to[j]:str[i]; }
      return out; }
    var TRL={'а':'a','б':'b','в':'v','г':'g','д':'d','е':'e','ж':'zh','з':'z','и':'i','й':'y','к':'k','л':'l','м':'m','н':'n','о':'o','п':'p','р':'r','с':'s','т':'t','у':'u','ф':'f','х':'h','ц':'c','ч':'ch','ш':'sh','щ':'sch','ъ':'','ы':'y','ь':'','э':'e','ю':'yu','я':'ya'};
    function translit(str){ return String(str).toLowerCase().replace(/ё/g,'е').replace(/[а-я]/g,function(c){ return TRL[c]!=null?TRL[c]:c; }); }
    /* Визуально одинаковые буквы: «МР» кириллицей и «MP» латиницей выглядят одинаково,
       но для машины это разные строки. Схлопываем — только на коротких кодах, иначе
       русские слова («соосный») превратились бы в кашу. */
    var HOMO={'а':'a','в':'b','с':'c','е':'e','к':'k','м':'m','н':'h','о':'o','р':'p','т':'t','у':'y','х':'x'};
    function homo(t){ return t.length>4?t:String(t).replace(/[авсекмнортух]/g,function(c){return HOMO[c];}); }
    /* Разделы сайта: ответ на общий запрос, когда конкретной модели в нём нет. */
    var SECTIONS=[
      {t:'Мотор-редукторы ZR',d:'Заводская маркировка · 8 397 исполнений',u:'/motor-reduktor-zr/',k:['zr','зр','шильд','маркировк']},
      {t:'Планетарные мотор-редукторы МР',d:'МР1–МР3, МПО, 3МП',u:'/catalog/mr',k:['мр','mr','мпо','mpo','3мп','планетарн','planetar','planet']},
      {t:'Редукторы и мотор-редукторы ПР',d:'40 типоразмеров · все типы передач',u:'/catalog/pr',k:['пр','pr']},
      {t:'Вариаторы',d:'Бесступенчатое регулирование скорости',u:'/catalog/variatory.html',k:['вариатор','variator','varispeed','бесступенчат','udl']},
      {t:'Червячные мотор-редукторы',d:'Самоторможение, высокое передаточное',u:'/catalog/chervyachnye.html',k:['червяч','worm','nmrv','рчу','чм']},
      {t:'Соосные цилиндрические мотор-редукторы',d:'Мотор и передача на одной оси',u:'/catalog/soosnye.html',k:['соосн','coaxial','inline']},
      {t:'Цилиндрические редукторы',d:'Эвольвентное зацепление, высокий КПД',u:'/catalog/cilindricheskie.html',k:['цилиндр','helical','spur']},
      {t:'Цилиндро-конические мотор-редукторы',d:'Валы под прямым углом',u:'/catalog/konichesko-cilindricheskie.html',k:['коническ','bevel','угловой']},
      {t:'Плоские цилиндрические мотор-редукторы',d:'Для ограниченного монтажного пространства',u:'/catalog/ploskie.html',k:['плоск','flat','parallel']},
      {t:'Импортозамещение',d:'Аналоги SEW, NORD, Bonfiglioli и других',u:'/importozameshchenie',k:['импорт','аналог','замен','замещ','analog','substitut','replace']},
      {t:'Подбор по параметрам',d:'Момент, мощность, обороты — онлайн',u:'/podbor',k:['подбор','подобрат','калькулятор','расчет','параметр','select','calc']},
      {t:'Чертежи',d:'Габаритные и присоединительные размеры',u:'/chertezhi',k:['чертеж','габарит','размер','drawing','dwg','dxf']},
      {t:'Цены',d:'Прайс и условия поставки',u:'/ceny',k:['цена','цены','прайс','стоимост','скольк','price','cost']},
      {t:'Весь каталог',d:'Все серии, типы передач и типоразмеры',u:'/catalog/',k:['каталог','catalog','редуктор','мотор','привод','gearbox','gearmotor','reducer','gear','motor','drive']}
    ];
    SECTIONS.forEach(function(x){ x.__k=x.k.map(homo); });
    /* Наши серии клиент пишет как придётся: «МР» кириллицей, «MR» латиницей, «MP» —
       латиницей по форме кириллических букв. Все написания сводим к одному набору. */
    var ALIAS={mp:['мр','mr'],mr:['мр','mr'],'мр':['mr','mp'],pr:['пр','pr'],'пр':['pr'],
      zr:['зр'],'зр':['zr'],evl:['евл'],'евл':['evl'],'нмрв':['nmrv'],nmrv:['нмрв'],
      'чм':['chm'],chm:['чм'],'мпо':['mpo'],mpo:['мпо'],
  /* Кириллические написания марок: клиент набирает «мотоварио», «бонфиглиоли»,
     «сев евродрайв» — в индексе они латиницей, и выдача была пустой. */
  'мотоварио':['motovario'],'бонфиглиоли':['bonfiglioli'],'транстехно':['transtecno'],
  'евродрайв':['sew','eurodrive'],'сев':['sew'],'сью':['sew'],'норд':['nord'],
  'ленце':['lenze'],'ленза':['lenze'],'бауэр':['bauer'],'байер':['bauer'],
  'йилмаз':['yilmaz'],'илмаз':['yilmaz'],'варвел':['varvel'],'вармек':['varmec'],
  'росси':['rossi'],'сити':['siti'],'трамек':['tramec'],'вемпер':['vemper'],
  'флендер':['flender'],'сименс':['siemens'],'кеб':['keb'],'гуомао':['guomao'],
  'бонэнг':['boneng'],'иннорэд':['innored'],'инновари':['innovari'],
  'ватт':['watt'],'тос':['tos'],'зноймо':['znojmo'],'штм':['stm'],
  'юнитдрайв':['unidrive'],'юнит':['unidrive'],
  'моторедуктор':['мотор','редуктор'],'мотор-редуктор':['мотор','редуктор']};
    /* Ключ короче 4 знаков сверяем целиком («пр», «zr»), длинный — по началу слова. */
    function secHit(sec,qt){ for(var i=0;i<qt.length;i++){ var q=homo(qt[i]);
      for(var j=0;j<sec.__k.length;j++){ var k=sec.__k[j];
        if(k.length<=3){ if(q===k)return 1; }
        else if(q.length>=4&&(q.indexOf(k)===0||k.indexOf(q)===0))return 1; } }
      return 0; }
    function secFind(qt){ var r=[]; for(var i=0;i<SECTIONS.length&&r.length<4;i++) if(secHit(SECTIONS[i],qt)) r.push(SECTIONS[i]); return r; }
    function nn(s){return String(s==null?'':s).toLowerCase().replace(/ё/g,'е').replace(/[^0-9a-zа-я]+/g,'');}
    function tk(s){return String(s==null?'':s).toLowerCase().replace(/ё/g,'е').split(/[^0-9a-zа-я]+/).filter(Boolean);}
    function esc(s){return String(s==null?'':s).replace(/[<>&"]/g,function(c){return {'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;'}[c];});}
    /* Расстояние Левенштейна с потолком: короткое слово — 1 правка, длинное — 2.
       «бонфильоли» и «бонфиглиоли» расходятся на две буквы, при потолке 1 не сходились. */
    function levOk(a,b,max){ var la=a.length,lb=b.length; if(Math.abs(la-lb)>max)return false;
      var prev=[],cur=[],i,j; for(j=0;j<=lb;j++)prev[j]=j;
      for(i=1;i<=la;i++){ cur[0]=i; var best=i;
        for(j=1;j<=lb;j++){ cur[j]=Math.min(prev[j]+1,cur[j-1]+1,prev[j-1]+(a[i-1]===b[j-1]?0:1)); if(cur[j]<best)best=cur[j]; }
        if(best>max)return false; prev=cur.slice(); }
      return prev[lb]<=max; }
    function fuzz(a,b){ var m=(a.length>=6&&b.length>=6)?2:1; return levOk(a,b,m); }
    var IDX=null,DF=null,T=null,loading=false,IMPB=null,BNAME={},VALS=[],BQ='',SOFT=false,LASTQT=[],QN=0,FORCEB='';
    /* Диапазон «0,1–30» / «4–303» → [lo,hi] числами (десятичная запятая, разные тире). */
    function rng(s){ if(!s)return null; var p=String(s).split(/[–—-]/); function n(x){x=String(x).replace(',','.').replace(/[^\d.]/g,'');return x?parseFloat(x):NaN;} var a=n(p[0]),b=p.length>1?n(p[1]):a; if(isNaN(a))return null; if(isNaN(b))b=a; return a<=b?[a,b]:[b,a]; }
    /* Из запроса вынимаем числовые значения с единицей (кВт/i) и «очищенный» текст под токены. */
    function qvals(raw){ var s=' '+String(raw==null?'':raw).toLowerCase().replace(/ё/g,'е')+' '; var vals=[];
      function grab(re,u){ s=s.replace(re,function(m,d){ var v=parseFloat(String(d).replace(',','.')); if(!isNaN(v))vals.push({v:v,u:u}); return ' '; }); }
      grab(/(\d+(?:[.,]\d+)?)\s*(?:квт|kwt|kw)(?![а-яa-z])/gi,'pw');
      grab(/\bi\s*[=:]?\s*(\d+(?:[.,]\d+)?)\b/g,'i');
      grab(/(\d+(?:[.,]\d+)?)\s*передат[а-я]*/g,'i');
      grab(/i\s*[=:]?\s*(\d+(?:[.,]\d+)?)(?![а-яa-z])/g,'i');
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
      IMPB={}; BNAME={}; imp.forEach(function(r){ bkeys(r.b).forEach(function(k){IMPB[k]=1; if(!BNAME[k])BNAME[k]=r.b;}); });
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
    /* «NMRV 63» ↔ «NMRV 063»: клиент опускает ведущий ноль, каталог его хранит. */
    function hasTok(gr,q){ if(gr.__t[q])return 1;
      if(/^\d{1,2}$/.test(q)&&gr.__t[('00'+q).slice(-3)])return 1;
      return 0; }
    function score(qt,qf,gr){ var s=0,m=0,mt=0,cm=IDX.length*0.9;
      /* Бренд в запросе задан — держим выдачу строго внутри этого бренда. */
      if(BQ&&!SOFT){ if(!gr.__imp||!gr.__t[BQ])return 0; }
      if(qf&&gr.__t[qf]){s+=90;m++;mt++;}
      for(var i=0;i<qt.length;i++){var q=qt[i],b=0,h=false;
        if(q.length<2){ continue; }   /* «2», «к» — слишком общо, по ним не ранжируем */
        if(hasTok(gr,q)){ if((DF[q]||0)>=cm)b=4; else {b=(/\d/.test(q)?55:26);h=true;} }
        else if(q.length>=3&&gr.__b.indexOf(q)>=0){b=12;h=true;}
        else if(/^\d+$/.test(q)&&q.length>=2&&gr.__nums){ for(var n=0;n<gr.__nums.length;n++){ if(gr.__nums[n]!==q&&gr.__nums[n].indexOf(q)===0){b=30;h=true;break;} } }
        if(!h&&q.length>=4){for(var t in gr.__t){if(Math.abs(t.length-q.length)<=2&&fuzz(t,q)){b=9;h=true;break;}}}
        if(h){m++;mt++;} s+=b; }
      /* Есть текстовые токены (тип/модель/бренд), но ни один не совпал — запись не наша. */
      if(qt.length&&mt===0&&!(SOFT&&VALS.length))return 0;
      /* Значение (кВт / передаточное i / типоразмер) — сортируем по попаданию в диапазон. */
      if(VALS.length){ for(var k=0;k<VALS.length;k++){ var vb=valFit(VALS[k],gr); if(vb>0){s+=vb;m++;} } }
      if(!m)return 0; if(!SOFT&&!BQ&&QN>=2&&mt<Math.ceil(QN/2))return 0; return s+m*4;
    }
    function rank(query){ var qv=qvals(query);
      /* Токены из «очищенного» запроса (без единиц и значений); «R107» слитно → «r»+«107». */
      var qt0=tk(qv.rest).filter(function(t){var k=nn(t);return !STOP[k]&&!UNIT[k];}).map(nn).filter(Boolean);
      var qt=[],seen={}; function push(x){ if(x&&!seen[x]){seen[x]=1;qt.push(x);} }
      qt0.forEach(function(q){ push(q); (ALIAS[q]||[]).forEach(push);
        var mm=q.match(/^([a-zа-я]+)(\d+)$/)||q.match(/^(\d+)([a-zа-я]+)$/);
        if(mm){ if(mm[1]){push(mm[1]);(ALIAS[mm[1]]||[]).forEach(push);} if(mm[2])push(mm[2]); } });
      VALS=qv.vals; BQ=qBrand(qt)||FORCEB; LASTQT=qt; QN=qt0.length;
      if(!qt.length&&!VALS.length)return[];
      /* Тип передачи в запросе → жёсткий фильтр по типу (bevel/worm/…). */
      var qType=null; for(var ti=0;ti<qt.length;ti++){ for(var w in TYPEW){ if(qt[ti].length>=4&&(qt[ti].indexOf(w)===0||w.indexOf(qt[ti])===0)){ qType=TYPEW[w]; break; } } if(qType!=null)break; }
      var qf=nn(qv.rest),qb=qt.map(brandFor);
      /* Пришёл по бренду импорта — обезличенные ZR-группы в выдачу не пускаем вообще:
         пользователь должен попасть на брендовую страницу, а не на нашу карточку. */
      var pool=(BQ&&!SOFT)?IDX.filter(function(r){return r.__imp;}):IDX;
      if(qType!=null&&!SOFT)pool=pool.filter(function(r){return r.t===qType;});
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
    /* Разделы сверяем по СЫРЫМ токенам: «редуктор» и «мотор» — стоп-слова для моделей,
       но именно они означают «покажи каталог». */
    /* «мр2» слитно — тоже про раздел МР, поэтому режем на буквы+цифры. */
    /* МР и ПР — один и тот же типоразмер: мотор-редуктор и редуктор без двигателя.
       Номера совпадают во всех 42 строках заводской таблицы соответствия, а в поисковом
       индексе лежит только ПР. Поэтому «МР 117» доспрашиваем как «ПР 117». */
    function mrToPr(str){ return String(str||'').replace(/(^|[^а-яa-z0-9])(мр|mr|mp)\s*[- ]?\s*(\d{3,4})/gi,
      function(_,a,b,n){ return a+'ПР '+n; }); }
    /* Бренд СТРАНИЦЫ, а не запроса. Клиент на /brands/keb вводит «RC 97» и не понимает,
       почему ему показывают SEW: он-то смотрит KEB. Контекст страницы важнее общей выдачи. */
    function pageBrand(){ var p=location.pathname, m;
      if((m=p.match(/^\/brands\/([a-z0-9\-]+)/i))) return nn(m[1].split('-')[0]);
      if((m=p.match(/^\/analog\/([a-z]+)-/i))) return nn(m[1]);
      return ''; }
    /* Все 25 страниц марок. Нужны по двум причинам: у части брендов (KEB, Flender,
       Siemens, Boneng, Guomao, UNI Drive) в базе НЕТ перечня моделей — по обозначению они
       не находятся, и клиент упирался в пустоту либо в чужой бренд. Теперь такой запрос
       всегда даёт хотя бы страницу марки. */
    var BPAGES=[
      {s:'bauer',n:'Bauer',k:['bauer','бауэр','баурер','байер']},
      {s:'boneng',n:'Boneng',k:['boneng','бонэнг','k303','k305','k307','k309','k311','k313','k315','k318','бонэн','бонинг']},
      {s:'bonfiglioli',n:'Bonfiglioli',k:['bonfiglioli','бонфиглиоли','бонфильоли','бонфиглиоле']},
      {s:'flender',n:'Flender',k:['flender','флендер','фляндер','флендэр']},
      {s:'guomao',n:'Guomao',k:['guomao','гуомао','gk37','gk47','gk57','gk67','gk77','gk87','gk97','gk107','gk127','gk157','zlyj','гуамао']},
      {s:'innored',n:'Innored',k:['innored','иннорэд','инноред','иннор']},
      {s:'innovari',n:'Innovari',k:['innovari','инновари']},
      {s:'keb',n:'KEB',k:['keb','кеб','кэб','кееб','keб','kэб','keeб']},
      {s:'lenze',n:'Lenze',k:['lenze','ленце','ленза','лензе']},
      {s:'motovario',n:'Motovario',k:['motovario','мотоварио','мотовარио','мотовario']},
      {s:'nord',n:'NORD',k:['nord','норд']},
      {s:'rossi',n:'Rossi',k:['rossi','росси','россі']},
      {s:'sew',n:'SEW-Eurodrive',k:['sew','сев','сью','евродрайв','севевродрайв']},
      {s:'siemens',n:'Siemens',k:['siemens','simogear','симогир','сименс','симменс']},
      {s:'siti',n:'SITI',k:['siti','сити']},
      {s:'stm',n:'STM',k:['stm','штм','стм']},
      {s:'tos-znojmo',n:'Tos Znojmo',k:['tos-znojmo','тоззноймо','зноймо','тос']},
      {s:'tramec',n:'Tramec',k:['tramec','трамек','трамэк']},
      {s:'transtecno',n:'Transtecno',k:['transtecno','транстехно','транстекно']},
      {s:'unidrive',n:'UNI Drive',k:['unidrive','юнитдрайв','юнидрайв','юнит']},
      {s:'varmec',n:'Varmec',k:['varmec','вармек','вармэк']},
      {s:'varvel',n:'Varvel',k:['varvel','варвел','варвель']},
      {s:'vemper',n:'Vemper',k:['vemper','вемпер','вэмпер']},
      {s:'watt-drive',n:'Watt Drive',k:['watt-drive','ваттдрайв','ватт']},
      {s:'yilmaz',n:'Yilmaz',k:['yilmaz','йилмаз','илмаз','йылмаз']}
    ];
    var BPAGE={}; BPAGES.forEach(function(x){ BPAGE[nn(x.s.split('-')[0])]=x.n; BPAGE[nn(x.s)]=x.n; });
    function brandPageFor(qt){ for(var i=0;i<qt.length;i++){ var q=qt[i]; if(q.length<3)continue;
      for(var j=0;j<BPAGES.length;j++){ var b=BPAGES[j], ks=[nn(b.s),nn(b.s.split('-')[0]),nn(b.n),nn(b.n.split(' ')[0])].concat((b.k||[]).map(nn));
        for(var k=0;k<ks.length;k++){ if(ks[k]&&(q===ks[k]||(q.length>=4&&ks[k].indexOf(q)===0))) return b; } } }
      return null; }
    function brandLabel(k){ return BNAME[k]||BPAGE[k]||k.toUpperCase(); }
    function rawTok(str){ var o=[]; tk(String(str||'').toLowerCase()).map(nn).filter(Boolean).forEach(function(q){ o.push(q);
      var mm=q.match(/^([a-zа-я]+)(\d+)$/)||q.match(/^(\d+)([a-zа-я]+)$/); if(mm){ if(mm[1])o.push(mm[1]); if(mm[2])o.push(mm[2]); } });
      return o; }
    /* ═══ Словарь обозначений импортных моделей (assets/model-hints.json) ═══
       741 обозначение по 16 маркам из дилерской выгрузки: ТОЛЬКО обозначение, марка и тип
       передачи — других данных в источнике нет, и придумывать их нельзя (соответствия
       «импорт → наш типоразмер ZR» здесь тоже нет).
       Зачем: клиент вводит то, что прочитал на шильдике («K188», «RMI 110»), а в основном
       индексе такой записи либо нет вовсе (по Flender/Siemens перечня моделей у нас не было),
       либо транскрипция расходится с заводским каталогом (дилерское «K188» против «K189»).
       Раньше это давало пустоту или подсовывало близкое из ЧУЖОЙ марки. Теперь на такой
       запрос честно отвечаем: что это за модель, чья она, какая передача — и ведём на
       страницу марки с предложением подобрать аналог ZR по шильдику.
       Словарь — страховка, а не основной путь: он подключается ПОСЛЕ того, как обычный
       поиск отработал и ничего толкового не дал, и файл грузится только в этот момент. */
    var HINTS=null,hintsLoad=false;
    /* Ведущие нули внутри чисел долой: клиент пишет «NMRV 63», каталог хранит «NMRV 063». */
    function zst(s){ return String(s).replace(/0+(\d)/g,'$1'); }
    function loadHints(cb){ if(HINTS)return cb&&cb();
      if(hintsLoad)return;                       /* уже летит запрос — второй не нужен */
      hintsLoad=true;
      fetch('/assets/model-hints.json').then(function(r){return r.json();})
        .then(function(d){ HINTS=d||{}; hintsLoad=false; cb&&cb(); })
        /* На ошибке кладём пустой объект: HINTS перестаёт быть null, и render больше
           не уйдёт в бесконечную попытку дозагрузки. */
        .catch(function(){ HINTS={}; hintsLoad=false; cb&&cb(); }); }
    /* Ключи-кандидаты. Ключи словаря нормализованы той же nn(), плюс генератор уже положил
       синонимы без ведущих нулей и с латиницей вместо кириллических двойников.
       Здесь добираем остальное: как ввёл → другая раскладка → транслит, и все непрерывные
       куски из слов запроса, длинные первыми — чтобы «rossi mrv 100» нашлось как «mrv100»,
       а не как бесполезное «100». */
    /* Наши собственные серии словарь перебивать НЕ имеет права. У Rossi есть модели «MR 2»,
       «MR 7», у кого-то «PR 90» — но клиент, набравший «МР 2» или «ПР 90», спрашивает наш
       планетарный МР и наш типоразмер ПР. Такие кандидаты выбрасываем. Цифра сразу после
       префикса обязательна, иначе бы отвалились законные «MRV 100» и «MR C3I 81». */
    var OURS=/^(mr|мр|mp|pr|пр|zr|зр|evl|евл|рчу|чм)\d/;
    function hintKeys(q){ var o=[],seen={};
      function add(s){ var k=nn(s); if(!k||k.length<2||OURS.test(k))return;
        if(!seen[k]){seen[k]=1;o.push(k);}
        var z=zst(k); if(z!==k&&!seen[z]){seen[z]=1;o.push(z);} }
      [String(q==null?'':q),swapLay(String(q==null?'':q)),translit(String(q==null?'':q))].forEach(function(s){
        var t=tk(s); if(!t.length||t.length>6)return;
        for(var len=t.length;len>=1;len--) for(var i=0;i+len<=t.length;i++) add(t.slice(i,i+len).join(''));
      });
      return o; }
    function hintFind(q){ if(!HINTS)return null; var ks=hintKeys(q);
      for(var i=0;i<ks.length;i++){ var h=HINTS[ks[i]]; if(h)return h; }
      return null; }
    /* Строка-подсказка. Говорим ровно то, что знаем: обозначение (в каталожном написании,
       а не в клиентском), тип передачи и марку. Ссылка — на страницу марки. */
    function hintRow(h){
      var bn=String(h.b||''), full=bn+(h.b2?' / '+h.b2:'');
      var bp=brandPageFor([nn(bn)])||brandPageFor([nn(bn.split(/[\s\-]+/)[0])]);
      var href=bp?('/brands/'+bp.s):'/importozameshchenie';
      return '<a class="ms-row ms-sec" href="'+esc(href)+'">'+SIC+'<span class="ms-rb">'
        +'<span class="ms-rm">'+esc(h.n||'')+' — '+esc(h.t||'')+' мотор-редуктор '+esc(full)+'</span>'
        +'<span class="ms-rt">Обозначение из каталога '+esc(full)+'. Своей карточки у нас нет — подберём аналог ZR по шильдику: пришлите фото или обозначение.</span></span>'
        +'<span class="ms-rp">марка</span></a>'; }
    var SIC='<span class="ms-sic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h10"/></svg></span>';
    function secRow(x){ return '<a class="ms-row ms-sec" href="'+esc(x.u)+'">'+SIC+'<span class="ms-rb"><span class="ms-rm">'+esc(x.t)+'</span><span class="ms-rt">'+esc(x.d||'')+'</span></span><span class="ms-rp">раздел</span></a>'; }
    /* Три попытки понять клиента, прежде чем сказать «ничего не найдено»:
       как ввёл → в другой раскладке → латиницей → мягкий режим без жёстких фильтров. */
    function smart(q){ var r,note='',used=q,PB=pageBrand(),ctx=(PB&&((IMPB&&IMPB[PB])||BPAGE[PB]))?PB:'',
      hasIdx=!!(ctx&&IMPB&&IMPB[ctx]);
      /* Клиент на странице бренда — сначала ищем ВНУТРИ этого бренда. */
      if(hasIdx){ FORCEB=ctx; SOFT=false; var rc=rank(q); FORCEB='';
        if(rc.length) return {rows:rc,note:'',used:q,ctx:ctx,inctx:1}; }
      SOFT=false; r=rank(q);
      if(!r.length){ var a=swapLay(q); if(a&&a.toLowerCase()!==q.toLowerCase()){ var ra=rank(a); if(ra.length){ r=ra; used=a; note='Похоже, набрано в другой раскладке — ищем «'+a+'»'; } } }
      if(!r.length){ var b=translit(q); if(b!==String(q).toLowerCase()){ var rb=rank(b); if(rb.length){ r=rb; used=b; note='Ищем латиницей: «'+b+'»'; } } }
      if(!r.length){ var c=mrToPr(q); if(c!==q){ var rc=rank(c); if(rc.length){ r=rc; used=c;
        note='МР и ПР — один типоразмер: показываем «'+c+'»'; } } }
      if(!r.length){ SOFT=true; r=rank(q); SOFT=false; if(r.length)note='Точных совпадений нет — показываем близкое'; }
      /* Нашли, но НЕ у бренда страницы — говорим об этом прямо, а не подсовываем чужое молча. */
      if(ctx&&r.length&&!note) note=hasIdx?('У '+brandLabel(ctx)+' такого обозначения в базе нет. Близкое у других производителей:'):('По '+brandLabel(ctx)+' у нас нет перечня моделей — подберём по шильдику. Близкое у других производителей:');
      else if(ctx&&r.length&&note) note='У '+brandLabel(ctx)+' такого нет. '+note;
      return {rows:r,note:note,used:used,ctx:ctx}; }
    /* Нормализованный «паспорт» записи — по нему проверяем, лежит ли в выдаче то, что
       спросили. Поле e (старая маркировка EVL) сюда входит: оно невидимо для клиента, но
       исторически участвует в этой проверке, и трогать её поведение нельзя. */
    function rowKey(g){ return nn(g.__imp?(g.b+' '+(g.m||'')):((g.z||'')+' '+(g.p||'')+' '+(g.e||''))); }
    /* А вот то, что клиент реально ВИДИТ в строке выдачи: марка с моделью у импорта,
       маркировка ZR и ПР у нашей группы. Скрытый EVL исключён намеренно — из-за него
       запрос «K188» (Siemens) считался попаданием в нашу же группу ZR 888, у которой
       внутри лежит невидимое «EVL 188», и подсказка не показывалась. */
    function rowSeen(g){ return g.__imp?(g.b+' '+(g.m||'')):((g.z||'')+' '+(g.p||'')); }
    function rowNums(g){ return String(rowSeen(g)).match(/\d+/g)||[]; }
    function render(q){ var res=smart(q), top=res.rows, rt=rawTok(res.used), secs=secFind(rt);
      /* Проверка «попали ли вообще»: если клиент назвал число («МР 2», «ZR 603»), а ни в
         одной найденной карточке этого числа нет — модели подобраны мимо, и раздел
         полезнее их. Тогда раздел встаёт первым. Иначе разделы идут довеском снизу. */
      var dig=rawTok(res.used).filter(function(t){return /^\d+$/.test(t);});
      var hit=!dig.length||top.some(function(x){ var n=rowKey(x.gr);
        return dig.every(function(d){return n.indexOf(d)>=0;}); });
      /* Обычный путь ответил плохо: пусто, или пришлось выкручиваться (раскладка, транслит,
         МР→ПР, мягкий режим), или числа из запроса нет ни в одной карточке — то есть выдача
         мимо. Только в этом случае трогаем словарь обозначений — и только в этот момент его
         качаем, чтобы не утяжелять первую загрузку страницы. */
      var hitNum=!dig.length||top.some(function(x){ var ns=rowNums(x.gr);
        return dig.every(function(d){return ns.indexOf(d)>=0;}); });
      /* Обозначение с шильдика — это «буквы+цифры» подряд. Совпадения одного числа мало:
         на «ZFB 200» выдача была забита чужими «RV 200», «BH 200», «200В» — число есть,
         модели нет. Поэтому для таких запросов требуем, чтобы обозначение нашлось целиком. */
      var qd=[],qtk=tk(res.used);
      if(qtk.length<=6) for(var dl=qtk.length;dl>=1;dl--) for(var di=0;di+dl<=qtk.length;di++){
        var ds=nn(qtk.slice(di,di+dl).join('')); if(ds.length>=3&&/[a-zа-я]/.test(ds)&&/\d/.test(ds))qd.push(zst(ds)); }
      var hitDes=!qd.length||top.some(function(x){ var n=zst(nn(rowSeen(x.gr)));
        return qd.some(function(s){return n.indexOf(s)>=0;}); });
      var weak=!top.length||!!res.note||!hitNum||!hitDes;
      if(weak&&HINTS===null){ loadHints(function(){ if(input.value.trim()===q)render(q); });
        /* Словарь ещё летит — дорисуем текущую выдачу, после ответа render повторится.
           Повторного скачивания не будет: HINTS уже не null. */ }
      var hint=weak?(hintFind(q)||(res.used!==q?hintFind(res.used):null)):null;
      /* Само это обозначение уже стоит в выдаче — подсказка была бы дублем, гасим её. */
      if(hint){ var hk=nn(hint.n||'');
        if(!hk||top.some(function(x){return nn(rowSeen(x.gr)).indexOf(hk)>=0;}))hint=null; }
      var hr=hint?hintRow(hint):'';
      /* Клиент назвал марку — её страница должна быть в выдаче всегда, даже если перечня
         моделей по ней у нас нет. Иначе запрос «KEB» уводил на чужой бренд. */
      var bp=brandPageFor(rt), bpNoData=bp&&!(IMPB&&IMPB[nn(bp.s.split('-')[0])]);
      var bpRow=bp?('<a class="ms-row ms-sec" href="/brands/'+esc(bp.s)+'">'+SIC+'<span class="ms-rb">'
        +'<span class="ms-rm">'+esc(bp.n)+' — аналоги и замена</span>'
        +'<span class="ms-rt">Страница марки: что заменяем, сроки, подбор по шильдику</span></span>'
        +'<span class="ms-rp">марка</span></a>'):'';
      var head=res.note?'<div class="ms-note">'+esc(res.note)+'</div>':'';
      /* Модель опознана словарём, а поиск дал лишь «близкое» — точный ответ ставим ВЫШЕ
         приблизительного, иначе клиент решит, что мы предлагаем не то, что он спросил. */
      if(hr&&top.length)head=hr+head;
      if(!top.length){
        var bx=res.ctx?('<a class="ms-row ms-sec" href="/brands/'+esc(res.ctx)+'">'+SIC+'<span class="ms-rb">'
            +'<span class="ms-rm">Подобрать замену '+esc(brandLabel(res.ctx))+'</span>'
            +'<span class="ms-rt">Пришлите обозначение или фото шильдика — подберём по размерам</span></span>'
            +'<span class="ms-rp">раздел</span></a>'):'';
        var body=hr?('<div class="ms-note">«'+esc(q)+'» — обозначение импортного каталога. Карточки у нас нет, но модель опознали:</div>'+hr+bx+secs.slice(0,2).map(secRow).join(''))
          :(bpRow||secs.length)?('<div class="ms-note">По «'+esc(q)+'» карточек нет. Возможно, вам сюда:</div>'+bpRow+bx+secs.map(secRow).join(''))
          :('<div class="ms-empty">По «'+esc(q)+'» ничего не нашли. Нажмите «Найти» — откроем полный подбор, или пришлите фото шильдика: подберём вручную.</div>'
            +bx+SECTIONS.slice(-4).map(secRow).join(''));
        dd.innerHTML=body+'<a class="ms-all" href="/podbor?q='+encodeURIComponent(q)+'">Открыть подбор по параметрам →</a>'; dd.hidden=false; return; }
      var rows=top.map(row).join('');
      if(bpNoData){ dd.innerHTML=head+bpRow+rows+'<a class="ms-all" href="/podbor?q='+encodeURIComponent(res.used)+'">Показать все результаты в подборе →</a>'; dd.hidden=false; return; }
      if(secs.length&&!hit){ dd.innerHTML=head+secs.slice(0,2).map(secRow).join('')+rows
        +'<a class="ms-all" href="/podbor?q='+encodeURIComponent(res.used)+'">Показать все результаты в подборе →</a>'; dd.hidden=false; return; }
      var tail=(bpRow||'')+secs.slice(0,top.length>=5?1:2).map(secRow).join('');
      dd.innerHTML=head+rows+tail+'<a class="ms-all" href="/podbor?q='+encodeURIComponent(res.used)+'">Показать все результаты в подборе →</a>'; dd.hidden=false;
    }
    function load(cb){ if(IDX)return cb&&cb(); if(loading)return; loading=true; fetch('/assets/search-index.json?v=11').then(function(r){return r.json();}).then(function(d){IDX=prep(d);loading=false;cb&&cb();}).catch(function(){loading=false;}); }
    var t; function deb(){ clearTimeout(t); var q=input.value.trim(); if(q.length<2){dd.hidden=true;return;} t=setTimeout(function(){ if(IDX)render(q); else load(function(){render(q);}); },140); }
    input.addEventListener('focus',function(){ load(); if(input.value.trim().length>=2)deb(); });
    input.addEventListener('input',deb);
    input.addEventListener('keydown',function(e){ if(e.key==='Escape'){dd.hidden=true;} else if(e.key==='Enter'){ e.preventDefault(); goSearch(); } });
    document.addEventListener('click',function(e){ if(!form.contains(e.target))dd.hidden=true; });
  })(); }catch(e){}

  /* Учёт кликов по контактам ВКЛЮЧЁН (решение владельца 11.08.2026): клик по
     tel:/mailto:/мессенджеру (Telegram, MAX, WhatsApp) — это обращение, и оно
     должно появляться в CRM как заявка, даже если человек не заполнил форму.
     Сервер (/api/contact_click.php) создаёт лид, дедупит по IP+браузеру за 6 ч
     и шлёт Telegram-уведомление менеджеру; письмо на почту НЕ шлёт (осознанно:
     полное письмо уходит только по реальной заявке с формы, иначе были дубли).
     Отправка через sendBeacon — переход по ссылке не задерживается. */
  try{ (function(){
    var API='/api/contact_click.php';
    function kindOf(h){
      if(/^tel:/i.test(h))return 'phone';
      if(/^mailto:/i.test(h))return 'email';
      if(/t\.me\/|telegram\.me\//i.test(h))return 'telegram';
      if(/wa\.me\/|api\.whatsapp\.com|^whatsapp:/i.test(h))return 'whatsapp';
      if(/(^|\.|\/\/)max\.ru\//i.test(h))return 'max';
      return '';
    }
    document.addEventListener('click',function(e){
      var a=e.target&&e.target.closest&&e.target.closest('a[href]');
      if(!a)return;
      var href=a.getAttribute('href')||'';
      var kind=kindOf(href);
      if(!kind)return;
      /* Фронт-троттлинг 30 с на канал — серия кликов не спамит сервер
         (сервер всё равно дедупит 6 ч, это только экономия запросов). */
      try{
        var key='zrcc_'+kind, now=Date.now();
        if(now-(+sessionStorage.getItem(key)||0)<30000)return;
        sessionStorage.setItem(key,String(now));
      }catch(_e){}
      /* Цель в Метрике. До 24.09.2026 её здесь не было: клик по телефону создавал
         лид в CRM и уведомление менеджеру, но в аналитике не появлялся вовсе.
         Для B2B-редукторов звонок — основная доля обращений, поэтому цена заявки
         считалась по одним только веб-формам и выходила завышенной, а Директ
         не мог оптимизироваться на звонивших.
         Цель стоит ПОСЛЕ троттлинга специально: так число целей в Метрике
         совпадает с числом лидов в CRM, и цифры можно сверять. */
      try{
        var GOAL={phone:'zvonok',email:'email_click',telegram:'messenger_telegram',
                  whatsapp:'messenger_whatsapp',max:'messenger_max'}[kind];
        if(GOAL&&window.ym)ym(109758131,'reachGoal',GOAL);
      }catch(_e){}
      var fd=new FormData();
      fd.append('kind',kind);
      fd.append('contact',href.slice(0,200));
      fd.append('page_url',location.href.slice(0,1000));
      fd.append('page_title',(document.title||'').slice(0,255));
      fd.append('referrer',(document.referrer||'').slice(0,1000));
      /* Источник берём так же, как формы: сначала текущий адрес, потом first-touch
         из localStorage. Раньше читался только location.search и не передавались
         gclid/yclid — человек приходил по объявлению на главную, листал каталог,
         жал «Позвонить», и лид попадал в CRM без источника и без yclid, то есть
         офлайн-конверсию было не с чем связать. */
      try{
        var q=new URLSearchParams(location.search);
        var st={};try{st=JSON.parse(localStorage.getItem('zr_utm')||'{}');}catch(_e2){}
        ['utm_source','utm_medium','utm_campaign','utm_term','utm_content','gclid','yclid']
          .forEach(function(k){
            var v=q.get(k)||st[k]; if(v)fd.append(k,String(v).slice(0,255));
          });
      }catch(_e){}
      if(navigator.sendBeacon){ try{ if(navigator.sendBeacon(API,fd))return; }catch(_e){} }
      try{ fetch(API,{method:'POST',body:fd,keepalive:true}); }catch(_e){}
    },true);
  })(); }catch(e){}

  /* Тема письма для прямых обращений на почту.
     Все ссылки сайта — голый mailto:zr@zavod-red.ru, поэтому письмо приходило БЕЗ ТЕМЫ:
     такое теряется среди прочей почты, а спам-фильтры относятся к нему строже (на письмах
     без темы и с телефоном в теме этот сайт уже спотыкался). Подставляем тему и страницу,
     с которой человек пишет, — менеджер сразу видит, о какой модели речь.
     Href правим ДО перехода: pointerdown/touchstart срабатывают раньше действия по ссылке. */
  try { (function(){
    function subjectFor(){
      /* Заголовок до разделителя — это модель или раздел («Мотор-редуктор ZR R 97 …»). */
      var t = (document.title || '').split(/\s[|\u2014\u2013]\s/)[0].trim();
      if (t.length > 60) t = t.slice(0, 60).trim() + '\u2026';
      return t ? ('\u0412\u043e\u043f\u0440\u043e\u0441 \u0441 \u0441\u0430\u0439\u0442\u0430 zavod-red.ru: ' + t)
               : '\u0412\u043e\u043f\u0440\u043e\u0441 \u0441 \u0441\u0430\u0439\u0442\u0430 zavod-red.ru';
    }
    function decorate(a){
      if (!a || a.getAttribute('data-zr-subj')) return;
      var href = a.getAttribute('href') || '';
      /* Ссылку со своими параметрами не трогаем — там тема задана осознанно. */
      if (!/^mailto:/i.test(href) || href.indexOf('?') >= 0) return;
      /* Адрес страницы БЕЗ параметров: рекламные метки (etext, yclid) в письме не нужны
         и превращают ссылку в нечитаемую простыню. */
      var page = (location.origin || (location.protocol + '//' + location.host)) + location.pathname;
      a.setAttribute('href', href + '?subject=' + encodeURIComponent(subjectFor())
                   + '&body=' + encodeURIComponent('\n\n---\n\u0421\u0442\u0440\u0430\u043d\u0438\u0446\u0430: ' + page));
      a.setAttribute('data-zr-subj', '1');
    }
    function onEvt(e){
      var a = e.target && e.target.closest && e.target.closest('a[href^="mailto:"]');
      if (a) decorate(a);
    }
    document.addEventListener('pointerdown', onEvt, true);
    document.addEventListener('touchstart', onEvt, true);
    document.addEventListener('click', onEvt, true);
  })(); } catch(e){}

  /* Клик по e-mail: у посетителя без настроенного почтового клиента ссылка mailto:
     не делает НИЧЕГО видимого — человек решает, что сайт сломан, и уходит.
     Поведение mailto не отменяем (у кого клиент есть — письмо откроется), но
     дополнительно копируем адрес и показываем подсказку с переходом на форму. */
  try { (function(){
    var TOAST_CSS = ''
     // По центру экрана и поверх всего (z выше чата/мобильной кнопки — они перекрывали
     // подсказку внизу, и клиент не видел, что адрес скопирован).
     + '.zr-mailhint{position:fixed;left:50%;top:50%;transform:translate(-50%,-44%);z-index:2147483000;'
     + 'display:flex;flex-direction:column;gap:10px;width:min(92vw,360px);background:#fff;color:#0e1a24;'
     + 'border:1px solid rgba(14,26,36,.12);border-top:4px solid #cf1616;border-radius:16px;padding:22px 22px 20px;'
     + 'box-shadow:0 30px 80px rgba(14,26,36,.38);font-size:14px;line-height:1.45;text-align:center;opacity:0;transition:.2s}'
     + '.zr-mailhint.show{opacity:1;transform:translate(-50%,-50%)}'
     + '.zr-mailhint b{font-size:16px;color:#101f2a}'
     + '.zr-mailaddr{font:700 17px/1.25 \'Space Grotesk\',system-ui,sans-serif;color:#cf1616;word-break:break-all;'
     + 'padding:9px 12px;background:#fbeaea;border-radius:9px;user-select:all}'
     + '.zr-mailhint span{color:#526069;font-size:12.5px}'
     + '.zr-mailhint .zr-mailform{background:#cf1616;color:#fff;border:0;border-radius:9px;'
     + 'padding:11px 16px;font-size:14px;font-weight:700;font-family:inherit;cursor:pointer;margin-top:2px}'
     + '.zr-mailhint .zr-mailform:hover{background:#b01212}'
     + '.zr-mailhint .zr-mailclose{position:absolute;top:7px;right:11px;background:none;border:0;color:#9aa7b0;'
     + 'font-size:22px;line-height:1;padding:2px 6px;cursor:pointer}'
     + '.zr-mailback{position:fixed;inset:0;background:rgba(12,22,30,.34);z-index:2147482999;opacity:0;transition:.2s}'
     + '.zr-mailback.show{opacity:1}';
    var st = document.createElement('style'); st.textContent = TOAST_CSS; document.head.appendChild(st);

    var box = null, back = null, timer = null;
    function killBox(){
      clearTimeout(timer);
      if (box){ box.classList.remove('show'); }
      if (back){ back.classList.remove('show'); }
      var b = box, bk = back; box = null; back = null;
      setTimeout(function(){ if (b) b.remove(); if (bk) bk.remove(); }, 230);
    }
    function toast(mail, copied){
      if (box || back) { clearTimeout(timer); if (box) box.remove(); if (back) back.remove(); box = null; back = null; }
      back = document.createElement('div'); back.className = 'zr-mailback';
      box = document.createElement('div'); box.className = 'zr-mailhint';
      box.innerHTML = '<button type="button" class="zr-mailclose" aria-label="Закрыть">&times;</button>'
        + '<b>' + (copied ? '✓ Адрес скопирован' : 'Наша почта') + '</b>'
        + '<div class="zr-mailaddr">' + mail + '</div>'
        + '<span>' + (copied
            ? 'Вставьте адрес в свою почту (Gmail, Mail.ru, Яндекс) и напишите нам — или отправьте заявку прямо здесь, ответим за 15 минут.'
            : 'Скопируйте адрес и напишите нам — или отправьте заявку прямо здесь, ответим за 15 минут.') + '</span>'
        + '<button type="button" class="zr-mailform">Отправить заявку здесь</button>';
      document.body.appendChild(back);
      document.body.appendChild(box);
      requestAnimationFrame(function(){ back.classList.add('show'); box.classList.add('show'); });
      box.querySelector('.zr-mailclose').addEventListener('click', killBox);
      back.addEventListener('click', killBox);
      box.querySelector('.zr-mailform').addEventListener('click', function(){
        killBox();
        // модалка вешается на клик по [data-zayavka] / ссылке #zayavka (см. выше)
        var trigger = document.querySelector('[data-zayavka],a[href="#zayavka"],a[href$="#zayavka"]');
        if (trigger) { trigger.click(); return; }
        location.href = '/#zayavka';
      });
      timer = setTimeout(killBox, 12000);
    }

    document.addEventListener('click', function(e){
      var a = e.target && e.target.closest && e.target.closest('a[href^="mailto:"]');
      if (!a) return;
      // mailto НЕ отменяем: у кого настроен почтовый клиент — откроется его почта, и он
      // напишет нам СО СВОЕЙ почты (именно этого хочет посетитель). Дополнительно кладём
      // адрес в буфер и показываем ВИДИМУЮ подсказку «Адрес скопирован» с запасной кнопкой
      // «Написать здесь» — если почтовый клиент не открылся, адрес уже в буфере, вставит
      // в свой веб-ящик. Раньше клик принудительно открывал форму и глушил mailto — из-за
      // этого посетитель не мог написать со своей почты и уходил (видно в Вебвизоре).
      var mail = (a.getAttribute('href') || '').replace(/^mailto:/i, '').split('?')[0];
      var copied = false;
      try {
        if (mail && navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(mail); copied = true;
        } else if (mail) {
          var ta = document.createElement('textarea');
          ta.value = mail; ta.setAttribute('readonly','');
          ta.style.cssText = 'position:absolute;left:-9999px';
          document.body.appendChild(ta); ta.select();
          try { document.execCommand('copy'); copied = true; } catch(e2) {}
          ta.remove();
        }
      } catch (e3) {}
      if (mail) toast(mail, copied);
    }, false);
  })(); } catch(e){}

/* LIGHTBOX — все картинки сайта с перелистыванием */
(function(){
  try {
    /* forClick=true — «можно ли ОТКРЫТЬ просмотр кликом по этой картинке».
       forClick=false — «попадает ли она в список кадров для перелистывания».
       Разделение нужно из-за миниатюр карточки: кликать по ним должен орган
       управления (смена кадра), а в галерее просмотра они всё равно нужны —
       иначе в лайтбоксе остаётся один кадр вместо всех фото товара. */
    function isExcluded(img, forClick) {
      var src = (img.src || '').toLowerCase();
      if (src.indexOf('logo') >= 0 || src.indexOf('icon') >= 0 || src.indexOf('favicon') >= 0) return true;
      if (img.closest('.hdr2') || img.closest('header') || img.closest('.footer')) return true;
      /* Картинка внутри ссылки на СТРАНИЦУ — это иллюстрация кликабельной плитки
         (плитки марок и разделов на главной): лайтбокс глушил переход своим
         preventDefault, человек жал по картинке и вместо каталога получал её же
         во весь экран. Галереи не задеты: там <a> ведёт на сам файл изображения —
         такие ссылки по-прежнему открываем лайтбоксом. */
      var a = img.closest('a[href]');
      if (a) {
        var href = (a.getAttribute('href') || '').toLowerCase().split(/[?#]/)[0];
        if (href && !/\.(jpe?g|png|webp|gif|avif|svg|bmp)$/.test(href)) return true;
      }
      /* Картинка внутри УПРАВЛЯЮЩЕГО элемента — не иллюстрация, а орган управления.
         Слушатель ниже висит в фазе перехвата со stopPropagation, поэтому клик по
         миниатюре в галерее карточки (.p2-thumbs button > img) до кнопки не доходил:
         вместо смены кадра открывался лайтбокс, и пролистать фото было нельзя ни
         миниатюрами, ни стрелками. То же касается <label>, <summary> и любых
         [role=button]/[data-zayavka] с картинкой внутри. */
      /* ПОСТЕР ВИДЕО — не иллюстрация, а обложка ролика (26.09.2026).
         Блок .vfac показывает картинку с кнопкой «play», а сам ролик подставляется
         по клику. Но слушатель лайтбокса висит в фазе ПЕРЕХВАТА и делает
         stopPropagation, поэтому до обработчика видео клик не доходил вовсе:
         человек жал «play» и получал постер во весь экран вместо ролика. Ровно так
         это и выглядело снаружи — «видео не работает». Исключаем обложку всегда,
         а не только forClick: в ленте лайтбокса ей тоже не место. */
      if (img.closest('.vfac[data-src]')) return true;
      if (forClick && img.closest('button,[role="button"],label,summary,[data-zayavka],[data-zoom-skip]')) return true;
      var nw = img.naturalWidth;
      if (nw > 0 && nw < 80) return true;
      return false;
    }

    /* Дедуп по src: главное фото карточки показывает тот же файл, что и активная
       миниатюра, — без дедупа один кадр попадал в ленту дважды и счётчик врал. */
    function getImages() {
      var seen = {}, out = [];
      Array.from(document.querySelectorAll('img')).forEach(function (i) {
        if (isExcluded(i, false)) return;
        var k = i.currentSrc || i.src || '';
        if (!k || seen[k]) return;
        seen[k] = 1; out.push(i);
      });
      return out;
    }

    var lb = null;

    document.addEventListener('click', function(e) {
      var img = e.target;
      if (!img || img.tagName !== 'IMG') return;
      if (isExcluded(img, true)) return;
      if (lb) return;

      e.preventDefault();
      e.stopPropagation();

      var images = getImages();
      var idx = images.indexOf(img);
      /* После дедупа кликнутый узел может не совпасть с тем, что попал в ленту
         (главное фото vs. миниатюра того же файла) — ищем по адресу картинки. */
      if (idx < 0) {
        var key = img.currentSrc || img.src || '';
        for (var _i = 0; _i < images.length; _i++) {
          if ((images[_i].currentSrc || images[_i].src) === key) { idx = _i; break; }
        }
      }
      if (idx < 0) idx = 0;

      lb = document.createElement('div');
      lb.className = 'zr-lb';

      var closeBtn = document.createElement('button');
      closeBtn.className = 'zr-lb-close';
      closeBtn.innerHTML = '&times;';
      closeBtn.setAttribute('aria-label', 'Закрыть');

      var prevBtn = document.createElement('button');
      prevBtn.className = 'zr-lb-prev';
      prevBtn.innerHTML = '&#8249;';
      prevBtn.setAttribute('aria-label', 'Предыдущее');

      var nextBtn = document.createElement('button');
      nextBtn.className = 'zr-lb-next';
      nextBtn.innerHTML = '&#8250;';
      nextBtn.setAttribute('aria-label', 'Следующее');

      var lbImg = document.createElement('img');
      lbImg.className = 'zr-lb-img';

      var counter = document.createElement('span');
      counter.className = 'zr-lb-counter';

      lb.appendChild(closeBtn);
      lb.appendChild(prevBtn);
      lb.appendChild(lbImg);
      lb.appendChild(nextBtn);
      lb.appendChild(counter);
      document.body.appendChild(lb);

      function show(i) {
        idx = ((i % images.length) + images.length) % images.length;
        lbImg.classList.add('fade');
        setTimeout(function(){
          lbImg.src = images[idx].src;
          lbImg.alt = images[idx].alt || '';
          lbImg.classList.remove('fade');
        }, 140);
        counter.textContent = (idx + 1) + ' / ' + images.length;
        prevBtn.style.display = images.length > 1 ? '' : 'none';
        nextBtn.style.display = images.length > 1 ? '' : 'none';
      }

      show(idx);
      requestAnimationFrame(function(){ lb.classList.add('show'); });
      /* Фон не должен уезжать под открытым просмотром: колесо мыши и инерционный
         скролл на телефоне прокручивали страницу под лайтбоксом, и после закрытия
         человек оказывался в другом месте текста. */
      var _sy = window.pageYOffset || document.documentElement.scrollTop || 0;
      var _prev = {
        ho: document.documentElement.style.overflow, bo: document.body.style.overflow,
        bp: document.body.style.position, bt: document.body.style.top, bw: document.body.style.width
      };
      document.documentElement.style.overflow = 'hidden';
      document.body.style.overflow = 'hidden';
      document.body.style.position = 'fixed';
      document.body.style.top = (-_sy) + 'px';
      document.body.style.width = '100%';

      function kill() {
        lb.classList.remove('show');
        var el = lb; lb = null;
        setTimeout(function(){ if(el.parentNode) el.remove(); }, 230);
        document.removeEventListener('keydown', kd);
        document.documentElement.style.overflow = _prev.ho;
        document.body.style.overflow = _prev.bo;
        document.body.style.position = _prev.bp;
        document.body.style.top = _prev.bt;
        document.body.style.width = _prev.bw;
        if (!_prev.bp) window.scrollTo(0, _sy);
      }

      lb.addEventListener('click', function(ev){
        if (ev.target === lb) kill();
      });
      closeBtn.addEventListener('click', function(ev){ ev.stopPropagation(); kill(); });
      prevBtn.addEventListener('click', function(ev){ ev.stopPropagation(); show(idx - 1); });
      nextBtn.addEventListener('click', function(ev){ ev.stopPropagation(); show(idx + 1); });

      // touch swipe
      var tx = null;
      lb.addEventListener('touchstart', function(ev){ tx = ev.touches[0].clientX; }, {passive:true});
      lb.addEventListener('touchend', function(ev){
        if (tx === null) return;
        var dx = ev.changedTouches[0].clientX - tx; tx = null;
        if (Math.abs(dx) > 50) dx < 0 ? show(idx + 1) : show(idx - 1);
      }, {passive:true});

      function kd(ev) {
        if (ev.key === 'Escape') kill();
        else if (ev.key === 'ArrowRight' || ev.key === 'ArrowDown') show(idx + 1);
        else if (ev.key === 'ArrowLeft'  || ev.key === 'ArrowUp')   show(idx - 1);
      }
      document.addEventListener('keydown', kd);
    }, true); // capture=true чтобы перехватить до <a>-навигации
  } catch(e) {}
})();

})();

/* БЕГУЩАЯ СТРОКА МАРОК в шапке (правая пустая часть строки навигации).
   Логотипы импортных производителей, клик → страница марки /brands/<slug>.
   Разметку вставляем скриптом: этот файл грузят все ~93k страниц, а править шапку
   в разметке нельзя. Стили — в assets/hdr.css (.hnav-brands / .hb-track / .hb-i).
   Набор дублируется, CSS сдвигает трек ровно на -50% → шов не виден. */
(function(){
  try {
    var BR = [
      ['sew','SEW-Eurodrive'],['nord','NORD Drivesystems'],['bonfiglioli','Bonfiglioli'],
      ['motovario','Motovario'],['siemens','Siemens'],['flender','Flender'],
      ['lenze','Lenze'],['keb','KEB'],['bauer','Bauer Gear Motor'],
      ['rossi','Rossi'],['varvel','Varvel'],['varmec','Varmec'],
      ['tramec','Tramec'],['transtecno','Transtecno'],['siti','SITI'],
      ['stm','STM'],['innovari','Innovari'],['innored','Innored'],
      ['unidrive','Unidrive'],['watt-drive','Watt Drive'],['yilmaz','Yilmaz'],
      ['boneng','Boneng'],['guomao','Guomao'],['vemper','Vemper'],['tos-znojmo','TOS Znojmo']
    ];
    function build(){
      var host = document.querySelector('.hdr2 .hnav .hn-in');
      if (!host || host.querySelector('.hnav-brands')) return;
      var box = document.createElement('div');
      box.className = 'hnav-brands';
      box.setAttribute('aria-label', 'Марки импортных редукторов');
      var track = document.createElement('div');
      track.className = 'hb-track';
      function fill(dup){
        for (var i = 0; i < BR.length; i++) {
          var a = document.createElement('a');
          a.className = 'hb-i';
          a.href = '/brands/' + BR[i][0];
          a.title = BR[i][1] + ' — редукторы и аналоги';
          if (dup) { a.setAttribute('aria-hidden','true'); a.tabIndex = -1; }
          var im = document.createElement('img');
          im.src = '/assets/brands/' + BR[i][0] + '.webp';
          im.alt = dup ? '' : BR[i][1];
          im.loading = 'lazy'; im.decoding = 'async';
          a.appendChild(im); track.appendChild(a);
        }
      }
      fill(false); fill(true);
      box.appendChild(track);
      /* Широкий экран — лента в строке навигации справа; узкий (до 1280px, где .hnav
         тесная, а ниже 860px прячется в бургер) — отдельной полосой под шапкой,
         чтобы марки были видны и на телефоне. Перекладываем и при повороте/ресайзе. */
      var hdr = host.closest('.hdr2') || document.querySelector('.hdr2');
      var mq = window.matchMedia('(max-width:1280px)');
      function place(){ (mq.matches ? hdr : host).appendChild(box); }
      place();
      if (mq.addEventListener) mq.addEventListener('change', place);
      else if (mq.addListener) mq.addListener(place);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', build);
    else build();
  } catch(e) {}
})();

/* Компактная шапка при прокрутке (телефон и планшет).
   20.09.2026. Липкой оставлена полоса .hmain, но на узком экране строка поиска
   переносится на второй ряд, и залипало 127px — пятая часть экрана. Ждать от CSS
   «состояния прокрутки» нельзя, поэтому вешаем класс сами: у верха страницы поиск
   виден целиком, после 120px он сворачивается и липкой остаётся одна строка (~67px).
   Класс снимается при возврате наверх и при расширении окна — на десктопе правило
   в hdr.css не действует вовсе. */
(function(){
  try {
    var hdr = document.querySelector('.hdr2');
    if (!hdr) return;
    var mq = window.matchMedia('(max-width:860px)');
    var ticking = false;
    function apply(){
      ticking = false;
      hdr.classList.toggle('hdr2--tight', mq.matches && window.pageYOffset > 120);
    }
    function onScroll(){ if (!ticking) { ticking = true; window.requestAnimationFrame(apply); } }
    window.addEventListener('scroll', onScroll, { passive: true });
    if (mq.addEventListener) mq.addEventListener('change', apply);
    else if (mq.addListener) mq.addListener(apply);
    apply();
  } catch(e) {}
})();
<!--cab67-->