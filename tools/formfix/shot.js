const { chromium } = require('playwright');
const [,, base, out, tag] = process.argv;
const pages = ['index','about','contacts','importozameshchenie','proizvoditelyam-oborudovaniya'];
(async () => {
  const b = await chromium.launch();
  for (const w of [1440, 390]) {
    const ctx = await b.newContext({ viewport: { width: w, height: 900 }, deviceScaleFactor: 1 });
    const pg = await ctx.newPage();
    await pg.route('**/*', r => r.request().url().startsWith(base) ? r.continue() : r.abort());
    for (const p of pages) {
      await pg.goto(`${base}/${p}.html`, { waitUntil: 'domcontentloaded' });
      await pg.waitForTimeout(400);
      const box = await pg.evaluate(() => {
        const f = document.querySelector('form.lead-form'); if (!f) return null;
        const sec = f.closest('section') || f.parentElement; const r = sec.getBoundingClientRect();
        const ft = document.querySelector('footer'); const fr = ft ? ft.getBoundingClientRect() : null;
        const top = r.top + scrollY; const bottom = (fr ? Math.min(fr.top + scrollY + 260, r.bottom + scrollY + 300) : r.bottom + scrollY);
        return { y: Math.max(0, top - 20), h: Math.min(2600, bottom - top + 40), docW: document.documentElement.scrollWidth };
      });
      if (!box) { console.log(p, w, 'нет формы'); continue; }
      await pg.screenshot({ path: `${out}/${tag}_${p}_${w}.png`, clip: { x: 0, y: box.y, width: w, height: box.h }, fullPage: true });
      console.log(p, w, 'ok', box.docW > w ? 'ГОРИЗ.ПРОКРУТКА ' + box.docW : '');
    }
    await ctx.close();
  }
  await b.close();
})();
