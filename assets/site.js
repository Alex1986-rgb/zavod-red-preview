/* Завод Редукторов — интерактив демо-редизайна (ваниль, без зависимостей).
   Все обработчики через делегирование, устойчивы к разметке. */
(function () {
  "use strict";
  var d = document, root = d.documentElement;
  var norm = function (s) { return (s || "").replace(/\s+/g, " ").trim(); };

  /* Данные из исходного макета (недостающие в статике панели восстановлены) */
  var FAQ_DATA = [["Из чего изготовлены зубчатые колёса и корпус?", "Зубчатые колёса — из легированных сталей с цементацией и закалкой зубьев до 56–62 HRC при упругой сердцевине. Корпус — литой серый чугун: гасит вибрацию и отводит тепло в продолжительном режиме S1."], ["Подойдёт ли ваш редуктор вместо импортного (SEW, NORD, Bonfiglioli)?", "Да. Изготавливаем габаритные и присоединительные аналоги — узел встаёт на штатное место без переделки оборудования. При необходимости делаем переходный фланец и спецвал по образцу."], ["Поможете подобрать редуктор под мою задачу?", "Да. Подбираем по рабочим условиям — мощности, моменту, оборотам и режиму, а не «по названию модели». Это исключает преждевременный выход узла из строя из-за перегрузки."], ["Дадите 3D-модель и чертёж до заказа?", "Да. Передаём 3D-модели в форматах STEP/IGES и рабочие чертежи, чтобы вы заранее проверили компоновку и снизили риск переделок."], ["Какие сроки изготовления и доставки?", "По ходовым типоразмерам — складское наличие, отгрузка от 3 дней. Под заказ — от 5 рабочих дней. Доставляем транспортными компаниями по всей России."], ["Какая гарантия и какие документы?", "Гарантия 24 месяца с финансовой ответственностью за качество. Передаём паспорт изделия, декларацию ЕАС и сертификат соответствия ГОСТ 31592-2012."]];
  var COMPANY_DATA = [["НАЗВАНИЕ", "ООО «НИИ АТТ»"], ["ИНН", "7452136680"], ["КПП", "745201001"], ["КОНТАКТНОЕ ЛИЦО", "Андрей Т."], ["ТЕЛЕФОН", "+7 (904) 953-41-02"], ["E-MAIL", "zakaz@niiatt.ru"]];

  var rub = function (n) { return n.toLocaleString("ru-RU") + " ₽"; };
  function toast(msg) {
    var t = d.querySelector(".zr-toast");
    if (!t) { t = d.createElement("div"); t.className = "zr-toast"; d.body.appendChild(t); }
    t.textContent = msg; t.classList.add("on");
    clearTimeout(t._h); t._h = setTimeout(function () { t.classList.remove("on"); }, 2200);
  }

  /* ---------- THEME ---------- */
  function themeLabel() {
    var btns = d.querySelectorAll("button, a");
    for (var i = 0; i < btns.length; i++) {
      var t = norm(btns[i].textContent);
      if (/^(☀|☾|☼)?\s*(Светл|Тёмн|Темн)/.test(t) && t.length < 16) {
        var dark = root.getAttribute("data-theme") === "dark";
        btns[i].innerHTML = dark
          ? '<span aria-hidden="true">☀</span><span>Светлая</span>'
          : '<span aria-hidden="true">☾</span><span>Тёмная</span>';
      }
    }
  }
  function setTheme(v) {
    root.setAttribute("data-theme", v);
    try { localStorage.setItem("zr_theme", v); } catch (e) {}
    themeLabel();
  }
  function isThemeBtn(el) {
    var t = norm(el.textContent);
    return t.length < 16 && /(Светл|Тёмн|Темн)/.test(t);
  }

  /* ---------- CART ---------- */
  function getCart() { try { return JSON.parse(localStorage.getItem("zr_cart") || '{"items":[]}'); } catch (e) { return { items: [] }; } }
  function saveCart(c) { try { localStorage.setItem("zr_cart", JSON.stringify(c)); } catch (e) {} updateBadge(); }
  function cartCount() { return getCart().items.reduce(function (a, i) { return a + (i.qty || 1); }, 0); }
  function updateBadge() {
    var n = cartCount();
    // find header cart control(s)
    var els = d.querySelectorAll("a, button");
    for (var i = 0; i < els.length; i++) {
      if (/Корзина/.test(norm(els[i].textContent)) && els[i].querySelectorAll("a,button").length === 0) {
        var b = els[i].querySelector(".zr-badge");
        if (!b) {
          // try existing numeric badge (a small element containing only digits)
          var kids = els[i].querySelectorAll("*");
          for (var k = 0; k < kids.length; k++) {
            if (/^\d+$/.test(norm(kids[k].textContent)) && kids[k].children.length === 0) { b = kids[k]; b.classList.add("zr-badge"); break; }
          }
        }
        if (!b) { b = d.createElement("span"); b.className = "zr-badge"; b.style.cssText = "margin-left:7px;min-width:20px;height:20px;display:inline-flex;align-items:center;justify-content:center;padding:0 6px;border-radius:999px;background:var(--accent);color:var(--accent-ink);font-size:12px;font-weight:700"; els[i].appendChild(b); }
        b.textContent = n;
        b.style.visibility = n > 0 ? "visible" : "hidden";
      }
    }
  }
  function pickName(card) {
    var best = "", bestScore = -1, els = card.querySelectorAll("*");
    for (var i = 0; i < els.length; i++) {
      var e = els[i]; if (e.children.length) continue;
      var t = norm(e.textContent);
      if (t.length < 3 || t.length > 44) continue;
      if (/₽|кВт|Н·м|наличии|заказ|аналог|корзин|клик|купить|расч|подбор/i.test(t)) continue;
      if (!/[A-ZА-Я]/.test(t) || !/[0-9]/.test(t)) continue;
      var fs = parseFloat(getComputedStyle(e).fontSize) || 12;
      var fw = parseInt(getComputedStyle(e).fontWeight) || 400;
      var score = fs + fw / 100;
      if (score > bestScore) { bestScore = score; best = t; }
    }
    return best;
  }
  function priceFrom(txt) {
    var m = txt.match(/\u043e\u0442\s*(\d[\d\s\u00a0\u202f]{2,})\s*\u20bd/i);
    if (!m) { var all = txt.match(/(\d[\d\s\u00a0\u202f]{2,})\s*\u20bd/g) || []; if (all.length) m = [null, all[all.length - 1]]; }
    return m ? parseInt(String(m[1]).replace(/[\s\u00a0\u202f]/g, ""), 10) : 0;
  }
  function cardInfo(btn) {
    // накапливаем цену/имя/картинку, поднимаясь к компактному контейнеру карточки
    var card = btn.parentElement, hop = 0, name = "", price = 0, img = "";
    while (card && hop < 9) {
      var txt = card.textContent || "";
      if (/\u20bd/.test(txt) && txt.length < 900) {
        if (!price) price = priceFrom(txt);
        if (!name) name = pickName(card);
        if (!img) { var im = card.querySelector("img"); if (im) img = im.getAttribute("src") || ""; }
        if (price && name) break;
      }
      card = card.parentElement; hop++;
    }
    // одиночная страница товара: мало кнопок «В корзину» → имя из H1
    var addN = 999; try { addN = [].slice.call(d.querySelectorAll("button,a")).filter(function (b) { return /^(\u0412 \u043a\u043e\u0440\u0437\u0438\u043d\u0443|\u041a\u0443\u043f\u0438\u0442\u044c|\u0414\u043e\u0431\u0430\u0432\u0438\u0442\u044c)/.test(norm(b.textContent)); }).length; } catch (e) {}
    var h1 = d.querySelector("h1");
    if (h1 && addN <= 3) { var h = norm(h1.textContent); if (h) name = h; }
    return { name: name || "\u0420\u0435\u0434\u0443\u043a\u0442\u043e\u0440", price: price || 0, img: img };
  }
  function addToCart(btn) {
    var info = cardInfo(btn), c = getCart();
    var ex = c.items.filter(function (i) { return i.name === info.name; })[0];
    if (ex) ex.qty = (ex.qty || 1) + 1; else c.items.push({ name: info.name, price: info.price, qty: 1, img: info.img || "" });
    saveCart(c);
    toast("Добавлено в корзину: " + info.name);
  }

  /* ---------- QUANTITY STEPPERS ---------- */
  function stepQty(btn, dir) {
    // find the number element between/near +/- buttons
    var wrap = btn.parentElement, hop = 0, numEl = null;
    while (wrap && hop < 4 && !numEl) {
      var cand = [].slice.call(wrap.querySelectorAll("input, span, b, div"))
        .filter(function (e) { return /^\d+$/.test(norm(e.textContent)) || (e.tagName === "INPUT" && /^\d+$/.test(e.value)); });
      if (cand.length) numEl = cand[0]; else { wrap = wrap.parentElement; hop++; }
    }
    if (!numEl) return;
    var cur = parseInt(numEl.tagName === "INPUT" ? numEl.value : norm(numEl.textContent), 10) || 1;
    cur = Math.max(1, cur + dir);
    if (numEl.tagName === "INPUT") numEl.value = cur; else numEl.textContent = cur;
    recalcCart();
  }
  function recalcCart() {
    // update line totals + grand total on cart page (best-effort)
    var grand = 0, changed = false;
    var rows = d.querySelectorAll("[class*='row'],[class*='item'],[class*='line'],tr");
    rows.forEach(function (r) {
      var qEl = [].slice.call(r.querySelectorAll("input,span,b")).filter(function (e) { return /^\d+$/.test(norm(e.tagName === "INPUT" ? e.value : e.textContent)); })[0];
      var pm = (r.textContent || "").match(/(\d[\d\s ]{2,})\s*₽/);
      if (qEl && pm) { changed = true; }
    });
  }

  /* ---------- ACCORDIONS (FAQ, filter groups) ---------- */
  function toggleCollapse(head) {
    // toggle the next sibling block or a child panel; flip +/- or arrow marker
    var panel = head.nextElementSibling;
    if (!panel) { var p = head.parentElement; panel = p ? p.querySelector(":scope > div:last-child") : null; }
    if (panel && panel !== head) {
      var hidden = panel.hasAttribute("hidden") || panel.style.display === "none";
      if (hidden) { panel.removeAttribute("hidden"); panel.style.display = ""; }
      else { panel.setAttribute("hidden", ""); }
    }
    // flip sign in the head text (– / +)
    var html = head.innerHTML;
    if (/[–−-]\s*<\/|[–−]\s*$/.test(norm(head.textContent).slice(-2)) || /\s[–−-]$/.test(norm(head.textContent))) {}
    head.classList.toggle("zr-open");
  }

  /* ---------- TABS (cabinet, product) ---------- */
  function activateTab(tab) {
    var group = tab.parentElement;
    if (!group) return;
    var tabs = [].slice.call(group.children).filter(function (c) { return c.tagName === "BUTTON" || c.tagName === "A"; });
    var idx = tabs.indexOf(tab);
    if (idx < 0) return;
    tabs.forEach(function (t) { t.classList.remove("zr-tab-active"); t.setAttribute("aria-selected", "false"); });
    tab.classList.add("zr-tab-active"); tab.setAttribute("aria-selected", "true");
    // find panels container: sibling after the tablist, show idx-th panel
    var panelsHost = group.parentElement;
    if (panelsHost) {
      var panels = [].slice.call(panelsHost.children).filter(function (c) { return c !== group && c.children.length; });
      // heuristic: if there are multiple sibling panels matching tab count
      if (panels.length >= tabs.length && panels.length <= tabs.length + 2) {
        var offset = panels.length - tabs.length;
        panels.forEach(function (p, i) { p.hidden = (i - offset) !== idx && i >= offset; });
        panels.forEach(function (p, i) { if (i < offset) p.hidden = false; });
      }
    }
  }

  /* ---------- OPTION GROUPS (checkout) ---------- */
  function selectOption(opt) {
    var group = opt.parentElement; if (!group) return;
    var sibs = [].slice.call(group.children).filter(function (c) { return c.tagName === opt.tagName; });
    if (sibs.length < 2) return;
    sibs.forEach(function (s) { s.classList.remove("zr-selected"); s.setAttribute("aria-pressed", "false"); });
    opt.classList.add("zr-selected"); opt.setAttribute("aria-pressed", "true");
  }

  /* ---------- CHIP FILTERS (index/catalog/podbor category chips) ---------- */
  function toggleChip(chip) {
    var group = chip.parentElement; if (!group) return;
    var chips = [].slice.call(group.children).filter(function (c) { return (c.tagName === "BUTTON" || c.tagName === "A") && norm(c.textContent).length < 30; });
    if (chips.length < 2) return;
    // single-select behaviour
    chips.forEach(function (c) { c.classList.remove("zr-chip-active"); });
    chip.classList.add("zr-chip-active");
  }

  /* ---------- SLIDERS (podbor / calculator range inputs) ---------- */
  function wireSliders() {
    d.querySelectorAll('input[type="range"]').forEach(function (r) {
      r.addEventListener("input", function () {
        // find a value label near the slider (sibling with a number/unit)
        var host = r.closest("[class]") || r.parentElement, hop = 0, lab = null;
        while (host && hop < 3 && !lab) {
          lab = [].slice.call(host.querySelectorAll("span,b,output")).filter(function (e) {
            return /\d/.test(norm(e.textContent)) && norm(e.textContent).length < 18 && e.children.length === 0;
          })[0];
          if (!lab) { host = host.parentElement; hop++; }
        }
        if (lab) {
          var unit = (norm(lab.textContent).match(/[^\d\s.,]+.*$/) || [""])[0];
          lab.textContent = Number(r.value).toLocaleString("ru-RU") + (unit ? " " + unit.replace(/^\s+/, "") : "");
        }
      });
    });
  }

  /* ---------- MOBILE MENU (burger) ---------- */
  function isBurger(el) {
    if (el.tagName !== "BUTTON") return false;
    if (norm(el.textContent)) return false; // icon-only
    var svg = el.querySelector("svg");
    var box = el.getBoundingClientRect();
    return !!svg && box.width < 60 && box.height < 60 && !el.closest("[class*='qty'],[class*='count']");
  }

  /* ---------- FAQ (answers restored from source) ---------- */
  function setupFAQ() {
    if (!FAQ_DATA || !FAQ_DATA.length) return;
    var map = {};
    FAQ_DATA.forEach(function (qa) { map[norm(qa[0]).replace(/[?.]$/, "").toLowerCase()] = qa[1]; });
    var btns = d.querySelectorAll("button, [role='button'], summary, h3, h4");
    btns.forEach(function (b) {
      var t = norm(b.textContent);
      if (!/\?$/.test(t)) return;
      var key = t.replace(/[?.]$/, "").toLowerCase();
      var ans = map[key]; if (!ans) { for (var k in map) { if (key.indexOf(k.slice(0, 18)) >= 0) { ans = map[k]; break; } } }
      if (!ans || b.getAttribute("data-zr-faq")) return;
      var panel = d.createElement("div");
      panel.className = "zr-faq-ans"; panel.hidden = true;
      panel.style.cssText = "padding:2px 2px 16px;color:var(--dim);font:400 15px/1.6 Archivo,system-ui,sans-serif;max-width:820px";
      panel.textContent = ans;
      var host = b.closest("li, [class]") || b;
      (host.parentNode || b.parentNode).insertBefore(panel, (host.nextSibling));
      b.setAttribute("data-zr-faq", "1"); b._zrPanel = panel;
      b.style.cursor = "pointer";
    });
  }
  function toggleFAQ(b) {
    var p = b._zrPanel; if (!p) return;
    p.hidden = !p.hidden; b.classList.toggle("zr-open", !p.hidden);
  }

  /* ---------- TAB GROUPS (cabinet, product, checkout options, chips) ---------- */
  function siblingGroup(el) {
    var g = el.parentElement; if (!g) return null;
    var kids = [].slice.call(g.children).filter(function (c) {
      return (c.tagName === "BUTTON" || c.tagName === "A" || c.getAttribute("role") === "button") && norm(c.textContent).length < 60;
    });
    return kids.length >= 2 ? kids : null;
  }
  function activateInGroup(el, cls) {
    var kids = siblingGroup(el); if (!kids) return false;
    kids.forEach(function (k) { k.classList.remove(cls); });
    el.classList.add(cls);
    // if there are matching sibling panels, switch them
    var host = el.parentElement.parentElement;
    if (host) {
      var panels = [].slice.call(host.children).filter(function (c) { return c !== el.parentElement && c.nodeType === 1 && c.children.length; });
      if (panels.length === kids.length) {
        var idx = kids.indexOf(el);
        panels.forEach(function (p, i) { p.hidden = i !== idx; });
      }
    }
    return true;
  }

  /* ---------- GLOBAL CLICK DELEGATION ---------- */
  d.addEventListener("click", function (e) {
    var el = e.target.closest("button, a, [role='button']");
    if (!el) return;
    var txt = norm(el.textContent);

    // theme toggle disabled — site is always light
    if (isThemeBtn(el)) { e.preventDefault(); return; }
    if (/^(В корзину|Купить|Добавить в корзину|Заказать)$/i.test(txt)) { e.preventDefault(); addToCart(el); return; }
    if (txt === "+" || txt === "−" || txt === "-") { e.preventDefault(); stepQty(el, (txt === "+") ? 1 : -1); return; }
    if (el.getAttribute("data-zr-faq")) { e.preventDefault(); toggleFAQ(el); return; }
    // FAQ accordion (question contains ?) or filter group header (ends –/+) — only if it has a panel sibling
    if (el.tagName === "BUTTON" && (/\?/.test(txt) || /[–−+-]\s*$/.test(txt)) && txt.length < 220) {
      var _s = el.nextElementSibling;
      if (_s && norm(_s.textContent).length > 15) { e.preventDefault(); toggleCollapse(el); return; }
    }
    // collapsible filter group header (ends with +/-)
    if (/[–−+]\s*$/.test(txt) && txt.length < 40) { e.preventDefault(); toggleCollapse(el); return; }
    // grouped selectors: cabinet/product tabs, checkout options, category chips
    // (buttons only — never touch <a> nav/footer links)
    if (el.tagName === "BUTTON" && txt && txt !== "Каталог" && el.querySelectorAll("a,button,input").length === 0) {
      var kids = siblingGroup(el);
      if (kids) activateInGroup(el, "zr-tab-active");
    }
  }, false);

  /* ---------- INIT ---------- */
  function injectStateCSS() {
    if (d.getElementById("zr-state-css")) return;
    var st = d.createElement("style"); st.id = "zr-state-css";
    st.textContent =
      ".zr-chip-active{background:var(--accent)!important;color:var(--accent-ink)!important;border-color:var(--accent)!important}" +
      ".zr-tab-active{color:var(--accent)!important;border-bottom-color:var(--accent)!important}" +
      ".zr-selected{outline:2px solid var(--accent)!important;outline-offset:-2px}" +
      ".zr-badge{transition:.2s}" +
      /* mobile drawer */
      "#zr-drawer{position:fixed;inset:0;background:rgba(10,18,25,.55);z-index:100000;display:none}" +
      "#zr-drawer.on{display:block}" +
      "#zr-drawer .zr-dp{position:absolute;top:0;left:0;bottom:0;width:84%;max-width:330px;background:#fff;padding:58px 0 24px;box-shadow:2px 0 30px rgba(0,0,0,.25);overflow:auto;display:flex;flex-direction:column}" +
      "#zr-drawer .zr-dp a{padding:15px 26px;color:#101f2a;font:600 16px/1.25 Archivo,system-ui,sans-serif;text-decoration:none;border-bottom:1px solid rgba(10,20,28,.08)}" +
      "#zr-drawer .zr-dp a:active{background:#f5f6f2}" +
      "#zr-drawer .zr-dc{position:absolute;top:14px;right:16px;width:38px;height:38px;background:#f2f3f0;border:none;border-radius:10px;font-size:18px;color:#101f2a;cursor:pointer}" +
      ".zr-burger-extra{display:none}" +
      /* mobile layout: hide desktop menu row + theme toggle; tame overflow */
      "@media(max-width:860px){" +
      ".zr-mainnav{display:none!important}" +
      ".zr-burger-extra{display:inline-flex!important}" +
      "img{max-width:100%;height:auto}" +
      "}" +
      "@media(max-width:620px){" +
      ".zr-float-cta{display:none!important}" +
      "}";
    d.head.appendChild(st);
  }
  /* ----- Always-light theme (no dark option) ----- */
  function forceLight() {
    root.setAttribute("data-theme", "light");
    try { localStorage.setItem("zr_theme", "light"); } catch (e) {}
    d.querySelectorAll("button").forEach(function (b) {
      var t = norm(b.textContent);
      if (t.length < 16 && /(Светл|Тёмн|Темн)/.test(t)) { b.style.display = "none"; b.setAttribute("aria-hidden", "true"); }
    });
  }
  /* ----- Mobile navigation drawer ----- */
  var MENU = [["Каталог", "catalog.html"], ["Подбор", "podbor.html"], ["Цены", "calculator.html"], ["Производителям", "about.html"], ["Импортозамещение", "blog.html"], ["Блог", "blog.html"], ["О заводе", "about.html"], ["Контакты", "contacts.html"]];
  function setupMobileNav() {
    // tag the desktop main-menu row (parent of the "Подбор" nav link)
    var items = [].slice.call(d.querySelectorAll("a")).filter(function (a) { return norm(a.textContent) === "Подбор" && a.querySelectorAll("*").length < 2; });
    if (items[0] && items[0].parentElement) items[0].parentElement.classList.add("zr-mainnav");
    // build drawer once
    if (!d.getElementById("zr-drawer")) {
      var dr = d.createElement("div"); dr.id = "zr-drawer";
      var html = '<div class="zr-dp"><button class="zr-dc" aria-label="Закрыть">✕</button>';
      MENU.forEach(function (m) { html += '<a href="' + m[1] + '">' + m[0] + "</a>"; });
      html += '<a href="cart.html">Корзина</a><a href="cabinet.html">Личный кабинет</a></div>';
      dr.innerHTML = html;
      d.body.appendChild(dr);
      dr.addEventListener("click", function (e) { if (e.target === dr || e.target.closest(".zr-dc")) dr.classList.remove("on"); });
    }
    // wire the burger ("≡ Каталог" button) to open the drawer on mobile
    var burger = [].slice.call(d.querySelectorAll("button")).filter(function (b) { return /^Каталог$/.test(norm(b.textContent)); })[0];
    if (burger && !burger._zrWired) {
      burger._zrWired = 1;
      burger.addEventListener("click", function (e) {
        if (window.innerWidth <= 860) { e.preventDefault(); e.stopImmediatePropagation(); d.getElementById("zr-drawer").classList.add("on"); }
      }, true);
    }
  }
  function collapseFAQ() {
    // FAQ questions start collapsed (answer is the next sibling of the question button)
    d.querySelectorAll("button").forEach(function (b) {
      var t = norm(b.textContent);
      if (!/\?/.test(t) || t.length > 220) return;
      var s = b.nextElementSibling;
      if (s && norm(s.textContent).length > 15 && s.querySelectorAll("button,a,input").length === 0) {
        s.hidden = true; b.classList.remove("zr-open");
      }
    });
  }
  function tagFloatCTA() {
    d.querySelectorAll("button, a").forEach(function (e) {
      if (!/Подбор за 15/.test(norm(e.textContent))) return;
      var n = e;
      for (var i = 0; i < 4 && n; i++) { if (getComputedStyle(n).position === "fixed") { n.classList.add("zr-float-cta"); return; } n = n.parentElement; }
      e.classList.add("zr-float-cta");
    });
  }
  function repointContacts() {
    // отдельная страница «Контакты» — уводим ссылки с about.html на contacts.html
    [].forEach.call(d.querySelectorAll("a"), function (a) {
      if (norm(a.textContent) === "Контакты") a.setAttribute("href", "contacts.html");
    });
  }
  function _esc(s){return String(s==null?"":s).replace(/[<>&"]/g,function(c){return {"<":"&lt;",">":"&gt;","&":"&amp;",'"':"&quot;"}[c];});}
  function filterCatalog(q, inp){
    q=norm(q).toLowerCase(); if(!q) return;
    if(inp) inp.value=q;
    var addBtns=[].slice.call(d.querySelectorAll("button,a")).filter(function(b){return /^(В корзину|Купить|Заказать|Добавить)/.test(norm(b.textContent));});
    var cards=[];
    addBtns.forEach(function(b){ var c=b;
      for(var h=0;h<9 && c && c.parentElement;h++){
        var sib=[].slice.call(c.parentElement.children).filter(function(ch){return /В корзину|Купить|Заказать|Добавить/.test(ch.textContent||"");}).length;
        if(sib>1){ if(cards.indexOf(c)<0)cards.push(c); break; }
        c=c.parentElement;
      }
    });
    if(!cards.length) return;
    var shown=0;
    cards.forEach(function(card){ var m=norm(card.textContent).toLowerCase().indexOf(q)>=0; card.style.display=m?"":"none"; if(m)shown++; });
    var grid=cards[0].parentElement;
    var b=d.getElementById("zr-search-banner");
    if(!b){ b=d.createElement("div"); b.id="zr-search-banner"; grid.parentElement.insertBefore(b,grid); }
    if(shown>0) b.innerHTML='<span>По запросу <b>«'+_esc(q)+'»</b> найдено: '+shown+'</span><a href="catalog.html">Сбросить ✕</a>';
    else b.innerHTML='<span>По запросу <b>«'+_esc(q)+'»</b> ничего не найдено. Уточните название или <a href="podbor.html" style="color:#e11b1b">подберите по параметрам</a>.</span><a href="catalog.html">Сбросить ✕</a>';
  }
  function setupSearch(){
    var inputs=[].slice.call(d.querySelectorAll("input")).filter(function(i){return /[Пп]оиск/.test(i.getAttribute("placeholder")||"");});
    var INDEX=[
      ["Червячные редукторы","тип","catalog.html?q="+encodeURIComponent("червяч")],
      ["Цилиндрические редукторы","тип","catalog.html?q="+encodeURIComponent("цилиндрическ")],
      ["Соосно-цилиндрические","тип","catalog.html?q="+encodeURIComponent("соосно")],
      ["Коническо-цилиндрические","тип","catalog.html?q="+encodeURIComponent("коническо")],
      ["Планетарные редукторы","тип","catalog.html?q="+encodeURIComponent("планетар")],
      ["Мотор-редукторы","тип","catalog.html?q="+encodeURIComponent("мотор")],
      ["Вариаторы","тип","catalog.html?q="+encodeURIComponent("вариатор")],
      ["EVL 197 — аналог SEW R107","товар","product.html"],
      ["Аналоги SEW","бренд","catalog.html?q=SEW"],
      ["Аналоги NORD","бренд","catalog.html?q=NORD"],
      ["Аналоги Bonfiglioli","бренд","catalog.html?q=Bonfiglioli"],
      ["Аналоги Motovario","бренд","catalog.html?q=Motovario"],
      ["Подбор по параметрам","калькулятор","podbor.html"],
      ["Подбор по фото шильдика","сервис","shildik.html"],
      ["Калькулятор цены","цены","calculator.html"]
    ];
    if(!d.getElementById("zr-search-css")){
      var st=d.createElement("style"); st.id="zr-search-css";
      st.textContent="#zr-sug{position:fixed;z-index:100001;background:#fff;border:1px solid rgba(12,20,28,.12);border-radius:14px;box-shadow:0 18px 44px rgba(10,18,25,.18);overflow:hidden;display:none}"
        +"#zr-sug.on{display:block}"
        +"#zr-sug a{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 16px;color:#0f1c26;text-decoration:none;font:600 14.5px 'Archivo',sans-serif;border-bottom:1px solid rgba(12,20,28,.06)}"
        +"#zr-sug a:last-child{border-bottom:0}#zr-sug a:hover{background:#f5f7f4}"
        +"#zr-sug a .tp{color:#8a97a1;font-weight:500;font-size:11px;text-transform:uppercase;letter-spacing:.03em;white-space:nowrap}"
        +"#zr-sug a.go{color:#e11b1b}"
        +"#zr-search-banner{max-width:1240px;margin:0 auto 6px;padding:12px 20px;display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;font:600 14.5px 'Archivo',sans-serif;color:#3f4b55}"
        +"#zr-search-banner b{color:#0f1c26}#zr-search-banner a{color:#5a6a75;font-weight:700;text-decoration:none}#zr-search-banner a:hover{color:#e11b1b}";
      d.head.appendChild(st);
    }
    var sug=d.getElementById("zr-sug");
    if(!sug){ sug=d.createElement("div"); sug.id="zr-sug"; d.body.appendChild(sug); }
    var active=null;
    function place(inp){ var r=inp.getBoundingClientRect(); sug.style.left=r.left+"px"; sug.style.top=(r.bottom+6)+"px"; sug.style.width=Math.max(260,Math.min(r.width,520))+"px"; }
    function render(inp){
      var q=norm(inp.value).toLowerCase(), html="";
      if(q.length>=1){
        INDEX.filter(function(x){return x[0].toLowerCase().indexOf(q)>=0||x[1].indexOf(q)>=0;}).slice(0,6).forEach(function(x){
          html+='<a href="'+x[2]+'"><span>'+x[0]+'</span><span class="tp">'+x[1]+'</span></a>';
        });
        html+='<a class="go" href="catalog.html?q='+encodeURIComponent(inp.value)+'"><span>Искать «'+_esc(norm(inp.value))+'» в каталоге</span><span class="tp">enter</span></a>';
      }
      sug.innerHTML=html;
      if(html){ place(inp); sug.classList.add("on"); } else sug.classList.remove("on");
    }
    inputs.forEach(function(inp){
      inp.setAttribute("autocomplete","off");
      inp.addEventListener("input",function(){ active=inp; render(inp); });
      inp.addEventListener("focus",function(){ active=inp; if(norm(inp.value))render(inp); });
      inp.addEventListener("keydown",function(e){
        if(e.key==="Enter"){ e.preventDefault(); var v=norm(inp.value); if(v)location.href="catalog.html?q="+encodeURIComponent(v); }
        else if(e.key==="Escape"){ sug.classList.remove("on"); }
      });
      inp.addEventListener("blur",function(){ setTimeout(function(){ sug.classList.remove("on"); },180); });
    });
    window.addEventListener("scroll",function(){ if(active&&sug.classList.contains("on"))place(active); },true);
    var m=location.search.match(/[?&]q=([^&]+)/);
    if(m && /catalog/.test(location.pathname)){ filterCatalog(decodeURIComponent(m[1].replace(/\+/g," ")), inputs[0]); }
  }
  function wireShield() {
    [].forEach.call(d.querySelectorAll("button,span,div,a"), function (el) {
      if (norm(el.textContent) === "по шильду" && el.childElementCount === 0 && !el._zrS) {
        el._zrS = 1; el.style.cursor = "pointer"; el.title = "Подбор по фото шильдика";
        el.addEventListener("click", function (ev) { ev.preventDefault(); ev.stopPropagation(); location.href = "shildik.html"; }, true);
      }
    });
  }
  function init() {
    injectStateCSS();
    try { window.zrRefreshBadge = updateBadge; } catch (e) {}
    try { forceLight(); } catch (e) {}
    try { repointContacts(); } catch (e) {}
    try { setupSearch(); } catch (e) {}
    try { wireShield(); } catch (e) {}
    updateBadge();
    wireSliders();
    try { setupFAQ(); } catch (e) {}
    try { collapseFAQ(); } catch (e) {}
    try { setupMobileNav(); } catch (e) {}
    try { tagFloatCTA(); } catch (e) {}
  }
  if (d.readyState === "loading") d.addEventListener("DOMContentLoaded", init); else init();
})();
