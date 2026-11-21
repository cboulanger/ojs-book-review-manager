// DOIs and ISBNs in free text. ISBNs are checksum-validated and normalized to ISBN-13;
// a bare 10-digit number only counts when "ISBN" stands right before it (phone numbers).

export function isbn10Valid(d) {
  if (!/^\d{9}[\dX]$/.test(d)) return false;
  const sum = [...d].reduce((s, c, i) => s + (c === 'X' ? 10 : Number(c)) * (10 - i), 0);
  return sum % 11 === 0;
}

export function isbn13Valid(d) {
  if (!/^\d{13}$/.test(d)) return false;
  const sum = [...d].reduce((s, c, i) => s + Number(c) * (i % 2 ? 3 : 1), 0);
  return sum % 10 === 0;
}

export function isbn10to13(d) {
  const core = '978' + d.slice(0, 9);
  const sum = [...core].reduce((s, c, i) => s + Number(c) * (i % 2 ? 3 : 1), 0);
  return core + ((10 - (sum % 10)) % 10);
}

export function findIsbns(text) {
  const found = new Set();
  const re = /(?<![\dX])(?:\d[-‐‑–\s]?){9,12}[\dXx](?!\d)/g;
  let m;
  while ((m = re.exec(text))) {
    const digits = m[0].replace(/[^\dXx]/g, '').toUpperCase();
    const labeled = /ISBN(?:-1[03])?\s*:?\s*$/i.test(text.slice(Math.max(0, m.index - 12), m.index));
    if (digits.length === 13 && /^97[89]/.test(digits) && isbn13Valid(digits)) found.add(digits);
    else if (digits.length === 10 && labeled && isbn10Valid(digits)) found.add(isbn10to13(digits));
  }
  return [...found];
}

export function findDois(text) {
  const found = new Set();
  for (const m of text.matchAll(/\b10\.\d{4,9}\/[^\s"'<>]+/g)) {
    found.add(m[0].replace(/[.,;:)\]»”’]+$/, '').toLowerCase());
  }
  return [...found];
}
