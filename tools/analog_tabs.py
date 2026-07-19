#!/usr/bin/env python3
"""П.8/П.11 для analog: apro-nav → крупные табы + секции Доставка/Тех.документация/Чертёж.

Приводит карточки /analog/ к тому же виду, что reduktor (см. tools/card_tabs_pro.py):
табы Описание/Характеристики/Доставка и оплата/Техническая документация/Чертёж/Вопросы,
крупные; добавлены универсальные секции #dostavka, #tehdoc, #chertezh перед #faq.

Идемпотентно (маркер id="dostavka"). os.walk — без glob (73k файлов, ARG_MAX).
"""
import os, re, sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
os.chdir(ROOT)

SECTIONS = (
    '<section class="section" id="dostavka" style="padding-top:10px"><div class="wrap">'
    '<h2 class="sec-h">Доставка и оплата</h2>'
    '<div class="why-grid">'
    '<div><b>Отгрузка от 3 дней</b><span>серийные типоразмеры — со склада, остальное под заказ</span></div>'
    '<div><b>Доставка по России</b><span>транспортными компаниями до терминала или адреса; самовывоз со склада</span></div>'
    '<div><b>Оплата по счёту</b><span>безналичный расчёт по договору, УПД; для серийных — индивидуальные условия</span></div>'
    '<div><b>Гарантия 24 месяца</b><span>паспорт изделия и декларация соответствия в комплекте</span></div>'
    '</div></div></section>'
    '<section class="section" id="tehdoc" style="padding-top:10px"><div class="wrap">'
    '<h2 class="sec-h">Техническая документация</h2>'
    '<div class="p2-docs"><span>Документы и справка:</span>'
    '<a href="/markirovka-zr">Расшифровка маркировки ZR</a>'
    '<a href="/glossary/montazhnoe-polozhenie">Монтажные положения</a>'
    '<a href="#spec">Характеристики</a>'
    '<a data-zayavka href="#zayavka">Паспорт и КП по запросу</a></div>'
    '</div></section>'
    '<section class="section" id="chertezh" style="padding-top:10px"><div class="wrap">'
    '<h2 class="sec-h">Чертёж</h2>'
    '<p class="lead" style="margin-bottom:14px">Габаритный и присоединительный чертёж исполнения — по запросу вместе с коммерческим предложением. Ниже — пример габаритного чертежа.</p>'
    '<img src="../assets/drawings/zr-demo-drawing.png" alt="Габаритный чертёж — пример" loading="lazy" style="max-width:640px;width:100%;border:1px solid var(--line);border-radius:12px;background:#fff;padding:10px;display:block">'
    '<p style="margin-top:14px"><a class="btn" data-zayavka href="#zayavka">Запросить чертёж исполнения</a></p>'
    '</div></section>'
)

NEW_NAV = (
    '<nav class="apro-nav">'
    '<a href="#opis">Описание</a>'
    '<a href="#spec">Характеристики</a>'
    '<a href="#dostavka">Доставка и оплата</a>'
    '<a href="#tehdoc">Техническая документация</a>'
    '<a href="#chertezh">Чертёж</a>'
    '<a href="#faq">Вопросы</a>'
    '<span class="sp"></span>'
    '<a class="red" data-zayavka href="#zayavka">Получить расчёт</a>'
    '</nav>'
)

CSS = (
    '<style id="zr-apro-tabs">'
    ".apro-nav a{font-size:14.5px!important;font-weight:600!important;padding:11px 17px!important;"
    "border:1.5px solid var(--line)!important;background:var(--card)!important;color:var(--text)!important;border-radius:10px!important}"
    '.apro-nav a:hover{border-color:var(--red)!important;color:var(--red)!important}'
    '.apro-nav a.red{background:var(--red)!important;color:#fff!important;border-color:var(--red)!important}'
    '.apro-nav{gap:8px!important;padding:12px 0!important}'
    '</style>'
)


def transform(path):
    t = open(path, encoding="utf-8").read()
    if 'class="apro-nav"' not in t:
        return "no-apronav"
    if 'id="dostavka"' in t:
        return "already"
    orig = t
    # вставить секции перед #faq (или #seo, или в конец контента)
    anchor = None
    for sid in ('faq', 'seo'):
        i = t.find('id="%s"' % sid)
        if i >= 0:
            anchor = t.rfind('<section', 0, i)
            break
    if anchor is None or anchor < 0:
        return "no-anchor"
    t = t[:anchor] + SECTIONS + t[anchor:]
    # переписать apro-nav
    t = re.sub(r'<nav class="apro-nav">.*?</nav>', lambda m: NEW_NAV, t, count=1, flags=re.S)
    # CSS
    t = re.sub(r'<style id="zr-apro-tabs">.*?</style>', '', t, flags=re.S)
    t = t.replace('</head>', CSS + '</head>', 1)
    if t == orig:
        return "no-change"
    open(path, "w", encoding="utf-8").write(t)
    return "ok"


if __name__ == "__main__":
    args = [a for a in sys.argv[1:] if a.endswith(".html")]
    from collections import Counter
    st = Counter()
    if args:
        for f in args:
            st[transform(f)] += 1
    else:
        for root, dirs, files in os.walk("analog"):
            for f in files:
                if f.endswith(".html"):
                    st[transform(os.path.join(root, f))] += 1
    print("итог:", dict(st))
