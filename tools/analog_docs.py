#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Блок «Техническая документация» на страницах /analog/ (как у конкурентов).

Вставляет после cmp-note (двухкарточный hero, см. analog_twocard.py) ряд
ссылок-чипов: Расшифровка маркировки ZR → /markirovka-zr, Монтажные
положения → /glossary/montazhnoe-polozhenie, Характеристики → карточка
/reduktor/ этой модели. Чертежи добавим отдельным пунктом, когда будет
готов пайплайн ГОСТ-чертежей.

Идемпотентен ('already'); страницы без cmp-note (623 старых) пропускает.

Запуск: python3 tools/analog_docs.py --all | analog/имя.html
"""
import re, sys, glob

CSS=('.cmp-docs{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:13px 0 0;font-size:12.5px}'
 '.cmp-docs>span{color:var(--muted);font-weight:600}'
 '.cmp-docs a{display:inline-flex;align-items:center;gap:6px;padding:7px 11px;border:1px solid var(--line);'
 'border-radius:9px;background:var(--card);color:var(--text);text-decoration:none;white-space:nowrap}'
 '.cmp-docs a:hover{border-color:var(--red);color:var(--red)}'
 ".cmp-docs a::before{content:'\\1F4C4';font-size:13px}")

def transform(f):
    t=open(f,encoding='utf-8').read()
    if 'cmp-docs' in t: return 'already'
    m=re.search(r'<p class="cmp-note">.*?</p>',t,re.S)
    if not m: return 'no-note'
    red=re.search(r'href="(/reduktor/[^"]*)"',t)
    zr=re.search(r'аналог (ZR [0-9/]+)',t)
    zr=zr.group(1) if zr else 'ZR'
    chips=('<div class="cmp-docs"><span>Техническая документация:</span>'
      '<a href="/markirovka-zr">Расшифровка маркировки ZR</a>'
      '<a href="/glossary/montazhnoe-polozhenie">Монтажные положения</a>'
      +(f'<a href="{red.group(1)}">Характеристики {zr}</a>' if red else '')
      +'</div>')
    t=t[:m.end()]+chips+t[m.end():]
    # CSS — в существующий блок cmp-css
    if '.cmp-docs{' not in t:
        t=t.replace('</style>',CSS+'</style>',1)
    open(f,'w',encoding='utf-8').write(t)
    return 'ok'

if __name__=='__main__':
    if len(sys.argv)>1 and sys.argv[1]=='--all':
        from collections import Counter
        c=Counter()
        for f in glob.glob('analog/*.html'):
            if f.endswith('/index.html'): continue   # хаб раздела — не карточка
            try: c[transform(f)]+=1
            except Exception: c['ERR']+=1
        print(dict(c))
    elif len(sys.argv)>1:
        print(transform(sys.argv[1]))
    else:
        print(__doc__)
