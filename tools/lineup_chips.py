#!/usr/bin/env python3
"""П.10: на лендингах EVL/ПР/МР — строка-линейка типоразмеров чипами (как image11 с ПР).

Заказчик: «при попадании на EVL/ПР/МР хочется сразу увидеть всю линейку».
Добавляем сразу после H1 компактную строку чипов-типоразмеров (ПР 430, ПР 440…),
каждый ведёт на карточку /reduktor/{slug}. Обозначение берём из заголовков карточек.

Идемпотентно (маркер id="lineup").
"""
import os, re, sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
os.chdir(ROOT)

PAGES = {
    "catalog/evl.html": ("ZR", r"ZR ?\d+[/\dА-Яа-я]*"),
    "catalog/pr.html":  ("ПР", r"ПР ?\d+[А-Яа-я]?"),
    "catalog/mr.html":  ("МР", r"ZR ?\d+[/\dА-Яа-я]*"),
}

CSS = (
    '<style id="zr-lineup">'
    '.lineup-chips{display:flex;flex-wrap:wrap;gap:8px;margin:0}'
    ".lineup-chips a{display:inline-flex;align-items:center;padding:9px 15px;border-radius:9px;"
    "background:var(--card);border:1px solid var(--line);color:var(--text);font:600 13.5px/1 'Space Grotesk',sans-serif;text-decoration:none;transition:.14s}"
    '.lineup-chips a:hover{border-color:var(--red);color:var(--red);transform:translateY(-1px)}'
    '</style>'
)


def process(path, series, pat):
    if not os.path.exists(path):
        return f"{path}: НЕТ ФАЙЛА"
    t = open(path, encoding="utf-8").read()
    if 'id="lineup"' in t:
        return f"{path}: already"
    cards = re.findall(r'<a class="pcard-title" href="([^"]+)">([^<]+)</a>', t)
    seen = {}
    for href, title in cards:
        m = re.search(pat, title)
        if not m:
            continue
        label = re.sub(r"\s+", " ", m.group(0)).strip()
        if label not in seen:
            seen[label] = href
    if not seen:
        return f"{path}: типоразмеры не извлечены"
    chips = "".join(f'<a href="{href}">{label}</a>' for label, href in seen.items())
    block = (
        f'<section class="section" id="lineup" style="padding-top:10px;padding-bottom:6px"><div class="wrap">'
        f'<div class="eyebrow">Вся линейка {series} — {len(seen)} типоразмеров</div>'
        f'<div class="lineup-chips">{chips}</div>'
        f'</div></section>'
    )
    # вставить после первой секции с H1 (после её </section>)
    mh = re.search(r"<h1[^>]*>.*?</h1>", t, re.S)
    if not mh:
        return f"{path}: нет H1"
    close = t.find("</section>", mh.end())
    if close < 0:
        return f"{path}: нет </section> после H1"
    ins = close + len("</section>")
    t = t[:ins] + block + t[ins:]
    t = t.replace("</head>", CSS + "</head>", 1)
    open(path, "w", encoding="utf-8").write(t)
    return f"{path}: OK ({len(seen)} чипов)"


if __name__ == "__main__":
    for path, (series, pat) in PAGES.items():
        print(process(path, series, pat))
