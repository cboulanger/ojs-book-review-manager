// Local pseudonymization: every person (email address, display names) gets a
// stable token like P0012. Everything shown to an AI assistant or written to the
// shareable index goes through mask(); the mapping stays in a private file that
// only the scripts read.

import { findIsbns } from './identifiers.js';

export const TOKEN = /^P\d{4,}$/;
const EMAIL = /[A-Za-z0-9._%+'-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/g;
const PHONE = /(?:\+\d{1,3}[\s.\/-]?|\b0)\(?\d{1,5}\)?(?:[\s.\/-]?\d){5,}/g;
const ADDRESS_LINE = new RegExp([
  String.raw`\b\d{4,5}[ \t]+\p{Lu}\p{Ll}+`, // German/Austrian/Swiss postcode + town
  String.raw`\p{L}+(stra(ss|ß)e|str\.|weg|allee|platz|gasse|ring|damm)[ \t]+\d+`,
  String.raw`\b\d+[ \t]+\p{Lu}\p{L}+[ \t]+(Road|Street|Avenue|Lane|Drive|Boulevard|Place|Square|Way|Court|Rd\.?|St\.?|Ave\.?)\b`,
  String.raw`\b(Road|Street|Avenue|Lane|Building|Centre|Center|House|Hall)\b.*`, // English address parts, e.g. "Manor Road Building"
  String.raw`\b[A-Z]{1,2}\d[A-Z\d]?[ \t]*\d[A-Z]{2}\b`, // UK postcode (OX1 3UQ)
  String.raw`\b[A-Z]{2}[ \t]+\d{5}(-\d{4})?\b`, // US state + ZIP
  String.raw`\bstreet\b`, String.raw`\bP\.?O\.? Box\b`, 'Postfach',
].map((p) => `^.*(${p}).*$`).join('|'), 'gimu');
/**
 * Single words that are never masked on their own: function words and the vocabulary of book
 * reviews. NER sometimes takes a title ("The Law of ...") for a name, and its parts would
 * otherwise be masked everywhere. Full names are always masked. Residual risk: a person whose
 * surname is one of these words, named by surname alone.
 */
export const COMMON_WORDS = new Set(`the and of for in on at to with from by an der die das des dem den und oder für fuer von vom zu zur zum
mit im am an auf aus bei ein eine einer eines law laws legal recht rechts rechte review reviews reviewer book books buch bücher
rezension rezensionen rezensent rezensenten rezensentin rezensentinnen rezensionsexemplar handbook handbuch journal zeitschrift
verlag press copy new neue neuer neues political politics economy society gesellschaft soziologie sociology theory theorie
theorien publikation publication edition hrsg ed eds editor herausgeber series reihe studies studien research forschung
university universität institut institute justice justiz kritische critical
grüße gruß grüßen beste besten bester herzliche herzlichen herzlich viele vielen liebe lieber lieben dank danke
nachricht nachrichten mail e-mail email best regards dear thanks thank kind wishes sincerely`.split(/\s+/));

/** After these, a common word is still masked as a name ("Lieber Herr Wolf"). */
const HONORIFIC = '(?:Herrn?|Frau|Dr\\.|Prof\\.|Professor(?:in)?|Liebe[rs]?|Dear|Mr\\.?|Mrs\\.?|Ms\\.?)';

/**
 * Words that occur in lowercase at least `min` times in the corpus are common words, not names
 * ("ich", "es", "dank"). Computed locally from the mail texts.
 */
export function lowercaseWords(texts, min = 3) {
  const counts = new Map();
  for (const text of texts) {
    // addresses and URLs are lowercase but full of names: they don't count
    const plain = String(text).replace(EMAIL, ' ').replace(/(https?:\/\/|www\.)\S+/gi, ' ').replace(/\S+\.(de|com|org|net|edu|eu|ch|at|uk|nl)\b\S*/gi, ' ');
    for (const m of plain.matchAll(/(?<![\p{L}\p{N}@.])\p{Ll}[\p{L}-]*(?![\p{L}\p{N}@])/gu)) counts.set(m[0], (counts.get(m[0]) ?? 0) + 1);
  }
  return [...counts].filter(([, n]) => n >= min).map(([w]) => w);
}

const escape = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

/** "Boulanger, Christian" / "Christian Boulanger" -> { givenName, familyName } */
/** "Mailto:A.B@Uni.DE." -> "a.b@uni.de": the same address written differently is one person. */
export function normalizeEmail(email) {
  return String(email ?? '').trim().replace(/^mailto:/i, '').replace(/^[<("']+|[>)"'.,;:]+$/g, '').toLowerCase();
}

export function splitName(name) {
  const n = (name || '').replace(/\s*\(.*?\)\s*/g, ' ').replace(/^"|"$/g, '').trim();
  if (!n || n.includes('@')) return {};
  if (n.includes(',')) {
    const [family, given] = n.split(',', 2).map((s) => s.trim());
    return { givenName: given || undefined, familyName: family || undefined };
  }
  const parts = n.split(/\s+/);
  if (parts.length === 1) return { familyName: parts[0] };
  const particle = parts.findIndex((p, i) => i > 0 && /^(von|van|de|der|du|zu|dos|da)$/i.test(p));
  const cut = particle > 0 ? particle : parts.length - 1;
  return { givenName: parts.slice(0, cut).join(' '), familyName: parts.slice(cut).join(' ') };
}

export class Pseudonymizer {
  /**
   * @param people   [{ id, emails, names, givenName?, familyName?, ... }]; people found by NER have no emails
   * @param literals [{ text, kind }]: addresses and phone numbers found by NER, masked as [kind]
   */
  constructor(people = [], literals = [], { commonWords = [] } = {}) {
    this.people = people;
    this.literals = literals;
    this.common = new Set([...COMMON_WORDS, ...commonWords.map((w) => w.toLowerCase())]);
    this.byEmail = new Map();
    // People whose addresses only differ in spelling (case, a trailing period, "mailto:") are
    // merged: the later token becomes an alias (aliasOf), so tokens already in use stay valid.
    for (const p of people) {
      if (p.aliasOf) continue;
      p.emails = [...new Set(p.emails.map(normalizeEmail))];
      const twin = p.emails.map((e) => this.byEmail.get(e)).find(Boolean);
      if (twin && twin !== p) {
        this.#merge(p, twin);
        continue;
      }
      for (const e of p.emails) this.byEmail.set(e, p);
    }
    this.nameRegex = null;
    this.literalRegex = null;
  }

  /** Corpus-derived common words (see lowercaseWords); rebuilds the name patterns. */
  setCommonWords(words) {
    this.common = new Set([...COMMON_WORDS, ...words.map((w) => w.toLowerCase())]);
    this.nameRegex = null;
  }

  /** A name found in a text (no address known): the token of a person with that name, or a new one. */
  addName(name, source = 'ner') {
    this.#names();
    const known = this.nameMap?.get(name.toLowerCase().replace(/\s+/g, ' '));
    if (known) return known;
    const p = { id: `P${String(this.people.length + 1).padStart(4, '0')}`, emails: [], names: [name], source, ...splitName(name) };
    this.people.push(p);
    this.nameRegex = null;
    return p.id;
  }

  /** A string to mask everywhere as [kind] (e.g. a street address or phone number found by NER). */
  addLiteral(text, kind) {
    const t = text.replace(/\s+/g, ' ').trim();
    if (t.length < 6 || this.literals.some((l) => l.text === t)) return false;
    this.literals.push({ text: t, kind });
    this.literalRegex = null;
    return true;
  }

  #literals() {
    if (!this.literalRegex && this.literals.length) {
      const list = [...this.literals].sort((a, b) => b.text.length - a.text.length);
      this.literalKind = new Map(list.map((l) => [l.text.toLowerCase(), l.kind]));
      this.literalRegex = new RegExp(list.map((l) => escape(l.text).replace(/ /g, '\\s+')).join('|'), 'gi');
    }
    return this.literalRegex;
  }

  /** The token for an address, created on first sight; display names are remembered for masking. */
  /** Merge person `p` into `into`; profile fields `into` lacks (e.g. added by hand) are taken over. */
  #merge(p, into) {
    for (const e of p.emails) if (!into.emails.includes(e)) into.emails.push(e);
    for (const n of p.names) if (!into.names.includes(n)) into.names.push(n);
    for (const [k, v] of Object.entries(p)) {
      if (!['id', 'emails', 'names', 'aliasOf'].includes(k) && v != null && into[k] == null) into[k] = v;
    }
    for (const k of Object.keys(p)) if (!['id', 'source'].includes(k)) delete p[k];
    p.aliasOf = into.id;
    for (const e of into.emails) this.byEmail.set(e, into);
  }

  /** The canonical record of a token (follows aliasOf). */
  resolve(id) {
    let p = this.people.find((x) => x.id === id);
    while (p?.aliasOf) p = this.people.find((x) => x.id === p.aliasOf);
    return p;
  }

  person(email, name) {
    const key = normalizeEmail(email);
    let p = this.byEmail.get(key);
    if (!p) {
      p = { id: `P${String(this.people.length + 1).padStart(4, '0')}`, emails: [key], names: [] };
      this.people.push(p);
      this.byEmail.set(key, p);
      this.nameRegex = null;
    }
    const clean = name?.replace(/^"|"$/g, '').trim();
    if (clean && !clean.includes('@') && !p.names.includes(clean)) {
      p.names.push(clean);
      const { givenName, familyName } = splitName(clean);
      if (!p.givenName && !p.familyName) Object.assign(p, { givenName, familyName });
      this.nameRegex = null;
    }
    return p.id;
  }

  get(id) {
    return this.resolve(id);
  }

  domain(id) {
    return this.get(id)?.emails[0].split('@')[1];
  }

  #names() {
    if (this.nameRegex) return this.nameRegex;
    const variants = new Map();
    const contextual = new Map(); // common single words: masked only after an honorific
    for (const p of this.people) {
      if (p.aliasOf) continue;
      for (const n of p.names) {
        const { givenName, familyName } = splitName(n);
        for (const v of [n, givenName && familyName && `${givenName} ${familyName}`, familyName, givenName]) {
          // single words: at least 3 letters; whole names always
          if (!v) continue;
          const key = v.toLowerCase();
          const single = !v.includes(' ');
          // correspondents (from headers) keep their names masked everywhere; for names found by NER,
          // words the corpus uses in lowercase are common words ("Ich" at a sentence start)
          const common = p.emails.length ? COMMON_WORDS : this.common;
          if (single && (v.length < 3 || common.has(key))) {
            if (v.length >= 3 && !contextual.has(key)) contextual.set(key, p.id);
          } else if (!variants.has(key)) variants.set(key, p.id);
        }
      }
    }
    const list = [...variants.keys()].sort((a, b) => b.length - a.length);
    this.nameMap = variants;
    this.contextMap = contextual;
    const ctx = [...contextual.keys()].sort((a, b) => b.length - a.length);
    this.contextRegex = ctx.length
      ? new RegExp(`(?<=${HONORIFIC}\\s+)(${ctx.map(escape).join('|')})(?![\\p{L}\\p{N}])`, 'giu')
      : null;
    this.nameRegex = list.length
      ? new RegExp(`(?<![\\p{L}\\p{N}])(${list.map((v) => escape(v).replace(/\s+/g, '\\s+')).join('|')})(?![\\p{L}\\p{N}])`, 'giu')
      : null;
    return this.nameRegex;
  }

  /** Replace addresses, known names, phone numbers and address lines; unknown addresses become new tokens. */
  mask(text) {
    if (!text) return text;
    let s = String(text);
    const lit = this.#literals();
    if (lit) s = s.replace(lit, (m) => `[${this.literalKind.get(m.replace(/\s+/g, ' ').toLowerCase()) ?? 'masked'}]`);
    s = s.replace(EMAIL, (e) => `<${this.person(e)}>`);
    const re = this.#names();
    if (re) s = s.replace(re, (m) => `[${this.nameMap.get(m.toLowerCase().replace(/\s+/g, ' ')) ?? 'P?'}]`);
    if (this.contextRegex) s = s.replace(this.contextRegex, (m) => `[${this.contextMap.get(m.toLowerCase()) ?? 'P?'}]`);
    s = s.replace(PHONE, (m) => (findIsbns(m).length ? m : '[phone]'));
    return s.replace(ADDRESS_LINE, '[address line]');
  }
}
