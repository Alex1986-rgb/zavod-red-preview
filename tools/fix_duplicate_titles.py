#!/usr/bin/env python3
"""Сводит через canonical страницы генерируемых разделов с побуквенно одинаковым заголовком.

Чем отличается от tools/fix_duplicate_canonical.py: тот сравнивал клон «X-2.html» с его
базовой страницей «X.html» и потому видел только такие пары. Но у позиции бывает сразу
несколько клонов — «X.html», «X-1.html», «X-2.html», «X-3.html», — и одинаковый заголовок
может оказаться у «X-1» и «X-3» между собой, при том что у базовой он другой. Такие пары
прошлый инструмент пропускал. Здесь страницы группируются прямо по заголовку, поэтому
способ ловит все случаи разом, включая те.

Что делается: в группе одинаковых заголовков выбирается главная страница — с самым коротким
адресом (базовая «X» короче клона «X-1»), при равной длине по алфавиту. У остальных canonical
и og:url переставляются на неё. Страницы остаются открытыми, в выдачу идёт одна.

Разделы берутся только генерируемые. Блог намеренно не трогается: там одинаковый заголовок
у двух статей — это content, его надо переписать, а не прятать через canonical.

Скрипт идемпотентен: повторный запуск ничего не меняет.

Запуск:  python3 tools/fix_duplicate_titles.py [--dry]
"""
import collections
import glob
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SITE = 'https://zavod-red.ru'
SECTIONS = ('analog', 'motor-reduktor-zr', 'ispolnenie', 'tiporazmer')
DRY = '--dry' in sys.argv

TITLE = re.compile(r'<title>([^<]*)</title>')
CANON = re.compile(r'(<link rel="canonical" href=")([^"]+)(")')
OGURL = re.compile(r'(<meta property="og:url" content=")([^"]+)(")')


def main():
    total_fixed = total_groups = 0

    for section in SECTIONS:
        by_title = collections.defaultdict(list)
        pages = sorted(glob.glob(os.path.join(ROOT, section, '*.html')))

        for path in pages:
            name = os.path.basename(path)
            if name == 'index.html':
                continue
            with open(path, encoding='utf-8', errors='ignore') as f:
                head = f.read(200000)
            t = TITLE.search(head)
            c = CANON.search(head)
            if not t or not c:
                continue
            slug = name[:-5]
            # берём только страницы, которые сейчас ссылаются сами на себя:
            # если canonical уже уводит на другую, страница сведена и трогать её нечего
            if c.group(2) != f'{SITE}/{section}/{slug}':
                continue
            by_title[t.group(1)].append(slug)

        fixed = groups = 0
        for title, slugs in by_title.items():
            if len(slugs) < 2:
                continue
            groups += 1
            primary = sorted(slugs, key=lambda s: (len(s), s))[0]
            target = f'{SITE}/{section}/{primary}'
            for slug in slugs:
                if slug == primary:
                    continue
                path = os.path.join(ROOT, section, slug + '.html')
                with open(path, encoding='utf-8', errors='ignore') as f:
                    text = f.read()
                new = CANON.sub(lambda m: m.group(1) + target + m.group(3), text)
                new = OGURL.sub(lambda m: m.group(1) + target + m.group(3), new)
                if new != text:
                    fixed += 1
                    if not DRY:
                        with open(path, 'w', encoding='utf-8') as f:
                            f.write(new)

        total_fixed += fixed
        total_groups += groups
        print(f'{section:20} групп одинаковых заголовков {groups:5}  '
              f'{"будет сведено" if DRY else "сведено"} страниц {fixed}')

    print(f'ИТОГО групп {total_groups}, {"будет сведено" if DRY else "сведено"} '
          f'страниц {total_fixed}')


if __name__ == '__main__':
    main()
