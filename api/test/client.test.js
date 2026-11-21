// Offline tests of the client with a mocked fetch: npm test (node --test)
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { ObjectsForReviewClient, OfrApiError, identifierInput, isDoi } from '../src/client.js';

/** A fetch that answers from a list of [method, path, status, body] and records the requests */
function mockFetch(routes) {
  const calls = [];
  const fetch = async (url, init) => {
    const u = new URL(url);
    const path = u.pathname.replace(/^.*\/api\/v1\/bookReviewManager/, '');
    calls.push({ method: init.method, path, query: Object.fromEntries(u.searchParams), headers: init.headers,
      body: init.body ? JSON.parse(init.body) : undefined });
    const route = routes.find(([method, p]) => method === init.method && (typeof p === 'string' ? p === path : p.test(path)));
    const [, , status, body] = route ?? [null, null, 404, { error: 'notFound' }];
    const payload = typeof body === 'function' ? body(calls.at(-1)) : body;
    return new Response(JSON.stringify(payload), { status, headers: { 'Content-Type': 'application/json' } });
  };
  return { fetch, calls };
}

const client = (fetch, extra = {}) => new ObjectsForReviewClient({ url: 'https://ojs.example/index.php/j/', token: 'T', fetch, ...extra });

test('identifiers: DOIs in any form, everything else is an ISBN', () => {
  assert.ok(isDoi('10.1515/9783110732870'));
  assert.ok(isDoi('https://doi.org/10.1515/x'));
  assert.ok(isDoi('doi:10.1515/x'));
  assert.ok(!isDoi('978-3-16-148410-0'));
  assert.deepEqual(identifierInput(' 978-0-262-04630-5 '), { isbn: '978-0-262-04630-5' });
});

test('builds the API URL and sends the token as Bearer header or query parameter', async () => {
  const { fetch, calls } = mockFetch([['GET', '/objects', 200, { items: [] }]]);
  await client(fetch).listObjects();
  assert.equal(calls[0].headers.Authorization, 'Bearer T');
  await client(fetch, { tokenInQuery: true }).listObjects();
  assert.equal(calls[1].query.apiToken, 'T');
  assert.equal(calls[1].headers.Authorization, undefined);
  assert.equal(new ObjectsForReviewClient({ url: 'https://x/index.php/j/api/v1/bookReviewManager', token: 'T' }).apiUrl,
    'https://x/index.php/j/api/v1/bookReviewManager');
});

test('errors carry status and code', async () => {
  const { fetch } = mockFetch([['POST', '/objects', 409, { error: 'duplicateIdentifier', errorMessage: 'exists', objectId: 3 }]]);
  await assert.rejects(client(fetch).addBook('10.1/x'), (error) => {
    assert.ok(error instanceof OfrApiError);
    assert.equal(error.status, 409);
    assert.equal(error.code, 'duplicateIdentifier');
    assert.equal(error.body.objectId, 3);
    return true;
  });
});

test('review essays: create, add and remove works', async () => {
  const { fetch, calls } = mockFetch([
    ['POST', '/bundles', 201, ({ body }) => ({ id: 20, isReviewEssay: true, members: body.objectIds.map((id) => ({ id })) })],
    ['POST', '/bundles/20/members', 200, { id: 20, members: [{ id: 12 }, { id: 13 }, { id: 14 }] }],
    ['DELETE', '/bundles/20/members/14', 200, { id: 20, members: [{ id: 12 }, { id: 13 }] }],
  ]);
  const ofr = client(fetch);
  const bundle = await ofr.createBundle({ title: 'Essay', objectIds: [12, 13], hidden: true });
  assert.equal(bundle.id, 20);
  assert.deepEqual(calls[0].body, { title: 'Essay', objectIds: [12, 13], hidden: true });

  await ofr.addBundleMember('20', '14', 'physical');
  assert.equal(calls[1].path, '/bundles/20/members');
  assert.deepEqual(calls[1].body, { objectId: 14, copyPreference: 'physical' });
  await ofr.addBundleMember(20, 14);
  assert.deepEqual(calls[2].body, { objectId: 14 });

  const removed = await ofr.removeBundleMember(20, 14);
  assert.equal(calls[3].method, 'DELETE');
  assert.equal(calls[3].body, undefined);
  assert.equal(removed.members.length, 2);
});

test('review essays: rule violations carry code and reason', async () => {
  const { fetch } = mockFetch([['POST', '/bundles', 409, { error: 'cannot_combine', errorMessage: 'Only one reviewer.',
    reason: 'plugins.generic.bookReviewManager.reviewEssay.error.twoReviewers' }]]);
  await assert.rejects(client(fetch).createBundle({ title: 'E', objectIds: [1, 2] }), (error) => {
    assert.equal(error.status, 409);
    assert.equal(error.code, 'cannot_combine');
    assert.match(error.body.reason, /twoReviewers$/);
    return true;
  });
});

test('importData: users first, then works with their assignments; failures do not stop the import', async () => {
  let nextId = 1;
  const { fetch, calls } = mockFetch([
    ['POST', '/users', 200, ({ body }) => ({ id: nextId++, email: body.email, _created: true, _rolesAdded: body.roles ?? ['author'] })],
    ['POST', '/objects', 200, ({ body }) => (body.isbn === 'bad'
      ? undefined : { id: 10, identifier: body.isbn ?? body.doi, title: 'T', _existing: body.doi === '10.1/old' })],
    ['POST', /^\/objects\/\d+\/assignments$/, 200, ({ body }) => ({ id: 100, userId: 1, status: body.status })],
  ]);
  // A failing work: the mock answers 200 with no body for 'bad', so make it fail explicitly
  const failing = async (url, init) => (init.body?.includes('"isbn":"bad"')
    ? new Response(JSON.stringify({ error: 'invalidIsbn', errorMessage: 'Not a valid ISBN.' }), { status: 400 })
    : fetch(url, init));

  const report = await client(failing).importData({
    users: [{ email: 'p@x.org', givenName: 'P', roles: ['publisherContact'] }],
    objects: [
      { isbn: '9780262046305', assignments: [{ email: 'r@x.org', status: 'accepted', dueDate: '2027-01-01' }] },
      { isbn: 'bad', assignments: [{ email: 'r@x.org', status: 'bid' }] },
      { doi: '10.1/old', assignments: [{ email: 'r@x.org', status: 'bid' }] },
    ],
  });

  assert.equal(calls[0].path, '/users');
  assert.equal(calls[1].body.onDuplicate, 'skip');
  assert.deepEqual(report.users.map((u) => u.action), ['created']);
  assert.deepEqual(report.objects.map((o) => o.action), ['created', 'exists']);
  assert.deepEqual(report.assignments.map((a) => a.action), ['created', 'skipped (work existed)']);
  assert.deepEqual(report.errors.map((e) => [e.kind, e.code]), [['object', 'invalidIsbn'], ['assignment', null]]);
});

test('importData dry run changes nothing', async () => {
  const { fetch, calls } = mockFetch([
    ['GET', '/users', 200, { items: [] }],
    ['GET', '/objects', 200, { items: [] }],
    ['GET', '/lookup', 200, { identifier: '9780262046305', title: 'Found' }],
  ]);
  const report = await client(fetch).importData({
    users: [{ email: 'p@x.org' }],
    objects: [{ isbn: '978-0-262-04630-5', assignments: [{ email: 'r@x.org', status: 'bid' }] }],
  }, { dryRun: true });
  assert.ok(calls.every((call) => call.method === 'GET'));
  assert.equal(report.objects[0].title, 'Found');
  assert.equal(report.assignments[0].action, 'create');
});

test('importData: review essays combine imported works and get their reviewer with copies', async () => {
  const works = { '9780262046305': { id: 12, bundleId: null }, '10.1/b': { id: 13, bundleId: null },
    '9781509955107': { id: 14, bundleId: 30 }, '10.1/c': { id: 15, bundleId: 30 } };
  const { fetch, calls } = mockFetch([
    ['GET', '/objects', 200, ({ query }) => ({ items: works[query.identifier.replace(/-/g, '')] || works[query.identifier] ? [works[query.identifier.replace(/-/g, '')] ?? works[query.identifier]] : [] })],
    ['POST', '/bundles', 201, ({ body }) => ({ id: 20, isReviewEssay: true, members: body.objectIds.map((id) => ({ id })) })],
    ['POST', /^\/objects\/\d+\/assignments$/, 200, ({ body }) => ({ id: 100, userId: 7, status: body.status })],
  ]);
  const report = await client(fetch).importData({
    essays: [
      { title: 'New books', works: ['978-0-262-04630-5', { doi: '10.1/b' }], hidden: true, assignments: [{
        email: 'r@x.org', status: 'accepted', dueDate: '2027-01-31',
        copies: [{ work: '9780262046305', copyPreference: 'physical', copyStatus: 'received' }, { work: { doi: '10.1/B' }, copyPreference: 'electronic' }],
      }] },
      { title: 'Exists', works: ['9781509955107', '10.1/c'], assignments: [{ email: 'r@x.org', status: 'accepted', dueDate: '2027-01-31' }] },
      { title: 'Missing work', works: ['10.1/none'], assignments: [{ email: 'r@x.org', status: 'accepted', dueDate: '2027-01-31' }] },
    ],
  });
  const created = calls.find((c) => c.method === 'POST' && c.path === '/bundles');
  assert.deepEqual(created.body, { title: 'New books', hidden: true, objectIds: [12, 13] });
  const assignment = calls.find((c) => c.path === '/objects/20/assignments');
  assert.deepEqual(assignment.body.copies, [
    { objectId: 12, copyPreference: 'physical', copyStatus: 'received' },
    { objectId: 13, copyPreference: 'electronic' },
  ]);
  assert.deepEqual(report.essays.map((e) => [e.title, e.id, e.action]), [['New books', 20, 'created'], ['Exists', 30, 'exists']]);
  assert.deepEqual(report.assignments.map((a) => [a.essayId, a.action]), [[20, 'created'], [30, 'skipped (essay existed)']]);
  assert.deepEqual(report.errors.map((e) => [e.kind, e.code]), [['essay', 'objectNotFound'], ['assignment', null]]);
  assert.equal(calls.filter((c) => c.path === '/bundles').length, 1);
});

test('importData dry run: essays of works not imported yet are reported, nothing is created', async () => {
  const { fetch, calls } = mockFetch([['GET', '/objects', 200, { items: [] }]]);
  const report = await client(fetch).importData({
    essays: [{ title: 'E', works: ['9780262046305', '10.1/b'], assignments: [{ email: 'r@x.org', status: 'accepted', dueDate: '2027-01-31',
      copies: [{ work: '10.1/b', copyPreference: 'physical' }] }] }],
  }, { dryRun: true });
  assert.ok(calls.every((call) => call.method === 'GET'));
  assert.deepEqual(report.essays, [{ title: 'E', id: null, works: [null, null], action: 'create' }]);
  assert.equal(report.assignments[0].action, 'create');
  assert.deepEqual(report.errors, []);
});
