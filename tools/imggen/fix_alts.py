#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
SEO-alt для фото редукторов на страницах /analog/.

Было «Motovario NMRV 050 фото 1» — для поиска по картинкам это пустышка:
ни типа передачи, ни назначения, ни связи с брендом ZR. Заменяем на
описательные подписи, разные для каждого снимка галереи.

Тип передачи берём из имени подключённой картинки (br-{бренд}-t{тип}) —
он там уже закодирован, лезть в каталог не нужно.

Запуск: python3 fix_alts.py [--dry]
"""
import os
import re
import sys

BASE = os.environ.get("ZAVOD_BASE", "/Users/alexandr/projects/zavod-red-preview")
DIR = os.path.join(BASE, "analog")

TYPES = ["червячный", "соосно-цилиндрический", "коническо-цилиндрический",
         "плоско-цилиндрический", "цилиндрический"]

# Подпись под каждый снимок галереи. Разные формулировки, чтобы страница
# не выглядела набором одинаковых alt — поиск такое считает спамом.
SHOTS = {
    "1": "{m} — {g} мотор-редуктор, вид на выходной вал",
    "2": "{m} — {g} мотор-редуктор, вид сбоку на корпус",
    "3": "{m} — {g} мотор-редуктор в заводской упаковке",
    "4": "{m} — габаритный чертёж {g2} редуктора",
}


def genitive(gear):
    """«червячный» → «червячного»: в подписи к чертежу нужен родительный падеж."""
    if gear.endswith("ий") or gear.endswith("ый"):
        return gear[:-2] + "ого"
    return gear

ALT = re.compile(r'alt="([^"]+?) фото ([1-4])"')
IMG = re.compile(r'br-[a-z0-9]+-t(\d)\.webp')


def fix(path):
    s = open(path, encoding="utf-8", errors="ignore").read()
    if " фото " not in s:
        return 0
    mt = IMG.search(s)
    gear = TYPES[int(mt.group(1))] if mt and int(mt.group(1)) < len(TYPES) \
        else "промышленный"

    def rep(m):
        model, n = m.group(1), m.group(2)
        return 'alt="' + SHOTS[n].format(m=model, g=gear,
                                         g2=genitive(gear)) + '"'

    new, n = ALT.subn(rep, s)
    if n and "--dry" not in sys.argv:
        open(path, "w", encoding="utf-8").write(new)
    return n


def main():
    files = [f for f in os.listdir(DIR) if f.endswith(".html")]
    total = changed = 0
    for i, f in enumerate(files, 1):
        n = fix(os.path.join(DIR, f))
        total += n
        changed += 1 if n else 0
        if i % 10000 == 0:
            print(f"  {i}/{len(files)}…", flush=True)
    print(f"файлов: {len(files)}, изменено: {changed}, alt переписано: {total}")
    if "--dry" in sys.argv:
        print("(пробный прогон, ничего не записано)")


if __name__ == "__main__":
    main()
