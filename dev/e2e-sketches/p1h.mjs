import { browser, login, OUT } from './lib.mjs';
const admin = await login('admin', 'ojsadmin');
for (const lang of ['en', 'de']) {
	const base = `http://localhost:8080/index.php/test/${lang}`;
	for (const path of ['manageBookReviews/desk', 'manageBookReviews/assignments?status=all', 'manageBookReviews', 'manageBookReviews/settings', 'books-for-review', 'books-for-review/link/0000']) {
		await admin.goto(`${base}/${path}`);
		await admin.waitForTimeout(path.includes('settings') || path === 'manageBookReviews' ? 3500 : 500);
		const text = await admin.locator('body').innerText();
		const missing = [...new Set(text.match(/##[^#\s]+##/g) || [])];
		console.log(lang, path, missing.length ? missing : 'ok');
	}
}
// Settings: new fields and saving
await admin.goto('http://localhost:8080/index.php/test/en/manageBookReviews/settings');
const f = admin.locator('#orSettingsForm');
await f.waitFor({ timeout: 15000 });
console.log('settings:', await f.locator('[name=ofrDefaultMode]').inputValue(), await f.locator('[name=ofrContractDays]').inputValue(), await f.locator('[name=ofrContractReminderDays]').inputValue(),
	'| editors:', await f.locator('[name=ofrDefaultEditorId] option').allInnerTexts());
await f.locator('[name=ofrContractReminderDays]').fill('20');
await f.locator('button.submitFormButton').click();
await admin.waitForTimeout(2000);
console.log('invalid reminder (>= deadline):', (await f.innerText()).includes('reminder before the deadline'));
await f.locator('[name=ofrContractReminderDays]').fill('7');
await f.locator('[name=ofrDefaultMode]').selectOption('bidding');
await f.locator('button.submitFormButton').click();
await admin.waitForTimeout(2500);
await admin.reload();
await admin.locator('#orSettingsForm').waitFor({ timeout: 15000 });
console.log('saved default mode:', await admin.locator('#orSettingsForm [name=ofrDefaultMode]').inputValue());
await admin.screenshot({ path: OUT + 'p1-settings.png', fullPage: true });
await admin.locator('#orSettingsForm [name=ofrDefaultMode]').selectOption('direct');
await admin.locator('#orSettingsForm button.submitFormButton').click();
await admin.waitForTimeout(2500);
await browser.close();
