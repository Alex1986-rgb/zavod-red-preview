#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Укорачивает слишком длинные description до 160 знаков.

Яндекс показывает в сниппете около 160 знаков описания, остальное обрезает
многоточием — и обрезает по границе, а не по смыслу, так что последним в сниппете
может оказаться огрызок слова. После того как в описания добавили кириллические
написания марок, десять страниц брендов вышли за предел на 1–10 знаков.

Правки — замены готовыми парами: канцелярский оборот меняется на короткий с тем же
смыслом. Замены применяются по очереди и ровно до тех пор, пока описание не уложится
в предел, поэтому лишнего не режется. Если после всех замен строка всё ещё длинная,
скрипт не обрезает её вслепую, а называет файл: такую надо переписать руками.

Запуск:  python3 tools/trim_descriptions.py [--dry] [--limit 160] [--glob 'brands/*.html']
"""
import argparse
import glob as globmod
import html
import os
import re

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

ZAMENY = [
    ('поставка оригинальных мотор-редукторов под заказ', 'оригинальные мотор-редукторы под заказ'),
    ('поставка оригинальных редукторов под заказ',       'оригинальные редукторы под заказ'),
    ('Поставка оригинальных',                            'Оригинальные'),
    ('поставка оригиналов под заказ',                    'оригинал под заказ'),
    ('подбор по модели или по шильдику',                 'подбор по модели или шильду'),
    ('Цена и наличие по запросу',                        'Цена по запросу'),
    ('гарантия 24 месяца',                               'гарантия 24 мес'),
    ('подбор по модели или шильду',                      'подбор по шильду'),
    (', отгрузка от 3 дн.',                              '.'),
    (', отгрузка.',                                      '.'),
]
RX = re.compile(r'(<meta[^>]+name="description"[^>]+content=")([^"]*)(")')


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--dry', action='store_true')
    ap.add_argument('--limit', type=int, default=160)
    ap.add_argument('--glob', default='brands/*.html')
    args = ap.parse_args()

    tronuto, ne_vlezli = 0, []
    for path in sorted(globmod.glob(os.path.join(ROOT, args.glob))):
        with open(path, encoding='utf-8') as f:
            s = f.read()
        m = RX.search(s)
        if not m:
            continue
        val = m.group(2)
        if len(html.unescape(val)) <= args.limit:
            continue
        bylo = len(html.unescape(val))
        for a, b in ZAMENY:
            if len(html.unescape(val)) <= args.limit:
                break
            if a in val:
                val = val.replace(a, b, 1)
        stalo = len(html.unescape(val))
        rel = os.path.relpath(path, ROOT)
        if stalo > args.limit:
            ne_vlezli.append((rel, stalo))
        print(f'  {rel:30} {bylo} → {stalo}')
        s = s[:m.start(2)] + val + s[m.end(2):]
        tronuto += 1
        if not args.dry:
            with open(path, 'w', encoding='utf-8') as f:
                f.write(s)

    print(f'{"проверено" if args.dry else "укорочено"} описаний: {tronuto}')
    for rel, n in ne_vlezli:
        print(f'  ПЕРЕПИСАТЬ РУКАМИ: {rel} — осталось {n} знаков')


if __name__ == '__main__':
    main()
