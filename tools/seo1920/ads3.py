#!/usr/bin/env python3
"""Описания страниц брендов под ключи из рекламной выгрузки конкурента (10.10.2026).

Источник ключей: yandex-direct-zavod/brand_campaigns.py — 1088 фраз из выгрузки рекламы конкурента
(отчёт 01.2024–07.2026). Из них на сайт идут только:
  • русские написания брендов, которые реально ищут («сев», «лензе», «трамэк», «юнит драйв»…);
  • ходовые модели — и ТОЛЬКО те, что есть в тексте самой страницы.
НЕ используются: название компании-конкурента, «официальный сайт», чужая техника
(буровые Bauer BG, частотники Lenze 8200, масла NORD и т. п.).

Меняет meta description и og:description страниц brands/<бренд>*.html. Title не трогает.
    python3 ads3.py <public_html>            # показать «было → стало»
    python3 ads3.py <public_html> --apply    # записать + копия + sitemap lastmod + IndexNow
"""
import sys, re, os, html, json, time, shutil, pathlib, datetime, urllib.request

ROOT = pathlib.Path(os.path.expanduser(sys.argv[1])).resolve()
APPLY = '--apply' in sys.argv
HOST, KEY = 'zavod-red.ru', '051a8bb09331b03c5e35d4a40339b5b3'
MAXLEN = 260

# бренд-стем → (имя, русские написания, ходовые модели из рекламы конкурента)
BRANDS = {
    'sew':        ('SEW-Eurodrive', 'СЕВ-Евродрайв, СЕВ, Сью',
                   ['R 37', 'R 77', 'R 97', 'RF 87', 'KA 37', 'SA 47', 'FA 107', 'WA 20']),
    'bonfiglioli': ('Bonfiglioli', 'Бонфильоли, Бонфиглиоли', ['VF 44', 'VF 49', 'W 63', 'W 86', 'C 22', 'F 20']),
    'nord':       ('NORD', 'Норд', ['SK 1282', 'SK 3282', 'SK 4282', 'SK 8282', 'SK 9022', 'SK 9032',
                                    'SK 9052', 'SK 9072', 'SK 12080']),
    'motovario':  ('Motovario', 'Мотоварио', ['NMRV 030', 'NMRV 040', 'NMRV 050', 'NMRV 063', 'NMRV 075',
                                              'NRV 030', 'CH']),
    'siti':       ('SITI', 'Сити', ['MU 40', 'MU 50', 'MU 75', 'MU 90', 'MU 110', 'MI 90', 'MNHL 25']),
    'varvel':     ('Varvel', 'Варвел', ['SRT 40', 'SRT 60', 'SRT 70', 'SRT 85', 'FRS 40', 'FRS 50', 'FRS 60']),
    'transtecno': ('Transtecno', 'Транстекно, Транстехно', ['CM 030', 'CM 040', 'CM 050', 'CM 075', 'CM 130',
                                                            'CMG 022', 'PG 090']),
    'lenze':      ('Lenze', 'Ленце, Лензе', ['GKS 04', 'GKS 07', 'GKS 11', 'GKR 04', 'GKR 05', 'GSS 05',
                                            'GSS 06', 'GFL 07', 'GFL 09']),
    'tramec':     ('Tramec', 'Трамек, Трамэк', ['XC 30', 'XC 40', 'XC 50', 'XC 63', 'XC 75', 'XC 90', 'XC 110',
                                             'KC 90']),
    'innored':    ('Innored', 'Иннорэд, Инноред, Иноред', ['IRWD 030', 'IRWD 050', 'IRWD 063', 'IRW 110']),
    'innovari':   ('Innovari', 'Инновари', []),
    'yilmaz':     ('Yilmaz', 'Йилмаз, Илмаз', ['MR', 'KV', 'KN', 'MN']),
    'unidrive':   ('UNI Drive', 'Юнидрайв, Unit Drive, Юнит Драйв', []),
    'keb':        ('KEB', 'КЕБ', ['G63', 'ZG13']),
    'bauer':      ('Bauer', 'Бауэр, Бауер', ['BS 02', 'BF 50', 'BG', 'BK']),
}
TAIL = 'Оригинал или аналог ZR, подбор по шильду за 15 минут, гарантия 36 мес.'
DESC = r'<meta name="description" content="([^"]*)"'
OGD = r'<meta property="og:description" content="([^"]*)"'

def text_of(s):
    s = re.sub(r'<(script|style)\b.*?</\1>', ' ', s, flags=re.S | re.I)
    return html.unescape(re.sub(r'<[^>]+>', ' ', s))

def present(body, model):
    """Модель есть в тексте: «R 77»/«R77», «NMRV 030»/«NMRV030»/«NMRV 30»; серия без числа — как отдельное слово."""
    parts = model.split(' ')
    if len(parts) == 1:
        return re.search(r'(?<![A-Za-z0-9])' + re.escape(model) + r'(?![A-Za-z])', body) is not None
    code, num = parts
    return re.search(r'(?<![A-Za-z0-9])' + re.escape(code) + r'[ -]?0*' + str(int(num)) + r'(?!\d)', body) is not None

def brand_of(stem):
    for b in ('watt-drive', 'tos-znojmo'):
        if stem.startswith(b):
            return b
    return stem.split('-', 1)[0]

changes = {}
for f in sorted((ROOT / 'brands').glob('*.html')):
    b = brand_of(f.stem)
    if b not in BRANDS:
        continue
    name, ru, models = BRANDS[b]
    s = f.read_text(encoding='utf-8')
    m = re.search(DESC, s)
    if not m:
        continue
    old = html.unescape(m.group(1))
    if 'Ищут также:' in old or 'аналог ZR, подбор по шильду за 15 минут, гарантия 36 мес.' in old:
        continue                                   # уже применено
    body = text_of(s)
    found = [x for x in models if present(body, x)]
    if f.stem != b:                                   # страница серии: только модели своей серии
        suf = f.stem[len(b) + 1:].replace('sk-', '').upper()
        own = [x for x in found if x.split(' ')[0].startswith(suf) or suf.startswith(x.split(' ')[0])]
        if b == 'nord':
            own = [x for x in found if suf.startswith('PLOSK') or suf.startswith('SOOSN')]
        found = own
    # первая фраза старого описания — уже под запросы (seo1920), оставляем её
    first = re.split(r'(?<=[.:])\s', old, 1)[0].rstrip(':.')
    ru_new = ', '.join(v for v in ru.split(', ') if v.lower() not in first.lower())
    also = f' Ищут также: {ru_new}.' if ru_new else ''
    def build(ms):
        return f'{first}.{also}' + (' Модели: ' + ', '.join(ms) + '.' if ms else '') + ' ' + TAIL
    new = build(found)
    while len(new) > MAXLEN and found:
        found.pop(); new = build(found)
    ns = s[:m.start(1)] + html.escape(new, quote=True) + s[m.end(1):]
    m2 = re.search(OGD, ns)
    if m2:
        ns = ns[:m2.start(1)] + html.escape(new, quote=True) + ns[m2.end(1):]
    changes[f] = (old, new, ns)

print(f'Корень: {ROOT}\nИзменится страниц: {len(changes)}')
for f, (o, n, _) in sorted(changes.items()):
    print(f'\nhttps://{HOST}/brands/{f.stem}\n  было:  {o}\n  стало: {n}')
if not APPLY:
    print('\nЭто проверка. Чтобы записать — добавьте --apply.'); sys.exit(0)
if not changes:
    print('\nНечего менять.'); sys.exit(0)

ts = time.strftime('%Y%m%d-%H%M%S')
bak = pathlib.Path(os.path.expanduser(f'~/_archive_old/ads3-{ts}'))
urls = sorted(f'https://{HOST}/brands/{f.stem}' for f in changes)
today = datetime.date.today().isoformat()
smaps = {}
for sm in sorted(ROOT.glob('sitemap*.xml')):
    t = sm.read_text(encoding='utf-8'); nt = t
    for u in urls:
        nt = re.sub(r'(<loc>' + re.escape(u) + r'</loc>\s*<lastmod>)[^<]*(</lastmod>)', r'\g<1>' + today + r'\g<2>', nt)
    if nt != t:
        smaps[sm] = nt
for f in list(changes) + list(smaps):
    dst = bak / f.relative_to(ROOT); dst.parent.mkdir(parents=True, exist_ok=True); shutil.copy2(f, dst)
for f, (_, _, ns) in changes.items():
    f.write_text(ns, encoding='utf-8')
for sm, t in smaps.items():
    sm.write_text(t, encoding='utf-8')
print(f'\nЗаписано страниц: {len(changes)}, sitemap-файлов: {len(smaps)}. Резервная копия: {bak}')
print(f'Откат: cp -rp {bak}/. {ROOT}/')
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
