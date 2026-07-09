/* Регистрация / вход в личный кабинет ЗР.
   Трёхслойная архитектура (все слои сосуществуют, деградируют мягко):
   1) Заявка: любая регистрация ВСЕГДА создаёт лид через /api/feedback.php — отдел продаж
      сразу видит клиента. Работает всегда, без нового бэкенда.
   2) Аккаунт: если развёрнут /api/register.php + БД — создаётся настоящий аккаунт (пароль
      хешируется на сервере, вход с любого устройства). Активируется автоматически, когда
      бэкенд отвечает не 404.
   3) CRM: register.php при настроенном CRM_API пробрасывает клиента в CRM (server-side).
   До настройки бэкенда кабинет работает в локальном режиме (localStorage: профиль, заявки,
   избранное) — аккаунт на этом устройстве. */
(function () {
  "use strict";
  var API = "/api/";                 // общий бэкенд сайта (feedback.php — лиды в CRM)
  var AUTH_API = "/api/cabinet/";     // бэкенд аккаунтов кабинета (register/login/me) — отдельная папка, НЕ пересекается с CRM
  var LS = { session: "zr_session", profile: "zr_profile", users: "zr_users", backend: "zr_backend" };

  function j(k, d) { try { return JSON.parse(localStorage.getItem(k)) || d; } catch (e) { return d; } }
  function set(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }
  function del(k) { try { localStorage.removeItem(k); } catch (e) {} }
  function emailOk(e) { return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e); }
  // единый профиль: и схема auth (name/company), и схема кабинета/checkout (person/org/inn/entity/addr)
  function mkProfile(name, company, email, phone, manager) {
    var ex = j(LS.profile, {}) || {};
    return { name: name || "", company: company || "", email: email || "", phone: phone || "", manager: manager || null,
             person: name || "", org: company || "", entity: ex.entity || "org", inn: ex.inn || "", addr: ex.addr || "" };
  }
  function phoneOk(p) { return (p || "").replace(/\D/g, "").length >= 11; }

  /* --- определить, живой ли бэкенд аккаунтов (кэш на 1 час) --- */
  function backendState() { return j(LS.backend, null); }
  async function probeBackend() {
    var c = backendState();
    if (c && c.t && (Date.now() - c.t) < 3600000) return c.live;
    var live = false;
    try {
      var r = await fetch(AUTH_API + "register.php", { method: "GET", cache: "no-store" });
      // 404 => файла нет (бэкенд не развёрнут). Любой иной ответ (405/400/200/501) => есть.
      live = r.status !== 404;
    } catch (e) { live = false; }
    set(LS.backend, { live: live, t: Date.now() });
    return live;
  }

  /* --- лид в отдел продаж через существующий feedback.php (контракт как в inner.js) --- */
  async function sendLead(data, kind) {
    try {
      var fd = new FormData();
      fd.append("work_email", "");                       // honeypot
      fd.append("text-562", data.name || "");
      fd.append("tel-535", data.phone || "");
      fd.append("email", data.email || "");
      fd.append("company", data.company || "");
      fd.append("product_title", (kind || "Регистрация в кабинете") + " · " + (data.email || ""));
      var r = await fetch(API + "feedback.php", { method: "POST", body: fd });
      return r.ok;
    } catch (e) { return false; }
  }

  /* --- API --- */
  var Auth = {
    user: function () { return j(LS.session, null); },
    isAuthed: function () { return !!(this.user() && this.user().email); },
    profile: function () { return j(LS.profile, null); },

    logout: function () { del(LS.session); },

    /* Регистрация: лид ВСЕГДА + аккаунт если бэкенд живой + сессия локально */
    register: async function (data) {
      if (!data.name || data.name.trim().length < 2) return { ok: false, msg: "Укажите имя." };
      if (!emailOk(data.email)) return { ok: false, msg: "Проверьте e-mail." };
      if (!phoneOk(data.phone)) return { ok: false, msg: "Телефон полностью: +7 (XXX) XXX-XX-XX." };
      if (!data.password || data.password.length < 6) return { ok: false, msg: "Пароль минимум 6 символов." };

      var mode = "local", accountMsg = "";
      if (await probeBackend()) {
        try {
          var r = await fetch(AUTH_API + "register.php", {
            method: "POST", credentials: "include", headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ name: data.name, email: data.email, phone: data.phone, company: data.company, password: data.password })
          });
          var res = await r.json().catch(function () { return {}; });
          if (r.ok && res.status === "success") { mode = "account"; }
          else if (res.status === "exists") { return { ok: false, msg: "Такой e-mail уже зарегистрирован — войдите." }; }
          else { accountMsg = res.message || ""; }
        } catch (e) { /* упадём в локальный режим */ }
      }

      // лид в продажи — всегда
      await sendLead(data, "Регистрация в кабинете");

      // локальная сессия + профиль (кабинет рендерится из них)
      var users = j(LS.users, {});
      users[data.email.toLowerCase()] = { name: data.name, company: data.company || "", phone: data.phone, ts: new Date().toISOString() };
      set(LS.users, users);
      set(LS.session, { email: data.email, name: data.name, mode: mode });
      set(LS.profile, mkProfile(data.name, data.company, data.email, data.phone, null));
      if (window.ym) try { ym(109758131, "reachGoal", "registration"); } catch (_) {}
      return { ok: true, mode: mode, msg: mode === "account" ? "Аккаунт создан." : "Регистрация принята — менеджер свяжется с вами." };
    },

    /* Восстановить сессию с сервера (вход сохраняется, даже если очистили localStorage) */
    syncSession: async function () {
      if (this.isAuthed()) return true;
      if (!(await probeBackend())) return false;
      try {
        var r = await fetch(AUTH_API + "me.php", { credentials: "include", cache: "no-store" });
        var res = await r.json().catch(function () { return {}; });
        if (res.status === "success" && res.user) {
          set(LS.session, { email: res.user.email, name: res.user.name, mode: "account" });
          set(LS.profile, mkProfile(res.user.name, res.user.company, res.user.email, res.user.phone, null));
          return true;
        }
      } catch (e) {}
      return false;
    },

    /* Вход: сервер если живой; иначе локально (аккаунт этого устройства) */
    login: async function (email, password) {
      if (!emailOk(email)) return { ok: false, msg: "Проверьте e-mail." };
      if (!password) return { ok: false, msg: "Введите пароль." };
      if (await probeBackend()) {
        try {
          var r = await fetch(AUTH_API + "login.php", {
            method: "POST", credentials: "include", headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ email: email, password: password })
          });
          var res = await r.json().catch(function () { return {}; });
          if (r.ok && res.status === "success") {
            set(LS.session, { email: email, name: (res.user && res.user.name) || email, mode: "account" });
            if (res.user) set(LS.profile, mkProfile(res.user.name, res.user.company, email, res.user.phone, res.user.manager));
            return { ok: true, msg: "Вход выполнен." };
          }
          // бэкенд есть, но БД аккаунтов не настроена (501 / «не настроен») → пробуем локальный режим ниже
          if (r.status !== 501 && !/не настро/i.test(res.message || "")) return { ok: false, msg: res.message || "Неверный e-mail или пароль." };
        } catch (e) { /* сервер недоступен → локальный режим ниже */ }
      }
      // локальный режим: восстановить сессию, если на этом устройстве регистрировались
      var users = j(LS.users, {});
      var u = users[email.toLowerCase()];
      if (!u) return { ok: false, msg: "На этом устройстве нет такого аккаунта. Зарегистрируйтесь или войдите с устройства регистрации." };
      set(LS.session, { email: email, name: u.name, mode: "local" });
      set(LS.profile, mkProfile(u.name, u.company, email, u.phone, null));
      return { ok: true, msg: "Вход выполнен." };
    }
  };
  window.zrAuth = Auth;
})();
