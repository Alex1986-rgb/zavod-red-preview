#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Чипы «Заменяет импортные приводы» на карточках /reduktor/*.html вели на /brands/<slug>,
то есть обратно на страницу бренда — с неё пользователь и пришёл. Получалась петля,
а карточка конкретной модели /analog/<u> из /reduktor/ была недостижима вообще.

Скрипт разворачивает приоритет: чип ведёт на карточку модели (/analog/<u>), а ссылка
«вся линейка бренда» остаётся второй и мелкой. Идемпотентен: перед разбором раскручивает
уже обработанные чипы обратно в исходный вид, поэтому повторный прогон ничего не ломает.

Запуск: python3 tools/reduktor_chips_analog.py [--dry]
"""
import re, os, sys, json, glob, collections
from urllib.parse import quote

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

# Слаг из href чипа надёжнее подписи <b>: подписи «живые» («Siti серия MI»,
# «Rossi артикулы R I, MR»), а слаг совпадает с реальной страницей бренда.
SLUG2BRAND = {
    'bauer': 'Bauer', 'bonfiglioli': 'Bonfiglioli', 'innored': 'Innored',
    'innovari': 'Innovari', 'lenze': 'Lenze', 'motovario': 'Motovario',
    'nord': 'NORD', 'rossi': 'Rossi', 'sew': 'SEW-Eurodrive',
    'tramec': 'Tramec',          # исторический слаг, за ним живёт Tramec
    'siti': 'SITI', 'stm': 'STM', 'transtecno': 'Transtecno',
    'varvel': 'Varvel', 'vemper': 'Vemper', 'watt-drive': 'Watt Drive',
    'yilmaz': 'Yilmaz',
}
# Часть чипов siti на деле про Varmec (подпись «Siti Varmec RFV») — если по SITI
# модель не нашлась, добираем по соседнему бренду, иначе теряем живую ссылку.
BRAND_ALT = {'SITI': ['Varmec'], 'Tramec': [], 'NORD': []}

CSS_ID = 'ir-chip-css'
CSS = ('<style id="%s">.imp-rep .ir-chip{display:inline-flex;flex-direction:column;gap:2px;'
       'padding:10px 14px;border:1px solid var(--line);border-radius:12px;background:var(--card);'
       'min-width:0}.imp-rep .ir-chip>a{display:block;padding:0;border:0;background:none;'
       'border-radius:0;text-decoration:none}.imp-rep .ir-chip:hover{border-color:var(--red)}'
       '.imp-rep .ir-chip>a:first-child:hover b{color:var(--red)}'
       '.imp-rep .ir-chip .ir-all{font-size:11px;color:var(--muted);text-decoration:underline;'
       'margin-top:3px}.imp-rep .ir-chip .ir-all:hover{color:var(--red)}</style>') % CSS_ID


def norm(s):
    """Ключ сравнения моделей: «F 107», «F-107», «f107» — одно и то же."""
    return re.sub(r'[^a-z0-9]', '', s.lower())


def build_index(cards):
    """Модель в JSON часто перечисление («BF 06, BF 10, BF 20»), в чипе тоже.
    Поэтому индексируем по каждому отдельному токену, а не по строке целиком."""
    idx = collections.defaultdict(list)
    for c in cards:
        for tok in c.get('m', '').split(','):
            tok = tok.strip()
            if tok:
                idx[(c['b'], norm(tok))].append(c)
    return idx


def resolve(idx, brand, models, self_slug):
    """Ищем карточку по паре (бренд, модель). Возвращает (href, kind) или (None, 'none').
    Приоритет: реально существующий файл analog/<u>.html → /reduktor/<r>?imp=."""
    brands = [brand] + BRAND_ALT.get(brand, [])
    toks = [t.strip() for t in models.split(',') if t.strip()]
    fb = None
    for b in brands:
        for tok in toks:
            # Прямая проверка файла по выведенному слагу «бренд-модель»: у части
            # позиций в JSON пустой u, но карточка analog/<слаг>.html реально есть
            # (напр. tramec-pa-100). Без этого ссылка зря падала в /reduktor/?imp=.
            guess = re.sub(r'[^a-z0-9]+', '-', ('%s %s' % (b, tok)).lower()).strip('-')
            if guess and os.path.exists(os.path.join(ROOT, 'analog', guess + '.html')):
                return '/analog/' + guess, 'analog'
            for c in idx.get((b, norm(tok)), []):
                u = c.get('u')
                # Битую ссылку ставить нельзя: /analog/ генерируется отдельно
                # и покрывает не все позиции JSON.
                if u and os.path.exists(os.path.join(ROOT, 'analog', u + '.html')):
                    return '/analog/' + u, 'analog'
                r = c.get('r')
                if r and r != self_slug and fb is None:
                    fb = ('/reduktor/%s?imp=%s' % (r, quote('%s %s' % (c['b'], tok))), 'fallback')
    return fb if fb else (None, 'none')


CHIP_RAW = re.compile(r'<a href="/brands/([^"]+)"><b>(.*?)</b><i>(.*?)</i></a>')
CHIP_DONE = re.compile(
    r'<span class="ir-chip"><a href="[^"]*"><b>(.*?)</b><i>(.*?)</i></a>'
    r'<a class="ir-all" href="/brands/([^"]+)">[^<]*</a></span>')


def unwrap(html):
    """Откат обработанных чипов в исходный вид — основа идемпотентности."""
    return CHIP_DONE.sub(
        lambda m: '<a href="/brands/%s"><b>%s</b><i>%s</i></a>' % (m.group(3), m.group(1), m.group(2)),
        html)


def main():
    dry = '--dry' in sys.argv
    cards = json.load(open(os.path.join(ROOT, 'assets', 'import-catalog.json'), encoding='utf-8'))['cards']
    idx = build_index(cards)

    st = collections.Counter()
    for path in sorted(glob.glob(os.path.join(ROOT, 'reduktor', '*.html'))):
        self_slug = os.path.basename(path)[:-5]
        src = open(path, encoding='utf-8').read()
        if '<div class="imp-rep">' not in src:
            st['skipped_no_block'] += 1
            continue

        html = unwrap(src)
        block = re.search(r'<div class="imp-rep">(.*?)</div>', html, re.S)
        if not block:
            st['skipped_no_block'] += 1
            continue

        def repl(m):
            slug, label, models = m.group(1), m.group(2), m.group(3)
            brand = SLUG2BRAND.get(slug)
            href, kind = (None, 'none') if not brand else resolve(idx, brand, models, self_slug)
            if not href:
                st['chips_kept'] += 1
                return m.group(0)
            st['chips_' + kind] += 1
            return ('<span class="ir-chip"><a href="%s"><b>%s</b><i>%s</i></a>'
                    '<a class="ir-all" href="/brands/%s">вся линейка бренда</a></span>'
                    % (href, label, models, slug))

        new_block = CHIP_RAW.sub(repl, block.group(0))
        html = html[:block.start()] + new_block + html[block.end():]

        if CSS_ID not in html:
            html = html.replace('</head>', CSS + '</head>', 1)

        st['cards_processed'] += 1
        if html != src:
            st['cards_changed'] += 1
            if not dry:
                open(path, 'w', encoding='utf-8').write(html)

    for k in ('cards_processed', 'cards_changed', 'skipped_no_block',
              'chips_analog', 'chips_fallback', 'chips_kept'):
        print('%-18s %d' % (k, st[k]))


if __name__ == '__main__':
    main()
