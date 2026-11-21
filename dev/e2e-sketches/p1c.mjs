import { B, OUT, browser, login, anonymous, since, mails, linkIn, modalForm, bodyText } from './lib.mjs';
const objectRow = p => p.locator('#book-review-manager-table tr', { hasText: 'A bidding test book' });
const status = async p => (await objectRow(p).locator('td').last().innerText()).replace(/\s+/g, ' ').trim();

const offerMail = mails(new Date(Date.now() - 30 * 60e3).toISOString()).filter(m => /selected to review/.test(m.subject)).pop();
const link = linkIn(offerMail.body);
console.log('link from offer mail:', link);

const anon = await anonymous();
await anon.goto(link);
console.log('link page:', await anon.locator('h1').innerText(), '|', (await anon.locator('.ofrResponse > p').nth(1).innerText()).trim());
await anon.screenshot({ path: OUT + 'p1-contract-page.png', fullPage: true });
// Submit without accepting the terms (server-side validation)
await anon.evaluate(() => document.querySelectorAll('.ofrContractForm [required]').forEach(f => f.required = false));
await Promise.all([anon.waitForNavigation(), anon.locator('.ofrContractForm button[type=submit]').click()]);
console.log('errors:', (await anon.locator('.ofrErrors li').allInnerTexts()));
await anon.locator('input[name=acceptTerms]').check();
await anon.locator('input[value=physical]').check();
await anon.locator('textarea[name=shippingAddress]').fill('Test Street 1\n12345 Test City');
await anon.locator('input[name=phone]').fill('+49 30 123456');
const t2 = since();
await Promise.all([anon.waitForNavigation(), anon.locator('.ofrContractForm button[type=submit]').click()]);
console.log('after accept:', await anon.locator('h1').innerText(), '|', await anon.locator('.ofrResponseMessage').innerText());
console.log('editor notified:', mails(t2).map(m => `${m.to} | ${m.subject}`));
await anon.goto(link);
console.log('link reused:', await anon.locator('h1').innerText());

const author = await login('author', 'ojsauthor');
await author.goto(`${B}/books-for-review`);
console.log('author status:', await status(author));

// Editor: notify the unsuccessful bidder
const bre = await login('bre', 'ojsbre');
await bre.goto(`${B}/manageBookReviews/desk`);
console.log('desk:', (await bre.locator('.bookReviewManagerManagement').innerText()).replace(/\s+/g, ' ').slice(0, 260));
await bre.locator('.ofrTable tr', { hasText: 'A bidding test book' }).locator('a', { hasText: 'Notify bidders' }).click();
const form = await modalForm(bre);
console.log('to:', await form.locator('.ofrRecipient').allInnerTexts(), '| send email checked:', await form.locator('input[name=sendEmail]').isChecked());
const body = await bodyText(bre);
console.log('placeholders kept:', body.includes('{$recipientName}'), body.includes('{$removeUrl}'));
const t3 = since();
await Promise.all([bre.waitForNavigation({ timeout: 20000 }), form.locator('button.submitFormButton').click()]);
const unsucc = mails(t3);
console.log('unsuccessful mails:', unsucc.map(m => `${m.to} | ${m.subject}`));
console.log('personalised:', /Dear Second Reviewer/.test(unsucc[0]?.body), '| leave link:', !!linkIn(unsucc[0]?.body || ''));
console.log('desk now:', (await bre.locator('.bookReviewManagerManagement').innerText()).replace(/\s+/g, ' ').slice(0, 120));

const r2 = await login('reviewer2', 'ojsreviewer2');
await r2.goto(`${B}/books-for-review`);
console.log('reviewer2 status:', await status(r2));
await bre.goto(`${B}/manageBookReviews/assignments?status=all`);
console.log('assignments page:', (await bre.locator('.ofrTable tbody tr').allInnerTexts()).filter(t => t.includes('bidding test')).map(t => t.replace(/\s+/g, ' ').slice(0, 160)));
await bre.screenshot({ path: OUT + 'p1-assignments.png', fullPage: true });
await browser.close();
