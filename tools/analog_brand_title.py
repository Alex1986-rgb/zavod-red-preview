#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Заголовок карточки — про оригинальный бренд, а не про наш аналог.

Было: «Bauer BF 06 0,12 кВт i=10,42, 8,7 Н·м — аналог ZR 636, купить | ЗР».
Человек ищет «редуктор bauer» — и видит в выдаче слово «аналог» раньше, чем понимает,
что оригинал у нас тоже есть. Мы поставляем и оригинал под заказ, и свою замену;
заголовок должен говорить о том, что искали, а замену предлагает уже сама страница.

В H1 и в описании это и так сказано правильно — «оригинал под заказ · замена ZR 636».
Расходился только заголовок и og:title. Их и правим, больше ничего.

Хвост подбирается самый полный из тех, что влезают в 70 знаков, которые Яндекс
показывает в выдаче:
    — оригинал под заказ, купить | ЗР
    — оригинал под заказ | ЗР
    — оригинал, купить | ЗР
    — купить, цена | ЗР
    — купить | ЗР
    | ЗР

Про уникальность. Маркировка ZR в прежнем заголовке кое-где была единственным, что
отличало две карточки друг от друга: у клонов совпадают и модель, и мощность, и
момент. Поэтому скрипт работает в два прохода: сначала считает предлагаемые
заголовки, потом возвращает маркировку тем, у кого иначе вышел бы дубль. Дублей в
заголовках после правки быть не должно — это проверяется и печатается.

Запуск:  python3 tools/analog_brand_title.py [--dry]
"""
import argparse
import collections
import html
import os
import re

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DIR = os.path.join(ROOT, 'analog')
LIMIT = 70

TITLE = re.compile(r'<title>(.*?)</title>', re.S)
CANON = re.compile(r'<link rel="canonical" href="[^"]*/analog/([^"/]+)"')
OG = re.compile(r'(<meta property="og:title" content=")([^"]*)(")')
# хвосты, которые проставил прежний скрипт
HVOST = re.compile(r'\s*—\s*аналог\s+(ZR[^,|<]*?)(?:,\s*купить)?(?:\s*\|\s*ЗР)?\s*$')

VARIANTY = (' — оригинал под заказ, купить | ЗР',
            ' — оригинал под заказ | ЗР',
            ' — оригинал, купить | ЗР',
            ' — купить, цена | ЗР',
            ' — купить | ЗР',
            ' | ЗР')


def razobrat(t):
    """(голова, маркировка ZR) или None, если хвост не наш."""
    m = HVOST.search(t)
    if not m:
        return None
    return t[:m.start()].rstrip(), m.group(1).strip()


def sobrat(golova, zr=None):
    baza = golova + (f' — аналог {zr}' if zr else '')
    for v in VARIANTY:
        if len(baza + v) <= LIMIT:
            return baza + v
    return baza + ' | ЗР'


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--dry', action='store_true')
    args = ap.parse_args()

    faily = sorted(f for f in os.listdir(DIR) if f.endswith('.html'))
    plan, propusk = {}, 0

    # проход 1 — что получилось бы без маркировки
    for f in faily:
        s_full = open(os.path.join(DIR, f), encoding='utf-8', errors='ignore').read(6000)
        m = TITLE.search(s_full)
        if not m:
            propusk += 1; continue
        t = html.unescape(m.group(1))
        r = razobrat(t)
        if not r:
            propusk += 1; continue
        # Уникальность считаем только среди страниц, чей canonical ведёт на себя.
        # У клонов (файлы с суффиксом -1, -2) canonical указывает на базовую карточку,
        # и совпадение заголовков там не дубль в индексе, а ожидаемое поведение.
        c = CANON.search(s_full)
        svoy = (c is None) or (c.group(1) == f[:-5])
        plan[f] = (r[0], r[1], sobrat(r[0]), svoy)

    # проход 2 — кому маркировку вернуть, чтобы не было дублей
    schet = collections.Counter(v[2] for v in plan.values() if v[3])
    vernuli = 0
    for f, (golova, zr, nov, svoy) in list(plan.items()):
        if svoy and schet[nov] > 1:
            plan[f] = (golova, zr, sobrat(golova, zr), svoy)
            vernuli += 1

    itog = collections.Counter(v[2] for v in plan.values() if v[3])
    dubli = sum(n - 1 for n in itog.values() if n > 1)
    klonov = sum(1 for v in plan.values() if not v[3])

    dlinnye = sum(1 for v in plan.values() if len(v[2]) > LIMIT)
    print(f'карточек разобрано: {len(plan)}, пропущено (чужой формат): {propusk}')
    print(f'маркировка возвращена ради уникальности: {vernuli}')
    print(f'заголовков длиннее {LIMIT} знаков: {dlinnye}')
    print(f'клонов с canonical на базовую карточку (уникальность не требуется): {klonov}')
    print(f'дублей среди самоканоничных заголовков: {dubli}')
    print('примеры:')
    for f in list(plan)[:4]:
        print(f'   {plan[f][2]}')

    if args.dry:
        return
    if dubli:
        raise SystemExit('есть дубли заголовков — не пишу, надо разобраться')

    zapisano = 0
    for f, (_, _, nov, _svoy) in plan.items():
        path = os.path.join(DIR, f)
        s = open(path, encoding='utf-8', errors='ignore').read()
        esc = html.escape(nov, quote=False)
        s = TITLE.sub(lambda _: f'<title>{esc}</title>', s, count=1)
        s = OG.sub(lambda m: m.group(1) + html.escape(nov, quote=True) + m.group(3), s, count=1)
        open(path, 'w', encoding='utf-8').write(s)
        zapisano += 1
    print(f'записано файлов: {zapisano}')


if __name__ == '__main__':
    main()
