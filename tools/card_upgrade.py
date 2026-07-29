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

# ---------- motor-reduktor-zr: full parity (p2 hero rebuild) ----------
MRZ_MARK = "/*mrzpro*/"
_PROCSS = None
def procss():
    global _PROCSS
    if _PROCSS is None:
        p = os.path.join(os.path.dirname(os.path.abspath(__file__)), "_procard_css.html")
        _PROCSS = open(p, encoding="utf-8").read()
    return _PROCSS

def spec_dict(t):
    d = {}
    for k, v in re.findall(r'<th>(.*?)</th>\s*<td>(.*?)</td>', t, re.S):
        d[re.sub(r'<.*?>', '', k).strip()] = v.strip()
    return d

def mrz_hero(model, typ, power, i, moment, rpm, img, alt, zav):
    zav_line = (zav or model)
    return (
    '<section class="section" style="padding-top:20px"><div class="wrap"><div class="p2">'
    '<div class="p2-gal"><div class="p2-main"><img id="p2img" src="' + img + '" alt="' + html.escape(alt) + '"></div>'
    '<div class="p2-thumbs"><button type="button" class="act" data-src="' + img + '" aria-label="Фото">'
    '<img src="' + img + '" alt="Фото мотор-редуктора" width="78" height="64"></button>'
    '<button type="button" data-src="../assets/drawings/zr-demo-drawing.png" aria-label="Чертёж">'
    '<img src="../assets/drawings/zr-demo-drawing.png" alt="Габаритный чертёж" width="78" height="64" loading="lazy" decoding="async"></button></div>'
    '<div class="p2-note-dw">Фото типового исполнения · габаритный чертёж — пример оформления, точный чертёж вышлем с КП</div></div>'
    '<div class="p2-info"><span class="eyebrow">Мотор-редуктор ZR · ' + html.escape(typ) + '</span>'
    '<h1 class="p2-h1">Мотор-редуктор ' + html.escape(model) + ' — ' + html.escape(power) + ', ' + html.escape(moment) + ', передаточное ' + html.escape(i) + '</h1>'
    '<div class="p2-price-row"><span class="p2-price">Цена по запросу</span><span class="p2-stock">В наличии / под заказ</span></div>'
    '<div class="p2-offer"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2l7 4v6c0 5-3 8-7 10-4-2-7-5-7-10V6z"/><path d="M9 12l2 2 4-4"/></svg>'
    '<div>Мотор-редуктор <b>' + html.escape(model) + '</b> — ' + html.escape(power) + ', ' + html.escape(moment) + ', передаточное ' + html.escape(i) + '. '
    'Собственное производство: отгрузка от 3 дней, гарантия 24 месяца. Заменяет импортные приводы без переделки узла — те же присоединительные и габаритные размеры.</div></div>'
    '<div class="p2-rows">'
    '<div><span class="k">Производитель</span><span class="v">Завод Редукторов (ООО «НИИ АТТ»)</span></div>'
    '<div><span class="k">Маркировка / Модель</span><span class="v">' + html.escape(model) + '</span></div>'
    '<div><span class="k">Тип передачи</span><span class="v">' + html.escape(typ) + '</span></div>'
    '<div><span class="k">Параметры</span><span class="v">' + html.escape(power) + ' · ' + html.escape(moment) + ' · i=' + html.escape(i) + ' · ' + html.escape(rpm) + '</span></div>'
    '</div>'
    '<div class="p2-cta"><a class="btn lg" data-zayavka href="#zayavka">Получить цену и КП</a><a class="btn ghost lg" href="/podbor">Подобрать по параметрам →</a></div>'
    '<div class="p2-trust"><div><b>Собственное производство</b><span>механообработка, сборка, испытания</span></div>'
    '<div><b>Гарантия 24 месяца</b><span>или переделаем за наш счёт</span></div>'
    '<div><b>Отгрузка от 3 дней</b><span>серийное — со склада</span></div>'
    '<div><b>Инженер за 15 минут</b><span>подбор по шильду, фото, параметрам</span></div></div>'
    '<div class="p2-docs"><span>Техническая документация:</span><a href="/markirovka-zr">Расшифровка маркировки ZR</a>'
    '<a href="/glossary/montazhnoe-polozhenie">Монтажные положения</a><a href="#spec">Характеристики</a></div>'
    '</div></div></div></section>'
    '<script>document.querySelectorAll(".p2-thumbs button").forEach(function(b){b.addEventListener("click",function(){document.getElementById("p2img").src=b.dataset.src;document.querySelectorAll(".p2-thumbs button").forEach(function(x){x.classList.remove("act")});b.classList.add("act");});});</script>')

def mrz_nav():
    return ('<div class="wrap"><nav class="apro-nav"><a href="#opis">Описание</a><a href="#spec">Характеристики</a>'
    '<a href="#dostavka">Доставка и оплата</a><a href="#tehdoc">Техническая документация</a><a href="#chertezh">Чертёж</a>'
    '<a href="#faq">Вопросы</a><span class="sp"></span><a class="red" data-zayavka href="#zayavka">Получить расчёт</a></nav></div>')

def mrz_opis(model, typ, power, i, moment, rpm, desc_inner):
    p2 = ('Мотор-редуктор <b>' + html.escape(model) + '</b> проектируется как замена импортных приводов '
    '(SEW, NORD, Motovario, Bonfiglioli) с совпадающими присоединительными и габаритными размерами — установка '
    'на штатное место без переделки рамы. Подбор исполнения по мощности, моменту и передаточному — в '
    '<a href="/podbor">калькуляторе</a>; замена импортного привода — на странице '
    '<a href="/importozameshchenie">импортозамещения</a>. Поставляется с двигателем 220/380 В или под отдельный двигатель.')
    return ('<section class="section" id="opis" style="padding-top:22px"><div class="wrap">'
    '<h2 class="sec-h" style="margin-bottom:14px">Мотор-редуктор ' + html.escape(model) + ' — описание</h2>'
    '<div class="seo-text" style="border-top:none;padding-top:0">'
    '<p class="opis-lead">' + desc_inner + '</p><p class="opis-lead">' + p2 + '</p></div></div></section>')

def upgrade_motor_reduktor_zr(t):
    if MRZ_MARK in t:
        return t, False
    m = re.search(r'<section class="section" id="opis"[^>]*>.*?</section>\s*'
                  r'<div class="wrap"><nav class="apro-nav">.*?</nav></div>', t, re.S)
    if not m:
        return t, False
    hero_block = m.group(0)
    d = spec_dict(t)
    model = re.sub(r'\s*\(.*?\)', '', d.get('Маркировка', '')).strip() or \
            (re.search(r'Мотор-редуктор\s+(ZR\s?[0-9/\-\.]+)', t) or ['','ZR'])[1]
    typ    = d.get('Тип передачи', 'редуктор')
    power  = d.get('Мощность двигателя', '')
    i      = d.get('Передаточное число', '')
    moment = d.get('Крутящий момент на выходе', d.get('Крутящий момент', ''))
    rpm    = d.get('Обороты на выходе', '')
    zav    = d.get('Заводское обозначение', '')
    img_m  = re.search(r'id="opis".*?<img src="([^"]+)"[^>]*alt="([^"]*)"', hero_block, re.S)
    img    = img_m.group(1) if img_m else '../assets/catalog/cat_worm.webp'
    alt    = img_m.group(2) if img_m else ('Мотор-редуктор ' + model)
    dm     = re.search(r'<p style="color:var\(--muted\);max-width:62ch">(.*?)</p>', hero_block, re.S)
    desc   = dm.group(1).strip() if dm else ('Мотор-редуктор <b>' + html.escape(model) + '</b> производства Завода Редукторов (ООО «НИИ АТТ»).')
    new_top = mrz_hero(model, typ, power, i, moment, rpm, img, alt, zav) + mrz_nav() + mrz_opis(model, typ, power, i, moment, rpm, desc)
    t = t[:m.start()] + new_top + t[m.end():]
    # inject pro CSS
    t = t.replace('</head>', '<style>' + MRZ_MARK + procss() + '</style></head>', 1)
    # faq-grid -> faq2 + секции #faq
    t = re.sub(r'(<section class="section" style="padding-top:0">)(<div class="wrap"><div class="eyebrow">Вопросы)',
               r'<section class="section" id="faq" style="padding-top:0">\2', t, count=1)
    t = t.replace('class="faq-grid"', 'class="faq2"', 1)
    # seo -> #seo + seo-tbl
    t, _ = red_seo_tbl(t, model)
    return t, True

# ---------- thin families (ispolnenie / tiporazmer): full pro build ----------
_DOSTAVKA = ('<section class="section" id="dostavka" style="padding-top:10px"><div class="wrap">'
 '<h2 class="sec-h" style="font-size:22px;margin-bottom:14px">Доставка и оплата</h2><div class="why-grid">'
 '<div><b>Отгрузка от 3 дней</b><span>серийные типоразмеры — со склада, остальное под заказ</span></div>'
 '<div><b>Доставка по России</b><span>транспортными компаниями до терминала или адреса; самовывоз со склада</span></div>'
 '<div><b>Оплата по счёту</b><span>безналичный расчёт по договору, УПД; для серийных — индивидуальные условия</span></div>'
 '<div><b>Гарантия 24 месяца</b><span>паспорт изделия и декларация соответствия в комплекте</span></div></div></div></section>')
_TEHDOC = ('<section class="section" id="tehdoc" style="padding-top:10px"><div class="wrap">'
 '<h2 class="sec-h" style="font-size:22px;margin-bottom:14px">Техническая документация</h2><div class="p2-docs">'
 '<span>Документы и справка:</span><a href="/markirovka-zr">Расшифровка маркировки ZR</a>'
 '<a href="/glossary/montazhnoe-polozhenie">Монтажные положения</a><a href="#spec">Характеристики</a>'
 '<a data-zayavka href="#zayavka">Паспорт и КП по запросу</a></div></div></section>')
_CHERTEZH = ('<section class="section" id="chertezh" style="padding-top:10px"><div class="wrap">'
 '<h2 class="sec-h" style="font-size:22px;margin-bottom:14px">Чертёж</h2>'
 '<p style="color:var(--muted);max-width:70ch;margin-bottom:14px">Габаритный и присоединительный чертёж '
 'исполнения — по запросу вместе с коммерческим предложением. Ниже — пример габаритного чертежа.</p>'
 '<img src="../assets/drawings/zr-demo-drawing.png" alt="Габаритный чертёж — пример" loading="lazy" '
 'style="max-width:640px;width:100%;border:1px solid var(--line);border-radius:12px;background:#fff;padding:10px;display:block">'
 '<p style="margin-top:14px"><a class="btn" data-zayavka href="#zayavka">Запросить чертёж исполнения</a></p></div></section>')

def thin_p2_hero(eyebrow, h1_html, model, typ, power, i, moment, rpm, img, alt):
    params = " · ".join(x for x in [power, moment, ("i=" + i if i else ""), rpm] if x)
    return (
    '<section class="section" style="padding-top:20px"><div class="wrap"><div class="p2">'
    '<div class="p2-gal"><div class="p2-main"><img id="p2img" src="' + img + '" alt="' + html.escape(alt) + '"></div>'
    '<div class="p2-thumbs"><button type="button" class="act" data-src="' + img + '" aria-label="Фото">'
    '<img src="' + img + '" alt="Фото редуктора" width="78" height="64"></button>'
    '<button type="button" data-src="../assets/drawings/zr-demo-drawing.png" aria-label="Чертёж">'
    '<img src="../assets/drawings/zr-demo-drawing.png" alt="Габаритный чертёж" width="78" height="64" loading="lazy" decoding="async"></button></div>'
    '<div class="p2-note-dw">Фото типового исполнения · габаритный чертёж — пример, точный чертёж вышлем с КП</div></div>'
    '<div class="p2-info"><span class="eyebrow">' + html.escape(eyebrow) + '</span>'
    '<h1 class="p2-h1">' + h1_html + '</h1>'
    '<div class="p2-price-row"><span class="p2-price">Цена по запросу</span><span class="p2-stock">В наличии / под заказ</span></div>'
    '<div class="p2-offer"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2l7 4v6c0 5-3 8-7 10-4-2-7-5-7-10V6z"/><path d="M9 12l2 2 4-4"/></svg>'
    '<div>Редуктор <b>' + html.escape(model) + '</b>' + ((' — ' + params) if params else '') + '. Собственное производство: '
    'отгрузка от 3 дней, гарантия 24 месяца. Заменяет импортные приводы без переделки узла — те же присоединительные и габаритные размеры.</div></div>'
    '<div class="p2-rows">'
    '<div><span class="k">Производитель</span><span class="v">Завод Редукторов (ООО «НИИ АТТ»)</span></div>'
    '<div><span class="k">Модель</span><span class="v">' + html.escape(model) + '</span></div>'
    '<div><span class="k">Тип передачи</span><span class="v">' + html.escape(typ) + '</span></div>'
    + (('<div><span class="k">Параметры</span><span class="v">' + html.escape(params) + '</span></div>') if params else '') +
    '</div>'
    '<div class="p2-cta"><a class="btn lg" data-zayavka href="#zayavka">Получить цену и КП</a><a class="btn ghost lg" href="/podbor">Подобрать по параметрам →</a></div>'
    '<div class="p2-trust"><div><b>Собственное производство</b><span>механообработка, сборка, испытания</span></div>'
    '<div><b>Гарантия 24 месяца</b><span>или переделаем за наш счёт</span></div>'
    '<div><b>Отгрузка от 3 дней</b><span>серийное — со склада</span></div>'
    '<div><b>Инженер за 15 минут</b><span>подбор по шильду, фото, параметрам</span></div></div>'
    '<div class="p2-docs"><span>Техническая документация:</span><a href="/markirovka-zr">Расшифровка маркировки ZR</a>'
    '<a href="/glossary/montazhnoe-polozhenie">Монтажные положения</a><a href="#spec">Характеристики</a></div>'
    '</div></div></div></section>'
    '<script>document.querySelectorAll(".p2-thumbs button").forEach(function(b){b.addEventListener("click",function(){document.getElementById("p2img").src=b.dataset.src;document.querySelectorAll(".p2-thumbs button").forEach(function(x){x.classList.remove("act")});b.classList.add("act");});});</script>')

def thin_nav():
    return ('<div class="wrap"><nav class="apro-nav"><a href="#opis">Описание</a><a href="#spec">Характеристики</a>'
    '<a href="#dostavka">Доставка и оплата</a><a href="#tehdoc">Техническая документация</a><a href="#chertezh">Чертёж</a>'
    '<a href="#faq">Вопросы</a><span class="sp"></span><a class="red" data-zayavka href="#zayavka">Получить расчёт</a></nav></div>')

def thin_opis(model, desc_inner):
    p2 = ('Это конкретное исполнение <b>' + html.escape(model) + '</b> из линейки серии ZR: подбирается под нагрузку по '
    'мощности, крутящему моменту и передаточному числу. Заменяет импортные приводы (SEW, NORD, Motovario, Bonfiglioli) '
    'с совпадающими присоединительными и габаритными размерами — установка без переделки рамы. Другие исполнения и '
    'полный ряд — на родительской карточке модели; подбор — в <a href="/podbor">калькуляторе</a>, замена импорта — '
    '<a href="/importozameshchenie">импортозамещение</a>.')
    return ('<section class="section" id="opis" style="padding-top:22px"><div class="wrap">'
    '<h2 class="sec-h" style="margin-bottom:14px">' + html.escape(model) + ' — описание исполнения</h2>'
    '<div class="seo-text" style="border-top:none;padding-top:0">'
    '<p class="opis-lead">' + desc_inner + '</p><p class="opis-lead">' + p2 + '</p></div></div></section>')

def upgrade_thin(t):
    if MRZ_MARK in t:
        return t, False
    m = re.search(r'<section class="section" style="padding-top:22px"><div class="wrap">\s*'
                  r'<div style="display:grid;grid-template-columns:minmax\(0,3\d0px\) 1fr.*?</section>', t, re.S)
    if not m:
        return t, False
    hero = m.group(0)
    d = spec_dict(t)
    h1m = re.search(r'<h1[^>]*>(.*?)</h1>', hero, re.S)
    h1  = h1m.group(1).strip() if h1m else ''
    model = (re.search(r'(ZR\s?[0-9][0-9/\-\.]*)', h1) or ['','ZR'])[1].strip()
    eyeb = (re.search(r'class="eyebrow">(.*?)<', hero) or ['','Исполнение ZR'])[1].strip()
    typ  = d.get('Тип передачи', 'редуктор')
    power= d.get('Мощность двигателя', '')
    i    = d.get('Передаточное число', '')
    moment = d.get('Крутящий момент на выходе', d.get('Крутящий момент', ''))
    rpm  = d.get('Обороты на выходе', '')
    im = re.search(r'<img src="([^"]+)"[^>]*alt="([^"]*)"', hero)
    img = im.group(1) if im else '../assets/catalog/cat_worm.webp'
    alt = im.group(2) if im else model
    dm = re.search(r'<p style="color:var\(--muted\);max-width:62ch">(.*?)</p>', hero, re.S)
    desc = dm.group(1).strip() if dm else ('Исполнение редуктора <b>' + html.escape(model) + '</b> производства Завода Редукторов.')
    new_top = thin_p2_hero(eyeb, h1, model, typ, power, i, moment, rpm, img, alt) + thin_nav() + thin_opis(model, desc)
    t = t[:m.start()] + new_top + t[m.end():]
    # spec section → id="spec"; после неё вставить dostavka/tehdoc/chertezh
    t = re.sub(r'<section class="section" style="padding-top:\d+px">(<div class="wrap"><h2[^>]*>Характеристики)',
               r'<section class="section" id="spec" style="padding-top:28px">\1', t, count=1)
    sm = re.search(r'<section class="section" id="spec".*?</section>', t, re.S)
    if sm:
        t = t[:sm.end()] + _DOSTAVKA + _TEHDOC + _CHERTEZH + t[sm.end():]
    # FAQ → id=faq + faq2
    t = re.sub(r'(<section class="section" style="padding-top:0">)(<div class="wrap"><div class="eyebrow">Вопросы)',
               r'<section class="section" id="faq" style="padding-top:0">\2', t, count=1)
    t = t.replace('class="faq-grid"', 'class="faq2"', 1)
    # seo → id=seo + seo-tbl
    t, _ = red_seo_tbl(t, model)
    # inject pro CSS
    t = t.replace('</head>', '<style>' + MRZ_MARK + procss() + '</style></head>', 1)
    return t, True

FAMILY = {"reduktor": upgrade_reduktor, "motor-reduktor-zr": upgrade_motor_reduktor_zr,
          "ispolnenie": upgrade_thin, "tiporazmer": upgrade_thin}

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
