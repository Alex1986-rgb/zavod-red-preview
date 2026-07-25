# Источники данных для extra_data.py

Модели вне import-catalog.json (78 шт) заполнены серийными диапазонами
из открытых каталогов производителей. Тип передачи и страна — надёжно;
передаточное/мощность — где источник даёт корректный для типоразмеров
диапазон, иначе поле пустое (не выводится).

- SEW-Eurodrive S (helical-worm): P 0.12–45 кВт — sew-eurodrive.com, S series
- Lenze GSS (helical-worm): 0.12–15 кВт, i до 1847 — lenze.com, GSS
- Transtecno CM/CMG: i 5–100, minitecno малой мощности — transtecno.com
- Varvel RS/RV (worm): i 5–100, P 0.5–24.9 кВт — varvel.com, RS series
- Watt Drive K (helical-bevel): до 55 кВт, K37–K187 — Watt Drive WG20
- Yilmaz N (моноблок helical-bevel): до 200+ кВт — yilmaz-reduktor, N/NR
- STM RMI (worm), PC/PR — stmspa.com
- Motovario S — helical-worm сериями 53–73

Даты обращения: июль 2026. ZR-аналог — внутренняя привязка завода,
в открытых источниках отсутствует, оставлен пустым.
