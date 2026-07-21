#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Промпты для генерации фотореалистичных снимков редукторов (Flux 2 Pro).

Принципы:
  * фотография, а не рендер: указываем камеру, оптику, свет, глубину резкости;
  * шильдик ПУСТОЙ — маркировку впечатываем скриптом (нейросеть врёт в тексте);
  * никаких чужих логотипов — корпус нейтральный, бренд не подделываем;
  * геометрия описывается по типу передачи, чтобы редуктор был узнаваем.

Запуск для проверки: python3 tools/imggen/prompts.py
"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from classify import (WORM, COAX, FLAT, BEVEL, WORMCYL, PLANET, VARI,
                      list_models, classify)

# Геометрия по типу передачи — главное, что делает редуктор узнаваемым
GEOMETRY = {
    WORM: ("compact square worm gearbox housing with deep cooling fins on all sides, "
           "input flange on top face, output hollow shaft passing horizontally through "
           "the body at 90 degrees to the input, four mounting feet at the base"),
    COAX: ("inline helical gearbox with elongated cylindrical housing, input and output "
           "shafts on the same axis, smooth ribbed body, round output bearing flange, "
           "mounting feet along the bottom"),
    FLAT: ("flat parallel-shaft gearbox with wide slim rectangular housing, large hollow "
           "output shaft bore in the centre of the side face, shrink disc collar, "
           "torque arm bracket"),
    BEVEL: ("right-angle bevel-helical gearbox, L-shaped housing where the output shaft "
            "exits at 90 degrees to the motor input flange, heavy ribbed casting, "
            "large output bearing hub"),
    WORMCYL: ("combined worm and helical gearbox, two-stage housing with a cylindrical "
              "pre-stage bolted to a square worm body, output shaft at 90 degrees"),
    PLANET: ("compact cylindrical planetary gearbox, short heavy barrel housing, thick "
             "output flange with bolt circle, machined splined output shaft"),
    VARI: ("mechanical variable speed drive unit, cylindrical variator housing with a "
           "speed adjustment handwheel on top, coupled to a gearbox body"),
}

# Ракурс и сцена — как в реальной каталожной съёмке на производстве
SCENE = ("professional product photograph on a light grey concrete factory floor, "
         "three-quarter view from slightly above, cardboard boxes and wooden pallets "
         "blurred in the background, soft natural daylight from the left side, "
         "shallow depth of field")

CAMERA = ("shot on Canon EOS R5, 85mm lens, f/4, sharp focus on the housing, "
          "photorealistic, ultra detailed, realistic cast iron surface texture, "
          "visible casting grain and machining marks, subtle dust and wear")

# Корпус — нейтральный индустриальный, без чужого фирменного цвета и логотипов
HOUSING = ("industrial blue-grey painted cast iron housing, machined bare metal flanges, "
           "stainless steel bolts and lock washers, blank unengraved metal nameplate "
           "with no text")

NEGATIVE = ("no text, no letters, no numbers, no logo, no watermark, no branding, "
            "no people, no hands, cartoon, 3d render, cgi, illustration, drawing")


def build_prompt(gear_type, size_hint=""):
    """Промпт под конкретный тип передачи."""
    geom = GEOMETRY.get(gear_type, GEOMETRY[WORM])
    size = f" {size_hint}," if size_hint else ""
    return (f"Photorealistic industrial product photography.{size} {geom}. "
            f"{HOUSING}. {SCENE}. {CAMERA}. "
            f"Negative: {NEGATIVE}.")


def size_hint(size):
    """Крупные типоразмеры выглядят массивнее — подсказываем модели масштаб."""
    digits = "".join(c for c in size if c.isdigit())
    if not digits:
        return ""
    n = int(digits[:3])
    if n <= 40:
        return "small compact unit about 15 cm across"
    if n <= 90:
        return "medium sized unit about 30 cm across"
    return "large heavy industrial unit about 60 cm across"


def job_for(model):
    """Задание на генерацию одной модели."""
    brand_slug, brand, code, size, gear = classify(model)
    return {
        "model": model,
        "brand": brand,
        "brand_slug": brand_slug,
        "series": code.upper(),
        "size": size,
        "gear": gear,
        "prompt": build_prompt(gear, size_hint(size)),
        "out": f"assets/analog/{model}.webp",
        # что впечатаем на шильдик после генерации
        "plate": f"{brand} {code.upper()} {size}".strip(),
        "alt": f"{gear.capitalize()} редуктор {brand} {code.upper()} {size} — "
               f"аналог ZR, фото",
    }


def all_jobs():
    return [job_for(m) for m in list_models()]


if __name__ == "__main__":
    jobs = all_jobs()
    print(f"заданий: {len(jobs)}\n")
    seen = set()
    for j in jobs:
        if j["gear"] in seen:
            continue
        seen.add(j["gear"])
        print(f"=== {j['gear'].upper()} — пример {j['model']} ===")
        print(j["prompt"][:400], "...\n")
