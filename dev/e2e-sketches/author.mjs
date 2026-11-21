import { chromium } from 'playwright-core';

const B = 'http://localhost:8080/index.php/test/en';
const OUT = new URL('.', import.meta.url).pathname;
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const page = await browser.newPage({ viewport: { width: 1400, height: 1000 } });
page.on('pageerror', e => console.log('PAGE ERROR:', e.message));

await page.goto(`${B}/login`);
await page.fill('input[name=username]', 'author');
await page.fill('input[name=password]', 'ojsauthor');
await Promise.all([page.waitForNavigation(), page.click('form#login button[type=submit], form#login .submit')]);

await page.goto(`${B}/submission?id=1`);
const grid = page.locator('[id*="objectsforreviewgrid"]').first();
for (let i = 0; i < 5 && !(await grid.isVisible().catch(() => false)); i++) {
	await page.waitForTimeout(1500);
	const next = page.getByRole('button', { name: /^continue$/i });
	if (await grid.isVisible().catch(() => false) || !(await next.count())) break;
	await next.first().click();
}
await grid.waitFor();
const add = page.locator('a[id*="addObjectForReview"]');
console.log('add link count:', await add.count());
await add.first().click();

const form = page.locator('#bookReviewManagerForm');
await form.waitFor({ timeout: 15000 });
const box = form.locator('.orDoiLookup');
console.log('button visible (DOI):', await box.isVisible());
await form.locator('[name=identifier]').fill('doi:10.1109/meta.2012.6464961');
await box.locator('button').click();
await box.locator('.orDoiLookupStatus').filter({ hasText: /filled in|failed|not found|valid/ }).waitFor({ timeout: 20000 });
console.log('status:', await box.locator('.orDoiLookupStatus').textContent());
const v = async n => form.locator(`[name=${n}]`).inputValue();
console.log({
	identifier: await v('identifier'), resourceType: await v('resourceType'),
	authors: await v('authors'), publisher: await v('publisher'), year: await v('year'),
	title: await v('title'),
});
await page.screenshot({ path: OUT + 'author-filled.png' });

await form.locator('button.submitFormButton').click();
await form.waitFor({ state: 'detached', timeout: 15000 });
const row = page.locator('tr', { hasText: '10.1109/meta.2012.6464961' }).first();
await row.waitFor({ timeout: 15000 });
console.log('grid row:', (await row.innerText()).replace(/\s+/g, ' '));
await page.screenshot({ path: OUT + 'author-saved.png' });
await browser.close();
