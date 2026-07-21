#!/usr/bin/env python3
"""Ставит калькулятор подбора ВЫШЕ сетки моделей на страницах брендов.

Заказчик: «всегда должен быть калькулятор сверху карточек».

Почему это правильно по существу: человек на странице бренда ищет замену конкретному
приводу — у него есть шильд или параметры. Калькулятор отвечает на этот вопрос за один
экран, а сетка из сотен плиток заставляет листать. Сейчас порядок обратный: сначала
плитки (около 4 КБ разметки), калькулятор — ниже, и до него надо доскроллить.

Скрипт переставляет две секции целиком, не трогая их содержимое, и работает только там,
где обе секции найдены и калькулятор действительно ниже сетки. Идемпотентен.

Запуск: python3 tools/brands_calc_first.py [--dry]
"""
import glob, os, re, sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))


def section(html, sid):
    """Границы <section id="..."> … </section> с учётом вложенных <section>."""
    m = re.search(r'<section[^>]*id="' + re.escape(sid) + r'"', html)
    if not m:
        return None
    i, depth = m.start(), 0
    for t in re.finditer(r'<section\b|</section>', html[i:]):
        depth += 1 if t.group(0) == '<section' else -1
        if depth == 0:
            return i, i + t.end()
    return None


def main():
    dry = '--dry' in sys.argv
    moved = already = skipped = 0
    for path in sorted(glob.glob(os.path.join(ROOT, 'brands', '*.html'))):
        h = open(path, encoding='utf-8').read()
        grid, calc = section(h, 'bmodels'), section(h, 'kalkulyator')
        if not grid or not calc:
            skipped += 1
            continue
        if calc[0] < grid[0]:
            already += 1
            continue
        # вырезаем калькулятор и вставляем перед сеткой; между ними может лежать
        # разметка других блоков — её порядок не меняем
        block = h[calc[0]:calc[1]]
        rest = h[:calc[0]] + h[calc[1]:]
        out = rest[:grid[0]] + block + rest[grid[0]:]
        if not dry:
            open(path, 'w', encoding='utf-8').write(out)
        moved += 1

    print(f'переставлено: {moved} | уже сверху: {already} | пропущено: {skipped}'
          + (' (пробный прогон)' if dry else ''))


if __name__ == '__main__':
    main()
