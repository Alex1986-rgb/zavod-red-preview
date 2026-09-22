#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Кириллица рядом со словом «редуктор» — на витрине брендов и в подзаголовках.

Запросы из выгрузки — это не просто «бонфиглиоли», а «редуктор бонфиглиоли». Значит
кириллическое написание должно стоять рядом со словом «редуктор», а не жить отдельно
в служебной фразе «так марку пишут кириллицей».

Две правки.

1. brands/index.html — витрина на 25 марок. Под каждой плиткой стояла одна и та же
   строка «Оригинал под заказ + габаритный аналог», одинаковая все 25 раз. У целевых
   марок она заменена на «Редукторы {кириллица} — оригинал под заказ и аналог»: и
   нужное словосочетание появляется, и 25 одинаковых подписей перестают быть
   одинаковыми. У остальных двенадцати марок строка остаётся прежней.

2. brands/*.html — подзаголовок под H1 начинается с «Редукторы {кириллица}»,
   а пояснение про написание уходит в конец предложения. Раньше кириллица стояла
   только в служебной фразе, то есть не образовывала нужного словосочетания.

Скрипт идемпотентен.

Запуск:  python3 tools/brand_tiles_cyr.py [--dry]
"""
import argparse
import os
import re

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OBSHAYA = '<span class="bc-sub">Оригинал под заказ + габаритный аналог</span>'

# ключ страницы: (как марка записана в <b> на витрине, кириллица)
BRANDS = {
    'sew': ('SEW-Eurodrive', 'СЕВ Евродрайв'), 'bonfiglioli': ('Bonfiglioli', 'Бонфиглиоли'),
    'nord': ('NORD', 'НОРД'), 'motovario': ('Motovario', 'Мотоварио'),
    'innovari': ('Innovari', 'Инновари'), 'lenze': ('Lenze', 'Лензе'),
    'bauer': ('Bauer', 'Бауер'), 'siti': ('SITI', 'Сити'),
    'varvel': ('Varvel', 'Варвел'), 'rossi': ('Rossi', 'Росси'),
    'keb': ('KEB', 'КЕБ'), 'siemens': ('Siemens', 'Сименс'), 'stm': ('STM', 'СТМ'),
}


def vitrina(dry):
    path = os.path.join(ROOT, 'brands', 'index.html')
    s = open(path, encoding='utf-8').read()
    sdelano = []
    for key, (lat, cyr) in BRANDS.items():
        # плитка = ссылка на /brands/<ключ> до закрывающего </a>
        m = re.search(r'<a class="brand-card" href="/brands/%s">.*?</a>' % re.escape(key), s, re.S)
        if not m:
            print(f'  витрина: плитка {key} не найдена'); continue
        plitka = m.group(0)
        if OBSHAYA not in plitka:
            sdelano.append(key + ' (уже)'); continue
        nov = f'<span class="bc-sub">Редукторы {cyr} — оригинал под заказ и аналог</span>'
        s = s[:m.start()] + plitka.replace(OBSHAYA, nov, 1) + s[m.end():]
        sdelano.append(key)
    ostalos = s.count(OBSHAYA)
    print(f'  витрина: подписей заменено {len([x for x in sdelano if "(уже)" not in x])}, '
          f'общих осталось {ostalos}')
    if not dry:
        open(path, 'w', encoding='utf-8').write(s)


def podzagolovki(dry):
    for key, (lat, cyr) in sorted(BRANDS.items()):
        path = os.path.join(ROOT, 'brands', key + '.html')
        s = open(path, encoding='utf-8').read()
        m = re.search(r'(<p class="bhero-lead">)(.*?)(</p>)', s, re.S)
        if not m:
            print(f'  {key:12} подзаголовок не найден'); continue
        txt = m.group(2)
        nachalo = f'Редукторы {cyr}. '
        if txt.startswith(nachalo):
            print(f'  {key:12} уже'); continue
        # Отдельным предложением, а не через тире: в прежнем тексте тире уже есть,
        # и второе в той же фразе читается тяжело.
        novyy = nachalo + txt
        s = s[:m.start(2)] + novyy + s[m.end(2):]
        print(f'  {key:12} подзаголовок начинается с «{nachalo.strip()}»')
        if not dry:
            open(path, 'w', encoding='utf-8').write(s)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--dry', action='store_true')
    args = ap.parse_args()
    vitrina(args.dry)
    podzagolovki(args.dry)


if __name__ == '__main__':
    main()
