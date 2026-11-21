import { B, OUT, browser, login, anonymous, since, mails, linkIn, modalForm, bodyText } from './lib.mjs';
import { execSync } from 'node:child_process';
const sql = q => execSync(`podman exec ojs-bookreviewmanager mysql -N ojs -e ${JSON.stringify(q)}`).toString().trim();
const task = () => execSync(`podman exec -w /var/www/html ojs-bookreviewmanager php lib/pkp/tools/scheduler.php test --name="APP\\plugins\\generic\\bookReviewManager\\classes\\ReservationReminder" 2>&1`).toString();
const IMG = new URL('../../docs/images/', import.meta.url).pathname;
const TITLE = 'Global Legal Orders';
const st = id => sql(`SELECT GROUP_CONCAT(CONCAT(u.username,':',a.status) ORDER BY a.assignment_id) FROM brm_assignments a JOIN users u USING(user_id) WHERE a.object_id=${id}`);
const aid = (id, user) => sql(`SELECT a.assignment_id FROM brm_assignments a JOIN users u USING(user_id) WHERE a.object_id=${id} AND u.username='${user}' ORDER BY a.assignment_id DESC LIMIT 1`);
const mailSummary = t => mails(t).map(m => `${m.to} | ${m.subject} | from ${(m.body.match(/\nFrom: (.*)/) || [])[1]}`);

const bre = await login('bre', 'ojsbre');
await bre.setViewportSize({ width: 1280, height: 900 });
// New direct offer
await bre.goto(`${B}/manageBookReviews`);
await bre.locator('#bookReviewManagerGridContainer a[id*="addAvailableObjectForReview"]').click();
const f = bre.locator('#availableObjectsForReviewForm');
await f.waitFor({ timeout: 15000 });
await bre.waitForTimeout(1500);
await f.locator('[name=identifierType]').selectOption('ISBN');
await f.locator('input[name=identifier]').fill('9780000000026');
await f.locator('input[name=authors]').fill('Lee, Min');
await bre.locator('#availableObjectsForReviewForm [name=title]').fill(TITLE);
await f.locator('input[name=publisher]').fill('Test Press');
await f.locator('[name=assignmentMode]').selectOption('direct');
await f.locator('button.submitFormButton').click();
await bre.waitForTimeout(3000);
const id = sql(`SELECT object_id FROM brm_object_settings WHERE setting_name='title' AND setting_value LIKE '%${TITLE}%' ORDER BY object_id DESC LIMIT 1`);
const page = `${B}/manageBookReviews/invite/${id}`;
for (const q of ['Becker', 'Rossi', 'Weber']) {
	await bre.goto(`${page}?q=${q}`);
	await Promise.all([bre.waitForNavigation(), bre.locator('button', { hasText: 'Add to list' }).first().click()]);
}

// Start the round
await bre.locator('a', { hasText: 'Start sequential round' }).click();
let form = await modalForm(bre);
console.log('round modal:', (await form.locator('.ofrStepDescription').innerText()).trim());
console.log('to:', await form.locator('.ofrRecipient').allInnerTexts(), '| hide checked:', await form.locator('input[name=hideOffer]').isChecked());
const body = await bodyText(bre);
console.log('placeholders kept:', ['{$recipientName}', '{$invitationDeadline}', '{$invitationUrl}'].map(p => body.includes(p)));
await bre.screenshot({ path: IMG + 'invite-round.png' });
let t = since();
await Promise.all([bre.waitForNavigation({ timeout: 20000 }), form.locator('button.submitFormButton').click()]);
let m = mails(t);
console.log('started:', mailSummary(t), '| hidden:', sql(`SELECT setting_value FROM brm_object_settings WHERE object_id=${id} AND setting_name='hidden'`));
console.log('personalised:', /Dear Jonas Becker/.test(m[0]?.body || ''), '| deadline filled:', !/invitationDeadline/.test(m[0]?.body || ''));
console.log('round box:', (await bre.locator('.ofrRound').innerText()).replace(/\s+/g, ' '));
await bre.screenshot({ path: IMG + 'invite-round-running.png', fullPage: true });

// Jonas declines: Maria is invited automatically, no notice to the editor
const jonas = await anonymous();
await jonas.goto(linkIn(m[0].body));
await jonas.locator('.ofrDecline summary').click();
t = since();
await Promise.all([jonas.waitForNavigation(), jonas.locator('.ofrDeclineForm button').click()]);
console.log('after decline:', mailSummary(t));
console.log('statuses:', st(id));

// Maria's deadline passes: the daily task invites Anna
sql(`UPDATE brm_assignment_settings SET setting_value=DATE_FORMAT(CURDATE() - INTERVAL 1 DAY, '%Y-%m-%d') WHERE assignment_id=${aid(id, 'mariarossi')} AND setting_name='invitationDeadline'`);
let out = task();
console.log('task:', (out.match(/Subject: [^\n]*/g) || []), '| statuses:', st(id));

// Reminder for Anna
sql(`DELETE FROM plugin_settings WHERE plugin_name='bookreviewmanagerplugin' AND setting_name='ofrInvitationReminderDays'`);
sql(`INSERT INTO plugin_settings (plugin_name, context_id, setting_name, setting_value, setting_type) VALUES ('bookreviewmanagerplugin', 1, 'ofrInvitationReminderDays', '2', 'int')`);
sql(`UPDATE brm_assignment_settings SET setting_value=DATE_FORMAT(NOW() - INTERVAL 3 DAY, '%Y-%m-%d %H:%i:%s') WHERE assignment_id=${aid(id, 'author')} AND setting_name='dateInvited'`);
out = task();
const reminderLink = linkIn(out);
console.log('reminder:', (out.match(/Subject: [^\n]*/g) || []), '| link:', !!reminderLink);
out = task();
console.log('second run, no duplicate reminder:', !(out.match(/Subject: Reminder/g) || []).length);

// Anna declines via the reminder link: list exhausted
const anna = await anonymous();
await anna.goto(reminderLink);
await anna.locator('.ofrDecline summary').click();
t = since();
await Promise.all([anna.waitForNavigation(), anna.locator('.ofrDeclineForm button').click()]);
console.log('after last decline:', mailSummary(t));
await bre.goto(page);
console.log('round box:', (await bre.locator('.ofrRound').innerText()).replace(/\s+/g, ' '));

// Add Paula, continue, then stop
await bre.goto(`${page}?q=Paula`);
await Promise.all([bre.waitForNavigation(), bre.locator('button', { hasText: 'Add to list' }).first().click()]);
t = since();
await Promise.all([bre.waitForNavigation(), bre.locator('button', { hasText: 'Continue round' }).click()]);
const paulaLink = linkIn(mails(t)[0]?.body || '');
console.log('continued:', mailSummary(t));
bre.once('dialog', d => d.accept());
await Promise.all([bre.waitForNavigation(), bre.locator('button', { hasText: 'Stop round' }).click()]);
console.log('after stop:', st(id), '| round box:', await bre.locator('.ofrRound').count(), '| start offered:', await bre.locator('a', { hasText: 'Start sequential round' }).count());
const paula = await anonymous();
await paula.goto(paulaLink);
console.log('paula link after stop:', await paula.locator('h1').innerText());

// Round 2, visible: someone reserves on the public page, the round ends
await bre.goto(`${page}`);
await bre.fill('#ofrNew-givenName', 'Tom'); await bre.fill('#ofrNew-familyName', 'Tester'); await bre.fill('#ofrNew-email', 'tom.tester@example.org');
await Promise.all([bre.waitForNavigation(), bre.locator('.ofrNewInvitee button').click()]);
await bre.locator('a', { hasText: 'Start sequential round' }).click();
form = await modalForm(bre);
await form.locator('input[name=hideOffer]').uncheck();
t = since();
await Promise.all([bre.waitForNavigation({ timeout: 20000 }), form.locator('button.submitFormButton').click()]);
const tomLink = linkIn(mails(t)[0]?.body || '');
// The offer is still hidden from round 1; show it
sql(`DELETE FROM brm_object_settings WHERE object_id=${id} AND setting_name='hidden'`);
const jonasUser = await login('reviewer2', 'ojsreviewer2');
await jonasUser.goto(`${B}/books-for-review`);
const row = jonasUser.locator('#book-review-manager-table tr', { hasText: TITLE });
await row.locator('.ofrReserveButton').click();
const d = jonasUser.locator('#ofrReserveDialog');
await d.locator('input[name=acceptTerms]').check();
await d.locator('input[value=have]').check();
await Promise.all([jonasUser.waitForNavigation(), d.locator('button[type=submit]').click()]);
console.log('after public reservation:', st(id), '| round mode:', sql(`SELECT COUNT(*) FROM brm_object_settings WHERE object_id=${id} AND setting_name='invitationMode'`));
const tom = await anonymous();
await tom.goto(tomLink);
console.log('tom link after reservation:', await tom.locator('h1').innerText());
console.log('history:', sql(`SELECT GROUP_CONCAT(SUBSTRING_INDEX(message,'.',-1) ORDER BY log_id SEPARATOR ', ') FROM event_log WHERE assoc_id=${id} AND assoc_type=${0x1F0F001}`).slice(0, 600));
await browser.close();
