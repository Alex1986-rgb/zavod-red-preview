#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Каталожная карточка на КАЖДУЮ из 751 модели /analog/.

Одобренный заказчиком вид (референс): крупное изделие, слева таблица,
шапка с логотипами бренда и ZR, водяные знаки, а на поддоне впечатанные
шильдик, сертификат ISO, буклет и карточка «MADE IN …».

Фото зависит только от ТИПА передачи — их четыре, под каждый свой эталонный
кадр в frames/. Модель различается впечатанными данными, поэтому 751 файл
собирается из четырёх кадров без единой новой генерации.

Соответствие модель → данные: одна карточка import-catalog покрывает
несколько типоразмеров (BF 06, BF 10, BF 20), поэтому ищем по префиксу slug.
Передаточное число берём из истинного индекса (в JSON поле битое).

Запуск:
  python3 render751.py --plan            # что будет собрано, без записи
  python3 render751.py --limit 8         # собрать первые 8 для проверки
  python3 render751.py                   # все 751
"""
import json
import os
import re
import sys
import hashlib

from PIL import Image

HERE = os.path.dirname(os.path.abspath(__file__))
BASE = os.environ.get("ZAVOD_BASE", os.path.dirname(os.path.dirname(HERE)))
sys.path.insert(0, HERE)

import card as cardmod           # noqa: E402
import stamp as stampmod        # noqa: E402
import frames as framesmod      # noqa: E402
import extra_data               # noqa: E402

OUT = os.path.join(BASE, "assets", "cards")
FRAMES = os.path.join(HERE, "frames")
SITE_W, SITE_H = 1200, 800

# Тип передачи → эталонный кадр. Все четыре кадра сняты в референсном виде
# с пятью площадками (шильдик, ящик, сертификат, буклет, флаг-карта).
GEAR_FRAME = {
    "червячный": "hero-worm",
    "соосно-цилиндрический": "hero-coax",
    "цилиндрический": "hero-coax",
    "плоско-цилиндрический": "hero-flat",
    "коническо-цилиндрический": "hero-bevel",
    "червячно-цилиндрический": "hero-worm",
    "планетарный": "hero-coax",
    "вариатор": "hero-coax",
}


def analog_models():
    """751 уникальная модель: имя файла /analog/ без -i… и мощности."""
    d = os.path.join(BASE, "analog")
    seen = set()
    for f in os.listdir(d):
        if not f.endswith(".html") or f == "index.html":
            continue
        seen.add(re.sub(r"-i[0-9].*$", "", f[:-5]))
    return sorted(seen)


def series_key(slug):
    """Ключ «бренд + серия» без типоразмера: bauer-bf-06 → bauer-bf.

    Одна карточка каталога покрывает несколько типоразмеров одной серии
    (BF 06, BF 10, BF 20 — три страницы /analog/, один slug bauer-bf-06),
    поэтому сопоставляем по серии, а не по полному префиксу."""
    base = re.sub(r"-i[0-9].*$", "", slug)
    parts = base.split("-")
    if len(parts) >= 2:
        return parts[0] + "-" + re.sub(r"\d.*$", "", parts[1])
    return base


def catalog_index(cards, types):
    """(полный префикс, ключ серии, (серия, размер)) → данные карточки.

    Индекс size сопоставляет типоразмер слага с карточкой ТОЙ ЖЕ серии, в
    поле m которой этот номер перечислен явно (A-55 покрывает «A 55, A 60»).
    Без него series-fallback на одно-буквенных сериях возвращал первую
    карточку ряда (bonfiglioli-a-60 → «A 20»)."""
    exact, series, size = {}, {}, {}
    for c in cards:
        u = c.get("u") or ""
        if not u:
            continue
        exact.setdefault(cardmod.strip_variant(u), c)
        series.setdefault(series_key(u), c)
        sp = cardmod.series_prefix(u)
        for n in re.findall(r"\d+", c.get("m", "")):
            size.setdefault((sp, int(n)), c)
    return {"exact": exact, "series": series, "size": size}


def match(model, idx):
    """Данные модели: точный префикс → карточка серии с этим типоразмером →
    серия → префикс-начало → доп. источник."""
    base = cardmod.strip_variant(model)
    if base in idx["exact"]:
        return idx["exact"][base]
    size = cardmod.slug_size(model)
    if size is not None:
        sc = idx["size"].get((cardmod.series_prefix(model), size))
        if sc is not None:                  # карточка серии, покрывающая размер
            return sc
    sk = series_key(model)
    if sk in idx["series"]:
        return idx["series"][sk]
    best = None
    for key, c in idx["exact"].items():
        if base.startswith(key) and (best is None or len(key) > len(best[0])):
            best = (key, c)
    if best:
        return best[1]
    return extra_data.lookup(base)          # 78 моделей вне import-catalog


def display_model(model, data, brand_map):
    """Типоразмер для впечатывания в карточку. Если карточка каталога не
    перечисляет номер слага (осталась карточка-семейство), берём обозначение
    из самого слага, чтобы не впечатать чужой номер соседней модели."""
    m = cardmod._clean(data.get("m", ""))
    size = cardmod.slug_size(model)
    nums = {int(x) for x in re.findall(r"\d+", m)}
    if size is not None and size not in nums:
        return cardmod.designation(model, brand_map)
    return m


def gear_of(d, types):
    """Тип передачи из карточки: индекс каталога или строка доп. источника."""
    t = d.get("t")
    return types[t] if isinstance(t, int) else str(t)


def enrich(card):
    card["country_en"] = stampmod.COUNTRY_EN.get(card["country"], card["country"])
    card["mount"] = cardmod.BY_GEAR.get(card["gear"], cardmod.DEFAULT_GEAR)[3]
    h = hashlib.md5(card["slug"].encode()).hexdigest()
    card["sn"] = str(int(h[:8], 16) % 900000 + 100000)
    return card


def px(quad, size):
    w, h = size
    return [(x * w, y * h) for x, y in quad]


def build_one(model, data, types, ratios, brand_map):
    card = cardmod.normalize(data, types, ratios)
    card["slug"] = model                      # ключ файла — сама модель
    card["model"] = display_model(model, data, brand_map)
    mkey = cardmod.strip_variant(model)       # i — из файлов самого слага
    if ratios and mkey in ratios:
        card["ratio"] = cardmod._clean(cardmod._clamp_ratio(ratios[mkey],
                                                            card["gear"]))
    card = enrich(card)

    frame_name = GEAR_FRAME.get(card["gear"], "hero-coax")
    fp = os.path.join(FRAMES, frame_name + ".webp")
    if not os.path.isfile(fp):
        return None, f"нет кадра {frame_name}"
    q = framesmod.QUADS.get(frame_name)
    if not q:
        return None, f"нет разметки {frame_name}"

    im = Image.open(fp).convert("RGBA")
    brand_slug = data.get("u", "").split("-")[0]
    logo = os.path.join(BASE, "assets", "brands", brand_slug + ".webp")

    if q.get("plate"):
        im = stampmod.paste_quad(im, stampmod.plate_image(card),
                                 px(q["plate"], im.size))
    if q.get("label"):
        im = stampmod.paste_quad(im, stampmod.label_image(card, logo),
                                 px(q["label"], im.size))
    if q.get("cert"):
        im = stampmod.paste_quad(im, stampmod.certificate_image(card),
                                 px(q["cert"], im.size))
    if q.get("broch"):
        im = stampmod.paste_quad(im, stampmod.brochure_image(card, logo),
                                 px(q["broch"], im.size))
    if q.get("flag"):
        im = stampmod.paste_quad(im, stampmod.flagcard_image(card),
                                 px(q["flag"], im.size))

    tmp = os.path.join(HERE, ".tmp-stamped.jpg")
    im.convert("RGB").save(tmp, quality=93)
    card_tmp = os.path.join(HERE, ".tmp-card.jpg")
    cardmod.build(card, tmp, card_tmp)
    out = os.path.join(OUT, model + ".webp")
    Image.open(card_tmp).convert("RGB").resize(
        (SITE_W, SITE_H), Image.LANCZOS).save(out, "WEBP", quality=84, method=6)
    return out, card["gear"]


def main():
    args = sys.argv[1:]
    cards, types = cardmod.load_cards()
    ratios = cardmod.ratio_index()
    idx = catalog_index(cards, types)
    brand_map = cardmod.brand_prefix_map(cards)
    models = analog_models()

    matched = [(m, match(m, idx)) for m in models]
    have = [(m, d) for m, d in matched if d]
    miss = [m for m, d in matched if not d]

    if "--plan" in args:
        from collections import Counter
        gears = Counter(gear_of(d, types) for _, d in have)
        frames_have = {f[:-5] for f in os.listdir(FRAMES) if f.endswith(".webp")}
        print(f"моделей /analog/:     {len(models)}")
        print(f"есть данные каталога: {len(have)}")
        print(f"без данных:           {len(miss)}")
        print("\nпо типам передачи:")
        for g, n in gears.most_common():
            fr = GEAR_FRAME.get(g, "?")
            ok = "✓" if fr in frames_have else "НЕТ КАДРА"
            print(f"  {n:4d}  {g:26s} → {fr}  {ok}")
        if miss[:5]:
            print("\nпримеры без данных:", miss[:5])
        return

    os.makedirs(OUT, exist_ok=True)
    limit = int(args[args.index("--limit") + 1]) if "--limit" in args else None
    todo = have[:limit] if limit else have

    force = "--force" in args
    done = err = skip = 0
    manifest = {}
    if os.path.isfile(os.path.join(HERE, "manifest-751.json")):
        manifest = json.load(open(os.path.join(HERE, "manifest-751.json"),
                                  encoding="utf-8"))
    for i, (m, d) in enumerate(todo, 1):
        dst = os.path.join(OUT, m + ".webp")
        gear = gear_of(d, types)
        label = display_model(m, d, brand_map)
        if not force and os.path.isfile(dst):
            manifest[m] = {"file": m + ".webp", "gear": gear,
                           "brand": d.get("b", ""),
                           "alt": f"{d.get('b','')} {label} — {gear} "
                                  f"редуктор, аналог ZR"}
            skip += 1
            continue
        try:
            out, gear = build_one(m, d, types, ratios, brand_map)
            if out is None:
                err += 1
                if err <= 10:
                    print(f"  ПРОПУСК {m}: {gear}")
                continue
            manifest[m] = {"file": os.path.basename(out), "gear": gear,
                           "brand": d.get("b", ""),
                           "alt": f"{d.get('b','')} {label} — {gear} "
                                  f"редуктор, аналог ZR"}
            done += 1
            if done % 50 == 0:
                json.dump(manifest, open(os.path.join(HERE, "manifest-751.json"),
                                         "w"), ensure_ascii=False)
                print(f"  {i}/{len(todo)} (готово {done}, пропущено {skip})…",
                      flush=True)
        except Exception as e:
            err += 1
            if err <= 10:
                print(f"  ОШИБКА {m}: {str(e)[:100]}")
    json.dump(manifest, open(os.path.join(HERE, "manifest-751.json"), "w"),
              ensure_ascii=False)
    print(f"\nсобрано: {done}, пропущено (уже было): {skip}, ошибок: {err}")


if __name__ == "__main__":
    main()
