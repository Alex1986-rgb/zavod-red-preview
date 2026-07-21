#!/usr/bin/env python3
"""Локальный сервер, повторяющий поведение боевого Apache для предпросмотра перед деплоем.

Обычный `python -m http.server dist/` для проверки НЕ годится: он не выполняет .htaccess,
поэтому все внутренние ссылки сайта (они без .html) отдают 404, а папки analog/ в dist
вообще нет — её льёт отдельный воркфлоу. Получается «сайт есть, а кликать нельзя».

Здесь эмулируется ровно то, что делает прод:
  * ЧПУ без расширения:      /brands/tramec        → dist/brands/tramec.html
  * каталог:                 /catalog/             → dist/catalog/index.html
  * analog/ из исходников:   /analog/<slug>        → <repo>/analog/<slug>.html
                             (в dist его нет по построению, но на боевом он есть)
  * 301-редиректы .htaccess: старые слаги sew-tramec → tramec
  * 404-страница проекта вместо голого ответа сервера.

Запуск: python3 deploy/serve-prod-like.py [порт]
"""
import http.server, os, posixpath, re, socketserver, sys, urllib.parse

REPO = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DIST = os.path.join(REPO, 'dist')
ANALOG = os.path.join(REPO, 'analog')          # в dist не попадает — берём из исходников
PORT = int(sys.argv[1]) if len(sys.argv) > 1 else 8099

# Те же 301, что в .htaccess: выдуманный бренд «SEW Tramec» → реальный Tramec
REDIRECTS = [
    (re.compile(r'^/brands/sew-tramec/?$'), lambda m: '/brands/tramec'),
    (re.compile(r'^/blog/(.*)sew-tramec(.*)$'), lambda m: f'/blog/{m.group(1)}tramec{m.group(2)}'),
    (re.compile(r'^/analog/sew-tramec-(.*?)/?$'), lambda m: f'/analog/tramec-{m.group(1)}'),
]

# ZR-слаги карточек → внутренние EVL-слаги (сайт в маркировке ZR, URL исторически EVL).
# На проде это 104 отдельных правила в .htaccess; здесь читаем их оттуда, чтобы превью
# не расходилось с боевым — иначе ссылки из корзины и избранного выглядят битыми, хотя живые.
def _zr_map():
    m = {}
    try:
        rx = re.compile(r'RewriteRule \^reduktor/(zr-[^/\s]+?)/\?\$.*?/reduktor/(evl-[^\s]+)')
        for line in open(os.path.join(REPO, '.htaccess'), encoding='utf-8'):
            g = rx.search(line)
            if g:
                m['/reduktor/' + g.group(1)] = '/reduktor/' + g.group(2)
    except OSError:
        pass
    return m


ZR_SLUGS = _zr_map()


class Handler(http.server.SimpleHTTPRequestHandler):
    def translate_path(self, path):
        p = urllib.parse.urlsplit(path).path
        p = posixpath.normpath(urllib.parse.unquote(p)).lstrip('/')
        # analog/ живёт в исходниках, всё остальное — в собранном dist/.
        # normpath срезает хвостовой слэш, поэтому «/analog/» приходит как «analog» —
        # проверяем оба вида, иначе индекс раздела уходит искать в dist и даёт ложный 404.
        root = REPO if p == 'analog' or p.startswith('analog/') else DIST
        full = os.path.join(root, p)
        if os.path.isdir(full):
            idx = os.path.join(full, 'index.html')
            if os.path.isfile(idx):
                return idx
        if os.path.isfile(full):
            return full
        if os.path.isfile(full + '.html'):     # ЧПУ без расширения — как на проде
            return full + '.html'
        return full

    def send_head(self):
        p = urllib.parse.urlsplit(self.path).path
        target = ZR_SLUGS.get(p.rstrip('/'))
        if not target:
            for rx, to in REDIRECTS:
                m = rx.match(p)
                if m:
                    target = to(m)
                    break
        if target:
            self.send_response(301)
            self.send_header('Location', target)
            self.end_headers()
            return None
        return super().send_head()

    def send_error(self, code, message=None, explain=None):
        page = os.path.join(DIST, '404.html')
        if code == 404 and os.path.isfile(page):
            body = open(page, 'rb').read()
            self.send_response(404)
            self.send_header('Content-Type', 'text/html; charset=utf-8')
            self.send_header('Content-Length', str(len(body)))
            self.end_headers()
            self.wfile.write(body)
            return
        super().send_error(code, message, explain)

    def log_message(self, *a):
        pass                                    # без шума в консоли


class Server(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True                       # 73k страниц analog — нужен параллелизм


if __name__ == '__main__':
    print(f'dist:   {DIST}\nanalog: {ANALOG}\nhttp://localhost:{PORT}')
    Server(('', PORT), Handler).serve_forever()
