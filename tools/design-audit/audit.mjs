// Дизайн-аудит страницы: скриншоты по секциям (desktop/mobile), полная страница, замеры DOM.
// Запуск: node audit.mjs <outDir> <baseUrl> <relative-url>
// Используется воркфлоу .github/workflows/design-audit.yml (рендер на раннере GitHub).
import { chromium } from 'playwright';
import fs from 'fs';

const OUT = process.argv[2];
const U = process.argv[3].replace(/\/$/, '') + '/' + process.argv[4].replace(/^\//, '');
fs.mkdirSync(OUT, { recursive: true });
const sleep = ms => new Promise(r => setTimeout(r, ms));
const b = await chromium.launch({ args: ['--no-sandbox'] });
const metrics = {};

for (const [w, tag] of [[1440, 'desktop'], [1024, 'tablet'], [390, 'mobile']]) {
  const ctx = await b.newContext({ viewport: { width: w, height: 900 }, deviceScaleFactor: 1 });
  await ctx.addInitScript(() => { try { localStorage.setItem('zr_cookie_ok', '1'); } catch (e) {} });
  const p = await ctx.newPage();
  await p.goto(U, { waitUntil: 'domcontentloaded', timeout: 90000 });
  await p.evaluate(async () => { for (let y = 0; y < document.body.scrollHeight; y += 700) { scrollTo(0, y); await new Promise(r => setTimeout(r, 40)); } scrollTo(0, 0); });
  await p.addStyleTag({ content: '[class*=cookie],#zrCookie,.zr-cookie,.zr-chat,[class*=chat-widget]{display:none!important}' });
  await sleep(1500);

  metrics[tag] = await p.evaluate(() => {
    const cs = e => getComputedStyle(e); const n = v => Math.round(parseFloat(v));
    const secs = [...document.querySelectorAll('section, header, footer')].map(s => {
      const r = s.getBoundingClientRect(); const st = cs(s);
      const h2 = s.querySelector('h2'); const eb = s.querySelector('.eyebrow'); const lead = s.querySelector('.lead');
      const first = [...s.querySelectorAll('.wrap > *')].filter(e => !['eyebrow', 'lead'].some(c => e.classList.contains(c)) && e.tagName !== 'H2')[0];
      return { tag: s.tagName.toLowerCase(), id: s.id || '', cls: (s.className || '').toString().slice(0, 40), top: n(r.top + scrollY), h: n(r.height),
        padT: n(st.paddingTop), padB: n(st.paddingBottom), bg: st.backgroundColor,
        h2: h2 ? { fs: n(cs(h2).fontSize), lh: cs(h2).lineHeight, mb: n(cs(h2).marginBottom), text: h2.innerText.slice(0, 40) } : null,
        eyebrow: !!eb, lead: lead ? { fs: n(cs(lead).fontSize), mb: n(cs(lead).marginBottom), w: n(lead.getBoundingClientRect().width) } : null,
        gapH2ToContent: (h2 && first) ? n(first.getBoundingClientRect().top - (lead || h2).getBoundingClientRect().bottom) : null };
    });
    const overflow = [...document.querySelectorAll('body *')].filter(e => { const r = e.getBoundingClientRect(); return r.right > innerWidth + 1 && r.width > 20 }).slice(0, 12).map(e => e.tagName + '.' + String(e.className).slice(0, 30) + ' right=' + Math.round(e.getBoundingClientRect().right));
    const btns = [...document.querySelectorAll('.btn, button, [class*=btn]')].slice(0, 40).map(e => { const r = e.getBoundingClientRect(); const st = cs(e); return { t: e.innerText.trim().slice(0, 22), cls: String(e.className).slice(0, 30), h: n(r.height), fs: n(st.fontSize), pad: st.padding, r: st.borderRadius, bg: st.backgroundColor, color: st.color } });
    const uniq = a => [...new Set(a)];
    const fonts = uniq([...document.querySelectorAll('h1,h2,h3,h4,p,a,span,li,td,th,label,button,input')].map(e => n(cs(e).fontSize))).sort((a, b) => a - b);
    const radii = uniq([...document.querySelectorAll('*')].map(e => cs(e).borderRadius).filter(v => v && v !== '0px')).slice(0, 30);
    const small = [...document.querySelectorAll('a,button')].filter(e => { const r = e.getBoundingClientRect(); return r.width > 0 && (r.height < 40 || r.width < 40) }).slice(0, 15).map(e => e.tagName + '«' + e.innerText.trim().slice(0, 18) + '» ' + Math.round(e.getBoundingClientRect().width) + '×' + Math.round(e.getBoundingClientRect().height));
    const heads = [...document.querySelectorAll('h1,h2,h3')].slice(0, 60).map(h => ({ t: h.tagName, fs: n(cs(h).fontSize), lh: cs(h).lineHeight, mt: n(cs(h).marginTop), mb: n(cs(h).marginBottom), text: h.innerText.trim().slice(0, 50) }));
    const imgs = [...document.querySelectorAll('img')].slice(0, 60).map(i => ({ src: (i.getAttribute('src') || '').slice(-50), w: Math.round(i.getBoundingClientRect().width), h: Math.round(i.getBoundingClientRect().height), nw: i.naturalWidth, nh: i.naturalHeight, alt: !!i.getAttribute('alt'), fit: cs(i).objectFit })).filter(i => i.w > 0);
    return { url: location.pathname, title: document.title, docW: document.documentElement.scrollWidth, vw: innerWidth, pageH: document.body.scrollHeight, secs, overflow, btns, fontSizes: fonts, radii, smallTargets: small, heads, imgs,
      theme: document.documentElement.getAttribute('data-theme'), bodyClass: document.body.className, bodyFont: cs(document.body).fontFamily.slice(0, 60),
      h1: (() => { const h = document.querySelector('h1'); return h ? { fs: n(cs(h).fontSize), lh: cs(h).lineHeight, text: h.innerText.slice(0, 80) } : null })() };
  });

  await p.screenshot({ path: `${OUT}/full-${tag}.jpg`, type: 'jpeg', quality: 45, fullPage: true });
  if (tag !== 'tablet') {
    // Липкая шапка и нижняя панель перекрывали верх секций — на время посекционной съёмки убираем их из потока.
    await p.addStyleTag({ content: 'header,.hdr2{position:static!important} .zr-mbar,.zr-mcta,.mcta,[class*=mobile-cta],[class*=sticky-cta],.zr-bottom{display:none!important}' });
    await sleep(300);
    await p.evaluate(() => document.querySelectorAll('section').forEach((s, i) => { if (!s.id) s.id = 'sec' + i; }));
    const ids = await p.$$eval('section', ss => ss.map(s => s.id));
    for (const id of ids) {
      const el = await p.$('[id="' + id + '"]'); if (!el) continue;
      const bb = await el.boundingBox(); if (!bb || bb.height < 40) continue;
      await el.evaluate(e => e.scrollIntoView({ block: 'start' })); await sleep(450);
      // абсолютная координата — ПОСЛЕ прокрутки, иначе кадр сдвигается на предыдущий блок
      const abs = await el.evaluate(e => ({ y: e.getBoundingClientRect().top + scrollY, h: e.getBoundingClientRect().height }));
      await p.screenshot({ path: `${OUT}/${tag}-${id}.jpg`, type: 'jpeg', quality: 52, fullPage: true, clip: { x: 0, y: abs.y, width: w, height: Math.min(abs.h, 1600) } });
    }
  }
  await ctx.close(); console.log('снято', tag, metrics[tag].pageH + 'px');
}
fs.writeFileSync(`${OUT}/metrics.json`, JSON.stringify(metrics, null, 1));
// Краткая сводка — в текст, чтобы читать без JSON.
const sum = Object.entries(metrics).map(([t, m]) => `${t}: pageH=${m.pageH} docW=${m.docW}/${m.vw} sections=${m.secs.length} overflow=${m.overflow.length} small=${m.smallTargets.length} fonts=${m.fontSizes.join(',')}`).join('\n');
fs.writeFileSync(`${OUT}/summary.txt`, sum + '\n');
console.log(sum);
await b.close();
