#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Флагманская карточка /analog/ (этап pro): полный SEO-контент + дизайн.

По ТЗ заказчика (эталон — siti-bh-140):
- галерея: главное фото бренда + 4 миниатюры (2 ракурса бренда + категория + чертёж);
- на странице бренда — фото и название бренда, оффер «подберём аналог ZR»;
- левая колонка: под фото — таблица характеристик, ниже неё — тех.документация
  (заполняем пустое место рядом с длинной инфо-колонкой);
- все CTA-кнопки в один ряд;
- секция «Исполнения» с корректным гридом;
- FAQ 8 вопросов в 2 блока по 4;
- SEO-текст: 5 абзацев + 2 таблицы + перелинковка; корректно на моб/десктопе.

Пока запускается точечно (аргумент = файл) для доводки эталонной страницы,
затем обобщается на все /analog/. Данные берёт из текущей a2-карточки.
"""
import re, os, sys, html, glob

BASE=os.path.join(os.path.dirname(__file__),'..')
IMGT={'червячный':'cat_worm','соосно-цилиндрический':'cat_coaxial',
 'коническо-цилиндрический':'cat_bevel','плоско-цилиндрический':'cat_flat','цилиндрический':'cat_cylindrical'}
CATN={'червячный':'Червячные','соосно-цилиндрический':'Соосно-цилиндрические',
 'коническо-цилиндрический':'Коническо-цилиндрические','плоско-цилиндрический':'Плоско-цилиндрические','цилиндрический':'Цилиндрические'}
BRAND_SLUG=[('siti','siti'),('sew','sew'),('nord','nord'),('bonfiglioli','bonfiglioli'),('motovario','motovario'),
 ('bauer','bauer'),('lenze','lenze'),('varvel','varvel'),('stm','stm'),('rossi','rossi'),('watt','watt-drive'),
 ('yilmaz','yilmaz'),('transtecno','transtecno'),('innovari','innovari'),('vemper','vemper'),('innored','innored'),
 ('tramec','sew-tramec'),('varmec','varmec')]

CSS=('<style id="apro-css">'
 # заполнение левой колонки: таблица + докум под фото
 '.p2-lspec{margin:16px 0 0;border:1px solid var(--line);border-radius:14px;overflow:hidden}'
 '.p2-lspec>div{display:flex;font-size:13.5px}.p2-lspec>div+div{border-top:1px solid var(--line)}'
 '.p2-lspec .k{flex:0 0 52%;padding:9px 13px;background:var(--bg2);color:var(--text);font-weight:600}'
 '.p2-lspec .v{flex:1;padding:9px 13px;color:var(--text)}'
 # оффер-плашка
 '.p2-offer{display:flex;gap:10px;align-items:flex-start;background:linear-gradient(120deg,rgba(207,22,22,.07),rgba(207,22,22,.02));'
 'border:1px solid color-mix(in srgb,var(--red) 30%,var(--line));border-radius:13px;padding:13px 15px;margin:12px 0 0;font-size:13.5px}'
 '.p2-offer b{color:var(--red)}.p2-offer svg{width:20px;height:20px;color:var(--red);flex:none;margin-top:1px}'
 # кнопки в один ряд
 '.p2-cta1{display:flex;gap:9px;flex-wrap:wrap;margin:16px 0 0}'
 '.p2-cta1 .btn{flex:1 1 0;min-width:150px;justify-content:center;font-size:14px;padding:12px 12px;white-space:nowrap}'
 # тех.документация
 '.p2-docs{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:16px 0 0;font-size:12.5px}'
 '.p2-docs>span{color:var(--muted);font-weight:600;flex-basis:100%}'
 '.p2-docs a{display:inline-flex;align-items:center;gap:6px;padding:8px 12px;border:1px solid var(--line);border-radius:9px;'
 'background:var(--card);color:var(--text);text-decoration:none;white-space:nowrap}'
 '.p2-docs a:hover{border-color:var(--red);color:var(--red)}.p2-docs a::before{content:"\\1F4C4";font-size:13px}'
 # исполнения
 '.ispn{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:10px}'
 '.ispn a{display:flex;flex-direction:column;gap:3px;padding:12px 14px;border:1px solid var(--line);border-radius:12px;'
 'background:var(--card);text-decoration:none;transition:.15s}'
 '.ispn a:hover{border-color:var(--red);transform:translateY(-2px)}'
 '.ispn a b{font:600 14px/1.2 "Space Grotesk",sans-serif;color:var(--text)}.ispn a span{font-size:12px;color:var(--muted)}'
 # FAQ 2 блока по 4
 '.faq2{display:grid;grid-template-columns:1fr 1fr;gap:12px}'
 '.faq2 details{border:1px solid var(--line);border-radius:12px;background:var(--card);padding:0 15px}'
 '.faq2 details+details{margin-top:0}'
 '.faq2 summary{cursor:pointer;font-weight:600;padding:14px 0;font-size:14.5px;list-style:none;position:relative;padding-right:24px}'
 '.faq2 summary::-webkit-details-marker{display:none}'
 '.faq2 summary::after{content:"+";position:absolute;right:0;top:12px;color:var(--red);font-size:19px;font-weight:400}'
 '.faq2 details[open] summary::after{content:"\\2013"}'
 '.faq2 details[open] summary{border-bottom:1px solid var(--line)}'
 '.faq2 details p{margin:0;padding:12px 0 15px;color:var(--muted);font-size:13.5px;line-height:1.55}'
 # SEO
 '.seo-pro h2{font-size:22px;margin:0 0 14px}.seo-pro p{color:var(--text);font-size:14.5px;line-height:1.65;margin:0 0 14px;max-width:none}'
 '.seo-pro a{color:var(--red)}'
 '.seo-tbl{width:100%;border-collapse:collapse;margin:6px 0 20px;font-size:13.5px}'
 '.seo-tbl th,.seo-tbl td{border:1px solid var(--line);padding:9px 12px;text-align:left}'
 '.seo-tbl th{background:var(--bg2);font-weight:600}'
 '.seo-tbl caption{caption-side:top;text-align:left;font-weight:600;color:var(--muted);font-size:12.5px;padding:0 0 7px}'
 '@media(max-width:760px){.faq2{grid-template-columns:1fr}.seo-tbl{display:block;overflow-x:auto;white-space:nowrap}}'
 # блок преимуществ (баланс правой колонки)
 '.p2-trust{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:18px 0 0}'
 '.p2-trust>div{border:1px solid var(--line);border-radius:12px;padding:12px 14px;background:var(--card)}'
 '.p2-trust b{display:block;font:700 14px/1.25 "Space Grotesk",sans-serif;color:var(--text)}'
 '.p2-trust span{font-size:12px;color:var(--muted);line-height:1.4}'
 # липкая якорная навигация
 '.apro-nav{position:sticky;top:64px;z-index:5;display:flex;gap:6px;flex-wrap:wrap;align-items:center;'
 'margin:0;padding:11px 0;background:var(--bg);border-bottom:1px solid var(--line)}'
 '.apro-nav a{font-size:13.5px;color:var(--muted);text-decoration:none;padding:7px 12px;border-radius:9px;border:1px solid transparent}'
 '.apro-nav a:hover{color:var(--text);border-color:var(--line)}.apro-nav .sp{flex:1}'
 '.apro-nav a.red{border:1px solid var(--red);color:var(--red);font-weight:600}'
 # мобайл: кнопки в столбец на всю ширину (в один ряд 3 кнопки не влезают)
 '@media(max-width:560px){.p2-cta1{flex-direction:column}.p2-cta1 .btn{flex:1 1 auto;width:100%;min-width:0}'
 '.p2-trust{grid-template-columns:1fr}.apro-nav{overflow-x:auto;flex-wrap:nowrap}}'
 '</style>')

def brand_photos(imp_short):
    """список br-{brand}-t*.webp по бренду (2 ракурса), иначе []"""
    bl=imp_short.lower()
    slug=next((s for k,s in BRAND_SLUG if k in bl),None)
    if not slug: return [],None
    ph=sorted(glob.glob(os.path.join(BASE,'assets','catalog','br-'+slug+'-t*.webp')))
    return ['../assets/catalog/'+os.path.basename(p) for p in ph], slug

def transform(f):
    t=open(f,encoding='utf-8').read()
    # данные из a2-карточки
    zr=re.search(r'аналог (ZR [0-9/ХX]+)',t); zr=zr.group(1) if zr else 'ZR'
    imp_full=re.search(r'Заменяет:</span><span class="v">([^(<]+?)(?: \(|<)',t)
    imp_full=imp_full.group(1).strip() if imp_full else ''
    h1old=re.search(r'аналог ([^<&]+)',re.search(r'<h1[^>]*>(.*?)</h1>',t,re.S).group(1))
    imp_short=(h1old.group(1).strip() if h1old else imp_full)
    typ=re.search(r'Импортозамещение · ([^<]+)</span>',t); typ=typ.group(1).strip() if typ else ''
    # спеки из старой таблицы .spec (если есть) или из hero
    def sp(k):
        m=re.search(r'<th>'+k+r'</th><td>([^<]*)</td>',t); return m.group(1).strip() if m else ''
    pw=sp('Мощность'); ig=sp('Передаточное число'); tq=sp('Крутящий момент'); ispn_n=sp('Исполнений')
    pr=''
    ra=sp('Российский аналог')
    m=re.search(r'ПР ?\d+',ra); pr=m.group(0) if m else ''
    red=re.search(r'href="(/reduktor/[^"]*)"',t); red=red.group(1) if red else '/podbor'
    # исполнения-ссылки
    inds=re.findall(r'href="(/analog/'+re.escape(os.path.basename(f)[:-5])+r'-[^"]*)"[^>]*><b>([^<]*)</b><span[^>]*>([^<]*)</span>',t)
    if not inds:
        inds=re.findall(r'class="ind-card" href="([^"]*)"><b>([^<]*)</b><span[^>]*>([^<]*)</span>',t)
    e=html.escape
    bphotos,bslug=brand_photos(imp_short)
    catimg='../assets/catalog/'+IMGT.get(typ,'cat_cylindrical')+'.webp'
    DW='../assets/drawings/zr-demo-drawing.png'
    main=bphotos[0] if bphotos else catimg
    thumbs=list(bphotos)+[catimg,DW]
    thumbs=thumbs[:4]
    while len(thumbs)<4: thumbs.append(catimg)
    def _thumb(i,th):
        cls=' class="act"' if i==0 else ''
        return '<button type="button"'+cls+' data-src="'+th+'" aria-label="Фото '+str(i+1)+'"><img src="'+th+'" alt="'+e(imp_short)+' фото '+str(i+1)+'"></button>'
    thumbs_html=''.join(_thumb(i,th) for i,th in enumerate(thumbs))
    cat=CATN.get(typ,typ.capitalize())+' редукторы'
    # таблица характеристик (левая колонка)
    rows=[('Бренд',imp_short.split()[0] if imp_short else ''),('Модель',imp_short),
      ('Наш аналог',zr+((' · '+pr) if pr else '')),('Тип передачи',typ)]
    if pw: rows.append(('Мощность',pw))
    if ig: rows.append(('Передаточное число',ig))
    if tq: rows.append(('Крутящий момент',tq))
    if ispn_n: rows.append(('Исполнений',ispn_n))
    rows.append(('Цена','по запросу · КП в день'))
    lspec=''.join(f'<div><span class="k">{e(k)}</span><span class="v">{e(v)}</span></div>' for k,v in rows if v)
    docs=(f'<div class="p2-docs"><span>Техническая документация:</span>'
      f'<a href="/markirovka-zr">Расшифровка маркировки ZR</a>'
      f'<a href="/glossary/montazhnoe-polozhenie">Монтажные положения</a>'
      f'<a href="#spec">Характеристики</a>'
      f'<a href="{red}">Карточка {e(zr)}</a></div>')
    ib='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2l7 4v6c0 5-3 8-7 10-4-2-7-5-7-10V6z"/><path d="M9 12l2 2 4-4"/></svg>'
    HERO=(f'<section class="section" style="padding-top:20px"><div class="wrap"><div class="p2">'
      f'<div class="p2-gal"><div class="p2-main"><img id="p2img" src="{main}" alt="{e(imp_short)} — {e(typ)} мотор-редуктор"></div>'
      f'<div class="p2-thumbs">{thumbs_html}</div>'
      f'<div class="p2-note-dw">Фото {e(imp_short.split()[0] if imp_short else "")} · ракурсы и габаритный чертёж (пример), точный чертёж исполнения — с КП</div>'
      # таблица в левой колонке (заполняет пустое место)
      f'<div class="p2-lspec">{lspec}</div>'
      f'{docs}</div>'
      # правая колонка
      f'<div class="p2-info">'
      f'<span style="display:inline-block;color:var(--red);font-weight:700;font-size:12px;letter-spacing:.05em;text-transform:uppercase">Импортозамещение · {e(typ)}</span>'
      f'<h1 class="p2-h1" style="margin-top:6px">{e(imp_short)} — мотор-редуктор и российский аналог {e(zr)}</h1>'
      f'<div class="p2-price-row"><span class="p2-price" style="font-size:24px">Цена по запросу</span><span class="p2-stock">В наличии / под заказ</span></div>'
      f'<div class="p2-offer">{ib}<div>Поставим оригинал <b>{e(imp_short)}</b> под заказ или подберём российский аналог <b>{e(zr)}</b> собственного производства — те же присоединительные размеры, замена без переделки узла, цена ниже и отгрузка от 3 дней.</div></div>'
      f'<div class="p2-rows">'
      f'<div><span class="k">Бренд</span><span class="v">{e(imp_short.split()[0] if imp_short else "")} (импорт)</span></div>'
      f'<div><span class="k">Модель</span><span class="v">{e(imp_short)}</span></div>'
      f'<div><span class="k">Наш аналог</span><span class="v">{e(zr)}{(" · "+e(pr)) if pr else ""}</span></div>'
      f'<div><span class="k">Категория</span><span class="v">Промышленное оборудование / {e(cat)}</span></div></div>'
      f'<div class="p2-cta1"><a class="btn lg" data-zayavka href="#zayavka">Получить расчёт и КП</a>'
      f'<a class="btn ghost lg" data-zayavka data-req="Подбор аналога {e(imp_short)}" href="#zayavka">Подобрать аналог</a>'
      f'<a class="btn ghost lg" data-zayavka data-req="{e(imp_short)}" href="#zayavka">Запросить оригинал</a></div>'
      f'<p class="p2-desc" style="margin-top:16px">На странице — характеристики {e(imp_short)}, таблица исполнений и наш аналог {e(zr)}. Пришлите шильд или модель — инженер подтвердит замену и рассчитает цену за 15 минут.</p>'
      # блок преимуществ в правой колонке (баланс с длинной таблицей слева)
      f'<div class="p2-trust">'
      f'<div><b>Собственное производство</b><span>механообработка, сборка, испытания</span></div>'
      f'<div><b>Гарантия 24 месяца</b><span>или переделаем за наш счёт</span></div>'
      f'<div><b>Отгрузка от 3 дней</b><span>серийное — со склада</span></div>'
      f'<div><b>Инженер за 15 минут</b><span>подбор по шильду, фото, параметрам</span></div>'
      f'</div>'
      f'</div></div></div></section>'
      '<script>document.querySelectorAll(".p2-thumbs button").forEach(function(b){b.addEventListener("click",function(){document.getElementById("p2img").src=b.dataset.src;document.querySelectorAll(".p2-thumbs button").forEach(function(x){x.classList.remove("act")});b.classList.add("act");});});'
      'document.addEventListener("click",function(ev){var el=ev.target.closest&&ev.target.closest("[data-req]");if(!el)return;var m=document.getElementById("zrMsg");if(m&&!m.value)m.value="Запрос: "+el.getAttribute("data-req")+". Прошу дать цену и срок.";});</script>')
    # секция характеристик (#spec) — дублирует таблицу крупно
    specrows=lspec
    SPEC=(f'<section class="section" id="spec" style="padding-top:8px"><div class="wrap">'
      f'<h2 class="sec-h">Характеристики {e(imp_short)} и аналога {e(zr)}</h2>'
      f'<div class="p2-lspec" style="max-width:620px">{specrows}</div></div></section>')
    # исполнения
    ispn_cards=''.join(f'<a href="{e(h)}"><b>{e(zr)} · {e(n)}</b><span>{e(s)}</span></a>' for h,n,s in inds[:12])
    ISPN=(f'<section class="section" id="ispn" style="padding-top:20px"><div class="wrap">'
      f'<h2 class="sec-h">Исполнения {e(imp_short)} — {e(ispn_n or "")} типоразмеров</h2>'
      f'<p class="lead" style="max-width:76ch">Каждое исполнение {e(imp_short)} по мощности и передаточному числу имеет свой аналог {e(zr)}. Откройте нужное или подберите в калькуляторе.</p>'
      f'<div class="ispn">{ispn_cards}</div>'
      f'<p style="margin-top:14px"><a class="btn ghost" href="/podbor?q={e(zr).replace(" ","%20")}">Все исполнения в подборе →</a></p></div></section>' if ispn_cards else '')
    # FAQ 8 в 2 блока
    b=imp_short; z=zr
    FAQ=(f'<section class="section" id="faq" style="padding-top:12px"><div class="wrap"><h2 class="sec-h">Частые вопросы: {e(b)}</h2>'
      f'<div class="faq2"><div>'
      f'<details><summary>Чем заменить {e(b)}?</summary><p>Прямая замена — редуктор {e(z)} нашего производства: совпадают присоединительные и габаритные размеры, узел встаёт на штатное место без переделки. Отличается ценой и сроком поставки.</p></details>'
      f'<details><summary>Какой российский аналог у {e(b)}?</summary><p>Аналог {e(b)} — {e(z)}{(" (обозначение "+e(pr)+")") if pr else ""}, {e(typ)} редуктор. Совпадает по валам, фланцам и монтажным размерам.</p></details>'
      f'<details><summary>Аналог {e(z)} встанет без переделки?</summary><p>Да. {e(z)} повторяет присоединительные размеры {e(b)} — валы, фланцы, расположение лап и положение в пространстве. Переходные фланцы и спецвалы при необходимости изготовим по чертежам.</p></details>'
      f'<details><summary>Чем аналог отличается от оригинала {e(b.split()[0])}?</summary><p>Характеристики и размеры сопоставимы, гарантия 24 месяца. Аналог обычно дешевле импорта и отгружается быстрее — от 3 дней со склада или под заказ.</p></details>'
      f'</div><div>'
      f'<details><summary>Сколько стоит {e(b)} и аналог?</summary><p>Цена и оригинала, и аналога — по запросу: зависит от типоразмера, передаточного числа и комплектации. Пришлите модель или шильд — рассчитаем оба варианта и пришлём КП в течение дня.</p></details>'
      f'<details><summary>Какие характеристики у {e(b)}?</summary><p>{("Мощность "+e(pw)+", ") if pw else ""}{("передаточное "+e(ig)+", ") if ig else ""}{("крутящий момент "+e(tq)+", ") if tq else ""}{(e(ispn_n)+" исполнений, ") if ispn_n else ""}тип — {e(typ)}. Точные параметры исполнения — в таблице выше.</p></details>'
      f'<details><summary>Можно ли купить оригинал {e(b)}?</summary><p>Да, поставляем оригинальные редукторы {e(b.split()[0])} под заказ — с гарантией производителя. Срок зависит от модели и наличия у поставщика; сообщим при запросе.</p></details>'
      f'<details><summary>Как подобрать по шильду или модели?</summary><p>Пришлите фото шильда или обозначение — инженер определит типоразмер, передаточное число и мощность, подберёт оригинал {e(b.split()[0])} и аналог {e(z)} за 15 минут.</p></details>'
      f'</div></div></div></section>')
    # SEO: 5 абзацев + 2 таблицы + перелинковка
    bsl=bslug or 'importnye-motor-reduktory'
    brand_link=('/brands/'+bslug) if bslug else '/catalog/importnye-motor-reduktory'
    tbl1_rows=''.join(f'<tr><td>{e(imp_short)} · {e(s)}</td><td>{e(z)}</td></tr>' for h,n,s in inds[:5]) or f'<tr><td>{e(imp_short)}</td><td>{e(z)}</td></tr>'
    SEO=(f'<section class="section seo-pro" id="seo" style="padding-top:14px"><div class="wrap">'
      f'<h2 class="sec-h">{e(b)} — аналог {e(z)}: подбор, замена и поставка</h2>'
      f'<p>Редуктор <b>{e(b)}</b> ({e(typ)}) применяется в конвейерах, приводных механизмах и промышленном оборудовании. Российский аналог — <a href="{red}">{e(z)}</a> производства Завода Редукторов (ООО «НИИ АТТ», Челябинск): совпадают присоединительные и габаритные размеры, поэтому {e(z)} встаёт на место {e(b)} без переделки рамы и переходных деталей. Изготавливаем как редуктор под отдельный двигатель, так и готовый мотор-редуктор 220/380 В.</p>'
      f'<p>{("Диапазон мощности "+e(pw)+", ") if pw else ""}{("передаточное число "+e(ig)+", ") if ig else ""}{("крутящий момент "+e(tq)+". ") if tq else ". "}Подбор аналога {e(b)} ведём по мощности, крутящему моменту, передаточному числу и присоединительным размерам — по шильду, фото или параметрам. Инженер подтверждает совместимость и рассчитывает замену за 15 минут.</p>'
      f'<table class="seo-tbl"><caption>Соответствие исполнений {e(b)} и аналога {e(z)}</caption>'
      f'<thead><tr><th>Импортное исполнение</th><th>Наш аналог</th></tr></thead><tbody>{tbl1_rows}</tbody></table>'
      f'<p>Замена импортного привода {e(b)} на {e(z)} не требует переделки оборудования: совпадают валы, фланцы, расположение лап и монтажное положение. При необходимости изготавливаем переходные фланцы, спецвалы и нестандартные исполнения по чертежам заказчика. Все изделия поставляются с паспортом, декларацией соответствия и гарантией 24 месяца.</p>'
      f'<table class="seo-tbl"><caption>Параметры и условия поставки</caption>'
      f'<thead><tr><th>Параметр</th><th>Значение</th></tr></thead><tbody>'
      f'<tr><td>Тип передачи</td><td>{e(typ)}</td></tr>'
      f'{("<tr><td>Мощность двигателя</td><td>"+e(pw)+"</td></tr>") if pw else ""}'
      f'{("<tr><td>Передаточное число</td><td>"+e(ig)+"</td></tr>") if ig else ""}'
      f'{("<tr><td>Крутящий момент</td><td>"+e(tq)+"</td></tr>") if tq else ""}'
      f'<tr><td>Гарантия</td><td>24 месяца</td></tr><tr><td>Отгрузка</td><td>от 3 дней · по всей России</td></tr>'
      f'<tr><td>Документы</td><td>паспорт, декларация соответствия, УПД</td></tr></tbody></table>'
      f'<p>Отгрузка серийных типоразмеров — в короткие сроки напрямую от производителя. Для производителей оборудования делаем серийные поставки с резервом на складе и фиксированной ценой по договору — приводная часть вашей серии всегда в наличии. Оригинал {e(b.split()[0])} поставляем под заказ с гарантией производителя.</p>'
      f'<p>Смотрите также: <a href="{brand_link}">все модели {e(b.split()[0])} и аналоги</a>, <a href="/catalog/">каталог редукторов</a>, <a href="/importozameshchenie">импортозамещение приводов</a>, <a href="/podbor">подбор по параметрам</a> и карточку модели <a href="{red}">{e(z)}</a>. Нужен аналог другого импортного редуктора — пришлите шильд, подберём замену за 15 минут.</p>'
      f'</div></section>')
    # финальная CTA-полоса
    BAND=(f'<section class="section" style="padding-top:0"><div class="wrap"><div class="p2-band">'
      f'<h2>Нужен {e(b)} или аналог {e(z)}? Рассчитаем и пришлём КП в течение дня</h2>'
      f'<a class="btn lg" data-zayavka href="#zayavka">Получить расчёт</a>'
      f'<p>Инженер подтвердит подбор по шильду или параметрам · гарантия 24 месяца · отгрузка по всей России</p></div></div></section>')
    NAV=(f'<div class="wrap"><nav class="apro-nav">'
      f'<a href="#spec">Характеристики</a>'+('<a href="#ispn">Исполнения</a>' if ispn_cards else '')+
      f'<a href="#faq">Вопросы</a><a href="#seo">Описание</a>'
      f'<span class="sp"></span><a class="red" data-zayavka href="#zayavka">Получить расчёт</a></nav></div>')
    body='<div class="wrap crumbs"><a href="/">Главная</a><span>›</span><a href="/analog/">Аналоги импорта</a><span>›</span>'+e(imp_short)+'</div>'+HERO+NAV+SPEC+ISPN+FAQ+SEO+BAND
    # собрать: head(chrome) + header + новый body + footer
    head_end=t.find('</head>')
    head=t[:head_end]
    # добавить apro-css если нет
    if 'apro-css' not in head:
        head+=CSS+'\n'
    # p2-css/p2b-css уже в head (a2-карточка). p2-band CSS в p2b-css.
    header=t[head_end+len('</head>'):t.find('</header>')+len('</header>')]
    # убрать существующий breadcrumb (crumbs) из header-хвоста если он там
    footer=t[t.find('<footer'):]
    footer=re.sub(r'<!--a2-->\s*','',footer)
    out=head+'</head>'+header+body+footer+'<!--apro-->\n'
    open(f,'w',encoding='utf-8').write(out)
    return 'ok'

if __name__=='__main__':
    if len(sys.argv)>1 and sys.argv[1]!='--all':
        print(transform(sys.argv[1]))
    else:
        print('точечный режим: передай путь к файлу')
