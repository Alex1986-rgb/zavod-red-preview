#!/usr/bin/env python3
"""Пп.7/15: убрать одиночные значения на карточках /analog/ → показывать диапазон модели.

Проблема (замечания заказчика): у модели (SITI BH 125, Bauer BF 06 …) сотни исполнений,
а карточка показывает ОДНО значение мощности/передаточного/момента из слага — вводит в
заблуждение. Заменяем на диапазон модели из assets/import-catalog.json (напр. 0,1–3 кВт,
i 4–129), крутящий момент (нет диапазона, самый вводящий в заблуждение) убираем.

Меняем в 3 местах pro-карточки:
  A) абзац «Диапазон параметров: мощность X кВт, передаточное Y, момент Z Н·м, … исполнений …»
  B) таблица характеристик (Мощность / Передаточное число / Крутящий момент)
  C) FAQ «Какие характеристики…»

Диапазон берём по модели (бренд+модель из H1). Непокрытые модели → качественный фолбэк.
Идемпотентно (маркер <!--rng-->).  Аргумент --dry — только отчёт покрытия, без записи.
"""
import os, re, sys, glob, json

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
os.chdir(ROOT)

DRY = "--dry" in sys.argv
LIMIT = None
for a in sys.argv[1:]:
    if a.isdigit():
        LIMIT = int(a)

# --- карта (бренд, модель) -> (pw, i) ---
d = json.load(open("assets/import-catalog.json", encoding="utf-8"))
LK = {}
BRANDS = set()
for c in d["cards"]:
    b = c["b"]
    BRANDS.add(b)
    for m in re.split(r",\s*", c["m"]):
        m = m.strip()
        if m:
            LK[(b.lower(), m.lower())] = (c["pw"], c["i"])

ALIAS = {"sew": "SEW-Eurodrive", "sew tramec": "Tramec", "sew-tramec": "Tramec"}
# бренды по убыванию длины — для сопоставления самого длинного префикса
BRAND_LIST = sorted(BRANDS | set(ALIAS.keys()), key=len, reverse=True)


def split_brand_model(title):
    tl = title.lower()
    for b in BRAND_LIST:
        if tl.startswith(b.lower() + " "):
            canon = ALIAS.get(b.lower(), b)
            model = title[len(b):].strip()
            return canon, model
    # запасной: первое слово — бренд
    p = title.split(" ", 1)
    return p[0], (p[1] if len(p) > 1 else "")


def lookup(title):
    brand, model = split_brand_model(title)
    return LK.get((brand.lower(), model.lower()))


def transform(path):
    t = open(path, encoding="utf-8").read()
    if "<!--rng-->" in t:
        return "already"
    mh = re.search(r'<h1 class="p2-h1"[^>]*>(.*?)\s+—\s+', t)
    if not mh:
        return "no-h1"
    title = re.sub(r"<[^>]+>", "", mh.group(1)).strip()
    rng = lookup(title)

    if rng:
        pw, i = rng
        opis = (f"Модель выпускается в сотнях исполнений: мощность {pw} кВт, "
                f"передаточное число {i}, различные варианты по редукции, валам и фланцам.")
        rows = (f'<div><span class="k">Мощность (модель)</span><span class="v">{pw} кВт</span></div>'
                f'<div><span class="k">Передаточное число</span><span class="v">{i}</span></div>'
                f'<div><span class="k">Исполнения</span><span class="v">сотни — по редукции, валам, фланцам</span></div>')
        faq = (f"Модель выпускается в диапазоне: мощность {pw} кВт, передаточное {i}; "
               f"крутящий момент и точное исполнение зависят от типоразмера. "
               f"Точные параметры — по вашему шильду и в таблице выше.")
    else:
        opis = ("Модель выпускается в сотнях исполнений — по мощности, передаточному числу, "
                "редукции, валам и фланцам.")
        rows = ('<div><span class="k">Мощность</span><span class="v">по исполнению</span></div>'
                '<div><span class="k">Передаточное число</span><span class="v">по исполнению</span></div>'
                '<div><span class="k">Исполнения</span><span class="v">сотни — по редукции, валам, фланцам</span></div>')
        faq = ("Модель выпускается в сотнях исполнений по мощности и передаточному числу; "
               "точное исполнение подтвердит инженер по шильду или модели.")

    orig = t
    # A) абзац «Диапазон параметров …»
    t = re.sub(r"Диапазон параметров:[^.]*?исполнений по редукции, валам и фланцам\.",
               opis.replace("\\", "\\\\"), t, count=1)
    # B) три строки таблицы
    t = re.sub(r'<div><span class="k">Мощность</span><span class="v">[^<]*</span></div>'
               r'<div><span class="k">Передаточное число</span><span class="v">[^<]*</span></div>'
               r'<div><span class="k">Крутящий момент</span><span class="v">[^<]*</span></div>',
               rows.replace("\\", "\\\\"), t, count=1)
    # C) FAQ «Какие характеристики…»
    t = re.sub(r"Мощность [^,<]+ кВт, передаточное [^,<]+, крутящий момент [^,<]+ Н·м, тип — [^.<]+\.\s*Точные параметры исполнения — в таблице выше\.",
               faq.replace("\\", "\\\\"), t, count=1)

    if t == orig:
        return "no-match"
    t = t.replace("</head>", "<!--rng--></head>", 1)
    if not DRY:
        open(path, "w", encoding="utf-8").write(t)
    return "ok" if rng else "ok-fallback"


if __name__ == "__main__":
    files = [a for a in sys.argv[1:] if a.endswith(".html")]
    if not files:
        files = glob.glob("analog/*.html")
        if LIMIT:
            files = files[:LIMIT]
    from collections import Counter
    st = Counter()
    unmapped_titles = Counter()
    for f in files:
        r = transform(f)
        st[r] += 1
    print("итог:", dict(st))
    if DRY:
        print("(сухой прогон — без записи)")
