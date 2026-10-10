#!/usr/bin/env python3
"""Сбросить кэш стилей на 5 страницах с формой: hdr.css?v=<старое> → hdr.css?v=form1010.
Без этого браузеры (особенно Safari) до часа показывают старую форму. Повтор безопасен.
    python3 bump.py <public_html> [--apply]"""
import sys, re, pathlib, shutil, time, os
root = pathlib.Path(sys.argv[1]); NEW = 'form1010'
pages = ['index', 'about', 'contacts', 'importozameshchenie', 'proizvoditelyam-oborudovaniya']
bak = pathlib.Path(os.path.expanduser(f'~/_archive_old/bump-{time.strftime("%Y%m%d-%H%M%S")}'))
for p in pages:
    f = root / f'{p}.html'; s = f.read_text(encoding='utf-8')
    ns = re.sub(r'(hdr\.css\?v=)[^"\']+', r'\g<1>' + NEW, s)
    print(p, 'было:', re.findall(r'hdr\.css\?v=[^"\']+', s), '→', 'без изменений' if ns == s else NEW)
    if ns != s and '--apply' in sys.argv:
        bak.mkdir(parents=True, exist_ok=True); shutil.copy2(f, bak / f.name); f.write_text(ns, encoding='utf-8')
