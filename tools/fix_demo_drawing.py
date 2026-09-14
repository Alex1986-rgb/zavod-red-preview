#!/usr/bin/env python3
"""Убирает с карточек ссылки на удалённый демо-чертёж assets/drawings/zr-demo-drawing.png.

Файл удалён коммитом fd82c1c7e6 («Исправленные чертежи (PNG) + лайтбокс»), когда демо-заглушку
заменили на реальные чертежи. Но 1389 карточек моделей, для которых реального чертежа нет,
остались ссылаться на удалённый файл: битая картинка в миниатюре галереи, битая картинка
в секции «Чертёж» и подстановка битой картинки в главное фото по клику на миниатюру.

Реального чертежа для этих моделей в assets/drawings/ нет (проверено по префиксу zr-<код>-),
поэтому картинка убирается, а секция «Чертёж» остаётся с текстом и кнопкой запроса.

Скрипт идемпотентен: повторный запуск ничего не меняет.

Запуск:  python3 tools/fix_demo_drawing.py [--dry]
"""
import glob
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
FAMILIES = ('reduktor', 'motor-reduktor-zr', 'ispolnenie', 'tiporazmer', 'analog')
DRY = '--dry' in sys.argv

# Миниатюра «Чертёж» в галерее вместе с вложенной картинкой.
THUMB = re.compile(
    r'<button type="button" data-src="\.\./assets/drawings/zr-demo-drawing\.png"[^>]*>'
    r'\s*<img src="\.\./assets/drawings/zr-demo-drawing\.png"[^>]*>\s*</button>'
)
# Крупная картинка в секции #chertezh.
BIG = re.compile(r'<img src="\.\./assets/drawings/zr-demo-drawing\.png"[^>]*>')
# Обещание картинки в лид-абзаце секции «Чертёж».
LEAD = ' Ниже — пример габаритного чертежа.'
# Подпись под галереей упоминает чертёж, которого больше нет.
NOTES = (
    ('Фото типового исполнения · габаритный чертёж — пример, точный чертёж вышлем с КП',
     'Фото типового исполнения · точный чертёж исполнения вышлем с КП'),
    ('Фото типового исполнения · габаритный чертёж — пример оформления, точный чертёж вышлем с КП',
     'Фото типового исполнения · точный чертёж исполнения вышлем с КП'),
    ('Фото типового исполнения · габаритный чертёж — пример оформления, точный чертёж исполнения вышлем с КП',
     'Фото типового исполнения · точный чертёж исполнения вышлем с КП'),
)


def fix(text):
    text = THUMB.sub('', text)
    text = BIG.sub('', text)
    text = text.replace(LEAD, '')
    for old, new in NOTES:
        text = text.replace(old, new)
    return text


def main():
    changed = 0
    seen = 0
    for family in FAMILIES:
        for path in glob.glob(os.path.join(ROOT, family, '*.html')):
            with open(path, encoding='utf-8') as f:
                text = f.read()
            if 'zr-demo-drawing' not in text:
                continue
            seen += 1
            new = fix(text)
            if new == text:
                continue
            if not DRY:
                with open(path, 'w', encoding='utf-8') as f:
                    f.write(new)
            changed += 1
    print(f'карточек со ссылкой на демо-чертёж: {seen}')
    print(f'{"будет исправлено" if DRY else "исправлено"}: {changed}')


if __name__ == '__main__':
    main()
