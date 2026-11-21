// Documentation screenshots, stage 2: the workflow
import { B, browser, login, anonymous, since, mails, linkIn, modalForm } from './lib.mjs';
const IMG = new URL('../../docs/images/', import.meta.url).pathname;
const T1 = 'Law and Society in Transition', T3 = 'Legal Pluralism Revisited';
const row = (p, t) => p.locator('#book-review-manager-table tr', { hasText: t });
const size = p => p.setViewportSize({ width: 1280, height: 900 });

// Anna applies (bid dialog), Jonas applies too
const anna = await login('author', 'ojsauthor'); await size(anna);
await anna.goto(`${B}/books-for-review`);
await row(anna, T1).locator('.ofrBidButton').click();
await anna.locator('#ofrBidDialog textarea[name=statement]').fill('I work on the sociology of legal change and reviewed two related monographs for this journal.');
await anna.screenshot({ path: IMG + 'bid-dialog.png' });
await Promise.all([anna.waitForNavigation(), anna.locator('#ofrBidDialog button[type=submit]').click()]);

const jonas = await login('reviewer2', 'ojsreviewer2'); await size(jonas);
await jonas.goto(`${B}/books-for-review`);
await row(jonas, T1).locator('.ofrBidButton').click();
await jonas.locator('#ofrBidDialog textarea[name=statement]').fill('My dissertation deals with transitional justice.');
await Promise.all([jonas.waitForNavigation(), jonas.locator('#ofrBidDialog button[type=submit]').click()]);

// Jonas reserves the third work directly, with a printed copy (reserve dialog)
await row(jonas, T3).locator('.ofrReserveButton').click();
const d = jonas.locator('#ofrReserveDialog');
await d.locator('input[name=acceptTerms]').check();
await d.locator('input[value=physical]').check();
await d.locator('textarea[name=shippingAddress]').fill('Jonas Becker\nUniversity Street 4\n10115 Berlin');
await d.locator('input[name=phone]').fill('+49 30 1234567');
await jonas.screenshot({ path: IMG + 'reserve-dialog.png' });
await Promise.all([jonas.waitForNavigation(), d.locator('button[type=submit]').click()]);
await jonas.screenshot({ path: IMG + 'public-page-reviewer.png', fullPage: true });

// Book review editor: To-do with the selection
const bre = await login('bre', 'ojsbre'); await size(bre);
await bre.goto(`${B}/manageBookReviews/desk`);
await bre.screenshot({ path: IMG + 'todo-selection.png', fullPage: true });
const item = bre.locator('.ofrDeskItem', { hasText: T1 });
await item.locator('.ofrBidders tbody tr', { hasText: 'Anna Weber' }).locator('a', { hasText: 'Select and send contract' }).click();
let form = await modalForm(bre);
await bre.screenshot({ path: IMG + 'contract-offer.png' });
let t = since();
await Promise.all([bre.waitForNavigation({ timeout: 20000 }), form.locator('button.submitFormButton').click()]);
const contractLink = linkIn(mails(t).find(m => /selected/.test(m.subject))?.body || '');
console.log('contract link:', contractLink);

// Anna opens the link from the email (no login)
const annaMail = await anonymous(); await size(annaMail);
await annaMail.goto(contractLink);
await annaMail.locator('input[name=acceptTerms]').check();
await annaMail.locator('input[value=electronic]').check();
await annaMail.screenshot({ path: IMG + 'contract-page.png', fullPage: true });
await Promise.all([annaMail.waitForNavigation(), annaMail.locator('.ofrContractForm button[type=submit]').click()]);

// To-do after the contract: unsuccessful bidders, review copies
await bre.goto(`${B}/manageBookReviews/desk`);
await bre.screenshot({ path: IMG + 'todo-copies.png', fullPage: true });
const unsuccessful = bre.locator('h2', { hasText: 'Notify bidders' }).locator('xpath=following-sibling::table[1]');
await unsuccessful.locator('a').first().click();
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
