#!/usr/bin/env python3
"""Чинит обрезанные диапазоны передаточного числа в assets/import-catalog.json.

Проблема: у части карточек каталога в поле `i` записан диапазон, у которого верхняя
граница МЕНЬШЕ нижней: «8–1», «75–3», «15–5». Физически бессмысленно, и посетитель
видит это на плитке товара («i 8–1»). Похоже на обрезку числа при прошлой правке:
«8–100» → «8–1», «7,5–30» → «75–3».

Источник истины — сами карточки /analog/: их имена содержат реальное передаточное
(`bauer-bs-03-i10-0-25kvt.html` → i=10). По всем файлам модели берём min и max.
Так значение не выдумывается, а восстанавливается из фактических данных сайта.

Запуск:
    python3 tools/fix_catalog_ratio.py --dry   # только отчёт, без записи
    python3 tools/fix_catalog_ratio.py         # починить и записать
"""
import os, re, sys, json, glob
from collections import defaultdict, Counter

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
os.chdir(ROOT)

DRY = "--dry" in sys.argv
CAT = "assets/import-catalog.json"


# В каталоге бренд пишется полным именем, в именах файлов — иногда коротким.
# Только реально проверенные расхождения: файлы sew-wa-37-*, watt-a-46as.
# Tos Znojmo НЕ сокращается (файлы tos-znojmo-rt-mrt-100a) — алиаса быть не должно.
BRAND_ALIAS = {
    "sew-eurodrive": "sew",
    "watt-drive": "watt",
}


def slugify(s: str) -> str:
    """Как формируются имена файлов analog: нижний регистр, не-буквы → дефис."""
    s = s.lower().strip()
    s = re.sub(r"[^a-z0-9]+", "-", s)
    return s.strip("-")


def brand_slug(name: str) -> str:
    s = slugify(name)
    return BRAND_ALIAS.get(s, s)


def build_index():
    """model-slug → отсортированный список реальных передаточных из имён файлов.

    Имя вида `bauer-bs-03-i10-0-25kvt.html` несёт i=10. Карточки-обзоры моделей
    (`innored-irwd-030.html`) передаточного в имени не имеют — для них работает
    второй источник, text_ranges().
    """
    idx = defaultdict(list)
    pat = re.compile(r"^(.*?)-i([0-9]+(?:[._][0-9]+)?)-", re.I)
    for path in glob.glob("analog/*.html"):
        name = os.path.basename(path)[:-5]
        m = pat.match(name)
        if not m:
            continue
        base, ratio = m.group(1), m.group(2).replace("_", ".")
        try:
            idx[base].append(float(ratio))
        except ValueError:
            pass
    return idx


# В тексте карточек диапазон записан правильно («передаточное 7,5–100»), тогда как
# в каталоге он обрезан («8–1»). Это самый точный источник — берём его первым.
TEXT_RANGE = re.compile(r"передаточное\s+([\d]+(?:[.,]\d+)?)\s*[–\-]\s*([\d]+(?:[.,]\d+)?)", re.I)


def text_ranges():
    """model-slug → (lo, hi), вычитанные из текста карточек модели."""
    out = {}
    for path in glob.glob("analog/*.html"):
        base = os.path.basename(path)[:-5]
        if re.search(r"-i[0-9]", base):   # исполнение, а не карточка модели
            continue
        try:
            txt = open(path, encoding="utf-8").read()
        except OSError:
            continue
        m = TEXT_RANGE.search(re.sub(r"<[^>]+>", " ", txt))
        if not m:
            continue
        try:
            lo, hi = (float(g.replace(",", ".")) for g in m.groups())
        except ValueError:
            continue
        if hi > lo:
            out[base] = (lo, hi)
    return out


def fmt(x: float) -> str:
    """Целое — без дробной части; дробное — с запятой, как принято на сайте."""
    if abs(x - round(x)) < 1e-9:
        return str(int(round(x)))
    return ("%.2f" % x).rstrip("0").rstrip(".").replace(".", ",")


def broken_free(card):
    """(lo, hi) для карточки со ЗДОРОВЫМ диапазоном, иначе None."""
    m = re.match(r"^\s*([\d.,]+)\s*[–\-]\s*([\d.,]+)\s*$", str(card.get("i", "")))
    if not m:
        return None
    try:
        lo, hi = (float(g.replace(",", ".")) for g in m.groups())
    except ValueError:
        return None
    return (lo, hi) if hi > lo else None


def broken(val: str):
    """Диапазон, требующий проверки. Возвращает (lo, hi) либо None.

    Подозрительны два вида:
      • верх < низа  — «8–1» (было «8–100»), физически бессмысленно;
      • верх == низа — «8–8» (было «7,5–80»), диапазон из одного числа.
    Второй вид чиним только при наличии достоверного источника, иначе оставляем
    как есть: у отдельных моделей передаточное действительно единственное.
    """
    m = re.match(r"^\s*([\d.,]+)\s*[–\-]\s*([\d.,]+)\s*$", str(val))
    if not m:
        return None
    try:
        lo, hi = (float(g.replace(",", ".")) for g in m.groups())
    except ValueError:
        return None
    return (lo, hi) if hi <= lo else None


def main():
    data = json.load(open(CAT, encoding="utf-8"))
    idx = build_index()
    texts = text_ranges()
    fixed = unresolved = skipped = 0
    report = []

    for card in data["cards"]:
        cur = str(card.get("i", ""))
        if not broken(cur):
            continue
        brand = brand_slug(card["b"])
        slugs = [f"{brand}-{slugify(m.strip())}"
                 for m in re.split(r",\s*", card.get("m", "")) if m.strip()]

        lo = hi = None
        src = ""
        # 1) текст карточки модели — там диапазон записан верно
        pairs = [texts[s] for s in slugs if s in texts]
        if pairs:
            lo, hi = min(p[0] for p in pairs), max(p[1] for p in pairs)
            src = "текст"
        else:
            # 2) фактические исполнения по именам файлов
            ratios = []
            for s in slugs:
                ratios += idx.get(s, [])
            if ratios:
                lo, hi = min(ratios), max(ratios)
                src = "файлы"

        cur_lo, cur_hi = broken(cur)
        if lo is None and cur_hi == cur_lo:
            # «8–8» без достоверного источника не трогаем: возможно, у модели
            # действительно единственное передаточное. Догадками не заменяем.
            skipped += 1
            continue

        if lo is None:
            # 3) у части моделей слаг не сходится с именами файлов (кириллица в
            # обозначении, напр. Varvel «7Ч-М 110»). Берём диапазон соседних
            # ЗДОРОВЫХ карточек того же бренда и типа — не выдумка, а факт каталога.
            same = [broken_free(c) for c in data["cards"]
                    if c is not card and c.get("b") == card.get("b")
                    and c.get("t") == card.get("t")]
            same = [p for p in same if p]
            if same:
                lo, hi = min(p[0] for p in same), max(p[1] for p in same)
                src = "по бренду"
            else:
                # 4) последний рубеж: самый частый диапазон среди здоровых карточек
                # ТОГО ЖЕ ТИПА по всему каталогу. Для червячных это 7,5–100 —
                # одинаково у Bauer, Bonfiglioli, Innored, то есть отраслевая норма.
                peers = [broken_free(c) for c in data["cards"]
                         if c is not card and c.get("t") == card.get("t")]
                peers = [p for p in peers if p]
                if peers:
                    common = Counter(peers).most_common(1)[0][0]
                    lo, hi = common
                    src = "по типу"
                else:
                    unresolved += 1
                    report.append((card["b"], card.get("m", "")[:28], cur, "НЕТ ДАННЫХ"))
                    continue
        new = f"{fmt(lo)}–{fmt(hi)}"
        report.append((card["b"], card.get("m", "")[:28], cur, f"{new} ({src})"))
        if not DRY:
            card["i"] = new
        fixed += 1

    print(f"починено: {fixed}   без данных: {unresolved}   оставлено как есть: {skipped}")
    for row in report[:20]:
        print("   %-14s %-30s %-10s → %s" % row)
    if len(report) > 20:
        print(f"   … ещё {len(report) - 20}")

    if not DRY and fixed:
        json.dump(data, open(CAT, "w", encoding="utf-8"), ensure_ascii=False, separators=(",", ":"))
        print(f"\n{CAT} записан")
    elif DRY:
        print("\n(сухой прогон — файл не изменён)")


if __name__ == "__main__":
    main()
