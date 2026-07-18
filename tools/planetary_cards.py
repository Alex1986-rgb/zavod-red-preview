#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Генерация карточек планетарных редукторов /reduktor/ по стандарту zavod-cards.

12 серий (3МП-31,5…125, МР1/2/3, МПО1М/2М) получают карточки того же типа,
что reduktor/evl-063-747 (эталон): галерея, цена/наличие, характеристики,
тех.документация, преимущества, CTA-полоса. Данные — из плиток
catalog/planetarnye.html (реальные диапазоны). Импортных аналогов у этих
российских серий нет → блок «Заменяет импорт» не добавляется.

После генерации перелинковывает планетарные плитки: /podbor?q=… → /reduktor/{slug}.

Запуск: python3 tools/planetary_cards.py
"""
import re, os, html

BASE=os.path.join(os.path.dirname(__file__),'..')
TPL=open(os.path.join(BASE,'reduktor','evl-063-747.html'),encoding='utf-8').read()

# верстка-хром из эталона (идентична на всех карточках)
HEADER=TPL[TPL.find('</head>')+len('</head>'):TPL.find('</header>')+len('</header>')]
FOOTER=TPL[TPL.find('<footer'):]
def style_block(sid):
    m=re.search(r'<style id="'+sid+r'">.*?</style>',TPL,re.S)
    return m.group(0) if m else ''
CSS_P2=style_block('p2-css'); CSS_P2B=style_block('p2b-css')
ICON=re.search(r'<link rel="icon"[^>]*>',TPL).group(0)
INNER=re.search(r'<link rel="stylesheet" href="\.\./assets/inner\.css\?v=\d+"[^>]*>',TPL).group(0)

IMG='../assets/catalog/cat_cylindrical.webp'
DW='../assets/drawings/zr-demo-drawing.png'

# данные: slug, марка, ступени, мощность, момент, i, доп-строка
P=[
 ('3mp-31-5','3МП-31,5','двухступенчатый','0,18–5,5 кВт','185–230 Н·м','31,5–100',''),
 ('3mp-40','3МП-40','двухступенчатый','0,18–7,5 кВт','330–375 Н·м','40–127',''),
 ('3mp-50','3МП-50','двухступенчатый','0,25–15 кВт','459–750 Н·м','50–160',''),
 ('3mp-63','3МП-63','двухступенчатый','0,37–37 кВт','762–1594 Н·м','63–200',''),
 ('3mp-80','3МП-80','двухступенчатый','0,55–45 кВт','1450–2285 Н·м','80–250',''),
 ('3mp-100','3МП-100','двухступенчатый','1,1–75 кВт','2338–4155 Н·м','100–315',''),
 ('3mp-125','3МП-125','двухступенчатый','2,2–132 кВт','4382–9180 Н·м','125–400',''),
 ('mr1','МР1','одноступенчатый','до 132 кВт','до 7100 Н·м','4,6–24,6',''),
 ('mr2','МР2','двухступенчатый','до 132 кВт','до 30 600 Н·м','13,9–28,1',''),
 ('mr3','МР3','трёхступенчатый','до 132 кВт','до 30 600 Н·м','18,6–37,5',''),
 ('mpo1m','МПО1М','одноступенчатый','','до 2082 Н·м','0,63–64','КПД до 95%'),
 ('mpo2m','МПО2М','двухступенчатый','','до 2082 Н·м','0,63–64','КПД до 95%'),
]

IC_F='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18M5 21V7l7-4 7 4v14M9 21v-6h6v6"/></svg>'
IC_C='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 7h10v10H7z" transform="rotate(45 12 12)"/></svg>'
IC_K='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7h18M3 12h18M3 17h10"/></svg>'
ADV=('<div class="pc-adv">'
 '<div class="av"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M3 21h18M5 21V7l7-4 7 4v14M9 21v-6h6v6"/></svg>'
 '<div><b>Собственное производство</b><span>механообработка, сборка и испытания на своей площадке</span></div></div>'
 '<div class="av"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M12 22s8-3 8-10V5l-8-3-8 3v7c0 7 8 10 8 10z"/></svg>'
 '<div><b>Гарантия 24 месяца</b><span>или переделаем за наш счёт</span></div></div>'
 '<div class="av"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M5 12h14M13 6l6 6-6 6"/></svg>'
 '<div><b>Отгрузка от 3 дней</b><span>серийные типоразмеры — со склада</span></div></div>'
 '<div class="av"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg>'
 '<div><b>Инженерная поддержка</b><span>подбор по шильду, фото или параметрам</span></div></div>'
 '</div>')
DOCS=('<div class="p2-docs"><span>Техническая документация:</span>'
 '<a href="/markirovka-zr">Расшифровка маркировки ZR</a>'
 '<a href="/glossary/montazhnoe-polozhenie">Монтажные положения</a>'
 '<a href="#spec">Характеристики</a></div>')
DOCS_CSS=('.p2-docs{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:14px 0 0;font-size:12.5px}'
 '.p2-docs>span{color:var(--muted);font-weight:600}'
 '.p2-docs a{display:inline-flex;align-items:center;gap:6px;padding:7px 11px;border:1px solid var(--line);'
 'border-radius:9px;background:var(--card);color:var(--text);text-decoration:none;white-space:nowrap}'
 '.p2-docs a:hover{border-color:var(--red);color:var(--red)}'
 ".p2-docs a::before{content:'\\1F4C4';font-size:13px}")

def build(slug,mark,stages,pw,tq,ig,extra):
    e=html.escape
    url='https://zavod-red.ru/reduktor/'+slug
    title=f'Мотор-редуктор планетарный {mark} — {stages}, момент {tq}'
    desc=f'Планетарный мотор-редуктор {mark} ({stages}): {("мощность "+pw+", ") if pw else ""}крутящий момент {tq}, передаточное {ig}. Производство, подбор, гарантия 24 мес.'
    spec_rows=[('Тип:','Планетарный мотор-редуктор'),('Серия:',mark.split("-")[0] if "-" in mark else mark),('Число ступеней:',stages)]
    if pw: spec_rows.append(('Мощность двигателя:',pw))
    spec_rows.append(('Крутящий момент:',tq))
    spec_rows.append(('Передаточное число:',ig))
    if extra: spec_rows.append(('Особенности:',extra))
    spec_rows.append(('Применение:','краны, конвейеры, экструдеры, тяжёлые приводы'))
    spec=''.join(f'<div><span class="k">{e(k)}</span><span class="v">{e(v)}</span></div>' for k,v in spec_rows)
    specs_chip=' · '.join([x for x in [pw,tq,'i '+ig] if x])
    ld_product='{"@context":"https://schema.org","@type":"Product","name":"Планетарный мотор-редуктор '+e(mark)+'","brand":{"@type":"Brand","name":"Завод Редукторов"},"category":"Планетарные редукторы","offers":{"@type":"Offer","priceCurrency":"RUB","availability":"https://schema.org/InStock","url":"'+url+'","seller":{"@type":"Organization","name":"ООО НИИ АТТ"}}}'
    ld_bc='{"@context":"https://schema.org","@type":"BreadcrumbList","itemListElement":[{"@type":"ListItem","position":1,"name":"Главная","item":"https://zavod-red.ru/"},{"@type":"ListItem","position":2,"name":"Каталог","item":"https://zavod-red.ru/catalog/"},{"@type":"ListItem","position":3,"name":"Планетарные","item":"https://zavod-red.ru/catalog/planetarnye"},{"@type":"ListItem","position":4,"name":"'+e(mark)+'"}]}'
    head=('<!DOCTYPE html>\n<html lang="ru">\n<head>\n'
      '<script>(function(){try{var t=localStorage.getItem("zr_theme")||"light";document.documentElement.setAttribute("data-theme",t);}catch(e){}})();</script>\n'
      '<meta charset="UTF-8" />\n<meta name="viewport" content="width=device-width, initial-scale=1.0" />\n'
      f'<title>{e(title)} | ЗР</title>\n<meta name="description" content="{e(desc)}" />\n'
      f'<link rel="canonical" href="{url}" />\n<meta name="theme-color" content="#0b151d" />\n{ICON}\n'
      f'<meta property="og:type" content="product" /><meta property="og:url" content="{url}" />\n'
      f'<meta property="og:title" content="{e(title)} | ЗР" /><meta property="og:description" content="{e(desc)}" />\n'
      '<meta property="og:image" content="https://zavod-red.ru/og-image.jpg" />\n'
      f'{INNER}\n{CSS_P2}\n{CSS_P2B[:-len("</style>")]}{DOCS_CSS}</style>\n'
      f'<script type="application/ld+json">{ld_product}</script>\n'
      f'<script type="application/ld+json">{ld_bc}</script>\n</head>')
    crumbs=(f'<div class="wrap crumbs"><a href="/">Главная</a><span>›</span>'
      f'<a href="/catalog/">Каталог</a><span>›</span>'
      f'<a href="/catalog/planetarnye">Планетарные</a><span>›</span>{e(mark)}</div>')
    hero=(f'<section class="section" style="padding-top:20px"><div class="wrap"><div class="p2">'
      f'<div class="p2-gal"><div class="p2-main"><img id="p2img" src="{IMG}" alt="Планетарный мотор-редуктор {e(mark)} — Завод Редукторов"></div>'
      f'<div class="p2-thumbs"><button type="button" class="act" data-src="{IMG}" aria-label="Фото"><img src="{IMG}" alt="Фото"></button>'
      f'<button type="button" data-src="{DW}" aria-label="Чертёж"><img src="{DW}" alt="Габаритный чертёж"></button></div>'
      f'<div class="p2-note-dw">Фото типового исполнения · габаритный чертёж — пример оформления, точный чертёж исполнения вышлем с КП</div></div>'
      f'<div class="p2-info"><h1 class="p2-h1">Мотор-редуктор планетарный {e(mark)} <em>{e(stages)}</em></h1>'
      f'<div class="p2-price-row"><span class="p2-price" style="font-size:24px">Цена по запросу</span><span class="p2-stock">В наличии / под заказ</span></div>'
      f'<div class="p2-rows"><div><span class="k">{IC_F}Производитель</span><span class="v">Завод Редукторов (ООО «НИИ АТТ»)</span></div>'
      f'<div><span class="k">{IC_C}Код товара / Модель:</span><span class="v">{e(mark)}</span></div>'
      f'<div><span class="k">{IC_K}Категория:</span><span class="v">Промышленное оборудование / Планетарные редукторы</span></div></div>'
      f'<div class="p2-feats"><a href="/importozameshchenie">\U0001F6E1 Импортозамещение</a><a href="/podbor">\U0001F50D Подбор аналога</a>'
      f'<a href="/garantiya">✓ Гарантия 24 месяца</a><a href="/dostavka-raschet">\U0001F69A Доставка по России</a></div>'
      f'<p class="p2-desc">Планетарный мотор-редуктор <b>{e(mark)}</b> ({e(stages)}) — компактный привод с высоким крутящим моментом {e(tq)} и большим передаточным числом при малых габаритах. Применяется в кранах, конвейерах, экструдерах и тяжёлых приводах. Выполняем инженерный подбор, поставку и гарантийное сопровождение.</p>'
      f'<div class="p2-spec">{spec}</div>'
      f'<div class="p2-cta"><a class="btn lg" data-zayavka href="#zayavka">\U0001F4C4 Запросить цену</a><a class="btn ghost lg" href="/podbor">\U0001F50D Подобрать по параметрам</a></div>'
      f'{DOCS}</div></div></div></section>'
      '<script>document.querySelectorAll(".p2-thumbs button").forEach(function(b){b.addEventListener("click",function(){document.getElementById("p2img").src=b.dataset.src;document.querySelectorAll(".p2-thumbs button").forEach(function(x){x.classList.remove("act")});b.classList.add("act");});});</script>')
    nav=('<nav class="pc-nav"><div class="wrap" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;width:100%">'
      '<a href="#spec">Характеристики</a><a href="#opis">Описание</a><a href="#faq">Вопросы</a>'
      '<span class="sp"></span><a data-zayavka href="#zayavka" style="border:1px solid var(--red);color:var(--red);border-radius:9px;font-weight:600;padding:7px 13px;text-decoration:none">Получить расчёт</a></div></nav>')
    specsec=(f'<section class="section" id="spec" style="padding-top:34px"><div class="wrap"><h2 class="sec-h">Характеристики {e(mark)}</h2>'
      f'<div class="p2-spec">{spec}</div></div></section>')
    advsec=f'<section class="section" style="padding-top:20px"><div class="wrap">{ADV}</div></section>'
    opis=(f'<section class="section" id="opis" style="padding-top:20px"><div class="wrap"><h2 class="sec-h">Описание</h2>'
      f'<div class="seo-text"><p>Планетарный мотор-редуктор <b>{e(mark)}</b> — {e(stages)} привод соосной компоновки с распределением нагрузки по нескольким сателлитам, что даёт высокую нагрузочную способность и КПД при компактных размерах. Крутящий момент {e(tq)}, передаточное число {e(ig)}{(", мощность "+e(pw)) if pw else ""}. Поставляется как редуктор под отдельный двигатель или как готовый мотор-редуктор.</p>'
      f'<p>Применяется в приводах кранов и грузоподъёмного оборудования, конвейерах, мешалках, экструдерах, барабанах и других механизмах с высокими моментами. Подбор по нагрузке, режиму работы и присоединительным размерам выполняет инженер — пришлите шильд или параметры.</p></div></div></section>')
    faq=(f'<section class="section" id="faq" style="padding-top:8px"><div class="wrap"><h2 class="sec-h">Частые вопросы по {e(mark)}</h2>'
      f'<div class="seo-text"><details><summary>Какой момент и передаточное у {e(mark)}?</summary><p>Крутящий момент {e(tq)}, передаточное число {e(ig)}{(", мощность двигателя "+e(pw)) if pw else ""}. Точное исполнение под нагрузку подберёт инженер.</p></details>'
      f'<details><summary>Изготавливаете под заказ или есть на складе?</summary><p>Серийные типоразмеры отгружаем от 3 дней, остальное — под заказ. Гарантия 24 месяца, поставка по всей России.</p></details></div></div></section>')
    band=(f'<section class="section" style="padding-top:0"><div class="wrap"><div class="p2-band">'
      f'<h2>Нужен {e(mark)}? Рассчитаем и пришлём КП в течение дня</h2>'
      f'<a class="btn lg" data-zayavka href="#zayavka">Получить расчёт</a>'
      f'<p>Инженер подтвердит подбор по шильду или параметрам · гарантия 24 месяца · отгрузка по всей России</p></div></div></section>')
    body=crumbs+hero+nav+specsec+advsec+opis+faq+band
    return head+HEADER+body+FOOTER+'<!--p2b-->\n'

def main():
    made=[]
    for row in P:
        slug=row[0]
        f=os.path.join(BASE,'reduktor',slug+'.html')
        open(f,'w',encoding='utf-8').write(build(*row))
        made.append((slug,row[1]))
    # перелинковка планетарных плиток
    from urllib.parse import quote
    slugmap={r[1]:r[0] for r in P}
    changed=0
    for fn in ['catalog/planetarnye.html']:
        p=os.path.join(BASE,fn); t=open(p,encoding='utf-8').read(); o=t
        for mark,slug in slugmap.items():
            t=t.replace(f'pcard-title" href="/podbor?q={quote(mark)}"',f'pcard-title" href="/reduktor/{slug}"')
            t=t.replace(f'pcard-title" href="/podbor?q={mark}"',f'pcard-title" href="/reduktor/{slug}"')
        if t!=o: open(p,'w',encoding='utf-8').write(t); changed+=1
    print('карточек создано:',len(made),'| перелинковано файлов:',changed)
    for s,m in made: print('  ',m,'→ /reduktor/'+s)

if __name__=='__main__':
    main()
