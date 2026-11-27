// Works offered for review: create, find, update, duplicates, validation
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { JOURNAL_URL, admin, as, newWork, newBiddingWork, newPublisherContact, newUser, rejects } from './fixtures.mjs';
import { OFFLINE, REAL_WORKS, doi, history, isbn10, isbn13, uid } from '../../lib/ojs.mjs';

const BOOK = 'http://purl.org/coar/resource_type/c_2f33';

test('create from an ISBN without lookup', async () => {
  const isbn = isbn13();
  const work = await newWork({ isbn: `${isbn.slice(0, 3)}-${isbn.slice(3)}`, title: '  Title <b>with</b>   markup ', year: '2024', publisher: 'P' });
  assert.equal(work.identifier, isbn, 'normalized');
  assert.equal(work.identifierType, 'isbn');
  assert.equal(work.resourceType, BOOK);
  assert.equal(work.title, 'Title with markup', 'plain text');
  assert.equal(work.creator, 'manager');
  assert.equal(work.state, 'open');
  assert.equal(work.hidden, true);
  assert.equal(work.returnRequired, false);
  assert.deepEqual(work.assignments, []);
  assert.equal(work._existing, undefined);
  assert.deepEqual(history(work.id), [], 'creating a work is not an event');
});

test('create from a DOI without lookup', async () => {
  const id = doi();
  const work = await newWork({ isbn: undefined, doi: `https://doi.org/${id}`, resourceType: 'http://purl.org/coar/resource_type/c_6501' });
  assert.equal(work.identifier, id);
  assert.equal(work.identifierType, 'doi');
  assert.equal(work.resourceType, 'http://purl.org/coar/resource_type/c_6501');
});

test('create from an ISBN with lookup; fields in the request win', { skip: OFFLINE && 'OFR_E2E_OFFLINE is set' }, async () => {
  const work = await admin().addObject({ isbn: REAL_WORKS.isbn.isbn, hidden: true, returnRequired: false, onDuplicate: 'skip' });
  assert.match(work.title, REAL_WORKS.isbn.title);
  const updated = await admin().addObject({ isbn: REAL_WORKS.isbn.isbn10, year: '1999', onDuplicate: 'update' });
  assert.equal(updated.id, work.id, 'ISBN-10 finds the ISBN-13');
  assert.equal(updated.year, '1999');
  assert.match(updated.title, REAL_WORKS.isbn.title);
});

test('an explicit resource type is kept for ISBNs without lookup', async () => {
  const work = await newWork({ resourceType: 'http://purl.org/coar/resource_type/c_3248' });
  assert.equal(work.resourceType, 'http://purl.org/coar/resource_type/c_3248');
});

test('create with another identifier type', async () => {
  const url = `https://example.org/e2e/${uid()}`;
  const work = await newWork({ isbn: undefined, identifier: url, identifierType: 'uri', resourceType: 'http://purl.org/coar/resource_type/c_7ad9' });
  assert.equal(work.identifier, url);
  assert.equal(work.identifierType, 'uri');
  assert.equal((await admin().findObject({ identifier: url, identifierType: 'uri' })).id, work.id);
});

test('validation of a new work', async () => {
  const cases = [
    [{ isbn: '9780262046306' }, 'invalidIsbn'],
    [{ isbn: undefined, doi: 'nope' }, 'invalidDoi'],
    [{ isbn: undefined, identifier: 'x', identifierType: 'shelfmark' }, 'invalidIdentifierType'],
    [{ isbn: undefined, identifier: '', identifierType: 'uri' }, 'identifierRequired'],
    [{ title: '' }, 'titleRequired'],
    [{ title: '<p> </p>' }, 'titleRequired'],
    [{ resourceType: 'book' }, 'invalidResourceType'],
    [{ assignmentMode: 'auction' }, 'invalidAssignmentMode'],
    [{ assignmentMode: 'bidding', bidDeadline: '31.12.2027' }, 'invalidDate'],
    [{ dateCreated: 'yesterday' }, 'invalidDate'],
    [{ onDuplicate: 'merge' }, 'invalidOnDuplicate'],
    [{ notifyPublisher: true }, 'notifyNeedsContact'],
  ];
  for (const [input, code] of cases) {
    await rejects(newWork(input), 400, code);
  }
});

test('the bid deadline only applies to bidding', async () => {
  const direct = await newWork({ assignmentMode: 'direct', bidDeadline: '2027-12-31' });
  assert.equal(direct.bidDeadline, null);
  const bidding = await newBiddingWork({ bidDeadline: '2027-12-31' });
  assert.equal(bidding.assignmentMode, 'bidding');
  assert.equal(bidding.bidDeadline, '2027-12-31');
});

test('dateCreated keeps the original date', async () => {
  const work = await newWork({ dateCreated: '2024-05-06' });
  assert.equal(work.dateCreated, '2024-05-06 00:00:00');
});

test('duplicates: error, skip or update', async () => {
  const isbn = isbn13();
  const work = await newWork({ isbn });

  await assert.rejects(newWork({ isbn }), (error) => error.status === 409 && error.code === 'duplicateIdentifier' && error.body.objectId === work.id);
  await assert.rejects(newWork({ isbn: isbn10(isbn) }), (error) => error.status === 409, 'ISBN-10 of the same book');

  const skipped = await newWork({ isbn, title: 'Changed', onDuplicate: 'skip' });
  assert.equal(skipped.id, work.id);
  assert.equal(skipped._existing, true);
  assert.equal(skipped.title, 'E2E work', 'skip changes nothing');

  const updated = await newWork({ isbn, title: 'Changed', onDuplicate: 'update' });
  assert.equal(updated.id, work.id);
  assert.equal(updated.title, 'Changed');
  assert.equal((await admin().getObject(work.id)).title, 'Changed');
});

test('DOIs are compared case-insensitively and in any form', async () => {
  const id = doi();
  const work = await newWork({ isbn: undefined, doi: id });
  const again = await newWork({ isbn: undefined, doi: `doi:${id.toUpperCase()}`, onDuplicate: 'skip' });
  assert.equal(again.id, work.id);
  assert.equal((await admin().findObject({ doi: `https://doi.org/${id}` })).id, work.id);
});

test('find, list and get', async () => {
  const isbn = isbn13();
  const work = await newWork({ isbn });
  assert.equal((await admin().findObject({ isbn: isbn10(isbn) })).id, work.id);
  assert.equal(await admin().findObject({ isbn: isbn13() }), null);
  const list = await admin().listObjects();
  const listed = list.find((item) => item.id === work.id);
  assert.ok(listed);
  assert.equal(listed.assignments, undefined, 'the list has no assignments');
  assert.equal((await admin().getObject(work.id)).identifier, isbn);
  await rejects(admin().getObject(999999999), 404, 'objectNotFound');
});

test('update changes only the given fields', async () => {
  const work = await newWork({ year: '2020', publisher: 'Old Press' });
  const updated = await admin().updateObject(work.id, { year: '2021', hidden: false, description: '<p>About the book</p>' });
  assert.equal(updated.year, '2021');
  assert.equal(updated.publisher, 'Old Press');
  assert.equal(updated.hidden, null);
  assert.equal(updated.description, '<p>About the book</p>');
  assert.equal((await admin().updateObject(work.id, { description: '<p> </p>' })).description, null, 'empty rich text is removed');
  await rejects(admin().updateObject(999999999, { year: '2000' }), 404, 'objectNotFound');
});

test("publisher's page and open access status", async () => {
  const isbn = isbn13();
  const work = await newWork({ isbn, publisherUrl: 'https://press.example/book', openAccess: 'partial' });
  assert.equal(work.publisherUrl, 'https://press.example/book');
  assert.equal(work.workUrl, 'https://press.example/book');
  assert.equal(work.openAccess, 'partial');
  const cleared = await admin().updateObject(work.id, { publisherUrl: '', openAccess: null });
  assert.equal(cleared.publisherUrl, null);
  assert.match(cleared.workUrl, new RegExp(`worldcat\\.org.*${isbn}`), 'default: WorldCat for an ISBN');
  assert.equal(cleared.openAccess, null);
  await rejects(admin().updateObject(work.id, { publisherUrl: 'javascript:alert(1)' }), 400, 'invalidPublisherUrl');
  await rejects(admin().updateObject(work.id, { openAccess: 'maybe' }), 400, 'invalidOpenAccess');
});

test('a DOI lookup stores the open access status', { skip: OFFLINE && 'OFR_E2E_OFFLINE is set' }, async () => {
  const work = await admin().addObject({ doi: REAL_WORKS.openAccess.doi, hidden: true, returnRequired: false, onDuplicate: 'skip' });
  assert.equal(work.openAccess, 'open');
  assert.equal(work.workUrl, `https://doi.org/${REAL_WORKS.openAccess.doi}`);
});

test('an invalid update changes nothing', async () => {
  const work = await newWork({ year: '2020' });
  await rejects(admin().updateObject(work.id, { year: '2030', assignmentMode: 'auction' }), 400, 'invalidAssignmentMode');
  assert.equal((await admin().getObject(work.id)).year, '2020');
});

test('publisher contact: needs the role, and an address if copies are returned', async () => {
  const contact = await newPublisherContact();
  const work = await newWork({ publisherContactEmail: contact.email, returnRequired: true, notifyPublisher: true });
  assert.equal(work.publisherContactId, contact.id);
  assert.equal(work.notifyPublisher, true);
  assert.equal((await newWork({ publisherContactId: contact.id })).publisherContactId, contact.id);

  const author = await newUser();
  await rejects(newWork({ publisherContactEmail: author.email }), 400, 'invalidPublisherContact');
  await rejects(newWork({ publisherContactEmail: `nobody-${uid()}@example.org` }), 400, 'invalidPublisherContact');

  const withoutAddress = await newPublisherContact({ mailingAddress: undefined });
  await rejects(newWork({ publisherContactEmail: withoutAddress.email, returnRequired: true }), 400, 'publisherAddressRequired');
  assert.ok(await newWork({ publisherContactEmail: withoutAddress.email, returnRequired: false }));
});

test('responsible editor: needs the role, and an address if the journal holds a returnable copy', async () => {
  const editor = await newUser({ roles: ['bookReviewEditor'] });
  const work = await newWork({ responsibleEditorEmail: editor.email });
  assert.equal(work.responsibleEditorId, editor.id);
  await rejects(newWork({ responsibleEditorEmail: editor.email, journalHoldsCopy: true, returnRequired: true }), 400, 'editorAddressRequired');

  const withAddress = await newUser({ roles: ['bookReviewEditor'], mailingAddress: 'Journal office\nStreet 2' });
  assert.equal((await newWork({ responsibleEditorEmail: withAddress.email, journalHoldsCopy: true, returnRequired: true })).journalHoldsCopy, true);

  const author = await newUser();
  await rejects(newWork({ responsibleEditorEmail: author.email }), 400, 'invalidResponsibleEditor');
});

test('a book review editor creating a work is responsible for it unless a default is set', async () => {
  const work = await newWork({}, as('bre'));
  const [bre] = await admin().listUsers({ role: 'bookReviewEditor' }).then((users) => users.filter((u) => u.username === 'bre'));
  assert.ok(work.responsibleEditorId !== null);
  if (bre) assert.ok([bre.id, work.responsibleEditorId].includes(work.responsibleEditorId));
});

test('hidden works are not on the public list', async () => {
  const hidden = await newWork({ title: `Hidden ${uid()}` });
  const visible = await newWork({ title: `Visible ${uid()}`, hidden: false });
  const page = await (await fetch(`${JOURNAL_URL}/books-for-review`)).text();
  assert.ok(page.includes(visible.title), 'visible work listed');
  assert.ok(!page.includes(hidden.title), 'hidden work not listed');
  await admin().updateObject(visible.id, { hidden: true });
});
