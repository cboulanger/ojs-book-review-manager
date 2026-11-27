// GET lookup: DOI and ISBN metadata (network tests are skipped with OFR_E2E_OFFLINE=1)
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { admin, rejects } from './fixtures.mjs';
import { OFFLINE, REAL_WORKS, doi, isbn13 } from '../../lib/ojs.mjs';

const network = { skip: OFFLINE && 'OFR_E2E_OFFLINE is set' };

test('invalid input is refused without asking Crossref', async () => {
  await rejects(admin().lookup({ doi: 'not a doi' }), 400, 'invalidDoi');
  await rejects(admin().lookup({ isbn: '978-0-262-04630-6' }), 400, 'invalidIsbn');
  await rejects(admin().lookup({}), 400, 'identifierRequired');
});

test('ISBN lookup', network, async () => {
  const data = await admin().lookup({ isbn: `ISBN ${REAL_WORKS.isbn.isbn.replace(/(\d{3})(\d)(\d{3})/, '$1-$2-$3-')}` });
  assert.equal(data.identifier, REAL_WORKS.isbn.isbn, 'normalized');
  assert.equal(data.identifierType, 'isbn');
  assert.match(data.title, REAL_WORKS.isbn.title);
  assert.ok(data.authors.includes(', '), 'names are inverted: Family, Given');
  assert.match(data.year, /^\d{4}$/);
  assert.equal(data.resourceType, 'http://purl.org/coar/resource_type/c_2f33');
  assert.ok(['Crossref', 'Open Library'].includes(data.source));
});

test('ISBN-10 lookup', network, async () => {
  const data = await admin().lookup({ isbn: REAL_WORKS.isbn.isbn10 });
  assert.match(data.title, REAL_WORKS.isbn.title);
});

test('DOI lookup, from a resolver URL', network, async () => {
  const data = await admin().lookup({ doi: `https://doi.org/${REAL_WORKS.doi.doi}` });
  assert.equal(data.identifier, REAL_WORKS.doi.doi);
  assert.equal(data.identifierType, 'doi');
  assert.match(data.title, REAL_WORKS.doi.title);
  assert.match(data.publisher, REAL_WORKS.doi.publisher);
});

test('open access is found in the Crossref license; ISBN lookups link the DOI', network, async () => {
  const byDoi = await admin().lookup({ doi: REAL_WORKS.openAccess.doi });
  assert.equal(byDoi.openAccess, 'open');
  assert.equal(byDoi.publisherUrl, undefined, 'a DOI is linked anyway');
  const byIsbn = await admin().lookup({ isbn: REAL_WORKS.openAccess.isbn });
  assert.equal(byIsbn.openAccess, 'open');
  assert.equal(byIsbn.publisherUrl, `https://doi.org/${REAL_WORKS.openAccess.doi}`);
  const closed = await admin().lookup({ doi: REAL_WORKS.doi.doi });
  assert.equal(closed.openAccess ?? null, null, 'no open license: unknown, not "closed"');
});

test('unknown DOI: 404', network, async () => {
  await rejects(admin().lookup({ doi: doi() }), 404, 'doiNotFound');
});

test('unknown ISBN: 404', network, async () => {
  await rejects(admin().lookup({ isbn: isbn13() }), 404, 'isbnNotFound');
});
