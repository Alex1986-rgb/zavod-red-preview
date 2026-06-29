# Завод Редукторов — сайт zavod-red.ru

Корпоративный сайт производителя промышленных **редукторов и мотор-редукторов**
ООО «НИИ АТТ» (Челябинск). Задача — приводить заявки на подбор/поставку редукторов
и импортозамещение приводов SEW, NORD, Bonfiglioli и др.

**Стек:** статический HTML + CSS + ванильный JS, без сборки. Заявки уходят на PHP-бэкенд
**CRM** (репозиторий [`zavod-red-crm`](https://github.com/Alex1986-rgb/zavod-red-crm)).

## 🔗 Быстрые ссылки

| | Ссылка |
|---|---|
| 🌐 Сайт | https://zavod-red.ru/ |
| 🧮 Калькулятор подбора | `/podbor.html` · встроен и в страницы каталога |
| 🚀 **Инструкция по заливке на хостинг** | [zavod-red-crm → DEPLOY-TIMEWEB.md](https://github.com/Alex1986-rgb/zavod-red-crm/blob/main/DEPLOY-TIMEWEB.md) |
| ♻️ Авто-деплой обновлений | [zavod-red-crm → AUTODEPLOY.md](https://github.com/Alex1986-rgb/zavod-red-crm/blob/main/AUTODEPLOY.md) |
| 🎨 Брендбук | [BRANDBOOK.md](BRANDBOOK.md) · визуальный `/brandbook.html` |

---

## Что внутри (131 страница)

| Раздел | Содержание |
|---|---|
| `index.html` | Главная: hero-видео, преимущества, типы редукторов, производство (3D-модели, видео), FAQ, форма |
| `catalog/` | Каталог по типам (червячные, цилиндрические, конические, плоские, соосные, планетарные, вариаторы, EVL) — **со встроенным калькулятором по типу** |
| `podbor.html` | **Калькулятор подбора** по параметрам — 134 модели, 8 680 строк (из заводской таблицы), с ГОСТ-обозначениями и импортными аналогами |
| `brands/` | Импортозамещение по 22 брендам (SEW, NORD, Bonfiglioli, Motovario, Flender…) |
| `blog/` | **70+ SEO-статей** (в т.ч. 20 под калькулятор: подбор по мощности/моменту/оборотам/передаточному, расчёты, аналоги) |
| `cases/` | Кейсы выполненных подборов |
| `uslugi/` | Услуги: подбор, производство, аналоги, документация, доставка |
| `ceny.html` | Цены по линейкам EVL / ПР / МР (импортные — «по запросу») |
| `importozameshchenie.html`, `proizvoditelyam-oborudovaniya.html` | Money-страницы: замена импорта, OEM-поставки |
| Тех. страницы | `oplata.html` (оплата+реквизиты), `garantiya.html`, `spasibo.html` (thank-you, цель Метрики), `sitemap.html`, `contacts`, `about`, `sertifikaty`, `dostavka-raschet`, `privacy`, `agreement`, `404` |

**Ассеты** — `assets/`: `inner.css`, `modal.js` (форма, темы, мобильная CTA, чат),
`podbor.js` + `podbor.css` + `podbor-data.json` (калькулятор), `case.css`, изображения (webp),
видео (по клику), 3D-модели (лениво). Главная — на инлайн-CSS.

---

## Ключевые возможности

- **Калькулятор подбора** — фильтр по мощности/моменту/оборотам/передаточному → подходящие
  типоразмеры EVL с ГОСТ и импортными аналогами; залочен по типу на страницах каталога.
- **Адаптивность** 320–1920px; тёмная/светлая тема; бургер ≤1240px.
- **SEO**: уникальные `title` (≤60) и `description` (≤160) на каждой странице, schema.org
  (`Organization`, `Product`, `Article`, `FAQPage`, `BreadcrumbList`, `HowTo`), хлебные крошки,
  `sitemap.xml` + `sitemap.html`, `robots.txt`.
- **Конверсия**: модальная форма + форма подбора, захват UTM/referrer (first-touch),
  цель Метрики `reachGoal('zayavka')`, honeypot, страница «Спасибо».
- **Производительность**: gzip + кэширование (`.htaccess`), webp-картинки с `loading="lazy"`,
  видео «по клику», 3D — лениво по скроллу.
- **Лиды** → `api/feedback.php` (CRM) → MySQL + Telegram + e-mail.

---

## Локальный запуск

```bash
cd zavod_preview
python3 -m http.server 4137
# открыть http://127.0.0.1:4137/index.html
```
> Примечание: `python -m http.server` однопоточный и медленный. Реальная скорость —
> на боевом Apache/nginx, где работает `.htaccess` (gzip + кэш).

## Заливка на хостинг (кратко)

Релиз: новый сайт + CRM на основной домен **zavod-red.ru** (хостинг Timeweb). Полная
пошаговая инструкция — **[DEPLOY-TIMEWEB.md](https://github.com/Alex1986-rgb/zavod-red-crm/blob/main/DEPLOY-TIMEWEB.md)**.

1. ⚠️ Бэкап старого сайта (файлы + БД).
2. Создать БД MySQL в панели Timeweb.
3. Залить архив `zavod-red_FULL_DEPLOY.zip` (сайт + CRM, ~32 МБ) в корень `public_html/` → распаковать.
4. `api/config.sample.php` → `api/config.php` (доступы к БД).
5. Открыть `/migrations/install.php` → создать админа → удалить `/migrations`.
6. Войти в `/admin/`, ввести ключи; настроить cron.

> Боевая версия собирается prod-трансформацией (Метрика в `<head>`, открытый `robots.txt`).
> При правках CSS/JS поднимать версию (`inner.css?v=N`) для сброса кэша.

---

## Разработка и маркетинг

**Кырлан Александр Сергеевич** — разработка сайта, SEO, CRM, автоматизация.
📞 **+7 925 733-86-48**

**Заказчик:** ООО «НИИ АТТ» · Челябинск, пр-т Ленина, д. 2, оф. 221 · ИНН 7452136680
Тел. +7 (495) 151-41-02, +7 (904) 953-41-02 · zr@zavod-red.ru
</content>
