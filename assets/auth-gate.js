/* Экран входа/регистрации кабинета. Показывается поверх кабинета, если нет сессии.
   Требует assets/auth.js (window.zrAuth). Загружать ПЕРЕД cabinet-mount.js на cabinet.html. */
(function () {
  "use strict";
  if (!window.zrAuth) return;
  var d = document;
  function el(tag, attrs, html) {
    var e = d.createElement(tag);
    if (attrs) for (var k in attrs) e.setAttribute(k, attrs[k]);
    if (html != null) e.innerHTML = html;
    return e;
  }

  function css() {
    if (d.getElementById("zr-auth-css")) return;
    var s = el("style", { id: "zr-auth-css" });
    s.textContent =
      "#zr-auth{position:fixed;inset:0;z-index:9000;background:var(--bg,#eef0ec);display:flex;align-items:center;justify-content:center;padding:24px;overflow:auto;font-family:inherit}" +
      "#zr-auth .ac{width:100%;max-width:440px;background:var(--card,#fff);border:1px solid var(--line,#e2e6e0);border-radius:18px;box-shadow:0 24px 60px rgba(16,31,42,.14);padding:34px 30px}" +
      "#zr-auth .ac-logo{height:38px;display:block;margin:0 auto 18px}" +
      "#zr-auth h1{font:600 22px/1.2 'Space Grotesk',sans-serif;text-align:center;margin:0 0 6px;color:var(--text,#101f2a)}" +
      "#zr-auth .ac-sub{text-align:center;color:var(--muted,#5c6b76);font-size:13.5px;margin:0 0 22px}" +
      "#zr-auth .ac-tabs{display:flex;gap:6px;background:var(--bg,#eef0ec);border-radius:11px;padding:5px;margin-bottom:20px}" +
      "#zr-auth .ac-tab{flex:1;text-align:center;padding:10px;border-radius:8px;border:0;background:transparent;font:600 14px/1 inherit;color:var(--muted,#5c6b76);cursor:pointer}" +
      "#zr-auth .ac-tab.on{background:var(--card,#fff);color:var(--text,#101f2a);box-shadow:0 2px 8px rgba(16,31,42,.08)}" +
      "#zr-auth label{display:block;font-size:12.5px;color:var(--muted,#5c6b76);margin:0 0 5px}" +
      "#zr-auth .fld{margin-bottom:14px}" +
      "#zr-auth input{width:100%;box-sizing:border-box;padding:12px 14px;border:1px solid var(--line,#e2e6e0);border-radius:10px;background:var(--bg,#f6f8f5);color:var(--text,#101f2a);font-size:15px;outline:0}" +
      "#zr-auth input:focus{border-color:var(--red,#cf1616)}" +
      "#zr-auth .ac-btn{width:100%;padding:13px;border:0;border-radius:11px;background:var(--red,#cf1616);color:#fff;font:600 15px/1 'Space Grotesk',sans-serif;cursor:pointer;margin-top:4px}" +
      "#zr-auth .ac-btn:disabled{opacity:.6;cursor:default}" +
      "#zr-auth .ac-msg{margin:14px 0 0;font-size:13px;border-radius:9px;padding:10px 12px;display:none}" +
      "#zr-auth .ac-msg.on{display:block}" +
      "#zr-auth .ac-msg.err{background:#fdecec;color:#b31414}" +
      "#zr-auth .ac-msg.ok{background:#e8f6ec;color:#1c7a3a}" +
      "#zr-auth .ac-foot{text-align:center;margin-top:18px;font-size:12.5px;color:var(--muted,#5c6b76)}" +
      "#zr-auth .ac-foot a{color:var(--red,#cf1616);text-decoration:none}" +
      "#zr-auth .ac-hint{font-size:11.5px;color:var(--muted,#8a97a0);margin-top:3px}";
    d.head.appendChild(s);
  }

  function maskPhone(inp) {
    inp.addEventListener("input", function () {
      var dg = this.value.replace(/\D/g, ""); if (dg[0] === "7" || dg[0] === "8") dg = dg.slice(1); dg = dg.slice(0, 10);
      var x = ("7" + dg).match(/(\d{1})(\d{0,3})(\d{0,3})(\d{0,2})(\d{0,2})/); if (!x) return;
      this.value = !x[2] ? "+7 (" : "+7 (" + x[2] + (x[3] ? ") " + x[3] : "") + (x[4] ? "-" + x[4] : "") + (x[5] ? "-" + x[5] : "");
    });
    inp.addEventListener("focus", function () { if (this.value === "") this.value = "+7 ("; });
  }

  function render() {
    css();
    var wrap = el("div", { id: "zr-auth" });
    wrap.innerHTML =
      '<div class="ac">' +
      '<img class="ac-logo" src="/assets/zr_logo.webp" alt="Завод Редукторов" onerror="this.style.display=\'none\'">' +
      '<h1 id="ac-title">Личный кабинет</h1>' +
      '<p class="ac-sub">Заявки, история заказов, персональный менеджер и КП в одном месте.</p>' +
      '<div class="ac-tabs"><button class="ac-tab on" data-t="login">Вход</button><button class="ac-tab" data-t="reg">Регистрация</button></div>' +
      '<form id="ac-form" autocomplete="on" novalidate>' +
        '<div class="fld reg-only" style="display:none"><label>Имя и фамилия</label><input name="name" type="text" autocomplete="name" placeholder="Иван Петров"></div>' +
        '<div class="fld reg-only" style="display:none"><label>Компания</label><input name="company" type="text" autocomplete="organization" placeholder="ООО «…» (необязательно)"></div>' +
        '<div class="fld"><label>E-mail</label><input name="email" type="email" autocomplete="email" placeholder="you@company.ru"></div>' +
        '<div class="fld reg-only" style="display:none"><label>Телефон</label><input name="phone" type="tel" autocomplete="tel" placeholder="+7 (___) ___-__-__"></div>' +
        '<div class="fld"><label>Пароль</label><input name="password" type="password" autocomplete="current-password" placeholder="••••••"><div class="ac-hint reg-only" style="display:none">Минимум 6 символов.</div></div>' +
        '<button class="ac-btn" type="submit" id="ac-submit">Войти</button>' +
        '<div class="ac-msg" id="ac-msg"></div>' +
      '</form>' +
      '<div class="ac-foot"><a href="/">← На сайт</a> · <a href="tel:+74951514102">+7 (495) 151-41-02</a></div>' +
      '</div>';
    d.body.appendChild(wrap);
    d.documentElement.style.overflow = "hidden";

    var mode = "login";
    var form = wrap.querySelector("#ac-form"), msg = wrap.querySelector("#ac-msg"),
        submit = wrap.querySelector("#ac-submit"), title = wrap.querySelector("#ac-title");
    var phone = form.querySelector('input[name=phone]'); maskPhone(phone);

    function setMode(m) {
      mode = m;
      wrap.querySelectorAll(".ac-tab").forEach(function (b) { b.classList.toggle("on", b.getAttribute("data-t") === m); });
      wrap.querySelectorAll(".reg-only").forEach(function (n) { n.style.display = m === "reg" ? "" : "none"; });
      submit.textContent = m === "reg" ? "Зарегистрироваться" : "Войти";
      title.textContent = m === "reg" ? "Регистрация" : "Личный кабинет";
      form.querySelector('input[name=password]').setAttribute("autocomplete", m === "reg" ? "new-password" : "current-password");
      showMsg("", "");
    }
    function showMsg(t, kind) { msg.className = "ac-msg" + (t ? " on " + kind : ""); msg.textContent = t; }

    wrap.querySelectorAll(".ac-tab").forEach(function (b) {
      b.addEventListener("click", function () { setMode(b.getAttribute("data-t")); });
    });

    form.addEventListener("submit", async function (e) {
      e.preventDefault();
      var v = function (n) { var x = form.querySelector('input[name=' + n + ']'); return x ? x.value.trim() : ""; };
      submit.disabled = true; showMsg("Отправляем…", "ok");
      var res;
      if (mode === "reg") res = await window.zrAuth.register({ name: v("name"), company: v("company"), email: v("email"), phone: v("phone"), password: v("password") });
      else res = await window.zrAuth.login(v("email"), v("password"));
      submit.disabled = false;
      if (res.ok) { showMsg(res.msg + " Входим…", "ok"); setTimeout(function () { location.reload(); }, 700); }
      else showMsg(res.msg, "err");
    });
  }

  async function boot() {
    if (window.zrAuth.isAuthed()) return;
    try { if (await window.zrAuth.syncSession()) { location.reload(); return; } } catch (e) {}
    render();
  }
  if (d.readyState === "loading") d.addEventListener("DOMContentLoaded", boot); else boot();
})();
