import { chromium } from 'playwright-core';

const B = 'http://localhost:8080/index.php/test/de';
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const page = await browser.newPage({ viewport: { width: 1400, height: 1000 } });
page.on('pageerror', e => console.log('PAGE ERROR:', e.message));
await page.goto(`${B}/login`);
await page.fill('input[name=username]', 'admin');
await page.fill('input[name=password]', 'ojsadmin');
await Promise.all([page.waitForNavigation(), page.click('form#login button[type=submit], form#login .submit')]);

await page.goto(`${B}/manageBookReviews`);
await page.locator('a[id*="addAvailableObjectForReview"]').click();
const form = page.locator('#availableObjectsForReviewForm');
await form.waitFor();
const box = form.locator('.orIdLookup');
const type = form.locator('[name=identifierType]');
const state = async () => `${await box.isVisible()} "${(await box.locator('button').innerText()).trim()}"`;
console.log('DOI:', await state());
await type.selectOption('ISBN');
console.log('ISBN:', await state());
await type.selectOption('urn');
console.log('URN visible:', await box.isVisible());
await type.selectOption('ISBN');

await form.locator('[name=identifier]').fill('978-0-674-02235-5');
await box.locator('button').click();
await box.locator('.orIdLookupStatus').filter({ hasText: /übernommen|fehlgeschlagen|keine|gültige/ }).waitFor({ timeout: 30000 });
console.log('status:', await box.locator('.orIdLookupStatus').innerText());
const v = async n => form.locator(`[name=${n}]`).inputValue();
console.log({ identifier: await v('identifier'), resourceType: await v('resourceType'), authors: await v('authors'), publisher: await v('publisher'), year: await v('year'),
	title: await v('title') });
await form.locator('button.submitFormButton').click();
await form.waitFor({ state: 'detached', timeout: 15000 });

await page.goto(`${B}/books-for-review`);
const links = await page.locator('a[href*="worldcat.org"]').evaluateAll(as => as.map(a => `${a.textContent.trim()} -> ${a.href} (target=${a.target})`));
console.log('public worldcat links:\n  ' + links.join('\n  '));

await page.goto(`${B}/manageBookReviews/reservations`);
console.log('backend worldcat links:', await page.locator('a[href*="worldcat.org"]').count());
await browser.close();
