// extract.js against a fake OpenAI-compatible server (synthetic mails, offline).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync, readFileSync, readdirSync } from 'node:fs';
import { createServer } from 'node:http';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { execFileSync, spawn, spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const scripts = join(dirname(fileURLToPath(import.meta.url)), '..', 'scripts');
const mail = (id, from, subject, date, body, extra = '') => [
  `From: ${from}`, 'To: editor@journal.example', `Subject: ${subject}`, `Date: ${date}`, `Message-ID: <${id}@x.example>`, extra,
  'Content-Type: text/plain; charset=utf-8', '', body, '',
].filter((l) => l !== null).join('\r\n');

function workDir() {
  const root = mkdtempSync(join(tmpdir(), 'extract-test-'));
  const caseDir = join(root, 'mail', 'Reviews', '3 Assigned', 'Case A');
  mkdirSync(caseDir, { recursive: true });
  mkdirSync(join(root, 'mail', 'Reviews', '1 Proposals'), { recursive: true });
  writeFileSync(join(caseDir, '1.eml'), mail('a1', 'Rita Reviewer <rita@uni.example>', 'Review', 'Mon, 06 Jan 2025 10:00:00 +0000', 'I will review the book (ISBN 978-3-16-148410-0) by 31 March.'));
  writeFileSync(join(caseDir, '2.eml'), mail('a2', 'Editor <editor@journal.example>', 'Re: Review', 'Tue, 07 Jan 2025 10:00:00 +0000', 'Great, thanks.', 'In-Reply-To: <a1@x.example>'));
  writeFileSync(join(root, 'mail', 'Reviews', '1 Proposals', '3.eml'), mail('p1', 'Press <press@verlag.example>', 'New book', 'Wed, 08 Jan 2025 10:00:00 +0000', 'May we offer this book?'));
  const work = join(root, 'work');
  execFileSync(process.execPath, [join(scripts, 'eml.js'), 'index', join(root, 'mail'), '--out', work, '--me', 'editor@journal.example'], { stdio: 'pipe' });
  const index = JSON.parse(readFileSync(join(work, 'index.json'), 'utf8'));
  index.ner = true; // stands in for a --ner run (the model is not needed here)
  writeFileSync(join(work, 'index.json'), JSON.stringify(index));
  const priv = JSON.parse(readFileSync(join(work, 'private.json'), 'utf8'));
  const token = (email) => priv.people.find((p) => p.emails.includes(email)).id;
  return { root, work, rita: token('rita@uni.example'), press: token('press@verlag.example') };
}

const run = (args, env = {}) => new Promise((resolve) => {
  const p = spawn(process.execPath, [join(scripts, 'extract.js'), ...args], { env: { ...process.env, ...env } });
  let out = '';
  let err = '';
  p.stdout.on('data', (d) => (out += d));
  p.stderr.on('data', (d) => (err += d));
  p.on('close', (code) => resolve({ code, out, err }));
});

test('list: each-folder makes one case per subfolder; each-thread one per thread', async () => {
  const { work } = workDir();
  const { out } = await run(['list', work, 'each-folder:3 Assigned', 'each-thread:1 Proposals']);
  const lines = out.trim().split('\n');
  assert.equal(lines.length, 2);
  assert.match(lines[0], /^3 Assigned\/Case A\t2 messages/);
  assert.match(lines[1], /^t\d+\t1 messages/);
});

test('run: sends masked text only, validates, and removes private data from the answer', async () => {
  const { work, rita } = workDir();
  const prompts = [];
  const server = createServer((req, res) => {
    let body = '';
    req.on('data', (d) => (body += d));
    req.on('end', () => {
      const request = JSON.parse(body);
      prompts.push(request.messages.map((m) => m.content).join('\n'));
      assert.equal(req.headers.authorization, 'Bearer secret-key');
      const answer = {
        kind: 'single',
        works: [{ title: 'A Book', isbn: ['978-3-16-148410-0', '978-3-16-148410-1'], evidence: ['m0001'] }],
        publisherContacts: [],
        review: { reviewer: rita, status: 'accepted', agreedOn: '2025-01-06', dueDate: '2025-03-31', dueDateBasis: 'mail of rita@uni.example',
          lastContact: '2025-01-07', copies: [{ work: 0, preference: 'have', status: 'notNeeded', evidence: ['m0001'] }], evidence: ['m0001', 'm9999'] },
        formerReviewers: [{ person: `${rita}@uni.example`, outcome: 'none' }],
        questions: [], confidence: 'high',
      };
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ choices: [{ message: { content: JSON.stringify(answer) } }], usage: { prompt_tokens: 100, completion_tokens: 50 } }));
    });
  });
  await new Promise((r) => server.listen(0, '127.0.0.1', r));
  const keyFile = join(work, '..', 'key');
  writeFileSync(keyFile, 'secret-key\n');
  const { code, out, err } = await run(['run', work, 'each-folder:3 Assigned', '--model', 'fake', '--out', join(work, 'out')],
    { OFR_LLM_BASE_URL: `http://127.0.0.1:${server.address().port}/v1`, OFR_LLM_KEY_FILE: keyFile });
  server.close();
  assert.equal(code, 0, err);
  assert.doesNotMatch(prompts.join('\n'), /Rita|rita@uni|editor@journal/, 'the prompt holds tokens only');
  assert.match(prompts[0], /31 March/);
  assert.doesNotMatch(out + err, /secret-key/);
  assert.match(out, /ok {3}3 Assigned\/Case A: single, 1 works, reviewer P\d+ accepted, 3 issues, 1 values REMOVED/);
  const [file] = readdirSync(join(work, 'out'));
  const record = JSON.parse(readFileSync(join(work, 'out', file), 'utf8'));
  assert.equal(record.result.review.dueDateBasis, '[removed: contained private data]');
  assert.deepEqual(record.removed, ['$.review.dueDateBasis']);
  assert.deepEqual(record.issues.sort(), [
    `formerReviewers[0]: "${rita}@uni.example" is not a bare token`,
    'review: evidence m9999 is not in this case',
    'works[0]: ISBN 978-3-16-148410-1 invalid',
  ].sort());
  // a second run skips cases already done
  const again = await run(['run', work, 'each-folder:3 Assigned', '--model', 'fake', '--out', join(work, 'out')], { OFR_LLM_KEY_FILE: keyFile });
  assert.match(again.out, /^skip 3 Assigned\/Case A/);
});

test('merge-extract: identifiers, folder rules, the reviewed work, essays and placeholders', () => {
  const dir = mkdtempSync(join(tmpdir(), 'merge-test-'));
  const rec = (c, result) => writeFileSync(join(dir, `${c.replace(/\W+/g, '_')}.json`), JSON.stringify({ case: c, result }));
  rec('3 Assigned/A', { kind: 'single', works: [{ title: 'Cited', isbn: [] }, { title: 'Reviewed', isbn: ['3-16-148410-X'] }],
    publisherContacts: [{ person: 'P0009' }],
    review: { reviewer: 'P0002', status: 'accepted', agreedOn: '2025-01-06', dueDate: null,
      copies: [{ work: 1, preference: 'physical', status: 'requested' }] }, formerReviewers: [{ person: 'P0003', outcome: 'gave up' }] });
  rec('4 Submitted/B', { kind: 'single', works: [{ title: 'B', doi: ['10.1000/b'] }], review: { reviewer: 'P0004', status: 'accepted', agreedOn: '2024-05-01', dueDate: '2025-01-01' } });
  rec('3 Assigned/C', { kind: 'essay', works: [{ title: 'C1', isbn: ['9780262046305'] }, { title: 'C2', isbn: ['9781108493116'] }],
    review: { reviewer: 'P0005', status: 'accepted', dueDate: '2026-06-30', copies: [{ work: 0, preference: 'have', status: 'notNeeded' }] } });
  rec('3 Assigned/D', { kind: 'essay', works: [{ title: 'D1', url: 'https://example.org/d1' }], review: { reviewer: 'P0006', status: 'accepted', dueDate: '2026-06-30' } });
  rec('t0001', { kind: 'proposal', works: [{ title: 'Offered', isbn: ['9783161484100'] }], review: null });
  rec('3 Assigned/E', { kind: 'single', works: [{ title: 'C1 again', isbn: ['978-0-262-04630-5'] }], review: { reviewer: 'P0008', status: 'accepted', dueDate: '2026-01-01' } });
  rec('t0002', { kind: 'none', works: [] });
  rec('old', { kind: 'single', works: [{ title: 'Old', isbn: ['9780262046305'] }], review: { reviewer: 'P0007', status: 'accepted' } });

  const res = spawnSync(process.execPath, [join(scripts, 'merge-extract.js'), dir, '--submitted', '4 Submitted', '--skip', 'old', '--placeholder-due', '2026-12-31'], { encoding: 'utf8' });
  assert.equal(res.status, 0, res.stderr);
  const doc = JSON.parse(readFileSync(join(dir, 'cases.json'), 'utf8'));
  const byKey = Object.fromEntries(doc.objects.map((o) => [o.isbn ?? o.doi ?? o.identifier, o]));

  // the review belongs to the work of the copy record, ISBN-10 normalized; placeholder due date; address placeholder
  const a = byKey['9783161484100'].assignments?.[0];
  assert.deepEqual({ ...a, _case: undefined, _confidence: undefined }, { email: 'P0002', status: 'accepted', dateCreated: '2025-01-06',
    dueDate: '2026-12-31', copyPreference: 'physical', copyStatus: 'requested', shippingAddress: '@P0002', _case: undefined, _confidence: undefined });
  assert.equal(byKey['9783161484100'].publisherContactEmail, 'P0009');
  assert.equal(byKey['9783161484100'].notifyPublisher, true);
  assert.ok(!doc.users.some((u) => u.person === 'P0003'), 'former reviewers are not imported');
  // --submitted stage
  assert.equal(byKey['10.1000/b'].assignments[0].status, 'submitted');
  assert.equal(byKey['10.1000/b'].assignments[0].dueDate, undefined);
  // essay with two works; its works have no assignments of their own
  assert.equal(doc.essays.length, 1);
  assert.deepEqual(doc.essays[0].works, ['9780262046305', '9781108493116']);
  assert.equal(byKey['9780262046305'].assignments, undefined);
  // an "essay" with one work becomes a single review on that work (by URL)
  assert.equal(byKey['https://example.org/d1'].assignments[0].email, 'P0006');
  assert.equal(byKey['https://example.org/d1'].lookup, false);
  // unresolved, skipped
  assert.equal(doc._unresolved[0], '3 Assigned/A: "Cited" has no ISBN, DOI or URL');
  assert.match(doc._unresolved[1], /^9780262046305: reviewed on its own \(3 Assigned\/E\) and in "Review essay: C1; C2"/);
  assert.equal(doc._unresolved.length, 2);
  assert.ok(doc._skipped.some((s) => s.startsWith('old:')) && doc._skipped.some((s) => s.startsWith('t0002:')));
});
