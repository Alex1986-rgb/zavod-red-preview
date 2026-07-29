#!/usr/bin/env python3
"""
Аудитор карточек zavod-red.ru против эталона «pro» (образ /analog/nord-9016-1-i22-71-4kvt).

Проверяет КАЖДУЮ карточку по элементам (а не по подстрокам — CSS-правила не считаются).
Классифицирует по семьям и уровню соответствия. Печатает сводку и примеры отклонений.

Использование:
    python3 tools/card_audit.py                 # все семьи, сводка
    python3 tools/card_audit.py analog          # одна семья
    python3 tools/card_audit.py --json          # машиночитаемо (для агентов)
    python3 tools/card_audit.py reduktor --list-bad 20   # список непроходящих

Эталон-стандарт (все обязательны для «PRO»):
  1. <nav class="apro-nav"> — липкая навигация карточки
  2. секция #opis идёт ПЕРЕД #spec (описание ведёт)
  3. элемент .opis-lead — лид-абзацы описания
  4. .faq2 — двухколоночный блок вопросов + секция #faq
  5. секция #seo + таблица .seo-tbl — соответствие исполнений
  6. .why-grid — сетка преимуществ
  7. галерея: .p2-gal (аналог) ИЛИ .p2-thumbs (ZR-семьи) — миниатюры
  8. пункт «Вопросы» в apro-nav
"""
import re, sys, glob, os, json, collections

FAMILIES = ["analog", "reduktor", "motor-reduktor-zr", "ispolnenie", "tiporazmer"]

def has_class(t, cls):
    """Есть ли HTML-элемент с данным классом (не CSS-правило)."""
    return re.search(r'class="[^"]*\b' + re.escape(cls) + r'\b', t) is not None

def section_ids(t):
    return re.findall(r'<section[^>]*\bid="([a-z0-9]+)"', t)

def audit_file(path):
    t = open(path, encoding="utf-8", errors="replace").read()
    ids = section_ids(t)
    nav = re.search(r'<nav class="apro-nav">(.*?)</nav>', t, re.S)
    nav_txt = nav.group(1) if nav else ""
    checks = {
        "apro_nav":    nav is not None,
        "opis_first":  ("opis" in ids and "spec" in ids and ids.index("opis") < ids.index("spec")),
        "opis_lead":   has_class(t, "opis-lead"),
        "faq2":        has_class(t, "faq2") and "faq" in ids,
        "seo_tbl":     has_class(t, "seo-tbl") and "seo" in ids,
        "why_grid":    has_class(t, "why-grid"),
        "gallery":     has_class(t, "p2-gal") or has_class(t, "p2-thumbs"),
        "nav_faq":     ("#faq" in nav_txt),
    }
    ok = sum(checks.values())
    total = len(checks)
    if ok == total:      level = "PRO"
    elif ok >= 4:        level = "PARTIAL"
    else:                level = "THIN"
    return checks, ok, total, level

def audit_family(fam, base):
    files = [f for f in glob.glob(os.path.join(base, fam, "*.html"))
             if os.path.basename(f) != "index.html"]
    agg = collections.Counter()
    levels = collections.Counter()
    bad = []
    for f in files:
        checks, ok, total, level = audit_file(f)
        levels[level] += 1
        for k, v in checks.items():
            if v: agg[k] += 1
        if level != "PRO":
            bad.append((os.path.basename(f), ok, [k for k, v in checks.items() if not v]))
    return {"family": fam, "n": len(files), "levels": dict(levels),
            "checks": dict(agg), "bad": bad}

def main():
    args = [a for a in sys.argv[1:]]
    as_json = "--json" in args
    if as_json: args.remove("--json")
    list_bad = 0
    if "--list-bad" in args:
        i = args.index("--list-bad"); list_bad = int(args[i+1]); del args[i:i+2]
    base = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    fams = args if args else FAMILIES

    reports = [audit_family(f, base) for f in fams]
    if as_json:
        for r in reports: r["bad"] = r["bad"][:list_bad or 0]
        print(json.dumps(reports, ensure_ascii=False, indent=2)); return

    CHECK_KEYS = ["apro_nav","opis_first","opis_lead","faq2","seo_tbl","why_grid","gallery","nav_faq"]
    for r in reports:
        n = r["n"] or 1
        print(f"\n=== {r['family']}  ({r['n']} карточек) ===")
        lv = r["levels"]
        print(f"  PRO {lv.get('PRO',0)} · PARTIAL {lv.get('PARTIAL',0)} · THIN {lv.get('THIN',0)}")
        for k in CHECK_KEYS:
            c = r["checks"].get(k, 0)
            mark = "✓" if c == r["n"] else ("·" if c else "✗")
            print(f"    {mark} {k:11} {c:6}/{r['n']} ({100*c//n}%)")
        if list_bad and r["bad"]:
            print(f"  — примеры непроходящих (до {list_bad}):")
            for name, ok, missing in r["bad"][:list_bad]:
                print(f"      {name}  ({ok}/8)  нет: {', '.join(missing)}")
    print()

if __name__ == "__main__":
    main()
