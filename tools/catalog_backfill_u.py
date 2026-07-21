#!/usr/bin/env python3
"""Проставляет в assets/import-catalog.json слаг страницы аналога (поле u) там, где он пуст,
но страница на самом деле существует.

Зачем: плитки каталога и брендов ведут на /analog/<u>, а без u падают на обезличенную
карточку ZR. У Watt Drive и Tramec страницы аналогов есть, но поле не было заполнено —
и весь бренд уходил на старый вид карточки, хотя брендовая страница на сайте лежит.

Сопоставление идёт по индексу assets/analog-idx (см. tools/gen_analog_index.py) и
проверяется существованием файла на диске: выдуманных ссылок не ставим.

Порядок: gen_analog_index.py → catalog_backfill_u.py
Запуск:  python3 tools/catalog_backfill_u.py [--dry]
"""
import json, os, re, sys, collections

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CAT = os.path.join(ROOT, 'assets', 'import-catalog.json')
IDXD = os.path.join(ROOT, 'assets', 'analog-idx')
ANALOG = os.path.join(ROOT, 'analog')

# Слаг бренда в именах файлов отличается от названия в каталоге
BSLUG = {'SEW-Eurodrive': 'sew', 'Watt Drive': 'watt-drive', 'Tos Znojmo': 'tos-znojmo'}


def bslug(b):
    return BSLUG.get(b, b.lower().split(' ')[0])


def fs(s):
    return re.sub(r'[^a-z0-9]+', '-', s.lower().replace('х', 'x')).strip('-')


def model_keys(part):
    """Варианты написания модели: как есть, с латинизацией кириллических букв
    (в данных встречается «112В, 100С» с русскими В и С) и голое число —
    страницы Tramec названы просто по типоразмеру («tramec-112»)."""
    s = part.strip()
    out = []
    t = s
    for a, b in [('В', 'b'), ('B', 'b'), ('С', 'c'), ('C', 'c'), ('А', 'a'),
                 ('A', 'a'), ('К', 'k'), ('K', 'k'), ('Р', 'p'), ('P', 'p')]:
        t = t.replace(a, b)
    for v in (s, t):
        f = fs(v)
        if f and f not in out:
            out.append(f)
    m = re.search(r'\d+', s)
    if m and m.group(0) not in out:
        out.append(m.group(0))
    return out


def main():
    dry = '--dry' in sys.argv
    cat = json.load(open(CAT, encoding='utf-8'))
    idx = {}
    for f in os.listdir(IDXD):
        if f.endswith('.json') and f != 'index.json':
            idx[f[:-5]] = json.load(open(os.path.join(IDXD, f), encoding='utf-8'))

    filled = 0
    left = collections.Counter()
    for c in cat['cards']:
        if c.get('u'):
            continue
        n = bslug(c['b'])
        d = idx.get(n)
        hit = None
        if d:
            for part in str(c['m']).split(','):
                for k in model_keys(part):
                    if k != '_m' and d.get(k):
                        hit = n + '-' + k + d[k][0][2]
                    elif k in (d.get('_m') or []):
                        hit = n + '-' + k
                    if hit:
                        break
                if hit:
                    break
        # страховка от битой ссылки: слаг принимаем, только если файл реально есть
        if hit and os.path.exists(os.path.join(ANALOG, hit + '.html')):
            c['u'] = hit
            filled += 1
        else:
            left[c['b']] += 1

    if not dry:
        with open(CAT, 'w', encoding='utf-8') as f:
            json.dump(cat, f, ensure_ascii=False, separators=(',', ':'))

    total = len(cat['cards'])
    withu = sum(1 for c in cat['cards'] if c.get('u'))
    print(f'проставлено u: {filled} | всего со слагом: {withu} из {total}'
          + (' (пробный прогон)' if dry else ''))
    if left:
        print('без страницы аналога (её просто нет на сайте):')
        for b, n in left.most_common():
            print(f'  {b:14} {n}')


if __name__ == '__main__':
    main()
