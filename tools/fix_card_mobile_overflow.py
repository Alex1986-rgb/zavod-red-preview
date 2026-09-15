#!/usr/bin/env python3
"""Убирает горизонтальную прокрутку на карточках при узком экране.

Зачем: на мобильном карточка раскладывается в одну колонку правилом
.p2{grid-template-columns:1fr}. Но 1fr — это minmax(auto,1fr), а auto-минимум равен
min-content содержимого. Ленту миниатюр (.p2-thumbs) распирает на ширину всех кнопок
сразу — на карточках аналогов это 4 кнопки по 92px плюс отступы, то есть 398px, — и
колонка раздувается шире контейнера. Замерено в браузере: страница 422px при экране
390px, вся карточка уезжает вбок, у посетителя появляется горизонтальная прокрутка.

Лечится минимумом в ноль: minmax(0,1fr) разрешает колонке сжиматься до ширины экрана,
а лента миниатюр листается внутри себя, как и задумано (у неё overflow-x:auto).
Замерено после правки: 390px при экране 390px.

Скрипт идемпотентен: повторный запуск ничего не меняет.

Запуск:  python3 tools/fix_card_mobile_overflow.py [--dry]
"""
import glob
import os
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DRY = '--dry' in sys.argv
FAMILIES = ('analog', 'reduktor', 'motor-reduktor-zr', 'ispolnenie', 'tiporazmer')

OLD = '.p2{grid-template-columns:1fr}'
NEW = '.p2{grid-template-columns:minmax(0,1fr)}'


def main():
    changed = total = seen = 0
    for family in FAMILIES:
        for path in glob.glob(os.path.join(ROOT, family, '*.html')):
            with open(path, encoding='utf-8', errors='ignore') as f:
                text = f.read()
            n = text.count(OLD)
            if not n:
                continue
            seen += 1
            total += n
            changed += 1
            if not DRY:
                with open(path, 'w', encoding='utf-8') as f:
                    f.write(text.replace(OLD, NEW))
    print(f'карточек с правилом: {seen}')
    print(f'{"будет исправлено" if DRY else "исправлено"}: {changed} файлов, {total} правил')


if __name__ == '__main__':
    main()
