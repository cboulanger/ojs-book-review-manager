import { chromium } from 'playwright-core';

const B = 'http://localhost:8080/index.php/test/de';
const OUT = new URL('.', import.meta.url).pathname;
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

const page = await login('author', 'ojsauthor');
await page.goto(`${B}/books-for-review`);
const button = page.locator('.ofrReserveButton').first();
const objectId = await button.getAttribute('data-object-id');
await button.click();
const dialog = page.locator('#ofrReserveDialog');
const form = dialog.locator('form');
console.log('dialog modal open:', await dialog.evaluate(d => d.open && d.matches(':modal')));
console.log('object shown:', (await dialog.locator('.ofrReserveObject').innerText()).slice(0, 60));
console.log('contract shown:', (await dialog.locator('.ofrContractText').innerText()).slice(0, 60));
const due = dialog.locator('input[name=dueDate]');
console.log('due default/min/max:', await due.inputValue(), await due.getAttribute('min'), await due.getAttribute('max'),
	'| expected default', addMonths(3), 'max', addMonths(6));
await page.screenshot({ path: OUT + 'reserve-dialog.png' });

const valid = () => form.evaluate(f => f.checkValidity());
console.log('\nempty form valid:', await valid());
await dialog.locator('input[name=acceptTerms]').check();
await dialog.locator('input[value=physical]').check();
console.log('physical: address visible/required:', await dialog.locator('#ofrShippingAddress').isVisible(), await dialog.locator('#ofrShippingAddress').evaluate(e => e.required),
	'| other note visible:', await dialog.locator('#ofrCopyNote').isVisible());
console.log('physical without address/phone valid:', await valid());
await dialog.locator('#ofrShippingAddress').fill('Max Muster\nBeispielweg 1\n12345 Berlin');
console.log('physical with address, no phone valid:', await valid());
await dialog.locator('#ofrPhone').fill('+49 30 1234567');
console.log('physical with address+phone valid:', await valid());
await dialog.locator('input[value=other]').check();
console.log('other: note required, valid without note:', await dialog.locator('#ofrCopyNote').evaluate(e => e.required), await valid(),
	'| address hidden:', !(await dialog.locator('#ofrShippingAddress').isVisible()));
await dialog.locator('input[value=physical]').check();
await page.screenshot({ path: OUT + 'reserve-dialog-physical.png' });

// Server-side validation: bypass browser checks (due date too late, phone missing)
await form.evaluate(f => { f.noValidate = true; });
await due.evaluate((e, v) => { e.removeAttribute('max'); e.value = v; }, addMonths(7));
await dialog.locator('#ofrPhone').fill('');
await Promise.all([page.waitForNavigation(), form.evaluate(f => f.submit())]);
console.log('\nserver errors:', (await page.locator('#ofrReserveDialog .ofrErrors').innerText()).replace(/\s+/g, ' '));
console.log('dialog reopened modal:', await page.locator('#ofrReserveDialog').evaluate(d => d.open && d.matches(':modal')),
	'| address kept:', JSON.stringify(await page.locator('#ofrShippingAddress').inputValue()),
	'| physical still checked:', await page.locator('#ofrReserveDialog input[value=physical]').isChecked());
await page.screenshot({ path: OUT + 'reserve-errors.png' });

// Valid submission
const d2 = page.locator('#ofrReserveDialog');
await d2.locator('input[name=dueDate]').fill(addMonths(2));
await d2.locator('#ofrPhone').fill('+49 30 1234567');
await d2.locator('input[name=acceptTerms]').check();
await Promise.all([page.waitForNavigation(), d2.locator('button[type=submit]').click()]);
console.log('\nafter reserve, own due date:', await page.locator('.ofrDueDate').first().innerText().catch(() => 'none'));

const admin = await login('admin', 'ojsadmin');
await admin.goto(`${B}/manageBookReviews/reservations`);
console.log('backend row:', (await admin.locator('.ofrTable tbody tr').first().innerText()).replace(/\s+/g, ' '));
await admin.screenshot({ path: OUT + 'reserve-backend.png' });
console.log('OBJECT_ID', objectId);
await browser.close();
