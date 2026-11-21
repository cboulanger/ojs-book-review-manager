#!/usr/bin/env node
// Read an exported tree of .eml files, pseudonymize it locally and group it into
// threads, so that review processes can be reconstructed without exposing who
// wrote what. Read-only: it never touches the mail files.
//
// Everything this tool prints, and index.json, contains tokens (P0012) instead of
// names, addresses and phone numbers. The real data stays in private.json.
// --ner adds a local GLiNER pass (see ner/) that also finds names, street
// addresses and phone numbers in the text of people who never wrote a mail.
// Without --ner, message bodies are never shown: show prints the (masked)
// headers only and grep searches subjects only. Private correspondence must not
// reach a remote model with only the header-based masking.
//
//   eml.js index   <mail dir> --out <work dir> [--me a@x,b@y] [--hide-domains] [--ner [--ner-threshold 0.5]]
//   eml.js folders <work dir>
//   eml.js people  <work dir>
//   eml.js threads <work dir> [--folder TEXT] [--match REGEX] [--json]
//   eml.js show    <work dir> <threadId|messageId|folder>... [--full] [--max CHARS]
//   eml.js grep    <work dir> <REGEX> [--folder TEXT]
//   some-command | eml.js mask <work dir>     (masks stdin, e.g. the ofr import report)

import { readdirSync, readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs';
import { join, relative, resolve, dirname, sep } from 'node:path';
import { parseEmlFile, stripQuoted } from './lib/eml.js';
import { findDois, findIsbns } from './lib/identifiers.js';
import { Pseudonymizer } from './lib/pseudonymize.js';

function parseArgs(argv) {
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

function* walkEml(dir) {
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const p = join(dir, entry.name);
    if (entry.isDirectory()) yield* walkEml(p);
    else if (entry.isFile() && /\.eml$/i.test(entry.name)) yield p;
  }
}

const normSubject = (s) =>
  (s || '')
    .replace(/^(\s*((re|aw|wg|fw|fwd|antw|sv|vs|tr)(\[\d+\])?\s*:|\[[^\]]*\])\s*)+/i, '')
    .replace(/\s+/g, ' ')
    .trim()
    .toLowerCase();

const privatePath = (work) => join(work, 'private.json');
const indexPath = (work) => join(work, 'index.json');

function loadWork(work) {
  if (!existsSync(indexPath(work))) throw new Error(`no index in ${work}; run "eml.js index <mail dir> --out ${work}"`);
  const priv = JSON.parse(readFileSync(privatePath(work), 'utf8'));
  return { index: JSON.parse(readFileSync(indexPath(work), 'utf8')), priv, pseudo: new Pseudonymizer(priv.people, priv.literals ?? []) };
}

function savePrivate(work, priv, pseudo) {
  priv.people = pseudo.people;
  priv.literals = pseudo.literals;
  writeFileSync(privatePath(work), JSON.stringify(priv, null, 1));
}

async function buildIndex(root, work, opt) {
  // keep the tokens of an earlier run stable
  const old = existsSync(privatePath(work)) ? JSON.parse(readFileSync(privatePath(work), 'utf8')) : { people: [] };
  const pseudo = new Pseudonymizer(old.people, old.literals ?? []);
  const parsed = new Map();
  let failed = 0;
  for (const file of walkEml(root)) {
    const rel = relative(root, file);
    let mail;
    try {
      mail = parseEmlFile(file);
    } catch (error) {
      failed++;
      console.error(`! unreadable file #${failed}: ${error.message}`);
      continue;
    }
    const folder = dirname(rel) === '.' ? '' : dirname(rel).split(sep).join('/');
    const key = mail.messageId ?? `${mail.date}|${mail.from[0]?.email}|${mail.subject}`;
    const existing = parsed.get(key);
    if (existing) {
      // the same message filed in several folders (e.g. Sent and a workflow folder)
      if (!existing.folders.includes(folder)) existing.folders.push(folder);
      continue;
    }
    parsed.set(key, { rel, folders: [folder], mail });
  }

  // register every person from the headers first, so that their names are masked everywhere
  for (const { mail } of parsed.values()) {
    for (const a of [...mail.from, ...mail.to, ...mail.cc, ...mail.replyTo]) if (a.email) pseudo.person(a.email, a.name);
  }
  if (opt.ner) await nerPass([...parsed.values()], pseudo, opt);
  const tokens = (list) => list.map((a) => (a.email ? pseudo.person(a.email, a.name) : pseudo.mask(a.name ?? 'unknown')));

  const messages = [...parsed.values()]
    .sort((a, b) => (a.mail.date ?? '').localeCompare(b.mail.date ?? ''))
    .map(({ rel, folders, mail }, i) => {
      const raw = `${mail.subject}\n${mail.text}\n${mail.attachments.map((a) => a.filename ?? '').join('\n')}`;
      return {
        id: `m${String(i + 1).padStart(4, '0')}`,
        file: rel, // moved to private.json below
        folders: folders.map((f) => pseudo.mask(f)),
        date: mail.date,
        from: tokens(mail.from),
        to: tokens(mail.to),
        cc: tokens(mail.cc),
        subject: pseudo.mask(mail.subject),
        messageId: mail.messageId,
        inReplyTo: mail.inReplyTo,
        references: mail.references,
        attachments: mail.attachments.map((a) => a.filename).filter(Boolean).map((f) => pseudo.mask(f)),
        dois: findDois(raw),
        isbns: findIsbns(raw),
        chars: mail.text.length,
      };
    });

  // "me": the journal's own address(es); default: the most frequent person
  const freq = new Map();
  for (const m of messages) for (const t of new Set([...m.from, ...m.to, ...m.cc])) freq.set(t, (freq.get(t) ?? 0) + 1);
  const me = opt.me
    ? String(opt.me).split(',').map((e) => pseudo.person(e.trim()))
    : [...freq.entries()].sort((a, b) => b[1] - a[1]).slice(0, 1).map(([t]) => t);
  const people = (m) => [...m.from, ...m.to, ...m.cc].filter((t) => /^P\d+$/.test(t));

  // threads: union-find over Message-ID references, then same subject + shared correspondent
  const parent = messages.map((_, i) => i);
  const find = (i) => (parent[i] === i ? i : (parent[i] = find(parent[i])));
  const union = (a, b) => (parent[find(a)] = find(b));
  const byId = new Map(messages.map((m, i) => [m.messageId, i]).filter(([id]) => id));
  messages.forEach((m, i) => {
    for (const ref of [...m.inReplyTo, ...m.references]) if (byId.has(ref)) union(i, byId.get(ref));
  });
  const bySubject = new Map();
  messages.forEach((m, i) => {
    const s = normSubject(m.subject);
    if (s.length < 8) return;
    const others = new Set(people(m).filter((t) => !me.includes(t)));
    for (const j of bySubject.get(s) ?? []) if (people(messages[j]).some((t) => others.has(t))) union(i, j);
    bySubject.set(s, [...(bySubject.get(s) ?? []), i]);
  });

  const groups = new Map();
  messages.forEach((m, i) => groups.set(find(i), [...(groups.get(find(i)) ?? []), m]));
  const threads = [...groups.values()]
    .sort((a, b) => (a[0].date ?? '').localeCompare(b[0].date ?? ''))
    .map((list, n) => {
      const id = `t${String(n + 1).padStart(4, '0')}`;
      const stats = new Map();
      for (const m of list) {
        for (const t of people(m)) {
          if (me.includes(t)) continue;
          const p = stats.get(t) ?? { person: t, domain: opt['hide-domains'] ? undefined : pseudo.domain(t), sent: 0, received: 0 };
          if (m.from.includes(t)) p.sent++;
          else p.received++;
          stats.set(t, p);
        }
      }
      list.forEach((m) => (m.thread = id));
      return {
        id,
        subject: list[0].subject,
        folders: [...new Set(list.flatMap((m) => m.folders))],
        start: list[0].date,
        end: list[list.length - 1].date,
        count: list.length,
        participants: [...stats.values()].sort((a, b) => b.sent + b.received - (a.sent + a.received)),
        dois: [...new Set(list.flatMap((m) => m.dois))],
        isbns: [...new Set(list.flatMap((m) => m.isbns))],
        messages: list.map((m) => m.id),
      };
    });

  const files = Object.fromEntries(messages.map((m) => [m.id, m.file]));
  messages.forEach((m) => delete m.file);
  const priv = {
    note:
      'PRIVATE: real names, addresses and file paths behind the tokens. Do not share it or show it to an AI assistant. ' +
      'You may correct givenName/familyName and add affiliation, mailingAddress, phone, country to people.',
    root: resolve(root),
    files,
    people: pseudo.people,
    literals: pseudo.literals,
  };
  const index = { generatedAt: new Date().toISOString(), ner: Boolean(opt.ner), me, failed, hideDomains: Boolean(opt['hide-domains']), messages, threads };
  return { index, priv };
}

/** Local NER over all texts: new names become name-only people, addresses and phone numbers literals. Prints counts only. */
async function nerPass(items, pseudo, opt) {
  let ner;
  try {
    ner = await import('../ner/ner.js');
  } catch (error) {
    throw new Error(`--ner needs the optional NER package: (cd ner && npm install && ./download-model.sh)\n${error.message}`);
  }
  const detector = await ner.createNer({
    modelPath: typeof opt['ner-model'] === 'string' ? opt['ner-model'] : undefined,
    threshold: opt['ner-threshold'] ? Number(opt['ner-threshold']) : 0.5,
  });
  // Mail threads quote each other over and over: check every distinct paragraph once.
  // Quote markers are removed so that a quoted copy matches its original.
  const paragraphs = new Set();
  for (const { folders, mail } of items) {
    const text = [mail.subject, ...folders.flatMap((f) => f.split('/')), ...mail.attachments.map((a) => a.filename ?? ''), mail.text].join('\n\n');
    for (const para of text.split(/\n\s*\n/)) {
      const norm = para
        .split('\n')
        .map((l) => l.replace(/^(\s*>)+\s?/, '').trim())
        .filter(Boolean)
        .join('\n');
      // names start with a capital letter; addresses and phone numbers contain digits
      if (norm.length >= 3 && /[\p{Lu}\d]/u.test(norm)) paragraphs.add(norm);
    }
  }
  const list = [...paragraphs].sort((a, b) => a.length - b.length); // similar lengths batch with little padding
  const counts = { person: 0, address: 0, phone: 0 };
  const started = Date.now();
  const step = 64;
  for (let i = 0; i < list.length; i += step) {
    for (const found of await detector.detect(list.slice(i, i + step))) {
      for (const e of found) {
        if (e.kind === 'person') {
          const name = ner.cleanPerson(e.text);
          const before = pseudo.people.length;
          if (name) pseudo.addName(name);
          if (pseudo.people.length > before) counts.person++;
        } else if (e.kind === 'address' || e.kind === 'phone') {
          // addresses contain a house number or postcode; an ISBN is not a phone number
          if (!/\d/.test(e.text) || findIsbns(e.text).length) continue;
          if (pseudo.addLiteral(e.text, e.kind)) counts[e.kind]++;
        }
      }
    }
    const done = Math.min(i + step, list.length);
    const doneChars = list.slice(0, done).reduce((n, t) => n + t.length, 0);
    const allChars = list.reduce((n, t) => n + t.length, 0);
    const secs = (Date.now() - started) / 1000;
    const eta = doneChars ? Math.round((secs * (allChars - doneChars)) / doneChars / 60) : '?';
    process.stderr.write(`\rNER: ${done}/${list.length} distinct paragraphs, ${Math.round(secs / 60)} min, about ${eta} min left   `);
  }
  console.error(`\nNER: ${counts.person} new names, ${counts.address} addresses, ${counts.phone} phone numbers (${Math.round((Date.now() - started) / 1000)} s)`);
}

const day = (iso) => (iso ? iso.slice(0, 10) : '????-??-??');

function threadLine(t) {
  const who = t.participants.slice(0, 4).map((p) => (p.domain ? `${p.person}@${p.domain}` : p.person)).join(', ');
  const ids = [...t.isbns, ...t.dois].join(' ');
  return `${t.id}  ${day(t.start)}..${day(t.end)}  ${String(t.count).padStart(3)}  [${t.folders.join(' | ')}]  ${t.subject}  <${who}>${ids ? '  ' + ids : ''}`;
}

function maskedText(work, m, full) {
  const mail = parseEmlFile(join(work.priv.root, work.priv.files[m.id]));
  return work.pseudo.mask(full ? mail.text : stripQuoted(mail.text));
}

const BODY_WITHHELD = '[message body withheld: the index was built without --ner, so only header data is shown]';

function printMessage(work, m, { full, max }) {
  let text = work.index.ner ? maskedText(work, m, full) : BODY_WITHHELD;
  if (text.length > max) text = text.slice(0, max) + `\n[… ${text.length - max} more characters, use --max]`;
  console.log(`=== ${m.id} (${m.thread})  ${m.date ?? ''}`);
  console.log(`From: ${m.from.join(', ')}   To: ${m.to.join(', ')}${m.cc.length ? `   Cc: ${m.cc.join(', ')}` : ''}`);
  console.log(`Subject: ${m.subject}`);
  console.log(`Folder: ${m.folders.join(' | ')}`);
  if (m.attachments.length) console.log(`Attachments: ${m.attachments.join(', ')}`);
  console.log(`\n${text}\n`);
}

const inFolder = (m, text) => !text || m.folders.some((f) => f.toLowerCase().includes(String(text).toLowerCase()));

const { pos, opt } = parseArgs(process.argv.slice(2));
const [command, dir, ...args] = pos;

switch (command) {
  case 'index': {
    if (!dir || !opt.out) throw new Error('usage: eml.js index <mail dir> --out <work dir> [--me addr,...] [--hide-domains]');
    mkdirSync(opt.out, { recursive: true });
    const { index, priv } = await buildIndex(dir, opt.out, opt);
    writeFileSync(indexPath(opt.out), JSON.stringify(index, null, 1));
    writeFileSync(privatePath(opt.out), JSON.stringify(priv, null, 1));
    console.error(
      `${index.messages.length} messages, ${index.threads.length} threads, ${priv.people.length} people, ${index.failed} unreadable\n` +
        `"me" = ${index.me.join(', ')} (override with --me)\n` +
        `-> ${indexPath(opt.out)} (pseudonymized)\n-> ${privatePath(opt.out)} (PRIVATE, keep it local)`,
    );
    break;
  }
  case 'folders': {
    const { index } = loadWork(dir);
    const counts = new Map();
    for (const m of index.messages) for (const f of m.folders) counts.set(f, (counts.get(f) ?? 0) + 1);
    for (const [f, n] of [...counts.entries()].sort()) console.log(`${String(n).padStart(5)}  ${f || '(root)'}`);
    break;
  }
  case 'people': {
    const { index, pseudo } = loadWork(dir);
    const stats = new Map();
    for (const t of index.threads) for (const p of t.participants) {
      const s = stats.get(p.person) ?? { threads: 0, sent: 0, received: 0, domain: p.domain };
      s.threads++;
      s.sent += p.sent;
      s.received += p.received;
      stats.set(p.person, s);
    }
    for (const p of pseudo.people) {
      const s = stats.get(p.id);
      if (!s) continue;
      const known = ['givenName', 'familyName', 'affiliation', 'mailingAddress'].filter((k) => p[k]).join(',');
      console.log(`${p.id}  ${s.domain ?? ''}  threads=${s.threads} sent=${s.sent} received=${s.received}  known: ${known || '-'}${index.me.includes(p.id) ? '  (me)' : ''}`);
    }
    break;
  }
  case 'threads': {
    const { index } = loadWork(dir);
    const re = opt.match ? new RegExp(opt.match, 'i') : null;
    const list = index.threads.filter(
      (t) =>
        (!opt.folder || t.folders.some((f) => f.toLowerCase().includes(String(opt.folder).toLowerCase()))) &&
        (!re || re.test(t.subject) || t.participants.some((p) => re.test(p.person) || re.test(p.domain ?? ''))),
    );
    if (opt.json) console.log(JSON.stringify(list, null, 1));
    else list.forEach((t) => console.log(threadLine(t)));
    console.error(`${list.length} threads`);
    break;
  }
  case 'show': {
    const work = loadWork(dir);
    const max = Number(opt.max ?? 4000);
    for (const id of args) {
      const thread = work.index.threads.find((t) => t.id === id);
      let ids = thread ? thread.messages : [id];
      if (thread) console.log(`##### ${threadLine(thread)}\n`);
      else if (!/^m\d+$/.test(id)) {
        // a folder (as printed by "folders", or its end): all messages filed there
        const f = id.toLowerCase().replace(/\/+$/, '');
        ids = work.index.messages.filter((m) => m.folders.some((x) => x.toLowerCase().endsWith(f))).map((m) => m.id);
        console.log(`##### folder "${id}": ${ids.length} messages\n`);
      }
      for (const mid of ids) {
        const m = work.index.messages.find((x) => x.id === mid);
        if (!m) console.error(`unknown id ${mid}`);
        else printMessage(work, m, { full: Boolean(opt.full), max });
      }
    }
    savePrivate(dir, work.priv, work.pseudo); // addresses found in bodies got new tokens
    break;
  }
  case 'grep': {
    const work = loadWork(dir);
    const re = new RegExp(args[0], 'i');
    if (!work.index.ner) console.error('index built without --ner: searching subjects only');
    for (const m of work.index.messages) {
      if (!inFolder(m, opt.folder)) continue;
      const searchable = work.index.ner ? `${m.subject}\n${maskedText(work, m, true)}` : m.subject; // no bodies without --ner
      const lines = searchable.split('\n').filter((l) => re.test(l));
      for (const l of lines.slice(0, 3)) console.log(`${m.thread} ${m.id} ${day(m.date)}  ${l.trim().slice(0, 200)}`);
    }
    savePrivate(dir, work.priv, work.pseudo);
    break;
  }
  case 'mask': {
    const work = loadWork(dir);
    const input = readFileSync(0, 'utf8');
    process.stdout.write(work.pseudo.mask(input));
    savePrivate(dir, work.priv, work.pseudo);
    break;
  }
  default:
    console.error(readFileSync(new URL(import.meta.url), 'utf8').split('\n').slice(1, 17).join('\n').replace(/^\/\/ ?/gm, ''));
    process.exit(command ? 2 : 0);
}
