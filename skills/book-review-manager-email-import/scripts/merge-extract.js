#!/usr/bin/env node
// Build a cases file from extract.js results (mode B), with fixed rules. A starting point that the
// orchestrator reviews with the user, not a finished import.
//
//   merge-extract.js <extract dir> [--out cases.json] [--submitted <stage>] [--skip <case>]...
//                    [--placeholder-due YYYY-MM-DD] [--title-lookup]
//
// Rules:
// - works: identified by a valid ISBN (normalized to ISBN-13), else DOI, else (with --title-lookup) a
//   Crossref title search, else the URL (identifierType uri, lookup false); the same book in several
//   cases becomes one work. Works without any identifier are listed in _unresolved.
// - review.status accepted/submitted is imported; cases of the --submitted stage count as submitted;
//   proposed/invited/bid/offered become works without a review; published/declined/withdrawn are left out;
//   formerReviewers are never imported.
// - a review belongs to the work its copy record refers to (else the first identifiable work);
//   a review essay needs two identifiable works, otherwise it is treated as a single review;
//   a work both in an essay and reviewed on its own is reported in _unresolved.
// - a missing due date gets --placeholder-due (default: today + 6 months) with _confidence low;
//   a printed copy not yet sent gets shippingAddress "@<reviewer>" (the user adds the address).
// - publisherContacts become users with role publisherContact, set on the case's works with notifyPublisher.
// Prints counts, titles and tokens only.

import { readdirSync, readFileSync, writeFileSync, existsSync } from 'node:fs';
import { join } from 'node:path';
import { parseArgs } from './lib/work.js';
import { isbn10Valid, isbn13Valid, isbn10to13 } from './lib/identifiers.js';

const { pos, opt } = parseArgs(process.argv.slice(2));
const dir = pos[0];
if (!dir) {
  console.error(readFileSync(new URL(import.meta.url), 'utf8').split('\n').slice(1, 22).join('\n').replace(/^\/\/ ?/gm, ''));
  process.exit(2);
}
const skip = new Set([].concat(opt.skip ?? []).filter((x) => x !== true));
const submittedStage = opt.submitted && opt.submitted !== true ? String(opt.submitted) : null;
const placeholder = opt['placeholder-due'] && opt['placeholder-due'] !== true ? String(opt['placeholder-due'])
  : new Date(Date.now() + 182 * 864e5).toISOString().slice(0, 10);
const out = opt.out && opt.out !== true ? String(opt.out) : join(dir, 'cases.json');
const TOKEN = /^P\d{4,}$/;

function toIsbn13(value) {
  const d = String(value ?? '').replace(/[^\dX]/gi, '').toUpperCase();
  if (d.length === 13 && isbn13Valid(d)) return d;
  if (d.length === 10 && isbn10Valid(d)) return isbn10to13(d);
  return null;
}

const normTitle = (t) => String(t ?? '').toLowerCase().replace(/[–-]/g, ' ').replace(/[^\p{L}\p{N} ]/gu, '').replace(/\s+/g, ' ').trim();

// Crossref title search (public bibliographic data; only the title is sent), cached next to the results
const cacheFile = join(dir, 'title-lookup.json');
const cache = existsSync(cacheFile) ? JSON.parse(readFileSync(cacheFile, 'utf8')) : {};
let lookedUp = 0;
async function byTitle(title) {
  if (!opt['title-lookup'] || !title || /^\[P\d+\]/.test(title)) return null;
  if (!(title in cache)) {
    const url = 'https://api.crossref.org/works?rows=5&filter=type:monograph,type:edited-book,type:book'
      + `&select=title,ISBN&query.bibliographic=${encodeURIComponent(title)}`;
    try {
      const res = await fetch(url, { headers: { 'User-Agent': 'book-review-manager-merge/1.0' }, signal: AbortSignal.timeout(30000) });
      const items = (await res.json()).message.items;
      const want = normTitle(title);
      const hit = items.find((i) => {
        const t = normTitle(i.title?.[0]);
        return t && (want.startsWith(t) || t.startsWith(want)) && t.length >= Math.min(want.length, 12) * 0.6 && i.ISBN?.length;
      });
      cache[title] = hit ? toIsbn13(hit.ISBN[0]) : null;
      writeFileSync(cacheFile, JSON.stringify(cache, null, 1));
    } catch {
      return null;
    }
    await new Promise((r) => setTimeout(r, 1500));
  }
  if (cache[title]) lookedUp++;
  return cache[title];
}

async function identify(w) {
  for (const i of w.isbn ?? []) if (toIsbn13(i)) return { isbn: toIsbn13(i) };
  for (const d of w.doi ?? []) if (String(d).startsWith('10.')) return { doi: d };
  const found = await byTitle(w.title);
  if (found) return { isbn: found, _isbnFrom: 'Crossref title search' };
  if (w.url) return { identifier: w.url, identifierType: 'uri', lookup: false, title: w.title || w.url };
  return null;
}
const keyOf = (id) => id.isbn ?? id.doi ?? id.identifier;

const users = new Map();
const objects = new Map();
const essays = [];
const unresolved = [];
const skipped = [];

for (const file of readdirSync(dir).filter((f) => f.endsWith('.json') && !['cases.json', 'title-lookup.json'].includes(f)).sort()) {
  const rec = JSON.parse(readFileSync(join(dir, file), 'utf8'));
  const c = rec.case;
  const r = rec.result;
  if (!c) continue;
  if (skip.has(c)) { skipped.push(`${c}: left out (--skip)`); continue; }
  if (!r || r.kind === 'none') { skipped.push(`${c}: ${rec.error ?? 'not about a review'}`); continue; }

  const works = [];
  for (const w of r.works ?? []) {
    const id = await identify(w);
    if (!id) { unresolved.push(`${c}: "${w.title ?? '?'}" has no ISBN, DOI or URL`); works.push(null); continue; }
    const k = keyOf(id);
    if (!objects.has(k)) objects.set(k, { ...id, returnRequired: false, _title: w.title, _case: [] });
    objects.get(k)._case.push(c);
    works.push(k);
  }
  for (const pc of r.publisherContacts ?? []) {
    if (!TOKEN.test(pc.person ?? '')) continue;
    if (!users.has(pc.person)) users.set(pc.person, { person: pc.person, roles: ['publisherContact'] });
    for (const k of works) if (k && !objects.get(k).publisherContactEmail) Object.assign(objects.get(k), { publisherContactEmail: pc.person, notifyPublisher: true });
  }

  const v = r.review;
  let status = { accepted: 'accepted', submitted: 'submitted' }[v?.status];
  if (status && submittedStage && c.startsWith(submittedStage)) status = 'submitted';
  if (!status || !TOKEN.test(v.reviewer ?? '')) continue;
  if (!users.has(v.reviewer)) users.set(v.reviewer, { person: v.reviewer, roles: ['author'] });
  const a = { email: v.reviewer, status, _case: c };
  if (v.agreedOn) a.dateCreated = v.agreedOn;
  if (status === 'accepted') {
    a.dueDate = v.dueDate || placeholder;
    if (!v.dueDate) a._confidence = 'low: no due date in the mails (placeholder)';
    else if (v.dueDateBasis) a._note = `due date: ${v.dueDateBasis}`;
  }
  const copies = (v.copies ?? []).filter((cp) => Number.isInteger(cp.work) && works[cp.work]);
  const valid = works.filter(Boolean);
  const needsAddress = (pref, st) => pref === 'physical' && [undefined, 'pending', 'requested'].includes(st);
  const copyFields = (cp) => ({
    ...(['have', 'physical', 'electronic', 'other'].includes(cp.preference) ? { copyPreference: cp.preference } : {}),
    ...(cp.status && cp.status !== 'unknown' ? { copyStatus: cp.status } : {}),
  });

  if (r.kind === 'essay' && valid.length >= 2) {
    a.copies = copies.map((cp) => ({ work: works[cp.work], ...copyFields(cp) }));
    if (a.copies.some((cp) => needsAddress(cp.copyPreference, cp.copyStatus))) a.shippingAddress = `@${v.reviewer}`;
    essays.push({ title: `Review essay: ${valid.map((k) => String(objects.get(k)._title ?? k).slice(0, 40)).join('; ')}`, works: valid, assignments: [a] });
    continue;
  }
  const target = copies.length ? works[copies[0].work] : valid[0];
  if (!target) { unresolved.push(`${c}: review by ${v.reviewer} without an identifiable work`); continue; }
  const cp = copies.find((x) => works[x.work] === target);
  if (cp && status === 'accepted') {
    Object.assign(a, copyFields(cp));
    if (['journal', 'publisher'].includes(cp.sentBy)) a.copySentBy = cp.sentBy;
    if (cp.date) a.copyStatusDate = cp.date;
    if (needsAddress(a.copyPreference, a.copyStatus)) a.shippingAddress = `@${v.reviewer}`;
  }
  (objects.get(target).assignments ??= []).push(a);
}

// a work in an essay has no assignments of its own: a contradiction for the user to resolve
for (const e of essays) {
  for (const k of e.works) {
    const o = objects.get(k);
    if (o.assignments?.length) {
      unresolved.push(`${k}: reviewed on its own (${o.assignments.map((x) => x._case).join(', ')}) and in "${e.title}"; kept in the essay only`);
      delete o.assignments;
    }
  }
}

const doc = {
  _note: `built by merge-extract.js from ${dir}; review with the user before converting`,
  users: [...users.values()],
  objects: [...objects.values()],
  essays,
  _unresolved: unresolved,
  _skipped: skipped,
};
writeFileSync(out, JSON.stringify(doc, null, 1));
const reviews = doc.objects.reduce((n, o) => n + (o.assignments?.length ?? 0), 0) + essays.length;
console.error(`${doc.objects.length} works, ${essays.length} essays, ${reviews} review processes, `
  + `${doc.users.filter((u) => u.roles.includes('publisherContact')).length} publisher contacts; `
  + `${unresolved.length} unresolved, ${skipped.length} skipped${opt['title-lookup'] ? `, ${lookedUp} ISBNs by title` : ''} -> ${out}`);
for (const u of unresolved) console.error(`  unresolved: ${u}`);
