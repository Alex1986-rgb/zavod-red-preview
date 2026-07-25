#!/usr/bin/env python3
"""Чинит машинную грамматику: тип редуктора застрял в именительном падеже.

Из аудита: «серия червячный редукторы», «прямой аналог импортных соосно-цилиндрический
редукторов» — прилагательное в именительном единственном, хотя нужно родительный
множественный. Читается как автоперевод и попадает в сниппеты поисковика.

Чиним только форму «{тип-им.п.} редукторы/редукторов» → «{тип-род.мн.} редукторов».
НЕ трогаем «{тип} редуктор» в единственном числе — там именительный правилен
(«червячный редуктор ZR 6013»).

Идемпотентно: повторный прогон уже исправленное не находит (именительного перед
«редукторы/редукторов» не остаётся). --dry — отчёт без записи.
"""
import os, re, sys, glob
from collections import Counter

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
os.chdir(ROOT)

DRY = "--dry" in sys.argv
LIMIT = next((int(a) for a in sys.argv[1:] if a.isdigit()), None)

# тип в именительном ед. → тип в родительном мн.
GEN = {
    "червячный": "червячных",
    "цилиндрический": "цилиндрических",
    "коническо-цилиндрический": "коническо-цилиндрических",
    "соосно-цилиндрический": "соосно-цилиндрических",
    "плоско-цилиндрический": "плоско-цилиндрических",
    "цилиндро-конический": "цилиндро-конических",
    "планетарный": "планетарных",
    "волновой": "волновых",
}

# «{тип-им} редукторы|редукторов» → «{тип-род} редукторов»
# (буквы кириллицы, чтобы \b не срабатывал на латинице)
PAT = re.compile(
    r"(?<![А-Яа-яЁё])(" + "|".join(map(re.escape, GEN)) + r")(\s+)редуктор(?:ы|ов)(?![А-Яа-яЁё])"
)

DIRS = ["motor-reduktor-zr", "ispolnenie", "tiporazmer", "reduktor", "analog",
        "catalog", "brands", "blog", "glossary"]


def repl(m):
    return GEN[m.group(1)] + m.group(2) + "редукторов"


def transform(path):
    t = open(path, encoding="utf-8").read()
    new, n = PAT.subn(repl, t)
    if n == 0:
        return 0
    if not DRY:
        open(path, "w", encoding="utf-8").write(new)
    return n


if __name__ == "__main__":
    files = [a for a in sys.argv[1:] if a.endswith(".html")]
    if not files:
        files = []
        for d in DIRS:
            files += sorted(glob.glob(f"{d}/*.html"))
        if LIMIT:
            files = files[:LIMIT]
    total = touched = 0
    for i, f in enumerate(files, 1):
        n = transform(f)
        if n:
            touched += 1
            total += n
        if i % 20000 == 0:
            print("...", i)
    print(f"файлов исправлено: {touched}, замен всего: {total}")
    if DRY:
        print("(сухой прогон — без записи)")
