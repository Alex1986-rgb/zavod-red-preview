#!/usr/bin/env python3
"""Ставит в галерею карточек /analog/ реальные фото бренда вместо стоковых картинок категорий.

Зачем: в галерее карточки первый слайд — брендированное фото модели (assets/cards-photo), а
остальные слайды у части брендов забиты общими стоковыми снимками assets/catalog/cat_*.webp —
одна и та же картинка на десятки тысяч страниц. При этом фотографии этих же брендов
(assets/catalog/br-<бренд>-t<N>.webp) лежат на диске и просто не подставлены: у bauer, rossi,
lenze, yilmaz, stm, watt, tramec стоком забита вся галерея, у остальных — хвостовые слайды.

Скрипт подставляет доступные фото бренда в слоты «Фото N» (N ≥ 2), занятые стоком, не повторяя
одно фото дважды на странице. Если фото бренда закончились, слот остаётся со стоком.
Главный слайд (p2-main) и слайд с чертежом не трогаются.

Скрипт идемпотентен: повторный запуск ничего не меняет.

Запуск:  python3 tools/analog_brand_photos.py [--dry]
"""
import collections
import glob
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
ANALOG = os.path.join(ROOT, 'analog')
CATALOG = os.path.join(ROOT, 'assets', 'catalog')
DRY = '--dry' in sys.argv

THUMBS = re.compile(r'<div class="p2-thumbs">.*?</div>', re.S)
BUTTON = re.compile(r'<button\b.*?</button>', re.S)
STOCK = re.compile(r'\.\./assets/catalog/cat_[a-z]+\.webp')
BRAND_PHOTO = re.compile(r'\.\./assets/catalog/(br-[a-z-]+-t\d)\.webp')
PHOTO_SLOT = re.compile(r'aria-label="Фото \d+"')


def brand_photos():
    """{бренд: [br-…-t0.webp, …]} — по тому, что реально лежит на диске."""
    out = collections.defaultdict(list)
    for name in sorted(os.listdir(CATALOG)):
        m = re.match(r'br-([a-z-]+)-t\d\.webp$', name)
        if m:
            out[m.group(1)].append(name)
    return dict(out)


def fix_page(text, photos):
    """Подставляет фото бренда в стоковые слоты галереи. Возвращает (новый текст, сколько слотов)."""
    block = THUMBS.search(text)
    if not block:
        return text, 0
    old = block.group(0)
    used = set(BRAND_PHOTO.findall(old))
    spare = [p for p in photos if p[:-5] not in used]
    if not spare:
        return text, 0

    filled = 0

    def swap(m):
        nonlocal filled
        button = m.group(0)
        if not PHOTO_SLOT.search(button) or not STOCK.search(button):
            return button
        if not spare:
            return button
        photo = spare.pop(0)
        filled += 1
        return STOCK.sub(f'../assets/catalog/{photo}', button)

    new = BUTTON.sub(swap, old)
    if new == old:
        return text, 0
    return text.replace(old, new, 1), filled


def main():
    photos = brand_photos()
    order = sorted(photos, key=len, reverse=True)
    pages = sorted(glob.glob(os.path.join(ANALOG, '*.html')))

    changed = 0
    slots = 0
    per_brand = collections.Counter()

    for path in pages:
        name = os.path.basename(path)[:-5]
        brand = next((b for b in order if name.startswith(b + '-')), None)
        if not brand:
            continue
        with open(path, encoding='utf-8', errors='ignore') as f:
            text = f.read()
        new, filled = fix_page(text, photos[brand])
        if not filled:
            continue
        changed += 1
        slots += filled
        per_brand[brand] += filled
        if not DRY:
            with open(path, 'w', encoding='utf-8') as f:
                f.write(new)

    print(f'карточек просмотрено: {len(pages)}')
    print(f'{"будет исправлено" if DRY else "исправлено"} карточек: {changed}, слайдов: {slots}')
    for brand, n in per_brand.most_common():
        print(f'   {brand:14} слайдов {n}')


if __name__ == '__main__':
    main()
