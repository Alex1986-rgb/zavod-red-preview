#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Грид карточек моделей бренда на страницах /brands/ (уточнение заказчика).

«Переходим на страницу импорта — там импортные карточки исходя из названия
фирмы и наши предложения-аналоги». На каждую бренд-страницу вставляется
секция с плитками моделей этого бренда из assets/import-catalog.json
(та же карточка, что в общем каталоге импорта: оригинал + наш аналог ZR,
параметры, ссылка на страницу пары «оригинал → аналог»).

Идемпотентен ('already'). Бренды без данных в каталоге пропускаются.
Запуск: python3 tools/brand_models_grid.py --all | brands/имя.html
"""
import re, sys, glob, os

# слаг страницы -> значение поля b в import-catalog.json
BMAP={'bauer':'Bauer','bonfiglioli':'Bonfiglioli','innored':'Innored','innovari':'Innovari',
 'lenze':'Lenze','motovario':'Motovario','nord':'NORD','rossi':'Rossi','sew':'SEW-Eurodrive',
 # слаг совпадает с брендом: 'tramec' был склейкой двух разных фирм, убран
 'tramec':'Tramec','siti':'SITI','stm':'STM','transtecno':'Transtecno','varmec':'Varmec',
 'varvel':'Varvel','vemper':'Vemper','watt-drive':'Watt Drive','yilmaz':'Yilmaz'}

CSS_FILE=os.path.join(os.path.dirname(__file__),'..','catalog','importnye-motor-reduktory.html')

def pcard_css():
    t=open(CSS_FILE,encoding='utf-8').read()
    m=re.search(r'<style[^>]*>[^<]*\.pcard\{.*?</style>',t,re.S)
    return m.group(0)

def block(brand_b, brand_title):
    sec=('<section class="section" style="padding-top:14px" id="bmodels"><div class="wrap">'
      f'<div class="eyebrow">Каталог {brand_title}</div>'
      f'<h2 class="sec-h">Модели {brand_title} и наши аналоги ZR</h2>'
      '<p class="lead" style="max-width:76ch">Каждая позиция — импортный оригинал (поставим под заказ) '
      'и наш аналог ZR собственного производства: одинаковые присоединительные размеры, замена без '
      'переделки, цена ниже, отгрузка от 3 дней. Откройте карточку — там оригинал и аналог рядом.</p>'
      '<div class="pcard-grid" id="bmGrid"></div>'
      '<p id="bmMoreWrap" style="text-align:center;margin:18px 0 0" hidden>'
      '<button class="btn ghost" id="bmMore" type="button">Показать ещё</button></p>'
      '</div></section>')
    js=('<script id="bm-js">(function(){'
      'var GR=document.getElementById("bmGrid");if(!GR)return;'
      'var TYPES=["червячный","соосно-цилиндрический","коническо-цилиндрический","плоско-цилиндрический","цилиндрический"];'
      'function esc(s){return String(s==null?"":s).replace(/[&<>"]/g,function(c){return {"&":"&amp;","<":"&lt;",">":"&gt;","\\"":"&quot;"}[c]})}'
      'function card(c){var name=c.b+" "+c.m,q=encodeURIComponent(name);'
      'var url=c.u?("/analog/"+c.u):("/podbor?q="+q);var chips=[];'
      'if(c.pw)chips.push(c.pw+" кВт");if(c.i)chips.push("i "+c.i);'
      'return "<article class=\\"pcard\\">"'
      '+"<div class=\\"pcard-media\\"><img src=\\"/assets/catalog/"+esc(c.im)+".webp\\" alt=\\""+esc(name)+" — импортный мотор-редуктор\\" loading=\\"lazy\\">"'
      '+"<span class=\\"pcard-badge imp\\">"+esc(name)+"</span><span class=\\"pcard-stock\\">оригинал / аналог</span></div>"'
      '+"<div class=\\"pcard-body\\"><span class=\\"pcard-type\\">"+esc(TYPES[c.t]||"")+(c.c?" · "+esc(c.c):"")+"</span>"'
      '+"<a class=\\"pcard-title\\" href=\\""+url+"\\">"+esc(name)+" — аналог "+esc(c.z||"ZR")+"</a>"'
      '+"<div class=\\"pcard-chips\\">"+chips.map(function(x){return "<span>"+esc(x)+"</span>"}).join("")+"</div>"'
      '+"<div class=\\"pcard-foot\\"><span class=\\"pcard-price\\">Цена: <b>по запросу</b></span>"'
      '+"<a class=\\"zr-more-btn\\" href=\\""+url+"\\">Оригинал и аналог →</a></div></div></article>"}'
      'fetch("/assets/import-catalog.json?v=30").then(function(r){return r.json()}).then(function(d){'
      f'var all=d.cards.filter(function(c){{return c.b==="{brand_b}"}});'
      'var shown=0,STEP=12;'
      'function more(){var next=all.slice(shown,shown+STEP);GR.insertAdjacentHTML("beforeend",next.map(card).join(""));shown+=next.length;'
      'var w=document.getElementById("bmMoreWrap");if(w)w.hidden=shown>=all.length}'
      'more();var b=document.getElementById("bmMore");if(b)b.addEventListener("click",more);'
      '}).catch(function(){GR.innerHTML="<p class=lead>Каталог временно недоступен — воспользуйтесь калькулятором ниже.</p>"});'
      '})();</script>')
    return sec+js

def transform(f):
    slug=os.path.basename(f)[:-5]
    if slug not in BMAP: return 'no-data'
    t=open(f,encoding='utf-8').read()
    if 'id="bmodels"' in t: return 'already'
    # заголовок бренда для текстов — из h1 (обычно «Мотор-редукторы {Brand} — …»)
    h1=re.search(r'<h1[^>]*>([^<]*)</h1>',t)
    m=re.search(r'Мотор-редукторы ([^—<]+)',h1.group(1)) if h1 else None
    title=(m.group(1).strip() if m else BMAP[slug])
    # вставить после первой секции (hero)
    first=re.search(r'<section[^>]*>.*?</section>',t[t.find('<body'):],re.S)
    ins=t.find('<body')+first.end()
    t=t[:ins]+block(BMAP[slug],title)+t[ins:]
    if '.pcard{' not in t:
        t=t.replace('</head>',pcard_css()+'\n</head>',1)
    open(f,'w',encoding='utf-8').write(t)
    return 'ok'

if __name__=='__main__':
    if len(sys.argv)>1 and sys.argv[1]=='--all':
        from collections import Counter
        c=Counter()
        for f in glob.glob('brands/*.html'):
            if f.endswith('/index.html'): continue
            try: c[transform(f)]+=1
            except Exception as e: c['ERR:'+str(e)[:30]]+=1
        print(dict(c))
    elif len(sys.argv)>1:
        print(transform(sys.argv[1]))
    else:
        print(__doc__)
