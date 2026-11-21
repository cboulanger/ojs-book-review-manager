<?php

/**
 * @file plugins/generic/bookReviewManager/classes/BundleWorkflow.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class BundleWorkflow
 * @ingroup plugins_generic_bookReviewManager
 *
 * @brief Review essays: one review of several works.
 *
 *  - The container is an object for review with the resource type
 *    "reviewEssay". It owns the offer, the bids, the invitations and the
 *    reviewer's contract (the container assignment).
 *  - The members are the works reviewed in the essay. Each points to the
 *    container with the offer setting bundleId (bundlePosition orders them).
 *    A member is not offered on its own.
 *  - On each member, a mirror assignment (setting bundleAssignmentId = the
 *    container assignment's id) follows the reviewer's status and carries the
 *    review copy status of that work (copy preference, shipping, return).
 *
 *  AssignmentWorkflow calls syncMembers() after every transition of a
 *  container; running it again changes nothing.
 */

namespace APP\plugins\generic\bookReviewManager\classes;

use APP\facades\Repo;
use Illuminate\Support\Facades\DB;
use PKP\core\Core;
use PKP\user\User;

class BundleWorkflow {

	/** Locale key prefix of the errors (an exception's message is the full key) */
	const ERROR_PREFIX = 'plugins.generic.bookReviewManager.reviewEssay.error.';

	/**
	 * The review essay a work belongs to, if any.
	 */
	public static function getContainer(ObjectForReview $work): ?ObjectForReview {
		$bundleId = $work->getBundleId();
		if (!$bundleId) {
			return null;
		}
		$container = (new ObjectForReviewDAO())->getById($bundleId);
		return $container && $container->isReviewEssay() ? $container : null;
	}

	/**
	 * The works of a review essay, in order ([] for any other object).
	 * @return ObjectForReview[]
	 */
	public static function membersOf(ObjectForReview $object): array {
		return $object->isReviewEssay() ? (new ObjectForReviewDAO())->getMembers((int) $object->getId()) : [];
	}

	/**
	 * Bring the members of a review essay in line with its reviewer: create
	 * or update the mirror assignments, and copy the submission and the
	 * reviewer to the works.
	 */
	public static function syncMembers(ObjectForReview $container): void {
		DB::transaction(function () use ($container) {
			$dao = AssignmentWorkflow::dao();
			$reviewerAssignment = self::getReviewerAssignment($container);
			if (!$reviewerAssignment) {
				return;
			}
			$members = (new ObjectForReviewDAO())->getMembers($container->getId());
			$reviewer = Repo::user()->get($reviewerAssignment->getUserId(), true);
			$status = $reviewerAssignment->getStatus();

			$mirrors = [];
			foreach ($dao->getMirrors($reviewerAssignment->getId()) as $mirror) {
				$mirrors[$mirror->getObjectId()] = $mirror;
			}

			foreach ($members as $member) {
				$mirror = $mirrors[$member->getId()] ?? null;
				if ($status === Assignment::STATUS_ACCEPTED) {
					self::syncAccepted($container, $reviewerAssignment, $member, $mirror, $reviewer);
				} elseif (!$mirror && in_array($status, [Assignment::STATUS_SUBMITTED, Assignment::STATUS_COMPLETED], true)) {
					// A finished review without mirrors (e.g. imported): the works get theirs now
					self::createMirror($container, $reviewerAssignment, $member, $reviewer, $status);
				} elseif ($mirror && in_array($status, [Assignment::STATUS_SUBMITTED, Assignment::STATUS_COMPLETED], true)) {
					if ($mirror->isReviewer() && $mirror->getStatus() !== $status) {
						if ($status === Assignment::STATUS_SUBMITTED) {
							Reservation::erasePersonalData($mirror);
						}
						self::setStatus($container, $member, $mirror, $status, $reviewer);
					}
				} elseif ($mirror && $mirror->getStatus() === Assignment::STATUS_ACCEPTED) {
					// Retracted or cancelled: copies already sent stay returnable, so only the retract link ends
					Reservation::erasePersonalData($mirror);
					CopyWorkflow::onAssignmentEnded($mirror);
					self::setStatus($container, $member, $mirror, $status, $reviewer);
					ActionLink::invalidate($mirror->getId(), ActionLink::ACTION_RETRACT);
				}
			}

			if (in_array($status, [Assignment::STATUS_ACCEPTED, Assignment::STATUS_SUBMITTED, Assignment::STATUS_COMPLETED], true)) {
				// The delivery details now live on the mirrors
				$changed = false;
				foreach (['shippingAddress', 'phone', 'copyPreferences', 'copyNote'] as $field) {
					if ($reviewerAssignment->getData($field) !== null) {
						$reviewerAssignment->unsetData($field);
						$changed = true;
					}
				}
				if ($changed) {
					$dao->updateObject($reviewerAssignment);
				}
			}

			$submissionId = $container->getSubmissionId() ?: null;
			foreach ($members as $member) {
				if ((int) $member->getSubmissionId() !== (int) $submissionId) {
					$member->setSubmissionId($submissionId);
					(new ObjectForReviewDAO())->updateObject($member);
				}
				AssignmentWorkflow::syncReviewer($member);
			}
		});
	}

	//
	// Creating and changing review essays
	//

	/**
	 * Can these works be combined into a review essay (or added to one)?
	 * @param ObjectForReview[] $works (a null entry, e.g. an unknown id, is not available)
	 * @return ?string Null if allowed, otherwise the locale key of the reason
	 */
	public static function canCombine(array $works): ?string {
		$dao = AssignmentWorkflow::dao();
		$reviewers = 0;
		$pending = false;
		foreach ($works as $work) {
			if (!$work instanceof ObjectForReview) {
				return self::ERROR_PREFIX . 'notAvailable';
			}
		}
		$ids = array_map(fn (ObjectForReview $work) => $work->getId(), $works);
		if (count($ids) !== count(array_unique($ids))) {
			return self::ERROR_PREFIX . 'notAvailable';
		}
		foreach ($works as $work) {
			if ($work->isReviewEssay() || $work->isBundleMember() || $work->getSubmissionId() || $work->getData('offerClosed')) {
				return self::ERROR_PREFIX . 'notAvailable';
			}
			$assignments = $dao->getByObjectId($work->getId(), array_merge(Assignment::REVIEWER_STATUSES, [Assignment::STATUS_OFFERED]));
			foreach ($assignments as $assignment) {
				if ($assignment->getStatus() === Assignment::STATUS_OFFERED) {
					$pending = true;
				} elseif ($assignment->getStatus() === Assignment::STATUS_ACCEPTED) {
					$reviewers++;
				} else {
					// Submitted or completed: the review is (being) published on its own
					return self::ERROR_PREFIX . 'notAvailable';
				}
			}
			if (InvitationWorkflow::isRoundActive($work)) {
				$pending = true;
			}
		}
		if ($reviewers > 1) {
			return self::ERROR_PREFIX . 'twoReviewers';
		}
		return $pending ? self::ERROR_PREFIX . 'pending' : null;
	}

	/**
	 * Combine works into a new review essay. A reviewer who already has an agreement
	 * for one of the works becomes the reviewer of the essay.
	 * @param ObjectForReview[] $works In their order in the essay
	 * @param array $copyPreferences Work id => copy preference of the reviewer for the other works
	 * @return ObjectForReview The container
	 */
	public static function create($context, string $title, ?string $description, array $works, User $editor,
			array $copyPreferences = []): ObjectForReview {
		if (!$works) {
			throw new \InvalidArgumentException(self::ERROR_PREFIX . 'notAvailable');
		}
		return DB::transaction(function () use ($context, $title, $description, $works, $editor, $copyPreferences) {
			$objectDao = new ObjectForReviewDAO();
			$works = array_map(fn ($work) => $work instanceof ObjectForReview ? $objectDao->getById($work->getId()) : null, array_values($works));
			self::check(self::canCombine($works));

			$container = $objectDao->newDataObject();
			$container->setContextId($context->getId());
			$container->setResourceType(ObjectForReview::RESOURCE_TYPE_REVIEW_ESSAY);
			$container->setIdentifier('');
			$container->setIdentifierType('');
			$container->setCreator('manager');
			$container->setTitle($title);
			$container->setDescription($description);
			foreach (['assignmentMode', 'bidDeadline', 'responsibleEditorId', 'hidden'] as $name) {
				$container->setOfferSetting($name, $works[0]->getData($name));
			}
			$objectDao->insertObject($container);
			// Reload: after insertObject() date_created is not set on the object, and updateObject() would fail
			$container = $objectDao->getById($container->getId());

			$reviewerAssignment = null;
			foreach ($works as $i => $work) {
				AssignmentWorkflow::discardList($work, null, $editor);
				InvitationWorkflow::endAll($work, $editor);
				$work->setOfferSetting('bundleId', $container->getId());
				$work->setOfferSetting('bundlePosition', $i + 1);
				$objectDao->updateObject($work);
				$reviewer = AssignmentWorkflow::dao()->getReviewer($work->getId());
				if ($reviewer && $reviewer->getStatus() === Assignment::STATUS_ACCEPTED) {
					$reviewerAssignment = $reviewer;
					$reviewerWork = $work;
				}
			}

			if ($reviewerAssignment) {
				self::takeOverReviewer($container, $reviewerWork, $reviewerAssignment, $copyPreferences);
			}

			ReservationLog::logEvent($container, ReservationLog::EVENT_ESSAY_CREATED, $editor, null, self::logData($container));
			foreach ($works as $work) {
				ReservationLog::logEvent($work, ReservationLog::EVENT_ESSAY_MEMBER_ADDED, $editor, null, self::logData($container));
			}
			return $objectDao->getById($container->getId());
		});
	}

	/**
	 * The reviewer of one work becomes the reviewer of the essay: a container assignment
	 * with the same agreement, and the reviewer's assignment becomes that work's mirror.
	 */
	protected static function takeOverReviewer(ObjectForReview $container, ObjectForReview $work, Assignment $assignment,
			array $copyPreferences): void {
		$dao = AssignmentWorkflow::dao();
		$containerAssignment = $dao->newDataObject();
		$containerAssignment->setObjectId($container->getId());
		$containerAssignment->setContextId($container->getContextId());
		$containerAssignment->setUserId($assignment->getUserId());
		$containerAssignment->setStatus(Assignment::STATUS_ACCEPTED);
		$containerAssignment->setDateCreated($assignment->getDateCreated());
		foreach (['dueDate', 'dateReserved', 'reminderSent', 'shippingAddress', 'phone', 'copyNote'] as $field) {
			if ($assignment->getData($field) !== null) {
				$containerAssignment->setData($field, $assignment->getData($field));
			}
		}
		$containerAssignment->setData('copyPreferences',
			array_replace($copyPreferences, [$work->getId() => $assignment->getData('copyPreference') ?? Reservation::COPY_HAVE]));
		$dao->insertObject($containerAssignment);

		// The existing assignment is the work's mirror, so syncMembers() creates none for it
		$assignment->setData('bundleAssignmentId', $containerAssignment->getId());
		$dao->updateObject($assignment);
		// Retracting goes through the essay now
		ActionLink::invalidate($assignment->getId(), ActionLink::ACTION_RETRACT);

		self::syncMembers($container);
		AssignmentWorkflow::syncReviewer($container);
	}

	/**
	 * Add a work to a review essay that is not submitted yet.
	 * @param ?string $copyPreference The reviewer's copy preference for the work, if the essay has a reviewer
	 */
	public static function addMember(ObjectForReview $container, ObjectForReview $work, User $editor, ?string $copyPreference = null): void {
		DB::transaction(function () use ($container, $work, $editor, $copyPreference) {
			$objectDao = new ObjectForReviewDAO();
			$dao = AssignmentWorkflow::dao();
			[$container, $work] = [self::reloadContainer($container), $objectDao->getById($work->getId())];
			self::check(self::canCombine([$work]));
			$reviewer = $dao->getReviewer($work->getId());
			if ($reviewer) {
				throw new \InvalidArgumentException(self::ERROR_PREFIX . 'twoReviewers');
			}

			AssignmentWorkflow::discardList($work, null, $editor);
			InvitationWorkflow::endAll($work, $editor);
			$positions = array_map(fn ($member) => (int) $member->getData('bundlePosition'), $objectDao->getMembers($container->getId()));
			$work->setOfferSetting('bundleId', $container->getId());
			$work->setOfferSetting('bundlePosition', ($positions ? max($positions) : 0) + 1);
			$objectDao->updateObject($work);

			$containerAssignment = self::getReviewerAssignment($container);
			if ($containerAssignment && $containerAssignment->getStatus() === Assignment::STATUS_ACCEPTED) {
				$copyPreference = $copyPreference ?? Reservation::COPY_HAVE;
				$preferences = (array) ($containerAssignment->getData('copyPreferences') ?? []);
				$preferences[$work->getId()] = $copyPreference;
				$containerAssignment->setData('copyPreferences', $preferences);
				if ($copyPreference === Reservation::COPY_PHYSICAL && !$containerAssignment->getData('shippingAddress')) {
					// The address now lives on the mirrors; without one the editor asks the reviewer
					foreach ($dao->getMirrors($containerAssignment->getId()) as $mirror) {
						if ($mirror->getData('shippingAddress')) {
							$containerAssignment->setData('shippingAddress', $mirror->getData('shippingAddress'));
							$containerAssignment->setData('phone', $mirror->getData('phone'));
							break;
						}
					}
				}
				$dao->updateObject($containerAssignment);
				self::syncMembers($container);
			}

			ReservationLog::logEvent($work, ReservationLog::EVENT_ESSAY_MEMBER_ADDED, $editor, null, self::logData($container));
			ReservationLog::logEvent($container, ReservationLog::EVENT_ESSAY_MEMBER_ADDED, $editor, null, self::memberLogData($work, ReservationLog::EVENT_ESSAY_MEMBER_ADDED));
		});
	}

	/**
	 * Take a work out of a review essay that is not submitted yet. The reviewer's
	 * agreement no longer covers it; a review copy already sent can still be returned.
	 */
	public static function removeMember(ObjectForReview $container, ObjectForReview $work, User $editor): void {
		DB::transaction(function () use ($container, $work, $editor) {
			$objectDao = new ObjectForReviewDAO();
			$dao = AssignmentWorkflow::dao();
			[$container, $work] = [self::reloadContainer($container), $objectDao->getById($work->getId())];
			if ($work->getBundleId() !== $container->getId()) {
				throw new \InvalidArgumentException(self::ERROR_PREFIX . 'notAvailable');
			}
			if (count($objectDao->getMembers($container->getId())) < 2) {
				throw new \InvalidArgumentException(self::ERROR_PREFIX . 'lastMember');
			}

			foreach ($dao->getByObjectId($work->getId()) as $mirror) {
				if (!$mirror->isMirror()) {
					continue;
				}
				if ($mirror->getStatus() === Assignment::STATUS_ACCEPTED) {
					Reservation::erasePersonalData($mirror);
					CopyWorkflow::onAssignmentEnded($mirror);
					$mirror->setStatus(Assignment::STATUS_CANCELLED);
					$mirror->setDateStatus(Core::getCurrentDate());
				}
				// An ordinary ended assignment from now on
				$mirror->unsetData('bundleAssignmentId');
				$dao->updateObject($mirror);
			}

			self::release($work);
			AssignmentWorkflow::syncReviewer($work);
			ReservationLog::logEvent($work, ReservationLog::EVENT_ESSAY_MEMBER_REMOVED, $editor, null, self::logData($container));
			ReservationLog::logEvent($container, ReservationLog::EVENT_ESSAY_MEMBER_REMOVED, $editor, null, self::memberLogData($work, ReservationLog::EVENT_ESSAY_MEMBER_REMOVED));
		});
	}

	/**
	 * Dissolve a review essay without a reviewer: its works are offered on their own again (hidden at first).
	 */
	public static function dissolve(ObjectForReview $container, User $editor): void {
		DB::transaction(function () use ($container, $editor) {
			$objectDao = new ObjectForReviewDAO();
			$dao = AssignmentWorkflow::dao();
			$container = self::reloadContainer($container);
			if ($dao->getReviewer($container->getId())) {
				throw new \InvalidArgumentException(self::ERROR_PREFIX . 'hasReviewer');
			}
			if ($dao->getByObjectId($container->getId(), [Assignment::STATUS_OFFERED])) {
				throw new \InvalidArgumentException(self::ERROR_PREFIX . 'pendingEssay');
			}

			AssignmentWorkflow::discardList($container, null, $editor);
			InvitationWorkflow::endAll($container, $editor);
			$containerAssignmentIds = array_map(fn ($a) => $a->getId(), $dao->getByObjectId($container->getId()));
			foreach ($objectDao->getMembers($container->getId()) as $member) {
				// Mirrors of an earlier reviewer become ordinary ended assignments (their container assignment is deleted)
				foreach ($dao->getByObjectId($member->getId()) as $mirror) {
					if (in_array($mirror->getBundleAssignmentId(), $containerAssignmentIds, true)) {
						$mirror->unsetData('bundleAssignmentId');
						$dao->updateObject($mirror);
					}
				}
				self::release($member);
				ReservationLog::logEvent($member, ReservationLog::EVENT_ESSAY_DISSOLVED, $editor, null, self::logData($container));
			}
			$objectDao->deleteById($container->getId());
		});
	}

	protected static function check(?string $error): void {
		if ($error !== null) {
			throw new \InvalidArgumentException($error);
		}
	}

	/**
	 * The container, fresh from the database; refuses a container that is not a review essay or is already submitted.
	 */
	protected static function reloadContainer(ObjectForReview $container): ObjectForReview {
		$container = (new ObjectForReviewDAO())->getById($container->getId());
		if (!$container || !$container->isReviewEssay()) {
			throw new \InvalidArgumentException(self::ERROR_PREFIX . 'notAvailable');
		}
		if ($container->getSubmissionId()) {
			throw new \InvalidArgumentException(self::ERROR_PREFIX . 'submitted');
		}
		return $container;
	}

	/**
	 * A work leaves its essay: offered on its own again, hidden until the editor shows it.
	 */
	protected static function release(ObjectForReview $work): void {
		$work->setOfferSetting('bundleId', null);
		$work->setOfferSetting('bundlePosition', null);
		$work->setOfferSetting('hidden', true);
		(new ObjectForReviewDAO())->updateObject($work);
	}

	/**
	 * The container assignment the mirrors follow: the current reviewer (accepted, submitted
	 * or completed; the newest, as AssignmentDAO::getReviewer()), otherwise the ended
	 * assignment (retracted or cancelled) with the latest status date.
	 */
	public static function getReviewerAssignment(ObjectForReview $container): ?Assignment {
		$dao = AssignmentWorkflow::dao();
		if ($reviewer = $dao->getReviewer($container->getId())) {
			return $reviewer;
		}
		$ended = null;
		foreach ($dao->getByObjectId($container->getId(), [Assignment::STATUS_RETRACTED, Assignment::STATUS_CANCELLED]) as $assignment) {
			if (!$ended
				|| strcmp((string) $assignment->getDateStatus(), (string) $ended->getDateStatus()) > 0
				|| ((string) $assignment->getDateStatus() === (string) $ended->getDateStatus() && $assignment->getId() > $ended->getId())) {
				$ended = $assignment;
			}
		}
		return $ended;
	}

	/**
	 * Create the mirror of a member: the copy preference for the work and the delivery details
	 * from the container assignment, with the reviewer's status.
	 * @param string $status Accepted, or submitted/completed for a finished review that had no mirrors (e.g. imported)
	 */
	protected static function createMirror(ObjectForReview $container, Assignment $reviewerAssignment, ObjectForReview $member,
			?User $reviewer, string $status): void {
		$dao = AssignmentWorkflow::dao();
		$preferences = (array) ($reviewerAssignment->getData('copyPreferences') ?? []);
		$preference = $preferences[$member->getId()] ?? Reservation::COPY_HAVE;
		$mirror = $dao->newDataObject();
		$mirror->setObjectId($member->getId());
		$mirror->setContextId($member->getContextId());
		$mirror->setUserId($reviewerAssignment->getUserId());
		$mirror->setStatus(Assignment::STATUS_ACCEPTED);
		$mirror->setData('bundleAssignmentId', $reviewerAssignment->getId());
		$mirror->setData('dueDate', $reviewerAssignment->getData('dueDate'));
		$mirror->setData('dateReserved', $reviewerAssignment->getData('dateReserved'));
		$mirror->setData('copyPreference', $preference);
		if ($preference === Reservation::COPY_PHYSICAL) {
			$mirror->setData('shippingAddress', $reviewerAssignment->getData('shippingAddress'));
			$mirror->setData('phone', $reviewerAssignment->getData('phone'));
		} elseif ($preference === Reservation::COPY_OTHER) {
			$mirror->setData('copyNote', $reviewerAssignment->getData('copyNote'));
		}
		CopyWorkflow::init($mirror);
		if ($status !== Assignment::STATUS_ACCEPTED) {
			// As on attaching the review: a finished review keeps no delivery details. No copy is
			// asked for any more (as for an imported finished review of a single work).
			Reservation::erasePersonalData($mirror);
			$mirror->setData('copyStatus', CopyWorkflow::NOT_NEEDED);
			$mirror->setStatus($status);
			$mirror->setDateStatus($reviewerAssignment->getDateStatus());
		}
		$dao->insertObject($mirror);
		ReservationLog::logEvent($member, ReservationLog::EVENT_ESSAY_MIRRORED, null, $reviewer, self::logData($container));
	}

	/**
	 * The reviewer's agreement is accepted: create the member's mirror, or bring it back to "accepted" (detach).
	 */
	protected static function syncAccepted(ObjectForReview $container, Assignment $reviewerAssignment, ObjectForReview $member,
			?Assignment $mirror, ?User $reviewer): void {
		$dao = AssignmentWorkflow::dao();
		if (!$mirror) {
			self::createMirror($container, $reviewerAssignment, $member, $reviewer, Assignment::STATUS_ACCEPTED);
			return;
		}
		$changed = false;
		if ($mirror->getData('dueDate') !== $reviewerAssignment->getData('dueDate')) {
			$mirror->setData('dueDate', $reviewerAssignment->getData('dueDate'));
			$changed = true;
		}
		if ($mirror->getStatus() === Assignment::STATUS_SUBMITTED) {
			self::setStatus($container, $member, $mirror, Assignment::STATUS_ACCEPTED, $reviewer);
		} elseif ($changed) {
			$dao->updateObject($mirror);
		}
	}

	/**
	 * Change a mirror's status and log it on the member.
	 */
	protected static function setStatus(ObjectForReview $container, ObjectForReview $member, Assignment $mirror, string $status,
			?User $reviewer): void {
		$mirror->setStatus($status);
		$mirror->setDateStatus(Core::getCurrentDate());
		AssignmentWorkflow::dao()->updateObject($mirror);
		ReservationLog::logEvent($member, ReservationLog::EVENT_ESSAY_STATUS, null, $reviewer,
			self::logData($container) + ['message' => ReservationLog::EVENT_MESSAGES[ReservationLog::EVENT_ESSAY_STATUS] . '.' . $status]);
	}

	/**
	 * Log data of a mirror event. Only the event log schema's fields are stored, so the
	 * essay's title goes into recipientName (the reviewer is not named in these messages).
	 */
	protected static function logData(ObjectForReview $container): array {
		return ['recipientName' => $container->getTitle()];
	}

	/**
	 * Log data of a member event logged on the container: names the work, with the message <event>.container.
	 */
	protected static function memberLogData(ObjectForReview $work, int $eventType): array {
		return ['recipientName' => $work->getTitle(), 'message' => ReservationLog::EVENT_MESSAGES[$eventType] . '.container'];
	}
}
