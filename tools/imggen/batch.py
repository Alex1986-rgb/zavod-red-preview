#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Партия каталожных фото: одно фото на пару «бренд + тип передачи».

Сайт подключает картинки ключом br-{бренд}-t{тип}, одну на все модели этой
пары, поэтому на 751 модель нужен 61 файл, а не 751. Это и определяет объём
партии.

Каждое фото проходит: генерация (Higgsfield) → автопоиск пустых площадок
(шильдик и лист паспорта) → впечатка данных → webp под сайт → alt для SEO.
Состояние в manifest.json, повторный запуск продолжает с места остановки.

Запуск:
  python3 batch.py --plan              # что будет сделано, без трат
  python3 batch.py --generate 5        # сгенерировать первые 5
  python3 batch.py --process           # обработать скачанное
  python3 batch.py --status
"""
import json
import os
import re
import sys
from collections import defaultdict

import numpy as np
from PIL import Image

HERE = os.path.dirname(os.path.abspath(__file__))
BASE = os.environ.get("ZAVOD_BASE", "/Users/alexandr/projects/zavod-red-preview")
RAW = os.path.join(HERE, "batch", "raw")
OUT = os.path.join(HERE, "batch", "out")
MANIFEST = os.path.join(HERE, "batch", "manifest.json")

SITE_W, SITE_H = 1200, 800
STAMP = os.environ.get("ZAVOD_STAMP") == "1"

# Геометрия по типу передачи — то, что делает редуктор узнаваемым.
# Формулировки проверены на тестовой партии: без явного «перпендикулярно»
# модель рисует червячный редуктор как соосный.
GEOMETRY = {
    "червячный": (
        "compact square worm gearbox housing with deep cooling fins on all "
        "sides, electric motor bolted to one face, hollow output shaft passing "
        "through the body at a 90 degree right angle to the motor axis, four "
        "mounting feet, machined output flange with bolt circle"),
    "соосно-цилиндрический": (
        "inline helical gearmotor: motor and gearbox bolted in one straight "
        "line on the SAME axis forming an elongated cylindrical barrel, output "
        "shaft protruding straight out of the front end face on that same "
        "axis, round output flange, mounting feet, no right angle bend"),
    "цилиндрический": (
        "inline helical gearmotor: motor and gearbox on the same axis, "
        "elongated cylindrical body, output shaft straight out of the front "
        "face, round bearing flange, mounting feet"),
    "плоско-цилиндрический": (
        "flat parallel shaft gearbox: wide slim shallow rectangular housing, "
        "large HOLLOW output bore through the centre of the wide flat side "
        "face, shrink disc clamping collar, torque arm bracket, electric motor "
        "mounted parallel alongside the flat body"),
    "коническо-цилиндрический": (
        "bevel helical right angle gearmotor: L-shaped heavy ribbed casting, "
        "output shaft exits at a 90 degree right angle to the electric motor, "
        "large round output bearing hub with bolt circle, mounting feet"),
    "червячно-цилиндрический": (
        "ONE single combined worm-and-helical gearmotor, exactly one machine "
        "in frame: motor, then a round helical pre-stage barrel, then a square "
        "finned worm housing, all bolted into one body, output shaft at a 90 "
        "degree right angle to the motor"),
    "планетарный": (
        "planetary gear reducer: short heavy squat cylindrical barrel housing, "
        "thick machined output flange with a circular bolt hole pattern, "
        "splined output shaft in the centre, round input flange at the rear"),
    "вариатор": (
        "mechanical variable speed drive: cylindrical variator housing with a "
        "round knurled speed adjustment handwheel on top, coupled in line to a "
        "gearbox body and an electric motor, mounting feet"),
}

SCENE = (
    "The unit stands on a wooden pallet on a light grey concrete factory "
    "floor, pale wooden crates and cardboard boxes blurred in the background, "
    "soft natural daylight from the left. Industrial blue painted cast "
    "housing, machined bare metal flanges, stainless steel bolts. Shot on "
    "Canon EOS R5, 85mm lens, f/5.6, extremely sharp, ultra detailed, visible "
    "cast metal grain and paint texture.")

BLANKS = (
    "BLANK AREA 1 — DATA PLATE: a large rectangular polished stainless steel "
    "nameplate riveted flat onto the side of the housing, angled towards the "
    "camera, fully visible, sharp, well lit. Its surface is COMPLETELY BLANK "
    "brushed metal — no text, no engraving, nothing, just four rivets in the "
    "corners. "
    "BLANK AREA 2 — THE DOCUMENT: in the foreground, lying flat on the pallet "
    "boards in front of the unit, a single crisp sheet of BLANK WHITE A4 "
    "PAPER, tilted slightly, its whole surface visible, brightly lit, sharp. "
    "The sheet is PERFECTLY EMPTY WHITE — no text, no printing, no lines, no "
    "logo, no seal, nothing at all on it.")

COMPOSITION = (
    "COMPOSITION: the unit, the crate and the paper are all inside the RIGHT "
    "two thirds of the frame; the LEFT third shows only empty blurred concrete "
    "floor with clear empty space. "
    "ABSOLUTELY NO TEXT ANYWHERE IN THE IMAGE — no letters, no numbers, no "
    "logos, no branding, no watermarks, no writing on the paper, the plate, "
    "the crate or the boxes.")


def build_prompt(gear):
    geom = GEOMETRY.get(gear, GEOMETRY["червячный"])
    return (f"Ultra sharp professional catalogue product photograph of an "
            f"industrial gear reducer. GEOMETRY: {geom}. {SCENE} {BLANKS} "
            f"{COMPOSITION}")


BRAND_NAMES = {
    "bauer": "Bauer", "bonfiglioli": "Bonfiglioli", "innored": "INNORED",
    "innovari": "Innovari", "lenze": "Lenze", "motovario": "Motovario",
    "nord": "NORD", "rossi": "Rossi", "sew": "SEW-Eurodrive", "siti": "SITI",
    "stm": "STM", "tos": "TOS Znojmo", "tramec": "Tramec",
    "transtecno": "Transtecno", "varmec": "Varmec", "varvel": "Varvel",
    "vemper": "Vemper", "watt": "WATT Drive", "yilmaz": "Yilmaz",
}


def jobs():
    """Список заданий: одна пара «бренд + тип» = одно фото."""
    d = json.load(open(os.path.join(BASE, "assets", "import-catalog.json"),
                       encoding="utf-8"))
    types = d["t"]
    groups = defaultdict(list)
    for c in d["cards"]:
        u = c.get("u") or ""
        if not u:
            continue
        groups[(u.split("-")[0], c["t"])].append(c)

    out = []
    for (brand, t), cards in groups.items():
        gear = types[t]
        models = [c.get("m", "") for c in cards if c.get("m")]
        out.append({
            "key": f"br-{brand}-t{t}",
            "brand_slug": brand,
            "brand": BRAND_NAMES.get(brand, brand.upper()),
            "gear": gear,
            "count": len(cards),
            "sample": cards[0],
            "models": models[:6],
            "prompt": build_prompt(gear),
        })
    out.sort(key=lambda j: -j["count"])
    return out


def alt_text(job):
    """Alt для SEO: бренд, тип передачи, назначение. Без «фото 1/2/3»."""
    return (f"{job['brand']} — {job['gear']} мотор-редуктор, "
            f"аналог редукторов ZR «Завод Редукторов»")


def title_text(job):
    return f"{job['brand']} {job['gear']} мотор-редуктор — фото"


# ── автопоиск пустых площадок ────────────────────────────────────────────────

def components(mask):
    """Связные области маски (4-связность) — обходом в ширину.

    Первая версия детектора искала площадку по медиане всех светлых пикселей
    и стабильно попадала в бетонный пол: он светлый, малонасыщенный и занимает
    полкадра. Без разбора на связные области отличить лист от пола нельзя.
    """
    h, w = mask.shape
    seen = np.zeros((h, w), dtype=bool)
    out = []
    for sy in range(0, h, 2):
        for sx in range(0, w, 2):
            if not mask[sy, sx] or seen[sy, sx]:
                continue
            stack = [(sy, sx)]
            seen[sy, sx] = True
            pts = []
            while stack:
                y, x = stack.pop()
                pts.append((y, x))
                for ny, nx in ((y - 1, x), (y + 1, x), (y, x - 1), (y, x + 1)):
                    if 0 <= ny < h and 0 <= nx < w and mask[ny, nx] \
                            and not seen[ny, nx]:
                        seen[ny, nx] = True
                        stack.append((ny, nx))
            if len(pts) < 60:
                continue
            ys = np.array([p[0] for p in pts])
            xs = np.array([p[1] for p in pts])
            y0, y1, x0, x1 = ys.min(), ys.max(), xs.min(), xs.max()
            bw, bh = x1 - x0 + 1, y1 - y0 + 1
            out.append({
                "area": len(pts), "fill": len(pts) / (bw * bh),
                "box": (x0 / w, y0 / h, x1 / w, y1 / h),
                "aspect": bw / bh, "cy": ys.mean() / h,
            })
    return out


def find_regions(path):
    """Ищет на фото пустой шильдик и лист паспорта.

    Шильдик — малонасыщенный светлый прямоугольник на синем корпусе.
    Лист — крупное белое пятно в нижней половине кадра. Отбираем связные
    области по заполненности bbox (прямоугольность), пропорциям и площади,
    поэтому пол и блики не проходят.
    """
    im = Image.open(path).convert("RGB")
    im.thumbnail((360, 360), Image.BILINEAR)
    a = np.asarray(im).astype(np.int16)
    h, w, _ = a.shape
    total = h * w
    sat = a.max(axis=2) - a.min(axis=2)
    val = a.mean(axis=2)

    def pick(mask, lo, hi, aspect_rng, fill_min):
        cand = []
        for c in components(mask):
            x0, y0, x1, y1 = c["box"]
            # композиция кладёт всё в правые две трети: пятно, дотянувшееся
            # до левого края, — это пол, а не площадка
            if x0 < 0.03 or (x1 - x0) > 0.62:
                continue
            if not (lo * total <= c["area"] <= hi * total):
                continue
            if c["fill"] < fill_min:
                continue
            if not aspect_rng[0] <= c["aspect"] <= aspect_rng[1]:
                continue
            cand.append(c)
        return max(cand, key=lambda c: c["area"])["box"] if cand else None

    # шильдик: металл на корпусе — светлее фона, но не белый, и не у низа кадра
    plate_mask = (sat < 44) & (val > 112) & (val < 240)
    plate_mask[int(h * 0.82):] = False
    plate_mask[:int(h * 0.08)] = False
    plate = pick(plate_mask, 0.0025, 0.07, (0.45, 2.8), 0.60)

    # лист бумаги: самое белое крупное пятно в нижней половине
    doc_mask = (sat < 30) & (val > 190)
    doc_mask[:int(h * 0.48)] = False
    doc = pick(doc_mask, 0.008, 0.22, (0.8, 5.0), 0.55)
    return plate, doc


def quad(rect, inset=0.06):
    """Прямоугольник в долях → четыре угла с небольшим отступом внутрь."""
    x0, y0, x1, y1 = rect
    dx, dy = (x1 - x0) * inset, (y1 - y0) * inset
    x0, y0, x1, y1 = x0 + dx, y0 + dy, x1 - dx, y1 - dy
    return [(x0, y0), (x1, y0), (x1, y1), (x0, y1)]


# Вариантов съёмки на каждый тип передачи. Фото зависит только от типа —
# корпус нейтральный, бренд появляется лишь на впечатанном шильдике, поэтому
# генерировать 61 отдельный кадр незачем. Варианты нужны, чтобы соседние
# бренды одного типа не выглядели одинаково.
VARIANTS = 3
ANGLES = [
    "three-quarter view from slightly above, output flange turned towards "
    "the camera",
    "low three-quarter view close to pallet level, output shaft towards "
    "the camera, motor visible behind",
    "side three-quarter view from the left, output flange and mounting feet "
    "clearly visible",
]


def variant_jobs():
    """16 базовых кадров: 4 типа передачи × 4 ракурса."""
    gears = sorted({j["gear"] for j in jobs()})
    out = []
    for g in gears:
        for v, angle in enumerate(ANGLES):
            out.append({
                "id": f"{slugify(g)}-v{v}",
                "gear": g,
                "variant": v,
                "prompt": build_prompt(g).replace(
                    "GEOMETRY:", f"VIEW: {angle}. GEOMETRY:"),
            })
    return out


def slugify(gear):
    return {
        "червячный": "worm",
        "соосно-цилиндрический": "coax",
        "цилиндрический": "coax",
        "плоско-цилиндрический": "flat",
        "коническо-цилиндрический": "bevel",
        "червячно-цилиндрический": "wormcyl",
        "планетарный": "planet",
        "вариатор": "vari",
    }.get(gear, "worm")


def pick_variant(key):
    """Стабильный выбор варианта по ключу — пересборка не тасует картинки."""
    import hashlib
    return int(hashlib.md5(key.encode()).hexdigest()[:8], 16) % VARIANTS


def load_manifest():
    try:
        return json.load(open(MANIFEST, encoding="utf-8"))
    except (OSError, ValueError):
        return {}


def save_manifest(m):
    os.makedirs(os.path.dirname(MANIFEST), exist_ok=True)
    json.dump(m, open(MANIFEST, "w", encoding="utf-8"),
              ensure_ascii=False, indent=1)


def main():
    args = sys.argv[1:]
    js = jobs()
    man = load_manifest()

    if "--plan" in args:
        need = [j for j in js if j["key"] not in man]
        have = os.listdir(os.path.join(BASE, "assets", "catalog"))
        print(f"пар «бренд + тип»: {len(js)}  (покрывают все модели каталога)")
        print(f"уже в manifest:    {len(js) - len(need)}")
        print(f"к генерации:       {len(need)}  → {len(need) * 2} кредитов (2K)")
        print(f"\nфайлов в assets/catalog сейчас: "
              f"{len([f for f in have if f.startswith('br-')])}")
        print("\nпо типам передачи:")
        c = defaultdict(int)
        for j in js:
            c[j["gear"]] += 1
        for g, n in sorted(c.items(), key=lambda x: -x[1]):
            print(f"  {n:3d} — {g}")
        print("\nпервые 10 заданий:")
        for j in js[:10]:
            print(f"  {j['key']:24s} {j['gear']:26s} {j['count']:3d} моделей")
            print(f"       alt: {alt_text(j)}")
        return

    if "--status" in args:
        done = [k for k, v in man.items() if v.get("out")]
        print(f"всего заданий: {len(js)}")
        print(f"готово:        {len(done)}")
        print(f"осталось:      {len(js) - len(done)}")
        return

    if "--variants" in args:
        vs = variant_jobs()
        print(f"базовых кадров: {len(vs)} → {len(vs) * 2} кредитов (2K)\n")
        for v in vs:
            print(f"--- {v['id']} ({v['gear']}) ---")
            print(v["prompt"])
            print()
        return

    if "--process" in args:
        process(js, man)
        return

    print("укажите --plan / --variants / --process / --status")


def process(js, man):
    """Собирает 61 картинку сайта из 16 базовых кадров: подбирает вариант,
    находит площадки, впечатывает данные бренда, сохраняет webp и alt."""
    sys.path.insert(0, HERE)
    import card as cardmod
    import stamp as stampmod

    os.makedirs(OUT, exist_ok=True)
    ratios = cardmod.ratio_index()
    cards, types = cardmod.load_cards()

    ok = skipped = 0
    for j in js:
        # Если выбранный вариант не сгенерировался, берём соседний того же
        # типа — тип передачи важнее разнообразия ракурсов.
        pref = pick_variant(j["key"])
        base = None
        for v in [pref] + [x for x in range(VARIANTS) if x != pref]:
            p = os.path.join(RAW, f"{slugify(j['gear'])}-v{v}.png")
            if os.path.isfile(p):
                base = p
                break
        if not base:
            skipped += 1
            continue

        c = cardmod.normalize(j["sample"], types, ratios)
        c["country_en"] = stampmod.COUNTRY_EN.get(c["country"], c["country"])
        c["mount"] = cardmod.BY_GEAR.get(c["gear"], cardmod.DEFAULT_GEAR)[3]
        import hashlib
        c["sn"] = str(int(hashlib.md5(c["slug"].encode()).hexdigest()[:8], 16)
                      % 900000 + 100000)

        im = Image.open(base).convert("RGBA")
        logo = os.path.join(BASE, "assets", "brands", j["brand_slug"] + ".webp")

        # Впечатка шильдика и паспорта в партии выключена намеренно.
        # find_regions() ловит площадки лишь на части кадров и даёт ложные
        # срабатывания на кожухе вентилятора и на бетоне: штамп, посаженный
        # мимо, портит фото сильнее, чем его отсутствие. Пустой шильдик на
        # каталожном снимке — нормальная практика. Для конкретного кадра
        # координаты задаются вручную через stamp.py, как в карточке-образце.
        marks = []
        if STAMP:
            plate, doc = find_regions(base)
            if plate:
                im = stampmod.paste_quad(
                    im, stampmod.plate_image(c),
                    [(x * im.width, y * im.height) for x, y in quad(plate)])
                marks.append("шильдик")
            if doc:
                im = stampmod.paste_quad(
                    im, stampmod.passport_image(c, logo),
                    [(x * im.width, y * im.height) for x, y in quad(doc, 0.03)])
                marks.append("паспорт")

        out = os.path.join(OUT, j["key"] + ".webp")
        im.convert("RGB").resize((SITE_W, SITE_H), Image.LANCZOS).save(
            out, "WEBP", quality=84, method=6)

        man[j["key"]] = {
            "out": os.path.basename(out),
            "gear": j["gear"], "brand": j["brand"], "models": j["count"],
            "alt": alt_text(j), "title": title_text(j),
            "stamped": marks,
            "kb": os.path.getsize(out) // 1024,
        }
        ok += 1
        print(f"  {j['key']:24s} {'+'.join(marks) or 'без впечатки':18s} "
              f"{man[j['key']]['kb']:3d} КБ")

    save_manifest(man)
    print(f"\nсобрано: {ok}, пропущено (нет базового кадра): {skipped}")


if __name__ == "__main__":
    main()
