#!/usr/bin/env python3
"""П.1: плитки брендов на каталожных страницах — с фото редуктора.

Было: текстовая плитка (только название + «каталог и аналоги ZR»).
Стало: фото редуктора бренда + логотип (если есть) + название + «страна · оригинал и аналог».
ZR на этапе браузинга по брендам убран (см. решение заказчика по пп.3/4).

Идемпотентно: повторный запуск заменяет сетку и стиль заново.
"""
import os, re, sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
os.chdir(ROOT)

# порядок как в исходной сетке; (slug, name, country)
BRANDS = [
    ("sew", "SEW-Eurodrive", "Германия"),
    ("nord", "NORD", "Германия"),
    ("bonfiglioli", "Bonfiglioli", "Италия"),
    ("motovario", "Motovario", "Италия"),
    ("bauer", "Bauer", "Германия"),
    ("lenze", "Lenze", "Германия"),
    ("rossi", "Rossi", "Италия"),
    ("siti", "SITI", "Италия"),
    ("varvel", "Varvel", "Италия"),
    ("innovari", "Innovari", "Италия"),
    ("stm", "STM", "Италия"),
    ("watt-drive", "Watt Drive", "Австрия"),
    ("yilmaz", "Yilmaz", "Турция"),
    ("transtecno", "Transtecno", "Италия"),
    ("varmec", "Varmec", "Италия"),
    ("innored", "Innored", "Китай"),
    ("vemper", "Vemper", "Импорт"),
    ("sew-tramec", "SEW Tramec", "Италия"),
    ("flender", "Flender", "Германия"),
    ("siemens", "Siemens", "Германия"),
    ("keb", "KEB", "Германия"),
    ("unidrive", "Unidrive", "Импорт"),
    ("boneng", "Boneng", "Китай"),
    ("guomao", "Guomao", "Китай"),
]

CSS = (
    '<style id="zr-brandtiles">'
    '.imp-bnav{display:grid;grid-template-columns:repeat(auto-fill,minmax(192px,1fr));gap:14px}'
    '.imp-bcard{display:flex;flex-direction:column;gap:6px;background:var(--card);border:1px solid var(--line);'
    'border-radius:14px;padding:12px;text-decoration:none;transition:.15s}'
    '.imp-bcard:hover{border-color:var(--red);transform:translateY(-3px);box-shadow:0 10px 24px rgba(14,26,36,.08)}'
    '.ibc-ph{position:relative;background:#fff;border:1px solid var(--line);border-radius:10px;padding:8px;'
    'min-height:118px;display:flex;align-items:center;justify-content:center}'
    '.ibc-ph .ibc-unit{width:100%;height:104px;object-fit:contain;display:block}'
    '.ibc-ph .ibc-logo{position:absolute;top:7px;left:8px;max-height:16px;max-width:74px;object-fit:contain;opacity:.92}'
    ".imp-bcard b{font-size:15px;color:var(--text);font-family:'Space Grotesk',sans-serif;margin-top:2px}"
    '.imp-bcard .ibc-sub{font-size:12px;color:var(--muted)}'
    '@media(max-width:560px){.imp-bnav{grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px}'
    '.ibc-ph{min-height:96px}.ibc-ph .ibc-unit{height:82px}}'
    '</style>'
)


def tile(slug, name, country):
    logo = ""
    if os.path.exists(f"assets/brands/{slug}.webp"):
        logo = (f'<img class="ibc-logo" src="/assets/brands/{slug}.webp" '
                f'alt="{name} — логотип" loading="lazy">')
    return (
        f'<a class="imp-bcard" href="/brands/{slug}">'
        f'<span class="ibc-ph">{logo}'
        f'<img class="ibc-unit" src="/assets/brands-photo/{slug}-unit.webp" '
        f'alt="Мотор-редуктор {name} — фото" loading="lazy"></span>'
        f'<b>{name}</b>'
        f'<span class="ibc-sub">{country} · оригинал и аналог</span>'
        f'</a>'
    )


GRID = '<div class="imp-bnav">' + "".join(tile(*b) for b in BRANDS) + '</div>'

FILES = [
    "catalog/index.html",
    "catalog/importnye-motor-reduktory.html",
]


def process(path):
    if not os.path.exists(path):
        return f"{path}: НЕТ ФАЙЛА"
    t = open(path, encoding="utf-8").read()
    orig = t
    # 1) заменить сетку imp-bnav (первый закрывающий div — внутри только <a>)
    new, n = re.subn(r'<div class="imp-bnav">.*?</div>', GRID, t, count=1, flags=re.S)
    if n == 0:
        return f"{path}: сетка .imp-bnav не найдена"
    t = new
    # 2) вставить/заменить style zr-brandtiles перед </head>
    t = re.sub(r'<style id="zr-brandtiles">.*?</style>', '', t, flags=re.S)
    t = t.replace("</head>", CSS + "</head>", 1)
    if t == orig:
        return f"{path}: без изменений"
    open(path, "w", encoding="utf-8").write(t)
    return f"{path}: OK (сетка заменена, стиль вставлен)"


if __name__ == "__main__":
    for f in FILES:
        print(process(f))
