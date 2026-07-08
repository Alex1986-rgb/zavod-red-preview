/* Личный кабинет (модернизированный): история с трекингом статуса, редактируемый
   профиль, персональный менеджер, статистика и лояльность. Данные из localStorage. */
(function () {
  "use strict";
  var d = document;
  function orders() { try { return JSON.parse(localStorage.getItem("zr_orders") || "[]"); } catch (e) { return []; } }
  function profile() { try { return JSON.parse(localStorage.getItem("zr_profile") || "null"); } catch (e) { return null; } }
  function getCart() { try { return JSON.parse(localStorage.getItem("zr_cart") || '{"items":[]}'); } catch (e) { return { items: [] }; } }
  function saveCart(c) { try { localStorage.setItem("zr_cart", JSON.stringify(c)); } catch (e) {} if (window.zrRefreshBadge) try { window.zrRefreshBadge(); } catch (_) {} }
  function rub(n) { return Math.round(n).toLocaleString("ru-RU") + " ₽"; }
  function esc(s) { return String(s == null ? "" : s).replace(/[<>&"]/g, function (c) { return { "<": "&lt;", ">": "&gt;", "&": "&amp;", '"': "&quot;" }[c]; }); }
  function dmy(iso) { try { var x = new Date(iso), p = function (n) { return ("0" + n).slice(-2); }; return p(x.getDate()) + "." + p(x.getMonth() + 1) + "." + x.getFullYear(); } catch (e) { return ""; } }
  function toast(m) { var t = d.getElementById("zr-cab-toast"); if (!t) { t = d.createElement("div"); t.id = "zr-cab-toast"; d.body.appendChild(t); } t.textContent = m; t.className = "on"; clearTimeout(t._h); t._h = setTimeout(function () { t.className = ""; }, 2400); }

  var STAGES = ["Принят", "Подтверждён", "В производстве", "Отгружен"];
  function stageOf(o) {
    var ms = Date.now() - new Date(o.date).getTime(), min = ms / 60000;
    return min < 10 ? 0 : min < 1440 ? 1 : min < 4320 ? 2 : 3;
  }

  function css() {
    if (d.getElementById("zr-cab-css")) return;
    var s = d.createElement("style"); s.id = "zr-cab-css";
    s.textContent =
      ".zr-cab{background:#eef2f6}.zr-cab-in{max-width:1180px;margin:0 auto;padding:12px 20px 40px}" +
      ".zr-cab-head{display:flex;align-items:center;gap:18px;margin-bottom:20px;flex-wrap:wrap}" +
      ".zr-cab-ava{width:64px;height:64px;border-radius:16px;background:#e11b1b;color:#fff;display:flex;align-items:center;justify-content:center;font:800 24px 'Space Grotesk',sans-serif;flex:none}" +
      ".zr-cab-head h1{font:700 clamp(23px,3.2vw,32px)/1.1 'Space Grotesk',sans-serif;color:#0f1c26;margin:0 0 4px}" +
      ".zr-cab-head .sub{color:#5a6a75;font-size:14px}" +
      ".zr-cab-badge{display:inline-block;margin-top:8px;padding:6px 14px;border-radius:999px;background:rgba(18,145,95,.1);color:#12915f;font-weight:700;font-size:13px}" +
      ".zr-cab-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:16px}" +
      ".zr-cab-stat{background:#fff;border:1px solid rgba(12,20,28,.1);border-radius:16px;padding:18px 20px}" +
      ".zr-cab-stat .n{font:800 26px 'Space Grotesk',sans-serif;color:#0f1c26;letter-spacing:-.02em}" +
      ".zr-cab-stat .n.acc{color:#e11b1b}.zr-cab-stat .n.good{color:#12915f}" +
      ".zr-cab-stat .l{color:#8a97a1;font-size:11.5px;letter-spacing:.04em;text-transform:uppercase;font-weight:700;margin-top:4px}" +
      ".zr-loyal{background:#fff;border:1px solid rgba(12,20,28,.1);border-radius:16px;padding:16px 20px;margin-bottom:18px;display:flex;align-items:center;gap:18px;flex-wrap:wrap}" +
      ".zr-loyal .lt{font-weight:700;color:#0f1c26;font-size:14.5px}.zr-loyal .ls{color:#5a6a75;font-size:13px}" +
      ".zr-loyal .bar{flex:1;min-width:160px;height:8px;background:#eef2f6;border-radius:5px;overflow:hidden}" +
      ".zr-loyal .bar span{display:block;height:100%;background:linear-gradient(90deg,#ff4b3c,#e11b1b);border-radius:5px}" +
      ".zr-cab-body{display:grid;grid-template-columns:1fr 300px;gap:18px;align-items:start}" +
      ".zr-cab-tabs{display:inline-flex;background:#fff;border:1px solid rgba(12,20,28,.1);border-radius:14px;padding:5px;gap:4px;margin-bottom:16px;flex-wrap:wrap}" +
      ".zr-cab-tabs button{border:none;background:none;padding:11px 18px;border-radius:10px;font:600 14px 'Archivo',sans-serif;color:#3f4b55;cursor:pointer;transition:.15s}" +
      ".zr-cab-tabs button.on{background:#e11b1b;color:#fff}.zr-cab-tabs button .c{opacity:.7;font-weight:700}" +
      ".zr-ord{background:#fff;border:1px solid rgba(12,20,28,.1);border-radius:16px;padding:18px 20px;margin-bottom:14px}" +
      ".zr-ord-top{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap}" +
      ".zr-ord-no{font:700 15.5px 'IBM Plex Mono',monospace;color:#0f1c26}.zr-ord-date{color:#8a97a1;font-size:13px;margin-left:10px}" +
      ".zr-ord-st{padding:5px 12px;border-radius:999px;background:rgba(225,27,27,.1);color:#e11b1b;font-weight:700;font-size:12.5px}" +
      ".zr-ord-st.done{background:rgba(18,145,95,.12);color:#12915f}" +
      ".zr-step{display:flex;margin:16px 0 6px}" +
      ".zr-step-i{flex:1;position:relative;text-align:center;font-size:11px;color:#9aa6af;font-weight:600}" +
      ".zr-step-i .dot{display:block;width:13px;height:13px;border-radius:50%;background:#dfe5ea;margin:0 auto 7px;position:relative;z-index:1}" +
      ".zr-step-i::before{content:'';position:absolute;top:6px;left:-50%;width:100%;height:2px;background:#dfe5ea}" +
      ".zr-step-i:first-child::before{display:none}" +
      ".zr-step-i.done .dot{background:#e11b1b}.zr-step-i.done::before{background:#e11b1b}" +
      ".zr-step-i.cur .lbl{color:#0f1c26}.zr-step-i.cur .dot{box-shadow:0 0 0 4px rgba(225,27,27,.16)}" +
      ".zr-ord-items{border-top:1px solid rgba(12,20,28,.07);margin-top:14px;padding-top:12px}" +
      ".zr-ord-it{display:flex;justify-content:space-between;gap:10px;padding:5px 0;font-size:14px;color:#3f4b55}.zr-ord-it b{color:#0f1c26;font-weight:600}" +
      ".zr-ord-foot{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;border-top:1px solid rgba(12,20,28,.07);margin-top:10px;padding-top:12px}" +
      ".zr-ord-total b{font:700 20px 'Space Grotesk',sans-serif;color:#0f1c26}.zr-ord-total span{color:#8a97a1;font-size:13px}" +
      ".zr-repeat{border:1px solid rgba(12,20,28,.14);background:#fff;color:#0f1c26;font:700 13.5px 'Archivo',sans-serif;padding:10px 18px;border-radius:10px;cursor:pointer;transition:.15s}.zr-repeat:hover{border-color:#e11b1b;color:#e11b1b}" +
      ".zr-cab-panel{background:#fff;border:1px solid rgba(12,20,28,.1);border-radius:16px;padding:24px 26px}" +
      ".zr-field{margin-bottom:14px}.zr-field label{display:block;font-size:13px;font-weight:600;color:#5a6a75;margin-bottom:6px}" +
      ".zr-field input{width:100%;background:#f5f7f9;border:1px solid rgba(12,20,28,.1);border-radius:11px;padding:12px 14px;font:500 15px 'Archivo',sans-serif;color:#0f1c26;transition:.15s}" +
      ".zr-field input:focus{outline:none;border-color:#e11b1b;background:#fff;box-shadow:0 0 0 3px rgba(225,27,27,.12)}" +
      ".zr-frow{display:grid;grid-template-columns:1fr 1fr;gap:0 16px}" +
      ".zr-seg{display:inline-flex;background:#eef2f6;border-radius:11px;padding:4px;gap:4px;margin-bottom:16px}.zr-seg button{border:none;background:none;padding:9px 16px;border-radius:8px;font:600 14px 'Archivo',sans-serif;color:#3f4b55;cursor:pointer}.zr-seg button.on{background:#e11b1b;color:#fff}" +
      ".zr-doc{display:flex;align-items:center;gap:14px;padding:14px 0;border-bottom:1px solid rgba(12,20,28,.07)}.zr-doc:last-child{border-bottom:0}" +
      ".zr-doc .di{width:40px;height:40px;border-radius:10px;background:rgba(225,27,27,.1);color:#e11b1b;display:flex;align-items:center;justify-content:center;font-size:18px}" +
      ".zr-doc .dn{font-weight:600;color:#0f1c26}.zr-doc .dd{color:#8a97a1;font-size:13px}.zr-doc a{margin-left:auto;color:#e11b1b;font-weight:700;font-size:14px;text-decoration:none}" +
      ".zr-aside-card{background:#fff;border:1px solid rgba(12,20,28,.1);border-radius:16px;padding:20px;margin-bottom:16px}" +
      ".zr-mgr{display:flex;align-items:center;gap:12px;margin-bottom:14px}.zr-mgr .ma{width:46px;height:46px;border-radius:50%;background:#0f1c26;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700}" +
      ".zr-mgr .mn{font-weight:700;color:#0f1c26}.zr-mgr .mr{color:#8a97a1;font-size:12.5px}" +
      ".zr-aside-card h3{font:700 15px 'Space Grotesk',sans-serif;color:#0f1c26;margin:0 0 6px}.zr-aside-card p{color:#5a6a75;font-size:13.5px;line-height:1.5;margin:0 0 14px}" +
      ".zr-aside-card .row{display:flex;align-items:center;gap:8px;font-size:14px;color:#0f1c26;font-weight:600;padding:6px 0}.zr-aside-card .row a{color:#0f1c26;text-decoration:none}.zr-aside-card .row a:hover{color:#e11b1b}" +
      ".zr-cab-btn{display:inline-flex;align-items:center;justify-content:center;padding:12px 22px;border-radius:12px;background:#e11b1b;color:#fff;font-weight:700;font-size:14.5px;text-decoration:none;border:none;cursor:pointer;transition:.15s}.zr-cab-btn:hover{background:#cf1616}.zr-cab-btn.wide{width:100%}.zr-cab-btn.gh{background:#fff;color:#0f1c26;border:1px solid rgba(12,20,28,.14)}.zr-cab-btn.gh:hover{border-color:#e11b1b;color:#e11b1b}" +
      ".zr-cab-empty{text-align:center;padding:36px 24px}.zr-cab-empty .ic{font-size:40px}.zr-cab-empty h3{font:700 20px 'Space Grotesk',sans-serif;margin:12px 0 8px;color:#0f1c26}.zr-cab-empty p{color:#5a6a75;margin:0 auto 20px;max-width:340px}" +
      "#zr-cab-toast{position:fixed;left:50%;bottom:26px;transform:translateX(-50%) translateY(20px);z-index:100002;background:#12915f;color:#fff;font-weight:600;font-size:14px;padding:12px 22px;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.25);opacity:0;pointer-events:none;transition:.3s}#zr-cab-toast.on{opacity:1;transform:translateX(-50%) translateY(0)}" +
      "@media(max-width:920px){.zr-cab-body{grid-template-columns:1fr}.zr-cab-stats{grid-template-columns:1fr 1fr}}" +
      "@media(max-width:520px){.zr-cab-stats{grid-template-columns:1fr}.zr-frow{grid-template-columns:1fr}}";
    d.head.appendChild(s);
  }

  var host, tab = "orders", editEntity = null;

  function savings() { return orders().reduce(function (a, o) { var t = o.total || 0; return a + Math.round(t * 0.08 / 0.92); }, 0); }
  function statsHTML() {
    var os = orders(), sum = os.reduce(function (a, o) { return a + (o.total || 0); }, 0);
    var count = os.length, total = sum, sav = savings();
    var big = function (n) { return n >= 1e6 ? (Math.round(n / 1e5) / 10 + " млн ₽") : rub(n); };
    return '<div class="zr-cab-stats">' +
      '<div class="zr-cab-stat"><div class="n">' + (count || 14) + '</div><div class="l">заказов' + (count ? "" : " за год") + '</div></div>' +
      '<div class="zr-cab-stat"><div class="n">' + big(total || 1840000) + '</div><div class="l">сумма поставок</div></div>' +
      '<div class="zr-cab-stat"><div class="n good">' + big(sav || 147200) + '</div><div class="l">экономия с заводом</div></div>' +
      '<div class="zr-cab-stat"><div class="n acc">8%</div><div class="l">ваша скидка</div></div></div>';
  }
  function loyaltyHTML() {
    var c = orders().length, need = Math.max(0, 10 - c), pct = Math.min(100, Math.round(c / 10 * 100));
    if (c === 0) pct = 8;
    return '<div class="zr-loyal"><div><div class="lt">Программа лояльности</div><div class="ls">' +
      (need > 0 ? ("До скидки 10% — ещё " + need + " " + plural(need, "заказ", "заказа", "заказов")) : "Максимальная скидка 10% активна") + '</div></div>' +
      '<div class="bar"><span style="width:' + Math.max(pct, 6) + '%"></span></div></div>';
  }
  function plural(n, a, b, c) { n = Math.abs(n) % 100; var n1 = n % 10; if (n > 10 && n < 20) return c; if (n1 > 1 && n1 < 5) return b; if (n1 === 1) return a; return c; }

  function stepperHTML(o) {
    var idx = stageOf(o);
    return '<div class="zr-step">' + STAGES.map(function (s, i) {
      return '<div class="zr-step-i ' + (i <= idx ? "done" : "") + (i === idx ? " cur" : "") + '"><span class="dot"></span><span class="lbl">' + s + '</span></div>';
    }).join("") + "</div>";
  }
  function ordersHTML() {
    var os = orders();
    if (!os.length) return '<div class="zr-cab-panel"><div class="zr-cab-empty"><div class="ic">📦</div><h3>Заказов пока нет</h3><p>Оформите первый заказ — он появится здесь с историей и трекингом статуса.</p><a class="zr-cab-btn" href="catalog.html">Перейти в каталог</a></div></div>';
    return os.map(function (o, idx) {
      var st = stageOf(o), done = st >= 3;
      var its = (o.items || []).map(function (i) { return '<div class="zr-ord-it"><span><b>' + esc(i.name) + '</b> × ' + (i.qty || 1) + '</span><span>' + rub((i.price || 0) * (i.qty || 1)) + '</span></div>'; }).join("");
      return '<div class="zr-ord"><div class="zr-ord-top"><div><span class="zr-ord-no">' + esc(o.no) + '</span><span class="zr-ord-date">' + dmy(o.date) + '</span></div><span class="zr-ord-st ' + (done ? "done" : "") + '">' + STAGES[st] + '</span></div>' +
        stepperHTML(o) +
        '<div class="zr-ord-items">' + its + '</div>' +
        '<div class="zr-ord-foot"><div class="zr-ord-total"><span>Итого: </span><b>' + rub(o.total || 0) + '</b></div>' +
        '<button class="zr-repeat" data-repeat="' + idx + '" type="button">Повторить заказ</button></div></div>';
    }).join("");
  }
  function profileFormHTML() {
    var p = profile() || {}; var ent = editEntity || p.entity || "org"; var org = ent !== "ind";
    var fld = function (name, label, val) { return '<div class="zr-field"><label>' + label + '</label><input name="' + name + '" value="' + esc(val || "") + '"></div>'; };
    return '<form class="zr-cab-panel" id="zr-prof-form">' +
      '<div class="zr-seg"><button type="button" data-pent="org" class="' + (org ? "on" : "") + '">Юридическое лицо</button><button type="button" data-pent="ind" class="' + (org ? "" : "on") + '">Физическое лицо</button></div>' +
      (org ? '<div class="zr-frow">' + fld("org", "Организация", p.org) + fld("inn", "ИНН", p.inn) + '</div>' + fld("person", "Контактное лицо", p.person)
           : fld("person", "ФИО", p.person || p.org)) +
      '<div class="zr-frow">' + fld("phone", "Телефон", p.phone) + fld("email", "Email", p.email) + '</div>' +
      fld("addr", "Адрес доставки", p.addr) +
      '<button class="zr-cab-btn" id="zr-prof-save" type="button" style="margin-top:6px">Сохранить данные</button></form>';
  }
  function docsHTML() {
    var docs = [["Счёт на оплату", "формируется после подтверждения", "📄"], ["Договор поставки", "типовой, с НДС", "📝"], ["Паспорт изделия", "к каждой позиции", "📘"], ["Сертификат ГОСТ 31592-2012", "соответствие", "🏅"]];
    return '<div class="zr-cab-panel">' + docs.map(function (x) { return '<div class="zr-doc"><span class="di">' + x[2] + '</span><div><div class="dn">' + x[0] + '</div><div class="dd">' + x[1] + '</div></div><a href="contacts.html">Запросить</a></div>'; }).join("") + "</div>";
  }
  function asideHTML() {
    return '<aside class="zr-cab-aside">' +
      '<div class="zr-aside-card"><div class="zr-mgr"><div class="ma">СК</div><div><div class="mn">Сергей К.</div><div class="mr">ваш персональный менеджер</div></div></div>' +
      '<div class="row">📞 <a href="tel:+74951514102">+7 (495) 151-41-02</a></div>' +
      '<div class="row">✉ <a href="mailto:zr@zavod-red.ru">zr@zavod-red.ru</a></div>' +
      '<a class="zr-cab-btn wide gh" href="contacts.html" style="margin-top:12px">Написать менеджеру</a></div>' +
      '<div class="zr-aside-card"><h3>Нужен подбор?</h3><p>Пришлите фото шильдика или параметры — инженер подберёт аналог и цену за 15 минут.</p><a class="zr-cab-btn wide" href="podbor.html">Подобрать редуктор</a></div>' +
      '</aside>';
  }

  function render() {
    var p = profile(), os = orders();
    var name = (p && (p.org || p.person)) || "Личный кабинет";
    var init = (name.replace(/[«»"]/g, "").match(/[A-ZА-Я]/g) || ["З", "Р"]).slice(0, 2).join("");
    var sub = p ? [(p.entity === "ind" ? "Физлицо" : "Юрлицо"), p.inn ? "ИНН " + p.inn : "", p.phone || ""].filter(Boolean).join(" · ") : "История заказов, статусы и реквизиты в одном месте";
    var tabs = '<div class="zr-cab-tabs">' +
      '<button data-tab="orders" class="' + (tab === "orders" ? "on" : "") + '">Заказы <span class="c">' + os.length + '</span></button>' +
      '<button data-tab="profile" class="' + (tab === "profile" ? "on" : "") + '">Данные компании</button>' +
      '<button data-tab="docs" class="' + (tab === "docs" ? "on" : "") + '">Документы</button></div>';
    var content = tab === "profile" ? profileFormHTML() : tab === "docs" ? docsHTML() : ordersHTML();
    host.innerHTML = '<div class="zr-cab-in">' +
      '<div class="zr-cab-head"><div class="zr-cab-ava">' + esc(init) + '</div><div><h1>' + esc(name) + '</h1><div class="sub">' + esc(sub) + '</div><span class="zr-cab-badge">Оптовые условия · скидка 8%</span></div></div>' +
      statsHTML() + loyaltyHTML() +
      '<div class="zr-cab-body"><div class="zr-cab-main">' + tabs + content + '</div>' + asideHTML() + '</div></div>';
  }

  d.addEventListener("click", function (e) {
    if (!host) return;
    var t = e.target.closest("[data-tab]");
    if (t) { tab = t.getAttribute("data-tab"); editEntity = null; render(); return; }
    var pe = e.target.closest("[data-pent]");
    if (pe) { editEntity = pe.getAttribute("data-pent"); render(); return; }
    if (e.target.closest("#zr-prof-save")) {
      var f = d.getElementById("zr-prof-form"); if (!f) return;
      var v = function (n) { var el = f.querySelector('[name="' + n + '"]'); return el ? el.value.trim() : ""; };
      var ent = editEntity || (profile() || {}).entity || "org";
      try { localStorage.setItem("zr_profile", JSON.stringify({ entity: ent, org: v("org"), inn: v("inn"), person: v("person"), phone: v("phone"), email: v("email"), addr: v("addr") })); } catch (er) {}
      editEntity = null; render(); toast("Данные сохранены");
      return;
    }
    var r = e.target.closest("[data-repeat]");
    if (r) {
      var o = orders()[parseInt(r.getAttribute("data-repeat"), 10)];
      if (o && o.items) {
        var c = getCart();
        o.items.forEach(function (it) { var ex = c.items.filter(function (x) { return x.name === it.name; })[0]; if (ex) ex.qty = (ex.qty || 1) + (it.qty || 1); else c.items.push({ name: it.name, price: it.price, qty: it.qty || 1, img: "" }); });
        saveCart(c); location.href = "cart.html";
      }
    }
  });

  function hideMock() {
    var cand = [].slice.call(d.querySelectorAll("div,section")).filter(function (e) {
      var t = e.textContent || "";
      return /менеджер:/.test(t) && /(НИИ АТТ|клиент с)/.test(t) && t.length < 400 && !e.querySelector(".zr-cab");
    });
    cand.sort(function (a, b) { return b.textContent.length - a.textContent.length; });
    if (cand[0]) cand[0].style.display = "none";
  }
  function build() {
    var mockup = d.querySelector(".m1");
    if (!mockup) return false;
    mockup.style.display = "none";
    hideMock(); css();
    if (!host) { host = d.createElement("section"); host.className = "zr-cab"; mockup.parentNode.insertBefore(host, mockup); }
    render();
    return true;
  }
  var n = 0; (function loop() { if (build()) return; if (n++ < 60) setTimeout(loop, 80); })();
})();
