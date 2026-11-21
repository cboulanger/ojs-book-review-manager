// Needs the optional NER package and model: (cd ner && npm install && ./download-model.sh).
// Skipped otherwise. Synthetic data only.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { existsSync, mkdtempSync, mkdirSync, writeFileSync, readFileSync } from 'node:fs';
import { tmpdir, homedir } from 'node:os';
import { join, dirname } from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { Pseudonymizer } from '../scripts/lib/pseudonymize.js';

const here = dirname(fileURLToPath(import.meta.url));
const scripts = join(here, '..', 'scripts');
const model = process.env.OFR_NER_MODEL || join(homedir(), '.cache', 'ofr-email-import', 'gliner_multi_pii-v1.onnx');
const ready = existsSync(model) && existsSync(join(here, '..', 'ner', 'node_modules', 'gliner'));

test('pseudonymizer: name-only people and literals', () => {
  const p = new Pseudonymizer();
  const a = p.person('k.seidel@verlag.example', 'Seidel, Katharina');
  assert.equal(p.addName('Katharina Seidel'), a); // known name -> same token
  const b = p.addName('Tobias Wendt');
  assert.notEqual(b, a);
  assert.equal(p.get(b).emails.length, 0);
  assert.ok(p.addLiteral('Lindenallee 14, 04109 Leipzig', 'address'));
  assert.equal(p.addLiteral('14', 'address'), false); // too short to mask safely
  assert.equal(p.mask('Bitte an Lindenallee 14,\n04109 Leipzig, Herr Wendt'), `Bitte an [address], Herr [${b}]`);
});

test('CLI --ner masks names, addresses and phones that appear in no header', { skip: !ready && 'NER package or model missing' }, () => {
  const root = mkdtempSync(join(tmpdir(), 'eml-ner-'));
  const dir = join(root, 'mail', 'Reviews', 'Assigned');
  mkdirSync(dir, { recursive: true });
  writeFileSync(join(dir, 'a.eml'), [
    'From: Press Office <press@verlag.example>',
    'To: editor@journal.example',
    'Subject: Rezensionsexemplar',
    'Date: Wed, 08 Jan 2025 09:00:00 +0000',
    'Message-ID: <n1@verlag.example>',
    'Content-Type: text/plain; charset=utf-8',
    '',
    'Sehr geehrter Herr Dr. Albrecht,',
    '',
    'Frau Katharina Seidel aus unserem Lektorat schickt das Rezensionsexemplar',
    '(ISBN 978-3-16-148410-0) an Marek Nowak, Lindenallee 14, 04109 Leipzig.',
    '',
    'Mit freundlichen Grüßen',
    'Tobias Wendt',
    'Tel. 0341 9876543',
    '',
  ].join('\r\n'));
  const work = join(root, 'work');
  execFileSync(process.execPath, [join(scripts, 'eml.js'), 'index', join(root, 'mail'), '--out', work, '--me', 'editor@journal.example', '--ner'], { stdio: 'pipe' });
  const shown = execFileSync(process.execPath, [join(scripts, 'eml.js'), 'show', work, 't0001', '--full'], { encoding: 'utf8' });
  for (const secret of ['Albrecht', 'Seidel', 'Nowak', 'Wendt', 'Lindenallee', '9876543']) {
    assert.ok(!shown.includes(secret), `output leaks ${secret}:\n${shown}`);
  }
  assert.match(shown, /978-3-16-148410-0/);
  assert.match(shown, /Rezensionsexemplar/);
  const priv = JSON.parse(readFileSync(join(work, 'private.json'), 'utf8'));
  assert.ok(priv.people.some((p) => p.source === 'ner' && p.names.includes('Marek Nowak')));
});
