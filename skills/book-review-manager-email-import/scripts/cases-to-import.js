#!/usr/bin/env node
// Check a cases file against the import rules of the Book Review Manager API and
// write the import file for `ofr import`.
//
//   cases-to-import.js cases.json --work <work dir> [--out import.json] [--today YYYY-MM-DD]
//
// The cases file has the import format (users, objects, objects[].assignments,
// essays: review essays of several works, with essays[].assignments[].copies)
// plus bookkeeping fields that start with "_" (e.g. _thread, _evidence, _note,
// _confidence). They are stripped. Records with "_skip": true are left out.
//
// People are written as tokens from `eml.js index` (P0012): "email": "P0012" in
// assignments, publisherContactEmail, responsibleEditorEmail, and
// { "person": "P0012", "roles": [...] } in users. "shippingAddress": "@P0012"
// takes that person's mailingAddress. The real values come from
// <work dir>/private.json and only end up in the output file; messages on the
// console use tokens. Don't print the output file in an AI session.
// Exit code 1 if there are errors; warnings don't block.

import { readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

const args = process.argv.slice(2);
const opt = (name) => {
  const i = args.indexOf(`--${name}`);
  return i >= 0 ? args[i + 1] : undefined;
};
const input = args.find((a, i) => !a.startsWith('--') && !args[i - 1]?.startsWith('--'));
if (!input) {
  console.error('usage: cases-to-import.js cases.json [--out import.json] [--today YYYY-MM-DD]');
  process.exit(2);
}
const out = opt('out') ?? 'import.json';
const work = opt('work');
const today = opt('today') ?? new Date().toISOString().slice(0, 10);

const doc = JSON.parse(readFileSync(input, 'utf8'));
const errors = [];
const warnings = [];

// --- tokens -> people from private.json (values stay out of all messages) ---
const TOKEN = /^P\d{4,}$/;
const people = new Map(
  work ? JSON.parse(readFileSync(join(work, 'private.json'), 'utf8')).people.map((p) => [p.id, p]) : [],
);
// a token can be an alias of another (the same address spelt differently): follow it
const canonical = (token) => {
  let p = people.get(token);
  while (p?.aliasOf) p = people.get(p.aliasOf);
  return p;
};
const personOf = (token, where) => {
  const p = canonical(token);
  if (!p) errors.push(`${where}: unknown person ${token}${work ? '' : ' (pass --work)'}`);
  return p;
};
const PROFILE = ['givenName', 'familyName', 'affiliation', 'mailingAddress', 'phone', 'country'];
for (const [i, u] of (doc.users ?? []).entries()) {
  if (u.person && !u.email) u.email = u.person;
  if (!TOKEN.test(u.email ?? '')) continue;
  const p = personOf(u.email, `users[${i}]`);
  for (const k of PROFILE) if (p?.[k] && u[k] === undefined) u[k] = p[k];
}
const resolveAssignment = (a) => {
  if (TOKEN.test(a.email ?? '') && !(doc.users ?? []).some((u) => u.email === a.email)) {
    const p = personOf(a.email, `assignment ${a.email}`);
    for (const k of ['givenName', 'familyName']) if (p?.[k] && a[k] === undefined) a[k] = p[k];
  }
  if (/^@P\d{4,}$/.test(a.shippingAddress ?? '')) {
    const p = personOf(a.shippingAddress.slice(1), `shippingAddress of ${a.email}`);
    if (p && !p.mailingAddress) errors.push(`${a.email}: shippingAddress ${a.shippingAddress}, but that person has no mailingAddress in private.json`);
    a.shippingAddress = p?.mailingAddress;
  }
};
for (const o of [...(doc.objects ?? []), ...(doc.essays ?? [])]) {
  for (const k of ['publisherContactEmail', 'responsibleEditorEmail']) if (TOKEN.test(o[k] ?? '')) personOf(o[k], k);
  for (const a of o.assignments ?? []) resolveAssignment(a);
}
const realEmail = (v) => (TOKEN.test(v ?? '') ? canonical(v)?.emails[0] ?? v : v);

const strip = (value) => {
  if (Array.isArray(value)) return value.filter((v) => !v?._skip).map(strip);
  if (value && typeof value === 'object') {
    return Object.fromEntries(Object.entries(value).filter(([k]) => !k.startsWith('_')).map(([k, v]) => [k, strip(v)]));
  }
  return value;
};

const DATE = /^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/;
const STATUSES = ['bid', 'unsuccessful', 'offered', 'accepted', 'submitted', 'completed'];
const COPY_STATUSES = ['notNeeded', 'pending', 'requested', 'sent', 'received', 'returnRequested', 'returnSent', 'returned'];
const COPY_PREFS = ['have', 'physical', 'electronic', 'other'];

const users = new Map();
for (const [i, u] of (doc.users ?? []).entries()) {
  if (u._skip) continue;
  const where = `users[${i}] ${u.email ?? ''}`;
  if (!u.email || !(u.email.includes('@') || TOKEN.test(u.email))) errors.push(`${where}: email (or person token) missing or invalid`);
  if (!u.givenName) warnings.push(`${where}: no givenName (only works if the account already exists)`);
  if (users.has(u.email?.toLowerCase())) errors.push(`${where}: listed twice`);
  users.set(u.email?.toLowerCase(), u);
}
const hasRole = (email, role) => users.get(email?.toLowerCase())?.roles?.includes(role);

function checkAssignment(a, w, bidding) {
  if (!STATUSES.includes(a.status)) {
    errors.push(`${w}: status must be one of ${STATUSES.join(', ')} (declined/cancelled processes are left out)`);
    return;
  }
  if (!a.email && !a.userId) errors.push(`${w}: email or userId required`);
  if (a.email && !a.givenName && !users.get(a.email.toLowerCase())) {
    warnings.push(`${w}: not in users and no givenName (only works if the account already exists)`);
  }
  if (['bid', 'unsuccessful', 'offered'].includes(a.status) && !bidding) errors.push(`${w}: ${a.status} needs assignmentMode "bidding"`);
  if (a.status === 'accepted') {
    if (!a.dueDate) errors.push(`${w}: accepted needs dueDate`);
    else if (a.dueDate < today) warnings.push(`${w}: dueDate ${a.dueDate} is past; the reviewer counts as overdue`);
    else {
      const days = (Date.parse(a.dueDate) - Date.parse(today)) / 864e5;
      if (days < 14) warnings.push(`${w}: dueDate in ${days} days; a reminder may go out right after the import`);
    }
  }
  if (['submitted', 'completed'].includes(a.status) && !a.submissionId) {
    warnings.push(`${w}: no submissionId; the work won't show as submitted`);
  }
  for (const f of ['dueDate', 'contractDeadline', 'dateOffered', 'dateCreated', 'dateStatus', 'copyStatusDate']) {
    if (a[f] && !DATE.test(a[f])) errors.push(`${w}: ${f} must be Y-m-d`);
  }
  if (a.status === 'offered' && a.contractDeadline && a.contractDeadline < today) {
    warnings.push(`${w}: contractDeadline ${a.contractDeadline} is past; the offer expires on the next daily run`);
  }
  checkCopy(a, w, a);
}

/** Copy fields of an assignment, or of one entry in an essay's copies (shippingAddress is the assignment's). */
function checkCopy(c, w, a) {
  if (c.copyPreference && !COPY_PREFS.includes(c.copyPreference)) errors.push(`${w}: copyPreference must be one of ${COPY_PREFS.join(', ')}`);
  if (c.copyStatus && !COPY_STATUSES.includes(c.copyStatus)) errors.push(`${w}: copyStatus must be one of ${COPY_STATUSES.join(', ')}`);
  if (c.copyPreference === 'physical' && ['pending', 'requested', undefined].includes(c.copyStatus) && !a.shippingAddress) {
    errors.push(`${w}: physical copy not yet sent needs shippingAddress`);
  }
  if (c.copyStatus === 'requested' && !c.copyStatusDate) {
    warnings.push(`${w}: copyStatus requested without copyStatusDate; publisher reminders count from the import time`);
  }
}

const seenIds = new Set();
for (const [i, o] of (doc.objects ?? []).entries()) {
  if (o._skip) continue;
  const id = o.doi ?? o.isbn ?? o.identifier ?? o.title;
  const where = `objects[${i}] ${id ?? ''}${o._thread ? ` (${[].concat(o._thread).join(',')})` : ''}`;
  if (!o.doi && !o.isbn && !o.identifier) errors.push(`${where}: needs doi, isbn or identifier`);
  if (o.identifier && !o.identifierType) errors.push(`${where}: identifier without identifierType`);
  if ((o.lookup === false || o.identifier) && !o.title) errors.push(`${where}: title required without lookup`);
  if (id && seenIds.has(String(id).toLowerCase())) errors.push(`${where}: same work listed twice; merge the assignments`);
  seenIds.add(String(id ?? '').toLowerCase());
  for (const f of ['dateCreated', 'bidDeadline']) if (o[f] && !DATE.test(o[f])) errors.push(`${where}: ${f} must be Y-m-d`);
  if (o.publisherContactEmail && !hasRole(o.publisherContactEmail, 'publisherContact')) {
    warnings.push(`${where}: publisher contact ${o.publisherContactEmail} is not in users with role publisherContact (must already exist on the server)`);
  }
  if (o.returnRequired !== false) {
    const contact = users.get(o.publisherContactEmail?.toLowerCase());
    if (o.publisherContactEmail && contact && !contact.mailingAddress) {
      errors.push(`${where}: returnRequired (default true) needs a mailingAddress for ${o.publisherContactEmail}; add it or set returnRequired: false`);
    }
  }
  if (o.notifyPublisher && !o.publisherContactEmail && !o.publisherContactId) errors.push(`${where}: notifyPublisher needs a publisher contact`);

  const active = (o.assignments ?? []).filter((a) => !a._skip);
  const bidding = o.assignmentMode === 'bidding';
  const count = (s) => active.filter((a) => s.includes(a.status)).length;
  if (count(['accepted', 'submitted', 'completed']) > 1) errors.push(`${where}: only one accepted/submitted/completed reviewer per work`);
  if (count(['offered']) > 1) errors.push(`${where}: only one offered contract per work`);
  if (count(['offered']) && count(['accepted', 'submitted', 'completed'])) errors.push(`${where}: offered and accepted at the same time`);

  for (const [j, a] of active.entries()) checkAssignment(a, `${where} assignments[${j}] ${a.email ?? a.userId ?? ''}`, bidding);
}

// --- review essays: one review of several works ---
// A work is named as in objects: "<isbn or doi>" or { doi | isbn | identifier }; ISBN hyphens and case don't matter.
const workKey = (w) => {
  const v = String(typeof w === 'string' ? w : w?.doi ?? w?.isbn ?? w?.identifier ?? '').trim();
  return /^[\d\s-]+[\dXx]$/.test(v) ? v.replace(/[^\dXx]/g, '').toUpperCase() : v.toLowerCase();
};
const objectByKey = new Map((doc.objects ?? []).filter((o) => !o._skip).map((o) => [workKey(o.doi ?? o.isbn ?? o.identifier), o]));
const inEssay = new Map();
for (const [i, e] of (doc.essays ?? []).entries()) {
  if (e._skip) continue;
  const where = `essays[${i}] ${e.title ?? ''}`;
  if (!e.title) errors.push(`${where}: title required`);
  const works = e.works ?? [];
  if (!works.length) errors.push(`${where}: works required`);
  else if (works.length === 1) warnings.push(`${where}: only one work; is this really a review essay?`);
  const keys = works.map(workKey);
  for (const k of keys) {
    const o = objectByKey.get(k);
    if (!o) warnings.push(`${where}: work ${k} is not in objects (must already exist on the server)`);
    else if ((o.assignments ?? []).some((a) => !a._skip)) errors.push(`${where}: work ${k} has assignments of its own; import the reviewer on the essay`);
    if (inEssay.has(k)) errors.push(`${where}: work ${k} is also in ${inEssay.get(k)}`);
    inEssay.set(k, where);
  }
  if (new Set(keys).size !== keys.length) errors.push(`${where}: a work is listed twice`);
  if (e.bidDeadline && !DATE.test(e.bidDeadline)) errors.push(`${where}: bidDeadline must be Y-m-d`);

  const active = (e.assignments ?? []).filter((a) => !a._skip);
  const count = (st) => active.filter((a) => st.includes(a.status)).length;
  if (count(['accepted', 'submitted', 'completed']) > 1) errors.push(`${where}: only one accepted/submitted/completed reviewer per essay`);
  if (count(['offered']) > 1) errors.push(`${where}: only one offered contract per essay`);
  for (const [j, a] of active.entries()) {
    const w = `${where} assignments[${j}] ${a.email ?? a.userId ?? ''}`;
    if (a.copyPreference || a.copyStatus || a.copyStatusDate) errors.push(`${w}: an essay's reviewer has copies (one per work) instead of copyPreference/copyStatus`);
    if (a.copySentBy || a.returnTarget) warnings.push(`${w}: copySentBy/returnTarget are not imported for essays`);
    const seen = new Set();
    for (const c of a.copies ?? []) {
      const k = c.objectId !== undefined ? `#${c.objectId}` : workKey(c.work);
      if (c.objectId === undefined && !keys.includes(k)) errors.push(`${w}: copies name ${k}, which is not one of the essay's works`);
      if (seen.has(k)) errors.push(`${w}: copies name ${k} twice`);
      seen.add(k);
      checkCopy(c, `${w} copy ${k}`, a);
    }
    checkAssignment(a, w, e.assignmentMode === 'bidding');
  }
}

for (const e of errors) console.error(`ERROR   ${e}`);
for (const w of warnings) console.error(`warning ${w}`);
const result = strip(doc);
for (const u of result.users ?? []) {
  delete u.person;
  u.email = realEmail(u.email);
}
for (const o of result.objects ?? []) {
  for (const k of ['publisherContactEmail', 'responsibleEditorEmail']) if (o[k]) o[k] = realEmail(o[k]);
  for (const a of o.assignments ?? []) a.email = realEmail(a.email);
}
for (const e of result.essays ?? []) {
  if (e.responsibleEditorEmail) e.responsibleEditorEmail = realEmail(e.responsibleEditorEmail);
  for (const a of e.assignments ?? []) a.email = realEmail(a.email);
}
const nObjects = result.objects?.length ?? 0;
const nAssignments = [...(result.objects ?? []), ...(result.essays ?? [])].reduce((n, o) => n + (o.assignments?.length ?? 0), 0);
console.error(`${result.users?.length ?? 0} users, ${nObjects} works, ${result.essays?.length ?? 0} essays, ${nAssignments} assignments; ${errors.length} errors, ${warnings.length} warnings`);
if (errors.length) process.exit(1);
writeFileSync(out, JSON.stringify(result, null, 2));
console.error(`-> ${out} (contains real names and addresses: don't print it in an AI session)`);
