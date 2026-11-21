import { chromium } from 'playwright-core';

const B = 'http://localhost:8080/index.php/test/de';
const browser = await chromium.launch({ channel: 'chrome', headless: true });
async function login(user, pass) {
	const ctx = await browser.newContext({ viewport: { width: 1300, height: 1000 } });
	const page = await ctx.newPage();
	page.on('pageerror', e => console.log('PAGE ERROR:', e.message));
	page.on('dialog', d => d.accept());
	await page.goto(`${B}/login`);
	await page.fill('input[name=username]', user);
	await page.fill('input[name=password]', pass);
	await Promise.all([page.waitForNavigation(), page.click('form#login button[type=submit], form#login .submit')]);
	return page;
}
const iso = d => d.toISOString().slice(0, 10);
const addMonths = n => { const d = new Date(); d.setHours(12); d.setMonth(d.getMonth() + n); return iso(d); };

async function saveSettings(admin, def, max) {
	await admin.goto(`${B}/manageBookReviews/settings`);
	const form = admin.locator('#orSettingsForm');
	await form.waitFor();
	await form.locator('input[name=ofrDueMonthsDefault]').fill(String(def));
	await form.locator('input[name=ofrDueMonthsMax]').fill(String(max));
	await form.locator('button.submitFormButton').click();
	await admin.waitForTimeout(2500);
	return (await admin.locator('#bookReviewManagerSettingsContainer').innerText()).replace(/\s+/g, ' ');
}

const admin = await login('admin', 'ojsadmin');
await admin.goto(`${B}/manageBookReviews/settings`);
await admin.locator('#orSettingsForm').waitFor();
console.log('defaults shown:', await admin.locator('input[name=ofrDueMonthsDefault]').inputValue(), await admin.locator('input[name=ofrDueMonthsMax]').inputValue());
console.log('contract prefilled (de):', (await admin.evaluate(() => {
	const ta = [...document.querySelectorAll('#orSettingsForm textarea')].find(t => /ofrContract/.test(t.name) && /de/.test(t.name));
	return ta ? (window.tinymce && tinymce.get(ta.id) ? tinymce.get(ta.id).getContent({ format: 'text' }) : ta.value) : 'n/a';
})).slice(0, 50));

const invalid = await saveSettings(admin, 5, 2);
console.log('invalid (5 > 2) error shown:', /ganze Monatszahlen/.test(invalid));
await saveSettings(admin, 1, 2);

const author = await login('author', 'ojsauthor');
await author.goto(`${B}/books-for-review`);
const btn = author.locator('.ofrReserveButton[data-object-id="6"]');
await btn.click();
const due = author.locator('#ofrReserveDialog input[name=dueDate]');
console.log('dialog default/max with 1/2:', await due.inputValue(), await due.getAttribute('max'), '| expected', addMonths(1), addMonths(2));

// Reserve with "other"
const d = author.locator('#ofrReserveDialog');
await d.locator('input[name=acceptTerms]').check();
await d.locator('input[value=other]').check();
await d.locator('#ofrCopyNote').fill('Ich leihe es in der Bibliothek aus.');
await Promise.all([author.waitForNavigation(), d.locator('button[type=submit]').click()]);
console.log('reserved, due date shown:', await author.locator('.ofrDueDate').first().innerText().catch(() => 'none'));

// Cancel
await Promise.all([author.waitForNavigation(), author.locator('#cancelObject-6').click()]);
console.log('after cancel reserve button back:', await author.locator('.ofrReserveButton[data-object-id="6"]').count());

await saveSettings(admin, 3, 6);
await browser.close();
