#!/usr/bin/env python3
"""П.6: эталон верхнего блока брендовой страницы (образец — SITI, применяется ко всем).

- H1 «Мотор-редукторы X — каталог и аналоги» → «Мотор-редукторы X» (убран дубль).
- Кнопки подняты ВЫШЕ текста, крупные/широкие: Модели оригинала / Калькулятор подбора / Фото X / Таблица замен.
- Пустая правая половина hero заполнена фото редуктора бренда (assets/brands-photo/{slug}-unit.webp).
- Убран дубль-текст (qnote под кнопками).
- Заголовок сетки «Модели X и наши аналоги ZR» → «Модели X» (ZR на этапе браузинга скрыт).
- Карточки сетки: убран хвост «→ ZR …» (оригинал на лице; ZR внутри карточки товара).

Использование: python3 tools/brand_hero_pro.py [файлы...]  (по умолчанию — все brands/*.html кроме index)
Идемпотентно.
"""
import os, re, sys, glob

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
os.chdir(ROOT)

CSS = (
    '<style id="zr-bhero">'
    '.bhero{display:grid;grid-template-columns:1.35fr .9fr;gap:40px;align-items:center}'
    '.bhero-main{min-width:0}'
    '.bhero-btns{display:flex;flex-wrap:wrap;gap:12px;margin:20px 0 22px}'
    ".bhb{display:inline-flex;align-items:center;gap:8px;padding:15px 26px;border-radius:12px;background:var(--card);"
    "border:1.5px solid var(--line);color:var(--text);font:700 15.5px/1 'Space Grotesk',sans-serif;text-decoration:none;transition:.15s}"
    '.bhb:hover{border-color:var(--red);color:var(--red);transform:translateY(-2px);box-shadow:0 8px 20px rgba(14,26,36,.08)}'
    '.bhero-lead{color:var(--muted);font-size:16px;line-height:1.62;max-width:64ch;margin:0}'
    '.bhero-cta{display:flex;flex-wrap:wrap;gap:12px;margin-top:22px}'
    '.bhero-cta .btn.ghost{background:var(--card);border:1.5px solid var(--line);color:var(--text)}'
    '.bhero-cta .btn.ghost:hover{border-color:var(--red);color:var(--red)}'
    '.bhero-photo{background:#fff;border:1px solid var(--line);border-radius:18px;padding:22px;display:flex;align-items:center;justify-content:center}'
    '.bhero-photo img{width:100%;height:auto;max-height:300px;object-fit:contain;display:block}'
    '@media(max-width:820px){.bhero{grid-template-columns:1fr;gap:22px}.bhero-photo{max-height:230px;padding:16px}.bhb{padding:13px 20px;font-size:14.5px}}'
    '</style>'
)


def build_hero(name, slug, lead, anchors):
    btn_defs = [
        ("bmodels", "Модели оригинала"),
        ("kalkulyator", "Калькулятор подбора"),
        ("bphoto", f"Фото {name}"),
        ("xtable", "Таблица замен"),
    ]
    btns = "".join(
        f'        <a class="bhb" href="#{a}">{label}</a>\n'
        for a, label in btn_defs if a in anchors
    )
    return (
        '<section class="phero"><div class="wrap">\n'
        '  <div class="bhero">\n'
        '    <div class="bhero-main">\n'
        f'      <div class="eyebrow">Импортозамещение · {name}</div>\n'
        f'      <h1>Мотор-редукторы {name}</h1>\n'
        '      <div class="bhero-btns">\n'
        f'{btns}'
        '      </div>\n'
        f'      <p class="bhero-lead">{lead}</p>\n'
        '      <div class="bhero-cta"><a class="btn lg" data-zayavka href="#zayavka">Запросить цену</a>'
        '<a class="btn ghost lg" href="/brands/">Все бренды</a></div>\n'
        '    </div>\n'
        f'    <div class="bhero-photo"><img src="/assets/brands-photo/{slug}-unit.webp" alt="Мотор-редуктор {name} — фото" loading="lazy"></div>\n'
        '  </div>\n'
        '</div></section>'
    )


def process(path):
    slug = os.path.splitext(os.path.basename(path))[0]
    if not os.path.exists(f"assets/brands-photo/{slug}-unit.webp"):
        return f"{slug}: НЕТ фото {slug}-unit.webp — пропуск"
    t = open(path, encoding="utf-8").read()
    orig = t

    # уже переделано?
    already = 'class="bhero"' in t

    # извлечь имя из H1
    mh1 = re.search(r'<h1>Мотор-редукторы\s+(.*?)(?:\s+—\s+каталог и аналоги)?</h1>', t)
    if not mh1 and not already:
        return f"{slug}: H1 не распознан — пропуск"
    name = mh1.group(1).strip() if mh1 else slug.upper()

    # извлечь первый lead <p> после h1 (значимое описание)
    lead = ""
    ml = re.search(r'<h1>.*?</h1>\s*<p>(.*?)</p>', t, flags=re.S)
    if ml:
        lead = ml.group(1).strip()

    if not already:
        # какие якоря-цели реально есть на странице
        anchors = set(re.findall(r'id="(bmodels|kalkulyator|bphoto|xtable)"', t))
        # заменить весь phero-герой
        new_hero = build_hero(name, slug, lead, anchors)
        t2, n = re.subn(r'<section class="phero"><div class="wrap">.*?</div></section>',
                        lambda m: new_hero, t, count=1, flags=re.S)
        if n == 0:
            return f"{slug}: phero не найден — пропуск"
        t = t2

    # заголовок сетки: убрать « и наши аналоги ZR»
    t = re.sub(r'(<h2 class="sec-h">Модели\s+' + re.escape(name) + r')\s+и наши аналоги ZR</h2>',
               r'\1</h2>', t)
    # убрать длинный дубль-lead в секции bmodels (сразу после sec-h)
    t = re.sub(r'(<h2 class="sec-h">Модели\s+' + re.escape(name) + r'</h2>)<p class="lead"[^>]*>.*?</p>',
               r'\1', t, flags=re.S)

    # карточки bm-js: убрать хвост « &rarr; ZR»
    t = t.replace('+esc(name)+" &rarr; "+esc(c.z||"ZR")+"</a>"', '+esc(name)+"</a>"')

    # версия import-catalog в bm-js
    t = t.replace('import-catalog.json?v=310', 'import-catalog.json?v=311')

    # CSS
    t = re.sub(r'<style id="zr-bhero">.*?</style>', '', t, flags=re.S)
    t = t.replace('</head>', CSS + '</head>', 1)

    if t == orig:
        return f"{slug}: без изменений"
    open(path, "w", encoding="utf-8").write(t)
    return f"{slug}: OK (name={name!r})"


if __name__ == "__main__":
    files = sys.argv[1:]
    if not files:
        files = [f for f in glob.glob("brands/*.html") if not f.endswith("index.html")]
    for f in sorted(files):
        print(process(f))
