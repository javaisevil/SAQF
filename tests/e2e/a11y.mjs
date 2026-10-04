// Accessibility audit with axe-core (WCAG 2.0/2.1/2.2 A and AA rules) over every role, in English desktop, English mobile
// and Arabic (right to left). Development-time only: axe-core is NOT part of SAQF and nothing from it ships.
// Not part of bin/test_all.sh (needs Playwright, a browser and axe-core):
//   npm install axe-core            (anywhere outside the repository)
//   SAQF_URL=http://127.0.0.1:8097 AXE_CORE=/path/to/node_modules/axe-core/axe.min.js \
//   PLAYWRIGHT_MODULE=/path/to/playwright/index.mjs node tests/e2e/a11y.mjs
// The server must be a DEMO installation started with SAQF_BOT_CHECK=off. The test browser bypasses the page's
// Content-Security-Policy ONLY so that axe can be injected; the application's policy is not changed.
// Exit code: 1 when any serious or critical violation remains. Set ROLES=f.omar,hod.ced to limit the roles.
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
import fs from 'node:fs';
const base = process.env.SAQF_URL || 'http://127.0.0.1:8097';
const axeSrc = fs.readFileSync(process.env.AXE_CORE || 'node_modules/axe-core/axe.min.js', 'utf8');
const roles = (process.env.ROLES || 'f.omar,hod.ced,qa.director,dean.coe,vp.academic,it.admin').split(',');
const modes = [
  { name: 'en-desktop', lang: 'en', vp: { width: 1280, height: 900 } },
  { name: 'en-mobile', lang: 'en', vp: { width: 390, height: 844 } },
  { name: 'ar-desktop', lang: 'ar', vp: { width: 1280, height: 900 } },
];
const MAXPAGES = parseInt(process.env.MAXPAGES || '28', 10);
const browser = await chromium.launch();
const found = {}; // rule -> {impact, help, nodes:Map(selector->{pages:Set,html})}
const pageList = [];
async function login(ctx, user) {
  const page = await ctx.newPage();
  await page.goto(base + '/login.php');
  await Promise.all([page.waitForNavigation(), page.evaluate((u) => { const i = document.querySelector('form[action="demo.php"] input[name=as][value="' + u + '"]'); i.form.submit(); }, user)]);
  return page;
}
for (const role of roles) {
  // discover pages once in English desktop
  const ctx0 = await browser.newContext({ viewport: { width: 1280, height: 900 }, bypassCSP: true });
  const p0 = await login(ctx0, role);
  const hrefs = new Set(['index.php']);
  const grab = async () => { for (const h of await p0.$$eval('a[href]', (as) => as.map((a) => a.getAttribute('href')))) { if (!h || /^(https?:|mailto:|#|javascript:)/.test(h) || /logout|demo\.php|export\.php|evidence\.php|\?lang=|download|search\.php/.test(h)) continue; hrefs.add(h.replace(/^\.\//, '')); } };
  await grab();
  for (const h of [...hrefs].slice(0, 12)) { try { await p0.goto(base + '/' + h); await grab(); } catch (e) {} }
  // keep unique (script, tab) combos
  const seen = new Set(); const pages = [];
  for (const h of hrefs) { const u = new URL(base + '/' + h); const key = u.pathname + '|' + (u.searchParams.get('tab') || '') + '|' + (u.searchParams.get('type') || ''); if (seen.has(key)) continue; seen.add(key); pages.push(h); }
  await ctx0.close();
  for (const mode of modes) {
    const ctx = await browser.newContext({ viewport: mode.vp, bypassCSP: true });
    const page = await login(ctx, role);
    if (mode.lang === 'ar') { await page.goto(base + '/index.php?lang=ar'); }
    for (const h of pages.slice(0, MAXPAGES)) {
      try {
        const r = await page.goto(base + '/' + h, { waitUntil: 'load' });
        if (!r || r.status() >= 500) continue;
        await page.addScriptTag({ content: axeSrc });
        const res = await page.evaluate(async () => await window.axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'] }, resultTypes: ['violations'] }));
        pageList.push(`${role} ${mode.name} ${h} violations=${res.violations.length}`);
        for (const v of res.violations) {
          const f = (found[v.id] ||= { impact: v.impact, help: v.help, url: v.helpUrl, nodes: new Map() });
          for (const n of v.nodes) {
            const key = n.target.join(' ');
            const e = f.nodes.get(key) || { pages: new Set(), html: n.html.slice(0, 220), summary: (n.failureSummary || '').split('\n').slice(0, 3).join(' | ') };
            e.pages.add(`${mode.name}:${h}`);
            f.nodes.set(key, e);
          }
        }
      } catch (e) { pageList.push(`${role} ${mode.name} ${h} ERROR ${String(e.message).slice(0, 80)}`); }
    }
    await ctx.close();
  }
}
const out = Object.entries(found).map(([id, f]) => ({ id, impact: f.impact, help: f.help, nodes: [...f.nodes].map(([sel, e]) => ({ sel, html: e.html, summary: e.summary, pages: [...e.pages].slice(0, 6), pageCount: e.pages.size })) }));
if (process.env.OUT) fs.writeFileSync(process.env.OUT, JSON.stringify({ pages: pageList, rules: out }, null, 1));
console.log(`${pageList.length} page checks; ${out.length} distinct rules violated`);
for (const r of out) console.log(r.impact, r.id, '-', r.help, '- distinct elements:', r.nodes.length);
await browser.close();
const bad = out.filter((r) => r.impact === 'serious' || r.impact === 'critical').length;
console.log(bad ? `${bad} rule(s) with serious or critical violations remain` : 'No serious or critical violations.');
process.exit(bad ? 1 : 0);
