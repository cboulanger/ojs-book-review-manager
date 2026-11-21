// Tests of the command line client against a fake API server (offline): npm test
import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { execFile } from 'node:child_process';
import { mkdtemp, writeFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const OFR = fileURLToPath(new URL('../bin/ofr.js', import.meta.url));
let server;
let url;
let dir;
const requests = [];

/** Routes of the fake API: [method, path regex, (body, query) => [status, json]] */
const routes = [
  ['GET', /^\/objects$/, (_, query) => [200, { itemsMax: 1, items: query.get('identifier') === '9780262046305' ? [{ id: 6 }] : [] }]],
  ['POST', /^\/objects$/, (body) => (body.isbn === '9780000000000'
    ? [400, { error: 'invalidIsbn', errorMessage: 'Not a valid ISBN.' }]
    : [200, { id: 7, identifier: body.isbn ?? body.doi, title: 'T', ...(body.isbn === '9780262046305' ? { _existing: true } : {}) }])],
  ['PUT', /^\/objects\/7$/, (body) => [200, { id: 7, ...body }]],
  ['GET', /^\/lookup$/, (_, query) => [200, { identifier: query.get('doi') ?? query.get('isbn'), title: 'Looked up' }]],
  ['POST', /^\/users$/, (body) => [200, { id: 3, ...body, _created: true, _rolesAdded: body.roles }]],
  ['POST', /^\/objects\/7\/assignments$/, (body) => [200, { id: 9, ...body }]],
  ['POST', /^\/bundles$/, (body) => [201, { id: 20, isReviewEssay: true, ...body }]],
  ['POST', /^\/bundles\/20\/members$/, (body) => [200, { id: 20, added: body }]],
  ['DELETE', /^\/bundles\/20\/members\/14$/, () => [200, { id: 20, members: [] }]],
];

before(async () => {
  dir = await mkdtemp(join(tmpdir(), 'ofr-cli-'));
  server = createServer((req, res) => {
    let raw = '';
    req.on('data', (chunk) => { raw += chunk; });
    req.on('end', () => {
      const u = new URL(req.url, 'http://x');
      const path = u.pathname.replace(/^.*\/api\/v1\/bookReviewManager/, '');
      const body = raw ? JSON.parse(raw) : undefined;
      requests.push({ method: req.method, path, query: u.searchParams, body, auth: req.headers.authorization });
      const route = routes.find(([method, pattern]) => method === req.method && pattern.test(path));
      const [status, json] = route ? route[2](body, u.searchParams) : [404, { error: 'notFound' }];
      if (req.headers.authorization !== 'Bearer TOKEN' && u.searchParams.get('apiToken') !== 'TOKEN') {
        res.writeHead(401).end(JSON.stringify({ error: 'You are not authorized to access the requested resource.' }));
        return;
      }
      res.writeHead(status, { 'Content-Type': 'application/json' }).end(JSON.stringify(json));
    });
  });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  url = `http://127.0.0.1:${server.address().port}/index.php/j`;
});

after(async () => {
  server.close();
  await rm(dir, { recursive: true, force: true });
});

/** Run the CLI; resolves to { code, stdout, stderr, json } */
function ofr(args, env = {}) {
  return new Promise((resolve) => {
    execFile(process.execPath, [OFR, ...args], { env: { PATH: process.env.PATH, OFR_URL: url, OFR_TOKEN: 'TOKEN', ...env } },
      (error, stdout, stderr) => {
        let json;
        try { json = JSON.parse(stdout); } catch { json = undefined; }
        resolve({ code: error ? error.code : 0, stdout, stderr, json });
      });
  });
}

const last = () => requests.at(-1);

test('help and usage errors exit with 0 and 2', async () => {
  const help = await ofr(['help']);
  assert.equal(help.code, 0);
  assert.match(help.stdout, /Usage: ofr <command>/);
  assert.equal((await ofr(['frobnicate'])).code, 2);
  assert.equal((await ofr(['list', '--bogus'])).code, 2);
  assert.equal((await ofr(['lookup'])).code, 2, 'missing argument');
});

test('connection settings: missing URL or token, token file, token in query', async () => {
  const noUrl = await ofr(['list'], { OFR_URL: '' });
  assert.equal(noUrl.code, 2);
  assert.match(noUrl.stderr, /OFR_URL/);
  assert.equal((await ofr(['list'], { OFR_TOKEN: '' })).code, 2);

  const tokenFile = join(dir, 'token');
  await writeFile(tokenFile, 'TOKEN\n');
  assert.equal((await ofr(['list'], { OFR_TOKEN: '', OFR_TOKEN_FILE: tokenFile })).code, 0);

  await ofr(['list', '--token-in-query']);
  assert.equal(last().query.get('apiToken'), 'TOKEN');
  assert.equal(last().auth, undefined);
});

test('a rejected token exits with 1 and suggests --token-in-query', async () => {
  const result = await ofr(['list'], { OFR_TOKEN: 'wrong' });
  assert.equal(result.code, 1);
  assert.match(result.stderr, /401/);
  assert.match(result.stderr, /token-in-query/);
});

test('lookup and add-book detect DOIs and ISBNs', async () => {
  const lookup = await ofr(['lookup', 'https://doi.org/10.1515/x']);
  assert.equal(last().query.get('doi'), 'https://doi.org/10.1515/x');
  assert.equal(lookup.json.title, 'Looked up');

  await ofr(['add-book', '978-0-262-04630-5', '--mode', 'bidding', '--bid-deadline', '2027-01-31', '--publisher-contact', 'p@x.org',
    '--no-return', '--notify-publisher', '--hidden', '--resource-type', 'bookPart', '--title', 'Own title']);
  assert.deepEqual(last().body, {
    isbn: '978-0-262-04630-5', assignmentMode: 'bidding', bidDeadline: '2027-01-31', publisherContactEmail: 'p@x.org',
    returnRequired: false, notifyPublisher: true, hidden: true, resourceType: 'http://purl.org/coar/resource_type/c_3248',
    title: 'Own title', onDuplicate: 'error',
  });
});

test('API errors exit with 1 and print the error body', async () => {
  const result = await ofr(['add-book', '9780000000000']);
  assert.equal(result.code, 1);
  assert.match(result.stderr, /400 Not a valid ISBN/);
  assert.match(result.stderr, /"error":"invalidIsbn"/);
});

test('add-books reads one identifier per line, skips comments and existing works', async () => {
  const file = join(dir, 'books.txt');
  await writeFile(file, '# my list\n9780262046305\n\n10.1515/abc  # a DOI\n9780000000000\n');
  const result = await ofr(['add-books', file, '--mode', 'direct']);
  assert.equal(result.code, 1, 'one record failed');
  assert.deepEqual(result.json.objects.map((o) => o.action), ['exists', 'created']);
  assert.equal(result.json.errors.length, 1);
  assert.match(result.stderr, /objects: 1 exists, 1 created/);
  const posted = requests.filter((r) => r.method === 'POST' && r.path === '/objects').slice(-3).map((r) => r.body);
  assert.deepEqual(posted.map((b) => b.isbn ?? b.doi), ['9780262046305', '10.1515/abc', '9780000000000']);
  assert.ok(posted.every((b) => b.onDuplicate === 'skip' && b.assignmentMode === 'direct'));
});

test('people commands set the role', async () => {
  await ofr(['add-publisher-contact', '--email', 'p@x.org', '--given', 'Petra', '--address', 'Line 1\\nLine 2', '--country', 'DE']);
  assert.deepEqual(last().body, { givenName: 'Petra', mailingAddress: 'Line 1\nLine 2', country: 'DE', email: 'p@x.org', roles: ['publisherContact'] });
  await ofr(['add-author', '--email', 'a@x.org', '--given', 'A']);
  assert.deepEqual(last().body.roles, ['author']);
  await ofr(['add-user', '--email', 'e@x.org', '--given', 'E', '--role', 'author', '--role', 'bookReviewEditor', '--update-profile']);
  assert.deepEqual(last().body.roles, ['author', 'bookReviewEditor']);
  assert.equal(last().body.updateProfile, true);
  assert.equal((await ofr(['add-author', '--given', 'No email'])).code, 2);
});

test('--data takes JSON or @file', async () => {
  const file = join(dir, 'assignment.json');
  await writeFile(file, JSON.stringify({ email: 'r@x.org', status: 'accepted', dueDate: '2027-01-01' }));
  const result = await ofr(['add-assignment', '7', '--data', `@${file}`]);
  assert.equal(result.json.id, 9);
  assert.equal(last().body.status, 'accepted');

  await ofr(['update-object', '7', '--data', '{"hidden":true}']);
  assert.deepEqual(last().body, { hidden: true });
  assert.equal((await ofr(['update-object', '7', '--data', '{not json'])).code, 2);
});

test('import --dry-run only reads', async () => {
  const file = join(dir, 'import.json');
  await writeFile(file, JSON.stringify({ objects: [{ isbn: '9781111111111', assignments: [{ email: 'r@x.org', status: 'bid' }] }] }));
  const before = requests.length;
  const result = await ofr(['import', file, '--dry-run']);
  assert.equal(result.code, 0);
  assert.ok(requests.slice(before).every((r) => r.method === 'GET'));
  assert.equal(result.json.objects[0].action, 'create');
});

test('bundle create, add and remove', async () => {
  const created = await ofr(['bundle', 'create', '--title', 'Essay', '--works', '12, 13', '--hidden', '--description', 'About both']);
  assert.equal(created.code, 0, created.stderr);
  assert.equal(created.json.id, 20);
  assert.equal(last().method, 'POST');
  assert.equal(last().path, '/bundles');
  assert.deepEqual(last().body, { title: 'Essay', objectIds: [12, 13], hidden: true, description: 'About both' });

  const added = await ofr(['bundle', 'add', '20', '14', '--copy', 'physical']);
  assert.equal(added.code, 0, added.stderr);
  assert.deepEqual(last().body, { objectId: 14, copyPreference: 'physical' });
  await ofr(['bundle', 'add', '20', '14']);
  assert.deepEqual(last().body, { objectId: 14 });

  const removed = await ofr(['bundle', 'remove', '20', '14']);
  assert.equal(removed.code, 0, removed.stderr);
  assert.equal(last().method, 'DELETE');
  assert.equal(last().path, '/bundles/20/members/14');
});

test('bundle: usage errors exit with 2', async () => {
  assert.equal((await ofr(['bundle', 'create', '--works', '12,13'])).code, 2, 'no title');
  assert.equal((await ofr(['bundle', 'create', '--title', 'T'])).code, 2, 'no works');
  assert.equal((await ofr(['bundle', 'create', '--title', 'T', '--works', '12,x'])).code, 2, 'not an id');
  assert.equal((await ofr(['bundle', 'add', '20'])).code, 2, 'no work id');
  assert.equal((await ofr(['bundle', 'remove'])).code, 2, 'no ids');
  assert.equal((await ofr(['bundle'])).code, 2, 'no subcommand');
  assert.equal((await ofr(['bundle', 'frobnicate'])).code, 2);
  assert.match((await ofr(['help'])).stdout, /bundle create/);
});
