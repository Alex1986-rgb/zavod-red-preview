#!/usr/bin/env python3
"""Добавить стили формы заявки (form.css, метка LIVEFIX-FORM) в конец assets/hdr.css. Повтор безопасен.
    python3 patch.py <public_html> <form.css>"""
import sys, pathlib
root, css = pathlib.Path(sys.argv[1]), pathlib.Path(sys.argv[2]).read_text(encoding='utf-8')
p = root / 'assets/hdr.css'
s = p.read_text(encoding='utf-8')
if 'LIVEFIX-FORM' in s:
    print('hdr.css: уже исправлено'); sys.exit(0)
p.write_text(s.rstrip('\n') + '\n\n' + css, encoding='utf-8')
print('hdr.css: стили формы добавлены')
