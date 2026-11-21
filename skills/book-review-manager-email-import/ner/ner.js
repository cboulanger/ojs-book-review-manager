// Local named-entity recognition for personal data, with GLiNER (multilingual PII
// model, ONNX, CPU). Runs entirely on this machine: the only network access is the
// one-time download of the model and its tokenizer from Hugging Face.
//
// Use the full-precision model (onnx/model.onnx): the int8 build scores names far
// too low to be useful. See download-model.sh.

import { existsSync } from 'node:fs';
import { homedir } from 'node:os';
import { join } from 'node:path';
import { Gliner } from 'gliner/node';

export const DEFAULT_MODEL = join(homedir(), '.cache', 'ofr-email-import', 'gliner_multi_pii-v1.onnx');
const TOKENIZER = 'onnx-community/gliner_multi_pii-v1';

/** GLiNER labels -> our kinds. Organizations are not masked (publishers must stay visible). */
const LABELS = { person: 'person', 'street address': 'address', 'phone number': 'phone', email: 'email' };

const HONORIFIC = /^((herrn?|frau|dr\.?|prof\.?|professor(in)?|mr\.?|mrs\.?|ms\.?|dear|liebe[rs]?|sehr geehrte[rs]?)\s+)+/i;

/** Split text into chunks of at most `maxWords` words, at line breaks where possible (model limit: 384 words). */
export function chunk(text, maxWords = 250) {
  const chunks = [];
  let cur = [];
  let words = 0;
  for (const line of text.split('\n')) {
    const n = line.split(/\s+/).filter(Boolean).length;
    if (words + n > maxWords && cur.length) {
      chunks.push(cur.join('\n'));
      cur = [];
      words = 0;
    }
    if (n > maxWords) {
      const w = line.split(/\s+/);
      for (let i = 0; i < w.length; i += maxWords) chunks.push(w.slice(i, i + maxWords).join(' '));
      continue;
    }
    cur.push(line);
    words += n;
  }
  if (cur.length) chunks.push(cur.join('\n'));
  return chunks.filter((c) => c.trim());
}

/** A detected person span -> the name to mask, or null for noise ("I", "Herr", lowercase words). */
export function cleanPerson(span) {
  const name = span.replace(HONORIFIC, '').replace(/[,;:.]+$/, '').replace(/\s+/g, ' ').trim();
  if (name.length < 3 || !/^\p{Lu}/u.test(name) || /\d/.test(name)) return null;
  return name;
}

export async function createNer({ modelPath = process.env.OFR_NER_MODEL || DEFAULT_MODEL, threshold = 0.5, batchSize = 8 } = {}) {
  if (!existsSync(modelPath)) throw new Error(`NER model not found at ${modelPath}; run ner/download-model.sh`);
  const gliner = new Gliner({
    tokenizerPath: TOKENIZER,
    onnxSettings: { modelPath, executionProvider: 'cpu', multiThread: true },
    maxWidth: 12,
  });
  await gliner.initialize();

  return {
    /** texts -> per text: [{ kind: person|address|phone|email, text, score }] */
    async detect(texts) {
      const pieces = texts.flatMap((t, i) => chunk(t ?? '').map((c) => ({ i, c })));
      const out = texts.map(() => []);
      for (let b = 0; b < pieces.length; b += batchSize) {
        const batch = pieces.slice(b, b + batchSize);
        const res = await gliner.inference({ texts: batch.map((p) => p.c), entities: Object.keys(LABELS), flatNer: true, threshold });
        res.forEach((list, k) => {
          for (const e of list) out[batch[k].i].push({ kind: LABELS[e.label], text: e.spanText.trim(), score: e.score });
        });
      }
      return out;
    },
  };
}
