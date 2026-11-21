import { B, OUT, browser, login, since, mails, modalForm, bodyText } from './lib.mjs';
import { execSync } from 'node:child_process';
const sql = q => execSync(`podman exec ojs-bookreviewmanager mysql -N ojs -e ${JSON.stringify(q)}`).toString().trim();
const TITLE = 'A copy test book';
const objectRow = p => p.locator('#book-review-manager-table tr', { hasText: TITLE });
const status = async p => (await objectRow(p).locator('td').last().innerText()).replace(/\s+/g, ' ').trim();
const objectId = sql(`SELECT object_id FROM brm_object_settings WHERE setting_name='title' AND setting_value LIKE '%${TITLE}%'`);

const r2 = await login('reviewer2', 'ojsreviewer2');
await r2.goto(`${B}/books-for-review`);
await objectRow(r2).locator('.ofrReserveButton').click();
const d = r2.locator('#ofrReserveDialog');
await d.locator('input[name=acceptTerms]').check();
await d.locator('input[value=electronic]').check();
await Promise.all([r2.waitForNavigation(), d.locator('button[type=submit]').click()]);

const bre = await login('bre', 'ojsbre');
await bre.goto(`${B}/manageBookReviews/desk`);
let row = bre.locator('.ofrCopies tbody tr', { hasText: TITLE });
console.log('copy row:', (await row.innerText()).replace(/\s+/g, ' '));
await row.locator('a', { hasText: 'Send copy from journal stock' }).click();
let mf = await modalForm(bre);
await mf.locator('input[name=sendEmail]').uncheck();
let t = since();
await Promise.all([bre.waitForNavigation({ timeout: 20000 }), mf.locator('button.submitFormButton').click()]);
console.log('mails (unchecked):', mails(t).length, '| journalHoldsCopy:', sql(`SELECT COUNT(*) FROM brm_object_settings WHERE object_id=${objectId} AND setting_name='journalHoldsCopy'`));
row = bre.locator('.ofrCopies tbody tr', { hasText: TITLE });
console.log('copy row:', (await row.innerText()).replace(/\s+/g, ' '));
await row.locator('a', { hasText: 'Mark as received' }).click();
mf = await modalForm(bre);
await Promise.all([bre.waitForNavigation({ timeout: 20000 }), mf.locator('button.submitFormButton').click()]);
console.log('copy rows:', await bre.locator('.ofrCopies tbody tr', { hasText: TITLE }).count());

// Review attached and published
console.log(execSync(`podman cp ../ofrPublishTest.php ojs-bookreviewmanager:/var/www/html/tools/ofrPublishTest.php && podman exec ojs-bookreviewmanager sh -c 'cd /var/www/html && runuser -u www-data -- php tools/ofrPublishTest.php ${objectId} 2>&1 | grep status; rm tools/ofrPublishTest.php'`).toString().trim());
await bre.goto(`${B}/manageBookReviews/desk`);
row = bre.locator('.ofrTable tbody tr', { hasText: TITLE }).last();
console.log('notification row:', (await row.innerText()).replace(/\s+/g, ' '));
await row.locator('a', { hasText: 'Notify publisher' }).click();
mf = await modalForm(bre);
const body = await bodyText(bre);
console.log('to:', await mf.locator('.ofrRecipient').allInnerTexts(), '| review link:', (body.match(/article\/view\/\S+?"/) || [''])[0]);
t = since();
await Promise.all([bre.waitForNavigation({ timeout: 20000 }), mf.locator('button.submitFormButton').click()]);
console.log('mails:', mails(t).map(m => `${m.to} | ${m.subject}`));
console.log('desk:', (await bre.locator('.bookReviewManagerManagement').innerText()).replace(/\s+/g, ' ').slice(0, 80));

await bre.goto(`${B}/manageBookReviews/assignments?status=all`);
await bre.locator('.ofrTable tbody tr', { hasText: TITLE }).first().locator('a', { hasText: 'History' }).click();
const hist = bre.locator('.ofrHistory');
await hist.waitFor({ timeout: 15000 });
console.log('history:\n  ' + (await hist.locator('.ofrHistoryHead').allInnerTexts()).map(x => x.replace(/\s+/g, ' ')).join('\n  '));
await browser.close();
