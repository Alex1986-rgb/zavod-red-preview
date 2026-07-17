#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Две карточки на страницах /analog/ (импортозамещение).

Переделывает hero-секцию карточки аналога в схему из двух карточек:
«Импортный оригинал» (бренд, страна, параметры) → «Наш аналог ZR»
(собственное производство, те же параметры, CTA «Получить расчёт и КП»).
H1 становится «{импорт} → аналог ZR {n}». Мобайл — карточки в стопку,
стрелка поворачивается вниз (CSS в <style id="cmp-css">).

Применён ко всем analog/*.html 17.07.2026 (73 163 ok, 623 пропущено без
данных). ВАЖНО: если конвейер генерации аналогов перегенерирует страницы,
прогнать скрипт заново — трансформ идемпотентен ('already', если cmp-card
уже есть) и безопасен (страницы без нужных данных возвращает no-lead/no-zr
и не трогает).

Запуск:
    python3 tools/analog_twocard.py analog/имя.html   # одна страница
    python3 tools/analog_twocard.py --all             # все analog/*.html
"""
import re, html, sys, glob

def transform(f):
    t=open(f,encoding='utf-8').read()
    if 'cmp-card' in t: return 'already'
    h1m=re.search(r'<h1[^>]*>([^<]*)</h1>',t)
    if not h1m: return 'no-h1'
    h1=h1m.group(1)
    # маркировка ZR: «аналог ZR 939», «— редуктор ZR 848», «российский ZR 999», «замена на ZR 858»…
    zrm=re.search(r'(?:аналог|редуктор) (ZR [0-9/]+)',h1) or re.search(r'\bZR\s+([0-9][0-9/]*)\b',h1)
    if not zrm: return 'no-zr'
    zr=zrm.group(1) if zrm.group(1).startswith('ZR') else 'ZR '+zrm.group(1)
    imp_short=h1.split(' — ')[0].strip()
    imp_short=re.sub(r'^Аналог\s+(редуктора\s+)?','',imp_short)  # «Аналог редуктора SEW R 97» → «SEW R 97»
    imp_short=re.sub(r'\s+купить$','',imp_short)                 # «NORD 13063 купить» → «NORD 13063»
    lm=re.search(r'<p class="pc-lead">(.*?)</p>',t,re.S)
    if lm:  # основной pc-шаблон
        imp_full=re.search(r'<b>([^<]*)</b>',lm.group(1)); imp_full=imp_full.group(1) if imp_full else ''
        typ=re.search(r'pc-badge">Аналог · ([^<]*)</span>',t); typ=typ.group(1) if typ else ''
        img=re.search(r'pc-media.*?<img src="([^"]*)"',t,re.S); img=img.group(1) if img else '../assets/catalog/cat_cylindrical.webp'
    else:   # старый «купить»-шаблон (без pc-блоков): тип из eyebrow, картинка первая на странице
        imp_full=''
        typ=re.search(r'eyebrow">Купить · аналог · ([^<]*)<',t); typ=typ.group(1) if typ else ''
        img=re.search(r'<img src="([^"]*)"',t); img=img.group(1) if img else '../assets/catalog/cat_cylindrical.webp'
    red=re.search(r'href="(/reduktor/[^"]*)"',t); red=red.group(1) if red else '/podbor?q='+zr.replace(' ','%20')
    chips=dict(re.findall(r'<span>(Мощность|Момент|Передаточное)</span><b>([^<]*)</b>',t))
    pw=chips.get('Мощность','—'); tq=chips.get('Момент','—'); ig=chips.get('Передаточное','—')
    COUNTRY={'SEW':'Германия','NORD':'Германия','Bauer':'Германия','Lenze':'Германия','Watt':'Австрия','Bonfiglioli':'Италия','Motovario':'Италия','Rossi':'Италия','SITI':'Италия','Siti':'Италия','Varvel':'Италия','Innovari':'Италия','STM':'Италия','Transtecno':'Италия','Tramec':'Италия','Yilmaz':'Турция','Guomao':'Китай','Innored':'Китай'}
    country=next((v for k,v in COUNTRY.items() if k.lower() in imp_short.lower()),'импорт')
    e=html.escape
    # спеки: показываем только при наличии данных (у старого «купить»-шаблона их нет)
    specs='' if pw=='—' and tq=='—' and ig=='—' else f'<ul class="cmp-specs"><li>{pw}</li><li>{tq}</li><li>i={ig}</li></ul>'
    imgtag=f'<div class="cmp-media"><img src="{img}" alt="{e(imp_short)}" loading="lazy"></div>'
    imp_card=(f'<div class="cmp-card cmp-import"><span class="cmp-tag">Импортный оригинал</span>{imgtag}'
      f'<div class="cmp-name">{e(imp_full or imp_short)}</div><div class="cmp-sub">{country} · поставка под заказ</div>'
      f'{specs}'
      f'<div class="cmp-tail">Оригинал — сроки и цена от поставщика</div></div>')
    our_card=(f'<div class="cmp-card cmp-ours"><span class="cmp-tag red">Наш аналог ZR</span>{imgtag}'
      f'<div class="cmp-name cmp-zr">{e(zr)}</div><div class="cmp-sub">Завод Редукторов · собственное производство</div>'
      f'{specs}'
      f'<ul class="cmp-adv"><li>Совпадает по размерам — замена без переделки</li><li>Цена ниже · отгрузка от 3 дней · гарантия 24 мес</li></ul>'
      f'<div class="cmp-buy"><span class="pp">Цена по запросу</span><a class="btn lg" data-zayavka href="#zayavka">Получить расчёт и КП</a><a class="btn ghost" href="{red}" style="text-align:center">Карточка ZR &rarr;</a></div></div>')
    HERO=(f'<section class="section" style="padding-top:20px"><div class="wrap">'
      f'<span style="display:inline-block;color:var(--red);font-weight:700;font-size:12px;letter-spacing:.05em;text-transform:uppercase">Импортозамещение · {e(typ)}</span>'
      f'<h1 style="font-size:clamp(22px,3.4vw,31px);margin:8px 0 2px;letter-spacing:-.01em">{e(imp_short)} &rarr; аналог {e(zr)}</h1>'
      f'<p style="color:var(--muted);font-size:14.5px;margin:4px 0 0;max-width:80ch">Импортный редуктор {e(imp_full or imp_short)} и наш аналог {e(zr)} собственного производства — одинаковые параметры и присоединительные размеры, замена без переделки.</p>'
      f'<div class="cmp2">{imp_card}<div class="cmp-eq"><span class="s">&rarr;</span><small>тот же монтаж<br>и размеры</small></div>{our_card}</div>'
      f'<p class="cmp-note">Аналог {e(zr)} повторяет габаритные и присоединительные размеры импортного {e(imp_short)} (валы, фланцы, крепёж) — узел встаёт на штатное место. Совместимость подтверждает инженер по шильду или параметрам.</p>'
      f'</div></section>')
    # надёжная замена всей hero-секции
    m=re.search(r'<section class="section" style="padding-top:22px">.*?</section>', t, re.S)
    if not m: return 'no-hero-section'
    t=t[:m.start()]+HERO+t[m.end():]
    # CSS один раз
    if 'cmp-css' not in t:
        t=t.replace('</head>', CSS+'\n</head>',1)
    open(f,'w',encoding='utf-8').write(t)
    return 'ok'

CSS=('<style id="cmp-css">'
 '.cmp2{display:grid;grid-template-columns:1fr auto 1fr;align-items:stretch;gap:0;margin-top:14px;max-width:100%}'
 '.cmp-card{min-width:0;background:var(--card);border:1px solid var(--line);border-radius:16px;padding:18px;display:flex;flex-direction:column;gap:9px}'
 '.cmp-ours{border-color:color-mix(in srgb,var(--red) 45%,var(--line));box-shadow:0 10px 30px color-mix(in srgb,var(--red) 9%,transparent)}'
 ".cmp-tag{align-self:flex-start;font:700 10.5px/1 'Space Grotesk',sans-serif;text-transform:uppercase;letter-spacing:.03em;padding:5px 10px;border-radius:7px;background:var(--bg);color:var(--muted);border:1px solid var(--line)}"
 '.cmp-tag.red{background:var(--red);color:#fff;border-color:var(--red)}'
 '.cmp-media{background:linear-gradient(160deg,#f6f8fa,#e7edf1);border-radius:12px;aspect-ratio:16/9;display:flex;align-items:center;justify-content:center;padding:8px}'
 '.cmp-media img{max-width:80%;max-height:100%;object-fit:contain;mix-blend-mode:multiply}'
 ".cmp-name{font:700 19px/1.15 'Space Grotesk',sans-serif;color:var(--text);overflow-wrap:anywhere}.cmp-zr{color:var(--red)}"
 '.cmp-sub{font-size:12px;color:var(--muted)}'
 '.cmp-specs{display:flex;flex-wrap:wrap;gap:6px;margin:2px 0 0;padding:0;list-style:none}'
 ".cmp-specs li{font:600 11.5px/1 'IBM Plex Mono',monospace;background:var(--bg);border:1px solid var(--line);border-radius:7px;padding:6px 8px;color:var(--text);white-space:nowrap}"
 '.cmp-adv{margin:6px 0 0;padding:0;list-style:none;display:flex;flex-direction:column;gap:5px}'
 '.cmp-adv li{font-size:12.5px;color:var(--text);padding-left:19px;position:relative}.cmp-adv li::before{content:"✓";position:absolute;left:0;color:#12915f;font-weight:700}'
 '.cmp-tail{margin-top:auto;padding-top:8px;color:var(--muted);font-size:12px}'
 '.cmp-eq{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;padding:0 14px;min-width:76px}'
 ".cmp-eq .s{font:700 28px/1 'Space Grotesk',sans-serif;color:var(--red)}.cmp-eq small{font-size:10.5px;color:var(--muted);text-align:center;line-height:1.3}"
 ".cmp-buy{margin-top:auto;padding-top:8px;display:flex;flex-direction:column;gap:7px}.cmp-buy .pp{font:700 14.5px/1 'Space Grotesk',sans-serif;color:var(--text)}.cmp-buy .btn{width:100%;box-sizing:border-box}"
 '.cmp-note{color:var(--muted);font-size:13px;margin:14px 0 0;max-width:80ch}'
 '@media(max-width:760px){.cmp2{grid-template-columns:1fr}.cmp-eq{flex-direction:row;padding:10px 0;gap:8px}.cmp-eq .s{transform:rotate(90deg)}}'
 '</style>')

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
