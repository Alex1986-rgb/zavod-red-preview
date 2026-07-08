/* Реальная корзина из localStorage.zr_cart вместо макета. Только на cart.html. */
(function () {
  "use strict";
  var d = document;
  var DISCOUNT = 0.08;

  function getCart() { try { return JSON.parse(localStorage.getItem("zr_cart") || '{"items":[]}'); } catch (e) { return { items: [] }; } }
  function save(c) { try { localStorage.setItem("zr_cart", JSON.stringify(c)); } catch (e) {} if (window.zrRefreshBadge) try { window.zrRefreshBadge(); } catch (_) {} }
  function rub(n) { return Math.round(n).toLocaleString("ru-RU") + " ₽"; }
  function esc(s) { return String(s == null ? "" : s).replace(/[<>&]/g, function (c) { return { "<": "&lt;", ">": "&gt;", "&": "&amp;" }[c]; }); }

  function css() {
    if (d.getElementById("zr-cart-css")) return;
    var s = d.createElement("style"); s.id = "zr-cart-css";
    s.textContent =
      ".zr-cart{background:#eef2f6}" +
      ".zr-cart-grid{max-width:1240px;margin:0 auto;padding:10px 20px 34px;display:grid;grid-template-columns:1fr 372px;gap:22px;align-items:start}" +
      ".zr-cart-list{display:flex;flex-direction:column;gap:14px}" +
      ".zr-ci{background:#fff;border:1px solid rgba(12,20,28,.1);border-radius:16px;padding:15px 16px;display:grid;grid-template-columns:74px 1fr auto auto 30px;gap:16px;align-items:center}" +
      ".zr-ci-img{width:74px;height:74px;border-radius:12px;background:#eef3f7;overflow:hidden;display:flex;align-items:center;justify-content:center;flex:none}" +
      ".zr-ci-img img{width:100%;height:100%;object-fit:contain}" +
      ".zr-ci-img span{font:800 22px 'Space Grotesk',sans-serif;color:#c3ccd3}" +
      ".zr-ci-name{font-weight:700;font-size:16px;color:#0f1c26;line-height:1.25}" +
      ".zr-ci-unit{color:#5a6a75;font-size:13px;margin-top:4px}" +
      ".zr-ci-qty{display:inline-flex;align-items:center;gap:3px;border:1px solid rgba(12,20,28,.12);border-radius:10px;padding:4px}" +
      ".zr-ci-qty button{width:30px;height:30px;border:none;background:#f2f4f6;border-radius:7px;font-size:17px;line-height:1;cursor:pointer;color:#0f1c26;transition:.12s}" +
      ".zr-ci-qty button:hover{background:#e11b1b;color:#fff}" +
      ".zr-ci-qty b{min-width:28px;text-align:center;font-weight:700;font-size:15px}" +
      ".zr-ci-sum{font-weight:800;font-size:16px;white-space:nowrap;text-align:right}" +
      ".zr-ci-del{border:none;background:none;color:#aeb7bf;font-size:15px;cursor:pointer;padding:6px;transition:.12s}" +
      ".zr-ci-del:hover{color:#c33b3b}" +
      ".zr-cart-back{color:#5a6a75;font-weight:600;font-size:14px;margin-top:6px;text-decoration:none}" +
      ".zr-cart-back:hover{color:#e11b1b}" +
      ".zr-cart-sum{background:#fff;border:1px solid rgba(12,20,28,.1);border-radius:18px;padding:24px;position:sticky;top:90px}" +
      ".zr-cart-sum h2{font:700 20px 'Space Grotesk',sans-serif;margin:0 0 16px;color:#0f1c26}" +
      ".zr-cart-sum .sr{display:flex;justify-content:space-between;align-items:center;padding:9px 0;font-size:14.5px;color:#3f4b55}" +
      ".zr-cart-sum .sr b{font-weight:700;color:#0f1c26}" +
      ".zr-cart-sum .sr.good b{color:#12915f}" +
      ".zr-cart-sum .sr .muted{color:#8a97a1;font-size:13px}" +
      ".zr-cart-sum .sr.total{border-top:1px solid rgba(12,20,28,.1);margin-top:8px;padding-top:16px}" +
      ".zr-cart-sum .sr.total b{font:700 24px 'Space Grotesk',sans-serif}" +
      ".zr-cbtn{display:inline-flex;align-items:center;justify-content:center;padding:14px 22px;border-radius:12px;font-weight:700;font-size:15px;cursor:pointer;text-decoration:none;border:1px solid transparent;transition:.15s}" +
      ".zr-cbtn.primary{background:#e11b1b;color:#fff}.zr-cbtn.primary:hover{background:#cf1616}" +
      ".zr-cbtn.ghost{background:#fff;color:#0f1c26;border-color:rgba(12,20,28,.14)}.zr-cbtn.ghost:hover{border-color:#e11b1b;color:#e11b1b}" +
      ".zr-cbtn.wide{width:100%;margin-top:16px}" +
      ".zr-cart-sum .sn{color:#8a97a1;font-size:12.5px;line-height:1.5;margin:12px 0 0;text-align:center}" +
      ".zr-cart-empty{max-width:520px;margin:16px auto 40px;text-align:center;background:#fff;border:1px solid rgba(12,20,28,.1);border-radius:18px;padding:46px 30px}" +
      ".zr-cart-empty .ec-ic{font-size:46px;line-height:1}" +
      ".zr-cart-empty h2{font:700 24px 'Space Grotesk',sans-serif;margin:14px 0 8px;color:#0f1c26}" +
      ".zr-cart-empty p{color:#5a6a75;font-size:15px;line-height:1.55;margin:0 auto 22px;max-width:360px}" +
      ".zr-cart-empty .ec-btns{display:flex;gap:12px;justify-content:center;flex-wrap:wrap}" +
      "@media(max-width:900px){.zr-cart-grid{grid-template-columns:1fr}.zr-cart-sum{position:static}}" +
      "@media(max-width:560px){.zr-ci{grid-template-columns:58px 1fr 30px;grid-template-areas:'img main del' 'img qty sum';row-gap:12px}.zr-ci-img{grid-area:img;width:58px;height:58px}.zr-ci-main{grid-area:main}.zr-ci-qty{grid-area:qty;justify-self:start}.zr-ci-sum{grid-area:sum}.zr-ci-del{grid-area:del}}";
    d.head.appendChild(s);
  }

  function itemImg(it) {
    var s = it.img || "";
    if (s && /^(assets\/|https?:)/.test(s) && s.indexOf("blob:") < 0) {
      return '<img src="' + esc(s) + '" alt="" onerror="this.remove()">';
    }
    return '<span>ЗР</span>';
  }

  var host;
  function render() {
    var c = getCart(), items = (c.items || []).filter(function (i) { return i && i.name; });
    if (!items.length) {
      host.innerHTML = '<div class="zr-cart-empty"><div class="ec-ic">🛒</div><h2>Корзина пуста</h2><p>Добавьте редукторы из каталога или подберите по параметрам.</p><div class="ec-btns"><a class="zr-cbtn primary" href="catalog.html">В каталог</a><a class="zr-cbtn ghost" href="podbor.html">Подбор редуктора</a></div></div>';
      updateSubtitle(0, 0);
      return;
    }
    var sub = items.reduce(function (a, i) { return a + (i.price || 0) * (i.qty || 1); }, 0);
    var disc = Math.round(sub * DISCOUNT), total = sub - disc;
    var qtyTotal = items.reduce(function (a, i) { return a + (i.qty || 1); }, 0);
    var rows = items.map(function (it, idx) {
      return '<div class="zr-ci" data-i="' + idx + '">' +
        '<div class="zr-ci-img">' + itemImg(it) + '</div>' +
        '<div class="zr-ci-main"><div class="zr-ci-name">' + esc(it.name) + '</div><div class="zr-ci-unit">' + rub(it.price || 0) + ' / шт</div></div>' +
        '<div class="zr-ci-qty"><button class="q-dec" type="button" aria-label="Меньше">−</button><b>' + (it.qty || 1) + '</b><button class="q-inc" type="button" aria-label="Больше">+</button></div>' +
        '<div class="zr-ci-sum">' + rub((it.price || 0) * (it.qty || 1)) + '</div>' +
        '<button class="zr-ci-del" type="button" aria-label="Удалить">✕</button>' +
      '</div>';
    }).join("");
    host.innerHTML =
      '<div class="zr-cart-grid"><div class="zr-cart-list">' + rows +
        '<a class="zr-cart-back" href="catalog.html">← Продолжить покупки</a></div>' +
      '<aside class="zr-cart-sum"><h2>Итог заказа</h2>' +
        '<div class="sr"><span>Товары, ' + qtyTotal + ' шт.</span><b>' + rub(sub) + '</b></div>' +
        '<div class="sr good"><span>Скидка от завода</span><b>−' + rub(disc) + '</b></div>' +
        '<div class="sr"><span>Доставка</span><span class="muted">расчёт при оформлении</span></div>' +
        '<div class="sr total"><span>Итого</span><b>' + rub(total) + '</b></div>' +
        '<a class="zr-cbtn primary wide" href="checkout.html">Оформить заказ</a>' +
        '<p class="sn">Счёт с НДС · гарантия 24 мес. Инженер свяжется в течение 15 минут.</p>' +
      '</aside></div>';
    updateSubtitle(items.length, total);
  }

  function updateSubtitle(count, total) {
    var el = [].slice.call(d.querySelectorAll("*")).filter(function (e) { return /поз\.\s*на сумму/.test(e.textContent) && e.children.length === 0; })[0];
    if (el) el.textContent = count ? (count + " поз. на сумму " + rub(total)) : "Корзина пуста";
  }

  d.addEventListener("click", function (e) {
    if (!host) return;
    var row = e.target.closest(".zr-ci"); if (!row) return;
    var idx = parseInt(row.getAttribute("data-i"), 10), c = getCart();
    if (!c.items || !c.items[idx]) return;
    if (e.target.closest(".q-inc")) c.items[idx].qty = (c.items[idx].qty || 1) + 1;
    else if (e.target.closest(".q-dec")) c.items[idx].qty = Math.max(1, (c.items[idx].qty || 1) - 1);
    else if (e.target.closest(".zr-ci-del")) c.items.splice(idx, 1);
    else return;
    save(c); render();
  });

  function build() {
    var mockup = d.querySelector(".m1");
    if (!mockup) return false;
    mockup.style.display = "none";
    css();
    if (!host) { host = d.createElement("section"); host.className = "zr-cart"; mockup.parentNode.insertBefore(host, mockup); }
    render();
    return true;
  }
  var n = 0; (function loop() { if (build()) return; if (n++ < 60) setTimeout(loop, 80); })();
})();
