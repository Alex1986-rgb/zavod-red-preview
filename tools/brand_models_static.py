#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Отрисовывает карточки моделей бренда прямо в HTML, а не только скриптом.

Главный смысловой блок страницы бренда — каталог моделей — в исходном коде пустой:
стоит <div class="pcard-grid" id="bmGrid"></div>, а карточки подставляет скрипт,
дочитав /assets/import-catalog.json. Ни одной модели в HTML нет, noscript-запасного
варианта тоже. Поисковый робот, который не выполнил скрипт (а Яндекс делает это не
всегда и не сразу), видит на странице пустоту вместо трёх десятков моделей.

Скрипт кладёт те же карточки в разметку. Формат повторяет функцию card() из самой
страницы буква в букву — потому что скрипт при загрузке перезаписывает содержимое
блока целиком, и если разметка разойдётся, посетитель увидит скачок вёрстки.
Для посетителя ничего не меняется: он получает ровно то же, что и раньше.

Бренд берётся не из списка в скрипте, а из фильтра на самой странице
(c.b === "…") — так страница и данные не разъедутся.

Запуск:  python3 tools/brand_models_static.py [--dry] [--max 24]
"""
import argparse
import html
import json
import os
import re

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PUSTO = '<div class="pcard-grid" id="bmGrid"></div>'


def esc(s):
    """Тот же экранировщик, что и в скрипте страницы: & < > "."""
    return (str(s if s is not None else '')
            .replace('&', '&amp;').replace('<', '&lt;')
            .replace('>', '&gt;').replace('"', '&quot;'))


def card(c, types):
    name = f"{c['b']} {c['m']}"
    if c.get('u'):
        url = '/analog/' + c['u']
    elif c.get('r'):
        from urllib.parse import quote
        url = '/reduktor/' + c['r'] + '?imp=' + quote(name, safe='')
    else:
        from urllib.parse import quote
        url = '/podbor?q=' + quote(name, safe='')
    chips = []
    if c.get('pw'):
        chips.append(c['pw'] + ' кВт')
    if c.get('i'):
        chips.append('i ' + c['i'])
    return (
        '<article class="pcard">'
        f'<div class="pcard-media"><img src="/assets/catalog/{esc(c.get("im"))}.webp" '
        f'alt="{esc(name)} — импортный мотор-редуктор" loading="lazy">'
        f'<span class="pcard-badge imp">{esc(name)}</span>'
        '<span class="pcard-stock">под заказ</span></div>'
        f'<div class="pcard-body"><span class="pcard-type">{esc(types[c["t"]] if c.get("t") is not None else "")}'
        + (' · ' + esc(c['c']) if c.get('c') else '') + '</span>'
        f'<a class="pcard-title" href="{url}">{esc(name)}</a>'
        + (f'<span class="pcard-zr">наш аналог {esc(c["z"])}</span>' if c.get('z') else '')
        + '<div class="pcard-chips">'
        + ''.join(f'<span>{esc(x)}</span>' for x in chips)
        + '</div><div class="pcard-foot"><span class="pcard-price">Цена: <b>по запросу</b></span>'
        f'<a class="zr-more-btn" href="{url}">Подробнее →</a></div></div></article>')


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--dry', action='store_true')
    ap.add_argument('--max', type=int, default=24)
    args = ap.parse_args()

    dannye = json.load(open(os.path.join(ROOT, 'assets', 'import-catalog.json'), encoding='utf-8'))
    types, cards = dannye['t'], dannye['cards']

    vsego = 0
    for f in sorted(os.listdir(os.path.join(ROOT, 'brands'))):
        if not f.endswith('.html') or f == 'index.html':
            continue
        path = os.path.join(ROOT, 'brands', f)
        s = open(path, encoding='utf-8').read()
        if PUSTO not in s:
            print(f'  {f[:-5]:16} пустого блока нет — пропуск'); continue
        m = re.search(r'c\.b\s*===\s*"([^"]+)"', s)
        if not m:
            print(f'  {f[:-5]:16} не нашёл, по какому бренду фильтр'); continue
        brend = m.group(1)
        svoi = [c for c in cards if c.get('b') == brend][:args.max]
        if not svoi:
            print(f'  {f[:-5]:16} моделей бренда «{brend}» в каталоге нет'); continue
        razmetka = ('<div class="pcard-grid" id="bmGrid">'
                    + ''.join(card(c, types) for c in svoi) + '</div>')
        s = s.replace(PUSTO, razmetka, 1)

        # Скрипт при первом проходе ДОПИСЫВАЕТ карточки (insertAdjacentHTML "beforeend"),
        # а не перерисовывает блок. Пока разметка была пустой, это было незаметно; теперь
        # посетитель увидел бы наши карточки плюс первую дюжину от скрипта, с повторами.
        # Чистим блок ровно перед первой отрисовкой — «Показать ещё» при этом работает
        # по-прежнему, оно вызывает more() отдельно.
        init = 'if(CNT)CNT.textContent="Найдено: "+flt.length;more();'
        if init in s:
            s = s.replace(init, 'GR.innerHTML="";' + init, 1)
        else:
            raise SystemExit(f'{f}: не нашёл первый вызов more() — разметка разъедется')
        print(f'  {f[:-5]:16} {brend:16} карточек в разметке: {len(svoi)}')
        if not args.dry:
            open(path, 'w', encoding='utf-8').write(s)
        vsego += len(svoi)
    print(f'{"будет отрисовано" if args.dry else "отрисовано"} карточек: {vsego}')


if __name__ == '__main__':
    main()
