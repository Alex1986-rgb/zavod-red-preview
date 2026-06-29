#!/usr/bin/env python3
# Уведомить Яндекс/Bing о всех URL сайта (IndexNow). Запуск: python3 indexnow-submit.py
import urllib.request, json, re, os
KEY="051a8bb09331b03c5e35d4a40339b5b3"
HOST="zavod-red.ru"
sm=open(os.path.join(os.path.dirname(__file__),"sitemap.xml"),encoding="utf-8").read()
urls=re.findall(r"<loc>([^<]+)</loc>", sm)
print(f"URL в sitemap: {len(urls)}")
payload={"host":HOST,"key":KEY,"keyLocation":f"https://{HOST}/{KEY}.txt","urlList":urls}
data=json.dumps(payload).encode()
for ep in ["https://api.indexnow.org/indexnow","https://yandex.com/indexnow"]:
    try:
        req=urllib.request.Request(ep,data=data,headers={"Content-Type":"application/json; charset=utf-8"},method="POST")
        r=urllib.request.urlopen(req,timeout=20)
        print(f"  {ep} → {r.status} (отправлено {len(urls)} URL)")
    except Exception as e:
        print(f"  {ep} → {str(e)[:60]}")
