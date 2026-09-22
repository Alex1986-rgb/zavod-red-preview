#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Даёт KEB и Siemens настоящую таблицу замен — её у них не было.

На brands/keb.html и brands/siemens.html кнопка «Таблица замен» ведёт на якорь
#xtable, а там стоит блок «Поставка оригинала»: перечень серий с назначением и ни
слова о том, чем мы их заменяем. По запросу «KEB аналог» странице нечего ответить.
У остальных одиннадцати целевых марок такая таблица есть — она строится из карточек
analog/, а у KEB и Siemens карточек ноль из 73 900.

Откуда данные. assets/podbor.js, константа SERIESMAP: сопоставление серий по типу,
собранное по веб-каталогам производителей. Там же оговорка автора — «типоразмер
уточняет инженер». Соответствие типа нашим сериям ZR взято из карточек: соосно-
цилиндрические — ZR 9х9, коническо-цилиндрические — ZR 8х8, плоско-цилиндрические —
ZR 6х6, червячные — ZR 60х.

Чего скрипт НЕ делает: не выдумывает помодельное соответствие. Его в репозитории нет,
и сочинять таблицу «KEB G50 → ZR 959» было бы враньём в документе, по которому
заказывают оборудование. Таблица посерийная, и прямо об этом сказано в подписи.

Прежний блок «Поставка оригинала» остаётся на странице — он полезен, — но переезжает
под новый якорь, чтобы кнопка «Таблица замен» вела туда, куда обещает.

Запуск:  python3 tools/keb_siemens_xtable.py [--dry]
"""
import argparse
import os

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

# тип → (человеческое имя, наши серии, адрес раздела)
TIPY = {
    'soos':  ('Соосно-цилиндрические',                 'ZR 939 — ZR 999',  '/catalog/soosnye'),
    'kon':   ('Коническо-цилиндрические (угловые)',    'ZR 838 — ZR 888',  '/catalog/konichesko-cilindricheskie'),
    'plosk': ('Плоско-цилиндрические (параллельно-осевые)', 'ZR 636 — ZR 6156', '/catalog/ploskie'),
    'cherv': ('Червячные',                             'ZR 601 — ZR 6013', '/catalog/chervyachnye'),
}

MARKI = {
    'keb': ('KEB', 'КЕБ', [
        ('G',  'soos'), ('K',  'kon'), ('F',  'plosk'), ('S',  'cherv'),
    ]),
    'siemens': ('Siemens SIMOGEAR', 'Сименс', [
        ('E / Z / D', 'soos'), ('B / K', 'kon'), ('FZ / FD', 'plosk'), ('S', 'cherv'),
    ]),
}


def tablica(lat, cyr, ryady):
    stroki = ''.join(
        f'<tr><td>{ser}</td><td>{TIPY[t][0]}</td>'
        f'<td><b><a href="{TIPY[t][2]}">{TIPY[t][1]}</a></b></td></tr>'
        for ser, t in ryady)
    return (
        '<section id="xtable" class="section" style="padding-top:34px"><div class="wrap">\n'
        '  <div class="eyebrow">Таблица подбора</div>\n'
        f'  <h2 style="font-size:26px">Замены {lat} → наш редуктор ZR</h2>\n'
        '  <p class="lead" style="margin-bottom:18px">Соответствие по типу привода: у каждой '
        f'серии {lat} ({cyr}) есть наша серия того же типа и с теми же присоединительными '
        'размерами. Таблица посерийная — помодельного соответствия по этой марке мы не '
        'публикуем, чтобы не выдавать расчёт за факт: типоразмер подтверждает инженер по '
        'шильду или чертежу.</p>\n'
        f'  <table class="xref"><thead><tr><th>Серия {lat}</th><th>Тип привода</th>'
        '<th>Наш аналог</th></tr></thead><tbody>' + stroki + '</tbody></table>\n'
        '  <p class="lead" style="margin-top:16px">Пришлите обозначение с шильда — инженер '
        'определит типоразмер, проверит момент, передаточное число и монтаж, и назовёт '
        'конкретную модель ZR.</p>\n'
        '  <a class="btn lg" data-zayavka href="#zayavka" style="min-width:260px;justify-content:center">'
        'Подобрать замену по шильду</a>\n'
        '</div></section>\n\n')


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--dry', action='store_true')
    args = ap.parse_args()

    for key, (lat, cyr, ryady) in MARKI.items():
        path = os.path.join(ROOT, 'brands', key + '.html')
        s = open(path, encoding='utf-8').read()
        if 'id="xtable" class="section" style="padding-top:34px"><div class="wrap">\n  <div class="eyebrow">Таблица подбора' in s:
            print(f'  {key:10} уже сделано'); continue
        staryy = '<section id="xtable" class="section" style="padding-top:34px">'
        if staryy not in s:
            print(f'  {key:10} секция #xtable не найдена'); continue
        # прежний блок поставки оригинала переезжает под свой якорь
        s = s.replace(staryy, '<section id="original" class="section" style="padding-top:34px">', 1)
        i = s.index('<section id="original"')
        s = s[:i] + tablica(lat, cyr, ryady) + s[i:]
        print(f'  {key:10} таблица на {len(ryady)} строк вставлена; блок поставки — под #original')
        if not args.dry:
            open(path, 'w', encoding='utf-8').write(s)


if __name__ == '__main__':
    main()
