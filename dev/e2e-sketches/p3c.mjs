import { B, OUT, browser, login, anonymous, since, mails, modalForm, linkIn } from './lib.mjs';
import { execSync } from 'node:child_process';
const sql = q => execSync(`podman exec ojs-bookreviewmanager mysql -N ojs -e ${JSON.stringify(q)}`).toString().trim();
const scheduler = () => execSync(`podman exec ojs-bookreviewmanager sh -c 'cd /var/www/html && runuser -u www-data -- php lib/pkp/tools/scheduler.php test --name="APP\\\\plugins\\\\generic\\\\bookReviewManager\\\\classes\\\\ReservationReminder"' 2>&1`).toString();
const subjects = out => [...out.matchAll(/\nTo: (.*)\r?\nSubject: (.*)/g)].map(m => `${m[1].trim()} | ${m[2].trim()}`);
const TITLE = 'A link test book';
const objectRow = p => p.locator('#book-review-manager-table tr', { hasText: TITLE });
const objectId = sql(`SELECT object_id FROM brm_object_settings WHERE setting_name='title' AND setting_value LIKE '%${TITLE}%'`);

const r2 = await login('reviewer2', 'ojsreviewer2');
await r2.goto(`${B}/books-for-review`);
await objectRow(r2).locator('.ofrReserveButton').click();
const d = r2.locator('#ofrReserveDialog');
await d.locator('input[name=acceptTerms]').check();
await d.locator('input[value=physical]').check();
await d.locator('textarea[name=shippingAddress]').fill('Reviewer Road 2\n80331 München');
await d.locator('input[name=phone]').fill('+49 89 123456');
let t = since();
await Promise.all([r2.waitForNavigation(), d.locator('button[type=submit]').click()]);
const retractLink = linkIn(mails(t).find(m => /confirmation/.test(m.subject)).body);
const aId = sql(`SELECT assignment_id FROM brm_assignments WHERE object_id=${objectId} AND status='accepted'`);

const bre = await login('bre', 'ojsbre');
await bre.goto(`${B}/manageBookReviews/desk`);
await bre.locator('.ofrCopies tbody tr', { hasText: TITLE }).locator('a', { hasText: 'Request copy from publisher' }).click();
const mf = await modalForm(bre);
await Promise.all([bre.waitForNavigation({ timeout: 20000 }), mf.locator('button.submitFormButton').click()]);

// Publisher reminders
const set = (name, days) => sql(`UPDATE brm_assignment_settings SET setting_value=DATE_SUB(NOW(), INTERVAL ${days} DAY) WHERE assignment_id=${aId} AND setting_name='${name}'`);
console.log('run 0 (fresh request):', subjects(scheduler()).filter(s => /publisher@/.test(s)));
set('copyStatusDate', 15);
let out = scheduler();
console.log('run 1:', subjects(out).filter(s => /publisher@/.test(s)), '| link:', !!linkIn(out.replace(/=\r?\n/g, '')));
console.log('run 2:', subjects(scheduler()).filter(s => /publisher@/.test(s)));
set('copyReminderSent', 15);
console.log('run 3:', subjects(scheduler()).filter(s => /publisher@/.test(s)));
await bre.goto(`${B}/manageBookReviews/desk`);
console.log('desk copy row:', (await bre.locator('.ofrCopies tbody tr', { hasText: TITLE }).innerText()).replace(/\s+/g, ' '));

// Reviewer withdraws by link
const anon = await anonymous();
await anon.goto(retractLink);
console.log('retract page:', await anon.locator('h1').innerText(), '|', (await anon.locator('.ofrResponse > p').nth(1).innerText()).trim().slice(0, 80));
t = since();
await Promise.all([anon.waitForNavigation(), anon.locator('.ofrResponse form button').click()]);
console.log('done:', await anon.locator('.ofrResponseMessage').innerText(), '| status:', sql(`SELECT status FROM brm_assignments WHERE assignment_id=${aId}`));
console.log('editor mail:', mails(t).map(m => `${m.to} | ${m.subject}`));
console.log('reminders stop:', subjects(scheduler()).filter(s => /publisher@/.test(s)).length === 0 ? 'none sent' : 'SENT');
await bre.goto(`${B}/manageBookReviews/desk`);
const row = bre.locator('.ofrCopies tbody tr', { hasText: TITLE });
console.log('desk after retract:', (await row.innerText()).replace(/\s+/g, ' '));

// Overdue review
const author = await login('author', 'ojsauthor');
await author.goto(`${B}/books-for-review`);
const row8 = author.locator('.ofrReserveButton').first();
const oid = await row8.getAttribute('data-object-id');
await row8.click();
const d2 = author.locator('#ofrReserveDialog');
await d2.locator('input[name=acceptTerms]').check();
await d2.locator('input[value=have]').check();
await Promise.all([author.waitForNavigation(), d2.locator('button[type=submit]').click()]);
const a2 = sql(`SELECT assignment_id FROM brm_assignments WHERE object_id=${oid} AND status='accepted'`);
sql(`UPDATE brm_assignment_settings SET setting_value=DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 3 DAY), '%Y-%m-%d') WHERE assignment_id=${a2} AND setting_name='dueDate'`);
await bre.goto(`${B}/manageBookReviews/desk`);
console.log('overdue:', (await bre.locator('.ofrOverdue tbody tr').allInnerTexts()).map(x => x.replace(/\s+/g, ' ')));
await bre.screenshot({ path: OUT + 'p3-desk.png', fullPage: true });
await bre.goto(`http://localhost:8080/index.php/test/de/manageBookReviews/desk`);
console.log('de missing:', [...new Set(((await bre.locator('body').innerText()).match(/##[^#\s]+##/g) || []))].filter(k => !k.includes('common.help')));
await anon.goto(retractLink.replace('/en/', '/de/'));
console.log('de link page:', await anon.locator('h1').innerText());
await browser.close();
