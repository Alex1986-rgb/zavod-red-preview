#!/usr/bin/env python3
"""SEO-правки под список запросов keys_zavod_marzha_1920 (192 запроса × 10 городов, Яндекс), 09.10.2026.

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

# путь (без .html) → (title, description)
PAGES = {
 # Общие запросы: производство редукторов, промышленные редукторы, редукторный завод
 'index': ('Завод Редукторов — производство промышленных редукторов и мотор-редукторов',
           'Редукторный завод в Челябинске: производство промышленных редукторов и мотор-редукторов — '
           'червячные, цилиндрические, соосные, конические, вариаторы. Аналоги SEW, NORD, Bonfiglioli. '
           'Отгрузка от 3 дней, гарантия 36 месяцев.'),
 'about': ('Редукторный завод «НИИ АТТ» — производство редукторов в Челябинске | ZR',
           'Редукторный завод ООО «НИИ АТТ»: собственное производство промышленных редукторов и '
           'мотор-редукторов в Челябинске. Зубошлифовка, закалка 56–62 HRC, гарантия 36 месяцев.'),
 # Собственные серии ПР и МР
 'catalog/pr': ('Редукторы ПР — ПР 316, ПР 430, ПР 450, ПР 463: производство и подбор | ZR',
                'Редукторы серии ПР собственного производства: ПР 316 (Ц, ФП), ПР 430 П, ПР 450, ПР 463 и '
                'другие типоразмеры. Расшифровка обозначения, подбор по шильду за 15 минут, гарантия 36 месяцев.'),
 'catalog/mr': ('Мотор-редукторы МР — МР 3110, МР 440: цена, производство и подбор | ZR',
                'Мотор-редукторы серии МР собственного производства: МР 3110 П, МР 440 П и другие типоразмеры. '
                'Цена завода без посредников, подбор по обозначению или шильду, гарантия 36 месяцев.'),
 # Bonfiglioli
 'brands/bonfiglioli': ('Мотор-редукторы Bonfiglioli (Бонфильоли) — купить: VF, W, C, A, F, аналог | ZR',
     d('Купить мотор-редуктор или редуктор Bonfiglioli (Бонфильоли)',
       'Червячные VF и W, цилиндрические C, конические A, плоские F')),
 'brands/bonfiglioli-vf': ('Червячный редуктор Bonfiglioli VF — VF 30, VF 44, VF 49: купить, аналог ZR',
     d('Червячные редукторы и мотор-редукторы Bonfiglioli VF',
       'Типоразмеры VF 30, VF 44, VF 49, VF 130, VF 150, расшифровка обозначения')),
 'brands/bonfiglioli-w': ('Редуктор Bonfiglioli W — W 63, W 75, W 86, W 110: купить, аналог ZR',
     d('Червячные редукторы Bonfiglioli серии W', 'Типоразмеры W 63, W 75, W 86, W 110')),
 'brands/bonfiglioli-c': ('Bonfiglioli C — цилиндрический мотор-редуктор C 22…C 100: купить, аналог ZR',
     d('Соосно-цилиндрические мотор-редукторы Bonfiglioli серии C', 'Типоразмеры от C 22 до C 100')),
 'brands/bonfiglioli-f': ('Редуктор Bonfiglioli F — F 10, F 20, F 25…F 90: купить, аналог ZR',
     d('Плоско-цилиндрические редукторы Bonfiglioli серии F', 'Типоразмеры F 10, F 20, F 25, F 31, F 41 … F 90')),
 # SEW-Eurodrive
 'brands/sew': ('Мотор-редукторы SEW-Eurodrive — купить: серии R, K, F, S, W, аналог | ZR',
     d('Купить мотор-редуктор SEW-Eurodrive (СЕВ-Евродрайв)',
       'Серии R, K, F, S, W: R37…R97, K67, K77, FA57, SA67, WA20')),
 'brands/sew-r': ('Редуктор SEW R — R37, R57, R67, R77, R87, R97, RF57, RF87: купить, аналог ZR',
     d('Соосно-цилиндрические редукторы и мотор-редукторы SEW-Eurodrive серии R',
       'R37, R57, R67, R77, R87, R97, фланцевые RF57, RF87')),
 'brands/sew-k': ('Редуктор SEW K — K67, K77, KF77, KH77, KAF97, KAZ127: купить, аналог ZR',
     d('Коническо-цилиндрические мотор-редукторы SEW-Eurodrive серии K',
       'K37…K187, исполнения KF77, KH77, KA97, KAF97, KAZ127')),
 'brands/sew-f': ('Редуктор SEW F — FA57, FA97, FF87, F47…F127: купить, аналог ZR',
     d('Плоско-цилиндрические редукторы SEW-Eurodrive серии F',
       'F37…F157, насадные FA57, FA97, фланцевые FF87')),
 'brands/sew-s': ('Редуктор SEW S — S37…S97, SA67, SF77: купить, аналог ZR',
     d('Мотор-редукторы SEW-Eurodrive серии S', 'S37, S47, S57, S67, S77, S87, S97, исполнения SA67, SF77')),
 'brands/sew-w': ('Редуктор SEW W (Spiroplan) — WA20, WA37, WA47: купить, аналог ZR',
     d('Мотор-редукторы SEW-Eurodrive Spiroplan серии W', 'WA20, WA37, WA47, WF20, WF37, WF47')),
 # NORD
 'brands/nord': ('Мотор-редукторы NORD (Норд) SK — купить: SK 2282, SK 9012, аналог | ZR',
     d('Купить мотор-редуктор NORD (Норд) SK',
       'SK 2282, SK 2382, SK 3282, SK 5282, SK 9012, SK 9016 — подбор аналога NORD по шильду')),
 'brands/nord-sk-ploskie': ('NORD SK плоские — SK 2282, SK 2382, SK 3282: купить, аналог ZR',
     d('Плоско-цилиндрические мотор-редукторы NORD SK', 'SK 1282, SK 2282, SK 2382, SK 3282 и другие типоразмеры')),
 # Motovario
 'brands/motovario': ('Мотор-редукторы Motovario (Мотоварио) — купить: NMRV, NRV, аналог | ZR',
     d('Купить мотор-редуктор Motovario (Мотоварио)', 'Червячные NMRV, NRV и другие серии')),
 'brands/motovario-nmrv': ('Червячный редуктор Motovario NMRV / NRV 030…150: купить, аналог ZR',
     d('Червячные редукторы Motovario NMRV (NRV)', 'NMRV 030, 040, 050, 063, 075, 090, 110, 130, 150')),
 # SITI
 'brands/siti': ('Мотор-редукторы SITI (СИТИ) — купить: MU, MI, MBH, MNHL, аналог | ZR',
     d('Купить мотор-редуктор SITI (СИТИ)', 'Червячные MU и MI, конические BH (MBH), соосные MNHL')),
 'brands/siti-bh': ('SITI BH / MBH — мотор-редуктор MBH 125, MBH 160: купить, аналог ZR',
     d('Коническо-цилиндрические мотор-редукторы SITI BH (MBH)', 'BH 63, 80, 125, 140, 160, 180, 200')),
 'brands/siti-mi': ('Редуктор SITI MI — MI 40, MI 50…MI 90, MI 150: купить, аналог ZR',
     d('Червячные редукторы SITI серии MI', 'MI 30, MI 40, MI 50, MI 60, MI 70, MI 80, MI 90, MI 150')),
 'brands/siti-mu': ('Червячный редуктор SITI MU — MU 40, MU 50…MU 110: купить, аналог ZR',
     d('Червячные редукторы SITI серии MU', 'MU 30, MU 40, MU 50, MU 63, MU 75, MU 90, MU 110')),
 'brands/siti-mnhl': ('Мотор-редуктор SITI MNHL (HL) — MNHL 25…100: купить, аналог ZR',
     d('Соосно-цилиндрические мотор-редукторы SITI MNHL (HL)', 'MNHL 25, 35, 40, 50, 60, 70, 90, 100')),
 # Innored, Innovari
 'brands/innored': ('Редукторы и мотор-редукторы Innored (Иннорэд) — купить, аналог | ZR',
     d('Купить редуктор или мотор-редуктор Innored (Иннорэд)', 'Червячные IRW / IRWD и вариаторы')),
 'brands/innored-irwd': ('Червячный редуктор Innored IRW / IRWD 030…150: купить, аналог ZR',
     d('Червячные редукторы Innored IRW (IRWD)', 'IRWD 030, 040, 050, 063, 075, 090, 110, 130, 150')),
 'brands/innovari': ('Мотор-редукторы Innovari (Инновари) — купить: оригинал и аналог | ZR',
     d('Купить мотор-редуктор Innovari (Инновари)')),
 # Varvel, Tos Znojmo
 'brands/varvel': ('Редукторы Varvel (Варвел) — купить: RV, SRT, MRN, аналог | ZR',
     d('Купить редуктор или мотор-редуктор Varvel (Варвел)', 'Серии RV, SRT, MRN, MRD, RO, SRS')),
 'brands/varvel-rv': ('Редуктор Varvel RV — RV 13…RV 63: купить, аналог ZR',
     d('Редукторы Varvel серии RV', 'RV 13, RV 22, RV 33, RV 43, RV 53, RV 63')),
 'brands/varvel-srt': ('Червячный редуктор Varvel SRT — SRT 28…SRT 110, SRT 85: купить, аналог ZR',
     d('Червячные редукторы Varvel серии SRT', 'SRT 28, 40, 50, 60, 70, 85, 110')),
 'brands/varvel-mrn': ('Мотор-редуктор Varvel MRN: купить, типоразмеры, аналог ZR',
     d('Плоско-цилиндрические мотор-редукторы Varvel серии MRN')),
 'brands/tos-znojmo-rt-mrt': ('Мотор-редуктор Tos Znojmo MRT / RT — MRT 70, MRT 80: купить, аналог ZR',
     d('Червячные мотор-редукторы Tos Znojmo MRT (RT)', 'MRT 30, 40, 50, 70, 80, 100, 120, 150')),
 # Остальные бренды
 'brands/tramec': ('Редукторы и мотор-редукторы Tramec (Трамек) — купить, аналог | ZR',
     d('Купить редуктор или мотор-редуктор Tramec (Трамек)', 'Серии K и XC')),
 'brands/yilmaz': ('Редукторы и мотор-редукторы Yilmaz (Йилмаз) — купить, аналог | ZR',
     d('Купить редуктор или мотор-редуктор Yilmaz (Йилмаз)', 'Серии E, K, M, N')),
 'brands/lenze': ('Мотор-редуктор Lenze (Ленце) — купить: GFL, GST, GKR, GKS, аналог | ZR',
     d('Купить мотор-редуктор или редуктор Lenze (Ленце)', 'Серии GFL, GST, GKR, GKS')),
 'brands/transtecno': ('Редукторы Transtecno (Транстекно, Транстехно) — купить, аналог | ZR',
     d('Купить редуктор Transtecno (Транстекно, Транстехно)', 'Серии CM, CMG, CMIS, ECFT')),
 'brands/transtecno-cmg': ('Редуктор Transtecno CMG — CMG 012…CMG 052: купить, аналог ZR',
     d('Соосно-цилиндрические редукторы Transtecno CMG', 'CMG 012, CMG 032, CMG 042, CMG 052')),
 'brands/guomao': ('Редукторы Guomao (Гуомао) — купить: GR, GK, GF, GS, аналог | ZR',
     d('Купить редуктор или мотор-редуктор Guomao (Гуомао)', 'Серии GR, GK, GF, GS')),
 'brands/boneng': ('Редукторы Boneng (Бонэнг) — купить: оригинал и аналог | ZR',
     d('Купить редуктор или мотор-редуктор Boneng (Бонэнг)')),
 'brands/watt-drive': ('Мотор-редукторы Watt Drive (Ватт Драйв) — купить, аналог | ZR',
     d('Купить мотор-редуктор Watt Drive (Ватт Драйв)', 'Серии A, F, H, K, S')),
 'brands/keb': ('Мотор-редукторы KEB (КЕБ) — купить: оригинал и аналог | ZR',
     d('Купить мотор-редуктор или редуктор KEB (КЕБ)')),
 'brands/siemens': ('Мотор-редукторы Siemens (Сименс) SIMOGEAR — купить, аналог | ZR',
     d('Купить мотор-редуктор Siemens (Сименс) SIMOGEAR')),
 'brands/bauer': ('Мотор-редукторы Bauer (Бауэр) — купить: BS, BF, BG, BK, аналог | ZR',
     d('Купить мотор-редуктор Bauer (Бауэр)', 'Серии BS, BF, BG, BK')),
 'brands/unidrive': ('Мотор-редукторы UNI Drive (Юнидрайв, Unitdrive) — купить, аналог | ZR',
     d('Купить мотор-редуктор UNI Drive (Юнидрайв, Юнит Драйв)')),
 'brands/rossi': ('Редукторы Rossi (Росси) — купить: MR, MRV, RV, аналог | ZR',
     d('Купить редуктор или мотор-редуктор Rossi (Росси)', 'Серии MR, MRV, RV, R-I')),
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

# Утечка «S 97, S 67, S 77, S 87» и падеж — на всех страницах серий брендов (кроме самих SEW S и SEW).
for f in sorted((ROOT / 'brands').glob('*.html')):
    if f.name in ('sew-s.html', 'sew.html', 'watt-drive-s.html', 'index.html'):
        continue
    base = changes[f][0] if f in changes else f.read_text(encoding='utf-8')
    s = changes[f][1] if f in changes else base
    for pat in (TITLE, DESC, OGT, OGD):
        m = re.search(pat, s, re.S)
        if not m:
            continue
        v = m.group(1)
        nv = fix_case(LEAK.sub('', v))
        nv = re.sub(r'аналоги(?:\s+ZR)?\s*\|\s*ZR\s*$', 'аналоги ZR', nv)
        nv = re.sub(r'аналоги\s*$', 'аналоги ZR', nv)
        nv = re.sub(r'Обозначения:\s*,\s*', 'Обозначения: ', nv)
        nv = re.sub(r'\s{2,}', ' ', nv)
        if nv != v:
            s = s[:m.start(1)] + nv + s[m.end(1):]
    if s != base:
        rel = 'brands/' + f.stem
        changes[f] = (base, s, url_of(rel))

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
bak = pathlib.Path(os.path.expanduser(f'~/_archive_old/seo1920-{ts}'))
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
