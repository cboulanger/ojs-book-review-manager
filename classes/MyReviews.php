<?php

/**
 * @file plugins/generic/bookReviewManager/classes/MyReviews.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class MyReviews
 * @ingroup plugins_generic_bookReviewManager
 *
 * @brief What a user has to do for the journal's book reviews: open
 *  invitations, contract offers and reservations (reviews still to be
 *  submitted). Shown on the public page ("Your book reviews") and, for
 *  reservations, on the backend page reached from the dashboard menu.
 */

namespace APP\plugins\generic\bookReviewManager\classes;

class MyReviews {

	/** Statuses listed on the public page */
	const PUBLIC_STATUSES = [Assignment::STATUS_INVITED, Assignment::STATUS_OFFERED, Assignment::STATUS_ACCEPTED];

	/** Statuses listed on the backend page (dashboard menu) */
	const DASHBOARD_STATUSES = [Assignment::STATUS_ACCEPTED];

	/**
	 * Number of a user's open reservations (for the dashboard menu).
	 */
	public static function countReservations(int $contextId, int $userId): int {
		return count(self::rows($contextId, $userId, self::DASHBOARD_STATUSES));
	}

	/**
	 * A user's open items, invitations and offers first, then reservations by due date.
	 * @param string[] $statuses Subset of PUBLIC_STATUSES
	 * @return array[] [objectId, status, citation, identifierUrl, reviewEssay, invitationKind,
	 *  invitationDeadline, contractDeadline, dueDate, overdue, copyStatus, members]
	 */
	public static function rows(int $contextId, int $userId, array $statuses): array {
		$objectDao = new ObjectForReviewDAO();
		$today = date('Y-m-d');
		$rows = [];
		foreach (AssignmentWorkflow::dao()->getByUser($userId, $contextId, $statuses) as $assignment) {
			$object = $objectDao->getById($assignment->getObjectId());
			if (!$object || $object->getContextId() != $contextId || $object->isBundleMember()) {
				continue;
			}
			$status = $assignment->getStatus();
			// An invitation whose deadline has passed or whose offer has changed can't be answered any more
			if ($status === Assignment::STATUS_INVITED && !InvitationWorkflow::isAnswerable($object, $assignment)) {
				continue;
			}
			$dueDate = $status === Assignment::STATUS_ACCEPTED ? $assignment->getData('dueDate') : null;
			$rows[] = [
				'objectId' => $object->getId(),
				'status' => $status,
				'citation' => $object->getCitation(),
				'identifierUrl' => $object->getIdentifierUrl(),
				'reviewEssay' => $object->isReviewEssay(),
				'invitationKind' => $status === Assignment::STATUS_INVITED ? InvitationWorkflow::getKind($object) : null,
				'invitationDeadline' => $status === Assignment::STATUS_INVITED ? $assignment->getData('invitationDeadline') : null,
				'contractDeadline' => $status === Assignment::STATUS_OFFERED ? $assignment->getData('contractDeadline') : null,
				'dueDate' => $dueDate,
				'overdue' => $dueDate && substr((string) $dueDate, 0, 10) < $today,
				// For a review essay, the copy status is that of each work (members)
				'copyStatus' => $status === Assignment::STATUS_ACCEPTED && !$object->isReviewEssay() ? CopyWorkflow::getStatus($assignment) : null,
				'members' => $status === Assignment::STATUS_ACCEPTED && $object->isReviewEssay() ? self::memberRows($object, $userId) : [],
			];
		}
		$order = array_flip(self::PUBLIC_STATUSES);
		usort($rows, fn ($a, $b) => $order[$a['status']] <=> $order[$b['status']]
			?: (string) $a['dueDate'] <=> (string) $b['dueDate']);
		return $rows;
	}

	/**
	 * The works of a review essay with the user's review copy status of each.
	 */
	protected static function memberRows(ObjectForReview $essay, int $userId): array {
		return array_map(function ($member) use ($userId) {
			$mirror = AssignmentWorkflow::dao()->getByObjectAndUser($member->getId(), $userId, [Assignment::STATUS_ACCEPTED]);
			return [
				'id' => $member->getId(),
				'citation' => $member->getCitation(),
				'copyStatus' => $mirror ? CopyWorkflow::getStatus($mirror) : null,
			];
		}, BundleWorkflow::membersOf($essay));
	}
}
