#!/usr/bin/env python3
"""П.8/П.11: единые крупные табы карточки + секции Доставка/Тех.документация/Чертёж.

Эталон — /reduktor/evl-1610 (карточки с .pc-nav). Заказчик (image9) хочет табы:
Описание / Характеристики / Доставка и оплата / Аналоги / Техническая документация / Чертёж — крупные.

Делаем:
  - вставляем универсальные секции #dostavka, #tehdoc, #chertezh перед секцией #faq;
  - переписываем .pc-nav на 6 табов (Аналоги — только если есть #analogi);
  - увеличиваем кнопки .pc-nav (крупные, с рамкой).

Область: карточки с .pc-nav (reduktor/*.html). Идемпотентно (маркер id="dostavka").
"""
import os, re, sys, glob

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

CSS = (
    '<style id="zr-cardtabs">'
    '.pc-nav a{font-size:15px!important;font-weight:600!important;padding:12px 20px!important;'
    'border:1.5px solid var(--line)!important;background:var(--card)!important;color:var(--text)!important;border-radius:11px!important}'
    '.pc-nav a:hover{border-color:var(--red)!important;color:var(--red)!important}'
    '.pc-nav{gap:10px!important;padding:14px 0!important}'
    '</style>'
)

TABS = [
    ("opis", "Описание"),
    ("spec", "Характеристики"),
    ("dostavka", "Доставка и оплата"),
    ("analogi", "Аналоги"),
    ("tehdoc", "Техническая документация"),
    ("chertezh", "Чертёж"),
]


def build_nav(anchors):
    links = "".join(f'<a href="#{a}">{label}</a>' for a, label in TABS if a in anchors)
    return (
        '<nav class="pc-nav"><div class="wrap" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;width:100%">'
        + links
        + '<span class="sp"></span>'
        + '<a data-zayavka href="#zayavka" style="border:1.5px solid var(--red);color:#fff;background:var(--red);'
          'border-radius:11px;font-weight:700;padding:12px 22px">Получить расчёт</a>'
        + '</div></nav>'
    )


def process(path):
    t = open(path, encoding="utf-8").read()
    if 'class="pc-nav"' not in t:
        return "no-pcnav"
    if 'id="dostavka"' in t:
        return "already"
    orig = t

    # 1) вставить универсальные секции перед секцией #faq (или в конец контента)
    mfaq = re.search(r'<section[^>]*id="faq"', t)
    if mfaq:
        sec_start = t.rfind('<section', 0, mfaq.start())
        t = t[:sec_start] + SECTIONS + t[sec_start:]
    else:
        # запасной: перед подвалом
        mf = re.search(r'<footer|<section class="site-footer"|</main>', t)
        pos = mf.start() if mf else len(t)
        t = t[:pos] + SECTIONS + t[pos:]

    # какие анкоры доступны для табов (dostavka/tehdoc/chertezh уже вставлены)
    anchors = set(re.findall(r'id="(opis|spec|analogi|dostavka|tehdoc|chertezh)"', t))

    # 2) переписать pc-nav
    t = re.sub(r'<nav class="pc-nav">.*?</nav>', lambda m: build_nav(anchors), t, count=1, flags=re.S)

    # 3) CSS
    t = re.sub(r'<style id="zr-cardtabs">.*?</style>', '', t, flags=re.S)
    t = t.replace('</head>', CSS + '</head>', 1)

    if t == orig:
        return "no-change"
    open(path, "w", encoding="utf-8").write(t)
    return "ok"


if __name__ == "__main__":
    files = [a for a in sys.argv[1:] if a.endswith(".html")]
    if not files:
        files = glob.glob("reduktor/*.html")
    from collections import Counter
    st = Counter()
    for f in files:
        st[process(f)] += 1
    print("итог:", dict(st))
