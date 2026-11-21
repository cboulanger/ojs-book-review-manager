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
use APP\plugins\generic\bookReviewManager\classes\AssignmentWorkflow;
use APP\plugins\generic\bookReviewManager\classes\BundleWorkflow;
use APP\plugins\generic\bookReviewManager\classes\InvitationWorkflow;
use APP\plugins\generic\bookReviewManager\classes\ObjectForReview;
use APP\plugins\generic\bookReviewManager\classes\ObjectForReviewDAO;
use APP\plugins\generic\bookReviewManager\classes\ReservationLog;
use APP\plugins\generic\bookReviewManager\classes\ReservationReminder;
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

	/**
	 * A review essay with its works.
	 * @return array [container, members]
	 */
	protected function newEssay(array $memberTitles): array {
		// Reload: after insertObject() date_created is not set on the object, and updateObject() would fail
		$container = (new ObjectForReviewDAO())->getById($this->newWork('Essay')->getId());
		$container->setResourceType(ObjectForReview::RESOURCE_TYPE_REVIEW_ESSAY);
		(new ObjectForReviewDAO())->updateObject($container);
		$members = [];
		foreach (array_values($memberTitles) as $i => $title) {
			$members[] = $this->newWork($title, ['bundleId' => $container->getId(), 'bundlePosition' => $i + 1]);
		}
		return [$container, $members];
	}

	public function testMembersInOrderAndContainerCitation(): void {
		$container = (new ObjectForReviewDAO())->getById($this->newWork('Essay')->getId());
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

	public function testMembersOf(): void {
		[$container, $members] = $this->newEssay(['A', 'B']);
		$this->assertSame(array_map(fn ($m) => $m->getId(), $members),
			array_map(fn ($m) => $m->getId(), BundleWorkflow::membersOf($container)));
		$this->assertSame([], BundleWorkflow::membersOf($members[0]), 'a work has no members');
	}

	public function testMirrorsAreNotCountedAsReviews(): void {
		$work = $this->newWork('W');
		$this->newAssignment($work, Assignment::STATUS_COMPLETED, 1, ['bundleAssignmentId' => 999999]);
		$before = (new AssignmentDAO())->countReviewsByUser(1, self::CONTEXT_ID);
		$this->newAssignment($work, Assignment::STATUS_COMPLETED, 1);
		$this->assertSame($before + 1, (new AssignmentDAO())->countReviewsByUser(1, self::CONTEXT_ID));
	}

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
		// Logged once on the work, naming the essay
		$labels = array_column(ReservationLog::getHistory($a), 'label');
		$this->assertCount(1, $labels);
		$this->assertStringContainsString(': Essay ', $labels[0]);
	}

	public function testFinishedReviewerWithoutMirrorsIsMirrored(): void {
		foreach ([Assignment::STATUS_SUBMITTED, Assignment::STATUS_COMPLETED] as $status) {
			[$container, [$a, $b]] = $this->newEssay(['A', 'B']);
			$reviewer = $this->newAssignment($container, $status, 1, [
				'dueDate' => '2030-01-31', 'dateReserved' => '2029-06-01 00:00:00', 'dateStatus' => '2029-12-01 10:00:00',
				'shippingAddress' => 'Somewhere 1', 'phone' => '+49 30 123456',
				'copyPreferences' => [$a->getId() => 'physical', $b->getId() => 'electronic'],
			]);
			BundleWorkflow::syncMembers($container);
			BundleWorkflow::syncMembers($container); // idempotent

			foreach ([[$a, 'physical'], [$b, 'electronic']] as [$member, $preference]) {
				$mirrors = (new AssignmentDAO())->getByObjectId($member->getId());
				$this->assertCount(1, $mirrors, "{$status}: one mirror");
				$mirror = $mirrors[0];
				$this->assertSame($reviewer->getId(), $mirror->getBundleAssignmentId());
				$this->assertSame($status, $mirror->getStatus());
				$this->assertSame('2029-12-01 10:00:00', $mirror->getDateStatus());
				$this->assertSame('2030-01-31', $mirror->getData('dueDate'));
				$this->assertSame('2029-06-01 00:00:00', $mirror->getData('dateReserved'));
				$this->assertSame($preference, $mirror->getData('copyPreference'));
				$this->assertSame('notNeeded', $mirror->getData('copyStatus'), 'no copy is asked for a finished review');
				$this->assertSame(1, (int) (new ObjectForReviewDAO())->getById($member->getId())->getUserId());
			}
			$mirrorA = (new AssignmentDAO())->getByObjectId($a->getId())[0];
			$this->assertNull($mirrorA->getData('shippingAddress'), "{$status}: personal data is not kept for finished reviews");
			$this->assertNull($mirrorA->getData('phone'), "{$status}: personal data is not kept for finished reviews");
			$containerAssignment = (new AssignmentDAO())->getById($reviewer->getId());
			$this->assertNull($containerAssignment->getData('copyPreferences'));
			$this->assertNull($containerAssignment->getData('shippingAddress'));
			$this->assertCount(1, ReservationLog::getHistory($a), "{$status}: the mirror is logged once");
		}
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

	public function testAcceptedBidderIsMirroredEvenIfALaterBidderWasCancelled(): void {
		[$container, [$a, $b]] = $this->newEssay(['A', 'B']);
		// Bidder C bid on a later day; the contract offer was cancelled. Bidder B bid earlier and accepted.
		$c = $this->newAssignment($container, Assignment::STATUS_CANCELLED, 999999999, ['dateCreated' => '2031-01-02 10:00:00']);
		$reviewer = $this->newAssignment($container, Assignment::STATUS_ACCEPTED, 1, [
			'dateCreated' => '2031-01-01 10:00:00', 'dueDate' => '2030-01-31',
			'copyPreferences' => [$a->getId() => 'electronic', $b->getId() => 'have'],
		]);
		BundleWorkflow::syncMembers($container);

		foreach ([$a, $b] as $member) {
			$mirrors = (new AssignmentDAO())->getByObjectId($member->getId());
			$this->assertCount(1, $mirrors);
			$this->assertSame($reviewer->getId(), $mirrors[0]->getBundleAssignmentId());
			$this->assertSame(Assignment::STATUS_ACCEPTED, $mirrors[0]->getStatus());
		}
	}

	public function testMirrorsOfAnEarlierReviewerStayWhenANewReviewerIsMirrored(): void {
		[$container, [$a]] = $this->newEssay(['A']);
		$first = $this->newAssignment($container, Assignment::STATUS_ACCEPTED, 1, ['dueDate' => '2030-01-31', 'copyPreferences' => [$a->getId() => 'have']]);
		BundleWorkflow::syncMembers($container);
		AssignmentWorkflow::retract($this->plugin(), $container, (new AssignmentDAO())->getById($first->getId()), \APP\facades\Repo::user()->get(1, true));
		$second = $this->newAssignment($container, Assignment::STATUS_ACCEPTED, 1, ['dueDate' => '2030-02-28', 'copyPreferences' => [$a->getId() => 'have']]);
		BundleWorkflow::syncMembers($container);

		$byAssignment = [];
		foreach ((new AssignmentDAO())->getByObjectId($a->getId()) as $mirror) {
			$byAssignment[$mirror->getBundleAssignmentId()] = $mirror->getStatus();
		}
		$this->assertSame([$first->getId() => Assignment::STATUS_RETRACTED, $second->getId() => Assignment::STATUS_ACCEPTED], $byAssignment);
	}

	public function testCancelByEditorCancelsMirrors(): void {
		[$container, [$a, $b]] = $this->newEssay(['A', 'B']);
		$reviewer = $this->newAssignment($container, Assignment::STATUS_ACCEPTED, 1, ['dueDate' => '2030-01-31',
			'copyPreferences' => [$a->getId() => 'electronic', $b->getId() => 'have']]);
		BundleWorkflow::syncMembers($container);

		AssignmentWorkflow::cancelByEditor($container, (new AssignmentDAO())->getById($reviewer->getId()), \APP\facades\Repo::user()->get(1, true));

		foreach ([$a, $b] as $member) {
			$this->assertSame(Assignment::STATUS_CANCELLED, (new AssignmentDAO())->getByObjectId($member->getId())[0]->getStatus());
		}
	}

	public function testPersonalDataLeavesTheMirrorOnAttachAndRetract(): void {
		$user = \APP\facades\Repo::user()->get(1, true);
		foreach (['attach', 'retract'] as $transition) {
			[$container, [$a]] = $this->newEssay(['A']);
			$reviewer = $this->newAssignment($container, Assignment::STATUS_ACCEPTED, 1, ['dueDate' => '2030-01-31',
				'copyPreferences' => [$a->getId() => 'physical'], 'shippingAddress' => 'Somewhere 1', 'phone' => '+49 30 123456']);
			BundleWorkflow::syncMembers($container);
			$mirror = (new AssignmentDAO())->getByObjectId($a->getId())[0];
			$this->assertSame('Somewhere 1', $mirror->getData('shippingAddress'));

			$assignment = (new AssignmentDAO())->getById($reviewer->getId());
			if ($transition === 'attach') {
				AssignmentWorkflow::attach($container, $assignment, $user, 424242);
			} else {
				AssignmentWorkflow::retract($this->plugin(), $container, $assignment, $user);
			}
			$mirror = (new AssignmentDAO())->getById($mirror->getId());
			$this->assertNull($mirror->getData('shippingAddress'), $transition);
			$this->assertNull($mirror->getData('phone'), $transition);
		}
	}

	public function testMemberStateFollowsContainer(): void {
		[$container, [$a]] = $this->newEssay(['A']);
		$member = (new ObjectForReviewDAO())->getById($a->getId());
		$this->assertFalse(AssignmentWorkflow::isReservable($member, []));
		$this->newAssignment($container, Assignment::STATUS_ACCEPTED, 1, ['dueDate' => '2030-01-31']);
		$this->assertSame(AssignmentWorkflow::STATE_ASSIGNED, AssignmentWorkflow::getState($member, []));
		$this->assertFalse(InvitationWorkflow::canInvite($member));
	}

	public function testMirrorsGetNoDueDateReminder(): void {
		[$container, [$a, $b]] = $this->newEssay(['A', 'B']);
		$tomorrow = (new \DateTimeImmutable('tomorrow'))->format('Y-m-d');
		$mirror = $this->newAssignment($a, Assignment::STATUS_ACCEPTED, 1, ['dueDate' => $tomorrow, 'bundleAssignmentId' => 999999]);
		// Not a mirror, but its user does not exist, so no email is sent
		$other = $this->newAssignment($b, Assignment::STATUS_ACCEPTED, 999999999, ['dueDate' => $tomorrow]);
		// Only these two assignments, not the other ones in the dev journal
		$task = new class ([[$a, $mirror], [$b, $other]]) extends ReservationReminder {
			public function __construct(private array $pairs) {
				parent::__construct();
			}

			protected function assignments($context, array $statuses): array {
				return $this->pairs;
			}
		};
		$context = \APP\core\Application::getContextDAO()->getById(self::CONTEXT_ID);

		$this->assertSame(0, $this->invoke($task, 'sendDueDateReminders', $this->plugin(['ofrReminderDays' => 14]), $context));
		$this->assertNull((new AssignmentDAO())->getById($mirror->getId())->getData('reminderSent'));
	}

	//
	// Creating and changing bundles
	//

	protected function editor(): \PKP\user\User {
		return \APP\facades\Repo::user()->get(1, true);
	}

	protected function context() {
		return \APP\core\Application::getContextDAO()->getById(self::CONTEXT_ID);
	}

	protected function createEssay(array $works, array $copyPreferences = []): ObjectForReview {
		$container = BundleWorkflow::create($this->context(), 'Essay', 'About both', $works, $this->editor(), $copyPreferences);
		$this->created[] = $container->getId();
		return $container;
	}

	protected function reload(ObjectForReview $object): ObjectForReview {
		return (new ObjectForReviewDAO())->getById($object->getId());
	}

	/**
	 * An essay with reviewer 1, accepted, with mirrors.
	 * @param array $preferences Work title => copy preference
	 * @return array [container, members, container assignment]
	 */
	protected function essayWithReviewer(array $preferences, array $data = []): array {
		[$container, $members] = $this->newEssay(array_keys($preferences));
		$copyPreferences = [];
		foreach (array_values($preferences) as $i => $preference) {
			$copyPreferences[$members[$i]->getId()] = $preference;
		}
		$reviewer = $this->newAssignment($container, Assignment::STATUS_ACCEPTED, 1,
			['dueDate' => '2030-01-31', 'copyPreferences' => $copyPreferences] + $data);
		BundleWorkflow::syncMembers($container);
		return [$container, $members, $reviewer];
	}

	public function testCreateFromFreshWorks(): void {
		$a = $this->newWork('A', ['hidden' => true, 'assignmentMode' => 'bidding', 'bidDeadline' => '2030-01-31']);
		$b = $this->newWork('B', ['hidden' => null]);

		$container = $this->reload($this->createEssay([$a, $b]));

		$this->assertTrue($container->isReviewEssay());
		$this->assertSame('Essay', $container->getTitle());
		$this->assertSame('About both', $container->getDescription());
		$this->assertSame('', (string) $container->getIdentifier());
		$this->assertSame('manager', $container->getCreator());
		$this->assertTrue($container->isHidden());
		$this->assertTrue($container->isBidding());
		$this->assertSame('2030-01-31', $container->getData('bidDeadline'));
		$this->assertSame([$a->getId(), $b->getId()],
			array_map(fn ($m) => $m->getId(), (new ObjectForReviewDAO())->getMembers($container->getId())));
		$this->assertSame(2, (int) $this->reload($b)->getData('bundlePosition'));
		$this->assertSame([], (new AssignmentDAO())->getByObjectId($container->getId()));
		$this->assertCount(1, ReservationLog::getHistory($container));
		$this->assertStringContainsString('Essay', ReservationLog::getHistory($a)[0]['label']);
	}

	public function testCreateTakesOverExistingReviewer(): void {
		$a = $this->newWork('A');
		$b = $this->newWork('B');
		$c = $this->newWork('C');
		$r = $this->newAssignment($a, Assignment::STATUS_ACCEPTED, 1, ['dueDate' => '2030-01-31', 'dateReserved' => '2029-01-01',
			'copyPreference' => 'physical', 'shippingAddress' => 'Somewhere 1', 'phone' => '+49 30 123456',
			'copyStatus' => 'requested', 'reminderSent' => '2030-01-24', 'copyNote' => 'From the library']);

		$container = $this->createEssay([$a, $b, $c], [$b->getId() => 'electronic', $c->getId() => 'other']);

		$containerAssignments = (new AssignmentDAO())->getByObjectId($container->getId());
		$this->assertCount(1, $containerAssignments);
		$reviewer = $containerAssignments[0];
		$this->assertSame(Assignment::STATUS_ACCEPTED, $reviewer->getStatus());
		$this->assertSame('2030-01-31', $reviewer->getData('dueDate'));
		$this->assertSame('2029-01-01', $reviewer->getData('dateReserved'));
		// No second due-date reminder
		$this->assertSame('2030-01-24', $reviewer->getData('reminderSent'));
		// The note of an 'other' copy reaches the new mirror (and then lives only there)
		$this->assertSame('From the library', (new AssignmentDAO())->getByObjectId($c->getId())[0]->getData('copyNote'));
		$this->assertSame($reviewer->getId(), BundleWorkflow::getReviewerAssignment($container)->getId());
		// A's assignment became the mirror; no second mirror for A
		$assignmentsA = (new AssignmentDAO())->getByObjectId($a->getId());
		$this->assertCount(1, $assignmentsA);
		$this->assertSame($r->getId(), $assignmentsA[0]->getId());
		$this->assertSame($reviewer->getId(), $assignmentsA[0]->getBundleAssignmentId());
		$this->assertSame('physical', $assignmentsA[0]->getData('copyPreference'));
		$this->assertSame('requested', $assignmentsA[0]->getData('copyStatus'));
		$this->assertSame('Somewhere 1', $assignmentsA[0]->getData('shippingAddress'));
		$assignmentsB = (new AssignmentDAO())->getByObjectId($b->getId());
		$this->assertCount(1, $assignmentsB);
		$this->assertSame($reviewer->getId(), $assignmentsB[0]->getBundleAssignmentId());
		$this->assertSame('electronic', $assignmentsB[0]->getData('copyPreference'));
		$this->assertSame(1, (int) $this->reload($container)->getUserId());
		$this->assertSame(1, (int) $this->reload($b)->getUserId());
	}

	public function testCreateRefusesTwoReviewers(): void {
		$a = $this->newWork('A');
		$b = $this->newWork('B');
		$this->newAssignment($a, Assignment::STATUS_ACCEPTED, 1, ['dueDate' => '2030-01-31']);
		$this->newAssignment($b, Assignment::STATUS_ACCEPTED, 1, ['dueDate' => '2030-01-31']);

		$this->assertSame('plugins.generic.bookReviewManager.reviewEssay.error.twoReviewers', BundleWorkflow::canCombine([$a, $b]));
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('plugins.generic.bookReviewManager.reviewEssay.error.twoReviewers');
		$this->createEssay([$a, $b]);
	}

	public function testCanCombineRefusesUnavailableAndPendingWorks(): void {
		[$container, [$member]] = $this->newEssay(['M']);
		$closed = $this->newWork('Closed', ['offerClosed' => true]);
		$offered = $this->newWork('Offered');
		$this->newAssignment($offered, Assignment::STATUS_OFFERED, 1);
		$round = $this->newWork('Round', ['invitationMode' => InvitationWorkflow::MODE_SEQUENTIAL]);
		$free = $this->newWork('Free');

		$notAvailable = 'plugins.generic.bookReviewManager.reviewEssay.error.notAvailable';
		$this->assertSame($notAvailable, BundleWorkflow::canCombine([$free, $container]));
		$this->assertSame($notAvailable, BundleWorkflow::canCombine([$free, $member]));
		$this->assertSame($notAvailable, BundleWorkflow::canCombine([$free, $closed]));
		$this->assertSame('plugins.generic.bookReviewManager.reviewEssay.error.pending', BundleWorkflow::canCombine([$free, $offered]));
		$this->assertSame('plugins.generic.bookReviewManager.reviewEssay.error.pending', BundleWorkflow::canCombine([$free, $round]));
		$this->assertNull(BundleWorkflow::canCombine([$free]));
	}

	public function testCreateDiscardsOpenBids(): void {
		$a = $this->newWork('A', ['assignmentMode' => 'bidding']);
		$b = $this->newWork('B');
		$bid = $this->newAssignment($a, Assignment::STATUS_BID, 1, ['statement' => 'Me!']);
		$invitee = $this->newAssignment($b, Assignment::STATUS_LISTED, 1);

		$this->createEssay([$a, $b]);

		$this->assertSame(Assignment::STATUS_DISCARDED, (new AssignmentDAO())->getById($bid->getId())->getStatus());
		$this->assertSame(Assignment::STATUS_DISCARDED, (new AssignmentDAO())->getById($invitee->getId())->getStatus());
	}

	public function testAddMemberWithReviewerCreatesMirror(): void {
		[$container, , $reviewer] = $this->essayWithReviewer(['A' => 'have']);
		$c = $this->newWork('C');

		BundleWorkflow::addMember($container, $c, $this->editor(), 'electronic');

		$c = $this->reload($c);
		$this->assertSame($container->getId(), $c->getBundleId());
		$this->assertSame(2, (int) $c->getData('bundlePosition'));
		$mirrors = (new AssignmentDAO())->getByObjectId($c->getId());
		$this->assertCount(1, $mirrors);
		$this->assertSame($reviewer->getId(), $mirrors[0]->getBundleAssignmentId());
		$this->assertSame('electronic', $mirrors[0]->getData('copyPreference'));
		$this->assertSame('pending', $mirrors[0]->getData('copyStatus'));
		$this->assertSame(1, (int) $c->getUserId());
		$this->assertNull((new AssignmentDAO())->getById($reviewer->getId())->getData('copyPreferences'));
		$this->assertSame(['Work added to the review essay: C'], array_column(ReservationLog::getHistory($container), 'label'));
		$this->assertSame('Review essay: Essay — work added to the essay', ReservationLog::getHistory($c)[0]['label']);
	}

	public function testAddMemberPhysicalCopiesAddressFromAnotherMirror(): void {
		[$container] = $this->essayWithReviewer(['A' => 'physical', 'B' => 'have'],
			['shippingAddress' => 'Somewhere 1', 'phone' => '+49 30 123456']);
		$c = $this->newWork('C');

		BundleWorkflow::addMember($container, $c, $this->editor(), 'physical');

		$mirror = (new AssignmentDAO())->getByObjectId($c->getId())[0];
		$this->assertSame('physical', $mirror->getData('copyPreference'));
		$this->assertSame('Somewhere 1', $mirror->getData('shippingAddress'));
		$this->assertSame('+49 30 123456', $mirror->getData('phone'));
		$this->assertSame('pending', $mirror->getData('copyStatus'));
	}

	public function testAddMemberPhysicalWithoutAddressLeavesCopyPending(): void {
		[$container, , $reviewer] = $this->essayWithReviewer(['A' => 'electronic']);
		$c = $this->newWork('C');

		BundleWorkflow::addMember($container, $c, $this->editor(), 'physical');

		$mirror = (new AssignmentDAO())->getByObjectId($c->getId())[0];
		$this->assertSame('physical', $mirror->getData('copyPreference'));
		$this->assertNull($mirror->getData('shippingAddress'));
		$this->assertSame('pending', $mirror->getData('copyStatus'));
		$this->assertNull((new AssignmentDAO())->getById($reviewer->getId())->getData('shippingAddress'));
	}

	public function testAddMemberRefusesWorkWithReviewerAndSubmittedEssay(): void {
		[$container] = $this->newEssay(['A']);
		$c = $this->newWork('C');
		$this->newAssignment($c, Assignment::STATUS_ACCEPTED, 1, ['dueDate' => '2030-01-31']);
		try {
			BundleWorkflow::addMember($container, $c, $this->editor());
			$this->fail('A work with its own reviewer was added');
		} catch (\InvalidArgumentException $e) {
			$this->assertSame('plugins.generic.bookReviewManager.reviewEssay.error.twoReviewers', $e->getMessage());
		}

		$container = $this->reload($container);
		$container->setSubmissionId(424242);
		(new ObjectForReviewDAO())->updateObject($container);
		$this->expectExceptionMessage('plugins.generic.bookReviewManager.reviewEssay.error.submitted');
		BundleWorkflow::addMember($container, $this->newWork('D'), $this->editor());
	}

	public function testRemoveMemberCancelsMirrorAndHidesWork(): void {
		[$container, [$a, $b], $reviewer] = $this->essayWithReviewer(['A' => 'physical', 'B' => 'have'],
			['shippingAddress' => 'Somewhere 1']);
		$mirror = (new AssignmentDAO())->getByObjectId($a->getId())[0];
		$mirror->setData('copyStatus', 'sent');
		(new AssignmentDAO())->updateObject($mirror);
		$a = $this->reload($a);
		$a->setOfferSetting('hidden', null);
		(new ObjectForReviewDAO())->updateObject($a);

		BundleWorkflow::removeMember($container, $a, $this->editor());

		$a = $this->reload($a);
		$this->assertTrue($a->isHidden());
		$this->assertNull($a->getBundleId());
		$this->assertNull($a->getData('bundlePosition'));
		$this->assertNull($a->getUserId());
		$mirror = (new AssignmentDAO())->getById($mirror->getId());
		$this->assertSame(Assignment::STATUS_CANCELLED, $mirror->getStatus());
		$this->assertFalse($mirror->isMirror());
		$this->assertSame('sent', $mirror->getData('copyStatus'));
		$this->assertNull($mirror->getData('shippingAddress'));
		$this->assertSame([$b->getId()], array_map(fn ($m) => $m->getId(), (new ObjectForReviewDAO())->getMembers($container->getId())));
		$this->assertSame(Assignment::STATUS_ACCEPTED, (new AssignmentDAO())->getById($reviewer->getId())->getStatus());
		$this->assertSame(['Work removed from the review essay: A'], array_column(ReservationLog::getHistory($container), 'label'));
	}

	public function testRemoveLastMemberRefused(): void {
		[$container, [$a]] = $this->newEssay(['A']);
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('plugins.generic.bookReviewManager.reviewEssay.error.lastMember');
		BundleWorkflow::removeMember($container, $a, $this->editor());
	}

	public function testDissolveRefusedWithReviewer(): void {
		[$container] = $this->essayWithReviewer(['A' => 'have', 'B' => 'have']);
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('plugins.generic.bookReviewManager.reviewEssay.error.hasReviewer');
		BundleWorkflow::dissolve($container, $this->editor());
	}

	public function testDissolveRefusedWithContractOffer(): void {
		[$container] = $this->newEssay(['A', 'B']);
		$this->newAssignment($container, Assignment::STATUS_OFFERED, 1);
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('plugins.generic.bookReviewManager.reviewEssay.error.pendingEssay');
		BundleWorkflow::dissolve($container, $this->editor());
	}

	public function testCreateRefusesUnknownWork(): void {
		$a = $this->newWork('A');
		$unknown = $this->newWork('Gone');
		(new ObjectForReviewDAO())->deleteById($unknown->getId());

		$notAvailable = 'plugins.generic.bookReviewManager.reviewEssay.error.notAvailable';
		$this->assertSame($notAvailable, BundleWorkflow::canCombine([$a, null]));
		foreach ([[$a, null], [$a, $unknown]] as $works) {
			try {
				$this->createEssay($works);
				$this->fail('An unknown work was accepted');
			} catch (\InvalidArgumentException $e) {
				$this->assertSame($notAvailable, $e->getMessage());
			}
		}
		$this->assertNull($this->reload($a)->getBundleId());
	}

	public function testDissolveFreesMembers(): void {
		[$container, [$a, $b]] = $this->newEssay(['A', 'B']);
		$bid = $this->newAssignment($container, Assignment::STATUS_BID, 1, ['statement' => 'Me!']);

		BundleWorkflow::dissolve($container, $this->editor());

		$this->assertNull((new ObjectForReviewDAO())->getById($container->getId()));
		$this->assertNull((new AssignmentDAO())->getById($bid->getId()));
		foreach ([$a, $b] as $work) {
			$work = $this->reload($work);
			$this->assertNull($work->getBundleId());
			$this->assertNull($work->getData('bundlePosition'));
			$this->assertTrue($work->isHidden());
			$this->assertStringContainsString('Essay', ReservationLog::getHistory($work)[0]['label']);
		}
	}

	public function testGetReviewerAssignmentPrefersTheNewestReviewer(): void {
		[$container] = $this->newEssay(['A']);
		$this->newAssignment($container, Assignment::STATUS_COMPLETED, 1, ['dateCreated' => '2030-01-01 10:00:00']);
		$newest = $this->newAssignment($container, Assignment::STATUS_ACCEPTED, 1, ['dateCreated' => '2030-01-02 10:00:00']);
		$this->assertSame($newest->getId(), BundleWorkflow::getReviewerAssignment($container)->getId());
	}

	public function testEssayWithoutWorksIsNotOffered(): void {
		$dao = new ObjectForReviewDAO();
		[$empty] = $this->newEssay([]);
		[$essay] = $this->newEssay(['A']);
		$this->assertFalse(AssignmentWorkflow::isReservable($empty, []), 'no works: not reservable');
		$this->assertTrue(AssignmentWorkflow::isReservable($essay, []), 'with a work: reservable');

		foreach ([$empty, $essay] as $container) {
			$container = $dao->getById($container->getId());
			$container->setOfferSetting('assignmentMode', ObjectForReview::MODE_BIDDING);
			$dao->updateObject($container);
		}
		$this->assertFalse(AssignmentWorkflow::isBiddingOpen($dao->getById($empty->getId()), []), 'no works: no bids');
		$this->assertTrue(AssignmentWorkflow::isBiddingOpen($dao->getById($essay->getId()), []), 'with a work: bidding open');
	}

	//
	// The editor's actions (ReservationActionsHandler)
	//

	/** A request of the editor's dialogs for these parameters */
	protected function handlerRequest(array $vars): object {
		// The plugin registers its DAO when it is loaded, which these tests do not do
		\PKP\db\DAORegistry::registerDAO('BookReviewManagerObjectDAO', new ObjectForReviewDAO());
		$context = $this->context();
		$user = $this->editor();
		return new class($vars, $context, $user) {
			public function __construct(private array $vars, private $context, private $user) {}
			public function getUserVar($key) { return $this->vars[$key] ?? null; }
			public function getContext() { return $this->context; }
			public function getUser() { return $this->user; }
			public function isPost() { return true; }
			public function checkCSRF() { return true; }
		};
	}

	public function testHandlerRefusesMirrorsAsTargets(): void {
		[$container, [$a], $reviewer] = $this->essayWithReviewer(['A' => 'electronic', 'B' => 'have']);
		$mirror = (new AssignmentDAO())->getByObjectId($a->getId())[0];
		$this->assertTrue($mirror->isMirror());
		$handler = new \APP\plugins\generic\bookReviewManager\controllers\ReservationActionsHandler($this->plugin());
		foreach (['message', 'cancel'] as $mode) {
			[$object, $assignments] = $this->invoke($handler, 'getTarget',
				$this->handlerRequest(['objectId' => $a->getId(), 'assignmentId' => $mirror->getId()]), $mode);
			$this->assertNull($object, "{$mode}: a mirror is not a target");
			$this->assertSame([], $assignments);
		}
		[$object, $assignments] = $this->invoke($handler, 'getTarget',
			$this->handlerRequest(['objectId' => $container->getId(), 'assignmentId' => $reviewer->getId()]), 'message');
		$this->assertSame($container->getId(), $object->getId(), 'the reviewer of the essay is');
		$this->assertSame([$reviewer->getId()], array_map(fn ($assignment) => $assignment->getId(), $assignments));
	}

	public function testHandlerRefusesOfferActionsOnMembers(): void {
		[$container, [$a]] = $this->newEssay(['A', 'B']);
		$handler = new \APP\plugins\generic\bookReviewManager\controllers\ReservationActionsHandler($this->plugin());
		foreach (['closeOffer', 'switchToDirect', 'reopenBidding', 'reopenOffer'] as $action) {
			$result = $this->invoke($handler, 'offerAction', $this->handlerRequest(['objectId' => $a->getId()]), $action);
			$this->assertFalse($result->getStatus(), "{$action}: refused on a work of an essay");
		}
		$this->assertFalse((bool) $this->reload($a)->getData('offerClosed'), 'the offer of the work is unchanged');
	}
}
