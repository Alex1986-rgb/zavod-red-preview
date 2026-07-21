#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Классификатор моделей импортных редукторов по типу передачи.
Читает список страниц /analog/, сводит их к моделям (бренд-серия-типоразмер)
и определяет конструктивный тип — от него зависит промпт генерации фото.

Запуск: python3 tools/imggen/classify.py [--report]
"""
import os
import re
import sys
from collections import Counter

BASE = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

# Тип передачи по коду серии. Ключ — (бренд, код) или просто код (для всех брендов).
# Источник: обозначения производителей + таблицы соответствия на /brands/.
WORM = "червячный"
COAX = "соосно-цилиндрический"
FLAT = "плоский цилиндрический"
BEVEL = "коническо-цилиндрический"
WORMCYL = "червячно-цилиндрический"
PLANET = "планетарный"
VARI = "вариатор"

BY_CODE = {
    # червячные
    "nmrv": WORM, "mrv": WORM, "rmi": WORM, "umi": WORM, "wmi": WORM,
    "vf": WORM, "w": WORM, "wa": WORM, "rv": WORM, "vrl": WORM, "ro": WORM,
    "cm": WORM, "cmg": WORM, "cmis": WORM, "cmgis": WORM, "mi": WORM, "mu": WORM,
    "irwd": WORM, "rcv": WORM, "pcrv": WORM, "b": WORM, "ba": WORM,
    # соосно-цилиндрические
    "r": COAX, "c": COAX, "bg": COAX, "gks": COAX, "gss": COAX, "gst": COAX,
    "gst09": COAX, "sk": COAX, "cs": COAX, "ecft": COAX, "ecft100": COAX,
    "mrd": COAX, "mrn": COAX, "srs": COAX, "srt": COAX, "vrc": COAX,
    "vrp": COAX, "e": COAX, "mr": COAX, "mr1": COAX, "mr2": COAX, "mr5": COAX,
    # плоские цилиндрические
    "f": FLAT, "fa": FLAT, "bf": FLAT, "gfl": FLAT, "fc": FLAT, "xc": FLAT,
    # коническо-цилиндрические
    "k": BEVEL, "a": BEVEL, "bk": BEVEL, "ha": BEVEL, "n": BEVEL,
    "pd": BEVEL, "bh": BEVEL, "gkr": BEVEL, "h": BEVEL,
    # червячно-цилиндрические
    "s": WORMCYL, "cb": WORMCYL, "mnhl": WORMCYL, "bs": WORMCYL, "m": WORMCYL,
    # планетарные
    "pa": PLANET, "pc": PLANET, "pm": PLANET, "pr": PLANET, "o": PLANET,
    # вариаторы
    "drv": VARI, "drv050": VARI, "amp": VARI,
}

# Бренды, где числовые коды серий — по умолчанию этот тип
BRAND_DEFAULT = {
    "nord": COAX,        # SK и числовые — соосные/плоские, основная масса соосные
    "tramec": WORM,      # числовые типоразмеры червячных
    "varvel": WORM,      # числовые — червячные RS/RO
    "motovario": WORM,   # числовые — NMRV-подобные
    "innovari": COAX,    # числовые с буквой (701c, 402a)
    "siti": WORM,
    "stm": WORM,
    "bonfiglioli": WORM,
    "tos": WORM,         # tos-znojmo
    "watt": COAX,
    "sew": COAX,
    "rossi": COAX,
    "yilmaz": COAX,
    "lenze": COAX,
    "bauer": COAX,
    "transtecno": WORM,
    "vemper": WORM,
    "varmec": WORM,
    "innored": WORM,
}

BRAND_NAMES = {
    "bauer": "Bauer", "bonfiglioli": "Bonfiglioli", "innored": "INNORED",
    "innovari": "Innovari", "lenze": "Lenze", "motovario": "Motovario",
    "nord": "NORD", "rossi": "Rossi", "sew": "SEW-Eurodrive", "siti": "SITI",
    "stm": "STM", "tos": "TOS Znojmo", "tramec": "Tramec",
    "transtecno": "Transtecno", "varmec": "Varmec", "varvel": "Varvel",
    "vemper": "Vemper", "watt": "WATT Drive", "yilmaz": "Yilmaz",
}


def list_models():
    """Уникальные модели: бренд-серия-типоразмер (без i и мощности)."""
    d = os.path.join(BASE, "analog")
    seen = set()
    for f in os.listdir(d):
        if not f.endswith(".html") or f == "index.html":
            continue
        m = re.sub(r"-i[0-9].*$", "", f[:-5])
        if m and m != "index":
            seen.add(m)
    return sorted(seen)


def classify(model):
    """(бренд_slug, бренд_имя, код_серии, типоразмер, тип_передачи)"""
    parts = model.split("-")
    brand = parts[0]
    code = parts[1] if len(parts) > 1 else ""
    size = "-".join(parts[2:]) if len(parts) > 2 else ""
    gear = BY_CODE.get(code)
    if gear is None:
        # числовой код или неизвестный — берём умолчание бренда
        gear = BRAND_DEFAULT.get(brand, WORM)
    return brand, BRAND_NAMES.get(brand, brand.upper()), code, size, gear


def main():
    models = list_models()
    rows = [classify(m) + (m,) for m in models]
    types = Counter(r[4] for r in rows)
    print(f"моделей: {len(models)}")
    print("\nпо типам передачи:")
    for t, n in types.most_common():
        print(f"  {n:4d} — {t}")
    if "--report" in sys.argv:
        print("\nпримеры классификации:")
        for r in rows[:15]:
            print(f"  {r[5]:28s} → {r[1]}, {r[4]}, типоразмер {r[3] or '—'}")
    return rows


if __name__ == "__main__":
    main()
