// End-to-end passkey test with a real browser: Chromium's virtual authenticator (CDP "WebAuthn" domain) plays
// the device's fingerprint/PIN sensor. Not part of bin/test_all.sh (needs Playwright and a browser):
//   SAQF_URL=http://localhost:8093 PLAYWRIGHT_MODULE=/path/to/playwright/index.mjs node tests/e2e/passkeys.mjs
// The server must be a DEMO installation reachable as http://localhost:<port> (browsers refuse IP addresses),
// started with SAQF_BOT_CHECK=off so the sign-in form needs no proof-of-work.
const pw = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const base = process.env.SAQF_URL || 'http://localhost:8093';
const browser = await pw.chromium.launch();
const ctx = await browser.newContext();
const page = await ctx.newPage();
let pass = 0, fail = 0;
const ok = (c, label) => { c ? pass++ : fail++; console.log((c ? '  ✓ ' : '  ✗ ') + label); };

await page.goto(base + '/login.php');
await Promise.all([page.waitForNavigation(), page.evaluate(() => { const i = document.querySelector('form[action="demo.php"] input[name=as][value="f.omar"]'); i.form.submit(); })]);
await page.goto(base + '/account.php');
const cdp = await ctx.newCDPSession(page);
await cdp.send('WebAuthn.enable');
const { authenticatorId } = await cdp.send('WebAuthn.addVirtualAuthenticator', { options: { protocol: 'ctap2', transport: 'internal', hasResidentKey: true, hasUserVerification: true, isUserVerified: true, automaticPresenceSimulation: true } });

console.log('Registration in the browser');
ok(await page.locator('[data-passkey="register"]').count() === 1, 'the account page shows "Add a passkey" on localhost');
await page.fill('#passkey-label', 'Virtual authenticator');
await Promise.all([page.waitForNavigation(), page.click('[data-passkey="register"]')]);
ok((await page.textContent('#passkeys')).includes('Virtual authenticator') && (await page.textContent('#passkeys')).includes('not used yet'), 'the passkey is registered and listed');
const creds = await cdp.send('WebAuthn.getCredentials', { authenticatorId });
ok(creds.credentials.length === 1 && creds.credentials[0].rpId === 'localhost', 'the authenticator holds exactly one credential, bound to this site (rpId ' + (creds.credentials[0] || {}).rpId + ')');

console.log('Sign-in with password, then the passkey');
await page.request.post(base + '/logout.php', { form: {} }).catch(() => {});
await ctx.clearCookies();
await page.goto(base + '/login.php');
await page.fill('input[name=username]', 'f.omar');
await page.fill('input[name=password]', 'Yamamah@2026');
await Promise.all([page.waitForURL(/mfa\.php/), page.click('button[type=submit]')]);
ok((await page.textContent('body')).includes('Use your passkey'), 'after the password, the second step offers the passkey');
await Promise.all([page.waitForURL(/faculty\.php|index\.php/), page.click('[data-passkey="login"]')]);
ok(/faculty\.php|index\.php/.test(page.url()), 'the passkey completes the sign-in (' + page.url().replace(base, '') + ')');
await page.goto(base + '/account.php');
ok((await page.textContent('#passkeys')).includes('last used'), 'the use is recorded on the account page');
ok((await page.textContent('body')).includes('Password and passkey'), 'the session list says how this sign-in was confirmed');

console.log('A device that cannot verify the person is refused');
await ctx.clearCookies();
await page.goto(base + '/login.php');
await page.fill('input[name=username]', 'f.omar');
await page.fill('input[name=password]', 'Yamamah@2026');
await Promise.all([page.waitForURL(/mfa\.php/), page.click('button[type=submit]')]);
await cdp.send('WebAuthn.setUserVerified', { authenticatorId, isUserVerified: false });
await page.click('[data-passkey="login"]');
await page.waitForTimeout(1500);
ok(/mfa\.php/.test(page.url()), 'without user verification (no PIN or fingerprint) the sign-in does not complete');
ok(await page.locator('#passkey-status').isVisible(), 'and the page says the passkey could not be used');
await cdp.send('WebAuthn.setUserVerified', { authenticatorId, isUserVerified: true });

console.log('Removal');
await ctx.clearCookies();
await page.goto(base + '/login.php');
await Promise.all([page.waitForNavigation(), page.evaluate(() => { const i = document.querySelector('form[action="demo.php"] input[name=as][value="f.omar"]'); i.form.submit(); })]);
await page.goto(base + '/account.php');
page.once('dialog', d => d.accept());
await Promise.all([page.waitForNavigation(), page.click('[data-passkey="remove"]')]);
ok(!(await page.textContent('#passkeys')).includes('Virtual authenticator'), 'a removed passkey disappears from the list');

console.log(`\n${pass} passed, ${fail} failed`);
await browser.close();
process.exit(fail ? 1 : 0);
