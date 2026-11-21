import { chromium } from 'playwright-core';

const B = 'http://localhost:8080/index.php/test/de';
const OUT = new URL('.', import.meta.url).pathname;
const browser = await chromium.launch({ channel: 'chrome', headless: true });
async function login(user, pass) {
	const ctx = await browser.newContext({ viewport: { width: 1400, height: 1000 } });
	const page = await ctx.newPage();
	page.on('pageerror', e => console.log(`PAGE ERROR (${user}):`, e.message));
	page.on('dialog', d => d.accept());
	await page.goto(`${B}/login`);
	await page.fill('input[name=username]', user);
	await page.fill('input[name=password]', pass);
	await Promise.all([page.waitForNavigation(), page.click('form#login button[type=submit], form#login .submit')]);
	return page;
}
const inDays = n => { const d = new Date(); d.setHours(12); d.setDate(d.getDate() + n); return d.toISOString().slice(0, 10); };

// Author reserves the test book, due in 10 days
const author = await login('author', 'ojsauthor');
await author.goto(`${B}/books-for-review`);
await author.locator('.ofrReserveButton[data-object-id="6"]').click();
const d = author.locator('#ofrReserveDialog');
await d.locator('input[name=acceptTerms]').check();
await d.locator('input[name=dueDate]').fill(inDays(10));
await d.locator('input[value=have]').check();
await Promise.all([author.waitForNavigation(), d.locator('button[type=submit]').click()]);
console.log('reserved:', await author.locator('.ofrDueDate').first().innerText().catch(() => 'no'));

// Admin: actions on the reservations page
const admin = await login('admin', 'ojsadmin');
await admin.goto(`${B}/manageBookReviews/reservations`);
const row = admin.locator('.ofrTable tbody tr', { hasText: 'A test book for reservations' });
console.log('row actions:', (await row.locator('.ofrActions a').allInnerTexts()).map(t => t.trim()));

await row.locator('.ofrActions a', { hasText: 'E-Mail senden' }).click();
const form = admin.locator('#ofrReservationEmailForm');
await form.waitFor({ timeout: 15000 });
await admin.waitForTimeout(1500);
console.log('to:', (await form.locator('.ofrEmailFields p').first().innerText()).trim());
console.log('subject prefilled:', await form.locator('input[name=subject]').inputValue());
const body = await admin.evaluate(() => { const ta = document.querySelector('#ofrReservationEmailForm [name=body]'); const ed = tinymce.get(ta.id); return ed ? ed.getContent({ format: 'text' }) : ta.value; });
console.log('body prefilled:', body.replace(/\s+/g, ' ').slice(0, 220));
await admin.screenshot({ path: OUT + 'manage-email.png' });
await admin.evaluate(() => { const ta = document.querySelector('#ofrReservationEmailForm [name=body]'); const ed = tinymce.get(ta.id);
	ed.setContent(ed.getContent().replace('<p>&nbsp;</p>', '<p>Bitte schicken Sie uns Ihre Postanschrift.</p>')); ed.fire('change'); });
await Promise.all([admin.waitForNavigation({ timeout: 20000 }), form.locator('button.submitFormButton').click()]);
console.log('page reloaded after send');

// History
await admin.locator('.ofrTable tbody tr', { hasText: 'A test book for reservations' }).locator('.ofrActions a', { hasText: 'Verlauf' }).click();
const hist = admin.locator('.ofrHistory');
await hist.waitFor({ timeout: 15000 });
console.log('history:\n  ' + (await hist.locator('.ofrHistoryHead').allInnerTexts()).map(t => t.replace(/\s+/g, ' ')).join('\n  '));
await hist.locator('summary').first().click();
console.log('email body in history:', (await hist.locator('.ofrHistoryBody').first().innerText()).replace(/\s+/g, ' ').slice(0, 160));
await admin.screenshot({ path: OUT + 'manage-history.png' });
await browser.close();
