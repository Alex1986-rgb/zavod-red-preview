#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Кнопка «Запросить оригинал» на импортной карточке /analog/ (фишка заказчика).

Воронка остаётся на нашем аналоге ZR (красная CTA), но клиент, которому нужен
строго оригинал, не уходит: на карточке «Импортный оригинал» появляется
второстепенная кнопка — открывает ту же модалку заявки с предзаполненной темой
«Нужен ОРИГИНАЛ: {бренд модель}». Обработчик [data-req] добавляется инлайн
(на analog-страницах podbor.js не подключён, в modal.js хендлера нет).

Идемпотентен ('already'). Запуск: python3 tools/analog_origbtn.py --all | файл
"""
import re, sys, glob

CSS=('.cmp-origbtn{margin-top:9px;display:block;text-align:center;font-size:13.5px;'
 'padding:10px 14px;border:1px solid var(--line);border-radius:11px;color:var(--text);'
 'text-decoration:none;background:var(--bg)}'
 '.cmp-origbtn:hover{border-color:var(--red);color:var(--red)}')

JS=('<script id="origreq-js">document.addEventListener("click",function(e){'
 'var b=e.target.closest&&e.target.closest("[data-req]");if(!b)return;'
 'var m=document.getElementById("zrMsg");'
 'if(m&&!m.value)m.value="Нужен ОРИГИНАЛ: "+b.getAttribute("data-req")+". Прошу дать цену и срок поставки.";'
 '});</script>')

def transform(f):
    t=open(f,encoding='utf-8').read()
    if 'cmp-origbtn' in t: return 'already'
    if 'cmp-card cmp-import' not in t: return 'no-cards'
    # имя импорта из карточки
    m=re.search(r'cmp-import">.*?<div class="cmp-name">([^<]*)</div>',t,re.S)
    if not m: return 'no-name'
    imp=m.group(1).strip()
    # вставить кнопку после cmp-tail (конец импортной карточки)
    tail=re.search(r'<div class="cmp-tail">[^<]*</div>',t)
    if not tail: return 'no-tail'
    btn=f'<a class="cmp-origbtn" data-zayavka data-req="{imp}" href="#zayavka">Запросить оригинал {imp}</a>'
    t=t[:tail.end()]+btn+t[tail.end():]
    if '.cmp-origbtn{' not in t:
        t=t.replace('</style>',CSS+'</style>',1)
    if 'origreq-js' not in t:
        t=t.replace('</body>',JS+'\n</body>',1)
    open(f,'w',encoding='utf-8').write(t)
    return 'ok'

if __name__=='__main__':
    if len(sys.argv)>1 and sys.argv[1]=='--all':
        from collections import Counter
        c=Counter()
        for f in glob.glob('analog/*.html'):
            if f.endswith('/index.html'): continue
            try: c[transform(f)]+=1
            except Exception: c['ERR']+=1
        print(dict(c))
    elif len(sys.argv)>1:
        print(transform(sys.argv[1]))
    else:
        print(__doc__)
