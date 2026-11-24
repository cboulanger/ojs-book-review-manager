// Review essays: one review of several works (a container with member works)
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { admin, newUser, newWork, raw, rejects } from './fixtures.mjs';
import { apiKey, date, sql } from '../../lib/ojs.mjs';

/** A hidden review essay of new works */
async function newBundle(count = 2, extra = {}) {
  const works = [];
  for (let i = 0; i < count; i++) works.push(await newWork({ title: `E2E essay work ${i + 1}` }));
  const bundle = await admin().createBundle({ title: 'E2E review essay', objectIds: works.map((w) => w.id), hidden: true, ...extra });
  return { bundle, works };
}

test('create a review essay: 201, the container and its members', async () => {
  const [a, b] = [await newWork(), await newWork()];
  const response = await raw('/bundles', {
    method: 'POST',
    headers: { Authorization: `Bearer ${apiKey('admin')}` },
    body: { title: 'E2E review essay', description: 'About both', objectIds: [a.id, b.id], hidden: true, assignmentMode: 'bidding' },
  });
  assert.equal(response.status, 201, JSON.stringify(response.body));
  const bundle = response.body;
  assert.equal(bundle.isReviewEssay, true);
  assert.equal(bundle.resourceType, 'reviewEssay');
  assert.equal(bundle.identifier, '');
  assert.equal(bundle.title, 'E2E review essay');
  assert.equal(bundle.hidden, true);
  assert.equal(bundle.assignmentMode, 'bidding');
  assert.equal(bundle.bundleId, null);
  assert.deepEqual(bundle.assignments, []);
  assert.deepEqual(bundle.members.map((m) => m.id), [a.id, b.id]);
  assert.ok(bundle.members.every((m) => m.bundleId === bundle.id && m.isReviewEssay === false));

  const work = await admin().getObject(a.id);
  assert.equal(work.bundleId, bundle.id);
  assert.equal(work.members, undefined, 'a work has no members');
});

test('create: input is validated', async () => {
  const work = await newWork();
  await rejects(admin().createBundle({ objectIds: [work.id] }), 400, 'titleRequired');
  await rejects(admin().createBundle({ title: 'E2E', objectIds: [] }), 400, 'worksRequired');
  await rejects(admin().createBundle({ title: 'E2E', objectIds: [work.id], copyPreferences: { [work.id]: 'pdf' } }), 400, 'invalidCopyPreference');
  await rejects(admin().createBundle({ title: 'E2E', objectIds: [work.id], assignmentMode: 'lottery' }), 400, 'invalidAssignmentMode');
  assert.equal((await admin().getObject(work.id)).bundleId, null, 'nothing was saved');
  await rejects(admin().createBundle({ title: 'E2E', objectIds: [work.id, 999999999] }), 409, 'cannot_combine');
});

test('add a work and remove it again', async () => {
  const { bundle } = await newBundle();
  const third = await newWork();
  const added = await admin().addBundleMember(bundle.id, third.id);
  assert.equal(added.members.length, 3);
  assert.equal(added.members[2].id, third.id);
  assert.equal((await admin().getObject(third.id)).bundleId, bundle.id);

  const removed = await admin().removeBundleMember(bundle.id, third.id);
  assert.equal(removed.members.length, 2);
  const work = await admin().getObject(third.id);
  assert.equal(work.bundleId, null);
  assert.equal(work.hidden, true, 'a removed work is hidden at first');
});

test('members: rules give review_essay_rule, unknown essays 404', async () => {
  const { bundle, works } = await newBundle();
  const other = await newBundle();
  await assert.rejects(admin().addBundleMember(bundle.id, other.works[0].id), (error) => {
    assert.equal(error.status, 409);
    assert.equal(error.code, 'review_essay_rule');
    assert.equal(error.body.reason, 'plugins.generic.bookReviewManager.reviewEssay.error.notAvailable');
    assert.ok(error.body.errorMessage && !error.body.errorMessage.startsWith('plugins.'), 'the message is translated');
    return true;
  });
  await admin().removeBundleMember(bundle.id, works[1].id);
  await assert.rejects(admin().removeBundleMember(bundle.id, works[0].id),
    (error) => error.status === 409 && error.code === 'review_essay_rule' && error.body.reason.endsWith('.lastMember'));
  await rejects(admin().removeBundleMember(bundle.id, other.works[0].id), 409, 'review_essay_rule');

  await rejects(admin().addBundleMember(999999999, works[1].id), 404, 'bundleNotFound');
  await rejects(admin().addBundleMember(works[0].id, works[1].id), 404, 'bundleNotFound');
  await rejects(admin().removeBundleMember(works[0].id, works[1].id), 404, 'bundleNotFound');
  await rejects(admin().addBundleMember(bundle.id, 999999999), 404, 'objectNotFound');
  await rejects(admin().addBundleMember(bundle.id, works[1].id, 'pdf'), 400, 'invalidCopyPreference');
});

test('import an accepted reviewer of the essay: the members get mirrors with their copy status', async () => {
  const { bundle, works: [a, b] } = await newBundle();
  const reviewer = await newUser();
  const assignment = await admin().addAssignment(bundle.id, {
    email: reviewer.email, status: 'accepted', dueDate: date(90),
    copies: [
      { objectId: a.id, copyPreference: 'physical', copyStatus: 'sent', copyStatusDate: '2026-11-01' },
      { objectId: b.id, copyPreference: 'electronic', copyStatus: 'received' },
    ],
  });
  assert.equal(assignment.status, 'accepted');
  assert.equal(assignment.objectId, bundle.id);

  const container = await admin().getObject(bundle.id);
  assert.equal(container.state, 'assigned');
  assert.equal(container.reviewerId, reviewer.id);
  assert.equal(container.assignments.length, 1);
  const expected = { [a.id]: ['physical', 'sent'], [b.id]: ['electronic', 'received'] };
  for (const member of container.members) {
    const mirrors = member.assignments.filter((m) => m.bundleAssignmentId === assignment.id);
    assert.equal(mirrors.length, 1, `one mirror on work ${member.id}`);
    const [mirror] = mirrors;
    assert.equal(mirror.status, 'accepted');
    assert.equal(mirror.userId, reviewer.id);
    assert.deepEqual([mirror.copyPreference, mirror.copyStatus], expected[member.id]);
    assert.equal(member.reviewerId, reviewer.id);
  }
  assert.equal(container.members.find((m) => m.id === a.id).assignments[0].copyStatusDate, '2026-11-01 00:00:00');

  // A member is reviewed through its essay
  await rejects(admin().addAssignment(a.id, { email: (await newUser()).email, status: 'accepted', dueDate: date(30) }), 409, 'member_of_review_essay');
});

test('import on an essay: without copies every work is "have"; copies are validated', async () => {
  const { bundle, works: [a] } = await newBundle();
  const reviewer = await newUser();
  await rejects(admin().addAssignment(bundle.id, { email: reviewer.email, status: 'accepted', dueDate: date(90),
    copies: [{ objectId: 999999999, copyPreference: 'have' }] }), 400, 'invalidCopies');
  await rejects(admin().addAssignment(bundle.id, { email: reviewer.email, status: 'accepted', dueDate: date(90),
    copies: [{ objectId: a.id, copyPreference: 'pdf' }] }), 400, 'invalidCopyPreference');
  await rejects(admin().addAssignment(bundle.id, { email: reviewer.email, status: 'accepted', dueDate: date(90),
    copies: [{ objectId: a.id, copyPreference: 'electronic', copyStatus: 'lost' }] }), 400, 'invalidCopyStatus');
  await rejects(admin().addAssignment(bundle.id, { email: reviewer.email, status: 'accepted', dueDate: date(90),
    copies: [{ objectId: a.id, copyPreference: 'physical' }] }), 400, 'shippingAddressRequired');
  assert.equal((await admin().getObject(bundle.id)).assignments.length, 0, 'nothing was saved');

  await admin().addAssignment(bundle.id, { email: reviewer.email, status: 'accepted', dueDate: date(90) });
  const container = await admin().getObject(bundle.id);
  for (const member of container.members) {
    assert.equal(member.assignments.length, 1);
    assert.equal(member.assignments[0].copyPreference, 'have');
    assert.equal(member.assignments[0].copyStatus, 'notNeeded');
  }
});

test('import a submitted review essay: the works get submitted mirrors with their copy status', async (t) => {
  // "submitted" needs the review submission (without it, the reservation is imported as accepted)
  const [submission] = sql('SELECT submission_id FROM submissions WHERE context_id = 1 ORDER BY submission_id DESC LIMIT 1');
  if (!submission) {
    t.skip('the dev journal has no submission');
    return;
  }
  const { bundle, works: [a, b] } = await newBundle();
  const reviewer = await newUser();
  const assignment = await admin().addAssignment(bundle.id, {
    email: reviewer.email, status: 'submitted', dateStatus: '2026-10-15 09:00:00', submissionId: Number(submission.submission_id),
    copies: [
      { objectId: a.id, copyPreference: 'physical', copyStatus: 'returnRequested', copyStatusDate: '2026-10-20' },
      { objectId: b.id, copyPreference: 'electronic' },
    ],
  });
  assert.equal(assignment.status, 'submitted');
  assert.equal(assignment.copyPreferences, null, 'the preferences moved to the mirrors');

  const container = await admin().getObject(bundle.id);
  assert.equal(container.reviewerId, reviewer.id);
  for (const member of container.members) {
    const mirrors = member.assignments.filter((m) => m.bundleAssignmentId === assignment.id);
    assert.equal(mirrors.length, 1, `one mirror on work ${member.id}`);
    assert.equal(mirrors[0].status, 'submitted');
    assert.equal(mirrors[0].dateStatus, '2026-10-15 09:00:00');
    assert.equal(member.reviewerId, reviewer.id);
  }
  const mirrorOf = (work) => container.members.find((m) => m.id === work.id).assignments[0];
  assert.deepEqual([mirrorOf(a).copyPreference, mirrorOf(a).copyStatus, mirrorOf(a).copyStatusDate],
    ['physical', 'returnRequested', '2026-10-20 00:00:00']);
  assert.deepEqual([mirrorOf(b).copyPreference, mirrorOf(b).copyStatus], ['electronic', 'notNeeded']);
});

test('import a submitted review essay without its submission: accepted, copies received, with a warning', async () => {
  const { bundle, works: [a, b] } = await newBundle();
  const assignment = await admin().addAssignment(bundle.id, {
    email: (await newUser()).email, status: 'submitted', dateStatus: '2026-10-15 09:00:00',
    copies: [{ objectId: a.id, copyPreference: 'physical' }, { objectId: b.id, copyPreference: 'have' }],
  });
  assert.equal(assignment.status, 'accepted');
  assert.equal(assignment.dueDate, '2026-10-15');
  assert.deepEqual(assignment.warnings.map((w) => w.code), ['submittedWithoutSubmission']);
  const container = await admin().getObject(bundle.id);
  const mirrorOf = (work) => container.members.find((m) => m.id === work.id).assignments[0];
  assert.deepEqual([mirrorOf(a).status, mirrorOf(a).copyStatus], ['accepted', 'received']);
  assert.deepEqual([mirrorOf(b).status, mirrorOf(b).copyStatus], ['accepted', 'notNeeded']);
});

test('import with copy statuses: a mirror whose copy has arrived keeps no delivery details', async () => {
  const { bundle, works: [a, b] } = await newBundle();
  const reviewer = await newUser();
  const assignment = await admin().addAssignment(bundle.id, {
    email: reviewer.email, status: 'accepted', dueDate: date(90),
    shippingAddress: 'E2E Street 1, 10115 Berlin', phone: '+49 30 000000',
    copies: [
      { objectId: a.id, copyPreference: 'physical', copyStatus: 'received' },
      { objectId: b.id, copyPreference: 'physical', copyStatus: 'pending' },
    ],
  });
  const container = await admin().getObject(bundle.id);
  const personal = (work) => {
    const mirror = container.members.find((m) => m.id === work.id).assignments.find((m) => m.bundleAssignmentId === assignment.id);
    const rows = sql(`SELECT setting_name FROM brm_assignment_settings WHERE assignment_id = ${Number(mirror.id)}
      AND setting_name IN ('shippingAddress', 'phone') AND setting_value <> ''`);
    return rows.map((row) => row.setting_name).sort();
  };
  assert.deepEqual(personal(a), [], 'the received copy: no address, no phone');
  assert.deepEqual(personal(b), ['phone', 'shippingAddress'], 'the copy still to be sent keeps them');
});

test('PUT objects: the resource type of an essay cannot change, and a work cannot become one', async () => {
  const { bundle, works: [a] } = await newBundle();
  const book = 'http://purl.org/coar/resource_type/c_2f33';
  await assert.rejects(admin().updateObject(bundle.id, { resourceType: book }), (error) => {
    assert.equal(error.status, 409);
    assert.equal(error.code, 'review_essay_rule');
    assert.equal(error.body.reason, 'plugins.generic.bookReviewManager.reviewEssay.error.resourceType');
    assert.ok(error.body.errorMessage && !error.body.errorMessage.startsWith('plugins.'), 'the message is translated');
    return true;
  });
  const container = await admin().getObject(bundle.id);
  assert.equal(container.resourceType, 'reviewEssay');
  assert.equal(container.isReviewEssay, true);
  assert.equal((await admin().updateObject(bundle.id, { resourceType: 'reviewEssay', year: '2025' })).resourceType, 'reviewEssay',
    'the same type is accepted');

  await rejects(admin().updateObject(a.id, { resourceType: 'reviewEssay' }), 400, 'invalidResourceType');
  const loose = await newWork();
  await rejects(admin().updateObject(loose.id, { resourceType: 'reviewEssay' }), 400, 'invalidResourceType');
  assert.equal((await admin().getObject(loose.id)).isReviewEssay, false);
});

test('import on a work that is not a review essay: copies give invalidCopies', async () => {
  const work = await newWork();
  const reviewer = await newUser();
  await assert.rejects(admin().addAssignment(work.id, { email: reviewer.email, status: 'accepted', dueDate: date(90),
    copies: [{ objectId: work.id, copyPreference: 'electronic' }] }), (error) => {
    assert.equal(error.status, 400);
    assert.equal(error.code, 'invalidCopies');
    assert.ok(error.body.errorMessage && !error.body.errorMessage.startsWith('plugins.'), 'the message is translated');
    return true;
  });
  assert.equal((await admin().getObject(work.id)).assignments.length, 0, 'nothing was saved');
});

test('create: copyPreferences only for the works of the essay', async () => {
  const [a, other] = [await newWork(), await newWork()];
  await rejects(admin().createBundle({ title: 'E2E', objectIds: [a.id], copyPreferences: { [other.id]: 'have' } }), 400, 'invalidCopies');
  assert.equal((await admin().getObject(a.id)).bundleId, null);
});

test('combining two works that each have a reviewer gives cannot_combine', async () => {
  const [a, b] = [await newWork(), await newWork()];
  for (const work of [a, b]) {
    await admin().addAssignment(work.id, { email: (await newUser()).email, status: 'accepted', dueDate: date(60) });
  }
  await assert.rejects(admin().createBundle({ title: 'E2E', objectIds: [a.id, b.id] }), (error) => {
    assert.equal(error.status, 409);
    assert.equal(error.code, 'cannot_combine');
    assert.equal(error.body.reason, 'plugins.generic.bookReviewManager.reviewEssay.error.twoReviewers');
    return true;
  });
  assert.equal((await admin().getObject(a.id)).bundleId, null);
});

test('a reviewer of one work becomes the reviewer of the essay', async () => {
  const [a, b] = [await newWork(), await newWork()];
  const reviewer = await newUser();
  await admin().addAssignment(a.id, { email: reviewer.email, status: 'accepted', dueDate: date(60) });
  const bundle = await admin().createBundle({ title: 'E2E', objectIds: [a.id, b.id], hidden: true, copyPreferences: { [b.id]: 'electronic' } });
  assert.equal(bundle.reviewerId, reviewer.id);
  const mirrorB = bundle.members.find((m) => m.id === b.id).assignments.find((m) => m.bundleAssignmentId);
  assert.equal(mirrorB.copyPreference, 'electronic');
});
