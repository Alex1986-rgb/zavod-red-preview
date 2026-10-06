<?php
declare(strict_types=1);
/**
 * Письма: диалоги из crm_messages (входящие/исходящие e-mail), просмотр треда,
 * ответ вручную или ИИ-черновиком (ai.php?action=draft) с отправкой по SMTP.
 */
require __DIR__ . '/_guard.php';
require __DIR__ . '/_layout.php';
// Устаревшая страница: её заменил раздел «Письма» (mail.php) — ни меню, ни ссылок на неё нет.
header('Location: mail.php'); exit;
require_once __DIR__ . '/../api/mail.php'; // smtp_send(), msg_insert() — безопасно (CLI-guard)

$h    = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$csrf = csrf_token();
$pdo  = pdo();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $leadId  = (int)($_POST['lead_id'] ?? 0);
    $to      = trim((string)($_POST['to'] ?? ''));
    $subject = trim((string)($_POST['subject'] ?? ''));
    $body    = trim((string)($_POST['body'] ?? ''));
    $msg = '';
    if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL) && $body !== '') {
        $htmlBody = '<div style="font:15px/1.6 Arial,sans-serif;color:#1a2730">' . nl2br($h($body))
            . '<br><br>—<br>С уважением, Завод Редукторов · +7 (495) 151-41-02 · zr@zavod-red.ru</div>';
        $subj = $subject !== '' ? $subject : 'Ответ от Завода Редукторов';
        if (function_exists('smtp_send') && smtp_send($to, $subj, $htmlBody, true)) {
            if (function_exists('msg_insert')) {
                msg_insert($leadId > 0 ? $leadId : null, 'email', 'out', $body, ['contact' => $to, 'subject' => $subj]);
            }
            $msg = 'Письмо отправлено.';
        } else {
            $msg = 'Ошибка отправки — проверьте SMTP в Настройках.';
        }
    } else {
        $msg = 'Укажите корректный e-mail и текст письма.';
    }
    header('Location: mailbox.php?lead=' . $leadId . '&msg=' . urlencode($msg)); exit;
}

$leadId = (int)($_GET['lead'] ?? 0);
$flash  = (string)($_GET['msg'] ?? '');

$threads = $pdo->query(
    "SELECT m.lead_id, MAX(m.created_at) AS last_at, COUNT(*) AS cnt,
            COALESCE(l.name,'') AS name, COALESCE(l.email,'') AS email,
            SUM(m.direction='in') AS inc
     FROM crm_messages m
     LEFT JOIN crm_leads l ON l.id = m.lead_id
     WHERE m.channel='email'
     GROUP BY m.lead_id, l.name, l.email
     ORDER BY last_at DESC LIMIT 60"
)->fetchAll();

$thread = []; $lead = null; $clientEmail = ''; $lastIncoming = '';
if ($leadId > 0) {
    $st = $pdo->prepare("SELECT * FROM crm_messages WHERE lead_id=? AND channel='email' ORDER BY created_at ASC");
    $st->execute([$leadId]);
    $thread = $st->fetchAll();
    foreach ($thread as $m) {
        if ($m['direction'] === 'in') { $lastIncoming = (string)$m['body']; if ($m['contact']) $clientEmail = (string)$m['contact']; }
    }
    $st2 = $pdo->prepare('SELECT * FROM crm_leads WHERE id=?');
    $st2->execute([$leadId]);
    $lead = $st2->fetch() ?: null;
    if ($clientEmail === '' && $lead && !empty($lead['email'])) $clientEmail = (string)$lead['email'];
}
$lastSubject = '';
foreach (array_reverse($thread) as $m) { if (!empty($m['subject'])) { $lastSubject = (string)$m['subject']; break; } }

render_head('Письма');
render_sidebar('mailbox');
?>
<style>
  .mb{display:grid;grid-template-columns:320px 1fr;gap:18px;align-items:start}
  @media(max-width:900px){.mb{grid-template-columns:1fr}}
  .mb-list{background:var(--card);border:1px solid var(--line);border-radius:14px;overflow:hidden}
  .mb-list a{display:block;padding:12px 14px;border-bottom:1px solid var(--line);color:var(--text)}
  .mb-list a:last-child{border-bottom:0}
  .mb-list a.on{background:rgba(225,27,27,.08);border-left:3px solid var(--red)}
  .mb-list .nm{font-weight:700;font-size:14px}
  .mb-list .em{color:var(--muted);font-size:12px}
  .mb-list .mt{color:var(--muted);font-size:11px;margin-top:3px;display:flex;gap:8px}
  .mb-pane{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:18px 20px}
  .mb-empty{color:var(--muted);text-align:center;padding:40px 0}
  .msg{border:1px solid var(--line);border-radius:10px;padding:12px 14px;margin-bottom:10px;background:#fff}
  .msg.in{border-left:3px solid #2f7fc2}
  .msg.out{border-left:3px solid var(--red);background:rgba(225,27,27,.03)}
  .msg .mh{display:flex;justify-content:space-between;font-size:12px;color:var(--muted);margin-bottom:6px}
  .msg .mb-subj{font-weight:700;font-size:13px;margin-bottom:4px}
  .msg .mb-body{font-size:14px;white-space:pre-wrap;line-height:1.5;color:#1a2730}
  .reply{margin-top:18px;border-top:1px dashed var(--line);padding-top:16px}
  .reply input,.reply textarea{width:100%;border:1px solid var(--line);border-radius:9px;padding:11px 13px;font:14px Arial,sans-serif;background:#fff;color:#16242e;margin-bottom:10px}
  .reply textarea{min-height:150px;resize:vertical}
  .btn{display:inline-flex;align-items:center;gap:8px;background:var(--red);color:#fff;border:0;border-radius:10px;padding:11px 20px;font-weight:700;font-size:14px;cursor:pointer}
  .btn.ghost{background:transparent;border:1.5px solid var(--line);color:var(--text)}
  .mb-flash{background:rgba(46,160,67,.14);border:1px solid #2ea043;color:#1c7a31;border-radius:10px;padding:11px 14px;margin-bottom:14px;font-size:14px}
  .mb-tools{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
</style>

<h1 style="font-size:22px;margin:0 0 4px">Письма</h1>
<p style="color:var(--muted);font-size:14px;margin:0 0 18px">Входящие и исходящие e-mail клиентов. Ответ вручную или ИИ-черновиком на основе базы знаний.</p>
<?php if ($flash !== ''): ?><div class="mb-flash"><?= $h($flash) ?></div><?php endif; ?>

<div class="mb">
  <div class="mb-list">
    <?php if (!$threads): ?>
      <div class="mb-empty" style="padding:24px 14px">Писем пока нет.<br><span style="font-size:12px">Появятся после настройки почты (cron + IMAP в Настройках).</span></div>
    <?php else: foreach ($threads as $t): ?>
      <a href="mailbox.php?lead=<?= (int)$t['lead_id'] ?>" class="<?= $leadId === (int)$t['lead_id'] ? 'on' : '' ?>">
        <div class="nm"><?= $h($t['name'] ?: ($t['email'] ?: 'Без имени')) ?></div>
        <?php if ($t['email']): ?><div class="em"><?= $h($t['email']) ?></div><?php endif; ?>
        <div class="mt"><span><?= (int)$t['cnt'] ?> сообщ.</span><span><?= $h(mb_substr((string)$t['last_at'], 0, 16)) ?></span><?php if ((int)$t['inc']): ?><span style="color:#2f7fc2">●&nbsp;вход.</span><?php endif; ?></div>
      </a>
    <?php endforeach; endif; ?>
  </div>

  <div class="mb-pane">
    <?php if ($leadId <= 0): ?>
      <div class="mb-empty">Выберите диалог слева, чтобы прочитать переписку и ответить.</div>
    <?php else: ?>
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:8px">
        <div><b style="font-size:16px"><?= $h($lead['name'] ?? ($clientEmail ?: ('Лид #' . $leadId))) ?></b>
          <?php if ($clientEmail): ?><span style="color:var(--muted);font-size:13px"> · <?= $h($clientEmail) ?></span><?php endif; ?></div>
        <a href="lead.php?id=<?= $leadId ?>" class="btn ghost" style="padding:7px 14px;font-size:13px">Карточка заявки →</a>
      </div>

      <?php foreach ($thread as $m): ?>
        <div class="msg <?= $m['direction'] === 'in' ? 'in' : 'out' ?>">
          <div class="mh"><span><?= $m['direction'] === 'in' ? '⬇ От клиента' : '⬆ Мы' ?> · <?= $h($m['contact']) ?></span><span><?= $h(mb_substr((string)$m['created_at'], 0, 16)) ?></span></div>
          <?php if (!empty($m['subject'])): ?><div class="mb-subj"><?= $h($m['subject']) ?></div><?php endif; ?>
          <div class="mb-body"><?= $h(mb_substr((string)$m['body'], 0, 4000)) ?></div>
        </div>
      <?php endforeach; ?>

      <form class="reply" method="post" id="replyForm">
        <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
        <input type="hidden" name="lead_id" value="<?= $leadId ?>">
        <input type="email" name="to" value="<?= $h($clientEmail) ?>" placeholder="E-mail клиента" required>
        <input type="text" name="subject" value="<?= $h($lastSubject !== '' ? ('Re: ' . preg_replace('/^Re:\s*/i', '', $lastSubject)) : '') ?>" placeholder="Тема">
        <textarea name="body" id="replyBody" placeholder="Текст ответа…" required></textarea>
        <div class="mb-tools">
          <button class="btn" type="submit">Отправить</button>
          <button class="btn ghost" type="button" id="aiDraftBtn" data-lead="<?= $leadId ?>">✨ ИИ-черновик</button>
          <span id="aiState" style="color:var(--muted);font-size:13px"></span>
        </div>
      </form>
    <?php endif; ?>
  </div>
</div>

<script>
(function(){
  var btn=document.getElementById('aiDraftBtn');
  if(!btn) return;
  btn.addEventListener('click',function(){
    var lead=btn.getAttribute('data-lead'), st=document.getElementById('aiState'), ta=document.getElementById('replyBody');
    var incoming=''; var ins=document.querySelectorAll('.msg.in .mb-body'); if(ins.length) incoming=ins[ins.length-1].textContent;
    st.textContent='ИИ думает…'; btn.disabled=true;
    var fd=new FormData(); fd.append('csrf',window.CSRF||''); fd.append('id',lead); fd.append('channel','email'); fd.append('incoming',incoming);
    fetch('../api/ai.php?action=draft',{method:'POST',body:fd,credentials:'same-origin'})
      .then(function(r){return r.json();})
      .then(function(d){ btn.disabled=false; if(d&&d.ok&&d.text){ ta.value=d.text; st.textContent='Готово — проверьте и отправьте.'; } else { st.textContent=(d&&d.error)||'Не удалось (впишите ключ ИИ в Настройках).'; } })
      .catch(function(){ btn.disabled=false; st.textContent='Ошибка сети.'; });
  });
})();
</script>
<?php render_foot();
