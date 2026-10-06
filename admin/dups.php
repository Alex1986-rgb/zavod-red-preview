<?php
declare(strict_types=1);

require __DIR__ . '/_guard.php';
require __DIR__ . '/_layout.php';

render_head('Дубли заявок');
render_sidebar('dups');
?>
<style>
  /* Группа дублей — карточка общего слоя (.dgroup в admin.css), заголовок — .card__title,
     таблица — .table-wrap + .tbl. Здесь только то, чего в системе нет. */
  .dgroup .key{background:var(--bg);color:var(--muted);border-radius:var(--r-md);padding:2px 10px;font-size:13px;font-weight:600}
  .d-table a{font-weight:600}
  .d-foot{display:flex;gap:10px;align-items:center;justify-content:flex-end;margin-top:12px}
</style>

<div class="page-head"><h1 class="page-title">Дубли заявок</h1></div>

<div class="help help--info">
  <span class="help__icon">💡</span>
  <div class="help__body">
    <b>Поиск дублирующихся лидов.</b> Один клиент оставил несколько заявок — система группирует их по совпадению нормализованного телефона или email.
    <ul>
      <li>Проверьте группу и выберите основную заявку (радиокнопка).</li>
      <li>Нажмите «Объединить» — переписка, заметки и события перенесутся в основной лид, дубли удалятся.</li>
      <li>Действие необратимо: убедитесь, что заявки действительно от одного клиента.</li>
    </ul>
  </div>
</div>

<p class="page-lead">Группы заявок с одинаковыми контактами. Выберите основную заявку — остальные будут объединены в неё.</p>

<div id="dupsBox"><div class="empty">Загрузка…</div></div>

<script>
const ZR = window.ZR;
const API = '../api/leads.php';

function dt(s){ return s ? ZR.dateTimeRu(s) : '—'; }

async function loadDups(){
  const box = document.getElementById('dupsBox');
  const r = await ZR.apiGet(API, {action:'dups'});
  if(!r || !r.ok){ box.innerHTML = '<div class="empty">'+ZR.escapeHtml((r&&r.error)||'Ошибка загрузки')+'</div>'; return; }
  const groups = r.groups || r.items || [];
  if(!groups.length){ box.innerHTML = '<div class="empty">Дублей не найдено.</div>'; return; }

  let html = '';
  groups.forEach((g, gi) => {
    const key = g.key || g.value || g.phone || g.email || ('Группа ' + (gi+1));
    const items = g.items || g.leads || [];
    html += '<div class="dgroup">'
      + '<h2 class="card__title">Совпадение <span class="key">'+ZR.escapeHtml(key)+'</span></h2>'
      + '<div class="table-wrap"><table class="tbl d-table"><thead><tr><th>Основная</th><th>Лид</th><th>Имя</th><th>Телефон</th><th>Email</th><th>Создана</th></tr></thead><tbody>';
    items.forEach((it, ii) => {
      html += '<tr>'
        + '<td><input type="radio" name="primary-'+gi+'" value="'+Number(it.id)+'" '+(ii===0?'checked':'')+'></td>'
        + '<td><a href="lead.php?id='+Number(it.id)+'">#'+Number(it.id)+'</a></td>'
        + '<td>'+(ZR.escapeHtml(it.name)||'<span class="muted">—</span>')+'</td>'
        + '<td>'+(ZR.escapeHtml(it.phone)||'<span class="muted">—</span>')+'</td>'
        + '<td>'+(ZR.escapeHtml(it.email)||'<span class="muted">—</span>')+'</td>'
        + '<td>'+ZR.escapeHtml(dt(it.created_at))+'</td>'
        + '</tr>';
    });
    const ids = items.map(it => Number(it.id)).join(',');
    html += '</tbody></table></div>'
      + '<div class="d-foot">'
      + '<span class="hint" style="margin:0;margin-right:auto">Объединение необратимо: дубли удаляются, данные переносятся в основную заявку.</span>'
      + '<button class="btn btn--primary" onclick="mergeGroup('+gi+', \''+ids+'\', this)">Объединить</button>'
      + '<span class="tip" data-tip="Сольёт выбранные заявки в основную. Действие необратимо.">?</span>'
      + '</div></div>';
  });
  box.innerHTML = html;
}

async function mergeGroup(gi, idsCsv, btn){
  const radio = document.querySelector('input[name="primary-'+gi+'"]:checked');
  if(!radio){ ZR.toast('Выберите основную заявку', 'error'); return; }
  const to = Number(radio.value);
  const ids = idsCsv.split(',').map(Number).filter(n => n && n !== to);
  if(!ids.length){ ZR.toast('Нет заявок для объединения', 'error'); return; }
  if(!confirm('Объединить '+ids.length+' заявок в #'+to+'?')) return;

  if(btn) btn.disabled = true; // защита от повторного клика на время цикла
  for(const from of ids){
    const r = await ZR.apiPost(API, {action:'merge', from_id:from, to_id:to});
    if(!r || !r.ok){
      ZR.toast((r&&r.error)||('Не удалось объединить #'+from), 'error');
      // Часть лидов могла быть уже объединена и удалена — обновляем список,
      // чтобы в таблице не остались несуществующие заявки.
      await loadDups();
      return;
    }
  }
  ZR.toast('Заявки объединены', 'success');
  setTimeout(() => location.reload(), 600);
}

loadDups();
</script>
<?php render_foot();
