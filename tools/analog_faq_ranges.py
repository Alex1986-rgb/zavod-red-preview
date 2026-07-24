#!/usr/bin/env python3
"""Дочищает пп.7/15 на карточках /analog/: одиночные значения в блоке вопросов-ответов.

Прошлый проход (tools/analog_ranges.py) заменил диапазонами три места — абзац описания,
таблицу характеристик и FAQ, — но ставил маркер `<!--rng-->`, даже если заменилось не
всё. В результате на 66 733 карточках ответ «Какие характеристики у …» по-прежнему
показывает ОДИНОЧНЫЕ значения:

    Мощность 2,2 кВт, передаточное 10,63, крутящий момент 158 Н·м, тип — коническо-…

тогда как таблица на том же экране показывает диапазон модели «0,1–5,5 кВт». Два блока
одного экрана противоречат друг другу, а крутящий момент — то самое, что заказчик просил
убрать как самое вводящее в заблуждение.

Диапазон берём ИЗ ЭТОГО ЖЕ ФАЙЛА (строки таблицы «Мощность (модель)» и «Передаточное
число») — ничего не выдумываем и не тянем извне. Тот же текст правим и в JSON-LD
микроразметке, иначе одиночные цифры уедут в сниппет поисковика.

Идемпотентно: правленые файлы получают маркер `<!--faqrng-->`.

Запуск:
    python3 tools/analog_faq_ranges.py --dry        # отчёт без записи
    python3 tools/analog_faq_ranges.py --dry 200    # то же, на первых 200 файлах
    python3 tools/analog_faq_ranges.py              # применить ко всем
"""
import os, re, sys, glob
from collections import Counter

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
os.chdir(ROOT)

DRY = "--dry" in sys.argv
LIMIT = next((int(a) for a in sys.argv[1:] if a.isdigit()), None)

MARK = "<!--faqrng-->"

# Видимый ответ в <details>. Порядок значений в разных партиях карточек отличается,
# поэтому мощность/передаточное/момент ищем как единый блок до слова «тип».
VIS = re.compile(
    r"Мощность[^<]{0,60}?кВт,\s*(?:передаточное[^<]{0,40}?,\s*)?"
    r"(?:крутящий момент[^<]{0,40}?Н·м,\s*)?(?:передаточное[^<]{0,40}?,\s*)?"
    r"тип\s+—\s*([^.<]+)\.\s*Точные параметры исполнения — в таблице выше\."
)

# Тот же текст внутри JSON-LD (там экранированные кавычки, тегов нет).
LD = re.compile(
    r"Мощность[^\"]{0,60}?кВт,\s*(?:крутящий момент[^\"]{0,40}?Н·м,\s*)?"
    r"(?:передаточное[^\"]{0,40}?,\s*)?обороты выхода[^\"]{0,40}?,\s*"
    r"сервис-фактор[^\"]{0,30}?,\s*консольная нагрузка[^\"]{0,30}?\."
)

ROW = r'<span class="k">%s</span><span class="v">([^<]*)</span>'
RE_PW = re.compile(ROW % r"Мощность(?:\s*\(модель\))?")
RE_I = re.compile(ROW % r"Передаточное число")


def specs(text):
    """(мощность, передаточное) из таблицы характеристик этого же файла."""
    pw = RE_PW.search(text)
    i = RE_I.search(text)
    return (pw.group(1).strip() if pw else None, i.group(1).strip() if i else None)


def transform(path):
    t = open(path, encoding="utf-8").read()
    if MARK in t:
        return "already"
    if "Точные параметры исполнения — в таблице выше." not in t:
        return "no-faq"

    pw, ratio = specs(t)
    if not pw or not ratio:
        return "no-specs"

    def vis_repl(m):
        kind = m.group(1).strip()
        return (f"Мощность модели {pw}, передаточное {ratio}, тип — {kind}. "
                f"Крутящий момент и точное исполнение зависят от типоразмера — "
                f"смотрите таблицу выше или уточните у инженера по шильду.")

    new, n_vis = VIS.subn(vis_repl, t, count=1)
    if n_vis == 0:
        return "no-match"

    # та же правка в микроразметке — иначе одиночные цифры попадут в сниппет
    new, _ = LD.subn(
        f"Мощность модели {pw}, передаточное {ratio}. "
        f"Точные параметры зависят от исполнения — уточните по шильду.",
        new, count=1)

    new = new.replace("</head>", MARK + "</head>", 1)
    if not DRY:
        open(path, "w", encoding="utf-8").write(new)
    return "ok"


if __name__ == "__main__":
    files = [a for a in sys.argv[1:] if a.endswith(".html")] or sorted(glob.glob("analog/*.html"))
    if LIMIT:
        files = files[:LIMIT]
    st = Counter()
    for f in files:
        st[transform(f)] += 1
    print("итог:", dict(st))
    if DRY:
        print("(сухой прогон — без записи)")
