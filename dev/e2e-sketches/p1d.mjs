import { B, OUT, browser, login, anonymous, since, mails, linkIn, modalForm, bodyText } from './lib.mjs';
const objectRow = p => p.locator('#book-review-manager-table tr', { hasText: 'A bidding test book' });
const status = async p => (await objectRow(p).locator('td').last().innerText()).replace(/\s+/g, ' ').trim();
const leaveLink = linkIn(mails(new Date(Date.now() - 30 * 60e3).toISOString()).filter(m => /Your application/.test(m.subject)).pop().body);

// The reviewer cancels the reservation
const author = await login('author', 'ojsauthor');
await author.goto(`${B}/books-for-review`);
const t0 = since();
await Promise.all([author.waitForNavigation(), objectRow(author).locator('button', { hasText: 'Cancel' }).click()]);
console.log('author after cancel:', await status(author));
console.log('mails:', mails(t0).map(m => `${m.to} | ${m.subject}`));

// Editor chooses again from the kept list
const bre = await login('bre', 'ojsbre');
await bre.goto(`${B}/manageBookReviews/desk`);
const item = bre.locator('.ofrDeskItem', { hasText: 'A bidding test book' });
console.log('desk state:', (await item.locator('.ofrDeskState').innerText()).replace(/\s+/g, ' ').trim());
console.log('bidders:', (await item.locator('.ofrBidders tbody tr').allInnerTexts()).map(t => t.replace(/\s+/g, ' ')));
console.log('offer actions:', (await item.locator('.ofrOfferActions a').allInnerTexts()).map(t => t.trim()));
await item.locator('a', { hasText: 'Select and send contract' }).click();
let form = await modalForm(bre);
console.log('subject (after dropout):', await form.locator('input[name=subject]').inputValue());
// Removing the link placeholder is refused
const original = await bodyText(bre);
await bre.evaluate(() => { const ed = tinymce.get(document.querySelector('#ofrReservationEmailForm [name=body]').id); ed.setContent('<p>No link here</p>'); ed.fire('change'); });
await form.locator('button.submitFormButton').click();
await bre.waitForTimeout(2500);
console.log('validation:', (await bre.locator('#ofrReservationEmailForm .pkp_form_error, #ofrReservationEmailForm .error, #ofrReservationEmailForm [id*=error]').allInnerTexts()).map(t => t.trim()).filter(Boolean).slice(0, 2));
await bre.evaluate(b => { const ed = tinymce.get(document.querySelector('#ofrReservationEmailForm [name=body]').id); ed.setContent(b); ed.fire('change'); }, original);
const t1 = since();
await Promise.all([bre.waitForNavigation({ timeout: 20000 }), bre.locator('#ofrReservationEmailForm button.submitFormButton').click()]);
console.log('offer-again mail:', mails(t1).map(m => `${m.to} | ${m.subject}`));

// The selected bidder declines on the logged-in contract page
const r2 = await login('reviewer2', 'ojsreviewer2');
await r2.goto(`${B}/books-for-review`);
console.log('reviewer2 status:', await status(r2));
await objectRow(r2).locator('a', { hasText: 'Respond to the offer' }).click();
await r2.waitForLoadState();
await r2.locator('.ofrDecline summary').click();
await r2.locator('textarea[name=declineReason]').fill('Sorry, no time this semester.');
const t2 = since();
await Promise.all([r2.waitForNavigation(), r2.locator('.ofrDeclineForm button[type=submit]').click()]);
console.log('after decline:', await r2.locator('h1').innerText());
const declined = mails(t2);
console.log('declined mail:', declined.map(m => `${m.to} | ${m.subject}`), '| reason in mail:', /no time this semester/.test(declined[0]?.body));

// Old leave link: reviewer2 is no longer on the list
const anon = await anonymous();
await anon.goto(leaveLink);
console.log('old leave link:', await anon.locator('h1').innerText());

// Editor reopens bidding
await bre.goto(`${B}/manageBookReviews/desk`);
const item2 = bre.locator('.ofrDeskItem', { hasText: 'A bidding test book' });
console.log('desk state:', (await item2.locator('.ofrDeskState').innerText()).replace(/\s+/g, ' ').trim(), '|', (await item2.locator('p').last().innerText()).trim());
console.log('offer actions:', (await item2.locator('.ofrOfferActions a').allInnerTexts()).map(t => t.trim()));
await bre.screenshot({ path: OUT + 'p1-desk-dropout.png', fullPage: true });
await item2.locator('a', { hasText: 'Reopen bidding' }).click();
await bre.locator('.pkp_modal_confirmation .ok, .pkp_modal .pkpModalConfirmButton, .pkp_modal button.ok').first().click().catch(async () => {
	console.log('confirm buttons:', await bre.locator('.pkp_modal button').allInnerTexts());
});
await bre.waitForTimeout(3000);
console.log('desk after reopen:', (await bre.locator('.bookReviewManagerManagement').innerText()).replace(/\s+/g, ' ').slice(0, 100));
await anon.goto(`${B}/books-for-review`);
console.log('public:', await status(anon));

// History
await bre.goto(`${B}/manageBookReviews/assignments?status=all`);
await bre.locator('.ofrTable tbody tr', { hasText: 'A bidding test book' }).first().locator('a', { hasText: 'History' }).click();
const hist = bre.locator('.ofrHistory');
await hist.waitFor({ timeout: 15000 });
console.log('history:\n  ' + (await hist.locator('.ofrHistoryHead').allInnerTexts()).map(t => t.replace(/\s+/g, ' ')).join('\n  '));
await browser.close();
