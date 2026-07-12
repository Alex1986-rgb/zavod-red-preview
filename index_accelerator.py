#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Ускоритель индексации zavod-red.ru.

Что делает при каждом запуске:
  1. Читает ВСЕ sitemap-файлы сайта (в порядке приоритета разделов)
     и сопоставляет каждый URL с локальным HTML-файлом.
  2. Находит НОВЫЕ и ИЗМЕНЁННЫЕ страницы (быстро: сначала mtime+размер,
     содержимое хэшируется только у затронутых файлов) и шлёт их
     в IndexNow (Яндекс + Bing) пакетами по 10 000.
  3. Сплошная индексация через очередь переобхода Яндекс.Вебмастера:
     дневная квота (~150 URL) заполняется по приоритету —
     изменённые → ни разу не отправлявшиеся (важные разделы первыми) →
     самые давние. Так весь сайт проходит через переобход по кругу.

Запуск:
  python3 index_accelerator.py              # обычный проход
  python3 index_accelerator.py --dry-run    # показать, что ушло бы, без отправки
  python3 index_accelerator.py --all        # принудительно отправить все URL в IndexNow
  python3 index_accelerator.py --status     # состояние, квота, прогресс

Токен Вебмастера: переменная YANDEX_WEBMASTER_TOKEN
или файл ~/.config/zavod/yandex_webmaster_token
"""
import argparse
import hashlib
import json
import os
import re
import urllib.request
from datetime import datetime, timezone

BASE = os.path.dirname(os.path.abspath(__file__))
HOST = "zavod-red.ru"
KEY = "051a8bb09331b03c5e35d4a40339b5b3"
# Порядок = приоритет для очереди переобхода (важные разделы первыми).
# sitemap-images.xml не включаем — это ссылки на картинки, не страницы.
PAGE_SITEMAPS = [
    "sitemap.xml",             # основные страницы, блог, каталог
    "sitemap-hub.xml",         # хабы
    "sitemap-zr.xml",          # карточки ZR
    "sitemap-tiporazmer.xml",  # типоразмеры
    "sitemap-ispolnenie.xml",  # исполнения
    "sitemap-analog.xml",      # аналоги (длинный хвост)
    "sitemap-analog-2.xml",
]
STATE_FILE = os.path.join(BASE, ".index-state.json")
LOG_FILE = os.path.join(BASE, "index-accelerator.log")
INDEXNOW_ENDPOINTS = ["https://yandex.com/indexnow", "https://api.indexnow.org/indexnow"]
INDEXNOW_BATCH = 10000          # лимит IndexNow на один POST
RECRAWL_MAX_PER_RUN = 145       # квота переобхода за запуск (5 — резерв)
TOKEN_FILE = os.path.expanduser("~/.config/zavod/yandex_webmaster_token")


def log(msg):
    line = f"[{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] {msg}"
    print(line)
    try:
        with open(LOG_FILE, "a", encoding="utf-8") as f:
            f.write(line + "\n")
    except OSError:
        pass


def sitemap_urls():
    """Все URL страниц в порядке приоритета разделов (без дублей)."""
    seen, out = set(), []
    for sm in PAGE_SITEMAPS:
        path = os.path.join(BASE, sm)
        if not os.path.isfile(path):
            log(f"  ВНИМАНИЕ: {sm} не найден локально — раздел пропущен")
            continue
        for u in re.findall(r"<loc>([^<]+)</loc>", open(path, encoding="utf-8").read()):
            if u not in seen:
                seen.add(u)
                out.append(u)
    return out


def local_file_for(url):
    """https://zavod-red.ru/podbor -> podbor.html | podbor/index.html | podbor"""
    path = re.sub(r"^https?://[^/]+/?", "", url).split("#")[0].split("?")[0]
    if not path or path.endswith("/"):
        path += "index.html"
    candidates = [path]
    if not path.endswith(".html"):
        candidates = [path + ".html", path + "/index.html", path]
    for c in candidates:
        full = os.path.join(BASE, c)
        if os.path.isfile(full):
            return full
    return None


def sha256_file(path):
    h = hashlib.sha256()
    with open(path, "rb") as fh:
        for chunk in iter(lambda: fh.read(65536), b""):
            h.update(chunk)
    return h.hexdigest()


def fingerprint(url, prev):
    """{'h': sha256, 'ms': 'mtime-size'}. Содержимое хэшируем только если
    mtime+размер изменились — иначе слишком долго на 90k+ файлов."""
    f = local_file_for(url)
    if f is None:
        return {"h": "no-local-file", "ms": ""}
    st = os.stat(f)
    ms = f"{int(st.st_mtime)}-{st.st_size}"
    if prev and prev.get("ms") == ms:
        return prev
    return {"h": sha256_file(f), "ms": ms}


def load_state():
    try:
        s = json.load(open(STATE_FILE, encoding="utf-8"))
    except (OSError, ValueError):
        s = {}
    s.setdefault("urls", {})
    s.setdefault("recrawl", {})   # url -> ISO-дата последней постановки в переобход
    # миграция старого формата (url -> строка-хэш)
    for u, v in list(s["urls"].items()):
        if isinstance(v, str):
            s["urls"][u] = {"h": v, "ms": ""}
    return s


def save_state(state):
    json.dump(state, open(STATE_FILE, "w", encoding="utf-8"), ensure_ascii=False)


def post_json(url, payload, headers=None):
    data = json.dumps(payload).encode()
    hdrs = {"Content-Type": "application/json; charset=utf-8"}
    hdrs.update(headers or {})
    req = urllib.request.Request(url, data=data, headers=hdrs, method="POST")
    return urllib.request.urlopen(req, timeout=60)


def get_json(url, headers=None):
    req = urllib.request.Request(url, headers=headers or {})
    return json.load(urllib.request.urlopen(req, timeout=30))


def submit_indexnow(urls, dry_run=False):
    ok = True
    for i in range(0, len(urls), INDEXNOW_BATCH):
        batch = urls[i:i + INDEXNOW_BATCH]
        payload = {"host": HOST, "key": KEY,
                   "keyLocation": f"https://{HOST}/{KEY}.txt", "urlList": batch}
        for ep in INDEXNOW_ENDPOINTS:
            if dry_run:
                log(f"  [dry-run] {ep}: пакет {len(batch)} URL")
                continue
            try:
                r = post_json(ep, payload)
                log(f"  {ep} → HTTP {r.status} ({len(batch)} URL)")
            except Exception as e:
                ok = False
                log(f"  {ep} → ОШИБКА: {str(e)[:100]}")
    return ok


def webmaster_token():
    t = os.environ.get("YANDEX_WEBMASTER_TOKEN", "").strip()
    if not t and os.path.isfile(TOKEN_FILE):
        t = open(TOKEN_FILE, encoding="utf-8").read().strip()
    return t or None


def webmaster_session():
    """(api, user_id, host_id, headers) или None."""
    token = webmaster_token()
    if not token:
        return None
    hdr = {"Authorization": f"OAuth {token}"}
    api = "https://api.webmaster.yandex.net/v4"
    uid = get_json(f"{api}/user/", hdr)["user_id"]
    hosts = get_json(f"{api}/user/{uid}/hosts/", hdr)["hosts"]
    host_id = next((h["host_id"] for h in hosts if HOST in h["host_id"]), None)
    if not host_id:
        log(f"  Переобход: хост {HOST} не найден в Вебмастере")
        return None
    return api, uid, host_id, hdr


def priority_patterns():
    """Подстроки из .index-priority (по одной на строку) — такие URL идут в переобход первыми."""
    p = os.path.join(BASE, ".index-priority")
    if not os.path.isfile(p):
        return []
    return [ln.strip().lower() for ln in open(p, encoding="utf-8") if ln.strip()]


def recrawl_candidates(changed, all_urls, recrawl_map):
    """Приоритет: изменённые → ни разу не отправлявшиеся (в порядке разделов) → самые давние.
    URL, содержащие подстроки из .index-priority, поднимаются в начало своей группы."""
    pats = priority_patterns()
    boost = (lambda lst: [u for u in lst if any(p in u.lower() for p in pats)] +
                         [u for u in lst if not any(p in u.lower() for p in pats)]) if pats else (lambda lst: lst)
    never = boost([u for u in all_urls if u not in recrawl_map and u not in changed])
    oldest = boost(sorted((u for u in all_urls if u in recrawl_map and u not in changed),
                          key=lambda u: recrawl_map[u]))
    seen, order = set(), []
    for u in changed + never + oldest:
        if u not in seen:
            seen.add(u)
            order.append(u)
    return order


def webmaster_recrawl(changed, all_urls, state, dry_run=False):
    """Сплошная индексация: заполняет дневную квоту переобхода по приоритету."""
    try:
        sess = webmaster_session()
    except Exception as e:
        log(f"  Переобход Вебмастера недоступен (сеть?): {str(e)[:100]}")
        return
    if sess is None:
        if not webmaster_token():
            log("  Переобход Вебмастера: токен не задан — пропускаю (только IndexNow)")
        return
    api, uid, host_id, hdr = sess
    try:
        quota = get_json(f"{api}/user/{uid}/hosts/{host_id}/recrawl/quota/", hdr)
        remain = quota.get("quota_remainder", 0)
        log(f"  Квота переобхода: осталось {remain} из {quota.get('daily_quota', '?')} на сегодня")
        todo = recrawl_candidates(changed, all_urls, state["recrawl"])[:min(RECRAWL_MAX_PER_RUN, remain)]
        if not todo:
            log("  Переобход: очередь пуста или квота исчерпана")
            return
        now = datetime.now(timezone.utc).isoformat()
        sent = errs = 0
        for u in todo:
            if dry_run:
                continue
            try:
                post_json(f"{api}/user/{uid}/hosts/{host_id}/recrawl/queue/", {"url": u}, hdr)
                state["recrawl"][u] = now
                sent += 1
            except Exception as e:
                errs += 1
                if errs <= 3:
                    log(f"  переобход {u} → {str(e)[:80]}")
                if errs >= 10:
                    log("  Слишком много ошибок переобхода — останавливаюсь до следующего запуска")
                    break
        if dry_run:
            log(f"  [dry-run] в переобход ушло бы: {len(todo)} URL (первые: {', '.join(todo[:3])}…)")
        else:
            covered = len(state["recrawl"])
            log(f"  В очередь переобхода поставлено: {sent}"
                f" | прогресс сплошной индексации: {covered}/{len(all_urls)}")
    except Exception as e:
        log(f"  Переобход Вебмастера → ОШИБКА: {str(e)[:120]}")


def cmd_status():
    state = load_state()
    urls = sitemap_urls()
    print(f"URL всего (7 sitemap):    {len(urls)}")
    for sm in PAGE_SITEMAPS:
        p = os.path.join(BASE, sm)
        n = len(re.findall(r"<loc>", open(p, encoding="utf-8").read())) if os.path.isfile(p) else 0
        print(f"  {sm:26s} {n}")
    covered = sum(1 for u in urls if u in state["recrawl"])
    print(f"URL в состоянии:          {len(state['urls'])}")
    print(f"Прогресс переобхода:      {covered}/{len(urls)} страниц отправлено хотя бы раз")
    print(f"Токен Вебмастера:         {'есть' if webmaster_token() else 'НЕТ (только IndexNow)'}")
    sess = webmaster_session() if webmaster_token() else None
    if sess:
        api, uid, host_id, hdr = sess
        q = get_json(f"{api}/user/{uid}/hosts/{host_id}/recrawl/quota/", hdr)
        print(f"Квота переобхода:         осталось {q.get('quota_remainder', '?')} из {q.get('daily_quota', '?')} на сегодня")


def main():
    p = argparse.ArgumentParser(description="Ускоритель индексации zavod-red.ru")
    p.add_argument("--all", action="store_true", help="отправить все URL в IndexNow, игнорируя состояние")
    p.add_argument("--dry-run", action="store_true", help="ничего не отправлять, только показать")
    p.add_argument("--status", action="store_true", help="показать состояние, квоту и прогресс")
    args = p.parse_args()

    if args.status:
        cmd_status()
        return

    urls = sitemap_urls()
    state = load_state()
    known = state["urls"]

    log(f"=== Запуск: {len(urls)} URL в {len(PAGE_SITEMAPS)} sitemap, {len(known)} в состоянии ===")

    changed, fresh = [], {}
    for u in urls:
        prev = known.get(u)
        fp = fingerprint(u, prev)
        fresh[u] = fp
        if args.all or prev is None or prev.get("h") != fp["h"]:
            changed.append(u)

    indexnow_ok = True
    if changed:
        new_cnt = sum(1 for u in changed if u not in known)
        log(f"IndexNow: {len(changed)} URL (новых: {new_cnt}, изменённых: {len(changed) - new_cnt})")
        indexnow_ok = submit_indexnow(changed, dry_run=args.dry_run)
    else:
        log("IndexNow: изменений нет — ничего не отправляю.")

    # Сплошная индексация работает каждый запуск, даже без изменений на сайте
    webmaster_recrawl(changed, urls, state, dry_run=args.dry_run)

    if not args.dry_run:
        if indexnow_ok:
            state["urls"] = fresh
        else:
            log("Были ошибки IndexNow — отпечатки НЕ сохранены, изменения уйдут при следующем запуске.")
        state["last_run"] = datetime.now(timezone.utc).isoformat()
        save_state(state)
        log("Состояние сохранено.")


if __name__ == "__main__":
    main()
