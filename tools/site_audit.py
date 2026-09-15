#!/usr/bin/env python3
"""Сквозной аудит сайта: одним проходом по всем HTML-страницам.

Отвечает на вопрос «что на сайте сломано или сделано небрежно» по тем признакам, которые
поисковик и посетитель замечают сразу: заголовок, описание, H1, canonical, битые ссылки и
картинки, подписи к картинкам, разметка для поисковика, мобильная вёрстка.

Чем отличается от соседей:
  tools/check_site.py  — сторожевые проверки против откатов конкретных правок, 12 штук.
  tools/card_audit.py  — соответствие карточек товара эталонному образцу.
  tools/check_prod.py  — что реально отдаёт боевой сервер.
  этот скрипт          — общее состояние всех страниц разом, без привязки к истории правок.

Ничего не меняет — только считает и печатает.

Запуск:  python3 tools/site_audit.py [--section НАЗВАНИЕ] [--limit N] [--examples N]
"""
import argparse
import collections
import glob
import json
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SITE = 'https://zavod-red.ru'

TITLE = re.compile(r'<title>(.*?)</title>', re.S)
DESC = re.compile(r'<meta\s+name="description"\s+content="([^"]*)"', re.I)
CANON = re.compile(r'<link\s+rel="canonical"\s+href="([^"]*)"', re.I)
H1 = re.compile(r'<h1\b[^>]*>(.*?)</h1>', re.S | re.I)
IMG = re.compile(r'<img\b([^>]*)>', re.I)
ATTR = re.compile(r'(\w[\w-]*)\s*=\s*"([^"]*)"')
LINK = re.compile(r'<a\b[^>]*\bhref="([^"]+)"', re.I)
LD = re.compile(r'<script[^>]+type="application/ld\+json"[^>]*>(.*?)</script>', re.S | re.I)
TAGS = re.compile(r'<[^>]+>')

# Длины, при которых Яндекс и Google обрезают строку в выдаче.
TITLE_MAX, DESC_MAX, DESC_MIN = 70, 160, 70


def text_of(html):
    return TAGS.sub(' ', html).strip()


def rel_to_path(href, page):
    """Внутренняя ссылка → путь к файлу. None, если ссылка внешняя или не на страницу."""
    if href.startswith(('http://', 'https://')):
        if not href.startswith(SITE):
            return None
        href = href[len(SITE):] or '/'
    if href.startswith(('mailto:', 'tel:', 'javascript:', '#', 'data:')):
        return None
    href = href.split('#')[0].split('?')[0]
    if not href:
        return None
    if href.startswith('/'):
        base = os.path.join(ROOT, href.lstrip('/'))
    else:
        base = os.path.normpath(os.path.join(os.path.dirname(page), href))
    return base


def exists_page(base):
    if base.endswith('/') or os.path.isdir(base):
        return os.path.exists(os.path.join(base, 'index.html'))
    return os.path.exists(base) or os.path.exists(base + '.html')


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--section', help='только этот раздел (analog, blog, …)')
    ap.add_argument('--limit', type=int, help='ограничить число страниц (для быстрой пробы)')
    ap.add_argument('--examples', type=int, default=3, help='сколько примеров печатать')
    args = ap.parse_args()

    pattern = os.path.join(ROOT, args.section, '*.html') if args.section else \
        os.path.join(ROOT, '**', '*.html')
    pages = [p for p in glob.glob(pattern, recursive=not args.section)
             if not p.startswith(os.path.join(ROOT, 'dist') + os.sep)]
    pages.sort()
    if args.limit:
        pages = pages[:args.limit]

    issues = collections.Counter()
    examples = collections.defaultdict(list)
    titles = collections.Counter()
    descs = collections.Counter()
    h1s = collections.Counter()
    link_targets = collections.defaultdict(list)   # путь → страницы, которые на него ссылаются
    img_targets = collections.defaultdict(list)
    per_section = collections.Counter()

    def note(key, page, extra=''):
        issues[key] += 1
        if len(examples[key]) < args.examples:
            examples[key].append(os.path.relpath(page, ROOT) + (f'  {extra}' if extra else ''))

    for page in pages:
        rel = os.path.relpath(page, ROOT)
        section = rel.split(os.sep)[0] if os.sep in rel else 'корень'
        per_section[section] += 1
        try:
            with open(page, encoding='utf-8', errors='ignore') as f:
                html = f.read()
        except OSError:
            note('файл не читается', page)
            continue

        # ── заголовок ──────────────────────────────────────────────────────
        m = TITLE.search(html)
        if not m or not m.group(1).strip():
            note('нет <title>', page)
        else:
            t = text_of(m.group(1))
            titles[t] += 1
            if len(t) > TITLE_MAX:
                note(f'<title> длиннее {TITLE_MAX} знаков', page, f'{len(t)}')

        # ── описание ───────────────────────────────────────────────────────
        m = DESC.search(html)
        if not m or not m.group(1).strip():
            note('нет meta description', page)
        else:
            d = m.group(1).strip()
            descs[d] += 1
            if len(d) > DESC_MAX:
                note(f'description длиннее {DESC_MAX} знаков', page, f'{len(d)}')
            elif len(d) < DESC_MIN:
                note(f'description короче {DESC_MIN} знаков', page, f'{len(d)}')

        # ── H1 ─────────────────────────────────────────────────────────────
        hs = H1.findall(html)
        if not hs:
            note('нет <h1>', page)
        elif len(hs) > 1:
            note('больше одного <h1>', page, f'{len(hs)}')
        if hs:
            h1s[text_of(hs[0])] += 1

        # ── canonical ──────────────────────────────────────────────────────
        m = CANON.search(html)
        if not m:
            note('нет canonical', page)

        # ── обязательное в <head> ──────────────────────────────────────────
        if not re.search(r'<html[^>]+lang=', html, re.I):
            note('нет lang у <html>', page)
        if not re.search(r'<meta[^>]+charset=', html, re.I):
            note('нет charset', page)
        if not re.search(r'name="viewport"', html, re.I):
            note('нет viewport (мобильные)', page)

        # ── картинки ───────────────────────────────────────────────────────
        for tag in IMG.findall(html):
            a = dict(ATTR.findall(tag))
            src = a.get('src', '')
            if not src:
                note('<img> без src', page)
                continue
            if 'alt' not in a:
                note('<img> без alt', page, src[:60])
            elif not a['alt'].strip():
                note('<img> с пустым alt', page, src[:60])
            if not src.startswith(('http', 'data:')):
                p = rel_to_path(src, page)
                if p:
                    img_targets[p].append(page)

        # ── ссылки ─────────────────────────────────────────────────────────
        for href in LINK.findall(html):
            p = rel_to_path(href, page)
            if p:
                link_targets[p].append(page)

        # ── разметка для поисковика ────────────────────────────────────────
        for block in LD.findall(html):
            try:
                json.loads(block)
            except Exception as e:
                note('битый JSON-LD', page, str(e)[:50])
                break

    # ── проверка целей ссылок и картинок (по одному разу на цель) ──────────
    broken_links = {t: v for t, v in link_targets.items() if not exists_page(t)}
    broken_imgs = {t: v for t, v in img_targets.items() if not os.path.exists(t)}
    for t, srcs in list(broken_links.items())[:args.examples]:
        examples['битая внутренняя ссылка'].append(
            f'{os.path.relpath(srcs[0], ROOT)} → {os.path.relpath(t, ROOT)}')
    for t, srcs in list(broken_imgs.items())[:args.examples]:
        examples['картинка не найдена'].append(
            f'{os.path.relpath(srcs[0], ROOT)} → {os.path.relpath(t, ROOT)}')
    issues['битая внутренняя ссылка'] = sum(len(v) for v in broken_links.values())
    issues['картинка не найдена'] = sum(len(v) for v in broken_imgs.values())

    # ── повторы ───────────────────────────────────────────────────────────
    def repeats(counter, name):
        dup = {k: v for k, v in counter.items() if v > 1}
        pages_hit = sum(v for v in dup.values())
        if dup:
            top = sorted(dup.items(), key=lambda x: -x[1])[:args.examples]
            for k, v in top:
                examples[name].append(f'{v} страниц: {k[:90]}')
        issues[name] = pages_hit
        return len(dup)

    n_t = repeats(titles, 'одинаковый <title> у разных страниц')
    n_d = repeats(descs, 'одинаковое description у разных страниц')
    n_h = repeats(h1s, 'одинаковый <h1> у разных страниц')

    # ── отчёт ─────────────────────────────────────────────────────────────
    print(f'Страниц просмотрено: {len(pages)}')
    print('По разделам: ' + ', '.join(f'{k} {v}' for k, v in per_section.most_common(10)))
    print(f'Уникальных: title {len(titles)}, description {len(descs)}, h1 {len(h1s)}')
    print(f'Повторяющихся значений: title {n_t}, description {n_d}, h1 {n_h}')
    print(f'Внутренних целей ссылок {len(link_targets)}, из них битых {len(broken_links)}')
    print(f'Целей картинок {len(img_targets)}, из них отсутствует {len(broken_imgs)}')
    print()
    print('НАЙДЕНО (страниц / случаев):')
    if not any(issues.values()):
        print('  чисто')
    for key, n in sorted(issues.items(), key=lambda x: -x[1]):
        if not n:
            continue
        print(f'  {n:8}  {key}')
        for ex in examples[key]:
            print(f'            · {ex}')
    return 0


if __name__ == '__main__':
    sys.exit(main())
