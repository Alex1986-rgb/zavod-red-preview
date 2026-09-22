#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Вписывает кириллические написания марок на страницы брендов.

Зачем. Заказчик перечислил целевые запросы парами: «Nord и НОРД», «Bonfiglioli и
бонфиглиоли», «sew eurodrive и сев евродрайв». Проверка показала, что на всех 95 131
странице сайта нет НИ ОДНОГО кириллического написания марки — ни «Бонфиглиоли», ни
«Мотоварио», ни «СЕВ Евродрайв». (Грубый греп даёт 95 128 совпадений на «Росси», но это
слово «России».) То есть по половине целевых запросов сайту просто нечего показать.

Что делает. На каждой странице бренда добавляет кириллическое написание в четырёх местах,
и ровно по одному разу в каждом — так, чтобы текст читался, а не выглядел списком ключей:
  1. подзаголовок под H1 — одно предложение о том, как марку пишут по-русски;
  2. description — форма в скобках после латинского названия;
  3. title — только если результат укладывается в 70 знаков, которые показывает Яндекс;
  4. блок «Частые вопросы» — вопрос-ответ, и та же пара в разметке FAQPage,
     иначе разметка разойдётся с видимым текстом и Яндекс перестанет ей верить.

Формулировки различаются от страницы к странице: тринадцать одинаковых предложений
поисковик склеит как шаблон.

Написания взяты в том виде, в каком их дал заказчик; где распространён второй вариант
(Бауэр при Bauer, Ленце при Lenze) — добавлен и он.

Скрипт идемпотентен: уже обработанные страницы помечены <!-- ZR_CYR --> и пропускаются.

Запуск:  python3 tools/brand_cyrillic.py [--dry] [--only sew,nord]
"""
import argparse
import html
import json
import os
import re

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
MARK = '<!-- ZR_CYR -->'
TITLE_LIMIT = 70          # столько знаков заголовка показывает Яндекс

# файл: (латиница как на странице, основная кириллица, прочие написания)
BRANDS = {
    'sew':         ('SEW-Eurodrive', 'СЕВ Евродрайв', ['СЕВ-Евродрайв', 'Сев Евродрайв']),
    'bonfiglioli': ('Bonfiglioli',   'Бонфиглиоли',   []),
    'nord':        ('NORD',          'НОРД',          ['Норд']),
    'motovario':   ('Motovario',     'Мотоварио',     []),
    'innovari':    ('Innovari',      'Инновари',      []),
    'lenze':       ('Lenze',         'Лензе',         ['Ленце']),
    'bauer':       ('Bauer',         'Бауер',         ['Бауэр']),
    'siti':        ('SITI',          'Сити',          []),
    'varvel':      ('Varvel',        'Варвел',        []),
    'rossi':       ('Rossi',         'Росси',         []),
    'keb':         ('KEB',           'КЕБ',           ['КЭБ']),
    'siemens':     ('Siemens',       'Сименс',        []),
    'stm':         ('STM',           'СТМ',           []),
}

# Порядок формулировок задан списком, а страница берёт свою по номеру в алфавитном
# списке — так тринадцать страниц не получают один и тот же абзац.
LEAD = [
    'В заявках и спецификациях марку часто пишут кириллицей — {cyr}: это то же самое оборудование.',
    'По-русски название передают как {cyr} — оба написания встречаются в закупочной документации.',
    'В спецификациях марка нередко записана кириллицей: {cyr}. Речь об одном и том же производителе.',
    'Кириллическое написание — {cyr}; в каталогах и счетах попадаются оба варианта.',
]
FAQ_Q = [
    'Как пишется {lat} по-русски — {cyr1}?',
    '{cyr1} и {lat} — это одна марка?',
    'Ищу «{cyr1_low}» — это у вас?',
    'В счёте написано «{cyr1_low}». Подберёте замену?',
]
FAQ_A = (
    'Да, это одно и то же. {lat} — латинское написание, {cyr} — то же название кириллицей; '
    'в российских спецификациях, счетах и заявках встречаются оба варианта, и обозначения '
    'моделей от этого не меняются. Пришлите обозначение с шильда в любом написании — '
    'инженер определит серию и типоразмер и подберёт либо оригинал под заказ, '
    'либо наш аналог серии ZR с теми же присоединительными размерами.'
)


def spellings(cyr, alts):
    """«Бауер» или «Бауэр» — читаемый перечень написаний."""
    return cyr if not alts else cyr + ' (также ' + ', '.join(alts) + ')'


def patch_lead(s, cyr_all, idx):
    """Предложение в подзаголовок под H1."""
    m = re.search(r'(<p class="bhero-lead">)(.*?)(</p>)', s, re.S)
    if not m:
        return s, False
    txt = m.group(2).rstrip()
    add = ' ' + LEAD[idx % len(LEAD)].format(cyr=cyr_all)
    return s[:m.start(2)] + txt + add + s[m.end(2):], True


def patch_meta(s, attr, lat, cyr, limit=None):
    """Кириллица в скобках после первого латинского названия в title/description."""
    if attr == 'title':
        m = re.search(r'<title>(.*?)</title>', s, re.S)
    else:
        m = re.search(r'(<meta[^>]+name="description"[^>]+content=")([^"]*)(")', s)
    if not m:
        return s, False
    grp = 1 if attr == 'title' else 2
    val = m.group(grp)
    if cyr in val or lat not in val:
        return s, False
    new = val.replace(lat, f'{lat} ({cyr})', 1)
    if limit and len(html.unescape(new)) > limit:
        return s, False
    return s[:m.start(grp)] + new + s[m.end(grp):], True


def patch_faq(s, lat, cyr, cyr1, idx):
    """Вопрос-ответ в видимый блок и та же пара в разметке FAQPage."""
    # КЕБ и СТМ в нижнем регистре читаются как опечатка, поэтому аббревиатуры не трогаем
    low = cyr1 if (cyr1.isupper() and len(cyr1) <= 4) else cyr1.lower()
    q = FAQ_Q[idx % len(FAQ_Q)].format(lat=lat, cyr1=cyr1, cyr1_low=low)
    a = FAQ_A.format(lat=lat, cyr=cyr)

    anchor = '<div class="faq-grid"><div class="cat-faq">'
    if anchor not in s:
        return s, False
    block = (f'\n    <details><summary>{html.escape(q)}</summary>'
             f'<p>{html.escape(a)}</p></details>')
    i = s.index(anchor) + len(anchor)
    s = s[:i] + block + s[i:]

    # Та же пара в JSON-LD: разметка обязана совпадать с видимым текстом, иначе Яндекс
    # перестаёт ей верить. Блоки разбираем по одному — общая регулярка с .*? проскакивает
    # через </script> и склеивает два соседних блока в один несуществующий объект.
    for m in re.finditer(r'<script type="application/ld\+json">(.*?)</script>', s, re.S):
        raw = m.group(1)
        if '"FAQPage"' not in raw:
            continue
        try:
            data = json.loads(raw)
        except ValueError:
            continue
        if data.get('@type') != 'FAQPage' or not isinstance(data.get('mainEntity'), list):
            continue
        data['mainEntity'].insert(0, {
            '@type': 'Question', 'name': q,
            'acceptedAnswer': {'@type': 'Answer', 'text': a}})
        s = s[:m.start(1)] + json.dumps(data, ensure_ascii=False, separators=(',', ':')) + s[m.end(1):]
        return s, True
    return s, False


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--dry', action='store_true')
    ap.add_argument('--only', default='')
    args = ap.parse_args()
    only = {x.strip() for x in args.only.split(',') if x.strip()}

    done = 0
    for idx, key in enumerate(sorted(BRANDS)):
        if only and key not in only:
            continue
        lat, cyr1, alts = BRANDS[key]
        path = os.path.join(ROOT, 'brands', key + '.html')
        if not os.path.isfile(path):
            print(f'  {key:12} нет файла'); continue
        with open(path, encoding='utf-8') as f:
            s = f.read()
        if MARK in s:
            print(f'  {key:12} уже сделано, пропуск'); continue

        cyr_all = spellings(cyr1, alts)
        did = []
        s, ok = patch_lead(s, cyr_all, idx);            did.append('подзаголовок' if ok else '—')
        s, ok = patch_meta(s, 'description', lat, cyr1); did.append('description' if ok else '—')
        s, ok = patch_meta(s, 'title', lat, cyr1, TITLE_LIMIT); did.append('title' if ok else 'title длинный')
        s, ok = patch_faq(s, lat, cyr_all, cyr1, idx);   did.append('вопрос-ответ' if ok else '—')
        s = s.replace('</head>', MARK + '\n</head>', 1)

        title = re.search(r'<title>(.*?)</title>', s, re.S).group(1)
        print(f'  {key:12} {", ".join(x for x in did if x != "—"):42} title {len(html.unescape(title))} зн.')
        if not args.dry:
            with open(path, 'w', encoding='utf-8') as f:
                f.write(s)
        done += 1
    print(f'{"проверено" if args.dry else "обработано"} страниц: {done}')


if __name__ == '__main__':
    main()
