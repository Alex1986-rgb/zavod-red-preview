<?php
declare(strict_types=1);

require __DIR__ . '/_guard.php'; // авторизация; БЕЗ _layout — это печатная страница

$id = (int)($_GET['id'] ?? 0);

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$lead = null;
$suggest = '';
if ($id > 0) {
    try {
        $st = pdo()->prepare('SELECT * FROM crm_leads WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $lead = $st->fetch() ?: null;

        if ($lead) {
            $st2 = pdo()->prepare("SELECT content FROM crm_ai WHERE lead_id = ? AND type = 'suggest' ORDER BY id DESC LIMIT 1");
            $st2->execute([$id]);
            $c = $st2->fetchColumn();
            if ($c !== false) $suggest = (string)$c;
        }
    } catch (Throwable $e) {
        $lead = null;
    }
}

// Структурное распознавание (позиции + аналоги ZR) — для «Подобранного решения» и предзаполнения позиций КП.
$rec = null;
$recItems = [];
if ($lead) {
    try {
        $st3 = pdo()->prepare("SELECT content FROM crm_ai WHERE lead_id = ? AND type = 'recognize' ORDER BY id DESC LIMIT 1");
        $st3->execute([$id]);
        $rc = $st3->fetchColumn();
        if ($rc !== false) { $d = json_decode((string)$rc, true); if (is_array($d)) $rec = $d; }
    } catch (Throwable $e) { $rec = null; }
    if ($rec) {
        $positions = array_values((array)($rec['positions'] ?? []));
        $analogs   = array_values((array)($rec['analogs'] ?? []));
        if ($analogs) {
            foreach ($analogs as $i => $a) {
                $our = trim((string)($a['our'] ?? ''));
                if ($our === '') continue;
                $for = trim((string)($a['for'] ?? ''));
                $name = $our . ($for !== '' ? ' — аналог ' . $for : '');
                $qty = (isset($positions[$i]['qty']) && (int)$positions[$i]['qty'] > 0) ? (int)$positions[$i]['qty'] : 1;
                $recItems[] = ['name' => $name, 'qty' => $qty, 'price' => ''];
            }
        } elseif ($positions) {
            foreach ($positions as $p) {
                $nm = trim((string)($p['model'] ?? ($p['raw'] ?? 'Редуктор')));
                $kind = trim((string)($p['kind'] ?? ''));
                if ($kind !== '') $nm .= ' — ' . $kind;
                $recItems[] = ['name' => $nm, 'qty' => max(1, (int)($p['qty'] ?? 1)), 'price' => ''];
            }
        }
    }
}

$today = date('d.m.Y');
$kpNo  = $id > 0 ? sprintf('ЗР-%05d', $id) : '—';
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Коммерческое предложение <?= h($kpNo) ?> · Завод Редукторов</title>
<style>
  :root{ --red:#e11b1b; --ink:#0f172a; --muted:#64748b; --line:#e2e8f0; }
  *{ box-sizing:border-box; }
  html,body{ margin:0; padding:0; background:#f1f5f9; color:#1e293b;
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif; font-size:14px; line-height:1.5; }
  .toolbar{ position:sticky; top:0; display:flex; flex-wrap:wrap; gap:10px; justify-content:flex-end;
    padding:12px 20px; background:#fff; border-bottom:1px solid var(--line); }
  .btn{ display:inline-flex; align-items:center; gap:6px; padding:9px 18px; border-radius:var(--r-md,10px);
    border:1px solid transparent; font:inherit; cursor:pointer; text-decoration:none;
    transition:background .14s ease,border-color .14s ease,color .14s ease,box-shadow .14s ease,transform .14s ease; }
  .btn--primary{ background:var(--red); color:#fff; }
  .btn--primary:hover{ background:var(--red-hover,#b91515); box-shadow:0 4px 12px rgba(225,27,27,.28); transform:translateY(-1px); }
  .btn--ghost{ background:#fff; border-color:var(--line); color:var(--muted); }
  .btn--ghost:hover{ border-color:var(--red); color:var(--ink); box-shadow:var(--shadow-sm,0 1px 3px rgba(16,24,40,.06)); }
  @media(max-width:600px){ .sheet{ padding:20px 16px !important; margin:12px auto !important; } .toolbar{ justify-content:flex-start; } }
  .kp-scroll{ overflow-x:auto; -webkit-overflow-scrolling:touch; }
  .sheet{ max-width:820px; margin:24px auto; background:#fff; padding:48px 56px; border-radius:var(--r-lg,14px);
    box-shadow:var(--shadow-md,0 6px 18px rgba(16,24,40,.09)); }
  .kp-head{ display:flex; justify-content:space-between; align-items:flex-start;
    border-bottom:3px solid var(--red); padding-bottom:18px; margin-bottom:24px; }
  .kp-logo{ display:flex; align-items:center; gap:14px; }
  .kp-logo .mark{ display:inline-flex; align-items:center; justify-content:center; width:54px; height:54px;
    background:var(--red); color:#fff; font-weight:800; font-size:18px; border-radius:var(--r-md,10px); letter-spacing:.5px; }
  .kp-co{ font-size:18px; font-weight:800; color:var(--ink); }
  .kp-co small{ display:block; font-size:12px; font-weight:600; color:var(--muted); margin-top:2px; }
  .kp-contacts{ text-align:right; font-size:13px; color:var(--muted); line-height:1.7; }
  .kp-contacts a{ color:var(--ink); text-decoration:none; }
  h1.kp-title{ font-size:22px; color:var(--ink); margin:0 0 4px; }
  .kp-meta{ color:var(--muted); font-size:13px; margin-bottom:26px; }
  .kp-section{ margin-bottom:26px; }
  .kp-section h2{ font-size:15px; color:var(--red); text-transform:uppercase; letter-spacing:.04em;
    margin:0 0 10px; border-bottom:1px solid var(--line); padding-bottom:6px; }
  .kp-section h2::before{ content:""; display:inline-block; width:3px; height:15px; border-radius:2px;
    background:var(--red); margin-right:8px; vertical-align:-2px; }
  .kp-kv{ display:grid; grid-template-columns:170px 1fr; gap:6px 14px; font-size:14px; }
  .kp-kv dt{ color:var(--muted); }
  .kp-kv dd{ margin:0; color:var(--ink); }
  .kp-solution{ white-space:pre-wrap; background:#fafafa; border:1px solid var(--line);
    border-radius:var(--r-md,10px); padding:14px 16px; }
  .kp-solution.placeholder{ color:var(--muted); font-style:italic; }
  table.kp-items{ width:100%; border-collapse:collapse; font-size:14px; }
  table.kp-items th,table.kp-items td{ border:1px solid var(--line); padding:9px 10px; text-align:left; }
  table.kp-items th{ background:#fafafa; font-size:12px; text-transform:uppercase; letter-spacing:.03em; color:var(--muted); }
  table.kp-items td.num{ text-align:right; }
  table.kp-items tbody tr:hover td{ background:rgba(0,0,0,.015); }
  table.kp-items .empty td{ height:34px; }
  .kp-terms{ list-style:none; padding:0; margin:0; }
  .kp-terms li{ padding:5px 0 5px 22px; position:relative; }
  .kp-terms li::before{ content:"✓"; position:absolute; left:0; color:var(--red); font-weight:700; }
  .kp-sign{ display:flex; justify-content:space-between; margin-top:42px; gap:40px; }
  .kp-sign .col{ flex:1; }
  .kp-sign .line{ border-top:1px solid var(--ink); margin-top:42px; padding-top:6px;
    font-size:12px; color:var(--muted); }
  .kp-foot{ margin-top:40px; padding-top:16px; border-top:1px solid var(--line);
    font-size:12px; color:var(--muted); text-align:center; }
  .err{ max-width:820px; margin:60px auto; background:#fff; padding:40px; border-radius:var(--r-lg,14px);
    box-shadow:var(--shadow-sm,0 1px 3px rgba(16,24,40,.06)); text-align:center; color:var(--muted); }
  .help{ display:flex; gap:10px; max-width:820px; margin:24px auto -8px; padding:13px 16px;
    background:rgba(29,78,216,.05); border-left:3px solid #1d4ed8; border-radius:var(--r-md,10px); }
  .help__icon{ font-size:18px; line-height:1.25; flex:none; }
  .help__body{ min-width:0; font-size:13px; color:#334155; line-height:1.6; }
  .help b{ font-weight:700; color:var(--ink); }
  .help ul{ margin:6px 0 0; padding-left:18px; }
  .help li{ margin:2px 0; }
  .help--warn{ background:rgba(180,83,9,.06); border-left-color:#b45309; }
  .hint{ font-size:12px; color:var(--muted); line-height:1.5; margin:4px 0 0; }
  table.kp-items td input{ width:100%; border:1px solid var(--line); border-radius:6px; padding:6px 8px; font:inherit; background:#fff;
    transition:border-color .14s ease, box-shadow .14s ease; }
  table.kp-items td input:focus{ outline:none; border-color:var(--red); box-shadow:0 0 0 3px var(--red-soft,rgba(225,27,27,.16)); }
  table.kp-items td.num input{ text-align:right; }
  table.kp-items .kp-del{ border:0; background:none; color:#cf2020; cursor:pointer; font-size:16px; line-height:1; transition:color .14s ease, transform .14s ease; }
  table.kp-items .kp-del:hover{ color:var(--red); transform:scale(1.15); }
  .kp-inl{ border:0; border-bottom:1px dashed var(--line); font:inherit; color:var(--ink); padding:1px 2px; min-width:180px; background:transparent;
    transition:border-color .14s ease; }
  .kp-inl:focus{ outline:none; border-bottom-color:var(--red); }
  @media print{
    .toolbar{ display:none !important; }
    .help{ display:none !important; }
    .hint{ display:none !important; }
    html,body{ background:#fff; }
    .sheet{ box-shadow:none; margin:0; max-width:none; padding:0; border-radius:0; }
    .kp-del,.kp-colact{ display:none !important; }
    table.kp-items td input,.kp-inl{ border:0 !important; padding:0 !important; background:transparent !important; -webkit-appearance:none; appearance:none; }
    @page{ margin:18mm 16mm; }
  }
</style>
<script>window.CSRF = <?= json_encode(csrf_token(), JSON_UNESCAPED_UNICODE) ?>;</script>
</head>
<body>

<div class="toolbar">
  <a class="btn btn--ghost" href="<?= $lead ? 'lead.php?id=' . (int)$id : 'leads.php' ?>">← Назад</a>
  <?php if ($lead): ?>
  <button class="btn btn--ghost" id="kpAddRow" type="button">+ Строка</button>
  <button class="btn btn--ghost" id="kpSave" type="button">💾 Сохранить</button>
  <button class="btn btn--ghost" id="kpSend" type="button"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="ic"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg> Отправить клиенту</button>
  <span id="kpFlash" style="align-self:center;font-size:13px"></span>
  <?php endif; ?>
  <button class="btn btn--primary" onclick="window.print()">Печать / Сохранить в PDF</button>
</div>

<?php if (!$lead): ?>
  <div class="err">
    <h1 style="color:#0f172a">Заявка не найдена</h1>
    <p>Лид #<?= h((string)$id) ?> не существует или база недоступна.</p>
  </div>
<?php else: ?>
<?php
  $clientName  = $lead['name'] ?? '';
  $clientPhone = $lead['phone'] ?? '';
  $clientEmail = $lead['email'] ?? '';
  $reducer     = $lead['reducer_type'] ?? '';
  $request     = $lead['message'] ?? '';
?>

<div class="help help--warn">
  <span class="help__icon">💡</span>
  <div class="help__body">
    <b>Коммерческое предложение по лиду.</b> Готовая страница для печати или сохранения в PDF (кнопка «Печать / Сохранить в PDF» вверху).
    <ul>
      <li>Проверьте реквизиты заказчика, подобранное решение и заполните позиции с ценами.</li>
      <li>Сформируйте PDF и отправьте клиенту.</li>
      <li>Импортные цены клиенту не публикуем — указываем «по запросу».</li>
    </ul>
  </div>
</div>

<div class="sheet">

  <div class="kp-head">
    <div class="kp-logo">
      <span class="mark">ЗР</span>
      <div class="kp-co">Завод Редукторов<small>ООО «НИИ АТТ»</small></div>
    </div>
    <div class="kp-contacts">
      +7 (495) 151-41-02<br>
      <a href="mailto:zr@zavod-red.ru">zr@zavod-red.ru</a><br>
      zavod-red.ru
    </div>
  </div>

  <h1 class="kp-title">Коммерческое предложение № <?= h($kpNo) ?></h1>
  <div class="kp-meta">от <?= h($today) ?></div>
  <p class="hint">Номер КП и дата подставляются автоматически. Перед отправкой проверьте реквизиты заказчика ниже.</p>

  <div class="kp-section">
    <h2>Заказчик</h2>
    <dl class="kp-kv">
      <dt>Контактное лицо</dt><dd><?= h($clientName) !== '' ? h($clientName) : '—' ?></dd>
      <dt>Телефон</dt><dd><?= h($clientPhone) !== '' ? h($clientPhone) : '—' ?></dd>
      <dt>E-mail</dt><dd><?= h($clientEmail) !== '' ? h($clientEmail) : '—' ?></dd>
    </dl>
  </div>

  <div class="kp-section">
    <h2>Запрос</h2>
    <dl class="kp-kv">
      <dt>Тип редуктора</dt><dd><?= h($reducer) !== '' ? h($reducer) : '—' ?></dd>
      <dt>Описание задачи</dt><dd><?= h($request) !== '' ? nl2br(h($request)) : '—' ?></dd>
    </dl>
  </div>

  <div class="kp-section">
    <h2>Подобранное решение</h2>
    <?php if ($rec && (!empty($rec['analogs']) || !empty($rec['summary']))): ?>
      <?php if (trim((string)($rec['summary'] ?? '')) !== ''): ?>
        <p class="kp-solution" style="margin-bottom:12px"><?= h((string)$rec['summary']) ?></p>
      <?php endif; ?>
      <?php if (!empty($rec['analogs'])): ?>
      <div class="kp-scroll"><table class="kp-items" style="margin-top:6px">
        <thead><tr><th>Оригинал / модель клиента</th><th>Наш аналог (ZR)</th><th style="width:130px">Соответствие</th></tr></thead>
        <tbody>
          <?php foreach ((array)$rec['analogs'] as $a): ?>
          <tr>
            <td><?= h(trim((string)($a['for'] ?? '—'))) ?: '—' ?></td>
            <td><b><?= h(trim((string)($a['our'] ?? '—'))) ?: '—' ?></b><?php if (!empty($a['note'])): ?><br><span class="hint" style="margin:0"><?= h((string)$a['note']) ?></span><?php endif; ?></td>
            <td><?= h(trim((string)($a['match'] ?? 'подбор'))) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
      <p class="hint">Полная взаимозаменяемость по присоединительным и габаритным размерам. Импортные цены не публикуем — рассчитываем по запросу.</p>
    <?php elseif (trim($suggest) !== ''): ?>
      <div class="kp-solution"><?= h($suggest) ?></div>
    <?php else: ?>
      <div class="kp-solution placeholder">Решение подбирается инженером по техническому заданию заказчика. Уточните, пожалуйста, передаваемую мощность, передаточное число, режим работы и условия эксплуатации — и мы предложим оптимальную модель редуктора с расчётом.</div>
    <?php endif; ?>
  </div>

  <div class="kp-section">
    <h2>Позиции</h2>
    <div class="kp-scroll"><table class="kp-items">
      <thead>
        <tr>
          <th style="width:36px">№</th>
          <th>Наименование</th>
          <th style="width:80px">Кол-во</th>
          <th style="width:120px">Цена, ₽</th>
          <th style="width:130px">Сумма, ₽</th>
        </tr>
      </thead>
      <tbody id="kpItems"><!-- строки рендерятся JS --></tbody>
      <tbody>
        <tr>
          <td colspan="4" class="num"><strong>Итого:</strong></td>
          <td class="num"><strong id="kpTotal">0 ₽</strong></td>
        </tr>
      </tbody>
    </table></div>
    <p class="hint">Заполните наименование, количество и цены. «Сохранить» запишет КП и подставит сумму в сделку. Для импортных позиций цену клиенту не публикуем — пишем «по запросу».</p>
  </div>

  <div class="kp-section">
    <h2>Условия</h2>
    <ul class="kp-terms">
      <li>Гарантия — <input class="kp-inl" id="tWarranty" value="24 месяца"> </li>
      <li>Срок отгрузки — <input class="kp-inl" id="tTerm" value="от 3 рабочих дней"> </li>
      <li>Условия оплаты — <input class="kp-inl" id="tPay" value="50% предоплата, 50% по готовности"> </li>
      <li>Предложение действительно — <input class="kp-inl" id="tValid" value="14 календарных дней"> </li>
      <li>Доставка по РФ и СНГ транспортной компанией.</li>
    </ul>
  </div>

  <div class="kp-sign">
    <div class="col">
      <div class="line">Менеджер ООО «НИИ АТТ» / подпись</div>
    </div>
    <div class="col">
      <div class="line">Заказчик / подпись</div>
    </div>
  </div>

  <div class="kp-foot">
    Завод Редукторов · ООО «НИИ АТТ» · +7 (495) 151-41-02 · zr@zavod-red.ru · zavod-red.ru
  </div>

</div>
<?php endif; ?>

<?php if ($lead): ?>
<script>
const KP_API='../api/kp.php';
const LEAD_ID=<?= (int)$id ?>;
const PREFILL_NAME=<?= json_encode(trim((string)$reducer) !== '' ? ('Редуктор — '.$reducer.' (подбор по ТЗ)') : 'Редуктор по техническому заданию', JSON_UNESCAPED_UNICODE) ?>;
const PREFILL_ITEMS=<?= json_encode($recItems, JSON_UNESCAPED_UNICODE) ?>; // распознанные аналоги ZR
const CSRF=window.CSRF||'';
let ITEMS=[];

function esc(s){const d=document.createElement('div');d.textContent=(s==null?'':String(s));return d.innerHTML;}
function nf(v){return parseFloat(String(v).replace(/\s/g,'').replace(',','.'))||0;}
function money(n){n=Math.round(nf(n));return n.toLocaleString('ru-RU')+' ₽';}

function render(){
  const tb=document.getElementById('kpItems');
  tb.innerHTML=ITEMS.map(function(it,i){
    const sum=nf(it.qty)*nf(it.price);
    return '<tr>'
      +'<td class="num">'+(i+1)+' <button type="button" class="kp-del" data-i="'+i+'" title="Удалить">×</button></td>'
      +'<td><input data-i="'+i+'" data-f="name" value="'+esc(it.name)+'" placeholder="Наименование позиции"></td>'
      +'<td class="num"><input data-i="'+i+'" data-f="qty" inputmode="decimal" value="'+esc(it.qty)+'" style="width:64px"></td>'
      +'<td class="num"><input data-i="'+i+'" data-f="price" inputmode="decimal" value="'+esc(it.price)+'" placeholder="по запросу"></td>'
      +'<td class="num">'+(sum?money(sum):'—')+'</td>'
      +'</tr>';
  }).join('');
  let total=0; ITEMS.forEach(function(it){total+=nf(it.qty)*nf(it.price);});
  document.getElementById('kpTotal').textContent=money(total);
  tb.querySelectorAll('input').forEach(function(inp){
    inp.addEventListener('input',function(){ITEMS[+inp.dataset.i][inp.dataset.f]=inp.value;render_totals();});
  });
  tb.querySelectorAll('.kp-del').forEach(function(b){
    b.addEventListener('click',function(){ITEMS.splice(+b.dataset.i,1);if(!ITEMS.length)ITEMS.push({name:'',qty:1,price:''});render();});
  });
}
// пересчёт итого без полного ререндера (не сбивает фокус)
function render_totals(){
  let total=0; document.querySelectorAll('#kpItems tr').forEach(function(tr,i){
    const it=ITEMS[i]; if(!it)return; const sum=nf(it.qty)*nf(it.price);
    tr.lastElementChild.textContent=sum?money(sum):'—';
    total+=sum;
  });
  document.getElementById('kpTotal').textContent=money(total);
}
function terms(){return {warranty:val('tWarranty'),term:val('tTerm'),pay:val('tPay'),valid:val('tValid')};}
function val(id){const e=document.getElementById(id);return e?e.value:'';}
function setTerms(t){t=t||{};if(t.warranty)document.getElementById('tWarranty').value=t.warranty;if(t.term)document.getElementById('tTerm').value=t.term;if(t.pay)document.getElementById('tPay').value=t.pay;if(t.valid)document.getElementById('tValid').value=t.valid;}
function flash(msg,ok){const e=document.getElementById('kpFlash');e.textContent=msg;e.style.color=ok?'#178a45':'#cf2020';setTimeout(function(){e.textContent='';},2800);}

document.getElementById('kpAddRow').onclick=function(){ITEMS.push({name:'',qty:1,price:''});render();};
async function kpDoSave(){
  const body=new URLSearchParams({action:'save',lead_id:LEAD_ID,items:JSON.stringify(ITEMS),terms:JSON.stringify(terms()),csrf:CSRF});
  const r=await fetch(KP_API,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF':CSRF},body});
  return r.json();
}
document.getElementById('kpSave').onclick=async function(e){
  const b=e.currentTarget, old=b.textContent; b.disabled=true; b.textContent='Сохранение…';
  try{ const j=await kpDoSave(); if(j&&j.ok)flash('Сохранено · итого '+money(j.total),true); else flash((j&&j.error)||'Ошибка',false); }
  catch(err){ flash('Ошибка сети',false); }
  finally{ b.disabled=false; b.textContent=old; }
};
document.getElementById('kpSend').onclick=async function(e){
  if(!confirm('Сохранить и отправить КП клиенту на e-mail?'))return;
  const b=e.currentTarget, old=b.textContent; b.disabled=true; b.textContent='Отправка…';
  try{
    const s=await kpDoSave();
    if(!s||!s.ok){ flash((s&&s.error)||'Не удалось сохранить — отправка отменена',false); return; }
    const body=new URLSearchParams({action:'send',lead_id:LEAD_ID,csrf:CSRF});
    const r=await fetch(KP_API,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF':CSRF},body});
    const j=await r.json();
    if(j&&j.ok)flash(j.note||'Отправлено',true); else flash((j&&j.error)||'Ошибка',false);
  }catch(err){ flash('Ошибка сети',false); }
  finally{ b.disabled=false; b.textContent=old; }
};

// загрузка сохранённого КП или префилл
(async function(){
  try{
    const r=await fetch(KP_API+'?action=get&lead_id='+LEAD_ID,{headers:{'Accept':'application/json'}});
    const j=await r.json();
    if(j&&j.ok&&j.kp&&Array.isArray(j.kp.items)&&j.kp.items.length){ITEMS=j.kp.items;setTerms(j.kp.terms);}
    else if(Array.isArray(PREFILL_ITEMS)&&PREFILL_ITEMS.length){ITEMS=PREFILL_ITEMS.map(function(x){return {name:x.name,qty:x.qty||1,price:''};});}
    else ITEMS=[{name:PREFILL_NAME,qty:1,price:''}];
  }catch(e){ITEMS=[{name:PREFILL_NAME,qty:1,price:''}];}
  render();
})();
</script>
<?php endif; ?>

</body>
</html>
