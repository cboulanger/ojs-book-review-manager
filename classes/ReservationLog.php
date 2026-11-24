<?php

/**
 * @file plugins/generic/bookReviewManager/classes/ReservationLog.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ReservationLog
 * @ingroup plugins_generic_bookReviewManager
 *
 * @brief Audit trail of reservations, stored in OJS's event log and email log
 *  (associated with the object for review).
 */

namespace APP\plugins\generic\bookReviewManager\classes;

use APP\facades\Repo;
use Illuminate\Support\Facades\DB;
use PKP\core\Core;
use PKP\log\EmailLogEntry;
use PKP\user\User;

class ReservationLog {

	/** Association type for log entries about an object for review (plugin-specific) */
	const ASSOC_TYPE = 0x1F0F001;

	const EVENT_RESERVED = 0x1F0F201;
	const EVENT_CANCELLED_BY_REVIEWER = 0x1F0F202;
	const EVENT_CANCELLED_BY_EDITOR = 0x1F0F203;
	const EVENT_ATTACHED = 0x1F0F204;
	const EVENT_BID = 0x1F0F205;
	const EVENT_BID_WITHDRAWN = 0x1F0F206;
	const EVENT_CONTRACT_OFFERED = 0x1F0F207;
	const EVENT_CONTRACT_ACCEPTED = 0x1F0F208;
	const EVENT_CONTRACT_DECLINED = 0x1F0F209;
	const EVENT_CONTRACT_EXPIRED = 0x1F0F20A;
	const EVENT_CONTRACT_WITHDRAWN = 0x1F0F20B;
	const EVENT_BIDDER_UNSUCCESSFUL = 0x1F0F20C;
	const EVENT_BIDDING_REOPENED = 0x1F0F20D;
	const EVENT_MODE_DIRECT = 0x1F0F20E;
	const EVENT_OFFER_CLOSED = 0x1F0F20F;
	const EVENT_OFFER_REOPENED = 0x1F0F210;
	const EVENT_LIST_DISCARDED = 0x1F0F211;
	const EVENT_COMPLETED = 0x1F0F212;
	const EVENT_COPY_REQUESTED = 0x1F0F213;
	const EVENT_COPY_SENT_JOURNAL = 0x1F0F214;
	const EVENT_COPY_SENT_PUBLISHER = 0x1F0F215;
	const EVENT_COPY_RECEIVED = 0x1F0F216;
	const EVENT_COPY_REQUEST_WITHDRAWN = 0x1F0F217;
	const EVENT_RETURN_REQUESTED_JOURNAL = 0x1F0F218;
	const EVENT_RETURN_REQUESTED_PUBLISHER = 0x1F0F219;
	const EVENT_RETURN_SENT = 0x1F0F21A;
	const EVENT_RETURNED_JOURNAL = 0x1F0F21B;
	const EVENT_RETURNED_PUBLISHER = 0x1F0F21C;
	const EVENT_PUBLISHER_NOTIFIED = 0x1F0F21D;
	const EVENT_OFFER_HIDDEN = 0x1F0F21E;
	const EVENT_OFFER_SHOWN = 0x1F0F21F;
	const EVENT_INVITEE_ADDED = 0x1F0F220;
	const EVENT_INVITEE_CREATED = 0x1F0F221;
	const EVENT_INVITEE_REMOVED = 0x1F0F222;
	const EVENT_INVITATION_LIST_REORDERED = 0x1F0F223;
	const EVENT_INVITATION_SENT = 0x1F0F224;
	const EVENT_INVITATION_DECLINED = 0x1F0F225;
	const EVENT_INVITATION_EXPIRED = 0x1F0F226;
	const EVENT_RESERVED_VIA_INVITATION = 0x1F0F227;
	const EVENT_BID_VIA_INVITATION = 0x1F0F228;
	const EVENT_INVITATIONS_ENDED = 0x1F0F229;
	const EVENT_ROUND_STARTED = 0x1F0F22A;
	const EVENT_ROUND_STOPPED = 0x1F0F22B;
	const EVENT_ROUND_EXHAUSTED = 0x1F0F22C;
	const EVENT_ROUND_COMPLETED = 0x1F0F22D;
	const EVENT_INVITATION_WITHDRAWN = 0x1F0F22E;
	const EVENT_ROUND_CONTINUED = 0x1F0F22F;
	const EVENT_ROUND_STARTED_PARALLEL = 0x1F0F230;
	const EVENT_ROUND_FINISHED = 0x1F0F231;
	const EVENT_IMPORTED = 0x1F0F232;
	/** Review essays (see BundleWorkflow), logged on the works */
	const EVENT_ESSAY_MIRRORED = 0x1F0F233;
	const EVENT_ESSAY_STATUS = 0x1F0F234;
	/** Review essays: created (on the container), works added or removed (on both), dissolved (on the works) */
	const EVENT_ESSAY_CREATED = 0x1F0F235;
	const EVENT_ESSAY_MEMBER_ADDED = 0x1F0F236;
	const EVENT_ESSAY_MEMBER_REMOVED = 0x1F0F237;
	const EVENT_ESSAY_DISSOLVED = 0x1F0F238;
	/** The editor changed the due date of a review (reviewDueDate: new, previousDueDate: previous) */
	const EVENT_DUE_DATE_CHANGED = 0x1F0F239;
	/** The editor linked an accepted reservation to a submission (submissionId) */
	const EVENT_LINKED_BY_EDITOR = 0x1F0F23A;
	/** A note of the editor (a reply by phone or email, a status report); the message is the note text */
	const EVENT_NOTE = 0x1F0F23B;

	/** Longest note, in characters */
	const MAX_NOTE_LENGTH = 10000;

	const EVENT_MESSAGES = [
		self::EVENT_RESERVED => 'plugins.generic.bookReviewManager.history.event.reserved',
		self::EVENT_CANCELLED_BY_REVIEWER => 'plugins.generic.bookReviewManager.history.event.cancelledByReviewer',
		self::EVENT_CANCELLED_BY_EDITOR => 'plugins.generic.bookReviewManager.history.event.cancelledByEditor',
		self::EVENT_ATTACHED => 'plugins.generic.bookReviewManager.history.event.attached',
		self::EVENT_BID => 'plugins.generic.bookReviewManager.history.event.bid',
		self::EVENT_BID_WITHDRAWN => 'plugins.generic.bookReviewManager.history.event.bidWithdrawn',
		self::EVENT_CONTRACT_OFFERED => 'plugins.generic.bookReviewManager.history.event.contractOffered',
		self::EVENT_CONTRACT_ACCEPTED => 'plugins.generic.bookReviewManager.history.event.contractAccepted',
		self::EVENT_CONTRACT_DECLINED => 'plugins.generic.bookReviewManager.history.event.contractDeclined',
		self::EVENT_CONTRACT_EXPIRED => 'plugins.generic.bookReviewManager.history.event.contractExpired',
		self::EVENT_CONTRACT_WITHDRAWN => 'plugins.generic.bookReviewManager.history.event.contractWithdrawn',
		self::EVENT_BIDDER_UNSUCCESSFUL => 'plugins.generic.bookReviewManager.history.event.bidderUnsuccessful',
		self::EVENT_BIDDING_REOPENED => 'plugins.generic.bookReviewManager.history.event.biddingReopened',
		self::EVENT_MODE_DIRECT => 'plugins.generic.bookReviewManager.history.event.modeDirect',
		self::EVENT_OFFER_CLOSED => 'plugins.generic.bookReviewManager.history.event.offerClosed',
		self::EVENT_OFFER_REOPENED => 'plugins.generic.bookReviewManager.history.event.offerReopened',
		self::EVENT_OFFER_HIDDEN => 'plugins.generic.bookReviewManager.history.event.offerHidden',
		self::EVENT_OFFER_SHOWN => 'plugins.generic.bookReviewManager.history.event.offerShown',
		self::EVENT_INVITEE_ADDED => 'plugins.generic.bookReviewManager.history.event.inviteeAdded',
		self::EVENT_INVITEE_CREATED => 'plugins.generic.bookReviewManager.history.event.inviteeCreated',
		self::EVENT_INVITEE_REMOVED => 'plugins.generic.bookReviewManager.history.event.inviteeRemoved',
		self::EVENT_INVITATION_LIST_REORDERED => 'plugins.generic.bookReviewManager.history.event.invitationListReordered',
		self::EVENT_INVITATION_SENT => 'plugins.generic.bookReviewManager.history.event.invitationSent',
		self::EVENT_INVITATION_DECLINED => 'plugins.generic.bookReviewManager.history.event.invitationDeclined',
		self::EVENT_INVITATION_EXPIRED => 'plugins.generic.bookReviewManager.history.event.invitationExpired',
		self::EVENT_RESERVED_VIA_INVITATION => 'plugins.generic.bookReviewManager.history.event.reservedViaInvitation',
		self::EVENT_BID_VIA_INVITATION => 'plugins.generic.bookReviewManager.history.event.bidViaInvitation',
		self::EVENT_INVITATIONS_ENDED => 'plugins.generic.bookReviewManager.history.event.invitationsEnded',
		self::EVENT_ROUND_STARTED => 'plugins.generic.bookReviewManager.history.event.roundStarted',
		self::EVENT_ROUND_STOPPED => 'plugins.generic.bookReviewManager.history.event.roundStopped',
		self::EVENT_ROUND_EXHAUSTED => 'plugins.generic.bookReviewManager.history.event.roundExhausted',
		self::EVENT_ROUND_COMPLETED => 'plugins.generic.bookReviewManager.history.event.roundCompleted',
		self::EVENT_INVITATION_WITHDRAWN => 'plugins.generic.bookReviewManager.history.event.invitationWithdrawn',
		self::EVENT_ROUND_CONTINUED => 'plugins.generic.bookReviewManager.history.event.roundContinued',
		self::EVENT_ROUND_STARTED_PARALLEL => 'plugins.generic.bookReviewManager.history.event.roundStartedParallel',
		self::EVENT_ROUND_FINISHED => 'plugins.generic.bookReviewManager.history.event.roundFinished',
		self::EVENT_IMPORTED => 'plugins.generic.bookReviewManager.history.event.imported',
		self::EVENT_ESSAY_MIRRORED => 'plugins.generic.bookReviewManager.history.event.essayMirrored',
		// Logged with the message essayStatus.<status>
		self::EVENT_ESSAY_STATUS => 'plugins.generic.bookReviewManager.history.event.essayStatus',
		self::EVENT_ESSAY_CREATED => 'plugins.generic.bookReviewManager.history.event.essayCreated',
		// On the container logged with the message <key>.container, naming the work
		self::EVENT_ESSAY_MEMBER_ADDED => 'plugins.generic.bookReviewManager.history.event.essayMemberAdded',
		self::EVENT_ESSAY_MEMBER_REMOVED => 'plugins.generic.bookReviewManager.history.event.essayMemberRemoved',
		self::EVENT_ESSAY_DISSOLVED => 'plugins.generic.bookReviewManager.history.event.essayDissolved',
		self::EVENT_DUE_DATE_CHANGED => 'plugins.generic.bookReviewManager.history.event.dueDateChanged',
		self::EVENT_LINKED_BY_EDITOR => 'plugins.generic.bookReviewManager.history.event.linkedByEditor',
		// Logged with the note text as an already translated message
		self::EVENT_NOTE => 'plugins.generic.bookReviewManager.history.note',
		self::EVENT_LIST_DISCARDED => 'plugins.generic.bookReviewManager.history.event.listDiscarded',
		self::EVENT_COMPLETED => 'plugins.generic.bookReviewManager.history.event.completed',
		self::EVENT_COPY_REQUESTED => 'plugins.generic.bookReviewManager.history.event.copyRequested',
		self::EVENT_COPY_SENT_JOURNAL => 'plugins.generic.bookReviewManager.history.event.copySentJournal',
		self::EVENT_COPY_SENT_PUBLISHER => 'plugins.generic.bookReviewManager.history.event.copySentPublisher',
		self::EVENT_COPY_RECEIVED => 'plugins.generic.bookReviewManager.history.event.copyReceived',
		self::EVENT_COPY_REQUEST_WITHDRAWN => 'plugins.generic.bookReviewManager.history.event.copyRequestWithdrawn',
		self::EVENT_RETURN_REQUESTED_JOURNAL => 'plugins.generic.bookReviewManager.history.event.returnRequestedJournal',
		self::EVENT_RETURN_REQUESTED_PUBLISHER => 'plugins.generic.bookReviewManager.history.event.returnRequestedPublisher',
		self::EVENT_RETURN_SENT => 'plugins.generic.bookReviewManager.history.event.returnSent',
		self::EVENT_RETURNED_JOURNAL => 'plugins.generic.bookReviewManager.history.event.returnedJournal',
		self::EVENT_RETURNED_PUBLISHER => 'plugins.generic.bookReviewManager.history.event.returnedPublisher',
		self::EVENT_PUBLISHER_NOTIFIED => 'plugins.generic.bookReviewManager.history.event.publisherNotified',
	];

	/**
	 * Log a reservation event.
	 * @param ObjectForReview $object
	 * @param int $eventType One of the EVENT_* constants
	 * @param ?User $actor The user who caused the event
	 * @param ?User $reviewer The reviewer the reservation belongs to
	 * @param array $data Additional data (e.g. ['reviewDueDate' => ...])
	 */
	public static function logEvent($object, int $eventType, ?User $actor, ?User $reviewer, array $data = []): void {
		$entry = Repo::eventLog()->newDataObject(array_merge([
			'assocType' => self::ASSOC_TYPE,
			'assocId' => $object->getId(),
			'eventType' => $eventType,
			'userId' => $actor?->getId(),
			'dateLogged' => Core::getCurrentDate(),
			'message' => self::EVENT_MESSAGES[$eventType],
			'isTranslated' => false,
			'userFullName' => $actor?->getFullName(),
			'recipientName' => $reviewer?->getFullName(),
		], $data));
		Repo::eventLog()->add($entry);
	}

	/**
	 * Add a note of the editor to the history.
	 * @param string $date Y-m-d: the day the note refers to (today or earlier). An earlier day is
	 *  logged at midnight and shown without a time.
	 * @throws \InvalidArgumentException With a locale key, if the note or the date is invalid
	 */
	public static function addNote($object, User $editor, string $note, string $date): void {
		if ($error = self::validateNote($note, $date)) {
			throw new \InvalidArgumentException($error);
		}
		self::logEvent($object, self::EVENT_NOTE, $editor, null, [
			'message' => trim($note),
			'isTranslated' => true,
			'dateLogged' => $date === date('Y-m-d') ? Core::getCurrentDate() : $date . ' 00:00:00',
		]);
	}

	/**
	 * @return ?string Locale key of the error, or null if the note can be added
	 */
	public static function validateNote(string $note, string $date): ?string {
		if (trim($note) === '') {
			return 'plugins.generic.bookReviewManager.history.note.error.required';
		}
		if (mb_strlen(trim($note)) > self::MAX_NOTE_LENGTH) {
			return 'plugins.generic.bookReviewManager.history.note.error.tooLong';
		}
		$parsed = \DateTime::createFromFormat('!Y-m-d', $date);
		if (!$parsed || $parsed->format('Y-m-d') !== $date || $date > date('Y-m-d')) {
			return 'plugins.generic.bookReviewManager.history.note.error.date';
		}
		return null;
	}

	/**
	 * Event data for a step taken through a one-click link: the actor's name is marked "via link".
	 */
	public static function viaLink(?User $actor): array {
		return ['userFullName' => trim(($actor?->getFullName() ?? '') . ' (' . __('plugins.generic.bookReviewManager.history.viaLink') . ')')];
	}

	/**
	 * Log a sent email (like Repo::emailLogEntry()->logMailable(), but for an object for review).
	 */
	public static function logEmail($object, ReservationEmailEventType $eventType, ?User $sender, User|string $recipient,
			string $from, string $subject, string $body): void {
		$entry = new EmailLogEntry();
		$entry->assocType = self::ASSOC_TYPE;
		$entry->assocId = $object->getId();
		$entry->eventType = $eventType->value;
		$entry->senderId = $sender?->getId();
		$entry->dateSent = Core::getCurrentDate();
		$entry->fromAddress = $from;
		$entry->recipients = $recipient instanceof User
			? '"' . $recipient->getFullName() . '" <' . $recipient->getEmail() . '>'
			: $recipient;
		$entry->ccRecipients = '';
		$entry->bccRecipients = '';
		$entry->subject = $subject;
		$entry->body = $body;
		$entry->save();
		if ($recipient instanceof User) {
			DB::table('email_log_users')->insert(['email_log_id' => $entry->id, 'user_id' => $recipient->getId()]);
		}
	}

	/**
	 * All log entries of an object, newest first.
	 * @return array[] Each with: date, type ('event'|'email'|'note'), label, actor, for emails: recipients, subject, body,
	 *  for notes: body (plain text), dateOnly
	 */
	public static function getHistory($object): array {
		$history = [];
		$events = Repo::eventLog()->getCollector()->filterByAssoc(self::ASSOC_TYPE, [$object->getId()])->getMany();
		foreach ($events as $event) {
			if ((int) $event->getData('eventType') === self::EVENT_NOTE) {
				$history[] = [
					'date' => $event->getDateLogged(),
					'id' => (int) $event->getId(),
					'type' => 'note',
					'label' => __(self::EVENT_MESSAGES[self::EVENT_NOTE]),
					'actor' => $event->getData('userFullName'),
					'body' => $event->getMessage(),
					// Added for an earlier day: no time
					'dateOnly' => str_ends_with((string) $event->getDateLogged(), ' 00:00:00'),
				];
				continue;
			}
			$history[] = [
				'date' => $event->getDateLogged(),
				'id' => (int) $event->getId(),
				'type' => 'event',
				'label' => $event->getTranslatedMessage(),
				'actor' => $event->getData('userFullName'),
			];
		}
		$emails = EmailLogEntry::withAssocType(self::ASSOC_TYPE)->withAssocId($object->getId())->get();
		foreach ($emails as $email) {
			$type = ReservationEmailEventType::tryFrom((int) $email->eventType);
			$history[] = [
				'date' => (string) $email->dateSent,
				'id' => (int) $email->id,
				'type' => 'email',
				'label' => $type ? __($type->labelKey()) : __('plugins.generic.bookReviewManager.history.email'),
				'actor' => $email->senderId ? $email->senderFullName : __('plugins.generic.bookReviewManager.history.system'),
				'recipients' => $email->recipients,
				'subject' => $email->subject,
				'body' => $email->body,
			];
		}
		// Newest first; within the same second by log order (a step's email is sent after its event is logged)
		usort($history, fn ($a, $b) => strcmp((string) $b['date'], (string) $a['date'])
			?: (($b['type'] === 'email') <=> ($a['type'] === 'email'))
			?: $b['id'] <=> $a['id']);
		return $history;
	}
}
