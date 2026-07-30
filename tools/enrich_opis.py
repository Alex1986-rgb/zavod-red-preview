#!/usr/bin/env python3
"""
Расширяет описание analog-карточек до ~5 абзацев: добавляет абзац «Применение» (по типу
передачи) и абзац «Исполнения и подбор» (из параметров модели). Контент строится из ДАННЫХ
карточки (модель, тип, мощность, i, момент, аналог ZR) → абзацы различаются, а не дублируются.
Идемпотентно (маркер). Работает для любого бренда.

    python3 tools/enrich_opis.py --dry analog/sew-r-107-i10-18_5kvt.html
    python3 tools/enrich_opis.py FILE [FILE ...]
    python3 tools/enrich_opis.py --brand sew        # все analog/sew-*
    python3 tools/enrich_opis.py --all              # все analog/*
"""
import re, sys, glob, os, html

MARK = "<!--applic-->"

# применение по типу передачи (подстрока типа → текст)
APPS = [
 ('червячн',  "в приводах, где нужны большое передаточное число в одной ступени, компактность и "
              "эффект самоторможения: конвейеры и рольганги, секционные ворота и шлагбаумы, дозаторы "
              "и питатели, мешалки, упаковочное и фасовочное оборудование, приводы задвижек и заслонок"),
 ('коническо',"там, где важны угловая компоновка валов (90°), высокий КПД и большой выходной момент: "
              "тяжёлые ленточные и скребковые конвейеры, ковшовые элеваторы, дробилки и мельницы, "
              "аэраторы и барабанные сушилки, миксеры большой мощности"),
 ('соосно',   "там, где привод ставится «в линию» при соосном расположении валов: насосы и вентиляторы, "
              "мешалки и миксеры, экструдеры, транспортёры и рольганги, станочное и общепромышленное оборудование"),
 ('плоск',    "там, где ограничена монтажная высота и нужен насадной (полый) вал: транспортёры и рольганги "
              "в стеснённых условиях, упаковочные и конвейерные линии, приводы барабанов"),
 ('планетарн',"там, где нужна высокая удельная нагрузочная способность при малых габаритах: приводы "
              "перемешивающих устройств, тяжёлые конвейеры, подъёмно-транспортное и специальное оборудование"),
 ('цилиндрическ',"в общепромышленных приводах: конвейеры, транспортёры, насосы и вентиляторы, "
              "мешалки, станочное оборудование и линии"),
]
def apps_for(typ):
    tl = (typ or '').lower()
    for key, txt in APPS:
        if key in tl: return txt
    return "в приводах общепромышленного назначения — конвейерах, транспортёрах, насосах, мешалках и станочном оборудовании"

def field(t, key):
    m = re.search(r'<span class="k">'+re.escape(key)+r'[^<]*</span><span class="v">(.*?)</span>', t)
    return re.sub('<[^>]+>', '', m.group(1)).strip() if m else ''

def enrich(t):
    if MARK in t: return t, False
    m = re.search(r'<section[^>]*id="opis".*?</section>', t, re.S)
    if not m or 'opis-lead' not in m.group(0): return t, False
    model = field(t, 'Модель') or 'модель'
    zr    = field(t, 'Наш аналог') or 'ZR'
    typ   = field(t, 'Тип передачи') or ''
    power = field(t, 'Мощность (модель)') or field(t, 'Мощность') or ''
    i     = field(t, 'Передаточное число') or ''
    mom   = (re.search(r'(\d[\d  .,\-–]*)\s*Н·м', re.search(r'<title>(.*?)</title>', t).group(1))
             if re.search(r'<title>', t) else None)
    moment = mom.group(1).strip() if mom else ''
    typ_l = typ.lower() if typ else 'мотор-редуктор'

    p_app = ('<p class="opis-lead">Мотор-редукторы <b>' + html.escape(model) + '</b> и российский аналог <b>'
             + html.escape(zr) + '</b> (' + html.escape(typ_l) + ') применяют ' + apps_for(typ) + '. '
             'Подбор конкретного исполнения под нагрузку и присоединительные размеры выполняет инженер.</p>')
    mom_clause = (' и требуемому моменту до ' + html.escape(moment) + ' Н·м') if moment else ''
    pw_clause  = ('При мощности ' + html.escape(power) + ' ') if power else ''
    i_clause   = ('и передаточных числах ' + html.escape(i) + ' ') if i else ''
    p_spec = ('<p class="opis-lead"><b>' + html.escape(model) + '</b> и аналог <b>' + html.escape(zr) + '</b> '
             'поставляются в разных монтажных исполнениях — на лапах, с выходным фланцем или комбинированно; '
             'выходной вал бывает сплошным со шпонкой или полым (насадным). При заказе указывают монтажное '
             'положение, сторону и тип выходного вала, климатическое исполнение — от этого зависят смазка, '
             'теплоотвод и присоединение к раме механизма. ' + pw_clause + i_clause +
             'исполнение подбирают по нагрузке' + mom_clause + ' и оборотам на выходе; при необходимости '
             'изготавливаем переходные фланцы и спецвалы по чертежам.</p>')

    add = MARK + p_app + p_spec
    sec = m.group(0)
    if '<div class="why-grid">' in sec:
        sec2 = sec.replace('<div class="why-grid">', add + '<div class="why-grid">', 1)
    else:
        sec2 = sec[:sec.rfind('</div></div></section>')] + add + '</div></div></section>'
    return t[:m.start()] + sec2 + t[m.end():], True

def main():
    a = sys.argv[1:]
    if '--dry' in a:
        f = a[a.index('--dry')+1]; t = open(f, encoding='utf-8').read()
        t2, ch = enrich(t)
        secs = re.search(r'<section[^>]*id="opis".*?</section>', t2, re.S).group(0)
        print('changed:', ch, '| opis-lead абзацев:', secs.count('opis-lead'))
        return
    if '--all' in a:      files = glob.glob('analog/*.html')
    elif '--brand' in a:  files = glob.glob('analog/'+a[a.index('--brand')+1]+'-*.html')
    else:                 files = [x for x in a if x.endswith('.html')]
    n = 0
    for f in files:
        if os.path.basename(f) == 'index.html': continue
        t = open(f, encoding='utf-8', errors='replace').read()
        t2, ch = enrich(t)
        if ch: open(f, 'w', encoding='utf-8').write(t2); n += 1
    print(f'enriched {n}/{len(files)}')

if __name__ == '__main__':
    main()
