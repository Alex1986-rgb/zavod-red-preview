#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Укорачивает слишком длинные <title> в генерируемых разделах ZR.

Зачем: Яндекс показывает примерно 70 знаков заголовка, остальное обрезает многоточием.
В /ispolnenie/ медиана длины 75, за лимит вылезали 6 950 страниц из 8 723; в /tiporazmer/
— 1 417 из 2 238. Обрезается при этом самое ценное — хвост с названием компании, а
пользователь видит «… — купить, цена | Завод Ре…».

Заодно хвост был непоследователен: на части страниц «| Завод Редукторов», на части «| ЗР»,
без всякой системы (в /ispolnenie/ 7 036 против 1 687).

Что делает: подбирает самый полный хвост, который ещё влезает в 70 знаков. Полное имя
компании остаётся везде, где помещается: «завод редукторов» — рабочий поисковый запрос,
выкидывать его из заголовка ради единообразия незачем.

Голова заголовка (марка, мощность, передаточное, момент) не трогается.
og:title меняется вместе с title.

Скрипт идемпотентен.

Запуск:  python3 tools/fix_title_length.py [--dry] [--section ispolnenie]
"""
import argparse
import collections
import glob
import html
import os
import re

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
# /reduktor/ намеренно не в списке: там 117 страниц с осмысленными, написанными
# руками заголовками, и у двадцати из них за 70 знаков вылезает сама голова
# («Мотор-редуктор планетарный 3МП-100 — двухступенчатый, момент 2338–4155 Н·м»).
# Такое чинится переписыванием головы, а не подрезкой хвоста. Запустить на нём
# можно явно: --section reduktor.
SECTIONS = ('ispolnenie', 'tiporazmer', 'motor-reduktor-zr')
LIMIT = 70

TITLE = re.compile(r'(<title>)([^<]*)(</title>)')
OGT = re.compile(r'(<meta property="og:title" content=")([^"]*)(")')
# известные хвосты: и старые, и уже укороченные
TAIL = re.compile(r'\s+—\s+купить(?:,\s*цена)?\s*\|\s*(?:Завод Редукторов|ЗР)\s*$')
VARIANTS = (' — купить, цена | Завод Редукторов',
            ' — купить, цена | ЗР',
            ' — купить | ЗР')


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--dry', action='store_true')
    ap.add_argument('--section')
    args = ap.parse_args()

    sections = (args.section,) if args.section else SECTIONS
    for sec in sections:
        stat = collections.Counter()
        lens = []
        samples = []
        for path in sorted(glob.glob(os.path.join(ROOT, sec, '*.html'))):
            with open(path, encoding='utf-8', errors='ignore') as f:
                text = f.read()
            t = TITLE.search(text)
            if not t:
                stat['без title'] += 1
                continue
            old = html.unescape(t.group(2))
            m = TAIL.search(old)
            if not m:
                stat['хвост незнакомый — не трогаем'] += 1
                continue
            head = old[:m.start()]
            new = next((head + v for v in VARIANTS if len(head) + len(v) <= LIMIT),
                       head + VARIANTS[-1])
            lens.append(len(new))
            if new == old:
                stat['уже влезает'] += 1
                continue
            if len(samples) < 3:
                samples.append((old, new))
            esc = html.escape(new, quote=True)
            out = TITLE.sub(lambda mm: mm.group(1) + esc + mm.group(3), text, count=1)
            out = OGT.sub(lambda mm: mm.group(1) + esc + mm.group(3), out, count=1)
            stat['укорочено'] += 1
            if not args.dry:
                with open(path, 'w', encoding='utf-8') as f:
                    f.write(out)
        lens.sort()
        over = sum(1 for l in lens if l > LIMIT)
        print(f'{sec:20} ' + '  '.join(f'{k}: {v}' for k, v in stat.most_common()))
        if lens:
            print(f'{"":20} длина после: медиана {lens[len(lens) // 2]}, макс {lens[-1]}, '
                  f'длиннее {LIMIT}: {over}')
        for a, b in samples:
            print(f'{"":20}   было  [{len(a)}] {a}\n{"":20}   стало [{len(b)}] {b}')


if __name__ == '__main__':
    main()
