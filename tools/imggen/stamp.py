#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Впечатка в сгенерированное фото: паспорт-шильдик на корпусе и логотип на ящике.

Нейросеть рисует текст и логотипы нечитаемой кашей, поэтому она оставляет
пустые площадки, а настоящие данные наносим здесь: маркировку — из карточки
каталога, логотип — из assets/brands/.

Площадки задаются четырьмя углами в долях кадра (0..1), поэтому одна и та же
разметка работает и на 2K, и на 4K версии одного кадра. Перспектива
учитывается: накладка трансформируется по четырём точкам.

Запуск:
  python3 stamp.py --photo gen/worm4k.png --model motovario-nmrv-050 \
      --plate 0.42,0.55 0.55,0.53 0.55,0.62 0.42,0.64 \
      --label 0.70,0.20 0.88,0.19 0.88,0.31 0.70,0.32
"""
import json
import os
import re
import sys

from PIL import Image, ImageDraw, ImageFont

BASE = os.environ.get("ZAVOD_BASE", "/Users/alexandr/projects/zavod-red-preview")
FONTS = "/System/Library/Fonts/Supplemental"
F_BOLD = os.path.join(FONTS, "Arial Bold.ttf")
F_REG = os.path.join(FONTS, "Arial.ttf")


def quad_coeffs(dst, src):
    """Коэффициенты для Image.transform(PERSPECTIVE): отображение dst → src."""
    m = []
    for (x, y), (u, v) in zip(dst, src):
        m.append([x, y, 1, 0, 0, 0, -u * x, -u * y])
        m.append([0, 0, 0, x, y, 1, -v * x, -v * y])
    # решаем 8x8 методом Гаусса без numpy — зависимостей и так хватает
    b = [c for (u, v) in src for c in (u, v)]
    n = 8
    for i in range(n):
        p = max(range(i, n), key=lambda r: abs(m[r][i]))
        m[i], m[p] = m[p], m[i]
        b[i], b[p] = b[p], b[i]
        d = m[i][i]
        if abs(d) < 1e-12:
            raise ValueError("вырожденный четырёхугольник")
        for j in range(i, n):
            m[i][j] /= d
        b[i] /= d
        for r in range(n):
            if r == i:
                continue
            f = m[r][i]
            if f:
                for j in range(i, n):
                    m[r][j] -= f * m[i][j]
                b[r] -= f * b[i]
    return b


def paste_quad(canvas, overlay, quad):
    """Кладёт overlay в четырёхугольник quad (пиксели) с учётом перспективы."""
    W, H = canvas.size
    src = [(0, 0), (overlay.width, 0), (overlay.width, overlay.height),
           (0, overlay.height)]
    coeffs = quad_coeffs(quad, src)
    warped = overlay.transform((W, H), Image.PERSPECTIVE, coeffs,
                               Image.BICUBIC)
    canvas.alpha_composite(warped)
    return canvas


def plate_image(card, w=600, h=920):
    """Паспорт редуктора: шапка с брендом и таблица параметров, как на шильдике.

    Пропорции вертикальные — под реальную площадку на корпусе; гравировка
    тёмная, полупрозрачная, чтобы читалась как травление по металлу,
    а не как наклеенная картинка.
    """
    im = Image.new("RGBA", (w, h), (0, 0, 0, 0))
    d = ImageDraw.Draw(im)
    ink = (32, 34, 38, 235)

    f_brand = ImageFont.truetype(F_BOLD, 62)
    f_k = ImageFont.truetype(F_BOLD, 40)
    f_v = ImageFont.truetype(F_REG, 40)
    f_foot = ImageFont.truetype(F_BOLD, 30)

    pad = 54
    d.text((pad, 60), card["brand"].upper(), font=f_brand, fill=ink)
    d.line([pad, 148, w - pad, 148], fill=ink, width=4)

    rows = [
        ("TYPE", card["model"]),
        ("i", card["ratio"]),
        ("P1", f"{card['power']} kW" if card["power"] else ""),
        ("IEC", card["mount"]),
        ("ZR", card["zr"]),
        ("S/N", card["sn"]),
    ]
    y = 186
    for k, v in rows:
        if not v:
            continue
        d.text((pad, y), k, font=f_k, fill=ink)
        d.text((pad + 130, y), str(v), font=f_v, fill=ink)
        y += 62

    d.line([pad, h - 108, w - pad, h - 108], fill=ink, width=3)
    d.text((pad, h - 92), "MADE IN " + card["country_en"].upper(),
           font=f_foot, fill=ink)
    return im


def label_image(card, brand_logo, w=900, h=620):
    """Транспортная этикетка на ящик: логотип бренда и модель."""
    im = Image.new("RGBA", (w, h), (0, 0, 0, 0))
    d = ImageDraw.Draw(im)
    ink = (22, 34, 60, 255)

    if brand_logo and os.path.isfile(brand_logo):
        lg = Image.open(brand_logo).convert("RGBA")
        px = lg.load()
        for yy in range(lg.height):                      # убираем белую плашку
            for xx in range(lg.width):
                r, g, b, a = px[xx, yy]
                if r > 238 and g > 238 and b > 238:
                    px[xx, yy] = (r, g, b, 0)
        bb = lg.split()[3].getbbox()
        if bb:
            lg = lg.crop(bb)
        r = min((w - 120) / lg.width, 260 / lg.height)
        lg = lg.resize((int(lg.width * r), int(lg.height * r)), Image.LANCZOS)
        im.alpha_composite(lg, ((w - lg.width) // 2, 70))
    else:
        f = ImageFont.truetype(F_BOLD, 90)
        tw = d.textlength(card["brand"].upper(), font=f)
        d.text(((w - tw) / 2, 110), card["brand"].upper(), font=f, fill=ink)

    f_m = ImageFont.truetype(F_BOLD, 68)
    tw = d.textlength(card["model"], font=f_m)
    d.text(((w - tw) / 2, h - 250), card["model"], font=f_m, fill=ink)

    f_c = ImageFont.truetype(F_BOLD, 40)
    t = "MADE IN " + card["country_en"].upper()
    tw = d.textlength(t, font=f_c)
    d.text(((w - tw) / 2, h - 150), t, font=f_c, fill=ink)
    return im


def passport_image(card, brand_logo, w=1240, h=1754):
    """Паспорт изделия — печатный лист, который кладём на поддон.

    Пропорции A4. Содержимое повторяет таблицу карточки, чтобы документ
    и характеристики на сайте не расходились: источник у них один.
    """
    im = Image.new("RGBA", (w, h), (252, 252, 250, 255))
    d = ImageDraw.Draw(im)
    ink = (24, 28, 36, 255)
    navy = (18, 58, 114, 255)
    grey = (120, 128, 140, 255)

    f_h1 = ImageFont.truetype(F_BOLD, 74)
    f_h2 = ImageFont.truetype(F_BOLD, 44)
    f_k = ImageFont.truetype(F_REG, 38)
    f_v = ImageFont.truetype(F_BOLD, 38)
    f_s = ImageFont.truetype(F_REG, 30)

    pad = 96
    if brand_logo and os.path.isfile(brand_logo):
        lg = Image.open(brand_logo).convert("RGBA")
        px = lg.load()
        for yy in range(lg.height):
            for xx in range(lg.width):
                r, g, b, a = px[xx, yy]
                if r > 238 and g > 238 and b > 238:
                    px[xx, yy] = (r, g, b, 0)
        bb = lg.split()[3].getbbox()
        if bb:
            lg = lg.crop(bb)
        r = min(420 / lg.width, 150 / lg.height)
        lg = lg.resize((int(lg.width * r), int(lg.height * r)), Image.LANCZOS)
        im.alpha_composite(lg, (pad, pad))

    d.text((pad, pad + 200), "ПАСПОРТ ИЗДЕЛИЯ", font=f_h1, fill=navy)
    d.text((pad, pad + 292), f"{card['brand']} {card['model']}",
           font=f_h2, fill=ink)
    d.line([pad, pad + 372, w - pad, pad + 372], fill=navy, width=5)

    rows = [
        ("Тип редуктора", card["gear"].capitalize()),
        ("Типоразмер", card["model"]),
        ("Передаточное число (i)", card["ratio"]),
        ("Входная мощность (P1)", f"{card['power']} кВт" if card["power"] else ""),
        ("Монтажное исполнение", card["mount"]),
        ("Страна происхождения", card["country"]),
        ("Аналог «Завод Редукторов»", card["zr"]),
        ("Серийный номер", card["sn"]),
    ]
    y = pad + 420
    for k, v in rows:
        if not v:
            continue
        d.text((pad, y), k, font=f_k, fill=grey)
        d.text((pad + 620, y), str(v), font=f_v, fill=ink)
        d.line([pad, y + 62, w - pad, y + 62], fill=(226, 230, 236, 255), width=2)
        y += 86

    d.text((pad, y + 40), "Соответствует ТР ТС 010/2011", font=f_s, fill=ink)
    d.text((pad, y + 86), "Гарантия 12 месяцев со дня продажи", font=f_s, fill=ink)

    d.line([pad, h - 190, w - pad, h - 190], fill=navy, width=3)
    d.text((pad, h - 168), "ООО «НИИ АТТ» — Завод Редукторов",
           font=ImageFont.truetype(F_BOLD, 34), fill=navy)
    d.text((pad, h - 118), "zavod-red.ru", font=f_s, fill=grey)
    return im


def certificate_image(card, w=1240, h=1754):
    """Сертификат соответствия — лист на поддоне рядом с изделием.

    Первая версия читалась как нарисованная: плоский белый лист с парой строк.
    Настоящий бланк узнаётся по мелочам, поэтому здесь есть гильошная сетка,
    рельефная печать с лучами, ленты, две подписи от руки и блок
    аккредитации. Тон бумаги тёплый, не чисто белый — иначе на фото лист
    выглядит вставленным прямоугольником.
    """
    import math
    paper = (250, 248, 242, 255)
    im = Image.new("RGBA", (w, h), paper)
    d = ImageDraw.Draw(im)
    navy = (20, 48, 96, 255)
    gold = (172, 132, 48, 255)
    ink = (38, 42, 52, 255)
    grey = (122, 128, 140, 255)
    hair = (206, 214, 228, 255)

    # гильош: тонкая волновая сетка по краям, как защитная печать бланка
    guilloche = Image.new("RGBA", (w, h), (0, 0, 0, 0))
    gd = ImageDraw.Draw(guilloche)
    for k in range(46):
        amp = 16 + (k % 7) * 3
        ph = k * 0.42
        for band_y in (150, h - 150):
            pts = []
            for x in range(60, w - 60, 6):
                pts.append((x, band_y + math.sin(x / 46.0 + ph) * amp
                            + math.cos(x / 121.0 + ph) * amp * 0.45))
            gd.line(pts, fill=(150, 172, 206, 42), width=1)
    im.alpha_composite(guilloche)

    # рамки с уголками
    d.rectangle([48, 48, w - 48, h - 48], outline=navy, width=7)
    d.rectangle([66, 66, w - 66, h - 66], outline=gold, width=2)
    for cx0, cy0, sx, sy in ((66, 66, 1, 1), (w - 66, 66, -1, 1),
                             (66, h - 66, 1, -1), (w - 66, h - 66, -1, -1)):
        d.line([cx0, cy0 + sy * 54, cx0 + sx * 54, cy0 + sy * 54], fill=gold, width=2)
        d.line([cx0 + sx * 54, cy0, cx0 + sx * 54, cy0 + sy * 54], fill=gold, width=2)

    def centre(text, y, fnt, fill, spacing=0):
        if spacing:
            total = sum(d.textlength(ch, font=fnt) + spacing for ch in text) - spacing
            x = (w - total) / 2
            for ch in text:
                d.text((x, y), ch, font=fnt, fill=fill)
                x += d.textlength(ch, font=fnt) + spacing
        else:
            d.text(((w - d.textlength(text, font=fnt)) / 2, y), text,
                   font=fnt, fill=fill)

    centre("CERTIFICATE OF REGISTRATION", 150, ImageFont.truetype(F_REG, 26),
           grey, spacing=5)
    centre("CERTIFICATE", 202, ImageFont.truetype(F_BOLD, 90), navy)
    d.line([300, 322, w - 300, 322], fill=gold, width=3)

    centre("This is to certify that the Quality Management System of",
           360, ImageFont.truetype(F_REG, 27), grey)
    suffix = {"Италия": " S.p.A.", "Германия": " GmbH",
              "Австрия": " GmbH", "Турция": " A.\u015e."}.get(card["country"], "")
    centre(card["brand"] + suffix, 408, ImageFont.truetype(F_BOLD, 52), ink)
    centre("has been assessed and registered as conforming to",
           486, ImageFont.truetype(F_REG, 25), grey)
    centre("ISO 9001:2015", 528, ImageFont.truetype(F_BOLD, 74), navy)

    d.line([230, 640, w - 230, 640], fill=hair, width=2)
    centre("ОБЛАСТЬ СЕРТИФИКАЦИИ", 664, ImageFont.truetype(F_BOLD, 26), navy)
    f_s = ImageFont.truetype(F_REG, 28)
    rows = [
        ("Изделие", f"{card['gear']} редуктор {card['model']}"),
        ("Аналог ZR", card["zr"]),
        ("Страна", card["country"]),
        ("Сертификат №", f"RU.{card['sn']}.QMS"),
    ]
    y = 716
    for k, v in rows:
        d.text((236, y), k, font=f_s, fill=grey)
        d.text((520, y), str(v), font=f_s, fill=ink)
        y += 50
    d.line([230, y + 14, w - 230, y + 14], fill=hair, width=2)

    # рельефная печать с лучами и лентами
    cx, cy, r = w // 2, h - 520, 108
    for rr, col in ((r, (198, 164, 82, 255)), (r - 12, gold), (r - 34, gold)):
        d.ellipse([cx - rr, cy - rr, cx + rr, cy + rr], outline=col, width=4)
    for k in range(36):
        an = math.radians(k * 10)
        d.line([cx + math.cos(an) * (r - 34), cy + math.sin(an) * (r - 34),
                cx + math.cos(an) * (r - 14), cy + math.sin(an) * (r - 14)],
               fill=(200, 172, 104, 200), width=3)
    f_seal = ImageFont.truetype(F_BOLD, 30)
    d.text((cx - d.textlength("ISO", font=f_seal) / 2, cy - 34), "ISO",
           font=f_seal, fill=gold)
    f_seal2 = ImageFont.truetype(F_BOLD, 24)
    d.text((cx - d.textlength("9001", font=f_seal2) / 2, cy + 4), "9001",
           font=f_seal2, fill=gold)
    for sx in (-1, 1):
        d.polygon([(cx + sx * 34, cy + r - 26), (cx + sx * 70, cy + r + 74),
                   (cx + sx * 30, cy + r + 60)], fill=(168, 34, 44, 235))

    # подписи: линия, росчерк, должность
    f_sig = ImageFont.truetype(F_REG, 24)
    x0 = 196
    d.line([x0, h - 268, x0 + 330, h - 268], fill=ink, width=2)
    d.text((x0, h - 254), "Руководитель органа", font=f_sig, fill=grey)
    # росчерк: несколько петель разной амплитуды — ровная синусоида читалась
    # как график, а не как подпись
    pts = []
    for i in range(90):
        t = i / 89.0
        pts.append((x0 + 18 + t * 268,
                    h - 292 - math.sin(t * 11.0) * (20 - t * 9)
                    - math.sin(t * 3.4 + 1.1) * 13 + math.sin(t * 27) * 3))
    d.line(pts, fill=(28, 42, 92, 225), width=3, joint="curve")

    x1 = w - 196 - 330
    d.line([x1, h - 268, x1 + 330, h - 268], fill=ink, width=2)
    d.text((x1, h - 254), "Дата выдачи", font=f_sig, fill=grey)
    # дата детерминирована от номера: пересборка не меняет бланк
    day = 1 + int(card["sn"]) % 28
    month = 1 + (int(card["sn"]) // 28) % 12
    d.text((x1 + 60, h - 306), f"{day:02d}.{month:02d}.2024",
           font=ImageFont.truetype(F_REG, 34), fill=ink)

    # блок аккредитации
    d.rectangle([w // 2 - 96, h - 190, w // 2 + 96, h - 118], outline=navy, width=3)
    centre("IAF", h - 182, ImageFont.truetype(F_BOLD, 40), navy)
    centre("ACCREDITED", h - 138, ImageFont.truetype(F_BOLD, 18), navy, spacing=2)

    # лёгкое зерно бумаги, чтобы лист не читался как вставленный прямоугольник
    import random
    rnd = random.Random(int(card["sn"]))
    grain = Image.new("RGBA", (w, h), (0, 0, 0, 0))
    gp = grain.load()
    for _ in range(w * h // 260):
        gx, gy = rnd.randrange(w), rnd.randrange(h)
        v = rnd.randrange(18)
        gp[gx, gy] = (120, 116, 104, v)
    im.alpha_composite(grain)
    return im


def brochure_image(card, brand_logo, w=1000, h=1400):
    """Обложка буклета: тёмная подложка, логотип бренда, подпись каталога."""
    im = Image.new("RGBA", (w, h), (22, 42, 78, 255))
    d = ImageDraw.Draw(im)
    for y in range(h):
        k = int(22 + 26 * (y / h))
        d.line([0, y, w, y], fill=(k, 42 + k // 3, 78 + k // 2, 255))

    if brand_logo and os.path.isfile(brand_logo):
        lg = Image.open(brand_logo).convert("RGBA")
        px = lg.load()
        for yy in range(lg.height):
            for xx in range(lg.width):
                r, g, b, a = px[xx, yy]
                if r > 238 and g > 238 and b > 238:
                    px[xx, yy] = (r, g, b, 0)
                elif a > 0 and r < 130 and g < 130:
                    px[xx, yy] = (255, 255, 255, a)   # тёмный знак на тёмном фоне
        bb = lg.split()[3].getbbox()
        if bb:
            lg = lg.crop(bb)
        rr = min((w - 220) / lg.width, 210 / lg.height)
        lg = lg.resize((int(lg.width * rr), int(lg.height * rr)), Image.LANCZOS)
        im.alpha_composite(lg, ((w - lg.width) // 2, 150))

    f = ImageFont.truetype(F_BOLD, 62)
    for i, t in enumerate(("INDUSTRIAL", "GEARBOXES")):
        d.text(((w - d.textlength(t, font=f)) / 2, h - 320 + i * 76), t,
               font=f, fill=(255, 255, 255, 235))
    d.line([w // 2 - 150, h - 150, w // 2 + 150, h - 150],
           fill=(255, 255, 255, 150), width=4)
    return im


def flagcard_image(card, w=900, h=520):
    """Карточка «MADE IN …» с флагом страны — как в эталоне."""
    im = Image.new("RGBA", (w, h), (252, 252, 250, 255))
    d = ImageDraw.Draw(im)
    stripes = {
        "Италия": [(0, 140, 69), (255, 255, 255), (206, 43, 55)],
        "Германия": [(0, 0, 0), (221, 0, 0), (255, 206, 0)],
        "Турция": [(227, 10, 23)],
        "Австрия": [(237, 41, 57), (255, 255, 255), (237, 41, 57)],
        "Китай": [(238, 28, 37)],
        "Чехия": [(255, 255, 255), (215, 20, 26)],
    }.get(card["country"], [(120, 130, 145)])
    fw = 250
    n = len(stripes)
    for i, c in enumerate(stripes):
        d.rectangle([40, 40 + i * (h - 80) // n, 40 + fw,
                     40 + (i + 1) * (h - 80) // n], fill=c)
    d.rectangle([40, 40, 40 + fw, h - 40], outline=(160, 168, 180, 255), width=3)
    d.text((40 + fw + 60, h // 2 - 36), "MADE IN " + card["country_en"].upper(),
           font=ImageFont.truetype(F_BOLD, 62), fill=(24, 30, 40, 255))
    return im


COUNTRY_EN = {
    "Италия": "Italy", "Германия": "Germany", "Турция": "Turkey",
    "Австрия": "Austria", "Китай": "China", "Чехия": "Czech Republic",
}


def load_card(slug):
    import card as cardmod
    cards, types = cardmod.load_cards()
    c = cardmod.find_card(slug, cards, types, cardmod.ratio_index())
    if not c:
        sys.exit(f"нет данных по модели {slug}")
    c["country_en"] = COUNTRY_EN.get(c["country"], c["country"])
    c["mount"] = cardmod.BY_GEAR.get(c["gear"], cardmod.DEFAULT_GEAR)[3]
    # Серийный номер детерминирован от slug: hash() в Python рандомизируется
    # между запусками, поэтому берём стабильный md5 — иначе один и тот же
    # редуктор получал бы разный S/N при каждой пересборке партии.
    import hashlib
    h = hashlib.md5(c["slug"].encode()).hexdigest()
    c["sn"] = str(int(h[:8], 16) % 900000 + 100000)
    return c


def parse_quad(vals, size):
    W, H = size
    q = []
    for v in vals:
        x, y = v.split(",")
        q.append((float(x) * W, float(y) * H))
    return q


def main():
    args = sys.argv[1:]

    def opt(name, n):
        if name not in args:
            return None
        i = args.index(name)
        return args[i + 1:i + 1 + n]

    photo = opt("--photo", 1)[0]
    slug = opt("--model", 1)[0]
    out = (opt("--out", 1) or ["stamped.png"])[0]
    here = os.path.dirname(os.path.abspath(__file__))
    sys.path.insert(0, here)

    card = load_card(slug)
    im = Image.open(photo if os.path.isabs(photo) else os.path.join(here, photo))
    im = im.convert("RGBA")

    pq = opt("--plate", 4)
    if pq:
        im = paste_quad(im, plate_image(card), parse_quad(pq, im.size))
        print("шильдик впечатан")

    brand_slug = card["slug"].split("-")[0]
    logo = os.path.join(BASE, "assets", "brands", brand_slug + ".webp")

    lq = opt("--label", 4)
    if lq:
        im = paste_quad(im, label_image(card, logo), parse_quad(lq, im.size))
        print("этикетка на ящик впечатана")

    for name, maker in (("--cert", certificate_image),
                        ("--broch", brochure_image),
                        ("--flag", flagcard_image)):
        q = opt(name, 4)
        if not q:
            continue
        art = maker(card, logo) if name == "--broch" else maker(card)
        im = paste_quad(im, art, parse_quad(q, im.size))
        print(f"{name[2:]} впечатан")

    dq = opt("--doc", 4)
    if dq:
        im = paste_quad(im, passport_image(card, logo), parse_quad(dq, im.size))
        print("паспорт изделия впечатан")

    out = out if os.path.isabs(out) else os.path.join(here, out)
    im.convert("RGB").save(out, quality=95)
    print(f"{card['brand']} {card['model']} → {out}")


if __name__ == "__main__":
    main()
