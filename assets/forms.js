/* Отправка заявок с сайта. По умолчанию — демо (без доставки).
   ЧТОБЫ ЗАЯВКИ РЕАЛЬНО ПРИХОДИЛИ НА ПОЧТУ:
   1) Откройте https://web3forms.com , введите почту (напр. zr@zavod-red.ru)
      и получите бесплатный Access Key (придёт письмом, аккаунт не нужен).
   2) Вставьте его ниже в WEB3FORMS_KEY (ключ публичный — держать в коде безопасно).
   После этого все формы («Оставить заявку», «Подтвердить заказ») будут
   отправлять данные на вашу почту. Без ключа формы работают как демо. */
(function () {
  "use strict";
  window.ZR_FORMS = window.ZR_FORMS || {
    WEB3FORMS_KEY: "",              // <-- вставьте ключ Web3Forms сюда
    NOTIFY_EMAIL: "zr@zavod-red.ru" // куда придут заявки (почта, к которой привязан ключ)
  };

  // Отправляет заявку. Возвращает Promise<boolean> — доставлено ли реально.
  window.zrSubmitForm = function (subject, fields) {
    var cfg = window.ZR_FORMS || {};
    if (!cfg.WEB3FORMS_KEY) return Promise.resolve(false); // не настроено → вызывающий код делает fallback
    var payload = { access_key: cfg.WEB3FORMS_KEY, subject: subject, from_name: "Сайт «Завод Редукторов»" };
    for (var k in fields) if (fields.hasOwnProperty(k) && fields[k] != null && fields[k] !== "") payload[k] = fields[k];
    return fetch("https://api.web3forms.com/submit", {
      method: "POST",
      headers: { "Content-Type": "application/json", "Accept": "application/json" },
      body: JSON.stringify(payload)
    }).then(function (r) { return r.json(); })
      .then(function (d) { return !!(d && d.success); })
      .catch(function () { return false; });
  };

  window.zrFormsEnabled = function () { return !!(window.ZR_FORMS && window.ZR_FORMS.WEB3FORMS_KEY); };
})();
