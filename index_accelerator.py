#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Ускоритель индексации zavod-red.ru.

Что делает при каждом запуске:
  1. Читает sitemap.xml и сопоставляет каждый URL с локальным HTML-файлом.
  2. Сравнивает хэши содержимого с прошлым запуском (.index-state.json)
     и отправляет НОВЫЕ и ИЗМЕНЁННЫЕ страницы в IndexNow (Яндекс + Bing).
  3. Сплошная индексация через очередь переобхода Яндекс.Вебмастера:
     ежедневная квота (~150 URL) заполняется по приоритету —
     сначала изменённые страницы, потом ни разу не отправлявшиеся,
     потом самые давние. Так весь сайт проходит через переобход по кругу.

Запуск:
  python3 index_accelerator.py              # обычный ежедневный проход
  python3 index_accelerator.py --dry-run    # показать, что ушло бы, без отправки
  python3 index_accelerator.py --all        # принудительно отправить весь sitemap в IndexNow
  python3 index_accelerator.py --status     # состояние, квота, прогресс сплошной индексации

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
SITEMAP = os.path.join(BASE, "sitemap.xml")
STATE_FILE = os.path.join(BASE, ".index-state.json")
LOG_FILE = os.path.join(BASE, "index-accelerator.log")
INDEXNOW_ENDPOINTS = ["https://yandex.com/indexnow", "https://api.indexnow.org/indexnow"]
INDEXNOW_BATCH = 10000          # лимит IndexNow на один POST
RECRAWL_MAX_PER_RUN = 140       # сколько квоты переобхода тратить за запуск (10 — резерв)
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
    sm = open(SITEMAP, encoding="utf-8").read()
    return re.findall(r"<loc>([^<]+)</loc>", sm)


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


def content_hash(url):
    f = local_file_for(url)
    if f is None:
        return "no-local-file"  # страница есть в sitemap, файла нет — считаем стабильной
    h = hashlib.sha256()
    with open(f, "rb") as fh:
        for chunk in iter(lambda: fh.read(65536), b""):
            h.update(chunk)
    return h.hexdigest()


def load_state():
    try:
        s = json.load(open(STATE_FILE, encoding="utf-8"))
    except (OSError, ValueError):
        s = {}
    s.setdefault("urls", {})
    s.setdefault("recrawl", {})   # url -> ISO-дата последней постановки в переобход
    return s


def save_state(state):
    json.dump(state, open(STATE_FILE, "w", encoding="utf-8"), ensure_ascii=False)


def post_json(url, payload, headers=None):
    data = json.dumps(payload).encode()
    hdrs = {"Content-Type": "application/json; charset=utf-8"}
    hdrs.update(headers or {})
    req = urllib.request.Request(url, data=data, headers=hdrs, method="POST")
    return urllib.request.urlopen(req, timeout=30)


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
    """(user_id, host_id, headers) или None, если токена нет/хост не найден."""
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


def recrawl_candidates(changed, all_urls, recrawl_map):
    """Приоритет: изменённые → ни разу не отправлявшиеся → самые давние."""
    never = [u for u in all_urls if u not in recrawl_map and u not in changed]
    oldest = sorted((u for u in all_urls if u in recrawl_map and u not in changed),
                    key=lambda u: recrawl_map[u])
    seen, order = set(), []
    for u in changed + never + oldest:
        if u not in seen:
            seen.add(u)
            order.append(u)
    return order


def webmaster_recrawl(changed, all_urls, state, dry_run=False):
    """Сплошная индексация: заполняет дневную квоту переобхода по приоритету."""
    sess = webmaster_session()
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
    covered = sum(1 for u in urls if u in state["recrawl"])
    print(f"URL в sitemap.xml:        {len(urls)}")
    print(f"URL в состоянии:          {len(state['urls'])}")
    print(f"Прогресс переобхода:      {covered}/{len(urls)} страниц отправлено хотя бы раз")
    print(f"Файл состояния:           {STATE_FILE}")
    print(f"Токен Вебмастера:         {'есть' if webmaster_token() else 'НЕТ (только IndexNow)'}")
    sess = webmaster_session() if webmaster_token() else None
    if sess:
        api, uid, host_id, hdr = sess
        q = get_json(f"{api}/user/{uid}/hosts/{host_id}/recrawl/quota/", hdr)
        print(f"Квота переобхода:         осталось {q.get('quota_remainder', '?')} из {q.get('daily_quota', '?')} на сегодня")


def main():
    p = argparse.ArgumentParser(description="Ускоритель индексации zavod-red.ru")
    p.add_argument("--all", action="store_true", help="отправить весь sitemap в IndexNow, игнорируя состояние")
    p.add_argument("--dry-run", action="store_true", help="ничего не отправлять, только показать")
    p.add_argument("--status", action="store_true", help="показать состояние, квоту и прогресс")
    args = p.parse_args()

    if args.status:
        cmd_status()
        return

    urls = sitemap_urls()
    state = load_state()
    known = state["urls"]

    log(f"=== Запуск: {len(urls)} URL в sitemap, {len(known)} в состоянии ===")

    changed, hashes = [], {}
    for u in urls:
        h = content_hash(u)
        hashes[u] = h
        if args.all or known.get(u) != h:
            changed.append(u)

    indexnow_ok = True
    if changed:
        new_cnt = sum(1 for u in changed if u not in known)
        log(f"IndexNow: {len(changed)} URL (новых: {new_cnt}, изменённых: {len(changed) - new_cnt})")
        indexnow_ok = submit_indexnow(changed, dry_run=args.dry_run)
    else:
        log("IndexNow: изменений нет — ничего не отправляю.")

    # Сплошная индексация работает каждый день, даже без изменений на сайте
    webmaster_recrawl(changed, urls, state, dry_run=args.dry_run)

    if not args.dry_run:
        if indexnow_ok:
            state["urls"] = hashes
        else:
            log("Были ошибки IndexNow — хэши НЕ сохранены, изменения уйдут при следующем запуске.")
        state["last_run"] = datetime.now(timezone.utc).isoformat()
        save_state(state)
        log("Состояние сохранено.")


if __name__ == "__main__":
    main()
