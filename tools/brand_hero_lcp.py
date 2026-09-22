#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Фото бренда на первом экране перестаёт грузиться отложенно.

На всех 26 страницах брендов главная картинка первого экрана размечена
loading="lazy". Это самый крупный элемент экрана — именно по нему поисковик меряет
скорость отрисовки. Отложенная загрузка откладывает её ровно там, где откладывать
нельзя: браузер сначала разбирает весь документ и только потом идёт за картинкой.

Что ставится вместо:
  fetchpriority="high"  — браузер берёт эту картинку первой, не дожидаясь остального;
  decoding="async"      — декодирование не блокирует разбор страницы;
  width и height        — из самого файла, чтобы место под картинку резервировалось
                          заранее и вёрстка не прыгала при загрузке.

Размеры читаются из заголовка WebP, а не задаются константой: у 26 файлов они разные
(от 642×360 до 714×432), и один размер на всех дал бы искажение пропорций.

Скрипт идемпотентен.

Запуск:  python3 tools/brand_hero_lcp.py [--dry]
"""
import argparse
import os
import re
import struct

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
IMG = re.compile(r'(<div class="bhero-photo"><img )([^>]*?)(>)')


def webp_size(path):
    """Ширина и высота из заголовка WebP — все три формы контейнера."""
    with open(path, 'rb') as f:
        d = f.read(40)
    tag = d[12:16]
    if tag == b'VP8X':
        return int.from_bytes(d[24:27], 'little') + 1, int.from_bytes(d[27:30], 'little') + 1
    if tag == b'VP8 ':
        return (struct.unpack('<H', d[26:28])[0] & 0x3fff,
                struct.unpack('<H', d[28:30])[0] & 0x3fff)
    if tag == b'VP8L':
        b = int.from_bytes(d[21:25], 'little')
        return (b & 0x3fff) + 1, ((b >> 14) & 0x3fff) + 1
    return None


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--dry', action='store_true')
    args = ap.parse_args()

    tronuto = propusk = 0
    for f in sorted(os.listdir(os.path.join(ROOT, 'brands'))):
        if not f.endswith('.html'):
            continue
        path = os.path.join(ROOT, 'brands', f)
        s = open(path, encoding='utf-8').read()
        m = IMG.search(s)
        if not m:
            continue
        atr = m.group(2)
        if 'fetchpriority' in atr:
            propusk += 1; continue

        src = re.search(r'src="([^"]+)"', atr)
        razmer = None
        if src:
            disk = os.path.join(ROOT, src.group(1).lstrip('/'))
            if os.path.isfile(disk):
                razmer = webp_size(disk)

        novyy = atr.replace(' loading="lazy"', '')
        novyy = novyy.rstrip() + ' fetchpriority="high" decoding="async"'
        if razmer:
            novyy += f' width="{razmer[0]}" height="{razmer[1]}"'
        s = s[:m.start(2)] + novyy + s[m.end(2):]
        print(f'  {f[:-5]:16} {"×".join(map(str, razmer)) if razmer else "размер не прочитан":>12}')
        if not args.dry:
            open(path, 'w', encoding='utf-8').write(s)
        tronuto += 1

    print(f'{"будет правлено" if args.dry else "правлено"} страниц: {tronuto}, уже сделано: {propusk}')


if __name__ == '__main__':
    main()
