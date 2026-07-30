#!/usr/bin/env python3
"""
Обогащение карточек analog/sew-* дуальным блоком «оригинал ↔ аналог» + содержательным
блоком про серию SEW (R/F/K/S/W). Контент из данных карточки + фактура по серии.
Идемпотентно (маркер). Цель — вес под запросы «купить SEW …» и «аналог SEW …».

    python3 tools/enrich_sew.py --dry analog/sew-r-107-i10-18_5kvt.html
    python3 tools/enrich_sew.py FILE [FILE ...]     # применить к списку
    python3 tools/enrich_sew.py --all               # все analog/sew-*
"""
import re, sys, glob, os, html

MARK = "<!--dualsew-->"

SERIES = {
 'r': ("SEW R — цилиндрические соосные",
   "Серия <b>SEW R</b> — цилиндрические соосные (helical-inline) мотор-редукторы: входной и "
   "выходной валы на одной оси, компактная установка «в линию». Момент до ~19 000 Н·м, "
   "мощность 0,09–160 кВт, передаточные числа до i≈289, чугунный корпус. Применяют в "
   "конвейерах, рольгангах, кранах, мешалках и миксерах (исполнение RM). Российский аналог — "
   "соосно-цилиндрические редукторы ZR с совпадающими присоединительными размерами."),
 'f': ("SEW F — плоские параллельные",
   "Серия <b>SEW F</b> — плоские параллельные (parallel-shaft) мотор-редукторы со смещёнными "
   "осями и малой монтажной высотой для тесных мест. Момент до ~5900 Н·м, мощность до 7,5 кВт. "
   "Исполнения F/FF/FAF/FAB — сплошной или полый вал, лапы или фланец. Применяют в транспортёрах, "
   "упаковочных линиях, станках. Аналог — плоские цилиндрические редукторы ZR."),
 'k': ("SEW K — коническо-цилиндрические",
   "Серия <b>SEW K</b> — коническо-цилиндрические (helical-bevel) угловые мотор-редукторы с "
   "валами под 90°: высокий КПД и большой момент при угловой компоновке. Исполнения K/KA/KF/KAF. "
   "Применяют в подъёмниках, талях, конвейерах и экскаваторном оборудовании. Аналог — "
   "коническо-цилиндрические редукторы ZR (замена без переделки узла)."),
 's': ("SEW S — цилиндро-червячные",
   "Серия <b>SEW S</b> — цилиндро-червячные (helical-worm) мотор-редукторы: червячная ступень с "
   "цилиндрической предступенью и эффектом самоторможения. Момент до ~1900 Н·м. Применяют в "
   "конвейерах и подъёмном оборудовании, где важна безопасность удержания. Аналог — червячные и "
   "цилиндро-червячные редукторы ZR."),
 'w': ("SEW W (SPIROPLAN®) — компактные угловые",
   "Серия <b>SEW W</b> (SPIROPLAN®) — компактные угловые мотор-редукторы с беззазорной стальной "
   "парой в лёгком корпусе: малый вес и габарит, тихий ход. Применяют в лёгких приводах, автоматике, "
   "упаковке. Аналог — малогабаритные угловые/червячные мотор-редукторы ZR."),
}

def field(t, key):
    m = re.search(r'<span class="k">'+re.escape(key)+r'[^<]*</span><span class="v">(.*?)</span>', t)
    return re.sub('<[^>]+>', '', m.group(1)).strip() if m else ''

def series_of(slug):
    b = os.path.basename(slug).replace('sew-', '')
    if b.startswith('wa') or b.startswith('w-'): return 'w'
    return b[0] if b and b[0] in SERIES else ''

def enrich(t, slug):
    if MARK in t: return t, False
    ser = series_of(slug)
    if ser not in SERIES: return t, False
    model = field(t, 'Модель') or 'SEW'
    zr    = field(t, 'Наш аналог') or 'ZR'
    zrlink = (re.search(r'href="(/reduktor/[^"]+)"', t) or [None, '/reduktor/'])[1]
    stitle, stext = SERIES[ser]
    dual = (MARK +
      '<div style="margin-top:22px">'
      '<h3 class="sec-h" style="font-size:18px;margin-bottom:6px">Оригинал под заказ или аналог со склада?</h3>'
      '<p style="color:var(--muted);font-size:14px;margin-bottom:14px">Поставляем и оригинал <b>'
      + html.escape(model) + '</b> под заказ, и российский аналог <b>' + html.escape(zr) + '</b> собственного '
      'производства — с теми же присоединительными и габаритными размерами.</p>'
      '<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px" class="dual-offer">'
      '<div style="border:1px solid var(--line);border-radius:12px;padding:16px 18px;background:var(--card)">'
      '<div style="font:700 15px/1.2 \'Space Grotesk\',sans-serif;margin-bottom:10px">Оригинал ' + html.escape(model) + '</div>'
      '<ul style="margin:0;padding-left:18px;color:var(--muted);font-size:13.5px;line-height:1.7">'
      '<li>Поставка под заказ, срок 4–12 недель</li><li>Цена с учётом валютного курса и логистики</li>'
      '<li>Заводская гарантия производителя</li></ul>'
      '<a class="btn ghost" data-zayavka href="#zayavka" style="margin-top:12px">Запросить оригинал</a></div>'
      '<div style="border:2px solid var(--red);border-radius:12px;padding:16px 18px;background:var(--card)">'
      '<div style="font:700 15px/1.2 \'Space Grotesk\',sans-serif;margin-bottom:10px">Аналог ' + html.escape(zr)
      + ' <span style="color:var(--red);font-size:12px">· со склада</span></div>'
      '<ul style="margin:0;padding-left:18px;color:var(--muted);font-size:13.5px;line-height:1.7">'
      '<li>Отгрузка от 3 дней со склада</li><li>Цена в рублях, без валютных наценок</li>'
      '<li>Те же присоединительные размеры — замена без переделки</li><li>Гарантия завода 24 месяца</li></ul>'
      '<a class="btn" href="' + zrlink + '" style="margin-top:12px">Открыть аналог ' + html.escape(zr) + ' →</a></div>'
      '</div>'
      '<div style="border-top:1px solid var(--line);margin-top:18px;padding-top:14px">'
      '<h3 class="sec-h" style="font-size:17px;margin-bottom:8px">Серия ' + html.escape(stitle) + '</h3>'
      '<p style="color:var(--muted);font-size:14px;line-height:1.65;max-width:880px">' + stext + '</p></div>'
      '</div>')
    # вставить в конец секции #opis (перед её закрытием)
    m = re.search(r'<section[^>]*id="opis".*?</section>', t, re.S)
    if not m: return t, False
    sec = m.group(0)
    sec2 = sec[:sec.rfind('</div></div></section>')] + dual + '</div></div></section>'
    return t[:m.start()] + sec2 + t[m.end():], True

def main():
    a = sys.argv[1:]
    if '--dry' in a:
        f = a[a.index('--dry')+1]; t = open(f, encoding='utf-8').read()
        t2, ch = enrich(t, f)
        print('changed:', ch, '| dual-offer:', t2.count('dual-offer'), '| серия:', t2.count('Серия SEW'))
        return
    files = glob.glob('analog/sew-*.html') if '--all' in a else [x for x in a if x.endswith('.html')]
    n = 0
    for f in files:
        if os.path.basename(f) == 'index.html': continue
        t = open(f, encoding='utf-8', errors='replace').read()
        t2, ch = enrich(t, f)
        if ch: open(f, 'w', encoding='utf-8').write(t2); n += 1
    print(f'enriched {n}/{len(files)}')

if __name__ == '__main__':
    main()
