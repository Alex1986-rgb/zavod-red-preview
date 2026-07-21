#!/usr/bin/env python3
"""Перегенерация карт сайта для раздела /analog/.

Зачем: карты собирались один раз по тогдашнему списку файлов и с тех пор разъехались.
Приёмка перед деплоем нашла 1124 страницы, которых в картах нет вовсе — среди них ВСЕ
114 карточек, созданных в этой сессии для брендов Vemper, Tos Znojmo, InnoRed, Varmec,
Tramec, STM. Их не увидел бы ни поисковик, ни index_accelerator.py (он читает эти же
карты), то есть страницы создали, а трафика ради которого — не будет.

Карты бьются по 45 000 URL: это ниже лимита в 50 000 и совпадает с прежней разбивкой,
чтобы не менять sitemap-index.xml.

Приоритет 0.6 у карточек уровня модели («yilmaz-e-030») против 0.5 у параметрических:
модельная страница шире по смыслу и должна ранжироваться выше конкретного исполнения.

Запуск: python3 tools/gen_sitemap_analog.py
"""
import os, re, glob

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
ANALOG = os.path.join(ROOT, 'analog')
BASE = 'https://zavod-red.ru/analog/'
PER_FILE = 45000

HEAD = '<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n'
TAIL = '</urlset>\n'

# параметрическая карточка: <бренд>-<модель>-i<передаточное>-<мощность>kvt
PARAM = re.compile(r'-i\d+(?:[-_]\d+)?-\d+(?:[-_]\d+)?kvt(?:-\d+)?$')


def main():
    slugs = sorted(
        os.path.basename(p)[:-5]
        for p in glob.glob(os.path.join(ANALOG, '*.html'))
        if os.path.basename(p) != 'index.html'
    )
    chunks = [slugs[i:i + PER_FILE] for i in range(0, len(slugs), PER_FILE)] or [[]]

    written = []
    for n, chunk in enumerate(chunks, 1):
        name = 'sitemap-analog.xml' if n == 1 else f'sitemap-analog-{n}.xml'
        with open(os.path.join(ROOT, name), 'w', encoding='utf-8') as f:
            f.write(HEAD)
            for s in chunk:
                pr = '0.5' if PARAM.search(s) else '0.6'
                f.write(f'  <url><loc>{BASE}{s}</loc><changefreq>monthly</changefreq>'
                        f'<priority>{pr}</priority></url>\n')
            f.write(TAIL)
        written.append((name, len(chunk)))

    total = sum(c for _, c in written)
    models = sum(1 for s in slugs if not PARAM.search(s))
    print(f'страниц в analog/: {len(slugs)} | записано в карты: {total}')
    print(f'  из них уровня модели: {models} (приоритет 0.6)')
    for name, c in written:
        print(f'  {name:24} {c}')


if __name__ == '__main__':
    main()
