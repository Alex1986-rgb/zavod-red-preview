#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Переписывает хвост <title> карточек /analog/: «купить оригинал, цена» → «аналог ZR N».

Зачем: на всех 73 899 карточках хвост заголовка был дословно один и тот же —
«— купить оригинал, цена | ЗР». Уникальной оставалась только голова (модель, мощность,
передаточное), а половина видимой в выдаче строки повторялась по всему разделу. Заодно
из заголовков пропало слово «аналог», хотя карточка в первую очередь про замену: её и
ищут — «аналог Bauer BK 20», «замена ZR 838».

Маркировка ZR берётся со страницы (строка «Замена» в характеристиках), а не угадывается.

Хвост подбирается по длине: заголовок длиннее 70 знаков Яндекс обрезает, поэтому у
длинных моделей хвост укорачивается, а не режется поиском посередине слова.

og:title меняется вместе с title — они должны совпадать.

Скрипт идемпотентен: повторный запуск пересчитывает тот же хвост.

Запуск:  python3 tools/analog_retitle.py [--dry] [--limit N] [--brand bauer]
"""
import argparse
import collections
import glob
import html
import os
import re

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
ANALOG = os.path.join(ROOT, 'analog')
LIMIT = 70                                   # дальше поиск обрезает заголовок

TITLE = re.compile(r'(<title>)([^<]*)(</title>)')
OGT = re.compile(r'(<meta property="og:title" content=")([^"]*)(")')
ZR = re.compile(r'<span class="k">Замена</span><span class="v">(ZR[^<]*)</span>')
# что считаем «хвостом»: старый вариант и уже переписанный
TAIL = re.compile(r'\s+—\s+(?:купить оригинал, цена|аналог ZR[^|—]*?)(?:\s*\|\s*ЗР)?\s*$')


def tail_for(head, zr):
    """Самый полный хвост, который ещё влезает в 70 знаков вместе с головой."""
    for tail in (f' — аналог {zr}, купить | ЗР',
                 f' — аналог {zr} | ЗР',
                 f' — аналог {zr}'):
        if len(head) + len(tail) <= LIMIT:
            return tail
    return f' — аналог {zr}'                 # голова сама длиннее лимита — резать нечего


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--dry', action='store_true')
    ap.add_argument('--limit', type=int)
    ap.add_argument('--brand')
    args = ap.parse_args()

    files = sorted(glob.glob(os.path.join(ANALOG, '*.html')))
    if args.brand:
        files = [f for f in files if os.path.basename(f).startswith(args.brand)]
    if args.limit:
        files = files[:args.limit]

    stat = collections.Counter()
    lens = []
    samples = []
    for path in files:
        with open(path, encoding='utf-8', errors='ignore') as f:
            text = f.read()
        t = TITLE.search(text)
        z = ZR.search(text)
        if not t:
            stat['без title'] += 1
            continue
        if not z:
            stat['нет маркировки ZR на странице'] += 1
            continue
        old = html.unescape(t.group(2))
        m = TAIL.search(old)
        if not m:
            stat['хвост незнакомый — не трогаем'] += 1
            continue
        head = old[:m.start()]
        new_title = head + tail_for(head, z.group(1).strip())
        lens.append(len(new_title))
        if len(samples) < 5 and old != new_title:
            samples.append((old, new_title))
        if new_title == old:
            stat['уже такой'] += 1
            continue
        esc = html.escape(new_title, quote=True)
        out = TITLE.sub(lambda mm: mm.group(1) + esc + mm.group(3), text, count=1)
        out = OGT.sub(lambda mm: mm.group(1) + esc + mm.group(3), out, count=1)
        stat['переписано'] += 1
        if not args.dry:
            with open(path, 'w', encoding='utf-8') as f:
                f.write(out)

    print(f'файлов просмотрено: {len(files)}')
    for k, v in stat.most_common():
        print(f'  {v:7}  {k}')
    if lens:
        lens.sort()
        print(f'\nдлина нового title: медиана {lens[len(lens) // 2]}, '
              f'макс {lens[-1]}, длиннее {LIMIT}: {sum(1 for l in lens if l > LIMIT)}')
    for a, b in samples:
        print(f'\n  было: {a}\n  стало: {b}')


if __name__ == '__main__':
    main()
