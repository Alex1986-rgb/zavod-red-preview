#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Картиночный sitemap для /analog/: каждое фото модели с title+caption (описание).

Google Images ранжирует по image:title / image:caption. Здесь на каждую страницу
/analog/ добавляем её фото модели с ключевым описанием (бренд, модель, тип, i, кВт,
аналог ZR). Разбиваем на части и регистрируем в sitemap-index.xml.

Размер части: у Яндекса предел на файл карты — 10 МБ (у Google 50 МБ). Запись с
title и caption весит около 630 байт, поэтому при прежних 45000 URL файл выходил
на 27 МБ и Вебмастер отбраковывал его целиком. 12000 даёт примерно 7,5 МБ.

    python3 tools/gen_image_sitemap.py
"""
import os, sys, glob, re
from xml.sax.saxutils import escape

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
os.chdir(ROOT)
sys.path.insert(0, os.path.join(ROOT, "tools", "imggen"))
import oneshot as O                       # noqa: E402

BASE = "https://zavod-red.ru"
PER = 12000      # ~7,5 МБ на файл: предел Яндекса 10 МБ, запись весит ~630 байт
CARDS = {s: c for s, c in O.all_cards()}
PHOTOS = sorted((os.path.basename(p)[:-5] for p in glob.glob("assets/cards-photo/*.webp")),
                key=len, reverse=True)
BY_FIRST = {}
for name in PHOTOS:
    BY_FIRST.setdefault(name[0], []).append(name)


def photo_for(slug):
    for name in BY_FIRST.get(slug[:1], []):
        if slug == name or slug.startswith(name + "-"):
            return name
    return None


def descr(photo):
    c = CARDS.get(photo, {})
    brand = c.get("brand", ""); model = c.get("model", "")
    gear = (c.get("gear", "") or "").capitalize()
    zr = c.get("zr", ""); ratio = c.get("ratio", ""); power = c.get("power", "")
    title = f"{brand} {model} — {gear.lower()} мотор-редуктор, аналог {zr}".strip()
    cap = (f"Фото {brand} {model}: {gear.lower()} мотор-редуктор"
           + (f", i {ratio}" if ratio else "")
           + (f", {power} кВт" if power else "")
           + f". Российский аналог {zr} — Завод Редукторов (ООО «НИИ АТТ»), Челябинск.")
    return title, cap


def main():
    slugs = sorted(os.path.basename(p)[:-5] for p in glob.glob("analog/*.html"))
    rows = []
    skipped = 0
    for slug in slugs:
        ph = photo_for(slug)
        if not ph:
            skipped += 1
            continue
        loc = f"{BASE}/analog/{slug}"
        img = f"{BASE}/assets/cards-photo/{ph}.webp"
        title, cap = descr(ph)
        rows.append(
            f"  <url><loc>{loc}</loc>\n"
            f"    <image:image><image:loc>{img}</image:loc>"
            f"<image:title>{escape(title)}</image:title>"
            f"<image:caption>{escape(cap)}</image:caption></image:image>\n"
            f"  </url>")

    files = []
    for i in range(0, len(rows), PER):
        idx = i // PER + 1
        fn = f"sitemap-images-analog-{idx}.xml"
        body = ("<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
                "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\" "
                "xmlns:image=\"http://www.google.com/schemas/sitemap-image/1.1\">\n"
                + "\n".join(rows[i:i + PER]) + "\n</urlset>\n")
        open(fn, "w", encoding="utf-8").write(body)
        files.append(fn)
    # Устаревшие части от прошлых запусков: если карточек стало меньше или
    # изменился размер части, лишние файлы оставались и на диске, и в индексе —
    # робот продолжал их запрашивать. Убираем всё, чего нет в текущем наборе.
    lishnie = [f for f in sorted(glob.glob("sitemap-images-analog-*.xml")) if f not in files]
    for f in lishnie:
        os.remove(f)

    # Регистрация в sitemap-index.xml: строки этого набора пересобираем целиком,
    # а не дописываем — иначе записи об удалённых частях остаются навсегда.
    idx_path = "sitemap-index.xml"
    idx = open(idx_path, encoding="utf-8").read()
    idx = re.sub(r"[ \t]*<sitemap><loc>[^<]*sitemap-images-analog-\d+\.xml</loc>.*?</sitemap>\n?",
                 "", idx)
    add = "".join(f"  <sitemap><loc>{BASE}/{fn}</loc></sitemap>\n" for fn in files)
    idx = idx.replace("</sitemapindex>", add + "</sitemapindex>")
    open(idx_path, "w", encoding="utf-8").write(idx)

    krupno = [(f, os.path.getsize(f)) for f in files if os.path.getsize(f) > 10 * 1024 * 1024]
    print(f"страниц с фото: {len(rows)} | без фото: {skipped}")
    print(f"файлов: {len(files)} | удалено устаревших: {len(lishnie)}")
    for f, r in krupno:
        print(f"  ВНИМАНИЕ: {f} — {r/1048576:.1f} МБ, больше предела Яндекса 10 МБ")


if __name__ == "__main__":
    main()
