import { chromium } from 'playwright-core';
const B = 'http://localhost:8080/index.php/test/en';
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
const bre = await login('bre', 'ojsbre');
await bre.goto(`${B}/manageBookReviews/desk`);
console.log('desk title:', await bre.locator('h1.app__pageHeading').innerText());
const menu = await bre.locator('nav, .app__nav').first().innerText().catch(() => '');
console.log('menu has To-do:', menu.includes('To-do'), '| has Settings entry for plugin:', /Reviewers & bidders[\s\S]*Settings/.test(menu));
console.log('desk text:', (await bre.locator('.bookReviewManagerManagement').innerText()).slice(0, 200).replace(/\s+/g, ' '));
const settingsResp = await bre.goto(`${B}/manageBookReviews/settings`);
console.log('settings page as BRE:', settingsResp.status(), (await bre.title()).slice(0, 60));

// Create a bidding offer
await bre.goto(`${B}/manageBookReviews`);
await bre.locator('#bookReviewManagerGridContainer a[id*="addAvailableObjectForReview"]').click();
const form = bre.locator('#availableObjectsForReviewForm');
await form.waitFor({ timeout: 15000 });
await bre.waitForTimeout(1500);
await form.locator('[name=identifierType]').selectOption('ISBN');
await form.locator('input[name=identifier]').fill('9781509982622');
await form.locator('input[name=authors]').fill('Doe, Jane');
await bre.locator('#availableObjectsForReviewForm [name=title]').fill('A bidding test book');
await form.locator('input[name=publisher]').fill('Test Press');
await form.locator('input[name=year]').fill('2024');
console.log('deadline visible (direct):', await form.locator('input[name=bidDeadline]').isVisible());
console.log('editor preselected:', await form.locator('[name=responsibleEditorId] option:checked').innerText());
await form.locator('[name=assignmentMode]').selectOption('bidding');
console.log('deadline visible (bidding):', await form.locator('input[name=bidDeadline]').isVisible());
await bre.screenshot({ path: OUT + 'p1-offerform.png' });
await form.locator('button.submitFormButton').click();
await bre.waitForTimeout(3000);
const row = bre.locator('#bookReviewManagerGridContainer tr.gridRow', { hasText: 'A bidding test book' });
console.log('grid row:', (await row.innerText()).replace(/\s+/g, ' '));
await browser.close();
