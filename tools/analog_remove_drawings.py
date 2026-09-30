#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Убирает чертежи со страниц /analog/.

Чертежи — наша конструкторская документация, и на карточках импортных аналогов ей
не место: по просьбе заказчика их оттуда убираем целиком, а не прячем стилями.
Прятать было бы бесполезно — разметка остаётся в исходном коде страницы, и файл
по-прежнему открывается по прямой ссылке.

Что вырезается на каждой карточке:
  1. кнопки чертежей в галерее (.p2-thumbs) — они там стоят после фотографий;
  2. секция «Чертёж» целиком (id="chertezh");
  3. ссылка на неё в навигации карточки.

Чего скрипт НЕ делает. Файлы /assets/drawings/*.png остаются на сервере и
по-прежнему открываются по прямой ссылке, а также показываются на страницах
/ispolnenie/, /tiporazmer/, /motor-reduktor-zr/ и в разделе /chertezhi. Это
отдельное решение — сказать об этом заказчику, а не решать за него.

Скрипт идемпотентен: повторный запуск ничего не меняет.

Запуск:  python3 tools/analog_remove_drawings.py [--dry] [--limit N]
"""
import argparse
import os
import re

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
KATALOG = os.path.join(ROOT, 'analog')

# Кнопка галереи целиком. Ленивый повтор внутри — чтобы не съесть соседние кнопки.
KNOPKA = re.compile(
    r'<button type="button"[^>]*data-src="[^"]*assets/drawings/[^"]*"[^>]*>.*?</button>',
    re.S)
# Секция «Чертёж». Внутри неё вложенных <section> нет — проверено на карточках.
SEKTSIYA = re.compile(r'<section[^>]*id="chertezh".*?</section>', re.S)
# Ссылка на секцию в навигации карточки.
SSYLKA = re.compile(r'<a href="#chertezh">[^<]*</a>')


def pochistit(s):
    """Возвращает (новый текст, сколько чего убрано)."""
    s, knopok = KNOPKA.subn('', s)
    s, sektsiy = SEKTSIYA.subn('', s)
    s, ssylok = SSYLKA.subn('', s)
    return s, knopok, sektsiy, ssylok


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--dry', action='store_true')
    ap.add_argument('--limit', type=int, default=0)
    args = ap.parse_args()

    faily = sorted(f for f in os.listdir(KATALOG) if f.endswith('.html'))
    if args.limit:
        faily = faily[:args.limit]

    tronuto = propusk = 0
    itogo = [0, 0, 0]
    ostatki = []
    for i, f in enumerate(faily, 1):
        put = os.path.join(KATALOG, f)
        with open(put, encoding='utf-8') as fh:
            s = fh.read()
        if 'assets/drawings/' not in s and 'id="chertezh"' not in s:
            propusk += 1
            continue
        nov, a, b, c = pochistit(s)
        itogo[0] += a; itogo[1] += b; itogo[2] += c
        if 'assets/drawings/' in nov:
            ostatki.append(f)
        if nov != s:
            tronuto += 1
            if not args.dry:
                with open(put, 'w', encoding='utf-8') as fh:
                    fh.write(nov)
        if i % 10000 == 0:
            print(f'  ...{i} из {len(faily)}', flush=True)

    print(f'{"будет правлено" if args.dry else "правлено"} карточек: {tronuto}, '
          f'уже без чертежей: {propusk}')
    print(f'   кнопок в галерее: {itogo[0]}, секций «Чертёж»: {itogo[1]}, ссылок в меню: {itogo[2]}')
    if ostatki:
        print(f'   ОСТАЛИСЬ упоминания чертежей на {len(ostatki)} карточках, например: {ostatki[:3]}')
    else:
        print('   упоминаний чертежей не осталось ни на одной')


if __name__ == '__main__':
    main()
