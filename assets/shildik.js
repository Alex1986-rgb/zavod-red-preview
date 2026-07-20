/* Подбор редуктора по фото шильдика (таблички).
   Загрузка/съёмка фото → OCR (Tesseract.js с CDN) → распознаём марку импортного привода
   → матчим по базе assets/podbor-data.json (аналоги SEW/NORD/Motovario/… → наш EVL)
   → показываем аналоги-редукторы ЗР. Есть ручной ввод и отправка инженеру.
   Монтируется в #shildikRoot. Самодостаточный (свой CSS). */
(function () {
  "use strict";
  var d = document;
  var TESS_CDN = "https://cdn.jsdelivr.net/npm/tesseract.js@5.1.1/dist/tesseract.min.js";
  var DATA_URL = "/assets/podbor-data.json";
  var FEEDBACK = "/api/feedback.php";
  var root, DATA = null, INDEX = null, curImageDataUrl = null, ocrText = "";

  /* ---------- css ---------- */
  function css() {
    if (d.getElementById("shd-css")) return;
    var s = d.createElement("style"); s.id = "shd-css";
    s.textContent = [
      "#shildikRoot{--r:#e11b1b;--rh:#cf1616;--ink:#0f1c26;--mut:#5a6a75;--line:rgba(12,20,28,.12);--bg:#f3f6f8}",
      ".shd-wrap{max-width:1060px;margin:0 auto;padding:8px 20px 40px}",
      ".shd-hero{text-align:center;margin:6px 0 22px}.shd-hero .eb{display:inline-block;font:700 12px/1 'Archivo',sans-serif;letter-spacing:.08em;text-transform:uppercase;color:var(--r);background:rgba(225,27,27,.09);padding:7px 14px;border-radius:999px}",
      ".shd-hero h1{font:700 clamp(24px,3.6vw,36px)/1.1 'Space Grotesk',sans-serif;color:var(--ink);margin:14px 0 8px}",
      ".shd-hero p{color:var(--mut);font-size:15px;max-width:620px;margin:0 auto;line-height:1.55}",
      ".shd-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;align-items:start}",
      ".shd-card{background:#fff;border:1px solid var(--line);border-radius:18px;padding:22px 24px}",
      ".shd-card h2{font:700 17px 'Space Grotesk',sans-serif;color:var(--ink);margin:0 0 4px}.shd-card .cap{color:#8a97a1;font-size:13px;margin:0 0 16px}",
      ".shd-drop{border:2px dashed rgba(225,27,27,.35);border-radius:16px;background:linear-gradient(180deg,#fff, #fdf5f5);padding:30px 20px;text-align:center;cursor:pointer;transition:.15s}",
      ".shd-drop:hover,.shd-drop.drag{border-color:var(--r);background:#fdeeee}",
      ".shd-drop .ic{font-size:40px}.shd-drop .t{font:700 16px 'Archivo',sans-serif;color:var(--ink);margin:8px 0 4px}.shd-drop .s{color:#8a97a1;font-size:13px}",
      ".shd-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}",
      ".shd-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:12px 20px;border-radius:12px;background:var(--r);color:#fff;font:700 14.5px 'Archivo',sans-serif;border:0;cursor:pointer;transition:.15s;text-decoration:none}.shd-btn:hover{background:var(--rh)}.shd-btn:disabled{opacity:.55;cursor:default}",
      ".shd-btn.gh{background:#fff;color:var(--ink);border:1px solid var(--line)}.shd-btn.gh:hover{border-color:var(--r);color:var(--r)}",
      ".shd-btn.wide{width:100%}",
      ".shd-preview{margin-top:14px;border-radius:14px;overflow:hidden;border:1px solid var(--line);position:relative;background:#0f1c26}",
      ".shd-preview img{width:100%;display:block;max-height:300px;object-fit:contain}",
      ".shd-preview .rm{position:absolute;top:8px;right:8px;width:32px;height:32px;border-radius:50%;border:0;background:rgba(15,28,38,.7);color:#fff;font-size:16px;cursor:pointer}",
      ".shd-prog{margin-top:14px;display:none}.shd-prog.on{display:block}",
      ".shd-prog .bar{height:8px;background:#eef2f6;border-radius:5px;overflow:hidden}.shd-prog .bar span{display:block;height:100%;width:0;background:linear-gradient(90deg,#ff4b3c,var(--r));transition:width .2s}",
      ".shd-prog .lbl{font-size:12.5px;color:var(--mut);margin-top:7px;text-align:center}",
      ".shd-field{margin-top:14px}.shd-field label{display:block;font-size:13px;font-weight:600;color:var(--mut);margin-bottom:6px}",
      ".shd-field input,.shd-field textarea{width:100%;box-sizing:border-box;background:#f5f7f9;border:1px solid var(--line);border-radius:11px;padding:12px 14px;font:500 15px 'Archivo',sans-serif;color:var(--ink)}",
      ".shd-field textarea{min-height:70px;resize:vertical}.shd-field input:focus,.shd-field textarea:focus{outline:none;border-color:var(--r);background:#fff;box-shadow:0 0 0 3px rgba(225,27,27,.12)}",
      ".shd-hint{font-size:12px;color:#8a97a1;margin-top:6px}",
      ".shd-res{margin-top:6px}",
      ".shd-empty{text-align:center;color:#8a97a1;font-size:14px;padding:24px 10px}",
      ".shd-item{display:flex;align-items:center;gap:14px;border:1px solid var(--line);border-radius:14px;padding:14px 16px;margin-bottom:11px;background:#fff;transition:.15s}.shd-item:hover{border-color:var(--r);box-shadow:0 8px 22px rgba(225,27,27,.08)}",
      ".shd-item .rank{width:30px;height:30px;border-radius:9px;background:rgba(225,27,27,.1);color:var(--r);font:800 14px 'Space Grotesk',sans-serif;display:flex;align-items:center;justify-content:center;flex:none}",
      ".shd-item .evl{font:700 16px 'Space Grotesk',sans-serif;color:var(--ink)}.shd-item .meta{color:#8a97a1;font-size:12.5px;margin-top:2px}.shd-item .match{color:#12915f;font-weight:600;font-size:12.5px}",
      ".shd-item .go{margin-left:auto;display:flex;gap:8px;flex:none}",
      ".shd-note{background:#fff8e6;border:1px solid #f0e0a8;color:#8a6d10;border-radius:12px;padding:12px 15px;font-size:13.5px;margin-top:12px}",
      "@media(max-width:820px){.shd-grid{grid-template-columns:1fr}}"
    ].join("");
    d.head.appendChild(s);
  }

  /* ---------- utils ---------- */
  function norm(s) { return String(s || "").toUpperCase().replace(/[Ёё]/g, "Е").replace(/[^A-ZА-Я0-9]/g, ""); }
  function esc(s) { return String(s == null ? "" : s).replace(/[<>&"]/g, function (c) { return { "<": "&lt;", ">": "&gt;", "&": "&amp;", '"': "&quot;" }[c]; }); }

  /* ---------- data + index ---------- */
  function loadData() {
    if (DATA) return Promise.resolve(DATA);
    return fetch(DATA_URL, { cache: "force-cache" }).then(function (r) { return r.json(); }).then(function (j) {
      DATA = j; buildIndex(); return j;
    });
  }
  function buildIndex() {
    INDEX = []; var types = DATA.t || [];
    var g = DATA.g || {};
    Object.keys(g).forEach(function (gid) {
      var it = g[gid]; if (!it) return;
      var evl = it.e || "", pr = it.p || "", type = types[it.t] || "";
      // прямые коды
      if (evl) INDEX.push({ gid: gid, evl: evl, type: type, brand: "EVL", marking: evl, key: norm(evl), w: 3 });
      if (pr) INDEX.push({ gid: gid, evl: evl, type: type, brand: "ПР", marking: pr, key: norm(pr), w: 3 });
      // аналоги импортных марок
      if (it.a) Object.keys(it.a).forEach(function (brand) {
        (it.a[brand] || []).forEach(function (m) {
          var k = norm(m); if (k.length < 3) return;
          INDEX.push({ gid: gid, evl: evl, type: type, brand: brand, marking: m, key: k, bkey: norm(brand), w: 4 });
        });
      });
    });
  }

  /* ---------- matching ---------- */
  function search(text) {
    if (!INDEX) return [];
    var T = norm(text); if (T.length < 3) return [];
    var scores = {};
    INDEX.forEach(function (e) {
      if (e.key.length < 3) return;
      var hit = 0;
      if (T.indexOf(e.key) >= 0) hit = e.w;                      // марка целиком внутри распознанного текста
      if (!hit) return;
      // бонус, если рядом бренд
      if (e.bkey && e.bkey.length >= 3 && T.indexOf(e.bkey) >= 0) hit += 2;
      // длинные марки надёжнее
      if (e.key.length >= 6) hit += 1;
      var s = scores[e.gid] || (scores[e.gid] = { gid: e.gid, evl: e.evl, type: e.type, score: 0, via: [] });
      s.score += hit;
      var label = (e.brand === "EVL" || e.brand === "ПР") ? (e.brand + " " + e.marking) : (e.brand + " " + e.marking);
      if (s.via.indexOf(label) < 0 && s.via.length < 3) s.via.push(label);
    });
    return Object.keys(scores).map(function (k) { return scores[k]; })
      .sort(function (a, b) { return b.score - a.score; }).slice(0, 6);
  }

  /* ---------- OCR ---------- */
  function loadTesseract() {
    if (window.Tesseract) return Promise.resolve(window.Tesseract);
    return new Promise(function (res, rej) {
      var sc = d.createElement("script"); sc.src = TESS_CDN;
      sc.onload = function () { window.Tesseract ? res(window.Tesseract) : rej(new Error("no Tesseract")); };
      sc.onerror = function () { rej(new Error("CDN недоступен")); };
      d.head.appendChild(sc);
    });
  }
  function setProgress(on, pct, label) {
    var p = root.querySelector("#shd-prog"); if (!p) return;
    p.classList.toggle("on", !!on);
    p.querySelector(".bar span").style.width = Math.max(3, Math.round((pct || 0) * 100)) + "%";
    p.querySelector(".lbl").textContent = label || "";
  }
  function runOCR() {
    if (!curImageDataUrl) return;
    var btn = root.querySelector("#shd-recognize"); if (btn) btn.disabled = true;
    setProgress(true, 0.05, "Загружаем распознавание…");
    loadTesseract().then(function (T) {
      setProgress(true, 0.15, "Читаем текст на фото…");
      return T.recognize(curImageDataUrl, "eng", {
        logger: function (m) { if (m.status === "recognizing text") setProgress(true, 0.2 + m.progress * 0.8, "Распознаём: " + Math.round(m.progress * 100) + "%"); }
      });
    }).then(function (out) {
      setProgress(false, 1, "");
      ocrText = (out && out.data && out.data.text) || "";
      var inp = root.querySelector("#shd-text"); if (inp) inp.value = ocrText.replace(/\s+/g, " ").trim();
      doSearch();
      if (btn) btn.disabled = false;
    }).catch(function (e) {
      setProgress(false, 0, "");
      if (btn) btn.disabled = false;
      renderNote("Не удалось распознать автоматически (" + (e.message || "ошибка") + "). Впишите маркировку вручную ниже — поиск работает и по тексту.");
    });
  }

  /* ---------- render ---------- */
  function renderNote(msg) { var el = root.querySelector("#shd-note"); if (el) el.innerHTML = msg ? '<div class="shd-note">' + esc(msg) + '</div>' : ""; }
  function doSearch() {
    var inp = root.querySelector("#shd-text"); var text = inp ? inp.value : "";
    var res = search(text);
    var host = root.querySelector("#shd-results");
    if (!text || text.length < 3) { host.innerHTML = '<div class="shd-empty">Загрузите фото шильдика или впишите маркировку — покажем подходящие редукторы ЗР.</div>'; return; }
    if (!res.length) { host.innerHTML = '<div class="shd-empty">По «' + esc(text.slice(0, 40)) + '» точных аналогов не нашли. Отправьте фото инженеру — подберём вручную за 15 минут.</div>'; return; }
    host.innerHTML = res.map(function (r, i) {
      var q = encodeURIComponent(r.evl || "");
      return '<div class="shd-item"><span class="rank">' + (i + 1) + '</span>' +
        '<div><div class="evl">' + esc(r.evl || "Редуктор ЗР") + '</div>' +
        '<div class="meta">' + esc(r.type || "") + '</div>' +
        (r.via.length ? '<div class="match">✓ по вашему шильду: ' + esc(r.via.join(", ")) + '</div>' : "") + '</div>' +
        '<div class="go"><a class="shd-btn sm" href="/podbor?q=' + q + '">Подобрать</a>' +
        '<a class="shd-btn gh sm" href="/catalog/">Каталог</a></div></div>';
    }).join("");
  }

  function view() {
    css();
    root.innerHTML =
      '<div class="shd-wrap">' +
      '<div class="shd-hero"><span class="eb">Подбор по фото</span><h1>Определим редуктор по шильдику</h1>' +
      '<p>Сфотографируйте табличку (шильдик) импортного редуктора — распознаем маркировку прямо в браузере и подберём наш аналог EVL. Данные с фото никуда не отправляются.</p></div>' +
      '<div class="shd-grid">' +
        '<div class="shd-card"><h2>1. Фото шильдика</h2><p class="cap">Перетащите фото, выберите файл или снимите камерой.</p>' +
          '<div class="shd-drop" id="shd-drop"><div class="ic">📷</div><div class="t">Загрузить фото шильдика</div><div class="s">JPG/PNG · перетащите сюда или нажмите</div></div>' +
          '<input type="file" id="shd-file" accept="image/*" capture="environment" hidden>' +
          '<div id="shd-preview-wrap"></div>' +
          '<div id="shd-prog" class="shd-prog"><div class="bar"><span></span></div><div class="lbl"></div></div>' +
          '<div class="shd-actions"><button class="shd-btn wide" id="shd-recognize" disabled>Распознать и подобрать</button></div>' +
          '<div id="shd-note"></div>' +
        '</div>' +
        '<div class="shd-card"><h2>2. Маркировка</h2><p class="cap">Распознанный текст можно поправить. Или впишите марку вручную (напр. «SEW WA 20», «NORD SK», «NMRV 030»).</p>' +
          '<div class="shd-field"><label>Текст с шильдика / маркировка</label><textarea id="shd-text" placeholder="SEW WA 20 · NORD SK · Motovario NMRV 030 · EVL 197…"></textarea><div class="shd-hint">Поиск идёт по 20+ импортным маркам (SEW, NORD, Bonfiglioli, Motovario, STM, Siti, Bauer…).</div></div>' +
          // Имя и телефон обязательны: без них сервер отклоняет заявку, и раньше
          // ВСЕ обращения «Отправить инженеру» терялись, а человеку писали «принято».
          '<div class="shd-field"><label>Ваше имя</label><input type="text" id="shd-name" placeholder="Как к вам обращаться"></div>' +
          '<div class="shd-field"><label>Телефон для ответа</label><input type="tel" id="shd-phone" placeholder="+7 (___) ___-__-__"><div class="shd-hint">Инженер перезвонит и пришлёт подбор с ценой.</div></div>' +
          '<div class="shd-actions"><button class="shd-btn" id="shd-find">Найти аналог</button><button class="shd-btn gh" id="shd-send">Отправить инженеру</button></div>' +
        '</div>' +
      '</div>' +
      '<div class="shd-card" style="margin-top:18px"><h2>Подходящие редукторы ЗР</h2><div class="shd-res" id="shd-results"><div class="shd-empty">Загрузите фото шильдика или впишите маркировку — покажем подходящие редукторы ЗР.</div></div></div>' +
      '</div>';
    wire();
  }

  function handleFile(file) {
    if (!file || !/^image\//.test(file.type)) { renderNote("Выберите изображение (JPG или PNG)."); return; }
    var fr = new FileReader();
    fr.onload = function () {
      curImageDataUrl = fr.result;
      root.querySelector("#shd-preview-wrap").innerHTML = '<div class="shd-preview"><button class="rm" id="shd-rm" title="Убрать">✕</button><img src="' + curImageDataUrl + '" alt="Шильдик"></div>';
      root.querySelector("#shd-recognize").disabled = false;
      renderNote("");
      runOCR(); // сразу распознаём
    };
    fr.readAsDataURL(file);
  }

  function sendEngineer() {
    var text = (root.querySelector("#shd-text") || {}).value || "";
    var nm   = ((root.querySelector("#shd-name")  || {}).value || "").trim();
    var ph   = ((root.querySelector("#shd-phone") || {}).value || "").trim();
    if (text.trim().length < 2 && !curImageDataUrl) { renderNote("Впишите маркировку или загрузите фото."); return; }
    // Проверяем ДО отправки: сервер требует имя и телефон, иначе заявка отклоняется.
    if (nm.length < 2) { renderNote("Укажите имя — без него инженер не сможет ответить."); return; }
    // Считаем цифры, а не длину строки: так проходят и не-российские номера.
    if (ph.replace(/\D/g, "").length < 10) { renderNote("Укажите телефон — без него заявку принять нельзя."); return; }
    var fd = new FormData();
    fd.append("work_email", "");
    fd.append("text-562", nm);
    fd.append("tel-535", ph);
    fd.append("message", "Подбор по шильдику. Маркировка: " + text.slice(0, 500));
    try { fd.append("page_url", location.href); fd.append("page_title", document.title || "Подбор по шильдику"); } catch (e) {}
    fd.append("product_title", "Подбор по шильдику: " + text.slice(0, 300));
    // фото прикладываем как файл, если есть
    if (curImageDataUrl) {
      try { var arr = curImageDataUrl.split(","), mime = (arr[0].match(/:(.*?);/) || [])[1] || "image/jpeg", bin = atob(arr[1]), n = bin.length, u8 = new Uint8Array(n); while (n--) u8[n] = bin.charCodeAt(n); fd.append("file", new Blob([u8], { type: mime }), "shildik.jpg"); } catch (e) {}
    }
    var btn = root.querySelector("#shd-send"); if (btn) { btn.disabled = true; btn.textContent = "Отправляем…"; }
    fetch(FEEDBACK, { method: "POST", body: fd }).then(function (r) { return r.json().catch(function () { return {}; }); }).then(function (res) {
      // Честный результат: раньше при ЛЮБОМ отказе показывали «Заявка принята»,
      // и человек ждал звонка по заявке, которой не существует.
      var ok = res && (res.status === "success" || res.ok);
      renderNote(ok
        ? "Отправлено инженеру — подберём аналог и пришлём цену."
        : ((res && res.message) || "Не удалось отправить заявку.") + " Позвоните: +7 (495) 151-41-02.");
    }).catch(function () { renderNote("Не удалось отправить. Позвоните: +7 (495) 151-41-02."); })
      .then(function () { if (btn) { btn.disabled = false; btn.textContent = "Отправить инженеру"; } });
  }

  function wire() {
    var drop = root.querySelector("#shd-drop"), file = root.querySelector("#shd-file");
    drop.addEventListener("click", function () { file.click(); });
    file.addEventListener("change", function () { if (file.files && file.files[0]) handleFile(file.files[0]); });
    ["dragenter", "dragover"].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add("drag"); }); });
    ["dragleave", "drop"].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove("drag"); }); });
    drop.addEventListener("drop", function (e) { var f = e.dataTransfer && e.dataTransfer.files[0]; if (f) handleFile(f); });
    root.addEventListener("click", function (e) {
      if (e.target.closest("#shd-recognize")) return runOCR();
      if (e.target.closest("#shd-find")) return doSearch();
      if (e.target.closest("#shd-send")) return sendEngineer();
      if (e.target.closest("#shd-rm")) { curImageDataUrl = null; root.querySelector("#shd-preview-wrap").innerHTML = ""; root.querySelector("#shd-recognize").disabled = true; return; }
    });
    var ta = root.querySelector("#shd-text");
    var deb; ta.addEventListener("input", function () { clearTimeout(deb); deb = setTimeout(doSearch, 350); });
    // предзагрузка базы
    loadData().catch(function () {});
    // префилл маркировки из URL (?q=)
    try { var q = new URLSearchParams(location.search).get("q"); if (q) { ta.value = q; loadData().then(doSearch); } } catch (e) {}
  }

  function mount() { root = d.getElementById("shildikRoot"); if (!root) return false; view(); return true; }
  var n = 0; (function loop() { if (mount()) return; if (n++ < 40) setTimeout(loop, 80); })();
})();
