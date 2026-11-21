<?php

/**
 * @file plugins/generic/bookReviewManager/classes/CopyWorkflow.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class CopyWorkflow
 * @ingroup plugins_generic_bookReviewManager
 *
 * @brief How the reviewer gets the work, and what happens to a physical copy
 *  when no review materialises. The copy status belongs to the assignment:
 *
 *  notNeeded       reviewer has the work already, or "other"
 *  pending         physical or electronic copy needed, nothing done yet
 *  requested       requested from the publisher contact
 *  sent            sent by the journal (from stock) or by the publisher
 *  received        reviewer has the copy (address and phone are deleted)
 *  returnRequested reviewer asked to return a physical copy (to the journal or the publisher)
 *  returnSent      reviewer has sent it back
 *  returned        the journal or the publisher has it back
 *
 *  Each step is recorded by the book review editor (STEPS), optionally with a
 *  prefilled email, and written to the audit trail. A copy goes back to the
 *  journal or the publisher, never directly to another reviewer.
 */

namespace APP\plugins\generic\bookReviewManager\classes;

use APP\core\Application;
use APP\facades\Repo;
use PKP\core\Core;
use PKP\user\User;

class CopyWorkflow {

	const NOT_NEEDED = 'notNeeded';
	const PENDING = 'pending';
	const REQUESTED = 'requested';
	const SENT = 'sent';
	const RECEIVED = 'received';
	const RETURN_REQUESTED = 'returnRequested';
	const RETURN_SENT = 'returnSent';
	const RETURNED = 'returned';

	const DEFAULT_PUBLISHER_REMINDER_DAYS = 14;
	const MAX_PUBLISHER_REMINDER_DAYS = 90;

	const BY_JOURNAL = 'journal';
	const BY_PUBLISHER = 'publisher';

	/**
	 * Steps the editor records:
	 *  from: copy statuses in which the step is possible
	 *  to: resulting status
	 *  email: email type, or null
	 *  recipient: 'reviewer' | 'publisher'
	 *  emailRequired: the step consists in sending the email
	 */
	const STEPS = [
		'requestCopy' => ['from' => [self::PENDING], 'to' => self::REQUESTED,
			'email' => ReservationEmailEventType::COPY_REQUEST, 'recipient' => 'publisher', 'emailRequired' => true],
		'sendFromStock' => ['from' => [self::PENDING], 'to' => self::SENT,
			'email' => ReservationEmailEventType::COPY_SENT, 'recipient' => 'reviewer', 'emailRequired' => false],
		'publisherSent' => ['from' => [self::REQUESTED], 'to' => self::SENT,
			'email' => ReservationEmailEventType::COPY_SENT, 'recipient' => 'reviewer', 'emailRequired' => false],
		'markReceived' => ['from' => [self::SENT], 'to' => self::RECEIVED, 'email' => null],
		'withdrawRequest' => ['from' => [self::REQUESTED], 'to' => self::NOT_NEEDED, 'email' => null],
		'requestReturn' => ['from' => [self::SENT, self::RECEIVED], 'to' => self::RETURN_REQUESTED,
			'email' => ReservationEmailEventType::RETURN_REQUEST, 'recipient' => 'reviewer', 'emailRequired' => false],
		'returnSent' => ['from' => [self::RETURN_REQUESTED], 'to' => self::RETURN_SENT,
			'email' => ReservationEmailEventType::RETURN_SENT, 'recipient' => 'publisher', 'emailRequired' => false],
		'returned' => ['from' => [self::RETURN_REQUESTED, self::RETURN_SENT], 'to' => self::RETURNED, 'email' => null],
	];

	/** Statuses that need the editor's attention or are waiting for someone */
	const OPEN_STATUSES = [self::PENDING, self::REQUESTED, self::SENT, self::RETURN_REQUESTED, self::RETURN_SENT];

	/** Assignment statuses with a copy that may have to be returned */
	const RETURNABLE_ASSIGNMENT_STATUSES = [Assignment::STATUS_ACCEPTED, Assignment::STATUS_RETRACTED, Assignment::STATUS_CANCELLED];

	/**
	 * Initial copy status when the contract is accepted.
	 */
	public static function init(Assignment $assignment): void {
		$needsCopy = in_array($assignment->getData('copyPreference'), [Reservation::COPY_PHYSICAL, Reservation::COPY_ELECTRONIC], true);
		$assignment->setData('copyStatus', $needsCopy ? self::PENDING : self::NOT_NEEDED);
		$assignment->setData('copyStatusDate', Core::getCurrentDate());
	}

	/**
	 * The assignment ended (retracted or cancelled): a copy nobody has asked for yet is no longer needed.
	 */
	public static function onAssignmentEnded(Assignment $assignment): void {
		if ($assignment->getData('copyStatus') === self::PENDING) {
			$assignment->setData('copyStatus', self::NOT_NEEDED);
			$assignment->setData('copyStatusDate', Core::getCurrentDate());
		}
	}

	/**
	 * Days after which (and between which) the publisher is reminded of an unanswered copy request (0: never).
	 */
	public static function getPublisherReminderDays($plugin, int $contextId): int {
		$days = $plugin->getSetting($contextId, 'ofrPublisherReminderDays');
		return $days === null ? self::DEFAULT_PUBLISHER_REMINDER_DAYS : (int) $days;
	}

	public static function getStatus(Assignment $assignment): ?string {
		return $assignment->getData('copyStatus') ?: null;
	}

	public static function isPhysical(Assignment $assignment): bool {
		return $assignment->getData('copyPreference') === Reservation::COPY_PHYSICAL;
	}

	/**
	 * Does the copy need the editor's attention (shown on the To-do page)?
	 */
	public static function isOpen(ObjectForReview $object, Assignment $assignment): bool {
		$status = self::getStatus($assignment);
		if (in_array($status, self::OPEN_STATUSES, true)) {
			return true;
		}
		// A physical copy with a reviewer who dropped out
		return $status === self::RECEIVED && $assignment->getStatus() !== Assignment::STATUS_ACCEPTED
			&& self::canRequestReturn($object, $assignment);
	}

	/**
	 * Steps the editor can take now.
	 * @return string[] Step names
	 */
	public static function getAllowedSteps($plugin, ObjectForReview $object, Assignment $assignment): array {
		$status = self::getStatus($assignment);
		$active = $assignment->getStatus() === Assignment::STATUS_ACCEPTED;
		$steps = [];
		foreach (self::STEPS as $step => $definition) {
			if (!in_array($status, $definition['from'], true)) {
				continue;
			}
			$allowed = match ($step) {
				'requestCopy' => $active && $object->getPublisherContactId(),
				'sendFromStock' => $active && $object->getData('journalHoldsCopy'),
				'requestReturn' => self::canRequestReturn($object, $assignment)
					&& array_filter(self::getReturnTargets($plugin, $object)),
				default => true,
			};
			if ($allowed) {
				$steps[] = $step;
			}
		}
		return $steps;
	}

	protected static function canRequestReturn(ObjectForReview $object, Assignment $assignment): bool {
		return self::isPhysical($assignment)
			&& $object->isReturnRequired()
			&& in_array($assignment->getStatus(), self::RETURNABLE_ASSIGNMENT_STATUSES, true);
	}

	/**
	 * Take a step.
	 * @param array $options ['returnTarget' => journal|publisher] for requestReturn
	 */
	public static function apply($plugin, $context, ObjectForReview $object, Assignment $assignment, string $step, User $actor,
			array $options = [], bool $viaLink = false): void {
		if (!in_array($step, self::getAllowedSteps($plugin, $object, $assignment), true)) {
			throw new \InvalidArgumentException("Copy step {$step} is not possible for assignment " . $assignment->getId());
		}
		$reviewer = Repo::user()->get($assignment->getUserId(), true);
		$event = null;
		switch ($step) {
			case 'requestCopy':
				$event = ReservationLog::EVENT_COPY_REQUESTED;
				break;
			case 'sendFromStock':
				$assignment->setData('copySentBy', self::BY_JOURNAL);
				// The journal's copy has left the stock (it is back when returned to the journal)
				$object->setOfferSetting('journalHoldsCopy', null);
				(new ObjectForReviewDAO())->updateObject($object);
				$event = ReservationLog::EVENT_COPY_SENT_JOURNAL;
				break;
			case 'publisherSent':
				$assignment->setData('copySentBy', self::BY_PUBLISHER);
				$event = ReservationLog::EVENT_COPY_SENT_PUBLISHER;
				break;
			case 'markReceived':
				// The address and phone number are no longer needed
				Reservation::erasePersonalData($assignment);
				$event = ReservationLog::EVENT_COPY_RECEIVED;
				break;
			case 'withdrawRequest':
				$event = ReservationLog::EVENT_COPY_REQUEST_WITHDRAWN;
				break;
			case 'requestReturn':
				$target = ($options['returnTarget'] ?? '') === self::BY_JOURNAL ? self::BY_JOURNAL : self::BY_PUBLISHER;
				$assignment->setData('returnTarget', $target);
				$event = $target === self::BY_JOURNAL ? ReservationLog::EVENT_RETURN_REQUESTED_JOURNAL : ReservationLog::EVENT_RETURN_REQUESTED_PUBLISHER;
				break;
			case 'returnSent':
				$event = ReservationLog::EVENT_RETURN_SENT;
				break;
			case 'returned':
				if ($assignment->getData('returnTarget') === self::BY_JOURNAL) {
					$object->setOfferSetting('journalHoldsCopy', true);
					(new ObjectForReviewDAO())->updateObject($object);
					$event = ReservationLog::EVENT_RETURNED_JOURNAL;
				} else {
					$event = ReservationLog::EVENT_RETURNED_PUBLISHER;
				}
				break;
		}
		$assignment->setData('copyStatus', self::STEPS[$step]['to']);
		$assignment->setData('copyStatusDate', Core::getCurrentDate());
		$assignment->unsetData('copyReminderSent');
		$assignment->unsetData('copyReminderCount');
		AssignmentWorkflow::dao()->updateObject($assignment);
		ReservationLog::logEvent($object, $event, $actor, $reviewer, $viaLink ? ReservationLog::viaLink($actor) : []);

		// No review will come: requesting the return ends a current reservation
		if ($step === 'requestReturn' && $assignment->getStatus() === Assignment::STATUS_ACCEPTED) {
			AssignmentWorkflow::cancelByEditor($object, $assignment, $actor);
		}
	}

	/**
	 * The reviewer confirms receipt on the public page.
	 */
	public static function confirmReceipt($plugin, $context, ObjectForReview $object, Assignment $assignment, User $reviewer): void {
		self::apply($plugin, $context, $object, $assignment, 'markReceived', $reviewer);
	}

	/**
	 * Template variables of the copy emails.
	 * @param ?string $step The step the email belongs to (determines who sent the copy)
	 */
	public static function getEmailParams($context, ObjectForReview $object, Assignment $assignment, ?string $step): array {
		$locale = $context->getPrimaryLocale();
		$reviewer = Repo::user()->get($assignment->getUserId(), true);
		$preference = $assignment->getData('copyPreference');
		$sentBy = match ($step) {
			'sendFromStock' => self::BY_JOURNAL,
			'publisherSent' => self::BY_PUBLISHER,
			default => $assignment->getData('copySentBy'),
		};
		return [
			'reviewerName' => htmlspecialchars($reviewer?->getFullName() ?? ''),
			'copyType' => $preference ? htmlspecialchars(__('plugins.generic.bookReviewManager.copy.type.' . $preference, [], $locale)) : '',
			'copySource' => $sentBy ? htmlspecialchars(__('plugins.generic.bookReviewManager.copy.source.' . $sentBy, [], $locale)) : '',
			'deliveryDetails' => self::getDeliveryDetails($assignment, $reviewer, $locale),
		];
	}

	/**
	 * Send a copy email automatically (from the journal contact), e.g. after a
	 * one-click link was used, including the next one-click link.
	 */
	public static function sendAutomatic($plugin, $context, ObjectForReview $object, Assignment $assignment,
			ReservationEmailEventType $type, User $recipient, ?string $step = null): void {
		ReservationMailer::ensureTemplates($plugin, $context->getId());
		[$subject, $body] = ReservationMailer::compose($context, $type, $object, $assignment, $recipient, null,
			self::getEmailParams($context, $object, $assignment, $step));
		ReservationMailer::send($context, $type, $object, $recipient, null, $subject, $body, [], $assignment);
	}

	/**
	 * Where a returned copy can go, with the address (null if no address is entered).
	 * The journal: the responsible book review editor (or, without one, the journal's
	 * mailing address). The publisher: the publisher contact.
	 * @return array ['journal' => ?string, 'publisher' => ?string] Plain text addresses
	 */
	public static function getReturnTargets($plugin, ObjectForReview $object): array {
		$targets = [self::BY_JOURNAL => null, self::BY_PUBLISHER => null];
		$editor = $object->getResponsibleEditorId() ? Repo::user()->get($object->getResponsibleEditorId(), true) : null;
		if ($editor) {
			if (trim((string) $editor->getMailingAddress()) !== '') {
				$targets[self::BY_JOURNAL] = $editor->getFullName() . "\n" . trim($editor->getMailingAddress());
			}
		} else {
			$context = Application::getContextDAO()->getById($object->getContextId());
			if ($context && trim((string) $context->getData('mailingAddress')) !== '') {
				$targets[self::BY_JOURNAL] = $context->getLocalizedName() . "\n" . trim($context->getData('mailingAddress'));
			}
		}
		$contact = self::getPublisherContact($object);
		if ($contact && trim((string) $contact->getMailingAddress()) !== '') {
			$affiliation = (string) $contact->getLocalizedData('affiliation');
			$targets[self::BY_PUBLISHER] = $contact->getFullName() . ($affiliation !== '' ? "\n" . $affiliation : '')
				. "\n" . trim($contact->getMailingAddress());
		}
		return $targets;
	}

	public static function getPublisherContact(ObjectForReview $object): ?User {
		return $object->getPublisherContactId() ? Repo::user()->get($object->getPublisherContactId(), true) : null;
	}

	/**
	 * Delivery details for the copy request to the publisher, as HTML in the email's locale:
	 * the postal address (physical copy) or the reviewer's email (electronic copy).
	 */
	public static function getDeliveryDetails(Assignment $assignment, ?User $reviewer, string $locale): string {
		$t = fn ($key) => __('plugins.generic.bookReviewManager.' . $key, [], $locale);
		$name = htmlspecialchars($reviewer?->getFullName() ?? '');
		if (self::isPhysical($assignment)) {
			$lines = [$t('copy.delivery.physical'), $name, nl2br(htmlspecialchars((string) $assignment->getData('shippingAddress')))];
			if ($assignment->getData('phone')) {
				$lines[] = $t('reserve.phone') . ': ' . htmlspecialchars($assignment->getData('phone'));
			}
			return implode('<br />', $lines);
		}
		return $t('copy.delivery.electronic') . '<br />' . $name . ' &lt;' . htmlspecialchars($reviewer?->getEmail() ?? '') . '&gt;';
	}

	/**
	 * Label of the current copy status, e.g. for the To-do and Reviewers pages.
	 */
	public static function getStatusLabel(Assignment $assignment): string {
		$status = self::getStatus($assignment);
		if (!$status) {
			return '';
		}
		$label = __('plugins.generic.bookReviewManager.copy.status.' . $status);
		if ($status === self::SENT && $assignment->getData('copySentBy')) {
			$label .= ' (' . __('plugins.generic.bookReviewManager.copy.by.' . $assignment->getData('copySentBy')) . ')';
		}
		if (in_array($status, [self::RETURN_REQUESTED, self::RETURN_SENT, self::RETURNED], true) && $assignment->getData('returnTarget')) {
			$label .= ' (' . __('plugins.generic.bookReviewManager.copy.to.' . $assignment->getData('returnTarget')) . ')';
		}
		return $label;
	}
}
