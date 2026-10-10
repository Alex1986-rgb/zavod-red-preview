#!/usr/bin/env python3
"""Главная: вопросы, которые ads6 добавил отдельной секцией перед подвалом (на главной нет .cat-faq,
а inner.css не подключён — блок вышел «голым», с треугольниками), переносим в родной FAQ главной
(.faq-grid → .faq-item / .faq-q / .faq-a). Микроразметка FAQPage уже содержит эти вопросы — не трогаем.
Метка <!-- ads6 --> остаётся (внутри .faq-grid), чтобы ads6 не добавил блок повторно.
    python3 indexfaq.py <public_html> [--apply]"""
import sys, re, pathlib, shutil, time, os
root = pathlib.Path(sys.argv[1]); f = root / 'index.html'; s = f.read_text(encoding='utf-8')
m = re.search(r'<section class="section" style="padding-top:0"><div class="wrap"><!-- ads6 -->.*?</section>', s, re.S)
if not m:
    print('главная: отдельной секции ads6 нет — нечего переносить'); sys.exit(0)
qa = re.findall(r'<details><summary>(.*?)</summary><p>(.*?)</p></details>', m.group(0), re.S)
items = ''.join(f'<div class="faq-item"><button class="faq-q">{q}<span class="faq-ic"></span></button><div class="faq-a"><p>{a}</p></div></div>' for q, a in qa)
s2 = s[:m.start()] + s[m.end():]
g = s2.find('class="faq-grid"')
if g < 0:
    print('СТОП: на главной нет .faq-grid'); sys.exit(1)
# конец .faq-grid: после последнего .faq-item этой сетки
last = s2.rfind('<div class="faq-item">', g, s2.find('</section>', g))
end = s2.find('</div></div>', last) + len('</div></div>')
s2 = s2[:end] + '<!-- ads6 -->' + items + s2[end:]
print(f'главная: перенесено вопросов {len(qa)} в родной FAQ')
if '--apply' in sys.argv:
    bak = pathlib.Path(os.path.expanduser(f'~/_archive_old/indexfaq-{time.strftime("%Y%m%d-%H%M%S")}')); bak.mkdir(parents=True, exist_ok=True)
    shutil.copy2(f, bak / 'index.html'); f.write_text(s2, encoding='utf-8'); print('записано, копия:', bak)
