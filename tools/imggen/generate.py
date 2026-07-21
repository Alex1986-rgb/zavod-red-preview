#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Генерация фото редукторов через Flux 2 Pro (Black Forest Labs API).

Очередь с докачкой: состояние в .imggen-state.json, повторный запуск
продолжает с места остановки. Готовые файлы не перегенерируются.

Ключ: переменная BFL_API_KEY или файл ~/.config/zavod/bfl_api_key

Запуск:
  python3 tools/imggen/generate.py --test 10     # пробная партия
  python3 tools/imggen/generate.py               # все 751
  python3 tools/imggen/generate.py --status      # что сделано
"""
import base64
import json
import os
import sys
import time
import urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from prompts import all_jobs

BASE = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
STATE = os.path.join(BASE, ".imggen-state.json")
OUTDIR = os.path.join(BASE, "assets", "analog")
KEY_FILE = os.path.expanduser("~/.config/zavod/bfl_api_key")
API = "https://api.bfl.ai/v1/flux-pro-2"   # актуальный эндпоинт проверить в доках BFL
WIDTH, HEIGHT = 1216, 832


def api_key():
    k = os.environ.get("BFL_API_KEY", "").strip()
    if not k and os.path.isfile(KEY_FILE):
        k = open(KEY_FILE, encoding="utf-8").read().strip()
    if not k:
        sys.exit("Нет ключа BFL: положите его в ~/.config/zavod/bfl_api_key "
                 "или задайте BFL_API_KEY")
    return k


def load_state():
    try:
        return json.load(open(STATE, encoding="utf-8"))
    except (OSError, ValueError):
        return {"done": {}, "failed": {}}


def save_state(s):
    json.dump(s, open(STATE, "w", encoding="utf-8"), ensure_ascii=False)


def post(url, payload, key):
    req = urllib.request.Request(
        url, data=json.dumps(payload).encode(),
        headers={"Content-Type": "application/json", "x-key": key}, method="POST")
    return json.load(urllib.request.urlopen(req, timeout=120))


def get(url, key):
    req = urllib.request.Request(url, headers={"x-key": key})
    return json.load(urllib.request.urlopen(req, timeout=60))


def generate_one(job, key):
    """Отправляет запрос, ждёт результат, сохраняет webp. Возвращает путь или None."""
    res = post(API, {
        "prompt": job["prompt"],
        "width": WIDTH, "height": HEIGHT,
        "output_format": "png", "safety_tolerance": 2,
    }, key)
    poll = res.get("polling_url") or res.get("polling_uri")
    rid = res.get("id")
    if not poll and rid:
        poll = f"https://api.bfl.ai/v1/get_result?id={rid}"
    if not poll:
        raise RuntimeError(f"нет polling_url в ответе: {str(res)[:200]}")

    for _ in range(90):                      # до ~3 минут ожидания
        time.sleep(2)
        r = get(poll, key)
        st = r.get("status")
        if st == "Ready":
            url = r["result"]["sample"]
            data = urllib.request.urlopen(url, timeout=120).read()
            os.makedirs(OUTDIR, exist_ok=True)
            raw = os.path.join(OUTDIR, job["model"] + ".png")
            open(raw, "wb").write(data)
            return raw
        if st in ("Error", "Failed", "Content Moderated", "Request Moderated"):
            raise RuntimeError(f"статус {st}: {str(r)[:200]}")
    raise RuntimeError("таймаут ожидания результата")


def main():
    args = sys.argv[1:]
    jobs = all_jobs()
    state = load_state()

    if "--status" in args:
        print(f"всего заданий: {len(jobs)}")
        print(f"готово:        {len(state['done'])}")
        print(f"с ошибкой:     {len(state['failed'])}")
        left = [j for j in jobs if j["model"] not in state["done"]]
        print(f"осталось:      {len(left)}")
        return

    limit = None
    if "--test" in args:
        i = args.index("--test")
        limit = int(args[i + 1]) if len(args) > i + 1 else 10

    todo = [j for j in jobs if j["model"] not in state["done"]]
    if limit:
        todo = todo[:limit]
    if not todo:
        print("всё уже сгенерировано")
        return

    key = api_key()
    print(f"к генерации: {len(todo)} (примерно ${len(todo) * 0.045:.2f})")
    ok = err = 0
    for i, job in enumerate(todo, 1):
        try:
            path = generate_one(job, key)
            state["done"][job["model"]] = os.path.basename(path)
            state["failed"].pop(job["model"], None)
            ok += 1
            print(f"[{i}/{len(todo)}] {job['model']} → {os.path.basename(path)}")
        except Exception as e:
            err += 1
            state["failed"][job["model"]] = str(e)[:200]
            print(f"[{i}/{len(todo)}] {job['model']} → ОШИБКА: {str(e)[:120]}")
        if i % 10 == 0:
            save_state(state)
    save_state(state)
    print(f"\nготово: {ok}, ошибок: {err}")
    print("состояние сохранено — повторный запуск продолжит с места остановки")


if __name__ == "__main__":
    main()
