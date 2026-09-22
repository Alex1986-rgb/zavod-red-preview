#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Исправляет страницу бренда Rossi: сейчас она описывает не Rossi, а Varvel.

Что не так. brands/rossi.html сделана копией brands/varvel.html, и фактуру при этом
не поменяли. В таблице замен стоят серии SRT 28–110, SRS 28–150, 7Ч-М, MRN, MRD —
это модельный ряд Varvel; Rossi таких серий не выпускает. Те же чужие обозначения
разошлись ещё по пяти местам страницы: в карточке параметров, в описании подбора по
шильду, в перечне типов и в двух абзацах текста. Совпадение предложений с varvel.html —
около 90%, то есть поисковик эти две страницы склеит и одну из выдачи выбросит.
Обе марки — в списке целевых запросов заказчика.

Откуда берётся правильная фактура. Из собственного каталога: 7 256 карточек
analog/rossi-* уже содержат поля «Модель», «Замена» и «Категория», и там ряд Rossi
настоящий — MR, R I, R 2I, MR V / RV, ECFT. Таблица ниже собрана из этих карточек:
для каждой модели взята замена, которую мы уже публикуем. Значит страница бренда
перестаёт противоречить каталогу, а не начинает спорить с ним по-новому.

Оговорка, которую нельзя замолчать: в самих карточках сопоставление червячных
крупных типоразмеров выглядит неровно — MRV 118 отправлен на тот же ZR 604, что и
MRV 32, а MRV 225 и MRV 325 — оба на ZR 606. Здесь нужна проверка инженера. Страница
и так говорит, что точную замену подтверждает инженер, но ряд стоит перепроверить.

Запуск:  python3 tools/fix_rossi_page.py [--dry]
"""
import argparse
import os
import re

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PAGE = os.path.join(ROOT, 'brands', 'rossi.html')

# Группы таблицы: (подзаголовок, [(модели Rossi, замена ZR)]).
# Строки сведены по замене: у Rossi несколько обозначений одного типоразмера.
TABLE = [
    ('Червячные редукторы и мотор-редукторы', [
        ('MR V 32, MR V 118, RV 32', 'ZR 604'),
        ('MR V 225, MR V 325',       'ZR 606'),
        ('MR V 50, MR V 430, RV 50', 'ZR 607'),
        ('MR V 80, MR V 535, RV 80', 'ZR 609'),
        ('MR V 742',                 'ZR 6010'),
        ('MR V 100, RV 100',         'ZR 6013'),
    ]),
    ('Соосно-цилиндрические мотор-редукторы', [
        ('MR 0, MR 1, MR 2, MR 3', 'ZR 939'),
        ('MR 5, MR 6',             'ZR 959'),
        ('MR 7',                   'ZR 979'),
    ]),
    ('Плоско-цилиндрические (параллельно-осевые) редукторы', [
        ('MR 63, MR 64, R I 63, R 2I 50', 'ZR 636'),
        ('MR 80, R I 80',                 'ZR 646'),
        ('MR 81',                         'ZR 666'),
        ('R I 100',                       'ZR 676'),
        ('MR 125, R I 125, RV 125/126',   'ZR 686'),
        ('MR 140, R I 140, RV 160/161',   'ZR 696'),
        ('MR 180, R I 180, RV 200',       'ZR 6106'),
        ('MR 200, R I 200',               'ZR 6126'),
        ('MR 250, R I 225, R I 250',      'ZR 6156'),
    ]),
    ('Коническо-цилиндрические', [
        ('ECFT 070/146', 'ZR 676/939'),
    ]),
]

# Чужие обозначения вне таблицы: что на что меняем, по одному разу каждое
TEXT = [
    ('Заменяем серии Rossi</th><td>SRT, SRS, MRN, MRD, RO, RV',
     'Заменяем серии Rossi</th><td>MR, MR V, R I, R 2I, RV, ECFT'),

    ('определяем серию (SRT, SRS, MRN, MRD, RO, RV)',
     'определяем серию (MR, MR V, R I, R 2I, RV, ECFT)'),

    ('RO / RV (червячные)',
     'MR V / RV (червячные)'),

    ('SRT / SRS (соосно-цилиндрические)',
     'MR 0–MR 7 (соосно-цилиндрические)'),

    ('редукторы серий RS и RT, червячные с цилиндрической предступенью FRS и FRT, '
     'серия SRS/SRT, а также соосные и коническо-цилиндрические исполнения и вариаторы VR.',
     'червячные MR V и RV, соосно-цилиндрические MR 0–MR 7, плоско-цилиндрические '
     '(параллельно-осевые) MR и R I / R 2I, а также коническо-цилиндрические ECFT.'),

    ('серий RS/RT, FRS/FRT, SRS/SRT и VR',
     'серий MR, MR V, R I, R 2I, RV и ECFT'),

    # Блок «Поставляем оригинальные Rossi» — тот же варвеловский ряд, только другими
    # обозначениями: RS/RT, FRS/FRT, RD/RO/RN и вариаторы VR/TVR. Первый прогон его не
    # тронул, потому что искал SRT/SRS/MRN/MRD.
    ('<tr><td><b>RS / RT</b> — червячные</td><td>RS28, RS40, RS50, RS63, RS75, RS90, RS110</td></tr>\n'
     '    <tr><td><b>FRS / FRT</b> — с предступенью</td><td>Червячные с цилиндрической предступенью</td></tr>\n'
     '    <tr><td><b>RD / RO / RN</b></td><td>Соосно- и коническо-цилиндрические</td></tr>\n'
     '    <tr><td><b>MR / MR V / R I</b></td><td>Серия редукторов</td></tr>\n'
     '    <tr><td><b>VR / TVR</b></td><td>Вариаторы</td></tr>',
     '<tr><td><b>MR V / RV</b> — червячные</td><td>MR V 32, 50, 80, 100, 118, 225, 325, 430, 535, 742</td></tr>\n'
     '    <tr><td><b>MR 0 — MR 7</b> — соосно-цилиндрические</td><td>MR 0, MR 1, MR 2, MR 3, MR 5, MR 6, MR 7</td></tr>\n'
     '    <tr><td><b>MR / R I / R 2I</b> — плоско-цилиндрические</td><td>63, 64, 80, 81, 125, 140, 180, 200, 225, 250</td></tr>\n'
     '    <tr><td><b>ECFT</b> — коническо-цилиндрические</td><td>ECFT 070/146</td></tr>'),

    # Вопрос в блоке «Частые вопросы» — и видимый текст, и разметка FAQPage
    ('Чем аналог серий RS/FRS отличается от оригинала Rossi?',
     'Чем аналог серий MR и R I отличается от оригинала Rossi?'),

    ('<tr><td><b>SRS / SRT</b></td><td>Серия редукторов</td></tr>',
     '<tr><td><b>MR / MR V / R I</b></td><td>Серия редукторов</td></tr>'),

    ('<div class="chips"><span class="brand-chip">SRS</span><span class="brand-chip">SRT</span></div>',
     '<div class="chips"><span class="brand-chip">MR</span><span class="brand-chip">MR V</span>'
     '<span class="brand-chip">R I</span><span class="brand-chip">RV</span>'
     '<span class="brand-chip">ECFT</span></div>'),

    ('<tr><th>Серии</th><td>RS/RT, FRS/FRT, SRS/SRT, VR</td></tr>',
     '<tr><th>Серии</th><td>MR, MR V, R I, R 2I, RV, ECFT</td></tr>'),

    # description Rossi — побайтовая копия варвеловского, вместе с чужими сериями
    ('Rossi (Росси) купить: поставка оригиналов под заказ + аналог редукторов серии ZR '
     '(RS, RT, FRS, RD, SRS, VR). Цена по запросу, подбор по модели или по шильдику.',
     'Rossi (Росси) купить: оригинал под заказ и аналог ZR по присоединительным размерам '
     '— серии MR, MR V, R I, RV, ECFT. Цена по запросу, подбор по шильду.'),

    ('<tr><th>MRN (плоские цилиндрические)</th><td>аналог ZR плоского типа</td></tr>'
     '<tr><th>MRD (коническо-цилиндрические)</th><td>аналог ZR конического типа</td></tr>',
     '<tr><th>MR, R I, R 2I (плоско-цилиндрические)</th><td>аналог ZR плоского типа</td></tr>'
     '<tr><th>ECFT (коническо-цилиндрические)</th><td>аналог ZR конического типа</td></tr>'),
]


def build_tbody():
    out = []
    for zag, rows in TABLE:
        out.append(f'<tr><td class="xref-group" colspan="2">{zag}</td></tr>')
        for mod, zr in rows:
            out.append(f'<tr><td>{mod}</td><td><b>{zr}</b></td></tr>')
    return ''.join(out)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--dry', action='store_true')
    args = ap.parse_args()

    with open(PAGE, encoding='utf-8') as f:
        s = f.read()

    i = s.find('id="xtable"')
    if i < 0:
        raise SystemExit('секция id="xtable" не найдена')
    m = re.search(r'<tbody>.*?</tbody>', s[i:], re.S)
    if not m:
        raise SystemExit('таблица замен не найдена')
    staro = m.group(0)
    novo = '<tbody>' + build_tbody() + '</tbody>'
    s = s[:i] + s[i:].replace(staro, novo, 1)

    zameneno = 0
    for a, b in TEXT:
        if b in s:
            continue
        if a not in s:
            print('  не найдено:', a[:70]); continue
        # Вопрос-ответ живёт на странице дважды: в разметке FAQPage и в видимом тексте.
        # Заменять только первое вхождение нельзя — разметка разойдётся с текстом, и
        # Яндекс перестанет ей верить. Для остальных пар повтор безвреден: они
        # встречаются по разу, и replace без счётчика сделает ровно одну замену.
        s = s.replace(a, b)
        zameneno += 1

    ostalos = len(re.findall(r'SRT|SRS|MRN|MRD|7Ч-М', s))
    strok = sum(len(r) for _, r in TABLE)
    print(f'  таблица: было {staro.count("<tr>")} строк, стало {strok + len(TABLE)}')
    print(f'  правок в тексте: {zameneno}')
    print(f'  чужих обозначений осталось: {ostalos}')

    if not args.dry:
        with open(PAGE, 'w', encoding='utf-8') as f:
            f.write(s)


if __name__ == '__main__':
    main()
