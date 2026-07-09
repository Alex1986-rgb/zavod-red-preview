/* Кнопки «в избранное» (♥) на карточках каталога (.catcard) и мотор-редукторов (.zrcard).
   Пишет в localStorage zr_favorites — тот же ключ, что читает кабинет (cabinet-mount.js).
   Самостоятельный: не зависит от других скриптов. Подключать на catalog/*.html и
   motor-reduktor-zr/index.html: <script defer src="../assets/fav.js?v=1"></script> */
(function () {
  "use strict";
  var d = document, KEY = "zr_favorites";
  function list() { try { return JSON.parse(localStorage.getItem(KEY)) || []; } catch (e) { return []; } }
  function save(a) { try { localStorage.setItem(KEY, JSON.stringify(a)); } catch (e) {} }
  function has(name) { return list().some(function (x) { return x.name === name; }); }
  function toggle(obj) {
    var a = list(), i = -1;
    for (var k = 0; k < a.length; k++) { if (a[k].name === obj.name) { i = k; break; } }
    if (i >= 0) { a.splice(i, 1); save(a); return false; }
    a.unshift(obj); save(a); return true;
  }
  function priceFrom(txt) { var m = String(txt || "").replace(/[\s ]/g, "").match(/(\d{3,})/); return m ? parseInt(m[1], 10) : 0; }
  function toast(m, warn) {
    var t = d.getElementById("zr-fav-toast");
    if (!t) { t = d.createElement("div"); t.id = "zr-fav-toast"; d.body.appendChild(t); }
    t.textContent = m; t.className = "on" + (warn ? " warn" : "");
    clearTimeout(t._h); t._h = setTimeout(function () { t.className = ""; }, 2200);
  }
  function css() {
    if (d.getElementById("zr-fav-css")) return;
    var s = d.createElement("style"); s.id = "zr-fav-css";
    s.textContent =
      ".zr-fav-btn{position:absolute;top:10px;right:10px;z-index:4;width:36px;height:36px;border-radius:50%;border:1px solid rgba(12,20,28,.12);background:rgba(255,255,255,.94);color:#c2ccd3;font-size:18px;line-height:1;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:.15s;padding:0;box-shadow:0 2px 8px rgba(16,31,42,.08)}" +
      ".zr-fav-btn:hover{color:#e11b1b;border-color:#e11b1b;transform:scale(1.08)}" +
      ".zr-fav-btn.on{color:#e11b1b;border-color:rgba(225,27,27,.4);background:rgba(225,27,27,.08)}" +
      "#zr-fav-toast{position:fixed;left:50%;bottom:26px;transform:translateX(-50%) translateY(20px);z-index:100003;background:#12915f;color:#fff;font-weight:600;font-size:14px;padding:12px 22px;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.25);opacity:0;pointer-events:none;transition:.3s}" +
      "#zr-fav-toast.on{opacity:1;transform:translateX(-50%) translateY(0)}#zr-fav-toast.warn{background:#c0392b}";
    d.head.appendChild(s);
  }
  function mkBtn(card, obj) {
    if (!obj.name || card.querySelector(":scope > .zr-fav-btn")) return;
    if (getComputedStyle(card).position === "static") card.style.position = "relative";
    var b = d.createElement("button");
    b.type = "button"; b.className = "zr-fav-btn" + (has(obj.name) ? " on" : "");
    b.setAttribute("aria-label", "В избранное"); b.title = "В избранное"; b.innerHTML = "&#10084;";
    b.addEventListener("click", function (ev) {
      ev.preventDefault(); ev.stopPropagation();
      var added = toggle(obj); b.classList.toggle("on", added);
      toast(added ? "Добавлено в избранное" : "Убрано из избранного");
    });
    card.appendChild(b);
  }
  function txt(el, sel) { var x = el.querySelector(sel); return x ? x.textContent.trim() : ""; }
  function scan() {
    css();
    [].forEach.call(d.querySelectorAll(".catcard"), function (c) {
      var name = txt(c, ".catcard-title"); if (!name) return;
      var type = txt(c, ".catcard-type");
      mkBtn(c, { name: name, price: 0, spec: type, img: "", url: location.pathname });
    });
    [].forEach.call(d.querySelectorAll(".zrcard"), function (c) {
      var name = txt(c, ".zrcard-title") || txt(c, "h3") || txt(c, "strong");
      if (!name) { var im = c.querySelector("img"); name = im ? (im.getAttribute("alt") || "").replace(/\s*[—-].*$/, "").trim() : ""; }
      if (!name) return;
      var img = ""; var im2 = c.querySelector("img"); if (im2) img = im2.getAttribute("src") || "";
      mkBtn(c, { name: name, price: priceFrom(txt(c, ".zrcard-price")), spec: "", img: img, url: c.getAttribute("href") || "" });
    });
  }
  if (d.readyState === "loading") d.addEventListener("DOMContentLoaded", scan); else scan();
  // на случай ленивой дорисовки карточек — один повторный проход
  setTimeout(scan, 800);
})();
