#!/usr/bin/env python3
"""Ставит фото КОНКРЕТНОЙ модели первым на карточки /analog/.

Сейчас первое фото карточки — общее фото бренда (assets/catalog/br-{brand}-t1.webp).
В assets/cards-photo/ лежат 694 фото по моделям (bauer-bf-06.webp, nord-sk-02.webp …).
Ставим фото модели первым: заменяем src главного фото (id="p2img") и первой миниатюры
на assets/cards-photo/{модель}.webp. Остальные миниатюры не трогаем.

Сопоставление: имя фото — самый длинный префикс слага карточки. Слаг карточки
bauer-bf-06-i10-42-0-12kvt → модель bauer-bf-06 (фото bauer-bf-06.webp). Берём самое
длинное совпадение, чтобы bauer-bg-10x не путался с bauer-bg-10.

Идемпотентно: правленые файлы содержат маркер <!--modelphoto-->.
    python3 tools/place_card_photos.py --dry        # охват без записи
    python3 tools/place_card_photos.py --dry 500    # то же на 500 файлах
    python3 tools/place_card_photos.py              # применить
"""
import os, re, sys, glob
from collections import Counter

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
os.chdir(ROOT)

DRY = "--dry" in sys.argv
LIMIT = next((int(a) for a in sys.argv[1:] if a.isdigit()), None)

MARK = "<!--modelphoto-->"

# доступные фото моделей: имя без .webp, по убыванию длины (длинный префикс — первым)
PHOTOS = sorted(
    (os.path.basename(p)[:-5] for p in glob.glob("assets/cards-photo/*.webp")),
    key=len, reverse=True,
)
# индекс по первой букве для скорости
BY_FIRST = {}
for name in PHOTOS:
    BY_FIRST.setdefault(name[0], []).append(name)


def photo_for(slug):
    """Самый длинный photo-name, являющийся префиксом слага (по границе '-')."""
    cand = BY_FIRST.get(slug[:1], [])
    for name in cand:
        if slug == name or slug.startswith(name + "-"):
            return name
    return None


P2IMG = re.compile(r'(<img[^>]*id="p2img"[^>]*src=")[^"]*(")')


def transform(path):
    slug = os.path.basename(path)[:-5]
    photo = photo_for(slug)
    if photo is None:
        return "no-photo"
    t = open(path, encoding="utf-8").read()
    if MARK in t:
        return "already"
    src = f"../assets/cards-photo/{photo}.webp"

    # 1) главное фото
    new, n1 = P2IMG.subn(lambda m: m.group(1) + src + m.group(2), t, count=1)
    if n1 == 0:
        return "no-p2img"
    # 2) первая миниатюра (первая кнопка в .p2-thumbs): её data-src и img src
    def first_thumb(mo):
        block = mo.group(0)
        block = re.sub(r'(data-src=")[^"]*(")', lambda x: x.group(1) + src + x.group(2), block, count=1)
        block = re.sub(r'(<img[^>]*src=")[^"]*(")', lambda x: x.group(1) + src + x.group(2), block, count=1)
        return block
    new = re.sub(r'<button type="button"(?: class="act")?[^>]*>.*?</button>',
                 first_thumb, new, count=1, flags=re.S)

    new = new.replace("</head>", MARK + "</head>", 1)
    if not DRY:
        open(path, "w", encoding="utf-8").write(new)
    return "ok"


if __name__ == "__main__":
    files = [a for a in sys.argv[1:] if a.endswith(".html")]
    if not files:
        files = sorted(glob.glob("analog/*.html"))
        if LIMIT:
            files = files[:LIMIT]
    st = Counter()
    for i, f in enumerate(files, 1):
        st[transform(f)] += 1
        if i % 20000 == 0:
            print("...", i)
    print("итог:", dict(st))
    print(f"фото-моделей доступно: {len(PHOTOS)}")
    if DRY:
        print("(сухой прогон — без записи)")
