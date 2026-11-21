import { B, OUT, browser, login, anonymous, since, mails, modalForm } from './lib.mjs';
import { execSync } from 'node:child_process';
const sql = q => execSync(`podman exec ojs-bookreviewmanager mysql -N ojs -e ${JSON.stringify(q)}`).toString().trim();
const TITLE = 'A link test book';
const objectRow = p => p.locator('#book-review-manager-table tr', { hasText: TITLE });
const status = async p => (await objectRow(p).locator('td').last().innerText()).replace(/\s+/g, ' ').trim();
const linkFor = (m, action) => { const all = [...m.body.matchAll(/https?:\/\/[^\s"<>]*books-for-review\/link\/[0-9a-f]{48}/g)].map(x => x[0]); return all[0]; };
const copyStatus = id => sql(`SELECT setting_value FROM brm_assignment_settings WHERE assignment_id=${id} AND setting_name='copyStatus'`);

const objectId = sql(`SELECT object_id FROM brm_object_settings WHERE setting_name='title' AND setting_value LIKE '%${TITLE}%'`);
const aId = sql(`SELECT assignment_id FROM brm_assignments WHERE object_id=${objectId} AND status='accepted'`);
const bre = await login('bre', 'ojsbre');
const anon = await anonymous();
let t, mf;
const sentMail = mails(new Date(Date.now() - 10 * 60e3).toISOString()).filter(m => /on its way/.test(m.subject)).pop();
console.log('auto copy-sent mail:', sentMail?.to, '|', sentMail?.subject);
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
