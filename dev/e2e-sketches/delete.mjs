import { B, browser, login } from './lib.mjs';
import { execSync } from 'node:child_process';
const sql = q => execSync(`podman exec ojs-bookreviewmanager mysql -N ojs -e ${JSON.stringify(q)}`).toString().trim();
// A dummy object of another journal, and one of this journal to delete
sql(`INSERT INTO brm_objects (context_id, identifier, identifier_type, resource_type, creator, date_created) VALUES (99, 'x', 'ISBN', 'book', 'manager', NOW())`);
const foreignId = sql(`SELECT MAX(object_id) FROM brm_objects WHERE context_id=99`);
sql(`INSERT INTO brm_objects (context_id, identifier, identifier_type, resource_type, creator, date_created) VALUES (1, '123', 'ISBN', 'book', 'manager', NOW())`);
const ownId = sql(`SELECT MAX(object_id) FROM brm_objects WHERE context_id=1`);
sql(`INSERT INTO brm_object_settings (object_id, locale, setting_name, setting_value, setting_type) VALUES (${ownId}, '', 'title', 'Delete me', 'string')`);

const bre = await login('bre', 'ojsbre');
await bre.goto(`${B}/manageBookReviews`);
await bre.waitForTimeout(2000);
const token = await bre.evaluate(() => pkp.currentUser.csrfToken);
const url = `http://localhost:8080/index.php/test/$$$call$$$/plugins/generic/book-review-manager/controllers/grid/objects-for-review-management-grid/delete-available-object-for-review`;
let r = await bre.request.post(url, { form: { objectId: foreignId, csrfToken: token } });
console.log('foreign object, with token:', r.status(), (await r.text()).slice(0, 60), '| still there:', sql(`SELECT COUNT(*) FROM brm_objects WHERE object_id=${foreignId}`));
r = await bre.request.post(url, { form: { objectId: ownId } });
console.log('own object, no token:', r.status(), (await r.text()).slice(0, 60), '| still there:', sql(`SELECT COUNT(*) FROM brm_objects WHERE object_id=${ownId}`));

// Normal delete through the grid
const row = bre.locator('#bookReviewManagerGridContainer tr.gridRow', { hasText: 'Delete me' });
await row.locator('a.show_extras').click();
await bre.locator(`#${await row.getAttribute('id')}-control-row a`, { hasText: 'Delete' }).click();
await bre.locator('.pkpModalConfirmButton, button:has-text("OK")').first().click();
await bre.waitForTimeout(2500);
console.log('own object via UI, still there:', sql(`SELECT COUNT(*) FROM brm_objects WHERE object_id=${ownId}`), '| row visible:', await row.count());
sql(`DELETE FROM brm_objects WHERE object_id=${foreignId}`);
await browser.close();
