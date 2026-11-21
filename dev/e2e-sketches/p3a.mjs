import { B, OUT, browser, login, anonymous, since, mails, modalForm } from './lib.mjs';
import { execSync } from 'node:child_process';
const sql = q => execSync(`podman exec ojs-bookreviewmanager mysql -N ojs -e ${JSON.stringify(q)}`).toString().trim();
const TITLE = 'A link test book';
const objectRow = p => p.locator('#book-review-manager-table tr', { hasText: TITLE });
const status = async p => (await objectRow(p).locator('td').last().innerText()).replace(/\s+/g, ' ').trim();
const linkFor = (m, action) => { const all = [...m.body.matchAll(/https?:\/\/[^\s"<>]*books-for-review\/link\/[0-9a-f]{48}/g)].map(x => x[0]); return all[0]; };
const copyStatus = id => sql(`SELECT setting_value FROM brm_assignment_settings WHERE assignment_id=${id} AND setting_name='copyStatus'`);

const bre = await login('bre', 'ojsbre');
await bre.goto(`${B}/manageBookReviews`);
await bre.locator('#bookReviewManagerGridContainer a[id*="addAvailableObjectForReview"]').click();
const form = bre.locator('#availableObjectsForReviewForm');
await form.waitFor({ timeout: 15000 });
await bre.waitForTimeout(1500);
await form.locator('[name=identifierType]').selectOption('ISBN');
await form.locator('input[name=identifier]').fill('9780000000019');
await bre.locator('#availableObjectsForReviewForm [name=title]').fill(TITLE);
await form.locator('[name=assignmentMode]').selectOption('direct');
await form.locator('[name=publisherContactId]').selectOption({ label: 'Paula Publisher (publisher@example.org)' });
await form.locator('button.submitFormButton').click();
await bre.waitForTimeout(3000);
const objectId = sql(`SELECT object_id FROM brm_object_settings WHERE setting_name='title' AND setting_value LIKE '%${TITLE}%'`);

// Reservation: confirmation with retract link
const author = await login('author', 'ojsauthor');
await author.goto(`${B}/books-for-review`);
await objectRow(author).locator('.ofrReserveButton').click();
const d = author.locator('#ofrReserveDialog');
await d.locator('input[name=acceptTerms]').check();
await d.locator('input[value=physical]').check();
await d.locator('textarea[name=shippingAddress]').fill('Author Street 9\n10115 Berlin');
await d.locator('input[name=phone]').fill('+49 30 999999');
let t = since();
await Promise.all([author.waitForNavigation(), d.locator('button[type=submit]').click()]);
const conf = mails(t).find(m => /confirmation/.test(m.subject));
console.log('confirmation:', conf?.to, '|', conf?.subject, '| retract link:', !!linkFor(conf || { body: '' }));
const aId = sql(`SELECT assignment_id FROM brm_assignments WHERE object_id=${objectId} AND status='accepted'`);

// Editor requests the copy: link for the publisher
await bre.goto(`${B}/manageBookReviews/desk`);
await bre.locator('.ofrCopies tbody tr', { hasText: TITLE }).locator('a', { hasText: 'Request copy from publisher' }).click();
let mf = await modalForm(bre);
t = since();
await Promise.all([bre.waitForNavigation({ timeout: 20000 }), mf.locator('button.submitFormButton').click()]);
const req = mails(t).find(m => /Request for a review copy/.test(m.subject));
const copySentLink = linkFor(req);
console.log('request to:', req.to, '| copySent link:', !!copySentLink);

// Publisher clicks
const anon = await anonymous();
await anon.goto(copySentLink);
console.log('page:', await anon.locator('h1').innerText(), '|', (await anon.locator('.ofrResponse > p').nth(1).innerText()).trim());
console.log('opening changed nothing:', copyStatus(aId));
await anon.screenshot({ path: OUT + 'p3-copysent-link.png' });
t = since();
await Promise.all([anon.waitForNavigation(), anon.locator('.ofrResponse form button').click()]);
console.log('after confirm:', await anon.locator('.ofrResponseMessage').innerText(), '| status:', copyStatus(aId));
const sentMail = mails(t).find(m => /on its way/.test(m.subject));
console.log('auto mail:', sentMail?.to, '|', sentMail?.subject, '| received link:', !!linkFor(sentMail || { body: '' }));
await anon.goto(copySentLink);
console.log('link again:', await anon.locator('h1').innerText());

// Reviewer clicks "received"
await anon.goto(linkFor(sentMail));
await Promise.all([anon.waitForNavigation(), anon.locator('.ofrResponse form button').click()]);
console.log('received:', copyStatus(aId), '| address left:', sql(`SELECT COUNT(*) FROM brm_assignment_settings WHERE assignment_id=${aId} AND setting_name IN ('shippingAddress','phone')`));

// Return to the publisher: reviewer and publisher confirm by link
await bre.goto(`${B}/manageBookReviews/assignments`);
await bre.locator('.ofrTable tbody tr', { hasText: TITLE }).locator('a', { hasText: 'Request return' }).click();
mf = await modalForm(bre);
await mf.locator('input[name=returnTarget][value=publisher]').check();
t = since();
await Promise.all([bre.waitForNavigation({ timeout: 20000 }), mf.locator('button.submitFormButton').click()]);
const retReq = mails(t).find(m => /return the review copy/.test(m.subject));
await anon.goto(linkFor(retReq));
console.log('return page:', await anon.locator('h1').innerText());
t = since();
await Promise.all([anon.waitForNavigation(), anon.locator('.ofrResponse form button').click()]);
const retSent = mails(t).find(m => /on its way back/.test(m.subject));
console.log('status:', copyStatus(aId), '| auto mail:', retSent?.to, '|', retSent?.subject);
await anon.goto(linkFor(retSent));
console.log('publisher page:', (await anon.locator('.ofrResponse > p').nth(1).innerText()).trim());
await Promise.all([anon.waitForNavigation(), anon.locator('.ofrResponse form button').click()]);
console.log('final status:', copyStatus(aId));

await bre.goto(`${B}/manageBookReviews/assignments?status=all`);
await bre.locator('.ofrTable tbody tr', { hasText: TITLE }).first().locator('a', { hasText: 'History' }).click();
const hist = bre.locator('.ofrHistory');
await hist.waitFor({ timeout: 15000 });
console.log('history:\n  ' + (await hist.locator('.ofrHistoryHead').allInnerTexts()).map(x => x.replace(/\s+/g, ' ')).join('\n  '));
await browser.close();
