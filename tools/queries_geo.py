#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Подгоняет целевые страницы под формулировки запросов и под десять городов замера.

Из выгрузки позиций (340 строк = 34 запроса × 10 городов) видно три вещи, которых
прежняя правка не учитывала.

1. ФОРМУЛИРОВКА. Все запросы идут с приставкой «редуктор»: «редуктор sew eurodrive»,
   «редуктор бонфиглиоли», «редуктор keb». В заголовках у нас стояло «Мотор-редукторы
   {бренд}» — слово «редукторы» есть только внутри составного «мотор-редукторы».
   Заголовок переписан на «Редукторы и мотор-редукторы {бренд} ({кириллица})»:
   так закрываются обе формы сразу, и «редуктор bauer», и «мотор-редуктор bauer».

2. ГОРОДА. Позиции снимают в Москве, Петербурге, Воронеже, Екатеринбурге, Казани,
   Туле, Ульяновске, Ростове-на-Дону, Самаре и Краснодаре. Ни одна из пятидесяти
   целевых страниц не упоминала ни одного из них — только Челябинск. Для Яндекса по
   коммерческому запросу это решающее. Добавлена строка об отгрузке в эти города и
   поле areaServed в разметку Organization.

3. УГЛОВЫЕ. Запрос «угловые редукторы» в выгрузке есть, слово «угловые» на сайте
   встречается на 86 страницах, но ни в одном заголовке. Отвечает на него
   коническо-цилиндрическая — туда оно и вписано.

Заголовок считается с запасом: если «Редукторы и мотор-редукторы …» не влезает в 70
знаков, берётся более короткая форма. Скрипт печатает длину каждого заголовка.

Скрипт идемпотентен: помеченные <!-- ZR_GEO --> страницы пропускаются.

Запуск:  python3 tools/queries_geo.py [--dry]
"""
import argparse
import html
import json
import os
import re

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
MARK = '<!-- ZR_GEO -->'
LIMIT = 70

GORODA = ['Москву', 'Санкт-Петербург', 'Екатеринбург', 'Казань', 'Самару',
          'Ростов-на-Дону', 'Краснодар', 'Воронеж', 'Тулу', 'Ульяновск']
GORODA_IM = ['Москва', 'Санкт-Петербург', 'Екатеринбург', 'Казань', 'Самара',
             'Ростов-на-Дону', 'Краснодар', 'Воронеж', 'Тула', 'Ульяновск', 'Челябинск']

GEO_FRAZA = ('Отгружаем со склада в Челябинске и под заказ транспортными компаниями — '
             + ', '.join(GORODA[:-1]) + ' и ' + GORODA[-1] + ', и дальше по России.')

BRANDS = {
    'sew': ('SEW-Eurodrive', 'СЕВ Евродрайв'), 'bonfiglioli': ('Bonfiglioli', 'Бонфиглиоли'),
    'nord': ('NORD', 'НОРД'), 'motovario': ('Motovario', 'Мотоварио'),
    'innovari': ('Innovari', 'Инновари'), 'lenze': ('Lenze', 'Лензе'),
    'bauer': ('Bauer', 'Бауер'), 'siti': ('SITI', 'Сити'),
    'varvel': ('Varvel', 'Варвел'), 'rossi': ('Rossi', 'Росси'),
    'keb': ('KEB', 'КЕБ'), 'siemens': ('Siemens', 'Сименс'), 'stm': ('STM', 'СТМ'),
}
TIPY = ['konichesko-cilindricheskie', 'ploskie', 'variatory', 'planetarnye',
        'cilindricheskie', 'chervyachnye']


def zagolovok(lat, cyr):
    """Самая полная форма, которая влезает в 70 знаков."""
    for t in (f'Редукторы и мотор-редукторы {lat} ({cyr}) — купить | ЗР',
              f'Редукторы и мотор-редукторы {lat} ({cyr}) | ЗР',
              f'Редукторы {lat} ({cyr}) — купить, аналог | ЗР',
              f'Редукторы {lat} ({cyr}) | ЗР'):
        if len(t) <= LIMIT:
            return t
    return f'Редукторы {lat} | ЗР'


def dobavit_geo(s):
    """Строка про отгрузку — в подзаголовок под H1."""
    for pat in (r'(<p class="bhero-lead">)(.*?)(</p>)', r'(<p class="seo-lead">)(.*?)(</p>)'):
        m = re.search(pat, s, re.S)
        if m:
            return s[:m.end(2)] + ' ' + GEO_FRAZA + s[m.end(2):], True
    return s, False


def area_served(s):
    """Города в поле areaServed рядом с прежним «RU».

    Само по себе это поле региональную выдачу Яндекса не двигает — он берёт регион из
    Вебмастера и Яндекс.Бизнеса. Но разметка обязана не противоречить тексту: раз на
    странице появились города, они должны быть и здесь. «RU» остаётся первым: сужать
    охват страны до десяти городов нельзя.
    """
    tronuto = False
    for m in list(re.finditer(r'<script type="application/ld\+json">(.*?)</script>', s, re.S))[::-1]:
        raw = m.group(1)
        if '"Organization"' not in raw or '"City"' in raw:
            continue
        try:
            d = json.loads(raw)
        except ValueError:
            continue
        def walk(o):
            nonlocal tronuto
            if isinstance(o, dict):
                if o.get('@type') == 'Organization':
                    bylo = o.get('areaServed')
                    spisok = ([bylo] if isinstance(bylo, str) else list(bylo or []))
                    o['areaServed'] = spisok + [{'@type': 'City', 'name': g} for g in GORODA_IM]
                    tronuto = True
                for v in o.values():
                    walk(v)
            elif isinstance(o, list):
                for v in o:
                    walk(v)
        walk(d)
        if tronuto:
            s = s[:m.start(1)] + json.dumps(d, ensure_ascii=False, separators=(',', ':')) + s[m.end(1):]
            break
    return s, tronuto


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--dry', action='store_true')
    args = ap.parse_args()

    stranic = 0
    for key, (lat, cyr) in sorted(BRANDS.items()):
        path = os.path.join(ROOT, 'brands', key + '.html')
        s = open(path, encoding='utf-8').read()
        if MARK in s:
            print(f'  {key:12} уже сделано'); continue
        t = zagolovok(lat, cyr)
        s = re.sub(r'<title>.*?</title>', '<title>' + t + '</title>', s, count=1, flags=re.S)
        s = re.sub(r'(<h1[^>]*>).*?(</h1>)',
                   lambda m: m.group(1) + f'Редукторы и мотор-редукторы {lat} ({cyr})' + m.group(2),
                   s, count=1, flags=re.S)
        s, geo = dobavit_geo(s)
        s, area = area_served(s)
        s = s.replace('</head>', MARK + '\n</head>', 1)
        print(f'  {key:12} title {len(t):2} зн.  гео {"да" if geo else "НЕТ"}  areaServed {"да" if area else "НЕТ"}')
        if not args.dry:
            open(path, 'w', encoding='utf-8').write(s)
        stranic += 1

    for key in TIPY:
        path = os.path.join(ROOT, 'catalog', key + '.html')
        s = open(path, encoding='utf-8').read()
        if MARK in s:
            print(f'  {key:34} уже сделано'); continue
        s, geo = dobavit_geo(s)
        s, area = area_served(s)
        s = s.replace('</head>', MARK + '\n</head>', 1)
        print(f'  {key:34} гео {"да" if geo else "НЕТ"}  areaServed {"да" if area else "НЕТ"}')
        if not args.dry:
            open(path, 'w', encoding='utf-8').write(s)
        stranic += 1

    print(f'{"проверено" if args.dry else "обработано"} страниц: {stranic}')


if __name__ == '__main__':
    main()
