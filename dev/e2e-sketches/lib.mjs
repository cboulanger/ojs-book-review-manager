import { chromium } from 'playwright-core';
import { execSync } from 'node:child_process';
export const B = 'http://localhost:8080/index.php/test/en';
export const OUT = new URL('.', import.meta.url).pathname;
export const browser = await chromium.launch({ channel: 'chrome', headless: true });
export async function login(user, pass) {
	const ctx = await browser.newContext({ viewport: { width: 1400, height: 1000 } });
	const page = await ctx.newPage();
	page.on('pageerror', e => console.log(`PAGE ERROR (${user}):`, e.message));
	page.on('dialog', d => d.accept());
	await page.goto(`${B}/login`);
	await page.fill('input[name=username]', user);
	await page.fill('input[name=password]', pass);
	await Promise.all([page.waitForNavigation(), page.click('form#login button[type=submit], form#login .submit')]);
	return page;
}
export async function anonymous() {
	const ctx = await browser.newContext({ viewport: { width: 1400, height: 1000 } });
	const page = await ctx.newPage();
	page.on('pageerror', e => console.log('PAGE ERROR (anon):', e.message));
	return page;
}
export const since = () => new Date().toISOString();
/** Emails logged since a timestamp: [{to, subject, body}] (soft line breaks removed) */
export function mails(from) {
	const log = execSync(`podman logs --since ${from} ojs-bookreviewmanager 2>&1`, { maxBuffer: 50e6 }).toString()
		.replace(/=\r?\n/g, '').replace(/=3D/g, '=');
	return log.split(/production\.DEBUG: (?=From: )/).slice(1).map(m => m.split(/\n\S+ - - \[/)[0]).map(m => ({
		to: (m.match(/\nTo: (.*)/) || [])[1], subject: (m.match(/\nSubject: (.*)/) || [])[1], body: m,
	}));
}
export const linkIn = text => (text.match(/https?:\/\/[^\s"<>]*books-for-review\/link\/[0-9a-f]{48}/) || [])[0];
export async function modalForm(page) {
	const form = page.locator('#ofrReservationEmailForm');
	await form.waitFor({ timeout: 15000 });
	await page.waitForTimeout(1500);
	return form;
}
export const bodyText = page => page.evaluate(() => { const ta = document.querySelector('#ofrReservationEmailForm [name=body]'); const ed = tinymce.get(ta.id); return ed ? ed.getContent() : ta.value; });
