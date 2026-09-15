#!/usr/bin/env python3
"""Направляет canonical страниц-дублей на их базовую страницу.

Зачем: в разделах ispolnenie/ и motor-reduktor-zr/ есть файлы вида «X-2.html» рядом с «X.html».
Почти все они — самостоятельные позиции (другое передаточное, другая мощность), у них свой
заголовок, и трогать их не надо. Но у части заголовок ПОБУКВЕННО совпадает с базовой страницей,
а различие — в одном числе внутри таблицы (например, сервис-фактор 2,7 против 2,6). Для поиска
это дубль: две страницы с одним заголовком спорят друг с другом за один и тот же запрос.

Признак дубля здесь — точное совпадение <title> с базовой страницей. Он строгий намеренно:
960 клонов в ispolnenie и 912 в motor-reduktor-zr имеют разные заголовки и под правку не
попадают.

Ссылок на такие страницы внутри сайта нет — они доступны только по прямому адресу, поэтому
canonical на базовую страницу ничего не ломает: страница остаётся открытой, а в выдачу идёт
базовая. Вместе с canonical переставляется og:url, иначе разметка соцсетей будет спорить
с canonical, как это уже было в разделах ispolnenie и tiporazmer.

Скрипт идемпотентен: повторный запуск ничего не меняет.

Запуск:  python3 tools/fix_duplicate_canonical.py [--dry]
"""
import glob
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SITE = 'https://zavod-red.ru'
SECTIONS = ('ispolnenie', 'motor-reduktor-zr')
DRY = '--dry' in sys.argv

TITLE = re.compile(r'<title>([^<]*)</title>')
CANON = re.compile(r'(<link rel="canonical" href=")([^"]+)(")')
OGURL = re.compile(r'(<meta property="og:url" content=")([^"]+)(")')
CLONE = re.compile(r'-\d+\.html$')


def title_of(text):
    m = TITLE.search(text)
    return m.group(1) if m else None


def main():
    fixed = already = 0
    per_section = {}

    for section in SECTIONS:
        n_fixed = n_already = 0
        for path in sorted(glob.glob(os.path.join(ROOT, section, '*.html'))):
            base = CLONE.sub('.html', path)
            if base == path or not os.path.exists(base):
                continue

            with open(path, encoding='utf-8', errors='ignore') as f:
                text = f.read()
            with open(base, encoding='utf-8', errors='ignore') as f:
                base_title = title_of(f.read())

            # дубль — только при точном совпадении заголовка
            if base_title is None or title_of(text) != base_title:
                continue

            slug = os.path.basename(base)[:-5]
            target = f'{SITE}/{section}/{slug}'

            new = CANON.sub(lambda m: m.group(1) + target + m.group(3), text)
            new = OGURL.sub(lambda m: m.group(1) + target + m.group(3), new)

            if new == text:
                n_already += 1
                continue
            n_fixed += 1
            if not DRY:
                with open(path, 'w', encoding='utf-8') as f:
                    f.write(new)

        per_section[section] = (n_fixed, n_already)
        fixed += n_fixed
        already += n_already

    for section, (nf, na) in per_section.items():
        print(f'{section:20} {"будет исправлено" if DRY else "исправлено"}: {nf:4}  уже верных: {na}')
    print(f'{"будет исправлено" if DRY else "исправлено"} всего: {fixed} | уже верных: {already}')


if __name__ == '__main__':
    main()
