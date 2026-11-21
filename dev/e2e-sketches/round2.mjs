import { B, browser, login, anonymous, since, mails, linkIn, modalForm } from './lib.mjs';
import { execSync } from 'node:child_process';
const sql = q => execSync(`podman exec ojs-bookreviewmanager mysql -N ojs -e ${JSON.stringify(q)}`).toString().trim();
const task = () => execSync(`podman exec -w /var/www/html ojs-bookreviewmanager php lib/pkp/tools/scheduler.php test --name="APP\\plugins\\generic\\bookReviewManager\\classes\\ReservationReminder" 2>&1`).toString();
const TITLE = 'Global Legal Orders';
const id = sql(`SELECT object_id FROM brm_object_settings WHERE setting_name='title' AND setting_value LIKE '%${TITLE}%' ORDER BY object_id DESC LIMIT 1`);
const st = () => sql(`SELECT GROUP_CONCAT(CONCAT(u.username,':',a.status) ORDER BY a.assignment_id) FROM brm_assignments a JOIN users u USING(user_id) WHERE a.object_id=${id}`);
const mailSummary = t => mails(t).map(m => `${m.to} | ${m.subject}`);
const page = `${B}/manageBookReviews/invite/${id}`;

// Reminder setting through the settings form (validation: must be fewer than the days to answer)
const admin = await login('admin', 'ojsadmin');
await admin.goto(`${B}/manageBookReviews/settings`);
const sf = admin.locator('#orSettingsForm');
await sf.waitFor({ timeout: 15000 });
await admin.waitForTimeout(1500);
await sf.locator('input[name=ofrInvitationReminderDays]').fill('9');
await sf.locator('button.submitFormButton').click();
await admin.waitForTimeout(2500);
console.log('validation (9 >= 7):', (await sf.locator('.error, label.error, .pkp_form_error').allInnerTexts()).map(s => s.trim()).filter(Boolean));
await sf.locator('input[name=ofrInvitationReminderDays]').fill('2');
await sf.locator('button.submitFormButton').click();
await admin.waitForTimeout(2500);

let out = task();
const reminderLink = linkIn(out);
console.log('reminder:', (out.match(/Subject: [^\r\n]*/g) || []), '| to:', (out.match(/To: [^\r\n]*/g) || []), '| link:', !!reminderLink);
out = task();
console.log('second run, no duplicate:', !(out.match(/Subject: Reminder/g) || []).length);

const bre = await login('bre', 'ojsbre');
await bre.setViewportSize({ width: 1280, height: 900 });
// Anna declines via the reminder link: list exhausted
const anna = await anonymous();
await anna.goto(reminderLink);
await anna.locator('.ofrDecline summary').click();
let t = since();
await Promise.all([anna.waitForNavigation(), anna.locator('.ofrDeclineForm button').click()]);
console.log('after last decline:', mailSummary(t));
await bre.goto(page);
console.log('round box:', (await bre.locator('.ofrRound').innerText()).replace(/\s+/g, ' '));

// Add Paula, continue, then stop
await bre.goto(`${page}?q=Paula`);
await Promise.all([bre.waitForNavigation(), bre.locator('button', { hasText: 'Add to list' }).first().click()]);
t = since();
await Promise.all([bre.waitForNavigation(), bre.locator('button', { hasText: 'Continue round' }).click()]);
const paulaMail = mails(t)[0];
console.log('continued:', mailSummary(t), '| from:', (paulaMail?.body.match(/From: [^\r\n]*/) || [])[0]);
bre.once('dialog', d => d.accept());
await Promise.all([bre.waitForNavigation(), bre.locator('button', { hasText: 'Stop round' }).click()]);
console.log('after stop:', st(), '| round box:', await bre.locator('.ofrRound').count(), '| start offered:', await bre.locator('a', { hasText: 'Start sequential round' }).count());
const paula = await anonymous();
await paula.goto(linkIn(paulaMail.body));
console.log('paula link after stop:', await paula.locator('h1').innerText());

// Round 2, visible: someone reserves on the public page, the round ends
await bre.goto(page);
await bre.fill('#ofrNew-givenName', 'Tom'); await bre.fill('#ofrNew-familyName', 'Tester'); await bre.fill('#ofrNew-email', 'tom.tester@example.org');
await Promise.all([bre.waitForNavigation(), bre.locator('.ofrNewInvitee button').click()]);
await bre.locator('a', { hasText: 'Start sequential round' }).click();
const form = await modalForm(bre);
await form.locator('input[name=hideOffer]').uncheck();
t = since();
await Promise.all([bre.waitForNavigation({ timeout: 20000 }), form.locator('button.submitFormButton').click()]);
const tomLink = linkIn(mails(t)[0]?.body || '');
console.log('round 2 started:', mailSummary(t));
// Still hidden from round 1: show it again
await bre.goto(`${B}/manageBookReviews`);
await bre.waitForTimeout(2000);
const row = bre.locator('#bookReviewManagerGridContainer tr.gridRow', { hasText: TITLE });
await row.locator('a.show_extras').click();
await bre.locator(`#${await row.getAttribute('id')}-control-row a`, { hasText: /^\s*Show\s*$/ }).click();
await bre.waitForTimeout(2500);
const jonas = await login('reviewer2', 'ojsreviewer2');
await jonas.goto(`${B}/books-for-review`);
const pub = jonas.locator('#book-review-manager-table tr', { hasText: TITLE });
await pub.locator('.ofrReserveButton').click();
const d = jonas.locator('#ofrReserveDialog');
await d.locator('input[name=acceptTerms]').check();
await d.locator('input[value=have]').check();
await Promise.all([jonas.waitForNavigation(), d.locator('button[type=submit]').click()]);
console.log('after public reservation:', st(), '| round still set:', sql(`SELECT COUNT(*) FROM brm_object_settings WHERE object_id=${id} AND setting_name LIKE 'invitation%'`));
const tom = await anonymous();
await tom.goto(tomLink);
console.log('tom link after reservation:', await tom.locator('h1').innerText());
await bre.goto(page);
await bre.locator('.ofrInviteHistory a').click();
await bre.waitForTimeout(2500);
console.log('history:', (await bre.locator('.pkp_modal_panel').first().innerText()).split('\n').filter(l => /20\d\d-/.test(l)).map(l => l.replace(/^\S+ \S+ \S+ /, '')).reverse().join(' / ').slice(0, 1600));
await browser.close();
