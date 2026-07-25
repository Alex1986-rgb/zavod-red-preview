#!/usr/bin/env python3
"""Добавляет пункт «Редукторы EVL» в ПЛОСКОЕ меню карточек /analog/.

Первый проход (tools/add_evl_menu.py) вставлял EVL в выпадашку каталога рядом с
ПР/МР — но у карточек /analog/ меню другое, плоское (без выпадашки каталога), там
пункта ПР/МР нет, поэтому ~74 тысячи карточек аналогов остались без EVL.

Здесь вставляем «Редукторы EVL» сразу после плоского пункта «Мотор-редукторы ZR».
Файлы, где EVL уже есть (основные страницы из первого прохода), пропускаем —
двойного пункта не будет. Идемпотентно. --dry — отчёт без записи.
"""
import os, sys, glob
from collections import Counter

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
os.chdir(ROOT)

DRY = "--dry" in sys.argv
LIMIT = next((int(a) for a in sys.argv[1:] if a.isdigit()), None)

ANCHOR = '<a href="/motor-reduktor-zr/">Мотор-редукторы ZR</a>'
EVL_ITEM = '<a href="/catalog/evl">Редукторы EVL</a>'
NEW = ANCHOR + EVL_ITEM


def transform(path):
    t = open(path, encoding="utf-8").read()
    if "Редукторы EVL" in t:       # уже есть (основные страницы) — не трогаем
        return "already"
    if ANCHOR not in t:
        return "no-anchor"
    t = t.replace(ANCHOR, NEW, 1)  # только первое (само меню шапки)
    if not DRY:
        open(path, "w", encoding="utf-8").write(t)
    return "ok"


if __name__ == "__main__":
    files = [a for a in sys.argv[1:] if a.endswith(".html")]
    if not files:
        files = sorted(glob.glob("analog/*.html"))
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
