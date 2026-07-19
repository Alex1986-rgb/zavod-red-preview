#!/usr/bin/env python3
"""П.8/П.11 для motor-reduktor-zr: нав-табы + секции Доставка/Тех.документация/Чертёж.

ZR-карточки простые (hero + spec-таблица, без id/нав). Добавляем:
  - id="opis" на hero-секцию, id="spec" на секцию характеристик;
  - нав-панель apro-nav (Описание/Характеристики/Доставка/Тех.документация/Чертёж) перед характеристиками;
  - универсальные секции #dostavka/#tehdoc/#chertezh перед подвалом;
  - CSS apro-nav (у zr-карточек его нет).

Идемпотентно (маркер id="dostavka"). os.walk по motor-reduktor-zr.
"""
import os, re, sys
from collections import Counter

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
os.chdir(ROOT)

NAV = (
    '<div class="wrap"><nav class="apro-nav">'
    '<a href="#opis">Описание</a>'
    '<a href="#spec">Характеристики</a>'
    '<a href="#dostavka">Доставка и оплата</a>'
    '<a href="#tehdoc">Техническая документация</a>'
    '<a href="#chertezh">Чертёж</a>'
    '<span class="sp"></span>'
    '<a class="red" data-zayavka href="#zayavka">Получить расчёт</a>'
    '</nav></div>'
)

SECTIONS = (
    '<section class="section" id="dostavka" style="padding-top:10px"><div class="wrap">'
    '<h2 class="sec-h" style="font-size:22px;margin-bottom:14px">Доставка и оплата</h2>'
    '<div class="why-grid">'
    '<div><b>Отгрузка от 3 дней</b><span>серийные типоразмеры — со склада, остальное под заказ</span></div>'
    '<div><b>Доставка по России</b><span>транспортными компаниями до терминала или адреса; самовывоз со склада</span></div>'
    '<div><b>Оплата по счёту</b><span>безналичный расчёт по договору, УПД; для серийных — индивидуальные условия</span></div>'
    '<div><b>Гарантия 24 месяца</b><span>паспорт изделия и декларация соответствия в комплекте</span></div>'
    '</div></div></section>'
    '<section class="section" id="tehdoc" style="padding-top:10px"><div class="wrap">'
    '<h2 class="sec-h" style="font-size:22px;margin-bottom:14px">Техническая документация</h2>'
    '<div class="p2-docs"><span>Документы и справка:</span>'
    '<a href="/markirovka-zr">Расшифровка маркировки ZR</a>'
    '<a href="/glossary/montazhnoe-polozhenie">Монтажные положения</a>'
    '<a href="#spec">Характеристики</a>'
    '<a data-zayavka href="#zayavka">Паспорт и КП по запросу</a></div>'
    '</div></section>'
    '<section class="section" id="chertezh" style="padding-top:10px"><div class="wrap">'
    '<h2 class="sec-h" style="font-size:22px;margin-bottom:14px">Чертёж</h2>'
    '<p style="color:var(--muted);max-width:70ch;margin-bottom:14px">Габаритный и присоединительный чертёж исполнения — по запросу вместе с коммерческим предложением. Ниже — пример габаритного чертежа.</p>'
    '<img src="../assets/drawings/zr-demo-drawing.png" alt="Габаритный чертёж — пример" loading="lazy" style="max-width:640px;width:100%;border:1px solid var(--line);border-radius:12px;background:#fff;padding:10px;display:block">'
    '<p style="margin-top:14px"><a class="btn" data-zayavka href="#zayavka">Запросить чертёж исполнения</a></p>'
    '</div></section>'
)

CSS = (
    '<style id="zr-cardtabs">'
    '.apro-nav{position:sticky;top:56px;z-index:5;display:flex;gap:8px;flex-wrap:wrap;align-items:center;'
    'margin:8px 0 0;padding:12px 0;background:var(--bg);border-bottom:1px solid var(--line);overflow-x:auto}'
    ".apro-nav a{font-size:14.5px;font-weight:600;padding:11px 17px;border:1.5px solid var(--line);"
    "background:var(--card);color:var(--text);border-radius:10px;text-decoration:none;white-space:nowrap;transition:.14s}"
    '.apro-nav a:hover{border-color:var(--red);color:var(--red)}'
    '.apro-nav a.red{background:var(--red);color:#fff;border-color:var(--red)}'
    '.apro-nav .sp{flex:1}'
    '.why-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px}'
    '.why-grid>div{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:15px 16px}'
    '.why-grid b{display:block;color:var(--text);font-size:15px;margin-bottom:3px}'
    '.why-grid span{color:var(--muted);font-size:13px}'
    '.p2-docs{display:flex;flex-wrap:wrap;gap:8px;align-items:center;font-size:14px}'
    '.p2-docs>span{color:var(--muted);font-weight:600}'
    '.p2-docs a{padding:8px 13px;border:1px solid var(--line);border-radius:9px;color:var(--text);text-decoration:none;background:var(--card)}'
    '.p2-docs a:hover{border-color:var(--red);color:var(--red)}'
    '.sec-h{font-family:'"'"'Space Grotesk'"'"',sans-serif}'
    '@media(max-width:560px){.why-grid{grid-template-columns:1fr}}'
    '</style>'
)


def transform(path):
    t = open(path, encoding="utf-8").read()
    if 'id="dostavka"' in t:
        return "already"
    orig = t
    # id="opis" на hero-секцию (первая section с grid)
    t = re.sub(r'<section class="section"([^>]*)>(\s*<div class="wrap">\s*<div style="display:grid)',
               r'<section class="section" id="opis"\1>\2', t, count=1)
    # нав перед секцией характеристик + id="spec"
    t, n = re.subn(
        r'<section class="section"([^>]*)>(\s*<div class="wrap"><h2[^>]*>Характеристики мотор-редуктора)',
        lambda m: NAV + '<section class="section" id="spec"' + m.group(1) + '>' + m.group(2),
        t, count=1)
    if n == 0:
        return "no-spec"
    # секции перед подвалом
    fi = t.find('<footer')
    if fi < 0:
        return "no-footer"
    t = t[:fi] + SECTIONS + t[fi:]
    # CSS
    t = re.sub(r'<style id="zr-cardtabs">.*?</style>', '', t, flags=re.S)
    t = t.replace('</head>', CSS + '</head>', 1)
    if t == orig:
        return "no-change"
    open(path, "w", encoding="utf-8").write(t)
    return "ok"


if __name__ == "__main__":
    args = [a for a in sys.argv[1:] if a.endswith(".html")]
    st = Counter()
    if args:
        for f in args:
            st[transform(f)] += 1
    else:
        for root, dirs, files in os.walk("motor-reduktor-zr"):
            for f in files:
                if f.endswith(".html") and f != "index.html":
                    st[transform(os.path.join(root, f))] += 1
    print("итог:", dict(st))
