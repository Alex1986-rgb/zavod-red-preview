#!/usr/bin/env python3
"""Проверка БОЕВОГО сайта после заливки — по фактам, а не по зелёному статусу Actions.

Зачем именно так: lftp mirror --ignore-time сравнивает файлы ПО РАЗМЕРУ и не удаляет
лишние. Правка, не изменившая длину файла, молча не заливается, а воркфлоу при этом
отчитывается успехом. Единственный надёжный способ убедиться — запросить прод и
посмотреть, что он реально отдаёт.

Запуск: python3 tools/check_prod.py
Код возврата 1, если хоть одна проверка провалена.
"""
import json, re, sys, urllib.request, urllib.error

BASE = 'https://zavod-red.ru'
results = []


def get(path, timeout=25):
    req = urllib.request.Request(BASE + path, headers={'User-Agent': 'zavod-check/1.0'})
    try:
        with urllib.request.urlopen(req, timeout=timeout) as r:
            return r.getcode(), r.read().decode('utf-8', 'ignore')
    except urllib.error.HTTPError as e:
        return e.code, ''
    except Exception as e:
        return 0, str(e)


def check(name, ok, detail=''):
    results.append((ok, name))
    print(f'{"OK  " if ok else "ПРОВАЛ"}  {name}' + (f'\n        {detail}' if detail and not ok else ''))


print(f'Проверка {BASE}\n')

# ── 1. Главный баг заказчика: плитка бренда ведёт на карточку импорта ────────
code, h = get('/brands/sew')
check('/brands/sew отвечает', code == 200, f'код {code}')
check('Плитки ведут на /analog/, а не на обезличенную карточку ZR',
      'var url=c.u?("/analog/"+c.u)' in h, 'приоритет ссылок не обновился')

# ── 2. Новые карточки брендов, которых раньше не было ────────────────────────
for path in ['/analog/tos-znojmo-tz-623', '/analog/yilmaz-e-030',
             '/analog/rossi-mr-0-i344-0-12kvt', '/brands/tramec', '/brands/tos-znojmo']:
    code, _ = get(path)
    check(f'{path}', code == 200, f'код {code}')

# ── 3. Карточка ZR в новом виде ──────────────────────────────────────────────
code, h = get('/reduktor/evl-737')
check('Карточка ZR: плашка-оффер и блок доверия', 'p2-offer' in h and 'p2-trust' in h,
      'карточка осталась в старом виде')

# ── 3b. «Тонкие» карточки: исполнения, мотор-редукторы ZR, типоразмеры ───────
# 19 358 страниц, которые дольше всех оставались в старом виде — их не задел ни один
# из прежних проходов, и заметил это заказчик, а не проверка.
for path in ['/ispolnenie/evl-1810-i26_32-30kvt',
             '/motor-reduktor-zr/zr-959-i5-4kvt',
             '/tiporazmer/evl-198x-i1_39']:
    code, h = get(path)
    check(f'{path} — в новом виде',
          code == 200 and 'p2-offer' in h and 'p2-trust' in h,
          f'код {code}, оффер {"есть" if "p2-offer" in h else "нет"}, '
          f'плитки {"есть" if "p2-trust" in h else "нет"}')

# ── 4. Кнопки карточки аналога: две, оригинал первым ─────────────────────────
code, h = get('/analog/lenze-gfl-04-i128-51-0-12kvt')
m = re.search(r'<div class="p2-cta1">(.*?)</div>', h, re.S)
labels = re.findall(r'>([^<>]+)</a>', m.group(1)) if m else []
check('Кнопки карточки аналога: «Запросить оригинал» + «Подобрать аналог»',
      labels == ['Запросить оригинал', 'Подобрать аналог'], f'получено: {labels}')

# ── 5. Редиректы выдуманного бренда ──────────────────────────────────────────
for old, new in [('/brands/sew-tramec', '/brands/tramec'),
                 ('/blog/kupit-sew-tramec', '/blog/kupit-tramec')]:
    req = urllib.request.Request(BASE + old, method='HEAD')
    try:
        opener = urllib.request.build_opener(type('NoRedir', (urllib.request.HTTPRedirectHandler,),
                                                  {'redirect_request': lambda *a, **k: None})())
        with opener.open(req, timeout=25) as r:
            loc, code = r.headers.get('Location', ''), r.getcode()
    except urllib.error.HTTPError as e:
        loc, code = e.headers.get('Location', ''), e.code
    except Exception as e:
        loc, code = str(e), 0
    check(f'301 {old} → {new}', code == 301 and new in (loc or ''), f'код {code}, Location {loc}')

# ── 6. Ассеты обновились ─────────────────────────────────────────────────────
for a, marker in [('/assets/podbor.js', 'analog-idx'),
                  ('/assets/inner.css', 'p2-trust'),
                  ('/assets/modal.js', 'ms-rz')]:
    code, h = get(a)
    check(f'{a} — новая версия', code == 200 and marker in h,
          f'код {code}, маркер «{marker}» {"есть" if marker in h else "отсутствует"}')

# ── 7. Индекс карточек для калькулятора доехал ───────────────────────────────
code, h = get('/assets/analog-idx/tos-znojmo.json')
check('Индекс analog-idx на месте', code == 200 and 'tz-623' in h, f'код {code}')

# ── 8. Приём заявок жив ──────────────────────────────────────────────────────
try:
    req = urllib.request.Request(BASE + '/api/feedback.php', data=b'{}',
                                 headers={'Content-Type': 'application/json'}, method='POST')
    with urllib.request.urlopen(req, timeout=25) as r:
        code = r.getcode()
except urllib.error.HTTPError as e:
    code = e.code
except Exception:
    code = 0
check('Приём заявок отвечает', code in (200, 400, 422), f'код {code}')

# ── 9. Старой марки нет в видимом тексте главной ─────────────────────────────
code, h = get('/')
vis = re.sub(r'<(script|style)[^>]*>.*?</\1>', ' ', h, flags=re.S)
vis = re.sub(r'<[^>]+>', ' ', vis)
check('EVL не виден в тексте главной', 'EVL' not in vis, 'старая марка на месте')

# ── 9b. Поля форм заявки имеют name ──────────────────────────────────────────
# Браузер отправляет ТОЛЬКО поля с атрибутом name. У «Ваше имя» и «Телефон» его не было,
# и заявки со страничных форм приходили без контактов — письмо есть, данных нет.
# Заметил заказчик, а не проверка, поэтому теперь проверяется на каждой выкатке.
for path in ['/contacts', '/importozameshchenie', '/about', '/proizvoditelyam-oborudovaniya']:
    code, h = get(path)
    form = re.search(r'<form[^>]*class="lead-form"[^>]*>(.*?)</form>', h, re.S)
    inner = form.group(1) if form else ''
    ok = code == 200 and 'name="text-562"' in inner and 'name="tel-535"' in inner
    check(f'{path} — форма шлёт имя и телефон', ok,
          f'код {code}, имя {"есть" if "text-562" in inner else "НЕТ"}, '
          f'телефон {"есть" if "tel-535" in inner else "НЕТ"}')

# ── 10. Боевые ассеты совпадают со сборкой ───────────────────────────────────
# Главная ловушка деплоя: lftp mirror сравнивает файлы ПО РАЗМЕРУ. Правка, не изменившая
# длину (перестановка блоков, замена версии той же длины), молча не заливается, а Actions
# показывает успех. Дважды за сессию поймали именно так — теперь проверяется всегда.
import hashlib, os
_dist = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), 'dist')
for a in ['podbor.js', 'inner.css', 'modal.js', 'inner.js']:
    p = os.path.join(_dist, 'assets', a)
    if not os.path.exists(p):
        continue
    code, live = get('/assets/' + a)
    same = code == 200 and hashlib.sha1(live.encode('utf-8')).hexdigest() == \
        hashlib.sha1(open(p, encoding='utf-8').read().encode('utf-8')).hexdigest()
    check(f'/assets/{a} совпадает со сборкой', same,
          f'код {code}, размеры: прод {len(live)}, сборка {os.path.getsize(p)}'
          + (' — РАЗМЕР СОВПАЛ, lftp пропустил файл' if len(live) == os.path.getsize(p) else ''))

print()
bad = [r for r in results if not r[0]]
print(f'Проверок: {len(results)} | провалено: {len(bad)}')
sys.exit(1 if bad else 0)
