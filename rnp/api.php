<?php
/**
 * РНП отдела продаж — хранилище и вход. ООО «НИИ АТТ».
 * Кладётся рядом с index.html. Ничего не требует: ни базы данных, ни расширений.
 * Данные лежат в файле rnp-data.php в этой же папке.
 */

ini_set('display_errors', '0');
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate');

define('RNP_DATA', __DIR__ . '/rnp-data.php');
define('RNP_LOCK', __DIR__ . '/rnp-data.lock');
define('RNP_GUARD', "<?php exit; ?>\n");
define('RNP_MAX_DOC', 400000); // предел на один документ месяца, байт

/* ───────────── служебное ───────────── */

function out($a) { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
function fail($code, $http = 400) { http_response_code($http); out(array('error' => $code)); }

function body() {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return array();
    $j = json_decode($raw, true);
    return is_array($j) ? $j : array();
}

function blank_data() { return array('people' => array(), 'pw' => array(), 'months' => array()); }

function read_data() {
    if (!file_exists(RNP_DATA)) return blank_data();
    $raw = file_get_contents(RNP_DATA);
    if ($raw === false) return blank_data();
    $pos = strpos($raw, "\n");
    $json = ($pos === false) ? '' : substr($raw, $pos + 1);
    $d = json_decode($json, true);
    if (!is_array($d)) return blank_data();
    foreach (array('people', 'pw', 'months') as $k) {
        if (!isset($d[$k]) || !is_array($d[$k])) $d[$k] = array();
    }
    return $d;
}

function write_data($d) {
    $tmp = RNP_DATA . '.' . getmypid() . '.tmp';
    $json = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) fail('encode_failed', 500);
    if (@file_put_contents($tmp, RNP_GUARD . $json) === false) { @unlink($tmp); fail('write_failed', 500); }
    if (!@rename($tmp, RNP_DATA)) { @unlink($tmp); fail('write_failed', 500); }
    @chmod(RNP_DATA, 0640);
}

function lock_open() { $f = @fopen(RNP_LOCK, 'c'); if ($f) @flock($f, LOCK_EX); return $f; }
function lock_close($f) { if ($f) { @flock($f, LOCK_UN); @fclose($f); } }

function find_person($d, $id) {
    foreach ($d['people'] as $p) if (isset($p['id']) && $p['id'] === $id) return $p;
    return null;
}
function find_by_login($d, $login) {
    $login = mb_strtolower(trim($login), 'UTF-8');
    foreach ($d['people'] as $p) {
        if (isset($p['login']) && $p['login'] !== '' && mb_strtolower($p['login'], 'UTF-8') === $login) return $p;
    }
    return null;
}
function me_of($d) {
    if (empty($_SESSION['rnp_pid'])) return null;
    $p = find_person($d, $_SESSION['rnp_pid']);
    if (!$p) return null;
    return array(
        'pid'   => $p['id'],
        'name'  => isset($p['name']) ? $p['name'] : '',
        'login' => isset($p['login']) ? $p['login'] : '',
        'admin' => !empty($p['admin']),
    );
}
function valid_month($m) { return is_string($m) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m) === 1; }
function valid_id($s)    { return is_string($s) && preg_match('/^[A-Za-z0-9_\-]{1,40}$/', $s) === 1; }
function valid_login($s) { return is_string($s) && preg_match('/^[A-Za-z0-9_.\-]{2,32}$/', $s) === 1; }

function clean_doc($doc) {
    if (!is_array($doc)) return array();
    $o = array();
    $o['daily']   = (isset($doc['daily'])   && is_array($doc['daily'])   && count($doc['daily']))   ? $doc['daily']   : new stdClass();
    $o['clients'] = (isset($doc['clients']) && is_array($doc['clients'])) ? array_values($doc['clients']) : array();
    $o['journal'] = (isset($doc['journal']) && is_array($doc['journal'])) ? array_values($doc['journal']) : array();
    $o['plan']    = (isset($doc['plan'])    && is_array($doc['plan'])    && count($doc['plan']))    ? $doc['plan']    : new stdClass();
    return $o;
}

session_name('rnpsid');
if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
           || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    session_set_cookie_params(0, '/', '', $secure, true);
    session_start();
}

$a = isset($_GET['a']) ? $_GET['a'] : '';

/* ───────────── кто я ───────────── */

if ($a === 'whoami') {
    $d = read_data();
    if (!count($d['people'])) out(array('ok' => true, 'setup' => true, 'me' => null));
    out(array('ok' => true, 'setup' => false, 'me' => me_of($d)));
}

/* ───────────── первый запуск ───────────── */

if ($a === 'setup') {
    $b = body();
    $name = isset($b['name']) ? trim($b['name']) : '';
    $login = isset($b['login']) ? trim($b['login']) : '';
    $pass = isset($b['password']) ? (string)$b['password'] : '';
    if ($name === '' || !valid_login($login) || strlen($pass) < 6) fail('bad_input');

    $f = lock_open();
    $d = read_data();
    if (count($d['people'])) { lock_close($f); fail('already_set'); }
    $id = 'p' . substr(md5($login . microtime(true)), 0, 10);
    $d['people'][] = array('id' => $id, 'name' => $name, 'role' => 'sales',
                           'admin' => true, 'login' => $login, 'plan' => new stdClass());
    $d['pw'][$id] = password_hash($pass, PASSWORD_DEFAULT);
    write_data($d);
    lock_close($f);

    session_regenerate_id(true);
    $_SESSION['rnp_pid'] = $id;
    out(array('ok' => true, 'me' => me_of(read_data())));
}

/* ───────────── вход и выход ───────────── */

if ($a === 'login') {
    $b = body();
    $login = isset($b['login']) ? trim($b['login']) : '';
    $pass = isset($b['password']) ? (string)$b['password'] : '';
    $d = read_data();
    $p = find_by_login($d, $login);
    $hash = ($p && isset($d['pw'][$p['id']])) ? $d['pw'][$p['id']] : null;
    if (!$p || !$hash || !password_verify($pass, $hash)) {
        usleep(400000); // притормаживаем перебор
        fail('bad_credentials', 401);
    }
    session_regenerate_id(true);
    $_SESSION['rnp_pid'] = $p['id'];
    out(array('ok' => true, 'me' => me_of($d)));
}

if ($a === 'logout') {
    $_SESSION = array();
    if (ini_get('session.use_cookies')) {
        $c = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $c['path'], $c['domain'], $c['secure'], $c['httponly']);
    }
    session_destroy();
    out(array('ok' => true));
}

/* ───────────── дальше только для вошедших ───────────── */

$data = read_data();
$me = me_of($data);
if (!$me) fail('auth', 401);

/* ───────────── состояние за месяц ───────────── */

if ($a === 'state') {
    $m = isset($_GET['month']) ? $_GET['month'] : '';
    if (!valid_month($m)) fail('bad_month');
    $people = array();
    foreach ($data['people'] as $p) {
        $people[] = array(
            'id'    => isset($p['id']) ? $p['id'] : '',
            'name'  => isset($p['name']) ? $p['name'] : '',
            'role'  => (isset($p['role']) && $p['role'] === 'lead') ? 'lead' : 'sales',
            'admin' => !empty($p['admin']),
            'login' => $me['admin'] ? (isset($p['login']) ? $p['login'] : '') : '',
            'plan'  => (isset($p['plan']) && is_array($p['plan']) && count($p['plan'])) ? $p['plan'] : new stdClass(),
        );
    }
    $docs = isset($data['months'][$m]) && is_array($data['months'][$m]) ? $data['months'][$m] : new stdClass();
    out(array('ok' => true, 'me' => $me, 'people' => $people, 'docs' => $docs));
}

/* ───────────── запись цифр за месяц ───────────── */

if ($a === 'save') {
    $b = body();
    $m = isset($b['month']) ? $b['month'] : '';
    $pid = isset($b['person']) ? $b['person'] : '';
    if (!valid_month($m) || !valid_id($pid)) fail('bad_input');
    if (!$me['admin'] && $me['pid'] !== $pid) fail('forbidden', 403);

    $doc = clean_doc(isset($b['doc']) ? $b['doc'] : array());
    $enc = json_encode($doc, JSON_UNESCAPED_UNICODE);
    if ($enc === false || strlen($enc) > RNP_MAX_DOC) fail('too_big');

    $f = lock_open();
    $d = read_data();
    if (!find_person($d, $pid)) { lock_close($f); fail('no_person'); }
    if (!isset($d['months'][$m]) || !is_array($d['months'][$m])) $d['months'][$m] = array();
    $d['months'][$m][$pid] = $doc;
    write_data($d);
    lock_close($f);
    out(array('ok' => true));
}

/* ───────────── состав отдела (только администратор) ───────────── */

if ($a === 'roster') {
    if (!$me['admin']) fail('forbidden', 403);
    $b = body();
    $in = isset($b['people']) && is_array($b['people']) ? $b['people'] : null;
    if ($in === null) fail('bad_input');
    if (count($in) > 100) fail('too_many');

    $f = lock_open();
    $d = read_data();
    $old = array();
    foreach ($d['people'] as $p) if (isset($p['id'])) $old[$p['id']] = $p;

    $seen = array(); $logins = array(); $new = array(); $adminLeft = false;
    foreach ($in as $p) {
        if (!is_array($p)) continue;
        $id = isset($p['id']) && valid_id($p['id']) ? $p['id'] : ('p' . substr(md5(uniqid('', true)), 0, 10));
        if (isset($seen[$id])) continue;
        $seen[$id] = true;

        $login = isset($p['login']) ? trim((string)$p['login']) : '';
        if ($login !== '') {
            if (!valid_login($login)) { lock_close($f); fail('bad_login'); }
            $lk = mb_strtolower($login, 'UTF-8');
            if (isset($logins[$lk])) { lock_close($f); fail('login_taken'); }
            $logins[$lk] = true;
        }
        $isAdmin = !empty($p['admin']);
        // себя из администраторов не разжалуешь — иначе некому будет править список
        if ($id === $me['pid']) $isAdmin = true;
        if ($isAdmin) $adminLeft = true;

        $plan = (isset($p['plan']) && is_array($p['plan'])) ? $p['plan'] : array();
        $cleanPlan = array();
        foreach ($plan as $k => $v) {
            if (!preg_match('/^[A-Za-z]{1,20}$/', (string)$k)) continue;
            if (is_numeric($v)) $cleanPlan[$k] = $v + 0;
        }
        $new[] = array(
            'id'    => $id,
            'name'  => isset($p['name']) ? mb_substr(trim((string)$p['name']), 0, 60, 'UTF-8') : '',
            'role'  => (isset($p['role']) && $p['role'] === 'lead') ? 'lead' : 'sales',
            'admin' => $isAdmin,
            'login' => $login,
            'plan'  => $cleanPlan ? $cleanPlan : new stdClass(),
        );
    }
    if (!$adminLeft) { lock_close($f); fail('no_admin'); }
    if (!isset($seen[$me['pid']])) { lock_close($f); fail('cannot_remove_self'); }

    // пароли удалённых сотрудников подчищаем
    $pw = array();
    foreach ($d['pw'] as $id => $h) if (isset($seen[$id])) $pw[$id] = $h;

    $d['people'] = $new;
    $d['pw'] = $pw;
    write_data($d);
    lock_close($f);
    out(array('ok' => true));
}

/* ───────────── пароли ───────────── */

if ($a === 'passwd') { // администратор задаёт пароль сотруднику
    if (!$me['admin']) fail('forbidden', 403);
    $b = body();
    $pid = isset($b['person']) ? $b['person'] : '';
    $pass = isset($b['password']) ? (string)$b['password'] : '';
    if (!valid_id($pid) || strlen($pass) < 6) fail('bad_input');

    $f = lock_open();
    $d = read_data();
    $p = find_person($d, $pid);
    if (!$p) { lock_close($f); fail('no_person'); }
    if (!isset($p['login']) || $p['login'] === '') { lock_close($f); fail('no_login'); }
    $d['pw'][$pid] = password_hash($pass, PASSWORD_DEFAULT);
    write_data($d);
    lock_close($f);
    out(array('ok' => true));
}

if ($a === 'mypasswd') { // сотрудник меняет свой пароль
    $b = body();
    $old = isset($b['old']) ? (string)$b['old'] : '';
    $new = isset($b['password']) ? (string)$b['password'] : '';
    if (strlen($new) < 6) fail('bad_input');
    $hash = isset($data['pw'][$me['pid']]) ? $data['pw'][$me['pid']] : null;
    if (!$hash || !password_verify($old, $hash)) { usleep(400000); fail('bad_credentials', 403); }

    $f = lock_open();
    $d = read_data();
    $d['pw'][$me['pid']] = password_hash($new, PASSWORD_DEFAULT);
    write_data($d);
    lock_close($f);
    out(array('ok' => true));
}

fail('unknown_action', 404);
