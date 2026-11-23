<?php

/**
 * @file plugins/generic/bookReviewManager/controllers/form/ReservationEmailForm.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ReservationEmailForm
 * @ingroup plugins_generic_bookReviewManager
 *
 * @brief A workflow step taken by the book review editor, with a prefilled,
 *  editable email that is recorded in the audit trail (like unassigning a
 *  reviewer in OJS):
 *  - message: email the reviewer or selected bidder
 *  - cancel: cancel a contract offer or reservation, optionally notifying the person
 *  - contractOffer: select a bidder and send the contract link
 *  - unsuccessful: tell the remaining bidders that someone else writes the review
 *    (they stay on the list), optionally by email
 *  - invitation: invite someone on the invitation list (InvitationWorkflow)
 *  - round: start a sequential invitation round (the text is stored and reused)
 *  - dueDate: change the due date of an accepted review, optionally notifying the reviewer
 */

namespace APP\plugins\generic\bookReviewManager\controllers\form;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\bookReviewManager\classes\ActionLink;
use APP\plugins\generic\bookReviewManager\classes\Assignment;
use APP\plugins\generic\bookReviewManager\classes\AssignmentWorkflow;
use APP\plugins\generic\bookReviewManager\classes\InvitationWorkflow;
use APP\plugins\generic\bookReviewManager\classes\ReservationEmailEventType;
use APP\plugins\generic\bookReviewManager\classes\ReservationMailer;
use APP\plugins\generic\bookReviewManager\controllers\ReservationActionsHandler;
use APP\template\TemplateManager;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorCustom;
use PKP\form\validation\FormValidatorPost;

class ReservationEmailForm extends \PKP\form\Form {

	const MODE_MESSAGE = 'message';
	const MODE_CANCEL = 'cancel';
	const MODE_CONTRACT_OFFER = 'contractOffer';
	const MODE_UNSUCCESSFUL = 'unsuccessful';
	const MODE_INVITATION = 'invitation';
	const MODE_ROUND = 'round';
	const MODE_DUE_DATE = 'dueDate';

	/** Modes in which sending the email is optional */
	const OPTIONAL_EMAIL_MODES = [self::MODE_CANCEL, self::MODE_UNSUCCESSFUL, self::MODE_DUE_DATE];

	/** Handler operation that saves each mode */
	const SAVE_OPS = [
		self::MODE_MESSAGE => 'sendEmail',
		self::MODE_CANCEL => 'cancel',
		self::MODE_CONTRACT_OFFER => 'offerContract',
		self::MODE_UNSUCCESSFUL => 'notifyUnsuccessful',
		self::MODE_INVITATION => 'sendInvitation',
		self::MODE_ROUND => 'startRound',
		self::MODE_DUE_DATE => 'changeDueDate',
	];

	protected $plugin;
	protected $context;
	protected $object;
	/** @var Assignment[] */
	protected array $assignments;
	protected string $mode;
	/** @var \PKP\user\User[] assignmentId => user */
	protected array $recipients = [];

	/**
	 * @param Assignment[] $assignments One assignment, or several for MODE_UNSUCCESSFUL
	 */
	public function __construct($plugin, $context, $object, array $assignments, string $mode) {
		parent::__construct($plugin->getTemplateResource('reservationEmailForm.tpl'));
		$this->plugin = $plugin;
		$this->context = $context;
		$this->object = $object;
		$this->assignments = $assignments;
		$this->mode = $mode;
		foreach ($assignments as $assignment) {
			if ($user = Repo::user()->get($assignment->getUserId(), true)) {
				$this->recipients[$assignment->getId()] = $user;
			}
		}

		$needsEmail = fn () => !in_array($this->mode, self::OPTIONAL_EMAIL_MODES, true) || $this->getData('sendEmail');
		$this->addCheck(new FormValidatorCustom($this, 'subject', 'required', 'plugins.generic.bookReviewManager.reservations.subjectRequired',
			fn ($subject) => !$needsEmail() || trim((string) $subject) !== ''));
		$this->addCheck(new FormValidatorCustom($this, 'body', 'required', 'plugins.generic.bookReviewManager.reservations.bodyRequired',
			fn ($body) => !$needsEmail() || trim(strip_tags((string) $body)) !== ''));
		if ($mode === self::MODE_CONTRACT_OFFER) {
			$this->addCheck(new FormValidatorCustom($this, 'body', 'required', 'plugins.generic.bookReviewManager.contractOffer.linkRequired',
				fn ($body) => str_contains((string) $body, '{$contractUrl}')));
		}
		if (in_array($mode, [self::MODE_INVITATION, self::MODE_ROUND], true)) {
			$this->addCheck(new FormValidatorCustom($this, 'body', 'required', 'plugins.generic.bookReviewManager.invitation.linkRequired',
				fn ($body) => str_contains((string) $body, '{$invitationUrl}')));
		}
		if ($mode === self::MODE_DUE_DATE) {
			// One check per message; each passes when the date fails an earlier one
			$error = fn ($date) => ($a = reset($this->assignments)) ? AssignmentWorkflow::validateNewDueDate($a, (string) $date) : null;
			foreach (['plugins.generic.bookReviewManager.reserve.error.dueDate', 'plugins.generic.bookReviewManager.dueDate.error.past',
					'plugins.generic.bookReviewManager.dueDate.error.unchanged'] as $key) {
				$this->addCheck(new FormValidatorCustom($this, 'dueDate', 'required', $key, fn ($date) => $error($date) !== $key));
			}
		}
		$this->addCheck(new FormValidatorPost($this));
		$this->addCheck(new FormValidatorCSRF($this));
	}

	protected function emailType(): ReservationEmailEventType {
		return match ($this->mode) {
			self::MODE_CANCEL => ReservationEmailEventType::CANCELLED_BY_EDITOR,
			self::MODE_CONTRACT_OFFER => AssignmentWorkflow::isAfterDropout(AssignmentWorkflow::dao()->getByObjectId($this->object->getId()))
				? ReservationEmailEventType::CONTRACT_OFFER_AGAIN
				: ReservationEmailEventType::CONTRACT_OFFER,
			self::MODE_UNSUCCESSFUL => ReservationEmailEventType::BID_UNSUCCESSFUL,
			self::MODE_INVITATION, self::MODE_ROUND => InvitationWorkflow::getEmailType($this->object),
			self::MODE_DUE_DATE => ReservationEmailEventType::DUE_DATE_CHANGED,
			default => ReservationEmailEventType::MESSAGE,
		};
	}

	public function initData() {
		$sender = Application::get()->getRequest()->getUser();
		// Batches and rounds: {$recipientName} (and the deadline) are filled in for each recipient
		$single = count($this->assignments) === 1 && !in_array($this->mode, [self::MODE_UNSUCCESSFUL, self::MODE_ROUND], true);
		$assignment = $single ? reset($this->assignments) : null;
		$extra = [];
		if ($this->mode === self::MODE_CONTRACT_OFFER) {
			// The deadline the offer will get
			[$days] = AssignmentWorkflow::getContractDays($this->plugin, $this->context->getId());
			$deadline = (new \DateTimeImmutable('today'))->modify("+{$days} days")->format('Y-m-d');
			$extra['contractDeadline'] = date(\PKP\core\PKPString::convertStrftimeFormat($this->context->getLocalizedDateFormatShort()), strtotime($deadline));
		}
		if ($this->mode === self::MODE_INVITATION) {
			$extra['invitationDeadline'] = $this->formatDate(InvitationWorkflow::getDeadline($this->plugin, $this->object));
		}
		if ($this->mode === self::MODE_DUE_DATE) {
			// The new date is chosen in the same form and filled in when sending
			$current = (string) $assignment?->getData('dueDate');
			$extra['dueDate'] = '{$dueDate}';
			$extra['previousDueDate'] = $current ? $this->formatDate($current) : '';
			$this->setData('dueDate', substr($current, 0, 10));
		}
		// In batches, {$recipientName} is filled in per recipient when sending
		$recipient = $assignment ? ($this->recipients[$assignment->getId()] ?? null) : null;
		[$subject, $body] = ReservationMailer::compose($this->context, $this->emailType(), $this->object, $assignment, $recipient, $sender, $extra);
		$this->setData('subject', $subject);
		$this->setData('body', $body);
		$this->setData('sendEmail', true);
		// Hidden by default only in sequential rounds
		$this->setData('hideOffer', !$this->object->isBidding());
	}

	public function readInputData() {
		$this->readUserVars(['subject', 'body', 'sendEmail', 'hideOffer', 'dueDate']);
		$this->setData('dueDate', trim((string) $this->getData('dueDate')));
	}

	public function fetch($request, $template = null, $display = false) {
		$recipients = [];
		foreach ($this->recipients as $user) {
			$recipients[] = ['name' => $user->getFullName(), 'email' => $user->getEmail()];
		}
		$first = $recipients[0] ?? ['name' => '', 'email' => ''];
		$templateMgr = TemplateManager::getManager($request);
		$templateMgr->assign([
			'ofrEmailsSuppressed' => \APP\plugins\generic\bookReviewManager\classes\ReservationMailer::emailsSuppressed($request->getContext()?->getId()),
			'mode' => $this->mode,
			'objectId' => $this->object->getId(),
			'assignmentIds' => array_map(fn ($a) => $a->getId(), $this->assignments),
			'objectDescription' => $this->object->getCitation(),
			'recipients' => $recipients,
			'reviewerName' => $first['name'],
			'optionalEmail' => in_array($this->mode, self::OPTIONAL_EMAIL_MODES, true),
			'cancelOffer' => $this->mode === self::MODE_CANCEL && reset($this->assignments)?->getStatus() === Assignment::STATUS_OFFERED,
		] + ($this->mode === self::MODE_INVITATION ? [
			'stepDescription' => __('plugins.generic.bookReviewManager.invitation.send.description.' . InvitationWorkflow::getKind($this->object), [
				'name' => $first['name'],
				'deadline' => $this->formatDate(InvitationWorkflow::getDeadline($this->plugin, $this->object)),
			]),
			'bodyHint' => __('plugins.generic.bookReviewManager.invitation.linkHint'),
			'submitKey' => 'plugins.generic.bookReviewManager.invitation.send',
		] : []) + ($this->mode === self::MODE_ROUND ? [
			'stepDescription' => $this->object->isBidding()
				? __('plugins.generic.bookReviewManager.invitation.round.descriptionParallel', [
					'number' => count($recipients),
					'deadline' => $this->formatDate(InvitationWorkflow::getDeadline($this->plugin, $this->object)),
				])
				: __('plugins.generic.bookReviewManager.invitation.round.description', [
					'name' => $first['name'],
					'days' => InvitationWorkflow::getInvitationDays($this->plugin, (int) $this->context->getId()),
				]),
			'hideOfferOption' => true,
			'hideOffer' => (bool) $this->getData('hideOffer'),
			'bodyHint' => __('plugins.generic.bookReviewManager.invitation.round.hint'),
			'submitKey' => $this->object->isBidding() ? 'plugins.generic.bookReviewManager.invitation.round.startParallel' : 'plugins.generic.bookReviewManager.invitation.round.start',
		] : []) + ($this->mode === self::MODE_DUE_DATE ? [
			'stepDescription' => __('plugins.generic.bookReviewManager.dueDate.description', [
				'reviewer' => $first['name'],
				'dueDate' => ($current = (string) reset($this->assignments)?->getData('dueDate')) ? $this->formatDate($current) : '–',
			]),
			'dueDate' => (string) $this->getData('dueDate'),
			'dueDateError' => $this->getErrorsArray()['dueDate'] ?? null,
			'minDueDate' => date('Y-m-d'),
			'bodyHint' => __('plugins.generic.bookReviewManager.dueDate.linkHint'),
			'submitKey' => 'plugins.generic.bookReviewManager.dueDate.submit',
		] : []) + [
			'actionUrl' => $request->getDispatcher()->url($request, Application::ROUTE_COMPONENT, null,
				ReservationActionsHandler::COMPONENT, self::SAVE_OPS[$this->mode]),
		]);
		return parent::fetch($request, $template, $display);
	}

	protected function formatDate(string $date): string {
		return date(\PKP\core\PKPString::convertStrftimeFormat($this->context->getLocalizedDateFormatShort()), strtotime($date));
	}

	public function execute(...$functionArgs) {
		$sender = Application::get()->getRequest()->getUser();
		if ($this->mode === self::MODE_ROUND) {
			InvitationWorkflow::startRound($this->plugin, $this->context, $this->object, $sender,
				trim((string) $this->getData('subject')), (string) $this->getData('body'), (bool) $this->getData('hideOffer'));
			return parent::execute(...$functionArgs);
		}
		$sendEmail = !in_array($this->mode, self::OPTIONAL_EMAIL_MODES, true) || $this->getData('sendEmail');
		$type = $this->emailType();
		$subject = trim((string) $this->getData('subject'));
		$body = (string) $this->getData('body');

		foreach ($this->assignments as $assignment) {
			$recipient = $this->recipients[$assignment->getId()] ?? null;
			$late = [];
			switch ($this->mode) {
				case self::MODE_CANCEL:
					AssignmentWorkflow::cancelByEditor($this->object, $assignment, $sender);
					break;
				case self::MODE_CONTRACT_OFFER:
					$key = AssignmentWorkflow::offerContract($this->plugin, $this->context, $this->object, $assignment, $sender);
					$late['contractUrl'] = ActionLink::url($this->context, $key);
					break;
				case self::MODE_UNSUCCESSFUL:
					AssignmentWorkflow::markUnsuccessful($this->object, $assignment, $sender);
					$key = ActionLink::create($assignment->getId(), ActionLink::ACTION_LEAVE,
						(new \DateTimeImmutable('today'))->modify('+' . ActionLink::DEFAULT_VALIDITY_DAYS . ' days')->format('Y-m-d 23:59:59'));
					$late['removeUrl'] = ActionLink::url($this->context, $key);
					break;
				case self::MODE_INVITATION:
					$key = InvitationWorkflow::invite($this->object, $assignment, $sender, InvitationWorkflow::getDeadline($this->plugin, $this->object));
					$late['invitationUrl'] = ActionLink::url($this->context, $key);
					break;
				case self::MODE_DUE_DATE:
					$dueDate = (string) $this->getData('dueDate');
					AssignmentWorkflow::changeDueDate($this->object, $assignment, $sender, $dueDate);
					$late['dueDate'] = $this->formatDate($dueDate);
					break;
			}
			if ($sendEmail && $recipient) {
				ReservationMailer::send($this->context, $type, $this->object, $recipient, $sender, $subject, $body, $late);
			}
		}
		parent::execute(...$functionArgs);
	}
}
