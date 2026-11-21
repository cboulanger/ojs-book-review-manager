#!/usr/bin/env node
// Structured extraction of review cases by a language model (OpenAI-compatible chat API).
//
// The orchestrating agent decides what a case is and merges the results; the model only reads the
// masked mails of one case at a time and returns JSON. The orchestrator never sees mail text,
// only the validated, leak-checked result.
//
//   extract.js check --model NAME          (synthetic request: endpoint, key and JSON answers work)
//   extract.js list <work dir> <case spec>... [--exclude TEXT]
//   extract.js run  <work dir> <case spec>... --model NAME [--editor P0001,P0008] [--exclude TEXT]
//                   [--out DIR] [--max-message CHARS] [--max-tokens N] [--force]
//
// Case specs:
//   folder:<folder end>     all messages filed in that folder (one case)
//   thread:t0145[+t0160]    these threads (one case)
//   each-folder:<stage>     each subfolder of <stage> is a case; threads filed directly in <stage> are cases too
//   each-thread:<folder>    each thread with a message in <folder> (or its subfolders) is a case
//
// Endpoint: OFR_LLM_BASE_URL (default https://chat-ai.academiccloud.de/v1) and OFR_LLM_KEY_FILE (a file
// holding the API key; it is never printed). Results: <work>/extract/<model>/<case>.json, one line per
// case on stdout (tokens only).

import { readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs';
import { join } from 'node:path';
import { parseArgs, loadWork, maskedText, day } from './lib/work.js';
import { isbn10Valid, isbn13Valid } from './lib/identifiers.js';

const TODAY = new Date().toISOString().slice(0, 10);
const STATUSES = ['proposed', 'invited', 'bid', 'offered', 'accepted', 'submitted', 'published', 'declined', 'withdrawn', 'unknown'];
const COPY_PREFS = ['have', 'physical', 'electronic', 'other', 'unknown'];
const COPY_STATUSES = ['notNeeded', 'pending', 'requested', 'sent', 'received', 'returnRequested', 'returnSent', 'returned', 'unknown'];

const SCHEMA = `{
  "kind": "single" | "essay" | "proposal" | "none",      // essay = one review of several works; proposal = works offered, no reviewer yet; none = not about a review
  "works": [ { "title": "...", "authors": "Family, Given; ...", "publisher": "...", "year": "2024",
               "isbn": ["978..."], "doi": ["10..."], "url": "...", "evidence": ["m0123"] } ],
  "publisherContacts": [ { "person": "P0042", "publisher": "...", "evidence": ["m0123"] } ],
  "review": null | {
    "reviewer": "P0044",                                  // the CURRENT reviewer (state at the end of the case)
    "status": ${STATUSES.map((s) => `"${s}"`).join(' | ')},
    "agreedOn": "YYYY-MM-DD" | null,                      // when the current reviewer agreed
    "dueDate": "YYYY-MM-DD" | null,                       // the deadline currently agreed with the current reviewer
    "dueDateBasis": "short quote or reasoning",
    "lastContact": "YYYY-MM-DD",
    "copies": [ { "work": 0, "preference": ${COPY_PREFS.map((s) => `"${s}"`).join(' | ')},
                  "status": ${COPY_STATUSES.map((s) => `"${s}"`).join(' | ')},
                  "sentBy": "journal" | "publisher" | null, "date": "YYYY-MM-DD" | null, "evidence": ["m0123"] } ],
    "evidence": ["m0123"]
  },
  "formerReviewers": [ { "person": "P0039", "outcome": "declined | gave up | returned the book | not chosen | ...", "evidence": ["m0123"] } ],
  "questions": ["what the editor must decide or confirm"],
  "confidence": "high" | "medium" | "low"
}`;

const SYSTEM = `You extract facts from the email correspondence of a journal's book review editor.
People are pseudonymized: tokens like P0044 stand for persons ([P0044] in text, <P0044> for an address);
[address] and [phone] are masked. Use tokens exactly as given (bare "P0044", never "P0044@domain").
Answer with ONE JSON object that follows the schema; no other text.`;

function instructions(editors) {
  return `Today is ${TODAY}. The journal's editor writes as ${editors.join(', ')}; the editor is never the reviewer unless the mails say
the editor reviews the book himself.

Rules:
- Use only what the mails say. Unknown values are null; add a question instead of guessing.
- The CURRENT state counts: reviewers who declined, gave up, returned the book or whose offer was not accepted go into
  formerReviewers, not into review. Read the case to its end.
- works: only the books (or other works) that are offered for review or being reviewed in this case. NOT works cited in
  signatures, publication lists, newsletters with many titles, or references. Give an ISBN only if the mails give it for
  that very book (an ISBN in a URL counts); don't invent ISBNs. copies[].work is the index in works.
- kind "essay": one reviewer reviews several works in ONE text (Sammelrezension, Doppelrezension, review essay).
- publisherContacts: people at the publisher who handled review copies or offers for these works.
- dueDate: the deadline currently agreed; if the mails only say e.g. "late summer" or "next issue", give your best date
  and explain it in dueDateBasis.
- Every evidence list holds the message ids (m0123) the value is based on.
- Never repeat personal circumstances (health, family, private life, moves) in any field: say neutrally
  "reviewer was unavailable" or "delayed". No quotes from the mails except dates and deadlines.

Schema:
${SCHEMA}`;
}

// --- cases -------------------------------------------------------------------------------------

const lower = (s) => String(s).toLowerCase();
const stageOf = (folder, stage) => {
  const parts = folder.split('/');
  const i = parts.findIndex((p) => lower(p) === lower(stage));
  return i < 0 ? null : parts.slice(i + 1);
};

function buildCases(index, specs, exclude) {
  const excluded = (m) => exclude.some((x) => m.folders.some((f) => lower(f).includes(lower(x))));
  const msgs = index.messages.filter((m) => !excluded(m));
  const byThread = (ids) => msgs.filter((m) => ids.includes(m.thread));
  const cases = [];
  for (const spec of specs) {
    const [type, ...rest] = spec.split(':');
    const arg = rest.join(':');
    if (type === 'folder') {
      cases.push({ id: arg, messages: msgs.filter((m) => m.folders.some((f) => lower(f).endsWith(lower(arg)))) });
    } else if (type === 'thread') {
      const ids = arg.split('+');
      cases.push({ id: ids.join('+'), messages: byThread(ids) });
    } else if (type === 'each-folder') {
      const groups = new Map();
      for (const m of msgs) {
        for (const f of m.folders) {
          const below = stageOf(f, arg.split('/').pop());
          if (!below || !lower(f).includes(lower(arg))) continue;
          const key = below.length ? `${arg}/${below[0]}` : `${arg} ${m.thread}`;
          if (!groups.has(key)) groups.set(key, new Set());
          groups.get(key).add(m);
        }
      }
      for (const [id, set] of groups) cases.push({ id, messages: [...set] });
    } else if (type === 'each-thread') {
      const threads = [...new Set(msgs.filter((m) => m.folders.some((f) => lower(f).includes(lower(arg)))).map((m) => m.thread))];
      for (const t of threads) cases.push({ id: t, messages: byThread([t]) });
    } else {
      throw new Error(`unknown case spec ${spec} (folder:, thread:, each-folder:, each-thread:)`);
    }
  }
  for (const c of cases) c.messages.sort((a, b) => (a.date ?? '').localeCompare(b.date ?? ''));
  return cases.filter((c) => c.messages.length);
}

function caseText(work, c, maxMessage) {
  const lines = [`Case: ${c.id}`, `Messages, oldest first (${c.messages.length}):`, ''];
  for (const m of c.messages) {
    let text = maskedText(work, m, false);
    if (text.length > maxMessage) text = text.slice(0, maxMessage) + ` […${text.length - maxMessage} more characters]`;
    lines.push(`--- ${m.id} | ${day(m.date)} | from ${m.from.join(', ')} | to ${m.to.join(', ')}${m.cc.length ? ` | cc ${m.cc.join(', ')}` : ''}`);
    lines.push(`Subject: ${m.subject}${m.attachments.length ? ` | attachments: ${m.attachments.join(', ')}` : ''}`);
    lines.push(text, '');
  }
  return lines.join('\n');
}

// --- model call ----------------------------------------------------------------------------------

let lastCall = 0;
async function chat(model, messages, maxTokens = 8192) {
  const base = process.env.OFR_LLM_BASE_URL || 'https://chat-ai.academiccloud.de/v1';
  const keyFile = process.env.OFR_LLM_KEY_FILE;
  if (!keyFile) throw new Error('set OFR_LLM_KEY_FILE to a file holding the API key');
  const key = readFileSync(keyFile, 'utf8').trim();
  for (let attempt = 0; attempt < 6; attempt++) {
    const wait = 1200 - (Date.now() - lastCall);
    if (wait > 0) await new Promise((r) => setTimeout(r, wait));
    lastCall = Date.now();
    const body = { model, messages, temperature: 0, max_tokens: maxTokens, response_format: { type: 'json_object' } };
    let res;
    try {
      res = await fetch(`${base}/chat/completions`, {
        method: 'POST',
        headers: { Authorization: `Bearer ${key}`, 'Content-Type': 'application/json', 'User-Agent': 'ofr-extract/1.0' },
        body: JSON.stringify(body),
        signal: AbortSignal.timeout(600000),
      });
    } catch (error) {
      await new Promise((r) => setTimeout(r, 10000 * (attempt + 1)));
      continue;
    }
    if (res.status === 429 || res.status >= 500) {
      const reset = Number(res.headers.get('x-ratelimit-reset-requests-10s')) || 10 * (attempt + 1);
      await new Promise((r) => setTimeout(r, (reset + 1) * 1000));
      continue;
    }
    const data = await res.json().catch(() => null);
    if (!res.ok) throw new Error(`HTTP ${res.status}: ${JSON.stringify(data)?.slice(0, 300)}`);
    return data;
  }
  throw new Error('no answer after 6 attempts');
}

function parseJson(text) {
  const s = String(text ?? '').replace(/^```(?:json)?\s*|\s*```$/g, '');
  try {
    return JSON.parse(s);
  } catch {
    const a = s.indexOf('{');
    const b = s.lastIndexOf('}');
    return a >= 0 && b > a ? JSON.parse(s.slice(a, b + 1)) : null;
  }
}

// --- checks ------------------------------------------------------------------------------------

/** Values from private.json: if one appears in the result, it is removed (never printed). */
function privateNeedles(priv) {
  const needles = [];
  for (const p of priv.people) {
    for (const e of p.emails ?? []) needles.push(e.toLowerCase());
    for (const n of p.names ?? []) if (n.includes(' ') && n.length >= 6) needles.push(n.toLowerCase());
    for (const k of ['mailingAddress', 'phone']) for (const l of String(p[k] ?? '').split('\n')) if (l.trim().length >= 8) needles.push(l.trim().toLowerCase());
  }
  return needles;
}

function scrub(value, needles, path = '', removed = []) {
  if (Array.isArray(value)) return value.map((v, i) => scrub(v, needles, `${path}[${i}]`, removed));
  if (value && typeof value === 'object') {
    return Object.fromEntries(Object.entries(value).map(([k, v]) =>
      // bibliographic fields are public data
      [k, ['title', 'authors', 'publisher'].includes(k) ? v : scrub(v, needles, `${path}.${k}`, removed)]));
  }
  if (typeof value === 'string') {
    const low = value.toLowerCase();
    if (needles.some((n) => low.includes(n)) || /[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/.test(value.replace(/P\d{4,}@[A-Za-z0-9.-]+/g, ''))) {
      removed.push(path);
      return '[removed: contained private data]';
    }
  }
  return value;
}

function validate(r, c, people) {
  const issues = [];
  const ids = new Set(c.messages.map((m) => m.id));
  const token = (t, where) => {
    if (t == null) return;
    if (!/^P\d{4,}$/.test(t)) issues.push(`${where}: "${String(t).slice(0, 30)}" is not a bare token`);
    else if (!people.has(t)) issues.push(`${where}: unknown person ${t}`);
  };
  const date = (d, where) => d != null && !/^\d{4}-\d{2}-\d{2}$/.test(d) && issues.push(`${where}: date "${d}" is not YYYY-MM-DD`);
  const evidence = (ev, where) => (ev ?? []).forEach((m) => !ids.has(m) && issues.push(`${where}: evidence ${m} is not in this case`));
  if (!['single', 'essay', 'proposal', 'none'].includes(r.kind)) issues.push(`kind "${r.kind}" unknown`);
  (r.works ?? []).forEach((w, i) => {
    for (const isbn of w.isbn ?? []) {
      const d = String(isbn).replace(/[^\dX]/gi, '').toUpperCase();
      if (!(d.length === 13 ? isbn13Valid(d) : d.length === 10 && isbn10Valid(d))) issues.push(`works[${i}]: ISBN ${isbn} invalid`);
    }
    evidence(w.evidence, `works[${i}]`);
  });
  (r.publisherContacts ?? []).forEach((p, i) => { token(p.person, `publisherContacts[${i}]`); evidence(p.evidence, `publisherContacts[${i}]`); });
  (r.formerReviewers ?? []).forEach((p, i) => { token(p.person, `formerReviewers[${i}]`); evidence(p.evidence, `formerReviewers[${i}]`); });
  if (r.review) {
    const v = r.review;
    token(v.reviewer, 'review.reviewer');
    if (!STATUSES.includes(v.status)) issues.push(`review.status "${v.status}" unknown`);
    ['agreedOn', 'dueDate', 'lastContact'].forEach((k) => date(v[k], `review.${k}`));
    evidence(v.evidence, 'review');
    (v.copies ?? []).forEach((cp, i) => {
      if (!COPY_PREFS.includes(cp.preference)) issues.push(`review.copies[${i}].preference "${cp.preference}" unknown`);
      if (!COPY_STATUSES.includes(cp.status)) issues.push(`review.copies[${i}].status "${cp.status}" unknown`);
      if (typeof cp.work !== 'number' || !(r.works ?? [])[cp.work]) issues.push(`review.copies[${i}].work ${cp.work} is not an index in works`);
      date(cp.date, `review.copies[${i}].date`);
      evidence(cp.evidence, `review.copies[${i}]`);
    });
  }
  if (r.kind === 'essay' && (r.works ?? []).length < 2) issues.push('kind essay with fewer than 2 works');
  return issues;
}

// --- main --------------------------------------------------------------------------------------

const { pos, opt } = parseArgs(process.argv.slice(2));
const [command, dir, ...specs] = pos;

if (command === 'check') {
  // synthetic data only: tells whether endpoint, key, model and JSON answers work; prints no key
  if (!opt.model || opt.model === true) throw new Error('--model is required');
  const started = Date.now();
  const data = await chat(String(opt.model), [{ role: 'system', content: SYSTEM },
    { role: 'user', content: 'Return {"kind": "proposal", "works": [{"title": "Test", "isbn": ["9783161484100"]}]} as your JSON answer.' }]);
  const result = parseJson(data.choices?.[0]?.message?.content);
  console.log(`${opt.model}: ${result?.works?.[0]?.isbn?.[0] === '9783161484100' ? 'ok, JSON answer' : 'answer is not the expected JSON'} ` +
    `(${Math.round((Date.now() - started) / 1000)} s)`);
  process.exit(result ? 0 : 1);
}
const exclude = [].concat(opt.exclude ?? []).filter((x) => x !== true);
if (!['list', 'run'].includes(command) || !dir || !specs.length) {
  console.error(readFileSync(new URL(import.meta.url), 'utf8').split('\n').slice(1, 23).join('\n').replace(/^\/\/ ?/gm, ''));
  process.exit(2);
}
const work = loadWork(dir);
if (!work.index.ner) throw new Error('the index was built without --ner: mail bodies may not be sent to a model');
const cases = buildCases(work.index, specs, exclude);

if (command === 'list') {
  for (const c of cases) console.log(`${c.id}\t${c.messages.length} messages\t${day(c.messages[0].date)}..${day(c.messages.at(-1).date)}`);
  console.error(`${cases.length} cases`);
  process.exit(0);
}

if (!opt.model || opt.model === true) throw new Error('--model is required');
const editors = opt.editor && opt.editor !== true ? String(opt.editor).split(',') : work.index.me;
const outDir = opt.out && opt.out !== true ? opt.out : join(dir, 'extract', String(opt.model).replace(/[^\w.-]/g, '_'));
mkdirSync(outDir, { recursive: true });
const people = new Set(work.priv.people.map((p) => p.id));
const needles = privateNeedles(work.priv);
const maxMessage = Number(opt['max-message'] ?? 3000);
const totals = { cases: 0, requests: 0, prompt_tokens: 0, completion_tokens: 0, failed: 0 };

for (const c of cases) {
  const file = join(outDir, `${c.id.replace(/[^\w.+-]+/g, '_')}.json`);
  // done = a result without error; failed cases are tried again
  if (existsSync(file) && !opt.force && !JSON.parse(readFileSync(file, 'utf8')).error) {
    console.log(`skip ${c.id} (done; --force to redo)`);
    continue;
  }
  totals.cases++;
  const text = caseText(work, c, maxMessage);
  const record = { case: c.id, model: opt.model, messages: c.messages.map((m) => m.id), promptChars: text.length };
  try {
    const messages = [{ role: 'system', content: SYSTEM }, { role: 'user', content: `${instructions(editors)}\n\n${text}` }];
    const maxTokens = Number(opt['max-tokens'] ?? 8192); // reasoning models need more
    let data = await chat(opt.model, messages, maxTokens);
    totals.requests++;
    let result = parseJson(data.choices?.[0]?.message?.content);
    if (!result) { // one retry with a reminder
      messages.push({ role: 'assistant', content: String(data.choices?.[0]?.message?.content ?? '').slice(0, 2000) },
        { role: 'user', content: 'That was not a valid JSON object. Answer with the JSON object only.' });
      data = await chat(opt.model, messages, maxTokens);
      totals.requests++;
      result = parseJson(data.choices?.[0]?.message?.content);
    }
    for (const k of ['prompt_tokens', 'completion_tokens']) totals[k] += data.usage?.[k] ?? 0;
    if (!result) throw new Error('no JSON in the answer');
    const removed = [];
    record.result = scrub(result, needles, '$', removed);
    record.removed = removed;
    record.issues = validate(record.result, c, people);
    record.usage = data.usage;
  } catch (error) {
    record.error = String(error.message ?? error).slice(0, 300);
    totals.failed++;
  }
  writeFileSync(file, JSON.stringify(record, null, 1));
  const r = record.result;
  console.log(record.error
    ? `FAIL ${c.id}: ${record.error}`
    : `ok   ${c.id}: ${r.kind}, ${r.works?.length ?? 0} works, reviewer ${r.review?.reviewer ?? '-'} ${r.review?.status ?? ''}, ` +
      `${record.issues.length} issues${record.removed.length ? `, ${record.removed.length} values REMOVED (private data)` : ''}`);
}
console.error(`${totals.cases} cases, ${totals.requests} requests, ${totals.prompt_tokens} prompt + ${totals.completion_tokens} completion tokens, ${totals.failed} failed -> ${outDir}`);
