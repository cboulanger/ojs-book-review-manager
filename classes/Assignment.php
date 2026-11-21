<?php

/**
 * @file plugins/generic/bookReviewManager/classes/Assignment.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class Assignment
 * @ingroup plugins_generic_bookReviewManager
 *
 * @brief One bidder or reviewer of an object for review. In direct reservation
 *  mode an assignment starts as "accepted"; in bidding mode it starts as a bid.
 *  The contract data (due date, copy preference, ...) is stored with the
 *  assignment (see Reservation::FIELDS).
 */

namespace APP\plugins\generic\bookReviewManager\classes;

use PKP\core\DataObject;

class Assignment extends DataObject {

	const STATUS_BID = 'bid';
	const STATUS_UNSUCCESSFUL = 'unsuccessful';
	const STATUS_OFFERED = 'offered';
	const STATUS_ACCEPTED = 'accepted';
	const STATUS_SUBMITTED = 'submitted';
	const STATUS_COMPLETED = 'completed';
	const STATUS_WITHDRAWN = 'withdrawn';
	const STATUS_DECLINED = 'declined';
	const STATUS_EXPIRED = 'expired';
	const STATUS_RETRACTED = 'retracted';
	const STATUS_CANCELLED = 'cancelled';
	const STATUS_DISCARDED = 'discarded';
	/** Invitations (see InvitationWorkflow) */
	const STATUS_LISTED = 'listed';
	const STATUS_INVITED = 'invited';
	const STATUS_INVITATION_DECLINED = 'invitationDeclined';
	const STATUS_INVITATION_EXPIRED = 'invitationExpired';
	const STATUS_INVITATION_WITHDRAWN = 'invitationWithdrawn';

	/** People on an invitation list */
	const INVITATION_STATUSES = [self::STATUS_LISTED, self::STATUS_INVITED, self::STATUS_INVITATION_DECLINED, self::STATUS_INVITATION_EXPIRED,
		self::STATUS_INVITATION_WITHDRAWN];

	/** Invitations that are not finished yet */
	const OPEN_INVITATION_STATUSES = [self::STATUS_LISTED, self::STATUS_INVITED];

	/** Bidders still on the list */
	const BIDDER_STATUSES = [self::STATUS_BID, self::STATUS_UNSUCCESSFUL];

	/** The reviewer of the object (at most one per object) */
	const REVIEWER_STATUSES = [self::STATUS_ACCEPTED, self::STATUS_SUBMITTED, self::STATUS_COMPLETED];

	/** Assignments that ended without a review (after the contract was offered or accepted) */
	const DROPOUT_STATUSES = [self::STATUS_DECLINED, self::STATUS_EXPIRED, self::STATUS_RETRACTED, self::STATUS_CANCELLED];

	/**
	 * Data stored as settings, in addition to Reservation::FIELDS: bid and
	 * contract offer, the review copy (see CopyWorkflow) and the invitation
	 * (see InvitationWorkflow).
	 */
	const FIELDS = ['statement', 'dateOffered', 'contractDeadline', 'contractReminderSent', 'declineReason',
		'copyStatus', 'copyStatusDate', 'copySentBy', 'returnTarget', 'copyReminderSent', 'copyReminderCount',
		'position', 'invitedBy', 'dateInvited', 'invitationDeadline', 'invitationDeclineReason', 'invitationReminderSent',
		'bundleAssignmentId'];

	/** A mirror carries the review copy status of one member work of a review essay. */
	function isMirror(): bool {
		return (bool) $this->getData('bundleAssignmentId');
	}

	/** Id of the container assignment this mirror belongs to. */
	function getBundleAssignmentId(): ?int {
		return $this->getData('bundleAssignmentId') ? (int) $this->getData('bundleAssignmentId') : null;
	}

	function getObjectId(): int {
		return (int) $this->getData('objectId');
	}

	function setObjectId($objectId) {
		$this->setData('objectId', (int) $objectId);
	}

	function getContextId(): int {
		return (int) $this->getData('contextId');
	}

	function setContextId($contextId) {
		$this->setData('contextId', (int) $contextId);
	}

	function getUserId(): int {
		return (int) $this->getData('userId');
	}

	function setUserId($userId) {
		$this->setData('userId', (int) $userId);
	}

	function getStatus(): string {
		return (string) $this->getData('status');
	}

	function setStatus(string $status) {
		$this->setData('status', $status);
	}

	function getDateCreated() {
		return $this->getData('dateCreated');
	}

	function setDateCreated($date) {
		$this->setData('dateCreated', $date);
	}

	function getDateStatus() {
		return $this->getData('dateStatus');
	}

	function setDateStatus($date) {
		$this->setData('dateStatus', $date);
	}

	function isBidder(): bool {
		return in_array($this->getStatus(), self::BIDDER_STATUSES, true);
	}

	function isInvitee(): bool {
		return in_array($this->getStatus(), self::INVITATION_STATUSES, true);
	}

	function isReviewer(): bool {
		return in_array($this->getStatus(), self::REVIEWER_STATUSES, true);
	}
}
