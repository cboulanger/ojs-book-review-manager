import { chromium } from 'playwright-core';

const B = 'http://localhost:8080/index.php/test/de';
const OUT = new URL('.', import.meta.url).pathname;
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const page = await browser.newPage({ viewport: { width: 1400, height: 1000 } });
page.on('pageerror', e => console.log('PAGE ERROR:', e.message));
await page.goto(`${B}/login`);
await page.fill('input[name=username]', 'admin');
await page.fill('input[name=password]', 'ojsadmin');
await Promise.all([page.waitForNavigation(), page.click('form#login button[type=submit], form#login .submit')]);

// History is still available from the available objects list
await page.goto(`${B}/manageBookReviews`);
const gridRow = page.locator('#bookReviewManagerGridContainer tr.gridRow', { hasText: 'A test book for reservations' });
await gridRow.waitFor({ timeout: 15000 });
await gridRow.locator('a.show_extras').click();
const histLink = page.locator('a[id*="-row-6-history"]');
await histLink.waitFor({ state: 'visible', timeout: 10000 });
await histLink.click();
const hist = page.locator('.ofrHistory');
await hist.waitFor({ timeout: 15000 });
console.log('history:\n  ' + (await hist.locator('.ofrHistoryHead').allInnerTexts()).map(t => t.replace(/\s+/g, ' ')).join('\n  '));
await page.screenshot({ path: OUT + 'manage-history2.png' });

await page.goto(`${B}/manageBookReviews/settings`);
await page.locator('#orSettingsForm').waitFor();
console.log('reminder days setting:', await page.locator('input[name=ofrReminderDays]').inputValue());
await browser.close();
