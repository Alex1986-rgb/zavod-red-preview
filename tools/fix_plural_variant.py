#!/usr/bin/env python3
"""Чинит склонение «N вариантов исполнения» по русским правилам.

Из аудита: везде стоит «вариантов» независимо от числа — «1 вариантов исполнения»,
«2 вариантов». Правильно: 1 → вариант, 2–4 → варианта, 0 и 5–20 → вариантов
(с поправкой на 11–14 → вариантов). Фраза уходит и в текст, и в описание для
поисковика, поэтому чиним оба вхождения.

Идемпотентно: повторный прогон уже верные формы не меняет (регэксп ловит только
рассогласование числа и слова).  --dry — отчёт без записи.
"""
import os, re, sys, glob

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
os.chdir(ROOT)

DRY = "--dry" in sys.argv
LIMIT = next((int(a) for a in sys.argv[1:] if a.isdigit()), None)

DIRS = ["tiporazmer", "motor-reduktor-zr", "ispolnenie", "reduktor", "analog"]

PAT = re.compile(r"(\d+)\s+вариант(?:ов|а)?\b")


def form(n):
    if 11 <= n % 100 <= 14:
        return "вариантов"
    d = n % 10
    if d == 1:
        return "вариант"
    if 2 <= d <= 4:
        return "варианта"
    return "вариантов"


def transform(path):
    t = open(path, encoding="utf-8").read()
    changed = [0]

    def repl(m):
        n = int(m.group(1))
        correct = f"{n} {form(n)}"
        if m.group(0) != correct:
            changed[0] += 1
        return correct

    new = PAT.sub(repl, t)
    if changed[0] and not DRY:
        open(path, "w", encoding="utf-8").write(new)
    return changed[0]


if __name__ == "__main__":
    files = [a for a in sys.argv[1:] if a.endswith(".html")]
    if not files:
        files = []
        for d in DIRS:
            files += sorted(glob.glob(f"{d}/*.html"))
        if LIMIT:
            files = files[:LIMIT]
    touched = total = 0
    for i, f in enumerate(files, 1):
        n = transform(f)
        if n:
            touched += 1
            total += n
        if i % 20000 == 0:
            print("...", i)
    print(f"файлов исправлено: {touched}, замен: {total}")
    if DRY:
        print("(сухой прогон — без записи)")
