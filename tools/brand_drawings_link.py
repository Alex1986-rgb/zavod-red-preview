#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Ссылка на габаритные чертежи — со страницы каждого бренда.

На /chertezhi разложены листы по маркам, с готовыми якорями (#sew, #nord, #bauer …).
Габаритные и присоединительные размеры — главный аргумент по запросу «редуктор
{марка} купить»: покупатель проверяет, встанет ли замена на штатное место. Ни одна
страница бренда на этот раздел не ссылалась.

Ссылка встаёт в блок статей, который уже стоит на странице, — рядом с «Все статьи о
редукторах». Отдельную секцию заводить незачем: строка короткая, а лишний блок
растянул бы страницу.

Якорь ставится только тем маркам, у которых он на /chertezhi действительно есть —
у Innovari, KEB и Siemens его нет, им ссылка ведёт на раздел целиком. Проверяется по
файлу, а не по списку: появится якорь — скрипт подхватит сам.

Запуск:  python3 tools/brand_drawings_link.py [--dry]
"""
import argparse
import os
import re

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
MARK = 'zr-drawings-link'
STARAYA = '<p style="margin-top:14px"><a href="/blog/">Все статьи о редукторах →</a></p>'

BRANDS = {
    'sew': 'SEW-Eurodrive', 'bonfiglioli': 'Bonfiglioli', 'nord': 'NORD',
    'motovario': 'Motovario', 'innovari': 'Innovari', 'lenze': 'Lenze',
    'bauer': 'Bauer', 'siti': 'SITI', 'varvel': 'Varvel', 'rossi': 'Rossi',
    'keb': 'KEB', 'siemens': 'Siemens', 'stm': 'STM',
}


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--dry', action='store_true')
    args = ap.parse_args()

    chert = open(os.path.join(ROOT, 'chertezhi.html'), encoding='utf-8').read()
    yakorya = set(re.findall(r'id="([a-z-]+)"', chert))

    for key, lat in sorted(BRANDS.items()):
        path = os.path.join(ROOT, 'brands', key + '.html')
        s = open(path, encoding='utf-8').read()
        if MARK in s:
            print(f'  {key:12} уже есть'); continue
        if STARAYA not in s:
            print(f'  {key:12} блок статей не найден'); continue
        est = key in yakorya
        adres = f'/chertezhi#{key}' if est else '/chertezhi'
        novaya = ('<p style="margin-top:14px" class="' + MARK + '">'
                  '<a href="/blog/">Все статьи о редукторах →</a>'
                  '<span style="padding:0 10px;opacity:.4">·</span>'
                  f'<a href="{adres}">Габаритные и присоединительные чертежи {lat} →</a></p>')
        s = s.replace(STARAYA, novaya, 1)
        print(f'  {key:12} → {adres}{"" if est else "   (якоря нет, ведём на раздел)"}')
        if not args.dry:
            open(path, 'w', encoding='utf-8').write(s)


if __name__ == '__main__':
    main()
