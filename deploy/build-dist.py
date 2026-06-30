#!/usr/bin/env python3
"""Сборка боевой версии сайта в dist/: вставка счётчика Яндекс.Метрики в <head>,
открытый robots.txt. Запускается в CI перед FTP-деплоем. Превью-репозиторий чистый
(Метрика отключена), боевые правки делаются только здесь."""
import os, shutil, glob

SRC = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))  # корень репозитория
DST = os.path.join(SRC, 'dist')

METRIKA = ('<!-- Yandex.Metrika counter -->\n'
'<script type="text/javascript">(function(m,e,t,r,i,k,a){m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};'
'm[i].l=1*new Date();for(var j=0;j<document.scripts.length;j++){if(document.scripts[j].src===r){return;}}'
'k=e.createElement(t),a=e.getElementsByTagName(t)[0],k.async=1,k.src=r,a.parentNode.insertBefore(k,a)})'
'(window,document,"script","https://mc.yandex.ru/metrika/tag.js","ym");'
'ym(109758131,"init",{clickmap:true,trackLinks:true,accurateTrackBounce:true,webvisor:true});</script>\n'
'<noscript><div><img src="https://mc.yandex.ru/watch/109758131" style="position:absolute;left:-9999px;" alt="" /></div></noscript>')

IGNORE = shutil.ignore_patterns('.git', '.github', 'deploy', 'dist', '*.py', '*.md',
                                '.DS_Store', 'div', 'api', 'admin', 'crm-data', 'migrations')

if os.path.exists(DST):
    shutil.rmtree(DST)
shutil.copytree(SRC, DST, ignore=IGNORE)

# robots.txt — открыт для индексации
with open(os.path.join(DST, 'robots.txt'), 'w', encoding='utf-8') as f:
    f.write("User-agent: *\nAllow: /\n\nSitemap: https://zavod-red.ru/sitemap.xml\nSitemap: https://zavod-red.ru/sitemap-images.xml\nSitemap: https://zavod-red.ru/sitemap-tiporazmer.xml\nSitemap: https://zavod-red.ru/sitemap-ispolnenie.xml\nSitemap: https://zavod-red.ru/sitemap-analog.xml\n")

# Метрика в <head> каждой HTML (если ещё не вставлена)
n = 0
for f in glob.glob(os.path.join(DST, '**', '*.html'), recursive=True):
    t = open(f, encoding='utf-8').read()
    if 'mc.yandex' in t:
        continue
    t = t.replace('<!-- (Метрика отключена) -->', '').replace('</head>', METRIKA + '\n</head>', 1)
    open(f, 'w', encoding='utf-8').write(t)
    n += 1

print(f'dist/ собран: Метрика добавлена на {n} страниц, robots.txt открыт')
