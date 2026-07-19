#!/usr/bin/env python3
"""П.9: меню «Каталог редукторов» → выпадашка (дропдаун) с 6 разделами.

Импортные мотор-редукторы (→/brands/) · Калькулятор подбора (→/podbor) ·
Под маркой ZR (→/motor-reduktor-zr/) · EVL (→/catalog/evl) · ПР (→/catalog/pr) · МР (→/catalog/mr).

Заменяет `<a href="/catalog/">Каталог редукторов</a>` на .dropdown/.submenu (как «О заводе»).
Toggle сохраняет href="/catalog/" — на десктопе клик ведёт в каталог (см. правку inner.js),
на мобиле раскрывает сабменю; на ховере — выпадает.

Область: все *.html КРОМЕ dist/, analog/ (уедет отдельно) и файлов из skip-списка
(незакоммиченное второй сессии). Идемпотентно.
"""
import os, sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
os.chdir(ROOT)

OLD = '<a href="/catalog/">Каталог редукторов</a>'
NEW = (
    '<div class="dropdown"><a class="drop-toggle" href="/catalog/" role="button" '
    'aria-haspopup="true" aria-expanded="false">Каталог редукторов</a>'
    '<div class="submenu">'
    '<a href="/brands/">Импортные мотор-редукторы</a>'
    '<a href="/podbor">Калькулятор подбора</a>'
    '<a href="/motor-reduktor-zr/">Под маркой ZR</a>'
    '<a href="/catalog/evl">Мотор-редукторы EVL</a>'
    '<a href="/catalog/pr">Мотор-редукторы ПР</a>'
    '<a href="/catalog/mr">Мотор-редукторы МР</a>'
    '</div></div>'
)

SKIP = set()
skipf = "/tmp/skip_menu.txt"
if os.path.exists(skipf):
    SKIP = {l.strip() for l in open(skipf) if l.strip()}

EXCLUDE_DIRS = {"dist", "analog", ".git", "node_modules"}


def main():
    changed = skipped = nomatch = 0
    for root, dirs, files in os.walk("."):
        dirs[:] = [d for d in dirs if d not in EXCLUDE_DIRS and not d.startswith(".git")]
        # верхний уровень: не заходить в analog/dist
        for f in files:
            if not f.endswith(".html"):
                continue
            path = os.path.join(root, f)
            rel = os.path.relpath(path, ".")
            if rel in SKIP:
                skipped += 1
                continue
            t = open(path, encoding="utf-8").read()
            if 'Под маркой ZR' in t:  # уже сделано
                continue
            if OLD not in t:
                nomatch += 1
                continue
            t = t.replace(OLD, NEW, 1)
            open(path, "w", encoding="utf-8").write(t)
            changed += 1
    print(f"изменено: {changed} | пропущено(skip): {skipped} | без совпадения меню: {nomatch}")


if __name__ == "__main__":
    main()
