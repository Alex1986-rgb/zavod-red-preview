#!/usr/bin/env python3
"""Чинит десятичную точку на запятую в русских величинах: «0.12 кВт» → «0,12 кВт».

В русской типографике разделитель дробной части — запятая. На карточках числа
идут через точку («0.12 кВт», «66.0 Н·м», «17.5 об/мин»), что читается как
машинный вывод.

ОСТОРОЖНО, чтобы не сломать разметку:
  • правим ТОЛЬКО число, за которым сразу идёт единица (кВт, Н·м, об/мин, мм, кг,
    кН, %); там точка гарантированно десятичная и текст виден человеку;
  • НЕ трогаем JSON-LD: блоки <script type="application/ld+json"> вырезаются перед
    заменой и возвращаются как были (в schema.org число обязано быть с точкой);
  • единица идёт сразу за числом → в путях/версиях/датах такого сочетания нет.

Идемпотентно: после замены точки перед единицей не остаётся. --dry — без записи.
"""
import os, re, sys, glob

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
os.chdir(ROOT)

DRY = "--dry" in sys.argv
LIMIT = next((int(a) for a in sys.argv[1:] if a.isdigit()), None)

DIRS = ["tiporazmer", "motor-reduktor-zr", "ispolnenie", "reduktor", "analog",
        "catalog", "brands"]

LD = re.compile(r'<script[^>]*application/ld\+json[^>]*>.*?</script>', re.S)
# число.число + пробел? + единица
NUM = re.compile(r'(\d+)\.(\d+)(\s*(?:кВт|Н·м|об/мин|мм|кг|кН|%))')


def transform(path):
    t = open(path, encoding="utf-8").read()

    # вынимаем JSON-LD, чтобы не трогать
    stash = []

    def hide(m):
        stash.append(m.group(0))
        return f"\x00{len(stash)-1}\x00"

    body = LD.sub(hide, t)

    body, n = NUM.subn(lambda m: f"{m.group(1)},{m.group(2)}{m.group(3)}", body)

    if n == 0:
        return 0

    # возвращаем JSON-LD на место
    body = re.sub(r"\x00(\d+)\x00", lambda m: stash[int(m.group(1))], body)

    if not DRY:
        open(path, "w", encoding="utf-8").write(body)
    return n


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
