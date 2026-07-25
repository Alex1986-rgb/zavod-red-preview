#!/usr/bin/env python3
"""Добавляет пункт «Редукторы EVL» в выпадающее меню каталога рядом с ПР/МР.

Заказчик: EVL нужен как отдельный раздел. Страница /catalog/evl уже есть (104
карточки + таблица соответствия), но в меню каталога её пункта нет — только ПР и
МР. Добавляем ссылку сразу после «Мотор-редукторы МР», чтобы EVL стал полноценным
разделом и находился из шапки на любой странице.

Меню инлайновое в каждом HTML, шаблон однороден. Идемпотентно: если пункт EVL уже
есть, файл не трогаем. --dry — отчёт без записи.
"""
import os, sys, glob

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
os.chdir(ROOT)

DRY = "--dry" in sys.argv
LIMIT = next((int(a) for a in sys.argv[1:] if a.isdigit()), None)

ANCHOR = '<a href="/catalog/mr">Мотор-редукторы МР</a>'
EVL_ITEM = '<a href="/catalog/evl">Редукторы EVL</a>'
NEW = ANCHOR + EVL_ITEM


def transform(path):
    t = open(path, encoding="utf-8").read()
    if EVL_ITEM in t:          # уже добавлено
        return "already"
    if ANCHOR not in t:        # нет этого меню на странице
        return "no-menu"
    t = t.replace(ANCHOR, NEW, 1)   # только первое вхождение — само меню шапки
    if not DRY:
        open(path, "w", encoding="utf-8").write(t)
    return "ok"


if __name__ == "__main__":
    files = [a for a in sys.argv[1:] if a.endswith(".html")]
    if not files:
        files = [p for p in glob.glob("**/*.html", recursive=True) if not p.startswith("dist/")]
        if LIMIT:
            files = files[:LIMIT]
    from collections import Counter
    st = Counter()
    for i, f in enumerate(files, 1):
        st[transform(f)] += 1
        if i % 25000 == 0:
            print("...", i)
    print("итог:", dict(st))
    if DRY:
        print("(сухой прогон — без записи)")
