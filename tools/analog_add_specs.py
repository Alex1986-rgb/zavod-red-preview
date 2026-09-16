#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Доливает в карточки /analog/ параметры КОНКРЕТНОГО исполнения из каталога EVL.

Зачем: в таблице характеристик карточки до сих пор стояли только диапазоны модели
(«мощность 0,1–3 кВт», «передаточное 5–106») — одни и те же на всех 178 исполнениях
Bauer BK 20. Между тем в assets/podbor-data.json по каждой строке каталога лежат три
величины, которых на страницах нет вообще: консольная нагрузка Fном, сервис-фактор
Sfном и обороты двигателя n вх. Это настоящие числа, а не перефразировка: они и
различают исполнения, и нужны инженеру при подборе.

Как сопоставляется карточка и строка каталога: из <title> берутся модель, мощность и
передаточное число, модель ищется среди импортных соответствий g[*].a, а внутри группы
берётся строка с той же мощностью и тем же передаточным. Совпадение проверяется по
моменту и оборотам на выходе, которые на карточке уже есть: разойдутся — карточка
пропускается, лишь бы не поставить чужие цифры.

Блок помечен маркером, повторный запуск его заменяет.

Запуск:  python3 tools/analog_add_specs.py [--dry] [--limit N] [--brand bauer]
"""
import argparse
import collections
import glob
import html
import json
import os
import re

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
ANALOG = os.path.join(ROOT, 'analog')

TITLE = re.compile(r'<title>([^<]*)</title>')
PARAM = re.compile(r'^(?P<model>.+?)\s+(?P<power>\d+(?:,\d+)?)\s*кВт\s+'
                   r'i=(?P<ratio>\d+(?:,\d+)?)'
                   r'(?:,\s*(?P<torque>\d+(?:,\d+)?)\s*Н·м)?')
# «обороты 48 об/мин» из описания — второй сверяемый признак
NOUT = re.compile(r'обороты\s+(\d+(?:[.,]\d+)?)\s*об/мин')
ANCHOR = '<div><span class="k">Цена</span>'
MARK_OPEN = '<!-- ZR_SPEC -->'
MARK_CLOSE = '<!-- /ZR_SPEC -->'
BLOCK = re.compile(re.escape(MARK_OPEN) + '.*?' + re.escape(MARK_CLOSE), re.S)

NBSP = ' '


def key(s):
    return re.sub(r'[\s\-—.]+', '', s).lower()


def num(s):
    return float(s.replace(',', '.'))


def ru(x, d=0):
    """3780 → «3 780», 0.85 → «0,85». Разряды разделяем неразрывным пробелом."""
    v = round(float(x), d)
    s = f'{v:,.{d}f}'.replace(',', ' ').replace('.', ',')
    return s.replace(' ', NBSP)


def build_index():
    db = json.load(open(os.path.join(ROOT, 'assets/podbor-data.json'), encoding='utf-8'))
    by_name = collections.defaultdict(set)
    for gi, g in db['g'].items():
        for brand, models in (g.get('a') or {}).items():
            short = brand.split(' ')[0]
            for m in models:
                for part in re.split(r',\s*', m):
                    if part.strip():
                        by_name[key(short + ' ' + part)].add(int(gi))
    rows = collections.defaultdict(list)
    for r in db['i']:
        rows[r[0]].append(r)
    return by_name, rows


def find_row(by_name, rows, model, power, ratio, torque=None):
    """Строка каталога для этой карточки или None.

    У SEW слаг перечисляет несколько моделей сразу («SEW K 57, S 67») — тогда ищем по
    первой, у неё бренд указан явно, а остальные части идут без бренда.

    Одна и та же модель бывает аналогом сразу нескольких типоразмеров EVL, и тогда под
    пару «мощность + передаточное» подходит несколько строк каталога с разным моментом.
    Это как раз клоны «-1», «-2» в именах файлов. Разводим их по моменту, который на
    карточке уже написан: он и отличает эти строки друг от друга.
    """
    names = [model]
    parts = [p.strip() for p in model.split(',')]
    if len(parts) > 1:
        brand = parts[0].split(' ')[0]
        names += parts[:1] + [f'{brand} {p}' for p in parts[1:]]
    cand = []
    for name in names:
        for gi in by_name.get(key(name), ()):
            for r in rows.get(gi, ()):
                if abs(r[1] - power) < 1e-6 and abs(r[4] - ratio) < 5e-3:
                    cand.append(r)
        if cand:
            break
    if not cand:
        return None
    if torque is not None:
        cand.sort(key=lambda r: abs(r[3] - torque))
    return cand[0]


def row_html(r, power, ratio):
    def cell(k, v):
        return f'<div><span class="k">{k}</span><span class="v">{v}</span></div>'
    out = [
        cell('Мощность (исполнение)', f'{ru(power, 2).rstrip("0").rstrip(",")}{NBSP}кВт'),
        cell('Передаточное (исполнение)', ru(ratio, 2).rstrip('0').rstrip(',')),
        cell('Момент на выходе Tном', f'{ru(r[3], 1).rstrip("0").rstrip(",")}{NBSP}Н·м'),
        cell('Обороты на выходе n вых', f'{ru(r[2], 1).rstrip("0").rstrip(",")}{NBSP}об/мин'),
    ]
    if r[5] is not None:
        out.append(cell('Консольная нагрузка Fном', f'{ru(r[5])}{NBSP}Н'))
    if r[6] is not None:
        out.append(cell('Сервис-фактор Sfном', ru(r[6], 2)))
    if r[7] is not None:
        out.append(cell('Обороты двигателя n вх', f'{ru(r[7])}{NBSP}об/мин'))
    return MARK_OPEN + ''.join(out) + MARK_CLOSE


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--dry', action='store_true')
    ap.add_argument('--limit', type=int)
    ap.add_argument('--brand')
    args = ap.parse_args()

    by_name, rows = build_index()
    files = sorted(glob.glob(os.path.join(ANALOG, '*.html')))
    if args.brand:
        files = [f for f in files if os.path.basename(f).startswith(args.brand)]
    if args.limit:
        files = files[:args.limit]

    stat = collections.Counter()
    bad = []
    for path in files:
        with open(path, encoding='utf-8', errors='ignore') as f:
            text = f.read()
        t = TITLE.search(text)
        if not t:
            stat['без title'] += 1
            continue
        m = PARAM.match(html.unescape(t.group(1)))
        if not m:
            stat['карточка модели — пропуск'] += 1
            continue
        power, ratio = num(m.group('power')), num(m.group('ratio'))
        torque = num(m.group('torque')) if m.group('torque') else None
        r = find_row(by_name, rows, m.group('model').strip(), power, ratio, torque)
        if r is None:
            stat['нет строки в каталоге'] += 1
            continue
        # сверка: момент и обороты на выходе на карточке уже есть — должны совпасть
        if torque is not None and abs(torque - r[3]) > max(0.6, r[3] * 0.02):
            stat['разошёлся момент — пропуск'] += 1
            if len(bad) < 8:
                bad.append((os.path.basename(path), 'момент', m.group('torque'), r[3]))
            continue
        n = NOUT.search(text[:3000])
        if n and abs(num(n.group(1)) - r[2]) > max(0.6, r[2] * 0.02):
            stat['разошлись обороты — пропуск'] += 1
            if len(bad) < 8:
                bad.append((os.path.basename(path), 'n вых', n.group(1), r[2]))
            continue
        if ANCHOR not in text:
            stat['нет якоря'] += 1
            continue
        new = BLOCK.sub('', text)
        new = new.replace(ANCHOR, row_html(r, power, ratio) + ANCHOR, 1)
        if new == text:
            stat['без изменений'] += 1
            continue
        stat['дополнено'] += 1
        if not args.dry:
            with open(path, 'w', encoding='utf-8') as f:
                f.write(new)

    print(f'файлов просмотрено: {len(files)}')
    for k, v in stat.most_common():
        print(f'  {v:7}  {k}')
    if bad:
        print('\nпримеры расхождений (файл, что, на карточке, в каталоге):')
        for b in bad:
            print('  ', *b)


if __name__ == '__main__':
    main()
