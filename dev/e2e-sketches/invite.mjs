import { B, OUT, browser, login, anonymous, since, mails, linkIn, modalForm } from './lib.mjs';
import { execSync } from 'node:child_process';
const sql = q => execSync(`podman exec ojs-bookreviewmanager mysql -N ojs -e ${JSON.stringify(q)}`).toString().trim();
const IMG = new URL('../../docs/images/', import.meta.url).pathname;
const RULE = 'The Rule of Law';
const objId = t => sql(`SELECT object_id FROM brm_object_settings WHERE setting_name='title' AND setting_value LIKE '%${t}%' LIMIT 1`);
const statuses = id => sql(`SELECT GROUP_CONCAT(CONCAT(u.username,':',a.status) ORDER BY a.assignment_id) FROM brm_assignments a JOIN users u USING(user_id) WHERE a.object_id=${id}`);
const listOrder = async p => (await p.locator('.ofrInviteList tbody tr strong').allInnerTexts());

const bre = await login('bre', 'ojsbre');
await bre.setViewportSize({ width: 1280, height: 900 });
const ruleId = objId(RULE);
// Reset from earlier runs
sql(`DELETE s FROM brm_assignment_settings s JOIN brm_assignments a USING(assignment_id) WHERE a.object_id=${ruleId}`);
sql(`DELETE FROM brm_assignments WHERE object_id=${ruleId}`);
sql(`DELETE FROM brm_object_settings WHERE object_id=${ruleId} AND setting_name='hidden'`);
sql(`UPDATE brm_objects SET user_id=NULL WHERE object_id=${ruleId}`);

// Hide the offer, open the invitation page from the grid row action
await bre.goto(`${B}/manageBookReviews`);
await bre.waitForTimeout(2000);
let row = bre.locator('#bookReviewManagerGridContainer tr.gridRow', { hasText: RULE });
await row.locator('a.show_extras').click();
let controls = bre.locator(`#${await row.getAttribute('id')}-control-row`);
console.log('row actions:', (await controls.locator('a').allInnerTexts()).map(t => t.trim()).filter(Boolean));
await controls.locator('a', { hasText: /^\s*Hide\s*$/ }).click();
await bre.waitForTimeout(2500);
row = bre.locator('#bookReviewManagerGridContainer tr.gridRow', { hasText: RULE });
await row.locator('a.show_extras').click();
controls = bre.locator(`#${await row.getAttribute('id')}-control-row`);
await Promise.all([bre.waitForNavigation(), controls.locator('a', { hasText: 'Invite reviewers' }).click()]);
console.log('invite page:', bre.url().replace(B, ''), '|', (await bre.locator('.ofrInviteHead').innerText()).replace(/\s+/g, ' ').slice(0, 220));

// Address book search and add
for (const q of ['Becker', 'Weber']) {
	await bre.fill('#ofrInviteSearch', q);
	await Promise.all([bre.waitForNavigation(), bre.locator('.ofrFilter button').click()]);
	console.log(`search ${q}:`, (await bre.locator('.ofrTable').last().innerText()).replace(/\s+/g, ' ').slice(0, 140));
	await Promise.all([bre.waitForNavigation(), bre.locator('button', { hasText: 'Add to list' }).first().click()]);
}
// New person: validation, then creation
await bre.fill('#ofrNew-givenName', 'Maria');
await bre.fill('#ofrNew-email', 'maria.rossi@example.org');
await Promise.all([bre.waitForNavigation(), bre.locator('.ofrNewInvitee button').click()]);
console.log('validation:', await bre.locator('.ofrNewInvitee .ofrWarning').allInnerTexts());
await bre.fill('#ofrNew-familyName', 'Rossi');
await bre.fill('#ofrNew-email', 'maria.rossi@example.org');
await bre.fill('#ofrNew-affiliation', 'University of Bologna');
await Promise.all([bre.waitForNavigation(), bre.locator('.ofrNewInvitee button').click()]);
console.log('list:', await listOrder(bre));
console.log('maria account:', sql(`SELECT CONCAT(u.username,' groups=',GROUP_CONCAT(ug.role_id)) FROM users u JOIN user_user_groups uug USING(user_id) JOIN user_groups ug USING(user_group_id) WHERE u.email='maria.rossi@example.org' GROUP BY u.user_id`));

// Reorder: button, then drag and drop
await Promise.all([bre.waitForNavigation(), bre.locator('.ofrInviteList tr', { hasText: 'Maria Rossi' }).locator('button[aria-label="Move up"]').click()]);
console.log('after move up:', await listOrder(bre));
const rows = bre.locator('.ofrInviteList tbody tr');
await Promise.all([bre.waitForNavigation(), rows.nth(2).dragTo(rows.nth(0), { targetPosition: { x: 20, y: 5 } })]);
console.log('after drag:', await listOrder(bre));
await bre.screenshot({ path: IMG + 'invite-page.png', fullPage: true });

// Invite Jonas: he declines
const sendTo = async name => {
	await bre.locator('.ofrInviteList tr', { hasText: name }).locator('a', { hasText: /Send invitation|Invite again/ }).click();
	const form = await modalForm(bre);
	console.log(`modal for ${name}:`, (await form.locator('.ofrStepDescription').innerText()).trim());
	const t = since();
	await Promise.all([bre.waitForNavigation({ timeout: 20000 }), form.locator('button.submitFormButton').click()]);
	const mail = mails(t).find(m => /Invitation/.test(m.subject));
	console.log(`mail to ${mail?.to} | ${mail?.subject}`);
	return linkIn(mail?.body || '');
};
let link = await sendTo('Jonas Becker');
await bre.screenshot({ path: OUT + 'invite-after-send.png', fullPage: true });
const jonas = await anonymous();
await jonas.goto(link);
console.log('jonas page:', await jonas.locator('h1').innerText());
await jonas.locator('.ofrDecline summary').click();
await jonas.fill('#ofrDeclineReason', 'On sabbatical until summer.');
let t = since();
await Promise.all([jonas.waitForNavigation(), jonas.locator('.ofrDeclineForm button').click()]);
console.log('jonas after decline:', await jonas.locator('h1').innerText(), '| editor mails:', mails(t).map(m => `${m.to} | ${m.subject}`));
await jonas.goto(link);
console.log('link reused:', await jonas.locator('h1').innerText());

// Invite Anna: she reserves
link = await sendTo('Anna Weber');
const anna = await anonymous();
await anna.setViewportSize({ width: 1280, height: 900 });
await anna.goto(link);
await anna.locator('input[name=acceptTerms]').check();
await anna.locator('input[value=have]').check();
await anna.screenshot({ path: IMG + 'invitation-page.png', fullPage: true });
t = since();
await Promise.all([anna.waitForNavigation(), anna.locator('.ofrInvitationForm button[type=submit]').click()]);
console.log('anna after reserve:', await anna.locator('h1').innerText(), '| mails:', mails(t).map(m => `${m.to} | ${m.subject}`));
console.log('statuses Rule of Law:', statuses(ruleId));
await bre.goto(`${B}/manageBookReviews/invite/${ruleId}`);
console.log('page now:', (await bre.locator('.ofrInviteHead').innerText()).replace(/\s+/g, ' ').slice(0, 200), '| send actions:', await bre.locator('.ofrInviteList a', { hasText: /Send invitation|Invite again/ }).count());
console.log('list rows:', (await bre.locator('.ofrInviteList tbody tr').allInnerTexts()).map(r => r.replace(/\s+/g, ' ').slice(0, 110)));

// History
await bre.locator('.ofrInviteHistory a').click();
await bre.waitForTimeout(2500);
console.log('history:', (await bre.locator('.ofrHistory, .pkp_modal_panel').first().innerText()).split('\n').filter(l => /20\d\d-/.test(l)).slice(0, 14));
await browser.close();
