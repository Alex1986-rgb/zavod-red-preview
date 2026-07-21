#!/usr/bin/env python3
"""Сквозная проверка инвариантов сайта. Падает с внятной ошибкой, если что-то откатилось.

Зачем: правки 20.07.2026 (бренд импорта не теряется при переходе в карточку) лежат в
десятках тысяч сгенерированных файлов. Любая перегенерация analog/, reduktor/, catalog/
или blog/ способна тихо вернуть прежнее поведение — и узнаем мы об этом от заказчика.
Здесь собраны проверки, которые в тот день делались руками в браузере.

Запуск:  python3 tools/check_site.py           # по исходникам
         python3 tools/check_site.py --dist    # по собранному dist/ (то, что реально уедет)
Код возврата 1, если есть проваленные проверки.
"""
import json, os, re, sys, glob, collections

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DIST = '--dist' in sys.argv
BASE = os.path.join(ROOT, 'dist') if DIST else ROOT
ANALOG = os.path.join(ROOT, 'analog')          # в dist не входит — всегда из исходников

results = []


def check(name, ok, detail=''):
    results.append((ok, name, detail))
    print(f'{"OK  " if ok else "ПРОВАЛ"}  {name}' + (f'\n        {detail}' if detail and not ok else ''))


def read(p):
    with open(p, encoding='utf-8', errors='ignore') as f:
        return f.read()


def pages(*globs):
    out = []
    for g in globs:
        out += glob.glob(os.path.join(BASE, g))
    return out


# ── 1. Бренд импорта не теряется: приоритет ссылок в плитках ──────────────────
bad = []
for p in pages('brands/*.html', 'catalog/index.html', 'catalog/importnye-motor-reduktory.html'):
    h = read(p)
    if 'import-catalog.json' not in h:
        continue
    if re.search(r'var url\s*=\s*c\.r\?\("/reduktor/', h) or re.search(r"var url\s*=\s*c\.r\?\('/reduktor/", h):
        bad.append(os.path.relpath(p, BASE))
check('Плитки ведут сначала на /analog/, а не на карточку ZR', not bad, f'перевёрнут приоритет: {bad[:5]}')

# ── 2. Карточки ZR понимают ?imp= (нужно для 120 позиций без своей страницы) ──
cards = pages('reduktor/*.html')
no_marker = [os.path.basename(p) for p in cards if 'ZR_IMPCTX' not in read(p)]
check(f'Все {len(cards)} карточек ZR принимают ?imp=', not no_marker, f'без маркера: {no_marker[:5]}')

# ── 3. Внутренний код EVL не виден пользователю ───────────────────────────────
tagre = re.compile(r'<(script|style)[^>]*>.*?</\1>', re.S)
leaks = []
for p in pages('*.html', 'catalog/*.html', 'brands/*.html', 'reduktor/*.html'):
    t = tagre.sub(' ', read(p))
    t = re.sub(r'<!--.*?-->', ' ', t, flags=re.S)
    t = re.sub(r'<[^>]+>', ' ', t)
    if 'EVL' in t:
        leaks.append(os.path.relpath(p, BASE))
check('EVL нигде не виден в тексте страниц', not leaks, f'утечки: {leaks[:5]}')

# ── 4. Выдуманного бренда «SEW Tramec» нет ────────────────────────────────────
ph = []
for p in pages('*.html', '*.xml', 'brands/*.html', 'catalog/*.html', 'blog/*.html'):
    h = read(p)
    if 'sew-tramec' in h or 'SEW Tramec' in h or 'SEW-Tramec' in h:
        ph.append(os.path.relpath(p, BASE))
check('Выдуманный бренд «SEW Tramec» вычищен', not ph, f'осталось в: {ph[:5]}')

# ── 5. Ссылки /analog/ ведут на существующие страницы ─────────────────────────
missing = collections.Counter()
total = 0
for p in pages('brands/*.html', 'catalog/*.html', 'reduktor/*.html', 'index.html'):
    for m in re.finditer(r'href="/analog/([a-z0-9_-]+)"', read(p)):
        total += 1
        if not os.path.exists(os.path.join(ANALOG, m.group(1) + '.html')):
            missing[m.group(1)] += 1
check(f'Все {total} ссылок на /analog/ ведут на существующие страницы',
      not missing, f'битые: {list(missing)[:5]}')

# ── 6. Плитки каталога и брендов не потеряли бренд в фолбэке ──────────────────
bare = []
for p in pages('brands/*.html', 'catalog/*.html'):
    h = read(p)
    for m in re.finditer(r'"/reduktor/"\+c\.r(?!\+"\?imp)', h):
        bare.append(os.path.relpath(p, BASE)); break
check('Фолбэк на карточку ZR всегда несёт ?imp= с брендом', not bare, f'без ?imp=: {bare[:5]}')

# ── 7. Карточки аналогов: две кнопки, красная — «Запросить оригинал» ──────────
sample = sorted(glob.glob(os.path.join(ANALOG, '*.html')))
sample = [p for p in sample if os.path.basename(p) != 'index.html'][:400]
wrong = []
for p in sample:
    m = re.search(r'<div class="p2-cta1">(.*?)</div>', read(p), re.S)
    if not m:
        continue
    labels = re.findall(r'>([^<>]+)</a>', m.group(1))
    if labels != ['Запросить оригинал', 'Подобрать аналог']:
        wrong.append(os.path.basename(p))
check(f'Кнопки карточек аналогов (выборка {len(sample)}): оригинал красным, аналог белым',
      not wrong, f'иначе: {wrong[:5]}')

# ── 8. Индекс карточек для калькулятора на месте и валиден ────────────────────
idx_dir = os.path.join(BASE, 'assets', 'analog-idx')
ok_idx, det = False, 'каталог индекса не найден'
if os.path.isdir(idx_dir):
    man = json.loads(read(os.path.join(idx_dir, 'index.json')))
    bad_slug = []
    for brand in list(man)[:3]:
        data = json.loads(read(os.path.join(idx_dir, brand + '.json')))
        # «_m» — не модель, а список страниц уровня модели («yilmaz-e-030»): у них нет
        # передаточного и мощности, поэтому проверяются отдельно
        for name in (data.get('_m') or [])[:20]:
            if not os.path.exists(os.path.join(ANALOG, f'{brand}-{name}.html')):
                bad_slug.append(f'{brand}-{name}')
        for model, rows in list(data.items())[:20]:
            if model == '_m':
                continue
            for r in rows[:3]:
                if not os.path.exists(os.path.join(ANALOG, f'{brand}-{model}{r[2]}.html')):
                    bad_slug.append(f'{brand}-{model}{r[2]}')
    ok_idx = not bad_slug
    det = f'слаги без файла: {bad_slug[:5]}'
check('Индекс analog-idx указывает на реальные страницы', ok_idx, det)

# ── 9. Поисковый индекс адресует конкретные позиции импорта ───────────────────
si = os.path.join(BASE, 'assets', 'search-index.json')
ok_si, det = False, 'файл не найден'
if os.path.exists(si):
    d = json.loads(read(si))
    n = len(d.get('i') or [])
    withu = sum(1 for x in (d.get('i') or []) if x.get('u'))
    ok_si = n >= 400 and withu >= 300
    det = f'записей импорта {n}, из них со слагом /analog/ {withu}'
check('Поиск адресует позиции импорта, а не только группы ZR', ok_si, det)

# ── 10. Удалённый пункт меню не вернулся ──────────────────────────────────────
back = [os.path.relpath(p, BASE) for p in pages('*.html', 'catalog/*.html', 'brands/*.html')
        if '<a href="/catalog/evl">Мотор-редукторы' in read(p)]
check('Дубль-пункт меню «Мотор-редукторы ZR» не вернулся', not back, f'вернулся в: {back[:5]}')

# ── 11. Картинки бренда Tramec существуют под новыми именами ──────────────────
imgs = ['assets/brands/tramec.webp', 'assets/brands-photo/tramec.webp',
        'assets/brands-photo/tramec-unit.webp', 'assets/brands-float/tramec.png']
lost = [i for i in imgs if not os.path.exists(os.path.join(BASE, i))]
check('Картинки Tramec на месте', not lost, f'нет файлов: {lost}')

# ── 12. Корзина складывает абсолютный путь к картинке и не плодит дубли ───────
carts = [p for p in pages('catalog/*.html', 'motor-reduktor-zr/index.html')
         if 'pcard-cart' in read(p) and 'btn.dataset.name' in read(p)]
old = [os.path.relpath(p, BASE) for p in carts
       if 'c.items.push({name:btn.dataset.name,price:0,qty:1,img:btn.dataset.img})' in read(p)]
check(f'Корзина ({len(carts)} страниц): абсолютный путь и увеличение количества',
      not old, f'старый обработчик в: {old[:5]}')

print()
failed = [r for r in results if not r[0]]
print(f'Проверок: {len(results)} | провалено: {len(failed)}')
sys.exit(1 if failed else 0)
