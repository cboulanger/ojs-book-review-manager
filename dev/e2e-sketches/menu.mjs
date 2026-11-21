import { chromium } from 'playwright-core';

const B = 'http://localhost:8080/index.php/test/de';
const OUT = new URL('.', import.meta.url).pathname;
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const page = await browser.newPage({ viewport: { width: 1400, height: 900 } });
page.on('pageerror', e => console.log('PAGE ERROR:', e.message));
page.on('response', r => { if (r.status() >= 500) console.log('HTTP', r.status(), r.url()); });

await page.goto(`${B}/login`);
await page.fill('input[name=username]', 'admin');
await page.fill('input[name=password]', 'ojsadmin');
await Promise.all([page.waitForNavigation(), page.click('form#login button[type=submit], form#login .submit')]);

// Menu section
await page.goto(`${B}/manageBookReviews`);
await page.waitForLoadState('networkidle');
const nav = page.locator('nav, .app__nav, [role=navigation]').first();
const section = page.getByText('Rezensionsangebote', { exact: true }).first();
console.log('section in menu:', await section.count());
const links = await page.locator('a[href*="manageBookReviews"], a[href$="/books-for-review"]').evaluateAll(as => as.map(a => `${a.textContent.trim()} -> ${a.getAttribute('href').replace(/^.*index\.php/, '')}`));
console.log('menu links:\n  ' + [...new Set(links)].join('\n  '));
console.log('heading:', (await page.locator('h1.app__pageHeading').innerText()).trim());
await page.locator('#bookReviewManagerGridContainer table, #bookReviewManagerGridContainer .pkp_controllers_grid').first().waitFor({ timeout: 15000 });
console.log('grid rows:', await page.locator('#bookReviewManagerGridContainer tr.gridRow').count());
await page.screenshot({ path: OUT + 'menu-available.png' });

for (const op of ['reservations', 'reviewedWorks']) {
	await page.goto(`${B}/manageBookReviews/${op}`);
	console.log(`\n[${op}] heading:`, (await page.locator('h1.app__pageHeading').innerText()).trim());
	console.log(`[${op}] content:`, (await page.locator('.bookReviewManagerManagement').innerText()).replace(/\s+/g, ' ').slice(0, 300));
	await page.screenshot({ path: OUT + `menu-${op}.png` });
}

// Settings: toggle "display as list", save, reload, check persisted, restore
await page.goto(`${B}/manageBookReviews/settings`);
const form = page.locator('#orSettingsForm');
await form.waitFor({ timeout: 15000 });
const cb = form.locator('input[name=displayAsList]');
const before = await cb.isChecked();
await cb.setChecked(!before);
await form.locator('button.submitFormButton, button[type=submit]').first().click();
await page.waitForTimeout(2000);
await page.reload();
await page.locator('#orSettingsForm').waitFor({ timeout: 15000 });
console.log('\n[settings] displayAsList before/after save:', before, await page.locator('#orSettingsForm input[name=displayAsList]').isChecked());
await page.screenshot({ path: OUT + 'menu-settings.png' });
await page.locator('#orSettingsForm input[name=displayAsList]').setChecked(before);
await page.locator('#orSettingsForm button.submitFormButton, #orSettingsForm button[type=submit]').first().click();
await page.waitForTimeout(2000);

// Website settings tab must be gone
await page.goto(`${B}/management/settings/website`);
await page.waitForLoadState('networkidle');
console.log('\nwebsite tab present:', await page.getByRole('tab', { name: /Rezension/ }).count());

await browser.close();
