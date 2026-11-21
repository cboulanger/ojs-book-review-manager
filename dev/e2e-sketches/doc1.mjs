// Documentation screenshots, stage 1: settings, offers, public page
import { B, browser, login, anonymous } from './lib.mjs';
const IMG = new URL('../../docs/images/', import.meta.url).pathname;
const setRich = (page, sel, html) => page.evaluate(([sel, html]) => {
	const ta = document.querySelector(sel); const ed = window.tinymce && tinymce.get(ta.id);
	if (ed) { ed.setContent(html); ed.save(); ed.fire('change'); } else { ta.value = html; }
}, [sel, html]);

// Plugin settings (journal manager)
const admin = await login('admin', 'ojsadmin');
await admin.setViewportSize({ width: 1280, height: 900 });
await admin.goto(`${B}/manageBookReviews/settings`);
const sf = admin.locator('#orSettingsForm');
await sf.waitFor({ timeout: 15000 });
await admin.waitForTimeout(2000);
await setRich(admin, '#orSettingsForm [name^="ofrInstructions"]', 'Would you like to review one of these works? Choose a title and apply or reserve it. Questions: reviews@example.org');
await setRich(admin, '#orSettingsForm [name^="ofrContract"]', '<p>By reserving a work you agree to submit a review of 1,000–1,500 words by the due date. Review copies remain the property of the publisher until the review is published.</p>');
await sf.locator('[name=ofrDefaultEditorId]').selectOption({ label: 'Clara Lindqvist (bre@example.org)' }).catch(async () => {
	console.log('editor options:', await sf.locator('[name=ofrDefaultEditorId] option').allInnerTexts());
});
await sf.locator('input[name=displayAsList]').check();
await admin.screenshot({ path: IMG + 'settings.png', fullPage: true });
await sf.locator('button.submitFormButton').click();
await admin.waitForTimeout(2500);

// Offers (book review editor)
const bre = await login('bre', 'ojsbre');
await bre.setViewportSize({ width: 1280, height: 900 });
async function offer({ type, id, authors, title, description, publisher, year, mode, deadline, contact, holds, notify, shot }) {
	await bre.goto(`${B}/manageBookReviews`);
	await bre.locator('#bookReviewManagerGridContainer a[id*="addAvailableObjectForReview"]').click();
	const form = bre.locator('#availableObjectsForReviewForm');
	await form.waitFor({ timeout: 15000 });
	await bre.waitForTimeout(1500);
	await form.locator('[name=identifierType]').selectOption(type);
	await form.locator('input[name=identifier]').fill(id);
	await form.locator('input[name=authors]').fill(authors);
	await form.locator('input[name=title]').fill(title);
	if (description) await setRich(bre, '#availableObjectsForReviewForm [name=description]', description);
	await form.locator('input[name=publisher]').fill(publisher);
	await form.locator('input[name=year]').fill(year);
	await form.locator('[name=assignmentMode]').selectOption(mode);
	if (deadline) await form.locator('input[name=bidDeadline]').fill(deadline);
	if (contact) await form.locator('[name=publisherContactId]').selectOption({ label: 'Paula Publisher (publisher@example.org)' });
	if (holds) await form.locator('input[name=journalHoldsCopy]').check();
	if (notify) await form.locator('input[name=notifyPublisher]').check();
	if (shot) {
		// Tall viewport, scrolled to the top, so the whole form is visible
		await bre.setViewportSize({ width: 1280, height: 1900 });
		await form.locator('[name=resourceType]').scrollIntoViewIfNeeded();
		await bre.evaluate(() => document.querySelector('#availableObjectsForReviewForm').scrollIntoView());
		await bre.waitForTimeout(500);
		await bre.screenshot({ path: IMG + shot });
		await bre.setViewportSize({ width: 1280, height: 900 });
	}
	await form.locator('button.submitFormButton').click();
	await bre.waitForTimeout(3000);
	const errors = await form.locator('.error, label.error').allInnerTexts().catch(() => []);
	if (errors.filter(e => e.trim()).length) console.log('ERRORS', title, errors);
}
const deadline = new Date(Date.now() + 21 * 864e5).toISOString().slice(0, 10);
await offer({ type: 'ISBN', id: '9781509982622', authors: 'Nelken, David', title: 'Law and Society in Transition', description: '<p>A comparative study of how legal institutions adapt to <em>social change</em>. We are looking for a reviewer with a background in sociology of law.</p>', publisher: 'Hart Publishing', year: '2025', mode: 'bidding', deadline, contact: true, notify: true, shot: 'offer-form.png' });
await offer({ type: 'ISBN', id: '9780198862000', authors: 'Moller, Lena', title: 'The Rule of Law: A Comparative History', publisher: 'Oxford University Press', year: '2024', mode: 'direct', holds: true });
await offer({ type: 'ISBN', id: '9783161600000', authors: 'Okafor, Chidi', title: 'Legal Pluralism Revisited', publisher: 'Test Press', year: '2025', mode: 'direct', contact: true });
await bre.goto(`${B}/manageBookReviews`);
await bre.waitForTimeout(2500);
await bre.screenshot({ path: IMG + 'available-objects.png', fullPage: true });

// Public page
const anon = await anonymous();
await anon.setViewportSize({ width: 1280, height: 900 });
await anon.goto(`${B}/books-for-review`);
await anon.screenshot({ path: IMG + 'public-page.png', fullPage: true });
console.log('public rows:', (await anon.locator('#book-review-manager-table tbody tr').allInnerTexts()).map(t => t.replace(/\s+/g, ' ')));
await browser.close();
