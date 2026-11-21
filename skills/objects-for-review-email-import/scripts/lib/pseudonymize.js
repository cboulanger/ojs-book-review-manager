// Local pseudonymization: every person (email address, display names) gets a
// stable token like P0012. Everything shown to an AI assistant or written to the
// shareable index goes through mask(); the mapping stays in a private file that
// only the scripts read.

import { findIsbns } from './identifiers.js';

export const TOKEN = /^P\d{4,}$/;
const EMAIL = /[A-Za-z0-9._%+'-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/g;
const PHONE = /(?:\+\d{1,3}[\s.\/-]?|\b0)\(?\d{1,5}\)?(?:[\s.\/-]?\d){5,}/g;
const ADDRESS_LINE = /^.*(\b\d{4,5}\s+\p{Lu}\p{Ll}+|\p{L}+(stra(ss|ß)e|str\.|weg|allee|platz|gasse|ring|damm)\s+\d+|\bstreet\b|\bP\.?O\.? Box\b|Postfach).*$/gimu;
const escape = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

/** "Boulanger, Christian" / "Christian Boulanger" -> { givenName, familyName } */
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
  constructor(people = [], literals = []) {
    this.people = people;
    this.literals = literals;
    this.byEmail = new Map();
    for (const p of people) for (const e of p.emails) this.byEmail.set(e.toLowerCase(), p);
    this.nameRegex = null;
    this.literalRegex = null;
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
  person(email, name) {
    const key = email.toLowerCase();
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
    return this.people.find((p) => p.id === id);
  }

  domain(id) {
    return this.get(id)?.emails[0].split('@')[1];
  }

  #names() {
    if (this.nameRegex) return this.nameRegex;
    const variants = new Map();
    for (const p of this.people) {
      for (const n of p.names) {
        const { givenName, familyName } = splitName(n);
        for (const v of [n, givenName && familyName && `${givenName} ${familyName}`, familyName, givenName]) {
          // single words: at least 3 letters; whole names always
          if (v && (v.includes(' ') || v.length >= 3) && !variants.has(v.toLowerCase())) variants.set(v.toLowerCase(), p.id);
        }
      }
    }
    const list = [...variants.keys()].sort((a, b) => b.length - a.length);
    this.nameMap = variants;
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
    s = s.replace(PHONE, (m) => (findIsbns(m).length ? m : '[phone]'));
    return s.replace(ADDRESS_LINE, '[address line]');
  }
}
