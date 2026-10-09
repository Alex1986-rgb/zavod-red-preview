// Проверка вёрстки списка страниц (обычно — живой zavod-red.ru): только чтение.
// Для каждой страницы на 390 и 1440 px: HTTP-статус, горизонтальный вылет и кто вылезает,
// битые картинки, неподгрузившиеся CSS/JS/шрифты/картинки своего домена, ошибки JS,
// нулевые стили. Для страниц из списка shots — скриншоты всей страницы (jpeg).
// Запуск: node check.mjs <outDir> <baseUrl> <pages.txt> [shots.txt]
import { chromium } from 'playwright';
import fs from 'fs';

const [OUT, BASE, LIST, SHOTS] = process.argv.slice(2);
const base = BASE.replace(/\/$/, '');
const host = new URL(base).host;
const pages = fs.readFileSync(LIST, 'utf8').split('\n').map(s => s.trim()).filter(s => s && !s.startsWith('#'));
const shots = new Set(SHOTS && fs.existsSync(SHOTS) ? fs.readFileSync(SHOTS, 'utf8').split('\n').map(s => s.trim()).filter(Boolean) : []);
fs.mkdirSync(OUT + '/img', { recursive: true });
const slug = p => p.replace(/\/index\.html$/, '').replace(/\.html$/, '').replace(/[^A-Za-z0-9_-]/g, '_') || 'root';
const url = p => base + '/' + p.replace(/^\//, '').replace(/(^|\/)index\.html$/, '$1').replace(/\.html$/, '');

const b = await chromium.launch({ args: ['--no-sandbox'], ...(process.env.PW_CHROME ? { executablePath: process.env.PW_CHROME } : {}) });
const VPS = [[390, 'mobile'], [1440, 'desktop']];
const results = [];

async function checkOne(p, w, tag) {
  const ctx = await b.newContext({ viewport: { width: w, height: 900 }, deviceScaleFactor: 1,
    userAgent: w < 500 ? 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1' : undefined,
    isMobile: w < 500, hasTouch: w < 500 });
  await ctx.addInitScript(() => { try { localStorage.setItem('zr_cookie_ok', '1'); } catch (e) {} });
  // Счётчики и чужие виджеты не нужны для вёрстки и тормозят прогон.
  await ctx.route(/mc\.yandex|yandex\.ru\/metrika|googletagmanager|google-analytics|vk\.com\/rtrg|top-fwz1/, r => r.abort());
  const pg = await ctx.newPage();
  const r = { page: p, vp: tag, status: 0, failed: [], jsErrors: [], overflow: null, offenders: [], brokenImgs: [], noCss: false, title: '' };
  pg.on('pageerror', e => r.jsErrors.push(String(e.message).slice(0, 160)));
  pg.on('console', m => { if (m.type() === 'error' && !/favicon|metrika|yandex/i.test(m.text())) r.jsErrors.push('console: ' + m.text().slice(0, 160)); });
  pg.on('response', res => { try { const u = new URL(res.url()); if (u.host === host && res.status() >= 400 && res.request().resourceType() !== 'document') r.failed.push(res.status() + ' ' + u.pathname); } catch (e) {} });
  pg.on('requestfailed', rq => { try { const u = new URL(rq.url()); if (u.host === host) r.failed.push('FAIL ' + u.pathname + ' ' + (rq.failure()?.errorText || '')); } catch (e) {} });
  try {
    const resp = await pg.goto(url(p), { waitUntil: 'domcontentloaded', timeout: 60000 });
    r.status = resp ? resp.status() : 0;
    await pg.evaluate(async () => { for (let y = 0; y < document.body.scrollHeight; y += 800) { scrollTo(0, y); await new Promise(r => setTimeout(r, 30)); } scrollTo(0, 0); });
    await pg.waitForTimeout(1200);
    Object.assign(r, await pg.evaluate(() => {
      const W = innerWidth;
      const docW = document.documentElement.scrollWidth;
      // Вылезающие элементы, которые реально видны и не сидят в контейнере с прокруткой/обрезкой.
      const clipped = e => { for (let a = e.parentElement; a && a !== document.body; a = a.parentElement) { const o = getComputedStyle(a); if (/(auto|scroll|hidden|clip)/.test(o.overflowX)) return true; } return false; };
      const off = [];
      for (const e of document.querySelectorAll('body *')) {
        const rc = e.getBoundingClientRect(); const st = getComputedStyle(e);
        if (rc.width < 8 || rc.height < 4 || st.visibility === 'hidden' || st.display === 'none' || st.position === 'fixed') continue;
        if (rc.right > W + 2 || rc.left < -2) { if (clipped(e)) continue; off.push(e.tagName.toLowerCase() + (e.id ? '#' + e.id : '') + (e.className && typeof e.className === 'string' ? '.' + e.className.trim().split(/\s+/).slice(0, 2).join('.') : '') + ' [' + Math.round(rc.left) + '…' + Math.round(rc.right) + ']'); }
      }
      // Оставляем самых «внешних»: дочерние вылезающих не интересны.
      const broken = [...document.images].filter(i => i.complete && i.naturalWidth === 0 && (i.currentSrc || i.src) && getComputedStyle(i).display !== 'none' && i.getBoundingClientRect().width > 0).map(i => (i.currentSrc || i.src).replace(location.origin, '')).slice(0, 10);
      return { title: document.title.slice(0, 80), overflow: docW > W + 2 ? docW : null, offenders: off.slice(0, 8), brokenImgs: broken, noCss: document.styleSheets.length === 0, h1: document.querySelectorAll('h1').length };
    }));
    if (shots.has(p)) {
      const h = await pg.evaluate(() => document.documentElement.scrollHeight);
      await pg.setViewportSize({ width: w, height: Math.min(h, 12000) });
      await pg.waitForTimeout(400);
      await pg.screenshot({ path: `${OUT}/img/${slug(p)}_${tag}.jpg`, type: 'jpeg', quality: 55, fullPage: false });
    }
  } catch (e) { r.error = String(e.message).slice(0, 200); }
  r.failed = [...new Set(r.failed)].slice(0, 12); r.jsErrors = [...new Set(r.jsErrors)].slice(0, 6);
  await ctx.close();
  return r;
}

const jobs = pages.flatMap(p => VPS.map(([w, t]) => [p, w, t]));
let i = 0;
async function worker() { while (i < jobs.length) { const [p, w, t] = jobs[i++]; results.push(await checkOne(p, w, t)); if (results.length % 40 === 0) console.log('…', results.length, '/', jobs.length); } }
await Promise.all(Array.from({ length: 6 }, worker));
await b.close();

results.sort((a, c) => a.page.localeCompare(c.page) || a.vp.localeCompare(c.vp));
fs.writeFileSync(OUT + '/results.json', JSON.stringify(results, null, 1));
const L = [];
const sec = (t, f) => { const rs = results.filter(f); L.push(`\n## ${t}: ${rs.length}`); return rs; };
for (const r of sec('Ошибка загрузки / статус ≠ 200', r => r.error || r.status !== 200)) L.push(`${r.page} [${r.vp}] status=${r.status} ${r.error || ''}`);
for (const r of sec('Горизонтальный вылет страницы', r => r.overflow)) L.push(`${r.page} [${r.vp}] ширина ${r.overflow}: ${r.offenders.slice(0, 4).join(' | ')}`);
for (const r of sec('Вылезающие элементы без вылета страницы', r => !r.overflow && r.offenders.length)) L.push(`${r.page} [${r.vp}]: ${r.offenders.slice(0, 3).join(' | ')}`);
for (const r of sec('Битые картинки', r => r.brokenImgs.length)) L.push(`${r.page} [${r.vp}]: ${r.brokenImgs.slice(0, 4).join(' ')}`);
for (const r of sec('Не загрузились ресурсы своего домена', r => r.failed.length)) L.push(`${r.page} [${r.vp}]: ${r.failed.slice(0, 5).join(' | ')}`);
for (const r of sec('Ошибки JS', r => r.jsErrors.length)) L.push(`${r.page} [${r.vp}]: ${r.jsErrors.slice(0, 3).join(' | ')}`);
for (const r of sec('Нет стилей', r => r.noCss)) L.push(`${r.page} [${r.vp}]`);
for (const r of sec('Нет или несколько H1', r => r.vp === 'desktop' && r.h1 !== 1 && !r.error)) L.push(`${r.page}: h1=${r.h1}`);
fs.writeFileSync(OUT + '/summary.txt', `Проверено: ${pages.length} страниц × 2 ширины, ${base}\n` + L.join('\n') + '\n');
console.log(fs.readFileSync(OUT + '/summary.txt', 'utf8').slice(0, 6000));
