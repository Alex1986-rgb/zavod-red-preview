#!/usr/bin/env python3
"""Добавить стили формы заявки (form.css, метка LIVEFIX-FORM) в конец assets/hdr.css. Повтор безопасен.
    python3 patch.py <public_html> <form.css>"""
import sys, pathlib, re
root, css = pathlib.Path(sys.argv[1]), pathlib.Path(sys.argv[2]).read_text(encoding='utf-8')
MARK = re.search(r'LIVEFIX-[A-Z0-9]+', css).group(0)   # метка блока: LIVEFIX-FORM, LIVEFIX-FORM2…
p = root / 'assets/hdr.css'
s = p.read_text(encoding='utf-8')
if MARK in s:
    print(f'hdr.css: {MARK} уже есть'); sys.exit(0)
p.write_text(s.rstrip('\n') + '\n\n' + css, encoding='utf-8')
print(f'hdr.css: {MARK} добавлен')
