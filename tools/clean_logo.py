#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Пересобирает логотип без потерь и проставляет ему ключ сброса кэша.

Зачем: assets/zr_logo.webp и zr_logo_dark.webp лежали в webp СО СЖАТИЕМ С ПОТЕРЯМИ
(чанк VP8, не VP8L). Для логотипа с резкими краями это неподходящий формат: вокруг
тёмных букв появляется серый ореол, ровная красная заливка идёт пятнами, а мелкая
надпись «ПВЗР» внутри шестерни превращается в кашу. Проверка на боевом показала, что
вёрстка логотип НЕ растягивает (451×168 против 113×42 на экране — запаса хватает даже
при DPR 3), то есть мылит именно сжатие, а не размер.

Что делает. Логотип двухцветный: фирменный красный и второй цвет (тёмный в версии для
светлого фона, белый — для тёмного). Каждый непрозрачный пиксель притягивается к
ближайшему из этих двух, прозрачность остаётся нетронутой — сглаживание краёв не
страдает, пропадает только грязь от сжатия. Результат пишется в webp БЕЗ потерь.
Побочно файлы стали легче: 24 КБ → 11 КБ и 16 КБ → 11 КБ.

Второе: .htaccess отдаёт картинки с кэшем на ГОД, поэтому заменить файл на месте
недостаточно — вернувшийся посетитель год видел бы старый. Поэтому всем ссылкам на
логотип проставляется ?v= с хэшем содержимого, как это уже сделано для CSS и JS
(tools/bump_asset_versions.py). Адрес в разметке schema.org не трогается: там нужен
постоянный адрес картинки организации.

Скрипт идемпотентен: повторный прогон пересчитывает тот же хэш и ничего не меняет.

Запуск:  python3 tools/clean_logo.py [--dry] [--images-only]
"""
import argparse
import glob
import hashlib
import os
import re

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
LOGOS = ('assets/zr_logo.webp', 'assets/zr_logo_dark.webp')
# ссылка на логотип в разметке или в CSS, с уже проставленным ключом или без него
REF = re.compile(r'(?P<path>(?:\.\./|/)?(?:assets/)?zr_logo(?:_dark)?\.webp)'
                 r'(?:\?v=[A-Za-z0-9_.-]+)?')
ABS = 'https://zavod-red.ru/'


def rebuild(path, out, dry):
    """Перекодировать логотип без потерь, убрав грязь сжатия. Возвращает (было, стало)."""
    from PIL import Image
    import numpy as np

    im = Image.open(path).convert('RGBA')
    a = np.asarray(im).astype(np.int16)
    rgb, alpha = a[..., :3], a[..., 3]
    opaque = alpha > 200
    r, g = rgb[..., 0], rgb[..., 1]
    # красный отделяем по «красный заметно больше зелёного», остальное — второй цвет
    red_mask = opaque & ((r - g) > 60) & (r > 120)
    other_mask = opaque & ~red_mask
    if not red_mask.any() or not other_mask.any():
        raise SystemExit(f'{path}: не нашёл двух цветов — трогать не буду')
    red = np.median(rgb[red_mask], axis=0).astype(int)
    other = np.median(rgb[other_mask], axis=0).astype(int)
    to_red = ((rgb - red) ** 2).sum(-1) < ((rgb - other) ** 2).sum(-1)
    snapped = np.where(to_red[..., None], red, other).astype(np.uint8)
    before = os.path.getsize(path)
    target = path if out is None else out
    if not dry:
        Image.fromarray(np.dstack([snapped, alpha.astype(np.uint8)]), 'RGBA').save(
            target, 'WEBP', lossless=True, quality=100, method=6)
    print(f'  {os.path.basename(path):22} тёмный/белый {tuple(int(x) for x in other)}  '
          f'красный {tuple(int(x) for x in red)}  {before} б → '
          f'{os.path.getsize(target) if not dry else "—"} б')
    return before


def digest(path):
    with open(path, 'rb') as f:
        return hashlib.md5(f.read()).hexdigest()[:8]


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--dry', action='store_true')
    ap.add_argument('--images-only', action='store_true',
                    help='только пересобрать картинки, ссылки не трогать')
    args = ap.parse_args()

    print('пересборка без потерь:')
    for rel in LOGOS:
        path = os.path.join(ROOT, rel)
        if not os.path.exists(path):
            print(f'  {rel}: нет файла'); continue
        rebuild(path, None, args.dry)

    if args.images_only:
        return

    vers = {os.path.basename(rel): digest(os.path.join(ROOT, rel)) for rel in LOGOS}
    print('ключи сброса кэша:', ', '.join(f'{k} → {v}' for k, v in vers.items()))

    def sub(m):
        # адрес картинки организации в schema.org должен оставаться постоянным
        name = os.path.basename(m.group('path'))
        return f'{m.group("path")}?v={vers[name]}'

    pages = [p for p in glob.glob(os.path.join(ROOT, '**', '*.html'), recursive=True)
             if not p.startswith(os.path.join(ROOT, 'dist') + os.sep)]
    styles = [os.path.join(ROOT, f) for f in ('assets/inner.css', 'assets/hdr.css')]

    changed = 0
    for path in pages + styles:
        with open(path, encoding='utf-8', errors='ignore') as f:
            text = f.read()
        if 'zr_logo' not in text:
            continue
        # абсолютные адреса (schema.org) прячем, чтобы не получили ключ
        keep = []

        def hide(m):
            keep.append(m.group(0))
            return f'\x00{len(keep) - 1}\x00'

        tmp = re.sub(re.escape(ABS) + r'assets/zr_logo(?:_dark)?\.webp(?:\?v=[A-Za-z0-9_.-]+)?',
                     hide, text)
        tmp = REF.sub(sub, tmp)
        new = re.sub(r'\x00(\d+)\x00', lambda m: keep[int(m.group(1))], tmp)
        if new != text:
            changed += 1
            if not args.dry:
                with open(path, 'w', encoding='utf-8') as f:
                    f.write(new)
    print(f'{"будет обновлено" if args.dry else "обновлено"} файлов со ссылками: {changed}')
    print('дальше: python3 tools/bump_asset_versions.py — пересчитать ключи самих CSS')


if __name__ == '__main__':
    main()
