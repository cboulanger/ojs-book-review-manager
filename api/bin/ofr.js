#!/usr/bin/env node
/**
 * Command line client for the REST API of the OJS Objects for Review plugin.
 * Run `ofr help` for the commands. Output is JSON on stdout; progress goes to stderr.
 */

import { readFile } from 'node:fs/promises';
import { parseArgs } from 'node:util';
import { ObjectsForReviewClient, identifierInput, RESOURCE_TYPES } from '../src/client.js';

const HELP = `Usage: ofr <command> [options]

Connection (or environment variables):
  --url <journal URL>      OFR_URL     e.g. https://example.org/index.php/journal
  --token <API key>        OFR_TOKEN   from the OJS user profile (Profile > API Key)
  --token-file <path>      OFR_TOKEN_FILE  read the API key from a file
  --token-in-query         OFR_TOKEN_IN_QUERY=1  send ?apiToken= (if the server strips the Authorization header)

Works offered for review:
  lookup <doi|isbn>                          Show the metadata found for a DOI or ISBN (saves nothing)
  add-book <doi|isbn> [fields]               Create a work from a DOI or ISBN
  add-books <file> [fields]                  Create works from a file with one DOI or ISBN per line
  add-object --data <json|@file>             Create a work from JSON (any identifier type)
  update-object <id> --data <json|@file>     Change fields of a work
  list                                       List all works
  get <id>                                   Show a work with its assignments
  find <doi|isbn>                            Show the work with this identifier, if any

  Fields for add-book / add-books (override the lookup):
    --mode direct|bidding  --bid-deadline Y-m-d  --editor <email>  --publisher-contact <email>
    --journal-holds-copy  --no-return  --notify-publisher  --hidden  --no-lookup
    --title --authors --publisher --year --description --resource-type <${Object.keys(RESOURCE_TYPES).join('|')}|URI>
    --on-duplicate error|skip|update   (add-book: error, add-books: skip)

People:
  users [--role author|publisherContact|bookReviewEditor] [--email <email>]
  add-user --email <email> --given <name> [--family <name>] [--affiliation <text>]
           [--address <mailing address>] [--phone <phone>] [--country <ISO code>]
           [--role author|publisherContact|bookReviewEditor ...] [--update-profile]
  add-author ...                             add-user with --role author
  add-publisher-contact ...                  add-user with --role publisherContact

Review processes under way:
  add-assignment <objectId> --data <json|@file>   Import a bidder or reviewer (no emails are sent)
  assignments <objectId>                          List the assignments of a work

Review essays (one review of several works):
  bundle create --title <title> --works <id,id,...> [--description <text>] [--hidden]
                [--mode direct|bidding] [--editor <email>]
                                                  Combine works into a review essay
  bundle add <essayId> <objectId> [--copy have|physical|electronic|other]
                                                  Add a work (--copy: the reviewer's copy, if there is one)
  bundle remove <essayId> <objectId>              Take a work out of a review essay
  To import the reviewer of an essay: add-assignment <essayId> with "copies": [{ objectId, copyPreference, copyStatus }]
  Or list essays in an import document: "essays": [{ title, works: [isbn|doi, ...], assignments: [...] }]

Bulk import:
  import <file.json> [--dry-run] [--on-duplicate skip|error|update] [--include-existing]
      { "users": [...], "objects": [ { ..., "assignments": [...] } ] }; see examples/import.example.json

  help                                       Show this text
`;

const OPTIONS = {
  url: { type: 'string' },
  token: { type: 'string' },
  'token-file': { type: 'string' },
  'token-in-query': { type: 'boolean' },
  data: { type: 'string' },
  // works
  mode: { type: 'string' },
  'bid-deadline': { type: 'string' },
  editor: { type: 'string' },
  'publisher-contact': { type: 'string' },
  'journal-holds-copy': { type: 'boolean' },
  'no-return': { type: 'boolean' },
  'notify-publisher': { type: 'boolean' },
  hidden: { type: 'boolean' },
  'no-lookup': { type: 'boolean' },
  title: { type: 'string' },
  authors: { type: 'string' },
  publisher: { type: 'string' },
  year: { type: 'string' },
  description: { type: 'string' },
  'resource-type': { type: 'string' },
  'on-duplicate': { type: 'string' },
  // review essays
  works: { type: 'string' },
  copy: { type: 'string' },
  // people
  email: { type: 'string' },
  given: { type: 'string' },
  family: { type: 'string' },
  affiliation: { type: 'string' },
  address: { type: 'string' },
  phone: { type: 'string' },
  country: { type: 'string' },
  role: { type: 'string', multiple: true },
  'update-profile': { type: 'boolean' },
  // import
  'dry-run': { type: 'boolean' },
  'include-existing': { type: 'boolean' },
  help: { type: 'boolean', short: 'h' },
};

async function main(argv) {
  const { values: opts, positionals } = parseArgs({ args: argv, options: OPTIONS, allowPositionals: true, strict: true });
  const [command = 'help', ...args] = positionals;
  if (command === 'help' || opts.help) {
    process.stdout.write(HELP);
    return;
  }

  const client = await makeClient(opts);
  const need = (value, what) => {
    if (value === undefined || value === '') throw new UsageError(`${command}: ${what} is required`);
    return value;
  };

  switch (command) {
    case 'lookup':
      return print(await client.lookup(identifierInput(need(args[0], 'a DOI or ISBN'))));
    case 'find':
      return print(await client.findObject(identifierInput(need(args[0], 'a DOI or ISBN'))));
    case 'list':
      return print(await client.listObjects());
    case 'get':
      return print(await client.getObject(need(args[0], 'the object id')));
    case 'add-book':
      return print(await client.addObject({
        ...identifierInput(need(args[0], 'a DOI or ISBN')),
        ...objectFields(opts),
        onDuplicate: opts['on-duplicate'] ?? 'error',
      }));
    case 'add-books': {
      const lines = (await readFile(need(args[0], 'a file'), 'utf8')).split(/\r?\n/)
        .map((line) => line.replace(/#.*/, '').trim()).filter(Boolean);
      const doc = { objects: lines.map((line) => ({ ...identifierInput(line), ...objectFields(opts) })) };
      return finishImport(await client.importData(doc, { onDuplicate: opts['on-duplicate'] ?? 'skip', onProgress: progress }));
    }
    case 'add-object':
      return print(await client.addObject(await readData(need(opts.data, '--data'))));
    case 'update-object':
      return print(await client.updateObject(need(args[0], 'the object id'), await readData(need(opts.data, '--data'))));
    case 'users':
      return print(await client.listUsers({ role: opts.role?.[0], email: opts.email }));
    case 'add-user':
    case 'add-author':
    case 'add-publisher-contact': {
      const roles = command === 'add-author' ? ['author'] : command === 'add-publisher-contact' ? ['publisherContact'] : opts.role;
      return print(await client.addUser({ ...userFields(opts), email: need(opts.email, '--email'), roles }));
    }
    case 'assignments':
      return print(await client.listAssignments(need(args[0], 'the object id')));
    case 'add-assignment':
      return print(await client.addAssignment(need(args[0], 'the object id'), await readData(need(opts.data, '--data'))));
    case 'bundle': {
      const [action, ...ids] = args;
      const id = (value, what) => {
        if (!/^\d+$/.test(need(value, what))) throw new UsageError(`${command} ${action}: ${what} must be a number`);
        return Number(value);
      };
      if (action === 'create') {
        const objectIds = need(opts.works, '--works').split(',').map((value) => id(value.trim(), 'each id in --works'));
        const fields = {
          title: need(opts.title, '--title'),
          objectIds,
          description: opts.description,
          hidden: opts.hidden,
          assignmentMode: opts.mode,
          responsibleEditorEmail: opts.editor,
        };
        return print(await client.createBundle(Object.fromEntries(Object.entries(fields).filter(([, value]) => value !== undefined))));
      }
      if (action === 'add') {
        return print(await client.addBundleMember(id(ids[0], 'the essay id'), id(ids[1], 'the object id'), opts.copy));
      }
      if (action === 'remove') {
        return print(await client.removeBundleMember(id(ids[0], 'the essay id'), id(ids[1], 'the object id')));
      }
      throw new UsageError(`${command}: create, add or remove is required (see: ofr help)`);
    }
    case 'import': {
      const doc = JSON.parse(await readFile(need(args[0], 'a JSON file'), 'utf8'));
      return finishImport(await client.importData(doc, {
        onDuplicate: opts['on-duplicate'] ?? 'skip',
        importAssignmentsOfExisting: Boolean(opts['include-existing']),
        dryRun: Boolean(opts['dry-run']),
        onProgress: progress,
      }));
    }
    default:
      throw new UsageError(`Unknown command: ${command} (see: ofr help)`);
  }
}

class UsageError extends Error {}

async function makeClient(opts) {
  const url = opts.url ?? process.env.OFR_URL;
  let token = opts.token ?? process.env.OFR_TOKEN;
  const tokenFile = opts['token-file'] ?? process.env.OFR_TOKEN_FILE;
  if (!token && tokenFile) token = (await readFile(tokenFile, 'utf8')).trim();
  if (!url) throw new UsageError('No journal URL: use --url or OFR_URL');
  if (!token) throw new UsageError('No API key: use --token, --token-file, OFR_TOKEN or OFR_TOKEN_FILE');
  const tokenInQuery = opts['token-in-query'] ?? ['1', 'true', 'yes'].includes(String(process.env.OFR_TOKEN_IN_QUERY ?? '').toLowerCase());
  return new ObjectsForReviewClient({ url, token, tokenInQuery });
}

/** --data '{"a":1}' or --data @file.json */
async function readData(value) {
  const text = value.startsWith('@') ? await readFile(value.slice(1), 'utf8') : value;
  try {
    return JSON.parse(text);
  } catch (error) {
    throw new UsageError(`--data is not valid JSON: ${error.message}`);
  }
}

function objectFields(opts) {
  const fields = {
    assignmentMode: opts.mode,
    bidDeadline: opts['bid-deadline'],
    responsibleEditorEmail: opts.editor,
    publisherContactEmail: opts['publisher-contact'],
    journalHoldsCopy: opts['journal-holds-copy'],
    returnRequired: opts['no-return'] ? false : undefined,
    notifyPublisher: opts['notify-publisher'],
    hidden: opts.hidden,
    lookup: opts['no-lookup'] ? false : undefined,
    title: opts.title,
    authors: opts.authors,
    publisher: opts.publisher,
    year: opts.year,
    description: opts.description,
    resourceType: opts['resource-type'] ? RESOURCE_TYPES[opts['resource-type']] ?? opts['resource-type'] : undefined,
  };
  return Object.fromEntries(Object.entries(fields).filter(([, value]) => value !== undefined));
}

function userFields(opts) {
  const fields = {
    givenName: opts.given,
    familyName: opts.family,
    affiliation: opts.affiliation,
    mailingAddress: opts.address?.replace(/\\n/g, '\n'),
    phone: opts.phone,
    country: opts.country,
    updateProfile: opts['update-profile'],
  };
  return Object.fromEntries(Object.entries(fields).filter(([, value]) => value !== undefined));
}

function progress(event) {
  const { type, action, message, ...rest } = event;
  const what = rest.title ?? rest.record?.title ?? rest.email ?? rest.record?.email ?? rest.identifier
    ?? rest.record?.doi ?? rest.record?.isbn ?? rest.record?.identifier ?? '';
  process.stderr.write(type === 'error'
    ? `! ${rest.kind} ${JSON.stringify(rest.record)}: ${message}\n`
    : `${type} ${action}${rest.id ? ` #${rest.id}` : ''}${what ? `: ${what}` : ''}\n`);
}

function finishImport(report) {
  print(report);
  const tally = (list) => Object.entries(list.reduce((counts, entry) => ({ ...counts, [entry.action]: (counts[entry.action] ?? 0) + 1 }), {}))
    .map(([action, n]) => `${n} ${action}`).join(', ') || 'none';
  process.stderr.write(`\nusers: ${tally(report.users)}; objects: ${tally(report.objects)}; `
    + `essays: ${tally(report.essays ?? [])}; assignments: ${tally(report.assignments)}; errors: ${report.errors.length}\n`);
  if (report.errors.length) process.exitCode = 1;
}

function print(data) {
  process.stdout.write(JSON.stringify(data, null, 2) + '\n');
}

main(process.argv.slice(2)).catch((error) => {
  if (error instanceof UsageError || error.code === 'ERR_PARSE_ARGS_UNKNOWN_OPTION' || error.code === 'ERR_PARSE_ARGS_INVALID_OPTION_VALUE') {
    process.stderr.write(`${error.message}\n`);
    process.exitCode = 2;
  } else {
    process.stderr.write(`${error.message}\n`);
    if (error.body && typeof error.body === 'object') process.stderr.write(JSON.stringify(error.body) + '\n');
    process.exitCode = 1;
  }
});
