# Завод Редукторов (zavod-red.ru) — Обзор

Корпоративный сайт производителя промышленных редукторов и мотор-редукторов ZR
(ООО «НИИ АТТ», Челябинск). Задача — приводить заявки на подбор/поставку и импортозамещение
приводов SEW, NORD, Bonfiglioli и др. Этот репозиторий — превью/исходник сайта; боевая
версия собирается prod-трансформацией и льётся на хостинг Timeweb.

## Стек и структура

Статический HTML + CSS + ванильный JS, **без сборщика фронта** (Node/npm нет). Часть
инструментария — Python 3 (генерация статей, сборка dist, ускоритель индексации). Заявки
уходят на PHP-бэкенд (`api/`), основной CRM — в отдельном репо `zavod-red-crm`.

Ключевые папки:
- `catalog/`, `brands/`, `blog/`, `cases/`, `uslugi/`, `otrasli/`, `glossary/` — контент-разделы (HTML).
- `reduktor/`, `motor-reduktor-zr/`, `ispolnenie/`, `tiporazmer/`, `analog/` — генерируемые карточки/аналоги (десятки тысяч файлов).
- `assets/` — `inner.css`, `modal.js`, `podbor.js`+`podbor-data.json` (калькулятор), формы, шапка, изображения (webp), видео, 3D.
- `api/` — PHP: приём лидов, регистрация/логин кабинета, заказы.
- `tools/`, `deploy/` — Python-скрипты генерации и сборки боевой версии.
- `dist/` — результат prod-сборки (не редактировать руками).

## Как запускать

Локальный dev-сервер (статика) — из `.claude/launch.json`, конфиг `zavod-preview-local`:
```bash
python3 -m http.server 4188 --directory /Users/alexandr/projects/zavod-red-preview
# открыть http://127.0.0.1:4188/index.html
```
Сборка боевой версии (Метрика в `<head>`, открытый `robots.txt`) → в `dist/`:
```bash
python3 deploy/build-dist.py
```
Деплой — через GitHub Actions (`.github/workflows/`: `deploy-site-lftp.yml`,
`deploy-analog-lftp.yml`, `index-accelerator.yml`) по FTP на Timeweb. Полная инструкция —
в репо `zavod-red-crm` (`DEPLOY-TIMEWEB.md`, `AUTODEPLOY.md`). Локально `python -m http.server`
однопоточный и не выполняет `.htaccess` (gzip/кэш) — реальная скорость только на боевом Apache/nginx.

## Ключевые файлы

- `index.html` — главная (hero-видео, типы редукторов, производство, FAQ, форма).
- `podbor.html` + `assets/podbor.js` + `assets/podbor-data.json` — калькулятор подбора по параметрам.
- `assets/modal.js`, `assets/forms.js` — модальная форма, темы, мобильная CTA, захват UTM.
- `assets/inner.css`, `assets/hdr.css`, `css/` — стили (главная — на инлайн-CSS).
- `api/feedback.php` / `api/store.php` — приём лидов и заказов в CRM.
- `.htaccess` — gzip, кэш, редиректы (боевая конфигурация отдачи).
- `deploy/build-dist.py` — prod-сборка в `dist/`.
- `tools/gen_brand_articles.py`, `tools/gen_type_articles.py` — генераторы SEO-статей.
- `index_accelerator.py` — ускоритель индексации (sitemap + IndexNow).
- `README.md`, `BRANDBOOK.md`, `AI-STACK.md` — обзор, бренд-гайд, памятка по AI-инструментам.

## Рабочие принципы (AI-агенты)

- Гигиена контекста: чем меньше в контексте, тем точнее модель. Большую задачу дроби на тикеты, каждый — в чистом контексте.
- Цикл разработки: `/grill-with-docs` → `/to-spec` → `/to-tickets` → `/implement` → `/code-review` (глобальные скиллы).
- Субагенты живут в `.claude/agents/`, оркестраторы-стадии — в `.claude/commands/` (слэш-команды). Инструмент, не вписанный в инструкцию агента, агент не использует.
- Память проекта — по скиллу `llm-wiki` (index.md → entities/ → concepts/). Мультиагентные системы — по скиллу `agentic-system`.

Локальные скиллы/агенты этого репо: скилл `zavod-content` (канон контента, `.claude/skills/zavod-content/SKILL.md`)
и агент `zavod-content-writer` (`.claude/agents/`) — для статей блога, глоссария и текстов страниц строго по канону.
Аудит/улучшение сайта — глобальный скилл `zavod-improve`.

## AI-стек

Полная памятка по AI-инструментам (Firecrawl/Exa/Pencil, скиллы) — см. [AI-STACK.md](AI-STACK.md).

## Подводные камни

- **Не коммитить секреты**: `api/config.local.php` в `.gitignore`; доступы к БД — из `config.local.sample.php`.
- **Боевой деплой** идёт на живой домен zavod-red.ru (Timeweb) через GitHub Actions по FTP — не запускать деплой без явной просьбы.
- **Превью-репозиторий чистый**: Метрика отключена, prod-правки (счётчик в `<head>`, открытый robots) делает только `deploy/build-dist.py`; руками `dist/` не править.
- **Сброс кэша**: при правках CSS/JS поднимать версию (`inner.css?v=N`), иначе клиент увидит старьё.
- **`analog/`** (десятки тысяч файлов) исключён из основной сборки `dist/` — его льёт отдельный воркфлоу `deploy-analog-lftp.yml` (флаг `INCLUDE_ANALOG=1`).
- **Большие артефакты**: `.index-state.json` (~15 МБ), sitemap-*.xml, `yandex-market.yml` — генерируемые/тяжёлые, не редактировать вручную.

## Рабочие процессы Claude Code

- **Параллельные сессии**: `claude --worktree <имя>` — отдельная копия репозитория на своей ветке; две задачи в двух терминалах, правки не сталкиваются. Локальные файлы вне гита, нужные для работы, перечислены в `.worktreeinclude` — они копируются в worktree автоматически.
- **План перед правками**: `Shift+Tab` до режима `plan` (или `claude --permission-mode plan`) — Claude сначала предлагает план и не трогает файлы до одобрения. Полезно для рискованных правок (деплой, массовые замены).
- **Субагенты для разведки**: «use a subagent to выяснить …» — чтение кода идёт в отдельном контексте, в диалог возвращается только вывод; основной контекст не засоряется.
- **Продолжение работы**: `claude --continue` — последняя сессия в этой папке; `claude --resume` — выбор из списка; `claude --from-pr <N>` — найти сессию, из которой родился PR.
- **@-ссылки**: `@путь/файл` кладёт файл в контекст целиком, `@папка/` — листинг; по `@` работает автодополнение путей.
- **Пайпы (headless)**: `git log --oneline -20 | claude -p "суммаризуй"` — Claude как unix-утилита для скриптов и CI.
- **Задачи по расписанию**: Routines (claude.ai/code/routines — облако Anthropic, работает при выключенном Mac, триггеры по GitHub-событиям), scheduled tasks desktop-приложения (доступ к локальным файлам), GitHub Actions, `/loop` в открытой сессии.
