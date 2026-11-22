// Shared by eml.js and extract.js: the work dir (index.json, private.json) and masked message text.

import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import { join } from 'node:path';
import { parseEmlFile, stripQuoted } from './eml.js';
import { Pseudonymizer } from './pseudonymize.js';

export function parseArgs(argv) {
  const pos = [];
  const opt = {};
  for (let i = 0; i < argv.length; i++) {
    const a = argv[i];
    if (a.startsWith('--')) {
      const [k, v] = a.slice(2).split('=', 2);
      if (v !== undefined) opt[k] = v;
      else if (argv[i + 1] !== undefined && !argv[i + 1].startsWith('--')) opt[k] = argv[++i];
      else opt[k] = true;
    } else pos.push(a);
  }
  return { pos, opt };
}

export const privatePath = (work) => join(work, 'private.json');
export const indexPath = (work) => join(work, 'index.json');

export function loadWork(work) {
  if (!existsSync(indexPath(work))) throw new Error(`no index in ${work}; run "eml.js index <mail dir> --out ${work}"`);
  const priv = JSON.parse(readFileSync(privatePath(work), 'utf8'));
  return { index: JSON.parse(readFileSync(indexPath(work), 'utf8')), priv, pseudo: new Pseudonymizer(priv.people, priv.literals ?? [], { commonWords: priv.commonWords ?? [] }) };
}

export function savePrivate(work, priv, pseudo) {
  priv.people = pseudo.people;
  priv.literals = pseudo.literals;
  writeFileSync(privatePath(work), JSON.stringify(priv, null, 1));
}

export const day = (iso) => (iso ? iso.slice(0, 10) : '????-??-??');

/** A message's text, masked; quoted replies cut unless `full`. */
export function maskedText(work, m, full) {
  const mail = parseEmlFile(join(work.priv.root, work.priv.files[m.id]));
  return work.pseudo.mask(full ? mail.text : stripQuoted(mail.text));
}

