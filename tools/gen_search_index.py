#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Генератор assets/search-index.json для быстрого поиска в шапке (assets/modal.js).

Зачем: старый индекс был ГРУППОВЫМ (104 группы по нашей марке EVL/ZR), одна группа
несла до 23 брендов импорта. Адресовать конкретную позицию «SEW R 107» было нечем,
и в индексе вообще не было слагов — поэтому подсказка всегда вела на /podbor?q=<наш ZR>
и теряла бренд, с которого пришёл пользователь.

Формат на выходе (обратно совместимый, старые ключи не удаляются):
  t : [названия типов]        — как было
  g : [групповые записи]      — как было, 1:1 из текущего индекса.
                                Нужны, чтобы поиск по НАШИМ маркам (ZR 603, ПР 4110, МР…)
                                продолжал работать: этих марок нет в import-catalog.json.
  i : [позиции импорта]       — НОВОЕ, по одной записи на карточку import-catalog.json.
                                Поля: b бренд, m модель(и), z наш ZR, u слаг /analog/<u>,
                                r слаг /reduktor/<r>, t тип, pw мощность, i передаточное.

Важно про дубли: бренды импорта присутствуют и в g, и в i. Разводит их modal.js —
при токенизации g он пропускает бренды, которые есть в i, чтобы брендовый запрос
попадал в адресную запись импорта, а не в обезличенную группу.

Запуск (идемпотентен, можно гонять сколько угодно раз):
    python3 tools/gen_search_index.py
"""

import json
import os
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CATALOG = os.path.join(ROOT, 'assets', 'import-catalog.json')
INDEX = os.path.join(ROOT, 'assets', 'search-index.json')

# Поля карточки импорта, которые кладём в индекс. Всё остальное (c, im) поиску
# не нужно: страну не ищут, картинка выводится по типу t через карту в modal.js.
FIELDS = ('b', 'm', 'z', 'u', 'r', 't', 'pw', 'i')


def load_json(path):
    with open(path, 'r', encoding='utf-8') as f:
        return json.load(f)


def build_import_records(cards):
    """Карточка import-catalog → компактная запись индекса. Порядок полей стабилен,
    чтобы повторный прогон давал побайтово тот же файл (иначе шумит git diff)."""
    out = []
    for c in cards:
        rec = {}
        for k in FIELDS:
            v = c.get(k)
            # t=0 (червячный) — валидный тип, поэтому проверяем на None/'' явно,
            # а не на ложность значения.
            if v is None or v == '':
                continue
            rec[k] = v
        if not rec.get('b') or not rec.get('m'):
            continue
        out.append(rec)
    return out


def main():
    if not os.path.exists(CATALOG):
        sys.exit('нет %s' % CATALOG)
    if not os.path.exists(INDEX):
        sys.exit('нет %s — из него берём t и g (наши марки ZR/ПР/МР)' % INDEX)

    catalog = load_json(CATALOG)
    cards = catalog.get('cards') or []
    old = load_json(INDEX)

    types = old.get('t') or catalog.get('t') or []
    groups = old.get('g') or []
    if not groups:
        sys.exit('в текущем индексе пустой g — потеряем поиск по нашим маркам, отбой')

    imports = build_import_records(cards)
    if not imports:
        sys.exit('из каталога не собралось ни одной записи — отбой')

    data = {'t': types, 'g': groups, 'i': imports}
    with open(INDEX, 'w', encoding='utf-8') as f:
        json.dump(data, f, ensure_ascii=False, separators=(',', ':'))
        f.write('\n')

    brands = sorted({r['b'] for r in imports})
    with_u = sum(1 for r in imports if r.get('u'))
    print('search-index.json: групп %d, позиций импорта %d (брендов %d), со слагом /analog/ %d, только /reduktor/ %d'
          % (len(groups), len(imports), len(brands), with_u, len(imports) - with_u))


if __name__ == '__main__':
    main()
