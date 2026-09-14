#!/usr/bin/env python3
"""Приводит тип редуктора в подписях слайдов галереи к типу самого изделия.

Зачем: в карточках /analog/ подписи слайдов собирались отдельно от данных карточки и у трети
слайдов называют не тот тип передачи: у Bauer BF 06 главное фото подписано «плоско-цилиндрический
мотор-редуктор», а соседние слайды — «соосно-цилиндрический» или «червячный». Для поиска по
картинкам это подпись, противоречащая товару, на десятках тысяч страниц.

Источник истины — alt главного фото (<img id="p2img">): он собран из данных карточки и совпадает
с H1, title и таблицей характеристик. Тип из него подставляется в подписи слайдов; остальной
текст подписи (вид сбоку, в упаковке, фото) сохраняется.

Чертежи (assets/drawings/*.png) не трогаются — у них своя подпись.
Главное фото (<img id="p2img">) — источник истины, поэтому тоже не трогается.

Скрипт идемпотентен: повторный запуск ничего не меняет.

Запуск:  python3 tools/analog_alt_type.py [--dry]
"""
import glob
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
ANALOG = os.path.join(ROOT, 'analog')
DRY = '--dry' in sys.argv

MAIN = re.compile(r'<img id="p2img"[^>]*alt="[^"]*?— ([а-яё-]+) мотор-редуктор')
SLIDE = re.compile(
    r'(<img src="\.\./assets/(?:catalog/(?:br-[a-z-]+-t\d|cat_[a-z]+)|cards-photo/[a-z0-9-]+)\.webp(?:\?v=\d+)?"[^>]*?alt="[^"]*?— )'
    r'([а-яё-]+)( мотор-редуктор)'
)


def main():
    pages = [p for p in sorted(glob.glob(os.path.join(ANALOG, '*.html')))
             if os.path.basename(p) != 'index.html']
    changed = slides = nomain = 0

    for path in pages:
        with open(path, encoding='utf-8', errors='ignore') as f:
            text = f.read()
        m = MAIN.search(text)
        if not m:
            nomain += 1
            continue
        kind = m.group(1)

        n = 0
        def sub(s):
            nonlocal n
            if s.group(2) == kind:
                return s.group(0)
            n += 1
            return s.group(1) + kind + s.group(3)

        new = SLIDE.sub(sub, text)
        if not n:
            continue
        changed += 1
        slides += n
        if not DRY:
            with open(path, 'w', encoding='utf-8') as f:
                f.write(new)

    print(f'карточек просмотрено: {len(pages)} | без главного alt: {nomain}')
    print(f'{"будет исправлено" if DRY else "исправлено"} карточек: {changed}, подписей: {slides}')


if __name__ == '__main__':
    main()
