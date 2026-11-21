import { B, browser, login, anonymous, since, mails, linkIn, modalForm, bodyText } from './lib.mjs';
import { execSync } from 'node:child_process';
const sql = q => execSync(`podman exec ojs-bookreviewmanager mysql -N ojs -e ${JSON.stringify(q)}`).toString().trim();
const IMG = new URL('../../docs/images/', import.meta.url).pathname;
const st = id => sql(`SELECT GROUP_CONCAT(CONCAT(u.username,':',a.status) ORDER BY a.assignment_id) FROM brm_assignments a JOIN users u USING(user_id) WHERE a.object_id=${id}`);
const roundSettings = id => sql(`SELECT COUNT(*) FROM brm_object_settings WHERE object_id=${id} AND setting_name LIKE 'invitation%'`);
const events = id => sql(`SELECT GROUP_CONCAT(REPLACE(message,'plugins.generic.bookReviewManager.history.event.','') ORDER BY log_id SEPARATOR ', ') FROM event_log WHERE assoc_id=${id} AND assoc_type=${0x1F0F001}`);
const out = [];
const log = (...a) => { console.log(...a); };

const bre = await login('bre', 'ojsbre');
await bre.setViewportSize({ width: 1280, height: 900 });
async function newOffer(title, mode, deadline) {
	await bre.goto(`${B}/manageBookReviews`);
	await bre.locator('#bookReviewManagerGridContainer a[id*="addAvailableObjectForReview"]').click();
	const f = bre.locator('#availableObjectsForReviewForm');
	await f.waitFor({ timeout: 15000 });
	await bre.waitForTimeout(1500);
	await f.locator('[name=identifierType]').selectOption('ISBN');
	await f.locator('input[name=identifier]').fill('97800000000' + Math.floor(Math.random() * 90 + 10));
	await f.locator('input[name=authors]').fill('Doe, Jo');
	await bre.locator('#availableObjectsForReviewForm [name=title]').fill(title);
	await f.locator('input[name=publisher]').fill('Test Press');
	await f.locator('[name=assignmentMode]').selectOption(mode);
	if (deadline) await f.locator('input[name=bidDeadline]').fill(deadline);
	await f.locator('button.submitFormButton').click();
	await bre.waitForTimeout(3000);
	return sql(`SELECT object_id FROM brm_object_settings WHERE setting_name='title' AND setting_value LIKE '%${title}%' ORDER BY object_id DESC LIMIT 1`);
}
async function addPeople(id, queries) {
	for (const q of queries) {
		await bre.goto(`${B}/manageBookReviews/invite/${id}?q=${encodeURIComponent(q)}`);
		await Promise.all([bre.waitForNavigation(), bre.locator('button', { hasText: 'Add to list' }).first().click()]);
	}
}
const linkFor = (ms, email) => linkIn(ms.find(m => m.to?.includes(email))?.body || '');

// 1. All at once on a bidding offer with a bid deadline
const deadline = new Date(Date.now() + 10 * 864e5).toISOString().slice(0, 10);
const id = await newOffer('Comparative Constitutionalism', 'bidding', deadline);
await addPeople(id, ['Rossi', 'Becker', 'Tester']);
await bre.locator('a', { hasText: 'Invite everyone on the list' }).click();
let form = await modalForm(bre);
log('modal:', (await form.locator('.ofrStepDescription').innerText()).trim(), '| hide checked:', await form.locator('input[name=hideOffer]').isChecked());
let t = since();
await Promise.all([bre.waitForNavigation({ timeout: 30000 }), form.locator('button.submitFormButton').click()]);
let ms = mails(t);
log('sent:', ms.map(m => `${m.to} | ${m.subject}`), '| deadline in mail:', ms.every(m => m.body.includes(deadline) || /\d/.test(m.body)));
log('deadlines stored:', sql(`SELECT GROUP_CONCAT(DISTINCT s.setting_value) FROM brm_assignment_settings s JOIN brm_assignments a USING(assignment_id) WHERE a.object_id=${id} AND s.setting_name='invitationDeadline'`), '(bid deadline', deadline + ')');
log('round box:', (await bre.locator('.ofrRound').innerText()).replace(/\s+/g, ' '));
const links = { maria: linkFor(ms, 'maria.rossi'), jonas: linkFor(ms, 'reviewer2'), tom: linkFor(ms, 'tom.tester') };

// To-do section
await bre.goto(`${B}/manageBookReviews/desk`);
log('To-do invitations:', (await bre.locator('h2', { hasText: /^Invitations$/ }).locator('xpath=following-sibling::table[1]').innerText()).replace(/\s+/g, ' ').slice(0, 400));
await bre.screenshot({ path: IMG + 'todo-invitations.png', fullPage: true });

// Maria applies, Tom declines (no notice to the editor)
const maria = await anonymous();
await maria.goto(links.maria);
await maria.fill('#ofrStatement', 'Comparative constitutional law is my field.');
await Promise.all([maria.waitForNavigation(), maria.locator('.ofrInvitationForm button[type=submit]').click()]);
const tom = await anonymous();
await tom.goto(links.tom);
await tom.locator('.ofrDecline summary').click();
t = since();
await Promise.all([tom.waitForNavigation(), tom.locator('.ofrDeclineForm button').click()]);
log('after Tom declines, mails:', mails(t).map(m => `${m.to} | ${m.subject}`), '| statuses:', st(id));

// Add Anna, invite the new people
await addPeople(id, ['Weber']);
log('round box:', (await bre.locator('.ofrRound').innerText()).replace(/\s+/g, ' '));
t = since();
await Promise.all([bre.waitForNavigation(), bre.locator('button', { hasText: 'Invite the new people' }).click()]);
log('invited new:', mails(t).map(m => `${m.to} | ${m.subject}`));

// Select Maria: bidding closes, open invitations are withdrawn, the round ends
await bre.goto(`${B}/manageBookReviews/desk`);
await bre.locator('.ofrDeskItem', { hasText: 'Comparative Constitutionalism' }).locator('.ofrBidders tr', { hasText: 'Maria Rossi' }).locator('a', { hasText: 'Select and send contract' }).click();
form = await modalForm(bre);
await Promise.all([bre.waitForNavigation({ timeout: 20000 }), form.locator('button.submitFormButton').click()]);
log('after selection:', st(id), '| round settings:', roundSettings(id));
const jonas = await anonymous();
await jonas.goto(links.jonas);
log('jonas link:', await jonas.locator('h1').innerText());
log('events:', events(id));

// 2. A round where everyone answers: finished automatically
const id2 = await newOffer('Rights Revisited', 'bidding', null);
await addPeople(id2, ['Tester']);
await bre.locator('a', { hasText: 'Invite everyone on the list' }).click();
form = await modalForm(bre);
t = since();
await Promise.all([bre.waitForNavigation({ timeout: 20000 }), form.locator('button.submitFormButton').click()]);
const tom2 = await anonymous();
await tom2.goto(linkIn(mails(t)[0].body));
await tom2.locator('.ofrDecline summary').click();
await Promise.all([tom2.waitForNavigation(), tom2.locator('.ofrDeclineForm button').click()]);
log('finished round:', st(id2), '| round settings:', roundSettings(id2), '| last event:', events(id2).split(', ').pop());

// 3. Sequential round stopped from To-do
const id3 = await newOffer('Law in Context', 'direct', null);
await addPeople(id3, ['Becker', 'Rossi']);
await bre.locator('a', { hasText: 'Start sequential round' }).click();
form = await modalForm(bre);
await Promise.all([bre.waitForNavigation({ timeout: 20000 }), form.locator('button.submitFormButton').click()]);
await bre.goto(`${B}/manageBookReviews/desk`);
const r3 = bre.locator('tr', { hasText: 'Law in Context' }).filter({ has: bre.locator('button', { hasText: 'Stop round' }) });
log('To-do row:', (await r3.innerText()).replace(/\s+/g, ' ').slice(0, 220));
await Promise.all([bre.waitForNavigation(), r3.locator('button', { hasText: 'Stop round' }).click()]);
log('after stop from To-do:', bre.url().replace(B, ''), '|', st(id3), '| round settings:', roundSettings(id3));
await browser.close();
