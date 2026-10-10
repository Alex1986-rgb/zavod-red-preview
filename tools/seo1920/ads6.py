#!/usr/bin/env python3
"""ads6: формулировки реальных запросов — в ТЕКСТ страниц, через блок «Частые вопросы».

Источник вопросов — выгрузка рекламы конкурента (поисковые запросы 01.2024–07.2026, с заявками):
«где купить мотор редуктор sew eurodrive», «мотор редуктор 380 вольт купить», «понижающий редуктор
цена от производителя», «расчет редуктора онлайн калькулятор», «виды редукторов», русские написания брендов…
Вопрос = формулировка запроса, ответ = факты, которые УЖЕ есть на этой странице (гарантия/сроки
подставляются, только если они написаны на странице). Добавляется в существующий блок FAQ
(<div class="cat-faq">) и в микроразметку FAQPage; если блока нет — создаётся перед подвалом.
Метка ads6 — повтор безопасен. Без переспама: 3–4 вопроса на страницу.

    python3 ads6.py <public_html> [--apply] [--no-ping]
"""
import sys, re, os, html, json, time, shutil, pathlib, urllib.request

ROOT = pathlib.Path(os.path.expanduser(sys.argv[1])).resolve()
APPLY = '--apply' in sys.argv
HOST, KEY = 'zavod-red.ru', '051a8bb09331b03c5e35d4a40339b5b3'
MARK = '<!-- ads6 -->'

# бренд-стем → (имя, русские написания, ссылки на серии [(url, текст)])
BRANDS = {
    'sew': ('SEW-Eurodrive', 'СЕВ-Евродрайв, СЕВ, Сью', [('/brands/sew-r', 'R'), ('/brands/sew-k', 'K'), ('/brands/sew-f', 'F'), ('/brands/sew-s', 'S'), ('/brands/sew-w', 'W')]),
    'bonfiglioli': ('Bonfiglioli', 'Бонфильоли, Бонфиглиоли', [('/brands/bonfiglioli-vf', 'VF'), ('/brands/bonfiglioli-w', 'W'), ('/brands/bonfiglioli-c', 'C'), ('/brands/bonfiglioli-a', 'A'), ('/brands/bonfiglioli-f', 'F')]),
    'nord': ('NORD', 'Норд', [('/brands/nord-sk-soosnye', 'SK соосные'), ('/brands/nord-sk-ploskie', 'SK плоские'), ('/brands/nord-sk-konicheskie', 'SK конические'), ('/brands/nord-sk-chervyachnye', 'SK червячные')]),
    'motovario': ('Motovario', 'Мотоварио', [('/brands/motovario-nmrv', 'NMRV'), ('/brands/motovario-ha', 'HA'), ('/brands/motovario-cb', 'CB'), ('/brands/motovario-cs', 'CS')]),
    'siti': ('SITI', 'Сити', [('/brands/siti-mu', 'MU'), ('/brands/siti-mi', 'MI'), ('/brands/siti-bh', 'BH'), ('/brands/siti-mnhl', 'MNHL')]),
    'varvel': ('Varvel', 'Варвел', [('/brands/varvel-srt', 'SRT'), ('/brands/varvel-rv', 'RV'), ('/brands/varvel-mrn', 'MRN'), ('/brands/varvel-srs', 'SRS')]),
    'bauer': ('Bauer', 'Бауэр, Бауер', [('/brands/bauer-bs', 'BS'), ('/brands/bauer-bf', 'BF'), ('/brands/bauer-bg', 'BG'), ('/brands/bauer-bk', 'BK')]),
    'transtecno': ('Transtecno', 'Транстекно, Транстехно', [('/brands/transtecno-cm', 'CM'), ('/brands/transtecno-cmg', 'CMG'), ('/brands/transtecno-cmis', 'CMIS')]),
    'lenze': ('Lenze', 'Ленце, Лензе', [('/brands/lenze-gks', 'GKS'), ('/brands/lenze-gkr', 'GKR'), ('/brands/lenze-gss', 'GSS'), ('/brands/lenze-gfl', 'GFL')]),
    'tramec': ('Tramec', 'Трамек, Трамэк', [('/brands/tramec-xc', 'XC'), ('/brands/tramec-k', 'K')]),
    'innovari': ('Innovari', 'Инновари', [('/brands/innovari-b', 'B'), ('/brands/innovari-x', 'X'), ('/brands/innovari-fa', 'FA')]),
    'innored': ('Innored', 'Иннорэд, Инноред, Иноред', [('/brands/innored-irwd', 'IRWD')]),
    'yilmaz': ('Yilmaz', 'Йилмаз, Илмаз', [('/brands/yilmaz-k', 'K'), ('/brands/yilmaz-n', 'N'), ('/brands/yilmaz-m', 'M')]),
    'unidrive': ('UNI Drive', 'Юнидрайв, Unit Drive, Юнит Драйв', []),
    'keb': ('KEB', 'КЕБ', []),
}

def facts(body):
    f = []
    if '15 минут' in body: f.append('подбор по шильду или по параметрам — за 15 минут')
    if re.search(r'отгрузк\w* от 3', body): f.append('отгрузка от 3 дней')
    if '36 месяц' in body: f.append('гарантия 36 месяцев')
    return f

def brand_qa(stem, body):
    name, ru, series = BRANDS[stem]
    fx = facts(body)
    links = ', '.join(f'<a href="{u}">{name} {t}</a>' for u, t in series)
    qa = [
        (f'Где купить мотор-редуктор {name}?',
         f'У нас: оригинальный {name} под заказ или аналог ZR собственного производства. Пришлите модель или фото шильда'
         + (' — ' + ', '.join(fx) + '.' if fx else '.') + ' Цену называем после подбора.'),
        (f'Сколько стоит редуктор {name}?',
         f'Цена зависит от серии, типоразмера, передаточного числа и мощности двигателя, поэтому называем её после подбора. '
         f'Аналог ZR, как правило, обходится дешевле оригинала.'),
        (f'Как ещё пишут {name} по-русски?',
         f'В запросах встречается: {ru}. Это один и тот же производитель — {name}.'),
    ]
    if links:
        qa.append((f'Какие серии {name} вы подбираете?', f'Серии с отдельными страницами: {links}. Другие серии — по запросу.'))
    return qa

def catalog_qa(rel, body):
    fx = facts(body)
    tail = (' ' + ', '.join(fx).capitalize() + '.') if fx else ''
    P = '<a href="/podbor">подбор по параметрам</a>'
    QA = {
        'index': [
            ('Где купить понижающий редуктор от производителя?', f'Напрямую у завода: понижающие редукторы и мотор-редукторы ZR нашего производства в Челябинске, без посредников.{tail}'),
            ('Изготавливаете ли редукторы на заказ?', 'Да: подбираем и изготавливаем редукторы и мотор-редукторы под задачу, в том числе аналоги импортных (SEW, NORD, Bonfiglioli и др.) с теми же присоединительными размерами.'),
            ('Как рассчитать и подобрать редуктор онлайн?', f'Через {P}: мощность, обороты, момент и передаточное число — калькулятор покажет подходящие модели.'),
        ],
        'catalog/index': [
            ('Какие виды редукторов бывают?', 'По типу передачи: червячные, цилиндрические (соосные и плоские), конические и коническо-цилиндрические, планетарные, а также мотор-вариаторы. Все они есть в каталоге.'),
            ('Что такое понижающий редуктор?', 'Механизм, который снижает обороты электродвигателя и во столько же раз увеличивает крутящий момент на выходном валу. Большинство промышленных редукторов — понижающие.'),
            ('Как заказать редуктор от производителя?', f'Выберите тип в каталоге или воспользуйтесь {P}; можно прислать фото шильда старого редуктора.{tail}'),
        ],
        'catalog/motor-reduktory': [
            ('Где купить мотор-редуктор?', f'У производителя: мотор-редукторы ZR — червячные, цилиндрические, конические, планетарные, на 220 и 380 В.{tail}'),
            ('Сколько стоит мотор-редуктор?', 'Цена зависит от типа, мощности, передаточного числа и исполнения; называем её после подбора. Цены от завода, без посредников.'),
            ('Как подобрать мотор-редуктор по параметрам онлайн?', f'Через {P}: укажите мощность, выходные обороты или момент — калькулятор предложит подходящие модели.'),
        ],
        'catalog/motor-reduktory-380v': [
            ('Где купить мотор-редуктор 380 вольт?', f'У производителя: трёхфазные мотор-редукторы ZR на 380 В — червячные, соосные, цилиндрические.{tail}'),
            ('Чем мотор-редуктор 380 В отличается от 220 В?', 'На 380 В — трёхфазный двигатель для промышленной сети, больший диапазон мощностей. На 220 В — однофазный, для бытовой сети и небольших мощностей: <a href="/catalog/motor-reduktory-220v">мотор-редукторы 220 В</a>.'),
        ],
        'catalog/motor-reduktory-220v': [
            ('Где купить мотор-редуктор 220 вольт?', f'У производителя: однофазные мотор-редукторы ZR на 220 В — червячные, соосные, плоские.{tail}'),
            ('Чем мотор-редуктор 220 В отличается от 380 В?', 'На 220 В — однофазный двигатель для бытовой сети и небольших мощностей. Для промышленной трёхфазной сети — <a href="/catalog/motor-reduktory-380v">мотор-редукторы 380 В</a>.'),
        ],
        'catalog/cilindricheskie': [
            ('Цилиндрический мотор-редуктор — где заказать по цене производителя?', f'Напрямую у завода: цилиндрические редукторы и мотор-редукторы ZR, одно- и двухступенчатые.{tail}'),
            ('Чем цилиндрический редуктор лучше червячного?', 'У цилиндрической передачи выше КПД и ниже нагрев, она дольше работает в режиме S1. Червячная компактнее и даёт большое передаточное число в одной ступени: <a href="/catalog/chervyachnye">червячные редукторы</a>.'),
        ],
        'catalog/planetarnye': [
            ('Планетарный мотор-редуктор — где заказать по цене производителя?', f'У нас: подбор и поставка планетарных редукторов и мотор-редукторов, изготовление под заказ.{tail}'),
            ('Как рассчитать планетарный редуктор?', f'Нужны момент на выходе, обороты и режим работы. Посчитать можно через {P} или прислать параметры инженеру.'),
        ],
        'catalog/chervyachnye': [
            ('Где купить червячный мотор-редуктор?', f'У производителя: червячные редукторы и мотор-редукторы ZR, в том числе аналоги NMRV, VF, MU.{tail}'),
            ('Как подобрать червячный мотор-редуктор онлайн?', f'Через {P}: мощность, обороты и передаточное число — калькулятор подберёт типоразмер.'),
        ],
        'catalog/pr': [
            ('Есть ли у вас редуктор ПР 315?', 'Да, ПР 315 — в линейке серии ПР вместе с ПР 313, ПР 314, ПР 316, ПР 317 и другими типоразмерами на этой странице.'),
        ],
        'catalog/mr': [
            ('Есть ли мотор-редукторы МР 4110 и МР 3110?', 'Да, МР 4110, МР 3110, МР 440, МР 313 и другие типоразмеры серии МР — в таблице на этой странице.'),
        ],
        'catalog/evl': [
            ('Есть ли мотор-редукторы EVL 187, EVL 188, EVL 189?', 'Да, эти и другие типоразмеры EVL — на этой странице с расшифровкой обозначения; подбираем по маркировке и шильдику.'),
        ],
        'podbor': [
            ('Как рассчитать редуктор онлайн?', 'Укажите мощность двигателя, обороты на выходе или момент — калькулятор рассчитает передаточное число и покажет подходящие модели.'),
            ('Как рассчитать передаточное число редуктора?', 'Передаточное число = обороты двигателя ÷ нужные обороты на выходе. Например, 1500 ÷ 50 = 30.'),
        ],
    }
    return QA.get(rel, [])

def page_file(rel):
    for c in (ROOT / f'{rel}.html', ROOT / rel / 'index.html'):
        if c.is_file():
            return c

def strip(t):
    return re.sub(r'<[^>]+>', '', t)

def apply_qa(s, qa):
    s0 = s
    # убрать вопросы, которые на странице уже есть
    have = {html.unescape(strip(x)).strip().lower() for x in re.findall(r'<summary>(.*?)</summary>', s, re.S)}
    qa = [(q, a) for q, a in qa if q.lower() not in have]
    if not qa:
        return s0, 0
    block = ''.join(f'<details><summary>{html.escape(q, quote=False)}</summary><p>{a}</p></details>' for q, a in qa)
    k = s.find('<div class="cat-faq">')
    if k >= 0:
        m = re.compile(r'</details>(\s*)</div>').search(s, k)
        if not m:
            return s0, 0
        s = s[:m.start() + len('</details>')] + MARK + block + s[m.start() + len('</details>'):]
    else:
        sec = ('<section class="section" style="padding-top:0"><div class="wrap">' + MARK +
               '<div class="eyebrow">Вопросы и ответы</div><h2 style="font-size:24px">Частые вопросы</h2>'
               '<div class="faq-grid"><div class="cat-faq">' + block + '</div></div></div></section>')
        f = s.find('<footer')
        if f < 0:
            return s0, 0
        s = s[:f] + sec + s[f:]
    # микроразметка FAQPage
    items = [{'@type': 'Question', 'name': q, 'acceptedAnswer': {'@type': 'Answer', 'text': strip(a)}} for q, a in qa]
    m = re.search(r'(<script type="application/ld\+json">)(\{[^<]*?"FAQPage"[^<]*?\})(</script>)', s)
    if m:
        try:
            d = json.loads(m.group(2))
            d.setdefault('mainEntity', []).extend(items)
            s = s[:m.start(2)] + json.dumps(d, ensure_ascii=False) + s[m.end(2):]
        except Exception:
            pass
    else:
        d = {'@context': 'https://schema.org', '@type': 'FAQPage', 'mainEntity': items}
        s = s.replace('</head>', '<script type="application/ld+json">' + json.dumps(d, ensure_ascii=False) + '</script></head>', 1)
    return s, len(qa)

targets = [('brands/' + b, 'brand', b) for b in BRANDS] + [(r, 'cat', r) for r in
          ['index', 'catalog/index', 'catalog/motor-reduktory', 'catalog/motor-reduktory-380v', 'catalog/motor-reduktory-220v',
           'catalog/cilindricheskie', 'catalog/planetarnye', 'catalog/chervyachnye', 'catalog/pr', 'catalog/mr', 'catalog/evl', 'podbor']]
changes = {}
for rel, kind, key in targets:
    f = page_file(rel)
    if not f:
        print('нет файла:', rel); continue
    s = f.read_text(encoding='utf-8')
    if MARK in s:
        continue
    body = re.sub(r'<[^>]+>', ' ', s)
    qa = brand_qa(key, body) if kind == 'brand' else catalog_qa(key, body)
    ns, n = apply_qa(s, qa)
    if n:
        url = f'https://{HOST}/' + ('' if rel == 'index' else rel)
        changes[f] = (ns, url, [q for q, _ in qa][:n])

print(f'Корень: {ROOT}\nИзменится страниц: {len(changes)}')
for f, (_, url, qs) in sorted(changes.items(), key=lambda x: x[1][1]):
    print(f'\n{url}\n  + ' + '\n  + '.join(qs))
if not APPLY:
    print('\nЭто проверка. Чтобы записать — добавьте --apply.'); sys.exit(0)
if not changes:
    print('\nНечего менять.'); sys.exit(0)
ts = time.strftime('%Y%m%d-%H%M%S')
bak = pathlib.Path(os.path.expanduser(f'~/_archive_old/ads6-{ts}'))
for f in changes:
    dst = bak / f.relative_to(ROOT); dst.parent.mkdir(parents=True, exist_ok=True); shutil.copy2(f, dst)
for f, (ns, _, _) in changes.items():
    f.write_text(ns, encoding='utf-8')
urls = sorted(u for _, u, _ in changes.values())
print(f'\nЗаписано страниц: {len(changes)}. Резервная копия: {bak}\nОткат: cp -rp {bak}/. {ROOT}/')
if '--no-ping' in sys.argv:
    print('IndexNow пропущен (--no-ping)'); sys.exit(0)
payload = json.dumps({'host': HOST, 'key': KEY, 'keyLocation': f'https://{HOST}/{KEY}.txt', 'urlList': urls}).encode()
for ep in ('https://yandex.com/indexnow', 'https://api.indexnow.org/indexnow'):
    try:
        r = urllib.request.urlopen(urllib.request.Request(ep, data=payload,
            headers={'Content-Type': 'application/json; charset=utf-8'}), timeout=30)
        print(f'IndexNow {ep}: HTTP {r.status} — отправлено URL: {len(urls)}')
    except urllib.error.HTTPError as e:
        print(f'IndexNow {ep}: HTTP {e.code} {e.read()[:200]!r}')
    except Exception as e:
        print(f'IndexNow {ep}: ошибка {e}')
print('ГОТОВО')
