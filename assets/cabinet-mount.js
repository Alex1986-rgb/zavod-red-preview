/* Личный кабинет клиента ЗР — рабочее пространство (v43).
   Боковая навигация + разделы: Обзор, Заказы, Избранное, Мои подборы, Документы,
   Данные компании, Поддержка, Настройки. Данные из localStorage + аккаунт с сервера
   (zr_session/zr_profile через auth.js/me.php). Смена пароля — /api/cabinet/change-password.php.
   Публичный API для других страниц: window.zrCab.addFavorite(obj) / addRequest(obj). */
(function () {
  "use strict";
  var d = document, AUTH_API = "/api/cabinet/", FEEDBACK = "/api/feedback.php";

  /* ---------- storage ---------- */
  function jget(k, def) { try { var v = JSON.parse(localStorage.getItem(k)); return v == null ? def : v; } catch (e) { return def; } }
  function jset(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }
  function orders() { return jget("zr_orders", []); }
  function profile() { return jget("zr_profile", null); }
  function session() { return jget("zr_session", null); }
  function favorites() { return jget("zr_favorites", []); }
  function requests() { return jget("zr_requests", []); }
  function support() { return jget("zr_support", []); }
  function addresses() { var a = jget("zr_addresses", null); if (a) return a; var p = profile() || {}; return p.addr ? [p.addr] : []; }
  function notify() { return jget("zr_notify", { email: true, sms: false, status: true }); }
  function getCart() { return jget("zr_cart", { items: [] }); }
  function saveCart(c) { jset("zr_cart", c); if (window.zrRefreshBadge) try { window.zrRefreshBadge(); } catch (_) {} }

  /* ---------- utils ---------- */
  function rub(n) { return Math.round(n).toLocaleString("ru-RU") + " ₽"; }
  function esc(s) { return String(s == null ? "" : s).replace(/[<>&"]/g, function (c) { return { "<": "&lt;", ">": "&gt;", "&": "&amp;", '"': "&quot;" }[c]; }); }
  function dmy(iso) { try { var x = new Date(iso), p = function (n) { return ("0" + n).slice(-2); }; return p(x.getDate()) + "." + p(x.getMonth() + 1) + "." + x.getFullYear(); } catch (e) { return ""; } }
  function plural(n, a, b, c) { n = Math.abs(n) % 100; var n1 = n % 10; if (n > 10 && n < 20) return c; if (n1 > 1 && n1 < 5) return b; if (n1 === 1) return a; return c; }
  function toast(m, warn) { var t = d.getElementById("zr-cab-toast"); if (!t) { t = d.createElement("div"); t.id = "zr-cab-toast"; d.body.appendChild(t); } t.textContent = m; t.className = "on" + (warn ? " warn" : ""); clearTimeout(t._h); t._h = setTimeout(function () { t.className = ""; }, 2600); }

  var STAGES = ["Принят", "Подтверждён", "В производстве", "Отгружен"];
  function stageOf(o) { var min = (Date.now() - new Date(o.date).getTime()) / 60000; return min < 10 ? 0 : min < 1440 ? 1 : min < 4320 ? 2 : 3; }

  /* ---------- nav model ---------- */
  var NAV = [
    { id: "overview", ic: "▣", t: "Обзор" },
    { id: "orders", ic: "📦", t: "Заказы", n: function () { return orders().length; } },
    { id: "favorites", ic: "❤", t: "Избранное", n: function () { return favorites().length; } },
    { id: "requests", ic: "🔍", t: "Мои подборы", n: function () { return requests().length; } },
    { id: "docs", ic: "📄", t: "Документы" },
    { id: "profile", ic: "🏢", t: "Данные компании" },
    { id: "support", ic: "💬", t: "Поддержка" },
    { id: "settings", ic: "⚙", t: "Настройки" }
  ];

  /* ---------- css ---------- */
  function css() {
    if (d.getElementById("zr-cab-css")) return;
    var s = d.createElement("style"); s.id = "zr-cab-css";
    s.textContent = [
      ".zr-cab{background:#eef2f6;min-height:60vh}.zr-cab *{box-sizing:border-box}",
      ".zr-cab-in{max-width:1240px;margin:0 auto;padding:14px 20px 48px}",
      ".zr-cab-top{display:flex;align-items:center;gap:16px;margin-bottom:18px;flex-wrap:wrap}",
      ".zr-cab-ava{width:60px;height:60px;border-radius:16px;background:linear-gradient(135deg,#ff4b3c,#e11b1b);color:#fff;display:flex;align-items:center;justify-content:center;font:800 22px 'Space Grotesk',sans-serif;flex:none}",
      ".zr-cab-top h1{font:700 clamp(21px,2.8vw,29px)/1.1 'Space Grotesk',sans-serif;color:#0f1c26;margin:0 0 3px}",
      ".zr-cab-top .sub{color:#5a6a75;font-size:13.5px}",
      ".zr-cab-top .badge{display:inline-block;margin-top:7px;padding:5px 13px;border-radius:999px;background:rgba(18,145,95,.1);color:#12915f;font-weight:700;font-size:12.5px}",
      ".zr-cab-top .spacer{flex:1}",
      ".zr-logout{border:1px solid rgba(12,20,28,.14);background:#fff;color:#5a6a75;font:600 13px 'Archivo',sans-serif;padding:9px 16px;border-radius:10px;cursor:pointer;transition:.15s}.zr-logout:hover{border-color:#e11b1b;color:#e11b1b}",
      ".zr-cab-grid{display:grid;grid-template-columns:230px 1fr;gap:18px;align-items:start}",
      ".zr-nav{background:#fff;border:1px solid rgba(12,20,28,.1);border-radius:16px;padding:8px;position:sticky;top:12px}",
      ".zr-nav button{display:flex;align-items:center;gap:11px;width:100%;border:0;background:none;padding:11px 13px;border-radius:11px;font:600 14px 'Archivo',sans-serif;color:#3f4b55;cursor:pointer;transition:.13s;text-align:left}",
      ".zr-nav button .ic{font-size:16px;width:20px;text-align:center;flex:none}",
      ".zr-nav button:hover{background:#f2f5f8}",
      ".zr-nav button.on{background:#e11b1b;color:#fff}",
      ".zr-nav button .cnt{margin-left:auto;font-size:12px;font-weight:800;background:rgba(12,20,28,.08);color:#5a6a75;min-width:22px;text-align:center;padding:2px 7px;border-radius:999px}",
      ".zr-nav button.on .cnt{background:rgba(255,255,255,.25);color:#fff}",
      ".zr-main{min-width:0}",
      ".zr-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:13px;margin-bottom:16px}",
      ".zr-kpi{background:#fff;border:1px solid rgba(12,20,28,.1);border-radius:15px;padding:16px 18px}",
      ".zr-kpi .n{font:800 25px 'Space Grotesk',sans-serif;color:#0f1c26;letter-spacing:-.02em}.zr-kpi .n.acc{color:#e11b1b}.zr-kpi .n.good{color:#12915f}",
      ".zr-kpi .l{color:#8a97a1;font-size:11px;letter-spacing:.04em;text-transform:uppercase;font-weight:700;margin-top:3px}",
      ".zr-card{background:#fff;border:1px solid rgba(12,20,28,.1);border-radius:16px;padding:22px 24px;margin-bottom:16px}",
      ".zr-card h2{font:700 17px 'Space Grotesk',sans-serif;color:#0f1c26;margin:0 0 4px}.zr-card .cap{color:#8a97a1;font-size:13px;margin:0 0 16px}",
      ".zr-h{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:14px}.zr-h h2{margin:0}",
      ".zr-qa{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}",
      ".zr-qa a{display:flex;flex-direction:column;gap:8px;padding:16px;border-radius:14px;border:1px solid rgba(12,20,28,.1);background:#fff;text-decoration:none;color:#0f1c26;transition:.15s}",
      ".zr-qa a:hover{border-color:#e11b1b;box-shadow:0 8px 22px rgba(225,27,27,.1);transform:translateY(-2px)}",
      ".zr-qa .qi{font-size:24px}.zr-qa .qt{font:700 14.5px 'Archivo',sans-serif}.zr-qa .qd{color:#8a97a1;font-size:12px}",
      ".zr-loyal{display:flex;align-items:center;gap:16px;flex-wrap:wrap}.zr-loyal .lt{font-weight:700;color:#0f1c26;font-size:14px}.zr-loyal .ls{color:#5a6a75;font-size:13px}",
      ".zr-loyal .bar{flex:1;min-width:150px;height:8px;background:#eef2f6;border-radius:5px;overflow:hidden}.zr-loyal .bar span{display:block;height:100%;background:linear-gradient(90deg,#ff4b3c,#e11b1b);border-radius:5px}",
      ".zr-chips{display:inline-flex;background:#fff;border:1px solid rgba(12,20,28,.1);border-radius:12px;padding:4px;gap:3px;margin-bottom:14px;flex-wrap:wrap}",
      ".zr-chips button{border:0;background:none;padding:9px 15px;border-radius:9px;font:600 13.5px 'Archivo',sans-serif;color:#3f4b55;cursor:pointer}.zr-chips button.on{background:#e11b1b;color:#fff}",
      ".zr-ord{background:#fff;border:1px solid rgba(12,20,28,.1);border-radius:16px;padding:18px 20px;margin-bottom:13px}",
      ".zr-ord-top{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}",
      ".zr-ord-no{font:700 15px 'IBM Plex Mono',monospace;color:#0f1c26}.zr-ord-date{color:#8a97a1;font-size:13px;margin-left:9px}",
      ".zr-st{padding:5px 12px;border-radius:999px;background:rgba(225,27,27,.1);color:#e11b1b;font-weight:700;font-size:12px}.zr-st.done{background:rgba(18,145,95,.12);color:#12915f}",
      ".zr-step{display:flex;margin:16px 0 6px}.zr-step-i{flex:1;position:relative;text-align:center;font-size:10.5px;color:#9aa6af;font-weight:600}",
      ".zr-step-i .dot{display:block;width:12px;height:12px;border-radius:50%;background:#dfe5ea;margin:0 auto 7px;position:relative;z-index:1}",
      ".zr-step-i::before{content:'';position:absolute;top:5px;left:-50%;width:100%;height:2px;background:#dfe5ea}.zr-step-i:first-child::before{display:none}",
      ".zr-step-i.done .dot{background:#e11b1b}.zr-step-i.done::before{background:#e11b1b}.zr-step-i.cur .lbl{color:#0f1c26}.zr-step-i.cur .dot{box-shadow:0 0 0 4px rgba(225,27,27,.16)}",
      ".zr-ord-items{border-top:1px solid rgba(12,20,28,.07);margin-top:13px;padding-top:11px}.zr-ord-it{display:flex;justify-content:space-between;gap:10px;padding:4px 0;font-size:13.5px;color:#3f4b55}.zr-ord-it b{color:#0f1c26;font-weight:600}",
      ".zr-ord-foot{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;border-top:1px solid rgba(12,20,28,.07);margin-top:10px;padding-top:11px}.zr-ord-total b{font:700 19px 'Space Grotesk',sans-serif;color:#0f1c26}.zr-ord-total span{color:#8a97a1;font-size:13px}",
      ".zr-fav{display:grid;grid-template-columns:repeat(3,1fr);gap:13px}",
      ".zr-fav-c{background:#fff;border:1px solid rgba(12,20,28,.1);border-radius:15px;padding:16px;display:flex;flex-direction:column;gap:8px}",
      ".zr-fav-c .fn{font:700 15px 'Space Grotesk',sans-serif;color:#0f1c26}.zr-fav-c .fp{color:#e11b1b;font-weight:700;font-size:14px}.zr-fav-c .fd{color:#8a97a1;font-size:12.5px}",
      ".zr-fav-c .fb{display:flex;gap:8px;margin-top:auto;padding-top:6px}",
      ".zr-req{display:flex;align-items:center;gap:14px;background:#fff;border:1px solid rgba(12,20,28,.1);border-radius:14px;padding:15px 18px;margin-bottom:11px}",
      ".zr-req .ri{width:40px;height:40px;border-radius:11px;background:rgba(225,27,27,.09);color:#e11b1b;display:flex;align-items:center;justify-content:center;font-size:18px;flex:none}",
      ".zr-req .rt{font-weight:700;color:#0f1c26;font-size:14.5px}.zr-req .rd{color:#8a97a1;font-size:12.5px}.zr-req .rs{margin-left:auto;font-size:12px;font-weight:700;padding:5px 11px;border-radius:999px;background:rgba(225,150,20,.13);color:#c07a10}",
      ".zr-doc{display:flex;align-items:center;gap:14px;padding:14px 0;border-bottom:1px solid rgba(12,20,28,.07)}.zr-doc:last-child{border-bottom:0}",
      ".zr-doc .di{width:40px;height:40px;border-radius:10px;background:rgba(225,27,27,.1);color:#e11b1b;display:flex;align-items:center;justify-content:center;font-size:18px}.zr-doc .dn{font-weight:600;color:#0f1c26}.zr-doc .dd{color:#8a97a1;font-size:13px}.zr-doc a{margin-left:auto;color:#e11b1b;font-weight:700;font-size:14px;text-decoration:none}",
      ".zr-field{margin-bottom:13px}.zr-field label{display:block;font-size:13px;font-weight:600;color:#5a6a75;margin-bottom:6px}",
      ".zr-field input,.zr-field textarea{width:100%;background:#f5f7f9;border:1px solid rgba(12,20,28,.1);border-radius:11px;padding:12px 14px;font:500 15px 'Archivo',sans-serif;color:#0f1c26;transition:.15s}",
      ".zr-field textarea{min-height:92px;resize:vertical}.zr-field input:focus,.zr-field textarea:focus{outline:none;border-color:#e11b1b;background:#fff;box-shadow:0 0 0 3px rgba(225,27,27,.12)}",
      ".zr-frow{display:grid;grid-template-columns:1fr 1fr;gap:0 16px}",
      ".zr-seg{display:inline-flex;background:#eef2f6;border-radius:11px;padding:4px;gap:4px;margin-bottom:16px}.zr-seg button{border:none;background:none;padding:9px 16px;border-radius:8px;font:600 14px 'Archivo',sans-serif;color:#3f4b55;cursor:pointer}.zr-seg button.on{background:#e11b1b;color:#fff}",
      ".zr-addr{display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid rgba(12,20,28,.07);font-size:14px;color:#0f1c26}.zr-addr:last-child{border-bottom:0}.zr-addr .ax{margin-left:auto;color:#c33;cursor:pointer;font-weight:700;border:0;background:none;font-size:18px}",
      ".zr-thread{max-height:340px;overflow:auto;display:flex;flex-direction:column;gap:9px;margin-bottom:14px;padding-right:4px}",
      ".zr-msg{max-width:80%;padding:10px 14px;border-radius:13px;font-size:14px;line-height:1.45}.zr-msg .mt{font-size:11px;opacity:.6;margin-top:4px}",
      ".zr-msg.me{align-self:flex-end;background:#e11b1b;color:#fff;border-bottom-right-radius:4px}.zr-msg.mgr{align-self:flex-start;background:#f2f5f8;color:#0f1c26;border-bottom-left-radius:4px}",
      ".zr-toggle{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:13px 0;border-bottom:1px solid rgba(12,20,28,.07)}.zr-toggle:last-child{border-bottom:0}.zr-toggle .tt{font-weight:600;color:#0f1c26;font-size:14.5px}.zr-toggle .td{color:#8a97a1;font-size:12.5px}",
      ".zr-sw{width:46px;height:26px;border-radius:999px;background:#cfd8df;position:relative;cursor:pointer;transition:.18s;flex:none;border:0}.zr-sw::after{content:'';position:absolute;top:3px;left:3px;width:20px;height:20px;border-radius:50%;background:#fff;transition:.18s}.zr-sw.on{background:#12915f}.zr-sw.on::after{left:23px}",
      ".zr-btn{display:inline-flex;align-items:center;justify-content:center;padding:11px 20px;border-radius:11px;background:#e11b1b;color:#fff;font-weight:700;font-size:14px;text-decoration:none;border:none;cursor:pointer;transition:.15s;font-family:'Archivo',sans-serif}.zr-btn:hover{background:#cf1616}.zr-btn.wide{width:100%}.zr-btn.gh{background:#fff;color:#0f1c26;border:1px solid rgba(12,20,28,.14)}.zr-btn.gh:hover{border-color:#e11b1b;color:#e11b1b}.zr-btn.sm{padding:8px 14px;font-size:13px}",
      ".zr-empty{text-align:center;padding:34px 24px}.zr-empty .ic{font-size:38px}.zr-empty h3{font:700 19px 'Space Grotesk',sans-serif;margin:10px 0 7px;color:#0f1c26}.zr-empty p{color:#5a6a75;margin:0 auto 18px;max-width:340px;font-size:14px}",
      ".zr-mgr{display:flex;align-items:center;gap:12px;margin-bottom:12px}.zr-mgr .ma{width:44px;height:44px;border-radius:50%;background:#0f1c26;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700}.zr-mgr .mn{font-weight:700;color:#0f1c26}.zr-mgr .mr{color:#8a97a1;font-size:12.5px}",
      ".zr-row{display:flex;align-items:center;gap:8px;font-size:14px;color:#0f1c26;font-weight:600;padding:5px 0}.zr-row a{color:#0f1c26;text-decoration:none}.zr-row a:hover{color:#e11b1b}",
      "#zr-cab-toast{position:fixed;left:50%;bottom:26px;transform:translateX(-50%) translateY(20px);z-index:100002;background:#12915f;color:#fff;font-weight:600;font-size:14px;padding:12px 22px;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.25);opacity:0;pointer-events:none;transition:.3s}#zr-cab-toast.on{opacity:1;transform:translateX(-50%) translateY(0)}#zr-cab-toast.warn{background:#c0392b}",
      "@media(max-width:920px){.zr-cab-grid{grid-template-columns:1fr}.zr-nav{position:static;display:flex;overflow:auto;padding:6px}.zr-nav button{white-space:nowrap}.zr-nav button .cnt{margin-left:6px}.zr-kpis{grid-template-columns:1fr 1fr}.zr-qa{grid-template-columns:1fr 1fr}.zr-fav{grid-template-columns:1fr 1fr}}",
      "@media(max-width:560px){.zr-kpis{grid-template-columns:1fr 1fr}.zr-qa{grid-template-columns:1fr}.zr-fav{grid-template-columns:1fr}.zr-frow{grid-template-columns:1fr}}"
    ].join("");
    d.head.appendChild(s);
  }

  var host, section = "overview", editEntity = null;

  /* ---------- shared bits ---------- */
  function kpis() {
    var os = orders(), count = os.length;
    var active = os.filter(function (o) { return stageOf(o) < 3; }).length;
    var positions = os.reduce(function (a, o) { return a + (o.items || []).reduce(function (x, i) { return x + (i.qty || 1); }, 0); }, 0);
    return '<div class="zr-kpis">' +
      kpi(count, count ? plural(count, "заказ", "заказа", "заказов") : "заказов") +
      kpi(count ? active : "—", "в работе") +
      kpi(favorites().length || "—", "в избранном") +
      '<div class="zr-kpi"><div class="n acc">до 10%</div><div class="l">опт-скидка</div></div></div>';
  }
  function kpi(n, l) { return '<div class="zr-kpi"><div class="n">' + n + '</div><div class="l">' + l + '</div></div>'; }
  function loyaltyCard() {
    var c = orders().length, need = Math.max(0, 10 - c), pct = Math.min(100, Math.round(c / 10 * 100));
    return '<div class="zr-card"><div class="zr-loyal"><div><div class="lt">Программа лояльности</div><div class="ls">' +
      (c === 0 ? "Оформите первый заказ — начнём копить опт-скидку до 10%"
        : need > 0 ? ("До скидки 10% — ещё " + need + " " + plural(need, "заказ", "заказа", "заказов"))
        : "Максимальная скидка 10% активна") + '</div></div>' +
      '<div class="bar"><span style="width:' + Math.max(pct, 4) + '%"></span></div></div></div>';
  }
  function stepperHTML(o) {
    var idx = stageOf(o);
    return '<div class="zr-step">' + STAGES.map(function (s, i) {
      return '<div class="zr-step-i ' + (i <= idx ? "done" : "") + (i === idx ? " cur" : "") + '"><span class="dot"></span><span class="lbl">' + s + '</span></div>';
    }).join("") + "</div>";
  }

  /* ---------- sections ---------- */
  function secOverview() {
    var os = orders(), name = displayName().split(" ")[0] || "клиент";
    var recent = os[0];
    var recentHTML = recent
      ? '<div class="zr-card"><div class="zr-h"><h2>Последний заказ</h2><button class="zr-btn gh sm" data-go="orders">Все заказы</button></div>' +
        '<div class="zr-ord-top"><div><span class="zr-ord-no">' + esc(recent.no) + '</span><span class="zr-ord-date">' + dmy(recent.date) + '</span></div><span class="zr-st ' + (stageOf(recent) >= 3 ? "done" : "") + '">' + STAGES[stageOf(recent)] + '</span></div>' +
        stepperHTML(recent) + '</div>'
      : "";
    return '<div class="zr-card"><h2>Здравствуйте, ' + esc(name) + '! 👋</h2><p class="cap">Заказы, подборы, документы и персональный менеджер — всё здесь.</p>' + kpis().replace('<div class="zr-kpis">', '<div class="zr-kpis" style="margin:0">') + '</div>' +
      '<div class="zr-card"><h2>Быстрые действия</h2><p class="cap">Самое нужное в один клик</p>' +
      '<div class="zr-qa">' +
        qa("/podbor", "🎯", "Подобрать редуктор", "калькулятор аналогов") +
        qa("/podbor", "📷", "По шильдику", "фото → аналог") +
        qa("/catalog/", "📚", "Каталог", "1546 позиций") +
        qa("/cart", "🛒", "Корзина", "оформить заказ") +
      '</div></div>' +
      recentHTML + loyaltyCard();
  }
  function qa(href, ic, t, dsub) { return '<a href="' + href + '"><span class="qi">' + ic + '</span><span class="qt">' + t + '</span><span class="qd">' + dsub + '</span></a>'; }

  var ordFilter = "all";
  function secOrders() {
    var os = orders();
    if (!os.length) return card(empty("📦", "Заказов пока нет", "Оформите первый заказ — он появится здесь с трекингом статуса.", "/catalog/", "В каталог"));
    var filtered = os.map(function (o, i) { return { o: o, i: i }; }).filter(function (r) { var st = stageOf(r.o); return ordFilter === "all" || (ordFilter === "active" && st < 3) || (ordFilter === "done" && st >= 3); });
    var chips = '<div class="zr-chips">' +
      chip("all", "Все", os.length) + chip("active", "В работе", os.filter(function (o) { return stageOf(o) < 3; }).length) + chip("done", "Завершённые", os.filter(function (o) { return stageOf(o) >= 3; }).length) + '</div>';
    var list = filtered.length ? filtered.map(function (r) {
      var o = r.o, st = stageOf(o), done = st >= 3;
      var its = (o.items || []).map(function (i) { return '<div class="zr-ord-it"><span><b>' + esc(i.name) + '</b> × ' + (i.qty || 1) + '</span><span>' + rub((i.price || 0) * (i.qty || 1)) + '</span></div>'; }).join("");
      return '<div class="zr-ord"><div class="zr-ord-top"><div><span class="zr-ord-no">' + esc(o.no) + '</span><span class="zr-ord-date">' + dmy(o.date) + '</span></div><span class="zr-st ' + (done ? "done" : "") + '">' + STAGES[st] + '</span></div>' +
        stepperHTML(o) + '<div class="zr-ord-items">' + its + '</div>' +
        '<div class="zr-ord-foot"><div class="zr-ord-total"><span>Итого: </span><b>' + rub(o.total || 0) + '</b></div>' +
        '<button class="zr-btn gh sm" data-repeat="' + r.i + '" type="button">Повторить заказ</button></div></div>';
    }).join("") : '<div class="zr-card"><p class="cap" style="margin:0;text-align:center">Нет заказов в этой категории.</p></div>';
    return chips + list;
  }
  function chip(id, t, n) { return '<button data-ordf="' + id + '" class="' + (ordFilter === id ? "on" : "") + '">' + t + ' ' + n + '</button>'; }

  function secFavorites() {
    var f = favorites();
    if (!f.length) return card(empty("❤", "Избранное пусто", "Добавляйте редукторы из каталога — чтобы быстро вернуться и заказать.", "/catalog/", "В каталог"));
    return '<div class="zr-fav">' + f.map(function (x, i) {
      return '<div class="zr-fav-c"><div class="fn">' + esc(x.name) + '</div>' + (x.spec ? '<div class="fd">' + esc(x.spec) + '</div>' : "") +
        '<div class="fp">' + (x.price ? rub(x.price) : "по запросу") + '</div>' +
        '<div class="fb"><button class="zr-btn sm" data-favcart="' + i + '">В корзину</button><button class="zr-btn gh sm" data-favdel="' + i + '">✕</button></div></div>';
    }).join("") + '</div>';
  }

  function secRequests() {
    var r = requests();
    if (!r.length) return card(empty("🔍", "Подборов пока нет", "Подберите аналог или пришлите фото шильдика — запросы сохранятся здесь.", "/podbor", "Подобрать"));
    return r.map(function (x) {
      return '<div class="zr-req"><span class="ri">🔍</span><div><div class="rt">' + esc(x.title || "Запрос подбора") + '</div><div class="rd">' + esc(x.params || "") + (x.date ? " · " + dmy(x.date) : "") + '</div></div><span class="rs">' + esc(x.status || "На рассмотрении") + '</span></div>';
    }).join("");
  }

  function secDocs() {
    var docs = [["Счёт на оплату", "формируется после подтверждения", "📄"], ["Договор поставки", "типовой, с НДС", "📝"], ["Паспорт изделия", "к каждой позиции", "📘"], ["Сертификат ГОСТ 31592-2012", "соответствие", "🏅"]];
    return '<div class="zr-card"><h2>Документы</h2><p class="cap">Запросите у менеджера — пришлём на почту в течение рабочего дня.</p>' +
      docs.map(function (x) { return '<div class="zr-doc"><span class="di">' + x[2] + '</span><div><div class="dn">' + x[0] + '</div><div class="dd">' + x[1] + '</div></div><a href="#" data-go="support">Запросить</a></div>'; }).join("") + '</div>';
  }

  function secProfile() {
    var p = profile() || {}, ent = editEntity || p.entity || "org", org = ent !== "ind";
    var fld = function (name, label, val, tag) { return '<div class="zr-field"><label>' + label + '</label><input name="' + name + '" value="' + esc(val || "") + '"></div>'; };
    var addrs = addresses();
    var addrList = addrs.length ? addrs.map(function (a, i) { return '<div class="zr-addr"><span>📍 ' + esc(a) + '</span><button class="ax" data-adrdel="' + i + '" title="Удалить">×</button></div>'; }).join("") : '<p class="cap" style="margin:0">Адресов пока нет.</p>';
    return '<form class="zr-card" id="zr-prof-form"><h2>Реквизиты</h2><p class="cap">Используются при оформлении заказов и в документах.</p>' +
      '<div class="zr-seg"><button type="button" data-pent="org" class="' + (org ? "on" : "") + '">Юрлицо</button><button type="button" data-pent="ind" class="' + (org ? "" : "on") + '">Физлицо</button></div>' +
      (org ? '<div class="zr-frow">' + fld("org", "Организация", p.org) + fld("inn", "ИНН", p.inn) + '</div>' + fld("person", "Контактное лицо", p.person) : fld("person", "ФИО", p.person || p.org)) +
      '<div class="zr-frow">' + fld("phone", "Телефон", p.phone) + fld("email", "Email", p.email) + '</div>' +
      '<button class="zr-btn" id="zr-prof-save" type="button" style="margin-top:4px">Сохранить реквизиты</button></form>' +
      '<div class="zr-card"><h2>Адреса доставки</h2><p class="cap">Можно добавить несколько — выберёте нужный при заказе.</p>' +
      '<div id="zr-addr-list">' + addrList + '</div>' +
      '<div class="zr-field" style="margin-top:14px"><label>Новый адрес</label><input id="zr-addr-new" placeholder="город, улица, дом, индекс"></div>' +
      '<button class="zr-btn gh" id="zr-addr-add" type="button">Добавить адрес</button></div>';
  }

  function secSupport() {
    var th = support();
    var thread = th.length ? th.map(function (m) { return '<div class="zr-msg ' + (m.from === "me" ? "me" : "mgr") + '">' + esc(m.text) + '<div class="mt">' + dmy(m.date) + '</div></div>'; }).join("")
      : '<div class="zr-msg mgr">Здравствуйте! Напишите вопрос — менеджер ответит в течение рабочего дня.<div class="mt">Поддержка ЗР</div></div>';
    return '<div class="zr-card"><h2>Поддержка</h2><p class="cap">Сообщение уйдёт вашему менеджеру.</p>' +
      '<div class="zr-thread" id="zr-thread">' + thread + '</div>' +
      '<div class="zr-field"><textarea id="zr-sup-text" placeholder="Ваш вопрос или запрос документа…"></textarea></div>' +
      '<button class="zr-btn" id="zr-sup-send" type="button">Отправить менеджеру</button></div>' +
      asideManager();
  }

  function secSettings() {
    var s = session() || {}, nt = notify();
    var sw = function (key, tt, td) { return '<div class="zr-toggle"><div><div class="tt">' + tt + '</div><div class="td">' + td + '</div></div><button class="zr-sw ' + (nt[key] ? "on" : "") + '" data-sw="' + key + '"></button></div>'; };
    return '<div class="zr-card"><h2>Аккаунт</h2><div class="zr-row">✉ ' + esc(s.email || "—") + '</div><div class="zr-row" style="color:#8a97a1;font-weight:500">Режим: ' + (s.mode === "account" ? "аккаунт на сервере (вход с любого устройства)" : "локальный (только это устройство)") + '</div>' +
      '<button class="zr-logout" id="zr-logout2" style="margin-top:10px">Выйти из аккаунта</button></div>' +
      (s.mode === "account" ? '<form class="zr-card" id="zr-pwd-form"><h2>Смена пароля</h2>' +
        '<div class="zr-field"><label>Текущий пароль</label><input type="password" name="old" autocomplete="current-password"></div>' +
        '<div class="zr-field"><label>Новый пароль (от 6 символов)</label><input type="password" name="new" autocomplete="new-password"></div>' +
        '<button class="zr-btn" id="zr-pwd-save" type="button">Сменить пароль</button></form>' : "") +
      '<div class="zr-card"><h2>Уведомления</h2>' +
        sw("status", "Статусы заказов", "уведомлять об изменении статуса") +
        sw("email", "Email-рассылка", "акции и новинки продукции") +
        sw("sms", "SMS", "важные уведомления по SMS") + '</div>';
  }

  function asideManager() {
    return '<div class="zr-card"><div class="zr-mgr"><div class="ma">СК</div><div><div class="mn">Сергей К.</div><div class="mr">ваш персональный менеджер</div></div></div>' +
      '<div class="zr-row">📞 <a href="tel:+74951514102">+7 (495) 151-41-02</a></div>' +
      '<div class="zr-row">✉ <a href="mailto:zr@zavod-red.ru">zr@zavod-red.ru</a></div></div>';
  }

  function card(inner) { return '<div class="zr-card">' + inner + '</div>'; }
  function empty(ic, h, p, href, btn) { return '<div class="zr-empty"><div class="ic">' + ic + '</div><h3>' + h + '</h3><p>' + p + '</p><a class="zr-btn" href="' + href + '">' + btn + '</a></div>'; }

  /* ---------- identity ---------- */
  function displayName() { var p = profile() || {}, s = session() || {}; return p.person || p.org || s.name || "Личный кабинет"; }

  /* ---------- render ---------- */
  var RENDERERS = { overview: secOverview, orders: secOrders, favorites: secFavorites, requests: secRequests, docs: secDocs, profile: secProfile, support: secSupport, settings: secSettings };
  function render() {
    var p = profile() || {}, s = session() || {}, name = displayName();
    var init = (name.replace(/[«»"]/g, "").match(/[A-Za-zА-Яа-я]/g) || ["З", "Р"]).slice(0, 2).join("").toUpperCase();
    var sub = (p.entity === "ind" ? "Физлицо" : (p.org ? "Юрлицо" : "")) + (p.inn ? " · ИНН " + p.inn : "") + (s.email ? " · " + s.email : "");
    var nav = '<nav class="zr-nav">' + NAV.map(function (it) {
      var n = it.n ? it.n() : 0;
      return '<button data-sec="' + it.id + '" class="' + (section === it.id ? "on" : "") + '"><span class="ic">' + it.ic + '</span>' + it.t + (it.n && n ? '<span class="cnt">' + n + '</span>' : "") + '</button>';
    }).join("") + '</nav>';
    var content = (RENDERERS[section] || secOverview)();
    host.innerHTML = '<div class="zr-cab-in">' +
      '<div class="zr-cab-top"><div class="zr-cab-ava">' + esc(init) + '</div><div><h1>' + esc(name) + '</h1><div class="sub">' + esc(sub || "Оптовый клиент Завода Редукторов") + '</div><span class="badge">Оптовые условия · прямые цены завода</span></div>' +
      '<div class="spacer"></div><button class="zr-logout" id="zr-logout">Выйти</button></div>' +
      '<div class="zr-cab-grid">' + nav + '<div class="zr-main">' + content + '</div></div></div>';
  }

  function go(sec) { section = sec; editEntity = null; render(); try { host.scrollIntoView({ behavior: "smooth", block: "start" }); } catch (e) {} }

  /* ---------- actions ---------- */
  function doLogout() { try { window.zrAuth && window.zrAuth.logout(); } catch (e) {} try { localStorage.removeItem("zr_session"); } catch (e) {} location.reload(); }

  d.addEventListener("click", function (e) {
    if (!host) return;
    var t;
    if ((t = e.target.closest("[data-sec]"))) return go(t.getAttribute("data-sec"));
    if ((t = e.target.closest("[data-go]"))) { e.preventDefault(); return go(t.getAttribute("data-go")); }
    if ((t = e.target.closest("[data-ordf]"))) { ordFilter = t.getAttribute("data-ordf"); return render(); }
    if ((t = e.target.closest("[data-pent]"))) { editEntity = t.getAttribute("data-pent"); return render(); }
    if (e.target.closest("#zr-logout") || e.target.closest("#zr-logout2")) return doLogout();

    if (e.target.closest("#zr-prof-save")) {
      var f = d.getElementById("zr-prof-form"); if (!f) return;
      var v = function (n) { var el = f.querySelector('[name="' + n + '"]'); return el ? el.value.trim() : ""; };
      var ent = editEntity || (profile() || {}).entity || "org";
      var prev = profile() || {};
      jset("zr_profile", { entity: ent, org: v("org"), inn: v("inn"), person: v("person"), phone: v("phone"), email: v("email"), addr: prev.addr || "", name: v("person"), company: v("org") });
      editEntity = null; render(); toast("Реквизиты сохранены"); return;
    }
    if (e.target.closest("#zr-addr-add")) {
      var inp = d.getElementById("zr-addr-new"); var val = inp ? inp.value.trim() : "";
      if (val.length < 5) return toast("Укажите адрес подробнее", true);
      var a = addresses(); a.push(val); jset("zr_addresses", a); render(); toast("Адрес добавлен"); return;
    }
    if ((t = e.target.closest("[data-adrdel]"))) { var a2 = addresses(); a2.splice(parseInt(t.getAttribute("data-adrdel"), 10), 1); jset("zr_addresses", a2); render(); return; }

    if ((t = e.target.closest("[data-favcart]"))) {
      var fx = favorites()[parseInt(t.getAttribute("data-favcart"), 10)]; if (!fx) return;
      var c = getCart(); var ex = c.items.filter(function (x) { return x.name === fx.name; })[0];
      if (ex) ex.qty = (ex.qty || 1) + 1; else c.items.push({ name: fx.name, price: fx.price || 0, qty: 1, img: fx.img || "" });
      saveCart(c); toast("Добавлено в корзину"); return;
    }
    if ((t = e.target.closest("[data-favdel]"))) { var ff = favorites(); ff.splice(parseInt(t.getAttribute("data-favdel"), 10), 1); jset("zr_favorites", ff); render(); return; }

    if ((t = e.target.closest("[data-repeat]"))) {
      var o = orders()[parseInt(t.getAttribute("data-repeat"), 10)];
      if (o && o.items) { var cc = getCart(); o.items.forEach(function (it) { var exx = cc.items.filter(function (x) { return x.name === it.name; })[0]; if (exx) exx.qty = (exx.qty || 1) + (it.qty || 1); else cc.items.push({ name: it.name, price: it.price, qty: it.qty || 1, img: "" }); }); saveCart(cc); location.href = "/cart"; }
      return;
    }
    if ((t = e.target.closest("[data-sw]"))) { var nt = notify(), key = t.getAttribute("data-sw"); nt[key] = !nt[key]; jset("zr_notify", nt); t.classList.toggle("on", nt[key]); return; }

    if (e.target.closest("#zr-sup-send")) return sendSupport();
    if (e.target.closest("#zr-pwd-save")) return changePwd();
  });

  function sendSupport() {
    var ta = d.getElementById("zr-sup-text"); var text = ta ? ta.value.trim() : "";
    if (text.length < 3) return toast("Напишите сообщение", true);
    var th = support(); th.push({ from: "me", text: text, date: new Date().toISOString() }); jset("zr_support", th);
    var p = profile() || {}, s = session() || {};
    try {
      var fd = new FormData();
      fd.append("work_email", ""); fd.append("text-562", p.person || s.name || "Клиент кабинета");
      fd.append("tel-535", p.phone || ""); fd.append("email", s.email || p.email || ""); fd.append("company", p.org || "");
      fd.append("product_title", "Кабинет · обращение: " + text.slice(0, 400));
      fetch(FEEDBACK, { method: "POST", body: fd });
    } catch (er) {}
    render(); toast("Сообщение отправлено");
    setTimeout(function () { var el = d.getElementById("zr-thread"); if (el) el.scrollTop = el.scrollHeight; }, 60);
  }

  async function changePwd() {
    var f = d.getElementById("zr-pwd-form"); if (!f) return;
    var oldp = f.querySelector('[name="old"]').value, newp = f.querySelector('[name="new"]').value;
    if (newp.length < 6) return toast("Новый пароль от 6 символов", true);
    var btn = d.getElementById("zr-pwd-save"); btn.disabled = true;
    try {
      var r = await fetch(AUTH_API + "change-password.php", { method: "POST", credentials: "include", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ old: oldp, new: newp }) });
      var res = await r.json().catch(function () { return {}; });
      if (r.ok && res.status === "success") { f.reset(); toast("Пароль изменён"); }
      else toast(res.message || "Не удалось сменить пароль", true);
    } catch (e) { toast("Сеть недоступна", true); }
    btn.disabled = false;
  }

  /* ---------- public API for other pages ---------- */
  window.zrCab = {
    addFavorite: function (obj) { if (!obj || !obj.name) return; var f = favorites(); if (f.some(function (x) { return x.name === obj.name; })) return; f.unshift({ name: obj.name, price: obj.price || 0, spec: obj.spec || "", img: obj.img || "", url: obj.url || "" }); jset("zr_favorites", f); },
    addRequest: function (obj) { if (!obj) return; var r = requests(); r.unshift({ title: obj.title || "Запрос подбора", params: obj.params || "", status: obj.status || "На рассмотрении", date: new Date().toISOString() }); jset("zr_requests", r); }
  };

  /* ---------- mount ---------- */
  function hideMock() {
    var cand = [].slice.call(d.querySelectorAll("div,section")).filter(function (e) {
      var t = e.textContent || ""; return /менеджер:/.test(t) && /(НИИ АТТ|клиент с)/.test(t) && t.length < 400 && !e.querySelector(".zr-cab");
    });
    cand.sort(function (a, b) { return b.textContent.length - a.textContent.length; });
    if (cand[0]) cand[0].style.display = "none";
  }
  function build() {
    var mockup = d.querySelector(".m1"); if (!mockup) return false;
    mockup.style.display = "none"; hideMock(); css();
    if (!host) { host = d.createElement("section"); host.className = "zr-cab"; mockup.parentNode.insertBefore(host, mockup); }
    render(); return true;
  }
  var n = 0; (function loop() { if (build()) return; if (n++ < 60) setTimeout(loop, 80); })();
})();
