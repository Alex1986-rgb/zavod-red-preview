#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Раскидывает города отгрузки по всему сайту — одной строкой в сквозном футере.

Позиции снимают по десяти городам, а упоминания городов были только на главной и на
девятнадцати страницах брендов и типов. Футер стоит на 95 128 страницах: одна строка
в нём закрывает весь сайт разом, включая 73 900 карточек аналогов, куда посетитель
чаще всего и приходит из поиска.

Строка встаёт в первую колонку, следом за «Отгрузка по всей России» — то есть
уточняет уже сказанное, а не появляется отдельным пятном.

Города только те, по которым замеряют, плюс Челябинск — там производство и склад.
Раздувать список до всех городов России смысла нет: это выглядело бы как набивка.

Скрипт идемпотентен.

Запуск:  python3 tools/footer_geo.py [--dry]
"""
import argparse
import os
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

STARO = 'Отгрузка по всей России.'
NOVO = ('Отгрузка по всей России. Возим в Москву, Санкт-Петербург, Екатеринбург, '
        'Казань, Самару, Ростов-на-Дону, Краснодар, Воронеж, Тулу и Ульяновск — '
        'со склада в Челябинске и под заказ.')


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--dry', action='store_true')
    args = ap.parse_args()

    pravleno = uzhe = net = 0
    po_razdelam = {}
    for kat, _, faily in os.walk(ROOT):
        rel = os.path.relpath(kat, ROOT)
        if rel.startswith(('dist', '.git', 'node_modules')):
            continue
        for f in faily:
            if not f.endswith('.html'):
                continue
            path = os.path.join(kat, f)
            with open(path, encoding='utf-8') as fh:
                s = fh.read()
            if NOVO in s:
                uzhe += 1; continue
            if STARO not in s:
                net += 1; continue
            s = s.replace(STARO, NOVO, 1)
            if not args.dry:
                with open(path, 'w', encoding='utf-8') as fh:
                    fh.write(s)
            pravleno += 1
            r = rel.split(os.sep)[0] if rel != '.' else 'корень'
            po_razdelam[r] = po_razdelam.get(r, 0) + 1

    print(f'{"будет исправлено" if args.dry else "исправлено"} страниц: {pravleno}')
    for r, n in sorted(po_razdelam.items(), key=lambda kv: -kv[1]):
        print(f'   {r:22} {n:6}')
    print(f'уже с городами: {uzhe}; без этой строки в футере: {net}')
    if pravleno == 0 and uzhe == 0:
        print('НИ ОДНОЙ страницы не затронуто — текст футера изменился, проверь STARO')
        sys.exit(1)


if __name__ == '__main__':
    main()
