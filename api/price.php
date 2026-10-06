<?php
declare(strict_types=1);

/**
 * Прайс: цена и срок по ZR-коду.
 *
 * Пока файла нет или строки в нём нет — система обязана писать «уточняется»,
 * а не подставлять число. Причина зафиксирована в docs/skript-razgovora.md:
 * колонка price_from в analogs.csv содержит 4 значения на 5453 позиции (заглушка
 * по типу редуктора), а реальный счёт на ZR 959 — 184 900 ₽ против 26 130 ₽ в базе.
 * Разница в семь раз, поэтому автоматическая цена оттуда — обещание, которое завод
 * не выполнит.
 *
 * Как включить цены: заполнить crm-data/price.csv (шаблон лежит рядом, шапка та же).
 * Ничего больше делать не нужно — письма и КП начнут собираться с ценой и сроком сами.
 *
 * Формат crm-data/price.csv:
 *   zr_code,name,price_rub,lead_days,in_stock,note
 *   ZR 959,Мотор-редуктор ZR 959 ФЦ,184900,14,0,цена за 1 шт с НДС
 */

function price_path(): string { return __DIR__ . '/../crm-data/price.csv'; }

/** Нормализация кода: «ZR 959», «zr-959», «ZR959» — одно и то же. */
function price_norm(string $code): string {
    $s = mb_strtoupper(trim($code), 'UTF-8');
    return (string)preg_replace('/[^A-Z0-9]/u', '', $s);
}

/** Загружен ли прайс и сколько в нём позиций (для админки и для отчётов). */
function price_status(): array {
    $p = price_path();
    if (!is_file($p)) return ['ready' => false, 'rows' => 0, 'updated' => ''];
    $rows = 0;
    if ($fh = fopen($p, 'r')) {
        fgetcsv($fh);
        while (($r = fgetcsv($fh)) !== false) {
            if (($r[0] ?? '') !== '' && (string)($r[2] ?? '') !== '') $rows++;
        }
        fclose($fh);
    }
    return ['ready' => $rows > 0, 'rows' => $rows, 'updated' => date('d.m.Y H:i', (int)filemtime($p))];
}

/**
 * Цена и срок по ZR-коду или null, если позиции в прайсе нет.
 * Возвращает ['price'=>int, 'lead_days'=>int, 'in_stock'=>bool, 'note'=>string].
 */
function price_lookup(string $zr): ?array {
    static $map = null;
    if ($map === null) {
        $map = [];
        $p = price_path();
        if (is_file($p) && ($fh = fopen($p, 'r'))) {
            $hdr = fgetcsv($fh);
            $ix = is_array($hdr) ? array_flip(array_map('trim', $hdr)) : [];
            while (($r = fgetcsv($fh)) !== false) {
                $code = price_norm((string)($r[$ix['zr_code'] ?? 0] ?? ''));
                $price = (string)($r[$ix['price_rub'] ?? 2] ?? '');
                if ($code === '' || $price === '') continue;
                $map[$code] = [
                    'price'     => (int)round((float)str_replace([' ', ','], ['', '.'], $price)),
                    'lead_days' => (int)($r[$ix['lead_days'] ?? 3] ?? 0),
                    'in_stock'  => (string)($r[$ix['in_stock'] ?? 4] ?? '') === '1',
                    'note'      => (string)($r[$ix['note'] ?? 5] ?? ''),
                ];
            }
            fclose($fh);
        }
    }
    // В коде может быть несколько вариантов: «ZR 656/939» — берём первый подошедший.
    foreach (preg_split('~[,/]~', $zr) ?: [] as $part) {
        $k = price_norm($part);
        if ($k !== '' && isset($map[$k])) return $map[$k];
    }
    return null;
}

/** Человеческая строка цены для письма: из прайса либо честное «уточняется». */
function price_text(string $zr): string {
    $p = price_lookup($zr);
    if (!$p) return 'цену и срок подтвердит инженер';
    $s = number_format($p['price'], 0, ',', ' ') . ' ₽';
    if ($p['lead_days'] > 0) {
        $s .= $p['in_stock'] ? ', есть на складе' : ', срок изготовления ' . $p['lead_days'] . ' дн.';
    }
    if ($p['note'] !== '') $s .= ' (' . $p['note'] . ')';
    return $s;
}
