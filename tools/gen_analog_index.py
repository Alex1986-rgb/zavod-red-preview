#!/usr/bin/env python3
"""Индекс страниц /analog/ для калькулятора подбора.

Зачем: в таблице подбора кнопка «Заказать» вела на /analog/ только для SEW (у него был
захардкоженный slug), а для остальных брендов — на обезличенную карточку ZR. Заказчик
требует, чтобы карточка везде была брендовой.

Построить слаг на лету нельзя: имена файлов вида
    <бренд>-<модель>-i<передаточное>-<мощность>kvt[-N].html
используют ЦЕЛЫЕ передаточные (i10, i344), а в базе подбора они дробные (7.5, 344.2),
плюс у дублей появляется суффикс -1/-2. Прямая склейка попадала лишь в треть случаев.

Поэтому раскладываем реальные файлы по (бренд → модель → список [i, кВт, суффикс])
и отдаём калькулятору, который подбирает ближайшую пару. Файлы пишутся по одному на бренд,
чтобы страница грузила только выбранный (самый крупный — nord, ~13k позиций).

Запуск: python3 tools/gen_analog_index.py
"""
import json, os, re, collections

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC = os.path.join(ROOT, 'analog')
DST = os.path.join(ROOT, 'assets', 'analog-idx')

# <бренд>-<модель>-i<передаточное>-<мощность>kvt[-N]
# Дробная часть отделяется и дефисом, и подчёркиванием, причём В ОБОИХ числах:
# «i7-96-1-5kvt» — это i=7,96 при 1,5 кВт, а не i=7 при 96-1-5 кВт. Поэтому оба числа
# описаны одним строгим шаблоном, иначе дробь передаточного утекает в мощность
# (на этом первый вариант разбора потерял 28 тысяч страниц из 74).
#
# Вторая ловушка — двусмысленность: «i344-0-12kvt» читается и как «i=344,0 при 12 кВт»,
# и как «i=344 при 0,12 кВт». Верно второе. Поэтому дробная часть ПЕРЕДАТОЧНОГО ленивая,
# а мощности — жадная: разбор сначала пробует отдать дробь мощности и отступает только
# там, где иначе не сходится («i7-96-1-5kvt» → i=7,96 при 1,5 кВт).
NUMP = r'\d+(?:[-_]\d+)?'
NUML = r'\d+(?:[-_]\d+)??'
RX = re.compile(rf'^([a-z]+)-(.+?)-i({NUML})-({NUMP})kvt(?:-(\d+))?$')


def num(s):
    """«0-12» → 0.12, «5_5» → 5.5, «344» → 344.0"""
    return float(s.replace('-', '.', 1).replace('_', '.'))


def main():
    idx = collections.defaultdict(lambda: collections.defaultdict(list))
    total = skipped = 0
    for name in os.listdir(SRC):
        if not name.endswith('.html') or name == 'index.html':
            continue
        m = RX.match(name[:-5])
        if not m:
            skipped += 1
            continue
        brand, model, ratio, power, dup = m.groups()
        try:
            i_val, kw_val = num(ratio), num(power)
        except ValueError:
            skipped += 1
            continue
        # Храним «хвост» имени (всё после модели), а не собираем слаг обратно из чисел:
        # десятичный разделитель в именах то дефис, то подчёркивание («i88-5_5kvt»),
        # и у дублей есть суффикс -1/-2. Обратная сборка неизбежно промахнётся.
        idx[brand][model].append([i_val, kw_val, name[len(brand) + len(model) + 1:-5]])
        total += 1

    os.makedirs(DST, exist_ok=True)
    for old in os.listdir(DST):
        os.remove(os.path.join(DST, old))

    manifest = {}
    for brand, models in idx.items():
        for m in models.values():
            m.sort()
        path = os.path.join(DST, brand + '.json')
        with open(path, 'w', encoding='utf-8') as f:
            json.dump(models, f, ensure_ascii=False, separators=(',', ':'))
        manifest[brand] = sum(len(v) for v in models.values())

    with open(os.path.join(DST, 'index.json'), 'w', encoding='utf-8') as f:
        json.dump(manifest, f, ensure_ascii=False, separators=(',', ':'))

    print(f'проиндексировано {total} страниц, пропущено {skipped} (нестандартное имя)')
    for b, n in sorted(manifest.items(), key=lambda x: -x[1]):
        kb = os.path.getsize(os.path.join(DST, b + '.json')) // 1024
        print(f'  {b:14} {n:6} позиций  {kb:5} КБ')


if __name__ == '__main__':
    main()
