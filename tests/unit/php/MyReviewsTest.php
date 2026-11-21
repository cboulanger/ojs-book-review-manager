<?php

/**
 * @file tests/unit/php/MyReviewsTest.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @brief A user's open invitations, contract offers and reservations (writes to the database).
 */

namespace APP\plugins\generic\bookReviewManager\tests\unit;

use APP\plugins\generic\bookReviewManager\classes\Assignment;
use APP\plugins\generic\bookReviewManager\classes\AssignmentDAO;
use APP\plugins\generic\bookReviewManager\classes\BundleWorkflow;
use APP\plugins\generic\bookReviewManager\classes\MyReviews;
use APP\plugins\generic\bookReviewManager\classes\ObjectForReview;
use APP\plugins\generic\bookReviewManager\classes\ObjectForReviewDAO;
use PHPUnit\Framework\Attributes\Group;

#[Group('db')]
class MyReviewsTest extends TestCase {

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

	/** The user's rows for the works created by this test */
	protected function rowsOf(int $userId, array $statuses): array {
		return array_values(array_filter(MyReviews::rows(self::CONTEXT_ID, $userId, $statuses),
			fn ($row) => in_array($row['objectId'], $this->created, true)));
	}

	public function testListsOpenItemsInOrder(): void {
		$tomorrow = date('Y-m-d', strtotime('+1 day'));
		$later = $this->newWork('Later due');
		$this->newAssignment($later, Assignment::STATUS_ACCEPTED, 1, ['dueDate' => '2099-12-31', 'copyStatus' => 'sent']);
		$sooner = $this->newWork('Sooner due');
		$this->newAssignment($sooner, Assignment::STATUS_ACCEPTED, 1, ['dueDate' => '2000-01-01']);
		$offered = $this->newWork('Offered');
		$this->newAssignment($offered, Assignment::STATUS_OFFERED, 1, ['contractDeadline' => $tomorrow]);
		$invited = $this->newWork('Invited');
		$this->newAssignment($invited, Assignment::STATUS_INVITED, 1, ['invitationDeadline' => $tomorrow]);
		// Not listed: an expired invitation, a submitted review, a bid
		$expired = $this->newWork('Expired invitation');
		$this->newAssignment($expired, Assignment::STATUS_INVITED, 1, ['invitationDeadline' => '2000-01-01']);
		$this->newAssignment($this->newWork('Submitted'), Assignment::STATUS_SUBMITTED, 1);
		$this->newAssignment($this->newWork('Bid'), Assignment::STATUS_BID, 1);

		$rows = $this->rowsOf(1, MyReviews::PUBLIC_STATUSES);
		$this->assertSame([$invited->getId(), $offered->getId(), $sooner->getId(), $later->getId()], array_column($rows, 'objectId'));
		$this->assertSame('reserve', $rows[0]['invitationKind']);
		$this->assertSame($tomorrow, $rows[0]['invitationDeadline']);
		$this->assertSame($tomorrow, $rows[1]['contractDeadline']);
		$this->assertTrue($rows[2]['overdue']);
		$this->assertFalse($rows[3]['overdue']);
		$this->assertSame('sent', $rows[3]['copyStatus']);

		$this->assertSame([$sooner->getId(), $later->getId()],
			array_column($this->rowsOf(1, MyReviews::DASHBOARD_STATUSES), 'objectId'));
	}

	public function testOtherUsersAndJournalsAreNotListed(): void {
		$work = $this->newWork('Someone else');
		$this->newAssignment($work, Assignment::STATUS_ACCEPTED, 999999);
		$this->assertSame([], $this->rowsOf(1, MyReviews::PUBLIC_STATUSES));
		$this->assertCount(1, $this->rowsOf(999999, MyReviews::PUBLIC_STATUSES));
		$this->assertSame([], array_filter(MyReviews::rows(self::CONTEXT_ID + 1000, 999999, MyReviews::PUBLIC_STATUSES),
			fn ($row) => in_array($row['objectId'], $this->created, true)));
	}

	public function testReviewEssayIsOneItemWithItsWorks(): void {
		[$container, [$a, $b]] = $this->newEssay(['A', 'B']);
		$this->newAssignment($container, Assignment::STATUS_ACCEPTED, 1, [
			'dueDate' => '2099-12-31', 'copyPreferences' => [$a->getId() => 'physical', $b->getId() => 'electronic'],
		]);
		BundleWorkflow::syncMembers($container);

		$rows = $this->rowsOf(1, MyReviews::DASHBOARD_STATUSES);
		$this->assertSame([$container->getId()], array_column($rows, 'objectId'), 'the mirrors on the works are not listed');
		$this->assertTrue($rows[0]['reviewEssay']);
		$this->assertNull($rows[0]['copyStatus']);
		$this->assertSame([$a->getId(), $b->getId()], array_column($rows[0]['members'], 'id'));
		$this->assertSame('pending', $rows[0]['members'][0]['copyStatus']);
		$this->assertSame(1, MyReviews::countReservations(self::CONTEXT_ID, 1) - $this->countOthers(1));
	}

	/** Reservations of the user that this test didn't create (dev data) */
	protected function countOthers(int $userId): int {
		return count(array_filter(MyReviews::rows(self::CONTEXT_ID, $userId, MyReviews::DASHBOARD_STATUSES),
			fn ($row) => !in_array($row['objectId'], $this->created, true)));
	}
}
