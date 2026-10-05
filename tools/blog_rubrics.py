# Нормализация рубрик блога: 33 исторических ярлыка eyebrow → 8 рубрик со слагами.
# Используется генератором статей и индексом /blog/ (фильтр по data-cat).
RUBRICS = {
    'podbor':       'Подбор и расчёт',
    'analogi':      'Аналоги и импортозамещение',
    'ekspluataciya':'Эксплуатация и ремонт',
    'ceny':         'Цены и поставка',
    'spravochnik':  'Справочник',
    'sravnenie':    'Сравнение',
    'otrasli':      'Решения для отраслей',
    'keysy':        'Кейсы',
}
_MAP = {
    'Подбор':'podbor','Подбор под задачу':'podbor','Отраслевой подбор':'podbor','Выбор':'podbor','Выбор типа':'podbor','Расчёты':'podbor',
    'Аналоги импорта':'analogi','Аналоги':'analogi','Импортозамещение':'analogi','Сравнение брендов':'analogi',
    'Эксплуатация':'ekspluataciya','Ремонт и обслуживание':'ekspluataciya','Сервис':'ekspluataciya','Диагностика':'ekspluataciya','Запчасти':'ekspluataciya',
    'Цены':'ceny','Поставка':'ceny','Цены и поставка':'ceny','ОЕМ':'ceny','OEM':'ceny',
    'Матчасть':'spravochnik','Справочник':'spravochnik','Теория':'spravochnik','Устройство':'spravochnik','Маркировки и обозначения':'spravochnik','Исполнения':'spravochnik','FAQ':'spravochnik','Обзор':'spravochnik',
    'Сравнение':'sravnenie',
    'Решения':'otrasli','Решения для отрасли':'otrasli','Редукторы для отрасли':'otrasli','Отрасль':'otrasli',
    'Кейс':'keysy','Кейсы':'keysy',
}
def rubric(label):
    """→ (slug, имя рубрики). Незнакомый ярлык — в «Справочник»."""
    label = (label or '').strip()
    for slug, name in RUBRICS.items():
        if label == name: return slug, name
    slug = _MAP.get(label, 'spravochnik')
    return slug, RUBRICS[slug]
