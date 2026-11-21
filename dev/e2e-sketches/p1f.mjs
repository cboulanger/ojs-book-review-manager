import { B, OUT, browser, login, anonymous, linkIn, modalForm } from './lib.mjs';
import { execSync } from 'node:child_process';
const objectRow = p => p.locator('#book-review-manager-table tr', { hasText: 'A bidding test book' });
const status = async p => (await objectRow(p).locator('td').last().innerText()).replace(/\s+/g, ' ').trim();
const sql = q => execSync(`podman exec ojs-bookreviewmanager mysql -N ojs -e ${JSON.stringify(q)}`).toString().trim();
const scheduler = () => execSync(`podman exec ojs-bookreviewmanager sh -c 'cd /var/www/html && runuser -u www-data -- php lib/pkp/tools/scheduler.php test --name="APP\\\\plugins\\\\generic\\\\bookReviewManager\\\\classes\\\\ReservationReminder"' 2>&1`).toString();
const subjects = out => [...out.matchAll(/\nTo: (.*)\r?\nSubject: (.*)/g)].map(m => `${m[1].trim()} | ${m[2].trim()}`);
async function confirmAction(page, item, label) {
	await item.locator('a', { hasText: label }).click();
	await page.locator('button:visible', { hasText: 'OK' }).click();
	await page.waitForTimeout(3000);
}
const objectId = sql("SELECT object_id FROM brm_object_settings WHERE setting_name='title' AND setting_value LIKE '%bidding test%'");

const bre = await login('bre', 'ojsbre');
await bre.goto(`${B}/manageBookReviews/desk`);
let item = bre.locator('.ofrDeskItem', { hasText: 'A bidding test book' });
console.log('desk after expiry:', (await item.locator('.ofrDeskState').innerText()).replace(/\s+/g, ' ').trim());
await confirmAction(bre, item, 'Reopen bidding');

const author = await login('author', 'ojsauthor');
await author.goto(`${B}/books-for-review`);
await objectRow(author).locator('.ofrBidButton').click();
await Promise.all([author.waitForNavigation(), author.locator('#ofrBidDialog button[type=submit]').click()]);
await bre.goto(`${B}/manageBookReviews/desk`);
await bre.locator('.ofrDeskItem', { hasText: 'A bidding test book' }).locator('a', { hasText: 'Select and send contract' }).click();
const form = await modalForm(bre);
await Promise.all([bre.waitForNavigation({ timeout: 20000 }), form.locator('button.submitFormButton').click()]);
const assignmentId = sql(`SELECT assignment_id FROM brm_assignments WHERE object_id=${objectId} AND status='offered'`);

sql(`UPDATE brm_assignment_settings SET setting_value=DATE_SUB(NOW(), INTERVAL 8 DAY) WHERE assignment_id=${assignmentId} AND setting_name='dateOffered'`);
let out = scheduler();
console.log('run 1:', subjects(out));
const reminderLink = linkIn(out.replace(/=\r?\n/g, ''));
console.log('run 2:', subjects(scheduler()));
sql(`UPDATE brm_assignment_settings SET setting_value=DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 1 DAY), '%Y-%m-%d') WHERE assignment_id=${assignmentId} AND setting_name='contractDeadline'`);
out = scheduler();
console.log('run 3:', subjects(out), '| expired text:', /not answered by the deadline/.test(out));
console.log('status:', sql(`SELECT status FROM brm_assignments WHERE assignment_id=${assignmentId}`));
console.log('task log:', (out.match(/For Test Journal:[^\n]*/) || ['(none)'])[0]);

const anon = await anonymous();
await anon.goto(reminderLink);
console.log('reminder link after expiry:', await anon.locator('h1').innerText());

await bre.goto(`${B}/manageBookReviews/desk`);
item = bre.locator('.ofrDeskItem', { hasText: 'A bidding test book' });
console.log('desk:', (await item.locator('.ofrDeskState').innerText()).replace(/\s+/g, ' ').trim());
await confirmAction(bre, item, 'Close offer');
await anon.goto(`${B}/books-for-review`);
console.log('public rows after close:', await objectRow(anon).count());
await bre.goto(`${B}/manageBookReviews/desk`);
await bre.locator('.ofrClosed summary').click();
await confirmAction(bre, bre.locator('.ofrClosed tr', { hasText: 'A bidding test book' }), 'Reopen offer');
await anon.goto(`${B}/books-for-review`);
console.log('public after reopen offer:', await status(anon));
await bre.goto(`${B}/manageBookReviews/desk`);
item = bre.locator('.ofrDeskItem', { hasText: 'A bidding test book' });
await confirmAction(bre, item, 'Switch to direct reservation');
await anon.goto(`${B}/books-for-review`);
console.log('public after switch to direct:', await status(anon));
await author.goto(`${B}/books-for-review`);
console.log('author sees:', await status(author));
console.log('statuses:', sql(`SELECT GROUP_CONCAT(status ORDER BY assignment_id) FROM brm_assignments WHERE object_id=${objectId}`));
await browser.close();
