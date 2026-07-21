#!/usr/bin/env python3
"""Приводит «тонкие» карточки к тому же виду, что /reduktor/ и /analog/.

Три семейства страниц остались в старом виде — их не задел ни один прежний проход:
  ispolnenie/        8723 — конкретное исполнение (мощность + передаточное)
  motor-reduktor-zr/ 8398 — мотор-редуктор под маркой ZR
  tiporazmer/        2238 — типоразмер с фиксированным передаточным

Проверка 120 случайных страниц: фото и ссылка на карточку ZR есть у всех, а плашки-оффера
и блока преимуществ нет НИ У ОДНОЙ. Это ровно тот же разрыв, что был у /reduktor/.

Вставка идёт между строкой цены и кнопками — там же, где на остальных карточках.
Текст оффера собирается из данных самой страницы (маркировка, мощность, момент);
ничего не выдумывается: если маркировку из H1 не удалось прочитать, страница пропускается.

Стили .p2-offer/.p2-trust лежат в assets/inner.css (вынесены туда ранее), эти страницы
её подключают — поэтому достаточно разметки.

Скрипт идемпотентен: повторный прогон ничего не меняет.
Запуск: python3 tools/thin_cards_new_look.py [--dry]
"""
import glob, os, re, sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DIRS = ['ispolnenie', 'motor-reduktor-zr', 'tiporazmer']

SHIELD = ('<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">'
          '<path d="M12 2l7 4v6c0 5-3 8-7 10-4-2-7-5-7-10V6z"/><path d="M9 12l2 2 4-4"/></svg>')

TRUST = ('<div class="p2-trust">'
         '<div><b>Собственное производство</b><span>механообработка, сборка, испытания</span></div>'
         '<div><b>Гарантия 24 месяца</b><span>или переделаем за наш счёт</span></div>'
         '<div><b>Отгрузка от 3 дней</b><span>серийное — со склада</span></div>'
         '<div><b>Инженер за 15 минут</b><span>подбор по шильду, фото, параметрам</span></div>'
         '</div>')

# строка цены: «по запросу · точная цена и КП в течение дня» — общая для всех трёх семейств
PRICE = re.compile(r'<div style="margin-top:16px;font-size:23px[^>]*>.*?</div>', re.S)


def offer(mark, spec):
    """Плашка под ценой. Параметры берутся из самой страницы — они и есть главный
    аргумент для того, кто ищет конкретное исполнение, а не модель вообще."""
    tail = (' — ' + spec) if spec else ''
    return ('<div class="p2-offer">' + SHIELD + '<div>Редуктор <b>' + mark +
            '</b>' + tail + '. Собственное производство: отгрузка от 3 дней, гарантия '
            '24 месяца. Заменяет импортные приводы без переделки узла — те же '
            'присоединительные и габаритные размеры.</div></div>')


def main():
    dry = '--dry' in sys.argv
    total = {}
    for d in DIRS:
        changed = already = skipped = 0
        for path in sorted(glob.glob(os.path.join(ROOT, d, '*.html'))):
            if os.path.basename(path) == 'index.html':
                skipped += 1
                continue
            h = open(path, encoding='utf-8').read()
            if 'p2-offer' in h and 'p2-trust' in h:
                already += 1
                continue
            h1 = re.search(r'<h1[^>]*>(.*?)</h1>', h, re.S)
            price = PRICE.search(h)
            if not (h1 and price):
                skipped += 1
                continue
            title = re.sub(r'<[^>]+>', ' ', h1.group(1))
            m = re.search(r'ZR\s+[\wА-я/.-]+', title)
            if not m:
                skipped += 1
                continue
            mark = m.group(0).strip()
            # хвост заголовка после тире — это и есть параметры исполнения
            rest = title.split('—', 1)[1].strip() if '—' in title else ''
            rest = re.sub(r'\s+', ' ', rest).strip(' .,')
            out = h[:price.end()] + offer(mark, rest) + h[price.end():]
            # блок преимуществ — после кнопок, как на остальных карточках
            cta = re.search(r'<div style="display:flex;flex-wrap:wrap;gap:10px;margin[^>]*>.*?</div>',
                            out[price.end():], re.S)
            if cta:
                p = price.end() + cta.end()
                out = out[:p] + TRUST + out[p:]
            else:
                out = out[:price.end() + len(offer(mark, rest))] + TRUST + \
                      out[price.end() + len(offer(mark, rest)):]
            if not dry:
                open(path, 'w', encoding='utf-8').write(out)
            changed += 1
        total[d] = (changed, already, skipped)

    for d, (c, a, s) in total.items():
        print(f'  {d:20} обновлено {c:5} | уже в новом виде {a:5} | пропущено {s}')
    print(('пробный прогон, файлы не тронуты' if dry else 'готово')
          + f' | всего обновлено: {sum(c for c, _, _ in total.values())}')


if __name__ == '__main__':
    main()
