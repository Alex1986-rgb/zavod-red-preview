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

  var ENABLED=['червячный','соосно-цилиндрический','коническо-цилиндрический','плоско-цилиндрический','цилиндрический'];
  var enabledIdx=[];

  // Параметры: индекс в позиции, заголовок, знаков после запятой
  var COLS=[
    {i:1, h:'Мощность двигателя, кВт',                 d:2},
    {i:2, h:'Обороты на выходе n вых, об/мин',          d:1},
    {i:3, h:'Крутящий момент на вых. валу Tном, Н·м',   d:1},
    {i:4, h:'Передаточное число, U',                    d:2},
    {i:5, h:'Консольная нагрузка Fном, Н',              d:0},
    {i:6, h:'Сервис-фактор, Sfном',                     d:2},
    {i:7, h:'Обороты двигателя n вх, об/мин',            d:0}
  ];
  var NCOL=COLS.length+2;

  function fmt(x,d){ if(x==null)return '—'; var p=Math.pow(10,d==null?2:d); var v=Math.round(x*p)/p; return String(v).replace('.',','); }

  var headHtml='<tr><th class="pf-th-tz">Типоразмер редуктора</th>'
    +COLS.map(function(c){return '<th>'+c.h+'</th>';}).join('')+'<th class="pf-th-ord">Заказ</th></tr>';

  var filterHtml='<tr class="pf-frow"><td><input class="pf-in" id="pfQ" type="text" placeholder="Модель / аналог" autocomplete="off" list="pfModels" inputmode="search"><datalist id="pfModels"></datalist></td>'
    +COLS.map(function(c){
        return '<td><div class="pf-rg"><span>от</span><select class="pf-sel" data-min="'+c.i+'"><option value="">Все</option></select></div>'
             +'<div class="pf-rg"><span>до</span><select class="pf-sel" data-max="'+c.i+'"><option value="">Все</option></select></div></td>';
      }).join('')+'<td></td></tr>';

  var btnHtml='<tr class="pf-brow"><td colspan="'+NCOL+'"><div class="pf-toolbar">'
    +'<button type="button" class="pf-btn pf-btn--ghost" id="pfReset">Сбросить фильтр</button>'
    +'<button type="button" class="pf-btn pf-btn--red" id="pfExport" data-zayavka>Получить выгрузку на почту</button>'
    +'<span class="pf-count" id="pfCount"></span></div></td></tr>';

  var pillsHtml='<div class="pf-brands" id="pfBrands"></div>'+(lockType?'':'<div class="pf-types" id="pfTypes"></div>');

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
   +'<p class="pf-note">'+(compact?'Задайте параметры — покажем число подходящих типоразмеров и откроем их в таблице подбора. ':'')+'Таблица справочная, по параметрам нашего производства (обозначения EVL и ГОСТ, импортные аналоги). Точные размеры, момент с сервис-фактором, наличие, цену и срок подтверждает инженер по заявке.</p>'
   +'<div class="pf-ask"><span>Не нашли нужный типоразмер или нужен расчёт под нагрузку?</span><button class="pf-cta" type="button" data-zayavka>Инженер подберёт под задачу</button></div>';

  var DB=null, RENDER=0, STEP=40, CUR=[], selType=-1, selBrand='', RANGE_VALS={}, $=function(id){return document.getElementById(id);};
  var qEl=$('pfQ');
  // переключатель брендов: ключ в g.a → отображение → slug бренд-страниц /analog/<s>-<frame>
  var BRANDS=[{k:'',n:'Наши EVL',s:''},{k:'SEW EURODRIVE',n:'SEW',s:'sew'}];
  var BMAP={}; BRANDS.forEach(function(b){BMAP[b.k]=b;});

  function curType(){
    if(lockType) return DB?DB.t.indexOf(presetType):-1;
    return selType;
  }
  function rowsOfType(){
    var ti=curType();
    return DB.i.filter(function(it){var gt=DB.g[it[0]].t; return enabledIdx.indexOf(gt)>=0 && (ti<0||gt===ti);});
  }
  function fillRanges(){
    var base=rowsOfType();
    COLS.forEach(function(c){
      var vals={}, _p=Math.pow(10,c.d==null?2:c.d);
      base.forEach(function(it){ if(it[c.i]!=null)vals[Math.round(it[c.i]*_p)/_p]=1; });
      var sorted=Object.keys(vals).map(parseFloat).sort(function(a,b){return a-b;});
      RANGE_VALS[c.i]=sorted;
      ['min','max'].forEach(function(mm){
        var sel=root.querySelector('[data-'+mm+'="'+c.i+'"]'); if(!sel)return;
        var keep=sel.value;
        sel.innerHTML='<option value="">Все</option>'+sorted.map(function(v){return '<option value="'+v+'">'+fmt(v,c.d)+'</option>';}).join('');
        if(keep&&vals[keep])sel.value=keep; else sel.value='';
      });
    });
  }

  // зависимые от/до: «до» показывает значения ≥ «от», «от» — значения ≤ «до» (по каждой колонке)
  function syncRanges(){
    COLS.forEach(function(c){
      var mn=root.querySelector('[data-min="'+c.i+'"]'), mx=root.querySelector('[data-max="'+c.i+'"]');
      if(!mn||!mx)return;
      var all=RANGE_VALS[c.i]||[];
      var lo=mn.value!==''?parseFloat(mn.value):null, hi=mx.value!==''?parseFloat(mx.value):null;
      function opts(vals,cur){return '<option value="">Все</option>'+vals.map(function(v){return '<option value="'+v+'"'+(String(v)===String(cur)?' selected':'')+'>'+fmt(v,c.d)+'</option>';}).join('');}
      mn.innerHTML=opts(all.filter(function(v){return hi==null||v<=hi;}), mn.value);
      mx.innerHTML=opts(all.filter(function(v){return lo==null||v>=lo;}), mx.value);
    });
  }

  // список моделей/аналогов для подсказок (datalist) — удобно на телефоне
  function fillModels(){
    var dl=$('pfModels'); if(!dl||!DB)return;
    var names={};
    Object.keys(DB.g).forEach(function(k){var g=DB.g[k]; if(g.e)names[g.e]=1; if(g.p)names[g.p]=1;
      Object.keys(g.a||{}).forEach(function(ak){if(g.a[ak]&&g.a[ak][0])names[g.a[ak][0]]=1;});});
    dl.innerHTML=Object.keys(names).sort().map(function(n){return '<option value="'+String(n).replace(/"/g,'&quot;')+'"></option>';}).join('');
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

  fetch(DATA_URL).then(function(r){return r.json();}).then(function(d){
    DB=d;
    enabledIdx=d.t.map(function(n,i){return ENABLED.indexOf(n)>=0?i:-1;}).filter(function(x){return x>=0;});
    if(lockType){ selType=d.t.indexOf(presetType); }
    else if(Q.type && d.t.indexOf(Q.type)>=0){ selType=d.t.indexOf(Q.type); }
    else if(presetType){ var pi=d.t.indexOf(presetType); selType=pi>=0?pi:-1; }
    else { selType=-1; }
    if(!lockType) buildPills();
    buildBrands();
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
        fillRanges(); syncRanges(); apply();
      });
    });
  }

  function buildBrands(){
    var box=$('pfBrands'); if(!box||!DB)return;
    box.innerHTML='<span class="pf-brands-lbl">Показать в марках:</span>'+BRANDS.map(function(b){
      return '<button type="button" class="pf-pill pf-pill--brand'+(selBrand===b.k?' is-active':'')+'" data-bk="'+b.k.replace(/"/g,'&quot;')+'">'+b.n+'</button>';
    }).join('');
    Array.prototype.forEach.call(box.querySelectorAll('.pf-pill'),function(btn){
      btn.addEventListener('click',function(){
        selBrand=btn.getAttribute('data-bk')||'';
        Array.prototype.forEach.call(box.querySelectorAll('.pf-pill'),function(x){x.classList.remove('is-active');});
        btn.classList.add('is-active');
        var th=root.querySelector('.pf-th-tz'); if(th)th.textContent=(selBrand&&BMAP[selBrand])?(BMAP[selBrand].n+' (наш аналог EVL)'):'Типоразмер редуктора';
        apply();
      });
    });
  }

  function passRange(it){
    for(var ci=0;ci<COLS.length;ci++){
      var i=COLS[ci].i;
      var mn=root.querySelector('[data-min="'+i+'"]'), mx=root.querySelector('[data-max="'+i+'"]');
      var v=it[i];
      if(mn&&mn.value!==''){ if(v==null||v<parseFloat(mn.value))return false; }
      if(mx&&mx.value!==''){ if(v==null||v>parseFloat(mx.value))return false; }
    }
    return true;
  }
  function passQ(it){
    var q=(qEl.value||'').trim().toLowerCase(); if(!q)return true;
    var g=DB.g[it[0]];
    var hay=[g.e,g.p].concat(Object.keys(g.g||{}).map(function(k){return g.g[k];}))
            .concat(Object.keys(g.a||{}).map(function(k){return g.a[k][0];})).join(' ').toLowerCase();
    return hay.indexOf(q)>=0;
  }

  function apply(){
    if(!DB)return;
    var ti=curType(), res=[];
    for(var k=0;k<DB.i.length;k++){
      var it=DB.i[k], gt=DB.g[it[0]].t;
      if(enabledIdx.indexOf(gt)<0)continue;
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
      var mn=root.querySelector('[data-min="'+c.i+'"]'), mx=root.querySelector('[data-max="'+c.i+'"]');
      if(mn&&mn.value!=='')q.push('min'+c.i+'='+encodeURIComponent(mn.value));
      if(mx&&mx.value!=='')q.push('max'+c.i+'='+encodeURIComponent(mx.value));
    });
    if(qEl&&qEl.value.trim()!=='')q.push('q='+encodeURIComponent(qEl.value.trim()));
    return pfx+'podbor.html'+(q.length?'?'+q.join('&'):'');
  }

  function evlSlug(n){return n.toLowerCase().replace(/ /g,'-').replace(/\//g,'-').replace(/х/g,'x');}
  function frameSlug(s){return s.toLowerCase().replace(/х/g,'x').replace(/[^a-z0-9]+/g,'-').replace(/^-+|-+$/g,'');}
  function rowHtml(it){
    var g=DB.g[it[0]];
    var gost=Object.keys(g.g||{}).map(function(k){return g.g[k];}).filter(Boolean);
    var tds=COLS.map(function(c){return '<td>'+fmt(it[c.i],c.d)+'</td>';}).join('');
    var tz, ord, b=BMAP[selBrand];
    if(b&&b.k){
      var imp=(g.a&&g.a[b.k])?g.a[b.k][0]:null;
      if(imp){
        tz='<td class="pf-tz"><b>'+b.n+' '+imp+'</b><span class="pf-tr-gost">наш аналог '+g.e+'</span></td>';
        ord='<td class="pf-order"><a class="pf-ord" href="/analog/'+b.s+'-'+frameSlug(imp)+'">Заказать</a></td>';
      }else{
        tz='<td class="pf-tz"><b>'+g.e+'</b><span class="pf-tr-an">аналог '+b.n+' — по запросу</span></td>';
        ord='<td class="pf-order"><a class="pf-ord pf-ord--req" data-zayavka data-req="'+b.n+' → '+g.e+'" href="#zayavka">Запрос</a></td>';
      }
    }else{
      var ans=Object.keys(g.a||{}).slice(0,3).map(function(k){return g.a[k][0];});
      var sub=[]; if(g.p)sub.push(g.p); gost.forEach(function(x){sub.push(x);});
      var an=ans.length?'<span class="pf-tr-an">≈ '+ans.join(' · ')+'</span>':'';
      tz='<td class="pf-tz"><b>'+g.e+'</b>'+(sub.length?'<span class="pf-tr-gost">'+sub.join(' · ')+'</span>':'')+an+'</td>';
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
    var head=['Типоразмер (EVL)','ПР','ГОСТ-обозначения','Импортные аналоги'].concat(COLS.map(function(c){return c.h;}));
    var lines=[head.map(csvCell).join(';')];
    CUR.forEach(function(it){
      var g=DB.g[it[0]];
      var gost=Object.keys(g.g||{}).map(function(k){return g.g[k];}).join(' / ');
      var ans=Object.keys(g.a||{}).map(function(k){return g.a[k][0];}).join(' / ');
      var row=[g.e, g.p||'', gost, ans].concat(COLS.map(function(c){return it[c.i]==null?'':fmt(it[c.i],c.d);}));
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
  function deb(){clearTimeout(t);t=setTimeout(apply,160);}
  qEl.addEventListener('input',deb);
  Array.prototype.forEach.call(root.querySelectorAll('.pf-sel[data-min],.pf-sel[data-max]'),function(s){s.addEventListener('change',function(){syncRanges();apply();});});
  $('pfReset').addEventListener('click',function(){
    qEl.value='';
    Array.prototype.forEach.call(root.querySelectorAll('.pf-sel[data-min],.pf-sel[data-max]'),function(s){s.value='';});
    syncRanges(); apply();
  });
  // «Получить выгрузку на почту» — открывает заявку с параметрами подбора (modal.js откроет форму по data-zayavka). Клиент не скачивает — мы отправляем сами.
  if($('pfExport'))$('pfExport').addEventListener('click',function(){
    var parts=[];
    COLS.forEach(function(c){var mn=root.querySelector('[data-min="'+c.i+'"]'),mx=root.querySelector('[data-max="'+c.i+'"]');var f=mn&&mn.value,t2=mx&&mx.value;if(f||t2)parts.push(c.h.split(',')[0]+': '+(f?'от '+f:'')+(t2?' до '+t2:''));});
    if(qEl&&qEl.value.trim())parts.push('модель/аналог: '+qEl.value.trim());
    var msg='Прошу прислать на почту выгрузку подходящих типоразмеров ('+(CUR?CUR.length:0)+' шт) по параметрам: '+(parts.length?parts.join('; '):'без фильтра')+'.';
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

  // предзаполнить тип в сообщении формы-заявки (modal.js)
  Array.prototype.forEach.call(root.querySelectorAll('[data-zayavka]'),function(b){
    b.addEventListener('click',function(){
      var tname=(DB&&selType>=0)?DB.t[selType]:presetType;
      var want=TYPE_LABEL[tname]||tname;
      var msg=document.getElementById('zrMsg');
      if(want&&msg&&!msg.value){ msg.value='Интересует '+want.toLowerCase()+' редуктор (из таблицы подбора). Прошу подобрать типоразмер, цену и срок.'; }
    });
  });
})();
