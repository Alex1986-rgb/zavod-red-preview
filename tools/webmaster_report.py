#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Что Яндекс.Вебмастер говорит о сайте: индексация, запросы, исключённые страницы.

Зачем: когда сайт «пропал из выдачи», гадать по коду сайта бесполезно — надо смотреть,
что об этом говорит сам поиск. Вебмастер хранит историю: сколько страниц в поиске по
дням, по каким запросам были показы и на каких позициях, какие страницы исключены и по
какой причине. Этого достаточно, чтобы отличить «страницы выпали из индекса» от «страницы
в индексе, но опустились», а это разные болезни с разным лечением.

Только чтение: ни одного изменяющего запроса к API не делается.

Токен — переменная YANDEX_WEBMASTER_TOKEN (тот же, что у index_accelerator.py).

Запуск:  python3 tools/webmaster_report.py [--url АДРЕС] [--days N]
         --url можно повторять: проверить конкретные страницы.
"""
import argparse
import json
import os
import sys
import urllib.error
import urllib.parse
import urllib.request
from datetime import date, timedelta

API = 'https://api.webmaster.yandex.net/v4'
HOST = 'zavod-red.ru'


def token():
    t = os.environ.get('YANDEX_WEBMASTER_TOKEN', '').strip()
    if not t:
        path = os.path.expanduser('~/.config/zavod/yandex_webmaster_token')
        if os.path.exists(path):
            t = open(path, encoding='utf-8').read().strip()
    return t


def get(url, hdr):
    req = urllib.request.Request(url, headers=hdr)
    try:
        with urllib.request.urlopen(req, timeout=40) as r:
            return json.loads(r.read().decode('utf-8'))
    except urllib.error.HTTPError as e:
        body = e.read().decode('utf-8', 'ignore')[:200]
        return {'_error': f'HTTP {e.code}: {body}'}
    except Exception as e:                                    # сеть, таймаут
        return {'_error': str(e)[:200]}


def section(title):
    print()
    print('=' * 78)
    print(title)
    print('=' * 78)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--url', action='append', default=[], help='проверить конкретный адрес')
    ap.add_argument('--days', type=int, default=90, help='глубина истории в днях')
    args = ap.parse_args()

    t = token()
    if not t:
        print('Токена Вебмастера нет — отчёт построить не из чего.')
        print('Ожидается переменная YANDEX_WEBMASTER_TOKEN.')
        return 1
    hdr = {'Authorization': f'OAuth {t}', 'Accept': 'application/json'}

    me = get(f'{API}/user/', hdr)
    if '_error' in me:
        print('Не удалось войти в Вебмастер:', me['_error'])
        return 1
    uid = me['user_id']
    hosts = get(f'{API}/user/{uid}/hosts/', hdr).get('hosts', [])
    host_id = next((h['host_id'] for h in hosts if HOST in h['host_id']), None)
    if not host_id:
        print('Сайт не найден среди подтверждённых в Вебмастере.')
        print('Доступные:', [h.get('ascii_host_url') for h in hosts][:10])
        return 1
    base = f'{API}/user/{uid}/hosts/{host_id}'
    since = (date.today() - timedelta(days=args.days)).isoformat()
    upto = date.today().isoformat()

    # ── 1. Сколько страниц в поиске, по дням ──────────────────────────────────
    section(f'СТРАНИЦ В ПОИСКЕ ПО ДНЯМ (за {args.days} дн.)')
    d = get(f'{base}/search-urls/in-search/history/'
            f'?date_from={since}&date_to={upto}', hdr)
    if '_error' in d:
        print('  не получено:', d['_error'])
    else:
        rows = d.get('history', [])
        if not rows:
            print('  данных нет')
        for r in rows:
            print(f'  {r.get("date", "")[:10]}  в поиске: {r.get("value")}')

    # ── 2. Индексация: обойдено и загружено ───────────────────────────────────
    section(f'ОБХОД РОБОТОМ ПО ДНЯМ (за {args.days} дн.)')
    d = get(f'{base}/indexing/history/?date_from={since}&date_to={upto}', hdr)
    if '_error' in d:
        print('  не получено:', d['_error'])
    else:
        for r in d.get('indexing', [])[-40:]:
            print(f'  {str(r.get("date", ""))[:10]}  обойдено: {r.get("crawled_url_count")}  '
                  f'в поиске: {r.get("searchable_url_count")}  ошибок: {r.get("failed_url_count")}')

    # ── 3. Популярные запросы: показы, клики, позиция ─────────────────────────
    section('ЗАПРОСЫ: показы, клики, средняя позиция')
    for order in ('TOTAL_SHOWS', 'TOTAL_CLICKS'):
        d = get(f'{base}/search-queries/popular/'
                f'?order_by={order}&query_indicator=TOTAL_SHOWS'
                f'&query_indicator=TOTAL_CLICKS&query_indicator=AVG_SHOW_POSITION'
                f'&date_from={since}&date_to={upto}', hdr)
        print(f'\n  — по {"показам" if order == "TOTAL_SHOWS" else "кликам"} —')
        if '_error' in d:
            print('   не получено:', d['_error'])
            continue
        for q in d.get('queries', [])[:25]:
            ind = q.get('indicators', {})
            print(f'   показов {str(ind.get("TOTAL_SHOWS")):>7}  '
                  f'кликов {str(ind.get("TOTAL_CLICKS")):>6}  '
                  f'позиция {str(ind.get("AVG_SHOW_POSITION")):>6}   {q.get("query_text", "")[:60]}')

    # ── 4. История показов и кликов целиком по сайту ──────────────────────────
    section('ПОКАЗЫ И КЛИКИ ПО САЙТУ, ПО ДНЯМ')
    d = get(f'{base}/search-queries/all/history/'
            f'?query_indicator=TOTAL_SHOWS&query_indicator=TOTAL_CLICKS'
            f'&date_from={since}&date_to={upto}', hdr)
    if '_error' in d:
        print('  не получено:', d['_error'])
    else:
        shows = {r['date'][:10]: r['value'] for r in d.get('indicators', {}).get('TOTAL_SHOWS', [])}
        clicks = {r['date'][:10]: r['value'] for r in d.get('indicators', {}).get('TOTAL_CLICKS', [])}
        for day in sorted(set(shows) | set(clicks)):
            print(f'  {day}  показов {str(shows.get(day, "—")):>8}  кликов {clicks.get(day, "—")}')

    # ── 5. Исключённые страницы и причины ─────────────────────────────────────
    section('ИСКЛЮЧЁННЫЕ ИЗ ПОИСКА СТРАНИЦЫ: причины')
    d = get(f'{base}/search-urls/excluded/samples/?limit=100', hdr)
    if '_error' in d:
        print('  не получено:', d['_error'])
    else:
        import collections
        reasons = collections.Counter()
        by_reason = collections.defaultdict(list)
        for s in d.get('samples', []):
            r = s.get('status') or s.get('excluded_url_status') or 'без причины'
            reasons[r] += 1
            if len(by_reason[r]) < 3:
                by_reason[r].append(s.get('url', ''))
        print(f'  всего в выборке: {d.get("count", len(d.get("samples", [])))}')
        for r, n in reasons.most_common():
            print(f'  {n:5}  {r}')
            for u in by_reason[r]:
                print(f'          {u}')

    # ── 6. Диагностика сайта ──────────────────────────────────────────────────
    section('ДИАГНОСТИКА САЙТА (что Вебмастер считает проблемой)')
    d = get(f'{base}/diagnostics/', hdr)
    if '_error' in d:
        print('  не получено:', d['_error'])
    else:
        for p in d.get('problems', []):
            print(f'  [{p.get("severity")}] {p.get("problem_type")}  состояние: {p.get("state")}')

    # ── 7. Конкретные адреса ──────────────────────────────────────────────────
    if args.url:
        section('СОСТОЯНИЕ КОНКРЕТНЫХ СТРАНИЦ')
        for u in args.url:
            q = urllib.parse.quote(u, safe='')
            d = get(f'{base}/search-urls/in-search/samples/?limit=100', hdr)
            print(f'\n  {u}')
            info = get(f'{base}/indexing/samples/?limit=100', hdr)
            if '_error' in info:
                print('    не получено:', info['_error'])
            else:
                hit = [s for s in info.get('samples', []) if s.get('url') == u]
                print(f'    в выборке обойдённых: {"да" if hit else "нет (выборка неполная)"}')

    return 0


if __name__ == '__main__':
    sys.exit(main())
