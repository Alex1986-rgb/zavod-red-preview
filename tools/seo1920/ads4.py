#!/usr/bin/env python3
"""ads4: каталог под общие запросы из выгрузки рекламы конкурента (см. PAGES). Основа — seo1920/patch.py.
SEO-правки под список запросов keys_zavod_marzha_1920 (192 запроса × 10 городов, Яндекс), 09.10.2026.

Запуск НА СЕРВЕРЕ из любой папки:
    python3 patch.py ~/zavod-red.ru/public_html           # проверка: что изменится, ничего не пишет
    python3 patch.py ~/zavod-red.ru/public_html --apply   # записать + резервная копия + IndexNow

Что делает:
  1. Переписывает <title>, meta description, og:title, og:description на страницах из PAGES —
     под конкретные запросы (модели берутся только те, что реально есть на странице).
  2. На страницах серий брендов убирает протёкшие из генератора чужие модели «S 97, S 67, S 77, S 87»
     (были в title у 23 страниц: «SEW серия W … аналоги S 67, S 77, S 87») и чинит падеж
     «5 типоразмеров червячный редукторов» → «червячных».
  3. Ставит сегодняшний <lastmod> этим URL во всех sitemap*.xml.
  4. Отправляет изменённые URL в IndexNow (Яндекс + Bing) — ключ сайта из index_accelerator.py.
Перед записью кладёт копии исходных файлов в ~/_archive_old/seo1920-<время>/ (с путями).
Повторный запуск безопасен: если на странице уже нужный текст — она не трогается.
"""
import sys, re, os, html, json, time, shutil, pathlib, datetime, urllib.request

ROOT = pathlib.Path(os.path.expanduser(sys.argv[1])).resolve()
APPLY = '--apply' in sys.argv
HOST = 'zavod-red.ru'
KEY = '051a8bb09331b03c5e35d4a40339b5b3'

G = 'оригинал под заказ или аналог ZR собственного производства (Челябинск)'
T = 'Цена по запросу, подбор по шильду за 15 минут, гарантия 36 месяцев.'

def d(what, models=''):
    s = f'{what}: {G}.'
    if models:
        s += f' {models}.'
    return f'{s} {T}'

# путь (без .html) → (title, description). Ключи — из выгрузки рекламы конкурента «Техноред»
# (поисковые запросы 01.2024–07.2026, 16 тыс. запросов): общие запросы без брендов, дававшие заявки.
PAGES = {
 'index': ('Завод Редукторов — производство понижающих редукторов и мотор-редукторов',
           'Редукторный завод в Челябинске: понижающие редукторы и мотор-редукторы от производителя — цена завода, '
           'изготовление на заказ. Червячные, цилиндрические, планетарные, 220 и 380 В. Аналоги SEW, NORD, '
           'Bonfiglioli. Гарантия 36 месяцев.'),
 'about': ('Редукторный завод в Челябинске «НИИ АТТ» — производство редукторов | ZR',
           'Редукторный завод в Челябинске (ООО «НИИ АТТ»): производство и изготовление редукторов на заказ, '
           'мотор-редукторы ZR, серии ПР и МР. Зубошлифовка, закалка 56–62 HRC, гарантия 36 месяцев.'),
 'catalog/index': ('Понижающие редукторы и мотор-редукторы — каталог, виды, цена от производителя | ZR',
           'Каталог понижающих редукторов и мотор-редукторов от производителя. Все виды: червячные, цилиндрические, '
           'планетарные, конические, соосные, плоские, вариаторы. Цена завода, заказ и подбор по параметрам.'),
 'catalog/motor-reduktory': ('Мотор-редуктор купить — цена от производителя, подбор по параметрам | ZR',
           'Мотор-редукторы купить от производителя: червячные, цилиндрические, конические, планетарные, 220 и 380 В, '
           'с тормозом. Цена завода, подбор по моменту и оборотам онлайн, отгрузка от 3 дней, гарантия 36 месяцев.'),
 'catalog/motor-reduktory-380v': ('Мотор-редуктор 380 В купить — трёхфазные ZR 603, ZR 604, ZR 606 | ZR',
           'Мотор-редукторы 380 вольт (трёхфазные) купить от производителя: червячные, соосные, цилиндрические. '
           'Цена завода, подбор по параметрам, замена SEW и NORD, гарантия 36 месяцев.'),
 'catalog/motor-reduktory-220v': ('Мотор-редуктор 220 В купить — однофазные ZR 603, ZR 604, ZR 606 | ZR',
           'Мотор-редукторы 220 вольт (однофазные) купить от производителя: червячные, соосные, плоские, '
           '0,12–2,2 кВт, малооборотные. Цена завода, подбор по параметрам, гарантия 36 месяцев.'),
 'catalog/cilindricheskie': ('Цилиндрический мотор-редуктор — цена от производителя, заказать | ZR',
           'Цилиндрические редукторы и мотор-редукторы от производителя: одно- и двухступенчатые Ц2У, ЦДУ, высокий КПД. '
           'Цена завода, заказ и подбор под нагрузку, отгрузка от 3 дней, гарантия 36 месяцев.'),
 'catalog/planetarnye': ('Планетарный мотор-редуктор — цена от производителя, расчёт и подбор | ZR',
           'Планетарные редукторы и мотор-редукторы: цена от производителя, расчёт и подбор по моменту и передаточному '
           'числу, изготовление под заказ. Купить с гарантией 36 месяцев.'),
 'catalog/chervyachnye': ('Червячный редуктор и мотор-редуктор купить — ZR 603, ZR 604, ZR 605 | ZR',
           'Червячные редукторы и мотор-редукторы купить от производителя: компактные, самотормозящие, серии Ч и МЧ. '
           'Цена завода, подбор онлайн, отгрузка от 3 дней, гарантия 36 месяцев.'),
 'catalog/konicheskie': ('Конический мотор-редуктор — купить, виды, подбор и замена | ZR',
           'Конические редукторы и мотор-редукторы: устройство, КПД, отличие от коническо-цилиндрических. '
           'Купить от производителя, подбор замены под угловую компоновку привода.'),
 'catalog/soosnye': ('Соосный мотор-редуктор купить — ZR 949, ZR 959, ZR 969 | ZR',
           'Соосные (соосно-цилиндрические) мотор-редукторы купить от производителя: мотор и передача на одной оси, '
           'высокий КПД. Подбор под задачу, отгрузка от 3 дней, гарантия 36 месяцев.'),
 'catalog/reduktory-s-tormozom': ('Мотор-редуктор с тормозом купить — ZR 603, ZR 604, ZR 606 | ZR',
           'Мотор-редукторы с электромагнитным тормозом от производителя: точная остановка, удержание нагрузки, '
           'защита от обратного хода. Купить с подбором по параметрам, гарантия 36 месяцев.'),
 'catalog/reduktory-s-polym-valom': ('Мотор-редуктор с полым валом купить — ZR 603, ZR 604, ZR 606 | ZR',
           'Редукторы и мотор-редукторы с полым выходным валом: под вал, со шпонкой, со стяжной втулкой. '
           'Купить от производителя, подбор по диаметру вала, гарантия 36 месяцев.'),
 'catalog/variatory': ('Мотор-редуктор с вариатором (мотор-вариатор) — ZR 603, ZR 604, ZR 606 | ZR',
           'Мотор-редукторы с вариатором и механические вариаторы скорости: бесступенчатое регулирование оборотов. '
           'Подбор под технологический процесс, поставка по РФ.'),
 'catalog/evl': ('Мотор-редуктор EVL (ЕВЛ) — EVL 187, EVL 188, EVL 189, EVL 747: каталог и подбор | ZR',
           'Редукторы и мотор-редукторы EVL (ЕВЛ): EVL 184–189, EVL 198, EVL 747 и другие типоразмеры. '
           'Расшифровка обозначения, подбор по маркировке и шильдику, производство и поставка.'),
 'catalog/pr': ('Редукторы ПР — ПР 315, ПР 316, ПР 430, ПР 450, ПР 463: производство и подбор | ZR',
           'Редукторы серии ПР собственного производства: ПР 315, ПР 316, ПР 314, ПР 317, ПР 219, ПР 430, ПР 450, '
           'ПР 463, ПР 490, ПР 1113. Расшифровка обозначения, подбор по шильду за 15 минут, гарантия 36 месяцев.'),
 'catalog/mr': ('Мотор-редукторы МР — МР 3110, МР 4110, МР 440, МР 313: цена и подбор | ZR',
           'Мотор-редукторы серии МР собственного производства: МР 3110, МР 4110, МР 4150, МР 440, МР 313, МР 315, '
           'МР 217, МР 218. Цена завода, подбор по обозначению или шильду, гарантия 36 месяцев.'),
 'podbor': ('Расчёт редуктора онлайн — калькулятор подбора мотор-редуктора по параметрам | ZR',
           'Онлайн-калькулятор: расчёт и подбор редуктора и мотор-редуктора по параметрам — мощность, обороты, момент, '
           'передаточное число. Подходящие модели и КП от завода за 15 минут.'),
 'privodnaya-tehnika': ('Приводная техника: редукторы для электродвигателей, мотор-редукторы | ZR',
           'Приводная техника от производителя: редукторы для электродвигателей, мотор-редукторы ZR, ГОСТ-серии ПР и МР, '
           'замена импортных приводов. Подбор по параметрам, гарантия 36 месяцев.'),
}

LEAK = re.compile(r'(?:\s*,?\s*\bS (?:97|67|77|87)\b)+')
CASE = re.compile(r'(\d+ типоразмеров )([а-яё-]+?)(ый|ий|ой) редукторов')

def fix_case(s):
    return CASE.sub(lambda m: m.group(1) + m.group(2) + ('их' if m.group(3) == 'ий' else 'ых') + ' редукторов', s)

def set_tag(s, pat, val, fmt):
    """Заменить содержимое первого совпадения pat (группа 1 — значение) на val."""
    m = re.search(pat, s, re.S)
    if not m:
        return s, False
    return s[:m.start(1)] + fmt(val) + s[m.end(1):], True

def esc(v):
    return html.escape(v, quote=True)

def page_file(rel):
    for c in (ROOT / f'{rel}.html', ROOT / rel / 'index.html'):
        if c.is_file():
            return c
    return None

def url_of(rel):
    if rel == 'index':
        return f'https://{HOST}/'
    return f'https://{HOST}/{rel}'

TITLE = r'<title>(.*?)</title>'
DESC = r'<meta name="description" content="([^"]*)"'
OGT = r'<meta property="og:title" content="([^"]*)"'
OGD = r'<meta property="og:description" content="([^"]*)"'

changes = {}   # Path -> (old, new, url)
missing = []

for rel, (title, desc) in PAGES.items():
    f = page_file(rel)
    if not f:
        missing.append(rel); continue
    old = f.read_text(encoding='utf-8')
    s = old
    s, ok = set_tag(s, TITLE, title, html.escape)
    if not ok:
        missing.append(rel + ' (нет <title>)'); continue
    s, _ = set_tag(s, DESC, desc, esc)
    s, _ = set_tag(s, OGT, title.replace(' | ZR', ''), esc)
    s, _ = set_tag(s, OGD, desc, esc)
    if s != old:
        changes[f] = (old, s, url_of(rel))

def show(v):
    return html.unescape(v)

print(f'Корень: {ROOT}\nИзменится страниц: {len(changes)}')
for f, (old, new, url) in sorted(changes.items()):
    ot = re.search(TITLE, old, re.S).group(1)
    nt = re.search(TITLE, new, re.S).group(1)
    print(f'\n{url}\n  было:  {show(ot)}\n  стало: {show(nt)}')
if missing:
    print('\nНЕ НАЙДЕНО (пропущено):', ', '.join(missing))

if not APPLY:
    print('\nЭто проверка. Чтобы записать — добавьте --apply.')
    sys.exit(0)
if not changes:
    print('\nНечего менять — всё уже применено.'); sys.exit(0)

ts = time.strftime('%Y%m%d-%H%M%S')
bak = pathlib.Path(os.path.expanduser(f'~/_archive_old/ads4-{ts}'))
urls = sorted({u for _, _, u in changes.values()})
today = datetime.date.today().isoformat()

# sitemap: только те, где встречается хотя бы один из наших URL
smaps = {}
for sm in sorted(ROOT.glob('sitemap*.xml')):
    t = sm.read_text(encoding='utf-8')
    nt = t
    for u in urls:
        nt = re.sub(r'(<loc>' + re.escape(u) + r'</loc>\s*<lastmod>)[^<]*(</lastmod>)', r'\g<1>' + today + r'\g<2>', nt)
    if nt != t:
        smaps[sm] = nt

for f in list(changes) + list(smaps):
    dst = bak / f.relative_to(ROOT)
    dst.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(f, dst)
for f, (_, new, _) in changes.items():
    f.write_text(new, encoding='utf-8')
for sm, t in smaps.items():
    sm.write_text(t, encoding='utf-8')
print(f'\nЗаписано страниц: {len(changes)}, sitemap-файлов: {len(smaps)}. Резервная копия: {bak}')
print(f'Откат: cp -rp {bak}/. {ROOT}/')

if '--no-ping' in sys.argv:
    print('IndexNow пропущен (--no-ping)'); sys.exit(0)

# IndexNow
kf = ROOT / f'{KEY}.txt'
if not kf.is_file():
    kf.write_text(KEY, encoding='utf-8')
    print(f'Создан файл ключа IndexNow: {kf.name}')
payload = json.dumps({'host': HOST, 'key': KEY, 'keyLocation': f'https://{HOST}/{KEY}.txt',
                      'urlList': urls}).encode()
for ep in ('https://yandex.com/indexnow', 'https://api.indexnow.org/indexnow'):
    try:
        r = urllib.request.urlopen(urllib.request.Request(
            ep, data=payload, headers={'Content-Type': 'application/json; charset=utf-8'}), timeout=30)
        print(f'IndexNow {ep}: HTTP {r.status} — отправлено URL: {len(urls)}')
    except urllib.error.HTTPError as e:
        print(f'IndexNow {ep}: HTTP {e.code} {e.read()[:200]!r}')
    except Exception as e:
        print(f'IndexNow {ep}: ошибка {e}')
print('ГОТОВО')
