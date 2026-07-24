#!/usr/bin/env python3
"""Убирает лишний вес карточек: миниатюры грузились полноразмерными и все сразу.

Ряд миниатюр показывает картинки размером 78×64 точки, но в разметке стоят полные
файлы (79 + 134 + 85 + … ≈ 300 КБ) без `loading="lazy"` и без width/height. Браузер
тянет все четыре ещё до того, как посетитель до них долистает, а отсутствие размеров
заставляет вёрстку прыгать, когда картинка наконец пришла.

Правка (без перегенерации самих изображений — их пути не меняются):
  • первой миниатюре и главному фото оставляем обычную загрузку: они видны сразу;
  • остальным ставим loading="lazy" + decoding="async" — грузятся при подходе к экрану;
  • всем миниатюрам проставляем width/height, чтобы место было занято заранее.

Экономия на карточке — порядка 200 КБ при первой отрисовке.

Идемпотентно: правленые файлы содержат маркер `<!--thlazy-->`.

Запуск:
    python3 tools/fix_thumbs_lazy.py --dry
    python3 tools/fix_thumbs_lazy.py
"""
import os, re, sys, glob
from collections import Counter

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
os.chdir(ROOT)

DRY = "--dry" in sys.argv
LIMIT = next((int(a) for a in sys.argv[1:] if a.isdigit()), None)

MARK = "<!--thlazy-->"
DIRS = ["analog", "reduktor", "motor-reduktor-zr", "ispolnenie", "tiporazmer"]

# <button …><img src=… alt=…></button> внутри ряда миниатюр
BTN = re.compile(r'(<button type="button"(?: class="act")?[^>]*>)(<img )([^>]*?)(>)')
THUMBS = re.compile(r'<div class="p2-thumbs">(.*?)</div>', re.S)


def fix_block(block):
    idx = [0]

    def one(m):
        open_tag, img_open, attrs, close = m.groups()
        idx[0] += 1
        if "loading=" in attrs:                      # уже правлено
            return m.group(0)
        extra = ' width="78" height="64"'
        # первая миниатюра видна сразу — её не откладываем
        if idx[0] > 1:
            extra += ' loading="lazy" decoding="async"'
        return f"{open_tag}{img_open}{attrs}{extra}{close}"

    return BTN.sub(one, block)


def transform(path):
    t = open(path, encoding="utf-8").read()
    if MARK in t:
        return "already"
    m = THUMBS.search(t)
    if not m:
        return "no-thumbs"
    new_block = fix_block(m.group(0))
    if new_block == m.group(0):
        return "no-change"
    t = t[: m.start()] + new_block + t[m.end():]
    t = t.replace("</head>", MARK + "</head>", 1)
    if not DRY:
        open(path, "w", encoding="utf-8").write(t)
    return "ok"


if __name__ == "__main__":
    files = [a for a in sys.argv[1:] if a.endswith(".html")]
    if not files:
        files = []
        for d in DIRS:
            files += sorted(glob.glob(f"{d}/*.html"))
        if LIMIT:
            files = files[:LIMIT]
    st = Counter()
    for i, f in enumerate(files, 1):
        st[transform(f)] += 1
        if i % 20000 == 0:
            print("...", i)
    print("итог:", dict(st))
    if DRY:
        print("(сухой прогон — без записи)")
