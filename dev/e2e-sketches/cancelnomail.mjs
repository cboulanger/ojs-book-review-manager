import { chromium } from 'playwright-core';

const B = 'http://localhost:8080/index.php/test/de';
const browser = await chromium.launch({ channel: 'chrome', headless: true });
async function login(user, pass) {
	const ctx = await browser.newContext({ viewport: { width: 1400, height: 1000 } });
	const page = await ctx.newPage();
	page.on('pageerror', e => console.log(`PAGE ERROR (${user}):`, e.message));
	await page.goto(`${B}/login`);
	await page.fill('input[name=username]', user);
	await page.fill('input[name=password]', pass);
	await Promise.all([page.waitForNavigation(), page.click('form#login button[type=submit], form#login .submit')]);
	return page;
}

const author = await login('author', 'ojsauthor');
await author.goto(`${B}/books-for-review`);
await author.locator('.ofrReserveButton[data-object-id="6"]').click();
const d = author.locator('#ofrReserveDialog');
await d.locator('input[name=acceptTerms]').check();
await d.locator('input[value=electronic]').check();
await Promise.all([author.waitForNavigation(), d.locator('button[type=submit]').click()]);
console.log('reserved:', await author.locator('.ofrDueDate').first().innerText().catch(() => 'no'));

const admin = await login('admin', 'ojsadmin');
await admin.goto(`${B}/manageBookReviews/reservations`);
const row = admin.locator('.ofrTable tbody tr', { hasText: 'A test book for reservations' });
await row.locator('.ofrActions a', { hasText: 'Reservierung aufheben' }).click();
const form = admin.locator('#ofrReservationEmailForm');
await form.waitFor({ timeout: 15000 });
await admin.waitForTimeout(1000);
await form.locator('input[name=sendEmail]').uncheck();
console.log('email fields hidden:', !(await form.locator('.ofrEmailFields').isVisible()));
await Promise.all([admin.waitForNavigation({ timeout: 20000 }), form.locator('button.submitFormButton').click()]);
console.log('row after cancel:', await admin.locator('.ofrTable tbody tr', { hasText: 'A test book for reservations' }).count());

await author.goto(`${B}/books-for-review`);
console.log('offered again on public page:', await author.locator('.ofrReserveButton[data-object-id="6"]').count());
await browser.close();
