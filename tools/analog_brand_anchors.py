#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Анкоры ссылок на страницу бренда — со словом «редукторы» и кириллицей.

Каждая из 73 899 карточек ссылается на страницу своей марки дважды: текстовой ссылкой
«все модели Bauer и аналоги» и кнопкой «Все редукторы бренда →». Это десятки тысяч
анкоров — один из самых весомых внутренних сигналов, — и ни в одном из них нет ни
кириллического написания марки, ни, у первой ссылки, слова «редукторы». Запрос же
звучит как «редуктор бауер».

Правятся оба анкора:
    «все модели Bauer и аналоги»  →  «все редукторы Bauer (Бауер) и аналоги»
    «Все редукторы бренда →»      →  «Все редукторы Bauer (Бауер) →»

Кнопка — это .btn, а у него в стилях white-space:nowrap: длинная подпись не
переносится, а вылезает за край экрана. Поэтому скрипт считает длину и, если подпись
для этой марки выходит слишком длинной, оставляет кнопке прежний короткий текст.
Текстовая ссылка переносится свободно, её правим всегда.

Марки — только те тринадцать, что назвал заказчик. Остальные не трогаем.

Запуск:  python3 tools/analog_brand_anchors.py [--dry]
"""
import argparse
import os
import re

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DIR = os.path.join(ROOT, 'analog')
# Столько знаков подписи укладывается в экран 360 px при nowrap. Порог не угадан,
# а замерен в браузере: 31 знак даёт ширину 304 px и переполнения нет, 32 — 322 px и
# тоже нет, 35 — 337 px и страница уже получает горизонтальную прокрутку.
KNOPKA_MAX = 32

# Имя марки в том виде, в каком она пишется на сайте. Раньше оно вытаскивалось
# регулярным выражением из соседней ссылки, но та к этому моменту уже была переписана,
# и в подпись попадала заглушка из адреса — «BAUER» вместо «Bauer». Держим списком.
LAT = {
    'sew': 'SEW-Eurodrive', 'bonfiglioli': 'Bonfiglioli', 'nord': 'NORD',
    'motovario': 'Motovario', 'innovari': 'Innovari', 'lenze': 'Lenze',
    'bauer': 'Bauer', 'siti': 'SITI', 'varvel': 'Varvel', 'rossi': 'Rossi',
    'keb': 'KEB', 'siemens': 'Siemens', 'stm': 'STM',
}
CYR = {
    'sew': 'СЕВ Евродрайв', 'bonfiglioli': 'Бонфиглиоли', 'nord': 'НОРД',
    'motovario': 'Мотоварио', 'innovari': 'Инновари', 'lenze': 'Лензе',
    'bauer': 'Бауер', 'siti': 'Сити', 'varvel': 'Варвел', 'rossi': 'Росси',
    'keb': 'КЕБ', 'siemens': 'Сименс', 'stm': 'СТМ',
}

SSYLKA = re.compile(r'(<a href="/brands/([a-z-]+)">)все модели ([^<]+?) и аналоги(</a>)')
KNOPKA = re.compile(r'(<a class="btn ghost" href="/brands/([a-z-]+)">)Все редукторы (?:бренда|[A-Za-z-]+ \([^)]*\)) →(</a>)')


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--dry', action='store_true')
    args = ap.parse_args()

    tekst = knopok = dlinnyh = fajlov = 0
    faily = sorted(f for f in os.listdir(DIR) if f.endswith('.html'))
    for f in faily:
        slug = f.split('-')[0]
        if slug not in CYR:
            continue
        path = os.path.join(DIR, f)
        s = open(path, encoding='utf-8', errors='ignore').read()
        ishodnyy = s

        def pr_ssylka(m):
            nonlocal tekst
            sl, imya = m.group(2), m.group(3)
            cyr = CYR.get(sl)
            if not cyr or cyr in m.group(0):
                return m.group(0)
            tekst += 1
            return f'{m.group(1)}все редукторы {imya} ({cyr}) и аналоги{m.group(4)}'

        def pr_knopka(m):
            nonlocal knopok, dlinnyh
            sl = m.group(2)
            cyr = CYR.get(sl)
            if not cyr:
                return m.group(0)
            podpis = f'Все редукторы {LAT[sl]} ({cyr}) →'
            if len(podpis) > KNOPKA_MAX:
                dlinnyh += 1
                return m.group(0)
            knopok += 1
            return f'{m.group(1)}{podpis}{m.group(3)}'

        s = SSYLKA.sub(pr_ssylka, s)
        s = KNOPKA.sub(pr_knopka, s)
        if s != ishodnyy:
            fajlov += 1
            if not args.dry:
                open(path, 'w', encoding='utf-8').write(s)

    print(f'{"будет правлено" if args.dry else "правлено"} карточек: {fajlov}')
    print(f'  текстовых анкоров: {tekst}')
    print(f'  подписей кнопок:   {knopok}')
    print(f'  кнопок оставлено как было (подпись длиннее {KNOPKA_MAX} знаков): {dlinnyh}')


if __name__ == '__main__':
    main()
