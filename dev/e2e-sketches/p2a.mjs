import { B, OUT, browser, login, since, mails, modalForm, bodyText } from './lib.mjs';
import { execSync } from 'node:child_process';
const sql = q => execSync(`podman exec ojs-bookreviewmanager mysql -N ojs -e ${JSON.stringify(q)}`).toString().trim();
const TITLE = 'A copy test book';
const objectRow = p => p.locator('#book-review-manager-table tr', { hasText: TITLE });
const status = async p => (await objectRow(p).locator('td').last().innerText()).replace(/\s+/g, ' ').trim();
const formErrors = async p => (await p.locator('#availableObjectsForReviewForm .pkp_form_error, #availableObjectsForReviewForm label.error, #availableObjectsForReviewForm .error').allInnerTexts()).map(x => x.trim()).filter(Boolean);

// Offer with a publisher contact
const bre = await login('bre', 'ojsbre');
await bre.goto(`${B}/manageBookReviews`);
await bre.locator('#bookReviewManagerGridContainer a[id*="addAvailableObjectForReview"]').click();
const form = bre.locator('#availableObjectsForReviewForm');
await form.waitFor({ timeout: 15000 });
await bre.waitForTimeout(1500);
await form.locator('[name=identifierType]').selectOption('ISBN');
await form.locator('input[name=identifier]').fill('9780000000002');
await form.locator('input[name=authors]').fill('Roe, Richard');
await bre.locator('#availableObjectsForReviewForm [name=title]').fill(TITLE);
await form.locator('input[name=publisher]').fill('Test Press');
await form.locator('[name=assignmentMode]').selectOption('direct');
console.log('publisher contacts:', await form.locator('[name=publisherContactId] option').allInnerTexts(), '| return required by default:', await form.locator('input[name=returnRequired]').isChecked());
// No responsible editor + journal holds a copy: refused
await form.locator('[name=responsibleEditorId]').selectOption('');
await form.locator('input[name=journalHoldsCopy]').check();
await form.locator('button.submitFormButton').click();
await bre.waitForTimeout(2500);
console.log('validation:', await formErrors(bre));
await form.locator('[name=responsibleEditorId]').selectOption({ label: 'Book Review Editor (bre@example.org)' });
await form.locator('input[name=journalHoldsCopy]').uncheck();
await form.locator('[name=publisherContactId]').selectOption({ label: 'Paula Publisher (publisher@example.org)' });
await form.locator('input[name=notifyPublisher]').check();
await bre.screenshot({ path: OUT + 'p2-offerform.png', fullPage: true });
await form.locator('button.submitFormButton').click();
await bre.waitForTimeout(3000);
console.log('grid row:', (await bre.locator('#bookReviewManagerGridContainer tr.gridRow', { hasText: TITLE }).innerText()).replace(/\s+/g, ' '));
const objectId = sql(`SELECT object_id FROM brm_object_settings WHERE setting_name='title' AND setting_value LIKE '%${TITLE}%'`);
console.log('offer settings:', sql(`SELECT GROUP_CONCAT(CONCAT(setting_name,'=',setting_value)) FROM brm_object_settings WHERE object_id=${objectId} AND setting_name IN ('publisherContactId','journalHoldsCopy','returnRequired','notifyPublisher','responsibleEditorId')`));

// Direct reservation with a printed copy
const author = await login('author', 'ojsauthor');
await author.goto(`${B}/books-for-review`);
await objectRow(author).locator('.ofrReserveButton').click();
const d = author.locator('#ofrReserveDialog');
await d.locator('input[name=acceptTerms]').check();
await d.locator('input[value=physical]').check();
await d.locator('textarea[name=shippingAddress]').fill('Author Street 9\n10115 Berlin');
await d.locator('input[name=phone]').fill('+49 30 999999');
await Promise.all([author.waitForNavigation(), d.locator('button[type=submit]').click()]);
console.log('author:', await status(author));

// Editor requests the copy from the publisher
await bre.goto(`${B}/manageBookReviews/desk`);
let row = bre.locator('.ofrCopies tbody tr', { hasText: TITLE });
console.log('copy row:', (await row.innerText()).replace(/\s+/g, ' '));
await row.locator('a', { hasText: 'Request copy from publisher' }).click();
let mf = await modalForm(bre);
console.log('modal:', (await mf.locator('.ofrStepDescription').innerText()).trim(), '| to:', await mf.locator('.ofrRecipient').allInnerTexts(), '| optional:', await mf.locator('input[name=sendEmail]').count());
const reqBody = await bodyText(bre);
console.log('delivery details in body:', /Author Street 9/.test(reqBody) && /999999/.test(reqBody));
let t = since();
await Promise.all([bre.waitForNavigation({ timeout: 20000 }), mf.locator('button.submitFormButton').click()]);
console.log('mails:', mails(t).map(m => `${m.to} | ${m.subject}`));

row = bre.locator('.ofrCopies tbody tr', { hasText: TITLE });
console.log('copy row:', (await row.innerText()).replace(/\s+/g, ' '));
await row.locator('a', { hasText: 'Publisher has sent the copy' }).click();
mf = await modalForm(bre);
console.log('copy sent body:', (await bodyText(bre)).replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').slice(0, 160));
t = since();
await Promise.all([bre.waitForNavigation({ timeout: 20000 }), mf.locator('button.submitFormButton').click()]);
console.log('mails:', mails(t).map(m => `${m.to} | ${m.subject}`));

// Reviewer confirms receipt
await author.goto(`${B}/books-for-review`);
console.log('author:', await status(author));
await Promise.all([author.waitForNavigation(), objectRow(author).locator('.ofrReceivedButton').click()]);
console.log('author after receipt:', await status(author));
const aId = sql(`SELECT assignment_id FROM brm_assignments WHERE object_id=${objectId} AND status='accepted'`);
console.log('settings now:', sql(`SELECT GROUP_CONCAT(CONCAT(setting_name,'=',setting_value)) FROM brm_assignment_settings WHERE assignment_id=${aId} AND setting_name IN ('copyStatus','copySentBy','shippingAddress','phone')`));
await bre.goto(`${B}/manageBookReviews/desk`);
console.log('copy row after receipt:', await bre.locator('.ofrCopies tbody tr', { hasText: TITLE }).count());

// No review will come: request return (from the Reviewers page)
await bre.goto(`${B}/manageBookReviews/assignments`);
row = bre.locator('.ofrTable tbody tr', { hasText: TITLE });
console.log('assignment row:', (await row.innerText()).replace(/\s+/g, ' '));
await row.locator('a', { hasText: 'Request return' }).click();
mf = await modalForm(bre);
console.log('targets:', (await mf.locator('.ofrReturnTarget').allInnerTexts()).map(x => x.replace(/\s+/g, ' ')), '| checked:', await mf.locator('input[name=returnTarget]:checked').inputValue());
await bre.screenshot({ path: OUT + 'p2-return-modal.png' });
await mf.locator('input[name=returnTarget][value=journal]').check();
t = since();
await Promise.all([bre.waitForNavigation({ timeout: 20000 }), mf.locator('button.submitFormButton').click()]);
const ret = mails(t);
console.log('return mail:', ret.map(m => `${m.to} | ${m.subject}`), '| address filled:', /Example Street 1/.test(ret[0]?.body || ''), '| placeholder left:', /returnAddress/.test(ret[0]?.body || ''));
console.log('assignment status:', sql(`SELECT status FROM brm_assignments WHERE assignment_id=${aId}`));

await bre.goto(`${B}/manageBookReviews/desk`);
row = bre.locator('.ofrCopies tbody tr', { hasText: TITLE });
console.log('copy row:', (await row.innerText()).replace(/\s+/g, ' '));
await row.locator('a', { hasText: 'Copy has arrived back' }).click();
mf = await modalForm(bre);
console.log('no email fields:', await mf.locator('input[name=subject]').count() === 0);
await Promise.all([bre.waitForNavigation({ timeout: 20000 }), mf.locator('button.submitFormButton').click()]);
console.log('journalHoldsCopy:', sql(`SELECT setting_value FROM brm_object_settings WHERE object_id=${objectId} AND setting_name='journalHoldsCopy'`));
console.log('copy rows left:', await bre.locator('.ofrCopies tbody tr', { hasText: TITLE }).count());
await author.goto(`${B}/books-for-review`);
console.log('offered again:', await status(author));
await browser.close();
