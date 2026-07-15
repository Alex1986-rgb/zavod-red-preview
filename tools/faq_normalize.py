#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Приведение FAQ всех страниц блога и глоссария к эталону kak-podobrat-reduktor:
  - минимум 6 вопросов (дополняем универсальными из пула + синхронизируем FAQPage ld+json);
  - раскладка faq-grid из ДВУХ блоков cat-faq (2 колонки), вопросы делятся пополам.

Правки точечные (вставки по границам </details>), с проверкой баланса <div> —
при любом несоответствии файл не трогается и попадает в отчёт.

Запуск: python3 tools/faq_normalize.py [--dry-run]
"""
import json
import os
import re
import sys

BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

POOL = [
    ("Как оформить заказ?",
     "Заявка через сайт, письмо на zr@zavod-red.ru или звонок менеджеру. Для юрлиц — счёт по реквизитам, для частных лиц — оплата картой."),
    ("Какая доставка по России?",
     "Любой транспортной компанией до терминала или двери; отгрузка со склада в Челябинске за 1–3 дня, упаковка — обрешётка."),
    ("Даёте ли документы для бухгалтерии и тендера?",
     "Полный пакет: счёт, УПД, паспорт изделия, сертификаты и справки о производителе."),
    ("Можно ли получить консультацию инженера?",
     "Да, бесплатно: подбор, проверка вашего расчёта, вопросы эксплуатации — по телефону или почте."),
    ("Есть ли гарантия?",
     "Заводская гарантия 12 месяцев с ввода в эксплуатацию; запчасти и сервис — на нашем производстве в Челябинске."),
    ("Как быстро приходит ответ на заявку?",
     "Коммерческое предложение по типовым запросам — в течение рабочего дня."),
]

DET_RE = re.compile(r"<details>.*?</details>", re.S)


def faq_region(t):
    """(start, end) области FAQ: от первого cat-faq до </section> после него."""
    k = t.find('<div class="cat-faq">')
    if k == -1:
        return None
    e = t.find("</section>", k)
    return (k, e if e != -1 else len(t))


def update_ld(t, added):
    m = None
    for sm in re.finditer(r'<script type="application/ld\+json">\s*(.*?)\s*</script>', t, re.S):
        if '"FAQPage"' in sm.group(1):
            m = sm
            break
    if not m:
        return t, False
    try:
        data = json.loads(m.group(1))
    except ValueError:
        return t, False
    ents = data.get("mainEntity", [])
    for q, a in added:
        ents.append({"@type": "Question", "name": q,
                     "acceptedAnswer": {"@type": "Answer", "text": a}})
    data["mainEntity"] = ents
    new = f'<script type="application/ld+json">{json.dumps(data, ensure_ascii=False)}</script>'
    return t[:m.start()] + new + t[m.end():], True


def process(path, dry):
    t0 = open(path, encoding="utf-8").read()
    reg = faq_region(t0)
    if not reg:
        return "нет cat-faq"
    k, e = reg
    seg = t0[k:e]
    dets = DET_RE.findall(seg)
    n = len(dets)
    has_grid = "faq-grid" in t0
    blocks = seg.count('<div class="cat-faq">')
    if n >= 6 and has_grid and blocks == 2:
        return "ок"

    t = t0
    summaries = {re.search(r"<summary>([^<]*)", d).group(1) for d in dets}

    # 1. дополнить до 6 вопросов
    added = []
    if n < 6:
        for q, a in POOL:
            if n + len(added) >= 6:
                break
            if q not in summaries:
                added.append((q, a))
        add_html = "".join(f"<details><summary>{q}</summary><div>{a}</div></details>" for q, a in added)
        last = list(DET_RE.finditer(t[k:e]))[-1]
        pos = k + last.end()
        t = t[:pos] + add_html + t[pos:]
        t, ld_ok = update_ld(t, added)
        if not ld_ok and added:
            return "FAQPage ld+json не найден — пропуск"

    # пересчитать регион
    k, e = faq_region(t)
    seg = t[k:e]
    n2 = len(DET_RE.findall(seg))

    # 2. если нет faq-grid — обернуть контейнер
    if "faq-grid" not in t:
        t = t[:k] + '<div class="faq-grid">' + t[k:]
        k2, e2 = faq_region(t)
        last = list(DET_RE.finditer(t[k2:e2]))[-1]
        close = t.find("</div>", k2 + last.end())
        t = t[:close + 6] + "</div>" + t[close + 6:]

    # 3. если один блок cat-faq — разбить пополам
    k, e = faq_region(t)
    if t[k:e].count('<div class="cat-faq">') == 1:
        half = (n2 + 1) // 2
        ds = list(DET_RE.finditer(t[k:e]))
        pos = k + ds[half - 1].end()
        t = t[:pos] + '</div><div class="cat-faq">' + t[pos:]

    # проверка баланса
    if t.count("<div") - t.count("</div") != t0.count("<div") - t0.count("</div"):
        return "нарушен баланс div — пропуск"
    kf, ef = faq_region(t)
    fin = t[kf - 40:ef]
    if len(DET_RE.findall(fin)) < 6 or fin.count('<div class="cat-faq">') != 2:
        return "итог не сошёлся — пропуск"

    if not dry:
        open(path, "w", encoding="utf-8").write(t)
    return f"исправлено (+{len(added)} вопр., блоков 2)"


def main():
    dry = "--dry-run" in sys.argv
    stats = {}
    problems = []
    for d in ("blog", "glossary"):
        for f in sorted(os.listdir(os.path.join(BASE, d))):
            if not f.endswith(".html") or f == "index.html":
                continue
            r = process(os.path.join(BASE, d, f), dry)
            stats[r.split(" (")[0]] = stats.get(r.split(" (")[0], 0) + 1
            if "пропуск" in r or "нет" in r:
                problems.append((f"{d}/{f}", r))
    for k, v in sorted(stats.items()):
        print(f"{k}: {v}")
    if problems:
        print("\nПроблемные:")
        for f, r in problems[:20]:
            print(" ", f, "→", r)


if __name__ == "__main__":
    main()
