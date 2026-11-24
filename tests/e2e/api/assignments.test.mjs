// Review processes under way: importing bidders and reviewers
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { admin, newBiddingWork, newPublisherContact, newUser, newWork, rejects } from './fixtures.mjs';
import { date, email, history, mailsSince, now, sql } from '../../lib/ojs.mjs';

const addAssignment = (work, data) => admin().addAssignment(work.id, data);

test('an accepted reviewer: the work is assigned, the copy is pending, nothing is emailed', async () => {
  const since = now();
  const work = await newWork();
  const reviewer = await newUser();
  const assignment = await addAssignment(work, {
    email: reviewer.email, status: 'accepted', dateCreated: '2026-09-01', dueDate: '2027-01-31',
    copyPreference: 'physical', shippingAddress: 'Reviewer\nStreet 1', phone: '+49 30 123',
  });
  assert.equal(assignment.status, 'accepted');
  assert.equal(assignment.userId, reviewer.id);
  assert.equal(assignment.dateCreated, '2026-09-01 00:00:00');
  assert.equal(assignment.dateReserved, '2026-09-01 00:00:00');
  assert.equal(assignment.dueDate, '2027-01-31');
  assert.equal(assignment.copyPreference, 'physical');
  assert.equal(assignment.copyStatus, 'pending');
  assert.equal(assignment.shippingAddress, 'Reviewer\nStreet 1');

  const updated = await admin().getObject(work.id);
  assert.equal(updated.state, 'assigned');
  assert.equal(updated.reviewerId, reviewer.id);
  assert.equal(updated.assignments.length, 1);

  const events = history(work.id);
  assert.equal(events.length, 1);
  assert.equal(events[0].message, 'plugins.generic.bookReviewManager.history.event.imported');
  assert.equal(events[0].recipientName, reviewer.fullName);
  assert.equal(events[0].userFullName, sql("SELECT CONCAT(s1.setting_value, ' ', s2.setting_value) AS n FROM users u JOIN user_settings s1 ON s1.user_id = u.user_id AND s1.setting_name = 'givenName' JOIN user_settings s2 ON s2.user_id = u.user_id AND s2.setting_name = 'familyName' WHERE u.username = 'admin' LIMIT 1")[0].n);

  const mails = mailsSince(since);
  assert.deepEqual(mails.filter((mail) => mail.to.includes(reviewer.email)), [], 'no email to the reviewer');
});

test('accepted: due date required, copy preference and status validated', async () => {
  const work = await newWork();
  const reviewer = await newUser();
  await rejects(addAssignment(work, { email: reviewer.email, status: 'accepted' }), 400, 'dueDateRequired');
  await rejects(addAssignment(work, { email: reviewer.email, status: 'accepted', dueDate: '2027-13-01' }), 400, 'invalidDate');
  await rejects(addAssignment(work, { email: reviewer.email, status: 'accepted', dueDate: '2027-01-01', copyPreference: 'pdf' }), 400, 'invalidCopyPreference');
  await rejects(addAssignment(work, { email: reviewer.email, status: 'accepted', dueDate: '2027-01-01', copyStatus: 'lost' }), 400, 'invalidCopyStatus');
  await rejects(addAssignment(work, { email: reviewer.email, status: 'accepted', dueDate: '2027-01-01', copyPreference: 'physical' }), 400, 'shippingAddressRequired');
  await rejects(addAssignment(work, { email: reviewer.email, status: 'accepted', dueDate: '2027-01-01', copyStatus: 'received', copySentBy: 'courier' }), 400, 'invalidCopySentBy');
  await rejects(addAssignment(work, { email: reviewer.email, status: 'accepted', dueDate: '2027-01-01', copyStatus: 'returnRequested', returnTarget: 'library' }), 400, 'invalidReturnTarget');
  assert.equal((await admin().getObject(work.id)).assignments.length, 0, 'nothing was saved');
});

test('accepted: an overdue review and a copy that has already arrived', async () => {
  const work = await newWork();
  const assignment = await addAssignment(work, {
    email: (await newUser()).email, status: 'accepted', dueDate: date(-10),
    copyPreference: 'physical', copyStatus: 'received', copySentBy: 'publisher', copyStatusDate: '2026-10-01',
  });
  assert.equal(assignment.dueDate, date(-10));
  assert.equal(assignment.copyStatus, 'received');
  assert.equal(assignment.copySentBy, 'publisher');
  assert.equal(assignment.copyStatusDate, '2026-10-01 00:00:00');
  assert.equal(assignment.shippingAddress, null, 'no address needed once the copy has arrived');
});

test('copy defaults: none needed without a copy, pending for electronic copies', async () => {
  const have = await addAssignment(await newWork(), { email: (await newUser()).email, status: 'accepted', dueDate: date(60) });
  assert.equal(have.copyPreference, 'have');
  assert.equal(have.copyStatus, 'notNeeded');
  const electronic = await addAssignment(await newWork(), { email: (await newUser()).email, status: 'accepted', dueDate: date(60), copyPreference: 'electronic' });
  assert.equal(electronic.copyStatus, 'pending');
  const other = await addAssignment(await newWork(), { email: (await newUser()).email, status: 'accepted', dueDate: date(60), copyPreference: 'other', copyNote: 'Library' });
  assert.equal(other.copyNote, 'Library');
});

test('one reviewer per work', async () => {
  const work = await newWork();
  await addAssignment(work, { email: (await newUser()).email, status: 'accepted', dueDate: date(30) });
  await rejects(addAssignment(work, { email: (await newUser()).email, status: 'accepted', dueDate: date(30) }), 409, 'reviewerExists');
  await rejects(addAssignment(work, { email: (await newUser()).email, status: 'completed' }), 409, 'reviewerExists');
});

test('the same person cannot be imported twice for a work', async () => {
  const work = await newBiddingWork();
  const bidder = await newUser();
  const bid = await addAssignment(work, { email: bidder.email, status: 'bid' });
  await assert.rejects(addAssignment(work, { email: bidder.email, status: 'unsuccessful' }),
    (error) => error.status === 409 && error.code === 'duplicateAssignment' && error.body.assignmentId === bid.id);
});

test('bids: only for bidding works; the statement is kept; bidding stays open', async () => {
  await rejects(addAssignment(await newWork(), { email: (await newUser()).email, status: 'bid' }), 400, 'notBidding');
  const work = await newBiddingWork();
  const bid = await addAssignment(work, { email: (await newUser()).email, status: 'bid', statement: '  My field.  ', dateCreated: '2026-10-01 09:30:00' });
  assert.equal(bid.statement, 'My field.');
  assert.equal(bid.dateCreated, '2026-10-01 09:30:00');
  await addAssignment(work, { email: (await newUser()).email, status: 'unsuccessful' });
  const updated = await admin().getObject(work.id);
  assert.equal(updated.state, 'open');
  assert.equal(updated.biddingClosed, null);
});

test('contract offered: closes bidding, one at a time, deadline from settings by default', async () => {
  const work = await newBiddingWork();
  await addAssignment(work, { email: (await newUser()).email, status: 'bid' });
  const offer = await addAssignment(work, { email: (await newUser()).email, status: 'offered', contractDeadline: date(5) });
  assert.equal(offer.contractDeadline, date(5));
  assert.ok(offer.dateOffered);
  const updated = await admin().getObject(work.id);
  assert.equal(updated.state, 'contractOffered');
  assert.equal(updated.biddingClosed, true);
  await rejects(addAssignment(work, { email: (await newUser()).email, status: 'offered' }), 409, 'offerExists');

  const defaulted = await addAssignment(await newBiddingWork(), { email: (await newUser()).email, status: 'offered' });
  assert.ok(defaulted.contractDeadline > date(0), 'a deadline in the future');
  await rejects(addAssignment(await newWork(), { email: (await newUser()).email, status: 'offered' }), 400, 'notBidding');
});

test('an accepted reviewer on a bidding work closes bidding', async () => {
  const work = await newBiddingWork();
  await addAssignment(work, { email: (await newUser()).email, status: 'accepted', dueDate: date(90) });
  const updated = await admin().getObject(work.id);
  assert.equal(updated.state, 'assigned');
  assert.equal(updated.biddingClosed, true);
});

test('submitted and completed reviews', async () => {
  const completed = await addAssignment(await newWork(), {
    email: (await newUser()).email, status: 'completed', dateCreated: '2025-03-01', dateStatus: '2025-10-01 12:00:00',
  });
  assert.equal(completed.status, 'completed');
  assert.equal(completed.dateStatus, '2025-10-01 12:00:00');
  assert.equal(completed.copyStatus, 'notNeeded');

  // Without submissionId the work can't be attached: recorded as accepted, with a warning, to be linked in OJS
  const work = await newWork();
  const unlinked = await addAssignment(work, {
    email: (await newUser()).email, status: 'submitted', dateStatus: '2026-04-30', copyPreference: 'physical', shippingAddress: 'Not kept', phone: '123',
  });
  assert.equal(unlinked.status, 'accepted');
  assert.equal(unlinked.dueDate, '2026-04-30', 'due since it was submitted');
  assert.equal(unlinked.copyStatus, 'received', 'a requested copy has arrived');
  assert.equal(unlinked.shippingAddress, null, 'delivery details are not kept');
  assert.equal(unlinked.phone, null);
  assert.deepEqual(unlinked.warnings.map((w) => w.code), ['submittedWithoutSubmission']);
  assert.equal((await admin().getObject(work.id)).state, 'assigned');
  await rejects(addAssignment(await newWork(), { email: (await newUser()).email, status: 'submitted', submissionId: 999999999 }), 400, 'submissionNotFound');
});

test('submitted with a submission: the work is attached to it', async (t) => {
  const [submission] = sql('SELECT submission_id FROM submissions WHERE context_id = 1 ORDER BY submission_id DESC LIMIT 1');
  if (!submission) {
    t.skip('the dev journal has no submission');
    return;
  }
  const work = await newWork();
  await addAssignment(work, { email: (await newUser()).email, status: 'submitted', submissionId: Number(submission.submission_id) });
  const updated = await admin().getObject(work.id);
  assert.equal(updated.submissionId, Number(submission.submission_id));
  assert.equal(updated.state, 'submitted');
});

test('the reviewer: by id, by email, or created from the record', async () => {
  const existing = await newUser();
  assert.equal((await addAssignment(await newWork(), { userId: existing.id, status: 'completed' })).userId, existing.id);

  const address = email('new-reviewer');
  const created = await addAssignment(await newWork(), { email: address, givenName: 'Nora', familyName: 'New', status: 'completed' });
  const user = await admin().findUserByEmail(address);
  assert.equal(created.userId, user.id);
  assert.deepEqual(user.roles, ['author'], 'reviewers become authors');

  await rejects(addAssignment(await newWork(), { email: email('unknown'), status: 'completed' }), 400, 'givenNameRequired');
  await rejects(addAssignment(await newWork(), { userId: 999999999, status: 'completed' }), 400, 'userNotFound');
  await rejects(addAssignment(await newWork(), { status: 'completed' }), 400, 'userRequired');
});

test('a publisher contact can be imported as a reviewer and keeps both roles', async () => {
  const contact = await newPublisherContact();
  await addAssignment(await newWork(), { userId: contact.id, status: 'completed' });
  assert.deepEqual((await admin().findUserByEmail(contact.email)).roles.sort(), ['author', 'publisherContact']);
});

test('statuses that cannot be imported', async () => {
  for (const status of ['declined', 'cancelled', 'invited', 'listed', '', undefined]) {
    await rejects(addAssignment(await newWork(), { email: (await newUser()).email, status }), 400, 'invalidStatus');
  }
  await rejects(admin().addAssignment(999999999, { email: 'x@example.org', status: 'bid' }), 404, 'objectNotFound');
});

test('list assignments', async () => {
  const work = await newBiddingWork();
  const a = await addAssignment(work, { email: (await newUser()).email, status: 'bid' });
  const b = await addAssignment(work, { email: (await newUser()).email, status: 'unsuccessful' });
  const list = await admin().listAssignments(work.id);
  assert.deepEqual(list.map((x) => x.id).sort(), [a.id, b.id].sort());
  assert.ok(list.every((x) => x.userEmail && x.userName));
});
