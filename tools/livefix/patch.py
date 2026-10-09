#!/usr/bin/env python3
"""Точечные правки вёрстки боевого сайта (по итогам проверки site-check live1/live2, 09.10.2026).

Запуск: python3 patch.py <папка с копиями боевых файлов assets/>. Каждая правка ищет точный
якорь ровно один раз; если якорь не найден — падает, ничего не записав. Повторный запуск
безопасен: правка с меткой LIVEFIX-0910 уже применена — пропускается.
"""
import sys, pathlib

ROOT = pathlib.Path(sys.argv[1])
MARK = 'LIVEFIX-0910'
plan = []

def rep(rel, old, new):
    p = ROOT / rel
    s = p.read_text(encoding='utf-8')
    if MARK in s and new in s:
        print(f'{rel}: уже исправлено'); return
    n = s.count(old)
    if n != 1:
        sys.exit(f'СТОП: {rel}: якорь найден {n} раз(а): {old[:80]!r}')
    plan.append((p, s.replace(old, new)))
    print(f'{rel}: правка подготовлена')

# 1. Нижняя мобильная панель: кнопки не сжимались (nowrap + min-width:auto) и «Получить расчёт»
#    вылезала за экран на 8px (на 360px — ещё больше).
rep('assets/modal.js',
    "+'.zr-mbar .zrmb-ai{flex:0 0 56px;font-weight:800}'",
    "+'.zr-mbar .zrmb-ai{flex:0 0 56px;font-weight:800}'\n"
    "   /* " + MARK + ": кнопки панели сжимаются по ширине экрана, «Получить расчёт» не вылезает за край */\n"
    "   +'.zr-mbar a{min-width:0;overflow:hidden;text-overflow:ellipsis}.zr-mbar .zrmb-lead{flex:1.35 1 0}'\n"
    "   +'@media(max-width:420px){.zr-mbar{gap:6px;padding-left:8px;padding-right:8px}.zr-mbar a{padding:12px 6px;font-size:14px;gap:6px}.zr-mbar .zrmb-ai{flex:0 0 46px}}'\n"
    "   +'@media(max-width:360px){.zr-mbar svg{display:none}.zr-mbar a{font-size:13px}}'")

# 2. Хлебные крошки: padding-шортхенд обнулял боковые отступы .wrap — текст лип к краю экрана.
rep('assets/inner.css',
    '.crumbs{padding:16px 0 0;font-size:13px;color:var(--muted2)}',
    '.crumbs{padding-top:16px;font-size:13px;color:var(--muted2)} /* ' + MARK + ': было padding:16px 0 0 — обнуляло отступы .wrap */')

# 3–5. Глобальные правки в конец hdr.css (подключён на всех страницах и идёт последним).
HDR_TAIL = '''
/* ===== ''' + MARK + ''': вёрстка на телефонах (проверка 267 страниц, 09.10.2026) ===== */
/* Таблицы до трёх колонок на телефоне помещаются в экран и переносят текст: раньше
   min-width:460–520px уводил за край колонку «Цена» на /ceny и правую колонку
   «Комплектации» — без подсказки, что таблицу надо листать вбок. Широкие таблицы
   (4+ колонки) по-прежнему листаются внутри себя. */
@media (max-width:560px){
  table.spec:not(:has(tr>*:nth-child(4))) tbody,table.spec:not(:has(tr>*:nth-child(4))) thead,
  table.xref:not(:has(tr>*:nth-child(4))) tbody,table.xref:not(:has(tr>*:nth-child(4))) thead,
  table.tbl:not(:has(tr>*:nth-child(4))) tbody,table.tbl:not(:has(tr>*:nth-child(4))) thead{min-width:0}
  table.spec:not(:has(tr>*:nth-child(4))) :is(th,td),
  table.xref:not(:has(tr>*:nth-child(4))) :is(th,td),
  table.tbl:not(:has(tr>*:nth-child(4))) :is(th,td){padding:10px 12px;overflow-wrap:anywhere;white-space:normal}
  table.spec td{font-size:13.5px}
}
/* Карточки товаров (бренды, каталог): чипы и строка аналогов не переносились и
   обрезались по краю карточки («i 3,77–128,51», «≈ Motovario, SEW EURODRIVE»). */
.pcard-grid .pcard,.pcard-grid .pcard-body{min-width:0}
.pcard-grid .pcard-chips span,.pcard-grid .pcard-zr{white-space:normal;overflow-wrap:anywhere}
'''
p = ROOT / 'assets/hdr.css'
s = p.read_text(encoding='utf-8')
if MARK in s:
    print('assets/hdr.css: уже исправлено')
else:
    plan.append((p, s.rstrip('\n') + '\n' + HDR_TAIL))
    print('assets/hdr.css: правка подготовлена')

for p, s in plan:
    p.write_text(s, encoding='utf-8')
print(f'записано файлов: {len(plan)}')
