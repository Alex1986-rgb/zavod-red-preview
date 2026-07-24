#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Веб-оптимизация сгенерированных фото: 2528×1696 PNG (7 МБ) → webp под сайт.

Два размера под то, как фото используются на zavod-red.ru:
  full  1200×800 — карточка модели, открытая страница
  thumb  600×400 — плитка в каталоге и в выдаче поиска

Ориентир по весу взят с существующих ассетов сайта: case1.webp 1200×750 = 86 КБ,
cat_worm.webp 800×600 = 113 КБ. Держимся в этих рамках, иначе каталог
на 751 позицию станет тяжелее, чем сейчас.

Запуск: python3 webopt.py gen/*.png
"""
import os
import sys

from PIL import Image

SIZES = {"full": (1200, 800), "thumb": (600, 400)}
QUALITY = {"full": 82, "thumb": 80}


def cover(img, w, h):
    """Кадрирует по центру под нужное соотношение, как object-fit: cover."""
    sr, dr = img.width / img.height, w / h
    if sr > dr:
        nw, nh = int(h * sr), h
    else:
        nw, nh = w, int(w / sr)
    img = img.resize((nw, nh), Image.LANCZOS)
    return img.crop(((nw - w) // 2, (nh - h) // 2,
                     (nw - w) // 2 + w, (nh - h) // 2 + h))


def main():
    files = sys.argv[1:]
    if not files:
        sys.exit("укажите png-файлы")
    outdir = os.path.join(os.path.dirname(os.path.abspath(files[0])), "web")
    os.makedirs(outdir, exist_ok=True)

    total = 0
    for path in files:
        name = os.path.splitext(os.path.basename(path))[0]
        src = Image.open(path).convert("RGB")
        line = [f"{name:10s} {src.width}x{src.height}"
                f" {os.path.getsize(path) // 1024:5d} КБ →"]
        for kind, (w, h) in SIZES.items():
            out = os.path.join(outdir, f"{name}-{kind}.webp")
            cover(src, w, h).save(out, "WEBP", quality=QUALITY[kind], method=6)
            kb = os.path.getsize(out) // 1024
            total += kb
            line.append(f"{kind} {w}x{h} {kb} КБ")
        print("  ".join(line))

    print(f"\nвсего {total} КБ на {len(files)} моделей "
          f"→ на 751 модель примерно {total * 751 // len(files) // 1024} МБ")


if __name__ == "__main__":
    main()
