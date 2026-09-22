#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Хабы импорта: в заголовке — бренд и оригинал, замену предлагаем в тексте.

Заказчик поправил позиционирование: мы поставляем не только свой аналог, но и
оригинал под заказ. Значит в заголовке и описании должна стоять марка, которую
ищут, а замену предлагает уже сама страница.

Что правится:
  catalog/importnye-motor-reduktory  заголовок был 76 знаков — длиннее того, что
                                     Яндекс показывает; и «аналог ZR» стояло раньше
                                     слова «оригинал»;
  analog/index                       хаб аналогов: марки вынесены вперёд, оригинал
                                     назван наравне с заменой;
  brands/index                       кириллические написания марок — их на хабах не
                                     было ни одного.

Страница importozameshchenie не трогается: она именно про замену импорта, там слово
«аналоги» по делу.

Запуск:  python3 tools/hub_brand_first.py [--dry]
"""
import argparse
import html
import os
import re

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
LIMIT = 70

PRAVKI = {
    'catalog/importnye-motor-reduktory.html': [
        ('<title>Импортные мотор-редукторы — каталог, оригинал и аналог ZR | Завод Редукторов</title>',
         '<title>Импортные мотор-редукторы — оригинал под заказ и аналог | ЗР</title>'),
        ('Каталог импортных мотор-редукторов с фильтром по бренду, типу и стране: SEW, NORD, Bonfiglioli, Motovario, Bauer и др.',
         'Импортные мотор-редукторы SEW-Eurodrive (СЕВ Евродрайв), NORD (НОРД), Bonfiglioli (Бонфиглиоли), Motovario, Bauer — оригинал под заказ и аналог ZR.'),
    ],
    'analog/index.html': [
        ('<title>Аналоги импортных редукторов SEW, NORD, Bonfiglioli | ЗР</title>',
         '<title>Редукторы SEW, NORD, Bonfiglioli — оригинал и аналог | ЗР</title>'),
        ('Российские аналоги импортных редукторов SEW EURODRIVE, NORD, Bonfiglioli, Motovario.',
         'Редукторы SEW-Eurodrive (СЕВ Евродрайв), NORD (НОРД), Bonfiglioli (Бонфиглиоли), Motovario (Мотоварио): оригинал под заказ и российский аналог ZR.'),
    ],
    'brands/index.html': [
        ('Поставка оригинальных импортных редукторов и аналогов под заказ: SEW, NORD, Bonfiglioli, Motovario, Flender',
         'Оригинальные импортные редукторы под заказ и аналоги: SEW-Eurodrive (СЕВ Евродрайв), NORD (НОРД), Bonfiglioli (Бонфиглиоли), Motovario (Мотоварио)'),
    ],
}


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--dry', action='store_true')
    args = ap.parse_args()

    for rel, pary in PRAVKI.items():
        path = os.path.join(ROOT, rel)
        s = open(path, encoding='utf-8').read()
        sdelano = 0
        for staroe, novoe in pary:
            if novoe in s:
                continue
            if staroe not in s:
                print(f'  {rel:42} не найдено: {staroe[:50]}'); continue
            s = s.replace(staroe, novoe, 1)
            sdelano += 1
        t = html.unescape(re.search(r'<title>(.*?)</title>', s, re.S).group(1))
        d = re.search(r'name="description" content="([^"]*)"', s)
        dl = len(html.unescape(d.group(1))) if d else 0
        assert len(t) <= LIMIT, f'{rel}: заголовок {len(t)} знаков'
        print(f'  {rel:42} правок {sdelano}  title {len(t)} зн.  description {dl} зн.')
        if not args.dry:
            open(path, 'w', encoding='utf-8').write(s)


if __name__ == '__main__':
    main()
