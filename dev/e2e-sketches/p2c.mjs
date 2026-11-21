import { B, OUT, browser, login, since, mails, modalForm } from './lib.mjs';
import { execSync } from 'node:child_process';
const sql = q => execSync(`podman exec ojs-bookreviewmanager mysql -N ojs -e ${JSON.stringify(q)}`).toString().trim();
const bre = await login('bre', 'ojsbre');

// Edit offer 1: validation message, then add the publisher contact
await bre.goto(`${B}/manageBookReviews`);
const gridRow = bre.locator('#bookReviewManagerGridContainer tr.gridRow', { hasText: 'Analysis of Large and Complex Data' });
await gridRow.locator('a.show_extras').click();
await bre.locator('a[id*="grid-row-1-edit-button"]').click();
const form = bre.locator('#availableObjectsForReviewForm');
await form.waitFor({ timeout: 15000 });
await bre.waitForTimeout(1500);
await form.locator('[name=responsibleEditorId]').selectOption('');
await form.locator('input[name=journalHoldsCopy]').check();
await form.locator('input[name=notifyPublisher]').check();
await form.locator('button.submitFormButton').click();
await bre.waitForTimeout(2500);
const text = await form.innerText();
console.log('errors shown:', ['responsible book review editor', 'publisher contact who is to be notified'].map(s => text.includes(s)));
await bre.screenshot({ path: OUT + 'p2-validation.png', fullPage: true });
await form.locator('input[name=journalHoldsCopy]').uncheck();
await form.locator('input[name=notifyPublisher]').uncheck();
await form.locator('[name=publisherContactId]').selectOption({ label: 'Paula Publisher (publisher@example.org)' });
await form.locator('button.submitFormButton').click();
await bre.waitForTimeout(3000);
console.log('saved contact:', sql("SELECT setting_value FROM brm_object_settings WHERE object_id=1 AND setting_name='publisherContactId'"));

const step = async (label, { uncheck = false, target = null } = {}) => {
	await bre.goto(`${B}/manageBookReviews/desk`);
	const row = bre.locator('.ofrCopies tbody tr').filter({ has: bre.locator('a', { hasText: label }) }).first();
	await row.locator('a', { hasText: label }).click();
	const mf = await modalForm(bre);
	if (uncheck) await mf.locator('input[name=sendEmail]').uncheck();
	if (target) await mf.locator(`input[name=returnTarget][value=${target}]`).check();
	const t = since();
	await Promise.all([bre.waitForNavigation({ timeout: 20000 }), mf.locator('button.submitFormButton').click()]);
	const status = sql("SELECT setting_value FROM brm_assignment_settings WHERE assignment_id=1 AND setting_name='copyStatus'");
	console.log(`${label} -> ${status}`, mails(t).map(m => `${m.to} | ${m.subject}`));
};
await step('Request copy from publisher');
await step('Withdraw copy request');
console.log('copy rows after withdraw:', await bre.locator('.ofrCopies tbody tr').count());
sql("UPDATE brm_assignment_settings SET setting_value='pending' WHERE assignment_id=1 AND setting_name='copyStatus'");
await step('Request copy from publisher');
await step('Publisher has sent the copy', { uncheck: true });
await step('Mark as received');
// Return to the publisher, from the Reviewers page
await bre.goto(`${B}/manageBookReviews/assignments`);
const row = bre.locator('.ofrTable tbody tr').filter({ has: bre.locator('a', { hasText: 'Request return' }) }).first();
await row.locator('a', { hasText: 'Request return' }).click();
let mf = await modalForm(bre);
await mf.locator('input[name=returnTarget][value=publisher]').check();
let t = since();
await Promise.all([bre.waitForNavigation({ timeout: 20000 }), mf.locator('button.submitFormButton').click()]);
console.log('return mail has publisher address:', /Publisher Lane 5/.test(mails(t)[0]?.body || ''));
await step('Copy has been sent back');
await step('Copy has arrived back');
console.log('journalHoldsCopy (should be empty):', sql("SELECT setting_value FROM brm_object_settings WHERE object_id=1 AND setting_name='journalHoldsCopy'"));

await bre.goto(`http://localhost:8080/index.php/test/de/manageBookReviews/assignments?status=all`);
console.log('de missing keys:', [...new Set(((await bre.locator('body').innerText()).match(/##[^#\s]+##/g) || []))].filter(k => !k.includes('common.help')));
await bre.locator('.ofrTable tbody tr').first().locator('a', { hasText: 'Verlauf' }).click();
const hist = bre.locator('.ofrHistory');
await hist.waitFor({ timeout: 15000 });
console.log('history (de):\n  ' + (await hist.locator('.ofrHistoryHead').allInnerTexts()).slice(0, 12).map(x => x.replace(/\s+/g, ' ')).join('\n  '));
await browser.close();
