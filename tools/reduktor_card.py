#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Карточки товара /reduktor/ в стиле макета заказчика (одобрен 17.07.2026).

Hero каждой карточки типоразмера переделывается по одобренному образцу
(reduktor/evl-747-777.html): галерея с миниатюрами (фото + габаритный
чертёж-пример), бейдж «В наличии / под заказ», строки производитель/код/
категория, 4 бейджа-преимущества, описание, таблица характеристик,
CTA «Запросить цену» + «Подобрать аналог». Цена — «по запросу» (реальных
прайсов в репо нет; на образце 747/777 цена «от 7 080 ₽» дана заказчиком).

Идемпотентен ('already' по p2-css). Запуск: --all | reduktor/файл.html
"""
import re, html, sys, glob, os

CAT={'червячный':'Червячные редукторы','соосно-цилиндрический':'Соосно-цилиндрические редукторы',
 'коническо-цилиндрический':'Коническо-цилиндрические редукторы',
 'плоско-цилиндрический':'Плоско-цилиндрические редукторы','цилиндрический':'Цилиндрические редукторы'}

CSS='''<style id="p2-css">
.p2{display:grid;grid-template-columns:minmax(0,480px) 1fr;gap:36px;align-items:start;margin-top:10px}
.p2-gal .p2-main{border:1px solid var(--line);border-radius:16px;overflow:hidden;background:#fff;aspect-ratio:1/.9;display:flex;align-items:center;justify-content:center;padding:14px}
.p2-gal .p2-main img{max-width:100%;max-height:100%;object-fit:contain;mix-blend-mode:multiply}
.p2-thumbs{display:flex;gap:10px;margin-top:12px}
.p2-thumbs button{flex:0 0 92px;height:76px;border:1px solid var(--line);border-radius:12px;background:#fff;padding:6px;cursor:pointer;transition:.15s}
.p2-thumbs button.act{border-color:var(--red);box-shadow:0 0 0 1px var(--red)}
.p2-thumbs img{width:100%;height:100%;object-fit:contain;mix-blend-mode:multiply}
.p2-h1{font-size:clamp(24px,2.8vw,33px);line-height:1.13;margin:0 0 4px;letter-spacing:-.01em}
.p2-h1 em{font-style:normal;font-size:.55em;color:var(--muted);font-weight:600;display:block;margin-top:4px}
.p2-price-row{display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin:14px 0 4px}
.p2-price{font:800 32px/1 'Space Grotesk',sans-serif;color:var(--text)}
.p2-price small{font:600 15px/1 'Space Grotesk',sans-serif;color:var(--muted);margin-right:4px}
.p2-stock{display:inline-flex;align-items:center;gap:7px;background:#e8f7ee;color:#0f8a4d;font-weight:700;font-size:13.5px;border-radius:10px;padding:8px 13px}
.p2-stock::before{content:'✓';display:inline-flex;align-items:center;justify-content:center;width:17px;height:17px;background:#12a65c;color:#fff;border-radius:50%;font-size:11px}
.p2-rows{margin:14px 0 2px;border-top:1px solid var(--line)}
.p2-rows>div{display:flex;gap:10px;align-items:baseline;padding:9px 0;border-bottom:1px solid var(--line);font-size:14px}
.p2-rows .k{color:var(--muted);flex:0 0 175px;display:flex;gap:8px;align-items:center}
.p2-rows .k svg{width:15px;height:15px;color:var(--red);flex:none}
.p2-rows .v{color:var(--text);font-weight:600}
.p2-feats{display:grid;grid-template-columns:1fr 1fr;gap:9px;margin:15px 0 0}
.p2-feats a{display:flex;align-items:center;gap:8px;border:1.5px solid var(--red);color:var(--red);border-radius:11px;padding:10px 13px;font-size:13.5px;font-weight:600;text-decoration:none;transition:.15s}
.p2-feats a:hover{background:var(--red);color:#fff}
.p2-desc{color:var(--muted);font-size:14.5px;line-height:1.55;margin:16px 0 0;max-width:66ch}
.p2-desc b{color:var(--text)}
.p2-spec{margin:16px 0 0;border:1px solid var(--line);border-radius:14px;overflow:hidden}
.p2-spec>div{display:flex;font-size:14px}
.p2-spec>div+div{border-top:1px solid var(--line)}
.p2-spec .k{flex:0 0 46%;padding:10px 14px;background:var(--bg2);color:var(--text);font-weight:600}
.p2-spec .v{flex:1;padding:10px 14px;color:var(--text)}
.p2-cta{display:flex;gap:10px;flex-wrap:wrap;margin:18px 0 0}
.p2-cta .btn{flex:1;min-width:200px;justify-content:center;font-size:15.5px}
.p2-note-dw{margin-top:10px;font-size:12px;color:var(--muted)}
@media(max-width:860px){.p2{grid-template-columns:1fr}.p2-rows .k{flex-basis:145px}}
</style>'''

IC_F='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18M5 21V7l7-4 7 4v14M9 21v-6h6v6"/></svg>'
IC_C='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 7h10v10H7z" transform="rotate(45 12 12)"/></svg>'
IC_K='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7h18M3 12h18M3 17h10"/></svg>'

def evl_from_slug(f):
    s=os.path.basename(f)[:-5]
    m=re.match(r'evl-(.+)$',s)
    if not m: return None
    parts=[]
    for p in m.group(1).split('-'):
        mm=re.match(r'^(\d+)(x?)$',p)
        if not mm: return None
        parts.append(mm.group(1)+('Х' if mm.group(2) else ''))
    return 'EVL '+'/'.join(parts)

def transform(f):
    t=open(f,encoding='utf-8').read()
    if 'p2-css' in t: return 'already'
    h1m=re.search(r'<h1[^>]*>([^<]*)</h1>',t)
    if not h1m: return 'no-h1'
    h1=h1m.group(1)
    zrm=re.search(r'(ZR [0-9/]+[ХX]?)',h1)
    if not zrm: return 'no-zr'
    zr=zrm.group(1)
    tag=re.search(r'pc-tag">([^<]*)</span>',t)
    typ=tag.group(1).strip() if tag else 'редуктор'
    evl=evl_from_slug(f) or ''
    img=re.search(r'pc-frame[^>]*>.*?<img src="([^"]*)"',t,re.S)
    img=img.group(1) if img else '../assets/catalog/cat_worm.webp'
    chips=dict(re.findall(r'<span>(Мощность|Момент|Передаточное|Исполнений)</span><b>([^<]*)</b>',t))
    lead=re.search(r'<p class="pc-lead">(.*?)</p>',t,re.S)
    # первое предложение лида как описание
    desc=''
    if lead:
        plain=re.sub(r'<[^>]+>','',lead.group(1)).strip()
        desc=plain.split('. ')[0].rstrip('.')+'.'
    e=html.escape
    cat=CAT.get(typ, typ.capitalize()+' редукторы')
    # доп из старого h1 (например «двухступенчатый»)
    extra='двухступенчатый' if 'двухступенчат' in h1 else ''
    em='обозначение '+e(evl)+((' · '+extra) if extra else '') if evl else (extra or '')
    spec_rows=[('Тип:',typ[0].upper()+typ[1:]+' редуктор'),('Серия:','ZR')]
    if chips.get('Передаточное'): spec_rows.append(('Передаточное число:',chips['Передаточное']))
    if chips.get('Момент'): spec_rows.append(('Крутящий момент:',chips['Момент']))
    if chips.get('Мощность'): spec_rows.append(('Мощность:',chips['Мощность']))
    if chips.get('Исполнений'): spec_rows.append(('Исполнений:',chips['Исполнений']))
    spec_rows.append(('Применение:','конвейеры, приводы, промышленное оборудование'))
    spec=''.join(f'<div><span class="k">{e(k)}</span><span class="v">{e(v)}</span></div>' for k,v in spec_rows)
    HERO=(f'<section class="section" style="padding-top:20px"><div class="wrap">'
      f'<div class="p2">'
      f'<div class="p2-gal">'
      f'<div class="p2-main"><img id="p2img" src="{img}" alt="Редуктор {e(zr)}{(" ("+e(evl)+")") if evl else ""} {e(typ)} — Завод Редукторов"></div>'
      f'<div class="p2-thumbs">'
      f'<button type="button" class="act" data-src="{img}" aria-label="Фото"><img src="{img}" alt="Фото редуктора"></button>'
      f'<button type="button" data-src="../assets/drawings/zr-demo-drawing.png" aria-label="Чертёж"><img src="../assets/drawings/zr-demo-drawing.png" alt="Габаритный чертёж"></button>'
      f'</div>'
      f'<div class="p2-note-dw">Фото типового исполнения · габаритный чертёж — пример оформления, точный чертёж исполнения вышлем с КП</div>'
      f'</div>'
      f'<div class="p2-info">'
      f'<h1 class="p2-h1">Редуктор {e(zr)} {e(typ)} промышленный'+(f' <em>{em}</em>' if em else '')+'</h1>'
      f'<div class="p2-price-row"><span class="p2-price" style="font-size:24px">Цена по запросу</span><span class="p2-stock">В наличии / под заказ</span></div>'
      f'<div class="p2-rows">'
      f'<div><span class="k">{IC_F}Производитель</span><span class="v">Завод Редукторов (ООО «НИИ АТТ»)</span></div>'
      f'<div><span class="k">{IC_C}Код товара / Модель:</span><span class="v">{e(zr)}{(" ("+e(evl)+")") if evl else ""}</span></div>'
      f'<div><span class="k">{IC_K}Категория:</span><span class="v">Промышленное оборудование / {e(cat)}</span></div>'
      f'</div>'
      f'<div class="p2-feats">'
      f'<a href="/importozameshchenie">\U0001F6E1 Импортозамещение</a>'
      f'<a href="/podbor">\U0001F50D Подбор аналога</a>'
      f'<a href="/garantiya">✓ Гарантия 24 месяца</a>'
      f'<a href="/dostavka-raschet">\U0001F69A Доставка по России</a>'
      f'</div>'
      +(f'<p class="p2-desc">{e(desc)} Выполняем инженерный подбор, замену импортных аналогов, поставку и гарантийное сопровождение.</p>' if desc else '')
      +f'<div class="p2-spec">{spec}</div>'
      f'<div class="p2-cta"><a class="btn lg" data-zayavka href="#zayavka">\U0001F4C4 Запросить цену</a><a class="btn ghost lg" href="/podbor">\U0001F50D Подобрать аналог</a></div>'
      f'</div></div></div></section>'
      '<script>document.querySelectorAll(".p2-thumbs button").forEach(function(b){b.addEventListener("click",function(){document.getElementById("p2img").src=b.dataset.src;document.querySelectorAll(".p2-thumbs button").forEach(function(x){x.classList.remove("act")});b.classList.add("act");});});</script>')
    m=re.search(r'<section class="section" style="padding-top:22px">.*?</section>',t,re.S)
    if not m: return 'no-hero'
    t=t[:m.start()]+HERO+t[m.end():]
    t=t.replace('</head>',CSS+'\n</head>',1)
    open(f,'w',encoding='utf-8').write(t)
    return 'ok'

if __name__=='__main__':
    if len(sys.argv)>1 and sys.argv[1]=='--all':
        from collections import Counter
        c=Counter()
        for f in glob.glob('reduktor/*.html'):
            if f.endswith('/index.html'): continue
            try: c[transform(f)]+=1
            except Exception as ex: c['ERR:'+str(ex)[:30]]+=1
        print(dict(c))
    elif len(sys.argv)>1:
        print(transform(sys.argv[1]))
    else:
        print(__doc__)
