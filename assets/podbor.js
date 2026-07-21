/* Подбор редуктора — фильтр-таблица по базе типоразмеров (тёмная/светлая тема сайта).
   Инжектит UI в #pfRoot: переключатель типа + таблица с фильтрами (от/до по каждой
   колонке), поиск, «Сбросить фильтр», «Сохранить выгрузку» (CSV).
   Контейнер: <div id="pfRoot" data-type="червячный" data-locktype="1"></div>
   data-type     — предустановленный тип (имя как в базе), необязательно
   data-locktype — "1" зафиксировать тип (страница и так этого вида) */
(function(){
  var root=document.getElementById('pfRoot');
  if(!root)return;

  var sub=/\/(catalog|cases|uslugi|brands|blog)\//.test(location.pathname);
  var pfx=sub?'../':'';
  var DATA_URL=pfx+'assets/podbor-data.json?v=8';

  var TYPE_LABEL={
    'червячный':'Червячный',
    'соосно-цилиндрический':'Соосно-цилиндрический',
    'коническо-цилиндрический':'Цилиндро-конический',
    'плоско-цилиндрический':'Плоский цилиндрический',
    'цилиндрический':'Цилиндрический'
  };

  var presetType=(root.getAttribute('data-type')||'').trim();
  var lockType=root.getAttribute('data-locktype')==='1';
  var compact=root.getAttribute('data-compact')==='1'; // только фильтры + счётчик, без списка строк
  // старые ключи брендов → актуальные. 'SEW-Tramec' — выдуманная фирма (реальная — Tramec, Италия);
  // алиас нужен, чтобы уцелевший где-то в HTML или в рекламной ссылке старый ключ не промахнулся
  // мимо BMAP: при неизвестном ключе groupHasBrand молча пропускает все группы (фильтр деградирует).
  // 'Tos Znojmo' — страница brands/tos-znojmo.html ставит человекочитаемое имя, а ключ
  // в данных короткий ('TZ'); без алиаса фильтр по бренду на той странице молча отключался.
  var BALIAS={'SEW-Tramec':'Tramec','SEW Tramec':'Tramec','Tos Znojmo':'TZ'};
  function normBrand(k){ k=(k||'').trim(); return BALIAS[k]||k; }
  var presetBrand=normBrand(root.getAttribute('data-brand')); // предустановленный бренд (ключ как в g.a), для страниц брендов
  var lockBrand=root.getAttribute('data-lockbrand')==='1';      // зафиксировать бренд (страница конкретной фирмы)
  // бренд может прийти и из URL: /podbor?brand=SEW+EURODRIVE — переход «полный подбор» со страницы бренда
  (function(){ var m=(location.search||'').match(/[?&]brand=([^&]*)/);
    if(m && !presetBrand){ presetBrand=normBrand(decodeURIComponent(m[1].replace(/\+/g,' '))); if(presetBrand) lockBrand=true; } })();

  var ENABLED=['червячный','соосно-цилиндрический','коническо-цилиндрический','плоско-цилиндрический','цилиндрический'];
  var enabledIdx=[];

  // Параметры: индекс в позиции, заголовок, знаков после запятой
  var COLS=[
    {i:1, h:'Мощность двигателя, кВт',                 d:2, m:'Мощность, кВт'},
    {i:2, h:'Обороты на выходе n вых, об/мин',          d:1, m:'n вых, об/мин'},
    {i:3, h:'Крутящий момент на вых. валу Tном, Н·м',   d:1, m:'Момент, Н·м'},
    {i:4, h:'Передаточное число, U',                    d:2, m:'Передат. U'},
    {i:5, h:'Консольная нагрузка Fном, Н',              d:0, m:'Fконс, Н'},
    {i:6, h:'Сервис-фактор, Sfном',                     d:2, m:'Сервис-фактор Sf'},
    {i:7, h:'Обороты двигателя n вх, об/мин',            d:0, m:'n вх, об/мин'}
  ];
  var NCOL=COLS.length+2;

  function fmt(x,d){ if(x==null)return '—'; var p=Math.pow(10,d==null?2:d); var v=Math.round(x*p)/p; return String(v).replace('.',','); }

  var headHtml='<tr><th class="pf-th-tz">Типоразмер редуктора</th>'
    +COLS.map(function(c){return '<th>'+c.h+'</th>';}).join('')+'<th class="pf-th-ord">Заказ</th></tr>';

  var filterHtml='<tr class="pf-frow"><td data-label="Поиск по модели / аналогу"><input class="pf-in" id="pfQ" type="text" placeholder="Модель / аналог" autocomplete="off" list="pfModels" inputmode="search"><datalist id="pfModels"></datalist></td>'
    +COLS.map(function(c){
        return '<td data-label="'+c.m+'"><div class="pf-rg"><span>от</span><select class="pf-sel" data-min="'+c.i+'"><option value="">Все</option></select></div>'
             +'<div class="pf-rg"><span>до</span><select class="pf-sel" data-max="'+c.i+'"><option value="">Все</option></select></div></td>';
      }).join('')+'<td class="pf-frow-pad"></td></tr>';

  var btnHtml='<tr class="pf-brow"><td colspan="'+NCOL+'"><div class="pf-toolbar">'
    +'<button type="button" class="pf-btn pf-btn--ghost" id="pfReset">Сбросить фильтр</button>'
    +'<button type="button" class="pf-btn pf-btn--red" id="pfExport" data-zayavka>Получить выгрузку на почту</button>'
    +'<span class="pf-count" id="pfCount"></span></div></td></tr>';

  var pillsHtml=(lockBrand?'<div class="pf-brands pf-brands--lock" id="pfBrandLock"></div>':'<div class="pf-brands" id="pfBrands"></div>')+(lockType?'':'<div class="pf-types" id="pfTypes"></div>');

  // тулбар: в полном режиме — сброс+CSV; в компактном — сброс + «Показать в таблице»
  var toolbar=compact
    ? '<tr class="pf-brow"><td colspan="'+NCOL+'"><div class="pf-toolbar">'
        +'<a class="pf-btn pf-btn--red" id="pfOpen" href="#">Показать подходящие в таблице →</a>'
        +'<button type="button" class="pf-btn pf-btn--ghost" id="pfReset">Сбросить</button>'
        +'<span class="pf-count" id="pfCount"></span></div></td></tr>'
    : btnHtml;

  // тело: в компактном режиме строк нет
  var tbody=compact
    ? ''
    : '<tbody><tr><td colspan="'+NCOL+'" class="pf-loading-cell">Загружаем базу типоразмеров…</td></tr></tbody>';

  root.innerHTML=pillsHtml
   +'<div class="pf-tablewrap"><table class="pf-table" id="pfTable">'
     +'<thead>'+headHtml+filterHtml+toolbar+'</thead>'
     +tbody
   +'</table></div>'
   +(compact?'':'<button type="button" class="pf-more" id="pfMore" hidden>Показать ещё</button>')
   +'<p class="pf-note">'+(compact?'Задайте параметры — покажем число подходящих типоразмеров и откроем их в таблице подбора. ':'')+'Таблица справочная, по параметрам нашего производства (маркировка ZR и ГОСТ, импортные аналоги). Точные размеры, момент с сервис-фактором, наличие, цену и срок подтверждает инженер по заявке.</p>'
   +'<div class="pf-ask"><span>Не нашли нужный типоразмер или нужен расчёт под нагрузку?</span><button class="pf-cta" type="button" data-zayavka>Инженер подберёт под задачу</button></div>';

  var DB=null, RENDER=0, STEP=40, CUR=[], selType=-1, selBrand=(lockBrand?presetBrand:''), RANGE_VALS={}, $=function(id){return document.getElementById(id);};
  var qEl=$('pfQ');
  // кеш ссылок на селекты от/до: элементы создаются один раз, меняются только их <option>,
  // поэтому querySelector по каждой строке (был O(8763×14) на apply — ~2.6с) больше не нужен.
  var _selCache={};
  function selEl(mm,i){ var k=mm+i; return _selCache[k]||(_selCache[k]=root.querySelector('[data-'+mm+'="'+i+'"]')); }
  // переключатель брендов: ключ в g.a → отображение → slug бренд-страниц /analog/<s>-<frame>
  var BRANDS=[{k:'',n:'Наши ZR',s:''},{k:'SEW EURODRIVE',n:'SEW',s:'sew'},{k:'NORD',n:'NORD',s:''},{k:'Bonfiglioli',n:'Bonfiglioli',s:''},{k:'Motovario',n:'Motovario',s:''},{k:'Bauer',n:'Bauer',s:''},{k:'Yilmaz',n:'Yilmaz',s:''},{k:'Lenze',n:'Lenze',s:''},{k:'STM',n:'STM',s:''},{k:'Transtecno',n:'Transtecno',s:''},{k:'Watt Drive',n:'Watt Drive',s:''},{k:'Vemper',n:'Vemper',s:''},{k:'INNOVARI',n:'INNOVARI',s:''},{k:'TZ',n:'Tos Znojmo',s:''},{k:'SITI',n:'SITI',s:''},{k:'Flender',n:'Flender',s:''},{k:'Siemens',n:'Siemens',s:''},{k:'KEB',n:'KEB',s:''},{k:'Boneng',n:'Boneng',s:''},{k:'Guomao',n:'Guomao',s:''},{k:'Unidrive',n:'Unidrive',s:''},{k:'Varmec',n:'Varmec',s:''},{k:'Varvel',n:'Varvel',s:''},{k:'Rossi',n:'Rossi',s:''},{k:'InnoRed',n:'InnoRed',s:''},{k:'Tramec',n:'Tramec',s:''}];
  var BMAP={}; BRANDS.forEach(function(b){BMAP[b.k]=b;});
  // ключ кнопки → фактические ключи бренда в g.a (данные разбиты по под-сериям). 'Varvel серия 7МЧ'
  // (советские Ч-М) НАМЕРЕННО исключён из Varvel. Бренды без данных отсеиваются на лету — см. brandHasData().
  var BKEYS={'NORD':['NORD','Nord цилиндро-червячные'],'STM':['STM','STM AMP, AMF','STM серия RMI','STM серия UMI','STM серия WMI'],'TZ':['TZ','Tos Znojmo'],'SITI':['SITI','Siti BH','Siti серия MI','Siti серия MU','Siti PD','Siti Varmec RFV'],'Varmec':['VARMEC RCV','Siti Varmec RFV'],'Varvel':['Varvel MRD','Varvel MRN','Varvel RO, RV','Varvel серия SRS','Varvel серия SRT'],'Rossi':['Rossi артикулы R I, MR','Rossi серия AS(MRV)'],'InnoRed':['Innored'],'Tramec':['Tramec T','Tramec серия КМ','Tramec серия X','Tramec серия PA, PC']};
  function bKeys(k){ return BKEYS[normBrand(k)]||[k]; }
  // какие бренды реально представлены в podbor-data.json. Список кнопок строим по данным, а не
  // по константе: иначе бренд без единой группы (Flender, Siemens, KEB, Boneng, Guomao, Unidrive)
  // даёт пустую таблицу, а при следующем обновлении данных список снова разъедется.
  var _availKeys=null;
  function availKeys(){
    if(_availKeys) return _availKeys;
    _availKeys={};
    if(DB&&DB.g) for(var i in DB.g){ var a=DB.g[i]&&DB.g[i].a; for(var k in a) _availKeys[k]=1; }
    return _availKeys;
  }
  function brandHasData(k){
    if(isOurs(k)) return true;                 // «Наши ZR» — не по g.a, показываем всегда
    var ks=bKeys(k), set=availKeys();
    for(var i=0;i<ks.length;i++) if(set[ks[i]]) return true;
    return false;
  }
  function brandModel(g,k){ var ks=bKeys(k); for(var i=0;i<ks.length;i++){ var a=g.a&&g.a[ks[i]]; if(a&&a[0]) return a[0]; } return null; }

  function curType(){
    if(lockType) return DB?DB.t.indexOf(presetType):-1;
    return selType;
  }
  function rowsOfType(){
    var ti=curType();
    return DB.i.filter(function(it){var gt=DB.g[it[0]].t; return enabledIdx.indexOf(gt)>=0 && (ti<0||gt===ti);});
  }
  // «наши» виды: ZR (k='') и EVL (k='__evl__') — показываем все наши модели
  function isOurs(k){ return !k || k==='__evl__'; }
  // отображаемое имя выбранного импортного бренда ('' — если выбраны наши ZR)
  function brandLabel(){ if(isOurs(selBrand)) return ''; var b=BMAP[selBrand]; return (b&&b.n)||selBrand; }
  // группа проходит текущий бренд? (наши виды → все; иначе только с аналогом этого бренда)
  function groupHasBrand(g){
    var b=BMAP[selBrand];
    if(isOurs(selBrand)) return true;
    // Бренда нет в базе подбора (Flender, Siemens, KEB, Boneng, Guomao, Unidrive — 0 групп):
    // фильтровать нечем, и жёсткий фильтр давал бы «Найдено: 0» на странице, где посетитель
    // ничего не фильтровал — он терял бы единственный интерактивный CTA. Показываем всю базу ZR,
    // как было до появления фильтра: человек пришёл за заменой, пусть подбирает по параметрам.
    if(!brandHasData(b?b.k:selBrand)) return true;
    // ключа нет в BMAP (чужой/новый data-brand) — не пропускаем всё подряд, а фильтруем
    // по самому значению: пустая выдача честнее, чем таблица чужих брендов на брендовой странице.
    return !!brandModel(g,b?b.k:selBrand);
  }
  // базовый набор строк для диапазонов «от/до»: фильтр по типу + бренду + строке поиска
  // (модель/аналог), но БЕЗ числовых фильтров — чтобы каскад «выбрал модель → в колонках
  // только её значения» работал (правка ТЗ №3).
  function rowsOfBase(){
    var ti=curType();
    return DB.i.filter(function(it){
      var g=DB.g[it[0]];
      if(enabledIdx.indexOf(g.t)<0) return false;
      if(ti>=0 && g.t!==ti) return false;
      if(!groupHasBrand(g)) return false;
      if(!passQ(it)) return false;
      return true;
    });
  }
  function fillRanges(){
    var base=rowsOfBase();
    COLS.forEach(function(c){
      var vals={}, _p=Math.pow(10,c.d==null?2:c.d);
      base.forEach(function(it){ if(it[c.i]!=null)vals[Math.round(it[c.i]*_p)/_p]=1; });
      var sorted=Object.keys(vals).map(parseFloat).sort(function(a,b){return a-b;});
      var prev=RANGE_VALS[c.i];
      RANGE_VALS[c.i]=sorted;
      // набор значений не изменился с прошлого раза → не трогаем DOM: экономим перестройку
      // 8 <select> с сотнями <option> на каждое нажатие клавиши (текущий выбор остаётся валидным).
      if(prev && prev.length===sorted.length && prev.join(',')===sorted.join(',')) return;
      ['min','max'].forEach(function(mm){
        var sel=selEl(mm,c.i); if(!sel)return;
        var keep=sel.value;
        sel.innerHTML='<option value="">Все</option>'+sorted.map(function(v){return '<option value="'+v+'">'+fmt(v,c.d)+'</option>';}).join('');
        if(keep&&vals[keep])sel.value=keep; else sel.value='';
      });
    });
  }

  // зависимые от/до: «до» показывает значения ≥ «от», «от» — значения ≤ «до» (по каждой колонке).
  // onlyCol — пересобрать только одну колонку (при change по конкретному селекту): не трогаем
  // остальные 6 колонок → нет пересборки ~2000 <option> на каждый выбор (было медленно).
  function syncRanges(onlyCol){
    COLS.forEach(function(c){
      if(onlyCol!=null && c.i!==onlyCol) return;
      var mn=selEl('min',c.i), mx=selEl('max',c.i);
      if(!mn||!mx)return;
      var all=RANGE_VALS[c.i]||[];
      var lo=mn.value!==''?parseFloat(mn.value):null, hi=mx.value!==''?parseFloat(mx.value):null;
      function opts(vals,cur){return '<option value="">Все</option>'+vals.map(function(v){return '<option value="'+v+'"'+(String(v)===String(cur)?' selected':'')+'>'+fmt(v,c.d)+'</option>';}).join('');}
      mn.innerHTML=opts(all.filter(function(v){return hi==null||v<=hi;}), mn.value);
      mx.innerHTML=opts(all.filter(function(v){return lo==null||v>=lo;}), mx.value);
    });
  }

  // список моделей/аналогов для подсказок (datalist) — зависит от выбранного бренда и типа
  // (правки ТЗ №1, №2): «Наши EVL» → только EVL+ГОСТ; конкретный бренд → только его аналоги;
  // текущий тип → только модели этого типа.
  // натуральная сортировка: числа сравниваем как числа (меньше→больше), а не как строки
  // (правка №1): чтобы 080/777 шло раньше 080/7117, а не наоборот.
  function natCmp(a,b){
    var ra=String(a).match(/\d+|\D+/g)||[], rb=String(b).match(/\d+|\D+/g)||[];
    for(var i=0;i<Math.min(ra.length,rb.length);i++){
      var x=ra[i], y=rb[i], nx=parseInt(x,10), ny=parseInt(y,10);
      if(!isNaN(nx)&&!isNaN(ny)){ if(nx!==ny)return nx-ny; }
      else if(x!==y){ return x<y?-1:1; }
    }
    return ra.length-rb.length;
  }
  function fillModels(){
    var dl=$('pfModels'); if(!dl||!DB)return;
    var ti=curType(), b=BMAP[selBrand], brandKey=(b&&!isOurs(b.k))?b.k:'', names={};
    Object.keys(DB.g).forEach(function(k){
      var g=DB.g[k];
      if(enabledIdx.indexOf(g.t)<0) return;
      if(ti>=0 && g.t!==ti) return;
      if(brandKey){
        var imp=brandModel(g,brandKey);
        if(imp)names[imp]=1;               // конкретный бренд → его аналоги
      }else{
        if(g.e){ var ze=zrOf(g.e); names[ze||g.e]=1; }   // наши виды → маркировка ZR
        if(g.p)names[g.p]=1;               // + ГОСТ (ПР/МР)
      }
    });
    dl.innerHTML=Object.keys(names).sort(natCmp).map(function(n){return '<option value="'+String(n).replace(/"/g,'&quot;')+'"></option>';}).join('');
  }

  // параметры из URL (приходят с мини-формы на главной): type, pw, os, tq
  var Q={};
  (location.search||'').replace(/^\?/,'').split('&').forEach(function(kv){ if(!kv)return; var a=kv.split('='); Q[a[0]]=decodeURIComponent((a[1]||'').replace(/\+/g,' ')); });

  function preApplyNumeric(){
    // точные min<col>/max<col> и поиск q — приходят из компактного режима на главной
    COLS.forEach(function(c){
      ['min','max'].forEach(function(mm){
        var key=mm+c.i; if(Q[key]==null||Q[key]==='')return;
        var sel=root.querySelector('[data-'+mm+'="'+c.i+'"]'); if(!sel)return;
        var ok=Array.prototype.some.call(sel.options,function(o){return o.value===Q[key];});
        if(ok)sel.value=Q[key];
      });
    });
    if(Q.q && qEl){ qEl.value=Q.q; }
    // legacy: pw/os/tq как одиночное значение → ближайший диапазон
    var map={pw:1, os:2, tq:3};
    Object.keys(map).forEach(function(k){
      if(Q[k]==null||Q[k]==='')return;
      var col=map[k], val=parseFloat(String(Q[k]).replace(',','.')); if(isNaN(val))return;
      var mn=root.querySelector('[data-min="'+col+'"]'), mx=root.querySelector('[data-max="'+col+'"]');
      function nums(sel){return Array.prototype.map.call(sel.options,function(o){return o.value;}).filter(function(v){return v!=='';}).map(parseFloat);}
      if(mn&&mn.value===''){var lo=nums(mn).filter(function(v){return v<=val;}); if(lo.length)mn.value=String(Math.max.apply(null,lo));}
      if(mx&&mx.value===''){var hi=nums(mx).filter(function(v){return v>=val;}); if(hi.length)mx.value=String(Math.min.apply(null,hi));}
    });
  }

  function loadDB(){ if(loadDB._d)return; loadDB._d=1;
  fetch(DATA_URL).then(function(r){return r.json();}).then(function(d){
    DB=d;
    enabledIdx=d.t.map(function(n,i){return ENABLED.indexOf(n)>=0?i:-1;}).filter(function(x){return x>=0;});
    if(lockType){ selType=d.t.indexOf(presetType); }
    else if(Q.type && d.t.indexOf(Q.type)>=0){ selType=d.t.indexOf(Q.type); }
    else if(presetType){ var pi=d.t.indexOf(presetType); selType=pi>=0?pi:-1; }
    else { selType=-1; }
    if(!lockType) buildPills();
    buildBrands();
    // бренд мог прийти со страницы бренда (data-brand) или из ?brand= — индекс нужен сразу
    var _b0=BMAP[selBrand]; if(_b0&&!isOurs(_b0.k))loadAnalogIdx(_b0);
    if(lockBrand){
      var _bo=BMAP[selBrand];
      var _bl=$('pfBrandLock');
      if(_bl) _bl.innerHTML='<span class="pf-brands-lbl">Показываем аналоги:</span><span class="pf-pill pf-pill--brand is-active" aria-current="true">'+((_bo&&_bo.n)||selBrand)+'</span>';
      var _th=root.querySelector('.pf-th-tz'); if(_th) _th.textContent=((_bo&&_bo.n)||selBrand)+' (наш аналог ZR)';
    }
    fillRanges();
    preApplyNumeric();
    fillModels();
    syncRanges();
    apply();
  }).catch(function(){
    var cnt=$('pfCount'); if(cnt)cnt.textContent='База временно недоступна';
    var tb=$('pfTable').tBodies[0];
    if(tb)tb.innerHTML='<tr><td colspan="'+NCOL+'" class="pf-empty">Не удалось загрузить базу. Обновите страницу или оставьте заявку — инженер подберёт.</td></tr>';
  });
  }
  // На /podbor калькулятор — основной контент, грузим базу сразу.
  // На главной это компактный мини-виджет (data-compact=1) — грузим базу (390КБ)
  // лениво, при приближении к виджету, чтобы не тормозить загрузку главной.
  if(compact && ('IntersectionObserver' in window)){
    var io=new IntersectionObserver(function(es){ es.forEach(function(e){ if(e.isIntersecting){ loadDB(); io.disconnect(); } }); }, {rootMargin:'700px'});
    io.observe(root);
    root.addEventListener('focusin', loadDB, {once:true});
    root.addEventListener('click', loadDB, {once:true});
  } else {
    loadDB();
  }

  function buildPills(){
    var box=$('pfTypes'); if(!box)return;
    var html='';
    if(enabledIdx.length>1) html+='<button type="button" class="pf-pill'+(selType<0?' is-active':'')+'" data-ti="-1">Все типы</button>';
    html+=enabledIdx.map(function(i){
      return '<button type="button" class="pf-pill'+(selType===i?' is-active':'')+'" data-ti="'+i+'">'+(TYPE_LABEL[DB.t[i]]||DB.t[i])+'</button>';
    }).join('');
    box.innerHTML=html;
    Array.prototype.forEach.call(box.querySelectorAll('.pf-pill'),function(b){
      b.addEventListener('click',function(){
        selType=parseInt(b.getAttribute('data-ti'));
        Array.prototype.forEach.call(box.querySelectorAll('.pf-pill'),function(x){x.classList.remove('is-active');});
        b.classList.add('is-active');
        fillModels(); fillRanges(); syncRanges(); apply();
      });
    });
  }

  function buildBrands(){
    var box=$('pfBrands'); if(!box||!DB)return;
    // Раньше здесь стоял ранний выход: без выбранного бренда переключатель прятался целиком
    // (п.12 прежней программы — «на общем подборе только наша марка ZR»). Заказчик отменил
    // 20.07.2026: на подборе по параметрам нужно выбирать и импортные бренды, а не только ZR.
    // Свёрнутый вид уже был написан ниже, но из-за этого выхода оставался недостижим.
    box.style.display='';
    var expanded=!!selBrand;
    box.innerHTML='<span class="pf-brands-lbl">Показать в марках:</span>'
      +'<span class="pf-brands-set" '+(expanded?'':'hidden')+'>'
      +BRANDS.filter(function(b){ return brandHasData(b.k)||b.k===selBrand; }).map(function(b){
        return '<button type="button" class="pf-pill pf-pill--brand'+(selBrand===b.k?' is-active':'')+'" data-bk="'+b.k.replace(/"/g,'&quot;')+'">'+b.n+'</button>';
      }).join('')+'</span>'
      +(expanded?'':'<button type="button" class="pf-pill" id="pfBrandsMore">Аналоги импортных брендов (SEW, NORD…) ▾</button>');
    var more=$('pfBrandsMore');
    if(more)more.addEventListener('click',function(){
      more.remove();
      var s=box.querySelector('.pf-brands-set'); if(s)s.hidden=false;
    });
    Array.prototype.forEach.call(box.querySelectorAll('.pf-pill--brand'),function(btn){
      btn.addEventListener('click',function(){
        selBrand=btn.getAttribute('data-bk')||'';
        Array.prototype.forEach.call(box.querySelectorAll('.pf-pill'),function(x){x.classList.remove('is-active');});
        btn.classList.add('is-active');
        var th=root.querySelector('.pf-th-tz'); var bb=BMAP[selBrand];
        if(th)th.textContent=(bb&&!isOurs(bb.k))?(bb.n+' (наш аналог ZR)'):'Типоразмер редуктора';
        if(bb&&!isOurs(bb.k))loadAnalogIdx(bb);   // ссылки «Заказать» ведут на карточки этого бренда
        fillModels(); fillRanges(); syncRanges(); apply();
      });
    });
  }

  function passRange(it){
    for(var ci=0;ci<COLS.length;ci++){
      var i=COLS[ci].i;
      var mn=selEl('min',i), mx=selEl('max',i);
      var v=it[i];
      if(mn&&mn.value!==''){ if(v==null||v<parseFloat(mn.value))return false; }
      if(mx&&mx.value!==''){ if(v==null||v>parseFloat(mx.value))return false; }
    }
    return true;
  }
  function normQ(s){ return String(s==null?'':s).toLowerCase().replace(/ё/g,'е').replace(/[^0-9a-zа-я]+/g,''); }
  function hayOf(g){
    if(g._nh!=null) return g._nh;
    var parts=[g.e,g.p];
    // ЗР-маркировка из номера(ов) EVL (заявлена в подсказке поиска: «ZR 603»)
    var mm=String(g.e||'').match(/EVL\s+([\d\/]+)/);
    if(mm){ mm[1].split('/').forEach(function(np){ var z=ZRMAP[parseInt(np,10)]; if(z!=null) parts.push('ZR '+z); }); }
    if(DB&&DB.t&&DB.t[g.t]!=null) parts.push(DB.t[g.t]);
    var a=g.a||{};
    // ВСЕ бренды + ВСЕ модели аналогов (не только первую)
    Object.keys(a).forEach(function(b){ parts.push(b); (a[b]||[]).forEach(function(m){ parts.push(m); }); });
    return (g._nh=normQ(parts.join(' ')));
  }
  var QSTOP={'редуктор':1,'редуктора':1,'редукторы':1,'мотор':1,'моторредуктор':1,'мотредуктор':1,'привод':1,'приводы':1,'купить':1,'аналог':1,'аналоги':1,'цена':1,'цены':1};
  function passQ(it){
    var raw=(qEl.value||'').trim(); if(!raw)return true;
    var hay=hayOf(DB.g[it[0]]);
    // токенный AND по нормализованной строке, общие слова («редуктор», «мотор»…) отбрасываем:
    // «SEW R107», «R 107», «ZR 603», «ЧМ 63», «мотор-редуктор SEW» — всё матчится
    var toks=raw.toLowerCase().split(/\s+/).map(normQ).filter(function(n){ return n && !QSTOP[n]; });
    if(!toks.length) return true;
    return toks.every(function(n){ return hay.indexOf(n)>=0; });
  }

  function apply(){
    if(!DB)return;
    if(!compact){ try{ renderSmartCards(); }catch(e){} }
    var ti=curType(), res=[];
    for(var k=0;k<DB.i.length;k++){
      var it=DB.i[k], gt=DB.g[it[0]].t;
      if(enabledIdx.indexOf(gt)<0)continue;
      // выбран бренд импорта → показываем только позиции, у которых есть его аналог
      if(!groupHasBrand(DB.g[it[0]]))continue;
      if(ti>=0 && gt!==ti)continue;
      if(!passQ(it))continue;
      if(!passRange(it))continue;
      res.push(it);
    }
    CUR=res; RENDER=0;
    $('pfCount').innerHTML='Найдено: <b>'+res.length+'</b>';
    if(compact){
      var op=$('pfOpen'); if(op){ op.href=buildOpenURL(); }
      return;
    }
    var body=$('pfTable').tBodies[0];
    if(res.length===0){
      body.innerHTML='<tr><td colspan="'+NCOL+'" class="pf-empty">Под эти параметры ничего не нашлось — смягчите фильтр или оставьте заявку, инженер подберёт.</td></tr>';
      $('pfMore').hidden=true; return;
    }
    body.innerHTML=''; more();
  }

  // ссылка на полную таблицу с текущими фильтрами (для компактного режима на главной)
  function buildOpenURL(){
    var q=[];
    var ti=curType(); if(ti>=0) q.push('type='+encodeURIComponent(DB.t[ti]));
    COLS.forEach(function(c){
      var mn=selEl('min',c.i), mx=selEl('max',c.i);
      if(mn&&mn.value!=='')q.push('min'+c.i+'='+encodeURIComponent(mn.value));
      if(mx&&mx.value!=='')q.push('max'+c.i+'='+encodeURIComponent(mx.value));
    });
    if(qEl&&qEl.value.trim()!=='')q.push('q='+encodeURIComponent(qEl.value.trim()));
    return pfx+'podbor.html'+(q.length?'?'+q.join('&'):'');
  }

  // Маркировка ZR (наша) по обозначению EVL — таблица соответствия из ТЗ.
  // Сдвоенные редукторы EVL A/B расшифровываются как ZR(A)/ZR(B). Суффикс исполнения
  // (напр. «Х») сохраняется. Если хоть одна часть без соответствия — ZR не показываем
  // (не выдумываем номера, которые могут уйти клиенту).
  var ZRMAP={193:939,194:949,195:959,196:969,197:979,198:989,199:999,1910:9109,1913:9139,1914:9149,1916:9169,
    183:838,184:848,185:858,186:868,187:878,188:888,189:898,1810:8108,1812:8128,1815:8158,1816:8168,1817:8178,1818:8188,
    737:603,747:604,757:605,767:606,777:607,797:609,7107:6010,7117:6010,7137:6013,7157:6015,
    63:606,71:607,80:608,90:609,
    163:636,164:646,165:656,166:666,167:676,168:686,169:696,1610:6106,1612:6126,1615:6156};
  function zrPart(p){var m=String(p).trim().match(/^(\d+)(.*)$/);if(!m)return null;var z=ZRMAP[parseInt(m[1],10)];return z==null?null:(z+m[2]);}
  function zrOf(evl){
    var m=String(evl).match(/^EVL\s+(.+)$/); if(!m)return null;
    var parts=m[1].split('/').map(function(p){return zrPart(p);});
    if(parts.some(function(z){return z==null;}))return null;
    return 'ZR '+parts.join('/');
  }

  function evlSlug(n){return n.toLowerCase().replace(/ /g,'-').replace(/\//g,'-').replace(/х/g,'x');}
  function frameSlug(s){return s.toLowerCase().replace(/х/g,'x').replace(/[^a-z0-9]+/g,'-').replace(/^-+|-+$/g,'');}

  /* ---- карточки /analog/ для таблицы подбора --------------------------------
     Индекс строится скриптом tools/gen_analog_index.py из реальных файлов analog/
     и лежит по одному файлу на бренд (самый крупный — nord, ~360 КБ), поэтому
     грузим только выбранный бренд и только когда его выбрали. */
  var AIDX={}, AIDX_LOADING={};
  var AIDX_NAME={'SEW EURODRIVE':'sew'};              // остальные совпадают с ключом в нижнем регистре
  function aidxName(b){ return AIDX_NAME[b.k] || b.k.toLowerCase().split(' ')[0]; }
  function loadAnalogIdx(b){
    var n=aidxName(b);
    if(AIDX[n]!==undefined||AIDX_LOADING[n])return;
    AIDX_LOADING[n]=1;
    fetch(pfx+'assets/analog-idx/'+n+'.json')
      .then(function(r){ return r.ok?r.json():null; })
      .then(function(j){ AIDX[n]=j; AIDX_LOADING[n]=0; if(j)apply(); })   // пришёл — перерисуем ссылки
      .catch(function(){ AIDX[n]=null; AIDX_LOADING[n]=0; });
  }
  function analogSlug(b,model,kw,ratio){
    var n=aidxName(b), idx=AIDX[n];
    if(!idx)return null;
    // В базе подбора модель часто записана несколькими обозначениями через запятую
    // («RV 32, MRV 32, MRV 118») — страница есть у каждого по отдельности, поэтому
    // берём первое, для которого нашлась запись, а не склеенную строку целиком.
    var ms=null, list=null, parts=String(model).split(',');
    for(var pi=0;pi<parts.length;pi++){
      var cand=frameSlug(parts[pi]);
      if(cand&&idx[cand]&&idx[cand].length){ ms=cand; list=idx[cand]; break; }
    }
    if(!list)return null;
    // Передаточные в базе подбора и в именах страниц не совпадают до цифры, поэтому берём
    // ближайшую реальную страницу: сначала с той же мощностью (она дискретна и точна),
    // и уже среди них — с минимальным расхождением по передаточному.
    var best=null,bestScore=Infinity;
    for(var i=0;i<list.length;i++){
      var e=list[i];
      var dkw=Math.abs(e[1]-kw)/Math.max(kw,0.01);
      var di=Math.abs(e[0]-ratio)/Math.max(ratio,0.01);
      var score=dkw*10+di;                        // мощность важнее: её клиент задаёт точно
      if(score<bestScore){bestScore=score;best=e;}
    }
    return best?(n+'-'+ms+best[2]):null;
  }
  function rowHtml(it){
    var g=DB.g[it[0]];
    var gost=Object.keys(g.g||{}).map(function(k){return g.g[k];}).filter(Boolean);
    var tds=COLS.map(function(c){return '<td data-label="'+c.m+'">'+fmt(it[c.i],c.d)+'</td>';}).join('');
    var tz, ord, b=BMAP[selBrand];
    if(b&&!isOurs(b.k)){
      var imp=brandModel(g,b.k);
      if(imp){
        tz='<td class="pf-tz"><b>'+b.n+' '+imp+'</b><span class="pf-tr-gost">наш аналог '+(zrOf(g.e)||g.e)+'</span></td>';
        // «Заказать» ведёт на БРЕНДОВУЮ карточку /analog/ — для всех брендов, а не только
        // для SEW (раньше у остальных стояла обезличенная карточка ZR, и бренд терялся).
        // Слаг не склеиваем: в именах файлов передаточные целые, в базе подбора дробные,
        // у дублей есть суффикс — берём ближайшую реальную страницу из индекса analog-idx.
        var au=analogSlug(b, imp, it[1], it[4]);
        ord=au
          ? '<td class="pf-order"><a class="pf-ord" href="/analog/'+au+'">Заказать</a></td>'
          : '<td class="pf-order"><a class="pf-ord" href="/reduktor/'+evlSlug(g.e)+'?imp='+encodeURIComponent(b.n+' '+imp)+'">Заказать</a></td>';
      }else{
        // EVL — внутренний код, наружу и в CRM уходит только маркировка ZR
        var _zrx=zrOf(g.e)||g.e;
        tz='<td class="pf-tz"><b>'+_zrx+'</b><span class="pf-tr-an">аналог '+b.n+' — по запросу</span></td>';
        ord='<td class="pf-order"><a class="pf-ord pf-ord--req" data-zayavka data-req="'+b.n+' → '+_zrx+'" href="#zayavka">Запрос</a></td>';
      }
    }else{
      // наши виды. ZR (по умолчанию): ZR крупно красным, EVL — серым кубиком.
      // EVL-вид: EVL крупно, ZR — серым кубиком. Импортные аналоги (≈) убраны (правка №2).
      var zr=zrOf(g.e);
      var mark='<b class="pf-zr">'+(zr||g.e)+'</b>';
      tz='<td class="pf-tz">'+mark+(g.p?'<span class="pf-tr-gost">'+g.p+'</span>':'')+'</td>';
      ord='<td class="pf-order"><a class="pf-ord" href="/reduktor/'+evlSlug(g.e)+'">Заказать</a></td>';
    }
    return '<tr>'+tz+tds+ord+'</tr>';
  }
  function more(){
    var end=Math.min(RENDER+STEP,CUR.length), html='';
    for(var k=RENDER;k<end;k++)html+=rowHtml(CUR[k]);
    $('pfTable').tBodies[0].insertAdjacentHTML('beforeend',html);
    RENDER=end; $('pfMore').hidden=RENDER>=CUR.length;
  }

  // --- выгрузка в CSV (открывается в Excel) ---
  function csvCell(v){ v=(v==null?'':String(v)); return /[";\n]/.test(v)?'"'+v.replace(/"/g,'""')+'"':v; }
  function exportCSV(){
    if(!CUR.length)return;
    var head=['Маркировка ZR','ПР','ГОСТ-обозначения','Импортные аналоги'].concat(COLS.map(function(c){return c.h;}));
    var lines=[head.map(csvCell).join(';')];
    CUR.forEach(function(it){
      var g=DB.g[it[0]];
      var gost=Object.keys(g.g||{}).map(function(k){return g.g[k];}).join(' / ');
      var ans=Object.keys(g.a||{}).map(function(k){return g.a[k][0];}).join(' / ');
      var row=[zrOf(g.e)||'', g.p||'', gost, ans].concat(COLS.map(function(c){return it[c.i]==null?'':fmt(it[c.i],c.d);}));
      lines.push(row.map(csvCell).join(';'));
    });
    var blob=new Blob(['﻿'+lines.join('\r\n')],{type:'text/csv;charset=utf-8'});
    var url=URL.createObjectURL(blob), a=document.createElement('a');
    a.href=url; a.download='podbor-reduktora-zavod-red.csv';
    document.body.appendChild(a); a.click();
    setTimeout(function(){URL.revokeObjectURL(url);a.remove();},120);
  }

  // события
  var t=null;
  // ввод модели/аналога → пересобрать диапазоны колонок под выбранную модель (каскад, правка №3)
  function deb(){clearTimeout(t);t=setTimeout(function(){fillRanges();syncRanges();apply();},160);}
  qEl.addEventListener('input',deb);

  /* ===== Умный поиск: ранжированные карточки «Наиболее подходящие» (RU/EN, приблизительно) ===== */
  var BRAND_RU={sew:['сью','сев','сэв'],nord:['норд'],bonfiglioli:['бонфильоли','бонфиглиоли','бонфилиоли'],motovario:['мотоварио'],bauer:['бауэр','бауер'],lenze:['ленце','лензе'],varvel:['варвель','варвел'],siti:['сити'],stm:['стм'],rossi:['росси'],watt:['ватт','ваттдрайв'],yilmaz:['йилмаз','йылмаз','юлмаз'],transtecno:['транстекно'],innovari:['инновари'],vemper:['вемпер'],innored:['иноред'],guomao:['гуомао'],keb:['кеб'],boneng:['боненг'],flender:['флендер']};
  var TYPE_EN={0:['worm'],1:['coaxial','inline','helicalinline'],2:['bevel','helicalbevel'],3:['flat','shaftmounted','shaft'],4:['helical','cylindrical']};
  var IMGT={0:'cat_worm',1:'cat_coaxial',2:'cat_bevel',3:'cat_flat',4:'cat_cylindrical'};
  var SIDX=null, SIDX_DF=null;
  function _sn(s){return String(s==null?'':s).toLowerCase().replace(/ё/g,'е').replace(/[^0-9a-zа-я]+/g,'');}
  function _stoks(s){return String(s==null?'':s).toLowerCase().replace(/ё/g,'е').split(/[^0-9a-zа-я]+/).filter(Boolean);}
  function _esc(s){return String(s==null?'':s).replace(/[<>&"]/g,function(c){return {'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;'}[c];});}
  function _rng(vals,dec){ if(!vals.length)return''; var lo=Math.min.apply(null,vals),hi=Math.max.apply(null,vals); var f=function(x){return (dec?x.toFixed(dec):String(Math.round(x))).replace(/,?\.?0+$/,'').replace('.',',');}; return lo===hi?f(lo):f(lo)+'–'+f(hi); }
  function buildSIDX(){
    if(SIDX)return SIDX;
    var rowsBy={}; for(var k2=0;k2<DB.i.length;k2++){var it=DB.i[k2];(rowsBy[it[0]]=rowsBy[it[0]]||[]).push(it);}
    SIDX=Object.keys(DB.g).map(function(key){
      var g=DB.g[key], gid=parseInt(key), toks={};
      function add(s){ _stoks(s).forEach(function(t){toks[t]=1;}); }
      add(g.e); add(g.p);
      var mm=String(g.e||'').match(/EVL\s+([\d\/]+)/), zr=null;
      if(mm)mm[1].split('/').forEach(function(np){var z=ZRMAP[parseInt(np,10)]; if(z!=null)add('ZR '+z);});
      // отображаемая маркировка — из единого источника zrOf(), иначе составные типоразмеры
      // («EVL 063/747») расходятся: карточка «ZR 606», строка таблицы «ZR 606/604» (57 групп из 104)
      zr=zrOf(g.e)||zr;
      add(DB.t[g.t]); (TYPE_EN[g.t]||[]).forEach(function(w){toks[w]=1;});
      var top=null;
      Object.keys(g.a||{}).forEach(function(b){ add(b); if(!top){var ms=g.a[b];top=(b.split(' ')[0]+' '+((ms&&ms[0])||'')).trim();} (BRAND_RU[_sn(b.split(' ')[0])]||[]).forEach(function(rw){toks[rw]=1;}); (g.a[b]||[]).forEach(function(m){add(m);}); });
      // конкатенированные маркировки как токены-единицы: «evl197», «zr603», «пр430», «r107»
      toks[_sn(g.e)]=1; if(g.p)toks[_sn(g.p)]=1;
      Object.keys(g.a||{}).forEach(function(b){ (g.a[b]||[]).forEach(function(m){ toks[_sn(m)]=1; }); });
      var rws=rowsBy[gid]||[];
      return {gid:gid,g:g,toks:toks,blob:Object.keys(toks).join(' '),zr:zr,top:top,
              power:_rng(rws.map(function(r){return r[1];}),1),ratio:_rng(rws.map(function(r){return r[4];}),0)};
    });
    SIDX_DF={}; SIDX.forEach(function(r){ for(var t in r.toks) SIDX_DF[t]=(SIDX_DF[t]||0)+1; });
    return SIDX;
  }
  function _lev1(a,b){ if(a===b)return true; var la=a.length,lb=b.length; if(Math.abs(la-lb)>1)return false; var i=0,j=0,d=0; while(i<la&&j<lb){ if(a[i]===b[j]){i++;j++;} else { if(++d>1)return false; if(la>lb)i++; else if(lb>la)j++; else{i++;j++;} } } if(i<la||j<lb)d++; return d<=1; }
  function scoreGroup(qtoks,qfull,rec,qexp){
    var score=0,matched=0,common=SIDX.length*0.9;
    // точное совпадение всей маркировки одним словом («evl197», «zr603», «r107») — топ
    if(qfull&&rec.toks[qfull]){ score+=90; matched++; }
    for(var qi=0;qi<qtoks.length;qi++){ var qt=qtoks[qi],best=0,isMatch=false;
      if(rec.toks[qt]){ if((SIDX_DF[qt]||0)>=common){ best=4; } else { best=(/\d/.test(qt)?55:26); isMatch=true; } }
      else if(qt.length>=3&&rec.blob.indexOf(qt)>=0){ best=12; isMatch=true; }
      else { var near=qexp&&qexp[qi]; if(near){ for(var t in near){ if(rec.toks[t]){best=9;isMatch=true;break;} } } }
      if(isMatch)matched++;
      score+=best;
    }
    if(!matched)return 0;
    if(qtoks.length>=2&&matched<Math.ceil(qtoks.length/2))return 0;
    return score+matched*4;
  }
  function rankGroups(query){
    var qtoks=_stoks(query).filter(function(t){return !QSTOP[_sn(t)];}).map(_sn).filter(Boolean);
    if(!qtoks.length)return[];
    var qfull=_sn(query);
    buildSIDX();
    // fuzzy-раскрытие опечаток ОДИН раз по словарю, а не Левенштейном в цикле по токенам
    // каждой из ~1800 групп (это давало 500+ мс на запрос). Раскрываем только те токены,
    // которых в базе вообще нет; существующие покрываются точным/подстрочным совпадением.
    var qexp=qtoks.map(function(qt){
      if(qt.length<4 || SIDX_DF[qt]) return null;
      var near=null;
      for(var t in SIDX_DF){ if(Math.abs(t.length-qt.length)<=1 && _lev1(t,qt)){ (near||(near={}))[t]=1; } }
      return near;
    });
    var scored=SIDX.map(function(rec){return {rec:rec,s:scoreGroup(qtoks,qfull,rec,qexp)};}).filter(function(x){return x.s>0;});
    scored.sort(function(a,b){return b.s-a.s || a.rec.gid-b.rec.gid;});
    return scored.slice(0,24);
  }
  function _brandFromToken(tok){ // «сью»/«sew»→ключ бренда
    if(BRAND_RU[tok])return tok;
    for(var k in BRAND_RU){ if(BRAND_RU[k].indexOf(tok)>=0)return k; }
    return tok;
  }
  function _analogFor(g,qBrands){ // аналог искомого бренда, иначе первый
    var a=g.a||{};
    // приоритет — бренд страницы/URL: на /brands/sew чужой бренд в карточке недопустим
    var _b=BMAP[selBrand];
    if(_b&&!isOurs(_b.k)){ var _m=brandModel(g,_b.k); if(_m) return (_b.n+' '+_m).trim(); }
    if(qBrands&&qBrands.length){
      var keys=Object.keys(a);
      for(var i=0;i<keys.length;i++){ var b=keys[i], bn=_sn(b.split(' ')[0]);
        if(qBrands.indexOf(bn)>=0){ var m=a[b]; return (b.split(' ')[0]+' '+((m&&m[0])||'')).trim(); }
      }
    }
    var kk=Object.keys(a); if(!kk.length)return null; var mm=a[kk[0]];
    return (kk[0].split(' ')[0]+' '+((mm&&mm[0])||'')).trim();
  }
  function _cardHTML(x,qBrands){
    var rec=x.rec,g=rec.g,img='/assets/catalog/'+(IMGT[g.t]||'cat_cylindrical')+'.webp';
    var an=_analogFor(g,qBrands)||rec.top;
    // Если пользователь пришёл с бренда импорта (или назвал его в запросе) — карточка принадлежит
    // импорту: бренд крупно, наш ZR вторичной строкой. Иначе прежний порядок (ZR крупно).
    var _b=BMAP[selBrand], _bctx=((_b&&!isOurs(_b.k))||(qBrands&&qBrands.length))&&an;
    var _zr=rec.zr||zrOf(g.e)||g.e;
    // в брендовом режиме заголовок — чистая импортная модель (ГОСТ-код ПР/МР тут лишний шум)
    var mark=_bctx?an:_zr, sub=_bctx?'':(g.p||''), anLine=_bctx?('наш аналог '+_zr):(an?('Аналог: '+an):'');
    var chips=(rec.power?'<span>'+rec.power+' кВт</span>':'')+(rec.ratio?'<span>i '+rec.ratio+'</span>':'');
    return '<button type="button" class="pfc" data-evl="'+_esc(g.e)+'">'
      +'<span class="pfc-media"><img src="'+img+'" alt="'+_esc(mark)+'" loading="lazy" onerror="this.style.display=\'none\';this.parentNode.classList.add(\'noimg\')"></span>'
      +'<span class="pfc-b"><span class="pfc-type">'+_esc(DB.t[g.t]||'')+'</span>'
      +'<span class="pfc-mark">'+_esc(mark)+(sub?' <em>'+_esc(sub)+'</em>':'')+'</span>'
      +(anLine?'<span class="pfc-an">'+_esc(anLine)+'</span>':'')
      +'<span class="pfc-chips">'+chips+'</span>'
      +'<span class="pfc-foot"><span class="pfc-price">по запросу</span><span class="pfc-cta">Показать →</span></span>'
      +'</span></button>';
  }
  var _cardHost=null;
  function _injectCardCSS(){ if(document.getElementById('pf-cards-css'))return; var s=document.createElement('style'); s.id='pf-cards-css';
    s.textContent=".pf-cards{margin:0 0 26px}.pf-ch{display:flex;align-items:baseline;gap:12px;flex-wrap:wrap;margin:0 0 14px}.pf-ch h2{font:700 20px/1.1 'Space Grotesk',sans-serif;margin:0;color:var(--text,#101f2a)}.pf-ch span{font-size:13px;color:var(--muted,#5c6b76)}.pf-cempty{color:var(--muted,#5c6b76);font-size:14px;background:var(--card,#fff);border:1px solid var(--line,#e2e6e0);border-radius:12px;padding:16px 18px}.pf-cempty a{color:var(--red,#cf1616)}"
    +".pf-cgrid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px}"
    +".pfc{display:flex;flex-direction:column;text-align:left;padding:0;background:var(--card,#fff);border:1px solid var(--line,#e6eae6);border-radius:14px;overflow:hidden;cursor:pointer;font:inherit;color:inherit;transition:box-shadow .16s,transform .16s,border-color .16s}"
    +".pfc:hover{box-shadow:0 12px 30px rgba(16,31,42,.1);transform:translateY(-2px);border-color:var(--red,#cf1616)}"
    +".pfc-media{position:relative;aspect-ratio:1/.66;background:linear-gradient(160deg,#f4f7f9,#e9eef1);display:flex;align-items:center;justify-content:center;padding:10px}"
    +".pfc-media img{max-width:100%;max-height:100%;object-fit:contain;mix-blend-mode:multiply}.pfc-media.noimg::after{content:'ЗР';font:700 24px/1 'Space Grotesk',sans-serif;color:#c3ccd2}"
    +".pfc-b{display:flex;flex-direction:column;gap:6px;padding:12px 13px 13px;flex:1}"
    +".pfc-type{font-size:11px;color:var(--muted,#7c8b95);text-transform:uppercase;letter-spacing:.03em}"
    +".pfc-mark{font:700 15px/1.2 'Space Grotesk',sans-serif;color:var(--text,#101f2a)}.pfc-mark em{font-style:normal;font-weight:500;font-size:12.5px;color:var(--muted,#7c8b95)}"
    +".pfc-an{font-size:12px;color:var(--muted,#5c6b76)}"
    +".pfc-chips{display:flex;flex-wrap:wrap;gap:5px;margin-top:2px}.pfc-chips span{font:500 11px/1 'IBM Plex Mono',ui-monospace,monospace;color:var(--muted,#5c6b76);background:var(--bg,#f2f5f2);border:1px solid var(--line,#e6eae6);border-radius:6px;padding:4px 7px}"
    +".pfc-foot{display:flex;align-items:center;justify-content:space-between;margin-top:auto;padding-top:8px}.pfc-price{font-size:12.5px;font-weight:700;color:var(--text,#101f2a)}.pfc-cta{font-size:12.5px;font-weight:600;color:var(--red,#cf1616)}"
    +"@media(max-width:1000px){.pf-cgrid{grid-template-columns:repeat(3,1fr)}}@media(max-width:720px){.pf-cgrid{grid-template-columns:repeat(2,1fr)}}@media(max-width:460px){.pf-cgrid{grid-template-columns:1fr 1fr;gap:10px}.pfc-mark{font-size:13.5px}}";
    document.head.appendChild(s);
  }
  function _ensureHost(){
    if(_cardHost&&document.body.contains(_cardHost))return _cardHost;
    _cardHost=document.createElement('section'); _cardHost.id='pfCards'; _cardHost.className='pf-cards'; _cardHost.style.display='none';
    root.parentNode.insertBefore(_cardHost,root);
    _cardHost.addEventListener('click',function(e){
      var b=e.target.closest&&e.target.closest('.pfc'); if(!b)return;
      var evl=b.getAttribute('data-evl'); if(qEl){qEl.value=evl; try{fillRanges();syncRanges();}catch(_){} apply();}
      var tbl=$('pfTable'); if(tbl)tbl.scrollIntoView({behavior:'smooth',block:'start'});
    });
    _injectCardCSS();
    return _cardHost;
  }
  function renderSmartCards(){
    if(compact)return;
    var host=_ensureHost(), q=(qEl.value||'').trim();
    if(!q){host.style.display='none';host.innerHTML='';return;}
    var top=rankGroups(q); host.style.display='';
    // на странице бренда (или при ?brand=) в карточки не должен попадать чужой бренд
    top=top.filter(function(x){ return groupHasBrand(x.rec.g); });
    if(!top.length){ host.innerHTML='<div class="pf-ch"><h2>Наиболее подходящие</h2></div><p class="pf-cempty">По запросу «'+_esc(q)+'» точную карточку не нашли — проверьте написание модели или подберите фильтрами ниже, либо <a href="#" data-zayavka>оставьте заявку</a> — инженер подберёт за 15 минут.</p>'; return; }
    var qBrands=_stoks(q).map(_sn).filter(Boolean).map(_brandFromToken);
    host.innerHTML='<div class="pf-ch"><h2>Наиболее подходящие</h2><span>'+top.length+(top.length>=24?'+':'')+' по запросу «'+_esc(q)+'»</span></div><div class="pf-cgrid">'+top.map(function(x){return _cardHTML(x,qBrands);}).join('')+'</div>';
  }
  Array.prototype.forEach.call(root.querySelectorAll('.pf-sel[data-min],.pf-sel[data-max]'),function(s){s.addEventListener('change',function(){
    var col=s.getAttribute('data-min')||s.getAttribute('data-max');
    syncRanges(col?parseInt(col):undefined); apply();
  });});
  $('pfReset').addEventListener('click',function(){
    qEl.value='';
    Array.prototype.forEach.call(root.querySelectorAll('.pf-sel[data-min],.pf-sel[data-max]'),function(s){s.value='';});
    fillRanges(); syncRanges(); apply();
  });
  // «Получить выгрузку на почту» — открывает заявку с параметрами подбора (modal.js откроет форму по data-zayavka). Клиент не скачивает — мы отправляем сами.
  if($('pfExport'))$('pfExport').addEventListener('click',function(){
    var parts=[];
    COLS.forEach(function(c){var mn=root.querySelector('[data-min="'+c.i+'"]'),mx=root.querySelector('[data-max="'+c.i+'"]');var f=mn&&mn.value,t2=mx&&mx.value;if(f||t2)parts.push(c.h.split(',')[0]+': '+(f?'от '+f:'')+(t2?' до '+t2:''));});
    if(qEl&&qEl.value.trim())parts.push('модель/аналог: '+qEl.value.trim());
    // бренд отдельной фразой, а не в списке параметров: менеджеру в CRM важнее всего, с какой
    // брендовой страницы пришёл лид, и в тексте это должно читаться сразу.
    var _bn=brandLabel();
    var msg='Прошу прислать на почту выгрузку подходящих типоразмеров ('+(CUR?CUR.length:0)+' шт)'
      +(_bn?' по бренду '+_bn:'')+' по параметрам: '+(parts.length?parts.join('; '):'без фильтра')+'.';
    var m=document.getElementById('zrMsg'); if(m){ m.value=msg; }
  });
  if($('pfMore'))$('pfMore').addEventListener('click',more);

  // кнопка «Запрос» (строка без аналога выбранного бренда) → подставить в заявку
  root.addEventListener('click',function(e){
    var b=e.target.closest('[data-req]'); if(!b)return;
    var req=b.getAttribute('data-req');
    var m=document.getElementById('zrMsg');
    if(m)m.value='Нужен аналог: '+req+'. Прошу подобрать наш редуктор, дать цену и срок.';
  });

  // предзаполнить тип в сообщении формы-заявки (modal.js) — делегированно, для ВСЕХ кнопок заявки,
  // включая кнопки в шапке страницы (вне #pfRoot). guard !msg.value не перетирает ввод и текст «Запрос».
  document.addEventListener('click',function(e){
    if(!e.target.closest('[data-zayavka]'))return;
    var tname=(DB&&selType>=0)?DB.t[selType]:presetType;
    var want=TYPE_LABEL[tname]||tname;
    var msg=document.getElementById('zrMsg');
    // бренд в тексте заявки обязателен: иначе менеджер в CRM не видит, что лид пришёл с /brands/sew
    var bn=brandLabel();
    if((want||bn)&&msg&&!msg.value){
      msg.value='Интересует '+(want?want.toLowerCase()+' редуктор':'редуктор')+(bn?', бренд '+bn:'')
        +' (из таблицы подбора). Прошу подобрать типоразмер, цену и срок.';
    }
  });
})();
