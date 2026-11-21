import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { execFileSync, spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { parseEmlBuffer, decodeWords, stripQuoted, smimeSignedContent } from '../scripts/lib/eml.js';
import { findIsbns, findDois } from '../scripts/lib/identifiers.js';
import { Pseudonymizer, splitName } from '../scripts/lib/pseudonymize.js';

const scripts = join(dirname(fileURLToPath(import.meta.url)), '..', 'scripts');
const crlf = (s) => s.replace(/\n/g, '\r\n');

const plainMail = crlf(`From: =?utf-8?Q?J=C3=BCrgen_M=C3=BCller?= <j.mueller@uni.example>
To: "Editor, The" <editor@journal.example>
Subject: =?iso-8859-1?Q?Rezension_f=FCr?= =?iso-8859-1?Q?_die_ZfRSoz?=
Date: Tue, 07 Jan 2025 10:00:00 +0100
Message-ID: <a1@uni.example>
Content-Type: text/plain; charset=iso-8859-1
Content-Transfer-Encoding: quoted-printable

Lieber Herr Editor,

gerne rezensiere ich ISBN 978-3-16-148410-0 (doi:10.1000/xyz123).
Mein Tel.: +49 30 1234567, Musterstra=DFe 5, 10115 Berlin

J=FCrgen M=FCller

> alter Text
`);

const multipartMail = crlf(`From: Press Office <press@verlag.example>
To: editor@journal.example
Subject: Re: Rezension für die ZfRSoz
Date: Wed, 08 Jan 2025 09:00:00 +0000
Message-ID: <b2@verlag.example>
In-Reply-To: <a1@uni.example>
MIME-Version: 1.0
Content-Type: multipart/mixed; boundary="outer"

--outer
Content-Type: multipart/alternative; boundary="alt"

--alt
Content-Type: text/html; charset=utf-8
Content-Transfer-Encoding: base64

${Buffer.from('<p>Wir senden das Buch an J&uuml;rgen M&uuml;ller.</p><p>Kontakt: other.person@verlag.example</p>').toString('base64')}
--alt--
--outer
Content-Type: application/pdf; name*=utf-8''Vertrag%20M%C3%BCller.pdf
Content-Disposition: attachment

JVBERi0=
--outer--
`);

test('headers: encoded words, adjacent words joined, addresses', () => {
  const m = parseEmlBuffer(Buffer.from(plainMail, 'latin1'));
  assert.equal(m.subject, 'Rezension für die ZfRSoz');
  assert.deepEqual(m.from, [{ name: 'Jürgen Müller', email: 'j.mueller@uni.example' }]);
  assert.deepEqual(m.to, [{ name: 'Editor, The', email: 'editor@journal.example' }]);
  assert.equal(m.date, '2025-01-07T09:00:00.000Z');
  assert.match(m.text, /Musterstraße 5/);
  assert.equal(decodeWords('=?UTF-8?B?w6Q=?= x'), 'ä x');
});

test('multipart: html alternative, base64, RFC 2231 attachment name', () => {
  const m = parseEmlBuffer(Buffer.from(multipartMail, 'latin1'));
  assert.match(m.text, /Wir senden das Buch an Jürgen Müller\./);
  assert.deepEqual(m.attachments.map((a) => a.filename), ['Vertrag Müller.pdf']);
  assert.deepEqual(m.inReplyTo, ['<a1@uni.example>']);
});

test('quoted replies are stripped', () => {
  assert.equal(stripQuoted('Neu\n> alt\nmehr'), 'Neu\nmehr');
  assert.equal(stripQuoted('Neu\n\nAm 3. Mai 2025 schrieb X <x@y.z>:\nalt'), 'Neu\n\n[… quoted text removed …]');
});

test('S/MIME signed-data content is unwrapped (BER, indefinite length)', () => {
  const inner = Buffer.from('Content-Type: text/plain\r\n\r\nsigned body');
  const tlv = (tag, ...parts) => {
    const body = Buffer.concat(parts);
    return Buffer.concat([Buffer.from([tag, 0x80]), body, Buffer.from([0, 0])]);
  };
  const prim = (tag, bytes) => Buffer.concat([Buffer.from([tag, bytes.length]), bytes]);
  const oid = (hex) => prim(0x06, Buffer.from(hex, 'hex'));
  const der = tlv(0x30, oid('2a864886f70d010702'),
    tlv(0xa0, tlv(0x30, prim(0x02, Buffer.from([1])), tlv(0x31),
      tlv(0x30, oid('2a864886f70d010701'), tlv(0xa0, tlv(0x24, prim(0x04, inner.subarray(0, 10)), prim(0x04, inner.subarray(10))))))));
  assert.equal(smimeSignedContent(der).toString(), inner.toString());
});

test('identifiers: ISBN checksum, ISBN-10 only when labeled, DOI punctuation', () => {
  assert.deepEqual(findIsbns('ISBN 978-3-16-148410-0 and 978-3-16-148410-1'), ['9783161484100']);
  assert.deepEqual(findIsbns('ISBN: 0-262-04630-1'), []); // invalid checksum
  assert.deepEqual(findIsbns('ISBN 3-16-148410-X'), ['9783161484100']);
  assert.deepEqual(findIsbns('call 3161484100'), []);
  assert.deepEqual(findDois('see https://doi.org/10.1000/ABC.1).'), ['10.1000/abc.1']);
});

test('pseudonymizer: names, addresses, phones, address lines; ISBNs survive', () => {
  assert.deepEqual(splitName('Müller, Jürgen'), { givenName: 'Jürgen', familyName: 'Müller' });
  assert.deepEqual(splitName('Anna von Berg'), { givenName: 'Anna', familyName: 'von Berg' });
  const p = new Pseudonymizer();
  const id = p.person('j.mueller@uni.example', 'Jürgen Müller');
  const masked = p.mask('Dear Jürgen Müller (j.mueller@uni.example), Müller wrote; x@y.example; +49 30 1234567\nMusterstraße 5\nISBN 978-3-16-148410-0');
  assert.doesNotMatch(masked, /Müller|Jürgen|mueller|x@y|1234567|Musterstraße/);
  assert.match(masked, new RegExp(`\\[${id}\\]`));
  assert.match(masked, /<P0002>/);
  assert.match(masked, /978-3-16-148410-0/);
});

test('CLI: index and show print tokens only; cases-to-import restores real data', () => {
  const root = mkdtempSync(join(tmpdir(), 'eml-test-'));
  const mail = join(root, 'mail', 'editor@journal.example', 'Reviews', '2 Assigned', 'Müller - Book');
  mkdirSync(mail, { recursive: true });
  writeFileSync(join(mail, '1 Rezension für Jürgen Müller.eml'), Buffer.from(plainMail, 'latin1'));
  writeFileSync(join(mail, '2.eml'), Buffer.from(multipartMail, 'latin1'));
  const work = join(root, 'work');
  const run = (...args) => execFileSync(process.execPath, [join(scripts, 'eml.js'), ...args], { encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'] });

  run('index', join(root, 'mail'), '--out', work, '--me', 'editor@journal.example');
  const index = readFileSync(join(work, 'index.json'), 'utf8');
  const shown = run('show', work, 't0001', '--full') + run('folders', work) + run('threads', work) + run('people', work);
  for (const secret of ['Müller', 'Jürgen', 'mueller', 'press@', 'other.person', '1234567', 'Musterstraße', 'editor@journal']) {
    assert.ok(!index.includes(secret), `index leaks ${secret}`);
    assert.ok(!shown.includes(secret), `output leaks ${secret}`);
  }
  assert.match(shown, /9783161484100/);
  assert.match(shown, /message body withheld/); // no --ner: headers only
  assert.doesNotMatch(shown, /Wir senden das Buch|gerne rezensiere/);
  assert.equal(run('grep', work, 'gerne'), ''); // bodies not searchable either
  assert.equal(JSON.parse(index).threads.length, 1);
  const people = JSON.parse(readFileSync(join(work, 'private.json'), 'utf8')).people;
  const reviewer = people.find((p) => p.emails[0] === 'j.mueller@uni.example').id;
  const masked = execFileSync(process.execPath, [join(scripts, 'eml.js'), 'mask', work], { input: 'created j.mueller@uni.example', encoding: 'utf8' });
  assert.equal(masked, `created <${reviewer}>`);
  const press = people.find((p) => p.emails[0] === 'press@verlag.example').id;
  const cases = {
    users: [{ person: press, roles: ['publisherContact'], _note: 'from t0001' }],
    objects: [{
      isbn: '9783161484100', publisherContactEmail: press, returnRequired: false, _thread: 't0001',
      assignments: [{ email: reviewer, status: 'accepted', dueDate: '2099-01-31', _evidence: 'm0001' }],
    }],
  };
  writeFileSync(join(work, 'cases.json'), JSON.stringify(cases));
  const conv = spawnSync(process.execPath, [join(scripts, 'cases-to-import.js'), join(work, 'cases.json'), '--work', work, '--out', join(work, 'import.json')], { encoding: 'utf8' });
  assert.equal(conv.status, 0, conv.stderr);
  for (const secret of ['Müller', 'mueller', 'press@']) assert.ok(!conv.stderr.includes(secret), `converter output leaks ${secret}`);
  const out = JSON.parse(readFileSync(join(work, 'import.json'), 'utf8'));
  assert.deepEqual(out.users[0], { email: 'press@verlag.example', givenName: 'Press', familyName: 'Office', roles: ['publisherContact'] });
  assert.equal(out.objects[0].publisherContactEmail, 'press@verlag.example');
  assert.deepEqual(out.objects[0].assignments[0], { email: 'j.mueller@uni.example', givenName: 'Jürgen', familyName: 'Müller', status: 'accepted', dueDate: '2099-01-31' });
});

test('cases-to-import: review essays resolve tokens and check works and copies', () => {
  const work = mkdtempSync(join(tmpdir(), 'eml-essay-'));
  writeFileSync(join(work, 'private.json'), JSON.stringify({ people: [
    { id: 'P0007', emails: ['rev@uni.example'], names: ['Rita Reviewer'], givenName: 'Rita', familyName: 'Reviewer', mailingAddress: 'Uni\nStreet 1' },
  ] }));
  const convert = (cases) => {
    writeFileSync(join(work, 'cases.json'), JSON.stringify(cases));
    return spawnSync(process.execPath, [join(scripts, 'cases-to-import.js'), join(work, 'cases.json'), '--work', work,
      '--out', join(work, 'import.json'), '--today', '2026-01-01'], { encoding: 'utf8' });
  };
  const good = convert({
    objects: [{ isbn: '978-3-16-148410-0', returnRequired: false }, { doi: '10.1000/Two', returnRequired: false }],
    essays: [{ title: 'Essay', works: ['9783161484100', '10.1000/two'], _thread: 't0001', assignments: [{
      email: 'P0007', status: 'accepted', dueDate: '2026-06-30', shippingAddress: '@P0007',
      copies: [{ work: '978-3-16-148410-0', copyPreference: 'physical', copyStatus: 'pending' }, { work: '10.1000/TWO', copyPreference: 'electronic' }],
    }] }],
  });
  assert.equal(good.status, 0, good.stderr);
  assert.ok(!good.stderr.includes('rev@uni'), 'converter output leaks the address');
  const [essay] = JSON.parse(readFileSync(join(work, 'import.json'), 'utf8')).essays;
  assert.equal(essay._thread, undefined);
  assert.deepEqual(essay.assignments[0], {
    email: 'rev@uni.example', status: 'accepted', dueDate: '2026-06-30', shippingAddress: 'Uni\nStreet 1', givenName: 'Rita', familyName: 'Reviewer',
    copies: [{ work: '978-3-16-148410-0', copyPreference: 'physical', copyStatus: 'pending' }, { work: '10.1000/TWO', copyPreference: 'electronic' }],
  });

  const bad = convert({
    objects: [{ isbn: '9783161484100', returnRequired: false, assignments: [{ email: 'P0007', status: 'accepted', dueDate: '2026-06-30' }] }],
    essays: [
      { title: 'One', works: ['9783161484100', '10.1000/elsewhere'], assignments: [{ email: 'P0007', status: 'accepted', dueDate: '2026-06-30',
        copyPreference: 'physical', copies: [{ work: '10.1000/other', copyPreference: 'electronic' }, { work: '10.1000/elsewhere', copyPreference: 'physical' }] }] },
      { title: 'Two', works: ['978-3-16-148410-0'] },
    ],
  });
  assert.equal(bad.status, 1);
  for (const expected of [/has assignments of its own/, /also in essays\[0\]/, /instead of copyPreference/, /not one of the essay's works/,
    /physical copy not yet sent needs shippingAddress/, /10\.1000\/elsewhere is not in objects/, /only one work/]) {
    assert.match(bad.stderr, expected);
  }
});

test('pseudonymizer: common words are not masked on their own, full names still are', () => {
  const p = new Pseudonymizer();
  const id = p.addName('The Law');            // NER took a title for a name
  const person = p.person('m@uni.example', 'Maria Buch');
  const masked = p.mask('The Law of Society. The book by Maria Buch; Buch wrote it.');
  assert.match(masked, new RegExp(`\\[${id}\\] of Society\\. The book by \\[${person}\\]; Buch wrote it\\.`));
});

test('pseudonymizer: corpus-common words are masked only after an honorific; addresses do not make names common', async () => {
  const { lowercaseWords } = await import('../scripts/lib/pseudonymize.js');
  const corpus = ['ich bin hier, ich komme, ich gehe', 'der wolf, ein wolf, kein wolf', 'mail anna.berg@uni.example, anna.berg@x.example, see www.berg.de anna.berg@y.example'];
  const common = lowercaseWords(corpus);
  assert.ok(common.includes('ich') && common.includes('wolf'));
  assert.ok(!common.includes('anna') && !common.includes('berg'), 'words from addresses must not count');
  const p = new Pseudonymizer([], [], { commonWords: common });
  const ich = p.addName('Ich');                 // NER mistake at a sentence start
  const wolf = p.person('w@uni.example', 'Peter Wolf');
  const berg = p.person('anna.berg@uni.example', 'Anna Berg');
  const masked = p.mask('Ich danke Peter Wolf. Lieber Herr Wolf, ich schreibe. Wolf antwortete. Berg auch.');
  assert.equal(masked, `Ich danke [${wolf}]. Lieber Herr [${wolf}], ich schreibe. [${wolf}] antwortete. [${berg}] auch.`);
  // a name found only by NER whose surname is a common word: masked after an honorific, not alone
  const q = new Pseudonymizer([], [], { commonWords: common });
  const nerWolf = q.addName('Petra Wolf');
  assert.equal(q.mask('Petra Wolf kam. Frau Wolf sprach. Wolf ging.'), `[${nerWolf}] kam. Frau [${nerWolf}] sprach. Wolf ging.`);
  assert.ok(ich);
});

test('pseudonymizer: UK and US address lines are masked, line by line', () => {
  const masked = new Pseudonymizer().mask('Centre for Socio-Legal Studies\nManor Road\n OX1 3UQ\nSpringfield, IL 62704\nThe book on law is good.\nbis 15. August 2026');
  assert.equal(masked, '[address line]\n[address line]\n[address line]\n[address line]\nThe book on law is good.\nbis 15. August 2026');
});

test('pseudonymizer: the same address spelt differently is one person; old tokens become aliases', () => {
  const p = new Pseudonymizer([
    { id: 'P0001', emails: ['a.b@uni.example'], names: ['Anna B'] },
    { id: 'P0002', emails: ['A.B@uni.example.'], names: ['Anna Berg'], mailingAddress: 'Uni\nStreet 1' },
  ]);
  assert.equal(p.person('mailto:a.b@UNI.example.'), 'P0001');
  assert.equal(p.get('P0002').id, 'P0001');
  assert.equal(p.get('P0001').mailingAddress, 'Uni\nStreet 1', 'fields added by hand are kept');
  assert.equal(p.people[1].aliasOf, 'P0001');
  assert.equal(p.mask('Anna Berg <a.b@uni.example.>'), '[P0001] <<P0001>.>');
});
