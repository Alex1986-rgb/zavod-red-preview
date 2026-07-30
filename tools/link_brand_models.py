#!/usr/bin/env python3
"""
Перелинковка хаб→карточки: добавляет на бренд-страницу brands/{brand}.html блок «Модели {BRAND}»
со ссылками на репрезентативную выборку карточек этого бренда (по ~N на серию/префикс).
Так авторитет хаба передаётся карточкам (лечит слабую внутреннюю перелинковку). Идемпотентно.

    python3 tools/link_brand_models.py sew
    python3 tools/link_brand_models.py sew nord motovario bonfiglioli
"""
import re, sys, glob, os, collections, html

MARK = "<!--brandmodels-->"
PER_SERIES = 10   # сколько карточек на серию/префикс
MAX_TOTAL  = 48

def model_of(path):
    t = open(path, encoding='utf-8', errors='replace').read()
    m = re.search(r'<span class="k">Модель</span><span class="v">(.*?)</span>', t)
    if m: return re.sub('<[^>]+>', '', m.group(1)).strip()
    tt = re.search(r'<title>(.*?)(?: \d|[—|])', t)
    return tt.group(1).strip() if tt else os.path.basename(path)

def model_key(path, brand):
    """Ключ МОДЕЛИ из слага без варианта (обрезаем -iXXX-YYkvt): sew-f-107-i101-11kvt → sew-f-107."""
    b = os.path.basename(path)[:-5]
    return re.split(r'-i\d', b)[0]

def pick(brand):
    files = sorted(glob.glob(f'analog/{brand}-*.html'))
    files = [f for f in files if os.path.basename(f) != 'index.html']
    # одна карточка на уникальную модель, сгруппировано по серии (префиксу)
    seen = set(); by_series = collections.OrderedDict()
    for f in files:
        mk = model_key(f, brand)
        if mk in seen: continue
        seen.add(mk)
        ser = re.match(r'([a-z]+)', os.path.basename(f).replace(f'{brand}-', ''))
        ser = ser.group(1) if ser else 'x'
        by_series.setdefault(ser, []).append(f)
    # равномерно по сериям, до MAX_TOTAL
    picked = []; round_i = 0
    while len(picked) < MAX_TOTAL and any(len(v) > round_i for v in by_series.values()):
        for ser, lst in by_series.items():
            if len(lst) > round_i:
                picked.append(lst[round_i])
                if len(picked) >= MAX_TOTAL: break
        round_i += 1
    return picked[:MAX_TOTAL]

def block_for(brand):
    picked = pick(brand)
    if not picked: return None
    chips = []
    for f in picked:
        slug = '/analog/' + os.path.basename(f)[:-5]
        chips.append(f'<a href="{slug}">{html.escape(model_of(f))}</a>')
    return (MARK +
      '<style>.bm-chips{display:flex;flex-wrap:wrap;gap:8px}.bm-chips a{display:inline-flex;align-items:center;'
      'padding:9px 15px;border-radius:9px;background:var(--card);border:1px solid var(--line);color:var(--text);'
      "font:600 13.5px/1 'Space Grotesk',sans-serif;text-decoration:none;transition:.14s}"
      '.bm-chips a:hover{border-color:var(--red);color:var(--red);transform:translateY(-1px)}</style>'
      '<section class="section" style="padding-top:0"><div class="wrap">'
      '<h2 class="sec-h" style="font-size:22px;margin-bottom:6px">Модели ' + brand.upper() + ' — подбор аналога</h2>'
      '<p style="color:var(--muted);font-size:14px;margin-bottom:14px">Популярные типоразмеры ' + brand.upper()
      + ' с российскими аналогами ZR. Не нашли свою модель — пришлите шильд, подберём за 15 минут.</p>'
      '<div class="bm-chips">' + ''.join(chips) + '</div>'
      '</div></section>')

def apply(brand):
    bf = f'brands/{brand}.html'
    if not os.path.exists(bf): return f'{brand}: нет brands/{brand}.html'
    t = open(bf, encoding='utf-8', errors='replace').read()
    if MARK in t: return f'{brand}: уже добавлено'
    blk = block_for(brand)
    if not blk: return f'{brand}: нет карточек analog/{brand}-*'
    # вставить перед footer
    i = t.rfind('<footer')
    if i == -1: i = t.rfind('</body>')
    if i == -1: return f'{brand}: не найдено место вставки'
    t = t[:i] + blk + t[i:]
    open(bf, 'w', encoding='utf-8').write(t)
    return f'{brand}: добавлено ({t.count(chr(60)+"a href=") and blk.count("/analog/")} ссылок)'

if __name__ == '__main__':
    for b in sys.argv[1:]:
        print(apply(b))
