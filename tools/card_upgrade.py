#!/usr/bin/env python3
"""
Трансформы приведения карточек zavod-red.ru к «pro»-образу (см. skill zavod-card-standard).
Идемпотентно: маркеры не дают повторно применить. Каждый шаг защищён — не совпало,
файл не меняется. Контент — из параметров самой карточки, без выдумывания.

    python3 tools/card_upgrade.py reduktor            # доработать семью reduktor до PRO
    python3 tools/card_upgrade.py reduktor --dry FILE  # показать результат по одному файлу
"""
import re, sys, glob, os, html

# ---------- helpers ----------
def model_from(t):
    m = re.search(r'<h1[^>]*>\s*(?:Редуктор|Мотор-редуктор)\s+([A-ZА-Я0-9/\-\.]+)', t)
    if m: return m.group(1).strip()
    m = re.search(r'\b(ZR\s?[0-9][0-9/\-\.]*)', t)
    return m.group(1).strip() if m else "ZR"

def typ_from(t):
    for pat, name in [('коническо-цилиндрическ','коническо-цилиндрический'),
                      ('цилиндрическ','цилиндрический'),
                      ('червячн','червячный'),
                      ('планетарн','планетарный'),
                      ('волнов','волновой')]:
        if pat in t.lower(): return name
    return 'редуктор'

# ---------- reduktor: finish to PRO ----------
SEO_MARK = "<!--seoblk-->"
FAQ_MARK = "<!--faqgen-->"

def red_opis_lead(t):
    """Пометить первые 2 абзаца описания классом opis-lead (в секции #opis; и .rich, и .seo-text)."""
    m = re.search(r'<section[^>]*id="opis".*?</section>', t, re.S)
    if not m or 'opis-lead' in m.group(0):
        return t, False
    sec = m.group(0)
    n = [0]
    def repl(mm):
        if n[0] < 2:
            n[0] += 1
            return '<p class="opis-lead">'
        return mm.group(0)
    sec2 = re.sub(r'<p>', repl, sec)
    if n[0] == 0:
        return t, False
    return t[:m.start()] + sec2 + t[m.end():], True

def red_faq2_convert(t):
    """Плоский FAQ (seo-text + details) в секции #faq → двухколоночный faq2."""
    if 'class="faq2"' in t:
        return t, False
    m = re.search(r'<section[^>]*id="faq"[^>]*>.*?(<div class="seo-text">((?:\s*<details>.*?</details>)+)\s*</div>)', t, re.S)
    if not m:
        return t, False
    block, inner = m.group(1), m.group(2)
    dets = re.findall(r'<details>.*?</details>', inner, re.S)
    if not dets:
        return t, False
    half = (len(dets) + 1) // 2
    new = ('<div class="faq2"><div class="cat-faq">' + ''.join(dets[:half]) +
           '</div><div class="cat-faq">' + ''.join(dets[half:]) + '</div></div>')
    return t.replace(block, new, 1), True

def red_seo_tbl(t, model):
    """Добавить id=seo и таблицу seo-tbl в завершающий блок seo-text."""
    if SEO_MARK in t: return t, False
    m = re.search(r'(<section class="section" style="padding-top:0"><div class="wrap"><div class="seo-text">)(.*?)(</div></div></section>)', t, re.S)
    if not m: return t, False
    tbl = (SEO_MARK +
      '<table class="seo-tbl"><caption>Импортозамещение: ' + html.escape(model) + ' — замена импортных приводов</caption>'
      '<thead><tr><th>Импортный бренд</th><th>Наш аналог</th></tr></thead><tbody>'
      '<tr><td>SEW-Eurodrive</td><td>' + html.escape(model) + '</td></tr>'
      '<tr><td>NORD</td><td>' + html.escape(model) + '</td></tr>'
      '<tr><td>Motovario</td><td>' + html.escape(model) + '</td></tr>'
      '<tr><td>Bonfiglioli</td><td>' + html.escape(model) + '</td></tr>'
      '</tbody></table>')
    # секцию делаем id="seo"; таблицу кладём внутрь seo-more/seo-body если есть, иначе перед </div>
    sec = m.group(0).replace('<section class="section" style="padding-top:0">',
                             '<section class="section" id="seo" style="padding-top:0">', 1)
    if '<div class="seo-body">' in sec:
        sec = sec.replace('<div class="seo-body">', '<div class="seo-body">' + tbl, 1)
    else:
        sec = sec.replace('</div></div></section>', tbl + '</div></div></section>', 1)
    return t[:m.start()] + sec + t[m.end():], True

def red_seo_new(t, model):
    """Фолбэк: нет хвостового seo-блока — вставить свежую секцию #seo после #faq."""
    if SEO_MARK in t or 'id="seo"' in t:
        return t, False
    fm = re.search(r'<section[^>]*id="faq".*?</section>', t, re.S)
    if not fm:
        return t, False
    sec = ('<section class="section" id="seo" style="padding-top:0"><div class="wrap"><div class="seo-text">'
      '<p class="seo-lead">' + html.escape(model) + ' — производство Завода Редукторов (ООО «НИИ АТТ», Челябинск): '
      'подбор исполнения, замена импортного привода, поставка с гарантией 24 месяца по всей России.</p>'
      '<details class="seo-more"><summary>Импортозамещение и поставка</summary><div class="seo-body">'
      + SEO_MARK +
      '<table class="seo-tbl"><caption>Импортозамещение: ' + html.escape(model) + ' — замена импортных приводов</caption>'
      '<thead><tr><th>Импортный бренд</th><th>Наш аналог</th></tr></thead><tbody>'
      '<tr><td>SEW-Eurodrive</td><td>' + html.escape(model) + '</td></tr>'
      '<tr><td>NORD</td><td>' + html.escape(model) + '</td></tr>'
      '<tr><td>Motovario</td><td>' + html.escape(model) + '</td></tr>'
      '<tr><td>Bonfiglioli</td><td>' + html.escape(model) + '</td></tr>'
      '</tbody></table><p>Смотрите <a href="/importozameshchenie">импортозамещение приводов</a>, '
      '<a href="/podbor">подбор по параметрам</a> и весь <a href="/catalog/">каталог редукторов</a>.</p>'
      '</div></details></div></div></section>')
    return t[:fm.end()] + sec + t[fm.end():], True

FAQ_Q = [
 ("Что такое {m}?", "{m} — {typ} производства Завода Редукторов (ООО «НИИ АТТ», Челябинск). Поставляется как редуктор под двигатель или как мотор-редуктор с электродвигателем 220/380 В. Точное исполнение подбираем по нагрузке — расчёт в <a href=\"/podbor\">калькуляторе подбора</a>."),
 ("Можно ли заменить импортный редуктор на {m}?", "Да. {m} проектируется как функциональный аналог импортных приводов (SEW, NORD, Motovario, Bonfiglioli) с совпадающими присоединительными и габаритными размерами — замена без переделки рамы. Подробнее — на странице <a href=\"/importozameshchenie\">импортозамещения</a>."),
 ("Какая гарантия и сроки поставки на {m}?", "На всю продукцию ZR действует гарантия 24 месяца. Серийные типоразмеры отгружаются от 3 дней со склада, остальное — под заказ; сроки уточняются по спецификации. Поставка по всей России."),
 ("Как подобрать исполнение {m}?", "Исходите из требуемого момента, оборотов на выходе и характера нагрузки. Пришлите данные с шильда, фото таблички или параметры — инженер подберёт исполнение за 15 минут (<a href=\"/podbor\">подбор по параметрам</a>)."),
]

def red_faq(t, model, typ):
    """Для карточек без faq2 — сгенерировать секцию #faq с faq2 (перед завершающим seo-блоком)."""
    if 'class="faq2"' in t or FAQ_MARK in t: return t, False
    if '<nav class="apro-nav">' not in t: return t, False
    cards = "".join(
        f'<details><summary>{html.escape(q.format(m=model))}</summary><p>'
        + a.format(m=html.escape(model), typ=typ) + '</p></details>'
        for q, a in FAQ_Q)
    sec = (FAQ_MARK + '<section class="section" id="faq" style="padding-top:0"><div class="wrap">'
      '<div class="eyebrow">Вопросы и ответы</div><h2 class="sec-h">Частые вопросы по '
      + html.escape(model) + '</h2><div class="faq2"><div class="cat-faq">' + cards[:len(cards)//2] +
      '</div><div class="cat-faq">' + cards[len(cards)//2:] + '</div></div></div></section>')
    # вставить перед первым завершающим seo-text section, иначе перед </body>/footer
    anchor = t.find('<section class="section" style="padding-top:0"><div class="wrap"><div class="seo-text">')
    if anchor == -1:
        anchor = t.find('id="seo"')
        anchor = t.rfind('<section', 0, anchor) if anchor != -1 else -1
    if anchor == -1: return t, False
    return t[:anchor] + sec + t[anchor:], True

def red_nav_seo(t):
    """Добавить пункт «Вопросы» в apro-nav если его нет (на 12 спец-карточках)."""
    m = re.search(r'(<nav class="apro-nav">)(.*?)(</nav>)', t, re.S)
    if not m or 'href="#faq"' in m.group(2): return t, False
    inner = m.group(2)
    inner2 = inner.replace('<span class="sp">', '<a href="#faq">Вопросы</a><span class="sp">', 1) \
             if '<span class="sp">' in inner else inner + '<a href="#faq">Вопросы</a>'
    return t[:m.start()] + m.group(1) + inner2 + m.group(3) + t[m.end():], True

def upgrade_reduktor(t):
    model, typ = model_from(t), typ_from(t)
    ch = False
    for fn in (red_faq2_convert,                       # плоский FAQ → faq2
               lambda x: red_faq(x, model, typ),       # нет FAQ вовсе → сгенерировать
               red_nav_seo,                            # «Вопросы» в nav
               red_opis_lead,                          # лид-абзацы описания
               lambda x: red_seo_tbl(x, model),        # seo-tbl в хвостовой seo-блок
               lambda x: red_seo_new(x, model)):       # фолбэк: новая секция #seo
        t, c = fn(t); ch = ch or c
    return t, ch

FAMILY = {"reduktor": upgrade_reduktor}

def main():
    a = sys.argv[1:]
    fam = a[0] if a else ""
    if fam not in FAMILY:
        print("families:", ", ".join(FAMILY)); return
    base = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    if "--dry" in a:
        f = a[a.index("--dry")+1]
        t = open(f, encoding="utf-8").read()
        t2, ch = FAMILY[fam](t)
        print("changed:", ch)
        print("sections:", re.findall(r'<section[^>]*id="([a-z0-9]+)"', t2))
        print("faq2:", t2.count('class="faq2"'), "opis-lead:", t2.count('class="opis-lead"'),
              "seo-tbl:", t2.count('class="seo-tbl"'))
        return
    files = [f for f in glob.glob(os.path.join(base, fam, "*.html"))
             if os.path.basename(f) != "index.html"]
    changed = 0
    for f in files:
        t = open(f, encoding="utf-8", errors="replace").read()
        t2, ch = FAMILY[fam](t)
        if ch:
            open(f, "w", encoding="utf-8").write(t2); changed += 1
    print(f"{fam}: изменено {changed}/{len(files)}")

if __name__ == "__main__":
    main()
