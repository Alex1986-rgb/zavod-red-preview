#!/usr/bin/env python3
"""Сопоставляет выгрузку ключевых запросов с тем, что уже есть на сайте.

Зачем: сбор выдачи и частотности (xmlriver, Yandex Search API, Вордстат) идёт вне этой
среды — сеть закрыта политикой окружения. Выгрузку делает владелец, а этот скрипт офлайн
отвечает на вопрос «под какие запросы страницы уже есть, а под какие нет» и группирует
непокрытое по брендам и типам, чтобы сразу видеть, что добавлять в генераторы.

Вход — файл в любом из форматов:
  • CSV/TSV  — колонка с запросом и, если есть, колонка с частотностью
               (заголовки распознаются: query/запрос/фраза/keyword, freq/частотность/shows/ws)
  • JSON     — [{"query": "...", "freq": 1234}, ...] либо {"запрос": 1234, ...}
  • XML      — берутся тексты <query>/<phrase>/<text>
  • TXT      — по одному запросу в строке, опционально "запрос<TAB>частотность"

Запуск:
    python3 tools/keywords_gap.py выгрузка.csv                 # отчёт в консоль
    python3 tools/keywords_gap.py выгрузка.csv --with-analog   # учесть и карточки /analog/
    python3 tools/keywords_gap.py выгрузка.csv --csv план.csv  # непокрытые запросы в файл
"""
import argparse
import csv
import glob
import json
import os
import re
import sys
from collections import Counter

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

# разделы, по которым ищем покрытие; analog/ подключается флагом (73 899 файлов)
SECTIONS = ('blog', 'glossary', 'catalog', 'brands', 'otrasli', 'uslugi', 'cases',
            'reduktor', 'motor-reduktor-zr', 'tiporazmer', 'ispolnenie')

QUERY_KEYS = ('query', 'запрос', 'фраза', 'keyword', 'ключевое слово', 'phrase', 'text')
FREQ_KEYS = ('freq', 'частотность', 'частота', 'shows', 'ws', 'count', 'volume', 'показов')

STOP = {'и', 'в', 'на', 'с', 'для', 'по', 'от', 'до', 'из', 'под', 'о', 'об', 'а', 'но',
        'или', 'к', 'у', 'за', 'же', 'ли', 'что', 'как', 'это'}

TITLE = re.compile(r'<title>([^<]*)</title>', re.I)
H1 = re.compile(r'<h1[^>]*>(.*?)</h1>', re.I | re.S)
TAGS = re.compile(r'<[^>]+>')


def norm(s):
    s = s.lower().replace('ё', 'е')
    s = re.sub(r'[^0-9a-zа-я]+', ' ', s)
    return re.sub(r'\s+', ' ', s).strip()


def tokens(s):
    return [w for w in norm(s).split() if w not in STOP and len(w) > 1]


# ------------------------------------------------------------------ чтение выгрузки
def read_queries(path):
    raw = open(path, encoding='utf-8-sig', errors='ignore').read().strip()
    if not raw:
        return []
    if raw[0] in '[{':
        return _from_json(raw)
    if raw[0] == '<':
        return _from_xml(raw)
    if '\t' in raw.split('\n')[0] or ',' in raw.split('\n')[0] or ';' in raw.split('\n')[0]:
        rows = _from_table(raw)
        if rows:
            return rows
    return [(ln.strip(), 0) for ln in raw.split('\n') if ln.strip()]


def _from_json(raw):
    data = json.loads(raw)
    out = []
    if isinstance(data, dict):
        for k, v in data.items():
            out.append((str(k), _int(v)))
        return out
    for item in data:
        if isinstance(item, str):
            out.append((item, 0))
            continue
        low = {str(k).lower(): v for k, v in item.items()}
        q = next((low[k] for k in QUERY_KEYS if k in low), None)
        f = next((low[k] for k in FREQ_KEYS if k in low), 0)
        if q:
            out.append((str(q), _int(f)))
    return out


def _from_xml(raw):
    return [(m, 0) for m in re.findall(r'<(?:query|phrase|text)>([^<]+)</', raw)]


def _from_table(raw):
    sample = raw[:4000]
    delim = max(('\t', ',', ';'), key=sample.count)
    rows = list(csv.reader(raw.split('\n'), delimiter=delim))
    rows = [r for r in rows if r and any(c.strip() for c in r)]
    if not rows:
        return []
    head = [c.strip().lower() for c in rows[0]]
    qi = next((i for i, c in enumerate(head) if c in QUERY_KEYS), None)
    fi = next((i for i, c in enumerate(head) if c in FREQ_KEYS), None)
    body = rows[1:] if qi is not None else rows
    if qi is None:
        qi = 0
        fi = 1 if len(rows[0]) > 1 else None
    out = []
    for r in body:
        if qi >= len(r) or not r[qi].strip():
            continue
        f = _int(r[fi]) if fi is not None and fi < len(r) else 0
        out.append((r[qi].strip(), f))
    return out


def _int(v):
    try:
        return int(re.sub(r'[^\d]', '', str(v)) or 0)
    except ValueError:
        return 0


# ------------------------------------------------------------------ индекс сайта
def build_index(with_analog):
    pages = []
    for name in sorted(glob.glob(os.path.join(ROOT, '*.html'))):
        pages.append(name)
    for sec in SECTIONS:
        pages += glob.glob(os.path.join(ROOT, sec, '*.html'))
    if with_analog:
        pages += glob.glob(os.path.join(ROOT, 'analog', '*.html'))

    index = []
    for p in pages:
        try:
            t = open(p, encoding='utf-8', errors='ignore').read(6000)
        except OSError:
            continue
        ti = TITLE.search(t)
        h1 = H1.search(t)
        slug = os.path.relpath(p, ROOT)[:-5].replace(os.sep, ' ').replace('-', ' ')
        text = ' '.join(filter(None, [
            ti.group(1) if ti else '',
            TAGS.sub(' ', h1.group(1)) if h1 else '',
            slug,
        ]))
        index.append((os.path.relpath(p, ROOT), set(tokens(text))))
    return index


def best_match(qtok, index):
    if not qtok:
        return None, 0.0
    need = set(qtok)
    best, score = None, 0.0
    for path, words in index:
        hit = len(need & words) / len(need)
        if hit > score:
            best, score = path, hit
            if score == 1.0:
                break
    return best, score


# ------------------------------------------------------------------ отчёт
def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('file', help='выгрузка запросов (csv/tsv/json/xml/txt)')
    ap.add_argument('--with-analog', action='store_true', help='учитывать карточки /analog/')
    ap.add_argument('--threshold', type=float, default=0.75,
                    help='доля слов запроса, при которой считаем запрос покрытым (0.75)')
    ap.add_argument('--csv', help='куда выгрузить непокрытые запросы')
    args = ap.parse_args()

    queries = read_queries(args.file)
    if not queries:
        print('в файле не найдено ни одного запроса'); sys.exit(1)
    seen = {}
    for q, f in queries:
        seen[norm(q)] = (q, max(f, seen.get(norm(q), ('', 0))[1]))
    queries = sorted(seen.values(), key=lambda x: -x[1])
    print(f'запросов в выгрузке: {len(queries)} (после снятия дублей)')

    index = build_index(args.with_analog)
    print(f'страниц в индексе: {len(index)}'
          f'{" (включая /analog/)" if args.with_analog else " (без /analog/, добавьте --with-analog)"}\n')

    covered, gaps = [], []
    for q, f in queries:
        path, score = best_match(tokens(q), index)
        (covered if score >= args.threshold else gaps).append((q, f, path, score))

    print(f'покрыто страницами: {len(covered)}')
    print(f'НЕ покрыто:         {len(gaps)}\n')

    if gaps:
        print('=== непокрытые запросы (по убыванию частотности) ===')
        for q, f, path, score in gaps[:40]:
            near = f'   ближайшая: {path} ({score:.0%})' if path else ''
            print(f'  {f:>8}  {q}{near}')
        if len(gaps) > 40:
            print(f'  … и ещё {len(gaps) - 40}')

        brands = [os.path.basename(p)[:-5] for p in glob.glob(os.path.join(ROOT, 'brands', '*.html'))]
        brands = [b for b in brands if b != 'index']
        hits = Counter()
        for q, f, _, _ in gaps:
            n = norm(q)
            for b in brands:
                if norm(b) in n:
                    hits[b] += f or 1
        if hits:
            print('\n=== непокрытое по брендам (вес = сумма частотности) ===')
            for b, w in hits.most_common(15):
                print(f'  {w:>8}  {b}')

    if args.csv:
        with open(args.csv, 'w', encoding='utf-8-sig', newline='') as fh:
            w = csv.writer(fh)
            w.writerow(['запрос', 'частотность', 'ближайшая страница', 'совпадение'])
            for q, f, path, score in gaps:
                w.writerow([q, f, path or '', f'{score:.2f}'])
        print(f'\nнепокрытые запросы выгружены: {args.csv}')


if __name__ == '__main__':
    main()
