#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Делает рабочим адрес /brands/sew-eurodrive, не подставляя под удар /brands/sew.

В выгрузке позиций «редуктор sew-eurodrive» стоит отдельной строкой, а адрес
/brands/sew-eurodrive сейчас отдаёт 404: файла нет, правила переадресации тоже.

Почему не 301. Переадресация живёт в .htaccess, а боевой .htaccess из заливки
исключён — там 82 правила, которых нет в репозитории, и затирать их нельзя. Значит
редирект пришлось бы ставить руками на сервере; этого мы избегаем.

Почему не самостоятельная страница. Вторая страница про SEW почти неизбежно
получилась бы близнецом /brands/sew, а две близкие страницы поисковик склеивает и
одну выбрасывает — рискуя как раз той, что уже в индексе.

Поэтому страница создаётся полной копией /brands/sew, но с canonical и og:url на
/brands/sew. Человек, пришедший по этому адресу, видит нормальную страницу, а
поисковик отдаёт весь вес основной. 404 больше нет, рисковать нечем.

Запуск:  python3 tools/make_sew_eurodrive.py [--dry]
"""
import argparse
import os
import re

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
ISTOCHNIK = os.path.join(ROOT, 'brands', 'sew.html')
CEL = os.path.join(ROOT, 'brands', 'sew-eurodrive.html')


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--dry', action='store_true')
    args = ap.parse_args()

    s = open(ISTOCHNIK, encoding='utf-8').read()

    # canonical и og:url уже указывают на /brands/sew — проверяем, что это так,
    # иначе копия начнёт ссылаться сама на себя и превратится в дубль
    kan = re.search(r'<link rel="canonical" href="([^"]+)"', s)
    if not kan or not kan.group(1).rstrip('/').endswith('/brands/sew'):
        raise SystemExit(f'canonical у sew.html неожиданный: {kan and kan.group(1)}')

    pometka = ('<!-- Копия /brands/sew под адрес /brands/sew-eurodrive: canonical ведёт на\n'
               '     основную страницу, чтобы две страницы про SEW не конкурировали между собой.\n'
               '     Создаётся скриптом tools/make_sew_eurodrive.py — руками не править. -->\n')
    s = s.replace('<head>', '<head>\n' + pometka, 1)

    bylo = os.path.getsize(CEL) if os.path.exists(CEL) else 0
    print(f'  источник: brands/sew.html, {os.path.getsize(ISTOCHNIK)} б')
    print(f'  canonical: {kan.group(1)}')
    print(f'  цель: brands/sew-eurodrive.html, {"перезапись" if bylo else "создание"}, {len(s.encode())} б')
    if not args.dry:
        open(CEL, 'w', encoding='utf-8').write(s)


if __name__ == '__main__':
    main()
