# Review Essays Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** One reviewer can review several works in one review essay. The steps with the reviewer move together, and the copy steps with each publisher stay per work.

**Architecture:** A review essay is a *container* row in `brm_objects` (`resource_type = 'reviewEssay'`). The container owns the offer, so the existing offer, bid, invitation and contract code runs on it unchanged. *Member* works point to it with the offer setting `bundleId`. On every member, the container's reviewer assignment is mirrored by a *mirror* assignment (setting `bundleAssignmentId`), which carries that work's copy status. The new class `BundleWorkflow` creates and syncs the mirrors.

**Tech Stack:** PHP 8.2, OJS 3.5 (PKP DAO and settings tables, Smarty templates, PKP grids and forms), PHPUnit 11.5 in the dev container, Node 20 (`node --test`) for the API client and the e2e tests.

**Spec:** `docs/superpowers/specs/2026-11-21-review-essays-design.md` (also published as cboulanger/bookReviewManager#4).

## Global Constraints

- PHP files use tabs, the namespace `APP\plugins\generic\bookReviewManager\classes` (or the matching subnamespace), and the existing file header style.
- There are no new tables, and `SCHEMA_VERSION` is not raised. Everything new is stored in the existing settings tables.
- Every user-visible string goes into `locale/en/locale.po`, `locale/de/locale.po` and `locale/fi/locale.po`. Email templates go into `emailTemplates.xml` and `locale/{en,de,fi}/emails.po`. `tests/unit/php/LocaleTest.php` checks that the locales are complete.
- In the interface, the word is "work" (not "object") and "review essay" (de: "Sammelrezension", fi: "kooste-arvio").
- Run the tests against the worktree container:
  `OFR_CONTAINER=ojs-ofr-essays OJS_PORT=8081 OFR_E2E_OFFLINE=1 tests/run.sh <suite>`.
  The container `ojs-ofr-essays` mounts this worktree. The user's container `ojs-bookreviewmanager` must not be touched.
- Every commit message ends with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Do not edit `api/SKILL.md`, `README.md` or anything under `skills/`. The user has uncommitted changes to these files in the main checkout. Task 9 handles the documentation.

## File structure

| File | Responsibility |
| --- | --- |
| `classes/ObjectForReview.php` (modify) | `RESOURCE_TYPE_REVIEW_ESSAY`, `isReviewEssay()`, `getBundleId()`, new offer fields, and the container citation |
| `classes/Assignment.php` (modify) | `bundleAssignmentId` field and `isMirror()` |
| `classes/ObjectForReviewDAO.php` (modify) | `getMembers()` and `getContainers()` |
| `classes/AssignmentDAO.php` (modify) | `getMirrors()`; `countReviewsByUser()` leaves out mirrors |
| `classes/BundleWorkflow.php` (create) | Creating and changing bundles, `syncMembers()`, the combine rules |
| `classes/AssignmentWorkflow.php` (modify) | Calls `syncMembers`; members delegate their state to the container |
| `classes/InvitationWorkflow.php` (modify) | Members cannot have invitations |
| `classes/Reservation.php` (modify) | Copy preference per work for containers |
| `classes/ReservationReminder.php` (modify) | No due date reminders for mirrors |
| `classes/ReservationLog.php` (modify) | New events |
| `classes/ReservationEmailEventType.php`, `mailables/ObjectsForReviewEssayChanged.php`, `emailTemplates.xml` | The email `BRM_REVIEW_ESSAY_CHANGED` |
| `pages/ObjectsForReviewHandler.php`, `templates/frontend/*` | Public list and contract form |
| `controllers/grid/*`, `controllers/grid/form/ReviewEssayForm.php` (create), `controllers/form/BundleMemberForm.php` (create), `templates/reviewEssayForm.tpl`, `templates/bundleMemberForm.tpl` | Backend "Available works" |
| `pages/ObjectsForReviewManagementHandler.php`, `templates/management.tpl` | To-do and "Reviewers & bidders" |
| `BookReviewManagerPlugin.php`, `controllers/grid/ObjectsForReviewGridHandler.php`, `controllers/grid/form/ObjectsForReviewForm.php` | Submission and publication |
| `api/ObjectsForReviewController.php`, `api/src/client.js`, `api/bin/ofr.js` | API |
| `tests/unit/php/BundleWorkflowTest.php` (create), `tests/e2e/api/bundles.test.mjs` (create) | Tests |

---

### Task 1: Model: containers, members, mirrors

**Files:**
- Modify: `classes/ObjectForReview.php`, `classes/Assignment.php`, `classes/ObjectForReviewDAO.php`, `classes/AssignmentDAO.php`, `classes/Reservation.php` (only `FIELDS`)
- Test: `tests/unit/php/ObjectForReviewTest.php`, `tests/unit/php/BundleWorkflowTest.php` (new, `db` group)

**Interfaces (Produces):**
- `ObjectForReview::RESOURCE_TYPE_REVIEW_ESSAY = 'reviewEssay'`
- `ObjectForReview::isReviewEssay(): bool`
- `ObjectForReview::getBundleId(): ?int`
- `ObjectForReview::isBundleMember(): bool`
- `ObjectForReview::OFFER_FIELDS` additionally contains `'bundleId'` and `'bundlePosition'`.
- `ObjectForReview::getCitation()` for a container returns `"<title> (<member citation>; <member citation>)"`. With no members, it returns just the title.
- `Assignment::FIELDS` additionally contains `'bundleAssignmentId'`.
- `Assignment::isMirror(): bool`
- `Assignment::getBundleAssignmentId(): ?int`
- `Reservation::FIELDS` additionally contains `'copyPreferences'` (an array: object id => preference).
- `ObjectForReviewDAO::getMembers(int $containerId): ObjectForReview[]` returns the members ordered by `bundlePosition`, then by object id.
- `AssignmentDAO::getMirrors(int $bundleAssignmentId): Assignment[]`
- `AssignmentDAO::countReviewsByUser()` leaves out mirrors.

- [ ] **Step 1: Write the failing unit tests.** Append to `tests/unit/php/ObjectForReviewTest.php`:

```php
	public function testReviewEssayFlags(): void {
		$container = $this->object(['resourceType' => ObjectForReview::RESOURCE_TYPE_REVIEW_ESSAY, 'identifier' => '', 'identifierType' => '']);
		$this->assertTrue($container->isReviewEssay());
		$this->assertNull($container->getBundleId());
		$member = $this->object(['bundleId' => '7']);
		$this->assertFalse($member->isReviewEssay());
		$this->assertTrue($member->isBundleMember());
		$this->assertSame(7, $member->getBundleId());
	}
```

Also add to `tests/unit/php/AssignmentWorkflowTest.php`, or to whichever test already covers `Assignment`:

```php
	public function testMirrorFlag(): void {
		$this->assertFalse($this->assignment(Assignment::STATUS_ACCEPTED)->isMirror());
		$mirror = $this->assignment(Assignment::STATUS_ACCEPTED, ['bundleAssignmentId' => 12]);
		$this->assertTrue($mirror->isMirror());
		$this->assertSame(12, $mirror->getBundleAssignmentId());
	}
```

- [ ] **Step 2: Write the failing database test.** Create `tests/unit/php/BundleWorkflowTest.php`. It has a `#[Group('db')]` attribute, follows the cleanup pattern of `ActionLinkTest.php`, and starts with these helpers, which later tasks reuse:

```php
<?php

/**
 * @file tests/unit/php/BundleWorkflowTest.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @brief Review essays: containers, members and mirror assignments (writes to the database).
 */

namespace APP\plugins\generic\bookReviewManager\tests\unit;

use APP\plugins\generic\bookReviewManager\classes\Assignment;
use APP\plugins\generic\bookReviewManager\classes\AssignmentDAO;
use APP\plugins\generic\bookReviewManager\classes\ObjectForReview;
use APP\plugins\generic\bookReviewManager\classes\ObjectForReviewDAO;
use PHPUnit\Framework\Attributes\Group;

#[Group('db')]
class BundleWorkflowTest extends TestCase {

	/** @var int[] Object ids to delete in tearDown */
	protected array $created = [];

	protected function tearDown(): void {
		$dao = new ObjectForReviewDAO();
		foreach ($this->created as $id) {
			$dao->deleteById($id);
		}
		$this->created = [];
	}

	protected function newWork(string $title, array $data = []): ObjectForReview {
		$dao = new ObjectForReviewDAO();
		$work = $dao->newDataObject();
		$work->setContextId(self::CONTEXT_ID);
		$work->setIdentifier('978' . random_int(1000000000, 9999999999));
		$work->setIdentifierType('isbn');
		$work->setResourceType('http://purl.org/coar/resource_type/c_2f33');
		$work->setCreator('manager');
		$work->setTitle($title);
		$work->setAuthors('Author ' . $title);
		$work->setOfferSetting('hidden', true);
		foreach ($data as $name => $value) {
			$work->setOfferSetting($name, $value);
		}
		$dao->insertObject($work);
		$this->created[] = $work->getId();
		return $work;
	}

	protected function newAssignment(ObjectForReview $object, string $status, int $userId = 1, array $data = []): Assignment {
		$dao = new AssignmentDAO();
		$assignment = $dao->newDataObject();
		$assignment->setObjectId($object->getId());
		$assignment->setContextId(self::CONTEXT_ID);
		$assignment->setUserId($userId);
		$assignment->setStatus($status);
		foreach ($data as $name => $value) {
			$assignment->setData($name, $value);
		}
		$dao->insertObject($assignment);
		return $assignment;
	}

	public function testMembersInOrderAndContainerCitation(): void {
		$container = $this->newWork('Essay', ['resourceType' => null]);
		$container->setResourceType(ObjectForReview::RESOURCE_TYPE_REVIEW_ESSAY);
		$container->setIdentifier('');
		$container->setIdentifierType('');
		(new ObjectForReviewDAO())->updateObject($container);
		$b = $this->newWork('B', ['bundleId' => $container->getId(), 'bundlePosition' => 2]);
		$a = $this->newWork('A', ['bundleId' => $container->getId(), 'bundlePosition' => 1]);

		$members = (new ObjectForReviewDAO())->getMembers($container->getId());
		$this->assertSame([$a->getId(), $b->getId()], array_map(fn ($m) => $m->getId(), $members));
		$this->assertSame('Essay (Author A: A; Author B: B)', $container->getCitation());
	}

	public function testMirrorsAreNotCountedAsReviews(): void {
		$work = $this->newWork('W');
		$this->newAssignment($work, Assignment::STATUS_COMPLETED, 1, ['bundleAssignmentId' => 999999]);
		$before = (new AssignmentDAO())->countReviewsByUser(1, self::CONTEXT_ID);
		$this->newAssignment($work, Assignment::STATUS_COMPLETED, 1);
		$this->assertSame($before + 1, (new AssignmentDAO())->countReviewsByUser(1, self::CONTEXT_ID));
	}
}
```

Note: `setOfferSetting('resourceType', null)` above is a no-op, kept for readability. The resource type is a column and is set directly.

- [ ] **Step 3: Run the tests and check that they fail.**
  Run: `OFR_CONTAINER=ojs-ofr-essays OJS_PORT=8081 tests/run.sh unit/php -- --filter 'ReviewEssay|Mirror|Members'`
  Expected: FAIL. The new methods do not exist yet.

- [ ] **Step 4: Implement.**
  - In `ObjectForReview`:
    - add the constant and the two fields to `OFFER_FIELDS`, documented in the docblock above it:
      - `bundleId`: the review essay (container) this work belongs to;
      - `bundlePosition`: its place in the list.
    - add the methods:

```php
	const RESOURCE_TYPE_REVIEW_ESSAY = 'reviewEssay';

	function isReviewEssay(): bool {
		return $this->getResourceType() === self::RESOURCE_TYPE_REVIEW_ESSAY;
	}

	function getBundleId(): ?int {
		return $this->getData('bundleId') ? (int) $this->getData('bundleId') : null;
	}

	function isBundleMember(): bool {
		return $this->getBundleId() !== null;
	}
```

  - In `getCitation()`, add this at the top:

```php
		if ($this->isReviewEssay()) {
			$citations = array_map(fn (ObjectForReview $m) => $m->getCitation(), (new ObjectForReviewDAO())->getMembers((int) $this->getId()));
			return $citations ? $this->getTitle() . ' (' . implode('; ', $citations) . ')' : (string) $this->getTitle();
		}
```

  - In `Assignment`:
    - add `'bundleAssignmentId'` to `FIELDS`;
    - add `isMirror()`, which returns `(bool) $this->getData('bundleAssignmentId')`;
    - add `getBundleAssignmentId(): ?int`.
  - In `Reservation::FIELDS`, add `'copyPreferences'`.
  - In `ObjectForReviewDAO`, add:

```php
	/**
	 * Works of a review essay, in their order.
	 * @return ObjectForReview[]
	 */
	function getMembers(int $containerId): array {
		$ids = DB::table('brm_object_settings')
			->where('setting_name', 'bundleId')
			->where('setting_value', (string) $containerId)
			->pluck('object_id')->all();
		$members = array_values(array_filter(array_map(fn ($id) => $this->getById((int) $id), $ids)));
		usort($members, fn ($a, $b) => ((int) $a->getData('bundlePosition') <=> (int) $b->getData('bundlePosition'))
			?: ($a->getId() <=> $b->getId()));
		return $members;
	}
```

  - In `AssignmentDAO`, add `getMirrors()`:

```php
	public function getMirrors(int $bundleAssignmentId): array {
		$ids = DB::table(self::SETTINGS_TABLE)->where('setting_name', 'bundleAssignmentId')
			->where('setting_value', (string) $bundleAssignmentId)->pluck('assignment_id')->all();
		return $ids ? $this->toObjects(DB::table(self::TABLE)->whereIn('assignment_id', $ids)->orderBy('assignment_id')->get()) : [];
	}
```

  - In `countReviewsByUser()`, add `->whereNotIn('assignment_id', DB::table(self::SETTINGS_TABLE)->where('setting_name', 'bundleAssignmentId')->select('assignment_id'))`.

- [ ] **Step 5: Run the tests and check that they pass.**
  Run: `OFR_CONTAINER=ojs-ofr-essays OJS_PORT=8081 tests/run.sh unit/php`
  Expected: all pass.

- [ ] **Step 6: Commit.**
  `git commit -m "Review essays: containers, members and mirror assignments in the model"`

---

### Task 2: `BundleWorkflow::syncMembers` and the shared transitions

**Files:**
- Create: `classes/BundleWorkflow.php`
- Modify: `classes/AssignmentWorkflow.php`, `classes/InvitationWorkflow.php`, `classes/ReservationReminder.php`, `classes/ReservationLog.php`, `locale/{en,de,fi}/locale.po`
- Test: `tests/unit/php/BundleWorkflowTest.php`, `tests/unit/php/TestCase.php` (`FakePlugin::notifyEditor` no-op)

**Interfaces:**
- Consumes: everything from Task 1.
- Produces:
  - `BundleWorkflow::getContainer(ObjectForReview $work): ?ObjectForReview`
  - `BundleWorkflow::syncMembers(ObjectForReview $container): void` (running it twice changes nothing)
  - `BundleWorkflow::MIRRORED_STATUSES = [accepted, submitted, completed, retracted, cancelled]`
  - `AssignmentWorkflow::getState()` delegates for members.
  - `isReservable()` and `isBiddingOpen()` return false for members.
  - `InvitationWorkflow::canInvite()` and `canAdd()` return false for members.

**What `syncMembers` does.** The reviewer assignment is the newest container assignment whose status is in `MIRRORED_STATUSES`.

- *No such assignment:* do nothing.
- *Reviewer is `accepted`:* for each member, look for a mirror of that reviewer (`bundleAssignmentId` = the reviewer assignment's id).
  - If there is none, create an `accepted` mirror with these fields:
    - `dueDate` and `dateReserved` from the container assignment;
    - `copyPreference` = `copyPreferences[memberId]`, or `have` if missing;
    - `shippingAddress` and `phone`, only if the preference is `physical`;
    - `copyNote`, only if the preference is `other`.

    Then run `CopyWorkflow::init($mirror)`.
  - If the mirror exists in the status `submitted`, set it back to `accepted` (this is a detach).
  - Copy the `dueDate` onto the mirror.
  - After the mirrors are created, unset `shippingAddress`, `phone` and `copyPreferences` on the container assignment, because they now live on the mirrors.
- *Reviewer is `submitted` or `completed`:* set mirrors that are in `REVIEWER_STATUSES` to the same status. For `submitted`, also run `Reservation::erasePersonalData()` on the mirror.
- *Reviewer is `retracted` or `cancelled`:* for every mirror in the status `accepted`, run `Reservation::erasePersonalData`, `CopyWorkflow::onAssignmentEnded`, and set the same status. Then invalidate the mirror's links with `ActionLink::invalidate($mirror->getId(), ActionLink::ACTION_RETRACT)`. Copy links stay valid, because the return steps continue.
- *Always:* set every member's `submission_id` to the container's (null when detached), then run `AssignmentWorkflow::syncReviewer($member)`.
- *Logging:* every status change of a mirror is logged on the member with the matching existing event, and `['message' => ...]` stays the default. Use the events below, with `$actor = null` and `$reviewer` = the reviewer user. Two new events are needed:
  - `EVENT_ESSAY_MIRRORED` (`0x1F0F233`, "Review essay: {$essay} — the reviewer's agreement covers this work"), logged when a mirror is created;
  - `EVENT_ESSAY_STATUS` (`0x1F0F234`, "Review essay: {$essay} — status changed to {$status}"), logged for the other status changes.

  The log data is `['essay' => $container->getTitle(), 'status' => __('plugins.generic.bookReviewManager.assignment.status.' . $status)]`. Check how `getTranslatedMessage` interpolates the data from `logEvent`. If params are not interpolated, put the essay title into the `recipientName` field instead and use a fixed message.

- [ ] **Step 1: Write the failing tests** in `BundleWorkflowTest`. Use a helper `newEssay(array $memberTitles): array`, which returns `[$container, $members]`: it creates the container with `newWork`, sets its resource type, and sets `bundleId` and `bundlePosition` on the members.

```php
	public function testAcceptedReviewerIsMirroredWithPerWorkCopyPreference(): void {
		[$container, [$a, $b]] = $this->newEssay(['A', 'B']);
		$reviewer = $this->newAssignment($container, Assignment::STATUS_ACCEPTED, 1, [
			'dueDate' => '2030-01-31', 'shippingAddress' => 'Somewhere 1', 'phone' => '+49 30 123456',
			'copyPreferences' => [$a->getId() => 'physical', $b->getId() => 'electronic'],
		]);
		BundleWorkflow::syncMembers($container);
		BundleWorkflow::syncMembers($container); // idempotent

		$mirrorsA = (new AssignmentDAO())->getByObjectId($a->getId());
		$this->assertCount(1, $mirrorsA);
		$this->assertSame($reviewer->getId(), $mirrorsA[0]->getBundleAssignmentId());
		$this->assertSame('physical', $mirrorsA[0]->getData('copyPreference'));
		$this->assertSame('pending', $mirrorsA[0]->getData('copyStatus'));
		$this->assertSame('Somewhere 1', $mirrorsA[0]->getData('shippingAddress'));
		$mirrorB = (new AssignmentDAO())->getByObjectId($b->getId())[0];
		$this->assertSame('electronic', $mirrorB->getData('copyPreference'));
		$this->assertNull($mirrorB->getData('shippingAddress'));
		$this->assertSame(1, (int) (new ObjectForReviewDAO())->getById($a->getId())->getUserId());
		$containerAssignment = (new AssignmentDAO())->getById($reviewer->getId());
		$this->assertNull($containerAssignment->getData('shippingAddress'));
		$this->assertNull($containerAssignment->getData('copyPreferences'));
	}

	public function testRetractEndsMirrorsAndKeepsSentCopiesReturnable(): void {
		[$container, [$a, $b]] = $this->newEssay(['A', 'B']);
		$reviewer = $this->newAssignment($container, Assignment::STATUS_ACCEPTED, 1, [
			'dueDate' => '2030-01-31', 'copyPreferences' => [$a->getId() => 'physical', $b->getId() => 'electronic'],
			'shippingAddress' => 'X', 'phone' => '+49 30 123456',
		]);
		BundleWorkflow::syncMembers($container);
		$mirrorA = (new AssignmentDAO())->getByObjectId($a->getId())[0];
		$mirrorA->setData('copyStatus', 'sent');
		(new AssignmentDAO())->updateObject($mirrorA);

		AssignmentWorkflow::retract($this->plugin(), $container, (new AssignmentDAO())->getById($reviewer->getId()), \APP\facades\Repo::user()->get(1, true));

		$mirrorA = (new AssignmentDAO())->getById($mirrorA->getId());
		$this->assertSame(Assignment::STATUS_RETRACTED, $mirrorA->getStatus());
		$this->assertSame('sent', $mirrorA->getData('copyStatus'));
		$mirrorB = (new AssignmentDAO())->getByObjectId($b->getId())[0];
		$this->assertSame(Assignment::STATUS_RETRACTED, $mirrorB->getStatus());
		$this->assertSame('notNeeded', $mirrorB->getData('copyStatus'));
		$this->assertNull((new ObjectForReviewDAO())->getById($a->getId())->getUserId());
	}

	public function testAttachDetachCompleteMoveMembersAlong(): void {
		[$container, [$a]] = $this->newEssay(['A']);
		$reviewer = $this->newAssignment($container, Assignment::STATUS_ACCEPTED, 1, ['dueDate' => '2030-01-31', 'copyPreferences' => [$a->getId() => 'have']]);
		BundleWorkflow::syncMembers($container);
		$user = \APP\facades\Repo::user()->get(1, true);

		AssignmentWorkflow::attach($container, (new AssignmentDAO())->getById($reviewer->getId()), $user, 424242);
		$this->assertSame(424242, (int) (new ObjectForReviewDAO())->getById($a->getId())->getSubmissionId());
		$this->assertSame(Assignment::STATUS_SUBMITTED, (new AssignmentDAO())->getByObjectId($a->getId())[0]->getStatus());

		AssignmentWorkflow::detach((new ObjectForReviewDAO())->getById($container->getId()));
		$this->assertNull((new ObjectForReviewDAO())->getById($a->getId())->getSubmissionId());
		$this->assertSame(Assignment::STATUS_ACCEPTED, (new AssignmentDAO())->getByObjectId($a->getId())[0]->getStatus());

		AssignmentWorkflow::attach($container, (new AssignmentDAO())->getById($reviewer->getId()), $user, 424242);
		AssignmentWorkflow::complete((new ObjectForReviewDAO())->getById($container->getId()));
		$this->assertSame(Assignment::STATUS_COMPLETED, (new AssignmentDAO())->getByObjectId($a->getId())[0]->getStatus());
	}

	public function testMemberStateFollowsContainer(): void {
		[$container, [$a]] = $this->newEssay(['A']);
		$member = (new ObjectForReviewDAO())->getById($a->getId());
		$this->assertFalse(AssignmentWorkflow::isReservable($member, []));
		$this->newAssignment($container, Assignment::STATUS_ACCEPTED, 1, ['dueDate' => '2030-01-31']);
		$this->assertSame(AssignmentWorkflow::STATE_ASSIGNED, AssignmentWorkflow::getState($member, []));
		$this->assertFalse(InvitationWorkflow::canInvite($member));
	}
```

  Use user id 1 (admin of the dev journal). Add `public function notifyEditor(...$args) {}` to `FakePlugin`.

- [ ] **Step 2: Run the tests and check that they fail.**
  Run: `... tests/run.sh unit/php -- --filter BundleWorkflowTest`
  Expected: FAIL, because `BundleWorkflow` does not exist.

- [ ] **Step 3: Implement `classes/BundleWorkflow.php`**, as described above. Wrap `syncMembers` in `DB::transaction(function () { ... })`. Add the file docblock, with the explanation of container, member and mirror from the spec.

- [ ] **Step 4: Wire it in `AssignmentWorkflow`.** Add `self::syncBundle($object);` as the last statement of each of these:
  - `reserve` (after `syncReviewer`);
  - `acceptContract`;
  - `cancelByEditor`;
  - `retract`;
  - `attach`;
  - `detach`;
  - `complete`.

  The helper:

```php
	protected static function syncBundle(ObjectForReview $object): void {
		if ($object->isReviewEssay()) {
			BundleWorkflow::syncMembers($object);
		}
	}
```

  In `reserve` and `acceptContract`, `CopyWorkflow::init($assignment)` must not run for a container. Wrap it in `if (!$object->isReviewEssay())`.

  Add this at the top of `getState()`:

```php
		if ($object->isBundleMember() && ($container = BundleWorkflow::getContainer($object))) {
			return self::getState($container, self::dao()->getByObjectId($container->getId()));
		}
```

  `isBiddingOpen()` and `isReservable()` return false if `$object->isBundleMember()`.

- [ ] **Step 5: In `InvitationWorkflow`,** `canInvite()` and `canAdd()` return false for members.

- [ ] **Step 6: In `ReservationReminder::sendDueDateReminders`,** add `if ($assignment->isMirror()) continue;`. `sendPublisherReminders` stays unchanged. Add a test to `BundleWorkflowTest` that calls the protected `sendDueDateReminders` through `invoke()`. Give it a mirror due tomorrow (`reminderSent` unset) and a fake plugin with `ofrReminderDays = 14`. Assert that the mirror's `reminderSent` is still null afterwards. If emails cannot be sent in the test environment, make the non-mirror path harmless by giving the non-mirror assignment a user id that does not exist; the code then skips it.

- [ ] **Step 7: Add the locale strings** for both events in en, de and fi.

- [ ] **Step 8: Run the whole `unit/php` suite.** Expected: all pass.

- [ ] **Step 9: Commit.**
  `git commit -m "Review essays: mirror the reviewer's assignment onto the works"`

---

### Task 3: Creating and changing bundles

**Files:**
- Modify: `classes/BundleWorkflow.php`, `classes/ReservationLog.php`, `locale/*/locale.po`
- Test: `tests/unit/php/BundleWorkflowTest.php`

**Interfaces (Produces):**
- `BundleWorkflow::canCombine(ObjectForReview[] $works): ?string` returns null if combining is allowed, otherwise a locale key with the reason:
  - `...reviewEssay.error.notAvailable`: a work is a container, a member, attached to a submission, or offer-closed;
  - `...reviewEssay.error.twoReviewers`;
  - `...reviewEssay.error.pending`: an `offered` assignment, or `InvitationWorkflow::isRoundActive`.
- `BundleWorkflow::create($context, string $title, ?string $description, ObjectForReview[] $works, User $editor, array $copyPreferences = []): ObjectForReview` returns the container.
- `BundleWorkflow::addMember(ObjectForReview $container, ObjectForReview $work, User $editor, ?string $copyPreference = null): void`
- `BundleWorkflow::removeMember(ObjectForReview $container, ObjectForReview $work, User $editor): void`
- `BundleWorkflow::dissolve(ObjectForReview $container, User $editor): void`
- `BundleWorkflow::getReviewerAssignment(ObjectForReview $container): ?Assignment` returns the newest assignment in `REVIEWER_STATUSES`.
- These throw `\InvalidArgumentException`, with the locale key as the message, when the rules are broken.

**Behaviour** (all inside `DB::transaction`):

- **`create`:**
  1. Run `canCombine`.
  2. Insert the container:
     - `resource_type` = essay, `identifier` = `''`, `identifier_type` = `''`, `creator` = `'manager'`;
     - the title and description;
     - offer settings copied from `$works[0]`: `assignmentMode`, `bidDeadline`, `responsibleEditorId`, `hidden`.
  3. For each work, in order:
     - run `AssignmentWorkflow::discardList($work, null, $editor)` and `InvitationWorkflow::endAll($work, $editor)`;
     - set `bundleId` and `bundlePosition` (starting at 1).
  4. If one work has an `accepted` reviewer assignment `$r`:
     - insert a container assignment for the same user, status `accepted`, with `dateCreated`, `dueDate`, `dateReserved`, `shippingAddress` and `phone` copied from `$r`;
     - set `bundleAssignmentId` on `$r`;
     - set `copyPreferences` on the new container assignment to `$copyPreferences` plus `[$work->getId() => $r->copyPreference]`;
     - run `syncMembers($container)`, which creates the mirrors for the other works.
  5. Log `EVENT_ESSAY_CREATED` on the container and `EVENT_ESSAY_MEMBER_ADDED` on each work.
- **`addMember`:**
  1. The essay must not be submitted, and `canCombine([$work])` must pass.
  2. The work itself must have no `accepted` reviewer: refuse with `...error.twoReviewers` if it has one.
  3. Discard and end the work's lists, then set `bundleId` and `bundlePosition` (max + 1).
  4. If the container has an `accepted` reviewer, set `copyPreferences[workId] = $copyPreference ?? 'have'` on the container assignment and run `syncMembers`.
  5. Log the event.
- **`removeMember`:**
  1. The essay must not be submitted, and at least one other member must remain (otherwise `...error.lastMember`).
  2. If the work has an `accepted` mirror:
     - run `erasePersonalData` and `CopyWorkflow::onAssignmentEnded`;
     - set the status to `cancelled` and unset `bundleAssignmentId`, so the mirror becomes an ordinary ended assignment and its copy can still be returned.
  3. Unset `bundleId` and `bundlePosition`, and set `hidden = true`.
  4. Run `AssignmentWorkflow::syncReviewer($work)` and log `EVENT_ESSAY_MEMBER_REMOVED` on both rows.
- **`dissolve`:**
  1. Refuse if the container has a reviewer (`...error.hasReviewer`).
  2. Run `AssignmentWorkflow::discardList($container, null, $editor)` and `InvitationWorkflow::endAll($container, $editor)`.
  3. Unset `bundleId` and `bundlePosition` on every member, and set `hidden = true` on them.
  4. Log `EVENT_ESSAY_DISSOLVED` on each member, then delete the container with `ObjectForReviewDAO::deleteById`.

New events: `EVENT_ESSAY_CREATED` (`0x1F0F235`), `EVENT_ESSAY_MEMBER_ADDED` (`0x1F0F236`), `EVENT_ESSAY_MEMBER_REMOVED` (`0x1F0F237`) and `EVENT_ESSAY_DISSOLVED` (`0x1F0F238`), each with locale strings in en, de and fi.

- [ ] **Step 1: Write the failing tests:**
  - `testCreateFromFreshWorks`: two works without reviewers; the container exists, the members have `bundleId`, and the container's `hidden` is copied from the first work.
  - `testCreateTakesOverExistingReviewer`: work A has an `accepted` assignment with `dueDate` and `copyPreference=physical`; create the essay with `copyPreferences = [B => 'electronic']`. The container assignment is `accepted` with the same `dueDate`; A's assignment is now a mirror with `copyPreference=physical` and its copy status unchanged; B has a new mirror with `electronic`.
  - `testCreateRefusesTwoReviewers`: `canCombine` returns `'plugins.generic.bookReviewManager.reviewEssay.error.twoReviewers'`, and `create` throws.
  - `testCreateDiscardsOpenBids`: a work with a `bid` assignment; after `create`, the bid is `discarded`.
  - `testAddMemberWithReviewerCreatesMirror`.
  - `testRemoveMemberCancelsMirrorAndHidesWork`: the removed work is `hidden`, has no `bundleId`, its mirror is `cancelled` and no longer a mirror, and its copy status is kept.
  - `testRemoveLastMemberRefused`.
  - `testDissolveRefusedWithReviewer`, and `testDissolveFreesMembers`.
- [ ] **Step 2: Run them.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run all of `unit/php`.** Expected: PASS.
- [ ] **Step 5: Add the dev helper `dev/make-review-essay.php`** for the manual checks in later tasks. It boots OJS the same way `tests/unit/php/bootstrap.php` does (require that file, or copy its boot lines). It creates two hidden works, "Essay test A" and "Essay test B", with random ISBNs and `creator = 'manager'`, in context 1, then calls `BundleWorkflow::create` with the title "Essay test", the editor = user `admin`, and `hidden = false` on the container. Finally, it prints `container=<id> members=<id>,<id>`. Run it with `podman exec ojs-ofr-essays php /var/www/html/plugins/generic/bookReviewManager/dev/make-review-essay.php`.
- [ ] **Step 6: Commit.**
  `git commit -m "Review essays: create, combine, add, remove and dissolve"`

---

### Task 4: Contract form with a copy preference per work

**Files:**
- Modify: `classes/Reservation.php`, `pages/ObjectsForReviewHandler.php` (`CONTRACT_INPUT`, `contractInput`, `contractTemplateData`, and every call of `Reservation::validate`), `templates/frontend/contractFields.tpl`, `classes/InvitationWorkflow.php` (only if it validates contract data itself; check this), `locale/*/locale.po`
- Test: `tests/unit/php/ReservationTest.php`

**Interfaces (Produces):**
- `Reservation::validate(array $input, array $dueDateRange, array $members = []): array`. Here `$members` is a list of `ObjectForReview` (the essay's works).
  - When `$members` is not empty, the method reads `$input['copyPreferences']` (a map from object id to preference), validates each entry against `COPY_PREFERENCES`, and stores the result in `$data['copyPreferences']`. A missing or invalid entry gives the error `copyPreference`.
  - `copyPreference` itself stays null.
  - The address and phone are required if any preference is `physical`; `copyNote` is required if any is `other`.
- `Reservation::getEmailDetails()` and `getDisplayData()` show the preferences per work, as one line per work, "citation: label", when `copyPreferences` is set. Mirrors are looked up only when `copyPreferences` is gone after the sync; in that case, nothing is added.

- [ ] **Step 1: Write the failing tests** in `ReservationTest`:
  - per-work preferences valid: `$data['copyPreferences'] === [5 => 'physical', 6 => 'have']`, and the address is required;
  - a missing entry for one work gives an error on `copyPreference`;
  - with no members, the behaviour is unchanged (the existing tests still pass).

  Use `$this->object(['objectId' => 5])` and `$this->object(['objectId' => 6])` as members.
- [ ] **Step 2: Run them.** Expected: FAIL.
- [ ] **Step 3: Implement** `validate`, `getEmailDetails` and `getDisplayData`.
- [ ] **Step 4: Change the template.** In `contractFields.tpl`, when `$reserveMembers` (an array of `{id, citation}`) is set, render the copy `<fieldset>` once per member, with radio buttons named `copyPreferences[{$member.id}]`. Then render the conditional address/phone block once, shown if any `physical` is checked, and the `copyNote` block once, shown if any `other` is checked. Update the inline script: compute the set of checked values across all radio groups whose name starts with `copyPreference`, and show `[data-show-for=X]` if X is in that set.
- [ ] **Step 5: Change the handler.**
  - In `ObjectsForReviewHandler`, `contractInput` also reads `copyPreferences` (an array).
  - `contractTemplateData($request, $formState, $object = null)` assigns `reserveMembers` for a container.
  - Every `Reservation::validate(...)` call passes `BundleWorkflow::membersOf($object)`. This is a new tiny helper: it returns `[]` for a non-container, otherwise `getMembers`.
  - The reservation dialog (`templates/frontend/reserveDialog.tpl`) is rendered per object on the list page, so it needs the members per object. Pass `members` in the `gridData` row (Task 5 adds this), and in `reserveDialog.tpl` assign `reserveMembers` from the row before including `contractFields.tpl`. Read `reserveDialog.tpl` first to see how it includes the fields.
- [ ] **Step 6: Run `unit/php`.** Then check the list page by hand. In `ojs-ofr-essays`, create an essay with `dev/make-review-essay.php` (Task 3, Step 5), and open `http://localhost:8081/index.php/test/books-for-review?reserve=<containerId>` as `bre` (password `ojsbre`). Check that two radio groups appear and that the address shows up when one of them is set to "physical".
- [ ] **Step 7: Commit.**
  `git commit -m "Review essays: copy preference per work in the agreement"`

---

### Task 5: Public list, one-click links, submission and publication

**Files:**
- Modify: `pages/ObjectsForReviewHandler.php`, `templates/frontend/pages/forReview.tpl`, `BookReviewManagerPlugin.php` (`completeOnPublish`, `addToSubmissionWizardSteps`, `addSubmissionDisplay`, `addSitemapURLs`), `controllers/grid/ObjectsForReviewGridHandler.php`, `controllers/grid/form/ObjectsForReviewForm.php`, `pages/ObjectsForReviewManagementHandler.php` (`reviewedWorks`)
- Test: `tests/unit/php/BundleWorkflowTest.php` (for the helper), plus a manual check

**Interfaces:**
- Consumes: Tasks 1–4.
- Produces: `BundleWorkflow::membersOf(ObjectForReview $o): ObjectForReview[]`, if Task 4 has not added it already.

**Changes:**

1. **`ObjectsForReviewHandler::index`.**
   - Skip objects with `isBundleMember()`.
   - For a container, add `'members' => [...]` to the row. For each member, the entry has `id`, `citation`, `identifierUrl` and, if the current user has an `accepted` mirror on it, `copyStatus` (`CopyWorkflow::getStatus($mirror)`).
   - The template `forReview.tpl` lists the members under the essay title, as `<ul class="ofrEssayWorks">`. For each member with `copyStatus == 'sent'`, it shows the existing "confirm receipt" button, with `objectId` = the member id.
   - For containers, the identifier columns stay empty.
2. **`requirePost`.** It currently redirects for submitted or closed objects. It must also redirect when the object is a member, except for `confirmReceipt`. Implement this with a parameter `requirePost($request, bool $allowMember = false)`, and call it with `true` only from `confirmReceipt`.
3. **`confirmReceipt`** works unchanged on members, because the mirror is that user's `accepted` assignment on the member.
4. **`completeOnPublish`.** Skip `isBundleMember()` objects: the container completes them through `syncMembers`.
5. **`addSubmissionDisplay` (article page) and `addSitemapURLs`.** Skip `isReviewEssay()` objects, so that only the reviewed works are shown. Check what `addSitemapURLs` iterates over, and apply the same filter if it lists objects.
6. **Submission wizard state and `ObjectsForReviewGridHandler::initialize`.** Skip members, so the essay shows as one entry, whose citation lists its works.
7. **`ObjectsForReviewGridHandler::deleteObjectForReview`.** It must refuse members: they are removed by removing the essay. Container rows already detach, because `creator == 'manager'`.
8. **`ObjectsForReviewForm`** (the list of the reviewer's reserved works). Skip members: the container is in the list, because its `user_id` is the reviewer's.
9. **`ObjectsForReviewManagementHandler::reviewedWorks`.** Skip containers.

- [ ] **Step 1: Implement points 1–9.**
- [ ] **Step 2: Add the test** `testMembersOf` (container → members in order; plain work → `[]`).
- [ ] **Step 3: Run `unit/php`.** Expected: PASS.
- [ ] **Step 4: Check by hand** in the container on port 8081, using an essay created with `dev/make-review-essay.php`:
  - the public list shows one entry with two works, and the works are not listed separately;
  - reserve it as `bre`, with one physical and one electronic copy;
  - the mirrors exist (`tests/lib/ojs.mjs` `sql()`, or `podman exec ojs-ofr-essays mysql ...`; see `tests/lib/env.sh` for the DB credentials helper).
- [ ] **Step 5: Commit.**
  `git commit -m "Review essays: public list, submission and publication"`

---

### Task 6: Backend: "Available works" grid and dialogs

**Files:**
- Modify: `controllers/grid/ObjectsForReviewManagementGridHandler.php`, `controllers/grid/ObjectsForReviewManagementGridRow.php`, `controllers/grid/ObjectsForReviewManagementGridCellProvider.php`, `classes/ReservationEmailEventType.php`, `emailTemplates.xml`, `locale/*/emails.po`, `locale/*/locale.po`, `BookReviewManagerPlugin.php` (`addMailable`)
- Create: `controllers/grid/form/ReviewEssayForm.php`, `templates/reviewEssayForm.tpl`, `controllers/grid/form/BundleMemberForm.php`, `templates/bundleMemberForm.tpl`, `mailables/ObjectsForReviewEssayChanged.php`

**Behaviour:**

- **Grid rows.** `initialize` orders the rows so that each container is followed directly by its members. A member's description is prefixed with `↳ ` and has the CSS class `ofrEssayMember` (through the cell provider). A container's description is prefixed with the bold label "Review essay:". `getGridDataElements` keeps insertion order, so build the array in that order.
- **Grid action "Create review essay".** It opens `ReviewEssayForm` in a modal.
- **`ReviewEssayForm`.** Model it on `AvailableObjectsForReviewForm`: read that file and `templates/editAvailableObjectForReviewForm.tpl` first, and reuse their offer-setting fields (assignment mode, bid deadline, responsible editor, hidden) by including the same template parts where possible. The form has these fields:
  - `title` (required, plain text) and `description` (rich text);
  - `objectIds[]`: checkboxes for all works for which `canCombine([$w])` is null, at least one required;
  - when exactly one of the selected works has a reviewer, a copy preference per other selected work: render a select per work, `copyPreferences[<id>]`, default `have`;
  - on edit: title, description and the offer settings only (members are managed through the row actions).

  On save, `canCombine` runs on the selection, and errors are shown with `addError`. Then `BundleWorkflow::create`, and the offer settings are applied to the container afterwards. If a reviewer was taken over, the form sends the email `BRM_REVIEW_ESSAY_CHANGED` to them with `ReservationMailer::compose`/`send` (follow `controllers/form/ReservationEmailForm.php` for an editable email; the simplest acceptable version sends the template unedited and notes that in the confirmation).
- **Row actions on a container:** *Edit* (`ReviewEssayForm`), *Add work* (`BundleMemberForm` in add mode: a select of the works that may be combined, plus a copy preference if the essay has a reviewer), *Dissolve* (a confirmation that calls a new grid op `dissolveReviewEssay`, which runs `BundleWorkflow::dissolve` and returns a `JSONMessage(false, __($e->getMessage()))` on `InvalidArgumentException`), and the existing *Delete*, which dissolves first and is refused if there is a reviewer. The existing *Hide/Show* toggle stays.
- **Row actions on a member:** *Edit* (the existing work form), and *Remove from review essay* (a confirmation that calls a new grid op `removeFromReviewEssay`). The existing *Delete* first removes the work from the essay and is refused if it is the last member. Members do not get the hide toggle or the invite button.
- **Row action on a plain work:** *Add to review essay…* (`BundleMemberForm` in "choose essay" mode: a select of the containers that are not submitted).
- **Register the new ops** in the constructor's `addRoleAssignment` list.
- **Email `BRM_REVIEW_ESSAY_CHANGED`.**
  - `ReservationEmailEventType::ESSAY_CHANGED = 0x1F0F116`, with a label key.
  - A mailable copied from `mailables/ObjectsForReviewConfirmation.php`, with the variables `{$reviewerName}`, `{$objectDescription}` (the container citation), `{$dueDate}` and `{$journalName}`.
  - The template text in en, de and fi: "The review essay you agreed to write now covers these works: … Due date: …". Register it like the other mailables in `addMailable`.
- **When the email is sent.** `BundleMemberForm`, when it adds a work while the essay has a reviewer, sends the email to the reviewer. `removeFromReviewEssay` sends it too, if there is a reviewer.

- [ ] **Step 1: Build the grid ordering and the row actions.** Log in as `bre` on port 8081, open *Book Reviews › Available works*, and check:
  - the essay row with its two indented works;
  - the actions per row type.
- [ ] **Step 2: Build `ReviewEssayForm` and create an essay through the UI** from two new works. Check that it appears as expected.
- [ ] **Step 3: Build `BundleMemberForm`, remove and dissolve.** Exercise each action once in the UI, including the refusals (dissolve with a reviewer, removing the last member).
- [ ] **Step 4: Build the mailable, the template and the locales.** Run `unit/php` (`LocaleTest` checks the locale completeness).
- [ ] **Step 5: Commit.**
  `git commit -m "Review essays: backend list and dialogs"`

---

### Task 7: To-do and "Reviewers & bidders"

**Files:**
- Modify: `pages/ObjectsForReviewManagementHandler.php`, `templates/management.tpl`, `templates/managementObjectCell.tpl`, `locale/*/locale.po`

**Behaviour:**

- **`objectRow($object)`.** It adds `essayTitle`: for a member, the container's title, otherwise null. `managementObjectCell.tpl` renders "(review essay: …)" after the description when `essayTitle` is set.
- **`desk`, overdue reviews.** Skip `$assignment->isMirror()`. Copy rows and publisher notifications stay per member, and the label comes from `essayTitle`.
- **`assignments`.** Skip mirrors. For a container's assignment row, add `members => [ {description, copyStatusLabel, copyActions} ]` from its mirrors (`AssignmentDAO::getMirrors`), and render them in `management.tpl` under the row's copy column as a small list.
- **`invite` page.** Redirect to the container when it is called for a member.

- [ ] **Step 1: Implement.**
- [ ] **Step 2: Check by hand.** With the reserved essay from Task 5, the To-do page shows two copy rows, labelled with the essay title, and "Reviewers & bidders" shows one row with two copy sublines. Run a copy step (*Send from stock*) for one work, and check that only that work's mirror changes.
- [ ] **Step 3: Run `unit/php`.** Expected: PASS.
- [ ] **Step 4: Commit.**
  `git commit -m "Review essays: To-do and Reviewers & bidders"`

---

### Task 8: API and Node client

**Files:**
- Modify: `api/ObjectsForReviewController.php`, `api/src/client.js`, `api/bin/ofr.js`
- Test: `tests/e2e/api/bundles.test.mjs` (new), `api/test/client.test.js`, `api/test/cli.test.js`

**Routes** (register them in `getGroupRoutes`, following the existing style):

- `POST /bundles`, body `{title, description?, objectIds:int[], copyPreferences?:{id:pref}, assignmentMode?, responsibleEditorId?, hidden?}`. It runs `BundleWorkflow::create`. `canCombine` failures give `409 cannot_combine`, with `{reason: <locale key>}`. It returns `objectToArray(container, true)` with status 201.
- `POST /bundles/{id}/members`, body `{objectId, copyPreference?}`, and `DELETE /bundles/{id}/members/{objectId}`. Rule violations give 409 with the code `review_essay_rule` and the reason. An unknown id, or an id that is not a container, gives 404.
- **`objectToArray`.** It adds `isReviewEssay`, `bundleId`, and, for containers, `members: [objectToArray(member)]`.
- **`importAssignment` on a container.** It accepts `copies: [{objectId, copyPreference, copyStatus?, copyStatusDate?}]`:
  1. the container assignment is created as now, with `copyPreferences` built from `copies`;
  2. then `BundleWorkflow::syncMembers` runs;
  3. then, for each entry with `copyStatus`, that status is set on the member's mirror.
- **`importAssignment` and `addAssignment` on a member** return `409 member_of_review_essay`.
- The bulk import (if it exists in the controller or the client, `importData`) passes bundles through: an entry `{bundle: {title, works:[...work inputs], ...}}` is out of scope. Document that bundles are created after the works.

**Client:**
- `client.createBundle(input)`, `client.addBundleMember(id, objectId, copyPreference)` and `client.removeBundleMember(id, objectId)`.
- CLI: `ofr bundle create --title T --works 12,13 [--hidden]`, `ofr bundle add <id> <objectId> [--copy physical]`, and `ofr bundle remove <id> <objectId>`. Follow how `ofr.js` parses its existing subcommands.

- [ ] **Step 1: Write the failing e2e test** `tests/e2e/api/bundles.test.mjs`, in the style of the existing files in `tests/e2e/api/` (read one first, together with `fixtures.mjs`):
  - create two works with `newWork()`, then `createBundle`; check the 201, `isReviewEssay` and the two members;
  - add a third work, remove it again (`bundleId` cleared);
  - import an accepted assignment with `copies` for the two works; `GET` the container with assignments, and each member has an accepted mirror with the given copy status;
  - `addAssignment` on a member gives 409 `member_of_review_essay`;
  - combining two works that each have a reviewer gives 409 `cannot_combine`.
- [ ] **Step 2: Write the failing client and CLI unit tests**, mirroring the existing tests for `addObject`.
- [ ] **Step 3: Run** `... tests/run.sh e2e/api` and `tests/run.sh unit/client`. Expected: FAIL.
- [ ] **Step 4: Implement.**
- [ ] **Step 5: Run all suites:** `OFR_CONTAINER=ojs-ofr-essays OJS_PORT=8081 OFR_E2E_OFFLINE=1 tests/run.sh`. Expected: all pass.
- [ ] **Step 6: Commit.**
  `git commit -m "Review essays: API and ofr client"`

---

### Task 9: Documentation and the full manual run

**Files:**
- Modify: `docs/workflow.md`
- Create: `docs/superpowers/followups/2026-11-21-review-essays-docs.md`. This lists the text to add to `README.md`, `api/SKILL.md` (moved to `skills/book-review-manager-api/SKILL.md` in the main checkout) and `skills/book-review-manager-email-import/SKILL.md`, which is uncommitted in the main checkout. The user applies it after merging, so their uncommitted rename does not conflict.

- [ ] **Step 1: `docs/workflow.md`.**
  - Add the section "### Review essays: one review of several works" after section 8 (and add it to the table of contents if there is one). It explains:
    - how to create an essay, and how to combine works;
    - what moves together and what stays per work;
    - adding and removing works, and dissolving an essay;
    - what the reviewer sees.
  - Add a line to the overview flowchart, and add "mirror" to the statuses reference.
- [ ] **Step 2: Do a full manual run on port 8081:**
  1. Create two works.
  2. Create an essay, invite `bre`, and reserve through the invitation link with one physical and one electronic copy.
  3. Request the copy from the publisher for one work, and send the other from stock.
  4. Confirm receipt as `bre`.
  5. Retract as `bre`, and check that the return steps are offered for the physical copy.
  6. Combine again with a reviewer through the API.

  Write down anything broken, and fix it in the task it belongs to.
- [ ] **Step 3: Commit.**
  `git commit -m "Review essays: documentation"`
