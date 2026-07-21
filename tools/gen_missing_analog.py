#!/usr/bin/env python3
"""Достраивает недостающие карточки /analog/ для позиций каталога без слага u.

Зачем: плитка с именем импортного бренда обязана вести на брендовую карточку. У части
позиций страницы не существовало вовсе — и клик уходил на обезличенную карточку ZR
(«старый вид», на который жалуется заказчик). Данные для карточек есть: модель, тип,
наш аналог ZR, мощность, передаточное и страна лежат в assets/import-catalog.json.

Как: клонируем существующую карточку того же ТИПА редуктора и подставляем поля. Проза
на этих страницах шаблонная — она описывает наш аналог и процесс поставки, а не саму
фирму, поэтому подстановка не порождает выдуманных утверждений о производителе.
Фото берётся по бренду (assets/catalog/br-<slug>-*.webp); если фото нет — страница НЕ
создаётся, чтобы не плодить битые картинки.

НЕ генерируем «7Ч-М» под брендом Varvel: это советская червячная серия, ошибочно ему
приписанная (в .htaccess уже стоит редирект ^analog/varvel-7-… → /catalog/chervyachnye).
Такие позиции остаются на нашей карточке ZR — это верно по существу.

Порядок: gen_analog_index.py → gen_missing_analog.py → gen_analog_index.py → catalog_backfill_u.py
Запуск:  python3 tools/gen_missing_analog.py [--dry]
"""
import json, os, re, sys, collections

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
ANALOG = os.path.join(ROOT, 'analog')
CAT = os.path.join(ROOT, 'assets', 'import-catalog.json')
IMG = os.path.join(ROOT, 'assets', 'catalog')

TYPES = ['червячный', 'соосно-цилиндрический', 'коническо-цилиндрический',
         'плоско-цилиндрический', 'цилиндрический']

# донор на каждый тип: существующая карточка уровня модели с той же структурой
DONOR = {
    'червячный': ('siti-mi-110', 'SITI', 'MI 110'),
    'соосно-цилиндрический': ('siti-mnhl-100', 'SITI', 'MNHL 100'),
    'коническо-цилиндрический': ('siti-100', 'SITI', '100'),
    'плоско-цилиндрический': ('siti-pd-100', 'SITI', 'PD 100'),
    'цилиндрический': ('siti-mnhl-100', 'SITI', 'MNHL 100'),
}

# слаг бренда в именах файлов и картинок отличается от названия в каталоге
BSLUG = {'SEW-Eurodrive': 'sew', 'Watt Drive': 'watt-drive', 'Tos Znojmo': 'tos-znojmo'}
BIMG = {'Tos Znojmo': 'tz', 'Watt Drive': 'watt-drive', 'SEW-Eurodrive': 'sew'}

# позиции, которым брендовая карточка не положена по существу
SKIP = [(lambda c: c['b'] == 'Varvel' and '7Ч-М' in c['m'], 'ошибочная атрибуция 7Ч-М')]


def bslug(b):
    return BSLUG.get(b, b.lower().split(' ')[0])


def bimg(b):
    return BIMG.get(b, b.lower().split(' ')[0])


def fs(s):
    return re.sub(r'[^a-z0-9]+', '-', s.lower().replace('х', 'x')).strip('-')


def first_model(m):
    """«PA 63, PC 63» → «PA 63»: слаг и заголовок строим по первому обозначению."""
    return str(m).split(',')[0].strip()


def brand_photo(b):
    pref = 'br-' + bimg(b) + '-'
    got = sorted(f for f in os.listdir(IMG) if f.startswith(pref) and f.endswith('.webp'))
    return got[0] if got else None


def zrmap():
    """Таблица EVL→ZR живёт в assets/podbor.js — читаем оттуда, чтобы не заводить второй
    источник истины и не выдумывать маркировку, которая уйдёт клиенту."""
    src = open(os.path.join(ROOT, 'assets', 'podbor.js'), encoding='utf-8').read()
    m = re.search(r'var ZRMAP=\{(.*?)\};', src, re.S)
    return {int(a): b for a, b in re.findall(r'(\d+)\s*:\s*[\'"]?([^,\'"}]+)', m.group(1))} if m else {}


def zr_of(evl, ZM):
    nums = re.search(r'EVL\s+([\d/]+)', str(evl))
    if not nums:
        return None
    parts = [ZM.get(int(x)) for x in nums.group(1).split('/')]
    return 'ZR ' + '/'.join(p for p in parts if p) if all(parts) else None


def from_podbor(existing):
    """Второй источник позиций: база подбора. В ней есть модели, которых нет в каталоге
    (например «TZ 623» у Tos Znojmo) — именно на них жаловался заказчик: плитки каталога
    у бренда уже вели на /analog/, а строка таблицы подбора всё ещё на карточку ZR."""
    ZM = zrmap()
    BK = {'TZ': 'Tos Znojmo', 'InnoRed': 'Innored', 'INNOVARI': 'Innovari',
          'SEW EURODRIVE': 'SEW-Eurodrive'}
    db = json.load(open(os.path.join(ROOT, 'assets', 'podbor-data.json'), encoding='utf-8'))['g']
    out, seen = [], set()
    for g in db.values():
        for bkey, models in (g.get('a') or {}).items():
            if not models:
                continue
            brand = BK.get(bkey, bkey.split(' ')[0])
            key = (brand, models[0])
            if key in seen:
                continue
            seen.add(key)
            out.append({'b': brand, 'm': models[0], 't': g.get('t', 0),
                        'z': zr_of(g.get('e'), ZM), 'pw': '', 'i': '', 'c': ''})
    return out


def main():
    dry = '--dry' in sys.argv
    cards = json.load(open(CAT, encoding='utf-8'))['cards']
    cards = cards + from_podbor(cards)
    made, skipped = 0, collections.Counter()

    for c in cards:
        if c.get('u'):
            continue
        for pred, why in SKIP:
            if pred(c):
                skipped[why] += 1
                break
        else:
            ty = TYPES[c['t']]
            donor_slug, dbrand, dmodel = DONOR[ty]
            dpath = os.path.join(ANALOG, donor_slug + '.html')
            photo = brand_photo(c['b'])
            if not os.path.exists(dpath) or not photo:
                skipped['нет донора или фото бренда'] += 1
                continue

            model = first_model(c['m'])
            slug = bslug(c['b']) + '-' + fs(model)
            out = os.path.join(ANALOG, slug + '.html')
            if os.path.exists(out):
                skipped['страница уже есть'] += 1
                continue

            h = open(dpath, encoding='utf-8').read()
            # порядок замен важен: сначала длинные строки (бренд+модель), потом короткие
            h = h.replace(dbrand + ' ' + dmodel, c['b'] + ' ' + model)
            h = h.replace(dmodel, model)
            # сжатая форма без пробелов встречается в <title> («MI110»)
            if dmodel.replace(' ', '') != dmodel:
                h = h.replace(dmodel.replace(' ', ''), model.replace(' ', ''))
            # у донора несколько ракурсов (t1, t2, …) — заменяем ВСЕ, иначе на новой карточке
            # останется чужое фото; у целевого бренда фото обычно одно
            h = re.sub(r'br-' + re.escape(bimg(dbrand)) + r'-t\d+\.webp', photo, h)
            # слаг донора встречается и без префикса — например в sku микроразметки Product
            h = h.replace(donor_slug, slug)
            h = h.replace('/brands/' + bslug(dbrand), '/brands/' + bslug(c['b']))
            h = h.replace(dbrand, c['b'])
            if c.get('z'):
                h = re.sub(r'ZR\s+[0-9]+(?:/[0-9]+)?', c['z'], h)
            if not dry:
                open(out, 'w', encoding='utf-8').write(h)
            made += 1

    print(f'создано карточек: {made}' + (' (пробный прогон)' if dry else ''))
    for k, v in skipped.most_common():
        print(f'  пропущено — {k}: {v}')


if __name__ == '__main__':
    main()
