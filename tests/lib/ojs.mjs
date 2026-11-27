/**
 * Helpers for E2E suites that run against the dev container (dev/ojs-dev.sh):
 * configuration, commands in the container, SQL, the email log, API keys and test data.
 * Shared by tests/e2e/* (API now; UI tests can import the same helpers).
 */

import { execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';

export const PODMAN = process.env.PODMAN ?? 'podman';
export const CONTAINER = process.env.OFR_CONTAINER ?? 'ojs-bookreviewmanager';
export const JOURNAL_URL = (process.env.OFR_E2E_URL ?? `http://localhost:${process.env.OJS_PORT ?? 8080}/index.php/test`).replace(/\/+$/, '');
export const OJS_DIR = '/var/www/html';
export const PLUGIN_IN_CONTAINER = `${OJS_DIR}/plugins/generic/bookReviewManager`;

/** Tests that need Crossref or Open Library are skipped with OFR_E2E_OFFLINE=1 */
export const OFFLINE = ['1', 'true', 'yes'].includes(String(process.env.OFR_E2E_OFFLINE ?? '').toLowerCase());

/** Accounts of the dev journal (dev/build-install.sh, dev/devSetup.php) */
export const USERS = {
  admin: { username: 'admin', password: 'ojsadmin', role: 'site admin, journal manager' },
  bre: { username: 'bre', password: 'ojsbre', role: 'book review editor' },
  author: { username: 'author', password: 'ojsauthor', role: 'author' },
  publisher: { username: 'publisher', password: 'ojspublisher', role: 'publisher contact' },
};

/** Run a command in the container (as www-data) and return its stdout */
export function exec(args, { user = 'www-data', input } = {}) {
  return execFileSync(PODMAN, ['exec', '-i', '-u', user, '-w', OJS_DIR, CONTAINER, ...args],
    { input, encoding: 'utf8', maxBuffer: 50e6, stdio: ['pipe', 'pipe', 'pipe'] });
}

/** Rows of a SQL query, as objects (values are strings or null) */
export function sql(query) {
  const out = exec(['mysql', '-uojs', '-pojs', 'ojs', '--batch', '--raw', '-e', query], { user: 'root' });
  const [header, ...lines] = out.split('\n').filter((line) => line !== '');
  if (!header) return [];
  const columns = header.split('\t');
  return lines.map((line) => Object.fromEntries(line.split('\t').map((value, i) => [columns[i], value === 'NULL' ? null : value])));
}

/** Quote a value for SQL */
export const q = (value) => `'${String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;

const apiKeys = new Map();

/** API key of a dev account (its existing key, or a new one); cached per process */
export function apiKey(username) {
  if (!apiKeys.has(username)) {
    apiKeys.set(username, exec(['php', `${PLUGIN_IN_CONTAINER}/tests/lib/php/api-key.php`, username]).trim());
  }
  return apiKeys.get(username);
}

/** Current time, for mailsSince() */
export const now = () => new Date().toISOString();

/**
 * Emails logged by OJS (mail driver "log") since a timestamp: [{to, subject, body}].
 * The log's timestamps are precise to the second: filter by recipient or subject.
 */
export function mailsSince(timestamp) {
  const log = execFileSync(PODMAN, ['logs', '--since', timestamp, CONTAINER], { encoding: 'utf8', maxBuffer: 50e6, stdio: ['ignore', 'pipe', 'pipe'] });
  const text = log.replace(/=\r?\n/g, '').replace(/=3D/g, '=');
  return text.split(/production\.DEBUG: (?=From: )/).slice(1).map((mail) => mail.split(/\n\S+ - - \[/)[0]).map((mail) => ({
    to: (mail.match(/\nTo: (.*)/) ?? [])[1] ?? '',
    subject: (mail.match(/\nSubject: (.*)/) ?? [])[1] ?? '',
    body: mail,
  }));
}

/** History entries ("event log") of a work: [{message, ...settings}], oldest first */
export function history(objectId) {
  const rows = sql(`SELECT e.log_id, e.message, s.setting_name, s.setting_value FROM event_log e
    LEFT JOIN event_log_settings s ON s.log_id = e.log_id
    WHERE e.assoc_type = ${0x1F0F001} AND e.assoc_id = ${Number(objectId)} ORDER BY e.log_id`);
  const entries = new Map();
  for (const row of rows) {
    const entry = entries.get(row.log_id) ?? { message: row.message };
    if (row.setting_name) entry[row.setting_name] = row.setting_value;
    entries.set(row.log_id, entry);
  }
  return [...entries.values()];
}

//
// Test data: unique per run, so that suites can run repeatedly and in parallel without a reset
//

/** A short unique id, e.g. "m3k9x2ab" */
export const uid = () => Date.now().toString(36).slice(-5) + randomBytes(3).toString('hex');

/** A unique email address */
export const email = (label = 'user') => `e2e-${label}-${uid()}@example.org`;

/** A random valid ISBN-13 (978 prefix) that is (almost certainly) not in the journal */
export function isbn13() {
  const digits = '978' + Array.from({ length: 9 }, () => Math.floor(Math.random() * 10)).join('');
  const sum = [...digits].reduce((total, digit, i) => total + Number(digit) * (i % 2 ? 3 : 1), 0);
  return digits + ((10 - (sum % 10)) % 10);
}

/** The ISBN-10 of an ISBN-13 with the 978 prefix */
export function isbn10(isbn13Value) {
  const core = isbn13Value.slice(3, 12);
  const sum = [...core].reduce((total, digit, i) => total + Number(digit) * (10 - i), 0);
  const check = (11 - (sum % 11)) % 11;
  return core + (check === 10 ? 'X' : String(check));
}

/** A unique DOI (test prefix 10.5555, never registered) */
export const doi = () => `10.5555/e2e-${uid()}`;

/** A date relative to today, as Y-m-d */
export function date(days = 0) {
  const d = new Date();
  d.setDate(d.getDate() + days);
  return d.toISOString().slice(0, 10);
}

/** Real works for lookup tests (need network): metadata as found by Crossref / Open Library */
export const REAL_WORKS = {
  isbn: { isbn: '9780262046305', isbn10: '026204630X', title: /Introduction to Algorithms/ },
  doi: { doi: '10.4324/9781003080114', title: /The Home in the Digital Age/, publisher: /Routledge|Informa/ },
  // An open access book (CC BY in Crossref)
  openAccess: { doi: '10.14361/9783839457290', isbn: '9783839457290' },
};
