#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Страницы /analog/ → вид карточки товара (стандарт zavod-cards, эталон evl-063-747).

Заменяет hero из двух карточек «Импортный оригинал → Наш аналог ZR» на
единую карточку товара нашего ZR: галерея (фото по типу + чертёж),
цена/наличие, строки Производитель / Код ZR / Категория / Заменяет {импорт},
4 преимущества, описание, характеристики, тех.документация, CTA
«Получить расчёт» + «Запросить оригинал {импорт}» (кнопка оригинала сохранена).
Контекст импортозамещения остаётся (h1 «ZR N — аналог {импорт}», строка
«Заменяет»), но макет — как у остальных карточек сайта.

Идемпотентен (маркер a2). Порядок при регенерации: analog_twocard → analog_docs
→ analog_origbtn → analog_card. Запуск: --all | analog/файл.html
"""
import re, sys, glob, os, html

BASE=os.path.join(os.path.dirname(__file__),'..')
IMGT={'червячный':'cat_worm','соосно-цилиндрический':'cat_coaxial',
 'коническо-цилиндрический':'cat_bevel','плоско-цилиндрический':'cat_flat',
 'цилиндрический':'cat_cylindrical'}
CATN={'червячный':'Червячные','соосно-цилиндрический':'Соосно-цилиндрические',
 'коническо-цилиндрический':'Коническо-цилиндрические','плоско-цилиндрический':'Плоско-цилиндрические',
 'цилиндрический':'Цилиндрические'}

# CSS-блоки берём из эталонной карточки — единый стиль
_T=open(os.path.join(BASE,'reduktor','evl-063-747.html'),encoding='utf-8').read()
CSS_P2=re.search(r'<style id="p2-css">.*?</style>',_T,re.S).group(0)
CSS_P2B=re.search(r'<style id="p2b-css">.*?</style>',_T,re.S).group(0)
DOCS_CSS=('<style id="a2-css">.p2-docs{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:14px 0 0;font-size:12.5px}'
 '.p2-docs>span{color:var(--muted);font-weight:600}'
 '.p2-docs a{display:inline-flex;align-items:center;gap:6px;padding:7px 11px;border:1px solid var(--line);'
 'border-radius:9px;background:var(--card);color:var(--text);text-decoration:none;white-space:nowrap}'
 '.p2-docs a:hover{border-color:var(--red);color:var(--red)}'
 ".p2-docs a::before{content:'\\1F4C4';font-size:13px}"
 '.p2-repl{display:inline-flex;align-items:center;gap:7px;background:var(--bg2);border:1px solid var(--line);'
 'border-radius:10px;padding:8px 13px;font-size:13px;color:var(--text);margin:10px 0 0}'
 '.p2-repl b{color:var(--red)}</style>')

IC_F='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18M5 21V7l7-4 7 4v14M9 21v-6h6v6"/></svg>'
IC_C='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 7h10v10H7z" transform="rotate(45 12 12)"/></svg>'
IC_K='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7h18M3 12h18M3 17h10"/></svg>'
IC_R='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 12a8 8 0 1 1 2.3 5.6M4 12V7m0 5h5"/></svg>'

def transform(f):
    t=open(f,encoding='utf-8').read()
    if '<!--a2-->' in t: return 'already'
    if 'class="cmp2"' not in t: return 'no-cmp'
    e=html.escape
    typ=re.search(r'Импортозамещение · ([^<]+)</span>',t)
    typ=typ.group(1).strip() if typ else ''
    zr=re.search(r'аналог (ZR [0-9/ХX]+)',t); zr=zr.group(1) if zr else 'ZR'
    imp_full=re.search(r'cmp-import">.*?<div class="cmp-name">([^<]*)</div>',t,re.S)
    imp_full=imp_full.group(1).strip() if imp_full else ''
    h1m=re.search(r'<h1[^>]*>([^<&]*)',t); imp_short=(h1m.group(1).strip() if h1m else imp_full).split(' &rarr;')[0].split('  ')[0].strip()
    country=re.search(r'cmp-import">.*?<div class="cmp-sub">([^·<]+)·',t,re.S)
    country=country.group(1).strip() if country else 'импорт'
    specs=re.findall(r'cmp-ours">.*?<ul class="cmp-specs">(.*?)</ul>',t,re.S)
    li=re.findall(r'<li>([^<]*)</li>',specs[0]) if specs else []
    pw=next((x for x in li if 'кВт' in x),'')
    tq=next((x for x in li if 'Н·м' in x),'')
    ig=next((x for x in li if x.startswith('i')),'')
    red=re.search(r'href="(/reduktor/[^"]*)"',t); red=red.group(1) if red else '/podbor?q='+zr.replace(' ','%20')
    req=re.search(r'cmp-origbtn[^>]*data-req="([^"]*)"',t); req=req.group(1) if req else imp_full
    img='../assets/catalog/'+IMGT.get(typ,'cat_cylindrical')+'.webp'
    DW='../assets/drawings/zr-demo-drawing.png'
    cat=CATN.get(typ,typ.capitalize())+' редукторы'
    spec_rows=[('Тип:',(typ[0].upper()+typ[1:] if typ else 'Редуктор')+' редуктор'),('Серия:','ZR'),
      ('Заменяет импорт:',imp_full or imp_short)]
    if ig: spec_rows.append(('Передаточное число:',ig.replace('i=','').replace('i ','')))
    if tq: spec_rows.append(('Крутящий момент:',tq))
    if pw: spec_rows.append(('Мощность:',pw))
    spec_rows.append(('Применение:','конвейеры, приводы, промышленное оборудование'))
    spec=''.join(f'<div><span class="k">{e(k)}</span><span class="v">{e(v)}</span></div>' for k,v in spec_rows)
    chips=' · '.join([x for x in [pw,tq,ig] if x])
    HERO=(f'<section class="section" style="padding-top:20px"><div class="wrap"><div class="p2">'
      f'<div class="p2-gal"><div class="p2-main"><img id="p2img" src="{img}" alt="Редуктор {e(zr)} {e(typ)} — аналог {e(imp_short)}"></div>'
      f'<div class="p2-thumbs"><button type="button" class="act" data-src="{img}" aria-label="Фото"><img src="{img}" alt="Фото"></button>'
      f'<button type="button" data-src="{DW}" aria-label="Чертёж"><img src="{DW}" alt="Габаритный чертёж"></button></div>'
      f'<div class="p2-note-dw">Фото типового исполнения · габаритный чертёж — пример оформления, точный чертёж вышлем с КП</div></div>'
      f'<div class="p2-info">'
      f'<span style="display:inline-block;color:var(--red);font-weight:700;font-size:12px;letter-spacing:.05em;text-transform:uppercase">Импортозамещение · {e(typ)}</span>'
      f'<h1 class="p2-h1" style="margin-top:6px">Мотор-редуктор {e(zr)} — аналог {e(imp_short)}'+(f' <em>{e(chips)}</em>' if chips else '')+'</h1>'
      f'<div class="p2-price-row"><span class="p2-price" style="font-size:24px">Цена по запросу</span><span class="p2-stock">В наличии / под заказ</span></div>'
      f'<div class="p2-repl">Заменяет импортный <b>{e(imp_full or imp_short)}</b> — тот же монтаж и присоединительные размеры</div>'
      f'<div class="p2-rows"><div><span class="k">{IC_F}Производитель</span><span class="v">Завод Редукторов (ООО «НИИ АТТ»)</span></div>'
      f'<div><span class="k">{IC_C}Код товара / Модель:</span><span class="v">{e(zr)}</span></div>'
      f'<div><span class="k">{IC_K}Категория:</span><span class="v">Промышленное оборудование / {e(cat)}</span></div>'
      f'<div><span class="k">{IC_R}Заменяет:</span><span class="v">{e(imp_full or imp_short)} ({e(country)})</span></div></div>'
      f'<div class="p2-feats"><a href="/importozameshchenie">\U0001F6E1 Импортозамещение</a><a href="/podbor">\U0001F50D Подбор аналога</a>'
      f'<a href="/garantiya">✓ Гарантия 24 месяца</a><a href="/dostavka-raschet">\U0001F69A Доставка по России</a></div>'
      f'<p class="p2-desc">Редуктор <b>{e(zr)}</b> собственного производства — габаритный и присоединительный аналог импортного {e(imp_full or imp_short)}: совпадают валы, фланцы и крепёж, замена без переделки узла. Цена ниже оригинала, отгрузка от 3 дней, гарантия 24 месяца. Можем поставить и сам оригинал под заказ.</p>'
      f'<div class="p2-spec">{spec}</div>'
      f'<div class="p2-cta"><a class="btn lg" data-zayavka href="#zayavka">\U0001F4C4 Получить расчёт и КП</a>'
      f'<a class="btn ghost lg" data-zayavka data-req="{e(req)}" href="#zayavka">Запросить оригинал {e(imp_short)}</a></div>'
      f'<div class="p2-docs"><span>Техническая документация:</span>'
      f'<a href="/markirovka-zr">Расшифровка маркировки ZR</a>'
      f'<a href="/glossary/montazhnoe-polozhenie">Монтажные положения</a>'
      f'<a href="#spec">Характеристики</a>'
      f'<a href="{red}">Карточка {e(zr)}</a></div>'
      f'</div></div></div></section>'
      '<script>document.querySelectorAll(".p2-thumbs button").forEach(function(b){b.addEventListener("click",function(){document.getElementById("p2img").src=b.dataset.src;document.querySelectorAll(".p2-thumbs button").forEach(function(x){x.classList.remove("act")});b.classList.add("act");});});'
      'document.addEventListener("click",function(ev){var el=ev.target.closest&&ev.target.closest("[data-req]");if(!el)return;var m=document.getElementById("zrMsg");if(m&&!m.value)m.value="Нужен ОРИГИНАЛ: "+el.getAttribute("data-req")+". Прошу дать цену и срок поставки.";});</script>')
    # заменить hero (первая section) целиком
    m=re.search(r'<section class="section" style="padding-top:20px">.*?</section>',t,re.S)
    if not m: return 'no-hero'
    t=t[:m.start()]+HERO+t[m.end():]
    # снять устаревшие cmp-css/cmp-docs/origreq (hero их больше не использует; оставшийся CSS безвреден, но чистим docs-блок ниже)
    t=re.sub(r'<div class="cmp-docs">.*?</div>','',t,flags=re.S)
    if 'a2-css' not in t:
        t=t.replace('</head>',CSS_P2+'\n'+CSS_P2B+'\n'+DOCS_CSS+'\n</head>',1)
    t=t.replace('</body>','<!--a2-->\n</body>',1)
    open(f,'w',encoding='utf-8').write(t)
    return 'ok'

if __name__=='__main__':
    if len(sys.argv)>1 and sys.argv[1]=='--all':
        from collections import Counter
        c=Counter()
        for f in glob.glob(os.path.join(BASE,'analog','*.html')):
            if f.endswith('/index.html'): continue
            try: c[transform(f)]+=1
            except Exception as ex: c['ERR:'+str(ex)[:40]]+=1
        print(dict(c))
    elif len(sys.argv)>1:
        print(transform(sys.argv[1]))
    else:
        print(__doc__)
