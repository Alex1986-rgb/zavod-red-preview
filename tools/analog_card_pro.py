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
import re, os, sys, html, glob, json

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
 # липкая галерея — прокручивается вместе с правой колонкой, убирает «мёртвое» пустое место
 '@media(min-width:861px){.p2-gal{position:sticky;top:78px;align-self:start}}'
 # секция «Описание»: 2 колонки (таблица характеристик + применения) — заполняют ширину
 '.opis-lead{color:var(--text);font-size:15px;line-height:1.7;margin:0 0 16px;max-width:none}'
 '.opis-lead+.opis-lead{margin-top:-4px}'
 '.opis-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:22px;align-items:start;margin:8px 0 4px}'
 '.opis-grid h3{font:700 16px/1.2 "Space Grotesk",sans-serif;margin:0 0 10px;color:var(--text)}'
 '.opis-apps{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:1fr 1fr;gap:7px}'
 '.opis-apps li{position:relative;padding-left:20px;font-size:13.5px;color:var(--text);line-height:1.4}'
 '.opis-apps li::before{content:"";position:absolute;left:0;top:6px;width:8px;height:8px;border-radius:2px;background:var(--red)}'
 '.why-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:18px 0 0}'
 '.why-grid>div{border:1px solid var(--line);border-radius:12px;padding:13px 15px;background:var(--card)}'
 '.why-grid b{display:block;font:700 14px/1.25 "Space Grotesk",sans-serif;color:var(--red);margin-bottom:3px}'
 '.why-grid span{font-size:12.5px;color:var(--muted);line-height:1.45}'
 '@media(max-width:760px){.opis-grid{grid-template-columns:1fr}.opis-apps{grid-template-columns:1fr}.why-grid{grid-template-columns:1fr 1fr}}'
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
 # SEO-таблицы (внутри .seo-body)
 '.seo-tbl{width:100%;border-collapse:collapse;margin:6px 0 20px;font-size:13.5px}'
 '.seo-tbl th,.seo-tbl td{border:1px solid var(--line);padding:9px 12px;text-align:left}'
 '.seo-tbl th{background:var(--bg2);font-weight:600}'
 '.seo-tbl caption{caption-side:top;text-align:left;font-weight:600;color:var(--muted);font-size:12.5px;padding:0 0 7px}'
 '@media(max-width:760px){.faq2{grid-template-columns:1fr}.seo-tbl{display:block;overflow-x:auto;white-space:nowrap}}'
 # компактная сводка ключевых параметров под галереей (баланс левой колонки)
 '.p2-kspec{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:16px 0 0}'
 '.p2-kspec>div{border:1px solid var(--line);border-radius:12px;padding:11px 13px;background:var(--card)}'
 '.p2-kspec span{display:block;font-size:11.5px;color:var(--muted);margin-bottom:2px}'
 '.p2-kspec b{font:700 15px/1.15 "Space Grotesk",sans-serif;color:var(--text)}'
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
    """список br-{brand}-t*.webp по бренду (2 ракурса), иначе [].
    bslug — для ссылки /brands/{bslug}; для фото при отсутствии пробуем короткий
    слаг (sew-tramec → br-tramec)."""
    bl=imp_short.lower()
    slug=next((s for k,s in BRAND_SLUG if k in bl),None)
    if not slug: return [],None
    def _glob(sl): return sorted(glob.glob(os.path.join(BASE,'assets','catalog','br-'+sl+'-t*.webp')))
    ph=_glob(slug)
    if not ph and '-' in slug:            # sew-tramec → tramec
        ph=_glob(slug.split('-')[-1])
    return ['../assets/catalog/'+os.path.basename(p) for p in ph], slug

def load_copy(f):
    """персональный копирайт-override tools/analog_copy/{slug}.json, иначе None.
    Абзацы хранят HTML как сущности (&lt;b&gt;…) — разворачиваем в реальные теги."""
    slug=os.path.basename(f)[:-5]
    p=os.path.join(BASE,'tools','analog_copy',slug+'.json')
    if not os.path.exists(p): return None
    try: d=json.load(open(p,encoding='utf-8'))
    except Exception: return None
    for k in ('opis_paragraphs','seo_deep_paragraphs','applications'):
        if isinstance(d.get(k),list):
            d[k]=[html.unescape(x) if isinstance(x,str) else x for x in d[k]]
    return d

def transform(f):
    t=open(f,encoding='utf-8').read()
    if '<!--apro-->' in t: return 'already'   # уже pro — не перечитывать pro-структуру как a2
    CO=load_copy(f) or {}
    # данные из a2-карточки
    zr=re.search(r'аналог (ZR [0-9/ХX]+)',t); zr=zr.group(1) if zr else 'ZR'
    imp_full=re.search(r'Заменяет:</span><span class="v">([^(<]+?)(?: \(|<)',t)
    imp_full=imp_full.group(1).strip() if imp_full else ''
    h1m=re.search(r'<h1[^>]*>(.*?)</h1>',t,re.S)
    h1old=re.search(r'аналог ([^<&]+)',h1m.group(1)) if h1m else None
    imp_short=(h1old.group(1).strip() if h1old else imp_full).strip()
    if not imp_short: return 'no-model'   # без модели карточку не строим
    b0=imp_short.split()[0] if imp_short.split() else imp_short   # бренд-слово (безопасно)
    typ=re.search(r'Импортозамещение · ([^<]+)</span>',t); typ=typ.group(1).strip() if typ else ''
    # спеки из старой таблицы .spec — гибко (метки разнятся: «Мощность»/«Мощность двигателя»,
    # «Момент»/«Крутящий момент»); фолбэк — из строки em h1 «X кВт · Y Н·м · i=Z».
    def spx(pat):
        m=re.search(r'<th>'+pat+r'</th><td>([^<]*)</td>',t); return m.group(1).strip() if m else ''
    pw=spx(r'Мощность(?: двигателя)?')
    ig=spx(r'Передаточное(?: число)?')
    tq=spx(r'(?:Крутящий )?[Мм]омент(?: на выходе)?')
    ispn_n=spx(r'Исполнени[ейя]+')
    # фолбэк из em h1 (a2-карточка)
    em=re.search(r'<em>([^<]*)</em>',t)
    if em:
        eparts=[x.strip() for x in em.group(1).split('·')]
        if not pw: pw=next((x for x in eparts if 'кВт' in x),'')
        if not tq: tq=next((x for x in eparts if 'Н·м' in x or 'Нм' in x),'')
        if not ig: ig=next((x.replace('i=','').replace('i ','') for x in eparts if x.startswith('i')),'')
    pr=''
    ra=spx(r'Российский аналог')
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
    rows=[('Бренд',b0),('Модель',imp_short),
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
      f'<div class="p2-note-dw">Фото {e(b0)} · ракурсы и габаритный чертёж (пример), точный чертёж исполнения — с КП</div>'
      # тех.документация под галереей (сводку параметров убрали — дублировала таблицу)
      f'{docs}</div>'
      # правая колонка
      f'<div class="p2-info">'
      f'<span style="display:inline-block;color:var(--red);font-weight:700;font-size:12px;letter-spacing:.05em;text-transform:uppercase">Импортозамещение · {e(typ)}</span>'
      f'<h1 class="p2-h1" style="margin-top:6px">{e(imp_short)} — мотор-редуктор и российский аналог {e(zr)}</h1>'
      f'<div class="p2-price-row"><span class="p2-price" style="font-size:24px">Цена по запросу</span><span class="p2-stock">В наличии / под заказ</span></div>'
      f'<div class="p2-offer">{ib}<div>Поставим оригинал <b>{e(imp_short)}</b> под заказ или подберём российский аналог <b>{e(zr)}</b> собственного производства — те же присоединительные размеры, замена без переделки узла, цена ниже и отгрузка от 3 дней.</div></div>'
      f'<div class="p2-rows">'
      f'<div><span class="k">Бренд</span><span class="v">{e(b0)} (импорт)</span></div>'
      f'<div><span class="k">Модель</span><span class="v">{e(imp_short)}</span></div>'
      f'<div><span class="k">Наш аналог</span><span class="v">{e(zr)}{(" · "+e(pr)) if pr else ""}</span></div>'
      f'<div><span class="k">Категория</span><span class="v">Промышленное оборудование / {e(cat)}</span></div></div>'
      f'<div class="p2-cta1"><a class="btn lg" data-zayavka href="#zayavka">Получить расчёт и КП</a>'
      f'<a class="btn ghost lg" data-zayavka data-req="Подбор аналога {e(imp_short)}" href="#zayavka">Подобрать аналог</a>'
      f'<a class="btn ghost lg" data-zayavka data-req="{e(imp_short)}" href="#zayavka">Запросить оригинал</a></div>'
      # блок преимуществ в правой колонке (баланс с левой)
      f'<div class="p2-trust">'
      f'<div><b>Собственное производство</b><span>механообработка, сборка, испытания</span></div>'
      f'<div><b>Гарантия 24 месяца</b><span>или переделаем за наш счёт</span></div>'
      f'<div><b>Отгрузка от 3 дней</b><span>серийное — со склада</span></div>'
      f'<div><b>Инженер за 15 минут</b><span>подбор по шильду, фото, параметрам</span></div>'
      f'</div>'
      f'</div></div></div></section>'
      '<script>document.querySelectorAll(".p2-thumbs button").forEach(function(b){b.addEventListener("click",function(){document.getElementById("p2img").src=b.dataset.src;document.querySelectorAll(".p2-thumbs button").forEach(function(x){x.classList.remove("act")});b.classList.add("act");});});'
      'document.addEventListener("click",function(ev){var el=ev.target.closest&&ev.target.closest("[data-req]");if(!el)return;var m=document.getElementById("zrMsg");if(m&&!m.value)m.value="Запрос: "+el.getAttribute("data-req")+". Прошу дать цену и срок.";});</script>')
    # --- ПОДРОБНОЕ ОПИСАНИЕ (сразу после hero, видимое, id=opis) ---
    # ссылка на бренд-раздел (для перелинковки в описании)
    _blink=('/brands/'+bslug) if bslug else '/catalog/importnye-motor-reduktory'
    # override-абзацы уже содержат HTML (b/a) — НЕ экранируем; шаблонные строим сами (с перелинковкой)
    opis_paras=CO.get('opis_paragraphs') or [
      f'<b>{e(imp_short)}</b> — {e(typ)} мотор-редуктор бренда <a href="{_blink}">{e(b0)}</a>. Российский аналог собственного производства — <a href="{red}">{e(zr)}</a>{(" (обозначение "+e(pr)+")") if pr else ""} от Завода Редукторов, ООО «НИИ АТТ». Аналог повторяет присоединительные и габаритные размеры оригинала — валы, фланцы, расположение лап и монтажное положение, поэтому встаёт на штатное место {e(imp_short)} без переделки рамы и переходных деталей.',
      f'Диапазон параметров: мощность {e(pw) or "по запросу"}, передаточное число {e(ig) or "—"}, крутящий момент {e(tq) or "—"}, {e(ispn_n) or ""} исполнений по редукции, валам и фланцам. Взаимозаменяемость подтверждает инженер по мощности, моменту, передаточному числу, консольной нагрузке и монтажному положению — по шильду, фото или параметрам за 15 минут (<a href="/podbor">подбор по параметрам</a>). Переходные фланцы и спецвалы изготавливаем по чертежам.',
      f'Замена {e(imp_short)} на {e(zr)} выгодна по цене и срокам: поставляем напрямую от производителя, без валютных рисков, с гарантией 24 месяца и полным пакетом документов. Серийные типоразмеры отгружаем от 3 дней со склада. При необходимости поставим и сам оригинал {e(b0)} под заказ. Смотрите также <a href="{_blink}">все модели {e(b0)} и аналоги</a>, <a href="/importozameshchenie">импортозамещение приводов</a> и <a href="/catalog/">каталог редукторов</a>.',
    ]
    why=CO.get('why_analog') or [
      {'t':'Замена без переделки','d':'те же присоединительные и габаритные размеры'},
      {'t':'Цена ниже импорта','d':'прямой производитель, без валютных наценок'},
      {'t':'Отгрузка от 3 дней','d':'серийные типоразмеры — со склада'},
      {'t':'Гарантия 24 месяца','d':'паспорт, декларация соответствия, УПД'}]
    OPIS=(f'<section class="section" id="opis" style="padding-top:22px"><div class="wrap"><div class="seo-text" style="border-top:none;padding-top:0">'
      f'<h2 class="sec-h" style="margin-bottom:14px">{e(imp_short)} — мотор-редуктор и российский аналог {e(zr)}</h2>'
      +''.join(f'<p class="opis-lead">{p}</p>' for p in opis_paras)
      +f'<div class="why-grid">'+''.join(f'<div><b>{e(w["t"])}</b><span>{e(w["d"])}</span></div>' for w in why)+'</div>'
      +f'</div></div></section>')
    # --- СЕКЦИЯ ХАРАКТЕРИСТИК (#spec): таблица + применения в 2 колонки + тех.документация ---
    apps=CO.get('applications') or [
      'конвейеры и транспортёры','ленточные и цепные элеваторы','мешалки и миксеры','экструдеры и грануляторы',
      'вращающиеся барабаны и сушилки','краны и грузоподъёмное оборудование','шнековые питатели','аэраторы и насосы',
      'дробилки и измельчители','упаковочные линии']
    SPEC=(f'<section class="section" id="spec" style="padding-top:8px"><div class="wrap">'
      f'<h2 class="sec-h">Характеристики {e(imp_short)} и аналога {e(zr)}</h2>'
      f'<div class="opis-grid">'
      f'<div class="p2-lspec">{lspec}</div>'
      f'<div><h3>Применение</h3><ul class="opis-apps">'+''.join(f'<li>{e(a)}</li>' for a in apps)+'</ul></div>'
      f'</div></div></section>')
    # секция «Исполнения» убрана по просьбе заказчика (список исполнений — в подборе)
    ispn_cards=''
    ISPN=''
    # FAQ 8 в 2 блока
    b=imp_short; z=zr
    FAQ=(f'<section class="section" id="faq" style="padding-top:12px"><div class="wrap"><h2 class="sec-h">Частые вопросы: {e(b)}</h2>'
      f'<div class="faq2"><div>'
      f'<details><summary>Чем заменить {e(b)}?</summary><p>Прямая замена — редуктор {e(z)} нашего производства: совпадают присоединительные и габаритные размеры, узел встаёт на штатное место без переделки. Отличается ценой и сроком поставки.</p></details>'
      f'<details><summary>Какой российский аналог у {e(b)}?</summary><p>Аналог {e(b)} — {e(z)}{(" (обозначение "+e(pr)+")") if pr else ""}, {e(typ)} редуктор. Совпадает по валам, фланцам и монтажным размерам.</p></details>'
      f'<details><summary>Аналог {e(z)} встанет без переделки?</summary><p>Да. {e(z)} повторяет присоединительные размеры {e(b)} — валы, фланцы, расположение лап и положение в пространстве. Переходные фланцы и спецвалы при необходимости изготовим по чертежам.</p></details>'
      f'<details><summary>Чем аналог отличается от оригинала {e(b0)}?</summary><p>Характеристики и размеры сопоставимы, гарантия 24 месяца. Аналог обычно дешевле импорта и отгружается быстрее — от 3 дней со склада или под заказ.</p></details>'
      f'</div><div>'
      f'<details><summary>Сколько стоит {e(b)} и аналог?</summary><p>Цена и оригинала, и аналога — по запросу: зависит от типоразмера, передаточного числа и комплектации. Пришлите модель или шильд — рассчитаем оба варианта и пришлём КП в течение дня.</p></details>'
      f'<details><summary>Какие характеристики у {e(b)}?</summary><p>{("Мощность "+e(pw)+", ") if pw else ""}{("передаточное "+e(ig)+", ") if ig else ""}{("крутящий момент "+e(tq)+", ") if tq else ""}{(e(ispn_n)+" исполнений, ") if ispn_n else ""}тип — {e(typ)}. Точные параметры исполнения — в таблице выше.</p></details>'
      f'<details><summary>Можно ли купить оригинал {e(b)}?</summary><p>Да, поставляем оригинальные редукторы {e(b0)} под заказ — с гарантией производителя. Срок зависит от модели и наличия у поставщика; сообщим при запросе.</p></details>'
      f'<details><summary>Как подобрать по шильду или модели?</summary><p>Пришлите фото шильда или обозначение — инженер определит типоразмер, передаточное число и мощность, подберёт оригинал {e(b0)} и аналог {e(z)} за 15 минут.</p></details>'
      f'</div></div></div></section>')
    # DEEP-SEO (низ, свёрнуто под стрелкой): не дублирует OPIS — про подбор исполнения, соответствие, заказ
    brand_link=('/brands/'+bslug) if bslug else '/catalog/importnye-motor-reduktory'
    tbl1_rows=''.join(f'<tr><td>{e(imp_short)} · {e(s)}</td><td>{e(z)}</td></tr>' for h,n,s in inds[:5]) or f'<tr><td>{e(imp_short)}</td><td>{e(z)}</td></tr>'
    deep=CO.get('seo_deep_paragraphs') or [
      f'Как понять, какое исполнение {e(imp_short)} стоит у вас: снимите данные с шильда (типоразмер, передаточное число, мощность двигателя) или пришлите фото таблички — по ним инженер определит точную позицию и подберёт аналог {e(z)} один в один.',
      f'Замену {e(imp_short)} на {e(z)} оформляем прямым договором с заводом-изготовителем: цена в рублях, гарантия 24 месяца, паспорт, декларация соответствия и УПД. Серийные позиции — со склада, остальное — под заказ.']
    perelink=(f'<p>Смотрите также: <a href="{brand_link}">все модели {e(b0)} и аналоги</a>, <a href="/catalog/">каталог редукторов</a>, <a href="/importozameshchenie">импортозамещение приводов</a>, <a href="/podbor">подбор по параметрам</a> и карточку <a href="{red}">{e(z)}</a>. Нужен аналог другого импортного редуктора — пришлите шильд, подберём замену за 15 минут.</p>')
    SEO=(f'<section class="section" id="seo" style="padding-top:14px"><div class="wrap"><div class="seo-text">'
      f'<h2 class="sec-h" style="margin-bottom:14px">{e(imp_short)} — подбор исполнения, соответствие и заказ</h2>'
      f'<p class="seo-lead">{deep[0]}</p>'
      f'<details class="seo-more"><summary>Соответствие исполнений и условия поставки</summary><div class="seo-body">'
      +''.join(f'<p>{p}</p>' for p in deep[1:])
      +f'<table class="seo-tbl"><caption>Соответствие исполнений {e(imp_short)} и аналога {e(z)}</caption>'
      f'<thead><tr><th>Импортное исполнение</th><th>Наш аналог</th></tr></thead><tbody>{tbl1_rows}</tbody></table>'
      +perelink
      +f'</div></details></div></div></section>')
    # финальная CTA-полоса
    BAND=(f'<section class="section" style="padding-top:0"><div class="wrap"><div class="p2-band">'
      f'<h2>Нужен {e(b)} или аналог {e(z)}? Рассчитаем и пришлём КП в течение дня</h2>'
      f'<a class="btn lg" data-zayavka href="#zayavka">Получить расчёт</a>'
      f'<p>Инженер подтвердит подбор по шильду или параметрам · гарантия 24 месяца · отгрузка по всей России</p></div></div></section>')
    NAV=(f'<div class="wrap"><nav class="apro-nav">'
      f'<a href="#opis">Описание</a><a href="#spec">Характеристики</a>'+('<a href="#ispn">Исполнения</a>' if ispn_cards else '')+
      f'<a href="#faq">Вопросы</a>'
      f'<span class="sp"></span><a class="red" data-zayavka href="#zayavka">Получить расчёт</a></nav></div>')
    body='<div class="wrap crumbs"><a href="/">Главная</a><span>›</span><a href="/analog/">Аналоги импорта</a><span>›</span>'+e(imp_short)+'</div>'+HERO+NAV+OPIS+SPEC+ISPN+FAQ+SEO+BAND
    # собрать: head(chrome) + header + новый body + footer
    head_end=t.find('</head>')
    head=t[:head_end]
    # добавить apro-css если нет
    if 'apro-css' not in head:
        head+=CSS+'\n'
    # title/description из override (SEO), если заданы
    if CO.get('meta_title'):
        head=re.sub(r'<title>.*?</title>', '<title>'+e(CO['meta_title'])+'</title>', head, count=1, flags=re.S)
    if CO.get('meta_description'):
        head=re.sub(r'(<meta name="description" content=")[^"]*(")', lambda m:m.group(1)+e(CO['meta_description'])+m.group(2), head, count=1)
    # p2-css/p2b-css уже в head (a2-карточка). p2-band CSS в p2b-css.
    header=t[head_end+len('</head>'):t.find('</header>')+len('</header>')]
    # убрать существующий breadcrumb (crumbs) из header-хвоста если он там
    footer=t[t.find('<footer'):]
    footer=re.sub(r'<!--a2-->\s*','',footer)
    out=head+'</head>'+header+body+footer+'<!--apro-->\n'
    open(f,'w',encoding='utf-8').write(out)
    return 'ok'

if __name__=='__main__':
    if len(sys.argv)>1 and sys.argv[1]=='--all':
        from collections import Counter
        import time
        c=Counter(); t0=time.time()
        for f in glob.glob(os.path.join(BASE,'analog','*.html')):
            if f.endswith('/index.html'): continue
            try: c[transform(f)]+=1
            except Exception as ex: c['ERR:'+str(ex)[:40]]+=1
        print(f'время: {time.time()-t0:.0f}с | {dict(c)}')
    elif len(sys.argv)>1:
        print(transform(sys.argv[1]))
    else:
        print('режим: --all или путь к файлу')
