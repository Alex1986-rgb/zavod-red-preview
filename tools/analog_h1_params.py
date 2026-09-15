#!/usr/bin/env python3
"""Добавляет в H1 карточек /analog/ параметры исполнения, которые отличают страницу от соседних.

Зачем: на 73 899 карточек приходилось 914 уникальных H1 — H1 собирался как
«{модель} — мотор-редуктор, оригинал под заказ · замена ZR {код}» и не содержал ни мощности,
ни передаточного, ни момента, то есть у сотен страниц одной серии был буквально один и тот же
заголовок. При этом сами параметры давно есть в title каждой страницы и уже уникальны.
Разделы ispolnenie/ и tiporazmer/ собраны правильно — там H1 несёт кВт/Н·м/i; этот скрипт
приводит /analog/ к той же схеме, ничего не выдумывая: параметры берутся из title той же страницы.

Было:   Bonfiglioli C 41 — мотор-редуктор, оригинал под заказ · замена ZR 969
Стало:  Bonfiglioli C 41 0,18 кВт i=137, 270 Н·м — мотор-редуктор, оригинал под заказ · замена ZR 969

Страницы уровня модели (в title нет параметров) не трогаются — их H1 и так уникален.

Скрипт идемпотентен: повторный запуск ничего не меняет.

Запуск:  python3 tools/analog_h1_params.py [--dry]
"""
import glob
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
ANALOG = os.path.join(ROOT, 'analog')
DRY = '--dry' in sys.argv

TITLE = re.compile(r'<title>([^<]*)</title>')
H1 = re.compile(r'(<h1[^>]*>)([^<]*)(</h1>)')


def new_h1(title, h1):
    """Возвращает H1 с параметрами или None, если вставлять нечего."""
    head = title.split(' — ')[0].strip()
    model, sep, tail = h1.partition(' — ')
    if not sep or not head.startswith(model):
        return None                      # страница уровня модели либо иной формат
    params = head[len(model):].strip(' ,')
    if not params or params in h1:
        return None                      # параметров нет либо уже подставлены
    return f'{model} {params} — {tail}'


def main():
    pages = [p for p in sorted(glob.glob(os.path.join(ANALOG, '*.html')))
             if os.path.basename(p) != 'index.html']
    changed = skipped = 0

    for path in pages:
        with open(path, encoding='utf-8', errors='ignore') as f:
            text = f.read()
        t = TITLE.search(text)
        h = H1.search(text)
        if not t or not h:
            skipped += 1
            continue
        fresh = new_h1(t.group(1), h.group(2))
        if not fresh:
            skipped += 1
            continue
        text = text[:h.start()] + h.group(1) + fresh + h.group(3) + text[h.end():]
        changed += 1
        if not DRY:
            with open(path, 'w', encoding='utf-8') as f:
                f.write(text)

    print(f'карточек просмотрено: {len(pages)}')
    print(f'{"будет обновлено" if DRY else "обновлено"}: {changed} | без изменений: {skipped}')


if __name__ == '__main__':
    main()
