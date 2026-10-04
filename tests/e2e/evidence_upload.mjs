// End-to-end check of the multi-file evidence upload in a real browser (the suggestion rows are built by
// public/assets/evidence.js). Not part of bin/test_all.sh (needs Playwright and a browser):
//   SAQF_URL=http://127.0.0.1:8094 PLAYWRIGHT_MODULE=/path/to/playwright/index.mjs node tests/e2e/evidence_upload.mjs
// The server must be a DEMO installation started with SAQF_BOT_CHECK=off.
import { writeFileSync, mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
const pw = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const base = process.env.SAQF_URL || 'http://127.0.0.1:8094';
const browser = await pw.chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1100, height: 900 } });
const page = await ctx.newPage();
let pass = 0, fail = 0;
const ok = (c, label) => { c ? pass++ : fail++; console.log((c ? '  ✓ ' : '  ✗ ') + label); };

const dir = mkdtempSync(join(tmpdir(), 'ev-'));
const pdf = Buffer.from('%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n');
const files = ['SWE401_Midterm_Exam_Paper.pdf', 'Midterm rubric.pdf', 'mystery.pdf'].map((n) => { const p = join(dir, n); writeFileSync(p, pdf); return p; });

await page.goto(base + '/login.php');
await Promise.all([page.waitForNavigation(), page.evaluate(() => { const i = document.querySelector('form[action="demo.php"] input[name=as][value="f.omar"]'); i.form.submit(); })]);
await page.goto(base + '/faculty.php');
const href = await page.locator('a[href*="workspace.php?id="]').first().getAttribute('href');
const id = /id=(\d+)/.exec(href)[1];
await page.goto(base + '/workspace.php?id=' + id + '&tab=evidence');
ok(await page.locator('#ev-files').count() === 1 && await page.locator('#ev-files').getAttribute('multiple') !== null, 'the page offers a multi-file chooser');
await page.setInputFiles('#ev-files', files);
await page.waitForSelector('#ev-rows .ev-row', { timeout: 5000 });
const rows = await page.locator('#ev-rows .ev-row').count();
ok(rows === 3, 'one row per chosen file (' + rows + ')');
ok(await page.locator('select[name="item_kind[0]"]').inputValue() === 'assessment', 'the exam paper is suggested as the assessment paper');
ok(await page.locator('select[name="item_kind[1]"]').inputValue() === 'rubric', 'the rubric is suggested as a rubric');
const a0 = await page.locator('select[name="item_assessment[0]"]').inputValue();
ok(a0 !== '' && a0 === await page.locator('select[name="item_assessment[1]"]').inputValue(), 'both are matched to the same (midterm) assessment');
ok(await page.locator('#ev-rows .ev-row.ev-unsure').count() === 1 && (await page.locator('#ev-rows .ev-unsure').textContent()).includes('Not sure'), 'the file SAQF cannot place is marked "not sure"');
await page.screenshot({ path: process.env.SAQF_SHOT || join(dir, 'evidence.png') });
await page.selectOption('select[name="item_kind[2]"]', 'other');
await page.fill('input[name="item_title[2]"]', 'Mystery file, corrected by me');
await Promise.all([page.waitForNavigation(), page.click('button[type=submit]:has-text("Upload")')]);
const body = await page.textContent('body');
ok(body.includes('3 files added'), 'all three are filed');
ok(body.includes('Mystery file, corrected by me') && body.includes('Midterm rubric'), 'the person\'s correction and the suggested title are both in the course file');
console.log(`\n${pass} passed, ${fail} failed`);
await browser.close();
process.exit(fail ? 1 : 0);
