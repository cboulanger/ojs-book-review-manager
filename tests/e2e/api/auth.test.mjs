// Authentication and permissions of the API
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { API_URL, as, raw, rejects, newUser } from './fixtures.mjs';
import { apiKey, sql } from '../../lib/ojs.mjs';

test('no token: 401', async () => {
  const { status } = await raw('/objects');
  assert.equal(status, 401);
});

test('a malformed token: 400', async () => {
  const { status } = await raw('/objects', { headers: { Authorization: 'Bearer not-a-jwt' } });
  assert.equal(status, 400);
});

test('a token signed with another secret: 400', async () => {
  // header.payload of a real key with a wrong signature
  const [header, payload] = apiKey('admin').split('.');
  const { status } = await raw('/objects', { headers: { Authorization: `Bearer ${header}.${payload}.AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA` } });
  assert.equal(status, 400);
});

test('journal manager and book review editor have access', async () => {
  assert.ok(Array.isArray(await as('admin').listObjects()));
  assert.ok(Array.isArray(await as('bre').listObjects()));
});

test('the token also works as ?apiToken=', async () => {
  assert.ok(Array.isArray(await as('admin', { tokenInQuery: true }).listObjects()));
});

test('an author has no access', async () => {
  await rejects(as('author').listObjects(), 401, 'user.authorization.roleBasedAccessDenied');
});

test('a section editor who is not a book review editor has no access', async (t) => {
  const [row] = sql(`SELECT u.username FROM users u
    JOIN user_user_groups uug ON uug.user_id = u.user_id JOIN user_groups ug ON ug.user_group_id = uug.user_group_id
    WHERE ug.role_id = 17 AND ug.context_id = 1 AND (uug.date_end IS NULL OR uug.date_end > NOW())
    AND u.user_id NOT IN (SELECT uug2.user_id FROM user_user_groups uug2 JOIN user_groups ug2 ON ug2.user_group_id = uug2.user_group_id
      WHERE ug2.role_id IN (1, 16) OR ug2.user_group_id = (SELECT setting_value FROM plugin_settings
        WHERE plugin_name = 'bookreviewmanagerplugin' AND setting_name = 'ofrEditorGroupId' AND context_id = 1))
    LIMIT 1`);
  if (!row) {
    t.skip('the dev journal has no section editor outside the book review editor group');
    return;
  }
  await rejects(as(row.username).listObjects(), 403, 'forbidden');
});

test('only journal managers can make someone a book review editor', async () => {
  await rejects(newUser({ roles: ['bookReviewEditor'] }, as('bre')), 403, 'managerOnly');
  const editor = await newUser({ roles: ['bookReviewEditor'] }, as('admin'));
  assert.deepEqual(editor.roles, ['bookReviewEditor']);
});

test('book review editors can add works and people', async () => {
  const bre = as('bre');
  const contact = await newUser({ roles: ['publisherContact'] }, bre);
  assert.deepEqual(contact.roles, ['publisherContact']);
});

test('the API needs a journal', async () => {
  const siteUrl = API_URL.replace(/\/index\.php\/[^/]+\/api/, '/index.php/index/api');
  const { status } = await raw('/objects', { url: siteUrl, headers: { Authorization: `Bearer ${apiKey('admin')}` } });
  assert.ok(status >= 400 && status < 500, `status ${status}`);
});
