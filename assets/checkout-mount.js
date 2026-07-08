/* Реальное оформление заказа из localStorage.zr_cart. Только на checkout.html. */
(function () {
  "use strict";
  var d = document;
  var DISCOUNT = 0.08;

  function getCart() { try { return JSON.parse(localStorage.getItem("zr_cart") || '{"items":[]}'); } catch (e) { return { items: [] }; } }
  function clearCart() { try { localStorage.removeItem("zr_cart"); } catch (e) {} if (window.zrRefreshBadge) try { window.zrRefreshBadge(); } catch (_) {} }
  function rub(n) { return Math.round(n).toLocaleString("ru-RU") + " ₽"; }
  function esc(s) { return String(s == null ? "" : s).replace(/[<>&"]/g, function (c) { return { "<": "&lt;", ">": "&gt;", "&": "&amp;", '"': "&quot;" }[c]; }); }

  function css() {
    if (d.getElementById("zr-co-css")) return;
    var s = d.createElement("style"); s.id = "zr-co-css";
    s.textContent =
      ".zr-co{background:#eef2f6}" +
      ".zr-co-grid{max-width:1240px;margin:0 auto;padding:10px 20px 40px;display:grid;grid-template-columns:1fr 372px;gap:22px;align-items:start}" +
      ".zr-co-panel{background:#fff;border:1px solid rgba(12,20,28,.1);border-radius:18px;padding:26px 26px 28px;margin-bottom:18px}" +
      ".zr-co-panel h2{font:700 19px/1.2 'Space Grotesk',sans-serif;color:#0f1c26;margin:0 0 4px}" +
      ".zr-co-panel .sub{color:#5a6a75;font-size:14px;margin:0 0 18px}" +
      ".zr-seg{display:inline-flex;background:#eef2f6;border-radius:12px;padding:4px;gap:4px;margin-bottom:18px}" +
      ".zr-seg button{border:none;background:none;padding:10px 18px;border-radius:9px;font:600 14.5px 'Archivo',sans-serif;color:#3f4b55;cursor:pointer;transition:.15s}" +
      ".zr-seg button.on{background:#e11b1b;color:#fff}" +
      ".zr-frow{display:grid;grid-template-columns:1fr 1fr;gap:14px}" +
      ".zr-field{margin-bottom:14px}.zr-field.full{grid-column:1/-1}" +
      ".zr-field label{display:block;font-size:13px;font-weight:600;color:#5a6a75;margin-bottom:7px}" +
      ".zr-field input,.zr-field textarea{width:100%;background:#f5f7f9;border:1px solid rgba(12,20,28,.1);border-radius:11px;padding:13px 15px;font:500 15px/1.4 'Archivo',sans-serif;color:#0f1c26;transition:.15s;resize:vertical}" +
      ".zr-field input:focus,.zr-field textarea:focus{outline:none;border-color:#e11b1b;background:#fff;box-shadow:0 0 0 3px rgba(225,27,27,.12)}" +
      ".zr-field.err input{border-color:#c33b3b;background:#fff5f5}" +
      ".zr-co-sum{background:#fff;border:1px solid rgba(12,20,28,.1);border-radius:18px;padding:24px;position:sticky;top:90px}" +
      ".zr-co-sum h2{font:700 20px 'Space Grotesk',sans-serif;margin:0 0 14px;color:#0f1c26}" +
      ".zr-co-items{max-height:230px;overflow:auto;margin:0 -4px 8px;padding:0 4px}" +
      ".zr-co-it{display:flex;justify-content:space-between;gap:10px;padding:8px 0;border-bottom:1px solid rgba(12,20,28,.06);font-size:13.5px;color:#3f4b55}" +
      ".zr-co-it .n{color:#0f1c26;font-weight:600}.zr-co-it .n small{color:#8a97a1;font-weight:500}" +
      ".zr-co-it .s{white-space:nowrap;font-weight:700;color:#0f1c26}" +
      ".zr-co-sum .sr{display:flex;justify-content:space-between;align-items:center;padding:8px 0;font-size:14.5px;color:#3f4b55}" +
      ".zr-co-sum .sr b{color:#0f1c26}.zr-co-sum .sr.good b{color:#12915f}.zr-co-sum .sr .muted{color:#8a97a1;font-size:13px}" +
      ".zr-co-sum .sr.total{border-top:1px solid rgba(12,20,28,.1);margin-top:8px;padding-top:14px}.zr-co-sum .sr.total b{font:700 24px 'Space Grotesk',sans-serif}" +
      ".zr-co-btn{display:flex;align-items:center;justify-content:center;width:100%;margin-top:16px;padding:15px 22px;border-radius:12px;font:700 15px 'Archivo',sans-serif;background:#e11b1b;color:#fff;border:none;cursor:pointer;transition:.15s;text-decoration:none}" +
      ".zr-co-btn:hover{background:#cf1616}" +
      ".zr-co-sum .sn{color:#8a97a1;font-size:12.5px;line-height:1.5;margin:12px 0 0;text-align:center}" +
      ".zr-co-ok{max-width:620px;margin:16px auto 40px;text-align:center;background:#fff;border:1px solid rgba(12,20,28,.1);border-radius:18px;padding:46px 34px}" +
      ".zr-co-ok .ic{width:74px;height:74px;border-radius:50%;background:rgba(18,145,95,.12);color:#12915f;display:flex;align-items:center;justify-content:center;margin:0 auto 18px;font-size:38px}" +
      ".zr-co-ok h2{font:700 26px 'Space Grotesk',sans-serif;color:#0f1c26;margin:0 0 10px}" +
      ".zr-co-ok p{color:#5a6a75;font-size:15px;line-height:1.6;margin:0 auto 8px;max-width:440px}" +
      ".zr-co-ok .ord{font:700 16px 'IBM Plex Mono',monospace;color:#e11b1b;margin:14px 0 22px}" +
      ".zr-co-ok .btns{display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin-top:8px}" +
      ".zr-co-ok a{display:inline-flex;align-items:center;padding:13px 24px;border-radius:12px;font-weight:700;font-size:14.5px;text-decoration:none}" +
      ".zr-co-ok a.pri{background:#e11b1b;color:#fff}.zr-co-ok a.gh{background:#fff;color:#0f1c26;border:1px solid rgba(12,20,28,.14)}" +
      "@media(max-width:900px){.zr-co-grid{grid-template-columns:1fr}.zr-co-sum{position:static}}" +
      "@media(max-width:520px){.zr-frow{grid-template-columns:1fr}}";
    d.head.appendChild(s);
  }

  var host, entity = "org";
  function items() { return (getCart().items || []).filter(function (i) { return i && i.name; }); }

  function summaryHTML() {
    var its = items();
    var sub = its.reduce(function (a, i) { return a + (i.price || 0) * (i.qty || 1); }, 0);
    var disc = Math.round(sub * DISCOUNT), total = sub - disc;
    var qty = its.reduce(function (a, i) { return a + (i.qty || 1); }, 0);
    var rows = its.map(function (i) {
      return '<div class="zr-co-it"><div class="n">' + esc(i.name) + ' <small>× ' + (i.qty || 1) + '</small></div><div class="s">' + rub((i.price || 0) * (i.qty || 1)) + '</div></div>';
    }).join("");
    return '<aside class="zr-co-sum"><h2>Ваш заказ</h2><div class="zr-co-items">' + rows + '</div>' +
      '<div class="sr"><span>Товары, ' + qty + ' шт.</span><b>' + rub(sub) + '</b></div>' +
      '<div class="sr good"><span>Скидка от завода</span><b>−' + rub(disc) + '</b></div>' +
      '<div class="sr"><span>Доставка</span><span class="muted">расчёт при оформлении</span></div>' +
      '<div class="sr total"><span>К оплате</span><b>' + rub(total) + '</b></div>' +
      '<button class="zr-co-btn" id="zr-co-submit" type="button">Подтвердить заказ</button>' +
      '<p class="sn">Счёт с НДС · гарантия 24 мес. После оформления инженер свяжется в течение 15 минут для подтверждения.</p></aside>';
  }

  function formHTML() {
    var org = entity === "org";
    var fields = org
      ? '<div class="zr-frow"><div class="zr-field full"><label>Организация *</label><input data-req name="org" placeholder="ООО «Ромашка»"></div>' +
        '<div class="zr-field"><label>ИНН *</label><input data-req name="inn" inputmode="numeric" placeholder="7712345678"></div>' +
        '<div class="zr-field"><label>Контактное лицо *</label><input data-req name="person" placeholder="Иван Иванов"></div></div>'
      : '<div class="zr-field full"><label>ФИО *</label><input data-req name="person" placeholder="Иван Иванов"></div>';
    return '<div class="zr-co-panel"><h2>Кто оформляет заказ</h2>' +
      '<div class="zr-seg"><button type="button" data-ent="org" class="' + (org ? "on" : "") + '">Юридическое лицо</button>' +
      '<button type="button" data-ent="ind" class="' + (org ? "" : "on") + '">Физическое лицо</button></div>' +
      fields +
      '<div class="zr-frow"><div class="zr-field"><label>Телефон *</label><input data-req name="phone" placeholder="+7 ___ ___-__-__"></div>' +
      '<div class="zr-field"><label>Email *</label><input data-req name="email" placeholder="you@company.ru"></div></div>' +
      '</div>' +
      '<div class="zr-co-panel"><h2>Доставка</h2><p class="sub">Транспортными компаниями по всей России · отгрузка от 3 дней.</p>' +
      '<div class="zr-field full"><label>Город и адрес доставки</label><input name="addr" placeholder="Город, улица, дом"></div></div>' +
      '<div class="zr-co-panel"><h2>Комментарий к заказу</h2><div class="zr-field full"><textarea name="comment" rows="3" placeholder="Требования к исполнению, сроки, реквизиты для счёта"></textarea></div></div>';
  }

  function render() {
    if (!items().length) {
      host.innerHTML = '<div class="zr-co-ok"><div class="ic" style="background:#eef2f6;color:#8a97a1">🛒</div><h2>Корзина пуста</h2><p>Добавьте товары, чтобы оформить заказ.</p><div class="btns"><a class="pri" href="catalog.html">В каталог</a><a class="gh" href="podbor.html">Подбор редуктора</a></div></div>';
      return;
    }
    host.innerHTML = '<div class="zr-co-grid"><form id="zr-co-form" onsubmit="return false">' + formHTML() + '</form>' + summaryHTML() + '</div>';
  }

  function orderNo() {
    var dt = new Date(), p = function (x) { return ("0" + x).slice(-2); };
    return "ЗР-" + String(dt.getFullYear()).slice(2) + p(dt.getMonth() + 1) + p(dt.getDate()) + "-" + p(dt.getHours()) + p(dt.getMinutes());
  }

  function submit() {
    var form = d.getElementById("zr-co-form"); if (!form) return;
    var ok = true, first = null;
    [].forEach.call(form.querySelectorAll("[data-req]"), function (inp) {
      var bad = !inp.value.trim();
      inp.closest(".zr-field").classList.toggle("err", bad);
      if (bad && !first) first = inp;
      if (bad) ok = false;
    });
    if (!ok) { if (first) first.focus(); return; }
    var no = orderNo();
    // сохраняем заказ в историю + профиль (для личного кабинета)
    function val(n) { var el = form.querySelector('[name="' + n + '"]'); return el ? el.value.trim() : ""; }
    var its = items();
    var sub = its.reduce(function (a, i) { return a + (i.price || 0) * (i.qty || 1); }, 0);
    var total = sub - Math.round(sub * DISCOUNT);
    var order = { no: no, date: new Date().toISOString(), items: its.map(function (i) { return { name: i.name, price: i.price, qty: i.qty }; }), total: total, entity: entity, org: val("org"), person: val("person"), phone: val("phone"), email: val("email"), status: "Принят" };
    try { var arr = JSON.parse(localStorage.getItem("zr_orders") || "[]"); arr.unshift(order); localStorage.setItem("zr_orders", JSON.stringify(arr.slice(0, 50))); } catch (e) {}
    try { localStorage.setItem("zr_profile", JSON.stringify({ entity: entity, org: val("org"), inn: val("inn"), person: val("person"), phone: val("phone"), email: val("email"), addr: val("addr") })); } catch (e) {}
    // отправка заявки на почту (если настроен Web3Forms) — fire-and-forget
    try {
      if (window.zrSubmitForm) {
        window.zrSubmitForm("Новый заказ " + no + " — сайт «Завод Редукторов»", {
          "Номер заказа": no, "Тип": entity === "ind" ? "Физлицо" : "Юрлицо",
          "Организация/ФИО": val("org") || val("person"), "ИНН": val("inn"), "Контактное лицо": val("person"),
          "Телефон": val("phone"), "Email": val("email"), "Адрес": val("addr"), "Комментарий": val("comment"),
          "Состав": its.map(function (i) { return i.name + " ×" + (i.qty || 1); }).join("; "), "Сумма": rub(total)
        });
      }
    } catch (e) {}
    clearCart();
    host.innerHTML = '<div class="zr-co-ok"><div class="ic">✓</div><h2>Заказ принят</h2>' +
      '<p>Спасибо! Мы получили заявку. Инженер свяжется с вами в течение 15 минут в рабочее время, подтвердит наличие, рассчитает доставку и выставит счёт.</p>' +
      '<div class="ord">Номер заказа: ' + no + '</div>' +
      '<div class="btns"><a class="pri" href="index.html">На главную</a><a class="gh" href="catalog.html">Продолжить покупки</a></div></div>';
    try { window.scrollTo({ top: host.getBoundingClientRect().top + window.scrollY - 90, behavior: "smooth" }); } catch (e) {}
  }

  d.addEventListener("click", function (e) {
    if (!host) return;
    var seg = e.target.closest("[data-ent]");
    if (seg) { entity = seg.getAttribute("data-ent"); render(); return; }
    if (e.target.closest("#zr-co-submit")) { e.preventDefault(); submit(); }
  });

  function build() {
    var mockup = d.querySelector(".m1");
    if (!mockup) return false;
    mockup.style.display = "none";
    css();
    if (!host) { host = d.createElement("section"); host.className = "zr-co"; mockup.parentNode.insertBefore(host, mockup); }
    render();
    return true;
  }
  var n = 0; (function loop() { if (build()) return; if (n++ < 60) setTimeout(loop, 80); })();
})();
