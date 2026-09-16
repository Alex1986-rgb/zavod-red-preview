#!/usr/bin/env python3
"""Ставит карточкам /analog/ входящие ссылки: на соседние исполнения, модель и бренд.

Зачем: проверка показала, что на параметрические карточки не ведёт НИ ОДНА внутренняя
ссылка. Ни с главной, ни из каталога, ни с соседней карточки той же модели. Они доступны
только из карты сайта, и Яндекс большую часть из них просто не знает («Страница неизвестна
роботу»). В поиске держится около 4 600 адресов из 95 068.

Блок «Похожие модели» на карточках был и исчез при смене шаблона 29.07. Здесь он
возвращается в виде, осмысленном для параметрической карточки: соседи по той же модели.

Что ставится на каждую параметрическую карточку:
  — до 6 исполнений той же модели с ТЕМ ЖЕ передаточным и другой мощностью;
  — до 6 исполнений с ТОЙ ЖЕ мощностью и другим передаточным;
  — ссылка на карточку модели, если она есть;
  — ссылка на страницу бренда.
Ссылки ведут только на страницы, чей canonical указывает сам на себя: звать на сведённый
дубль — значит гонять робота впустую.

Разметка повторяет блок .pc-rel из карточек ZR. Стиль добавляется в assets/inner.css один
раз, а не инлайном в каждую карточку: инлайн размножил бы полкилобайта по 73 899 файлам.

Скрипт идемпотентен: блок помечен маркером, повторный запуск его заменяет, а не плодит.

Запуск:  python3 tools/analog_interlink.py [--dry] [--brand bauer] [--limit N]
"""
import argparse
import collections
import glob
import html
import os
import re

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
ANALOG = os.path.join(ROOT, 'analog')
SITE = 'https://zavod-red.ru'

TITLE = re.compile(r'<title>([^<]*)</title>')
CANON = re.compile(r'<link rel="canonical" href="([^"]+)"')
# «Bauer BK 20 0,75 кВт i=28,83, 150 Н·м — …»; момент есть не у всех
PARAM = re.compile(r'^(?P<model>.+?)\s+(?P<power>\d+(?:,\d+)?)\s*кВт\s+'
                   r'i=(?P<ratio>\d+(?:,\d+)?)'
                   r'(?:,\s*(?P<torque>\d+(?:,\d+)?)\s*Н·м)?')
ANCHOR = '<section class="section" id="dostavka"'
MARK_OPEN = '<!-- ZR_INTERLINK -->'
MARK_CLOSE = '<!-- /ZR_INTERLINK -->'
BLOCK = re.compile(re.escape(MARK_OPEN) + '.*?' + re.escape(MARK_CLOSE), re.S)

MAX_PER_GROUP = 6


def key_of(name):
    """Ключ модели. На карточке исполнения «Bauer BK 20», на карточке модели «Bauer BK20» —
    пробелы и дефисы расставлены по-разному, поэтому сличаем без них."""
    return re.sub(r'[\s\-—]+', '', name).lower()


def num(s):
    """«28,83» → 28.83, для сортировки."""
    try:
        return float(s.replace(',', '.'))
    except ValueError:
        return 0.0


def brand_slugs():
    out = []
    for p in glob.glob(os.path.join(ROOT, 'brands', '*.html')):
        name = os.path.basename(p)[:-5]
        if name != 'index':
            out.append(name)
    return sorted(out, key=len, reverse=True)      # длинные первыми: watt-drive раньше watt


def head_of(path, n=6000):
    with open(path, encoding='utf-8', errors='ignore') as f:
        return f.read(n)


def build_index(brands):
    """slug → сведения о карточке. Читаем только начало файла: там title и canonical."""
    params, models = {}, {}
    for path in sorted(glob.glob(os.path.join(ANALOG, '*.html'))):
        slug = os.path.basename(path)[:-5]
        if slug == 'index':
            continue
        head = head_of(path)
        t = TITLE.search(head)
        c = CANON.search(head)
        if not t:
            continue
        self_canon = bool(c) and c.group(1).rstrip('/') == f'{SITE}/analog/{slug}'
        title = html.unescape(t.group(1))
        brand = next((b for b in brands if slug.startswith(b + '-') or slug == b), None)
        m = PARAM.match(title)
        if m:
            params[slug] = {
                'model': m.group('model').strip(),
                'power': m.group('power'),
                'ratio': m.group('ratio'),
                'brand': brand,
                'ok': self_canon,
            }
        else:
            # карточка уровня модели: «Varvel RV63 купить — …»
            name = re.split(r'\s+(?:купить|—)', title)[0].strip()
            models[key_of(name)] = {'slug': slug, 'ok': self_canon}
    return params, models


def card(url, big, small):
    return (f'<a href="{url}"><b>{html.escape(big)}</b>'
            f'<span>{html.escape(small)}</span></a>')


def make_block(slug, info, by_model, models):
    same_model = by_model.get(info['model'], [])
    # соседи-цели: только самоканоникальные и не мы сами
    def pick(pred, key):
        out = [s for s in same_model
               if s != slug and params_ok(s) and pred(s)]
        out.sort(key=key)
        return out[:MAX_PER_GROUP]

    def params_ok(s):
        return INDEX[s]['ok']

    by_power = pick(lambda s: INDEX[s]['ratio'] == info['ratio'],
                    lambda s: num(INDEX[s]['power']))
    by_ratio = pick(lambda s: INDEX[s]['power'] == info['power'],
                    lambda s: num(INDEX[s]['ratio']))
    if not by_power and not by_ratio:
        return None

    parts = []
    if by_power:
        parts.append('<h3 class="il-h">Та же редукция i=' + html.escape(info['ratio'])
                     + ', другая мощность</h3><div class="pc-rel">'
                     + ''.join(card(f'/analog/{s}', f'{INDEX[s]["power"]} кВт',
                                    f'i={INDEX[s]["ratio"]}') for s in by_power)
                     + '</div>')
    if by_ratio:
        parts.append('<h3 class="il-h">Та же мощность ' + html.escape(info['power'])
                     + ' кВт, другая редукция</h3><div class="pc-rel">'
                     + ''.join(card(f'/analog/{s}', f'i={INDEX[s]["ratio"]}',
                                    f'{INDEX[s]["power"]} кВт') for s in by_ratio)
                     + '</div>')

    tail = []
    mod = models.get(key_of(info['model']))
    if mod and mod['ok'] and mod['slug'] != slug:
        tail.append(f'<a class="btn ghost" href="/analog/{mod["slug"]}">'
                    f'Все исполнения {html.escape(info["model"])} →</a>')
    if info['brand']:
        tail.append(f'<a class="btn ghost" href="/brands/{info["brand"]}">'
                    f'Все редукторы бренда →</a>')
    if tail:
        parts.append('<p class="il-more">' + ' '.join(tail) + '</p>')

    return (MARK_OPEN
            + '<section class="section" style="padding-top:0"><div class="wrap">'
            + f'<h2 class="sec-h">Другие исполнения {html.escape(info["model"])}</h2>'
            + ''.join(parts)
            + '</div></section>' + MARK_CLOSE)


INDEX = {}


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--dry', action='store_true')
    ap.add_argument('--brand', help='только один бренд')
    ap.add_argument('--limit', type=int)
    args = ap.parse_args()

    brands = brand_slugs()
    global INDEX
    INDEX, models = build_index(brands)
    by_model = collections.defaultdict(list)
    for slug, info in INDEX.items():
        by_model[info['model']].append(slug)

    targets = sorted(INDEX)
    if args.brand:
        targets = [s for s in targets if INDEX[s]['brand'] == args.brand]
    if args.limit:
        targets = targets[:args.limit]

    changed = skipped = links = 0
    for slug in targets:
        block = make_block(slug, INDEX[slug], by_model, models)
        if not block:
            skipped += 1
            continue
        path = os.path.join(ANALOG, slug + '.html')
        with open(path, encoding='utf-8', errors='ignore') as f:
            text = f.read()
        new = BLOCK.sub('', text)                      # снять прошлую версию блока
        if ANCHOR not in new:
            skipped += 1
            continue
        new = new.replace(ANCHOR, block + ANCHOR, 1)
        if new == text:
            continue
        changed += 1
        links += block.count('<a href=')
        if not args.dry:
            with open(path, 'w', encoding='utf-8') as f:
                f.write(new)

    print(f'карточек в индексе: {len(INDEX)}, моделей: {len(by_model)}')
    print(f'{"будет обновлено" if args.dry else "обновлено"} карточек: {changed}, '
          f'пропущено (нет соседей или якоря): {skipped}')
    print(f'проставлено ссылок: {links}')


if __name__ == '__main__':
    main()
