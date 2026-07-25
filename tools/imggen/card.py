#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Сборка каталожной карточки редуктора: фото + таблица характеристик + брендинг.

Фото рисует нейросеть, всё остальное впечатывается здесь — таблица берётся
из данных каталога, логотипы и знаки из assets, водяной знак тайлом.
Так цифры честные, кириллица не плывёт, а 751 карточка выходит одинаковой.

Вёрстка повторяет эталонную карточку: слева белая панель с таблицей,
справа изделие, шапка с логотипом бренда и ZR, внизу знаки соответствия.

Рендер на Pillow — Chrome headless в этом окружении не поднимается,
и на партии в 751 штуку браузер на каждую карточку всё равно был бы дороже.

Запуск:
  python3 card.py --model motovario-nmrv-050 --photo gen/worm.png
"""
import json
import os
import re
import sys

from PIL import Image, ImageDraw, ImageFont

BASE = os.environ.get("ZAVOD_BASE", "/Users/alexandr/projects/zavod-red-preview")
W, H = 1536, 1024

FONTS = "/System/Library/Fonts/Supplemental"
F_BOLD = os.path.join(FONTS, "Arial Bold.ttf")
F_REG = os.path.join(FONTS, "Arial.ttf")

NAVY = (18, 58, 114)
INK = (27, 36, 48)
GRID = (204, 212, 224)
RULE = (185, 195, 209)

PAD_L = 56
PANEL_W = int(W * 0.40)
TBL_W = 470
TBL_TOP = 232
ROW_H = 52
COL_K = int(TBL_W * 0.50)

# Типовые характеристики по типу передачи. Это конструктивные свойства класса,
# а не выдуманные цифры типоразмера: чугунный червячный редуктор всегда
# тише и менее эффективен, чем цилиндрический.
BY_GEAR = {
    "червячный": ("до 90 %", "Чугун / алюминий", "≤ 75 дБ(А)", "B3 / B5 / B14"),
    "соосно-цилиндрический": ("до 98 %", "Чугун", "≤ 80 дБ(А)", "B3 / B5"),
    "цилиндрический": ("до 98 %", "Чугун", "≤ 80 дБ(А)", "B3 / B5"),
    "плоско-цилиндрический": ("до 98 %", "Чугун", "≤ 82 дБ(А)", "Полый вал / лапы"),
    "плоский цилиндрический": ("до 98 %", "Чугун", "≤ 82 дБ(А)", "Полый вал / лапы"),
    "коническо-цилиндрический": ("до 97 %", "Чугун", "≤ 82 дБ(А)", "B3 / B5 / лапы"),
    "червячно-цилиндрический": ("до 92 %", "Чугун", "≤ 78 дБ(А)", "B3 / B5"),
    "планетарный": ("до 97 %", "Сталь / чугун", "≤ 85 дБ(А)", "Фланцевое"),
    "вариатор": ("до 90 %", "Чугун / алюминий", "≤ 80 дБ(А)", "B3 / B5"),
}
DEFAULT_GEAR = BY_GEAR["червячный"]
LUBE = "Мин. / синт. масло"
TEMP = "−20 °C … +40 °C"


def font(path, size):
    return ImageFont.truetype(path, size)


def load_cards():
    p = os.path.join(BASE, "assets", "import-catalog.json")
    d = json.load(open(p, encoding="utf-8"))
    return d["cards"], d["t"]


def strip_variant(slug):
    """Отбрасывает вариант -iX-…-…kvt, оставляя модель: bauer-bf-06-i25-… → bauer-bf-06."""
    return re.sub(r"-i[0-9].*$", "", slug)


def series_prefix(slug):
    """Модель без числа типоразмера: bonfiglioli-a-60 → bonfiglioli-a,
    watt-drive-a-56c → watt-drive-a. По нему ищем карточку той же серии,
    которая покрывает нужный типоразмер (в её поле m перечислены размеры)."""
    return re.sub(r"-\d+[a-z]*$", "", strip_variant(slug))


def slug_size(slug):
    """Число типоразмера в конце слага: bonfiglioli-a-60 → 60, nord-sk-2382 → 2382."""
    m = re.search(r"-(\d+)[a-z]*$", strip_variant(slug))
    return int(m.group(1)) if m else None


def brand_prefix_map(cards):
    """Для каждой фирмы — ведущие токены слага, относящиеся к бренду
    (watt-drive, tos-znojmo, sew…). Это самый длинный общий нечисловой
    префикс u всех карточек фирмы; на нём отделяем бренд от серии+типоразмера."""
    from collections import defaultdict
    groups = defaultdict(list)
    for c in cards:
        u = c.get("u", "")
        if u:
            groups[u.split("-")[0]].append(strip_variant(u).split("-"))
    out = {}
    for k, rows in groups.items():
        pref = []
        for i in range(min(len(r) for r in rows)):
            tok = rows[0][i]
            if any(ch.isdigit() for ch in tok):
                break
            if all(len(r) > i and r[i] == tok for r in rows):
                pref.append(tok)
            else:
                break
        out[k] = pref
    return out


def designation(slug, brand_map):
    """Обозначение типоразмера из самого слага — на случай, когда карточка
    каталога не перечисляет его номер (карточка-семейство): tos-znojmo-tz-6210
    → «TZ 6210», bonfiglioli-a-60 → «A 60». Так в строку «Типоразмер» не
    попадёт чужой номер из карточки соседней модели."""
    toks = strip_variant(slug).split("-")
    pref = brand_map.get(toks[0], [toks[0]])
    while pref and toks[:len(pref)] != pref:
        pref = pref[:-1]
    tail = toks[len(pref):]
    return " ".join(t.upper() for t in tail) if tail else strip_variant(slug).upper()


_RATIO_CACHE = os.path.join(os.path.dirname(os.path.abspath(__file__)),
                            ".ratio-index-v2.json")

# Реалистичный потолок передаточного числа по типу передачи. И имена вариантов
# /analog/ (…-i5240-…, …-i14767-…), и поле `i` каталога (…–967, …–14767)
# местами содержат мусорные и многоступенчатые значения, от которых верхняя
# граница улетает в неправдоподобие. Одноступенчатые редукторы всех
# цилиндрических/червячных рядов реально не превышают ~300; шире — только
# вариатор (перекрывает огромный диапазон) и многоступенчатый планетарный.
RATIO_CEIL_DEFAULT = 315
RATIO_CEIL_BY_GEAR = {"вариатор": 10 ** 6, "планетарный": 600}


def _ratio_ceiling(gear):
    return RATIO_CEIL_BY_GEAR.get(gear, RATIO_CEIL_DEFAULT)


def _clamp_ratio(ratio, gear):
    """Отсекает выбросы передаточного числа реалистичным пределом типа передачи.

    Значения из [1, потолок] оставляем как есть (строку не трогаем, чтобы не
    менять запятые на точки без нужды); если попались выбросы — пересобираем
    диапазон из уцелевших значений. Работает и с диапазоном из имён файлов,
    и с полем `i` каталога."""
    nums = re.findall(r"[0-9]+(?:[.,][0-9]+)?", str(ratio))
    if not nums:
        return ratio
    ceil = _ratio_ceiling(gear)
    vals = [float(n.replace(",", ".")) for n in nums]
    kept = [v for v in vals if 1 <= v <= ceil]
    if len(kept) == len(vals):
        return ratio                       # всё в пределах — строку не портим
    if not kept:
        return ratio                       # чистить нечем — оставляем как есть
    lo, hi = min(kept), max(kept)
    return f"{lo:g}–{hi:g}" if lo != hi else f"{lo:g}"


def ratio_index():
    """Истинные диапазоны передаточных чисел из имён файлов /analog/,
    с отсечкой неправдоподобных выбросов по типу передачи.

    В import-catalog.json поле `i` побито у 172 записей из 498: у NMRV 050
    стоит «8–1» вместо реальных 7,5–100. Имена вариантов (…-i7-5-…, …-i100-…)
    сохранили правду, поэтому диапазон пересобираем из них. Но там же попадаются
    мусорные i (…-i5240-…, …-i14767-…) — битые или комбинированные значения,
    от которых верхний предел раздувается (A-55 давал «7.21–5240»). Значения
    выше физического потолка серии отбрасываем.
    """
    if os.path.isfile(_RATIO_CACHE):
        return json.load(open(_RATIO_CACHE, encoding="utf-8"))

    from collections import defaultdict
    cards, types = load_cards()
    exact_g, series_g = {}, {}
    for c in cards:
        u = c.get("u", "")
        if not u:
            continue
        g = types[c["t"]] if isinstance(c.get("t"), int) else str(c.get("t"))
        exact_g.setdefault(strip_variant(u), g)
        series_g.setdefault(series_prefix(u), g)

    def gear_of_key(key):
        return exact_g.get(key) or series_g.get(series_prefix(key))

    vals = defaultdict(set)
    d = os.path.join(BASE, "analog")
    for f in os.listdir(d):
        if not f.endswith(".html") or f == "index.html":
            continue
        m = re.match(r"(.+?)-i([0-9]+(?:-[0-9]+)?)-", f)
        if not m:
            continue
        try:
            vals[m.group(1)].add(float(m.group(2).replace("-", ".")))
        except ValueError:
            pass

    idx = {}
    for model, v in vals.items():
        ceil = _ratio_ceiling(gear_of_key(model))
        kept = [x for x in v if 1 <= x <= ceil] or list(v)
        lo, hi = min(kept), max(kept)
        if lo != hi:
            idx[model] = f"{lo:g}–{hi:g}"
    json.dump(idx, open(_RATIO_CACHE, "w", encoding="utf-8"), ensure_ascii=False)
    return idx


def _clean(v):
    """Схлопывает пробелы и переносы в одну строку.

    В данных каталога у части моделей значения содержат перенос строки;
    Pillow textlength не умеет мерить многострочный текст и валит сборку
    шильдика/сертификата. Чистим на входе, чтобы все потребители получали
    однострочные значения."""
    return " ".join(str(v).split())


def normalize(c, types, ratios=None):
    gear = types[c["t"]] if isinstance(c.get("t"), int) else str(c.get("t", ""))
    slug = c.get("u", "")
    ratio = c.get("i", "")
    if ratios:
        key = strip_variant(slug)
        ratio = ratios.get(key, ratio)
    ratio = _clamp_ratio(ratio, gear)
    return {
        "brand": _clean(c.get("b", "")), "model": _clean(c.get("m", "")),
        "gear": gear, "zr": _clean(c.get("z", "")),
        "power": _clean(c.get("pw", "")), "ratio": _clean(ratio),
        "country": _clean(c.get("c", "")), "slug": slug,
    }


def find_card(slug, cards, types, ratios=None):
    """Карточка по slug модели. Одна карточка может покрывать несколько
    типоразмеров ('BF 06, BF 10, BF 20'), поэтому пробуем и префикс."""
    for c in cards:
        if re.sub(r"-i[0-9].*$", "", c.get("u", "")) == slug:
            return normalize(c, types, ratios)
    for c in cards:
        if c.get("u", "").startswith(slug):
            return normalize(c, types, ratios)
    return None


def rows_for(card):
    """11 строк. Момент и вес не выводим — их нет в данных, а подбор привода
    по выдуманной цифре опаснее короткой таблицы."""
    kpd, hull, noise, mount = BY_GEAR.get(card["gear"], DEFAULT_GEAR)
    r = [
        ("Тип редуктора", card["gear"].capitalize()),
        ("Типоразмер", card["model"]),
        ("Передаточное число (i)", card["ratio"]),
        ("Входная мощность (P1)", f"{card['power']} кВт" if card["power"] else ""),
        ("Монтажное исполнение", mount),
        ("КПД", kpd),
        ("Материал корпуса", hull),
        ("Уровень шума", noise),
        ("Смазка", LUBE),
        ("Рабочая температура", TEMP),
        ("Аналог «Завод Редукторов»", card["zr"]),
    ]
    return [(k, v) for k, v in r if v]


def fit(draw, text, fnt, maxw):
    """Ужимает строку под ширину ячейки — типоразмеры вида
    'BG 10X, BG 15, BG 20' длиннее колонки.

    Переносы в исходных данных схлопываем: textlength не умеет мерить
    многострочный текст и падает, а в ячейке всё равно одна строка.
    """
    text = " ".join(str(text).split())
    if draw.textlength(text, font=fnt) <= maxw:
        return text
    while text and draw.textlength(text + "…", font=fnt) > maxw:
        text = text[:-1]
    return text + "…"


def cover(img, w, h):
    """Вписывает фото в кадр по короткой стороне, как object-fit: cover."""
    src_r, dst_r = img.width / img.height, w / h
    if src_r > dst_r:
        nw, nh = int(h * src_r), h
    else:
        nw, nh = w, int(w / src_r)
    img = img.resize((nw, nh), Image.LANCZOS)
    return img.crop(((nw - w) // 2, (nh - h) // 2,
                     (nw - w) // 2 + w, (nh - h) // 2 + h))


def watermark(canvas, logo_path):
    """Повторяющийся тайл логотипа под наклоном, как в эталоне."""
    if not os.path.isfile(logo_path):
        return canvas
    logo = Image.open(logo_path).convert("RGBA")
    logo.thumbnail((240, 240), Image.LANCZOS)
    a = logo.split()[3].point(lambda p: int(p * 0.30))
    logo.putalpha(a)

    big = W * 2
    tile = Image.new("RGBA", (big, big), (0, 0, 0, 0))
    step_x, step_y = logo.width + 130, logo.height + 110
    for y in range(0, big, step_y):
        for x in range(0, big, step_x):
            tile.paste(logo, (x + (y // step_y % 2) * step_x // 2, y), logo)
    tile = tile.rotate(-8, resample=Image.BICUBIC)
    off = (big - W) // 2, (big - H) // 2
    canvas.alpha_composite(tile.crop((off[0], off[1], off[0] + W, off[1] + H)))
    return canvas


def panel(canvas):
    """Белая подложка слева, растворяется вправо."""
    grad = Image.new("L", (PANEL_W, 1))
    for x in range(PANEL_W):
        t = x / PANEL_W
        if t < 0.74:
            v = 252
        elif t < 0.88:
            v = int(252 - (t - 0.74) / 0.14 * 40)
        else:
            v = int(212 - (t - 0.88) / 0.12 * 212)
        grad.putpixel((x, 0), max(0, min(255, v)))
    mask = grad.resize((PANEL_W, H))
    white = Image.new("RGBA", (PANEL_W, H), (255, 255, 255, 255))
    white.putalpha(mask)
    canvas.alpha_composite(white, (0, 0))
    return canvas


def dewhite(img):
    """Вырезает белую подложку логотипа в прозрачность.

    Файлы в assets/brands/ сохранены без альфы, на светлой панели карточки
    у них виден белый прямоугольник. Порог мягкий, чтобы не съесть
    светло-серые элементы самого знака.
    """
    img = img.convert("RGBA")
    px = img.load()
    for y in range(img.height):
        for x in range(img.width):
            r, g, b, a = px[x, y]
            if r > 238 and g > 238 and b > 238:
                px[x, y] = (r, g, b, 0)
    return img


def paste_logo(canvas, path, box_h, xy, right=False, strip_white=False):
    if not os.path.isfile(path):
        return 0
    lg = Image.open(path)
    lg = dewhite(lg) if strip_white else lg.convert("RGBA")
    bbox = lg.split()[3].getbbox()          # обрезаем поля, чтобы знак был крупнее
    if bbox:
        lg = lg.crop(bbox)
    r = box_h / lg.height
    lg = lg.resize((max(1, int(lg.width * r)), box_h), Image.LANCZOS)
    x = xy[0] - lg.width if right else xy[0]
    canvas.alpha_composite(lg, (int(x), int(xy[1])))
    return lg.width


# Флаги стран-производителей из каталога: вертикальные триколоры и
# горизонтальные полосы рисуем сами — растровых ассетов под них нет.
FLAGS = {
    "Италия": ("v", [(0, 140, 69), (255, 255, 255), (206, 43, 55)]),
    "Германия": ("h", [(0, 0, 0), (221, 0, 0), (255, 206, 0)]),
    "Турция": ("s", [(227, 10, 23)]),
    "Австрия": ("h", [(237, 41, 57), (255, 255, 255), (237, 41, 57)]),
    "Китай": ("s", [(238, 28, 37)]),
    "Чехия": ("h", [(255, 255, 255), (215, 20, 26)]),
}


# Предложный падеж для подписи «СДЕЛАНО В …» — иначе выходит «в Италия».
IN_COUNTRY = {
    "Италия": "ИТАЛИИ", "Германия": "ГЕРМАНИИ", "Турция": "ТУРЦИИ",
    "Австрия": "АВСТРИИ", "Китай": "КИТАЕ", "Чехия": "ЧЕХИИ",
}


def draw_flag(d, country, x, y, w=46, h=30):
    """Рисует флаг страны. Для «Импорт» флага нет — вернём 0."""
    spec = FLAGS.get(country)
    if not spec:
        return 0
    kind, colors = spec
    n = len(colors)
    for i, c in enumerate(colors):
        if kind == "v":
            d.rectangle([x + i * w // n, y, x + (i + 1) * w // n, y + h], fill=c)
        elif kind == "h":
            d.rectangle([x, y + i * h // n, x + w, y + (i + 1) * h // n], fill=c)
        else:
            d.rectangle([x, y, x + w, y + h], fill=c)
    d.rectangle([x, y, x + w, y + h], outline=(150, 158, 170), width=1)
    return w


def build(card, photo_path, out_path):
    canvas = cover(Image.open(photo_path).convert("RGBA"), W, H)
    canvas = watermark(canvas, os.path.join(BASE, "assets", "zr_logo_dark.webp"))
    canvas = panel(canvas)

    d = ImageDraw.Draw(canvas)
    f_h1 = font(F_BOLD, 37)
    f_h2 = font(F_BOLD, 22)
    f_k = font(F_REG, 15)
    f_v = font(F_REG, 15)
    f_mark = font(F_BOLD, 30)
    f_small = font(F_BOLD, 14)
    f_country = font(F_BOLD, 16)

    d.text((PAD_L, 118), "ПРОМЫШЛЕННЫЙ РЕДУКТОР", font=f_h1, fill=NAVY)
    d.text((PAD_L, 178), "ТЕХНИЧЕСКИЕ ХАРАКТЕРИСТИКИ", font=f_h2, fill=NAVY)

    y = TBL_TOP
    for k, v in rows_for(card):
        d.rectangle([PAD_L, y, PAD_L + TBL_W, y + ROW_H], fill=(255, 255, 255, 140))
        d.rectangle([PAD_L, y, PAD_L + TBL_W, y + ROW_H], outline=GRID, width=1)
        d.line([PAD_L + COL_K, y, PAD_L + COL_K, y + ROW_H], fill=GRID, width=1)
        d.text((PAD_L + 13, y + ROW_H // 2 - 9),
               fit(d, k, f_k, COL_K - 26), font=f_k, fill=INK)
        d.text((PAD_L + COL_K + 13, y + ROW_H // 2 - 9),
               fit(d, str(v), f_v, TBL_W - COL_K - 26), font=f_v, fill=INK)
        y += ROW_H

    brand_slug = card["slug"].split("-")[0] if card["slug"] else ""
    blogo = os.path.join(BASE, "assets", "brands", brand_slug + ".webp")
    if os.path.isfile(blogo):
        paste_logo(canvas, blogo, 62, (PAD_L, 30), strip_white=True)
    else:
        d.text((PAD_L, 36), card["brand"], font=font(F_BOLD, 32), fill=NAVY)
    paste_logo(canvas, os.path.join(BASE, "assets", "zr_logo_dark.webp"),
               76, (W - 44, 24), right=True)

    # подвал: знаки соответствия крупно, ниже — флаг страны происхождения
    fy = H - 128
    x = PAD_L
    d.text((x, fy), "CE", font=f_mark, fill=NAVY)
    x += 78
    d.text((x, fy - 3), "ISO", font=f_mark, fill=NAVY)
    d.text((x + 2, fy + 26), "9001", font=f_small, fill=NAVY)
    x += 86
    d.text((x, fy), "EAC", font=f_mark, fill=NAVY)

    fw = draw_flag(d, card["country"], PAD_L, H - 62)
    where = IN_COUNTRY.get(card["country"])
    label = f"СДЕЛАНО В {where}" if where else card["country"].upper()
    d.text((PAD_L + (fw + 18 if fw else 0), H - 55), label,
           font=f_country, fill=INK)

    canvas.convert("RGB").save(out_path, quality=92)
    return out_path


def main():
    args = sys.argv[1:]
    cards, types = load_cards()
    here = os.path.dirname(os.path.abspath(__file__))

    ratios = ratio_index()
    slug = args[args.index("--model") + 1] if "--model" in args else None
    card = (find_card(slug, cards, types, ratios) if slug
            else normalize(cards[0], types, ratios))
    if not card:
        sys.exit(f"нет данных по модели {slug}")

    photo = args[args.index("--photo") + 1] if "--photo" in args else "gen/worm.png"
    if not os.path.isabs(photo):
        photo = os.path.join(here, photo)

    out = args[args.index("--out") + 1] if "--out" in args else "card_demo.jpg"
    if not os.path.isabs(out):
        out = os.path.join(here, out)

    build(card, photo, out)
    print(f"{card['brand']} {card['model']} → {out}")
    for k, v in rows_for(card):
        print(f"   {k}: {v}")


if __name__ == "__main__":
    main()
