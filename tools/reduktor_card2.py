#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Модернизация структуры карточек /reduktor/ (этап 2, дизайн-раскладка).

Порядок блоков продающей B2B-карточки:
hero → липкая якорная навигация → Характеристики(#spec) → Типоразмеры(#tz)
→ «Заменяет импортные приводы»(#analogi, из базы подбора) → Преимущества
завода (4 плитки) → Описание(#opis) → FAQ(#faq) → Похожие модели →
финальная CTA-полоса → SEO-текст.

Идемпотентен ('already' по pc-nav-разметке). Запуск: --all | файл
"""
import re, sys, glob, os, json, html

BASE=os.path.join(os.path.dirname(__file__),'..')
D=json.load(open(os.path.join(BASE,'assets','podbor-data.json')))
BRAND_SLUG=[('motovario','motovario'),('sew','sew'),('bonfiglioli','bonfiglioli'),('nord','nord'),
 ('bauer','bauer'),('lenze','lenze'),('stm','stm'),('siti','siti'),('tramec','sew-tramec'),
 ('varvel','varvel'),('vemper','vemper'),('watt','watt-drive'),('yilmaz','yilmaz'),
 ('rossi','rossi'),('transtecno','transtecno'),('innovari','innovari'),('innored','innored'),
 ('guomao','guomao'),('flender','flender'),('keb','keb'),('siemens','siemens')]

CSS=('<style id="p2b-css">'
 '.imp-rep{display:flex;flex-wrap:wrap;gap:9px}'
 '.imp-rep a,.imp-rep span.chip{display:inline-flex;flex-direction:column;gap:2px;padding:10px 14px;'
 'border:1px solid var(--line);border-radius:12px;background:var(--card);text-decoration:none;min-width:0}'
 '.imp-rep b{font:700 14px/1.2 "Space Grotesk",sans-serif;color:var(--text)}'
 '.imp-rep i{font-style:normal;font-size:12px;color:var(--muted);overflow-wrap:anywhere}'
 '.imp-rep a:hover{border-color:var(--red)}.imp-rep a:hover b{color:var(--red)}'
 '.p2-band{background:linear-gradient(120deg,#101f2a,#1b3141);border-radius:18px;padding:30px 30px;'
 'display:flex;flex-wrap:wrap;align-items:center;gap:18px}'
 '.p2-band h2{color:#fff;font-size:22px;margin:0;flex:1;min-width:240px}'
 '.p2-band p{color:#b9c7d2;font-size:13.5px;margin:4px 0 0;flex-basis:100%;order:3}'
 '.p2-band .btn{flex:none}'
 '@media(max-width:560px){.p2-band{padding:22px 18px}}'
 '</style>')

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

def analog_chips(evl):
    g=None
    for gid,gg in D['g'].items():
        if gg['e']==evl: g=gg; break
    if not g or not g.get('a'): return None
    chips=[]
    for b,models in g['a'].items():
        bl=b.lower()
        slug=next((s for k,s in BRAND_SLUG if k in bl),None)
        name=html.escape(b); mods=html.escape(', '.join(models)[:60])
        if slug: chips.append(f'<a href="/brands/{slug}"><b>{name}</b><i>{mods}</i></a>')
        else: chips.append(f'<span class="chip"><b>{name}</b><i>{mods}</i></span>')
    return ''.join(chips)

def transform(f):
    t=open(f,encoding='utf-8').read()
    if 'p2-css' not in t: return 'no-p2'
    done='<!--p2b-->' in t
    zrm=re.search(r'Код товара / Модель:</span><span class="v">(ZR [0-9/ХX]+)',t)
    zr=zrm.group(1) if zrm else 'ZR'
    evl=evl_from_slug(f)
    # 1) id="tz" на секцию типоразмеров
    t=re.sub(r'<section class="section"([^>]*)><div class="wrap"><h2 class="sec-h">Типоразмеры',
             r'<section class="section" id="tz"\1><div class="wrap"><h2 class="sec-h">Типоразмеры',t,count=1)
    has_tz='id="tz"' in t
    # 2) якорная навигация: пересобираем всегда (старая могла остаться от прежнего шаблона
    #    с неполными пунктами — Характеристики/Описание/Вопросы/Заказать)
    chips_html=analog_chips(evl) if evl else None
    nav_inner=('<a href="#spec">Характеристики</a>'
      +('<a href="#tz">Типоразмеры</a>' if has_tz else '')
      +('<a href="#analogi">Заменяет импорт</a>' if chips_html else '')
      +'<a href="#opis">Описание</a><a href="#faq">Вопросы</a>'
      '<span class="sp"></span>'
      '<a data-zayavka href="#zayavka" style="border:1px solid var(--red);color:var(--red);border-radius:9px;font-weight:600">Получить расчёт</a>')
    NAV='<nav class="pc-nav"><div class="wrap" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;width:100%">'+nav_inner+'</div></nav>'
    if '<nav class="pc-nav"' in t:
        t=re.sub(r'<nav class="pc-nav".*?</nav>',NAV,t,count=1,flags=re.S)
    else:
        hero=re.search(r'<section class="section" style="padding-top:20px">.*?</section>',t,re.S)
        t=t[:hero.end()]+NAV+t[hero.end():]
    if done:  # только nav-фикс на уже обработанных
        open(f,'w',encoding='utf-8').write(t)
        return 'nav-fixed'
    # 3) блок «Заменяет импортные» + преимущества — перед #opis
    if 'id="analogi"' not in t:
        block=''
        if chips_html:
            block+=(f'<section class="section" id="analogi" style="padding-top:26px"><div class="wrap">'
              f'<h2 class="sec-h">Заменяет импортные приводы</h2>'
              f'<p class="lead" style="max-width:76ch">Редуктор {html.escape(zr)} — габаритный и присоединительный аналог '
              f'импортных моделей ниже: совпадают валы, фланцы и крепёж, замена без переделки узла. '
              f'Нажмите на бренд — каталог его моделей и наших аналогов.</p>'
              f'<div class="imp-rep">{chips_html}</div>'
              +ADV+'</div></section>')
        else:
            block+=('<section class="section" id="analogi" style="padding-top:26px"><div class="wrap">'+ADV+'</div></section>')
        m=re.search(r'<section class="section"[^>]*id="opis"',t)
        t=t[:m.start()]+block+t[m.start():]
    # 4) финальная CTA-полоса перед SEO-секцией
    if 'p2-band' not in t:
        band=(f'<section class="section" style="padding-top:0"><div class="wrap">'
          f'<div class="p2-band"><h2>Нужен {html.escape(zr)}? Рассчитаем и пришлём КП в течение дня</h2>'
          f'<a class="btn lg" data-zayavka href="#zayavka">Получить расчёт</a>'
          f'<p>Инженер подтвердит подбор по шильду или параметрам · гарантия 24 месяца · отгрузка по всей России</p>'
          f'</div></div></section>')
        m=re.search(r'<section class="section" style="padding-top:0"><div class="wrap"><div class="seo-text">',t)
        if m: t=t[:m.start()]+band+t[m.start():]
        else: t=t.replace('<footer',band+'<footer',1)
    if 'p2b-css' not in t:
        t=t.replace('</head>',CSS+'\n</head>',1)
    t=t.replace('</body>','<!--p2b-->\n</body>',1)
    open(f,'w',encoding='utf-8').write(t)
    return 'ok'

if __name__=='__main__':
    if len(sys.argv)>1 and sys.argv[1]=='--all':
        from collections import Counter
        c=Counter()
        for f in glob.glob(os.path.join(BASE,'reduktor','*.html')):
            if f.endswith('/index.html'): continue
            try: c[transform(f)]+=1
            except Exception as ex: c['ERR:'+str(ex)[:40]]+=1
        print(dict(c))
    elif len(sys.argv)>1:
        print(transform(sys.argv[1]))
    else:
        print(__doc__)
