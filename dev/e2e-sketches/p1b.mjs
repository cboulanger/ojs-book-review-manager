import { B, OUT, browser, login, anonymous, since, mails, linkIn, modalForm, bodyText } from './lib.mjs';
const t0 = since();
const objectRow = p => p.locator('#book-review-manager-table tr', { hasText: 'A bidding test book' });

// Two bids
const author = await login('author', 'ojsauthor');
await author.goto(`${B}/books-for-review`);
console.log('author sees:', (await objectRow(author).locator('td').last().innerText()).trim());
await objectRow(author).locator('.ofrBidButton').click();
await author.locator('#ofrBidDialog textarea[name=statement]').fill('I work on this topic and reviewed related books.');
await Promise.all([author.waitForNavigation(), author.locator('#ofrBidDialog button[type=submit]').click()]);
console.log('author after bid:', (await objectRow(author).locator('td').last().innerText()).replace(/\s+/g, ' ').trim());

const r2 = await login('reviewer2', 'ojsreviewer2');
await r2.goto(`${B}/books-for-review`);
await objectRow(r2).locator('.ofrBidButton').click();
await Promise.all([r2.waitForNavigation(), r2.locator('#ofrBidDialog button[type=submit]').click()]);
console.log('reviewer2 after bid:', (await objectRow(r2).locator('td').last().innerText()).replace(/\s+/g, ' ').trim());

const anon = await anonymous();
await anon.goto(`${B}/books-for-review`);
console.log('anonymous sees:', (await objectRow(anon).locator('td').last().innerText()).trim());

const bidMails = mails(t0).filter(m => /New application/.test(m.subject));
console.log('BID_NEW mails:', bidMails.map(m => `${m.to} | ${m.subject}`));

// Editor selects the author
const bre = await login('bre', 'ojsbre');
await bre.goto(`${B}/manageBookReviews/desk`);
const item = bre.locator('.ofrDeskItem', { hasText: 'A bidding test book' });
console.log('desk state:', (await item.locator('.ofrDeskState').innerText()).trim());
console.log('bidders:', (await item.locator('.ofrBidders tbody tr').allInnerTexts()).map(t => t.replace(/\s+/g, ' ').slice(0, 120)));
console.log('offer actions:', (await item.locator('.ofrOfferActions a').allInnerTexts()).map(t => t.trim()));
await bre.screenshot({ path: OUT + 'p1-desk-selection.png', fullPage: true });
await item.locator('.ofrBidders tbody tr', { hasText: 'Test Author' }).locator('a', { hasText: 'Select and send contract' }).click();
const form = await modalForm(bre);
console.log('modal text:', (await form.locator('p').first().innerText()).trim(), '|', (await form.locator('p').nth(1).innerText()).trim());
console.log('to:', await form.locator('.ofrRecipient').allInnerTexts(), '| subject:', await form.locator('input[name=subject]').inputValue());
const body = await bodyText(bre);
console.log('body keeps placeholder:', body.includes('{$contractUrl}'), '| deadline filled:', !body.includes('{$contractDeadline}'));
await bre.screenshot({ path: OUT + 'p1-contract-offer-modal.png' });
const t1 = since();
await Promise.all([bre.waitForNavigation({ timeout: 20000 }), form.locator('button.submitFormButton').click()]);
console.log('desk after offer:', (await bre.locator('.bookReviewManagerManagement').innerText()).replace(/\s+/g, ' ').slice(0, 300));

const offerMail = mails(t1).find(m => /selected to review/.test(m.subject));
const link = offerMail && linkIn(offerMail.body);
console.log('offer mail to:', offerMail?.to, '| link:', link);

await author.goto(`${B}/books-for-review`);
console.log('author status:', (await objectRow(author).locator('td').last().innerText()).replace(/\s+/g, ' ').trim());
await anon.goto(`${B}/books-for-review`);
console.log('anonymous status:', (await objectRow(anon).locator('td').last().innerText()).trim());

// Anonymous visitor opens the link: nothing happens until confirmed
const anon2 = await anonymous();
await anon2.goto(link);
console.log('link page heading:', await anon2.locator('h1').innerText(), '|', (await anon2.locator('.ofrResponse > p').nth(1).innerText()).trim());
await anon2.screenshot({ path: OUT + 'p1-contract-page.png', fullPage: true });
await anon2.locator('input[name=acceptTerms]').check();
await anon2.locator('input[value=physical]').check();
await anon2.locator('textarea[name=shippingAddress]').fill('Test Street 1\n12345 Test City');
await anon2.locator('input[name=phone]').fill('+49 30 123456');
const t2 = since();
await Promise.all([anon2.waitForNavigation(), anon2.locator('.ofrContractForm button[type=submit]').click()]);
console.log('after accept:', await anon2.locator('h1').innerText(), '|', await anon2.locator('.ofrResponseMessage').innerText());
console.log('editor notified:', mails(t2).map(m => `${m.to} | ${m.subject}`));
await anon2.goto(link);
console.log('link reused:', await anon2.locator('h1').innerText());

await author.goto(`${B}/books-for-review`);
console.log('author status:', (await objectRow(author).locator('td').last().innerText()).replace(/\s+/g, ' ').trim());
await browser.close();
