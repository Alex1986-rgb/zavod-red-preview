#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Генерация готовой каталожной карточки ОДНИМ промтом на модель (Higgsfield).

Одобренный вид: нейросеть рисует карточку целиком за один проход —
таблица слева с точными данными, логотип фирмы и ZR, изделие в цвете фирмы,
сертификат/буклет/флаг на поддоне, водяные знаки. Точные значения таблицы
подставляются в промт из каталога, поэтому цифры верные (мелкий текст на
шильдике/сертификате модель может исказить — это её ограничение).

Очередь с состоянием: reflects manifest-oneshot.json — какие модели
отправлены/готовы. При нехватке кредитов останавливается; повторный запуск
продолжает с места остановки (докупить кредиты и запустить снова).

Запуск:
  python3 oneshot.py --plan
  python3 oneshot.py --submit 40      # отправить 40 заданий в генерацию
  python3 oneshot.py --collect        # скачать готовые, собрать webp
  python3 oneshot.py --status
"""
import json
import os
import re
import sys
import time
import hashlib
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
BASE = os.environ.get("ZAVOD_BASE", os.path.dirname(os.path.dirname(HERE)))
sys.path.insert(0, HERE)

import card as cardmod        # noqa: E402
import render751 as R         # noqa: E402

OUT = os.path.join(BASE, "assets", "cards")
STATE = os.path.join(HERE, "manifest-oneshot.json")
REF_MEDIA = "09fea75d-1e21-4db7-8761-1d223a50f83a"   # эталонная карточка-референс

# Цвет и конфигурация корпуса по фирме — чтобы SEW был красный, NORD серый.
# Взято с реальных фирменных фото brands-photo/. gearbox_style дописывается
# по типу передачи в build_prompt.
BRAND_STYLE = {
    "SEW-Eurodrive": "SEW-Eurodrive style, RED painted cast housing",
    "NORD": "NORD style, SILVER-GREY smooth aluminium unicase housing",
    "Bonfiglioli": "Bonfiglioli style, light GREY cast housing",
    "Bauer": "Bauer style, BLUE painted cast housing",
    "Motovario": "Motovario style, BLUE painted cast housing",
    "Rossi": "Rossi style, BLUE painted cast housing",
    "SITI": "SITI style, light BLUE painted housing",
    "STM": "STM style, GREY painted cast housing",
    "Tramec": "Tramec style, BLUE painted housing",
    "Transtecno": "Transtecno style, SILVER aluminium housing",
    "Varmec": "Varmec style, light GREY housing",
    "Varvel": "Varvel style, GREY painted housing",
    "Vemper": "Vemper style, BLUE painted housing",
    "Watt Drive": "Watt Drive style, GREY painted cast housing",
    "Yilmaz": "Yilmaz Reduktor style, dark GREY cast iron housing",
    "Lenze": "Lenze style, GREY painted housing",
    "Innored": "INNORED style, BLUE painted housing",
    "Innovari": "Innovari style, BLUE painted housing",
    "Tos Znojmo": "TOS Znojmo style, GREEN painted housing",
}
DEFAULT_STYLE = "industrial BLUE painted cast housing"

GEO = {
    "червячный": "a compact square worm gearbox with a finned electric motor, "
                 "the output shaft at a 90 degree right angle to the motor",
    "соосно-цилиндрический": "an inline helical coaxial gearmotor, motor and "
                             "gearbox on one axis, round output flange",
    "цилиндрический": "an inline helical coaxial gearmotor, round output flange",
    "плоско-цилиндрический": "a flat parallel-shaft gearbox with a large hollow "
                             "output bore and torque arm",
    "коническо-цилиндрический": "a right-angle bevel-helical gearbox, output "
                                "shaft at 90 degrees to the motor",
    "червячно-цилиндрический": "a combined helical-worm gearmotor",
    "планетарный": "a compact cylindrical planetary gear reducer",
    "вариатор": "a mechanical variable-speed drive with a handwheel",
}

MOUNT = {
    "червячный": "B3 / B5 / B14", "соосно-цилиндрический": "B3 / B5",
    "цилиндрический": "B3 / B5", "плоско-цилиндрический": "Полый вал / лапы",
    "коническо-цилиндрический": "B3 / B5 / лапы",
    "червячно-цилиндрический": "B3 / B5", "планетарный": "Фланцевое",
    "вариатор": "B3 / B5",
}
KPD = {
    "червячный": "до 90 %", "соосно-цилиндрический": "до 98 %",
    "цилиндрический": "до 98 %", "плоско-цилиндрический": "до 98 %",
    "коническо-цилиндрический": "до 97 %", "червячно-цилиндрический": "до 92 %",
    "планетарный": "до 97 %", "вариатор": "до 90 %",
}
NOISE = {
    "червячный": "≤ 75 дБ(А)", "соосно-цилиндрический": "≤ 80 дБ(А)",
    "цилиндрический": "≤ 80 дБ(А)", "плоско-цилиндрический": "≤ 82 дБ(А)",
    "коническо-цилиндрический": "≤ 82 дБ(А)", "червячно-цилиндрический": "≤ 78 дБ(А)",
    "планетарный": "≤ 85 дБ(А)", "вариатор": "≤ 80 дБ(А)",
}
FLAG = {
    "Италия": ("Italian", "СДЕЛАНО В ИТАЛИИ"), "Германия": ("German", "СДЕЛАНО В ГЕРМАНИИ"),
    "Турция": ("Turkish", "СДЕЛАНО В ТУРЦИИ"), "Австрия": ("Austrian", "СДЕЛАНО В АВСТРИИ"),
    "Китай": ("Chinese", "СДЕЛАНО В КИТАЕ"), "Чехия": ("Czech", "СДЕЛАНО В ЧЕХИИ"),
    "Импорт": ("", "ИМПОРТ"),
}


def rows(card):
    g = card["gear"]
    r = [("Тип редуктора", g.capitalize()), ("Типоразмер", card["model"]),
         ("Передаточное число (i)", card["ratio"]),
         ("Входная мощность (P1)", f"{card['power']} кВт" if card["power"] else ""),
         ("Монтажное исполнение", MOUNT.get(g, "B3 / B5")),
         ("КПД", KPD.get(g, "до 96 %")),
         ("Материал корпуса",
          "Чугун / алюминий" if g == "червячный" else "Чугун"),
         ("Уровень шума", NOISE.get(g, "≤ 82 дБ(А)")),
         ("Аналог «Завод Редукторов»", card["zr"])]
    return [(k, v) for k, v in r if v]


def build_prompt(card):
    g = card["gear"]
    style = BRAND_STYLE.get(card["brand"], DEFAULT_STYLE)
    geo = GEO.get(g, GEO["червячный"])
    flag_adj, made = FLAG.get(card["country"], ("", card["country"].upper()))
    table = "\n".join(f"{k} | {v}" for k, v in rows(card))
    logo = card["brand"]
    return (
        "Recreate this exact marketing product card layout, same composition, "
        "proportions and style as the reference image, but with the content "
        "below.\n\n"
        "LEFT SIDE — white panel with a specifications table. Dark blue bold "
        "title «ПРОМЫШЛЕННЫЙ РЕДУКТОР», subtitle «ТЕХНИЧЕСКИЕ ХАРАКТЕРИСТИКИ». "
        "Then a clean bordered two-column table, Russian labels left and values "
        "right, EXACTLY these rows, crisp correctly spelled Russian, black on "
        f"white:\n{table}\n"
        "At the bottom left three compliance marks «CE», «ISO 9001», «EAC», and "
        f"below them {flag_adj} flag with «{made}».\n\n"
        f"TOP LEFT corner: the {logo} company logo. TOP RIGHT corner: a red "
        "round «ZR» gear emblem with «ЗАВОД ООО НИИ АТТ РЕДУКТОРЫ».\n\n"
        f"RIGHT SIDE — {geo}, {style}, standing on a wooden pallet in a "
        "warehouse, soft daylight from the left, photorealistic. A large blank "
        "polished steel nameplate on the housing. On the pallet in front: an "
        "ISO 9001:2015 certificate sheet, a dark blue product booklet with the "
        f"{logo} logo, and a small {flag_adj} flag «MADE IN» card. Behind it a "
        f"wooden shipping crate with a {logo} label.\n\n"
        "Faint repeated «ЗАВОД РЕДУКТОРОВ» watermark tiled across the whole "
        "image. Catalogue-quality product photography, sharp, 2K.")


def load_state():
    try:
        return json.load(open(STATE, encoding="utf-8"))
    except (OSError, ValueError):
        return {"jobs": {}}          # model -> {job_id, status, file}


def save_state(s):
    json.dump(s, open(STATE, "w", encoding="utf-8"), ensure_ascii=False, indent=0)


def all_cards():
    """(model, нормализованные данные) для всех 751 модели."""
    cards, types = cardmod.load_cards()
    ratios = cardmod.ratio_index()
    idx = R.catalog_index(cards, types)
    out = []
    for m in R.analog_models():
        d = R.match(m, idx)
        if not d:
            continue
        c = cardmod.normalize(d, types, ratios)
        c["slug"] = m
        out.append((m, c))
    return out


def main():
    args = sys.argv[1:]
    items = all_cards()
    state = load_state()

    if "--plan" in args:
        print(f"моделей к генерации: {len(items)}")
        print(f"уже в очереди/готово: {len(state['jobs'])}")
        print(f"примерная стоимость всех: {len(items) * 2} кредитов (2K)")
        print("\nпример промта (первая модель):\n")
        print(build_prompt(items[0][1])[:900], "…")
        return

    if "--status" in args:
        done = sum(1 for j in state["jobs"].values() if j.get("file"))
        sent = sum(1 for j in state["jobs"].values() if j.get("job_id"))
        print(f"всего моделей: {len(items)}")
        print(f"отправлено:    {sent}")
        print(f"собрано webp:  {done}")
        print(f"осталось:      {len(items) - done}")
        return

    print("используйте --plan / --submit N / --collect / --status")
    print("(submit/collect подключаются к Higgsfield через управляющий скрипт)")


if __name__ == "__main__":
    main()
