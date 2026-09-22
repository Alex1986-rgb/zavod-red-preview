#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Связывает страницу бренда с её же статьями в блоге.

В blog/ лежат 1092 статьи, из них 362 — про тринадцать целевых марок: «Купить SEW»,
«Аналоги SEW-Eurodrive: таблица соответствия», «Характеристики редукторов Bauer»,
«Импортозамещение KEB» и так далее. Ни одна страница бренда на них не ссылается.
Получается, что хаб, на который ведут тысячи карточек, вес вниз не отдаёт, а статьи
живут сами по себе.

Скрипт добавляет на каждую страницу бренда блок «Ещё о редукторах {марка}» со
ссылками на её же статьи. Отбор по подстроке в имени файла, заголовок берётся из
самой статьи — никаких выдуманных названий. Приоритет отдаётся статьям, которые
отвечают на коммерческий запрос: «купить», «цена», «аналог», «замена»,
«импортозамещение», «характеристики», «каталог».

Блок ставится перед вопросами-ответами. Anchor — настоящий заголовок статьи, то есть
содержит и марку, и слово «редуктор».

Скрипт идемпотентен.

Запуск:  python3 tools/brand_blog_links.py [--dry] [--max 8]
"""
import argparse
import html
import os
import re

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
MARK = 'zr-blog-links'

BRANDS = {
    'sew': ('SEW-Eurodrive', 'sew'), 'bonfiglioli': ('Bonfiglioli', 'bonfiglioli'),
    'nord': ('NORD', 'nord'), 'motovario': ('Motovario', 'motovario'),
    'innovari': ('Innovari', 'innovari'), 'lenze': ('Lenze', 'lenze'),
    'bauer': ('Bauer', 'bauer'), 'siti': ('SITI', 'siti'),
    'varvel': ('Varvel', 'varvel'), 'rossi': ('Rossi', 'rossi'),
    'keb': ('KEB', 'keb'), 'siemens': ('Siemens', 'siemens'), 'stm': ('STM', 'stm'),
}
# чем раньше слово в списке, тем выше статья в блоке
VES = ['kupit', 'cena', 'analog', 'zamenit', 'zamena', 'importozameshchenie',
       'harakteristiki', 'katalog', 'podbor']


def stati(slug):
    """Статьи блога про марку: (вес, слаг, заголовок)."""
    out = []
    for f in sorted(os.listdir(os.path.join(ROOT, 'blog'))):
        if not f.endswith('.html') or f == 'index.html':
            continue
        # только отдельное слово: 'stm' не должен ловить 'systm'
        if not re.search(r'(^|-)%s(-|\.)' % re.escape(slug), f):
            continue
        s = open(os.path.join(ROOT, 'blog', f), encoding='utf-8').read(4000)
        m = re.search(r'<title>(.*?)</title>', s, re.S)
        if not m:
            continue
        zag = html.unescape(m.group(1)).split('|')[0].strip()
        ves = next((i for i, w in enumerate(VES) if w in f), len(VES))
        out.append((ves, f[:-5], zag))
    out.sort(key=lambda x: (x[0], x[1]))
    return out


def blok(lat, spisok):
    ssylki = ''.join(
        f'<li><a href="/blog/{sl}">{html.escape(z)}</a></li>' for _, sl, z in spisok)
    return (
        f'\n<section class="section {MARK}" style="padding-top:0"><div class="wrap">\n'
        '  <div class="eyebrow">Статьи</div>\n'
        f'  <h2 style="font-size:26px">Ещё о редукторах {html.escape(lat)}</h2>\n'
        '  <ul style="columns:2;column-gap:32px;line-height:1.9;padding-left:20px;margin:0">\n'
        f'    {ssylki}\n'
        '  </ul>\n'
        f'  <p style="margin-top:14px"><a href="/blog/">Все статьи о редукторах →</a></p>\n'
        '</div></section>\n')


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--dry', action='store_true')
    ap.add_argument('--max', type=int, default=8)
    args = ap.parse_args()

    for key, (lat, slug) in sorted(BRANDS.items()):
        path = os.path.join(ROOT, 'brands', key + '.html')
        s = open(path, encoding='utf-8').read()
        if MARK in s:
            print(f'  {key:12} уже есть'); continue
        sp = stati(slug)[:args.max]
        if not sp:
            print(f'  {key:12} статей не нашлось'); continue
        # перед блоком вопросов-ответов
        i = s.find('<div class="eyebrow">Вопросы и ответы</div>')
        if i < 0:
            print(f'  {key:12} не нашёл, куда вставить'); continue
        i = s.rfind('<section', 0, i)
        s = s[:i] + blok(lat, sp) + s[i:]
        print(f'  {key:12} ссылок {len(sp)}: {", ".join(x[1] for x in sp[:3])}…')
        if not args.dry:
            open(path, 'w', encoding='utf-8').write(s)


if __name__ == '__main__':
    main()
