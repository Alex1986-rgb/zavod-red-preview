#!/usr/bin/env python3
"""Приводит карты сайта в соответствие с тем, что реально лежит на диске.

Три вещи, которые расходились:

1. ДАТА ИЗМЕНЕНИЯ. В sitemap.xml она стояла июльская, хотя страницы правились позже, а в
   остальных шести картах (93 215 адресов, 98% сайта) её не было вовсе. Поисковик не имеет
   признака, что страницу надо перечитать, и ходит по ней в своём темпе. Дата берётся из
   git — из даты последнего коммита, тронувшего файл. Время правки файла на диске для этого
   не годится: после свежего клона оно у всех файлов одинаковое.

2. ПРОПУЩЕННЫЕ СТРАНИЦЫ. Часть существующих страниц в карты не попала, то есть поисковик
   про них узнаёт только по внутренним ссылкам.

3. ДУБЛИ. Страницы вида «X-2.html», у которых заголовок побуквенно совпадает с «X.html»,
   из карт убираются: их canonical ведёт на базовую страницу (tools/fix_duplicate_canonical.py),
   и звать поисковик индексировать их отдельно — значит спорить с собственным же canonical.

Что НЕ трогается: набор адресов в sitemap.xml с его priority и changefreq (они расставлены
осмысленно, вручную), порядок строк, карты картинок. Скрипт только проставляет lastmod,
добавляет недостающие адреса и убирает дубли.

Скрипт идемпотентен: повторный запуск ничего не меняет.

Запуск:  python3 tools/refresh_sitemaps.py [--dry]
"""
import glob
import os
import re
import subprocess
import sys
from datetime import date

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SITE = 'https://zavod-red.ru'
DRY = '--dry' in sys.argv

# карта → разделы на диске, которые она обязана покрывать (пусто = покрытие не проверяем)
SITEMAPS = {
    'sitemap.xml': ['blog', 'glossary', 'cases', 'uslugi', 'otrasli'],
    'sitemap-hub.xml': [],
    'sitemap-zr.xml': ['motor-reduktor-zr'],
    'sitemap-tiporazmer.xml': ['tiporazmer'],
    'sitemap-ispolnenie.xml': ['ispolnenie'],
    'sitemap-analog.xml': [],
    'sitemap-analog-2.xml': [],
}
INDEX = 'sitemap-index.xml'

URL_BLOCK = re.compile(r'[ \t]*<url>.*?</url>\n?', re.S)
LOC = re.compile(r'<loc>([^<]+)</loc>')
LASTMOD = re.compile(r'<lastmod>[^<]*</lastmod>')
TITLE = re.compile(r'<title>([^<]*)</title>')
CLONE = re.compile(r'-\d+\.html$')


def git_dates():
    """{путь: дата последнего коммита} одним проходом по истории."""
    out = subprocess.run(
        ['git', 'log', '--pretty=format:C %cs', '--name-only', '--no-renames'],
        cwd=ROOT, capture_output=True, text=True, errors='ignore').stdout
    dates, cur = {}, None
    for line in out.splitlines():
        if line.startswith('C '):
            cur = line[2:]
        elif line and cur and line not in dates:
            dates[line] = cur          # история идёт от новых к старым — первое попадание и есть свежее
    return dates


def duplicate_slugs():
    """Страницы-дубли: «X-2.html» с тем же <title>, что у «X.html»."""
    dup = set()
    for section in ('ispolnenie', 'motor-reduktor-zr'):
        for path in glob.glob(os.path.join(ROOT, section, '*.html')):
            base = CLONE.sub('.html', path)
            if base == path or not os.path.exists(base):
                continue
            try:
                with open(path, encoding='utf-8', errors='ignore') as f:
                    a = TITLE.search(f.read(200000))
                with open(base, encoding='utf-8', errors='ignore') as f:
                    b = TITLE.search(f.read(200000))
            except OSError:
                continue
            if a and b and a.group(1) == b.group(1):
                dup.add(f'{section}/{os.path.basename(path)[:-5]}')
    return dup


def canonical_of(relpath):
    """canonical страницы. Нужен, чтобы не звать в индекс то, что сведено на другую."""
    try:
        with open(os.path.join(ROOT, relpath), encoding='utf-8', errors='ignore') as f:
            m = re.search(r'<link rel="canonical" href="([^"]+)"', f.read(200000))
    except OSError:
        return None
    return m.group(1) if m else None


def url_to_relpath(url):
    """Адрес страницы → путь к файлу в репозитории."""
    slug = url[len(SITE):].strip('/')
    if not slug:
        return 'index.html'
    for cand in (slug + '.html', os.path.join(slug, 'index.html')):
        if os.path.exists(os.path.join(ROOT, cand)):
            return cand
    return None


def main():
    dates = git_dates()
    dups = duplicate_slugs()
    today = date.today().isoformat()
    print(f'дат из git: {len(dates)} | страниц-дублей: {len(dups)}')

    touched_maps = []
    for name, sections in SITEMAPS.items():
        path = os.path.join(ROOT, name)
        text = open(path, encoding='utf-8').read()
        blocks = URL_BLOCK.findall(text)
        present = {LOC.search(b).group(1) for b in blocks if LOC.search(b)}

        kept, dropped, dated, undated = [], 0, 0, 0
        for block in blocks:
            m = LOC.search(block)
            if not m:
                kept.append(block)
                continue
            url = m.group(1)
            slug = url[len(SITE):].strip('/')
            if slug in dups:
                dropped += 1
                continue
            rel = url_to_relpath(url)
            day = dates.get(rel) if rel else None
            if day:
                tag = f'<lastmod>{day}</lastmod>'
                block = (LASTMOD.sub(tag, block) if LASTMOD.search(block)
                         else block.replace('</loc>', '</loc>' + tag, 1))
                dated += 1
            else:
                undated += 1
            kept.append(block)

        # недостающие страницы разделов
        added = skipped_canon = 0
        for section in sections:
            for page in sorted(glob.glob(os.path.join(ROOT, section, '*.html'))):
                slug = f'{section}/{os.path.basename(page)[:-5]}'
                if os.path.basename(page) == 'index.html' or slug in dups:
                    continue
                url = f'{SITE}/{slug}'
                if url in present:
                    continue
                # Страницу, сведённую через canonical на другую, в карту не зовём:
                # это то же противоречие, что и cross-canonical — sitemap просит
                # индексировать, а сама страница говорит «индексируй другую».
                own = canonical_of(f'{slug}.html')
                if own and own.rstrip('/') != url.rstrip('/'):
                    skipped_canon += 1
                    continue
                day = dates.get(f'{slug}.html', today)
                kept.append(f'  <url><loc>{url}</loc><lastmod>{day}</lastmod>'
                            f'<priority>0.4</priority></url>\n')
                added += 1

        new = ('<?xml version="1.0" encoding="UTF-8"?>\n'
               '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n'
               + ''.join(kept) + '</urlset>\n')
        changed = new != text
        if changed:
            touched_maps.append(name)
            if not DRY:
                open(path, 'w', encoding='utf-8').write(new)
        print(f'  {name:26} адресов {len(kept):6}  дата проставлена {dated:6}  '
              f'без даты {undated:4}  добавлено {added:3}  убрано дублей {dropped:3}'
              f'  не позваны (canonical на другую) {skipped_canon:3}'
              f'{"" if changed else "  (без изменений)"}')

    # Дата карты в оглавлении — самая свежая дата внутри неё самой. Так значение
    # выводится из содержимого и не пляшет от запуска к запуску.
    idx_path = os.path.join(ROOT, INDEX)
    idx = open(idx_path, encoding='utf-8').read()

    def stamp(m):
        name = m.group(1)[len(SITE) + 1:]
        p = os.path.join(ROOT, name)
        if not os.path.exists(p):
            return m.group(0)
        days = re.findall(r'<lastmod>([^<]+)</lastmod>', open(p, encoding='utf-8').read())
        day = max(days) if days else today
        body = re.sub(r'<lastmod>[^<]*</lastmod>', '', m.group(0))
        return body.replace('</loc>', f'</loc><lastmod>{day}</lastmod>', 1)

    new_idx = re.sub(r'<sitemap><loc>([^<]+)</loc>.*?</sitemap>', stamp, idx)
    if new_idx != idx and not DRY:
        open(idx_path, 'w', encoding='utf-8').write(new_idx)
    print(f'  {INDEX:26} {"обновлён" if new_idx != idx else "без изменений"}')


if __name__ == '__main__':
    main()
