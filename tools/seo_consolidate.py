#!/usr/bin/env python3
"""
Консолидация ультра-хвоста: у карточек ispolnenie/ и tiporazmer/ (варианты i/кВт одной модели)
переставляет canonical с самой себя на РОДИТЕЛЬСКУЮ модель (/reduktor/… из хлебных крошек).
Так вес уходит на сильную родительскую карточку, а near-duplicate варианты не конкурируют
за индекс. Идемпотентно.

    python3 tools/seo_consolidate.py --dry ispolnenie/evl-063-747-i120-0_12kvt.html
    python3 tools/seo_consolidate.py --all      # все ispolnenie + tiporazmer
"""
import re, sys, glob, os

HOST = "https://zavod-red.ru"

def parent_of(t):
    m = re.search(r'crumbs.*?(/(?:reduktor|motor-reduktor-zr)/[^"]+)"', t, re.S)
    # берём ПОСЛЕДНюю ссылку модели в крошках (ближайшего родителя)
    links = re.findall(r'href="(/(?:reduktor|motor-reduktor-zr)/[^"]+)"', t[:t.find('</nav>')] if '</nav>' in t else t[:3000])
    crumb = re.search(r'<div class="wrap crumbs">(.*?)</div>', t, re.S)
    if crumb:
        pl = re.findall(r'href="(/(?:reduktor|motor-reduktor-zr)/[^"]+)"', crumb.group(1))
        if pl: return pl[-1]
    return None

def consolidate(t):
    can = re.search(r'<link rel="canonical" href="([^"]+)"\s*/?>', t)
    if not can: return t, False
    parent = parent_of(t)
    if not parent: return t, False
    base = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    pfile = os.path.join(base, parent.strip('/') + '.html')
    if not os.path.exists(pfile): return t, False   # не канонизируем на несуществующую страницу
    target = HOST + parent
    if can.group(1) == target: return t, False   # уже консолидировано
    t = t[:can.start()] + f'<link rel="canonical" href="{target}" />' + t[can.end():]
    return t, True

def main():
    a = sys.argv[1:]
    if '--dry' in a:
        f = a[a.index('--dry')+1]; t = open(f, encoding='utf-8').read()
        t2, ch = consolidate(t)
        m = re.search(r'<link rel="canonical" href="([^"]+)"', t2)
        print('changed:', ch, '| canonical →', m.group(1) if m else '?')
        return
    files = (glob.glob('ispolnenie/*.html') + glob.glob('tiporazmer/*.html')) if '--all' in a \
            else [x for x in a if x.endswith('.html')]
    n = 0
    for f in files:
        if os.path.basename(f) == 'index.html': continue
        t = open(f, encoding='utf-8', errors='replace').read()
        t2, ch = consolidate(t)
        if ch: open(f, 'w', encoding='utf-8').write(t2); n += 1
    print(f'consolidated {n}/{len(files)}')

if __name__ == '__main__':
    main()
