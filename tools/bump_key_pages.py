#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Сбрасывает кэш CSS и JS на ключевых страницах, не трогая карточки.

Зачем не общий tools/bump_asset_versions.py. Тот проставляет ключи на всех 95 133
страницах — это столько же правок файлов, а свободного места на диске сессии 225 МБ
при .git размером 23 ГБ. Обрыв посреди такой правки испортил бы страницы, поэтому
полный прогон отложен до сессии с чистым диском.

Что делает этот. Правит ключи только на страницах, которые человек открывает
руками: корень, каталог, бренды, отрасли, услуги, кейсы и хабы разделов — около
полутора сотен файлов. HTML сервер отдаёт с no-cache, значит эти страницы обновятся
сразу, подтянут свежие assets/hdr.css и assets/modal.js — и бегущая лента в шапке
появится немедленно, а не через месяц.

Карточки (analog, ispolnenie, tiporazmer, motor-reduktor-zr, blog, glossary) остаются
на прежних ключах: их десятки тысяч, и они получат ленту при следующем полном прогоне.
Ничего не ломается — просто там она появится позже.

Ключ считается ровно так же, как в общем скрипте, — md5 от содержимого, первые
восемь знаков. Это важно: возьми я другую хэш-функцию, ключи разошлись бы, и
следующий полный прогон переставил бы их заново на всех страницах.

Запуск:  python3 tools/bump_key_pages.py [--dry]
"""
import argparse
import glob
import hashlib
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
REF = re.compile(r'(?P<attr>href|src)="(?P<path>[^"]+?\.(?:css|js))\?v=(?P<ver>[A-Za-z0-9_.-]+)"')

NABOR = ['*.html', 'catalog/*.html', 'brands/*.html', 'otrasli/*.html',
         'uslugi/*.html', 'cases/*.html',
         'blog/index.html', 'glossary/index.html', 'analog/index.html',
         'reduktor/index.html', 'motor-reduktor-zr/index.html']

_hash = {}


def kluch(page, ref):
    """Хэш ассета, на который ссылается страница; None — файла нет."""
    if ref in _hash:
        return _hash[ref]
    disk = (os.path.join(ROOT, ref.lstrip('/')) if ref.startswith('/')
            else os.path.normpath(os.path.join(os.path.dirname(page), ref)))
    v = None
    if os.path.isfile(disk):
        with open(disk, 'rb') as f:
            v = hashlib.md5(f.read()).hexdigest()[:8]
    _hash[ref] = v
    return v


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--dry', action='store_true')
    args = ap.parse_args()

    faily = []
    for p in NABOR:
        faily += glob.glob(os.path.join(ROOT, p))
    faily = sorted(set(faily))

    tronuto = zamen = 0
    svodka = {}
    for path in faily:
        with open(path, encoding='utf-8') as f:
            s = f.read()
        nov = s
        for m in list(REF.finditer(s))[::-1]:
            v = kluch(path, m.group('path'))
            if not v or v == m.group('ver'):
                continue
            nov = nov[:m.start('ver')] + v + nov[m.end('ver'):]
            zamen += 1
            imya = os.path.basename(m.group('path'))
            svodka[imya] = svodka.get(imya, 0) + 1
        if nov != s:
            tronuto += 1
            if not args.dry:
                with open(path, 'w', encoding='utf-8') as f:
                    f.write(nov)

    print(f'страниц просмотрено: {len(faily)}, {"будет правлено" if args.dry else "правлено"}: {tronuto}')
    print(f'ключей обновлено: {zamen}')
    for k, n in sorted(svodka.items(), key=lambda kv: -kv[1]):
        print(f'   {k:22} {n}')
    if tronuto == 0:
        print('ничего не изменилось — ключи уже соответствуют содержимому')


if __name__ == '__main__':
    main()
