/* Завод Редукторов CRM — общие хелперы админки.
   Vanilla JS, без сборки. window.CSRF проброшен из _layout.php (render_head). */
(function () {
  'use strict';

  /* ---------- fetch с CSRF ---------- */

  function api(url, opts) {
    opts = opts || {};
    opts.credentials = 'same-origin';
    opts.headers = opts.headers || {};
    var method = (opts.method || 'GET').toUpperCase();
    if (method !== 'GET' && method !== 'HEAD') {
      opts.headers['X-CSRF'] = window.CSRF || '';
    }
    return fetch(url, opts).then(function (r) {
      return r.json().catch(function () {
        return { ok: false, error: 'Некорректный ответ сервера (' + r.status + ')' };
      });
    });
  }

  /** GET JSON. params — объект query-параметров. */
  function apiGet(url, params) {
    if (params) {
      var qs = Object.keys(params)
        .filter(function (k) { return params[k] !== undefined && params[k] !== null && params[k] !== ''; })
        .map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); })
        .join('&');
      if (qs) url += (url.indexOf('?') === -1 ? '?' : '&') + qs;
    }
    return api(url, { method: 'GET' });
  }

  /** POST form-data (+CSRF). data — объект. */
  function apiPost(url, data) {
    var fd = new FormData();
    fd.append('csrf', window.CSRF || '');
    if (data) {
      Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    }
    return api(url, { method: 'POST', body: fd });
  }

  /* ---------- тосты ---------- */

  function toast(msg, type) {
    var box = document.getElementById('toast-box');
    if (!box) {
      box = document.createElement('div');
      box.id = 'toast-box';
      box.className = 'toast-box';
      document.body.appendChild(box);
    }
    var el = document.createElement('div');
    el.className = 'toast toast--' + (type || 'info');
    el.textContent = msg;
    box.appendChild(el);
    requestAnimationFrame(function () { el.classList.add('toast--show'); });
    setTimeout(function () {
      el.classList.remove('toast--show');
      setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 300);
    }, 3500);
  }

  /* ---------- форматирование ---------- */

  function money(v) {
    var n = Number(v) || 0;
    try {
      return n.toLocaleString('ru-RU', { style: 'currency', currency: 'RUB', maximumFractionDigits: 0 });
    } catch (e) {
      return n.toLocaleString('ru-RU') + ' ₽';
    }
  }

  function num(v) {
    var n = Number(v) || 0;
    return n.toLocaleString('ru-RU');
  }

  /** ISO/SQL дату → ДД.ММ.ГГГГ. */
  function dateRu(v) {
    if (!v) return '';
    var d = (v instanceof Date) ? v : new Date(String(v).replace(' ', 'T'));
    if (isNaN(d.getTime())) return String(v);
    return d.toLocaleDateString('ru-RU');
  }

  /** → ДД.ММ.ГГГГ ЧЧ:ММ. */
  function dateTimeRu(v) {
    if (!v) return '';
    var d = (v instanceof Date) ? v : new Date(String(v).replace(' ', 'T'));
    if (isNaN(d.getTime())) return String(v);
    return d.toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
  }

  /** YYYY-MM-DD для input[type=date]. */
  function isoDate(d) {
    d = d || new Date();
    var m = String(d.getMonth() + 1).padStart(2, '0');
    var day = String(d.getDate()).padStart(2, '0');
    return d.getFullYear() + '-' + m + '-' + day;
  }

  function escapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }

  /* ---------- выход ---------- */

  function bindLogout() {
    var link = document.getElementById('logout-link');
    if (!link) return;
    link.addEventListener('click', function (e) {
      e.preventDefault();
      apiPost('../api/auth.php', { action: 'logout' }).then(function () {
        window.location.href = 'login.php';
      }).catch(function () {
        window.location.href = 'login.php';
      });
    });
  }

  /* ---------- колокол уведомлений (счётчик «требуют действия») ---------- */
  function loadBell() {
    var bell = document.getElementById('appbarBell');
    var badge = document.getElementById('appbarBadge');
    if (!bell || !badge) return;
    apiGet('../api/engineer.php', { action: 'count' }).then(function (j) {
      if (!j || !j.ok) return;
      // Бейдж = очередь инженера (колокол ведёт на engineer.php, число должно совпадать с тем, что откроется).
      var eng = j.engineer || 0;
      if (eng > 0) {
        badge.textContent = eng > 99 ? '99+' : String(eng);
        badge.hidden = false;
      } else {
        badge.hidden = true;
      }
      var parts = [];
      if (j.engineer) parts.push(j.engineer + ' ждут инженера');
      if (j.new) parts.push(j.new + ' новых заявок');
      if (j.overdue) parts.push(j.overdue + ' просроченных задач');
      bell.title = parts.length ? parts.join(' · ') : 'Нет новых уведомлений';
    }).catch(function () {});
  }

  /* Сворачиваемые help-подсказки: по умолчанию свёрнуты (видна первая строка),
     клик разворачивает. Состояние на страницу запоминается в localStorage. */
  function initHelp() {
    var key = 'zr_help_open:' + location.pathname;
    var open = false;
    try { open = localStorage.getItem(key) === '1'; } catch (e) {}
    document.querySelectorAll('.help').forEach(function (h) {
      h.classList.add('help--collapsible');
      if (!open) h.classList.add('help--collapsed');
      h.addEventListener('click', function (e) {
        if (e.target.closest('a, button')) return; // не мешаем ссылкам/кнопкам внутри
        var nowCollapsed = h.classList.toggle('help--collapsed');
        try { localStorage.setItem(key, nowCollapsed ? '0' : '1'); } catch (e2) {}
      });
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    bindLogout();
    loadBell();
    setInterval(loadBell, 60000);
    initHelp();
  });

  /* ---------- экспорт ---------- */
  window.ZR = {
    api: api, apiGet: apiGet, apiPost: apiPost,
    toast: toast,
    money: money, num: num,
    dateRu: dateRu, dateTimeRu: dateTimeRu, isoDate: isoDate,
    escapeHtml: escapeHtml
  };

  /* Chart.js скачан локально (assets/chart.umd.min.js). Если по какой-то причине
     curl был недоступен при сборке — заменить файл вручную:
     curl -sL https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js \
       -o admin/assets/chart.umd.min.js   (должен быть >100КБ). */
})();


/* ═══════════════════════════════════════════
   МОДЕРНИЗАЦИЯ: табы, компактный режим, фиксация
   ═══════════════════════════════════════════ */
(function(){
  'use strict';

  /* --- Табы --- */
  document.addEventListener('click', function(e){
    var tab = e.target.closest('.tabs button, .tabs a');
    if (!tab || tab.classList.contains('active')) return;
    e.preventDefault();
    
    var tabsContainer = tab.closest('.tabs');
    var tabId = tab.getAttribute('data-tab');
    if (!tabId) return;
    
    // Deactivate all tabs
    tabsContainer.querySelectorAll('button, a').forEach(function(t){ t.classList.remove('active'); });
    tab.classList.add('active');
    
    // Show target panel
    var panels = document.querySelectorAll('.tab-panel');
    panels.forEach(function(p){ p.classList.remove('active'); });
    var target = document.getElementById('tab-' + tabId);
    if (target) target.classList.add('active');
    else {
      // Try finding by data-tab attribute on panels
      var match = document.querySelector('.tab-panel[data-tab="' + tabId + '"]');
      if (match) match.classList.add('active');
    }
  });

  /* --- Компактный режим (сохраняется в localStorage) --- */
  var DENSE_KEY = 'zr_crm_dense';
  function applyDense(on) {
    document.body.classList.toggle('dense', on);
    try { localStorage.setItem(DENSE_KEY, on ? '1' : '0'); } catch(e) {}
  }
  
  // Восстановить сохранённый режим
  try {
    if (localStorage.getItem(DENSE_KEY) === '1') {
      applyDense(true);
    }
  } catch(e) {}

  // Добавить кнопку переключения в appbar
  function addDenseToggle() {
    var appbar = document.querySelector('.appbar__right');
    if (!appbar || document.getElementById('denseToggle')) return;
    
    var btn = document.createElement('button');
    btn.id = 'denseToggle';
    btn.className = 'appbar__ic';
    btn.title = 'Компактный режим';
    btn.setAttribute('aria-label', 'Компактный режим');
    btn.style.cssText = 'background:none;border:0;cursor:pointer;color:var(--muted);font-size:13px;font-weight:700;padding:4px 10px;border-radius:8px;margin-right:4px;transition:.15s';
    btn.textContent = document.body.classList.contains('dense') ? '⊞' : '⊟';
    btn.addEventListener('click', function(){
      var on = !document.body.classList.contains('dense');
      applyDense(on);
      btn.textContent = on ? '⊞' : '⊟';
    });
    btn.addEventListener('mouseenter', function(){ this.style.background = 'rgba(0,0,0,.04)'; });
    btn.addEventListener('mouseleave', function(){ this.style.background = 'none'; });
    
    appbar.insertBefore(btn, appbar.firstChild);
  }
  
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', addDenseToggle);
  } else {
    addDenseToggle();
  }

  /* --- Авто-активация первого таба --- */
  function initTabs() {
    document.querySelectorAll('.tabs').forEach(function(tabs){
      var hasActive = tabs.querySelector('.active');
      if (!hasActive) {
        var first = tabs.querySelector('button, a');
        if (first) first.click();
      }
    });
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initTabs);
  } else {
    initTabs();
  }
})();


/* ═══════════════════════════════════════════
   ТЁМНАЯ ТЕМА + ГОРЯЧИЕ КЛАВИШИ + LIVE-СЧЁТЧИК
   ═══════════════════════════════════════════ */
(function(){
  'use strict';

  /* --- Тёмная тема --- */
  var THEME_KEY = 'zr_crm_theme';
  function setTheme(t) {
    document.documentElement.setAttribute('data-theme', t);
    try { localStorage.setItem(THEME_KEY, t); } catch(e) {}
  }
  function toggleTheme() {
    var cur = document.documentElement.getAttribute('data-theme') || 'light';
    setTheme(cur === 'dark' ? 'light' : 'dark');
    updateThemeBtn();
  }
  function updateThemeBtn() {
    var btn = document.getElementById('themeToggle');
    if (!btn) return;
    var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    btn.textContent = isDark ? '☀' : '🌙';
    btn.title = isDark ? 'Светлая тема' : 'Тёмная тема';
  }
  
  // Restore saved theme
  try {
    var saved = localStorage.getItem(THEME_KEY);
    if (saved) setTheme(saved);
  } catch(e) {}

  // Add theme toggle to appbar
  function addThemeToggle() {
    var appbar = document.querySelector('.appbar__right');
    if (!appbar || document.getElementById('themeToggle')) return;
    
    var btn = document.createElement('button');
    btn.id = 'themeToggle';
    btn.className = 'appbar__ic';
    btn.style.cssText = 'background:none;border:0;cursor:pointer;font-size:16px;padding:4px 8px;border-radius:8px;transition:.15s;margin-right:2px';
    btn.addEventListener('click', toggleTheme);
    btn.addEventListener('mouseenter', function(){ this.style.background = 'rgba(0,0,0,.05)'; });
    btn.addEventListener('mouseleave', function(){ this.style.background = 'none'; });
    
    var spacer = appbar.querySelector('.appbar__spacer');
    if (spacer) {
      spacer.parentNode.insertBefore(btn, spacer.nextSibling);
    } else {
      appbar.insertBefore(btn, appbar.firstChild);
    }
    updateThemeBtn();
  }

  /* --- Горячие клавиши (глобальные) --- */
  document.addEventListener('keydown', function(e){
    // Не перехватываем когда фокус в поле ввода
    var tag = document.activeElement && document.activeElement.tagName;
    var isInput = tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || document.activeElement.isContentEditable;
    
    // Esc — закрыть модалку / снять фокус
    if (e.key === 'Escape' && !isInput) {
      var modal = document.querySelector('.zr-modal:not([hidden])');
      if (modal) { modal.setAttribute('hidden',''); return; }
      if (document.activeElement) document.activeElement.blur();
      return;
    }
    
    // Ctrl+S / Cmd+S — сохранить (в форме)
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
      if (isInput) return; // native save in textarea
      e.preventDefault();
      var saveBtn = document.querySelector('#saveDeal, #saveTags, #addNote, [data-hotkey="save"]');
      if (saveBtn) saveBtn.click();
      return;
    }
    
    // Alt+T — тёмная тема
    if (e.altKey && e.key === 't') { e.preventDefault(); toggleTheme(); return; }
    
    // Alt+D — компактный режим
    if (e.altKey && e.key === 'd') { e.preventDefault(); 
      var dbtn = document.getElementById('denseToggle'); if (dbtn) dbtn.click(); return; }
    
    // G then L — быстрый переход: список заявок
    if (e.key === 'l' && !e.ctrlKey && !e.metaKey && !isInput) {
      // Only if not already typing
      if (window._gKey === 'g') { window.location = 'leads.php'; return; }
    }
    // G then D — дашборд
    if (e.key === 'd' && !e.ctrlKey && !e.metaKey && !isInput) {
      if (window._gKey === 'g') { window.location = 'index.php'; return; }
    }
    // G then S — настройки
    if (e.key === 's' && !e.ctrlKey && !e.metaKey && !isInput) {
      if (window._gKey === 'g') { window.location = 'settings.php'; return; }
    }
    // Track 'g' for go-to shortcuts
    if (e.key === 'g' && !e.ctrlKey && !e.metaKey && !isInput) {
      window._gKey = 'g';
      setTimeout(function(){ window._gKey = null; }, 800);
      return;
    }
    
    // Стрелки влево/вправо — навигация по заявкам в карточке
    if (!isInput && (e.key === 'ArrowLeft' || e.key === 'ArrowRight')) {
      var m = location.search.match(/[?&]id=(\d+)/);
      if (m && !document.querySelector('.zr-modal:not([hidden])')) {
        var id = parseInt(m[1]);
        if (e.key === 'ArrowLeft') { location.search = '?id=' + Math.max(1, id - 1); }
        else { location.search = '?id=' + (id + 1); }
      }
    }
  });

  /* --- Живой счётчик новых заявок в appbar --- */
  function pollBadge() {
    var badge = document.getElementById('appbarBadge');
    var bell = document.getElementById('appbarBell');
    if (!badge || !bell) return;
    
    fetch('/admin/leads.php?action=count_new', { headers: { 'Accept': 'application/json' } })
      .then(function(r){ return r.json(); })
      .then(function(d){
        if (d && d.count > 0) {
          badge.textContent = d.count > 99 ? '99+' : d.count;
          badge.hidden = false;
          bell.style.color = '#e11b1b';
        } else {
          badge.hidden = true;
          bell.style.color = '';
        }
      })
      .catch(function(){});
  }
  
  // Poll every 60s on leads/index pages
  if (/leads|index/.test(location.pathname)) {
    pollBadge();
    setInterval(pollBadge, 60000);
  }

  /* --- Кнопка «Быстрое создание заявки» (из любого места) --- */
  function addQuickCreate() {
    var appbar = document.querySelector('.appbar__right');
    if (!appbar || document.getElementById('quickCreate')) return;
    
    var btn = document.createElement('button');
    btn.id = 'quickCreate';
    btn.style.cssText = 'background:var(--red);color:#fff;border:0;border-radius:8px;padding:7px 14px;font:600 13px inherit;cursor:pointer;margin-right:8px;transition:.15s;white-space:nowrap';
    btn.textContent = '+ Заявка';
    btn.title = 'Быстрое создание заявки';
    btn.addEventListener('click', function(){
      // Simple prompt-based lead creation
      var name = prompt('Имя клиента:');
      if (!name) return;
      var phone = prompt('Телефон:');
      if (!phone && !confirm('Без телефона — продолжить?')) return;
      
      fetch('/api/leads.php?action=create_manual', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF': window.CSRF },
        body: 'name=' + encodeURIComponent(name) + '&phone=' + encodeURIComponent(phone || '') + '&status=new&source=manual'
      }).then(function(r){ return r.json(); })
        .then(function(d){
          if (d.ok) { alert('Заявка #' + d.id + ' создана'); location.href = 'lead.php?id=' + d.id; }
          else { alert('Ошибка: ' + (d.error || 'неизвестно')); }
        }).catch(function(){ alert('Сетевая ошибка'); });
    });
    
    var spacer = appbar.querySelector('.appbar__spacer');
    if (spacer) {
      spacer.parentNode.insertBefore(btn, spacer.nextSibling);
    } else {
      appbar.insertBefore(btn, appbar.firstChild);
    }
  }

  // Init when DOM ready
  function initFeatures() {
    addThemeToggle();
    addQuickCreate();
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initFeatures);
  } else {
    initFeatures();
  }
})();
