<?php
declare(strict_types=1);
/**
 * База знаний для ИИ-агента: текстовая база (kb.md) + загрузка файлов/фото/архивов.
 * ИИ (Claude) использует текст из kb.md и .txt/.md-файлов как контекст для подбора
 * аналогов и ответов. Прайсы/каталоги/фото-примеры хранятся для справки и зрения.
 */
require __DIR__ . '/_guard.php';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../api/kb_context.php'; // kb_files_status(), kb_context()

$h    = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$csrf = csrf_token();

$KB_DIR  = __DIR__ . '/../crm-data/kb';
$KB_TEXT = __DIR__ . '/../crm-data/kb.md';
if (!is_dir($KB_DIR)) @mkdir($KB_DIR, 0775, true);

$ALLOWED = ['jpg','jpeg','png','webp','gif','pdf','doc','docx','xls','xlsx','csv','txt','md','zip','rar','7z'];
$MAXSIZE = 25 * 1024 * 1024; // 25 МБ на файл

function fmt_size(int $b): string {
    if ($b >= 1048576) return round($b/1048576, 1) . ' МБ';
    if ($b >= 1024)    return round($b/1024) . ' КБ';
    return $b . ' Б';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $act = (string)($_POST['act'] ?? '');
    $msg = '';
    $isAdminKb = ((current_user() ?? [])['role'] ?? '') === 'admin';
    if (in_array($act, ['save_text', 'delete'], true) && !$isAdminKb) {
        header('Location: knowledge.php?msg=' . urlencode('Править текст базы и удалять файлы может только администратор.')); exit;
    }

    if ($act === 'save_text') {
        $msg = file_put_contents($KB_TEXT, (string)($_POST['kb_text'] ?? '')) === false
            ? 'Не удалось сохранить: нет прав на запись в crm-data/kb.md.'
            : 'Текстовая база знаний сохранена.';

    } elseif ($act === 'upload' && !empty($_FILES['files']['name'])) {
        $up = $_FILES['files'];
        $n  = is_array($up['name']) ? count($up['name']) : 0;
        $ok = 0; $bad = [];
        for ($i = 0; $i < $n; $i++) {
            if (($up['error'][$i] ?? 1) !== UPLOAD_ERR_OK) continue;
            $name = (string)$up['name'][$i];
            $size = (int)$up['size'][$i];
            $tmp  = (string)$up['tmp_name'][$i];
            $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, $ALLOWED, true)) { $bad[] = $name . ' (тип)'; continue; }
            if ($size <= 0 || $size > $MAXSIZE)  { $bad[] = $name . ' (размер)'; continue; }
            if (!is_uploaded_file($tmp))         { $bad[] = $name; continue; }
            $base = preg_replace('/[^\w\-.]+/u', '_', (string)pathinfo($name, PATHINFO_FILENAME));
            $base = trim((string)mb_substr($base, 0, 80), '_') ?: 'file';
            $fn = $base . '.' . $ext; $dst = $KB_DIR . '/' . $fn; $c = 1;
            while (file_exists($dst)) { $fn = $base . '_' . $c . '.' . $ext; $dst = $KB_DIR . '/' . $fn; $c++; }
            if (move_uploaded_file($tmp, $dst)) $ok++; else $bad[] = $name;
        }
        $msg = "Загружено файлов: {$ok}" . ($bad ? '. Пропущено: ' . implode(', ', $bad) : '');

    } elseif ($act === 'delete') {
        $fn = basename((string)($_POST['file'] ?? ''));
        $p  = $KB_DIR . '/' . $fn;
        if (str_ends_with(strtolower($fn), '.jsonl')) { header('Location: knowledge.php?msg=' . urlencode('Служебный файл поиска по сайту удалять нельзя.')); exit; }
        if ($fn !== '' && is_file($p)) { @unlink($p); $msg = 'Удалён файл: ' . $fn; }
    }

    header('Location: knowledge.php?msg=' . urlencode($msg)); exit;
}

$kbText = is_file($KB_TEXT) ? (string)file_get_contents($KB_TEXT) : '';
// Состав базы знаний берём из общего сборщика (api/kb_context.php): он же
// формирует контекст для ИИ, поэтому страница показывает ровно то, что модель видит,
// а не просто список файлов на диске.
// Служебные корпуса (*.jsonl: поиск по сайту, примеры ответов) — не пользовательские файлы:
// их удаление одним кликом ломало поиск в автоответах. В списке не показываем.
$files   = array_values(array_filter(kb_files_status(), fn($f) => $f['ext'] !== 'jsonl'));
$ctxLen  = mb_strlen(kb_context(), 'UTF-8');
$readCnt = count(array_filter($files, fn($f) => $f['readable']));
$imgExt  = ['jpg','jpeg','png','webp','gif'];
$flash   = (string)($_GET['msg'] ?? '');

render_head('База знаний ИИ');
render_sidebar('knowledge');
?>
<style>
  .kb-wrap{max-width:1100px}
  .kb-wrap textarea{width:100%;min-height:300px;border:1px solid var(--line);border-radius:10px;padding:14px;font:14px/1.55 ui-monospace,Menlo,Consolas,monospace;background:var(--card);color:var(--ink);resize:vertical}
  .kb-actions{display:flex;gap:10px;align-items:center;margin-top:12px;flex-wrap:wrap}
  .kb-actions .muted{font-size:13px}
  .kb-drop{display:block;border:2px dashed var(--line);border-radius:12px;padding:26px;text-align:center;color:var(--muted);cursor:pointer;transition:.15s}
  .kb-drop:hover{border-color:var(--red);color:var(--text)}
  .kb-files{list-style:none;margin:16px 0 0;padding:0;display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px}
  .kb-file{display:flex;align-items:center;gap:12px;border:1px solid var(--line);border-radius:10px;padding:10px 12px;background:var(--card)}
  .kb-file .ic{width:42px;height:42px;border-radius:8px;background:var(--bg);display:flex;align-items:center;justify-content:center;font-size:18px;flex:0 0 auto;overflow:hidden}
  .kb-file .ic img{width:100%;height:100%;object-fit:cover}
  .kb-file .nm{font-size:13px;font-weight:600;word-break:break-all;line-height:1.3}
  .kb-file .mt{font-size:11px;color:var(--muted)}
  .kb-file .del{margin-left:auto;background:none;border:0;color:var(--muted);cursor:pointer;font-size:16px}
  .kb-file .del:hover{color:var(--red)}
  .kb-tag{display:inline-block;font-size:10.5px;font-weight:700;padding:2px 7px;border-radius:5px;margin-top:4px}
  .kb-tag--on{background:rgba(46,160,67,.14);color:#1c7a31}
  .kb-tag--off{background:rgba(180,83,9,.12);color:#b45309}
  .kb-meter{display:flex;gap:16px;flex-wrap:wrap;font-size:13px;color:var(--muted);margin:0}
  .kb-meter b{color:var(--text)}
  .kn-hit{border-top:1px solid var(--line);padding:10px 0}
  .kn-hit__hd{display:flex;flex-wrap:wrap;gap:8px;align-items:center;font-size:13.5px}
  .kn-hit__body{font-size:13px;color:var(--muted);margin-top:4px;white-space:normal;overflow-wrap:anywhere}
</style>

<div class="kb-wrap shelf">
  <div class="page-head"><h1 class="page-title">База знаний ИИ-агента</h1></div>
  <p class="page-lead">Материалы, на которые опирается ИИ при подборе аналогов, расчёте и ответах клиентам.</p>

  <?php if ($flash !== ''): ?><div class="alert alert-ok"><?= $h($flash) ?></div><?php endif; ?>

  <?php
  // Единая база знаний (api/knowledge.php): сайт, переписка, пары «вопрос → ответ», документы.
  require_once __DIR__ . '/../api/knowledge.php';
  $knStats = kn_stats();
  $knQ = trim((string)($_GET['q'] ?? ''));
  $knSrc = (string)($_GET['src'] ?? '');
  $knLabels = ['guide' => 'Учебник ответов', 'site' => 'Сайт', 'pair' => 'Вопрос → ответ', 'mail' => 'Переписка', 'doc' => 'Документ'];
  $knRows = $knQ !== '' ? kn_find($knQ, ['limit' => 25, 'maxChars' => 900, 'src' => $knSrc !== '' ? [$knSrc] : ['guide', 'site', 'pair', 'mail', 'doc']]) : [];
  $arch = json_decode((string)setting('mail_archive', '{}'), true) ?: [];
  ?>
  <section class="card">
    <h2 class="card__title">Поиск по всей базе знаний</h2>
    <p class="kb-meter">
      <?php foreach ($knLabels as $k => $l): ?><span><?= $h($l) ?>: <b><?= number_format((int)($knStats[$k] ?? 0), 0, ',', ' ') ?></b></span><?php endforeach; ?>
      <?php if (empty($arch['done'])): ?><span>Архив почты догружается: входящие <?= (int)($arch['inbox']['next'] ?? 0) ?>/<?= (int)($arch['inbox']['total'] ?? 0) ?>, отправленные <?= (int)($arch['sent']['next'] ?? 0) ?>/<?= (int)($arch['sent']['total'] ?? 0) ?></span><?php endif; ?>
    </p>
    <form method="get" class="filters" style="margin-top:10px">
      <input class="input" type="search" name="q" value="<?= $h($knQ) ?>" placeholder="Модель, клиент, вопрос: «SEW R97», «доставка Казань», «NMRV 063 шильдик»…" style="flex:1;min-width:240px">
      <select class="input" name="src">
        <option value="">Везде</option>
        <?php foreach ($knLabels as $k => $l): ?><option value="<?= $k ?>"<?= $knSrc === $k ? ' selected' : '' ?>><?= $h($l) ?></option><?php endforeach; ?>
      </select>
      <button class="btn btn--primary" type="submit">Найти</button>
    </form>
    <?php if ($knQ !== ''): ?>
      <?php if (!$knRows): ?><p class="muted">Ничего не нашлось.</p><?php endif; ?>
      <?php foreach ($knRows as $r): ?>
        <div class="kn-hit">
          <div class="kn-hit__hd"><span class="badge badge--muted"><?= $h($knLabels[$r['src']] ?? $r['src']) ?></span>
            <b><?= $h($r['title'] !== '' ? $r['title'] : '(без темы)') ?></b>
            <small class="muted"><?= $h(substr((string)$r['dt'], 0, 10)) ?> <?= $h($r['who']) ?></small>
            <?php if (!empty($r['lead_id'])): ?><a href="lead.php?id=<?= (int)$r['lead_id'] ?>">заявка #<?= (int)$r['lead_id'] ?></a><?php endif; ?>
            <?php if ($r['url'] !== ''): ?><a href="<?= $h(str_starts_with($r['url'], 'file.php') ? '../api/' . $r['url'] : $r['url']) ?>" target="_blank" rel="noopener">открыть</a><?php endif; ?>
          </div>
          <div class="kn-hit__body"><?= nl2br($h($r['body'])) ?></div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </section>

  <div class="kb-meter">
    <span>ИИ получает: <b><?= number_format($ctxLen, 0, ',', ' ') ?></b> символов контекста</span>
    <span>Файлов читается: <b><?= $readCnt ?></b> из <?= count($files) ?></span>
  </div>

  <?php
    // Данные, собранные с сайта: справочник моделей + корпус пояснений.
    // Показываем честно, иначе снова получится «UI обещает, ИИ не читает».
    $seriesFile = __DIR__ . '/../crm-data/site_series.json';
    $chunksFile = __DIR__ . '/../crm-data/kb/site-chunks.jsonl';
    $siteSeries = is_file($seriesFile) ? json_decode((string)file_get_contents($seriesFile), true) : null;
    $seriesCnt  = is_array($siteSeries) ? count($siteSeries['series'] ?? []) : 0;
    $brandsCnt  = is_array($siteSeries) ? count($siteSeries['brands'] ?? []) : 0;
    $chunksCnt  = 0;
    if (is_file($chunksFile)) {
        $fh = fopen($chunksFile, 'r');
        if ($fh) { while (fgets($fh) !== false) $chunksCnt++; fclose($fh); }
    }
    $siteDate = is_file($seriesFile) ? date('d.m.Y H:i', (int)filemtime($seriesFile)) : '';
  ?>
  <?php
    require_once __DIR__ . '/../api/price.php';
    $price = price_status();
  ?>
  <div class="card">
    <h2 class="card__title">💰 Прайс — цена и срок</h2>
    <p class="hint">Пока прайса нет, письма и КП собираются <b>без цены</b>: система пишет «инженер посчитает».
      Это защита от обещаний, которые завод не выполнит — в старом справочнике на 5 453 позиции всего
      4 значения цены, а реальный счёт на ZR 959 отличался от базы в семь раз.</p>
    <?php if ($price['ready']): ?>
      <div class="kb-meter">
        <span>Позиций с ценой: <b><?= number_format($price['rows'], 0, ',', ' ') ?></b></span>
        <span>Обновлён: <b><?= $h($price['updated']) ?></b></span>
        <span>Цена в письмах: <b>включена</b></span>
      </div>
    <?php else: ?>
      <p class="hint">
        Файл <code>crm-data/price.csv</code> не заполнен — цена в письмах отключена.
        Шаблон с нужными колонками лежит рядом: <code>crm-data/price.sample.csv</code>
        (<code>zr_code, name, price_rub, lead_days, in_stock, note</code>).
        Как только заполните — цена и срок начнут подставляться сами, ничего перенастраивать не нужно.</p>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2 class="card__title">🌐 Данные сайта zavod-red.ru</h2>
    <p class="hint">Собираются автоматически с карточек и страниц сайта, руками не редактируются.
      Справочник даёт ZR-коды (выдумывать их ИИ не может), корпус пояснений подключается
      <b>поиском по тексту обращения</b>, а не целиком — поэтому контекст остаётся коротким.</p>
    <?php if ($seriesCnt === 0 && $chunksCnt === 0): ?>
      <p class="hint">Данные ещё не собраны. Запустите на сервере:
        <code>python3 tools/site_catalog_index.py &amp;&amp; python3 tools/site_kb_build.py</code></p>
    <?php else: ?>
      <div class="kb-meter">
        <span>Серий моделей: <b><?= number_format($seriesCnt, 0, ',', ' ') ?></b> по <b><?= $brandsCnt ?></b> маркам</span>
        <span>Кусков пояснений: <b><?= number_format($chunksCnt, 0, ',', ' ') ?></b></span>
        <?php if ($siteDate !== ''): ?><span>Обновлено: <b><?= $h($siteDate) ?></b></span><?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2 class="card__title">📝 Текстовая база (главное)</h2>
    <p class="hint">Описание серий EVL/ПР/МР, таблицы соответствия импортным аналогам, типовые цены, правила подбора, частые вопросы. Этот текст ИИ читает при каждом ответе.</p>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
      <input type="hidden" name="act" value="save_text">
      <textarea name="kb_text" placeholder="# Серии редукторов&#10;EVL — ...&#10;# Соответствие импорту&#10;SEW R57 → EVL 195 ...&#10;# Цены&#10;..."><?= $h($kbText) ?></textarea>
      <div class="kb-actions"><button class="btn btn--primary" type="submit">Сохранить текст</button>
        <span class="muted"><?= mb_strlen($kbText) ?> символов</span></div>
    </form>
  </div>

  <div class="card">
    <h2 class="card__title">📎 Файлы, фото и архивы</h2>
    <p class="hint">ИИ читает текст из <b>TXT, MD, CSV, XLSX, DOCX</b>. Из <b>PDF, DOC, XLS, фото и архивов</b> текст не извлекается —
      такие файлы хранятся для справки, но в подбор не идут: выгрузите их в CSV/XLSX или вставьте текст в базу выше.
      Что именно попало в контекст — видно по метке у каждого файла.</p>
    <form method="post" enctype="multipart/form-data" id="kbUpForm">
      <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
      <input type="hidden" name="act" value="upload">
      <input type="file" name="files[]" id="kbFiles" multiple hidden
             accept=".jpg,.jpeg,.png,.webp,.gif,.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.md,.zip,.rar,.7z">
      <label class="kb-drop" for="kbFiles" id="kbDrop">
        <div style="font-size:30px">⬆</div>
        Нажмите или перетащите файлы сюда<br>
        <span style="font-size:12px">JPG, PNG, PDF, DOC, XLS, CSV, TXT, MD, ZIP — до 25 МБ</span>
      </label>
      <div class="kb-actions"><button class="btn btn--primary" type="submit">Загрузить</button>
        <span id="kbCount" class="muted"></span></div>
    </form>

    <?php if ($files): ?>
    <ul class="kb-files">
      <?php foreach ($files as $f): $isImg = in_array($f['ext'], $imgExt, true); ?>
      <li class="kb-file">
        <span class="ic"><?php if ($isImg): ?><img src="kb_file.php?f=<?= $h(rawurlencode($f['name'])) ?>" alt=""><?php else: ?><?= $isImg ? '' : '📄' ?><?php endif; ?></span>
        <div style="min-width:0">
          <div class="nm"><?= $h($f['name']) ?></div>
          <div class="mt"><?= strtoupper($f['ext']) ?> · <?= fmt_size($f['size']) ?></div>
          <div class="mt">
            <?php if ($f['readable']): ?>
              <span class="kb-tag kb-tag--on">ИИ читает · <?= number_format($f['chars'], 0, ',', ' ') ?> симв.</span>
            <?php else: ?>
              <span class="kb-tag kb-tag--off">текст не извлекается</span>
            <?php endif; ?>
          </div>
        </div>
        <form method="post" onsubmit="return confirm('Удалить файл?')">
          <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
          <input type="hidden" name="act" value="delete">
          <input type="hidden" name="file" value="<?= $h($f['name']) ?>">
          <button class="del" type="submit" title="Удалить">✕</button>
        </form>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php else: ?>
      <div class="empty">Файлов пока нет.</div>
    <?php endif; ?>
  </div>

  <div class="help help--info">
    <span class="help__icon">💡</span>
    <div class="help__body">
      <b>Как это работает:</b> текстовая база + извлечённый текст файлов передаются ИИ как контекст при подборе и ответах.
      Фото шильдика <b>из заявки клиента</b> ИИ распознаёт зрением; фото, загруженные сюда, — только справочный архив, в промпт они не идут.
      Чтобы включить ИИ — впишите API-ключ в <a href="settings.php">Настройках</a>.
    </div>
  </div>
</div>

<script>
  (function(){
    var inp=document.getElementById('kbFiles'), drop=document.getElementById('kbDrop'), cnt=document.getElementById('kbCount');
    if(inp){ inp.addEventListener('change',function(){ cnt.textContent=inp.files.length?('Выбрано: '+inp.files.length):''; }); }
    if(drop){
      ['dragover','dragenter'].forEach(e=>drop.addEventListener(e,function(ev){ev.preventDefault();drop.style.borderColor='var(--red)';}));
      ['dragleave','drop'].forEach(e=>drop.addEventListener(e,function(ev){ev.preventDefault();drop.style.borderColor='';}));
      drop.addEventListener('drop',function(ev){ if(ev.dataTransfer&&ev.dataTransfer.files.length){ inp.files=ev.dataTransfer.files; cnt.textContent='Выбрано: '+inp.files.length; } });
    }
  })();
</script>
<?php render_foot();
