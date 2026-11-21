<?php

/**
 * @file plugins/generic/bookReviewManager/classes/AssignmentWorkflow.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class AssignmentWorkflow
 * @ingroup plugins_generic_bookReviewManager
 *
 * @brief Who writes the review of an object: direct reservation, or bidding
 *  with a contract offer to the bidder selected by the book review editor.
 *
 *  Every transition goes through this class: it checks the current status,
 *  updates the assignment and the object, and writes the audit trail.
 *  Emails that the editor confirms are sent by the calling forms; automatic
 *  notices to the editor are sent here.
 *
 *  The object's user_id is kept as the current reviewer (accepted, submitted
 *  or completed assignment), so the public list and the submission wizard
 *  keep working unchanged.
 */

namespace APP\plugins\generic\bookReviewManager\classes;

use APP\facades\Repo;
use PKP\core\Core;
use PKP\user\User;

class AssignmentWorkflow {

	const DEFAULT_CONTRACT_DAYS = 14;
	const DEFAULT_CONTRACT_REMINDER_DAYS = 7;
	const MAX_CONTRACT_DAYS = 90;

	/** Offer states, derived from the object and its assignments */
	const STATE_OPEN = 'open';
	const STATE_SELECTION = 'selection';
	const STATE_CONTRACT_OFFERED = 'contractOffered';
	const STATE_ASSIGNED = 'assigned';
	const STATE_SUBMITTED = 'submitted';
	const STATE_CLOSED = 'closed';

	public static function dao(): AssignmentDAO {
		return new AssignmentDAO();
	}

	//
	// Offer state
	//

	/**
	 * @param Assignment[] $assignments All assignments of the object
	 */
	public static function getState(ObjectForReview $object, array $assignments): string {
		if (!$object->isReviewEssay() && $object->isBundleMember()
				&& ($container = BundleWorkflow::getContainer($object)) && !$container->isBundleMember()) {
			return self::getState($container, self::dao()->getByObjectId($container->getId()));
		}
		if ($object->getData('offerClosed')) {
			return self::STATE_CLOSED;
		}
		if ($object->getSubmissionId()) {
			return self::STATE_SUBMITTED;
		}
		if (self::filter($assignments, Assignment::REVIEWER_STATUSES)) {
			return self::STATE_ASSIGNED;
		}
		if (self::filter($assignments, [Assignment::STATUS_OFFERED])) {
			return self::STATE_CONTRACT_OFFERED;
		}
		if ($object->isBidding() && !self::isBiddingOpen($object, $assignments)) {
			return self::STATE_SELECTION;
		}
		return self::STATE_OPEN;
	}

	/**
	 * Can reviewers bid for the object?
	 */
	public static function isBiddingOpen(ObjectForReview $object, array $assignments): bool {
		if ($object->isBundleMember() || !$object->isBidding() || $object->getData('offerClosed') || $object->getSubmissionId() || $object->getData('biddingClosed')) {
			return false;
		}
		if (self::filter($assignments, array_merge(Assignment::REVIEWER_STATUSES, [Assignment::STATUS_OFFERED]))) {
			return false;
		}
		return !self::isBidDeadlinePassed($object) && !self::isEmptyEssay($object);
	}

	/**
	 * A review essay without works cannot be offered.
	 */
	protected static function isEmptyEssay(ObjectForReview $object): bool {
		return $object->isReviewEssay() && !BundleWorkflow::membersOf($object);
	}

	public static function isBidDeadlinePassed(ObjectForReview $object): bool {
		$deadline = $object->getData('bidDeadline');
		return $deadline && $deadline < date('Y-m-d');
	}

	/**
	 * Can the object be reserved directly?
	 */
	public static function isReservable(ObjectForReview $object, array $assignments): bool {
		return !$object->isBundleMember()
			&& !$object->isBidding()
			&& self::getState($object, $assignments) === self::STATE_OPEN
			&& !self::isEmptyEssay($object);
	}

	/**
	 * Has a selected or assigned reviewer dropped out before (for the "offer available again" email)?
	 */
	public static function isAfterDropout(array $assignments): bool {
		return (bool) self::filter($assignments, Assignment::DROPOUT_STATUSES);
	}

	/**
	 * @return Assignment[] Assignments with one of the statuses
	 */
	public static function filter(array $assignments, array $statuses): array {
		return array_values(array_filter($assignments, fn (Assignment $a) => in_array($a->getStatus(), $statuses, true)));
	}

	/**
	 * Days until a contract offer expires, and after which the reminder is sent (0: no reminder).
	 * @return array [deadlineDays, reminderDays]
	 */
	public static function getContractDays($plugin, int $contextId): array {
		$deadline = $plugin->getSetting($contextId, 'ofrContractDays');
		$reminder = $plugin->getSetting($contextId, 'ofrContractReminderDays');
		return [
			$deadline === null ? self::DEFAULT_CONTRACT_DAYS : max(1, (int) $deadline),
			$reminder === null ? self::DEFAULT_CONTRACT_REMINDER_DAYS : (int) $reminder,
		];
	}

	//
	// Transitions
	//

	/**
	 * Direct reservation: the reviewer accepts the contract on the public page,
	 * or through an invitation link.
	 * @param ?Assignment $invitation The user's invitation, which becomes the reservation
	 */
	public static function reserve($plugin, $context, ObjectForReview $object, User $user, array $data, ?Assignment $invitation = null): Assignment {
		// Through the invitation link, or an invitation record reused for a reservation/bid on the public page
		$viaInvitation = $invitation !== null;
		$invitation ??= self::dao()->getByObjectAndUser($object->getId(), $user->getId(), Assignment::INVITATION_STATUSES);
		$assignment = $invitation ?? self::dao()->newDataObject();
		$assignment->setObjectId($object->getId());
		$assignment->setContextId($context->getId());
		$assignment->setUserId($user->getId());
		$assignment->setStatus(Assignment::STATUS_ACCEPTED);
		Reservation::apply($assignment, $data);
		if (!$object->isReviewEssay()) {
			CopyWorkflow::init($assignment);
		}
		if ($invitation) {
			// The reservation or bid dates from now; the invitation date stays in dateInvited
			$assignment->setDateCreated(Core::getCurrentDate());
			$assignment->setDateStatus(Core::getCurrentDate());
			self::dao()->updateObject($assignment);
			ActionLink::invalidate($assignment->getId(), ActionLink::ACTION_INVITATION);
		} else {
			self::dao()->insertObject($assignment);
		}
		self::syncReviewer($object);
		self::syncBundle($object);
		ReservationLog::logEvent($object, $viaInvitation ? ReservationLog::EVENT_RESERVED_VIA_INVITATION : ReservationLog::EVENT_RESERVED,
			$user, $user, ['reviewDueDate' => $data['dueDate']]);
		InvitationWorkflow::onReserved($object, $assignment);
		$plugin->notifyEditor($user, $object, 'BRM_NEW_RESERVATION', Reservation::getEmailDetails($assignment, $context));
		self::sendConfirmation($plugin, $context, $object, $assignment, $user);
		return $assignment;
	}

	/**
	 * A reviewer bids for an object (bidding mode), on the public page or through an invitation link.
	 * @param ?Assignment $invitation The user's invitation, which becomes the bid
	 */
	public static function bid($plugin, $context, ObjectForReview $object, User $user, string $statement, ?Assignment $invitation = null): Assignment {
		// Through the invitation link, or an invitation record reused for a reservation/bid on the public page
		$viaInvitation = $invitation !== null;
		$invitation ??= self::dao()->getByObjectAndUser($object->getId(), $user->getId(), Assignment::INVITATION_STATUSES);
		$assignment = $invitation ?? self::dao()->newDataObject();
		$assignment->setObjectId($object->getId());
		$assignment->setContextId($context->getId());
		$assignment->setUserId($user->getId());
		$assignment->setStatus(Assignment::STATUS_BID);
		if ($statement !== '') {
			$assignment->setData('statement', $statement);
		}
		if ($invitation) {
			// The reservation or bid dates from now; the invitation date stays in dateInvited
			$assignment->setDateCreated(Core::getCurrentDate());
			$assignment->setDateStatus(Core::getCurrentDate());
			self::dao()->updateObject($assignment);
			ActionLink::invalidate($assignment->getId(), ActionLink::ACTION_INVITATION);
		} else {
			self::dao()->insertObject($assignment);
		}
		ReservationLog::logEvent($object, $viaInvitation ? ReservationLog::EVENT_BID_VIA_INVITATION : ReservationLog::EVENT_BID, $user, $user);
		ReservationMailer::notifyEditor($plugin, $context, ReservationEmailEventType::BID_NEW, $object, $assignment, [
			'bidderName' => htmlspecialchars($user->getFullName()),
			'bidStatement' => $statement !== '' ? nl2br(htmlspecialchars($statement)) : '–',
		]);
		return $assignment;
	}

	/**
	 * Confirmation to the reviewer, with the agreement details and the
	 * "I can't write this review" link.
	 */
	protected static function sendConfirmation($plugin, $context, ObjectForReview $object, Assignment $assignment, User $reviewer): void {
		ReservationMailer::ensureTemplates($plugin, $context->getId());
		[$subject, $body] = ReservationMailer::compose($context, ReservationEmailEventType::CONFIRMATION, $object, $assignment, $reviewer, null, [
			'reservationDetails' => Reservation::getEmailDetails($assignment, $context),
		]);
		ReservationMailer::send($context, ReservationEmailEventType::CONFIRMATION, $object, $reviewer, null, $subject, $body, [], $assignment);
	}

	/**
	 * A bidder withdraws the bid, or leaves the list (bid statement is deleted).
	 */
	public static function withdrawBid(ObjectForReview $object, Assignment $assignment, User $user): void {
		self::requireStatus($assignment, Assignment::BIDDER_STATUSES);
		$assignment->unsetData('statement');
		self::setStatus($assignment, Assignment::STATUS_WITHDRAWN);
		ActionLink::invalidate($assignment->getId());
		ReservationLog::logEvent($object, ReservationLog::EVENT_BID_WITHDRAWN, $user, $user);
	}

	/**
	 * The editor selects a bidder: bidding closes and the bidder gets a contract offer.
	 * @return string Key of the one-click contract link
	 */
	public static function offerContract($plugin, $context, ObjectForReview $object, Assignment $assignment, User $editor): string {
		self::requireStatus($assignment, Assignment::BIDDER_STATUSES);
		[$days] = self::getContractDays($plugin, $context->getId());
		$deadline = (new \DateTimeImmutable('today'))->modify("+{$days} days")->format('Y-m-d');
		$assignment->setData('dateOffered', Core::getCurrentDate());
		$assignment->setData('contractDeadline', $deadline);
		$assignment->unsetData('contractReminderSent');
		$assignment->unsetData('declineReason');
		self::setStatus($assignment, Assignment::STATUS_OFFERED);

		$object->setOfferSetting('biddingClosed', true);
		(new ObjectForReviewDAO())->updateObject($object);
		InvitationWorkflow::onOfferChanged($object, $editor);

		$reviewer = Repo::user()->get($assignment->getUserId(), true);
		ReservationLog::logEvent($object, ReservationLog::EVENT_CONTRACT_OFFERED, $editor, $reviewer);
		// Valid until the end of the deadline day
		return ActionLink::create($assignment->getId(), ActionLink::ACTION_CONTRACT, $deadline . ' 23:59:59');
	}

	/**
	 * The selected bidder accepts the contract (due date, copy preference, ...).
	 */
	public static function acceptContract($plugin, $context, ObjectForReview $object, Assignment $assignment, array $data): void {
		self::requireStatus($assignment, [Assignment::STATUS_OFFERED]);
		Reservation::apply($assignment, $data);
		if (!$object->isReviewEssay()) {
			CopyWorkflow::init($assignment);
		}
		self::setStatus($assignment, Assignment::STATUS_ACCEPTED);
		ActionLink::invalidate($assignment->getId(), ActionLink::ACTION_CONTRACT);
		self::syncReviewer($object);
		self::syncBundle($object);
		$reviewer = Repo::user()->get($assignment->getUserId(), true);
		ReservationLog::logEvent($object, ReservationLog::EVENT_CONTRACT_ACCEPTED, $reviewer, $reviewer, ['reviewDueDate' => $data['dueDate']]);
		InvitationWorkflow::onReserved($object, $assignment);
		$plugin->notifyEditor($reviewer, $object, 'BRM_NEW_RESERVATION', Reservation::getEmailDetails($assignment, $context));
		self::sendConfirmation($plugin, $context, $object, $assignment, $reviewer);
	}

	/**
	 * The selected bidder declines the contract offer.
	 */
	public static function declineContract($plugin, $context, ObjectForReview $object, Assignment $assignment, string $reason): void {
		self::requireStatus($assignment, [Assignment::STATUS_OFFERED]);
		if ($reason !== '') {
			$assignment->setData('declineReason', $reason);
		}
		self::setStatus($assignment, Assignment::STATUS_DECLINED);
		ActionLink::invalidate($assignment->getId());
		$reviewer = Repo::user()->get($assignment->getUserId(), true);
		ReservationLog::logEvent($object, ReservationLog::EVENT_CONTRACT_DECLINED, $reviewer, $reviewer);
		ReservationMailer::notifyEditor($plugin, $context, ReservationEmailEventType::CONTRACT_DECLINED, $object, $assignment, [
			'reviewerName' => htmlspecialchars($reviewer?->getFullName() ?? ''),
			'declineReason' => $reason !== '' ? nl2br(htmlspecialchars($reason))
				: __('plugins.generic.bookReviewManager.contract.declined.noReason', [], $context->getPrimaryLocale()),
		]);
	}

	/**
	 * A contract offer was not answered in time (daily task).
	 */
	public static function expireContract($plugin, $context, ObjectForReview $object, Assignment $assignment): void {
		self::requireStatus($assignment, [Assignment::STATUS_OFFERED]);
		self::setStatus($assignment, Assignment::STATUS_EXPIRED);
		ActionLink::invalidate($assignment->getId());
		$reviewer = Repo::user()->get($assignment->getUserId(), true);
		ReservationLog::logEvent($object, ReservationLog::EVENT_CONTRACT_EXPIRED, null, $reviewer);
		ReservationMailer::notifyEditor($plugin, $context, ReservationEmailEventType::CONTRACT_DECLINED, $object, $assignment, [
			'reviewerName' => htmlspecialchars($reviewer?->getFullName() ?? ''),
			'declineReason' => __('plugins.generic.bookReviewManager.contract.expired', [], $context->getPrimaryLocale()),
		]);
	}

	/**
	 * The editor cancels an assignment: a pending contract offer or an accepted reservation.
	 */
	public static function cancelByEditor(ObjectForReview $object, Assignment $assignment, User $editor): void {
		self::requireStatus($assignment, [Assignment::STATUS_OFFERED, Assignment::STATUS_ACCEPTED]);
		$wasOffered = $assignment->getStatus() === Assignment::STATUS_OFFERED;
		Reservation::erasePersonalData($assignment);
		CopyWorkflow::onAssignmentEnded($assignment);
		self::setStatus($assignment, Assignment::STATUS_CANCELLED);
		ActionLink::invalidate($assignment->getId());
		self::syncReviewer($object);
		$reviewer = Repo::user()->get($assignment->getUserId(), true);
		ReservationLog::logEvent($object, $wasOffered ? ReservationLog::EVENT_CONTRACT_WITHDRAWN : ReservationLog::EVENT_CANCELLED_BY_EDITOR,
			$editor, $reviewer);
		self::syncBundle($object);
	}

	/**
	 * The reviewer retracts (cancels the reservation on the public page).
	 */
	public static function retract($plugin, ObjectForReview $object, Assignment $assignment, User $user, bool $viaLink = false): void {
		self::requireStatus($assignment, [Assignment::STATUS_ACCEPTED]);
		Reservation::erasePersonalData($assignment);
		CopyWorkflow::onAssignmentEnded($assignment);
		self::setStatus($assignment, Assignment::STATUS_RETRACTED);
		ActionLink::invalidate($assignment->getId());
		self::syncReviewer($object);
		ReservationLog::logEvent($object, ReservationLog::EVENT_CANCELLED_BY_REVIEWER, $user, $user, $viaLink ? ReservationLog::viaLink($user) : []);
		$plugin->notifyEditor($user, $object, 'BRM_CANCEL_RESERVATION');
		self::syncBundle($object);
	}

	/**
	 * A remaining bidder has been told that someone else writes the review (stays on the list).
	 */
	public static function markUnsuccessful(ObjectForReview $object, Assignment $assignment, User $editor): void {
		self::requireStatus($assignment, [Assignment::STATUS_BID]);
		self::setStatus($assignment, Assignment::STATUS_UNSUCCESSFUL);
		ReservationLog::logEvent($object, ReservationLog::EVENT_BIDDER_UNSUCCESSFUL, $editor, Repo::user()->get($assignment->getUserId(), true));
	}

	/**
	 * The reviewer attached the object to their review submission.
	 */
	public static function attach(ObjectForReview $object, Assignment $assignment, User $user, int $submissionId): void {
		self::requireStatus($assignment, [Assignment::STATUS_ACCEPTED]);
		Reservation::erasePersonalData($assignment);
		self::setStatus($assignment, Assignment::STATUS_SUBMITTED);
		$object->setSubmissionId($submissionId);
		(new ObjectForReviewDAO())->updateObject($object);
		ReservationLog::logEvent($object, ReservationLog::EVENT_ATTACHED, $user, $user, ['submissionId' => $submissionId]);
		self::syncBundle($object);
	}

	/**
	 * The object was removed from the submission again: the reviewer is back to "accepted".
	 */
	public static function detach(ObjectForReview $object): void {
		$object->setSubmissionId(null);
		(new ObjectForReviewDAO())->updateObject($object);
		foreach (self::dao()->getByObjectId($object->getId(), [Assignment::STATUS_SUBMITTED]) as $assignment) {
			self::setStatus($assignment, Assignment::STATUS_ACCEPTED);
		}
		self::syncBundle($object);
	}

	/**
	 * The review was published: the assignment is completed and the list of
	 * bidders is discarded (statements deleted).
	 */
	public static function complete(ObjectForReview $object): void {
		$assignments = self::dao()->getByObjectId($object->getId());
		foreach (self::filter($assignments, [Assignment::STATUS_SUBMITTED]) as $assignment) {
			self::setStatus($assignment, Assignment::STATUS_COMPLETED);
			ReservationLog::logEvent($object, ReservationLog::EVENT_COMPLETED, null, Repo::user()->get($assignment->getUserId(), true));
		}
		self::discardList($object, $assignments);
		InvitationWorkflow::endAll($object);
		self::syncBundle($object);
	}

	/**
	 * Close the remaining bidders' entries (review published or offer closed).
	 */
	public static function discardList(ObjectForReview $object, ?array $assignments = null, ?User $editor = null): void {
		$bidders = self::filter($assignments ?? self::dao()->getByObjectId($object->getId()), Assignment::BIDDER_STATUSES);
		foreach ($bidders as $assignment) {
			$assignment->unsetData('statement');
			self::setStatus($assignment, Assignment::STATUS_DISCARDED);
			ActionLink::invalidate($assignment->getId());
		}
		if ($bidders) {
			ReservationLog::logEvent($object, ReservationLog::EVENT_LIST_DISCARDED, $editor, null, ['recipientCount' => count($bidders)]);
		}
	}

	/**
	 * The editor reopens bidding (e.g. when nobody on the list is suitable).
	 */
	public static function reopenBidding(ObjectForReview $object, User $editor, ?string $bidDeadline): void {
		$object->setOfferSetting('assignmentMode', ObjectForReview::MODE_BIDDING);
		$object->setOfferSetting('biddingClosed', null);
		$object->setOfferSetting('bidDeadline', $bidDeadline);
		(new ObjectForReviewDAO())->updateObject($object);
		ReservationLog::logEvent($object, ReservationLog::EVENT_BIDDING_REOPENED, $editor, null);
		InvitationWorkflow::onOfferChanged($object, $editor);
	}

	/**
	 * The editor switches an offer to direct reservation.
	 */
	public static function switchToDirect(ObjectForReview $object, User $editor): void {
		$object->setOfferSetting('assignmentMode', ObjectForReview::MODE_DIRECT);
		$object->setOfferSetting('biddingClosed', null);
		(new ObjectForReviewDAO())->updateObject($object);
		ReservationLog::logEvent($object, ReservationLog::EVENT_MODE_DIRECT, $editor, null);
		InvitationWorkflow::onOfferChanged($object, $editor);
	}

	public static function closeOffer(ObjectForReview $object, User $editor): void {
		$object->setOfferSetting('offerClosed', true);
		(new ObjectForReviewDAO())->updateObject($object);
		ReservationLog::logEvent($object, ReservationLog::EVENT_OFFER_CLOSED, $editor, null);
		self::discardList($object, null, $editor);
		InvitationWorkflow::endAll($object, $editor);
	}

	/**
	 * Hide the offer from the public list, or show it again.
	 */
	public static function setHidden(ObjectForReview $object, bool $hidden, User $editor): void {
		if ($object->isHidden() === $hidden) {
			return;
		}
		$object->setOfferSetting('hidden', $hidden);
		(new ObjectForReviewDAO())->updateObject($object);
		ReservationLog::logEvent($object, $hidden ? ReservationLog::EVENT_OFFER_HIDDEN : ReservationLog::EVENT_OFFER_SHOWN, $editor, null);
	}

	public static function reopenOffer(ObjectForReview $object, User $editor): void {
		$object->setOfferSetting('offerClosed', null);
		$object->setOfferSetting('biddingClosed', null);
		(new ObjectForReviewDAO())->updateObject($object);
		ReservationLog::logEvent($object, ReservationLog::EVENT_OFFER_REOPENED, $editor, null);
	}

	//
	// Helpers
	//

	/**
	 * Keep the object's user_id in sync with its reviewer.
	 */
	public static function syncReviewer(ObjectForReview $object): void {
		$reviewer = self::dao()->getReviewer($object->getId());
		$userId = $reviewer?->getUserId();
		if ((int) $object->getUserId() !== (int) $userId) {
			$object->setUserId($userId);
			(new ObjectForReviewDAO())->updateObject($object);
		}
	}

	/**
	 * Bring the works of a review essay in line with its reviewer (see BundleWorkflow).
	 */
	protected static function syncBundle(ObjectForReview $object): void {
		if ($object->isReviewEssay()) {
			BundleWorkflow::syncMembers($object);
		}
	}

	protected static function setStatus(Assignment $assignment, string $status): void {
		$assignment->setStatus($status);
		$assignment->setDateStatus(Core::getCurrentDate());
		self::dao()->updateObject($assignment);
	}

	protected static function requireStatus(Assignment $assignment, array $statuses): void {
		if (!in_array($assignment->getStatus(), $statuses, true)) {
			throw new \InvalidArgumentException('Assignment ' . $assignment->getId() . ' has status ' . $assignment->getStatus());
		}
	}
}
