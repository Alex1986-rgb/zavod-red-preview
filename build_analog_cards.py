#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Пересборка хабов-аналогов /analog/<brand-frame> в КАРТОЧКИ ТОВАРА (pcard).
Извлекает контент из текущих HTML, добавляет данные EVL-модели (чипы/цена/таблица),
чинит запятые в slug. Старый comma-файл удаляется."""
import glob, os, re, json, html

SITE = os.path.dirname(os.path.abspath(__file__))
DOMAIN = 'https://zavod-red.ru'
ev = open(os.path.join(SITE, 'catalog', 'evl.html'), encoding='utf-8').read()
HEADER = re.search(r'<header>.*?</header>', ev, re.S).group(0)
FOOTER = re.search(r'<footer class="site-footer">.*?</footer>', ev, re.S).group(0)
CSSV = re.search(r'inner\.css\?v=(\d+)', ev).group(1)
CARD_CSS = open('/tmp/card_css.txt', encoding='utf-8').read()
SCRIPTS = '<script src="../assets/inner.js?v=6"></script>\n<script defer src="../assets/modal.js?v=21"></script>'
TYPE_IMG = {'червячный':'cat_worm','соосно-цилиндрический':'cat_coaxial','коническо-цилиндрический':'cat_bevel','плоско-цилиндрический':'cat_flat','цилиндрический':'cat_cylindrical'}
PRICE = {'червячный':7080,'соосно-цилиндрический':23050,'коническо-цилиндрический':26130,'плоско-цилиндрический':25680,'цилиндрический':23050}
IC = {'factory':'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M3 21h18M4 21V10l5 3V10l5 3V10l5 3v8"/></svg>',
 'shield':'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M9 12l2 2 4-4"/></svg>',
 'swap':'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M4 7h12l-3-3M20 17H8l3 3"/></svg>',
 'truck':'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M3 6h11v9H3zM14 9h4l3 3v3h-7"/><circle cx="7" cy="18" r="1.6"/><circle cx="17" cy="18" r="1.6"/></svg>'}

def esc(s): return html.escape(str(s), quote=True)
def uns(s): return html.unescape(s) if s and '&lt;' in s else (s or '')
def cf(x):
    v=round(float(x),2); s=str(int(v)) if v==int(v) else str(v); return s.replace('.',',')

D = json.load(open(os.path.join(SITE, 'assets', 'podbor-data.json'), encoding='utf-8'))
TYPES = D['t']
from collections import defaultdict
NAME2ROWS = defaultdict(list)
NAME2TYPE = {}
for it in D['i']:
    g = D['g'][str(it[0])]; NAME2ROWS[g['e']].append(it); NAME2TYPE[g['e']] = TYPES[g['t']]
def mslug(n): return n.lower().replace(' ','-').replace('/','-').replace('х','x')
SLUG2NAME = {mslug(v['e']): v['e'] for v in D['g'].values()}

def clean_slug(s):  # sew-k-57,-s-67 -> sew-k-57-s-67
    return re.sub(r'-+','-', re.sub(r'[^a-z0-9]+','-', s.lower())).strip('-')

def build(oldslug):
    f = os.path.join(SITE, 'analog', oldslug + '.html')
    h = open(f, encoding='utf-8').read()
    def g1(p, d=''):
        m = re.search(p, h, re.S); return m.group(1).strip() if m else d
    title = html.unescape(g1(r'<title>(.*?)</title>'))
    desc = html.unescape(g1(r'name="description" content="(.*?)"'))
    h1 = html.unescape(g1(r'<h1[^>]*>(.*?)</h1>'))
    lead = html.unescape(g1(r'<p class="seo-lead">(.*?)</p>')) or html.unescape(g1(r'<h1[^>]*>.*?</h1>\s*<p[^>]*>(.*?)</p>')) or html.unescape(g1(r'<p[^>]*>(.*?)</p>'))
    body = g1(r'<div class="rich"[^>]*>(.*?)</div>\s*</div>\s*</section>')
    if not body:  # fallback: контент из seo-body или все <p>/<h3> основного текста
        body = g1(r'<div class="seo-body">(.*?)</div>')
    if not body: body = ''.join('<p>'+p+'</p>' for p in re.findall(r'<p[^>]*>(.{60,}?)</p>', h)[:5])
    faq = [(html.unescape(q), html.unescape(a)) for q, a in re.findall(r'<summary>(.*?)</summary>\s*<p>(.*?)</p>', h, re.S)][:8]
    evlslug = (re.search(r'/reduktor/([a-z0-9-]+)', h) or [None, ''])[1]
    evl = SLUG2NAME.get(evlslug, '')
    term = html.unescape(g1(r'<span>›</span>\s*([^<]+?)\s*</div>')) or (title.split('—')[0].replace('Аналог','').strip())
    if not (h1 and body and evl and len(faq) >= 6): return None
    typ = NAME2TYPE.get(evl, 'соосно-цилиндрический')
    rows = NAME2ROWS.get(evl, [])
    P=[r[1] for r in rows]; U=[r[4] for r in rows]; T=[r[3] for r in rows]
    price = PRICE.get(typ, 23050); pf = f'{price:,}'.replace(',', ' ')
    img = '../assets/catalog/' + TYPE_IMG.get(typ, 'cat_coaxial') + '.webp'
    newslug = clean_slug(oldslug)
    url = f'{DOMAIN}/analog/{newslug}'
    def rng(a): return f'{min(a)}–{max(a)}' if a and min(a)!=max(a) else (str(min(a)) if a else '—')
    chips = [(IC['swap'],'Аналог',term),('','Мощность',f'{rng(P)} кВт'),('','Момент',f'{rng(T)} Н·м'),('','Передаточное',rng(U))]
    chips_html = ''.join(f'<div class="pc-chip">{ic}<div class="cv"><span>{esc(k)}</span><b>{esc(v)}</b></div></div>' for ic,k,v in chips)
    half=(len(faq)+1)//2
    col=lambda qs:'<div class="cat-faq">'+''.join(f'<details><summary>{esc(q)}</summary><p>{a}</p></details>' for q,a in qs)+'</div>'
    faq_html=f'<div class="faq-grid">{col(faq[:half])}{col(faq[half:])}</div>'
    adv=[(IC['factory'],'Производство','Челябинск, ООО «НИИ АТТ»'),(IC['shield'],'Гарантия 24 мес','паспорт на изделие'),(IC['swap'],'Замена без переделки','совпадают размеры и крепления'),(IC['truck'],'Поставка по РФ','в короткие сроки')]
    adv_html=''.join(f'<div class="av">{ic}<div><b>{t}</b><span>{d}</span></div></div>' for ic,t,d in adv)
    specrows=[('Импортный',term),('Наш аналог',evl),('Тип передачи',typ),('Мощность',f'{rng(P)} кВт'),('Передаточное',rng(U)),('Момент',f'{rng(T)} Н·м'),('Исполнений',str(len(rows)))]
    spec='<table class="spec"><tbody>'+''.join(f'<tr><th>{esc(k)}</th><td>{esc(v)}</td></tr>' for k,v in specrows)+'</tbody></table>'
    prod_ld={"@context":"https://schema.org","@type":"Product","name":f"Редуктор {term} — аналог {evl}","sku":newslug,"category":typ,"brand":{"@type":"Brand","name":"Завод Редукторов"},"manufacturer":{"@type":"Organization","areaServed":"RU","name":"ООО «НИИ АТТ»"},"description":desc,"offers":{"@type":"AggregateOffer","priceCurrency":"RUB","lowPrice":price,"availability":"https://schema.org/InStock"}}
    bc_ld={"@context":"https://schema.org","@type":"BreadcrumbList","itemListElement":[{"@type":"ListItem","position":1,"name":"Главная","item":DOMAIN+"/"},{"@type":"ListItem","position":2,"name":"Аналоги импорта","item":DOMAIN+"/analog/"},{"@type":"ListItem","position":3,"name":term,"item":url}]}
    faq_ld={"@context":"https://schema.org","@type":"FAQPage","mainEntity":[{"@type":"Question","name":q,"acceptedAnswer":{"@type":"Answer","text":re.sub('<[^>]+>','',a)}} for q,a in faq]}
    doc=f'''<!DOCTYPE html>
<html lang="ru">
<head>
<script>(function(){{try{{var t=localStorage.getItem("zr_theme")||"dark";document.documentElement.setAttribute("data-theme",t);}}catch(e){{}}}})();</script>
<meta charset="UTF-8" /><meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>{esc(title)}</title>
<meta name="description" content="{esc(desc)}" />
<link rel="canonical" href="{url}" />
<meta name="theme-color" content="#0b151d" />
<link rel="icon" type="image/png" sizes="32x32" href="../assets/favicons/fav-32x32.png" />
<meta property="og:type" content="product" /><meta property="og:url" content="{url}" />
<meta property="og:title" content="{esc(title)}" /><meta property="og:description" content="{esc(desc)}" />
<meta property="og:image" content="{DOMAIN}/og-image.jpg" />
<link rel="stylesheet" href="../assets/inner.css?v={CSSV}" />
{CARD_CSS}
<script type="application/ld+json">{json.dumps(prod_ld, ensure_ascii=False)}</script>
<script type="application/ld+json">{json.dumps(bc_ld, ensure_ascii=False)}</script>
<script type="application/ld+json">{json.dumps(faq_ld, ensure_ascii=False)}</script>
</head>
<body>
{HEADER}
<div class="wrap crumbs"><a href="/">Главная</a><span>›</span><a href="/analog/">Аналоги импорта</a><span>›</span>{esc(term)}</div>
<section class="section" style="padding-top:22px"><div class="wrap">
  <div class="pcard">
    <div class="pc-media"><div class="pc-frame"><span class="pc-tag">Аналог импорта</span><img src="{img}" alt="Аналог {esc(term)} — редуктор {esc(evl)}"></div><div class="pc-note">Наш аналог {esc(evl)} · совпадает по размерам</div></div>
    <div class="pc-info">
      <span class="pc-badge">Аналог · {esc(typ)}</span>
      <h1>{esc(h1)}</h1>
      <p class="pc-lead">{esc(lead)}</p>
      <div class="pc-chips">{chips_html}</div>
      <div class="pc-buy">
        <div class="pc-price"><span class="pp">от {pf} ₽</span><span class="ps">за наш аналог · КП в течение дня</span></div>
        <div class="pc-cta"><a class="btn lg" data-zayavka href="#zayavka">Получить цену и КП</a><a class="btn ghost lg" href="/reduktor/{mslug(evl)}">Наш аналог {esc(evl)} →</a></div>
        <div class="pc-imp">{esc(term)} = наш <a href="/reduktor/{mslug(evl)}">{esc(evl)}</a> по присоединительным размерам. <a href="/importozameshchenie">Импортозамещение</a>.</div>
        <div class="pc-trust"><span><b>Производитель</b> ООО «НИИ АТТ»</span><span><b>Гарантия</b> 24 мес.</span><span><b>Поставка</b> по РФ</span></div>
      </div>
    </div>
  </div>
  <div class="pc-adv">{adv_html}</div>
</div></section>
<section class="section" id="spec" style="padding-top:34px"><div class="wrap"><h2 class="sec-h">{esc(term)} ↔ {esc(evl)} — характеристики</h2>{spec}</div></section>
<section class="section" id="opis" style="padding-top:6px"><div class="wrap"><h2 class="sec-h">Описание</h2><div class="rich" style="max-width:880px">{uns(body)}</div></div></section>
<section class="section" id="faq" style="padding-top:0"><div class="wrap"><div class="eyebrow">Вопросы и ответы</div><h2 class="sec-h">Частые вопросы</h2>{faq_html}</div></section>
<section class="section" style="padding-top:0"><div class="wrap"><div class="seo-text"><p class="seo-lead">{esc(lead)}</p><details class="seo-more"><summary>Подбор и замена</summary><div class="seo-body"><p>Точный подбор аналога {esc(term)} — в <a href="/podbor">калькуляторе</a>. Наша модель — <a href="/reduktor/{mslug(evl)}">{esc(evl)}</a>, замена импорта — <a href="/importozameshchenie">импортозамещение</a>, все аналоги — <a href="/analog/">каталог аналогов</a>.</p></div></details></div></div></section>
{FOOTER}
{SCRIPTS}
</body></html>
'''
    open(os.path.join(SITE, 'analog', newslug + '.html'), 'w', encoding='utf-8').write(doc)
    return oldslug, newslug

def main():
    hubs = [os.path.basename(f)[:-5] for f in glob.glob(os.path.join(SITE,'analog','*.html'))
            if '-i' not in os.path.basename(f) and os.path.basename(f) != 'index.html']
    done=0; renamed=0; fail=[]
    for old in hubs:
        r = build(old)
        if not r: fail.append(old); continue
        oldslug, newslug = r; done+=1
        if oldslug != newslug:
            op=os.path.join(SITE,'analog',oldslug+'.html')
            if os.path.exists(op): os.remove(op); renamed+=1
    print(f'Пересобрано карточек-аналогов: {done} | slug исправлено: {renamed} | не удалось: {len(fail)}')
    if fail: print('  fail:', fail[:10])

if __name__ == '__main__':
    main()
