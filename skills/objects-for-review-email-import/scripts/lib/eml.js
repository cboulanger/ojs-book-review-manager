// Minimal, dependency-free parser for .eml files (RFC 5322 / MIME).
// Good enough for reading correspondence: headers (RFC 2047 encoded words),
// multipart bodies, base64 / quoted-printable, charsets, HTML -> text,
// attachment names (RFC 2231). Raw bytes are handled as latin1 strings so
// that no byte is lost before the declared charset is applied.

import { readFileSync } from 'node:fs';

const utf8Strict = new TextDecoder('utf-8', { fatal: true });

/** Decode bytes with a declared charset; without one (or for us-ascii), try UTF-8, then windows-1252. */
export function decodeBytes(bytes, charset) {
  const buf = Buffer.isBuffer(bytes) ? bytes : Buffer.from(bytes);
  let cs = (charset || '').toLowerCase().trim().replace(/^"|"$/g, '');
  if (!cs || cs === 'us-ascii' || cs === 'ascii' || cs === 'unknown-8bit' || cs === 'x-unknown') {
    try {
      return utf8Strict.decode(buf);
    } catch {
      cs = 'windows-1252';
    }
  }
  try {
    return new TextDecoder(cs).decode(buf);
  } catch {
    return buf.toString('latin1');
  }
}

/** Quoted-printable (latin1 string in, bytes out). */
export function qpBytes(s) {
  s = s.replace(/=\r?\n/g, '');
  const out = [];
  for (let i = 0; i < s.length; i++) {
    const hex = s.substr(i + 1, 2);
    if (s[i] === '=' && /^[0-9A-Fa-f]{2}$/.test(hex)) {
      out.push(parseInt(hex, 16));
      i += 2;
    } else {
      out.push(s.charCodeAt(i) & 0xff);
    }
  }
  return Buffer.from(out);
}

const WORD = /=\?([^?\s]+)\?([BbQq])\?([^?\s]*)\?=/g;

/** RFC 2047 encoded words; adjacent words of one charset are joined before decoding (split multibyte chars). */
export function decodeWords(value) {
  value = value.replace(/(=\?[^?\s]+\?[BbQq]\?[^?\s]*\?=)\s+(?==\?[^?\s]+\?[BbQq]\?)/g, '$1');
  let out = '';
  let last = 0;
  let pending = null;
  const flush = () => {
    if (pending) out += decodeBytes(Buffer.concat(pending.chunks), pending.charset);
    pending = null;
  };
  WORD.lastIndex = 0;
  let m;
  while ((m = WORD.exec(value))) {
    if (m.index > last) {
      flush();
      out += value.slice(last, m.index);
    }
    const charset = m[1].split('*')[0].toLowerCase();
    const bytes = m[2].toUpperCase() === 'B' ? Buffer.from(m[3], 'base64') : qpBytes(m[3].replace(/_/g, ' '));
    if (pending && pending.charset === charset) pending.chunks.push(bytes);
    else {
      flush();
      pending = { charset, chunks: [bytes] };
    }
    last = WORD.lastIndex;
  }
  flush();
  return out + value.slice(last);
}

/** A raw header value (latin1 string) as text: raw 8-bit UTF-8 is accepted, then encoded words are decoded. */
export function headerText(raw) {
  let v = raw ?? '';
  if (/[\x80-\xff]/.test(v)) {
    try {
      v = utf8Strict.decode(Buffer.from(v, 'latin1'));
    } catch {
      /* keep latin1 */
    }
  }
  return decodeWords(v).replace(/\s+/g, ' ').trim();
}

function parseHeaders(head) {
  const headers = {};
  for (const line of head.replace(/\r?\n[ \t]+/g, ' ').split(/\r?\n/)) {
    const i = line.indexOf(':');
    if (i <= 0) continue;
    const name = line.slice(0, i).trim().toLowerCase();
    (headers[name] ??= []).push(line.slice(i + 1).trim());
  }
  return headers;
}

/** "type/sub; a=b; name*=utf-8''x" -> { value, params } (RFC 2231 continuations and charsets). */
export function parseStructured(v) {
  const parts = [];
  let cur = '';
  let quoted = false;
  for (const ch of v ?? '') {
    if (ch === '"') quoted = !quoted;
    if (ch === ';' && !quoted) {
      parts.push(cur);
      cur = '';
    } else cur += ch;
  }
  parts.push(cur);
  const value = parts.shift().trim().toLowerCase();
  const params = {};
  const continued = {};
  for (const p of parts) {
    const i = p.indexOf('=');
    if (i < 0) continue;
    const key = p.slice(0, i).trim().toLowerCase();
    let val = p.slice(i + 1).trim();
    if (val.startsWith('"')) val = val.slice(1, val.endsWith('"') ? -1 : undefined).replace(/\\(.)/g, '$1');
    const m = key.match(/^([^*]+)(?:\*(\d+))?(\*)?$/);
    if (!m) continue;
    const [, name, idx, ext] = m;
    if (idx === undefined && !ext) params[name] = headerText(val);
    else (continued[name] ??= []).push({ idx: Number(idx ?? 0), ext: Boolean(ext), val });
  }
  for (const [name, list] of Object.entries(continued)) {
    list.sort((a, b) => a.idx - b.idx);
    let charset = 'utf-8';
    const chunks = list.map((item, n) => {
      let val = item.val;
      if (!item.ext) return Buffer.from(val, 'latin1');
      if (n === 0) {
        const mm = val.match(/^([^']*)'[^']*'(.*)$/);
        if (mm) {
          charset = mm[1] || 'utf-8';
          val = mm[2];
        }
      }
      return Buffer.from(val.replace(/%([0-9A-Fa-f]{2})/g, (_, h) => String.fromCharCode(parseInt(h, 16))), 'latin1');
    });
    params[name] = decodeBytes(Buffer.concat(chunks), charset);
  }
  return { value, params };
}

function splitEntity(raw) {
  if (/^\r?\n/.test(raw)) return { head: '', body: raw.replace(/^\r?\n/, '') };
  const m = raw.match(/\r?\n\r?\n/);
  return m ? { head: raw.slice(0, m.index), body: raw.slice(m.index + m[0].length) } : { head: raw, body: '' };
}

function parseEntity(raw) {
  const { head, body } = splitEntity(raw);
  const headers = parseHeaders(head);
  return {
    headers,
    ct: parseStructured(headers['content-type']?.[0] || 'text/plain'),
    cte: (headers['content-transfer-encoding']?.[0] || '7bit').trim().toLowerCase(),
    cd: parseStructured(headers['content-disposition']?.[0] || ''),
    body,
  };
}

function splitMultipart(body, boundary) {
  const delim = '--' + boundary;
  const parts = [];
  let cur = null;
  for (const line of body.split(/\r?\n/)) {
    if (line.startsWith(delim)) {
      const rest = line.slice(delim.length);
      if (rest.trim() === '' || rest.startsWith('--')) {
        if (cur) parts.push(cur.join('\r\n'));
        if (rest.startsWith('--')) return parts;
        cur = [];
        continue;
      }
    }
    if (cur) cur.push(line);
  }
  if (cur) parts.push(cur.join('\r\n'));
  return parts;
}

function decodeTransfer(body, cte) {
  if (cte === 'base64') return Buffer.from(body.replace(/[^A-Za-z0-9+/=]/g, ''), 'base64');
  if (cte === 'quoted-printable') return qpBytes(body);
  return Buffer.from(body, 'latin1');
}

const ENTITIES = {
  nbsp: ' ', amp: '&', lt: '<', gt: '>', quot: '"', apos: "'", auml: 'ä', ouml: 'ö', uuml: 'ü', Auml: 'Ä',
  Ouml: 'Ö', Uuml: 'Ü', szlig: 'ß', eacute: 'é', egrave: 'è', agrave: 'à', aacute: 'á', ccedil: 'ç',
  ndash: '–', mdash: '—', hellip: '…', laquo: '«', raquo: '»', bdquo: '„', ldquo: '“', rdquo: '”',
  lsquo: '‘', rsquo: '’', sbquo: '‚', euro: '€', copy: '©', shy: '',
};

export function decodeEntities(s) {
  return s
    .replace(/&#x([0-9a-f]+);/gi, (_, h) => String.fromCodePoint(parseInt(h, 16)))
    .replace(/&#(\d+);/g, (_, d) => String.fromCodePoint(Number(d)))
    .replace(/&([a-z]+);/gi, (all, name) => ENTITIES[name] ?? all);
}

export function htmlToText(html) {
  const s = html
    .replace(/<(script|style|head|title)\b[\s\S]*?<\/\1>/gi, '')
    .replace(/<!--[\s\S]*?-->/g, '')
    .replace(/<br\s*\/?>/gi, '\n')
    .replace(/<\/(p|div|tr|li|h[1-6]|blockquote|table)>/gi, '\n')
    .replace(/<li\b[^>]*>/gi, '- ')
    .replace(/<\/t[dh]>/gi, '\t')
    .replace(/<[^>]+>/g, '');
  return decodeEntities(s)
    .replace(/\r/g, '')
    .replace(/[ \t ]+\n/g, '\n')
    .replace(/\n{3,}/g, '\n\n')
    .trim();
}

// --- minimal BER reader, only for the content of S/MIME signed-data (RFC 5652) ---
function berRead(buf, pos) {
  const tag = buf[pos++];
  let len = buf[pos++];
  if (tag === undefined || len === undefined) throw new Error('truncated BER');
  const constructed = (tag & 0x20) !== 0;
  if (len === 0x80) {
    const children = [];
    let p = pos;
    while (!(buf[p] === 0 && buf[p + 1] === 0)) {
      if (p >= buf.length) throw new Error('truncated BER');
      const child = berRead(buf, p);
      children.push(child);
      p = child.next;
    }
    return { tag, constructed, start: pos, end: p, next: p + 2, children };
  }
  if (len & 0x80) {
    const n = len & 0x7f;
    len = 0;
    for (let i = 0; i < n; i++) len = len * 256 + buf[pos++];
  }
  return { tag, constructed, start: pos, end: pos + len, next: pos + len };
}

function berChildren(buf, node) {
  if (node.children) return node.children;
  const out = [];
  for (let p = node.start; p < node.end; ) {
    const child = berRead(buf, p);
    out.push(child);
    p = child.next;
  }
  return out;
}

function berOctets(buf, node) {
  return node.constructed
    ? Buffer.concat(berChildren(buf, node).map((c) => berOctets(buf, c)))
    : buf.subarray(node.start, node.end);
}

/** ContentInfo(signedData) -> SignedData -> encapContentInfo -> [0] -> OCTET STRING; null if not signed-data. */
export function smimeSignedContent(buf) {
  try {
    const [oid, explicit] = berChildren(buf, berRead(buf, 0));
    if (buf.subarray(oid.start, oid.end).toString('hex') !== '2a864886f70d010702') return null;
    const signedData = berChildren(buf, explicit)[0];
    const encap = berChildren(buf, signedData)[2];
    const content = berChildren(buf, encap)[1];
    return content ? berOctets(buf, berChildren(buf, content)[0]) : null;
  } catch {
    return null;
  }
}

function filenameOf(entity) {
  return entity.cd.params.filename || entity.ct.params.name || null;
}

function isAttachment(entity) {
  if (entity.cd.value === 'attachment') return true;
  return Boolean(filenameOf(entity)) && !entity.ct.value.startsWith('text/');
}

function walk(entity, out, depth) {
  const { ct, cte } = entity;
  if (ct.value.startsWith('multipart/') && ct.params.boundary && depth < 20) {
    const children = splitMultipart(entity.body, ct.params.boundary).map(parseEntity);
    if (ct.value === 'multipart/alternative') {
      const pick =
        children.find((c) => c.ct.value === 'text/plain' && !isAttachment(c)) ??
        children.find((c) => c.ct.value === 'text/html') ??
        children[children.length - 1];
      if (pick) walk(pick, out, depth + 1);
      return;
    }
    for (const child of children) walk(child, out, depth + 1);
    return;
  }
  const bytes = decodeTransfer(entity.body, cte);
  if (ct.value === 'message/rfc822') {
    const inner = parseEmlBuffer(bytes, depth + 1);
    out.attachments.push({ filename: filenameOf(entity) || 'message.eml', contentType: ct.value, size: bytes.length });
    out.texts.push(
      `[Attached message] From: ${formatAddresses(inner.from)} | Date: ${inner.date ?? '?'} | Subject: ${inner.subject}\n${inner.text}`,
    );
    return;
  }
  if (/^application\/(x-)?pkcs7-mime$/.test(ct.value)) {
    // opaque S/MIME: signed content can be unwrapped, encrypted content cannot
    const inner = smimeSignedContent(bytes);
    if (inner) {
      walk(parseEntity(inner.toString('latin1')), out, depth + 1);
      return;
    }
    out.texts.push('[S/MIME-encrypted message: content not readable]');
  }
  if (isAttachment(entity) || !ct.value.startsWith('text/')) {
    out.attachments.push({ filename: filenameOf(entity), contentType: ct.value, size: bytes.length });
    return;
  }
  const text = decodeBytes(bytes, ct.params.charset);
  out.texts.push(ct.value === 'text/html' ? htmlToText(text) : text.replace(/\r/g, ''));
}

/** "A <a@x>, "B, C" <b@y>" -> [{ name, email }]; addresses without "@" (Exchange X.500) keep `raw`. */
export function parseAddresses(v) {
  if (!v) return [];
  const parts = [];
  let cur = '';
  let quoted = false;
  let angle = 0;
  for (const ch of v) {
    if (ch === '"') quoted = !quoted;
    else if (!quoted && ch === '<') angle++;
    else if (!quoted && ch === '>') angle--;
    if ((ch === ',' || ch === ';') && !quoted && angle <= 0) {
      parts.push(cur);
      cur = '';
    } else cur += ch;
  }
  parts.push(cur);
  return parts
    .map((p) => p.trim())
    .filter(Boolean)
    .map((p) => {
      const m = p.match(/^(.*)<([^>]*)>\s*$/);
      let name;
      let addr;
      if (m) {
        name = m[1].trim().replace(/^"|"$/g, '').replace(/\\(.)/g, '$1').trim();
        addr = m[2].trim();
      } else {
        const mm = p.match(/[^\s<>()",;]+@[^\s<>()",;]+/);
        addr = mm ? mm[0] : p;
        name = p.replace(addr, '').replace(/[()"]/g, '').trim();
      }
      return addr.includes('@')
        ? { name: name || null, email: addr.toLowerCase() }
        : { name: name || null, email: null, raw: addr };
    });
}

export function formatAddresses(list) {
  return (list ?? []).map((a) => (a.name ? `${a.name} <${a.email ?? a.raw}>` : a.email ?? a.raw)).join(', ');
}

function messageIds(values) {
  return (values ?? []).flatMap((v) => v.match(/<[^<>\s]+>/g) ?? []).map((id) => id.toLowerCase());
}

/** Parse one message from a Buffer. */
export function parseEmlBuffer(buffer, depth = 0) {
  const entity = parseEntity(Buffer.from(buffer).toString('latin1'));
  const h = entity.headers;
  const text = (name) => (h[name]?.[0] !== undefined ? headerText(h[name][0]) : null);
  const out = { texts: [], attachments: [] };
  walk(entity, out, depth);
  const dateRaw = text('date');
  const date = dateRaw && !Number.isNaN(Date.parse(dateRaw)) ? new Date(dateRaw).toISOString() : null;
  return {
    subject: text('subject') ?? '',
    date,
    dateRaw,
    from: parseAddresses(text('from')),
    to: parseAddresses(text('to')),
    cc: parseAddresses(text('cc')),
    replyTo: parseAddresses(text('reply-to')),
    messageId: messageIds(h['message-id'])[0] ?? null,
    inReplyTo: messageIds(h['in-reply-to']),
    references: messageIds(h.references),
    text: out.texts.join('\n\n').trim(),
    attachments: out.attachments,
  };
}

export function parseEmlFile(path) {
  return parseEmlBuffer(readFileSync(path));
}

const QUOTE_START = [
  /^-{2,}\s*(Original Message|Ursprüngliche Nachricht|Originalnachricht|Forwarded message|Weitergeleitete Nachricht)/i,
  /^(On|Am)\s.{4,200}(wrote|schrieb)\s?.{0,40}:\s*$/i,
  /^(From|Von):\s.+$/, // Outlook-style header block of a quoted message
  /^_{10,}\s*$/,
];

/** Drop quoted replies (">" lines and everything after a reply header), keep the new text. */
export function stripQuoted(text) {
  const lines = text.split('\n');
  const kept = [];
  for (let i = 0; i < lines.length; i++) {
    const line = lines[i];
    if (QUOTE_START.some((re) => re.test(line.trim()))) {
      // "From:" alone is too common; require a "Sent/Gesendet/Date/Datum" line soon after
      if (/^(From|Von):/.test(line.trim()) && !lines.slice(i + 1, i + 4).some((l) => /^(Sent|Gesendet|Date|Datum):/i.test(l.trim()))) {
        kept.push(line);
        continue;
      }
      kept.push('[… quoted text removed …]');
      break;
    }
    if (/^\s*>/.test(line)) continue;
    kept.push(line);
  }
  return kept.join('\n').replace(/\n{3,}/g, '\n\n').trim();
}
