#!/usr/bin/env python3
"""Чинит обрезку ряда миниатюр на карточках при узком экране.

Ряд из 4 миниатюр свёрстан как `display:flex` с кнопками `flex:0 0 92px` — они не
сжимаются и не переносятся, а у контейнера нет прокрутки. Арифметика: отступ 24 +
4×92 + 3×10 = 422 px. На телефонах 360–414 px правый край ряда уезжает за экран,
четвёртая миниатюра обрезается, и добраться до неё нельзя — горизонтальной
прокрутки нет.

Правка: разрешаем ряду прокручиваться вбок и убираем полосу прокрутки из вида.
Ничего не переносим и не уменьшаем — на широких экранах вид не меняется.

Идемпотентно: правленый CSS содержит `overflow-x:auto`.

Запуск:
    python3 tools/fix_thumbs_overflow.py --dry
    python3 tools/fix_thumbs_overflow.py
"""
import os, sys, glob

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
os.chdir(ROOT)

DRY = "--dry" in sys.argv

OLD = ".p2-thumbs{display:flex;gap:10px;margin-top:12px}"
NEW = (".p2-thumbs{display:flex;gap:10px;margin-top:12px;overflow-x:auto;"
       "scrollbar-width:none;-webkit-overflow-scrolling:touch;scroll-snap-type:x proximity}"
       ".p2-thumbs::-webkit-scrollbar{display:none}"
       ".p2-thumbs button{scroll-snap-align:start}")

DIRS = ["analog", "reduktor", "motor-reduktor-zr", "ispolnenie", "tiporazmer"]


def main():
    done = skipped = 0
    for d in DIRS:
        for path in glob.glob(f"{d}/*.html"):
            try:
                t = open(path, encoding="utf-8").read()
            except OSError:
                continue
            if OLD not in t:
                skipped += 1
                continue
            if not DRY:
                open(path, "w", encoding="utf-8").write(t.replace(OLD, NEW, 1))
            done += 1
            if done % 20000 == 0:
                print("...", done)
    print(f"исправлено: {done}   без этого блока: {skipped}")
    if DRY:
        print("(сухой прогон — без записи)")


if __name__ == "__main__":
    main()
