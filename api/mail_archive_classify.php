<?php
declare(strict_types=1);
/**
 * Сортировка архива почты (письма до 29.09.2026, mail_kind='archive') — пачками по 20 писем
 * за один запрос DeepSeek. Мусор (рассылки, спам, уведомления) убирается из базы знаний,
 * сами письма остаются в CRM. С продолжением: позиция — по id, состояние — в mail_kind.
 *   php api/mail_archive_classify.php [--budget=240]
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/llm.php';
require_once __DIR__ . '/mime.php';
require_once __DIR__ . '/knowledge.php';
$budget = 240;
foreach (array_slice($argv, 1) as $a) if (preg_match('/^--budget=(\d+)$/', $a, $m)) $budget = (int)$m[1];
$deadline = time() + $budget;
if (!llm_ready()) { echo "ИИ не настроен\n"; exit(1); }
$pdo = pdo();
$kinds = ['request', 'client', 'supplier', 'invoice', 'newsletter', 'spam', 'service', 'other'];
$done = 0; $calls = 0; $stat = [];
$sys = "Ты сортируешь архив входящей почты завода-производителя редукторов. Для КАЖДОГО письма выбери тип:\n"
     . "request — клиент спрашивает про редукторы/двигатели/подбор/цену/КП/аналог/шильдик/тендер;\n"
     . "client — продолжение сделки: оплата, отгрузка, документы, договор, претензия;\n"
     . "supplier — поставщики/перевозчики/подрядчики по нашим закупкам; invoice — нам выставили счёт/акт;\n"
     . "newsletter — рассылка/новости/дайджест/реклама магазинов; spam — реклама услуг нам, фишинг, нерелевантное;\n"
     . "service — уведомления сервисов (банк, ЭДО, хостинг, госсайты, недоставка, автоответы); other — прочее.\n"
     . 'Верни СТРОГО JSON-массив: [{"id":N,"kind":"..."}] для всех писем, без пояснений.';
while (time() < $deadline) {
    $rows = $pdo->query("SELECT id, contact, subject, LEFT(body, 600) b FROM crm_messages
                         WHERE channel='email' AND direction='in' AND mail_kind='archive' ORDER BY id LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) break;
    $u = '';
    foreach ($rows as $r) {
        $t = trim((string)preg_replace('/\s+/u', ' ', mime_strip_quote((string)$r['b'])));
        $u .= "### id={$r['id']}\nОт: {$r['contact']}\nТема: {$r['subject']}\nТекст: " . mb_substr($t, 0, 350, 'UTF-8') . "\n\n";
    }
    $calls++;
    $map = [];
    try {
        $raw = llm_call($sys, $u, 900);
        $t = trim((string)preg_replace(['/^```[a-z]*\s*/i', '/\s*```$/'], '', trim($raw)));
        $s = strpos($t, '['); $e = strrpos($t, ']');
        $arr = ($s !== false && $e !== false) ? json_decode(substr($t, $s, $e - $s + 1), true) : null;
        foreach ((array)$arr as $x) if (isset($x['id'], $x['kind']) && in_array($x['kind'], $kinds, true)) $map[(int)$x['id']] = $x['kind'];
    } catch (Throwable $e) { echo 'сбой ИИ: ', $e->getMessage(), "\n"; sleep(5); continue; }
    foreach ($rows as $r) {
        $k = $map[(int)$r['id']] ?? 'other';   // не распознан — «прочее», чтобы не крутить вечно
        $pdo->prepare("UPDATE crm_messages SET mail_kind=? WHERE id=?")->execute([$k, (int)$r['id']]);
        if (in_array($k, ['newsletter', 'spam', 'service'], true)) {
            $pdo->prepare("DELETE FROM crm_knowledge WHERE src='mail' AND ref=?")->execute(['msg:' . (int)$r['id']]);
        }
        $stat[$k] = ($stat[$k] ?? 0) + 1; $done++;
    }
}
$left = (int)$pdo->query("SELECT COUNT(*) FROM crm_messages WHERE channel='email' AND direction='in' AND mail_kind='archive'")->fetchColumn();
echo json_encode(['разобрано' => $done, 'запросов' => $calls, 'по типам' => $stat, 'осталось' => $left], JSON_UNESCAPED_UNICODE), "\n";
