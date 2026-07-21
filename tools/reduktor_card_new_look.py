#!/usr/bin/env python3
"""Приводит карточки /reduktor/ к тому же виду, что и карточки /analog/.

Заказчик: «эти тоже старые, перенеси все карточки по всему сайту в новый вид».
Сравнение показало ровно две недостающие части: плашка-оффер под ценой (.p2-offer)
и блок из четырёх плиток преимуществ (.p2-trust). Остальное — галерея, липкая навигация,
характеристики, доставка, тех.документация, чертёж, FAQ — на карточках ZR уже есть,
плюс свои разделы «Типоразмеры» и «Заменяет импортные приводы», которых у аналогов нет.

Текст оффера пишется про НАШ товар, а не копируется у импортной карточки: там оффер
звучит «поставим оригинал или подберём аналог», здесь так писать нельзя — это и есть
наша продукция. Маркировка ZR берётся из самой страницы, ничего не выдумывается.

Стили .p2-offer/.p2-trust вынесены в assets/inner.css (раньше лежали инлайном только
на страницах аналогов), поэтому разметке достаточно классов.

Скрипт идемпотентен: повторный прогон ничего не меняет.
Запуск: python3 tools/reduktor_card_new_look.py [--dry]
"""
import glob, os, re, sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

SHIELD = ('<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">'
          '<path d="M12 2l7 4v6c0 5-3 8-7 10-4-2-7-5-7-10V6z"/><path d="M9 12l2 2 4-4"/></svg>')

TRUST = ('<div class="p2-trust">'
         '<div><b>Собственное производство</b><span>механообработка, сборка, испытания</span></div>'
         '<div><b>Гарантия 24 месяца</b><span>или переделаем за наш счёт</span></div>'
         '<div><b>Отгрузка от 3 дней</b><span>серийное — со склада</span></div>'
         '<div><b>Инженер за 15 минут</b><span>подбор по шильду, фото, параметрам</span></div>'
         '</div>')


def offer(zr, brands):
    """Плашка под ценой. Если карточка знает, какие импортные приводы заменяет —
    называем их: это главный довод для человека, пришедшего за заменой."""
    tail = ('Заменяет импортные приводы ' + brands + ' без переделки узла — '
            'те же присоединительные и габаритные размеры.') if brands else \
           ('Заменяет импортные приводы без переделки узла — '
            'те же присоединительные и габаритные размеры.')
    return ('<div class="p2-offer">' + SHIELD + '<div>Редуктор <b>' + zr +
            '</b> собственного производства: отгрузка от 3 дней, гарантия 24 месяца. '
            + tail + '</div></div>')


def main():
    dry = '--dry' in sys.argv
    changed = already = skipped = 0
    for path in sorted(glob.glob(os.path.join(ROOT, 'reduktor', '*.html'))):
        h = open(path, encoding='utf-8').read()
        if 'p2-offer' in h and 'p2-trust' in h:
            already += 1
            continue
        h1 = re.search(r'<h1[^>]*>(.*?)</h1>', h, re.S)
        # ищем именно РАЗМЕТКУ: те же имена классов встречаются выше в инлайновом <style>,
        # и поиск по «p2-price-row» без закрывающей скобки цеплялся за CSS
        # до ПЕРВОГО закрывающего тега: «.*?</div></div>» проглатывал и таблицу параметров,
        # и оффер вставал под ней, а не под ценой, как на карточках аналогов
        price = re.search(r'<div class="p2-price-row">(?:(?!</div>).)*</div>', h, re.S)
        # на карточках ZR контейнер кнопок называется p2-cta (у аналогов — p2-cta1)
        cta = re.search(r'<div class="p2-cta">.*?</div>', h, re.S)
        if not (h1 and price and cta):
            skipped += 1
            continue
        title = re.sub(r'<[^>]+>', ' ', h1.group(1))
        m = re.search(r'ZR\s+[\w/]+', title)
        # у планетарных 3МП / МР / МПО маркировки ZR нет — берём их собственное обозначение,
        # иначе 12 карточек этих серий остались бы в старом виде
        if not m:
            m = re.search(r'(?:3МП|МПО|МР)[\w-]*', title)
        if not m:
            skipped += 1
            continue
        zr = m.group(0)
        # бренды берём из блока «Заменяет импортные приводы» — только те, что уже на странице
        chips = re.findall(r'class="imp-rep[^"]*"[^>]*>([^<]{2,30})<', h)
        names = []
        for c in chips:
            n = c.split()[0].strip(' ·,')
            if n and n not in names:
                names.append(n)
        brands = ', '.join(names[:3]) if names else ''

        out = h[:price.end()] + offer(zr, brands) + h[price.end():]
        cta2 = re.search(r'<div class="p2-cta">.*?</div>', out, re.S)
        out = out[:cta2.end()] + TRUST + out[cta2.end():]
        if not dry:
            open(path, 'w', encoding='utf-8').write(out)
        changed += 1

    print(f'обновлено: {changed} | уже в новом виде: {already} | пропущено: {skipped}'
          + (' (пробный прогон)' if dry else ''))


if __name__ == '__main__':
    main()
