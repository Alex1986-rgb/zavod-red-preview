#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Плитки каталога → карточки товара /reduktor/ + чистка бейджей «Аналог».

Клик по плитке в каталоге должен вести на чистую карточку товара
(эталон /reduktor/evl-063-747), а не на калькулятор /podbor. Бейдж
«Аналог {brand}» убирается — на плитке остаётся продукт ZR.

- pcard-title href="/podbor?q=ZR N" → "/reduktor/{slug}" (карта по коду товара).
  Плитки без своей карточки (планетарные 3МП/МР/МПО) остаются на /podbor.
- <span class="pcard-badge">Аналог {brand}</span> → удаляется.

Идемпотентен. Запуск: python3 tools/catalog_tiles.py --all | файл
"""
import re, sys, glob, os, json
from urllib.parse import unquote

BASE=os.path.join(os.path.dirname(__file__),'..')

def build_map():
    m={}
    for f in glob.glob(os.path.join(BASE,'reduktor','*.html')):
        if f.endswith('index.html'): continue
        t=open(f,encoding='utf-8').read()
        mm=re.search(r'Код товара / Модель:</span><span class="v">(ZR [0-9/ХX]+)',t)
        if mm: m[mm.group(1)]=os.path.basename(f)[:-5]
    return m

ZR2SLUG=build_map()

def transform(f):
    t=open(f,encoding='utf-8').read()
    o=t
    # 1) перелинковка pcard-title
    def relink(mt):
        zr=unquote(mt.group(1))
        slug=ZR2SLUG.get(zr)
        return f'pcard-title" href="/reduktor/{slug}"' if slug else mt.group(0)
    t=re.sub(r'pcard-title" href="/podbor\?q=([^"]*)"', relink, t)
    # 2) убрать бейдж «Аналог/АНАЛОГ {brand}» (любой регистр; ГОСТ-бейджи не трогаем)
    t=re.sub(r'<span class="pcard-badge">(?:Аналог|АНАЛОГ|аналог) [^<]*</span>','',t)
    if t!=o:
        open(f,'w',encoding='utf-8').write(t)
        return 'ok'
    return 'nochange'

if __name__=='__main__':
    if len(sys.argv)>1 and sys.argv[1]=='--all':
        from collections import Counter
        c=Counter()
        for f in glob.glob(os.path.join(BASE,'catalog','*.html')):
            try: c[transform(f)]+=1
            except Exception as ex: c['ERR:'+str(ex)[:40]]+=1
        print('карта ZR→слаг:',len(ZR2SLUG),'|',dict(c))
    elif len(sys.argv)>1:
        print(transform(sys.argv[1]))
    else:
        print(__doc__)
