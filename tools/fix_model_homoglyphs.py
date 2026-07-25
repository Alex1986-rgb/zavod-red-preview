#!/usr/bin/env python3
"""Чинит кириллические буквы-омоглифы в кодах моделей Tramec/Bonfiglioli.

Из аудита: коды этих двух брендов записаны русскими буквами, визуально
неотличимыми от латинских — «Tramec 100С» (С русская, надо C), «112В» (надо B),
«Tramec К 110» (надо K), «Bonfiglioli С 90» (надо C). Латинский поиск их не
находит, и в самом каталоге (import-catalog.json) стоит «112В, 100С» — поэтому
их не находит и фильтр витрины.

ПОЧЕМУ НЕ МАССОВАЯ ЗАМЕНА: сухой прогон широкого правила поймал ложные цели —
«380В/220В» это ВОЛЬТЫ (В=Вольт, кириллица верна), «40Х/20ХН2М» — МАРКИ СТАЛИ
(Х=хром, Н=никель), «МР1/2/3» — НАША марка Мотор-Редуктор, «М12/М16» — резьба.
Поэтому здесь — ЗАКРЫТЫЙ белый список ровно тех токенов, что реально являются
кодами Tramec/Bonfiglioli (сверено с import-catalog.json). Больше ничего.

Правит: HTML-карточки/статьи + сам assets/import-catalog.json (чтобы фильтр нашёл).
Идемпотентно. --dry — отчёт без записи.
"""
import os, re, sys, glob
from collections import Counter

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
os.chdir(ROOT)

DRY = "--dry" in sys.argv
LIMIT = next((int(a) for a in sys.argv[1:] if a.isdigit()), None)

# Закрытый список: кириллический токен → латинский. Только модели Tramec/Bonfiglioli.
# Склеенные размеры Tramec (цифры + кириллическая С/В):
GLUED = {
    "80С": "80C", "90В": "90B", "100С": "100C", "112В": "112B",
    "125С": "125C", "140В": "140B", "160С": "160C", "180В": "180B",
    "180С": "180C", "200В": "200B", "200С": "200C", "225В": "225B",
}
# Пробельные (кириллическая буква + пробел + число): Tramec К-серия, Bonfiglioli С 90.
# Ключ — регэксп с границами слова, чтобы не задеть предлог/аббревиатуру.
SPACED = {
    r"\bК (30|40|50|63|75|90|110)\b": lambda m: "K " + m.group(1),
    r"\bС (90)\b": lambda m: "C " + m.group(1),
}

stats = Counter()

# склеенные — как ЦЕЛЫЙ токен, не приклеенный к другим буквам/цифрам:
# «190В» (гипотетический вольтаж) не пострадает — «90В» там с цифрой слева.
GLUED_RE = re.compile(
    r'(?<![0-9A-Za-z])(' + "|".join(map(re.escape, GLUED)) + r')(?![0-9A-Za-z])'
)


def fix_text(t):
    def g(m):
        cyr = m.group(1)
        stats[(cyr, GLUED[cyr])] += 1
        return GLUED[cyr]
    t = GLUED_RE.sub(g, t)
    # пробельные — по границам слова
    for pat, repl in SPACED.items():
        def wrap(m):
            stats[(m.group(0), None)] += 1
            return repl(m)
        t = re.sub(pat, wrap, t)
    return t


def transform(path):
    t = open(path, encoding="utf-8").read()
    new = fix_text(t)
    if new != t and not DRY:
        open(path, "w", encoding="utf-8").write(new)
    return new != t


if __name__ == "__main__":
    files = [a for a in sys.argv[1:] if a.endswith(".html") or a.endswith(".json")]
    if not files:
        # только файлы, где реально встречаются эти бренды, + каталог
        files = ["assets/import-catalog.json"]
        for d in ["analog", "brands", "blog", "reduktor", "motor-reduktor-zr"]:
            files += glob.glob(f"{d}/*.html")
        if LIMIT:
            files = files[:LIMIT]
    touched = 0
    for i, f in enumerate(files, 1):
        if os.path.exists(f) and transform(f):
            touched += 1
        if i % 20000 == 0:
            print("...", i)
    print(f"файлов изменено: {touched}")
    print("замены:")
    for (s, l), n in stats.most_common():
        print(f"  {n:>5}  {s!r}" + (f" → {l!r}" if l else " (пробельный)"))
    if DRY:
        print("(сухой прогон — без записи)")
