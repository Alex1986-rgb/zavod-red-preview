#!/usr/bin/env python3
"""Возвращает странице catalog/evl.html идентичность серии EVL.

После ребрендинга EVL→ZR страница /catalog/evl осталась на месте (и проиндексирована),
но весь текст на ней — про ZR: заголовок «Редукторы и мотор-редукторы ZR», упоминаний
EVL ноль. Клиент, который знает наш завод по старой маркировке, заходит по прямой
ссылке и не находит ни слова про EVL.

Что делает скрипт:
  1. В <title> и <h1> возвращает EVL рядом с ZR (ZR не убираем — он уже в выдаче).
  2. Вставляет вводный блок «EVL — прежняя маркировка» с таблицей соответствий
     EVL ↔ ZR, собранной из ZRMAP в assets/podbor.js (48 пар) — не из головы.
  3. На плитках товара к бейджу «ZR 979» дописывает прежнее «EVL 197», если пара
     есть в карте.

Идемпотентно: маркер <!--evlpage-->. Запуск:
    python3 tools/evl_page.py --dry
    python3 tools/evl_page.py
"""
import os, re, sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
os.chdir(ROOT)

DRY = "--dry" in sys.argv
PAGE = "catalog/evl.html"
MARK = "<!--evlpage-->"


def zrmap():
    """{ZR-номер: EVL-номер} из ключа ZRMAP в калькуляторе."""
    t = open("assets/podbor.js", encoding="utf-8").read()
    m = re.search(r"ZRMAP\s*=\s*\{", t)
    if not m:
        return {}
    start = m.end() - 1
    depth = 0
    end = start
    for j in range(start, len(t)):
        if t[j] == "{":
            depth += 1
        elif t[j] == "}":
            depth -= 1
            if depth == 0:
                end = j + 1
                break
    return {zr: evl for evl, zr in re.findall(r"(\d+)\s*:\s*(\d+)", t[start:end])}


INTRO_CSS = (
    '<style id="evl-intro-css">'
    '.evl-note{background:var(--card2,#f6f8fa);border:1px solid var(--line,rgba(14,26,36,.14));'
    'border-left:4px solid var(--red,#cf1616);border-radius:14px;padding:22px 24px;margin:0 0 26px}'
    '.evl-note h3{font-size:19px;margin:0 0 8px}'
    '.evl-note p{color:var(--muted,#526069);margin:0 0 14px;max-width:70ch}'
    '.evl-tbl{width:100%;border-collapse:collapse;font-size:14px;'
    'font-variant-numeric:tabular-nums;display:block;overflow-x:auto}'
    '.evl-tbl th,.evl-tbl td{text-align:left;padding:7px 14px 7px 0;white-space:nowrap}'
    '.evl-tbl th{color:var(--muted,#526069);font-weight:600;font-size:12.5px;'
    'text-transform:uppercase;letter-spacing:.04em;border-bottom:1px solid var(--line,rgba(14,26,36,.14))}'
    '.evl-tbl td{border-bottom:1px solid var(--line,rgba(14,26,36,.09))}'
    '.evl-tbl td b{color:var(--text,#0e1a24)}'
    '.evl-old{color:var(--muted,#526069)}'
    '</style>'
)


def intro_html(pairs):
    rows = "".join(
        f"<tr><td class=\"evl-old\">EVL {evl}</td><td><b>ZR {zr}</b></td></tr>"
        for zr, evl in sorted(pairs.items(), key=lambda x: int(x[1]))
    )
    return (
        '<section class="wrap" style="padding-top:26px">'
        '<div class="evl-note">'
        '<h3>EVL — прежняя маркировка наших редукторов</h3>'
        '<p>Редукторы серии EVL выпускает наш завод. Сейчас та же продукция маркируется '
        '<b>ZR</b>: конструкция, присоединительные и габаритные размеры не изменились — '
        'изменилось только обозначение. Если у вас в документации или на шильде стоит EVL, '
        'найдите номер в таблице и переходите на карточку соответствующей модели ZR.</p>'
        '<table class="evl-tbl"><thead><tr><th>Прежнее обозначение</th>'
        '<th>Действующее обозначение</th></tr></thead><tbody>'
        + rows +
        '</tbody></table>'
        '<p style="margin:14px 0 0">Не нашли своё обозначение — пришлите фото шильда, '
        'инженер определит модель и подберёт замену.</p>'
        '</div></section>'
    )


def main():
    t = open(PAGE, encoding="utf-8").read()
    if MARK in t:
        print("уже применено")
        return
    pairs = zrmap()
    if not pairs:
        print("ZRMAP не найден — правка отменена")
        return

    orig = t

    # 1) title и h1 — EVL рядом с ZR (ZR не убираем: страница уже в выдаче по нему)
    t = re.sub(r"<title>[^<]*</title>",
               "<title>Редукторы EVL — теперь ZR: таблица соответствия и каталог | ЗР</title>",
               t, count=1)
    t = re.sub(r"(<h1[^>]*>)(.*?)(</h1>)",
               r"\1Редукторы EVL — теперь ZR\3", t, count=1, flags=re.S)

    # 2) вводный блок с таблицей соответствий — сразу после </h1>-секции
    m = re.search(r"</h1>", t)
    if m:
        # вставляем после закрытия секции с h1
        close = t.find("</section>", m.end())
        pos = close + len("</section>") if close != -1 else m.end()
        t = t[:pos] + INTRO_CSS + intro_html(pairs) + t[pos:]

    # 3) на плитках дописываем прежнее обозначение
    def badge(m):
        zr = m.group(1)
        evl = pairs.get(zr)
        if not evl:
            return m.group(0)
        return (f'<span class="pcard-badge">ZR {zr}'
                f'<span class="evl-old" style="font-weight:500"> · EVL {evl}</span></span>')

    t = re.sub(r'<span class="pcard-badge">ZR (\d+)</span>', badge, t)

    if t == orig:
        print("ничего не изменилось")
        return
    t = t.replace("</head>", MARK + "</head>", 1)
    if DRY:
        print(f"сухой прогон: пар в таблице {len(pairs)}, "
              f"бейджей с EVL {len(re.findall(chr(183) + ' EVL', t))}")
        return
    open(PAGE, "w", encoding="utf-8").write(t)
    print(f"{PAGE} обновлён: пар {len(pairs)}, "
          f"бейджей с EVL {len(re.findall(chr(183) + ' EVL', t))}")


if __name__ == "__main__":
    main()
