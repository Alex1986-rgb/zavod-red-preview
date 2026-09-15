#!/usr/bin/env python3
"""Чинит подписи alt, которые обещают чертёж там, где в галерее стоит фотография.

Зачем: в карточках /analog/ часть слайдов галереи подписана «{модель} — габаритный чертёж
{типа} редуктора», хотя в src этого слайда стоит фотография (assets/catalog/br-*.webp или
cat_*.webp), а не чертёж. Для поиска по картинкам это подпись, не соответствующая содержимому,
на десятках тысяч страниц. Настоящие чертежи (assets/drawings/*.png) не трогаются — у них
подпись верная.

Было:   alt="Bauer BF 06 — габаритный чертёж соосно-цилиндрического редуктора"
Стало:  alt="Bauer BF 06 — соосно-цилиндрический мотор-редуктор, фото"

Скрипт идемпотентен: повторный запуск ничего не меняет.

Запуск:  python3 tools/analog_alt_photo.py [--dry]
"""
import glob
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
ANALOG = os.path.join(ROOT, 'analog')
DRY = '--dry' in sys.argv

# родительный падеж в «чертёж <чего> редуктора» → именительный для «<какой> мотор-редуктор»
FORMS = {
    'червячного': 'червячный',
    'соосно-цилиндрического': 'соосно-цилиндрический',
    'коническо-цилиндрического': 'коническо-цилиндрический',
    'плоско-цилиндрического': 'плоско-цилиндрический',
    'цилиндрического': 'цилиндрический',
    'промышленного': 'промышленный',
}

# alt правим только у слайдов-ФОТО: assets/catalog/br-*.webp и cat_*.webp
PHOTO_ALT = re.compile(
    r'(<img src="\.\./assets/catalog/(?:br-[a-z-]+-t\d|cat_[a-z]+)\.webp"[^>]*?alt=")'
    r'([^"]*?) — габаритный чертёж ([а-яё-]+) редуктора(")'
)


def fix(text):
    def sub(m):
        nom = FORMS.get(m.group(3))
        if not nom:
            return m.group(0)
        return f'{m.group(1)}{m.group(2)} — {nom} мотор-редуктор, фото{m.group(4)}'
    return PHOTO_ALT.subn(sub, text)


def main():
    pages = [p for p in sorted(glob.glob(os.path.join(ANALOG, '*.html')))
             if os.path.basename(p) != 'index.html']
    changed = alts = 0
    for path in pages:
        with open(path, encoding='utf-8', errors='ignore') as f:
            text = f.read()
        if 'габаритный чертёж' not in text:
            continue
        new, n = fix(text)
        if not n or new == text:
            continue
        changed += 1
        alts += n
        if not DRY:
            with open(path, 'w', encoding='utf-8') as f:
                f.write(new)
    print(f'карточек просмотрено: {len(pages)}')
    print(f'{"будет исправлено" if DRY else "исправлено"} карточек: {changed}, подписей: {alts}')


if __name__ == '__main__':
    main()
