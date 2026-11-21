import { B, OUT, browser, login, anonymous, since, mails, linkIn, modalForm } from './lib.mjs';
import { execSync } from 'node:child_process';
const objectRow = p => p.locator('#book-review-manager-table tr', { hasText: 'A bidding test book' });
const status = async p => (await objectRow(p).locator('td').last().innerText()).replace(/\s+/g, ' ').trim();
const sql = q => execSync(`podman exec ojs-bookreviewmanager mysql -N ojs -e ${JSON.stringify(q)}`).toString().trim();
const scheduler = () => execSync(`podman exec ojs-bookreviewmanager sh -c 'cd /var/www/html && runuser -u www-data -- php lib/pkp/tools/scheduler.php test --name="APP\\\\plugins\\\\generic\\\\bookReviewManager\\\\classes\\\\ReservationReminder"' 2>&1`).toString().trim();
async function confirmAction(page, item, label) {
	await item.locator('a', { hasText: label }).click();
	await page.locator('button:visible', { hasText: 'OK' }).click();
	await page.waitForTimeout(3000);
}

const bre = await login('bre', 'ojsbre');
await bre.goto(`${B}/manageBookReviews/desk`);
await confirmAction(bre, bre.locator('.ofrDeskItem', { hasText: 'A bidding test book' }), 'Reopen bidding');
const anon = await anonymous();
await anon.goto(`${B}/books-for-review`);
console.log('public after reopen:', await status(anon));

const r2 = await login('reviewer2', 'ojsreviewer2');
await r2.goto(`${B}/books-for-review`);
await objectRow(r2).locator('.ofrBidButton').click();
await Promise.all([r2.waitForNavigation(), r2.locator('#ofrBidDialog button[type=submit]').click()]);
console.log('reviewer2 bid again:', await status(r2));

await bre.goto(`${B}/manageBookReviews/desk`);
await bre.locator('.ofrDeskItem', { hasText: 'A bidding test book' }).locator('a', { hasText: 'Select and send contract' }).click();
const form = await modalForm(bre);
await Promise.all([bre.waitForNavigation({ timeout: 20000 }), form.locator('button.submitFormButton').click()]);

const objectId = sql("SELECT object_id FROM brm_object_settings WHERE setting_name='title' AND setting_value LIKE '%bidding test%'");
const assignmentId = sql(`SELECT assignment_id FROM brm_assignments WHERE object_id=${objectId} AND status='offered'`);
console.log('offered assignment:', assignmentId);

// 8 days later: reminder with a fresh link
sql(`UPDATE brm_assignment_settings SET setting_value=DATE_SUB(NOW(), INTERVAL 8 DAY) WHERE assignment_id=${assignmentId} AND setting_name='dateOffered'`);
let t = since();
console.log('scheduler:', scheduler().split('\n').filter(l => /reminder|Für|For |ERROR|rror/i.test(l)).slice(0, 3));
const reminder = mails(t).filter(m => /respond to the review offer/.test(m.subject));
console.log('reminder mail:', reminder.map(m => `${m.to} | ${m.subject}`), '| has link:', !!linkIn(reminder[0]?.body || ''));
t = since();
scheduler();
console.log('second run sends nothing:', mails(t).length === 0);

// Deadline passed: offer expires, editor notified
sql(`UPDATE brm_assignment_settings SET setting_value=DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 1 DAY), '%Y-%m-%d') WHERE assignment_id=${assignmentId} AND setting_name='contractDeadline'`);
t = since();
scheduler();
console.log('expiry mails:', mails(t).map(m => `${m.to} | ${m.subject}`));
console.log('status now:', sql(`SELECT status FROM brm_assignments WHERE assignment_id=${assignmentId}`));
const reminderLink = linkIn(reminder[0]?.body || '');
await anon.goto(reminderLink);
console.log('reminder link after expiry:', await anon.locator('h1').innerText());

await bre.goto(`${B}/manageBookReviews/desk`);
const item = bre.locator('.ofrDeskItem', { hasText: 'A bidding test book' });
console.log('desk state:', (await item.locator('.ofrDeskState').innerText()).replace(/\s+/g, ' ').trim());

// Close and reopen the offer
await confirmAction(bre, item, 'Close offer');
await anon.goto(`${B}/books-for-review`);
console.log('public rows with the book after close:', await objectRow(anon).count());
await bre.goto(`${B}/manageBookReviews/desk`);
await bre.locator('.ofrClosed summary').click();
console.log('closed list:', (await bre.locator('.ofrClosed tbody tr').allInnerTexts()).map(t => t.replace(/\s+/g, ' ').slice(0, 90)));
await confirmAction(bre, bre.locator('.ofrClosed tr', { hasText: 'A bidding test book' }), 'Reopen offer');
await anon.goto(`${B}/books-for-review`);
console.log('public after reopen offer:', await status(anon));
console.log('statuses:', sql(`SELECT GROUP_CONCAT(status ORDER BY assignment_id) FROM brm_assignments WHERE object_id=${objectId}`));
await browser.close();
