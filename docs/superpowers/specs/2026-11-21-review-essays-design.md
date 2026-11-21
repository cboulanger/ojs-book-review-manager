# Review essays: one review of several works

Status: draft for review (2026-11-21)

## Goal

A reviewer can write one review (a review essay) of several works. The process with the reviewer runs once for the whole essay: offer, invitations, bids, contract, due date, reminders, retraction or cancellation, submission and publication. The process with each publisher stays per work: publisher contact, review copy steps and the "notify publisher" step after publication.

## Decisions

1. **How a bundle is made.** There are two ways:
   - The editor creates a review essay offer from several works upfront.
   - The editor combines works later, including a work that already has a reviewer.
2. **What stays per work:** publisher contact, the review copy steps (request, send, receive, return, reminders to the publisher) and the publisher notification. Everything else belongs to the essay.
3. **Model: container work.** The essay is itself an `brm_objects` row (the *container*) that owns the offer. Its works (the *members*) point to it. On the members, the reviewer's progress is mirrored into one assignment per work (the *mirror*), which carries that work's copy status.

Rejected alternatives: repeating every transition on every member (this duplicates bids and invitations per person), and assignments that cover several works (this would mean rewriting CopyWorkflow, the reminders, the API and the data).

## Data model

There are no new tables. The changes are new rows and settings in the existing tables.

### Container

- A row in `brm_objects` with `resource_type = 'reviewEssay'`, `identifier = ''`, `identifier_type = ''`, and `creator` set to the editor.
- It has its own `title` (plain text, e.g. "New books on legal pluralism") and `description` (rich text).
- It holds all offer settings (`ObjectForReview::OFFER_FIELDS`) except the per-work copy settings `publisherContactId`, `journalHoldsCopy`, `returnRequired`, `notifyPublisher` and `publisherNotified`. Those stay on the members and are ignored on a container.
- Bids, invitations, contract offers and the reviewer's assignment belong to the container, so the existing `AssignmentWorkflow` and `InvitationWorkflow` code runs on it unchanged.
- New helpers: `ObjectForReview::isReviewEssay()`, and `ObjectForReviewDAO::getMembers(int $containerId)`, which returns the members in their stored order.

### Member

- A normal work with the new offer setting `bundleId` (the container's object id) and `bundlePosition` (int, for the order in which the works are listed).
- A member has no offer of its own. While `bundleId` is set:
  - it is not on the public list;
  - it cannot be reserved, bid for or have invitations;
  - its offer state is derived from the container (`AssignmentWorkflow::getState()` delegates to the container).
- It keeps its own publisher contact and copy settings.
- A work can belong to at most one container. A container cannot be a member.

### Mirror assignment

- An `brm_assignments` row on the member, for the container's reviewer, with the new setting `bundleAssignmentId` (the container assignment's id).
- It mirrors the container assignment's status: `accepted`, `submitted` and `completed`, and the dropout statuses `retracted` and `cancelled`.
- It holds the per-work contract data needed for the copy:
  - `copyPreference`, `copyNote`, and `shippingAddress` and `phone` (only while this work's copy needs them);
  - the copy fields `copyStatus`, `copyStatusDate`, `copySentBy`, `returnTarget`, `copyReminderSent` and `copyReminderCount`;
  - a copy of `dueDate`, for display only.
- Mirrors are excluded from:
  - due date reminders;
  - the "Overdue reviews" list;
  - `AssignmentDAO::countReviewsByUser()` (the essay counts as one review);
  - the "Reviewers & bidders" page (the container's row stands for them).
- New helper: `Assignment::isMirror()`.

Constants: add `bundleId` and `bundlePosition` to `OFFER_FIELDS`, and `bundleAssignmentId` to `Assignment::FIELDS`. No schema migration is needed, because settings rows are generic. The table code stays the same, and `SCHEMA_VERSION` is not raised.

## Workflow

### New class `classes/BundleWorkflow.php`

It owns all logic that is specific to bundles:

| Method | What it does |
| --- | --- |
| `create($context, array $objectIds, string $title, ?string $description, User $editor, array $offer)` | Creates the container and adds the works (see below). |
| `addMember(ObjectForReview $container, ObjectForReview $work, User $editor, ?array $copy)` | Adds one work. |
| `removeMember(ObjectForReview $container, ObjectForReview $work, User $editor)` | Removes one work. |
| `dissolve(ObjectForReview $container, User $editor)` | Removes all members and deletes the container. |
| `syncMembers(ObjectForReview $container)` | Brings mirrors in line with the container's reviewer assignment. |
| `getContainer(ObjectForReview $work): ?ObjectForReview` | Returns the work's container, if any. |

### Shared steps (the container drives them)

`AssignmentWorkflow` calls `BundleWorkflow::syncMembers()` at the end of every transition that changes a reviewer status: `reserve`, `acceptContract`, `cancelByEditor`, `retract`, `attach`, `detach` and `complete`. `syncMembers` does the following:

- **Container reviewer becomes `accepted`.** It creates a mirror on every member that has none for this reviewer. It copies `dueDate`, `shippingAddress`, `phone` and the member's `copyPreference` (see "Contract form") onto the mirror, then calls `CopyWorkflow::init()` on it.
- **Container reviewer becomes `retracted` or `cancelled`.** It sets every active mirror to the same status, then calls `CopyWorkflow::onAssignmentEnded()` and `Reservation::erasePersonalData()` on it. Physical copies already sent follow the existing return steps per work.
- **Container becomes `submitted`, `completed`, or `accepted` again after a detach.** It applies the same status to the mirrors.
- **Member `user_id`.** `syncReviewer()` runs for each member, so `user_id` follows the reviewer.

The audit trail logs each event on the container. Mirror changes are logged on the member with the existing event types and an extra `reviewEssay` parameter (the container title). This way, each work's own history stays complete.

### Per-work steps (unchanged code, run on the mirror)

The following run with `(member, mirror)` instead of `(object, assignment)`, so their code stays the same:

- `CopyWorkflow::apply()`, `confirmReceipt()` and the copy emails;
- the publisher reminders;
- the "Notify publisher" step after publication.

The To-do page labels copy rows of mirrors as "*work citation* (review essay: *container title*)".

### Contract form (reservation, contract offer, invitation answer)

For a container, the form asks for the following:

- **Copy preference, per work:** `have`, `physical`, `electronic` or `other`. Each work gets one row with its citation.
- **Delivery address and phone, once.** They are shown if any work is `physical` and stored on the mirrors that need them.
- **Due date, once.** The due date limits apply to the essay as a whole.

The container assignment stores the per-work answers as `copyPreferences` (a JSON map from object id to preference) until `syncMembers` copies them onto the mirrors. `Reservation::FIELDS` gets the new field `copyPreferences`. `Reservation::getEmailDetails()` lists the preference per work for a container.

Personal data is erased:

- from each mirror, by the existing copy steps (when the copy is received, or at submission);
- from the container assignment, as soon as the mirrors exist.

### Combining existing works

The editor uses *Available works › Combine into review essay* (several works selected) or *Add to review essay…* on one work. Combining is allowed only if all of the following hold:

1. No work is a container, a member of another container, or attached to a submission.
2. At most one of the works has a reviewer (an `accepted` assignment). If two or more works have different reviewers, combining is refused with an explanation.
3. No work has a pending contract offer (`offered`) or a running sequential invitation round.

Combining does the following:

- **Open lists on members.** Open bids and invitations on the members are discarded or withdrawn. These are `AssignmentWorkflow::discardList()` and `InvitationWorkflow::endAll()`, which already exist. The confirmation dialog warns the editor about this first.
- **When one work has a reviewer:**
  - The container gets a new `accepted` assignment for that reviewer, with the reviewer's contract data (due date and delivery details).
  - The existing assignment of that work becomes that work's mirror: it gets `bundleAssignmentId` and keeps its copy status.
  - The other works get new mirrors. For each new work, the editor sets the copy preference in the combine dialog.
  - The reviewer is sent an editable email ("Your review now covers several works"), with the list of works and the due date. The editor can change the due date in the same dialog.
- **The offer settings** (mode, responsible editor, hidden, bid deadline) are copied from the first selected work. The editor can change them in the dialog.

### Adding and removing a work later

- **Add.** Possible until the essay is submitted, under the same rules as combining (the work itself must have no reviewer). If the essay already has a reviewer, a mirror is created right away, and the reviewer is sent an editable email.
- **Remove.** Possible until the essay is submitted:
  - The work's mirror is set to `cancelled`. Its copy follows the existing return steps.
  - `bundleId` is cleared, and the work becomes an ordinary offer again, open and hidden. The editor decides whether to show it.
  - An essay must keep at least one member.
- **Dissolve.** Possible only while the essay has no reviewer. Every work is removed, and the container is deleted. Its bids and invitations are discarded first, as when an offer is closed.
- **Deleting a container** is the same as dissolving it. **Deleting a member** removes it from the essay first.

## Submission and publication

- **Submission wizard** (*For the Editors › Reviewed works*). The reviewer picks the essay as one entry: "Review essay: *title* (*n* works)". Attaching it does the following:
  - sets `submission_id` on the container and on every member;
  - moves the container assignment and the mirrors to `submitted`.

  Removing the entry reverses this (`detach`). A member on its own cannot be picked or removed.
- **Article page and "Reviewed works" page.** They show the members (the reviewed works) and never the container. The essay title is not shown as a reviewed work.
- **Publication** (`completeOnPublish`). It completes the container. `syncMembers` then completes the mirrors. The bidder list of the container is discarded as before.
- **Notify publishers.** One row per member that has `notifyPublisher` set, as before.

## Interface

- **Public list.**
  - The container is one entry, with its title, description and the list of its works. Each work links to its identifier, as works do today.
  - Reserve, bid and the invitation links act on the container.
  - Members are not listed separately.
- **Backend, "Available works".**
  - Containers are shown with their members indented beneath them.
  - Actions on a container: *Edit*, *Add work*, *Dissolve*, and the existing offer actions.
  - Actions on a member: *Edit* (bibliographic data and copy settings) and *Remove from review essay*.
  - New dialogs: *Combine into review essay* and *Add to review essay…*.
- **To-do.**
  - Shared items (contract offers, invitations, overdue reviews) appear once, for the container.
  - Copy items appear per work.
- **"Reviewers & bidders".** It shows the container's assignments only.
- **Emails.**
  - `ObjectForReview::getCitation()` on a container returns the title followed by the members' citations, one per line, so existing templates that use the citation work.
  - Copy emails use the member's citation.
  - One new template: `BRM_REVIEW_ESSAY_CHANGED`, for works added to or removed from a reviewer's essay. It is added in `emailTemplates.xml` and in `locale/{en,de,fi}/emails.po`.

## API

- `POST /bookReviewManager/bundles` takes `{title, description?, objectIds[], assignmentMode?, responsibleEditorId?, hidden?}` and creates a container from existing works, under the same rules as combining. It returns the container.
- `POST /bookReviewManager/bundles/{id}/members` takes `{objectId, copyPreference?}`. `DELETE /bookReviewManager/bundles/{id}/members/{objectId}` removes a member.
- `GET` of an object includes `isReviewEssay`, `bundleId` and, for a container, `members[]`.
- `POST /bookReviewManager/{containerId}/assignments` (importing a running process) accepts `copies: [{objectId, copyPreference, copyStatus?, ...}]`. A mirror is created for each work.
- Reserving or importing an assignment on a member returns `409 member_of_review_essay`.
- The Node client `ofr` gets `bundle create|add|remove`. `skills/book-review-manager-api/SKILL.md` and `skills/book-review-manager-email-import/SKILL.md` document review essays: an email thread about several books becomes a container.

## Error handling

- Every bundle operation runs in a database transaction, because it touches several rows.
- Every transition checks the container's state again before acting. Stale forms fail with the existing "status changed" message.
- One-click links on a member always resolve to the mirror. Links for shared steps (contract, invitation, "I cannot write this review") are always created for the container assignment.
- If `syncMembers` finds a member that has no mirror while the container has a reviewer, it creates the mirror. Running `syncMembers` twice has the same effect as running it once.

## Out of scope

- Reviewers combining works themselves. They ask the editor, who combines them.
- Different due dates per work.
- Splitting a submitted essay into separate reviews.
- Nested containers.

## Testing

- **PHPUnit, `BundleWorkflow`:**
  - create;
  - combine with and without a reviewer;
  - refusal for two reviewers;
  - add and remove a member, with and without a reviewer;
  - dissolve;
  - running `syncMembers` twice changes nothing.
- **PHPUnit, the transitions on a container:**
  - reserve: mirrors are created with the per-work copy preference;
  - retract and cancel: the mirrors end, and their copies follow the return steps;
  - attach, detach and complete;
  - personal data is erased.
- **PHPUnit, `ReservationReminder`:**
  - no due date reminder for mirrors;
  - publisher reminders do run for mirrors.
- **API end to end:**
  - create a bundle;
  - import a running process with `copies`;
  - 409 on members.
- **Node client:** unit tests for `bundle` commands.
- **Manual check in the dev container:** create an essay of two works, reserve it with one physical and one electronic copy, run the copy steps per work, submit, publish, and notify both publishers.

## Documentation

- `docs/workflow.md`:
  - a new section "Review essays";
  - the overview flowchart gets the container/member split;
  - the statuses reference mentions mirrors.
- `README.md`: one line in "Changed or new in this fork".
- New strings in `locale/{en,de,fi}/locale.po`.
