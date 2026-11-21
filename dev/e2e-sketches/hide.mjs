import { B, OUT, browser, login, anonymous } from './lib.mjs';
import { execSync } from 'node:child_process';
const sql = q => execSync(`podman exec ojs-bookreviewmanager mysql -N ojs -e ${JSON.stringify(q)}`).toString().trim();
const RULE = 'The Rule of Law', PLUR = 'Legal Pluralism Revisited';
const publicTitles = async p => { await p.goto(`${B}/books-for-review`); return (await p.locator('#book-review-manager-table tbody tr td:first-child').allInnerTexts()).map(t => t.split('\n')[0]); };

const bre = await login('bre', 'ojsbre');
const gridRow = t => bre.locator('#bookReviewManagerGridContainer tr.gridRow', { hasText: t });
async function toggle(title, label) {
	await bre.goto(`${B}/manageBookReviews`);
	await bre.waitForTimeout(2000);
	const row = gridRow(title);
	await row.locator('a.show_extras').click();
	const controls = bre.locator(`#${await row.getAttribute('id')}-control-row`);
	const link = controls.locator('a', { hasText: new RegExp(`^\\s*${label}\\s*$`) });
	console.log(`${title}: actions =`, (await controls.locator('a').allInnerTexts()).map(t => t.trim()).filter(Boolean));
	await link.click();
	await bre.waitForTimeout(2500);
	console.log(`${title}: status after ${label} =`, (await gridRow(title).locator('td').nth(3).innerText()).trim());
}

const anon = await anonymous();
console.log('public before:', await publicTitles(anon));
await toggle(RULE, 'Hide');
await toggle(PLUR, 'Hide');
console.log('public (anonymous) after hiding:', await publicTitles(anon));
const jonas = await login('reviewer2', 'ojsreviewer2');
console.log('public (Jonas, reviewer of Pluralism):', await publicTitles(jonas));
console.log('Jonas status on Pluralism:', (await jonas.locator('#book-review-manager-table tr', { hasText: PLUR }).locator('td').last().innerText()).replace(/\s+/g, ' '));

// Forged reservation of the hidden offer
const anna = await login('author', 'ojsauthor');
await anna.goto(`${B}/books-for-review`);
const ruleId = sql(`SELECT object_id FROM brm_object_settings WHERE setting_name='title' AND setting_value LIKE '%${RULE}%'`);
const token = await anna.evaluate(() => pkp.currentUser.csrfToken).catch(() => null) || await anna.locator('input[name=csrfToken]').first().inputValue().catch(() => null);
console.log('csrf token found:', !!token);
const resp = await anna.request.post(`${B}/books-for-review/reserveObject`, { form: { csrfToken: token, objectId: ruleId, acceptTerms: '1', dueDate: new Date(Date.now() + 60 * 864e5).toISOString().slice(0, 10), copyPreference: 'have' } });
console.log('forged reserve status:', resp.status(), '| assignments on Rule of Law:', sql(`SELECT COUNT(*) FROM brm_assignments WHERE object_id=${ruleId}`));

// Badge on To-do, history
await bre.goto(`${B}/manageBookReviews/desk`);
console.log('To-do badge on Pluralism:', await bre.locator('.ofrCopies tr', { hasText: PLUR }).locator('.ofrBadge').innerText().catch(() => 'none'));
await bre.screenshot({ path: OUT + 'hide-todo.png', fullPage: true });

await toggle(RULE, 'Show');
console.log('public (anonymous) after showing:', await publicTitles(anon));
console.log('history events:', sql(`SELECT GROUP_CONCAT(HEX(event_type)) FROM event_log WHERE assoc_id=${ruleId} AND event_type IN (${0x1F0F21E},${0x1F0F21F})`));
await toggle(RULE, 'Hide'); await toggle(RULE, 'Show');
await browser.close();
