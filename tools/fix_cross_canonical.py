#!/usr/bin/env python3
"""Делает canonical самоссылочным на страницах исполнений и типоразмеров.

Зачем: 10 961 страница ispolnenie/ и tiporazmer/ лежит в sitemap, robots их не
закрывает, og:url указывает на саму страницу — но canonical отправляет на хаб
/reduktor/{модель}. Сигналы противоречат друг другу: сайт одновременно говорит
«индексируй» и «это не каноникал». На практике это значит, что страницы обходятся
краулером, но в выдачу не попадают, а бюджет обхода тратится впустую.

Проверено перед правкой (выборка 1200 страниц каждого раздела):
  • title и H1 уникальны у всех;
  • текста в среднем 13,8–14,3 тыс. знаков;
  • ни одной пары страниц с побайтово одинаковым текстом.
То есть это не дубли хаба, а самостоятельные страницы под длинный хвост запросов
вида «редуктор ZR 606/604 0,12 кВт i=120». Поэтому выбран self-canonical, а не
удаление из sitemap: страницы содержательные, и терять их смысла нет.

Хлебные крошки на хаб не трогаются — там ссылка на родительскую модель верна.

Скрипт идемпотентен: повторный запуск ничего не меняет.

Запуск:  python3 tools/fix_cross_canonical.py [--dry]
"""
import glob
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SITE = 'https://zavod-red.ru'
SECTIONS = ('ispolnenie', 'tiporazmer')
DRY = '--dry' in sys.argv

CANON = re.compile(r'(<link rel="canonical" href=")([^"]+)(")')


def main():
    changed = already = other = 0
    for section in SECTIONS:
        for path in sorted(glob.glob(os.path.join(ROOT, section, '*.html'))):
            slug = os.path.basename(path)[:-5]
            own = f'{SITE}/{section}/{slug}'
            with open(path, encoding='utf-8', errors='ignore') as f:
                text = f.read()
            m = CANON.search(text)
            if not m:
                other += 1
                continue
            if m.group(2) == own:
                already += 1
                continue
            new = text[:m.start()] + m.group(1) + own + m.group(3) + text[m.end():]
            changed += 1
            if not DRY:
                with open(path, 'w', encoding='utf-8') as f:
                    f.write(new)

    print(f'{"будет исправлено" if DRY else "исправлено"}: {changed}')
    print(f'уже самоссылочных: {already} | без canonical: {other}')


if __name__ == '__main__':
    main()
