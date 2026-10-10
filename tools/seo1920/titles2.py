#!/usr/bin/env python3
"""Цифры в заголовках страниц брендов (просьба владельца 10.10.2026: «как ранее — по максимуму и по цифрам»).

После seo1920 у 50+ страниц brands/ в <title> не осталось ни одной модели («… типоразмеры и аналоги ZR»).
Этот скрипт берёт реальные типоразмеры серии ИЗ ТЕКСТА САМОЙ СТРАНИЦЫ и ставит их в title и og:title:
    «Lenze GFL — GFL 04, GFL 05, GFL 06, GFL 07: купить, аналог ZR 636, ZR 646»
Для страницы бренда — по одному-два типоразмера каждой его серии (с соседних страниц серий).
Трогает только title без цифр; страницы, где модели уже есть, не меняет. Повтор безопасен.

    python3 titles2.py <public_html>            # показать «было → стало»
    python3 titles2.py <public_html> --apply    # записать + копия в ~/_archive_old/ + sitemap lastmod + IndexNow
"""
import sys, re, os, html, json, time, shutil, pathlib, datetime, urllib.request, collections

ROOT = pathlib.Path(os.path.expanduser(sys.argv[1])).resolve()
APPLY = '--apply' in sys.argv
HOST, KEY = 'zavod-red.ru', '051a8bb09331b03c5e35d4a40339b5b3'
MAXLEN = 100
TITLE = r'<title>(.*?)</title>'
OGT = r'<meta property="og:title" content="([^"]*)"'

def text_of(s):
    s = re.sub(r'<(script|style)\b.*?</\1>', ' ', s, flags=re.S | re.I)
    return html.unescape(re.sub(r'<[^>]+>', ' ', s))

def sizes(body, code):
    """Типоразмеры серии code в тексте: 'GFL 04', 'MRV 40', 'K 102' — по частоте, затем по числу."""
    c = collections.Counter()
    for m in re.finditer(r'(?<![A-Za-z0-9])' + re.escape(code) + r'[ -]?(\d{2,4})(?![\d,.])', body):
        c[m.group(1)] += 1
    top = [n for n, _ in c.most_common(6)]
    return [f'{code} {n}' for n in sorted(top, key=int)]

def zr(body):
    c = collections.Counter(re.findall(r'\bZR (\d{3,4})\b', body))
    return [f'ZR {n}' for n, _ in c.most_common(2)]

def fit(head, models, tail):
    """Собрать «head — m1, m2, …: tail», выкидывая модели с конца, пока длина > MAXLEN."""
    models = list(models)
    while True:
        t = f'{head} — {", ".join(models)}: {tail}' if models else f'{head}: {tail}'
        if len(t) <= MAXLEN or not models:
            return t
        models.pop()

pages = {}
for f in sorted((ROOT / 'brands').glob('*.html')):
    if f.name == 'index.html':
        continue
    s = f.read_text(encoding='utf-8')
    m = re.search(TITLE, s, re.S)
    if m:
        pages[f] = (s, html.unescape(m.group(1)), text_of(s))

def series_of(stem, title):
    """(Бренд, код серии) для страницы серии, None для страницы бренда."""
    m = re.match(r'(.+?) сери[яи] (?:SITI )?([A-Za-z][A-Za-z0-9-]*)', title) or \
        re.match(r'(.+?) ([A-Z][A-Z0-9-]+) — характеристики', title)
    if m and '-' in stem:
        return m.group(1).strip(), m.group(2).strip()
    return None

def brand_stem(stem):
    for b in ('watt-drive', 'tos-znojmo', 'nord', 'siti'):
        if stem.startswith(b + '-'):
            return b
    return stem.split('-', 1)[0]

new_titles = {}
series_models = collections.defaultdict(list)    # бренд-стем -> [модели серии] по сериям
for f, (s, t, body) in pages.items():
    if '-' not in f.stem:
        continue
    sr = series_of(f.stem, t)
    suf = f.stem.split('-')[-1]
    code = sr[1] if sr else (suf.upper() if re.fullmatch(r'[a-z]{1,5}', suf) else None)
    if not code:
        continue
    codes = [code] + [c for c in code.split('-') if c != code]
    ms = list(dict.fromkeys(m for c in codes for m in sizes(body, c)))
    if ms:
        series_models[brand_stem(f.stem)].append(ms)
    if not sr or re.search(r'\d{2}', t) or not ms:
        continue
    an = zr(body)
    tail = 'купить, аналог ' + (', '.join(an) if an else 'ZR')
    new_titles[f] = fit(f'{sr[0]} {code}', ms, tail)

for f, (s, t, body) in pages.items():
    if '-' in f.stem or re.search(r'\d{2}', t):
        continue
    groups = series_models.get(f.stem, [])
    ms = []
    for i in range(2):                      # по кругу: сначала по одной модели каждой серии, потом по второй
        ms += [g[i] for g in groups if len(g) > i]
    if not ms:
        continue
    head = t.split(' — ')[0]
    new_titles[f] = fit(head, ms, 'купить, аналог | ZR')

changes = {}
for f, nt in new_titles.items():
    s = pages[f][0]
    m = re.search(TITLE, s, re.S)
    ns = s[:m.start(1)] + html.escape(nt, quote=False) + s[m.end(1):]
    m = re.search(OGT, ns)
    if m:
        ns = ns[:m.start(1)] + html.escape(nt.replace(' | ZR', ''), quote=True) + ns[m.end(1):]
    if ns != s:
        changes[f] = ns

print(f'Корень: {ROOT}\nИзменится страниц: {len(changes)}')
for f in sorted(changes):
    print(f'\nhttps://{HOST}/brands/{f.stem}\n  было:  {pages[f][1]}\n  стало: {new_titles[f]}')
if not APPLY:
    print('\nЭто проверка. Чтобы записать — добавьте --apply.'); sys.exit(0)
if not changes:
    print('\nНечего менять.'); sys.exit(0)

ts = time.strftime('%Y%m%d-%H%M%S')
bak = pathlib.Path(os.path.expanduser(f'~/_archive_old/titles2-{ts}'))
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
for f, ns in changes.items():
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
