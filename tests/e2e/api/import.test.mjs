// Bulk import through the client (importData) and the CLI (ofr import)
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import { mkdtemp, writeFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { admin } from './fixtures.mjs';
import { JOURNAL_URL, apiKey, date, doi, email, history, isbn13, mailsSince, now } from '../../lib/ojs.mjs';

const OFR = fileURLToPath(new URL('../../../api/bin/ofr.js', import.meta.url));

/** An import document with unique data: a contact, a reviewer, three works with five assignments */
function document() {
  const contact = email('contact');
  const reviewer = email('reviewer');
  const newBidder = email('bidder');
  const isbn = isbn13();
  const id = doi();
  return {
    ids: { contact, reviewer, newBidder, isbn, doi: id },
    doc: {
      users: [
        { email: contact, givenName: 'Petra', familyName: 'Press', mailingAddress: 'Press\nStreet 1', roles: ['publisherContact'] },
        { email: reviewer, givenName: 'Rita', familyName: 'Reviewer' },
      ],
      objects: [
        { isbn, lookup: false, title: 'Imported book', hidden: true, publisherContactEmail: contact, notifyPublisher: true,
          assignments: [{ email: reviewer, status: 'accepted', dueDate: date(90), copyPreference: 'physical', copyStatus: 'received' }] },
        { doi: id, lookup: false, title: 'Imported article', hidden: true, assignmentMode: 'bidding', returnRequired: false,
          assignments: [
            { email: newBidder, givenName: 'Bert', familyName: 'Bidder', status: 'bid', statement: 'Mine' },
            { email: reviewer, status: 'offered' },
            { email: reviewer, status: 'bid' }, // duplicate: fails
          ] },
        { isbn: '9780262046306', title: 'Invalid ISBN', assignments: [{ email: reviewer, status: 'bid' }] }, // fails, assignment skipped
      ],
    },
  };
}

test('import: everything valid is imported, failures are reported, nothing is emailed', async () => {
  const since = now();
  const { ids, doc } = document();
  const events = [];
  const report = await admin().importData(doc, { onProgress: (event) => events.push(event) });

  assert.deepEqual(report.users.map((u) => u.action), ['created', 'created']);
  assert.deepEqual(report.objects.map((o) => o.action), ['created', 'created']);
  assert.deepEqual(report.assignments.map((a) => a.action), ['created', 'created', 'created']);
  assert.deepEqual(report.errors.map((e) => [e.kind, e.code]), [['assignment', 'duplicateAssignment'], ['object', 'invalidIsbn'], ['assignment', null]]);
  assert.ok(report.errors.every((e) => !JSON.stringify(e.record).includes('Street')), 'no addresses in error reports');
  assert.equal(events.length, report.users.length + report.objects.length + report.assignments.length + report.errors.length);

  const book = await admin().findObject({ isbn: ids.isbn });
  assert.equal(book.state, 'assigned');
  assert.equal(book.publisherContactId, report.users[0].id);
  const article = await admin().getObject((await admin().findObject({ doi: ids.doi })).id);
  assert.equal(article.state, 'contractOffered');
  assert.deepEqual(article.assignments.map((a) => a.status).sort(), ['bid', 'offered']);
  assert.equal(history(article.id).length, 2);

  const mails = mailsSince(since);
  for (const address of [ids.contact, ids.reviewer, ids.newBidder]) {
    assert.deepEqual(mails.filter((mail) => mail.to.includes(address)), [], `no email to ${address}`);
  }
});

test('import again: nothing is duplicated', async () => {
  const { doc } = document();
  await admin().importData(doc);
  const again = await admin().importData(doc);
  assert.deepEqual(again.users.map((u) => u.action), ['exists', 'exists']);
  assert.deepEqual(again.objects.map((o) => o.action), ['exists', 'exists']);
  assert.ok(again.assignments.every((a) => a.action === 'skipped (work existed)'));
  assert.deepEqual(again.errors.map((e) => e.kind), ['object', 'assignment'], 'only the invalid work fails again');

  const withExisting = await admin().importData(doc, { importAssignmentsOfExisting: true });
  assert.ok(withExisting.errors.filter((e) => e.kind === 'assignment').every((e) => [409, null].includes(e.status)),
    'existing assignments are refused as duplicates');
});

test('dry run: looks up, creates nothing', async () => {
  const { ids, doc } = document();
  const report = await admin().importData(doc, { dryRun: true });
  assert.deepEqual(report.users.map((u) => u.action), ['create', 'create']);
  assert.deepEqual(report.objects.map((o) => o.action), ['create', 'create']);
  assert.equal(await admin().findUserByEmail(ids.contact), null);
  assert.equal(await admin().findObject({ isbn: ids.isbn }), null);
});

test('onDuplicate update changes existing works', async () => {
  const { ids, doc } = document();
  await admin().importData(doc);
  doc.objects[0].title = 'Renamed book';
  const report = await admin().importData(doc, { onDuplicate: 'update' });
  assert.equal(report.objects[0].action, 'updated');
  assert.equal((await admin().findObject({ isbn: ids.isbn })).title, 'Renamed book');
});

test('the CLI imports a file and exits with 1 if records failed', async () => {
  const dir = await mkdtemp(join(tmpdir(), 'ofr-e2e-'));
  try {
    const { ids, doc } = document();
    const file = join(dir, 'import.json');
    await writeFile(file, JSON.stringify(doc));
    const run = (args) => new Promise((resolve) => execFile(process.execPath, [OFR, ...args],
      { env: { PATH: process.env.PATH, OFR_URL: JOURNAL_URL, OFR_TOKEN: apiKey('admin') }, timeout: 120000 },
      (error, stdout, stderr) => resolve({ code: error ? error.code : 0, stdout, stderr })));

    const dry = await run(['import', file, '--dry-run']);
    assert.equal(dry.code, 1, 'the dry run already finds the invalid ISBN');
    assert.deepEqual(JSON.parse(dry.stdout).errors.map((e) => e.code), ['invalidIsbn', null]);
    assert.equal(await admin().findObject({ isbn: ids.isbn }), null, 'the dry run created nothing');

    const result = await run(['import', file]);
    assert.equal(result.code, 1, 'the invalid work fails');
    const report = JSON.parse(result.stdout);
    assert.equal(report.objects.length, 2);
    assert.match(result.stderr, /errors: 3/);
    assert.ok(await admin().findObject({ isbn: ids.isbn }));
  } finally {
    await rm(dir, { recursive: true, force: true });
  }
});

test('import with essays: works first, then the essay with its reviewer and one copy per work; a re-run skips it', async () => {
  const reviewer = email('essay-reviewer');
  const [first, second] = [isbn13(), doi()];
  const doc = {
    users: [{ email: reviewer, givenName: 'Erik', familyName: 'Essayist' }],
    objects: [
      { isbn: first, lookup: false, title: 'Essay book one', hidden: true, returnRequired: false },
      { doi: second, lookup: false, title: 'Essay book two', hidden: true, returnRequired: false },
    ],
    essays: [{
      title: 'E2E imported review essay', hidden: true, works: [first, second],
      assignments: [{
        email: reviewer, status: 'accepted', dueDate: date(90), dateCreated: '2026-09-01',
        copies: [
          { work: first, copyPreference: 'physical', copyStatus: 'received', copyStatusDate: '2026-09-15' },
          { work: { doi: second.toUpperCase() }, copyPreference: 'electronic', copyStatus: 'sent' },
        ],
      }],
    }],
  };
  const report = await admin().importData(doc);
  assert.deepEqual(report.errors, []);
  assert.deepEqual(report.essays.map((e) => e.action), ['created']);
  const [essay] = report.essays;
  assert.deepEqual(report.assignments.map((a) => [a.essayId ?? a.objectId, a.action]), [[essay.id, 'created']]);

  const container = await admin().getObject(essay.id);
  assert.equal(container.isReviewEssay, true);
  assert.deepEqual(container.members.map((m) => m.id), essay.works);
  const expected = [['physical', 'received'], ['electronic', 'sent']];
  container.members.forEach((member, i) => {
    assert.equal(member.assignments.length, 1, `one copy record on work ${member.id}`);
    assert.deepEqual([member.assignments[0].copyPreference, member.assignments[0].copyStatus], expected[i]);
  });

  const again = await admin().importData(doc);
  assert.deepEqual(again.errors, []);
  assert.deepEqual(again.essays.map((e) => [e.id, e.action]), [[essay.id, 'exists']]);
  assert.deepEqual(again.assignments.map((a) => a.action), ['skipped (essay existed)']);
  assert.equal((await admin().getObject(essay.id)).assignments.length, 1, 'nothing imported twice');
});
