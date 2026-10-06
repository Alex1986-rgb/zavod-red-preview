/* ===== Умный чат: ИИ-консультант по сайту (zavod-red.ru) =====
   Грузится лениво (по клику «Спросить ИИ-консультанта» в виджете или на мобильной панели).
   Ответы — /api/site_chat.php: только открытые знания (тексты сайта, справочник аналогов ZR,
   общие правила). Цену и наличие не называет — ведёт к заявке (data-zayavka → штатная форма).
   История диалога — в sessionStorage этой вкладки. */
(function(){
  if (window.zrChatOpen) return;
  var KEY = 'zr_chat_hist_v1', API = '/api/site_chat.php';
  var hist = [];
  try { hist = JSON.parse(sessionStorage.getItem(KEY) || '[]') || []; } catch (e) { hist = []; }

  var css = ''
   + '.zrc{position:fixed;right:20px;bottom:90px;z-index:999;width:380px;max-width:calc(100vw - 24px);height:560px;max-height:calc(100vh - 120px);'
   + 'display:none;flex-direction:column;background:#fff;color:#14202a;border-radius:16px;box-shadow:0 18px 50px rgba(11,21,29,.28);'
   + 'font-family:inherit;font-size:14.5px;line-height:1.45;overflow:hidden;border:1px solid #e3e8ee}'
   + '.zrc.open{display:flex}'
   + '.zrc__hd{display:flex;align-items:center;gap:10px;padding:13px 14px;background:#0b151d;color:#fff}'
   + '.zrc__hd b{font-size:15px;display:block}.zrc__hd small{opacity:.75;font-size:12px}'
   + '.zrc__x{margin-left:auto;background:none;border:0;color:#fff;font-size:22px;line-height:1;cursor:pointer;padding:4px 6px}'
   + '.zrc__dot{width:9px;height:9px;border-radius:50%;background:#37e06a;box-shadow:0 0 0 3px rgba(55,224,106,.25);flex:none}'
   + '.zrc__log{flex:1;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:10px;background:#f5f7f9}'
   + '.zrc__m{max-width:88%;padding:10px 12px;border-radius:13px;white-space:pre-wrap;word-wrap:break-word}'
   + '.zrc__m--u{align-self:flex-end;background:#e11b1b;color:#fff;border-bottom-right-radius:4px}'
   + '.zrc__m--b{align-self:flex-start;background:#fff;border:1px solid #e3e8ee;border-bottom-left-radius:4px}'
   + '.zrc__links{margin-top:8px;display:flex;flex-direction:column;gap:4px}'
   + '.zrc__links a{color:#c81414;text-decoration:underline;font-size:13.5px}'
   + '.zrc__lead{display:inline-block;margin-top:9px;background:#e11b1b;color:#fff!important;text-decoration:none!important;font-weight:700;'
   + 'padding:8px 14px;border-radius:9px;font-size:13.5px}'
   + '.zrc__chips{display:flex;flex-wrap:wrap;gap:6px;padding:0 14px 10px;background:#f5f7f9}'
   + '.zrc__chips button{border:1px solid #d5dde5;background:#fff;color:#14202a;border-radius:16px;padding:6px 11px;font-size:12.5px;cursor:pointer}'
   + '.zrc__chips button:hover{border-color:#e11b1b;color:#c81414}'
   + '.zrc__f{display:flex;gap:8px;padding:10px;border-top:1px solid #e3e8ee;background:#fff}'
   + '.zrc__f textarea{flex:1;resize:none;border:1px solid #d5dde5;border-radius:10px;padding:9px 11px;font:inherit;height:42px;max-height:110px;outline:none}'
   + '.zrc__f textarea:focus{border-color:#e11b1b}'
   + '.zrc__f button{background:#e11b1b;color:#fff;border:0;border-radius:10px;padding:0 15px;font-weight:700;cursor:pointer}'
   + '.zrc__f button:disabled{opacity:.5;cursor:default}'
   + '.zrc__note{font-size:11px;color:#8a97a3;padding:0 12px 8px;background:#fff}'
   + '.zrc__typing{opacity:.7;font-style:italic}'
   + '@media(max-width:600px){.zrc{right:0;left:0;bottom:0;width:100%;max-width:100%;height:100%;max-height:100%;border-radius:0}}';
  var st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);

  var box = document.createElement('div');
  box.className = 'zrc'; box.setAttribute('role', 'dialog'); box.setAttribute('aria-label', 'ИИ-консультант');
  box.innerHTML = '<div class="zrc__hd"><span class="zrc__dot"></span><div><b>ИИ-консультант ZR</b><small>Подбор аналога, модели, условия — отвечу сразу</small></div>'
    + '<button class="zrc__x" type="button" aria-label="Закрыть">×</button></div>'
    + '<div class="zrc__log"></div>'
    + '<div class="zrc__chips"></div>'
    + '<form class="zrc__f"><textarea rows="1" maxlength="600" placeholder="Например: аналог SEW R97 5,5 кВт"></textarea><button type="submit">➤</button></form>'
    + '<div class="zrc__note">Ответы ИИ носят справочный характер. Цену и срок рассчитает инженер по заявке.</div>';
  document.body.appendChild(box);
  var log = box.querySelector('.zrc__log'), ta = box.querySelector('textarea'), btn = box.querySelector('.zrc__f button'),
      chips = box.querySelector('.zrc__chips');

  function esc(s){ return String(s).replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
  function save(){ try { sessionStorage.setItem(KEY, JSON.stringify(hist.slice(-20))); } catch (e) {} }
  function add(role, text, links, lead){
    var d = document.createElement('div');
    d.className = 'zrc__m zrc__m--' + (role === 'user' ? 'u' : 'b');
    var h = esc(text);
    if (links && links.length) {
      h += '<div class="zrc__links">' + links.map(function(l){
        var u = String(l.url || '');
        if (!/^https:\/\/(www\.)?zavod-red\.ru\//.test(u)) return '';
        return '<a href="' + esc(u) + '">' + esc(l.title || u) + '</a>';
      }).join('') + '</div>';
    }
    if (lead) h += '<br><a class="zrc__lead" href="#zayavka" data-zayavka>Оставить заявку — подбор бесплатно</a>';
    d.innerHTML = h;
    log.appendChild(d); log.scrollTop = log.scrollHeight;
    return d;
  }
  function render(){
    log.innerHTML = '';
    if (!hist.length) {
      add('bot', 'Здравствуйте! Помогу подобрать редуктор или аналог импортного (SEW, NORD, Bonfiglioli, Motovario и др.), подскажу по сериям, документам и условиям. Что нужно?');
      chips.innerHTML = ['Аналог SEW R97', 'Чем заменить NMRV 063?', 'Какая гарантия и документы?', 'Как подобрать по шильдику?']
        .map(function(c){ return '<button type="button">' + esc(c) + '</button>'; }).join('');
    } else {
      chips.innerHTML = '';
      hist.forEach(function(m){ add(m.role, m.text, m.links, m.lead); });
    }
  }
  function send(q){
    q = String(q || '').trim();
    if (!q || btn.disabled) return;
    chips.innerHTML = '';
    hist.push({role: 'user', text: q}); add('user', q); save();
    ta.value = ''; btn.disabled = true;
    var wait = add('bot', 'Ищу ответ…'); wait.classList.add('zrc__typing');
    var payload = {q: q, page: location.pathname, history: hist.slice(-7, -1).map(function(m){ return {role: m.role, text: m.text}; })};
    // Не ждём бесконечно: через 40 с обрываем запрос и предлагаем заявку/звонок.
    var ac = window.AbortController ? new AbortController() : null, tm = ac && setTimeout(function(){ ac.abort(); }, 40000);
    fetch(API, {method: 'POST', headers: {'Content-Type': 'application/json'}, credentials: 'same-origin', body: JSON.stringify(payload), signal: ac ? ac.signal : undefined})
      .then(function(r){ return r.json(); })
      .then(function(d){
        wait.remove();
        var m = {role: 'bot', text: (d && d.answer) || 'Не получилось ответить. Оставьте заявку — инженер подберёт решение.', links: (d && d.links) || [], lead: !!(d && d.lead)};
        hist.push(m); add(m.role, m.text, m.links, m.lead); save();
      })
      .catch(function(){
        wait.remove();
        add('bot', 'Консультант сейчас не успел ответить. Позвоните +7 (495) 151-41-02 или оставьте заявку.', [], true);
      })
      .then(function(){ if (tm) clearTimeout(tm); btn.disabled = false; ta.focus(); });
    try { if (window.ym && hist.length <= 2) ym(109758131, 'reachGoal', 'ai_chat_question'); } catch (e) {}
  }

  box.querySelector('.zrc__x').addEventListener('click', function(){ box.classList.remove('open'); });
  box.querySelector('form').addEventListener('submit', function(e){ e.preventDefault(); send(ta.value); });
  ta.addEventListener('keydown', function(e){ if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(ta.value); } });
  chips.addEventListener('click', function(e){ var b = e.target.closest('button'); if (b) send(b.textContent); });
  // Кнопка «Оставить заявку» внутри чата: закрываем чат, штатная форма откроется своим обработчиком [data-zayavka].
  log.addEventListener('click', function(e){ if (e.target.closest('[data-zayavka]')) box.classList.remove('open'); });

  window.zrChatOpen = function(){ render(); box.classList.add('open'); setTimeout(function(){ ta.focus(); }, 50); };
})();
