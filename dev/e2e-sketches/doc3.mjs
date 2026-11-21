// Documentation screenshots, stage 2: the workflow
import { B, browser, login, anonymous, since, mails, linkIn, modalForm } from './lib.mjs';
const IMG = new URL('../../docs/images/', import.meta.url).pathname;
const T1 = 'Law and Society in Transition', T3 = 'Legal Pluralism Revisited';
const row = (p, t) => p.locator('#book-review-manager-table tr', { hasText: t });
const size = p => p.setViewportSize({ width: 1280, height: 900 });

const bre = await login('bre', 'ojsbre'); await size(bre);
await bre.goto(`${B}/manageBookReviews/desk`);
let form, t;
const unsuccessful = bre.locator('h2', { hasText: 'Notify bidders' }).locator('xpath=following-sibling::table[1]');
await unsuccessful.locator('a', { hasText: 'Notify bidders' }).click();
form = await modalForm(bre);
await bre.screenshot({ path: IMG + 'notify-unsuccessful.png' });
await Promise.all([bre.waitForNavigation({ timeout: 20000 }), form.locator('button.submitFormButton').click()]);

// Request the printed copy from the publisher
await bre.locator('.ofrCopies tbody tr', { hasText: T3 }).locator('a', { hasText: 'Request copy from publisher' }).click();
form = await modalForm(bre);
await bre.screenshot({ path: IMG + 'copy-request.png' });
t = since();
await Promise.all([bre.waitForNavigation({ timeout: 20000 }), form.locator('button.submitFormButton').click()]);
const copyLink = linkIn(mails(t).find(m => /copy/i.test(m.subject))?.body || '');
console.log('copy link:', copyLink);

// The publisher confirms with one click
const pub = await anonymous(); await size(pub);
await pub.goto(copyLink);
await pub.screenshot({ path: IMG + 'link-copy-sent.png', fullPage: true });
t = since();
await Promise.all([pub.waitForNavigation(), pub.locator('form button[type=submit]').first().click()]);
await pub.screenshot({ path: IMG + 'link-done.png', fullPage: true });
console.log('after publisher click:', mails(t).map(m => `${m.to} | ${m.subject}`));

// History of the offer
await bre.goto(`${B}/manageBookReviews/desk`);
await bre.locator('.ofrCopies tbody tr', { hasText: T3 }).locator('a', { hasText: 'History' }).click();
await bre.waitForTimeout(3000);
await bre.screenshot({ path: IMG + 'history.png' });

// Reviewers & bidders
await bre.goto(`${B}/manageBookReviews/assignments`);
await bre.screenshot({ path: IMG + 'assignments.png', fullPage: true });
await browser.close();
