#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Плитки категорийных страниц каталога: увести брендовые плитки на страницу импорта.

Зачем: плитка подписана «ZR <наш> · <Бренд> <Модель>», то есть обещает импортный
бренд, а ссылка вела на /reduktor/evl-*, где бренда нет вообще. Пользователь,
пришедший по бренду, терял его на первом же клике.

Правило ссылки (то же, что уже развёрнуто в brands/* и catalog/index.html):
  есть u  -> /analog/<u>                      — родная страница импорта
  нет u   -> /reduktor/<r>?imp=<Бренд Модель>  — наша карточка ZR, но она
                                                 перестроит H1/title под бренд
                                                 (механика ZR_IMPCTX)

Трогаем ТОЛЬКО <a class="pcard-title"> внутри <article class="pcard">
с непустым data-brands. Плитки без бренда (наша линейка ZR/ПР) и ссылки
.ser-card («типоразмер ZR») остаются на /reduktor/ — они и не обещают импорт.

Скрипт идемпотентен: целевой href каждый раз вычисляется заново из текста
подписи, старый href (включая уже проставленный ?imp=) полностью отбрасывается.

Запуск: python3 tools/catalog_tiles_analog.py [--dry]
"""
import glob
import json
import os
import re
import sys
from urllib.parse import quote

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CATALOG = os.path.join(ROOT, 'catalog')
DATA = os.path.join(ROOT, 'assets', 'import-catalog.json')

# В подписях плиток бренд записан сокращённо, а в import-catalog.json — полностью.
# Без этих алиасов 9 плиток SEW/Tos Znojmo улетали бы в фолбэк на пустом месте.
BRAND_ALIAS = {
    'sew': 'SEW-Eurodrive',
    'tz': 'Tos Znojmo',
    'tos': 'Tos Znojmo',
    'watt': 'Watt Drive',
}

TILE_RE = re.compile(r'<article class="pcard"[^>]*>.*?</article>', re.S)
TITLE_RE = re.compile(r'(<a class="pcard-title" href=")([^"]*)("\s*>)([^<]*)(</a>)')
BRANDS_RE = re.compile(r'data-brands="([^"]*)"')


def build_index(cards):
    """Ключ -> карточка. Приоритет у карточки со слагом u: она даёт брендовую страницу."""
    idx = {}
    for c in cards:
        key = (c['b'] + ' ' + c['m']).lower()
        old = idx.get(key)
        if old is None or (not old.get('u') and c.get('u')):
            idx[key] = c
    return idx


def resolve(imp_text, index, brands_lower):
    """Текст после «·» -> (карточка или None, нормализованное «Бренд Модель»)."""
    key = imp_text.lower()
    if key in index:
        return index[key], imp_text
    # Сокращённый бренд: подменяем первое слово на полное имя из каталога.
    head = imp_text.split(' ', 1)
    if len(head) == 2 and head[0].lower() in BRAND_ALIAS:
        full = BRAND_ALIAS[head[0].lower()] + ' ' + head[1]
        if full.lower() in index:
            return index[full.lower()], full
    # Бренд из двух слов («Tos Znojmo X», «Watt Drive X») — пробуем длинные имена.
    for b in brands_lower:
        if key.startswith(b + ' ') and key in index:
            return index[key], imp_text
    return None, imp_text


def main():
    dry = '--dry' in sys.argv
    with open(DATA, encoding='utf-8') as f:
        cards = json.load(f)['cards']
    index = build_index(cards)
    brands_lower = sorted({c['b'].lower() for c in cards}, key=len, reverse=True)

    stats = {'tiles': 0, 'analog': 0, 'imp': 0, 'unmatched': 0}
    unmatched = []
    changed_files = []

    for path in sorted(glob.glob(os.path.join(CATALOG, '*.html'))):
        if os.path.basename(path) == 'index.html':
            continue  # уже починен отдельно, не наша зона
        with open(path, encoding='utf-8') as f:
            src = f.read()

        def fix_tile(tile_match):
            tile = tile_match.group(0)
            mb = BRANDS_RE.search(tile)
            if not mb or not mb.group(1).strip():
                return tile  # плитка без импортного бренда — ссылка на ZR верна
            mt = TITLE_RE.search(tile)
            if not mt or '·' not in mt.group(4):
                return tile
            imp = mt.group(4).split('·', 1)[1].strip()
            stats['tiles'] += 1
            card, name = resolve(imp, index, brands_lower)
            # Слаг /reduktor/ берём из текущего href, отбрасывая любой хвост
            # запроса — иначе повторный прогон навесил бы второй ?imp=.
            cur = mt.group(2).split('?', 1)[0]
            if card and card.get('u'):
                url = '/analog/' + card['u']
                stats['analog'] += 1
            else:
                slug = (card or {}).get('r') or cur.rsplit('/', 1)[-1]
                url = '/reduktor/' + slug + '?imp=' + quote(name, safe='')
                stats['imp'] += 1
                if not card:
                    stats['unmatched'] += 1
                    unmatched.append((os.path.basename(path), imp))
            return tile[:mt.start()] + mt.group(1) + url + mt.group(3) + mt.group(4) + mt.group(5) + tile[mt.end():]

        out = TILE_RE.sub(fix_tile, src)
        if out != src:
            changed_files.append(os.path.basename(path))
            if not dry:
                with open(path, 'w', encoding='utf-8') as f:
                    f.write(out)

    print('брендовых плиток:      %d' % stats['tiles'])
    print('  -> /analog/<u>:      %d' % stats['analog'])
    print('  -> /reduktor/?imp=:  %d (из них без пары в каталоге: %d)' % (stats['imp'], stats['unmatched']))
    for f, t in unmatched:
        print('     не сопоставлено: %s | %s' % (f, t))
    print('файлов изменено: %d %s' % (len(changed_files), changed_files))
    if dry:
        print('(--dry: ничего не записано)')


if __name__ == '__main__':
    main()
