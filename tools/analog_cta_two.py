#!/usr/bin/env python3
"""Кнопки на карточках /analog/: три сводим к двум и меняем приоритет.

Решение заказчика 20.07.2026. Было: «Получить расчёт и КП» (красная), «Подобрать аналог»,
«Запросить оригинал». Три призыва к одному и тому же действию размывали выбор, а главным
стоял самый общий из них.

Стало: «Запросить оригинал» — красная и первая (карточка принадлежит импорту, значит
основной запрос — сам оригинал), «Подобрать аналог» — белая и вторая. «Получить расчёт и КП»
убран: обе оставшиеся кнопки и так открывают ту же форму заявки, но с конкретным запросом
в теле письма, что менеджеру полезнее общего «рассчитайте».

Скрипт идемпотентен: повторный прогон ничего не меняет.
Запуск: python3 tools/analog_cta_two.py [--dry]
"""
import glob, os, re, sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
BLOCK = re.compile(r'<div class="p2-cta1">(.*?)</div>', re.S)
ANCHOR = re.compile(r'<a\b[^>]*>(?:(?!</a>).)*</a>', re.S)


def rebuild(inner):
    """Оставляем два нужных якоря, переставляем и перекрашиваем. data-req у каждого
    свой (несёт модель в текст заявки) — поэтому берём готовые теги, а не собираем заново."""
    orig = analog = None
    for a in ANCHOR.findall(inner):
        label = re.sub(r'<[^>]+>', '', a).strip()
        if label == 'Запросить оригинал':
            orig = a
        elif label == 'Подобрать аналог':
            analog = a
    if not orig or not analog:
        return None
    orig = re.sub(r'class="[^"]*"', 'class="btn lg"', orig, count=1)          # красная
    analog = re.sub(r'class="[^"]*"', 'class="btn ghost lg"', analog, count=1)  # белая
    return '<div class="p2-cta1">' + orig + analog + '</div>'


def main():
    dry = '--dry' in sys.argv
    changed = skipped = already = 0
    for path in glob.glob(os.path.join(ROOT, 'analog', '*.html')):
        html = open(path, encoding='utf-8').read()
        m = BLOCK.search(html)
        if not m:
            skipped += 1
            continue
        new_block = rebuild(m.group(1))
        if new_block is None:
            skipped += 1
            continue
        if new_block == m.group(0):
            already += 1
            continue
        if not dry:
            with open(path, 'w', encoding='utf-8') as f:
                f.write(html[:m.start()] + new_block + html[m.end():])
        changed += 1
    print(f'изменено: {changed} | уже в новом виде: {already} | пропущено: {skipped}'
          + (' (пробный прогон, файлы не тронуты)' if dry else ''))


if __name__ == '__main__':
    main()
