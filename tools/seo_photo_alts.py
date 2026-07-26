#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""SEO-обогащение фото на карточках /analog/ под топ выдачи.

У страниц уже есть фото модели (place_card_photos.py) и базовый alt
«{Бренд} {Модель} — {тип} мотор-редуктор». Здесь добавляем то, чего не хватает
для картиночного SEO:
  1) в alt главного фото и первой миниатюры — ключ «, аналог ZR NNN»;
  2) og:image → абсолютный URL фото модели (+ og:image:alt);
  3) поле "image" в Product-схеме (JSON-LD) → фото модели.

Данные модели (zr, тип) берём тем же сопоставлением слаг→модель, что и
place_card_photos (самый длинный префикс). Идемпотентно: маркер <!--photoseo-->.

    python3 tools/seo_photo_alts.py --dry 500     # охват без записи
    python3 tools/seo_photo_alts.py               # применить ко всем
"""
import os, re, sys, glob
from collections import Counter

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
os.chdir(ROOT)
sys.path.insert(0, os.path.join(ROOT, "tools", "imggen"))

DRY = "--dry" in sys.argv
LIMIT = next((int(a) for a in sys.argv[1:] if a.isdigit()), None)
MARK = "<!--photoseo-->"
BASE = "https://zavod-red.ru"

# данные модели по слагу-фото: zr, gear
import oneshot as O                       # noqa: E402
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


def zr_of(photo):
    c = CARDS.get(photo)
    if not c:
        return None
    zr = (c.get("zr") or "").strip()
    return zr or None


def enrich_alt(tag, zr):
    """Добавить ', аналог ZR NNN' в alt, если ключа ещё нет."""
    m = re.search(r'\balt="([^"]*)"', tag)
    if not m:
        return tag
    alt = m.group(1)
    if "аналог" in alt.lower() or not zr:
        return tag
    new_alt = f"{alt}, аналог {zr}"
    return tag[:m.start(1)] + new_alt + tag[m.end(1):]


def transform(path):
    slug = os.path.basename(path)[:-5]
    photo = photo_for(slug)
    if photo is None:
        return "no-photo"
    t = open(path, encoding="utf-8").read()
    if MARK in t:
        return "already"
    zr = zr_of(photo)
    url = f"{BASE}/assets/cards-photo/{photo}.webp"
    changed = False

    # 1) alt главного фото p2img
    def do_p2(mo):
        return enrich_alt(mo.group(0), zr)
    t2 = re.sub(r'<img[^>]*id="p2img"[^>]*>', do_p2, t, count=1)
    changed |= t2 != t
    t = t2
    # альт первой миниатюры с этим же фото
    def do_thumb(mo):
        return enrich_alt(mo.group(0), zr)
    t2 = re.sub(r'<img[^>]*src="\.\./assets/cards-photo/' + re.escape(photo) + r'\.webp"[^>]*>',
                do_thumb, t)
    changed |= t2 != t
    t = t2

    # 2) og:image → фото модели (+ og:image:alt)
    def do_og(mo):
        return f'<meta property="og:image" content="{url}" />'
    t2, n = re.subn(r'<meta property="og:image" content="[^"]*"\s*/?>', do_og, t, count=1)
    if n:
        changed = True
        if 'property="og:image:alt"' not in t2:
            alt_txt = f"{CARDS.get(photo,{}).get('brand','')} — редуктор, аналог {zr or ''}".strip(" —")
            t2 = t2.replace(do_og(None),
                            do_og(None) + f'\n<meta property="og:image:alt" content="{alt_txt}" />', 1)
    t = t2

    # 3) "image" в Product-схему
    if re.search(r'"@type"\s*:\s*"Product"', t) and not re.search(
            r'"@type"\s*:\s*"Product"\s*,\s*"image"', t):
        t2 = re.sub(r'("@type"\s*:\s*"Product"\s*,\s*)',
                    r'\1"image": "' + url + r'", ', t, count=1)
        changed |= t2 != t
        t = t2

    if not changed:
        return "nochange"
    t = t.replace("</head>", MARK + "</head>", 1)
    if not DRY:
        open(path, "w", encoding="utf-8").write(t)
    return "ok"


if __name__ == "__main__":
    files = [a for a in sys.argv[1:] if a.endswith(".html")]
    if not files:
        files = sorted(glob.glob("analog/*.html"))
        if LIMIT:
            files = files[:LIMIT]
    st = Counter()
    for i, f in enumerate(files, 1):
        st[transform(f)] += 1
        if i % 20000 == 0:
            print("...", i, dict(st))
    print("итог:", dict(st))
    if DRY:
        print("(сухой прогон — без записи)")
