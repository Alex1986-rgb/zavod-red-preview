<?php
declare(strict_types=1);

/**
 * /api/backup.php — резервное копирование БД (mysqldump → .sql.gz) + ротация.
 *
 *   action=run    (auth admin + csrf) — сделать бэкап сейчас → JSON {ok,file,size}
 *   action=status (auth)              — последний бэкап + сколько всего файлов
 *
 * CLI (cron): `php api/backup.php` — do_backup() + печать JSON.
 *   0 3 * * * php /path/api/backup.php
 *
 * Хранятся последние 14 файлов в crm-data/backups/, старые удаляются.
 * STRICT UTF-8. Все shell-аргументы через escapeshellarg.
 */

require_once __DIR__ . '/helpers.php';

/** Каталог хранения бэкапов. */
function backup_dir(): string {
    return __DIR__ . '/../crm-data/backups';
}

/**
 * Делает дамп БД через `mysqldump | gzip` и пишет в crm-data/backups.
 * Возвращает {ok,file,size} либо {ok:false,error}.
 */
function do_backup(): array {
    if (!function_exists('shell_exec')) {
        return ['ok' => false, 'error' => 'shell_exec отключён в PHP (disable_functions) — бэкап через mysqldump недоступен'];
    }

    $dir = backup_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
        return ['ok' => false, 'error' => 'Каталог для бэкапов недоступен: ' . $dir];
    }
    if (!is_writable($dir)) {
        return ['ok' => false, 'error' => 'Каталог для бэкапов недоступен для записи: ' . $dir];
    }

    // Поиск рабочего mysqldump.
    $bin = null;
    foreach (['mysqldump', '/opt/homebrew/opt/mysql/bin/mysqldump'] as $cand) {
        $check = @shell_exec(escapeshellarg($cand) . ' --version 2>&1');
        if (is_string($check) && stripos($check, 'mysqldump') !== false) {
            $bin = $cand;
            break;
        }
    }
    if ($bin === null) {
        return ['ok' => false, 'error' => 'mysqldump не найден (пробовали mysqldump и /opt/homebrew/opt/mysql/bin/mysqldump)'];
    }

    $gzip = @shell_exec('gzip --version 2>&1');
    if (!is_string($gzip) || stripos($gzip, 'gzip') === false) {
        return ['ok' => false, 'error' => 'gzip не найден в системе'];
    }

    $d = cfg()['db'];
    $file = $dir . '/dump_' . date('Y-m-d_Hi') . '.sql.gz';

    $cmd = escapeshellarg($bin)
        . ' --single-transaction --quick --default-character-set=utf8mb4'
        . ' --host=' . escapeshellarg((string)($d['host'] ?? 'localhost'))
        . ' --user=' . escapeshellarg((string)($d['user'] ?? ''))
        . ' --password=' . escapeshellarg((string)($d['pass'] ?? ''))
        . ' ' . escapeshellarg((string)($d['name'] ?? ''))
        . ' 2>' . escapeshellarg($file . '.err')
        . ' | gzip -c > ' . escapeshellarg($file);

    @shell_exec($cmd);

    $errFile = $file . '.err';
    $err = is_file($errFile) ? trim((string)@file_get_contents($errFile)) : '';
    @unlink($errFile);

    $size = is_file($file) ? (int)@filesize($file) : 0;
    // gzip пустого/ошибочного дампа всё равно даёт несколько байт заголовка → считаем валидным от 64 байт.
    if ($size < 64) {
        @unlink($file);
        $msg = $err !== '' ? $err : 'mysqldump вернул пустой результат';
        return ['ok' => false, 'error' => 'Бэкап не создан: ' . $msg];
    }

    backup_rotate(14);

    return ['ok' => true, 'file' => basename($file), 'size' => $size];
}

/** Оставить последние $keep файлов dump_*.sql.gz, остальные удалить. */
function backup_rotate(int $keep = 14): void {
    $files = backup_list();
    $extra = array_slice($files, $keep); // отсортированы новые → старые
    foreach ($extra as $f) {
        @unlink($f['path']);
    }
}

/** Список бэкапов, новые первыми: [['path','name','size','mtime'], ...]. */
function backup_list(): array {
    $dir = backup_dir();
    $out = [];
    foreach ((array)@glob($dir . '/dump_*.sql.gz') as $path) {
        if (!is_file($path)) continue;
        $out[] = [
            'path'  => $path,
            'name'  => basename($path),
            'size'  => (int)@filesize($path),
            'mtime' => (int)@filemtime($path),
        ];
    }
    usort($out, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    return $out;
}

/* ===== CLI-вход (cron) ===== */
if (php_sapi_name() === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    cron_heartbeat('backup'); // пульс для health.php (фиксируем сам факт запуска крона)
    $res = do_backup();
    echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit($res['ok'] ? 0 : 1);
}

/* ===== HTTP-вход (только когда backup.php запрошен напрямую, не при include) ===== */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== realpath(__FILE__)) {
    return; // подключён как библиотека (например, из health.php) — роутер не выполняем
}
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

switch ($action) {
    case 'run':
        require_auth('admin');
        csrf_check();
        try {
            $res = do_backup();
            json_out($res, ($res['ok'] ?? false) ? 200 : 500);
        } catch (Throwable $e) {
            json_out(['ok' => false, 'error' => 'Ошибка бэкапа: ' . $e->getMessage()], 500);
        }
        // no break (json_out exits)

    case 'status':
        require_auth();
        try {
            $files = backup_list();
            $last = $files[0] ?? null;
            json_out([
                'ok'    => true,
                'total' => count($files),
                'last'  => $last ? [
                    'name' => $last['name'],
                    'size' => $last['size'],
                    'date' => date('Y-m-d H:i', $last['mtime']),
                    'age_hours' => round((time() - $last['mtime']) / 3600, 1),
                ] : null,
            ]);
        } catch (Throwable $e) {
            json_out(['ok' => false, 'error' => 'Ошибка статуса: ' . $e->getMessage()], 500);
        }

    default:
        json_out(['ok' => false, 'error' => 'Неизвестное действие'], 400);
}
