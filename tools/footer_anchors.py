#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Переписывает колонку «Каталог» в сквозном футере под формулировки запросов.

Футер стоит на 95 128 страницах и даёт типовым страницам почти весь их внутренний
вес. Анкоры в нём были короткие и не совпадали с запросами:

  было «Цилиндро-конические»  — перевёрнутый порядок слов и без слова «редукторы»,
                                 при запросе «коническо-цилиндрические редукторы»;
  было «Червячные», «Цилиндрические», «Соосные», «Планетарные» — то же, без «редукторы».

Anchor — один из самых весомых сигналов: 95 127 ссылок с неточным анкором работают
хуже, чем столько же с точным.

Вторая часть правки — две недостающие строки. Посадочная под «вариаторные редукторы»
имела 13 страниц-доноров на весь сайт против 95 166 у планетарных: её просто не было
в футере. Плоская (параллельно-осевая) — 2 777 доноров, и почти все с анкором
«плоско-цилиндрических редукторов». Обе добавлены с точным анкором запроса.

Адреса не меняются — только тексты ссылок и две новые строки. Скрипт идемпотентен.

Важно про выкатку: 73 900 из затронутых файлов лежат в analog/, а этот раздел
заливается отдельным воркфлоу. Полный эффект будет после обеих выкаток.

Запуск:  python3 tools/footer_anchors.py [--dry]
"""
import argparse
import os
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

STARO = (
    '      <a href="/catalog/chervyachnye">Червячные</a>\n'
    '      <a href="/catalog/cilindricheskie">Цилиндрические</a>\n'
    '      <a href="/catalog/soosnye">Соосные</a>\n'
    '      <a href="/catalog/konichesko-cilindricheskie">Цилиндро-конические</a>\n'
    '      <a href="/catalog/planetarnye">Планетарные</a>\n'
)
NOVO = (
    '      <a href="/catalog/chervyachnye">Червячные редукторы</a>\n'
    '      <a href="/catalog/cilindricheskie">Цилиндрические редукторы</a>\n'
    '      <a href="/catalog/soosnye">Соосные редукторы</a>\n'
    '      <a href="/catalog/konichesko-cilindricheskie">Коническо-цилиндрические редукторы</a>\n'
    '      <a href="/catalog/planetarnye">Планетарные редукторы</a>\n'
    '      <a href="/catalog/ploskie">Параллельно-осевые редукторы</a>\n'
    '      <a href="/catalog/variatory">Вариаторные редукторы</a>\n'
)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--dry', action='store_true')
    args = ap.parse_args()

    pravleno = uzhe = bez_futera = 0
    po_razdelam = {}
    for kat, _, faily in os.walk(ROOT):
        rel_kat = os.path.relpath(kat, ROOT)
        if rel_kat.startswith(('dist', '.git', 'node_modules')):
            continue
        for f in faily:
            if not f.endswith('.html'):
                continue
            path = os.path.join(kat, f)
            with open(path, encoding='utf-8') as fh:
                s = fh.read()
            if NOVO in s:
                uzhe += 1; continue
            if STARO not in s:
                bez_futera += 1; continue
            s = s.replace(STARO, NOVO, 1)
            if not args.dry:
                with open(path, 'w', encoding='utf-8') as fh:
                    fh.write(s)
            pravleno += 1
            razdel = rel_kat.split(os.sep)[0] if rel_kat != '.' else 'корень'
            po_razdelam[razdel] = po_razdelam.get(razdel, 0) + 1

    print(f'{"будет исправлено" if args.dry else "исправлено"} страниц: {pravleno}')
    for r, n in sorted(po_razdelam.items(), key=lambda kv: -kv[1]):
        print(f'   {r:22} {n:6}')
    print(f'уже с новым футером: {uzhe}')
    print(f'без этого футера (страницы другого шаблона): {bez_futera}')
    if pravleno == 0 and uzhe == 0:
        print('НИ ОДНОЙ страницы не затронуто — шаблон футера изменился, проверь STARO')
        sys.exit(1)


if __name__ == '__main__':
    main()
