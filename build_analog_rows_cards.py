#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Пересборка per-row аналогов /analog/<brand-frame-iX-Ykvt> в ПОЛНЫЕ КАРТОЧКИ ТОВАРА (pcard).
Единичные точные значения (мощность/момент/i/обороты) в чипах и spec, свой FAQ,
описание с точными параметрами. Чинит запятые в slug (старый comma-файл удаляется)."""
import glob, os, re, json, html

SITE = os.path.dirname(os.path.abspath(__file__))
DOMAIN = 'https://zavod-red.ru'
ev = open(os.path.join(SITE, 'catalog', 'evl.html'), encoding='utf-8').read()
HEADER = re.search(r'<header>.*?</header>', ev, re.S).group(0)
FOOTER = re.search(r'<footer class="site-footer">.*?</footer>', ev, re.S).group(0)
CSSV = re.search(r'inner\.css\?v=(\d+)', ev).group(1)
CARD_CSS = open('/tmp/card_css.txt', encoding='utf-8').read()
SCRIPTS = '<script src="../assets/inner.js?v=6"></script>\n<script defer src="../assets/modal.js?v=21"></script>'
IC = {'factory':'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M3 21h18M4 21V10l5 3V10l5 3V10l5 3v8"/></svg>',
 'shield':'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M9 12l2 2 4-4"/></svg>',
 'swap':'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M4 7h12l-3-3M20 17H8l3 3"/></svg>',
 'truck':'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M3 6h11v9H3zM14 9h4l3 3v3h-7"/><circle cx="7" cy="18" r="1.6"/><circle cx="17" cy="18" r="1.6"/></svg>',
 'gauge':'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M12 14l4-4M5 18a9 9 0 1 1 14 0"/></svg>'}

def esc(s): return html.escape(str(s), quote=True)
def uns(s): return html.unescape(s)

def clean_slug(s):  # sew-k-57,-s-67-i6_57 -> sew-k-57-s-67-i6_57  (запятые/лишнее -> дефис, но _ сохраняем)
    return re.sub(r'-+', '-', re.sub(r'[^a-z0-9_]+', '-', s.lower())).strip('-')

def build(oldslug):
    f = os.path.join(SITE, 'analog', oldslug + '.html')
    h = open(f, encoding='utf-8').read()
    def g1(p, d=''):
        m = re.search(p, h, re.S); return m.group(1).strip() if m else d
    title = uns(g1(r'<title>(.*?)</title>'))
    desc  = uns(g1(r'name="description" content="(.*?)"'))
    h1    = uns(g1(r'<h1[^>]*>(.*?)</h1>'))
    lead  = uns(g1(r'<p\b[^>]*>(.*?)</p>'))   # первый <p> (не <path> в SVG шапки!)
    img   = g1(r'<img src="(\.\./assets/catalog/[^"]+)"') or '../assets/catalog/cat_coaxial.webp'
    price = g1(r'"lowPrice":\s*(\d+)') or '23050'
    price = int(price); pf = f'{price:,}'.replace(',', ' ')
    # spec-таблица th->td
    spec = dict((uns(k).strip(), uns(v).strip()) for k, v in re.findall(r'<tr><th>(.*?)</th><td>(.*?)</td></tr>', h, re.S))
    imp  = spec.get('Импортный', '')                     # SEW EURODRIVE F 37
    evl  = spec.get('Наш аналог', '')                    # EVL 163
    typ  = spec.get('Тип передачи', 'соосно-цилиндрический')
    powr = spec.get('Мощность двигателя') or spec.get('Мощность', '')
    ratio= spec.get('Передаточное число') or spec.get('Передаточное', '')
    torq = spec.get('Крутящий момент') or spec.get('Момент', '')
    rpm  = spec.get('Обороты на выходе') or spec.get('Обороты выхода', '')
    cons = spec.get('Консольная нагрузка', '')
    sf   = spec.get('Сервис-фактор', '')
    # короткое имя SEW (для h1/чипов сохраняем запятую в тексте) и slug хаба
    term = re.sub(r'\bEURODRIVE\s*', '', imp).strip() or (title.split('—')[0].split(str(powr))[0].strip())
    faq  = [(uns(q), uns(a)) for q, a in re.findall(r'<summary>(.*?)</summary>\s*<p>(.*?)</p>', h, re.S)]
    hub_old = (re.search(r'href="/analog/([a-z0-9,._-]+?)"[^>]*>[^<]*(?:Все исполнения|SEW)', h) or [None, ''])[1]
    if not hub_old:
        m = re.search(r'crumbs.*?<a href="/analog/([a-z0-9,._-]+?)">', h, re.S)
        hub_old = m.group(1) if m else ''
    hub = clean_slug(hub_old) if hub_old else ''
    evlslug = (re.search(r'/reduktor/([a-z0-9-]+)', h) or [None, ''])[1]
    if not (h1 and evl and imp and len(faq) >= 4): return None
    newslug = clean_slug(oldslug)
    url = f'{DOMAIN}/analog/{newslug}'
    # чипы — единичные значения
    chips = [(IC['swap'], 'Аналог', term)]
    if powr: chips.append(('', 'Мощность', powr))
    if torq: chips.append(('', 'Момент', torq))
    if ratio: chips.append((IC['gauge'], 'Передаточное', ratio))
    chips_html = ''.join(f'<div class="pc-chip">{ic}<div class="cv"><span>{esc(k)}</span><b>{esc(v)}</b></div></div>' for ic, k, v in chips)
    # преимущества
    adv = [(IC['factory'],'Производство','Челябинск, ООО «НИИ АТТ»'),(IC['shield'],'Гарантия 24 мес','паспорт на изделие'),(IC['swap'],'Замена без переделки','совпадают размеры и крепления'),(IC['truck'],'Поставка по РФ','в короткие сроки')]
    adv_html = ''.join(f'<div class="av">{ic}<div><b>{t}</b><span>{d}</span></div></div>' for ic, t, d in adv)
    # spec — полный
    specrows = [('Импортный', imp), ('Наш аналог', evl), ('Тип передачи', typ)]
    for k, v in [('Мощность двигателя', powr), ('Передаточное число', ratio), ('Крутящий момент', torq), ('Обороты на выходе', rpm), ('Консольная нагрузка', cons), ('Сервис-фактор', sf)]:
        if v: specrows.append((k, v))
    spec_html = '<table class="spec"><tbody>' + ''.join(f'<tr><th>{esc(k)}</th><td>{esc(v)}</td></tr>' for k, v in specrows) + '</tbody></table>'
    # описание — точные параметры (уникализирует страницу)
    hub_link = f'<a href="/analog/{hub}">все исполнения {esc(term)}</a>' if hub else 'калькулятор подбора'
    body = (f'<h3>Аналог {esc(term)} — редуктор {esc(evl)}</h3>'
        f'<p>Редуктор <b>{esc(imp)}</b> в исполнении {esc(powr)}'
        + (f', передаточное {esc(ratio)}' if ratio else '')
        + (f', крутящий момент {esc(torq)}' if torq else '')
        + (f', обороты на выходе {esc(rpm)}' if rpm else '')
        + f' — это привод типа «{esc(typ)}». Российский аналог <a href="/reduktor/{evlslug}">{esc(evl)}</a> повторяет присоединительные и габаритные размеры, диаметр и тип вала, фланец и крепления оригинала, поэтому встаёт на место {esc(term)} <b>без переделки</b> рамы и сопряжённого оборудования.</p>'
        f'<h3>Характеристики этого исполнения</h3>'
        f'<ul>'
        + (f'<li>мощность двигателя — <b>{esc(powr)}</b>;</li>' if powr else '')
        + (f'<li>передаточное число — <b>{esc(ratio)}</b>;</li>' if ratio else '')
        + (f'<li>крутящий момент на выходе — <b>{esc(torq)}</b>;</li>' if torq else '')
        + (f'<li>обороты выходного вала — <b>{esc(rpm)}</b>;</li>' if rpm else '')
        + (f'<li>консольная нагрузка — <b>{esc(cons)}</b>;</li>' if cons else '')
        + (f'<li>сервис-фактор — <b>{esc(sf)}</b>.</li>' if sf else '')
        + '</ul>'
        f'<h3>Чем отличается от оригинала</h3>'
        f'<p>По геометрии, кинематике и рабочим характеристикам {esc(evl)} эквивалентен {esc(term)}. Отличие — в названии, цене и доступности: это <a href="/importozameshchenie">импортозамещение</a> без зависимости от импортных поставок, курса и логистики. Другие мощности и передаточные того же типоразмера — {hub_link}, точный подбор по параметрам — <a href="/podbor">калькулятор</a>.</p>')
    # FAQ
    half = (len(faq) + 1) // 2
    col = lambda qs: '<div class="cat-faq">' + ''.join(f'<details><summary>{esc(q)}</summary><p>{a}</p></details>' for q, a in qs) + '</div>'
    faq_html = f'<div class="faq-grid">{col(faq[:half])}{col(faq[half:])}</div>'
    # crumbs + хаб-ссылка
    crumbs = '<div class="wrap crumbs"><a href="/">Главная</a><span>›</span><a href="/analog/">Аналоги импорта</a>'
    if hub: crumbs += f'<span>›</span><a href="/analog/{hub}">{esc(term)}</a>'
    crumbs += f'<span>›</span>{esc(powr)}' + (f', i={esc(ratio)}' if ratio else '') + '</div>'
    hub_btn = f'<a class="btn ghost lg" href="/analog/{hub}">Все исполнения {esc(term)} →</a>' if hub else f'<a class="btn ghost lg" href="/reduktor/{evlslug}">Наш аналог {esc(evl)} →</a>'
    # schema
    prod_ld = {"@context":"https://schema.org","@type":"Product","name":f"Редуктор {term} — аналог {evl}","sku":newslug,"category":typ,"brand":{"@type":"Brand","name":"Завод Редукторов (аналог SEW EURODRIVE)"},"manufacturer":{"@type":"Organization","areaServed":"RU","name":"ООО «НИИ АТТ»"},"description":desc,"offers":{"@type":"AggregateOffer","priceCurrency":"RUB","lowPrice":price,"availability":"https://schema.org/InStock","seller":{"@type":"Organization","name":"Завод Редукторов"}}}
    bc_items = [{"@type":"ListItem","position":1,"name":"Главная","item":DOMAIN+"/"},{"@type":"ListItem","position":2,"name":"Аналоги импорта","item":DOMAIN+"/analog/"}]
    if hub: bc_items.append({"@type":"ListItem","position":3,"name":term,"item":f"{DOMAIN}/analog/{hub}"})
    bc_items.append({"@type":"ListItem","position":len(bc_items)+1,"name":powr,"item":url})
    bc_ld = {"@context":"https://schema.org","@type":"BreadcrumbList","itemListElement":bc_items}
    faq_ld = {"@context":"https://schema.org","@type":"FAQPage","mainEntity":[{"@type":"Question","name":q,"acceptedAnswer":{"@type":"Answer","text":re.sub('<[^>]+>','',a)}} for q, a in faq]}
    doc = f'''<!DOCTYPE html>
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
{crumbs}
<section class="section" style="padding-top:22px"><div class="wrap">
  <div class="pcard">
    <div class="pc-media"><div class="pc-frame"><span class="pc-tag">Аналог импорта</span><img src="{img}" alt="Аналог {esc(term)} {esc(powr)} — редуктор {esc(evl)}"></div><div class="pc-note">Наш аналог {esc(evl)} · совпадает по размерам</div></div>
    <div class="pc-info">
      <span class="pc-badge">Аналог · {esc(typ)}</span>
      <h1>{esc(h1)}</h1>
      <p class="pc-lead">{lead}</p>
      <div class="pc-chips">{chips_html}</div>
      <div class="pc-buy">
        <div class="pc-price"><span class="pp">от {pf} ₽</span><span class="ps">за наш аналог · КП в течение дня</span></div>
        <div class="pc-cta"><a class="btn lg" data-zayavka href="#zayavka">Получить цену и КП</a>{hub_btn}</div>
        <div class="pc-imp">{esc(term)} = наш <a href="/reduktor/{evlslug}">{esc(evl)}</a> по присоединительным размерам. <a href="/importozameshchenie">Импортозамещение</a>.</div>
        <div class="pc-trust"><span><b>Производитель</b> ООО «НИИ АТТ»</span><span><b>Гарантия</b> 24 мес.</span><span><b>Поставка</b> по РФ</span></div>
      </div>
    </div>
  </div>
  <div class="pc-adv">{adv_html}</div>
</div></section>
<section class="section" id="spec" style="padding-top:34px"><div class="wrap"><h2 class="sec-h">{esc(term)} ↔ {esc(evl)} — характеристики</h2>{spec_html}</div></section>
<section class="section" id="opis" style="padding-top:6px"><div class="wrap"><h2 class="sec-h">Описание</h2><div class="rich" style="max-width:880px">{body}</div></div></section>
<section class="section" id="faq" style="padding-top:0"><div class="wrap"><div class="eyebrow">Вопросы и ответы</div><h2 class="sec-h">Частые вопросы</h2>{faq_html}</div></section>
<section class="section" style="padding-top:0"><div class="wrap"><div class="seo-text"><p class="seo-lead">{lead}</p><details class="seo-more"><summary>Подбор и замена</summary><div class="seo-body"><p>Точный подбор аналога {esc(term)} — в <a href="/podbor">калькуляторе</a>. Наша модель — <a href="/reduktor/{evlslug}">{esc(evl)}</a>, замена импорта — <a href="/importozameshchenie">импортозамещение</a>, все аналоги — <a href="/analog/">каталог аналогов</a>.</p></div></details></div></div></section>
{FOOTER}
{SCRIPTS}
</body></html>
'''
    # чистка любых внутренних comma-ссылок на /analog/ (из lead/FAQ, унаследованных из старых файлов)
    doc = re.sub(r'(/analog/)([a-z0-9,._-]+)', lambda m: m.group(1) + clean_slug(m.group(2)), doc)
    open(os.path.join(SITE, 'analog', newslug + '.html'), 'w', encoding='utf-8').write(doc)
    return oldslug, newslug

def main():
    import sys
    rows = [os.path.basename(f)[:-5] for f in glob.glob(os.path.join(SITE, 'analog', '*.html'))
            if '-i' in os.path.basename(f)]
    only = sys.argv[1:] if len(sys.argv) > 1 else None
    if only: rows = [r for r in rows if r in only]
    done = 0; renamed = 0; fail = []
    for old in rows:
        try:
            r = build(old)
        except Exception as e:
            fail.append((old, str(e)[:60])); continue
        if not r: fail.append((old, 'no-match')); continue
        oldslug, newslug = r; done += 1
        if oldslug != newslug:
            op = os.path.join(SITE, 'analog', oldslug + '.html')
            if os.path.exists(op): os.remove(op); renamed += 1
    print(f'Пересобрано per-row карточек: {done} | slug исправлено: {renamed} | не удалось: {len(fail)}')
    if fail: print('  fail:', fail[:12])

if __name__ == '__main__':
    main()
