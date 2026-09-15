#!/usr/bin/env python3
"""Приводит ключи сброса кэша (?v=) к хэшу содержимого ассета — одинаково на всех страницах.

Зачем: .htaccess отдаёт CSS/JS с кэшем на месяц, поэтому вернувшийся посетитель получает файл
из кэша, пока не сменится ключ ?v=. Ключи проставлялись вручную, и разные разделы разъехались:
генерируемые карточки получали свежий ключ, а blog/glossary/catalog/brands оставались на старом.
Хуже того, assets/inner.js менялся 3–4 августа (лайтбокс и галерея картинок), а ключ на 95 097
страницах остался июльским — правки по картинкам просто не доезжали до вернувшихся посетителей.

Ключ считается от содержимого файла, поэтому расходиться больше нечему: файл поменялся — ключ
сменился на всех страницах сразу, файл не менялся — ключ остался прежним.

Та же беда была внутри самих скриптов: assets/inner.js подгружает fav.js, modal.js — поисковый
индекс, podbor.js — базу подбора, и ключ ?v= у этих трёх ссылок был вписан руками. Данные
(.json) .htaccess отдаёт с кэшем на ГОД, поэтому вернувшийся посетитель мог годами видеть
старую базу подбора. Теперь такие ссылки внутри assets/*.js обновляются тем же хэшем.

Запуск:  python3 tools/bump_asset_versions.py [--dry]
"""
import glob
import hashlib
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DRY = '--dry' in sys.argv

REF = re.compile(r'(?P<attr>href|src)="(?P<path>[^"]+?\.(?:css|js|json))\?v=(?P<ver>[A-Za-z0-9_.-]+)"')
# Ссылка на ассет в одинарных кавычках внутри JS: '/assets/fav.js?v=…', 'assets/podbor-data.json?v=…'
JS_REF = re.compile(r"'(?P<path>[^']*?/?assets/[^']+?\.(?:css|js|json))\?v=(?P<ver>[A-Za-z0-9_.-]+)'")
_hash_cache = {}


def asset_hash(page_path, ref_path):
    """Хэш файла ассета, на который ссылается страница. None — если файл не найден."""
    if ref_path in _hash_cache:
        return _hash_cache[ref_path]
    if ref_path.startswith('/'):
        disk = os.path.join(ROOT, ref_path.lstrip('/'))
    else:
        disk = os.path.normpath(os.path.join(os.path.dirname(page_path), ref_path))
    digest = None
    if os.path.isfile(disk):
        with open(disk, 'rb') as f:
            digest = hashlib.md5(f.read()).hexdigest()[:8]
    _hash_cache[ref_path] = digest
    return digest


def main():
    pages = [p for p in glob.glob(os.path.join(ROOT, '**', '*.html'), recursive=True)
             if not p.startswith(os.path.join(ROOT, 'dist') + os.sep)]
    changed = 0
    unknown = set()

    for page in pages:
        with open(page, encoding='utf-8', errors='ignore') as f:
            text = f.read()

        def sub(m):
            digest = asset_hash(page, m.group('path'))
            if digest is None:
                unknown.add(m.group('path'))
                return m.group(0)
            return f'{m.group("attr")}="{m.group("path")}?v={digest}"'

        new = REF.sub(sub, text)
        if new != text:
            changed += 1
            if not DRY:
                with open(page, 'w', encoding='utf-8') as f:
                    f.write(new)

    print(f'страниц просмотрено: {len(pages)}')
    print(f'{"будет обновлено" if DRY else "обновлено"}: {changed}')

    # Ссылки на ассеты, зашитые внутри самих скриптов
    scripts = sorted(glob.glob(os.path.join(ROOT, 'assets', '*.js')))
    js_changed = 0
    for script in scripts:
        with open(script, encoding='utf-8', errors='ignore') as f:
            text = f.read()

        def js_sub(m):
            ref = m.group('path')
            # внутри JS путь пишется от корня сайта, а не от папки скрипта
            digest = asset_hash(os.path.join(ROOT, 'index.html'), '/' + ref.lstrip('/'))
            if digest is None:
                unknown.add(ref)
                return m.group(0)
            return f"'{ref}?v={digest}'"

        new_text = JS_REF.sub(js_sub, text)
        if new_text != text:
            js_changed += 1
            if not DRY:
                with open(script, 'w', encoding='utf-8') as f:
                    f.write(new_text)

    print(f'скриптов просмотрено: {len(scripts)}, '
          f'{"будет обновлено" if DRY else "обновлено"}: {js_changed}')
    if unknown:
        print(f'ассеты не найдены на диске (ключ не тронут): {sorted(unknown)[:10]}')


if __name__ == '__main__':
    main()
