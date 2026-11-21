import { B, browser, login, anonymous, since, mails, linkIn, modalForm } from './lib.mjs';
import { execSync } from 'node:child_process';
const sql = q => execSync(`podman exec ojs-bookreviewmanager mysql -N ojs -e ${JSON.stringify(q)}`).toString().trim();
const TITLE = 'Courts and Crises';
const statuses = id => sql(`SELECT GROUP_CONCAT(CONCAT(u.username,':',a.status) ORDER BY a.assignment_id) FROM brm_assignments a JOIN users u USING(user_id) WHERE a.object_id=${id}`);

const bre = await login('bre', 'ojsbre');
// New bidding offer
await bre.goto(`${B}/manageBookReviews`);
await bre.locator('#bookReviewManagerGridContainer a[id*="addAvailableObjectForReview"]').click();
const form = bre.locator('#availableObjectsForReviewForm');
await form.waitFor({ timeout: 15000 });
await bre.waitForTimeout(1500);
await form.locator('[name=identifierType]').selectOption('ISBN');
await form.locator('input[name=identifier]').fill('9780000000019');
await form.locator('input[name=authors]').fill('Smith, Ana');
await bre.locator('#availableObjectsForReviewForm [name=title]').fill(TITLE);
await form.locator('input[name=publisher]').fill('Test Press');
await form.locator('[name=assignmentMode]').selectOption('bidding');
await form.locator('button.submitFormButton').click();
await bre.waitForTimeout(3000);
const id = sql(`SELECT object_id FROM brm_object_settings WHERE setting_name='title' AND setting_value LIKE '%${TITLE}%' ORDER BY object_id DESC LIMIT 1`);

const page = `${B}/manageBookReviews/invite/${id}`;
await bre.goto(page);
console.log('head:', (await bre.locator('.ofrInviteHead').innerText()).replace(/\s+/g, ' ').slice(60, 260));
for (const q of ['Rossi', 'Becker']) {
	await bre.goto(`${page}?q=${q}`);
	await Promise.all([bre.waitForNavigation(), bre.locator('button', { hasText: 'Add to list' }).first().click()]);
}
const sendTo = async name => {
	await bre.locator('.ofrInviteList tr', { hasText: name }).locator('a', { hasText: /Send invitation|Invite again/ }).click();
	const f = await modalForm(bre);
	console.log(`modal for ${name}:`, (await f.locator('.ofrStepDescription').innerText()).trim());
	const t = since();
	await Promise.all([bre.waitForNavigation({ timeout: 20000 }), f.locator('button.submitFormButton').click()]);
	const mail = mails(t).find(m => /Invitation/.test(m.subject));
	console.log(`mail to ${mail?.to} | ${mail?.subject}`);
	return linkIn(mail?.body || '');
};

// Maria applies through the link
let link = await sendTo('Maria Rossi');
const maria = await anonymous();
await maria.goto(link);
console.log('maria page:', await maria.locator('h1').innerText(), '| statement field:', await maria.locator('#ofrStatement').count());
await maria.fill('#ofrStatement', 'I wrote my thesis on courts in times of crisis.');
let t = since();
await Promise.all([maria.waitForNavigation(), maria.locator('.ofrInvitationForm button[type=submit]').click()]);
console.log('maria after apply:', await maria.locator('h1').innerText(), '| mails:', mails(t).map(m => `${m.to} | ${m.subject}`));

// Jonas: invitation expires (daily task)
await sendTo('Jonas Becker');
const jonasAid = sql(`SELECT a.assignment_id FROM brm_assignments a JOIN users u USING(user_id) WHERE a.object_id=${id} AND u.username='reviewer2'`);
sql(`UPDATE brm_assignment_settings SET setting_value=DATE_FORMAT(CURDATE() - INTERVAL 1 DAY, '%Y-%m-%d') WHERE assignment_id=${jonasAid} AND setting_name='invitationDeadline'`);
const out = execSync(`podman exec -w /var/www/html ojs-bookreviewmanager php lib/pkp/tools/scheduler.php test --name="APP\\plugins\\generic\\bookReviewManager\\classes\\ReservationReminder" 2>&1`).toString();
console.log('task:', (out.match(/expired invitation[^\n]*|For Test Journal[^\n]*/g) || []).slice(0, 2), '| notice:', /Invitation not accepted/.test(out));
console.log('statuses:', statuses(id));

// To-do: Maria's application is there
await bre.goto(`${B}/manageBookReviews/desk`);
const item = bre.locator('.ofrDeskItem', { hasText: TITLE });
console.log('To-do bidders:', (await item.locator('.ofrBidders tbody tr').allInnerTexts()).map(r => r.replace(/\s+/g, ' ').slice(0, 120)));

// Invitation list now
await bre.goto(page);
console.log('list:', (await bre.locator('.ofrInviteList tbody tr').allInnerTexts()).map(r => r.replace(/\s+/g, ' ').replace(/Reviews for.*?(?=Bid|Invit|On the)/, '').slice(0, 120)));

// Re-invite Jonas manually, then close the offer with Anna still listed
await bre.goto(`${page}?q=Weber`);
await Promise.all([bre.waitForNavigation(), bre.locator('button', { hasText: 'Add to list' }).first().click()]);
link = await sendTo('Jonas Becker');
await bre.goto(`${B}/manageBookReviews/desk`);
await bre.locator('.ofrDeskItem', { hasText: TITLE }).locator('a', { hasText: 'Close offer' }).click();
await bre.locator('button', { hasText: 'OK' }).first().click();
await bre.waitForTimeout(3000);
console.log('after close:', statuses(id));
const jonas = await anonymous();
await jonas.goto(link);
console.log('jonas link after close:', await jonas.locator('h1').innerText());
await browser.close();
