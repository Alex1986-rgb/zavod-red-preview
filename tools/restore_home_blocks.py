#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Возвращает на главную блоки, снятые 17 июля, не откатывая файл целиком.

Что было. 17.07.2026 в 06:38 коммит bad4b1238c20 «Компактная главная (просьба
заказчика №5): −12 блоков, контент в разделы, подменю шапки» снял с index.html
бегущую ленту брендов и девять секций. Разметку убрали, а стили оставили: в файле
до сих пор лежат живые правила .brand-ticker, .bt-track, @keyframes bt-scroll,
.team-grid, .cert-grid, .gal — без единого элемента, который бы их использовал.

Почему не откатом файла. После 17 июля в index.html легло два месяца работы:
подменю шапки, возвращённый блок с видео, ленивая загрузка базы подбора, сброс
кэша по хэшу содержимого. Откат на июльскую ревизию стёр бы всё это. Поэтому
блоки вырезаются из ревизии 0b00ce839702 (состояние за 8 минут до урезания) и
вставляются в текущую главную на исходные места.

Порядок восстанавливается точно исходный:
  [лента] → situation → catalog → ceny-teaser → calc → oem → steps → compare →
  uslugi → about → video → certs → reviews → team → selector → geo → faq → contacts

Кроме разметки возвращается недостающий JS: массив TEAM с карточками сотрудников,
заполнение сетки #teamGrid и её стрелки прокрутки. Всё остальное — стили, обработчик
клика по видео, скрипты галереи и сертификатов — в файле уже есть.

Заодно чинится обработчик .vfac: он создавал видео без класса vfac-sq, из-за чего
квадратные ролики в блоке команды растянулись бы по-другому, чем задумано.

Скрипт идемпотентен: уже вставленные блоки пропускаются.

Запуск:  python3 tools/restore_home_blocks.py [--dry]
"""
import argparse
import os
import re
import subprocess

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PAGE = os.path.join(ROOT, 'index.html')
SRC = '0b00ce839702'                      # состояние главной за 8 минут до урезания

# что вставляем и перед какой существующей секцией
PLAN = (
    ('situation', ['brand-ticker']),
    ('steps',     ['oem']),
    ('video',     ['compare', 'uslugi', 'about']),
    ('faq',       ['certs', 'reviews', 'team', 'selector', 'geo']),
)


def old_page():
    out = subprocess.run(['git', '-C', ROOT, 'show', f'{SRC}:index.html'],
                         capture_output=True)
    if out.returncode:
        raise SystemExit(f'не достать ревизию {SRC}')
    return out.stdout.decode('utf-8', 'ignore')


def grab_section(text, key):
    """Секция целиком, с учётом вложенных <section>."""
    if key == 'brand-ticker':
        i = text.find('class="brand-ticker"')
        start = text.rfind('<section', 0, i)
    else:
        m = re.search(r'<section[^>]*id="%s"' % re.escape(key), text)
        if not m:
            return None
        start = m.start()
    if start < 0:
        return None
    depth = 0
    for t in re.finditer(r'</?section\b', text[start:]):
        depth += 1 if t.group(0) == '<section' else -1
        if depth == 0:
            return text[start:start + t.end() + 1]
    return None


def team_js(text):
    """Массив TEAM и заполнение сетки — до начала следующей самостоятельной конструкции."""
    i = text.find('const TEAM=[')
    seg = text[i:text.find('</script>', i)]
    m = re.search(r'\n\s*(?=\(function\(\)|document\.querySelectorAll|const [A-Z])', seg[200:])
    return seg[:200 + m.start()] if m else seg


def carousel_js(text):
    k = text.find("var tr=document.getElementById('teamGrid')")
    s = text.rfind('<script', 0, k)
    e = text.find('</script>', k) + len('</script>')
    return text[s:e]


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--dry', action='store_true')
    args = ap.parse_args()

    old = old_page()
    with open(PAGE, encoding='utf-8') as f:
        page = f.read()

    added, skipped = [], []
    for anchor, keys in PLAN:
        m = re.search(r'<section[^>]*id="%s"' % anchor, page)
        if not m:
            raise SystemExit(f'не нашёл якорь id="{anchor}" в текущей главной')
        chunk = ''
        for key in keys:
            marker = ('class="brand-ticker"' if key == 'brand-ticker'
                      else f'id="{key}"')
            if marker in page or marker in chunk:
                skipped.append(key)
                continue
            block = grab_section(old, key)
            if block is None:
                raise SystemExit(f'не вырезался блок {key}')
            chunk += block + '\n'
            added.append((key, len(block)))
        if chunk:
            page = page[:m.start()] + chunk + page[m.start():]

    # недостающий JS: сначала данные команды, следом карусель — обе части должны
    # отработать раньше, чем навешивается обработчик клика по видео, иначе карточки
    # команды вставятся уже после него и остались бы без обработчика
    js_added = []
    if 'const TEAM=[' not in page:
        anchor = page.find("<script>document.querySelectorAll('.vfac')")
        if anchor < 0:
            anchor = page.rfind('</body>')
        block = ('<script>\n' + team_js(old).strip() + '\n</script>\n'
                 + carousel_js(old) + '\n')
        page = page[:anchor] + block + page[anchor:]
        js_added.append(f'TEAM + карусель ({len(block)} б)')

    # квадратные ролики команды: вернуть классу vfac-sq его роль
    old_v = "v.className = 'vfac-video'; v.setAttribute('preload','auto');"
    new_v = ("v.className = 'vfac-video' + (f.classList.contains('vfac-sq') ? ' vfac-sq' : '');"
             " v.setAttribute('preload','auto');")
    if old_v in page:
        page = page.replace(old_v, new_v, 1)
        js_added.append('обработчик .vfac: класс vfac-sq')

    print(f'{"будет вставлено" if args.dry else "вставлено"} блоков: {len(added)}')
    for k, n in added:
        print(f'   {k:14} {n:6} б')
    if skipped:
        print('уже на месте, пропущены:', ', '.join(skipped))
    for j in js_added:
        print('  JS:', j)
    print(f'размер главной: было {os.path.getsize(PAGE)} б, стало {len(page.encode())} б')

    if not args.dry:
        with open(PAGE, 'w', encoding='utf-8') as f:
            f.write(page)


if __name__ == '__main__':
    main()
