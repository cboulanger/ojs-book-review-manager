import { B, browser, login } from './lib.mjs';
import { execSync } from 'node:child_process';
const id = execSync(`podman exec ojs-bookreviewmanager mysql -N ojs -e "SELECT object_id FROM brm_object_settings WHERE setting_name='title' AND setting_value LIKE '%Law in Context%' LIMIT 1"`).toString().trim();
const bre = await login('bre', 'ojsbre');
const de = B.replace('/en', '/de');
for (const q of ['Weber', '']) {
	await bre.goto(`${de}/manageBookReviews/invite/${id}`);
	console.log('form action:', await bre.locator('form.ofrFilter').getAttribute('action'));
	await bre.fill('#ofrInviteSearch', q);
	const before = bre.url();
	await bre.locator('form.ofrFilter button').click();
	await bre.waitForTimeout(2500);
	console.log(`q="${q}":`, '| rows:', (await bre.locator('form.ofrFilter ~ table.ofrTable').first().locator('strong').allInnerTexts().catch(() => [])));
}
await browser.close();
