---
description: Привести все карточки zavod-red.ru к единому «pro»-образу (аудит → фикс → деплой)
---

Оркестратор приведения карточек сайта к эталону `/analog/nord-9016-1-i22-71-4kvt`.

Стандарт — в скилле `zavod-card-standard`. Семьи: analog, reduktor, motor-reduktor-zr,
ispolnenie, tiporazmer.

## Шаги

1. **Аудит парка.** Запусти субагента `zavod-card-auditor` (scope: все семьи) →
   получи таблицу PRO/PARTIAL/THIN и недостающие признаки. Или напрямую:
   `python3 tools/card_audit.py --list-bad 15`.

2. **План.** Для каждой не-PRO семьи определи тип фикса:
   - структурный (перенос/переклассовка при готовом контенте) — делать сразу;
   - генерация контента на тонких long-tail (ispolnenie/tiporazmer) — согласовать объём
     с оператором до массового прогона (риск near-duplicate на десятках тысяч страниц).

3. **Фикс по семьям.** На каждую семью — субагент `zavod-card-fixer` (по одной за раз).
   Цель — `PRO N/N` в `card_audit.py`. Проверка образцов через `querySelectorAll`.

4. **Деплой.** commit + push; `gh workflow run deploy-site-ssh.yml`
   (для analog — `deploy-analog-ssh.yml`, `INCLUDE_ANALOG=1`). Дождись success,
   проверь на боевом порядок секций и наличие блоков.

5. **Отчёт оператору:** было→стало по каждой семье, что задеплоено, что осталось.

Аргументы (`$ARGUMENTS`): можно указать конкретную семью — тогда цикл только по ней.
