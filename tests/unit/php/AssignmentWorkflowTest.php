<?php

namespace APP\plugins\generic\bookReviewManager\tests\unit;

use APP\plugins\generic\bookReviewManager\classes\Assignment;
use APP\plugins\generic\bookReviewManager\classes\AssignmentWorkflow as W;

/**
 * Offer states and the rules derived from them (no database).
 */
class AssignmentWorkflowTest extends TestCase {

	public function testStateOpen(): void {
		$this->assertSame(W::STATE_OPEN, W::getState($this->object(), []));
		$this->assertSame(W::STATE_OPEN, W::getState($this->object(), [$this->assignment(Assignment::STATUS_CANCELLED)]));
		$this->assertSame(W::STATE_OPEN, W::getState($this->bidding(), [$this->assignment(Assignment::STATUS_BID)]));
	}

	public function testStateSelectionWhenBiddingIsClosedOrOver(): void {
		$this->assertSame(W::STATE_SELECTION, W::getState($this->bidding(['biddingClosed' => true]), []));
		$this->assertSame(W::STATE_SELECTION, W::getState($this->bidding(['bidDeadline' => date('Y-m-d', strtotime('-1 day'))]), []));
		$this->assertSame(W::STATE_OPEN, W::getState($this->bidding(['bidDeadline' => date('Y-m-d')]), []), 'open on the deadline day');
	}

	public function testStateContractOffered(): void {
		$this->assertSame(W::STATE_CONTRACT_OFFERED, W::getState($this->bidding(['biddingClosed' => true]),
			[$this->assignment(Assignment::STATUS_OFFERED), $this->assignment(Assignment::STATUS_UNSUCCESSFUL)]));
	}

	public function testStateAssigned(): void {
		foreach (Assignment::REVIEWER_STATUSES as $status) {
			$this->assertSame(W::STATE_ASSIGNED, W::getState($this->object(), [$this->assignment($status)]), $status);
		}
	}

	public function testStateSubmittedAndClosedTakePrecedence(): void {
		$assignments = [$this->assignment(Assignment::STATUS_SUBMITTED)];
		$this->assertSame(W::STATE_SUBMITTED, W::getState($this->object(['submissionId' => 7]), $assignments));
		$this->assertSame(W::STATE_CLOSED, W::getState($this->object(['submissionId' => 7, 'offerClosed' => true]), $assignments));
	}

	public function testBiddingOpen(): void {
		$this->assertTrue(W::isBiddingOpen($this->bidding(), []));
		$this->assertFalse(W::isBiddingOpen($this->object(), []), 'direct mode');
		$this->assertFalse(W::isBiddingOpen($this->bidding(['biddingClosed' => true]), []));
		$this->assertFalse(W::isBiddingOpen($this->bidding(['offerClosed' => true]), []));
		$this->assertFalse(W::isBiddingOpen($this->bidding(['submissionId' => 3]), []));
		$this->assertFalse(W::isBiddingOpen($this->bidding(['bidDeadline' => '2000-01-01']), []));
		$this->assertFalse(W::isBiddingOpen($this->bidding(), [$this->assignment(Assignment::STATUS_ACCEPTED)]), 'reviewer chosen');
	}

	public function testBidDeadlinePassed(): void {
		$this->assertFalse(W::isBidDeadlinePassed($this->bidding()));
		$this->assertTrue(W::isBidDeadlinePassed($this->bidding(['bidDeadline' => '2000-01-01'])));
		$this->assertFalse(W::isBidDeadlinePassed($this->bidding(['bidDeadline' => date('Y-m-d')])));
	}

	public function testReservable(): void {
		$this->assertTrue(W::isReservable($this->object(), []));
		$this->assertFalse(W::isReservable($this->bidding(), []), 'bidding offers are not reserved directly');
		$this->assertFalse(W::isReservable($this->object(), [$this->assignment(Assignment::STATUS_ACCEPTED)]));
		$this->assertFalse(W::isReservable($this->object(['offerClosed' => true]), []));
	}

	public function testAfterDropout(): void {
		$this->assertFalse(W::isAfterDropout([$this->assignment(Assignment::STATUS_BID)]));
		foreach (Assignment::DROPOUT_STATUSES as $status) {
			$this->assertTrue(W::isAfterDropout([$this->assignment($status)]), $status);
		}
	}

	public function testFilterKeepsOrderAndReindexes(): void {
		$a = $this->assignment(Assignment::STATUS_BID);
		$b = $this->assignment(Assignment::STATUS_ACCEPTED);
		$c = $this->assignment(Assignment::STATUS_BID);
		$this->assertSame([$a, $c], W::filter([$a, $b, $c], [Assignment::STATUS_BID]));
		$this->assertSame([], W::filter([$a, $b, $c], [Assignment::STATUS_COMPLETED]));
	}

	public function testContractDays(): void {
		$this->assertSame([W::DEFAULT_CONTRACT_DAYS, W::DEFAULT_CONTRACT_REMINDER_DAYS], W::getContractDays($this->plugin(), self::CONTEXT_ID));
		$this->assertSame([21, 0], W::getContractDays($this->plugin(['ofrContractDays' => '21', 'ofrContractReminderDays' => '0']), self::CONTEXT_ID));
		$this->assertSame([1, 7], W::getContractDays($this->plugin(['ofrContractDays' => '0']), self::CONTEXT_ID), 'at least one day');
	}

	private function bidding(array $data = []) {
		return $this->object(['assignmentMode' => 'bidding'] + $data);
	}

	public function testMirrorFlag(): void {
		$this->assertFalse($this->assignment(Assignment::STATUS_ACCEPTED)->isMirror());
		$mirror = $this->assignment(Assignment::STATUS_ACCEPTED, ['bundleAssignmentId' => 12]);
		$this->assertTrue($mirror->isMirror());
		$this->assertSame(12, $mirror->getBundleAssignmentId());
	}

	public function testNewDueDateValidation(): void {
		$assignment = $this->assignment(Assignment::STATUS_ACCEPTED, ['dueDate' => '2099-01-31']);
		foreach (['', '2099-02-30', '31.01.2099', 'tomorrow'] as $bad) {
			$this->assertSame('plugins.generic.bookReviewManager.reserve.error.dueDate', W::validateNewDueDate($assignment, $bad), $bad);
		}
		$this->assertSame('plugins.generic.bookReviewManager.dueDate.error.past',
			W::validateNewDueDate($assignment, date('Y-m-d', strtotime('-1 day'))));
		$this->assertSame('plugins.generic.bookReviewManager.dueDate.error.unchanged', W::validateNewDueDate($assignment, '2099-01-31'));
		$this->assertNull(W::validateNewDueDate($assignment, '2099-03-01'));
		$this->assertNull(W::validateNewDueDate($assignment, date('Y-m-d')), 'today is allowed');
		$this->assertNull(W::validateNewDueDate($this->assignment(Assignment::STATUS_ACCEPTED), '2099-03-01'), 'no due date yet');
	}
}
