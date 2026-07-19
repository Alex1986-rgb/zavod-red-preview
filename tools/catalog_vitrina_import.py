#!/usr/bin/env python3
"""Пп.2–5: витрина «Каталог редукторов» (catalog/index.html) на данных import-catalog.json.

Было: 104 статичные ZR-карточки с единственным кросс-референсом Motovario.
Стало: рендер из import-catalog.json (498 позиций, модели всех брендов) —
  п.2 поиск находит модель любого бренда (NORD SK, SEW R107, NMRV…);
  п.3 выбрал NORD → карточки «NORD …», оригиналы бренда;
  п.4 ZR на витрине скрыт (оригинал — на лице карточки, ZR — внутри карточки товара);
  п.5 фильтр = Поиск / Тип / Бренд (Мощность и Передаточное убраны).

Идемпотентно.
"""
import os, re, json
from collections import Counter

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
os.chdir(ROOT)
PATH = "catalog/index.html"

d = json.load(open("assets/import-catalog.json", encoding="utf-8"))
cards = d["cards"]
TYPES = d["t"]
tc = Counter(c["t"] for c in cards)
bc = Counter(c["b"] for c in cards)

# --- опции фильтра ---
type_opts = '<option value="">Все типы</option>' + "".join(
    f'<option value="{i}">{TYPES[i][0].upper()+TYPES[i][1:]} ({tc[i]})</option>'
    for i in range(len(TYPES)) if tc.get(i, 0) > 0
)
brand_opts = '<option value="">Все бренды</option>' + "".join(
    f'<option value="{b}">{b} ({n})</option>' for b, n in bc.most_common()
)

# --- JS-рендер ---
JS = r"""
(function(){
  var GRID=document.getElementById('pcard-grid');if(!GRID)return;
  var fQ=document.getElementById('flt-q'),fT=document.getElementById('flt-type'),fB=document.getElementById('flt-brand'),fS=document.getElementById('cat-sort');
  var CNT=document.getElementById('cat-count'),EMPTY=document.getElementById('cat-empty');
  var MW=document.getElementById('cat-more-wrap'),MB=document.getElementById('cat-more');
  var TYPES=['червячный','соосно-цилиндрический','коническо-цилиндрический','плоско-цилиндрический','цилиндрический'];
  var STEP=24,ALL=[],flt=[],shown=0;
  function esc(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});}
  function norm(s){return String(s==null?'':s).toLowerCase().replace(/\s+/g,'');}
  function pwv(c){var m=String(c.pw||'').replace(',','.').match(/[0-9.]+/);return m?parseFloat(m[0]):0;}
  function card(c){
    var name=esc(c.b+' '+c.m);
    var url=c.u?('/analog/'+c.u):('/reduktor/'+c.r);
    var chips=[];if(c.pw)chips.push(c.pw+' кВт');if(c.i)chips.push('i '+c.i);
    return '<article class="pcard">'
      +'<div class="pcard-media"><img src="/assets/catalog/'+esc(c.im||'cat_worm')+'.webp" alt="'+name+' — мотор-редуктор" loading="lazy" onerror="this.style.display=\'none\';this.parentNode.classList.add(\'noimg\')">'
      +'<span class="pcard-badge imp">'+esc(c.b)+'</span><span class="pcard-stock">под заказ</span></div>'
      +'<div class="pcard-body"><span class="pcard-type">'+esc(TYPES[c.t]||'')+(c.c?' · '+esc(c.c):'')+'</span>'
      +'<a class="pcard-title" href="'+url+'">'+name+'</a>'
      +'<div class="pcard-chips">'+chips.map(function(x){return '<span>'+esc(x)+'</span>';}).join('')+'</div>'
      +'<div class="pcard-foot"><span class="pcard-price">Цена: <b>по запросу</b></span><a class="zr-more-btn" href="'+url+'">Подробнее →</a></div>'
      +'</div></article>';
  }
  function refilter(){
    var q=norm(fQ&&fQ.value||''),t=fT&&fT.value||'',b=fB&&fB.value||'';
    flt=ALL.filter(function(c){
      var hay=norm(c.b+' '+c.m+' '+(c.z||''));
      return (!t||String(c.t)===t)&&(!b||c.b===b)&&(!q||hay.indexOf(q)>=0);
    });
    var v=fS&&fS.value;
    if(v==='az')flt.sort(function(a,b){return (a.b+' '+a.m).localeCompare(b.b+' '+b.m,'ru');});
    else if(v==='power')flt.sort(function(a,b){return pwv(a)-pwv(b);});
    GRID.innerHTML='';shown=0;more();
    if(CNT)CNT.textContent=flt.length;
    if(EMPTY)EMPTY.style.display=flt.length?'none':'block';
  }
  function more(){
    var next=flt.slice(shown,shown+STEP);
    GRID.insertAdjacentHTML('beforeend',next.map(card).join(''));
    shown+=next.length;
    if(MW)MW.hidden=shown>=flt.length;
  }
  [fQ,fT,fB,fS].forEach(function(el){if(el){el.addEventListener('input',refilter);el.addEventListener('change',refilter);}});
  var rst=document.getElementById('flt-reset');if(rst)rst.addEventListener('click',function(){if(fQ)fQ.value='';if(fT)fT.value='';if(fB)fB.value='';refilter();});
  if(MB)MB.addEventListener('click',more);
  var open=document.getElementById('flt-open'),panel=document.getElementById('cat-filters');
  if(open)open.addEventListener('click',function(){panel.classList.toggle('open');});
  fetch('/assets/import-catalog.json?v=311').then(function(r){return r.json();}).then(function(data){
    ALL=data.cards||[];refilter();
  }).catch(function(){GRID.innerHTML='<p class="lead" style="grid-column:1/-1">Каталог временно недоступен — воспользуйтесь калькулятором подбора.</p>';});
})();
"""

NEW_GRID = (
    '<div class="pcard-grid" id="pcard-grid"></div>\n'
    '<div class="cat-empty" id="cat-empty" style="display:none">По выбранным фильтрам ничего не найдено. '
    '<button class="flt-reset" type="button" onclick="document.getElementById(\'flt-reset\').click()">Сбросить фильтры</button></div>\n'
    '<p id="cat-more-wrap" style="text-align:center;margin:22px 0 0" hidden>'
    '<button class="cat-morebtn" id="cat-more" type="button">Показать ещё</button></p>\n'
    '</div>\n  </div>\n</div></section>\n'
    '<script id="cat-grid-js">' + JS + '</script>'
)

CSS = (
    '<style id="zr-vitrina">'
    '.cat-morebtn{display:inline-flex;align-items:center;gap:8px;background:var(--card);border:1.5px solid var(--line);'
    "color:var(--text);font:700 14px/1 'Space Grotesk',sans-serif;padding:13px 30px;border-radius:12px;cursor:pointer;transition:.15s}"
    '.cat-morebtn:hover{border-color:var(--red);color:var(--red)}'
    '.pcard-foot .zr-more-btn{border-style:solid;border-width:1.5px}'
    '</style>'
)


def main():
    t = open(PATH, encoding="utf-8").read()
    orig = t

    # 1) placeholder + label поиска
    t = t.replace('placeholder="напр. ZR 606 или NMRV"',
                  'placeholder="напр. NORD SK 102, SEW F 107, NMRV 063"')
    # повторный запуск: обновить старый placeholder этого же скрипта
    t = re.sub(r'placeholder="напр\. NORD SK 32[^"]*"',
               'placeholder="напр. NORD SK 102, SEW F 107, NMRV 063"', t)
    t = t.replace('<h4>Поиск по модели / аналогу</h4>', '<h4>Поиск по модели</h4>')

    # 2) опции Тип
    t = re.sub(r'(<select id="flt-type"[^>]*>).*?(</select>)',
               lambda m: m.group(1) + type_opts + m.group(2), t, count=1, flags=re.S)
    # 3) опции Бренд + заголовок «Бренд / аналог» → «Бренд»
    t = t.replace('<h4>Бренд / аналог</h4>', '<h4>Бренд</h4>')
    t = re.sub(r'(<select id="flt-brand"[^>]*>).*?(</select>)',
               lambda m: m.group(1) + brand_opts + m.group(2), t, count=1, flags=re.S)
    # 4) убрать группы Мощность и Передаточное
    t = re.sub(r'<div class="flt-group"><h4>Мощность, кВт</h4>.*?</select></div>', '', t, count=1, flags=re.S)
    t = re.sub(r'<div class="flt-group"><h4>Передаточное число i</h4>.*?</select></div>', '', t, count=1, flags=re.S)

    # 5) сетка + JS (lambda-замена — в NEW_GRID есть \s, ломающий строковый repl)
    t = re.sub(r'<div class="pcard-grid" id="pcard-grid">.*?</script>', lambda m: NEW_GRID, t, count=1, flags=re.S)

    # 6) стиль
    t = re.sub(r'<style id="zr-vitrina">.*?</style>', '', t, flags=re.S)
    t = t.replace('</head>', CSS + '</head>', 1)

    if t == orig:
        print("без изменений — проверь якоря")
        return
    open(PATH, "w", encoding="utf-8").write(t)
    print("OK:", PATH)
    print("  тип-опций:", type_opts.count('<option'))
    print("  бренд-опций:", brand_opts.count('<option'))


if __name__ == "__main__":
    main()
