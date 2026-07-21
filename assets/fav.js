/* Энхансер карточек каталога: на каждую .catcard и .zrcard добавляет
   ♥ «в избранное», «В корзину» (синхрон с корзиной сайта + сервером аккаунта) и
   «Подробнее» → страница модели /reduktor/<slug>.
   Пишет в localStorage zr_favorites / zr_cart (те же ключи, что кабинет и корзина).
   Самодостаточный. Подключать: <script defer src="../assets/fav.js?v=2"></script> */
(function () {
  "use strict";
  if (window.__zrCardsEnh) return; window.__zrCardsEnh = true; // идемпотентность (грузится и явно, и через inner.js)
  var d = document, STORE = "/api/cabinet/store.php";

  /* ---------- storage ---------- */
  function jget(k, def) { try { var v = JSON.parse(localStorage.getItem(k)); return v == null ? def : v; } catch (e) { return def; } }
  function jset(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }
  function favs() { return jget("zr_favorites", []); }
  function cart() { var c = jget("zr_cart", { items: [] }); if (!c || !c.items) c = { items: [] }; return c; }
  function hasFav(name) { return favs().some(function (x) { return x.name === name; }); }
  function toggleFav(obj) {
    var a = favs(), i = -1;
    for (var k = 0; k < a.length; k++) { if (a[k].name === obj.name) { i = k; break; } }
    if (i >= 0) { a.splice(i, 1); jset("zr_favorites", a); pushServer("favorites", a); return false; }
    a.unshift(obj); jset("zr_favorites", a); pushServer("favorites", a); return true;
  }
  function addToCart(obj) {
    var c = cart(), ex = c.items.filter(function (i) { return i.name === obj.name; })[0];
    if (ex) ex.qty = (ex.qty || 1) + 1; else c.items.push({ name: obj.name, price: obj.price || 0, qty: 1, img: obj.img || "" });
    jset("zr_cart", c);
    if (window.zrRefreshBadge) try { window.zrRefreshBadge(); } catch (e) {}
    pushServer("cart", c);
    return c.items.reduce(function (a, i) { return a + (i.qty || 1); }, 0);
  }

  /* ---------- server sync (для залогиненных account) ---------- */
  function acct() { try { var s = JSON.parse(localStorage.getItem("zr_session")); return !!(s && s.mode === "account"); } catch (e) { return false; } }
  function pushServer(k, v) { if (!acct()) return; try { fetch(STORE, { method: "POST", credentials: "include", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ k: k, v: v }) }); } catch (e) {} }

  /* ---------- utils ---------- */
  function slugify(t) { return String(t || "").toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, ""); }
  function priceFrom(txt) { var m = String(txt || "").replace(/[\s  ]/g, "").match(/(\d{3,})/); return m ? parseInt(m[1], 10) : 0; }
  function txt(el, sel) { var x = el.querySelector(sel); return x ? x.textContent.trim() : ""; }
  function toast(m, warn) {
    var t = d.getElementById("zr-fav-toast");
    if (!t) { t = d.createElement("div"); t.id = "zr-fav-toast"; d.body.appendChild(t); }
    t.textContent = m; t.className = "on" + (warn ? " warn" : "");
    clearTimeout(t._h); t._h = setTimeout(function () { t.className = ""; }, 2200);
  }

  function css() {
    if (d.getElementById("zr-fav-css")) return;
    var s = d.createElement("style"); s.id = "zr-fav-css";
    s.textContent = [
      ".zr-fav-btn{position:absolute;top:10px;right:10px;z-index:4;width:36px;height:36px;border-radius:50%;border:1px solid rgba(12,20,28,.12);background:rgba(255,255,255,.94);color:#c2ccd3;font-size:18px;line-height:1;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:.15s;padding:0;box-shadow:0 2px 8px rgba(16,31,42,.08)}",
      ".zr-fav-btn:hover{color:#e11b1b;border-color:#e11b1b;transform:scale(1.08)}",
      ".zr-fav-btn.on{color:#e11b1b;border-color:rgba(225,27,27,.4);background:rgba(225,27,27,.08)}",
      ".zr-cardacts{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:10px}",
      ".zr-cart-btn{display:inline-flex;align-items:center;gap:6px;border:0;background:#e11b1b;color:#fff;font:700 13px 'Archivo',sans-serif;padding:9px 14px;border-radius:10px;cursor:pointer;transition:.15s}.zr-cart-btn:hover{background:#cf1616}",
      ".zr-more-btn{display:inline-flex;align-items:center;gap:5px;border:1px solid rgba(12,20,28,.14);background:#fff;color:#0f1c26;font:700 13px 'Archivo',sans-serif;padding:9px 14px;border-radius:10px;text-decoration:none;transition:.15s}.zr-more-btn:hover{border-color:#e11b1b;color:#e11b1b}",
      ".zr-zcart{margin-top:8px}",
      "#zr-fav-toast{position:fixed;left:50%;bottom:26px;transform:translateX(-50%) translateY(20px);z-index:100003;background:#12915f;color:#fff;font-weight:600;font-size:14px;padding:12px 22px;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.25);opacity:0;pointer-events:none;transition:.3s}",
      "#zr-fav-toast.on{opacity:1;transform:translateX(-50%) translateY(0)}#zr-fav-toast.warn{background:#c0392b}"
    ].join("");
    d.head.appendChild(s);
  }

  function heartBtn(card, obj) {
    if (!obj.name || card.querySelector(":scope > .zr-fav-btn")) return;
    if (getComputedStyle(card).position === "static") card.style.position = "relative";
    var b = d.createElement("button");
    b.type = "button"; b.className = "zr-fav-btn" + (hasFav(obj.name) ? " on" : "");
    b.setAttribute("aria-label", "В избранное"); b.title = "В избранное"; b.innerHTML = "&#10084;";
    b.addEventListener("click", function (ev) {
      ev.preventDefault(); ev.stopPropagation();
      var added = toggleFav(obj); b.classList.toggle("on", added);
      toast(added ? "Добавлено в избранное" : "Убрано из избранного");
    });
    card.appendChild(b);
  }
  function cartBtn(obj, label) {
    var b = d.createElement("button");
    b.type = "button"; b.className = "zr-cart-btn";
    b.innerHTML = "🛒 " + (label || "В корзину");
    b.addEventListener("click", function (ev) {
      ev.preventDefault(); ev.stopPropagation();
      addToCart(obj); toast("Добавлено в корзину: " + obj.name);
    });
    return b;
  }
  function moreLink(url) {
    var a = d.createElement("a"); a.className = "zr-more-btn"; a.href = url; a.innerHTML = "Подробнее →";
    a.addEventListener("click", function (ev) { ev.stopPropagation(); });
    return a;
  }

  function enhanceCatcard(c) {
    var name = txt(c, ".catcard-title"); if (!name) return;
    if (c.getAttribute("data-zr-enh")) return; c.setAttribute("data-zr-enh", "1");
    var type = txt(c, ".catcard-type");
    var price = priceFrom(txt(c, ".catcard-price"));
    var slug = slugify(name), detail = "/reduktor/" + slug;
    var obj = { name: name, price: price, spec: type, img: "", url: detail };
    heartBtn(c, obj);
    // действия: В корзину + Подробнее (страница модели). Старую ссылку «Подобрать» превращаем в «Подробнее».
    var foot = c.querySelector(".catcard-foot") || c;
    var oldCta = foot.querySelector(".catcard-cta");
    var acts = d.createElement("div"); acts.className = "zr-cardacts";
    acts.appendChild(cartBtn(obj, "В корзину"));
    acts.appendChild(moreLink(detail));
    if (oldCta) oldCta.style.display = "none";
    foot.appendChild(acts);
  }
  function enhanceZrcard(c) {
    if (c.getAttribute("data-zr-enh")) return; c.setAttribute("data-zr-enh", "1");
    var name = txt(c, ".zrcard-title") || txt(c, "h3") || txt(c, "strong");
    if (!name) { var im0 = c.querySelector("img"); name = im0 ? (im0.getAttribute("alt") || "").replace(/\s*[—-].*$/, "").trim() : ""; }
    if (!name) return;
    var img = ""; var im = c.querySelector("img"); if (im) img = im.getAttribute("src") || "";
    var obj = { name: name, price: priceFrom(txt(c, ".zrcard-price")), spec: "", img: img, url: c.getAttribute("href") || "" };
    heartBtn(c, obj); // сама карточка — ссылка на страницу модели (это и есть «подробнее»)
    // кнопка в корзину внутри карточки-ссылки
    if (!c.querySelector(".zr-zcart")) {
      var wrap = d.createElement("div"); wrap.className = "zr-zcart";
      wrap.appendChild(cartBtn(obj, "В корзину"));
      var foot = c.querySelector(".zrcard-foot") || c;
      foot.appendChild(wrap);
    }
  }

  // pcard (сетка товаров, напр. catalog/index.html): ♥ + «Подробнее»→/reduktor/<slug>. Корзина уже есть (.pcard-cart).
  function enhancePcardA(card) {
    if (card.getAttribute("data-zr-enh")) return;
    if (!card.querySelector(".pcard-body")) return;         // отсекаем hero-вариант (div.pcard с .pc-media)
    card.setAttribute("data-zr-enh", "1");
    var cartEl = card.querySelector(".pcard-cart");
    var name = (cartEl && cartEl.getAttribute("data-name")) || txt(card, ".pcard-title");
    if (!name) return;
    var img = (cartEl && cartEl.getAttribute("data-img")) || ""; var im = card.querySelector("img"); if (!img && im) img = im.getAttribute("src") || "";
    // Путь берётся со страницы каталога и часто относительный («../assets/…»). Корзина и
    // кабинет живут в корне, поэтому сохранённый как есть путь там уже никуда не ведёт —
    // приводим к абсолютному от текущей страницы, пока контекст ещё известен.
    if (img && img.charAt(0) !== "/" && !/^(https?:|data:)/.test(img)) {
      try { img = new URL(img, location.href).pathname; } catch (e) {}
    }
    var slug = slugify((name.split("·")[0] || name).trim());  // «EVL 063/747 · Motovario …» → evl-063-747
    var detail = "/reduktor/" + slug;
    var obj = { name: name, price: 0, spec: txt(card, ".pcard-type"), img: img, url: detail };
    heartBtn(card.querySelector(".pcard-media") || card, obj);
    var foot = card.querySelector(".pcard-foot") || card.querySelector(".pcard-body") || card;
    if (!foot.querySelector(".zr-more-btn")) { var a = moreLink(detail); a.style.marginLeft = "8px"; foot.appendChild(a); }
  }
  // карточки-ссылки (ind-card / post-card / ser-card): целиком <a href> → добавляем только ♥
  function enhanceLinkCard(card, titleSel) {
    if (card.getAttribute("data-zr-enh")) return; card.setAttribute("data-zr-enh", "1");
    var name = txt(card, titleSel) || txt(card, "b") || (card.getAttribute("aria-label") || "").trim();
    if (!name) return;
    var img = ""; var im = card.querySelector("img"); if (im) img = im.getAttribute("src") || "";
    heartBtn(card, { name: name, price: 0, spec: "", img: img, url: card.getAttribute("href") || "" });
  }

  function scan() {
    css();
    [].forEach.call(d.querySelectorAll(".catcard"), enhanceCatcard);
    [].forEach.call(d.querySelectorAll(".zrcard"), enhanceZrcard);
    [].forEach.call(d.querySelectorAll(".pcard"), enhancePcardA);
    [].forEach.call(d.querySelectorAll("a.ind-card"), function (c) { enhanceLinkCard(c, "b"); });
    [].forEach.call(d.querySelectorAll("a.post-card"), function (c) { enhanceLinkCard(c, "h2"); });
    [].forEach.call(d.querySelectorAll("a.ser-card"), function (c) { enhanceLinkCard(c, "b"); });
  }
  if (d.readyState === "loading") d.addEventListener("DOMContentLoaded", scan); else scan();
  setTimeout(scan, 800);
})();
