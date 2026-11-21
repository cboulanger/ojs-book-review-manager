/**
 * Fixtures of the API E2E suite: clients per role, raw requests, and factories for
 * test data. Everything is created with unique identifiers and email addresses, so
 * the suite runs repeatedly and its files run in parallel without resetting the journal.
 * Works are created hidden, so they stay off the public list.
 */

import assert from 'node:assert/strict';
import { ObjectsForReviewClient, OfrApiError } from '../../../api/src/client.js';
import { JOURNAL_URL, apiKey, email, isbn13 } from '../../lib/ojs.mjs';

export { JOURNAL_URL };
export const API_URL = `${JOURNAL_URL}/api/v1/bookReviewManager`;

const clients = new Map();

/** Client acting as a dev account: 'admin' (journal manager), 'bre' (book review editor), 'author' */
export function as(username, options = {}) {
  const key = `${username}:${JSON.stringify(options)}`;
  if (!clients.has(key)) {
    clients.set(key, new ObjectsForReviewClient({ url: JOURNAL_URL, token: apiKey(username), timeout: 90000, ...options }));
  }
  return clients.get(key);
}

export const admin = () => as('admin');

/** A request without the client: resolves to { status, body } */
export async function raw(path, { method = 'GET', headers = {}, body, url = API_URL } = {}) {
  const response = await fetch(url + path, {
    method,
    headers: { Accept: 'application/json', ...(body ? { 'Content-Type': 'application/json' } : {}), ...headers },
    body: body ? JSON.stringify(body) : undefined,
  });
  const text = await response.text();
  let json;
  try { json = JSON.parse(text); } catch { json = text; }
  return { status: response.status, body: json };
}

/**
 * Assert that a promise rejects with an API error.
 * @param {Promise} promise
 * @param {number} status
 * @param {string} [code] Expected "error" code
 */
export async function rejects(promise, status, code) {
  await assert.rejects(promise, (error) => {
    assert.ok(error instanceof OfrApiError, `expected an API error, got ${error}`);
    assert.equal(error.status, status, `${error.message}`);
    if (code) assert.equal(error.code, code, `${error.message}`);
    return true;
  });
}

/** A work offered for review: hidden, no lookup, no returns (override as needed) */
export function newWork(overrides = {}, client = admin()) {
  return client.addObject({
    isbn: isbn13(),
    lookup: false,
    title: 'E2E work',
    authors: 'Test, Tess',
    hidden: true,
    returnRequired: false,
    ...overrides,
  });
}

/** A work in bidding mode */
export const newBiddingWork = (overrides = {}, client = admin()) => newWork({ assignmentMode: 'bidding', ...overrides }, client);

/** A new user with roles (default: author) */
export function newUser(overrides = {}, client = admin()) {
  return client.addUser({
    email: email(overrides.roles?.[0] ?? 'author'),
    givenName: 'Erin',
    familyName: 'Tester',
    ...overrides,
  });
}

/** A publisher contact with a mailing address */
export const newPublisherContact = (overrides = {}) => newUser({
  roles: ['publisherContact'],
  givenName: 'Petra',
  familyName: 'Press',
  affiliation: 'E2E Press',
  mailingAddress: 'E2E Press\nReview copies\nPress Street 1\n12345 Town',
  ...overrides,
});
