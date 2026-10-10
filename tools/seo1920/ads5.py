#!/usr/bin/env python3
"""Заголовки страниц-хабов моделей analog/<бренд>-<серия>-<размер>.html под модельные запросы
из выгрузки рекламы конкурента («редуктор bonfiglioli vf49», «sew eurodrive r77», «motovario nmrv 050»).

Было:  «Bonfiglioli VF49 купить — оригинал под заказ, купить | ЗР»   (дважды «купить», нет «редуктор»)
Стало: «Редуктор Bonfiglioli VF 49 (VF49) — купить мотор-редуктор, аналог ZR 605 | ZR»
Модель и аналог ZR берутся из H1 самой страницы, ничего не придумывается. Меняются <title> и og:title,
описание не трогается. Только хабы (без «-i<передаточное>» в имени). Повтор безопасен.

    python3 ads5.py <public_html>            # показать «было → стало» (первые 40) и счёт
    python3 ads5.py <public_html> --apply    # записать + копия + IndexNow
"""
import sys, re, os, html, json, time, shutil, pathlib, urllib.request

ROOT = pathlib.Path(os.path.expanduser(sys.argv[1])).resolve()
APPLY = '--apply' in sys.argv
HOST, KEY = 'zavod-red.ru', '051a8bb09331b03c5e35d4a40339b5b3'
FULL = {'SEW': 'SEW-Eurodrive'}
MULTI = ('Watt Drive', 'Tos Znojmo', 'UNI Drive', 'SEW-Eurodrive')
TITLE = r'<title>(.*?)</title>'
OGT = r'<meta property="og:title" content="([^"]*)"'
H1 = r'<h1[^>]*>(.*?)</h1>'

changes = {}
skipped = 0
for f in sorted((ROOT / 'analog').glob('*.html')):
    if re.search(r'-i\d', f.stem) or f.name == 'index.html':
        continue
    s = f.read_text(encoding='utf-8')
    mt, mh = re.search(TITLE, s, re.S), re.search(H1, s, re.S)
    if not mt or not mh:
        skipped += 1; continue
    h1 = html.unescape(re.sub(r'<[^>]+>', '', mh.group(1))).strip()
    m = re.match(r'(.+?) — .*?(ZR \d{3,5})', h1)
    if not m:
        skipped += 1; continue
    model, zr = m.group(1).strip(), m.group(2)
    if re.search(r'[:,]|купить', model):
        skipped += 1; continue                       # нестандартный H1 — не трогаем
    brand = next((b for b in MULTI if model.startswith(b + ' ')), model.split(' ')[0])
    rest = model[len(brand):].strip()
    if not re.fullmatch(r'[A-Za-z]{1,6}[ .-]?[\dA-Za-z./-]*\d[\dA-Za-z./-]*', rest.replace(' ', '', 1)) or len(rest) > 16:
        skipped += 1; continue
    compact = rest.replace(' ', '')
    alias = f' ({compact})' if compact != rest else ''
    new = f'Редуктор {FULL.get(brand, brand)} {rest}{alias} — купить мотор-редуктор, аналог {zr} | ZR'
    old = html.unescape(mt.group(1))
    if old == new:
        continue
    ns = s[:mt.start(1)] + html.escape(new, quote=False) + s[mt.end(1):]
    mo = re.search(OGT, ns)
    if mo:
        ns = ns[:mo.start(1)] + html.escape(new.replace(' | ZR', ''), quote=True) + ns[mo.end(1):]
    changes[f] = (old, new, ns)

print(f'Корень: {ROOT}\nИзменится хабов: {len(changes)}, пропущено (нет H1/ZR): {skipped}')
for f, (o, n, _) in list(sorted(changes.items()))[:None if '--all' in sys.argv else 40]:
    print(f'  {f.stem}\n    было:  {o}\n    стало: {n}')
if not APPLY:
    print('\nЭто проверка. Чтобы записать — добавьте --apply.'); sys.exit(0)
if not changes:
    print('\nНечего менять.'); sys.exit(0)

ts = time.strftime('%Y%m%d-%H%M%S')
bak = pathlib.Path(os.path.expanduser(f'~/_archive_old/ads5-{ts}'))
for f in changes:
    dst = bak / f.relative_to(ROOT); dst.parent.mkdir(parents=True, exist_ok=True); shutil.copy2(f, dst)
for f, (_, _, ns) in changes.items():
    f.write_text(ns, encoding='utf-8')
urls = sorted(f'https://{HOST}/analog/{f.stem}' for f in changes)
print(f'\nЗаписано хабов: {len(changes)}. Резервная копия: {bak}')
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
