<?php
declare(strict_types=1);

/**
 * /api/kb.php — База знаний (аналоги, шильдики, FAQ, шаблоны КП, инструкции).
 *   GET  ?action=list[&category=&q=&sort=recent|popular]  список статей + счётчики категорий + популярное
 *   GET  ?action=get&id=      статья (инкремент просмотров)
 *   POST ?action=save (csrf)  id(опц), category, title, excerpt, body, tags → создать/обновить
 *   POST ?action=del  (csrf)  id
 * Таблица crm_kb создаётся автоматически при первом обращении.
 */

require_once __DIR__ . '/helpers.php';

/** Фиксированный набор категорий (slug => подпись). */
function kb_categories(): array {
    return [
        'analog-sew'         => 'Аналоги SEW',
        'analog-nord'        => 'Аналоги NORD',
        'analog-bonfiglioli' => 'Аналоги Bonfiglioli',
        'nameplates'         => 'Шильдики и расшифровка',
        'faq'                => 'Частые вопросы',
        'kp'                 => 'Шаблоны КП',
        'guides'             => 'Инструкции инженеру',
    ];
}

function kb_ensure(): void {
    static $done = false;
    if ($done) return;
    pdo()->exec(
        "CREATE TABLE IF NOT EXISTS crm_kb (
            id INT AUTO_INCREMENT PRIMARY KEY,
            category VARCHAR(40) NOT NULL DEFAULT 'faq',
            title VARCHAR(255) NOT NULL,
            excerpt VARCHAR(500) NOT NULL DEFAULT '',
            body MEDIUMTEXT NULL,
            tags VARCHAR(255) NOT NULL DEFAULT '',
            author VARCHAR(120) NOT NULL DEFAULT '',
            views INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_cat (category),
            KEY idx_views (views)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $done = true;
}

$user   = require_auth();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? ($_POST['action'] ?? 'list');

try {
    kb_ensure();
    if ($method === 'GET') {
        switch ($action) {
            case 'get':  kb_get();  break;
            case 'list': default: kb_list(); break;
        }
    } elseif ($method === 'POST') {
        csrf_check();
        switch ($action) {
            case 'save': kb_save($user); break;
            case 'del':  kb_del($user);  break;
            default: json_out(['ok' => false, 'error' => 'Неизвестное действие'], 400);
        }
    } else {
        json_out(['ok' => false, 'error' => 'Метод не поддерживается'], 405);
    }
} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => 'Внутренняя ошибка'], 500);
}

function kb_list(): void {
    $cats = kb_categories();
    $cat  = (string)($_GET['category'] ?? '');
    $q    = trim((string)($_GET['q'] ?? ''));
    $sort = ($_GET['sort'] ?? 'recent') === 'popular' ? 'views DESC' : 'updated_at DESC';

    $where = [];
    $args  = [];
    if ($cat !== '' && isset($cats[$cat])) { $where[] = 'category = ?'; $args[] = $cat; }
    if ($q !== '') {
        $where[] = '(title LIKE ? OR excerpt LIKE ? OR tags LIKE ? OR body LIKE ?)';
        $like = '%' . $q . '%';
        array_push($args, $like, $like, $like, $like);
    }
    $wsql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $st = pdo()->prepare("SELECT id, category, title, excerpt, tags, author, views, updated_at
                          FROM crm_kb $wsql ORDER BY $sort LIMIT 200");
    $st->execute($args);
    $rows = $st->fetchAll();

    // счётчики по категориям
    $counts = [];
    foreach (array_keys($cats) as $slug) $counts[$slug] = 0;
    foreach (pdo()->query("SELECT category, COUNT(*) c FROM crm_kb GROUP BY category") as $r) {
        $counts[$r['category']] = (int)$r['c'];
    }

    $popular = pdo()->query("SELECT id, title, views FROM crm_kb ORDER BY views DESC LIMIT 5")->fetchAll();
    $updated = pdo()->query("SELECT id, title, updated_at FROM crm_kb ORDER BY updated_at DESC LIMIT 5")->fetchAll();

    json_out([
        'ok' => true,
        'categories' => $cats,
        'counts' => $counts,
        'total' => (int)pdo()->query("SELECT COUNT(*) FROM crm_kb")->fetchColumn(),
        'articles' => $rows,
        'popular' => $popular,
        'updated' => $updated,
    ]);
}

function kb_get(): void {
    $id = (int)($_GET['id'] ?? 0);
    $st = pdo()->prepare("SELECT * FROM crm_kb WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) { json_out(['ok' => false, 'error' => 'Статья не найдена'], 404); }
    pdo()->prepare("UPDATE crm_kb SET views = views + 1 WHERE id = ?")->execute([$id]);
    $row['views'] = (int)$row['views'] + 1;
    if (isset($row['body'])) $row['body'] = kb_sanitize_html((string)$row['body']); // старые статьи — тоже через фильтр
    json_out(['ok' => true, 'article' => $row, 'categories' => kb_categories()]);
}

/**
 * Тело статьи — HTML из редактора. Раньше сохранялось и отдавалось как есть, а kb.php
 * вставляет его через innerHTML: любой менеджер мог сохранить <script>/onerror, и он
 * выполнялся у каждого, кто открыл статью (включая админа). Белый список тегов и
 * атрибутов, ссылки только http(s)/mailto/tel/относительные.
 */
function kb_sanitize_html(string $html): string {
    if (trim($html) === '') return '';
    if (!class_exists('DOMDocument')) return nl2br(htmlspecialchars($html, ENT_QUOTES, 'UTF-8'));
    $allowed = ['p','br','b','strong','i','em','u','s','h2','h3','h4','ul','ol','li','blockquote','code','pre',
                'table','thead','tbody','tr','th','td','a','hr','span','div','img','sup','sub'];
    $attrs = ['a' => ['href', 'title'], 'img' => ['src', 'alt'], 'td' => ['colspan', 'rowspan'], 'th' => ['colspan', 'rowspan']];
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?><div id="kbroot">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    $root = $doc->getElementById('kbroot');
    if (!$root) return nl2br(htmlspecialchars(strip_tags($html), ENT_QUOTES, 'UTF-8'));
    $walk = static function (DOMNode $node) use (&$walk, $allowed, $attrs): void {
        for ($i = $node->childNodes->length - 1; $i >= 0; $i--) {
            $ch = $node->childNodes->item($i);
            if ($ch instanceof DOMElement) {
                $tag = strtolower($ch->tagName);
                if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'svg', 'math', 'link', 'meta'], true)) {
                    $node->removeChild($ch); continue;
                }
                $walk($ch);
                if (!in_array($tag, $allowed, true)) {           // неизвестный тег — снять, текст оставить
                    while ($ch->firstChild) $node->insertBefore($ch->firstChild, $ch);
                    $node->removeChild($ch); continue;
                }
                $keep = $attrs[$tag] ?? [];
                for ($a = $ch->attributes->length - 1; $a >= 0; $a--) {
                    $an = strtolower($ch->attributes->item($a)->nodeName);
                    $av = trim((string)$ch->attributes->item($a)->nodeValue);
                    $bad = !in_array($an, $keep, true)
                        || (in_array($an, ['href', 'src'], true) && !preg_match('~^(https?:|mailto:|tel:|/|#|\.{0,2}/?[\w\-]+)~i', $av))
                        || preg_match('~^\s*(javascript|data|vbscript):~i', $av);
                    if ($bad) $ch->removeAttribute($ch->attributes->item($a)->nodeName);
                }
                if ($tag === 'a' && $ch->hasAttribute('href')) { $ch->setAttribute('rel', 'noopener'); $ch->setAttribute('target', '_blank'); }
            } elseif ($ch instanceof DOMComment) {
                $node->removeChild($ch);
            }
        }
    };
    $walk($root);
    $out = '';
    foreach ($root->childNodes as $c) $out .= $doc->saveHTML($c);
    return $out;
}

function kb_save(array $user): void {
    $cats = kb_categories();
    $id       = (int)($_POST['id'] ?? 0);
    $category = (string)($_POST['category'] ?? 'faq');
    if (!isset($cats[$category])) $category = 'faq';
    $title    = trim((string)($_POST['title'] ?? ''));
    $excerpt  = trim((string)($_POST['excerpt'] ?? ''));
    $body     = kb_sanitize_html((string)($_POST['body'] ?? ''));
    $tags     = trim((string)($_POST['tags'] ?? ''));
    if ($title === '') { json_out(['ok' => false, 'error' => 'Введите заголовок'], 400); }
    if ($excerpt === '') $excerpt = mb_substr(trim(strip_tags($body)), 0, 200);
    $author = (string)($user['name'] ?? $user['login'] ?? '');

    if ($id > 0) {
        pdo()->prepare("UPDATE crm_kb SET category=?, title=?, excerpt=?, body=?, tags=?, updated_at=NOW() WHERE id=?")
             ->execute([$category, $title, $excerpt, $body, $tags, $id]);
    } else {
        pdo()->prepare("INSERT INTO crm_kb (category, title, excerpt, body, tags, author) VALUES (?,?,?,?,?,?)")
             ->execute([$category, $title, $excerpt, $body, $tags, $author]);
        $id = (int)pdo()->lastInsertId();
    }
    json_out(['ok' => true, 'id' => $id]);
}

function kb_del(array $user): void {
    if (($user['role'] ?? '') !== 'admin') json_out(['ok' => false, 'error' => 'Удалять статьи может только администратор'], 403);
    $id = (int)($_POST['id'] ?? 0);
    pdo()->prepare("DELETE FROM crm_kb WHERE id = ?")->execute([$id]);
    json_out(['ok' => true]);
}
